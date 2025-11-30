<?php

namespace App\Http\Controllers;

use ZipArchive;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\{Upload, Group};
use App\Services\BarcodeOCRService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class UploadController extends Controller
{
    protected $barcodeService;

    public function __construct(BarcodeOCRService $barcodeService)
    {
        $this->barcodeService = $barcodeService;
    }

    public function index()
    {
        $uploads = Upload::with(['user', 'groups'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('uploads.index', compact('uploads'));
    }

    public function create()
    {
        return view('uploads.create');
    }

    public function show(Upload $upload)
    {
        $upload->load(['groups', 'user']);
        return view('uploads.show', compact('upload'));
    }

    public function showFile(Upload $upload)
    {
        $path = $upload->stored_filename;
        $disk = 'private';

        if (empty($path) || !Storage::disk($disk)->exists($path)) {
            abort(404, 'الملف غير موجود أو مساره مفقود في قاعدة البيانات.');
        }

        return Storage::disk($disk)->response($path);
    }

    public function downloadAllGroupsZip(Upload $upload)
    {
        if ($upload->status !== 'completed' || $upload->groups->isEmpty()) {
            return redirect()->back()->with('error', 'لا يمكن تحميل ملف ZIP. الملف غير مكتمل المعالجة أو لا يحتوي على مجموعات.');
        }

        $zip = new ZipArchive;
        $zipFileName = 'groups_for_' . $upload->original_filename . '.zip';
        $tempPath = storage_path('app/temp/' . $zipFileName);

        if (!File::isDirectory(storage_path('app/temp'))) {
            File::makeDirectory(storage_path('app/temp'), 0755, true);
        }

        if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $errors = [];

            foreach ($upload->groups as $group) {
                if ($group->pdf_path && Storage::exists($group->pdf_path)) {
                    $fileContents = Storage::get($group->pdf_path);
                    $zip->addFromString(basename($group->pdf_path), $fileContents);
                } else {
                    $errors[] = $group->code;
                }
            }

            $zip->close();

            if (!empty($errors)) {
                Log::warning('Some group files were missing during ZIP creation.', ['upload_id' => $upload->id, 'missing_groups' => $errors]);
            }

            if (File::exists($tempPath)) {
                $response = response()->download($tempPath, $zipFileName)->deleteFileAfterSend(true);
                return $response;
            }
        }

        return redirect()->back()->with('error', 'حدث خطأ أثناء إنشاء ملف ZIP.');
    }

    /**
     * رفع متعدد سريع - محدث
     */
    public function store(Request $request)
    {
        // زيادة الحدود
        ini_set('upload_max_filesize', '500M');
        ini_set('post_max_size', '500M');
        ini_set('max_execution_time', 300);
        ini_set('max_input_time', 300);
        ini_set('memory_limit', '1024M');

        Log::info('Fast multi-file upload started', [
            'file_count' => $request->hasFile('pdf_files') ? count($request->file('pdf_files')) : 0,
        ]);

        try {
            $request->validate([
                'pdf_files' => 'required|array',
                'pdf_files.*' => 'required|mimes:pdf|max:512000'
            ]);

            $files = $request->file('pdf_files');
            $uploadIds = [];
            $totalSizeMB = 0;

            DB::beginTransaction();

            foreach ($files as $file) {
                $fileSizeMB = round($file->getSize() / 1024 / 1024, 2);
                $totalSizeMB += $fileSizeMB;

                // تخزين الملف
                $storedName = $file->store('uploads', 'private');

                $upload = Upload::create([
                    'original_filename' => $file->getClientOriginalName(),
                    'stored_filename' => $storedName,
                    'file_size' => $file->getSize(),
                    'total_pages' => 0,
                    'status' => 'queued', // تأكد من وجودها في الـ enum
                    'user_id' => auth()->id(),
                ]);

                $uploadIds[] = $upload->id;

                // بدء المعالجة في الخلفية
                $this->startBackgroundProcessing($upload->id);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "تم رفع " . count($files) . " ملف بنجاح ({$totalSizeMB} MB). جاري المعالجة في الخلفية...",
                'upload_ids' => $uploadIds,
                'file_count' => count($files),
                'total_size_mb' => $totalSizeMB
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Multi-file upload failed', [
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'فشل في رفع الملفات: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * بدء المعالجة في الخلفية - محدث
     */
    private function startBackgroundProcessing($uploadId)
    {
        Log::info("Starting background processing", ['upload_id' => $uploadId]);

        // تحديث الحالة إلى processing مباشرة
        Upload::where('id', $uploadId)->update(['status' => 'processing']);

        // الطريقة 1: استخدام exec (الأسرع)
        if (function_exists('exec') && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            $artisanPath = base_path('artisan');
            $command = "cd " . base_path() . " && nohup php artisan process:upload {$uploadId} > /dev/null 2>&1 & echo $!";

            Log::info("Executing background command", ['command' => $command]);

            exec($command, $output, $returnVar);

            if (!empty($output) && is_numeric($output[0])) {
                Log::info("Background process started", ['pid' => $output[0], 'upload_id' => $uploadId]);
                return true;
            }
        }

        // الطريقة 2: معالجة فورية
        Log::info("Using immediate processing", ['upload_id' => $uploadId]);
        return $this->processUploadImmediate($uploadId);
    }

    /**
     * معالجة فورية
     */
    private function processUploadImmediate($uploadId)
    {
        Log::info("Starting immediate processing", ['upload_id' => $uploadId]);

        try {
            ignore_user_abort(true);
            set_time_limit(0);
            ini_set('memory_limit', '4096M');

            // استدعاء عملية المعالجة مباشرة
            $result = $this->process($uploadId);

            return $result->getData()->success ?? false;

        } catch (\Exception $e) {
            Log::error('Immediate processing failed', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage()
            ]);

            Upload::where('id', $uploadId)->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * معالجة ملف واحد
     */
    public function process($uploadId)
    {
        Log::info("Processing upload", ['upload_id' => $uploadId]);

        try {
            $upload = Upload::findOrFail($uploadId);

            if (!in_array($upload->status, ['queued', 'processing'])) {
                Log::warning("Upload not in processable state", [
                    'upload_id' => $uploadId,
                    'current_status' => $upload->status
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'الملف ليس في حالة معالجة. الحالة الحالية: ' . $upload->status
                ]);
            }

            $upload->update(['status' => 'processing']);

            $fullPath = Storage::disk('private')->path($upload->stored_filename);

            // الحصول على عدد الصفحات
            $pageCount = $this->barcodeService->getPdfPageCount($fullPath);
            $upload->update(['total_pages' => $pageCount]);

            // معالجة PDF
            $groups = $this->barcodeService->processPdf($upload, 'private');

            $upload->update([
                'status' => 'completed',
                'error_message' => null,
                'processed_at' => now()
            ]);

            Log::info('Processing completed successfully', [
                'upload_id' => $upload->id,
                'groups_count' => count($groups),
                'total_pages' => $pageCount
            ]);

            return response()->json([
                'success' => true,
                'message' => "تمت معالجة الملف بنجاح. تم إنشاء " . count($groups) . " قسم.",
                'groups_count' => count($groups),
                'total_pages' => $pageCount
            ]);

        } catch (\Exception $e) {
            Log::error('Processing failed', [
                'upload_id' => $uploadId,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if (isset($upload)) {
                $upload->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'فشل في المعالجة: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * التحقق من حالة عدة ملفات
     */
    public function checkMultiStatus(Request $request)
    {
        $uploadIds = $request->input('upload_ids', []);

        if (empty($uploadIds)) {
            return response()->json([
                'success' => false,
                'error' => 'لم يتم توفير معرّفات الملفات'
            ]);
        }

        $uploads = Upload::whereIn('id', $uploadIds)->get();

        $statuses = [];
        $allCompleted = true;
        $anyFailed = false;
        $totalGroups = 0;
        $totalPages = 0;
        $completedCount = 0;

        foreach ($uploads as $upload) {
            $statuses[] = [
                'id' => $upload->id,
                'filename' => $upload->original_filename,
                'status' => $upload->status,
                'message' => $this->getStatusMessage($upload->status),
                'groups_count' => $upload->groups()->count(),
                'total_pages' => $upload->total_pages,
                'file_size' => $upload->file_size,
                'created_at' => $upload->created_at->format('Y-m-d H:i:s')
            ];

            if ($upload->status !== 'completed') {
                $allCompleted = false;
            } else {
                $completedCount++;
            }

            if ($upload->status === 'failed') {
                $anyFailed = true;
            }

            $totalGroups += $upload->groups()->count();
            $totalPages += $upload->total_pages;
        }

        return response()->json([
            'success' => true,
            'statuses' => $statuses,
            'all_completed' => $allCompleted,
            'any_failed' => $anyFailed,
            'total_groups' => $totalGroups,
            'total_pages' => $totalPages,
            'processed_files' => $completedCount,
            'total_files' => count($uploadIds),
            'progress_percentage' => round(($completedCount / count($uploadIds)) * 100)
        ]);
    }

    /**
     * تحميل جميع الملفات المعالجة في ZIP واحد
     */
    public function downloadMultiZip(Request $request)
    {
        $uploadIds = $request->input('upload_ids', []);

        if (empty($uploadIds)) {
            return redirect()->back()->with('error', 'لم يتم توفير معرّفات الملفات');
        }

        $uploads = Upload::with('groups')->whereIn('id', $uploadIds)->get();

        $zip = new ZipArchive;
        $zipFileName = 'multiple_uploads_' . time() . '.zip';
        $tempPath = storage_path('app/temp/' . $zipFileName);

        if (!File::isDirectory(storage_path('app/temp'))) {
            File::makeDirectory(storage_path('app/temp'), 0755, true);
        }

        if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $errors = [];

            foreach ($uploads as $upload) {
                if ($upload->status === 'completed' && $upload->groups->isNotEmpty()) {
                    $folderName = Str::slug(pathinfo($upload->original_filename, PATHINFO_FILENAME));

                    foreach ($upload->groups as $group) {
                        if ($group->pdf_path && Storage::exists($group->pdf_path)) {
                            $fileContents = Storage::get($group->pdf_path);
                            $fileName = $folderName . '/' . basename($group->pdf_path);
                            $zip->addFromString($fileName, $fileContents);
                        } else {
                            $errors[] = "المجموعة {$group->code} من الملف {$upload->original_filename}";
                        }
                    }
                }
            }

            $zip->close();

            if (!empty($errors)) {
                Log::warning('Some group files were missing during multi-ZIP creation', [
                    'missing_groups' => $errors
                ]);
            }

            if (File::exists($tempPath)) {
                return response()->download($tempPath, $zipFileName)->deleteFileAfterSend(true);
            }
        }

        return redirect()->back()->with('error', 'حدث خطأ أثناء إنشاء ملف ZIP.');
    }

    public function checkStatus($uploadId)
    {
        $upload = Upload::find($uploadId);

        if (!$upload) {
            return response()->json([
                'success' => false,
                'error' => 'الرفع غير موجود'
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => $upload->status,
            'message' => $this->getStatusMessage($upload->status),
            'groups_count' => $upload->groups()->count(),
            'total_pages' => $upload->total_pages,
            'filename' => $upload->original_filename
        ]);
    }

    private function getStatusMessage($status)
    {
        $messages = [
            'pending' => 'في انتظار الرفع',
            'uploading' => 'جاري الرفع',
            'queued' => 'في قائمة الانتظار',
            'processing' => 'جاري المعالجة',
            'completed' => 'تمت المعالجة بنجاح',
            'failed' => 'فشلت المعالجة'
        ];

        return $messages[$status] ?? 'حالة غير معروفة';
    }

    public function destroy(Upload $upload)
    {
        if ($upload->stored_filename) {
            Storage::disk('private')->delete($upload->stored_filename);
        }

        $upload->groups()->each(function($group) {
            if ($group->pdf_path && Storage::exists($group->pdf_path)) {
                Storage::delete($group->pdf_path);
            }
            $group->delete();
        });

        $upload->delete();

        return response()->json([
            'success' => true
        ]);
    }
}
