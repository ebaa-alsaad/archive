<?php

namespace App\Http\Controllers;

use ZipArchive;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Jobs\ProcessUploadJob;
use App\Models\{Upload, Group};
use App\Services\BarcodeOCRService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

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

        // المسار المؤقت لملف ZIP
        $tempPath = storage_path('app/temp/' . $zipFileName);

        // إنشاء دليل مؤقت إذا لم يكن موجوداً
        if (!File::isDirectory(storage_path('app/temp'))) {
            File::makeDirectory(storage_path('app/temp'), 0755, true);
        }

        if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {

            $errors = [];

            // إضافة كل ملف PDF ناتج إلى ملف ZIP
            foreach ($upload->groups as $group) {
                if ($group->pdf_path && Storage::exists($group->pdf_path)) {
                    // يجب قراءة محتوى الملفات من الـ Storage
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

     // تهيئة رفع جديد
    public function initUpload(Request $request)
    {
        $fileName = $request->get('name');
        $fileSize = $request->get('size');

        $storedName = 'uploads/tmp/' . uniqid() . '_' . $fileName;

        // إنشاء سجل مؤقت في DB
        $upload = Upload::create([
            'original_filename' => $fileName,
            'stored_filename' => $storedName,
            'status' => 'queued',
            'total_pages' => 0,
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'upload_id' => $upload->id,
            'stored_filename' => $storedName
        ]);
    }

    // رفع Chunk
    public function uploadChunk(Request $request)
    {
        $uploadId = $request->get('upload_id');
        $upload = Upload::findOrFail($uploadId);

        $chunk = $request->file('file');
        $offset = (int)$request->get('offset', 0);

        Storage::disk('private')->append($upload->stored_filename, file_get_contents($chunk));

        // إذا اكتمل الملف
        if ($request->get('is_last_chunk')) {
            $upload->update(['status' => 'processing']);

            // دفع Job للمعالجة الثقيلة
            ProcessUploadJob::dispatch($upload);
        }

        return response()->json(['success' => true]);
    }

    public function store(Request $request)
    {
        ini_set('upload_max_filesize', '250M');
        ini_set('post_max_size', '250M');
        ini_set('max_execution_time', 1200);
        ini_set('max_input_time', 1200);
        ini_set('memory_limit', '1024M');

        Log::info('Upload request received', [
            'has_file' => $request->hasFile('pdf_file'),
            'file_size' => $request->file('pdf_file')?->getSize(),
        ]);

        try {
            $request->validate([
                'pdf_file' => 'required|mimes:pdf|max:256000'
            ]);

            $file = $request->file('pdf_file');
            $fileSizeMB = round($file->getSize() / 1024 / 1024, 2);

            $storedName = $file->store('uploads', 'private');
            $fullPath = Storage::disk('private')->path($storedName);

            $upload = Upload::create([
                'original_filename' => $file->getClientOriginalName(),
                'stored_filename' => $storedName,
                'total_pages' => 0,
                'status' => 'processing',
                'user_id' => auth()->id(),
            ]);

            // إرجاع الرد فوراً
            return response()->json([
                'success' => true,
                'message' => "تم رفع الملف بنجاح ({$fileSizeMB} MB). جاري المعالجة...",
                'upload_id' => $upload->id,
                'status' => 'processing'
            ]);

        } catch (\Exception $e) {
            Log::error('Upload failed', [
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'فشل في رفع الملف: ' . $e->getMessage()
            ], 500);
        }
    }

    public function process($uploadId)
    {
        try {
            $upload = Upload::findOrFail($uploadId);

            if ($upload->status !== 'processing') {
                return response()->json([
                    'success' => false,
                    'error' => 'الملف ليس في حالة معالجة. الحالة الحالية: ' . $upload->status
                ]);
            }

            $fullPath = Storage::disk('private')->path($upload->stored_filename);

            // الحصول على عدد الصفحات
            $pageCount = $this->barcodeService->getPdfPageCount($fullPath);
            $upload->update(['total_pages' => $pageCount]);

            // معالجة PDF
            $groups = $this->barcodeService->processPdf($upload, 'private');


            $upload->update([
                'status' => 'completed',
                'error_message' => null
            ]);

            $barcodes = [];
            foreach ($groups as $group) {
                if ($group instanceof Group && !empty($group->code)) {
                    $barcodes[] = $group->code;
                }
            }

            Log::info('Processing completed successfully', [
                'upload_id' => $upload->id,
                'groups_count' => count($groups)
            ]);

            return response()->json([
                'success' => true,
                'message' => "تمت معالجة الملف بنجاح. تم إنشاء " . count($groups) . " قسم.",
                'groups_count' => count($groups),
                'total_pages' => $pageCount,
                'barcodes' => $barcodes,
                'group_files' => array_map(fn($g) => $g->pdf_path, $groups)
            ]);

        } catch (\Exception $e) {
            Log::error('Processing failed', [
                'upload_id' => $uploadId,
                'error_message' => $e->getMessage()
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
            'total_pages' => $upload->total_pages
        ]);
    }

    private function getStatusMessage($status)
    {
        $messages = [
            'processing' => 'جاري معالجة الملف...',
            'completed' => 'تمت المعالجة بنجاح',
            'failed' => 'فشلت المعالجة',
            'queued' => 'في قائمة الانتظار'
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
