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

        Log::info("🎯 Starting PDF processing", [
            'upload_id' => $upload->id,
            'pages' => $pageCount,
            'file_path' => $pdfPath
        ]);

        try {
            // 1. اكتشاف الباركود الفاصل
            $separatorBarcode = $this->detectSeparatorBarcode($pdfPath, $pageCount);

            // 2. تقسيم الملف بناءً على الباركود
            $sections = $this->splitByBarcode($pdfPath, $pageCount, $separatorBarcode);

            if (empty($sections)) {
                throw new Exception("❌ No sections found after barcode splitting");
            }

            // 3. معالجة الأقسام
            $createdGroups = $this->processSections($pdfPath, $sections, $upload);

            Log::info("✅ PDF processing completed", [
                'upload_id' => $upload->id,
                'groups_created' => count($createdGroups),
                'sections_count' => count($sections)
            ]);

            return $createdGroups;

        } catch (Exception $e) {
            Log::error("❌ PDF processing failed", [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
        $sampleSize = min(10, $pageCount);

        Log::info("🔍 Scanning for separator barcode", ['sample_size' => $sampleSize]);

        for ($page = 1; $page <= $sampleSize; $page++) {
            $barcode = $this->readPageBarcode($pdfPath, $page);
            if ($barcode) {
                $barcodeFrequency[$barcode] = ($barcodeFrequency[$barcode] ?? 0) + 1;
                Log::debug("📄 Barcode found", ['page' => $page, 'barcode' => $barcode]);
            }
        }

        if (empty($barcodeFrequency)) {
            $defaultBarcode = 'default_separator_' . Str::random(8);
            Log::warning("⚠️ No barcodes found, using default", ['default' => $defaultBarcode]);
            return $defaultBarcode;
        }

        arsort($barcodeFrequency);
        $separator = array_key_first($barcodeFrequency);

        Log::info("🎯 Separator barcode detected", [
            'separator' => $separator,
            'frequency' => $barcodeFrequency[$separator],
            'all_barcodes' => $barcodeFrequency
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

        Log::info("✂️ Starting barcode-based splitting", [
            'total_pages' => $pageCount,
            'separator' => $separatorBarcode
        ]);

        for ($page = 1; $page <= $pageCount; $page++) {
            $barcode = $this->readPageBarcode($pdfPath, $page);

            // إذا كان هذا هو الباركود الفاصل وليس الصفحة الأولى، نبدأ قسم جديد
            if ($barcode === $separatorBarcode && $page > 1) {
                if (!empty($currentSection)) {
                    $sections[] = $currentSection;
                    Log::debug("📁 New section created", [
                        'section_index' => count($sections) - 1,
                        'pages_count' => count($currentSection),
                        'pages' => $currentSection
                    ]);
                    $currentSection = [];
                }
            }

            // نضيف الصفحة الحالية للقسم
            $currentSection[] = $page;
        }

        // إضافة القسم الأخير
        if (!empty($currentSection)) {
            $sections[] = $currentSection;
            Log::debug("📁 Final section added", [
                'section_index' => count($sections) - 1,
                'pages_count' => count($currentSection)
            ]);
        }

        Log::info("✅ Barcode splitting completed", [
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

        Log::info("🔄 Processing sections", ['total_sections' => count($sections)]);

        foreach ($sections as $index => $pages) {
            if (empty($pages)) {
                Log::warning("⚠️ Empty section skipped", ['section_index' => $index]);
                continue;
            }

            Log::info("📦 Processing section", [
                'section_index' => $index,
                'pages_count' => count($pages),
                'first_page' => $pages[0],
                'last_page' => end($pages)
            ]);

            $group = $this->createGroupFromPages($pdfPath, $pages, $index, $upload);
            if ($group) {
                $createdGroups[] = $group;

                // تحديث التقدم
                $progress = intval((($index + 1) / count($sections)) * 100);
                Redis::set("upload_progress:{$upload->id}", $progress);

                Log::info("✅ Section processed successfully", [
                    'section_index' => $index,
                    'group_id' => $group->id,
                    'pages_count' => count($pages)
                ]);
            } else {
                Log::error("❌ Failed to process section", [
                    'section_index' => $index,
                    'pages_count' => count($pages),
                    'pages' => $pages
                ]);
            }
        }

        Log::info("📊 Sections processing summary", [
            'total_sections' => count($sections),
            'successful_groups' => count($createdGroups)
        ]);

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
                Log::info("📁 Created directory", ['directory' => $fullDir]);
            }

            $outputPath = "{$fullDir}/{$filenameSafe}";
            $dbPath = "{$directory}/{$filenameSafe}";

            Log::info("🛠️ Creating PDF", [
                'output_path' => $outputPath,
                'pages_count' => count($pages),
                'pages_range' => min($pages) . '-' . max($pages)
            ]);

            // إنشاء ملف PDF
            if ($this->createPdfFromPages($pdfPath, $pages, $outputPath)) {
                $fileSize = filesize($outputPath);
                
                $group = Group::create([
                    'code' => 'section_' . ($index + 1),
                    'pdf_path' => $dbPath,
                    'pages_count' => count($pages),
                    'user_id' => $upload->user_id,
                    'upload_id' => $upload->id,
                    'filename' => $filenameSafe
                ]);

                Log::info("✅ Group created successfully", [
                    'group_id' => $group->id,
                    'filename' => $filenameSafe,
                    'pages_count' => count($pages),
                    'file_size' => $fileSize,
                    'file_path' => $dbPath
                ]);

                return $group;
            } else {
                Log::error("❌ Failed to create PDF file", [
                    'output_path' => $outputPath,
                    'pages' => $pages,
                    'pages_count' => count($pages)
                ]);
            }

        } catch (Exception $e) {
            Log::error("❌ Failed to create group", [
                'upload_id' => $upload->id,
                'pages' => $pages,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return null;
    }

    /**
     * إنشاء PDF من صفحات - الطريقة المحسنة
     */
    private function createPdfFromPages($pdfPath, $pages, $outputPath)
    {
        if (empty($pages)) {
            Log::error("❌ No pages provided for PDF creation");
            return false;
        }

        Log::info("🛠️ Creating PDF from pages", [
            'source' => $pdfPath,
            'output' => $outputPath,
            'pages_count' => count($pages),
            'pages_range' => min($pages) . '-' . max($pages)
        ]);

        // المحاولة الأولى: استخدام pdftk
        if ($this->createWithPdftk($pdfPath, $pages, $outputPath)) {
            Log::info("✅ PDF created successfully with pdftk");
            return true;
        }

        // المحاولة الثانية: استخدام ghostscript
        if ($this->createWithGhostscript($pdfPath, $pages, $outputPath)) {
            Log::info("✅ PDF created successfully with ghostscript");
            return true;
        }

        Log::error("❌ All PDF creation methods failed");
        return false;
    }

    /**
     * إنشاء PDF باستخدام pdftk
     */
    private function createWithPdftk($pdfPath, $pages, $outputPath)
    {
        $pagesList = implode(' ', $pages);
        $cmd = sprintf(
            'pdftk %s cat %s output %s 2>&1',
            escapeshellarg($pdfPath),
            $pagesList,
            escapeshellarg($outputPath)
        );

        Log::debug("🔧 pdftk command", ['cmd' => $cmd]);

        exec($cmd, $output, $returnVar);

        $success = $returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 0;

        if (!$success) {
            Log::warning("⚠️ pdftk failed", [
                'return_var' => $returnVar,
                'output' => $output
            ]);
        }

        return $success;
    }

    /**
     * إنشاء PDF باستخدام ghostscript
     */
    private function createWithGhostscript($pdfPath, $pages, $outputPath)
    {
        $firstPage = min($pages);
        $lastPage = max($pages);

        $cmd = sprintf(
            'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dFirstPage=%d -dLastPage=%d -sOutputFile=%s %s 2>&1',
            intval($firstPage),
            intval($lastPage),
            escapeshellarg($outputPath),
            escapeshellarg($pdfPath)
        );

        Log::debug("🔧 ghostscript command", ['cmd' => $cmd]);

        exec($cmd, $output, $returnVar);

        $success = $returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 0;

        if (!$success) {
            Log::warning("⚠️ ghostscript failed", [
                'return_var' => $returnVar,
                'output' => $output
            ]);
        }

        return $success;
    }

    /**
     * باقي الدوال تبقى كما هي مع تحسينات طفيفة...
     */

    private function readPageBarcode($pdfPath, $page)
    {
        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) {
                Log::debug("⚠️ Could not convert page to image", ['page' => $page]);
                return null;
            }

            $cmd = sprintf('zbarimg -q --raw %s 2>/dev/null', escapeshellarg($imagePath));
            exec($cmd, $output, $returnVar);

            if ($returnVar === 0 && !empty($output)) {
                $barcode = trim(is_array($output) ? $output[0] : $output);
                Log::debug("📊 Barcode read", ['page' => $page, 'barcode' => $barcode]);
                return $barcode;
            }

        } catch (Exception $e) {
            Log::debug("⚠️ Barcode reading failed", ['page' => $page, 'error' => $e->getMessage()]);
        }

        return null;
    }

    private function convertToImage($pdfPath, $page)
    {
        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $base = "page_" . md5($this->pdfHash . $page);
        $pngPath = "{$tempDir}/{$base}.png";

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

        Log::warning("⚠️ PDF to image conversion failed", ['page' => $page]);
        return null;
    }

    private function extractFilenameFromFirstPage($pdfPath, $firstPage, $index)
    {
        try {
            $content = $this->extractWithPdftotext($pdfPath, $firstPage);
            
            if (empty($content) || mb_strlen($content) < 20) {
                $content = $this->extractWithOCR($pdfPath, $firstPage);
            }

            $documentInfo = $this->findDocumentInfo($content);
            
            if (!empty($documentInfo)) {
                return current($documentInfo) . '_' . ($index + 1);
            }

            if (!empty($content)) {
                $cleanContent = preg_replace('/\s+/', '_', substr(trim($content), 0, 30));
                return $this->sanitizeFilename($cleanContent) . '_' . ($index + 1);
            }

        } catch (Exception $e) {
            Log::warning("⚠️ Filename extraction failed", ['page' => $firstPage]);
        }

        return 'document_section_' . ($index + 1) . '_' . time();
    }

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

    private function findDocumentInfo($content)
    {
        $patterns = [
            'قيد' => '/رقم\s*القيد\s*[:\-\s]*(\d+)/ui',
            'فاتورة' => '/رقم\s*الفاتورة\s*[:\-\s]*(\d+)/ui',
            'سند' => '/رقم\s*السند\s*[:\-\s]*(\d+)/ui',
            'رقم' => '/رقم\s*[:\-\s]*(\d+)/ui',
            'تاريخ' => '/(\d{1,2}\/\d{1,2}\/\d{2,4})/u'
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $content, $match)) {
                return [$type => trim($match[1] ?? $match[0])];
            }
        }

        return [];
    }

    private function sanitizeFilename($filename)
    {
        $clean = preg_replace('/[^\p{Arabic}a-zA-Z0-9\-_\.\s]/u', '_', (string)$filename);
        $clean = preg_replace('/\s+/', '_', $clean);
        $clean = preg_replace('/[_\.]{2,}/', '_', $clean);
        return trim($clean, '_-.');
    }

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