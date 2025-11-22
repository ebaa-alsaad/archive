<?php

namespace App\Services;

use Exception;
use App\Models\Group;
use App\Models\Upload;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BarcodeOCRService
{
    private $imageCache = [];
    private $barcodeCache = [];
    private $textCache = [];
    private $ocrCache = [];
    private $pdfHash = null;

    /**
     * المعالجة الرئيسية للملف - الإصدار المصحح
     */
    public function processPdf($upload)
    {
        set_time_limit(1800);
        ini_set('max_execution_time', 1800);
        ini_set('memory_limit', '2048M');

        $pdfPath = Storage::disk('private')->path($upload->stored_filename);
        if (!file_exists($pdfPath)) {
            throw new Exception("PDF file not found: " . $pdfPath);
        }

        $this->pdfHash = md5_file($pdfPath);
        $pageCount = $this->getPdfPageCount($pdfPath);

        Log::info("🎯 بدء معالجة PDF", [
            'upload_id' => $upload->id,
            'pages' => $pageCount,
            'file_size' => filesize($pdfPath)
        ]);

        // اكتشاف الباركود الفاصل من الصفحات الأولى
        $separatorBarcode = $this->detectSeparatorBarcode($pdfPath, $pageCount);
        Log::info("🎯 الباركود الفاصل", ['separator' => $separatorBarcode]);

        // تقسيم الصفحات حسب الباركود - الطريقة المصححة
        $sections = $this->splitPdfCorrectly($pdfPath, $pageCount, $separatorBarcode);

        if (empty($sections)) {
            throw new Exception("❌ لم يتم العثور على أقسام في الملف");
        }

        Log::info("✅ تم تقسيم الملف", [
            'sections_count' => count($sections),
            'sections_pages' => array_map('count', $sections)
        ]);

        $createdGroups = [];
        foreach ($sections as $index => $pages) {
            if (empty($pages)) continue;

            Log::info("📦 معالجة القسم", [
                'section' => $index + 1,
                'pages_count' => count($pages),
                'first_page' => $pages[0],
                'last_page' => end($pages)
            ]);

            $group = $this->createPdfGroup($pdfPath, $pages, $index, $upload);
            if ($group) {
                $createdGroups[] = $group;
                Log::info("✅ تم إنشاء المجموعة", [
                    'group_id' => $group->id,
                    'filename' => $group->filename
                ]);
            } else {
                Log::error("❌ فشل إنشاء المجموعة", ['section' => $index + 1]);
            }
        }

        // تنظيف الملفات المؤقتة
        $this->cleanupTemp();

        Log::info("🎉 اكتملت معالجة PDF", [
            'upload_id' => $upload->id,
            'groups_created' => count($createdGroups)
        ]);

        return $createdGroups;
    }

    /**
     * اكتشاف الباركود الفاصل - محسّن
     */
    private function detectSeparatorBarcode($pdfPath, $pageCount)
    {
        $barcodeFrequency = [];
        $sampleSize = min(10, $pageCount);

        Log::info("🔍 البحث عن الباركود الفاصل", ['sample_size' => $sampleSize]);

        for ($page = 1; $page <= $sampleSize; $page++) {
            $barcode = $this->readPageBarcode($pdfPath, $page);
            if ($barcode) {
                $barcodeFrequency[$barcode] = ($barcodeFrequency[$barcode] ?? 0) + 1;
                Log::debug("📄 باركود مكتشف", ['page' => $page, 'barcode' => $barcode]);
            }
        }

        if (empty($barcodeFrequency)) {
            Log::warning("⚠️ لم يتم العثور على باركود، استخدام قيمة افتراضية");
            return 'default_separator';
        }

        // الباركود الأكثر تكراراً هو الفاصل
        arsort($barcodeFrequency);
        $separator = array_key_first($barcodeFrequency);

        Log::info("🎯 تم تحديد الباركود الفاصل", [
            'separator' => $separator,
            'frequency' => $barcodeFrequency[$separator],
            'all_barcodes' => $barcodeFrequency
        ]);

        return $separator;
    }

    /**
     * تقسيم PDF بشكل صحيح - الإصدار المصحح
     */
    private function splitPdfCorrectly($pdfPath, $pageCount, $separatorBarcode)
    {
        $sections = [];
        $currentSection = [];

        Log::info("✂️ بدء التقسيم", [
            'total_pages' => $pageCount,
            'separator' => $separatorBarcode
        ]);

        for ($page = 1; $page <= $pageCount; $page++) {
            $barcode = $this->readPageBarcode($pdfPath, $page);

            Log::debug("🔍 فحص الصفحة", [
                'page' => $page,
                'barcode' => $barcode,
                'is_separator' => ($barcode === $separatorBarcode)
            ]);

            // إذا كان هذا هو الباركود الفاصل وليس الصفحة الأولى، نبدأ قسم جديد
            if ($barcode === $separatorBarcode && $page > 1) {
                if (!empty($currentSection)) {
                    $sections[] = $currentSection;
                    Log::debug("📁 قسم جديد مكتمل", [
                        'section' => count($sections),
                        'pages_count' => count($currentSection),
                        'pages' => $currentSection
                    ]);
                    $currentSection = [];
                }
                // لا نضيف صفحة الباركود الفاصل لأي قسم
                continue;
            }

            // إضافة الصفحة للقسم الحالي (إذا لم تكن صفحة باركود فاصل)
            $currentSection[] = $page;
        }

        // إضافة القسم الأخير إذا كان يحتوي على صفحات
        if (!empty($currentSection)) {
            $sections[] = $currentSection;
            Log::debug("📁 القسم الأخير", [
                'section' => count($sections),
                'pages_count' => count($currentSection)
            ]);
        }

        Log::info("✅ اكتمل التقسيم", [
            'total_sections' => count($sections),
            'sections_pages' => array_map('count', $sections)
        ]);

        return $sections;
    }

    /**
     * إنشاء مجموعة PDF - الإصدار المصحح
     */
    private function createPdfGroup($pdfPath, $pages, $index, $upload)
    {
        try {
            // استخراج اسم الملف من الصفحة الأولى
            $filename = $this->generateFilenameWithOCR($pdfPath, $pages, $index);
            $filenameSafe = $filename . '.pdf';

            $directory = "groups";
            $fullDir = storage_path("app/private/{$directory}");

            if (!file_exists($fullDir)) {
                mkdir($fullDir, 0775, true);
                Log::info("📁 تم إنشاء المجلد", ['directory' => $fullDir]);
            }

            $outputPath = "{$fullDir}/{$filenameSafe}";
            $dbPath = "{$directory}/{$filenameSafe}";

            Log::info("🛠️ إنشاء ملف PDF", [
                'output_path' => $outputPath,
                'pages_count' => count($pages),
                'pages_range' => min($pages) . '-' . max($pages)
            ]);

            // إنشاء ملف PDF
            if ($this->createQuickPdf($pdfPath, $pages, $outputPath)) {
                $fileSize = filesize($outputPath);

                Log::info("📄 تم إنشاء PDF بنجاح", [
                    'file_size' => $fileSize,
                    'output_path' => $outputPath
                ]);

                $group = Group::create([
                    'code' => 'section_' . ($index + 1),
                    'pdf_path' => $dbPath,
                    'pages_count' => count($pages),
                    'user_id' => $upload->user_id,
                    'upload_id' => $upload->id,
                    'filename' => $filenameSafe
                ]);

                Log::info("💾 تم حفظ المجموعة في قاعدة البيانات", [
                    'group_id' => $group->id,
                    'filename' => $filenameSafe
                ]);

                return $group;
            } else {
                Log::error("❌ فشل إنشاء ملف PDF", [
                    'output_path' => $outputPath,
                    'pages' => $pages
                ]);
            }

        } catch (Exception $e) {
            Log::error("💥 خطأ في إنشاء المجموعة", [
                'upload_id' => $upload->id,
                'pages' => $pages,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return null;
    }

    /**
     * إنشاء اسم الملف - مبسط وسريع
     */
    private function generateFilenameWithOCR($pdfPath, $pages, $index)
    {
        $firstPage = $pages[0];

        try {
            // محاولة استخراج النص بسرعة
            $content = $this->extractWithPdftotext($pdfPath, $firstPage);

            if (empty($content) || mb_strlen($content) < 20) {
                $content = $this->extractTextWithOCR($pdfPath, $firstPage);
            }

            // البحث عن أرقام المستندات
            $patterns = [
                'قيد' => '/رقم\s*القيد\s*[:\-\s]*(\d+)/ui',
                'فاتورة' => '/رقم\s*الفاتورة\s*[:\-\s]*(\d+)/ui',
                'سند' => '/رقم\s*السند\s*[:\-\s]*(\d+)/ui'
            ];

            foreach ($patterns as $type => $pattern) {
                if (preg_match($pattern, $content, $matches)) {
                    $number = $matches[1] ?? null;
                    if ($number) {
                        Log::info("🔍 تم العثور على رقم مستند", [
                            'type' => $type,
                            'number' => $number
                        ]);
                        return $this->sanitizeFilename($type . '_' . $number);
                    }
                }
            }

            // البحث عن تاريخ
            if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{2,4})/u', $content, $matches)) {
                $date = str_replace('/', '-', $matches[1]);
                return $this->sanitizeFilename('مستند_' . $date);
            }

            // استخدام جزء من النص إذا كان ذا معنى
            if (!empty($content) && mb_strlen($content) > 10) {
                $cleanContent = preg_replace('/\s+/', '_', substr(trim($content), 0, 30));
                if (!$this->looksLikeGarbled($cleanContent)) {
                    return $this->sanitizeFilename($cleanContent);
                }
            }

        } catch (Exception $e) {
            Log::warning("⚠️ فشل استخراج اسم الملف", [
                'page' => $firstPage,
                'error' => $e->getMessage()
            ]);
        }

        // اسم افتراضي
        return $this->sanitizeFilename('مستند_' . ($index + 1) . '_' . date('Y-m-d'));
    }

    /**
     * باقي الدوال تبقى كما هي مع تحسينات طفيفة...
     */

    private function extractWithPdftotext($pdfPath, $page)
    {
        $cacheKey = $this->pdfHash . '::pdftotext::' . $page;
        if (isset($this->textCache[$cacheKey])) return $this->textCache[$cacheKey];

        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

        $tempFile = "{$tempDir}/pdftxt_" . md5($cacheKey) . ".txt";

        if (file_exists($tempFile)) {
            $content = trim(preg_replace('/\s+/u', ' ', file_get_contents($tempFile)));
            return $this->textCache[$cacheKey] = $content;
        }

        $cmd = sprintf(
            'pdftotext -f %d -l %d -layout %s %s 2>&1',
            intval($page),
            intval($page),
            escapeshellarg($pdfPath),
            escapeshellarg($tempFile)
        );

        exec($cmd, $output, $returnVar);

        $content = file_exists($tempFile) ? trim(preg_replace('/\s+/u', ' ', file_get_contents($tempFile))) : '';

        return $this->textCache[$cacheKey] = $content;
    }

    private function extractTextWithOCR($pdfPath, $page)
    {
        $cacheKey = $this->pdfHash . '::ocr::' . $page;
        if (isset($this->ocrCache[$cacheKey])) return $this->ocrCache[$cacheKey];

        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) return $this->ocrCache[$cacheKey] = '';

            $tempDir = storage_path("app/temp");
            if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

            $outputFileBase = "{$tempDir}/ocr_" . md5($cacheKey);

            $cmd = sprintf(
                'tesseract %s %s -l ara+eng --psm 6 2>&1',
                escapeshellarg($imagePath),
                escapeshellarg($outputFileBase)
            );

            exec($cmd, $output, $returnVar);

            $textFile = $outputFileBase . '.txt';
            $content = file_exists($textFile) ? trim(preg_replace('/\s+/u', ' ', file_get_contents($textFile))) : '';
            @unlink($textFile);

            return $this->ocrCache[$cacheKey] = $content;

        } catch (Exception $e) {
            Log::warning("OCR extraction failed", ['page' => $page, 'error' => $e->getMessage()]);
            return $this->ocrCache[$cacheKey] = '';
        }
    }

    private function convertToImage($pdfPath, $page)
    {
        $cacheKey = $this->pdfHash . '::page::' . $page;
        if (isset($this->imageCache[$cacheKey]) && file_exists($this->imageCache[$cacheKey])) {
            return $this->imageCache[$cacheKey];
        }

        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

        $base = "page_" . md5($cacheKey);
        $pngPath = "{$tempDir}/{$base}.png";

        if (file_exists($pngPath)) return $this->imageCache[$cacheKey] = $pngPath;

        $cmd = sprintf(
            'pdftoppm -f %d -l %d -png -singlefile -r 150 %s %s 2>&1',
            intval($page),
            intval($page),
            escapeshellarg($pdfPath),
            escapeshellarg("{$tempDir}/{$base}")
        );

        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && file_exists($pngPath)) return $this->imageCache[$cacheKey] = $pngPath;

        Log::warning("convertToImage failed", ['page' => $page, 'returnVar' => $returnVar]);
        return null;
    }

    private function readPageBarcode($pdfPath, $page)
    {
        $cacheKey = $this->pdfHash . '::barcode::' . $page;
        if (isset($this->barcodeCache[$cacheKey])) return $this->barcodeCache[$cacheKey];

        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) return $this->barcodeCache[$cacheKey] = null;

            $barcode = $this->scanBarcode($imagePath);
            return $this->barcodeCache[$cacheKey] = $barcode;
        } catch (Exception $e) {
            Log::warning("Barcode reading failed", ['page' => $page, 'error' => $e->getMessage()]);
            return $this->barcodeCache[$cacheKey] = null;
        }
    }

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

    public function getPdfPageCount($pdfPath)
    {
        $cmd = 'pdfinfo ' . escapeshellarg($pdfPath) . ' 2>&1';
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) throw new Exception("Page count failed: " . implode("\n", $output));

        foreach ($output as $line) {
            if (preg_match('/Pages:\s*(\d+)/i', $line, $matches)) return (int)$matches[1];
        }

        throw new Exception("Unable to determine page count");
    }

    private function createQuickPdf($pdfPath, $pages, $outputPath)
    {
        try {
            $firstPage = min($pages);
            $lastPage = max($pages);

            $cmd = sprintf(
                'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dCompatibilityLevel=1.7 -dPDFSETTINGS=/prepress -dFirstPage=%d -dLastPage=%d -sOutputFile=%s %s 2>&1',
                intval($firstPage),
                intval($lastPage),
                escapeshellarg($outputPath),
                escapeshellarg($pdfPath)
            );

            exec($cmd, $output, $returnVar);

            $success = $returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 0;

            if (!$success) {
                Log::warning("⚠️ فشل إنشاء PDF بـ Ghostscript", [
                    'return_var' => $returnVar,
                    'output' => $output
                ]);

                // محاولة بـ pdftk كبديل
                return $this->createWithPdftk($pdfPath, $pages, $outputPath);
            }

            return $success;

        } catch (Exception $e) {
            Log::error("❌ فشل إنشاء PDF", [
                'error' => $e->getMessage(),
                'pages' => $pages
            ]);
            return false;
        }
    }

    private function createWithPdftk($pdfPath, $pages, $outputPath)
    {
        if (!function_exists('exec')) return false;

        $pagesList = implode(' ', $pages);
        $cmd = sprintf(
            'pdftk %s cat %s output %s 2>&1',
            escapeshellarg($pdfPath),
            $pagesList,
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $returnVar);

        return $returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 0;
    }

    private function sanitizeFilename($filename)
    {
        $clean = preg_replace('/[^\p{Arabic}a-zA-Z0-9\-_\.]/u', '_', (string)$filename);
        $clean = preg_replace('/[_\.]{2,}/', '_', $clean);
        $clean = preg_replace('/^[^0-9\p{Arabic}a-zA-Z]+|[^0-9\p{Arabic}a-zA-Z]+$/u', '', $clean);
        return $clean === '' ? 'file_' . time() : $clean;
    }

    private function cleanupTemp()
    {
        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) return;

        foreach (glob($tempDir . "/*") as $file) {
            if (filemtime($file) < time() - 3600) {
                @unlink($file);
            }
        }
    }

    private function looksLikeGarbled($content)
    {
        if (empty($content)) return true;
        preg_match_all('/[^\p{Arabic}\p{N}\s\p{P}]/u', $content, $m);
        return isset($m[0]) && count($m[0]) > (mb_strlen($content) * 0.3);
    }
}
