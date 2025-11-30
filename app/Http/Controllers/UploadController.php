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

    /**
     * عرض قائمة الرفوعات
     */
    public function index()
    {
        $uploads = Upload::with(['user', 'groups'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('uploads.index', compact('uploads'));
    }

    /**
     * عرض صفحة إنشاء رفع جديد
     */
    public function create()
    {
        return view('uploads.create');
    }

    /**
     * عرض تفاصيل رفع معين
     */
    public function show(Upload $upload)
    {
        $upload->load(['groups', 'user']);
        return view('uploads.show', compact('upload'));
    }

    /**
     * رفع ومعالجة مبسطة وموثوقة
     */
    public function store(Request $request)
    {
        // إعدادات واقعية
        ini_set('upload_max_filesize', '50M');
        ini_set('post_max_size', '50M');
        ini_set('max_execution_time', 120);
        ini_set('memory_limit', '256M');

        Log::info('🚀 RELIABLE Processing Started');

        try {
            $request->validate([
                'pdf_files' => 'required|array',
                'pdf_files.*' => 'required|mimes:pdf|max:51200' // 50MB
            ]);

            $files = $request->file('pdf_files');
            $results = [];
            $totalSizeMB = 0;

            Log::info("📁 Files received", ['count' => count($files)]);

            // معالجة كل ملف على حدة
            foreach ($files as $index => $file) {
                Log::info("🔄 Processing file {$index}", [
                    'filename' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'temp_path' => $file->getPathname()
                ]);

                $result = $this->processFileReliable($file, $totalSizeMB, $index);
                $results[] = $result;

                $progress = round(($index + 1) / count($files) * 100);
                Log::info("📊 Progress update", ['progress' => $progress, 'file_index' => $index]);
            }

            Log::info("✅ ALL FILES PROCESSED SUCCESSFULLY", [
                'file_count' => count($files),
                'total_size_mb' => $totalSizeMB
            ]);

            return response()->json([
                'success' => true,
                'message' => "تم معالجة " . count($files) . " ملف بنجاح! ({$totalSizeMB} MB)",
                'results' => $results,
                'file_count' => count($files),
                'total_size_mb' => $totalSizeMB
            ]);

        } catch (\Exception $e) {
            Log::error('❌ RELIABLE Processing Failed', [
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
     * معالجة ملف موثوقة - بدون استخدام move_uploaded_file
     */
    private function processFileReliable($file, &$totalSizeMB, $fileIndex)
    {
        $upload = null;
        $storedPath = null;

        try {
            // ⚡ الحصول على المعلومات قبل أي عملية نقل
            $fileSize = $file->getSize();
            $fileSizeMB = round($fileSize / 1024 / 1024, 2);
            $totalSizeMB += $fileSizeMB;
            $originalName = $file->getClientOriginalName();

            Log::info("📄 File details", [
                'filename' => $originalName,
                'size_bytes' => $fileSize,
                'size_mb' => $fileSizeMB,
                'file_index' => $fileIndex
            ]);

            // ⚡ استخدام طريقة Laravel الآمنة لحفظ الملف
            $storedPath = $file->store('uploads', 'private');
            $fullPath = Storage::disk('private')->path($storedPath);

            Log::info("💾 File stored successfully", [
                'stored_path' => $storedPath,
                'full_path' => $fullPath,
                'file_exists' => file_exists($fullPath) ? 'yes' : 'no'
            ]);

            // التحقق من أن الملف محفوظ فعلياً
            if (!Storage::disk('private')->exists($storedPath)) {
                throw new \Exception("Failed to store file: {$originalName}");
            }

            // إنشاء سجل في قاعدة البيانات
            $upload = Upload::create([
                'original_filename' => $originalName,
                'stored_filename' => $storedPath,
                'file_size' => $fileSize,
                'total_pages' => 0,
                'status' => 'processing',
                'user_id' => auth()->id(),
            ]);

            Log::info("🗃️ Database record created", [
                'upload_id' => $upload->id,
                'filename' => $originalName
            ]);

            // معالجة الملف
            Log::info("⚡ Starting PDF processing", ['upload_id' => $upload->id]);
            $processingResult = $this->barcodeService->processPdf($upload, 'private');

            // تحديث الحالة
            $upload->update([
                'status' => 'completed',
                'total_pages' => $processingResult['total_pages'] ?? 0,
                'processed_at' => now()
            ]);

            $result = [
                'filename' => $originalName,
                'upload_id' => $upload->id,
                'groups_count' => count($processingResult['groups'] ?? []),
                'total_pages' => $processingResult['total_pages'] ?? 0,
                'file_size_mb' => $fileSizeMB
            ];

            Log::info("✅ File processed successfully", $result);

            return $result;

        } catch (\Exception $e) {
            // تنظيف في حالة الخطأ
            if ($storedPath && Storage::disk('private')->exists($storedPath)) {
                Storage::disk('private')->delete($storedPath);
                Log::info("🧹 Cleaned up stored file due to error", ['path' => $storedPath]);
            }

            if ($upload) {
                $upload->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ]);
            }

            Log::error('❌ File processing failed', [
                'filename' => $file->getClientOriginalName(),
                'file_index' => $fileIndex,
                'error' => $e->getMessage(),
                'stored_path' => $storedPath
            ]);

            throw $e;
        }
    }

    /**
     * التحقق من حالة ملف واحد
     */
    public function checkStatus($uploadId)
    {
        try {
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
                'filename' => $upload->original_filename,
                'file_size_mb' => round($upload->file_size / 1024 / 1024, 2),
                'created_at' => $upload->created_at->format('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            Log::error('Check status failed', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'فشل في التحقق من الحالة'
            ], 500);
        }
    }

    /**
     * التحقق من حالة عدة ملفات
     */
    public function checkMultiStatus(Request $request)
    {
        try {
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

            return response()->json([
                'success' => true,
                'statuses' => $statuses,
                'all_completed' => $allCompleted,
                'any_failed' => $anyFailed,
                'total_groups' => $totalGroups,
                'total_pages' => $totalPages,
                'total_size_mb' => round($totalSizeMB, 2),
                'processed_files' => $completedCount,
                'total_files' => count($uploadIds),
                'progress_percentage' => count($uploadIds) > 0 ? round(($completedCount / count($uploadIds)) * 100) : 0
            ]);

        } catch (\Exception $e) {
            Log::error('Check multi status failed', [
                'upload_ids' => $uploadIds,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'فشل في التحقق من الحالة'
            ], 500);
        }
    }

    /**
     * تحميل نتائج المعالجة
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
     * تحميل ZIP لجميع الملفات
     */
    public function downloadMultiZip(Request $request)
    {
        try {
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

        } catch (\Exception $e) {
            Log::error('Download multi zip failed', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'حدث خطأ أثناء إنشاء ملف ZIP.');
        }
    }

    /**
     * حذف رفع
     */
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

            Log::info("Upload deleted successfully", ['upload_id' => $upload->id]);

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

    /**
     * عرض الملف الأصلي
     */
    public function showFile(Upload $upload)
    {
        try {
            $path = $upload->stored_filename;
            $disk = 'private';

            if (empty($path) || !Storage::disk($disk)->exists($path)) {
                abort(404, 'الملف غير موجود أو مساره مفقود في قاعدة البيانات.');
            }

            return Storage::disk($disk)->response($path);

        } catch (\Exception $e) {
            Log::error('Show file failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage()
            ]);

            abort(404, 'الملف غير موجود.');
        }
    }

    /**
     * تحميل ZIP لجميع مجموعات رفع معين
     */
    public function downloadAllGroupsZip(Upload $upload)
    {
        try {
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
                    Log::warning('Some group files were missing during ZIP creation.', [
                        'upload_id' => $upload->id,
                        'missing_groups' => $errors
                    ]);
                }

                if (File::exists($tempPath)) {
                    $response = response()->download($tempPath, $zipFileName)->deleteFileAfterSend(true);
                    return $response;
                }
            }

            return redirect()->back()->with('error', 'حدث خطأ أثناء إنشاء ملف ZIP.');

        } catch (\Exception $e) {
            Log::error('Download all groups zip failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()->with('error', 'حدث خطأ أثناء إنشاء ملف ZIP.');
        }
    }

    /**
     * الحصول على حالة النظام
     */
    public function getSystemStatus()
    {
        try {
            $systemInfo = [
                'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'disk_free_space' => round(disk_free_space('/') / 1024 / 1024, 2),
                'disk_total_space' => round(disk_total_space('/') / 1024 / 1024, 2),
                'timestamp' => now()->toDateTimeString()
            ];

            // محاولة الحصول على متوسط التحميل إذا كان النظام يدعمه
            if (function_exists('sys_getloadavg')) {
                $systemInfo['load_average'] = sys_getloadavg();
            }

            return response()->json([
                'success' => true,
                'system' => $systemInfo
            ]);

        } catch (\Exception $e) {
            Log::error('System status check failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'فشل في فحص حالة النظام'
            ], 500);
        }
    }

    /**
     * رسائل حالة المعالجة
     */
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

    /**
     * تنظيف الملفات المؤقتة القديمة
     */
    public function cleanupTempFiles()
    {
        try {
            $tempDir = storage_path('app/temp');
            $deletedCount = 0;

            if (File::isDirectory($tempDir)) {
                $files = File::files($tempDir);
                $now = time();

                foreach ($files as $file) {
                    // حذف الملفات الأقدم من ساعة
                    if ($now - filemtime($file) > 3600) {
                        File::delete($file);
                        $deletedCount++;
                    }
                }
            }

            Log::info("Temp files cleanup completed", ['deleted_count' => $deletedCount]);

            return response()->json([
                'success' => true,
                'message' => "تم تنظيف {$deletedCount} ملف مؤقت",
                'deleted_count' => $deletedCount
            ]);

        } catch (\Exception $e) {
            Log::error('Temp files cleanup failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'فشل في تنظيف الملفات المؤقتة'
            ], 500);
        }
    }
}
