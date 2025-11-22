<?php

namespace App\Services;

use Exception;
use App\Models\Group;
use App\Models\Upload;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Redis;

class BarcodeOCRService
{
    private $pdfHash = null;
    private $tempFiles = [];

    /**
     * المعالجة الرئيسية مع التقسيم بالباركود
     */
    public function processPdf(Upload $upload)
    {
        set_time_limit(1800);
        ini_set('max_execution_time', 1800);
        ini_set('memory_limit', '1024M');

        $pdfPath = Storage::disk('private')->path($upload->stored_filename);
        if (!file_exists($pdfPath)) {
            throw new Exception("PDF file not found: " . $pdfPath);
        }

        $this->pdfHash = md5_file($pdfPath);
        $pageCount = $this->getPdfPageCount($pdfPath);
        $upload->update(['total_pages' => $pageCount]);

        Log::info("Starting PDF processing with barcode splitting", [
            'upload_id' => $upload->id,
            'pages' => $pageCount
        ]);

        try {
            // 1. اكتشاف الباركود الفاصل
            $separatorBarcode = $this->detectSeparatorBarcode($pdfPath, $pageCount);

            // 2. تقسيم الملف بناءً على الباركود
            $sections = $this->splitByBarcode($pdfPath, $pageCount, $separatorBarcode);

            // 3. معالجة الأقسام
            $createdGroups = $this->processSections($pdfPath, $sections, $upload);

            Log::info("PDF processing completed", [
                'upload_id' => $upload->id,
                'groups_created' => count($createdGroups),
                'sections_count' => count($sections)
            ]);

            return $createdGroups;

        } catch (Exception $e) {
            Log::error("PDF processing failed", [
                'upload_id' => $upload->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } finally {
            $this->cleanupTemp();
        }
    }

    /**
     * اكتشاف الباركود الفاصل
     */
    private function detectSeparatorBarcode($pdfPath, $pageCount)
    {
        $barcodeFrequency = [];

        // فحص الصفحات الأولى لاكتشاف الباركود الفاصل
        $sampleSize = min(5, $pageCount);

        for ($page = 1; $page <= $sampleSize; $page++) {
            $barcode = $this->readPageBarcode($pdfPath, $page);
            if ($barcode) {
                $barcodeFrequency[$barcode] = ($barcodeFrequency[$barcode] ?? 0) + 1;
                Log::debug("Barcode found", ['page' => $page, 'barcode' => $barcode]);
            }
        }

        if (empty($barcodeFrequency)) {
            $defaultBarcode = 'default_separator_' . Str::random(8);
            Log::warning("No barcodes found, using default", ['default' => $defaultBarcode]);
            return $defaultBarcode;
        }

        // الباركود الأكثر تكراراً هو الفاصل
        arsort($barcodeFrequency);
        $separator = array_key_first($barcodeFrequency);

        Log::info("Separator barcode detected", [
            'separator' => $separator,
            'frequency' => $barcodeFrequency[$separator]
        ]);

        return $separator;
    }

    /**
     * تقسيم الملف بناءً على الباركود الفاصل
     */
    private function splitByBarcode($pdfPath, $pageCount, $separatorBarcode)
    {
        $sections = [];
        $currentSection = [];

        Log::info("Starting barcode-based splitting", [
            'total_pages' => $pageCount,
            'separator' => $separatorBarcode
        ]);

        for ($page = 1; $page <= $pageCount; $page++) {
            $barcode = $this->readPageBarcode($pdfPath, $page);

            // إذا وجدنا الباركود الفاصل وليس الصفحة الأولى، نبدأ قسم جديد
            if ($barcode === $separatorBarcode && $page > 1 && !empty($currentSection)) {
                $sections[] = $currentSection;
                Log::debug("New section started", [
                    'section_index' => count($sections) - 1,
                    'pages' => $currentSection
                ]);
                $currentSection = [];
            }

            // نضيف الصفحة الحالية للقسم
            $currentSection[] = $page;
        }

        // إضافة القسم الأخير
        if (!empty($currentSection)) {
            $sections[] = $currentSection;
            Log::debug("Final section added", [
                'section_index' => count($sections) - 1,
                'pages' => $currentSection
            ]);
        }

        Log::info("Barcode splitting completed", [
            'total_sections' => count($sections),
            'sections_pages' => array_map('count', $sections)
        ]);

        return $sections;
    }

    /**
     * معالجة الأقسام
     */
    private function processSections($pdfPath, $sections, $upload)
    {
        $createdGroups = [];

        foreach ($sections as $index => $pages) {
            if (empty($pages)) {
                Log::warning("Empty section skipped", ['section_index' => $index]);
                continue;
            }

            $group = $this->createGroupFromPages($pdfPath, $pages, $index, $upload);
            if ($group) {
                $createdGroups[] = $group;

                // تحديث التقدم
                $progress = intval((($index + 1) / count($sections)) * 100);
                Redis::set("upload_progress:{$upload->id}", $progress);

                Log::info("Section processed successfully", [
                    'section_index' => $index,
                    'group_id' => $group->id,
                    'pages_count' => count($pages)
                ]);
            } else {
                Log::error("Failed to process section", [
                    'section_index' => $index,
                    'pages_count' => count($pages)
                ]);
            }
        }

        return $createdGroups;
    }

    /**
     * إنشاء مجموعة من الصفحات
     */
    private function createGroupFromPages($pdfPath, $pages, $index, $upload)
    {
        try {
            // استخراج اسم الملف من الصفحة الأولى
            $filename = $this->extractFilenameFromFirstPage($pdfPath, $pages[0], $index);
            $filenameSafe = $this->sanitizeFilename($filename) . '.pdf';

            $directory = "groups";
            $fullDir = storage_path("app/private/{$directory}");

            if (!is_dir($fullDir)) {
                mkdir($fullDir, 0775, true);
            }

            $outputPath = "{$fullDir}/{$filenameSafe}";
            $dbPath = "{$directory}/{$filenameSafe}";

            // إنشاء ملف PDF
            if ($this->createPdfFromPages($pdfPath, $pages, $outputPath)) {
                $group = Group::create([
                    'code' => 'section_' . ($index + 1),
                    'pdf_path' => $dbPath,
                    'pages_count' => count($pages),
                    'user_id' => $upload->user_id,
                    'upload_id' => $upload->id,
                    'filename' => $filenameSafe
                ]);

                Log::info("Group created", [
                    'group_id' => $group->id,
                    'filename' => $filenameSafe,
                    'pages_count' => count($pages)
                ]);

                return $group;
            }

        } catch (Exception $e) {
            Log::error("Failed to create group", [
                'upload_id' => $upload->id,
                'pages' => $pages,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * استخراج اسم الملف من الصفحة الأولى
     */
    private function extractFilenameFromFirstPage($pdfPath, $firstPage, $index)
    {
        try {
            // أولاً: محاولة استخراج النص باستخدام pdftotext
            $content = $this->extractWithPdftotext($pdfPath, $firstPage);

            // إذا فشل، نستخدم OCR
            if (empty($content) || mb_strlen($content) < 20) {
                $content = $this->extractWithOCR($pdfPath, $firstPage);
            }

            // البحث عن معلومات المستند
            $documentInfo = $this->findDocumentInfo($content);

            if (!empty($documentInfo)) {
                // إرجاع أول معلومات مستند وجدناها
                return current($documentInfo) . '_' . ($index + 1);
            }

            // إذا لم نجد معلومات، نستخدم جزء من النص
            if (!empty($content)) {
                $cleanContent = preg_replace('/\s+/', '_', substr(trim($content), 0, 30));
                return $this->sanitizeFilename($cleanContent) . '_' . ($index + 1);
            }

        } catch (Exception $e) {
            Log::warning("Filename extraction failed", [
                'page' => $firstPage,
                'error' => $e->getMessage()
            ]);
        }

        // اسم افتراضي إذا فشل everything
        return 'document_section_' . ($index + 1) . '_' . time();
    }

    /**
     * البحث عن معلومات المستند في النص
     */
    private function findDocumentInfo($content)
    {
        $patterns = [
            'قيد' => '/رقم\s*القيد\s*[:\-\s]*(\d+)/ui',
            'فاتورة' => '/رقم\s*الفاتورة\s*[:\-\s]*(\d+)/ui',
            'سند' => '/رقم\s*السند\s*[:\-\s]*(\d+)/ui',
            'رقم' => '/رقم\s*[:\-\s]*(\d+)/ui',
            'تاريخ' => '/(\d{1,2}\/\d{1,2}\/\d{2,4})/u'
        ];

        $matches = [];
        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $content, $match)) {
                $matches[$type] = trim($match[1] ?? $match[0]);
                break; // نكتفي بأول تطابق
            }
        }

        return $matches;
    }

    /**
     * استخراج النص باستخدام pdftotext
     */
    private function extractWithPdftotext($pdfPath, $page)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'pdftxt_') . '.txt';

        $cmd = sprintf(
            'pdftotext -f %d -l %d -layout %s %s 2>/dev/null',
            intval($page),
            intval($page),
            escapeshellarg($pdfPath),
            escapeshellarg($tempFile)
        );

        exec($cmd, $output, $returnVar);

        $content = '';
        if (file_exists($tempFile)) {
            $content = file_get_contents($tempFile);
            unlink($tempFile);
        }

        return trim(preg_replace('/\s+/u', ' ', $content));
    }

    /**
     * استخراج النص باستخدام OCR
     */
    private function extractWithOCR($pdfPath, $page)
    {
        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) return '';

            $tempFile = tempnam(sys_get_temp_dir(), 'ocr_');

            $cmd = sprintf(
                'tesseract %s %s -l ara+eng --psm 6 2>/dev/null',
                escapeshellarg($imagePath),
                escapeshellarg($tempFile)
            );

            exec($cmd, $output, $returnVar);

            $content = '';
            $textFile = $tempFile . '.txt';
            if (file_exists($textFile)) {
                $content = file_get_contents($textFile);
                unlink($textFile);
            }

            return trim($content);

        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * قراءة الباركود من الصفحة
     */
    private function readPageBarcode($pdfPath, $page)
    {
        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) return null;

            $cmd = sprintf('zbarimg -q --raw %s 2>/dev/null', escapeshellarg($imagePath));
            exec($cmd, $output, $returnVar);

            if ($returnVar === 0 && !empty($output)) {
                return trim(is_array($output) ? $output[0] : $output);
            }

        } catch (Exception $e) {
            // تجاهل الأخطاء في قراءة الباركود
        }

        return null;
    }

    /**
     * تحويل صفحة PDF إلى صورة
     */
    private function convertToImage($pdfPath, $page)
    {
        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $base = "page_" . md5($this->pdfHash . $page);
        $pngPath = "{$tempDir}/{$base}.png";

        // استخدام cache للصورة
        if (file_exists($pngPath)) {
            $this->tempFiles[] = $pngPath;
            return $pngPath;
        }

        $cmd = sprintf(
            'pdftoppm -f %d -l %d -png -singlefile -r 100 %s %s 2>/dev/null',
            intval($page),
            intval($page),
            escapeshellarg($pdfPath),
            escapeshellarg("{$tempDir}/{$base}")
        );

        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && file_exists($pngPath)) {
            $this->tempFiles[] = $pngPath;
            return $pngPath;
        }

        return null;
    }

    /**
     * إنشاء PDF من صفحات
     */
    private function createPdfFromPages($pdfPath, $pages, $outputPath)
    {
        if (empty($pages)) return false;

        // محاولة pdftk أولاً
        $pagesList = implode(' ', $pages);
        $cmd = sprintf(
            'pdftk %s cat %s output %s 2>&1',
            escapeshellarg($pdfPath),
            $pagesList,
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 0) {
            return true;
        }

        // إذا فشل pdftk، نستخدم ghostscript
        $firstPage = min($pages);
        $lastPage = max($pages);

        $cmd = sprintf(
            'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dFirstPage=%d -dLastPage=%d -sOutputFile=%s %s 2>&1',
            intval($firstPage),
            intval($lastPage),
            escapeshellarg($outputPath),
            escapeshellarg($pdfPath)
        );

        exec($cmd, $output, $returnVar);

        return $returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 0;
    }

    /**
     * تنظيف اسم الملف
     */
    private function sanitizeFilename($filename)
    {
        $clean = preg_replace('/[^\p{Arabic}a-zA-Z0-9\-_\.\s]/u', '_', (string)$filename);
        $clean = preg_replace('/\s+/', '_', $clean);
        $clean = preg_replace('/[_\.]{2,}/', '_', $clean);
        return trim($clean, '_-.');
    }

    /**
     * الحصول على عدد صفحات PDF
     */
    public function getPdfPageCount($pdfPath)
    {
        $cmd = 'pdfinfo ' . escapeshellarg($pdfPath) . ' 2>/dev/null';
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0) {
            foreach ($output as $line) {
                if (preg_match('/Pages:\s*(\d+)/i', $line, $matches)) {
                    return (int)$matches[1];
                }
            }
        }

        throw new Exception("Unable to determine PDF page count");
    }

    /**
     * تنظيف الملفات المؤقتة
     */
    private function cleanupTemp()
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    public function __destruct()
    {
        $this->cleanupTemp();
    }
}
