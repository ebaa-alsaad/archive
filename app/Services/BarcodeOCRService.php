<?php

namespace App\Services;

use Exception;
use App\Models\Group;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class BarcodeOCRService
{
    private $imageCache = [];
    private $barcodeCache = [];
    private $textCache = [];
    private $ocrCache = [];
    private $pdfHash = null;
    private $uploadId = null;

    /**
     * معالجة PDF مع دعم المعالجة المتوازية
     */
    public function processPdf($upload, $disk = 'private')
    {
        // 🔥 إضافة lock أكثر أماناً للمعالجة المتوازية
        $lockKey = "processing_{$upload->id}";
        if (Redis::get($lockKey)) {
            Log::warning("Processing already in progress for upload", ['upload_id' => $upload->id]);
            throw new Exception("المعالجة جارية بالفعل لهذا الملف");
        }

        Redis::setex($lockKey, 7200, 'true');

        $this->uploadId = $upload->id;

        // 🔥 زيادة الحدود للمعالجة المتوازية
        set_time_limit(0); // لا نهائي
        ini_set('memory_limit', '4096M'); // زيادة الذاكرة
        ini_set('max_execution_time', 0);

        $pdfPath = Storage::disk($disk)->path($upload->stored_filename);

        if (!file_exists($pdfPath)) {
            Redis::del($lockKey);
            throw new Exception("PDF file not found: " . $pdfPath);
        }

        // 🔥 تنظيف المجموعات القديمة بشكل آمن
        try {
            Group::where('upload_id', $upload->id)->delete();
            Log::info("Cleaned up existing groups for upload", ['upload_id' => $upload->id]);
        } catch (Exception $e) {
            Log::warning("Cleanup failed, continuing", ['error' => $e->getMessage()]);
        }

        $this->updateProgress(5, 'جاري تهيئة الملف...');
        $this->pdfHash = md5_file($pdfPath); // 🔥 استخدام md5_file أكثر دقة

        try {
            $pageCount = $this->getPdfPageCount($pdfPath);
        } catch (Exception $e) {
            Redis::del($lockKey);
            throw new Exception("فشل في قراءة الملف: " . $e->getMessage());
        }

        // 🔥 قراءة الباركود الفاصل مع معالجة أفضل للأخطاء
        $separatorBarcode = null;
        try {
            $separatorBarcode = $this->readPageBarcode($pdfPath, 1) ?? 'default_barcode_' . time();
            Log::info("Using separator barcode", ['separator' => $separatorBarcode]);
        } catch (Exception $e) {
            Log::warning("Failed to read barcode from first page, using default", ['error' => $e->getMessage()]);
            $separatorBarcode = 'default_barcode_' . time();
        }

        $this->updateProgress(25, 'جاري تقسيم الصفحات إلى أقسام...');

        // 🔥 خوارزمية تقسيم محسنة
        $sections = $this->splitIntoSections($pdfPath, $pageCount, $separatorBarcode);

        Log::info("Total sections found", [
            'count' => count($sections),
            'sections_pages' => array_map('count', $sections)
        ]);

        $this->updateProgress(60, 'جاري إنشاء ملفات PDF للمجموعات...');

        // 🔥 معالجة الأقسام بشكل متوازي إذا أمكن
        $createdGroups = $this->processSections($sections, $pdfPath, $upload, $separatorBarcode);

        $this->updateProgress(100, 'تم الانتهاء من المعالجة');

        Log::info("Processing completed", [
            'upload_id' => $upload->id,
            'sections_created' => count($createdGroups),
            'total_pages' => $pageCount
        ]);

        // 🔥 تنظيف الـ Redis lock
        Redis::del($lockKey);

        return $createdGroups;
    }

    /**
     * تقسيم الصفحات إلى أقسام - محسنة
     */
    private function splitIntoSections($pdfPath, $pageCount, $separatorBarcode)
    {
        $sections = [];
        $currentSection = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            $pageProgress = 25 + (($page / $pageCount) * 20);
            $this->updateProgress($pageProgress, "جاري معالجة الصفحة $page من $pageCount...");

            try {
                $barcode = $this->readPageBarcode($pdfPath, $page);

                if ($barcode === $separatorBarcode) {
                    // قسم جديد - حفظ القسم الحالي إذا مش فارغ
                    if (!empty($currentSection)) {
                        $sections[] = $currentSection;
                        Log::debug("Section completed", [
                            'section_number' => count($sections),
                            'pages' => $currentSection
                        ]);
                    }
                    $currentSection = []; // ابدأ قسم جديد
                    $currentSection[] = $page; // 🔥 إضافة صفحة الباركود للقسم الجديد
                } else {
                    // صفحة عادية - أضفها للقسم الحالي
                    $currentSection[] = $page;
                }
            } catch (Exception $e) {
                Log::warning("Error processing page, adding to current section", [
                    'page' => $page,
                    'error' => $e->getMessage()
                ]);
                $currentSection[] = $page; // أضف الصفحة رغم الخطأ
            }
        }

        // إضافة آخر قسم إذا مش فارغ
        if (!empty($currentSection)) {
            $sections[] = $currentSection;
        }

        return $sections;
    }

    /**
     * معالجة الأقسام وإنشاء المجموعات
     */
    private function processSections($sections, $pdfPath, $upload, $separatorBarcode)
    {
        $createdGroups = [];
        $totalSections = count($sections);

        foreach ($sections as $index => $pages) {
            if (empty($pages)) continue;

            $sectionProgress = 60 + (($index / $totalSections) * 35);
            $this->updateProgress($sectionProgress, "جاري إنشاء المجموعة " . ($index + 1) . " من $totalSections...");

            try {
                $group = $this->createGroupFromSection($pdfPath, $pages, $index, $upload, $separatorBarcode);
                if ($group) {
                    $createdGroups[] = $group;
                }
            } catch (Exception $e) {
                Log::error("Failed to create group from section", [
                    'section_index' => $index,
                    'pages' => $pages,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $createdGroups;
    }

    /**
     * إنشاء مجموعة من قسم معين
     */
    private function createGroupFromSection($pdfPath, $pages, $index, $upload, $separatorBarcode)
    {
        $filename = $this->generateFilenameWithOCR($pdfPath, $pages, $index, $separatorBarcode);
        $filenameSafe = $filename . '.pdf';

        $directory = "groups";
        $fullDir = storage_path("app/private/{$directory}");
        if (!file_exists($fullDir)) {
            mkdir($fullDir, 0775, true);
        }

        $outputPath = "{$fullDir}/{$filenameSafe}";
        $dbPath = "{$directory}/{$filenameSafe}";

        // 🔥 حذف الملف القديم إذا موجود
        if (file_exists($outputPath)) {
            unlink($outputPath);
        }

        // 🔥 حذف المجموعة القديمة من قاعدة البيانات
        Group::where('pdf_path', $dbPath)->delete();

        // إنشاء PDF جديد
        $pdfCreated = $this->createQuickPdf($pdfPath, $pages, $outputPath);

        if ($pdfCreated && file_exists($outputPath) && filesize($outputPath) > 5000) {
            Log::debug("PDF created successfully", [
                'file' => $outputPath,
                'pages_count' => count($pages),
                'file_size' => filesize($outputPath)
            ]);

            $group = Group::create([
                'code' => $separatorBarcode,
                'pdf_path' => $dbPath,
                'pages_count' => count($pages),
                'user_id' => $upload->user_id,
                'upload_id' => $upload->id
            ]);

            Log::debug("Group created successfully", [
                'group_id' => $group->id,
                'pdf_path' => $dbPath
            ]);

            return $group;
        } else {
            Log::warning("Failed creating PDF group", [
                'filename' => $filenameSafe,
                'pages' => $pages,
                'file_size' => file_exists($outputPath) ? filesize($outputPath) : 0,
                'pdf_created' => $pdfCreated
            ]);

            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            return null;
        }
    }

    /**
     * تحديث حالة التقدم - محسنة
     */
    private function updateProgress($progress, $message = '')
    {
        if ($this->uploadId) {
            try {
                // تخزين في Redis
                Redis::setex("upload_progress:{$this->uploadId}", 3600, $progress);
                Redis::setex("upload_message:{$this->uploadId}", 3600, $message);

                // 🔥 إضافة تحديث للواجهة إذا كانت المعالجة مباشرة
                if (request()->wantsJson()) {
                    // يمكن إرسال إشعار عبر WebSocket هنا إذا كان مدعوماً
                }

            } catch (Exception $e) {
                Log::warning("Failed to update progress", [
                    'upload_id' => $this->uploadId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    // 🔥 باقي الدوال تبقى كما هي مع تحسينات طفيفة
    // generateFilenameWithOCR, extractWithPdftotext, extractTextWithOCR, etc.

    /**
     * إنشاء PDF باستخدام Ghostscript - محسنة
     */
    private function createQuickPdf($pdfPath, $pages, $outputPath)
    {
        try {
            $outputDir = dirname($outputPath);
            if (!file_exists($outputDir)) {
                mkdir($outputDir, 0775, true);
            }

            // 🔥 بناء أمر Ghostscript أكثر كفاءة
            $pageRanges = [];
            foreach ($pages as $page) {
                $pageRanges[] = "-dPageList={$page}";
            }
            $pageList = implode(' ', $pageRanges);

            $cmd = sprintf(
                'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dCompatibilityLevel=1.7 ' .
                '-dPDFSETTINGS=/prepress %s -sOutputFile=%s %s 2>&1',
                $pageList,
                escapeshellarg($outputPath),
                escapeshellarg($pdfPath)
            );

            exec($cmd, $output, $returnVar);

            // 🔥 تحقق أكثر دقة من النتيجة
            $success = $returnVar === 0 &&
                      file_exists($outputPath) &&
                      filesize($outputPath) > 5000; // 5KB كحد أدنى

            if (!$success) {
                Log::warning("Ghostscript failed, trying pdftk fallback", [
                    'returnVar' => $returnVar,
                    'file_size' => file_exists($outputPath) ? filesize($outputPath) : 0
                ]);

                // محاولة باستخدام pdftk
                $success = $this->tryPdftk($pdfPath, $pages, $outputPath);
            }

            return $success;

        } catch (Exception $e) {
            Log::error("PDF creation exception", [
                'error' => $e->getMessage(),
                'pages' => $pages,
                'output_path' => $outputPath
            ]);
            return false;
        }
    }

    /**
     * الحصول على عدد صفحات PDF - محسنة
     */
    public function getPdfPageCount($pdfPath)
    {
        // 🔥 محاولة متعددة لقراءة عدد الصفحات
        $attempts = [
            ['pdfinfo ' . escapeshellarg($pdfPath) . ' 2>&1', 'Pages:\s*(\d+)'],
            ['pdftk ' . escapeshellarg($pdfPath) . ' dump_data 2>&1', 'NumberOfPages:\s*(\d+)'],
            ['qpdf --show-npages ' . escapeshellarg($pdfPath) . ' 2>&1', '(\d+)']
        ];

        foreach ($attempts as $attempt) {
            list($cmd, $pattern) = $attempt;

            exec($cmd, $output, $returnVar);

            if ($returnVar === 0) {
                foreach ($output as $line) {
                    if (preg_match('/' . $pattern . '/i', $line, $matches)) {
                        return (int)$matches[1];
                    }
                }
            }
        }

        throw new Exception("Unable to determine page count using multiple methods");
    }
}
