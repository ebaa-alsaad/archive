<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\{Upload, Group};
use App\Services\BarcodeOCRService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;
use Illuminate\Support\Facades\File;

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
        // إعدادات متقدمة
        ini_set('upload_max_filesize', '200M');
        ini_set('post_max_size', '200M');
        ini_set('max_execution_time', 1800);
        ini_set('max_input_time', 1800);
        ini_set('memory_limit', '2048M');
        ignore_user_abort(true);

        Log::info('Upload request received', [
            'has_file' => $request->hasFile('pdf_file'),
            'file_size' => $request->file('pdf_file')?->getSize(),
            'file_size_mb' => $request->file('pdf_file') ? round($request->file('pdf_file')->getSize() / 1024 / 1024, 2) : 0
        ]);

        try {
            $request->validate([
                'pdf_file' => 'required|mimes:pdf|max:204800' // 200MB
            ]);

            $file = $request->file('pdf_file');
            $fileSizeMB = round($file->getSize() / 1024 / 1024, 2);

            // حفظ الملف مباشرة بدون تأخير
            $storedName = $file->store('uploads', 'private');
            $fullPath = Storage::disk('private')->path($storedName);

            // إنشاء سجل الرفع فوراً
            $upload = Upload::create([
                'original_filename' => $file->getClientOriginalName(),
                'stored_filename' => $storedName,
                'total_pages' => 0, // سيتم تحديثه لاحقاً
                'file_size_mb' => $fileSizeMB,
                'status' => 'processing',
                'user_id' => auth()->id(),
            ]);

            // إرجاع response فوري والبدء في المعالجة في الخلفية
            $response = response()->json([
                'success' => true,
                'message' => 'تم رفع الملف بنجاح، جاري المعالجة...',
                'upload_id' => $upload->id,
                'processing' => true
            ]);

            // إرسال الـ response فوراً
            if (ob_get_level()) ob_end_clean();
            header('Connection: close');
            header('Content-Length: '. strlen($response->getContent()));
            $response->send();

            // بدء المعالجة في الخلفية
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            // المعالجة الفعلية
            $this->processUploadInBackground($upload, $fullPath);

            return $response;

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

    private function processUploadInBackground($upload, $pdfPath)
    {
        try {
            // الحصول على عدد الصفحات
            $pageCount = $this->barcodeService->getPdfPageCount($pdfPath);
            $upload->update(['total_pages' => $pageCount]);

            // معالجة PDF وتقسيم الأقسام
            $groups = $this->barcodeService->processPdf($upload);

            // تحديث حالة الرفع
            $upload->update([
                'status' => 'completed',
                'error_message' => null
            ]);

            Log::info('Background processing completed', ['upload_id' => $upload->id]);

        } catch (\Exception $e) {
            Log::error('Background processing failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage()
            ]);

            $upload->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
        }
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
