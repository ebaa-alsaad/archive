<?php

namespace App\Http\Controllers;

use ZipArchive;
use App\Jobs\ProcessPdfJob;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
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

     // الدالة الجديدة لتحميل كل الملفات الناتجة كملف ZIP
    public function downloadAllGroupsZip(Upload $upload)
    {
        // 1. التحقق من حالة المعالجة ووجود مجموعات
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

                    // استخدام اسم الملف الناتج كما هو محفوظ
                    $zip->addFromString(basename($group->pdf_path), $fileContents);
                } else {
                    $errors[] = $group->code;
                }
            }

            $zip->close();

            // إذا كانت هناك أخطاء، قم بتسجيلها
            if (!empty($errors)) {
                Log::warning('Some group files were missing during ZIP creation.', ['upload_id' => $upload->id, 'missing_groups' => $errors]);
            }

            // إرسال ملف ZIP للمستخدم
            if (File::exists($tempPath)) {
                $response = response()->download($tempPath, $zipFileName)->deleteFileAfterSend(true);
                return $response;
            }
        }

        return redirect()->back()->with('error', 'حدث خطأ أثناء إنشاء ملف ZIP.');
    }

    public function store(Request $request)
    {
        ini_set('upload_max_filesize', '70M');
        ini_set('post_max_size', '70M');
        ini_set('max_execution_time', 600);
        ini_set('max_input_time', 600);
        ini_set('memory_limit', '512M');

        Log::info('Upload request received', [
            'has_file' => $request->hasFile('pdf_file'),
            'file_size' => $request->file('pdf_file')?->getSize(),
            'file_size_mb' => $request->file('pdf_file') ? round($request->file('pdf_file')->getSize() / 1024 / 1024, 2) : 0
        ]);

        try {
            $request->validate([
                'pdf_file' => 'required|mimes:pdf|max:71680'
            ]);

            $file = $request->file('pdf_file');
            $fileSizeMB = round($file->getSize() / 1024 / 1024, 2);

            $storedName = $file->store('uploads', 'private');
            $fullPath = Storage::disk('private')->path($storedName);

            // الحصول على عدد الصفحات
            $pageCount = $this->barcodeService->getPdfPageCount($fullPath);

            // إنشاء سجل الرفع
            $upload = Upload::create([
                'original_filename' => $file->getClientOriginalName(),
                'stored_filename' => $storedName,
                'total_pages' => $pageCount,
                'file_size_mb' => $fileSizeMB,
                'status' => 'processing',
                'user_id' => auth()->id(),
            ]);

            // معالجة PDF وتقسيم الأقسام
            $groups = $this->barcodeService->processPdf($upload);

            // تحديث حالة الرفع
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

            Log::info('Processing completed successfully');

            return response()->json([
                'success' => true,
                'message' => "تمت معالجة الملف بنجاح ({$fileSizeMB} MB). تم إنشاء " . count($groups) . " قسم.",
                'barcodes' => $barcodes,
                'upload_id' => $upload->id,
                'service_used' => get_class($this->barcodeService),
                'redirect_url' => route('uploads.show', $upload)
            ]);

        } catch (\Exception $e) {
            Log::error('Upload processing failed', [
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
                'error' => 'فشل في معالجة الملف: ' . $e->getMessage()
            ], 500);
        }
    }

    public function progress($uploadId)
    {
        $progress = Redis::get("upload_progress:{$uploadId}") ?? 0;

        return response()->json([
            'progress' => (int)$progress,
            'status' => Upload::find($uploadId)->status ?? 'unknown'
        ]);
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

        return redirect()->route('uploads.index')
            ->with('success', 'تم حذف الملف والبيانات المرتبطة به بنجاح');
    }
}
