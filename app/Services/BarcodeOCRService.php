<?php

namespace App\Services;

use Exception;
use App\Models\Group;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BarcodeOCRService
{
    private $imageCache = [];
    private $barcodeCache = [];
    private $textCache = [];

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

        // قراءة الباركود من الصفحة الأولى فقط
        $separatorBarcode = $this->readPageBarcode($pdfPath, 1) ?? 'default_barcode';
        Log::info("Using separator barcode: {$separatorBarcode}");

        // تقسيم الصفحات إلى أقسام حسب الباركود المتكرر
        $sections = [];
        $currentSection = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            $barcode = $this->readPageBarcode($pdfPath, $page);
            if ($barcode === $separatorBarcode) {
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

            // استخدام نفس الآلية للتسمية لجميع الملفات
            $filename = $this->generateFilenameWithOCR($pdfPath, $pages, $index, $separatorBarcode);
            $filenameSafe = $filename . '.pdf';

            $directory = "groups";
            $fullDir = storage_path("app/private/{$directory}");
            if (!file_exists($fullDir)) mkdir($fullDir, 0775, true);

            $outputPath = "{$fullDir}/{$filenameSafe}";
            $dbPath = "{$directory}/{$filenameSafe}";

            // استخدام طريقة سريعة لإنشاء PDF
            $pdfCreated = $this->createQuickPdf($pdfPath, $pages, $outputPath);

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
     * إنشاء اسم ملف باستخدام OCR لاستخراج النص - نفس الآلية للجميع
     */
    private function generateFilenameWithOCR($pdfPath, $pages, $index, $barcode)
    {
        $firstPage = $pages[0];
        
        // استخدام pdftotext أولاً لأنه أسرع
        $content = $this->extractWithPdftotext($pdfPath, $firstPage);
        
        // إذا لم يحصل على محتوى جيد، استخدم OCR
        if (empty($content) || strlen($content) < 40) {
        // النص ضعيف → استخدم OCR
            $content = $this->extractTextWithOCR($pdfPath, $firstPage);
        }

        Log::info("Extracted content from page {$firstPage}: " . substr($content, 0, 200));

        // 1. الأولوية: البحث عن رقم القيد
        $qeedNumber = $this->findDocumentNumber($content, 'قيد', [
            'رقم\s*القيد\s*[:\-]?\s*(\d+)',
            'القيد\s*[:\-]?\s*(\d+)',
            'قيد\s*[:\-]?\s*(\d+)',
            'رقم\s*[:\-]?\s*(\d+).*قيد'
        ]);

        if ($qeedNumber) {
            $filename = $qeedNumber;
            Log::info("Found رقم القيد: {$filename}");
            return $this->sanitizeFilename($filename);
        }

        // 2. الثانية: البحث عن رقم الفاتورة
        $invoiceNumber = $this->findDocumentNumber($content, 'فاتورة', [
            'رقم\s*الفاتورة\s*[:\-]?\s*(\d+)',
            'الفاتورة\s*[:\-]?\s*(\d+)',
            'فاتورة\s*[:\-]?\s*(\d+)'
        ]);

        if ($invoiceNumber) {
            $filename = $invoiceNumber;
            Log::info("Found رقم الفاتورة: {$filename}");
            return $this->sanitizeFilename($filename);
        }

        // 3. الثالثة: البحث عن رقم السند
        $sanedNumber = $this->findDocumentNumber($content, 'سند', [
            'رقم\s*السند\s*[:\-]?\s*(\d+)',
            'السند\s*[:\-]?\s*(\d+)',
            'سند\s*[:\-]?\s*(\d+)'
        ]);

        if ($sanedNumber) {
            $filename = $sanedNumber;
            Log::info("Found رقم السند: {$filename}");
            return $this->sanitizeFilename($filename);
        }

        // 4. الرابعة: البحث عن تاريخ
        $date = $this->findDate($content);
        if ($date) {
            $filename = $date;
            Log::info("Found تاريخ: {$filename}");
            return $this->sanitizeFilename($filename);
        }

        // 5. الخيار الأخير: اسم وصفي مع الباركود
        $filename = $barcode . '_' . ($index + 1);
        Log::info("Using fallback filename: {$filename}");
        return $this->sanitizeFilename($filename);
    }

    /**
     * استخراج النص باستخدام pdftotext (أسرع)
     */
    private function extractWithPdftotext($pdfPath, $page)
    {
        if (isset($this->textCache[$page])) {
            return $this->textCache[$page];
        }

        $tempDir = storage_path("app/temp");
        $tempFile = $tempDir . "/pdftxt_" . md5($pdfPath . $page) . ".txt";

        // لو ملف النص موجود مسبقاً
        if (file_exists($tempFile)) {
            return $this->textCache[$page] = trim(
                preg_replace('/\s+/', ' ', file_get_contents($tempFile))
            );
        }

        $cmd = "pdftotext -f {$page} -l {$page} -layout " .
            escapeshellarg($pdfPath) . " " . escapeshellarg($tempFile) . " 2>&1";

        exec($cmd);

        $content = '';
        if (file_exists($tempFile)) {
            $content = file_get_contents($tempFile);
            $content = preg_replace('/\s+/', ' ', $content);
            $content = trim($content);
        }

        return $this->textCache[$page] = $content;
    }


    /**
     * استخراج النص باستخدام OCR (للحالات الصعبة فقط)
     */
    private function extractTextWithOCR($pdfPath, $page)
    {
        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) return '';

            $tempDir = storage_path("app/temp");
            $outputFile = $tempDir . "/ocr_" . time();

            // استخدام إعداد سريع واحد فقط
            $cmd = "tesseract " . escapeshellarg($imagePath) . " " .
                   escapeshellarg($outputFile) . " -l ara --psm 6 2>&1";

            exec($cmd, $output, $returnVar);

            $content = '';
            if (file_exists($outputFile . '.txt')) {
                $content = file_get_contents($outputFile . '.txt');
                $content = preg_replace('/\s+/', ' ', $content);
                $content = trim($content);
                @unlink($outputFile . '.txt');
            }

            @unlink($imagePath);
            return $content;

        } catch (Exception $e) {
            Log::error("OCR extraction failed: " . $e->getMessage());
            return '';
        }
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
                Log::info("Found {$documentType}: {$number}");
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
     * إنشاء PDF سريع باستخدام ghostscript
     */
    private function createQuickPdf($pdfPath, $pages, $outputPath)
    {
        try {
            $firstPage = min($pages);
            $lastPage = max($pages);
            
            $cmd = "gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite " .
                   "-dFirstPage={$firstPage} -dLastPage={$lastPage} " .
                   "-sOutputFile=" . escapeshellarg($outputPath) . " " .
                   escapeshellarg($pdfPath) . " 2>&1";
            
            exec($cmd, $output, $returnVar);
            
            return $returnVar === 0 && file_exists($outputPath);
            
        } catch (Exception $e) {
            Log::error("Quick PDF creation failed: " . $e->getMessage());
            return false;
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
     * تحويل صفحة PDF إلى صورة PNG
     */
    private function convertToImage($pdfPath, $page)
    {
        if (isset($this->imageCache[$page])) {
            return $this->imageCache[$page];
        }

        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

        $base = "page_{$page}_" . md5($pdfPath);
        $pngPath = "{$tempDir}/{$base}.png";

        // إذا الصورة موجودة من قبل لا تعيد تحويلها
        if (file_exists($pngPath)) {
            return $this->imageCache[$page] = $pngPath;
        }

        $cmd = "pdftoppm -f {$page} -l {$page} -png -singlefile " .
            escapeshellarg($pdfPath) . " " .
            escapeshellarg("{$tempDir}/{$base}") . " 2>&1";

        exec($cmd);

        if (file_exists($pngPath)) {
            return $this->imageCache[$page] = $pngPath;
        }

        return null;
    }


    /**
     * قراءة الباركود من صفحة PDF
     */
    private function readPageBarcode($pdfPath, $page)
    {
        if (isset($this->barcodeCache[$page])) {
            return $this->barcodeCache[$page];
        }

        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) return null;

            $barcode = $this->scanBarcode($imagePath);

            return $this->barcodeCache[$page] = $barcode;

        } catch (Exception $e) {
            Log::error("Barcode reading failed for page {$page}: " . $e->getMessage());
            return null;
        }
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