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

    /**
     * رفع ومعالجة فورية فائقة السرعة - الحل الجذري
     */
    public function store(Request $request)
    {
        // ⚡ زيادة الحدود القصوى
        ini_set('upload_max_filesize', '500M');
        ini_set('post_max_size', '500M');
        ini_set('max_execution_time', 300);
        ini_set('max_input_time', 300);
        ini_set('memory_limit', '4096M');

        Log::info('⚡ ULTRA FAST Processing Started');

        try {
            $request->validate([
                'pdf_files' => 'required|array',
                'pdf_files.*' => 'required|mimes:pdf|max:512000'
            ]);

            $files = $request->file('pdf_files');
            $results = [];
            $totalSizeMB = 0;

            DB::beginTransaction();

            foreach ($files as $file) {
                $fileSizeMB = round($file->getSize() / 1024 / 1024, 2);
                $totalSizeMB += $fileSizeMB;

                Log::info("Processing file directly", [
                    'filename' => $file->getClientOriginalName(),
                    'size_mb' => $fileSizeMB
                ]);

                // ⚡ معالجة مباشرة فائقة السرعة
                $result = $this->processFileUltraFast($file);
                $results[] = $result;
            }

            DB::commit();

            Log::info("⚡ ALL FILES PROCESSED SUCCESSFULLY", [
                'file_count' => count($files),
                'total_size_mb' => $totalSizeMB
            ]);

            return response()->json([
                'success' => true,
                'message' => "⚡ تم معالجة " . count($files) . " ملف بنجاح في وقت قياسي! ({$totalSizeMB} MB)",
                'results' => $results,
                'file_count' => count($files),
                'total_size_mb' => $totalSizeMB
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('❌ ULTRA FAST Processing Failed', [
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'فشل في المعالجة: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * معالجة فائقة السرعة - بدون تخزين مؤقت
     */
    private function processFileUltraFast($file)
    {
        $tempPath = null;
        $upload = null;

        try {
            // ⚡ حفظ مؤقت سريع جداً
            $tempPath = $file->store('temp_ultrafast', 'private');
            $fullPath = Storage::disk('private')->path($tempPath);

            // إنشاء سجل سريع في قاعدة البيانات
            $upload = Upload::create([
                'original_filename' => $file->getClientOriginalName(),
                'stored_filename' => $tempPath,
                'file_size' => $file->getSize(),
                'total_pages' => 0,
                'status' => 'processing',
                'user_id' => auth()->id(),
            ]);

            Log::info("Starting ultra fast processing", [
                'upload_id' => $upload->id,
                'filename' => $file->getClientOriginalName()
            ]);

            // ⚡ معالجة مباشرة فائقة السرعة
            $processingResult = $this->barcodeService->processPdfUltraFast($upload, $fullPath);

            // تحديث حالة الرفع
            $upload->update([
                'status' => 'completed',
                'total_pages' => $processingResult['total_pages'] ?? 0,
                'processed_at' => now()
            ]);

            // ⚡ تنظيف فوري - حذف الملف المؤقت
            if ($tempPath && Storage::disk('private')->exists($tempPath)) {
                Storage::disk('private')->delete($tempPath);
                Log::debug("Temporary file cleaned", ['temp_path' => $tempPath]);
            }

            $result = [
                'filename' => $file->getClientOriginalName(),
                'upload_id' => $upload->id,
                'groups_count' => count($processingResult['groups'] ?? []),
                'total_pages' => $processingResult['total_pages'] ?? 0,
                'groups' => $processingResult['groups'] ?? []
            ];

            Log::info("File processed successfully", $result);

            return $result;

        } catch (\Exception $e) {
            // تنظيف في حالة الخطأ
            if ($tempPath && Storage::disk('private')->exists($tempPath)) {
                Storage::disk('private')->delete($tempPath);
            }

            if ($upload) {
                $upload->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ]);
            }

            Log::error('Ultra fast processing failed', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * تحميل النتائج فائقة السرعة
     */
    public function downloadResults(Request $request)
    {
        try {
            $uploadIds = $request->input('upload_ids', []);

            if (empty($uploadIds)) {
                return response()->json(['error' => 'لم يتم توفير معرّفات الملفات'], 400);
            }

            $uploads = Upload::with('groups')->whereIn('id', $uploadIds)->get();
            $allGroups = [];

            foreach ($uploads as $upload) {
                if ($upload->status === 'completed') {
                    foreach ($upload->groups as $group) {
                        $allGroups[] = $group;
                    }
                }
            }

            if (empty($allGroups)) {
                return response()->json(['error' => 'لا توجد مجموعات للتحميل'], 404);
            }

            $zip = new ZipArchive;
            $zipFileName = 'processed_results_' . time() . '.zip';
            $tempPath = storage_path('app/temp/' . $zipFileName);

            if (!File::isDirectory(storage_path('app/temp'))) {
                File::makeDirectory(storage_path('app/temp'), 0775, true);
            }

            if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                $addedFiles = 0;

                foreach ($allGroups as $group) {
                    if ($group->pdf_path && Storage::exists($group->pdf_path)) {
                        $fileContents = Storage::get($group->pdf_path);
                        $fileName = 'group_' . $group->id . '_' . basename($group->pdf_path);
                        $zip->addFromString($fileName, $fileContents);
                        $addedFiles++;
                    }
                }

                $zip->close();

                Log::info("ZIP created successfully", [
                    'files_count' => $addedFiles,
                    'zip_size' => file_exists($tempPath) ? filesize($tempPath) : 0
                ]);

                if (File::exists($tempPath) && $addedFiles > 0) {
                    return response()->download($tempPath, $zipFileName)->deleteFileAfterSend(true);
                }
            }

            return response()->json(['error' => 'فشل في إنشاء ملف ZIP أو لا توجد ملفات للتحميل'], 500);

        } catch (\Exception $e) {
            Log::error('Download results failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * التحقق من حالة الملفات
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

        $uploads = Upload::withCount('groups')->whereIn('id', $uploadIds)->get();

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
                'groups_count' => $upload->groups_count,
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

            $totalGroups += $upload->groups_count;
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
            'progress_percentage' => count($uploadIds) > 0 ? round(($completedCount / count($uploadIds)) * 100) : 0
        ]);
    }

    /**
     * تحميل ZIP لجميع الملفات
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
            $addedFiles = 0;

            foreach ($uploads as $upload) {
                if ($upload->status === 'completed' && $upload->groups->isNotEmpty()) {
                    $folderName = Str::slug(pathinfo($upload->original_filename, PATHINFO_FILENAME));

                    foreach ($upload->groups as $group) {
                        if ($group->pdf_path && Storage::exists($group->pdf_path)) {
                            $fileContents = Storage::get($group->pdf_path);
                            $fileName = $folderName . '/' . basename($group->pdf_path);
                            $zip->addFromString($fileName, $fileContents);
                            $addedFiles++;
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

            if (File::exists($tempPath) && $addedFiles > 0) {
                return response()->download($tempPath, $zipFileName)->deleteFileAfterSend(true);
            }
        }

        return redirect()->back()->with('error', 'حدث خطأ أثناء إنشاء ملف ZIP أو لا توجد ملفات للتحميل.');
    }

    public function checkStatus($uploadId)
    {
        $upload = Upload::withCount('groups')->find($uploadId);

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
            'groups_count' => $upload->groups_count,
            'total_pages' => $upload->total_pages,
            'filename' => $upload->original_filename
        ]);
    }

    private function getStatusMessage($status)
    {
        $messages = [
            'pending' => 'في انتظار الرفع',
            'uploading' => 'جاري الرفع',
            'processing' => 'جاري المعالجة',
            'completed' => 'تمت المعالجة بنجاح',
            'failed' => 'فشلت المعالجة'
        ];

        return $messages[$status] ?? 'حالة غير معروفة';
    }

    public function destroy(Upload $upload)
    {
        try {
            // حذف الملف الأصلي إذا موجود
            if ($upload->stored_filename && Storage::disk('private')->exists($upload->stored_filename)) {
                Storage::disk('private')->delete($upload->stored_filename);
            }

            // حذف ملفات المجموعات
            $upload->groups()->each(function($group) {
                if ($group->pdf_path && Storage::exists($group->pdf_path)) {
                    Storage::delete($group->pdf_path);
                }
                $group->delete();
            });

            $upload->delete();

            return response()->json([
                'success' => true,
                'message' => 'تم الحذف بنجاح'
            ]);

        } catch (\Exception $e) {
            Log::error('Delete upload failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'فشل في الحذف: ' . $e->getMessage()
            ], 500);
        }
    }

    // الدوال المساعدة للتوافق مع الواجهة القديمة
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
}
