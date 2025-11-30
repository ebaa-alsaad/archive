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
use App\Services\UltraFastProcessingService;

class UploadController extends Controller
{
    protected $barcodeService;
    protected $fastProcessingService;

    public function __construct(BarcodeOCRService $barcodeService, UltraFastProcessingService $fastProcessingService)
    {
        $this->barcodeService = $barcodeService;
        $this->fastProcessingService = $fastProcessingService;
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
     * رفع ومعالجة فائقة السرعة - النسخة المحسنة
     */
    public function store(Request $request)
    {
        // ⚡ إعدادات واقعية بناءً على مساحة tmpfs
        ini_set('upload_max_filesize', '100M');
        ini_set('post_max_size', '100M');
        ini_set('max_execution_time', 180);
        ini_set('max_input_time', 180);
        ini_set('memory_limit', '512M');
        ini_set('max_file_uploads', 10);

        // استخدام tmpfs للملفات المؤقتة
        ini_set('upload_tmp_dir', '/tmp/ultrafast_processing');

        Log::info('🚀 ULTRA FAST Processing Started - Enhanced Version');

        try {
            $request->validate([
                'pdf_files' => 'required|array',
                'pdf_files.*' => 'required|mimes:pdf|max:102400' // 100MB واقعي
            ]);

            $files = $request->file('pdf_files');
            $totalFiles = count($files);
            $totalSizeMB = 0;

            // فحص مساحة tmpfs المتاحة
            $tmpfsStatus = $this->fastProcessingService->getTmpfsStatus();
            Log::info("Tmpfs Status Before Processing", $tmpfsStatus);

            if ($tmpfsStatus['free_mb'] < 50) { // أقل من 50MB متاحة
                throw new \Exception('مساحة التخزين المؤقت غير كافية. يرجى المحاولة لاحقاً.');
            }

            DB::beginTransaction();

            // ⚡ معالجة بالدفعات لتحسين الأداء
            $results = $this->fastProcessingService->processBatch(
                $files,
                2, // معالجة ملفين في كل دفعة
                function($file) use (&$totalSizeMB) {
                    return $this->processFileUltraFastEnhanced($file, $totalSizeMB);
                }
            );

            DB::commit();

            Log::info("🎉 ALL FILES PROCESSED SUCCESSFULLY - Enhanced", [
                'file_count' => $totalFiles,
                'total_size_mb' => $totalSizeMB,
                'tmpfs_status_after' => $this->fastProcessingService->getTmpfsStatus()
            ]);

            return response()->json([
                'success' => true,
                'message' => "⚡ تم معالجة {$totalFiles} ملف بنجاح في وقت قياسي! ({$totalSizeMB} MB)",
                'results' => $results,
                'file_count' => $totalFiles,
                'total_size_mb' => $totalSizeMB,
                'processing_time' => round(microtime(true) - LARAVEL_START, 2)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('❌ ULTRA FAST Processing Failed - Enhanced', [
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
     * معالجة محسنة فائقة السرعة باستخدام tmpfs
     */
    private function processFileUltraFastEnhanced($file, &$totalSizeMB)
    {
        $tmpfsInfo = null;
        $upload = null;

        try {
            $fileSizeMB = round($file->getSize() / 1024 / 1024, 2);
            $totalSizeMB += $fileSizeMB;

            Log::info("🚀 Processing file with TMPFS", [
                'filename' => $file->getClientOriginalName(),
                'size_mb' => $fileSizeMB
            ]);

            // ⚡ استخدام tmpfs بدلاً من نظام التخزين العادي
            $tmpfsInfo = $this->fastProcessingService->storeInTmpfs($file);
            $fullPath = $tmpfsInfo['path'];

            // إنشاء سجل سريع في قاعدة البيانات
            $upload = Upload::create([
                'original_filename' => $file->getClientOriginalName(),
                'stored_filename' => $tmpfsInfo['path'], // تخزين مسار tmpfs
                'file_size' => $file->getSize(),
                'total_pages' => 0,
                'status' => 'processing',
                'user_id' => auth()->id(),
            ]);

            Log::info("Starting enhanced ultra fast processing", [
                'upload_id' => $upload->id,
                'filename' => $file->getClientOriginalName(),
                'tmpfs_path' => $tmpfsInfo['path']
            ]);

            // ⚡ معالجة مباشرة من tmpfs
            $processingResult = $this->barcodeService->processPdfUltraFast($upload, $fullPath);

            // تحديث حالة الرفع
            $upload->update([
                'status' => 'completed',
                'total_pages' => $processingResult['total_pages'] ?? 0,
                'processed_at' => now()
            ]);

            $result = [
                'filename' => $file->getClientOriginalName(),
                'upload_id' => $upload->id,
                'groups_count' => count($processingResult['groups'] ?? []),
                'total_pages' => $processingResult['total_pages'] ?? 0,
                'groups' => $processingResult['groups'] ?? [],
                'file_size_mb' => $fileSizeMB
            ];

            Log::info("File processed successfully with TMPFS", $result);

            return $result;

        } catch (\Exception $e) {
            // تنظيف في حالة الخطأ
            if ($tmpfsInfo && file_exists($tmpfsInfo['path'])) {
                $this->fastProcessingService->cleanupTmpfs($tmpfsInfo['path']);
            }

            if ($upload) {
                $upload->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ]);
            }

            Log::error('Enhanced ultra fast processing failed', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        } finally {
            // ⚡ تنظيف مؤكد للملف المؤقت من tmpfs
            if ($tmpfsInfo && file_exists($tmpfsInfo['path'])) {
                $this->fastProcessingService->cleanupTmpfs($tmpfsInfo['path']);
                Log::debug("TMPFS file cleaned", ['tmpfs_path' => $tmpfsInfo['path']]);
            }
        }
    }

    /**
     * تحميل النتائج فائقة السرعة - النسخة المحسنة
     */
    public function downloadResults(Request $request)
    {
        try {
            $uploadIds = $request->input('upload_ids', []);

            if (empty($uploadIds)) {
                return response()->json(['error' => 'لم يتم توفير معرّفات الملفات'], 400);
            }

            // استخدام Redis لل cache إذا متوفر
            $cacheKey = 'download_results_' . md5(implode(',', $uploadIds));
            $cachedResult = Redis::get($cacheKey);

            if ($cachedResult) {
                Log::info("Serving download from cache", ['cache_key' => $cacheKey]);
                return response()->json(json_decode($cachedResult, true));
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
                    // cache النتيجة لمدة 5 دقائق
                    Redis::setex($cacheKey, 300, json_encode([
                        'success' => true,
                        'download_url' => url('download/temp/' . $zipFileName)
                    ]));

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
     * التحقق من حالة الملفات - النسخة المحسنة
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

        // استخدام Redis لل cache
        $cacheKey = 'multi_status_' . md5(implode(',', $uploadIds));
        $cachedResult = Redis::get($cacheKey);

        if ($cachedResult) {
            return response()->json(json_decode($cachedResult, true));
        }

        $uploads = Upload::withCount('groups')->whereIn('id', $uploadIds)->get();

        $statuses = [];
        $allCompleted = true;
        $anyFailed = false;
        $totalGroups = 0;
        $totalPages = 0;
        $completedCount = 0;
        $totalSizeMB = 0;

        foreach ($uploads as $upload) {
            $fileSizeMB = round($upload->file_size / 1024 / 1024, 2);
            $totalSizeMB += $fileSizeMB;

            $statuses[] = [
                'id' => $upload->id,
                'filename' => $upload->original_filename,
                'status' => $upload->status,
                'message' => $this->getStatusMessage($upload->status),
                'groups_count' => $upload->groups_count,
                'total_pages' => $upload->total_pages,
                'file_size' => $upload->file_size,
                'file_size_mb' => $fileSizeMB,
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

        $result = [
            'success' => true,
            'statuses' => $statuses,
            'all_completed' => $allCompleted,
            'any_failed' => $anyFailed,
            'total_groups' => $totalGroups,
            'total_pages' => $totalPages,
            'total_size_mb' => round($totalSizeMB, 2),
            'processed_files' => $completedCount,
            'total_files' => count($uploadIds),
            'progress_percentage' => count($uploadIds) > 0 ? round(($completedCount / count($uploadIds)) * 100) : 0,
            'tmpfs_status' => $this->fastProcessingService->getTmpfsStatus()
        ];

        // cache النتيجة لمدة 10 ثوانٍ
        Redis::setex($cacheKey, 10, json_encode($result));

        return response()->json($result);
    }

    /**
     * تحميل ZIP لجميع الملفات - النسخة المحسنة
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

        // استخدام tmpfs للملفات المؤقتة الكبيرة
        $tempPath = '/tmp/ultrafast_processing/' . $zipFileName;

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

    // ... باقي الدوال تبقى كما هي (checkStatus, getStatusMessage, destroy, etc.)

    public function checkStatus($uploadId)
    {
        $cacheKey = 'upload_status_' . $uploadId;
        $cachedResult = Redis::get($cacheKey);

        if ($cachedResult) {
            return response()->json(json_decode($cachedResult, true));
        }

        $upload = Upload::withCount('groups')->find($uploadId);

        if (!$upload) {
            return response()->json([
                'success' => false,
                'error' => 'الرفع غير موجود'
            ]);
        }

        $result = [
            'success' => true,
            'status' => $upload->status,
            'message' => $this->getStatusMessage($upload->status),
            'groups_count' => $upload->groups_count,
            'total_pages' => $upload->total_pages,
            'filename' => $upload->original_filename,
            'file_size_mb' => round($upload->file_size / 1024 / 1024, 2)
        ];

        Redis::setex($cacheKey, 5, json_encode($result));

        return response()->json($result);
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
            if ($upload->stored_filename && file_exists($upload->stored_filename)) {
                unlink($upload->stored_filename);
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

        // إذا كان الملف في tmpfs
        if (strpos($path, '/tmp/ultrafast_processing') === 0 && file_exists($path)) {
            return response()->file($path);
        }

        // إذا كان الملف في التخزين العادي
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
        $tempPath = '/tmp/ultrafast_processing/' . $zipFileName;

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
     * دالة جديدة: الحصول على حالة النظام
     */
    public function getSystemStatus()
    {
        return response()->json([
            'success' => true,
            'system' => [
                'tmpfs_status' => $this->fastProcessingService->getTmpfsStatus(),
                'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'load_average' => sys_getloadavg(),
                'disk_free_space' => round(disk_free_space('/') / 1024 / 1024, 2)
            ]
        ]);
    }
}
