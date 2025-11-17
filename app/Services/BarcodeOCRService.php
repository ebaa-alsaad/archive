<?php

namespace App\Services;

use Exception;
use App\Models\Group;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BarcodeOCRService
{
    /**
     * المعالجة الرئيسية للملف
     */
    public function processPdf($upload)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        $pdfPath = Storage::disk('private')->path($upload->stored_filename);

        if (!file_exists($pdfPath)) {
            throw new Exception("PDF file not found: " . $pdfPath);
        }

        $pageCount = $this->getPdfPageCount($pdfPath);
        Log::info("Processing PDF with {$pageCount} pages");

        // قراءة كل الصفحات للباركود
        $barcodes = [];
        for ($page = 1; $page <= $pageCount; $page++) {
            $barcode = $this->readPageBarcode($pdfPath, $page);
            $barcodes[$page] = $barcode ?: null;
        }

        // استخدام الباركود الأول كفاصل
        $separatorBarcode = $barcodes[1] ?? 'default_barcode';
        Log::info("Using separator barcode: {$separatorBarcode}");

        // تقسيم الصفحات إلى أقسام حسب الباركود المتكرر - بدون تضمين صفحة الباركود
        $sections = [];
        $currentSection = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            if ($barcodes[$page] === $separatorBarcode) {
                if (!empty($currentSection)) {
                    $sections[] = $currentSection;
                }
                $currentSection = [];
            } else {
                $currentSection[] = $page;
            }
        }

        if (!empty($currentSection)) {
            $sections[] = $currentSection;
        }

        Log::info("Total sections found: " . count($sections));

        // إنشاء ملفات PDF لكل قسم
        $createdGroups = [];
        foreach ($sections as $index => $pages) {
            if (empty($pages)) {
                continue;
            }

            // استخدام OCR لاستخراج النص وتسمية الملفات
            $filename = $this->generateFilenameWithOCR($pdfPath, $pages, $index, $separatorBarcode);
            $filenameSafe = $filename . '.pdf';

            $directory = "groups";
            $fullDir = storage_path("app/private/{$directory}");
            if (!file_exists($fullDir)) mkdir($fullDir, 0775, true);

            $outputPath = "{$fullDir}/{$filenameSafe}";
            $dbPath = "{$directory}/{$filenameSafe}";

            $pdfCreated = $this->createPdfWithPoppler($pdfPath, $pages, $outputPath);

            if ($pdfCreated) {
                Log::info("PDF created successfully: {$filenameSafe}");

                $group = Group::create([
                    'code' => $separatorBarcode,
                    'pdf_path' => $dbPath,
                    'pages_count' => count($pages),
                    'user_id' => $upload->user_id,
                    'upload_id' => $upload->id
                ]);

                $createdGroups[] = $group;
            } else {
                Log::error("Failed creating PDF group '{$filenameSafe}'");
            }
        }

        Log::info("Processing completed successfully", [
            'sections_created' => count($createdGroups),
            'section_names' => array_map(fn($g) => $g->pdf_path, $createdGroups)
        ]);

        return $createdGroups;
    }

    /**
     * إنشاء اسم ملف باستخدام OCR لاستخراج النص
     */
    private function generateFilenameWithOCR($pdfPath, $pages, $index, $barcode)
    {
        $firstPage = $pages[0];
        $content = $this->extractTextWithOCR($pdfPath, $firstPage);

        Log::info("🔍 OCR extracted content from page {$firstPage}: " . $content);

        // 1. الأولوية: البحث عن رقم القيد
        $qeedNumber = $this->findDocumentNumber($content, 'قيد', [
            'رقم\s*القيد\s*[:\-]?\s*(\d+)',
            'القيد\s*[:\-]?\s*(\d+)',
            'قيد\s*[:\-]?\s*(\d+)',
            'رقم\s*[:\-]?\s*(\d+).*قيد'
        ]);

        if ($qeedNumber) {
            $filename = 'قيد_' . $qeedNumber;
            Log::info("✅ Found رقم القيد: {$filename}");
            return $this->sanitizeFilename($filename);
        }

        // 2. الثانية: البحث عن رقم الفاتورة
        $invoiceNumber = $this->findDocumentNumber($content, 'فاتورة', [
            'رقم\s*الفاتورة\s*[:\-]?\s*(\d+)',
            'الفاتورة\s*[:\-]?\s*(\d+)',
            'فاتورة\s*[:\-]?\s*(\d+)',
            'Invoice\s*No\.?\s*:?\s*(\d+)'
        ]);

        if ($invoiceNumber) {
            $filename = 'فاتورة_' . $invoiceNumber;
            Log::info("✅ Found رقم الفاتورة: {$filename}");
            return $this->sanitizeFilename($filename);
        }

        // 3. الثالثة: البحث عن رقم السند
        $sanedNumber = $this->findDocumentNumber($content, 'سند', [
            'رقم\s*السند\s*[:\-]?\s*(\d+)',
            'السند\s*[:\-]?\s*(\d+)',
            'سند\s*[:\-]?\s*(\d+)'
        ]);

        if ($sanedNumber) {
            $filename = 'سند_' . $sanedNumber;
            Log::info("✅ Found رقم السند: {$filename}");
            return $this->sanitizeFilename($filename);
        }

        // 4. الرابعة: البحث عن تاريخ
        $date = $this->findDate($content);
        if ($date) {
            $filename = 'مستند_' . $date;
            Log::info("✅ Found تاريخ: {$filename}");
            return $this->sanitizeFilename($filename);
        }

        // 5. الخيار الأخير: اسم وصفي مع الباركود
        $filename = 'مستند_' . $barcode . '_' . ($index + 1);
        Log::info("📝 Using fallback filename: {$filename}");
        return $this->sanitizeFilename($filename);
    }

    /**
     * البحث الديناميكي عن أرقام المستندات
     */
    private function findDocumentNumber($content, $documentType, $patterns)
    {
        foreach ($patterns as $pattern) {
            $fullPattern = '/' . $pattern . '/ui';
            if (preg_match($fullPattern, $content, $matches)) {
                $number = $matches[1];
                Log::info("🎯 Found {$documentType} pattern: {$pattern} -> {$number}");
                return $number;
            }
        }

        return null;
    }

    /**
     * البحث عن التاريخ
     */
    private function findDate($content)
    {
        $patterns = [
            '/(\d{2}\/\d{2}\/\d{4})/u',
            '/(\d{2}-\d{2}-\d{4})/u',
            '/(\d{4}-\d{2}-\d{2})/u',
            '/(\d{1,2}\s*\/\s*\d{1,2}\s*\/\s*\d{4})/u'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $date = preg_replace('/\s+/', '', $matches[1]);
                $cleanDate = str_replace('/', '-', $date);
                Log::info("🎯 Found date pattern: {$pattern} -> {$cleanDate}");
                return $cleanDate;
            }
        }

        return null;
    }

    /**
     * استخراج النص باستخدام OCR
     */
    private function extractTextWithOCR($pdfPath, $page)
    {
        try {
            $imagePath = $this->convertToHighQualityImage($pdfPath, $page);
            if (!$imagePath) return '';

            $tempDir = storage_path("app/temp");
            $outputFile = $tempDir . "/ocr_output_" . time();

            // إعدادات Tesseract محسنة للعربية
            $tesseractConfigs = [
                "-l ara --psm 6 --oem 3",
                "-l ara+eng --psm 4 --oem 3",
                "-l ara --psm 3 --oem 3",
                "-l ara+eng --psm 6 --oem 3"
            ];

            $bestContent = '';
            $bestLength = 0;

            foreach ($tesseractConfigs as $config) {
                $cmd = "tesseract " . escapeshellarg($imagePath) . " " .
                    escapeshellarg($outputFile) . " {$config} 2>&1";

                exec($cmd, $output, $returnVar);

                if (file_exists($outputFile . '.txt')) {
                    $content = file_get_contents($outputFile . '.txt');
                    $content = preg_replace('/\s+/', ' ', $content);
                    $content = trim($content);

                    if (strlen($content) > $bestLength) {
                        $bestContent = $content;
                        $bestLength = strlen($content);
                    }

                    @unlink($outputFile . '.txt');
                }
            }

            Log::info("Best OCR content for page {$page}, length: " . strlen($bestContent));

            @unlink($imagePath);
            return $bestContent;

        } catch (Exception $e) {
            Log::error("OCR extraction failed: " . $e->getMessage());
            return '';
        }
    }

    /**
     * تحويل صفحة PDF إلى صورة عالية الجودة لـ OCR
     */
    private function convertToHighQualityImage($pdfPath, $page)
    {
        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

        $base = "page_{$page}_ocr_" . time();
        $pngPath = "{$tempDir}/{$base}.png";

        $cmd = "pdftoppm -f {$page} -l {$page} -png -r 300 -gray -singlefile " .
            escapeshellarg($pdfPath) . " " .
            escapeshellarg("{$tempDir}/{$base}") . " 2>&1";

        exec($cmd, $output, $returnVar);

        if (file_exists($pngPath)) {
            Log::info("High quality image created for OCR: {$pngPath}");
            return $pngPath;
        } else {
            Log::error("Failed to create high quality image for page {$page}");
            return null;
        }
    }

    /**
     * تنظيف اسم الملف
     */
    private function sanitizeFilename($filename)
    {
        $clean = preg_replace('/[^\p{Arabic}a-zA-Z0-9_-]/u', '_', $filename);
        $clean = preg_replace('/_{2,}/', '_', $clean);
        $clean = trim($clean, '_');
        return $clean;
    }

    /**
     * إنشاء PDF باستخدام poppler-utils
     */
    private function createPdfWithPoppler($pdfPath, $pages, $outputPath)
    {
        try {
            $tempDir = storage_path("app/temp/poppler_" . time());
            if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

            $tempFiles = [];

            foreach ($pages as $page) {
                $tempFile = "{$tempDir}/page_{$page}.pdf";
                $cmd = "pdfseparate -f {$page} -l {$page} " . escapeshellarg($pdfPath) . " " . escapeshellarg($tempFile) . " 2>&1";
                exec($cmd, $output, $returnVar);

                if ($returnVar === 0 && file_exists($tempFile)) {
                    $tempFiles[] = $tempFile;
                }
            }

            if (!empty($tempFiles)) {
                $filesList = implode(' ', array_map('escapeshellarg', $tempFiles));
                $cmd = "pdfunite {$filesList} " . escapeshellarg($outputPath) . " 2>&1";
                exec($cmd, $output, $returnVar);
            }

            foreach ($tempFiles as $tempFile) {
                @unlink($tempFile);
            }
            @rmdir($tempDir);

            return $returnVar === 0 && file_exists($outputPath);
        } catch (Exception $e) {
            Log::error("PDF creation with poppler failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * قراءة الباركود من صفحة PDF
     */
    private function readPageBarcode($pdfPath, $page)
    {
        $imagePath = $this->convertToImage($pdfPath, $page);
        if (!$imagePath) return null;

        $barcode = $this->scanBarcode($imagePath);
        @unlink($imagePath);

        return $barcode;
    }

    /**
     * تحويل صفحة PDF إلى صورة PNG (للباركود)
     */
    private function convertToImage($pdfPath, $page)
    {
        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

        $base = "page_{$page}_" . time();
        $pngPath = "{$tempDir}/{$base}.png";

        $cmd = "pdftoppm -f {$page} -l {$page} -png -singlefile " .
               escapeshellarg($pdfPath) . " " .
               escapeshellarg("{$tempDir}/{$base}") . " 2>&1";
        exec($cmd);

        return file_exists($pngPath) ? $pngPath : null;
    }

    /**
     * مسح الباركود من الصورة
     */
    private function scanBarcode($imagePath)
    {
        $cmd = "zbarimg -q --raw " . escapeshellarg($imagePath) . " 2>&1";
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && !empty($output)) {
            return trim($output[0]);
        }
        return null;
    }

    /**
     * الحصول على عدد صفحات PDF
     */
    public function getPdfPageCount($pdfPath)
    {
        $cmd = "pdfinfo " . escapeshellarg($pdfPath) . " 2>&1";
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            throw new Exception("Page count failed: " . implode("\n", $output));
        }

        foreach ($output as $line) {
            if (preg_match('/Pages:\s*(\d+)/i', $line, $matches)) {
                return (int)$matches[1];
            }
        }

        throw new Exception("Unable to determine page count");
    }
}
