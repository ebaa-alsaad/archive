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

        // استخدام OCR لاستخراج النص من الصفحة الأولى
        $content = $this->extractTextWithOCR($pdfPath, $firstPage);

        Log::info("OCR extracted content from page {$firstPage}: " . substr($content, 0, 200));

        // 1. البحث عن رقم القيد
        if (preg_match('/رقم\s*القيد\s*[:\-]?\s*(\d+)/u', $content, $matches)) {
            $filename = 'قيد_' . $matches[1];
            Log::info("Found رقم القيد via OCR: {$filename}");
            return $this->sanitizeFilename($filename);
        }

        // 2. البحث عن رقم الفاتورة
        if (preg_match('/رقم\s*الفاتورة\s*[:\-]?\s*(\d+)/u', $content, $matches)) {
            $filename = 'فاتورة_' . $matches[1];
            Log::info("Found رقم الفاتورة via OCR: {$filename}");
            return $this->sanitizeFilename($filename);
        }

        // 3. البحث عن رقم السند
        if (preg_match('/رقم\s*السند\s*[:\-]?\s*(\d+)/u', $content, $matches)) {
            $filename = 'سند_' . $matches[1];
            Log::info("Found رقم السند via OCR: {$filename}");
            return $this->sanitizeFilename($filename);
        }

        // 4. البحث عن تاريخ
        if (preg_match('/(\d{2}\/\d{2}\/\d{4})/u', $content, $matches)) {
            $filename = 'مستند_' . str_replace('/', '-', $matches[1]);
            Log::info("Found تاريخ via OCR: {$filename}");
            return $this->sanitizeFilename($filename);
        }

        // 5. البحث عن أي رقم بارز في النص (كحل أخير)
        if (preg_match('/\b(\d{4,})\b/u', $content, $matches)) {
            $filename = 'مستند_' . $matches[1];
            Log::info("Found general number via OCR: {$filename}");
            return $this->sanitizeFilename($filename);
        }

        // 6. إذا فشل كل شيء، استخدام الباركود
        $filename = 'مستند_' . $barcode . '_' . ($index + 1);
        Log::info("Using fallback filename: {$filename}");
        return $this->sanitizeFilename($filename);
    }

    /**
     * استخراج النص باستخدام OCR
     */
    private function extractTextWithOCR($pdfPath, $page)
    {
        try {
            // تحويل صفحة PDF إلى صورة
            $imagePath = $this->convertToHighQualityImage($pdfPath, $page);
            if (!$imagePath) {
                Log::error("Failed to convert page {$page} to image");
                return '';
            }

            // استخدام tesseract للتعرف على النص العربي
            $tempDir = storage_path("app/temp");
            $outputFile = $tempDir . "/ocr_output_" . time();

            $cmd = "tesseract " . escapeshellarg($imagePath) . " " .
                   escapeshellarg($outputFile) . " -l ara+eng 2>&1";

            Log::info("Running OCR command: tesseract for page {$page}");
            exec($cmd, $output, $returnVar);

            $content = '';
            if (file_exists($outputFile . '.txt')) {
                $content = file_get_contents($outputFile . '.txt');
                $content = preg_replace('/\s+/', ' ', $content);
                $content = trim($content);
                @unlink($outputFile . '.txt');
                Log::info("OCR successful for page {$page}, content length: " . strlen($content));
            } else {
                Log::error("OCR output file not found for page {$page}");
            }

            // تنظيف الملف المؤقت
            @unlink($imagePath);

            return $content;

        } catch (Exception $e) {
            Log::error("OCR extraction failed for page {$page}: " . $e->getMessage());
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

        // استخدام إعدادات عالية الجودة لتحسين OCR
        $cmd = "pdftoppm -f {$page} -l {$page} -png -r 300 -singlefile " .
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
