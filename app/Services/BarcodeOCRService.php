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

    public function processPdf($upload, $disk = 'private')
    {
        // Lock للمعالجة المتوازية
        $lockKey = "processing_{$upload->id}";
        if (Redis::get($lockKey)) {
            Log::warning("Processing already in progress", ['upload_id' => $upload->id]);
            throw new Exception("المعالجة جارية بالفعل لهذا الملف");
        }

        Redis::setex($lockKey, 7200, 'true');

        $this->uploadId = $upload->id;

        // زيادة الحدود
        set_time_limit(0);
        ini_set('memory_limit', '4096M');

        $pdfPath = Storage::disk($disk)->path($upload->stored_filename);

        if (!file_exists($pdfPath)) {
            Redis::del($lockKey);
            throw new Exception("PDF file not found: " . $pdfPath);
        }

        // تنظيف المجموعات القديمة
        try {
            Group::where('upload_id', $upload->id)->delete();
            Log::info("Cleaned up existing groups", ['upload_id' => $upload->id]);
        } catch (Exception $e) {
            Log::warning("Cleanup failed, continuing", ['error' => $e->getMessage()]);
        }

        $this->updateProgress(5, 'جاري تهيئة الملف...');
        $this->pdfHash = md5_file($pdfPath);

        try {
            $pageCount = $this->getPdfPageCount($pdfPath);
        } catch (Exception $e) {
            Redis::del($lockKey);
            throw new Exception("فشل في قراءة الملف: " . $e->getMessage());
        }

        // قراءة الباركود الفاصل
        $separatorBarcode = null;
        try {
            $separatorBarcode = $this->readPageBarcode($pdfPath, 1) ?? 'default_barcode_' . time();
            Log::info("Using separator barcode", ['separator' => $separatorBarcode]);
        } catch (Exception $e) {
            Log::warning("Failed to read barcode, using default", ['error' => $e->getMessage()]);
            $separatorBarcode = 'default_barcode_' . time();
        }

        $this->updateProgress(25, 'جاري تقسيم الصفحات إلى أقسام...');

        // تقسيم الصفحات
        $sections = [];
        $currentSection = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            $pageProgress = 25 + (($page / $pageCount) * 20);
            $this->updateProgress($pageProgress, "جاري معالجة الصفحة $page من $pageCount...");

            try {
                $barcode = $this->readPageBarcode($pdfPath, $page);

                if ($barcode === $separatorBarcode) {
                    if (!empty($currentSection)) {
                        $sections[] = $currentSection;
                    }
                    $currentSection = [$page]; // ابدأ قسم جديد بالصفحة الحالية
                } else {
                    $currentSection[] = $page;
                }
            } catch (Exception $e) {
                Log::warning("Error processing page", ['page' => $page, 'error' => $e->getMessage()]);
                $currentSection[] = $page;
            }
        }

        if (!empty($currentSection)) {
            $sections[] = $currentSection;
        }

        Log::info("Sections found", [
            'count' => count($sections),
            'sections_pages' => array_map('count', $sections)
        ]);

        $this->updateProgress(60, 'جاري إنشاء ملفات PDF للمجموعات...');

        // معالجة الأقسام
        $createdGroups = [];
        $totalSections = count($sections);

        foreach ($sections as $index => $pages) {
            if (empty($pages)) continue;

            $sectionProgress = 60 + (($index / $totalSections) * 35);
            $this->updateProgress($sectionProgress, "جاري إنشاء المجموعة " . ($index + 1) . " من $totalSections...");

            try {
                $filename = $this->generateFilenameWithOCR($pdfPath, $pages, $index, $separatorBarcode);
                $filenameSafe = $filename . '.pdf';

                $directory = "groups";
                $fullDir = storage_path("app/private/{$directory}");
                if (!file_exists($fullDir)) {
                    mkdir($fullDir, 0775, true);
                }

                $outputPath = "{$fullDir}/{$filenameSafe}";
                $dbPath = "{$directory}/{$filenameSafe}";

                // حذف الملف القديم إذا موجود
                if (file_exists($outputPath)) {
                    unlink($outputPath);
                }

                // حذف المجموعة القديمة
                Group::where('pdf_path', $dbPath)->delete();

                // إنشاء PDF
                $pdfCreated = $this->createQuickPdf($pdfPath, $pages, $outputPath);

                if ($pdfCreated && file_exists($outputPath) && filesize($outputPath) > 5000) {
                    $group = Group::create([
                        'code' => $separatorBarcode,
                        'pdf_path' => $dbPath,
                        'pages_count' => count($pages),
                        'user_id' => $upload->user_id,
                        'upload_id' => $upload->id
                    ]);

                    $createdGroups[] = $group;

                    Log::debug("Group created", [
                        'group_id' => $group->id,
                        'pdf_path' => $dbPath
                    ]);
                } else {
                    Log::warning("Failed creating PDF group", [
                        'filename' => $filenameSafe,
                        'pages' => $pages,
                        'file_size' => file_exists($outputPath) ? filesize($outputPath) : 0
                    ]);

                    if (file_exists($outputPath)) {
                        unlink($outputPath);
                    }
                }
            } catch (Exception $e) {
                Log::error("Failed to create group from section", [
                    'section_index' => $index,
                    'pages' => $pages,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->updateProgress(100, 'تم الانتهاء من المعالجة');

        Log::info("Processing completed", [
            'upload_id' => $upload->id,
            'sections_created' => count($createdGroups),
            'total_pages' => $pageCount
        ]);

        Redis::del($lockKey);

        return $createdGroups;
    }

    /**
     * قراءة الباركود من صفحة PDF
     */
    private function readPageBarcode($pdfPath, $page)
    {
        $cacheKey = $this->pdfHash . '::barcode::' . $page;
        if (isset($this->barcodeCache[$cacheKey])) {
            return $this->barcodeCache[$cacheKey];
        }

        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) {
                return $this->barcodeCache[$cacheKey] = null;
            }

            $barcode = $this->scanBarcode($imagePath);
            return $this->barcodeCache[$cacheKey] = $barcode;
        } catch (Exception $e) {
            Log::warning("Barcode reading failed", ['page' => $page, 'error' => $e->getMessage()]);
            return $this->barcodeCache[$cacheKey] = null;
        }
    }

    /**
     * تحويل صفحة PDF إلى صورة
     */
    private function convertToImage($pdfPath, $page)
    {
        $cacheKey = $this->pdfHash . '::page::' . $page;
        if (isset($this->imageCache[$cacheKey]) && file_exists($this->imageCache[$cacheKey])) {
            return $this->imageCache[$cacheKey];
        }

        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $base = "page_{$cacheKey}";
        $pngPath = "{$tempDir}/{$base}.png";

        if (file_exists($pngPath)) {
            return $this->imageCache[$cacheKey] = $pngPath;
        }

        // استخدام pdftoppm لتحويل PDF إلى صورة
        $cmd = sprintf(
            'pdftoppm -f %d -l %d -png -singlefile %s %s 2>&1',
            intval($page),
            intval($page),
            escapeshellarg($pdfPath),
            escapeshellarg("{$tempDir}/{$base}")
        );

        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && file_exists($pngPath)) {
            return $this->imageCache[$cacheKey] = $pngPath;
        }

        // محاولة بديلة
        exec(str_replace('-singlefile', '', $cmd), $output2, $returnVar2);
        if ($returnVar2 === 0 && file_exists($pngPath)) {
            return $this->imageCache[$cacheKey] = $pngPath;
        }

        Log::warning("convertToImage failed", ['page' => $page, 'returnVar' => $returnVar, 'output' => $output]);
        return null;
    }

    /**
     * مسح الباركود من الصورة
     */
    private function scanBarcode($imagePath)
    {
        $cmd = sprintf('zbarimg -q --raw %s 2>&1', escapeshellarg($imagePath));
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && !empty($output)) {
            $first = trim(is_array($output) ? $output[0] : $output);
            return $first === '' ? null : $first;
        }

        return null;
    }

    /**
     * إنشاء اسم ملف باستخدام OCR
     */
    private function generateFilenameWithOCR($pdfPath, $pages, $index, $barcode)
    {
        $firstPage = $pages[0];

        // جرب pdftotext أولاً
        $content = $this->extractWithPdftotext($pdfPath, $firstPage);

        // إذا النص مش واضح، استخدم OCR
        if (
            empty($content) ||
            mb_strlen($content) < 40 ||
            $this->looksLikeGarbled($content) ||
            $this->tooManyNonArabic($content)
        ) {
            $content = $this->extractTextWithOCR($pdfPath, $firstPage);
        }

        Log::debug("OCR Content for filename", [
            'page' => $firstPage,
            'content_length' => mb_strlen($content),
            'content_sample' => mb_substr($content, 0, 100)
        ]);

        // البحث عن رقم السند
        $sanedNumber = $this->findDocumentNumber($content, 'سند', [
            'رقم\s*السند\s*[:\-]?\s*(\d{2,})',
            'السند\s*[:\-]?\s*(\d{2,})',
            'سند\s*[:\-]?\s*(\d{2,})',
            'سند\s*رقم\s*[:\-]?\s*(\d{2,})',
            '(\d{3})\s*سند',
            'سند\s*(\d{3})'
        ]);

        if ($sanedNumber) {
            Log::debug("Found document number", [
                'type' => 'سند',
                'value' => $sanedNumber,
                'page' => $firstPage
            ]);
            return $this->sanitizeFilename($sanedNumber);
        }

        // البحث عن رقم القيد
        $qeedNumber = $this->findDocumentNumber($content, 'قيد', [
            'رقم\s*القيد\s*[:\-]?\s*(\d+)',
            'القيد\s*[:\-]?\s*(\d+)',
            'قيد\s*[:\-]?\s*(\d+)',
        ]);
        if ($qeedNumber) {
            Log::debug("Found qeed number", ['value' => $qeedNumber]);
            return $this->sanitizeFilename($qeedNumber);
        }

        // البحث عن تاريخ
        $date = $this->findDate($content);
        if ($date) {
            Log::debug("Found date", ['value' => $date]);
            return $this->sanitizeFilename($date);
        }

        // fallback - استخدام الباركود + رقم القسم
        $fallbackName = $barcode . '_' . ($index + 1);
        Log::debug("Using fallback name", ['name' => $fallbackName]);
        return $this->sanitizeFilename($fallbackName);
    }

    /**
     * استخراج النص باستخدام pdftotext
     */
    private function extractWithPdftotext($pdfPath, $page)
    {
        $cacheKey = $this->pdfHash . '::pdftotext::' . $page;
        if (isset($this->textCache[$cacheKey])) {
            return $this->textCache[$cacheKey];
        }

        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $tempFile = "{$tempDir}/pdftxt_{$cacheKey}.txt";

        if (file_exists($tempFile)) {
            $content = trim(preg_replace('/\s+/u', ' ', file_get_contents($tempFile)));
            return $this->textCache[$cacheKey] = $content;
        }

        // استخدم pdftotext لصفحة محددة
        $cmd = sprintf(
            'pdftotext -f %d -l %d -layout %s %s 2>&1',
            intval($page),
            intval($page),
            escapeshellarg($pdfPath),
            escapeshellarg($tempFile)
        );

        exec($cmd, $output, $returnVar);

        $content = '';
        if (file_exists($tempFile)) {
            $content = trim(preg_replace('/\s+/u', ' ', file_get_contents($tempFile)));
        }

        return $this->textCache[$cacheKey] = $content;
    }

    /**
     * استخراج النص باستخدام OCR
     */
    private function extractTextWithOCR($pdfPath, $page)
    {
        $cacheKey = $this->pdfHash . '::ocr::' . $page;
        if (isset($this->ocrCache[$cacheKey])) {
            return $this->ocrCache[$cacheKey];
        }

        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) {
                return $this->ocrCache[$cacheKey] = '';
            }

            $tempDir = storage_path("app/temp");
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0775, true);
            }

            $outputFileBase = "{$tempDir}/ocr_{$cacheKey}";

            // استخدام tesseract للتعرف على النص
            $cmd = sprintf(
                'tesseract %s %s -l ara --psm 6 2>&1',
                escapeshellarg($imagePath),
                escapeshellarg($outputFileBase)
            );

            exec($cmd, $output, $returnVar);

            $textFile = $outputFileBase . '.txt';
            $content = '';
            if (file_exists($textFile)) {
                $content = trim(preg_replace('/\s+/u', ' ', file_get_contents($textFile)));
                @unlink($textFile);
            }

            return $this->ocrCache[$cacheKey] = $content;
        } catch (Exception $e) {
            Log::warning("OCR extraction failed", ['page' => $page, 'error' => $e->getMessage()]);
            return $this->ocrCache[$cacheKey] = '';
        }
    }

    /**
     * البحث عن أرقام المستندات
     */
    private function findDocumentNumber($content, $documentType, $patterns)
    {
        if (empty($content) || mb_strlen($content) < 2) {
            return null;
        }

        foreach ($patterns as $pattern) {
            $fullPattern = '/' . $pattern . '/ui';
            if (preg_match($fullPattern, $content, $matches)) {
                $number = $matches[1] ?? null;
                if ($number) {
                    Log::debug("Found document number", ['type' => $documentType, 'value' => $number]);
                    return $number;
                }
            }
        }
        return null;
    }

    /**
     * البحث عن التاريخ
     */
    private function findDate($content)
    {
        if (empty($content) || mb_strlen($content) < 4) {
            return null;
        }

        $patterns = [
            '/(\d{2}\/\d{2}\/\d{4})/u',
            '/(\d{2}-\d{2}-\d{4})/u',
            '/(\d{4}-\d{2}-\d{2})/u'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $date = preg_replace('/\s+/', '', $matches[1]);
                return str_replace('/', '-', $date);
            }
        }
        return null;
    }

    /**
     * إنشاء PDF باستخدام Ghostscript
     */
    private function createQuickPdf($pdfPath, $pages, $outputPath)
    {
        try {
            $outputDir = dirname($outputPath);
            if (!file_exists($outputDir)) {
                mkdir($outputDir, 0775, true);
            }

            // بناء قائمة الصفحات لـ Ghostscript
            $pageList = implode(' ', array_map(function($page) {
                return "-dPageList=" . $page;
            }, $pages));

            $cmd = sprintf(
                'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dCompatibilityLevel=1.7 ' .
                '-dPDFSETTINGS=/prepress %s -sOutputFile=%s %s 2>&1',
                $pageList,
                escapeshellarg($outputPath),
                escapeshellarg($pdfPath)
            );

            exec($cmd, $output, $returnVar);

            // التحقق من النتيجة
            $success = $returnVar === 0 &&
                    file_exists($outputPath) &&
                    filesize($outputPath) > 10000;

            if ($success) {
                Log::debug("PDF created successfully with ghostscript", [
                    'output_path' => $outputPath,
                    'file_size' => filesize($outputPath),
                    'pages_count' => count($pages),
                    'pages' => $pages
                ]);
            } else {
                Log::error("PDF creation failed", [
                    'returnVar' => $returnVar,
                    'output' => $output,
                    'file_size' => file_exists($outputPath) ? filesize($outputPath) : 0
                ]);

                // محاولة بديلة باستخدام pdftk
                if ($this->tryPdftk($pdfPath, $pages, $outputPath)) {
                    return true;
                }
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
     * محاولة باستخدام pdftk
     */
    private function tryPdftk($pdfPath, $pages, $outputPath)
    {
        try {
            // التحقق من وجود pdftk
            $cmdCheck = 'which pdftk 2>&1';
            exec($cmdCheck, $outputCheck, $returnCheck);

            if ($returnCheck !== 0) {
                Log::warning("pdftk not installed");
                return false;
            }

            $pagesString = implode(' ', $pages);
            $cmd = sprintf(
                'pdftk %s cat %s output %s 2>&1',
                escapeshellarg($pdfPath),
                $pagesString,
                escapeshellarg($outputPath)
            );

            exec($cmd, $output, $returnVar);

            return $returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 10000;

        } catch (Exception $e) {
            Log::warning("pdftk fallback failed", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * تنظيف اسم الملف
     */
    private function sanitizeFilename($filename)
    {
        $clean = preg_replace('/[^\p{Arabic}a-zA-Z0-9\-_\.]/u', '_', (string)$filename);
        $clean = preg_replace('/[_\.]{2,}/', '_', $clean);
        $clean = preg_replace('/^[^0-9\p{Arabic}a-zA-Z]+|[^0-9\p{Arabic}a-zA-Z]+$/u', '', $clean);
        return $clean === '' ? 'file_' . time() : $clean;
    }

    private function looksLikeGarbled($content)
    {
        if (empty($content)) {
            return true;
        }

        // عدّ أي حرف غير عربي/رقم/مسافة/علامة ترقيم
        preg_match_all('/[^\p{Arabic}\p{N}\s\p{P}]/u', $content, $m);
        return isset($m[0]) && count($m[0]) > 20;
    }

    private function tooManyNonArabic($content)
    {
        if (empty($content)) {
            return false;
        }
        return preg_match('/[a-zA-Z]{20,}/', $content);
    }

    /**
     * الحصول على عدد صفحات PDF
     */
    public function getPdfPageCount($pdfPath)
    {
        // محاولة متعددة لقراءة عدد الصفحات
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

    /**
     * تحديث التقدم
     */
    private function updateProgress($progress, $message = '')
    {
        if ($this->uploadId) {
            try {
                Redis::setex("upload_progress:{$this->uploadId}", 3600, $progress);
                Redis::setex("upload_message:{$this->uploadId}", 3600, $message);
            } catch (Exception $e) {
                Log::warning("Failed to update progress", [
                    'upload_id' => $this->uploadId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
