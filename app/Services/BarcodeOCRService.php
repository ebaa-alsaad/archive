<?php

namespace App\Services;

use Exception;
use App\Models\Group;
use App\Models\Upload;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Bus;
use App\Jobs\ProcessPdfSectionJob;

class BarcodeOCRService
{
    private $imageCache = [];
    private $barcodeCache = [];
    private $textCache = [];
    private $ocrCache = [];
    private $pdfHash = null;
    private $tempFiles = [];
    private $parallelLimit = 3;

    /**
     * المعالجة الرئيسية مع المعالجة المتوازية
     */
    public function processPdf(Upload $upload)
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
        $upload->update(['total_pages' => $pageCount]);
        
        Log::info("Starting parallel PDF processing", [
            'upload_id' => $upload->id,
            'pages' => $pageCount
        ]);

        // 1. اكتشاف الباركود الفاصل من جميع الصفحات
        $separatorBarcode = $this->detectSeparatorBarcode($pdfPath, $pageCount);
        
        // 2. تقسيم الملف إلى أقسام بناءً على الباركود الفاصل
        $sections = $this->splitPdfIntoSections($pdfPath, $pageCount, $separatorBarcode);
        
        // 3. معالجة متوازية للأقسام
        $createdGroups = $this->processSections($pdfPath, $sections, $separatorBarcode, $upload);

        $this->cleanupTemp();

        Log::info("Parallel PDF processing completed", [
            'upload_id' => $upload->id,
            'groups_created' => count($createdGroups)
        ]);

        return $createdGroups;
    }

    /**
     * اكتشاف الباركود الفاصل من جميع الصفحات
     */
    private function detectSeparatorBarcode($pdfPath, $pageCount)
    {
        $barcodeFrequency = [];
        
        // فحص أول 10 صفحات أو كل الصفحات إذا كان العدد أقل
        $sampleSize = min(10, $pageCount);
        
        for ($page = 1; $page <= $sampleSize; $page++) {
            $barcode = $this->readPageBarcode($pdfPath, $page);
            if ($barcode) {
                $barcodeFrequency[$barcode] = ($barcodeFrequency[$barcode] ?? 0) + 1;
            }
        }

        // إذا لم نجد أي باركود، نستخدم قيمة افتراضية
        if (empty($barcodeFrequency)) {
            return 'default_separator_' . Str::random(8);
        }

        // إرجاع الباركود الأكثر تكراراً
        arsort($barcodeFrequency);
        return array_key_first($barcodeFrequency);
    }

    /**
     * تقسيم الملف إلى أقسام بناءً على الباركود الفاصل
     */
    private function splitPdfIntoSections($pdfPath, $pageCount, $separatorBarcode)
    {
        $sections = [];
        $currentSection = [];
        
        for ($page = 1; $page <= $pageCount; $page++) {
            $barcode = $this->readPageBarcode($pdfPath, $page);
            
            // إذا كان هذا الباركود هو الباركود الفاصل وليس الصفحة الأولى
            if ($barcode === $separatorBarcode && $page > 1) {
                // حفظ القسم الحالي إذا كان يحتوي على صفحات
                if (!empty($currentSection)) {
                    $sections[] = $currentSection;
                    $currentSection = [];
                }
            }
            
            // إضافة الصفحة الحالية للقسم
            $currentSection[] = $page;
        }
        
        // إضافة القسم الأخير إذا كان يحتوي على صفحات
        if (!empty($currentSection)) {
            $sections[] = $currentSection;
        }

        Log::info("PDF split into sections", [
            'total_sections' => count($sections),
            'separator_barcode' => $separatorBarcode
        ]);

        return $sections;
    }

    /**
     * معالجة الأقسام
     */
    private function processSections($pdfPath, $sections, $separatorBarcode, $upload)
    {
        $createdGroups = [];

        foreach ($sections as $index => $pages) {
            if (empty($pages)) continue;

            $group = $this->createGroupFromPages($pdfPath, $pages, $index, $separatorBarcode, $upload);
            if ($group) {
                $createdGroups[] = $group;
                
                // تحديث التقدم
                $progress = intval((($index + 1) / count($sections)) * 100);
                Redis::set("upload_progress:{$upload->id}", $progress);
                
                Log::info("Section processed", [
                    'section_index' => $index,
                    'pages_count' => count($pages),
                    'group_id' => $group->id
                ]);
            }
        }

        return $createdGroups;
    }

    /**
     * إنشاء مجموعة من الصفحات
     */
    private function createGroupFromPages($pdfPath, $pages, $index, $barcode, $upload)
    {
        try {
            Log::info("Creating group from pages", [
                'pages' => $pages,
                'index' => $index,
                'pages_count' => count($pages)
            ]);

            $filename = $this->generateFilenameWithOCR($pdfPath, $pages, $index, $barcode);
            $filenameSafe = $this->sanitizeFilename($filename) . '.pdf';

            $directory = "groups";
            $fullDir = storage_path("app/private/{$directory}");
            
            // إنشاء المجلد إذا لم يكن موجوداً
            if (!is_dir($fullDir)) {
                mkdir($fullDir, 0775, true);
            }

            $outputPath = "{$fullDir}/{$filenameSafe}";
            $dbPath = "{$directory}/{$filenameSafe}";

            Log::info("PDF output details", [
                'output_path' => $outputPath,
                'db_path' => $dbPath
            ]);

            // إنشاء الـ PDF
            if ($this->createPdfFromPages($pdfPath, $pages, $outputPath)) {
                Log::info("PDF created successfully", [
                    'output_path' => $outputPath,
                    'file_exists' => file_exists($outputPath),
                    'file_size' => file_exists($outputPath) ? filesize($outputPath) : 0
                ]);

                $group = Group::create([
                    'code' => $barcode,
                    'pdf_path' => $dbPath,
                    'pages_count' => count($pages),
                    'user_id' => $upload->user_id,
                    'upload_id' => $upload->id,
                    'filename' => $filenameSafe
                ]);

                Log::info("Group record created", ['group_id' => $group->id]);
                return $group;
            } else {
                Log::error("Failed to create PDF file", [
                    'output_path' => $outputPath,
                    'pages' => $pages
                ]);
            }

        } catch (Exception $e) {
            Log::error("Failed to create group from pages", [
                'upload_id' => $upload->id,
                'pages' => $pages,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return null;
    }

    /**
     * إنشاء PDF من صفحات محددة - الطريقة المصححة
     */
    private function createPdfFromPages($pdfPath, $pages, $outputPath)
    {
        try {
            if (empty($pages)) {
                Log::error("No pages provided for PDF creation");
                return false;
            }

            // استخدام pdftk إذا كان متاحاً (أكثر موثوقية)
            if ($this->isCommandAvailable('pdftk')) {
                return $this->createPdfWithPdftk($pdfPath, $pages, $outputPath);
            }

            // استخدام Ghostscript كبديل
            return $this->createPdfWithGhostscript($pdfPath, $pages, $outputPath);

        } catch (Exception $e) {
            Log::error("PDF creation failed", [
                'error' => $e->getMessage(),
                'pages' => $pages
            ]);
            return false;
        }
    }

    /**
     * إنشاء PDF باستخدام pdftk (موثوق أكثر)
     */
    private function createPdfWithPdftk($pdfPath, $pages, $outputPath)
    {
        $pagesList = implode(' ', $pages);
        $cmd = sprintf(
            'pdftk %s cat %s output %s 2>&1',
            escapeshellarg($pdfPath),
            $pagesList,
            escapeshellarg($outputPath)
        );

        Log::debug("pdftk command", ['cmd' => $cmd]);

        exec($cmd, $output, $returnVar);

        $success = $returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 0;

        Log::info("PDF creation with pdftk result", [
            'success' => $success,
            'return_var' => $returnVar,
            'file_size' => $success ? filesize($outputPath) : 0
        ]);

        return $success;
    }

    /**
     * إنشاء PDF باستخدام Ghostscript
     */
    private function createPdfWithGhostscript($pdfPath, $pages, $outputPath)
    {
        $firstPage = min($pages);
        $lastPage = max($pages);

        Log::info("Creating PDF with Ghostscript", [
            'input_path' => $pdfPath,
            'output_path' => $outputPath,
            'first_page' => $firstPage,
            'last_page' => $lastPage,
            'pages_count' => count($pages)
        ]);

        $cmd = sprintf(
            'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dCompatibilityLevel=1.7 -dPDFSETTINGS=/prepress -dFirstPage=%d -dLastPage=%d -sOutputFile=%s %s 2>&1',
            intval($firstPage),
            intval($lastPage),
            escapeshellarg($outputPath),
            escapeshellarg($pdfPath)
        );

        Log::debug("Ghostscript command", ['cmd' => $cmd]);

        exec($cmd, $output, $returnVar);

        $success = $returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 0;

        Log::info("PDF creation with Ghostscript result", [
            'success' => $success,
            'return_var' => $returnVar,
            'file_size' => $success ? filesize($outputPath) : 0
        ]);

        return $success;
    }

    /**
     * التحقق من توفر الأمر في النظام
     */
    private function isCommandAvailable($command)
    {
        $cmd = sprintf('which %s 2>/dev/null', escapeshellarg($command));
        exec($cmd, $output, $returnVar);
        return $returnVar === 0;
    }

    /**
     * قراءة الباركود من صفحة معينة
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
            Log::debug("Barcode read result", [
                'page' => $page,
                'barcode' => $barcode,
                'image_path' => $imagePath
            ]);

            return $this->barcodeCache[$cacheKey] = $barcode;
        } catch (Exception $e) {
            Log::warning("Barcode reading failed", [
                'page' => $page,
                'error' => $e->getMessage()
            ]);
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

        $base = "page_" . md5($cacheKey);
        $pngPath = "{$tempDir}/{$base}.png";

        if (file_exists($pngPath)) {
            $this->tempFiles[] = $pngPath;
            return $this->imageCache[$cacheKey] = $pngPath;
        }

        $cmd = sprintf(
            'pdftoppm -f %d -l %d -png -singlefile -r 150 %s %s 2>/dev/null',
            intval($page),
            intval($page),
            escapeshellarg($pdfPath),
            escapeshellarg("{$tempDir}/{$base}")
        );

        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && file_exists($pngPath)) {
            $this->tempFiles[] = $pngPath;
            return $this->imageCache[$cacheKey] = $pngPath;
        }

        Log::warning("PDF to image conversion failed", [
            'page' => $page,
            'return_var' => $returnVar,
            'output' => $output
        ]);

        return null;
    }

    /**
     * مسح الباركود من الصورة
     */
    private function scanBarcode($imagePath)
    {
        $cmd = sprintf('zbarimg -q --raw %s 2>/dev/null', escapeshellarg($imagePath));
        exec($cmd, $output, $returnVar);
        
        if ($returnVar === 0 && !empty($output)) {
            $first = trim(is_array($output) ? $output[0] : $output);
            return $first === '' ? null : $first;
        }
        
        return null;
    }

    /**
     * إنشاء اسم الملف باستخدام OCR
     */
    private function generateFilenameWithOCR($pdfPath, $pages, $index, $barcode)
    {
        $firstPage = $pages[0];
        
        // محاولة استخراج النص من الصفحة الأولى
        $content = $this->extractWithPdftotext($pdfPath, $firstPage);

        if (empty($content) || mb_strlen($content) < 40) {
            $content = $this->extractTextWithOCR($pdfPath, $firstPage);
        }

        // إذا لم نحصل على محتوى ذو معنى، نستخدم الباركود ورقم الفهرس
        if (empty($content) || $this->looksLikeGarbled($content)) {
            return $this->sanitizeFilename($barcode . '_section_' . ($index + 1));
        }

        // البحث عن معلومات المستند في المحتوى
        $documentInfo = $this->smartDocumentRecognition($content);
        
        if (!empty($documentInfo)) {
            foreach (['قيد', 'فاتورة', 'سند'] as $type) {
                if (isset($documentInfo[$type])) {
                    return $this->sanitizeFilename($documentInfo[$type]);
                }
            }
            if (isset($documentInfo['تاريخ'])) {
                return $this->sanitizeFilename($documentInfo['تاريخ'] . '_' . $barcode);
            }
        }

        // استخدام أول 50 حرفاً من المحتوى كاسم ملف
        $cleanContent = preg_replace('/\s+/', '_', substr(trim($content), 0, 50));
        return $this->sanitizeFilename($cleanContent . '_' . ($index + 1));
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

        $tempFile = "{$tempDir}/pdftxt_" . md5($cacheKey) . ".txt";

        if (file_exists($tempFile)) {
            $content = $this->readAndCleanFile($tempFile);
            return $this->textCache[$cacheKey] = $content;
        }

        $cmd = sprintf(
            'pdftotext -f %d -l %d -layout -nopgbrk %s %s 2>/dev/null',
            intval($page),
            intval($page),
            escapeshellarg($pdfPath),
            escapeshellarg($tempFile)
        );

        exec($cmd, $output, $returnVar);

        $content = file_exists($tempFile) ? $this->readAndCleanFile($tempFile) : '';

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

            $outputFileBase = "{$tempDir}/ocr_" . md5($cacheKey);

            $cmd = sprintf(
                'tesseract %s %s -l ara+eng --psm 6 -c preserve_interword_spaces=1 2>/dev/null',
                escapeshellarg($imagePath),
                escapeshellarg($outputFileBase)
            );

            exec($cmd, $output, $returnVar);

            $textFile = $outputFileBase . '.txt';
            $content = file_exists($textFile) ? $this->readAndCleanFile($textFile) : '';
            
            if (file_exists($textFile)) {
                unlink($textFile);
            }

            return $this->ocrCache[$cacheKey] = $content;

        } catch (Exception $e) {
            Log::warning("OCR extraction failed", [
                'page' => $page, 
                'error' => $e->getMessage()
            ]);
            return $this->ocrCache[$cacheKey] = '';
        }
    }

    /**
     * قراءة وتنظيف محتوى الملف
     */
    private function readAndCleanFile($filepath)
    {
        if (!file_exists($filepath)) {
            return '';
        }
        
        $content = file_get_contents($filepath);
        $content = trim(preg_replace('/\s+/u', ' ', $content));
        $content = preg_replace('/[^\p{Arabic}\p{L}\p{N}\s\-_\.:]/u', '', $content);
        
        return trim($content);
    }

    /**
     * خوارزمية ذكية للتعرف على أنماط المستندات
     */
    private function smartDocumentRecognition($content)
    {
        $patterns = $this->getEnhancedPatterns();
        $matches = [];
        
        foreach ($patterns as $type => $typePatterns) {
            foreach ($typePatterns as $pattern) {
                if (preg_match($pattern, $content, $match)) {
                    $matches[$type] = trim($match[1] ?? $match[0]);
                    break;
                }
            }
        }
        
        return $matches;
    }

    /**
     * أنماط محسنة مع تعابير منتظمة أكثر ذكاء
     */
    private function getEnhancedPatterns()
    {
        return [
            'قيد' => [
                '/رقم\s*القيد\s*[:\-\s]*(\d+)/ui',
                '/القيد\s*رقم\s*(\d+)/ui',
                '/قيد\s*[:\-\s]*(\d+)/ui',
                '/(?:رقم\s*)?القيد\s*(?:رقم\s*)?[:\-\s]*(\d+)/ui'
            ],
            'فاتورة' => [
                '/رقم\s*الفاتورة\s*[:\-\s]*(\d+)/ui',
                '/الفاتورة\s*رقم\s*(\d+)/ui',
                '/فاتورة\s*[:\-\s]*(\d+)/ui',
                '/invoice\s*(?:no|number)?\s*[:\-\s]*(\d+)/ui'
            ],
            'سند' => [
                '/رقم\s*السند\s*[:\-\s]*(\d+)/ui',
                '/السند\s*رقم\s*(\d+)/ui',
                '/سند\s*[:\-\s]*(\d+)/ui',
                '/voucher\s*(?:no|number)?\s*[:\-\s]*(\d+)/ui'
            ],
            'تاريخ' => [
                '/(\d{1,2}\/\d{1,2}\/\d{2,4})/u',
                '/(\d{1,2}-\d{1,2}-\d{2,4})/u',
                '/(\d{4}-\d{1,2}-\d{1,2})/u',
                '/تاريخ\s*[:\-\s]*(\d{1,2}\/\d{1,2}\/\d{2,4})/ui'
            ]
        ];
    }

    /**
     * تنظيف اسم الملف
     */
    private function sanitizeFilename($filename)
    {
        $clean = preg_replace('/[^\p{Arabic}a-zA-Z0-9\-_\.\s]/u', '_', (string)$filename);
        $clean = preg_replace('/\s+/', '_', $clean);
        $clean = preg_replace('/[_\.]{2,}/', '_', $clean);
        $clean = trim($clean, '_-.');
        return $clean === '' ? 'document_' . time() : $clean;
    }

    /**
     * التحقق إذا كان المحتوى غير مفهوم
     */
    private function looksLikeGarbled($content)
    {
        if (empty($content)) {
            return true;
        }
        
        preg_match_all('/[^\p{Arabic}\p{N}\s\p{P}]/u', $content, $matches);
        $garbledCount = count($matches[0] ?? []);
        $totalLength = mb_strlen($content);
        
        return $totalLength > 0 && ($garbledCount / $totalLength) > 0.3;
    }

    /**
     * الحصول على عدد صفحات PDF
     */
    public function getPdfPageCount($pdfPath)
    {
        $cmd = 'pdfinfo ' . escapeshellarg($pdfPath) . ' 2>/dev/null';
        exec($cmd, $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new Exception("Failed to get PDF page count");
        }
        
        foreach ($output as $line) {
            if (preg_match('/Pages:\s*(\d+)/i', $line, $matches)) {
                return (int)$matches[1];
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
        $this->imageCache = [];
        $this->barcodeCache = [];
        $this->textCache = [];
        $this->ocrCache = [];
        
        // تنظيف الملفات المؤقتة القديمة
        $tempDir = storage_path("app/temp");
        if (file_exists($tempDir)) {
            foreach (glob($tempDir . "/*") as $file) {
                if (filemtime($file) < time() - 3600) {
                    @unlink($file);
                }
            }
        }
        
        gc_collect_cycles();
    }

    /**
     * معالجة قسم فردي (للاستخدام في الـ Job)
     */
    public function processSection($pdfPath, $pages, $index, $separatorBarcode, $upload)
    {
        return $this->createGroupFromPages($pdfPath, $pages, $index, $separatorBarcode, $upload);
    }

    /**
     * دالة التدمير - تنظيف تلقائي
     */
    public function __destruct()
    {
        $this->cleanupTemp();
    }
}