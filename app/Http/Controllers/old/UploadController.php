<?php

namespace App\Http\Controllers\old;

use Illuminate\Http\Request;
use App\Models\{Upload, Group};
use App\Services\BarcodeOCRService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    protected $barcodeService;
    protected $BarcodeOCRService;

    public function __construct(BarcodeOCRService $barcodeService, BarcodeOCRService $BarcodeOCRService)
    {
        $this->barcodeService = $barcodeService;
        $this->BarcodeOCRService = $BarcodeOCRService;
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
        $disk = 'local';

        if (empty($path) || !Storage::disk($disk)->exists($path)) {
            abort(404, 'الملف غير موجود أو مساره مفقود في قاعدة البيانات.');
        }

        /** @var \Illuminate\Contracts\Filesystem\Filesystem $diskInstance */
        $diskInstance = Storage::disk($disk);

        return $diskInstance->response($path);
    }

    /**
     * رفع ومعالجة الملف باستخدام الخدمة المناسبة للحجم
     */
    public function store(Request $request)
    {
        // رفع جميع الحدود لاستيعاب 70MB
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
                'pdf_file' => 'required|mimes:pdf|max:71680' // 70MB بالكيلوبايت
            ]);

            $file = $request->file('pdf_file');
            $fileSizeMB = round($file->getSize() / 1024 / 1024, 2);

            Log::info('File validation passed', [
                'name' => $file->getClientOriginalName(),
                'size_mb' => $fileSizeMB
            ]);

            $storedName = $file->store('uploads', 'private');
            Log::info('File stored successfully', ['path' => $storedName]);

            $fullPath = Storage::disk('private')->path($storedName);
            Log::info('File storage verification', [
                'stored_name' => $storedName,
                'full_path' => $fullPath,
                'file_exists' => file_exists($fullPath),
                'file_size' => file_exists($fullPath) ? filesize($fullPath) : 0
            ]);

            // اختيار الخدمة المناسبة بناءً على حجم الملف
            $service = $this->selectService($fileSizeMB);

            $pageCount = $service->getPageCount($fullPath);
            Log::info('PDF page count determined', ['total_pages' => $pageCount]);

            // إنشاء سجل الرفع مع عدد الصفحات
            $upload = Upload::create([
                'original_filename' => $file->getClientOriginalName(),
                'stored_filename' => $storedName,
                'total_pages' => $pageCount,
                'file_size_mb' => $fileSizeMB,
                'status' => 'processing',
                'user_id' => auth()->id(),
            ]);

            Log::info('Upload record created', ['upload_id' => $upload->id]);

            // المعالجة باستخدام الخدمة المختارة
            $groups = $service->processPdf($upload);

            // تحديث حالة الرفع
            $upload->update([
                'status' => 'completed',
                'error_message' => null
            ]);

            // معالجة المجموعات
            $barcodes = [];
            if (!empty($groups) && is_array($groups)) {
                foreach ($groups as $group) {
                    if ($group instanceof Group && !empty($group->code)) {
                        $barcodes[] = $group->code;
                    }
                }
            }

            Log::info('Processing completed successfully', [
                'barcodes_found' => count($barcodes),
                'barcodes' => $barcodes,
                'service_used' => get_class($service)
            ]);

            return response()->json([
                'success' => true,
                'message' => "تمت معالجة الملف بنجاح ({$fileSizeMB} MB). تم العثور على " . count($barcodes) . " باركود",
                'barcodes' => $barcodes,
                'upload_id' => $upload->id,
                'service_used' => get_class($service),
                'redirect_url' => route('uploads.show', $upload)
            ]);

        } catch (\Exception $e) {
            Log::error('Upload processing failed', [
                'error_message' => $e->getMessage(),
                'file_size' => $request->file('pdf_file')?->getSize(),
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

    /**
     * اختيار الخدمة المناسبة بناءً على حجم الملف
     */
    private function selectService($fileSizeMB)
    {
        // للملفات الصغيرة (<10MB) استخدم الخدمة العادية
        if ($fileSizeMB < 10) {
            Log::info("Using standard BarcodeOCRService for small file: {$fileSizeMB}MB");
            return $this->barcodeService;
        }

        // للملفات الكبيرة (>10MB) استخدم الخدمة المتقدمة
        Log::info("Using BarcodeOCRService for large file: {$fileSizeMB}MB");
        return $this->BarcodeOCRService;
    }

    public function destroy(Upload $upload)
    {
        // حذف الملفات المرتبطة
        if ($upload->stored_filename) {
            Storage::disk('private')->delete($upload->stored_filename);
        }

        // حذف المجموعات المرتبطة
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
