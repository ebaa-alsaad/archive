<?php

namespace App\Services;

use Exception;
use App\Models\Group;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BarcodeOCRService
{
    private $tempDir;
    private $processedImages = [];

    public function __construct()
    {
        $this->tempDir = storage_path("app/temp");
        if (!file_exists($this->tempDir)) {
            mkdir($this->tempDir, 0775, true);
        }
    }

    public function __destruct()
    {
        // تنظيف الملفات المؤقتة تلقائياً
        $this->cleanupTempFiles();
    }

    /**
     * المعالجة الرئيسية للملف - محسنة
     */
    public function processPdf($upload)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        $pdfPath = Storage::disk('private')->path($upload->stored_filename);

        if (!file_exists($pdfPath)) {
            throw new Exception("PDF file not found: " . $pdfPath);
        }

        // الحصول على عدد الصفحات ومعلومات إضافية مرة واحدة
        $pdfInfo = $this->getPdfInfo($pdfPath);
        $pageCount = $pdfInfo['pages'];
        Log::info("Processing PDF with {$pageCount} pages");

        // قراءة الباركود من الصفحة الأولى فقط
        $separatorBarcode = $this->readPageBarcode($pdfPath, 1) ?? 'default_barcode';
        Log::info("Using separator barcode: {$separatorBarcode}");

        // تقسيم الصفحات إلى أقسام - محسن
        $sections = $this->splitIntoSections($pdfPath, $pageCount, $separatorBarcode);
        Log::info("Total sections found: " . count($sections));

        // معالجة متوازية للمجموعات
        $createdGroups = $this->processSections($sections, $pdfPath, $separatorBarcode, $upload);

        Log::info("Processing completed successfully", [
            'sections_created' => count($createdGroups),
            'section_names' => array_map(fn($g) => $g->pdf_path, $createdGroups)
        ]);

        return $createdGroups;
    }

    /**
     * تقسيم الصفحات إلى أقسام - محسن
     */
    private function splitIntoSections($pdfPath, $pageCount, $separatorBarcode)
    {
        $sections = [];
        $currentSection = [];
        $batchSize = 10; // معالجة الصفحات على شكل دفعات

        for ($batchStart = 1; $batchStart <= $pageCount; $batchStart += $batchSize) {
            $batchEnd = min($batchStart + $batchSize - 1, $pageCount);
            
            for ($page = $batchStart; $page <= $batchEnd; $page++) {
                $barcode = $this->readPageBarcode($pdfPath, $page);
                
                if ($barcode === $separatorBarcode) {
                    if (!empty($currentSection)) {
                        $sections[] = $currentSection;
                        $currentSection = [];
                    }
                } else {
                    $currentSection[] = $page;
                }
            }
        }

        if (!empty($currentSection)) {
            $sections[] = $currentSection;
        }

        return $sections;
    }

    /**
     * معالجة الأقسام بشكل أكثر كفاءة
     */
    private function processSections($sections, $pdfPath, $separatorBarcode, $upload)
    {
        $createdGroups = [];
        $directory = "groups";
        $fullDir = storage_path("app/private/{$directory}");
        
        if (!file_exists($fullDir)) {
            mkdir($fullDir, 0775, true);
        }

        foreach ($sections as $index => $pages) {
            if (empty($pages)) {
                continue;
            }

            // إنشاء الملف أولاً ثم استخراج الاسم (أكثر كفاءة)
            $tempFilename = $this->generateTempFilename($pages, $index, $separatorBarcode);
            $filenameSafe = $tempFilename . '.pdf';
            $outputPath = "{$fullDir}/{$filenameSafe}";
            $dbPath = "{$directory}/{$filenameSafe}";

            // إنشاء PDF أولاً
            $pdfCreated = $this->createQuickPdf($pdfPath, $pages, $outputPath);

            if ($pdfCreated) {
                // استخراج الاسم بعد إنشاء الملف (إذا لزم الأمر)
                $betterFilename = $this->generateFilenameWithOCR($pdfPath, $pages, $index, $separatorBarcode);
                if ($betterFilename !== $tempFilename) {
                    $betterFilenameSafe = $betterFilename . '.pdf';
                    $betterOutputPath = "{$fullDir}/{$betterFilenameSafe}";
                    $betterDbPath = "{$directory}/{$betterFilenameSafe}";
                    
                    if (rename($outputPath, $betterOutputPath)) {
                        $outputPath = $betterOutputPath;
                        $dbPath = $betterDbPath;
                        $filenameSafe = $betterFilenameSafe;
                    }
                }

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

        return $createdGroups;
    }

    /**
     * إنشاء اسم مؤقت سريع
     */
    private function generateTempFilename($pages, $index, $barcode)
    {
        return $this->sanitizeFilename($barcode . '_' . ($index + 1));
    }

    /**
     * إنشاء اسم ملف باستخدام OCR - محسن
     */
    private function generateFilenameWithOCR($pdfPath, $pages, $index, $barcode)
    {
        $firstPage = $pages[0];
        
        // محاولة استخراج النص من الصفحة الأولى فقط
        $content = $this->extractWithPdftotext($pdfPath, $firstPage);
        
        // OCR فقط إذا كان المحتوى غير كافي
        if (empty($content) || strlen($content) < 50) {
            $content = $this->extractTextWithOCR($pdfPath, $firstPage);
        }

        // البحث عن الأنماط دفعة واحدة
        $filename = $this->findBestFilename($content, $barcode, $index);
        
        Log::info("Generated filename for section {$index}: {$filename}");
        return $filename;
    }

    /**
     * البحث عن أفضل اسم ملف - محسن
     */
    private function findBestFilename($content, $barcode, $index)
    {
        $patterns = [
            'qeed' => ['رقم\s*القيد\s*[:\-]?\s*(\d+)', 'القيد\s*[:\-]?\s*(\d+)', 'قيد\s*[:\-]?\s*(\d+)'],
            'invoice' => ['رقم\s*الفاتورة\s*[:\-]?\s*(\d+)', 'الفاتورة\s*[:\-]?\s*(\d+)'],
            'saned' => ['رقم\s*السند\s*[:\-]?\s*(\d+)', 'السند\s*[:\-]?\s*(\d+)']
        ];

        foreach ($patterns as $type => $typePatterns) {
            foreach ($typePatterns as $pattern) {
                if (preg_match('/' . $pattern . '/ui', $content, $matches)) {
                    Log::info("Found {$type}: {$matches[1]}");
                    return $this->sanitizeFilename($matches[1]);
                }
            }
        }

        // البحث عن التاريخ
        $date = $this->findDate($content);
        if ($date) {
            return $this->sanitizeFilename($date);
        }

        // اسم افتراضي
        return $this->sanitizeFilename($barcode . '_' . ($index + 1));
    }

    /**
     * استخراج النص باستخدام pdftotext - محسن
     */
    private function extractWithPdftotext($pdfPath, $page)
    {
        $tempFile = $this->tempDir . "/pdftotext_" . md5($pdfPath . $page) . ".txt";

        // استخدام الذاكرة المؤقتة لتجنب إعادة المعالجة
        if (file_exists($tempFile) && (time() - filemtime($tempFile)) < 300) {
            return file_get_contents($tempFile);
        }

        $cmd = "pdftotext -f {$page} -l {$page} -layout " . 
               escapeshellarg($pdfPath) . " " . escapeshellarg($tempFile) . " 2>&1";
        
        exec($cmd, $output, $returnVar);

        $content = '';
        if (file_exists($tempFile)) {
            $content = file_get_contents($tempFile);
            $content = preg_replace('/\s+/', ' ', $content);
            $content = trim($content);
        }

        return $content;
    }

    /**
     * استخراج النص باستخدام OCR - محسن
     */
    private function extractTextWithOCR($pdfPath, $page)
    {
        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) return '';

            $cacheKey = md5_file($imagePath);
            if (isset($this->processedImages[$cacheKey])) {
                @unlink($imagePath);
                return $this->processedImages[$cacheKey];
            }

            $outputFile = $this->tempDir . "/ocr_" . $cacheKey;

            // استخدام إعدادات أسرع لـ tesseract
            $cmd = "tesseract " . escapeshellarg($imagePath) . " " .
                   escapeshellarg($outputFile) . " -l ara+eng --psm 6 -c preserve_interword_spaces=1 2>&1";

            exec($cmd, $output, $returnVar);

            $content = '';
            if (file_exists($outputFile . '.txt')) {
                $content = file_get_contents($outputFile . '.txt');
                $content = preg_replace('/\s+/', ' ', $content);
                $content = trim($content);
                
                // تخزين في الذاكرة المؤقتة
                $this->processedImages[$cacheKey] = $content;
            }

            @unlink($imagePath);
            return $content;

        } catch (Exception $e) {
            Log::error("OCR extraction failed: " . $e->getMessage());
            return '';
        }
    }

    /**
     * إنشاء PDF سريع - محسن
     */
    private function createQuickPdf($pdfPath, $pages, $outputPath)
    {
        try {
            $firstPage = min($pages);
            $lastPage = max($pages);
            
            // استخدام ghostscript مع إعدادات محسنة للسرعة
            $cmd = "gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite " .
                   "-dFirstPage={$firstPage} -dLastPage={$lastPage} " .
                   "-dPDFSETTINGS=/prepress -dCompatibilityLevel=1.4 " .
                   "-sOutputFile=" . escapeshellarg($outputPath) . " " .
                   escapeshellarg($pdfPath) . " 2>&1";
            
            exec($cmd, $output, $returnVar);
            
            return $returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 0;
            
        } catch (Exception $e) {
            Log::error("Quick PDF creation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * الحصول على معلومات PDF - محسن
     */
    public function getPdfInfo($pdfPath)
    {
        $cmd = "pdfinfo " . escapeshellarg($pdfPath) . " 2>&1";
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            throw new Exception("Page count failed: " . implode("\n", $output));
        }

        $info = ['pages' => 0];
        foreach ($output as $line) {
            if (preg_match('/Pages:\s*(\d+)/i', $line, $matches)) {
                $info['pages'] = (int)$matches[1];
            }
        }

        if ($info['pages'] === 0) {
            throw new Exception("Unable to determine page count");
        }

        return $info;
    }

    /**
     * تنظيف الملفات المؤقتة
     */
    private function cleanupTempFiles()
    {
        try {
            $files = glob($this->tempDir . "/*.{txt,png}", GLOB_BRACE);
            $now = time();
            
            foreach ($files as $file) {
                if (is_file($file) && ($now - filemtime($file)) > 3600) { // ملفات أقدم من ساعة
                    @unlink($file);
                }
            }
            
            $this->processedImages = [];
        } catch (Exception $e) {
            Log::warning("Temp cleanup failed: " . $e->getMessage());
        }
    }

    // باقي الدوال كما هي بدون تغيير (findDate, sanitizeFilename, convertToImage, readPageBarcode, scanBarcode)
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

    private function sanitizeFilename($filename)
    {
        $clean = preg_replace('/[^\p{Arabic}a-zA-Z0-9_-]/u', '_', $filename);
        $clean = preg_replace('/_{2,}/', '_', $clean);
        $clean = trim($clean, '_');
        return $clean;
    }

    private function convertToImage($pdfPath, $page)
    {
        $base = "page_{$page}_" . md5($pdfPath . $page);
        $pngPath = "{$this->tempDir}/{$base}.png";

        // استخدام الذاكرة المؤقتة للصور
        if (file_exists($pngPath)) {
            return $pngPath;
        }

        $cmd = "pdftoppm -f {$page} -l {$page} -png -singlefile -r 150 " .
               escapeshellarg($pdfPath) . " " .
               escapeshellarg("{$this->tempDir}/{$base}") . " 2>&1";
        exec($cmd);

        return file_exists($pngPath) ? $pngPath : null;
    }

    private function readPageBarcode($pdfPath, $page)
    {
        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) return null;

            $barcode = $this->scanBarcode($imagePath);
            return $barcode;
        } catch (Exception $e) {
            Log::error("Barcode reading failed for page {$page}: " . $e->getMessage());
            return null;
        }
    }

    private function scanBarcode($imagePath)
    {
        $cmd = "zbarimg -q --raw " . escapeshellarg($imagePath) . " 2>&1";
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && !empty($output)) {
            return trim($output[0]);
        }
        return null;
    }
}