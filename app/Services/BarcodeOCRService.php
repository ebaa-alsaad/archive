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

        // 1. اكتشاف سريع للباركود الفاصل من الصفحات الأولى فقط
        $separatorBarcode = $this->quickDetectSeparator($pdfPath, min(5, $pageCount));
        
        // 2. تقسيم سريع باستخدام خوارزمية ذكية
        $sections = $this->smartSectionSplitting($pdfPath, $pageCount, $separatorBarcode);
        
        // 3. معالجة متوازية للأقسام
        $createdGroups = $this->parallelSectionProcessing($pdfPath, $sections, $separatorBarcode, $upload);

        $this->cleanupTemp();

        Log::info("Parallel PDF processing completed", [
            'upload_id' => $upload->id,
            'groups_created' => count($createdGroups)
        ]);

        return $createdGroups;
    }

    /**
     * معالجة قسم فردي (للاستخدام في الـ Job)
     */
    public function processSection($pdfPath, $pages, $index, $separatorBarcode, $upload)
    {
        return $this->createGroupFromPages($pdfPath, $pages, $index, $separatorBarcode, $upload);
    }

    /**
     * اكتشاف سريع للباركود الفاصل من عينة صغيرة
     */
    private function quickDetectSeparator($pdfPath, $samplePages)
    {
        $barcodes = [];
        
        for ($i = 1; $i <= $samplePages; $i++) {
            $barcode = $this->readPageBarcode($pdfPath, $i);
            if ($barcode) {
                $barcodes[$barcode] = ($barcodes[$barcode] ?? 0) + 1;
            }
        }

        // الباركود الأكثر تكراراً في العينة هو الفاصل
        if (!empty($barcodes)) {
            arsort($barcodes);
            return array_key_first($barcodes);
        }

        return 'default_' . Str::random(6);
    }

    /**
     * تقسيم ذكي باستخدام خريطة باركود أولية
     */
    private function smartSectionSplitting($pdfPath, $pageCount, $separatorBarcode)
    {
        $sections = [];
        $currentSection = [];
        
        // قراءة الباركود للصفحات الفردية أولاً لتسريع العملية
        for ($page = 1; $page <= $pageCount; $page += 2) {
            $barcode = $this->readPageBarcode($pdfPath, $page);
            
            if ($barcode === $separatorBarcode) {
                if (!empty($currentSection)) {
                    $sections[] = $this->expandSection($currentSection, $pageCount);
                    $currentSection = [];
                }
            } else {
                $currentSection[] = $page;
            }
        }

        if (!empty($currentSection)) {
            $sections[] = $this->expandSection($currentSection, $pageCount);
        }

        return $sections;
    }

    /**
     * توسيع القسم ليشمل الصفحات المتجاورة
     */
    private function expandSection($sectionPages, $pageCount)
    {
        $expanded = [];
        foreach ($sectionPages as $page) {
            $expanded[] = $page;
            // إضافة الصفحات الزوجية بين الفردية
            if ($page + 1 <= $pageCount) {
                $expanded[] = $page + 1;
            }
        }
        return array_unique($expanded);
    }

    /**
     * معالجة متوازية للأقسام
     */
    private function parallelSectionProcessing($pdfPath, $sections, $separatorBarcode, $upload)
    {
        $jobs = [];
        $createdGroups = [];

        foreach ($sections as $index => $pages) {
            if (empty($pages)) continue;

            $jobs[] = new ProcessPdfSectionJob(
                $pdfPath,
                $pages,
                $index,
                $separatorBarcode,
                $upload->id,
                $this->pdfHash
            );
        }

        // تشغيل الوظائف في دفعات متوازية
        if (count($jobs) > 0) {
            $batch = Bus::batch($jobs)
                ->then(function ($batch) use ($upload) {
                    Log::info("All sections processed", ['upload_id' => $upload->id]);
                })
                ->catch(function ($batch, $e) use ($upload) {
                    Log::error("Section processing failed", [
                        'upload_id' => $upload->id,
                        'error' => $e->getMessage()
                    ]);
                })
                ->dispatch();
        }

        // معالجة متسلسلة لجمع النتائج (بديل مؤقت)
        foreach ($sections as $index => $pages) {
            $group = $this->processSingleSection($pdfPath, $pages, $index, $separatorBarcode, $upload);
            if ($group) {
                $createdGroups[] = $group;
                
                // تحديث التقدم
                $progress = intval((($index + 1) / count($sections)) * 100);
                Redis::set("upload_progress:{$upload->id}", $progress);
            }
        }

        return $createdGroups;
    }

    /**
     * معالجة قسم واحد
     */
    private function processSingleSection($pdfPath, $pages, $index, $separatorBarcode, $upload)
    {
        return $this->createGroupFromPages($pdfPath, $pages, $index, $separatorBarcode, $upload);
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
            if ($this->createQuickPdf($pdfPath, $pages, $outputPath)) {
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
     * إنشاء PDF من صفحات محددة
     */
    private function createQuickPdf($pdfPath, $pages, $outputPath)
    {
        try {
            if (empty($pages)) {
                Log::error("No pages provided for PDF creation");
                return false;
            }

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

            Log::info("PDF creation result", [
                'success' => $success,
                'return_var' => $returnVar,
                'output_path' => $outputPath,
                'file_exists' => file_exists($outputPath),
                'file_size' => file_exists($outputPath) ? filesize($outputPath) : 0,
                'output' => $output
            ]);

            return $success;

        } catch (Exception $e) {
            Log::error("PDF creation failed", [
                'error' => $e->getMessage(),
                'pages' => $pages
            ]);
            return false;
        }
    }


    /**
     * استخراج ذكي للمعلومات من الصفحة الأولى فقط
     */
    private function extractFirstPageInfo($pdfPath, $firstPage)
    {
        // استخراج النص بثلاث طرق مختلفة في نفس الوقت
        $results = $this->extractTextMultipleMethods($pdfPath, $firstPage);
        
        // دمج وتحليل النتائج
        return $this->analyzeAndMergeResults($results);
    }

    /**
     * استخراج النص بطرق متعددة
     */
    private function extractTextMultipleMethods($pdfPath, $page)
    {
        $results = [];
        
        // 1. pdftotext سريع
        $results['pdftotext'] = $this->extractWithPdftotext($pdfPath, $page);
        
        // 2. OCR سريع على مناطق محددة فقط
        $results['ocr_fast'] = $this->fastTargetedOCR($pdfPath, $page);
        
        return $results;
    }

    /**
     * OCR سريع على مناطق مستهدفة فقط
     */
    private function fastTargetedOCR($pdfPath, $page)
    {
        $imagePath = $this->convertToImage($pdfPath, $page);
        if (!$imagePath) return '';
        
        // اقتصاص المناطق التي يحتمل أن تحتوي على معلومات مهمة
        $regions = [
            'top' => '1000x300+0+0',    // المنطقة العلوية
            'center' => '1000x200+0+300', // المنطقة الوسطى
        ];
        
        $content = '';
        foreach ($regions as $region) {
            $croppedImage = $this->cropImage($imagePath, $region);
            if ($croppedImage) {
                $text = $this->quickOCR($croppedImage);
                $content .= " " . $text;
                @unlink($croppedImage);
            }
        }
        
        return trim($content);
    }

    /**
     * اقتصاص الصورة
     */
    private function cropImage($imagePath, $geometry)
    {
        $tempPath = storage_path('app/temp/cropped_' . md5($imagePath . $geometry) . '.png');
        
        $cmd = "convert {$imagePath} -crop {$geometry} {$tempPath} 2>/dev/null";
        exec($cmd, $output, $returnVar);
        
        return $returnVar === 0 ? $tempPath : null;
    }

    /**
     * OCR سريع
     */
    private function quickOCR($imagePath)
    {
        $tempOutput = storage_path('app/temp/ocr_temp_' . md5($imagePath));
        
        $cmd = "tesseract {$imagePath} {$tempOutput} -l ara+eng --psm 6 --oem 1 2>/dev/null";
        exec($cmd, $output, $returnVar);
        
        $content = file_exists($tempOutput . '.txt') ? 
            file_get_contents($tempOutput . '.txt') : '';
        
        @unlink($tempOutput . '.txt');
        return trim($content);
    }

    /**
     * دمج وتحليل النتائج
     */
    private function analyzeAndMergeResults($results)
    {
        $bestResult = '';
        $maxScore = 0;

        foreach ($results as $method => $content) {
            $score = $this->calculateContentScore($content);
            if ($score > $maxScore) {
                $maxScore = $score;
                $bestResult = $content;
            }
        }

        return $bestResult;
    }

    /**
     * حساب جودة المحتوى
     */
    private function calculateContentScore($content)
    {
        if (empty($content)) return 0;

        $score = 0;
        
        // نقاط للطول المناسب
        $length = mb_strlen($content);
        if ($length > 50 && $length < 1000) {
            $score += 30;
        }

        // نقاط لوجود كلمات عربية
        if (preg_match('/[\p{Arabic}]+/u', $content)) {
            $score += 40;
        }

        // نقاط لوجود أرقام (مؤشر على أرقام المستندات)
        if (preg_match('/\d+/', $content)) {
            $score += 20;
        }

        // نقاط لوجود كلمات مفتاحية
        $keywords = ['قيد', 'فاتورة', 'سند', 'رقم', 'تاريخ'];
        foreach ($keywords as $keyword) {
            if (str_contains($content, $keyword)) {
                $score += 10;
                break;
            }
        }

        return $score;
    }

    /**
     * إنشاء اسم الملف باستخدام OCR
     */
    private function generateFilenameWithOCR($pdfPath, $pages, $index, $barcode)
    {
        $firstPage = $pages[0];
        
        // استخدام الاستخراج الذكي
        $content = $this->extractFirstPageInfo($pdfPath, $firstPage);

        // إذا النص غير كافي، استخدم الطرق التقليدية
        if (empty($content) || mb_strlen($content) < 40) {
            $content = $this->extractWithPdftotext($pdfPath, $firstPage);
        }

        if (empty($content) || $this->looksLikeGarbled($content)) {
            $content = $this->extractTextWithOCR($pdfPath, $firstPage);
        }

        // خوارزمية ذكية للتعرف على أنماط المستندات
        $documentInfo = $this->smartDocumentRecognition($content);
        
        if (!empty($documentInfo)) {
            foreach (['قيد', 'فاتورة', 'سند'] as $type) {
                if (isset($documentInfo[$type])) {
                    return $this->sanitizeFilename($documentInfo[$type]);
                }
            }
            if (isset($documentInfo['تاريخ'])) {
                return $this->sanitizeFilename($documentInfo['تاريخ']);
            }
        }

        // استخدام الباركود ورقم الفهرس كبديل
        return $this->sanitizeFilename($barcode . '_' . ($index + 1));
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
                    $matches[$type] = $match[1] ?? $match[0];
                    break 2; // وجدنا تطابق، نخرج من الحلقتين
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

            $outputFileBase = "{$tempDir}/ocr_{$cacheKey}";

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
     * باقي الدوال المساعدة...
     */
    private function readAndCleanFile($filepath) {
        if (!file_exists($filepath)) return '';
        $content = file_get_contents($filepath);
        return trim(preg_replace('/\s+/u', ' ', $content));
    }

    private function convertToImage($pdfPath, $page) {
        $cacheKey = $this->pdfHash . '::page::' . $page;
        if (isset($this->imageCache[$cacheKey]) && file_exists($this->imageCache[$cacheKey])) {
            return $this->imageCache[$cacheKey];
        }

        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

        $base = "page_{$cacheKey}";
        $pngPath = "{$tempDir}/{$base}.png";

        if (file_exists($pngPath)) {
            $this->tempFiles[] = $pngPath;
            return $this->imageCache[$cacheKey] = $pngPath;
        }

        $cmd = sprintf(
            'pdftoppm -f %d -l %d -png -singlefile -r 150 %s %s 2>/dev/null',
            intval($page), intval($page), escapeshellarg($pdfPath), escapeshellarg("{$tempDir}/{$base}")
        );

        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && file_exists($pngPath)) {
            $this->tempFiles[] = $pngPath;
            return $this->imageCache[$cacheKey] = $pngPath;
        }

        return null;
    }

    private function readPageBarcode($pdfPath, $page) {
        $cacheKey = $this->pdfHash . '::barcode::' . $page;
        if (isset($this->barcodeCache[$cacheKey])) {
            return $this->barcodeCache[$cacheKey];
        }

        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) return $this->barcodeCache[$cacheKey] = null;

            $barcode = $this->scanBarcode($imagePath);
            return $this->barcodeCache[$cacheKey] = $barcode;
        } catch (Exception $e) {
            return $this->barcodeCache[$cacheKey] = null;
        }
    }

    private function scanBarcode($imagePath) {
        $cmd = sprintf('zbarimg -q --raw %s 2>/dev/null', escapeshellarg($imagePath));
        exec($cmd, $output, $returnVar);
        if ($returnVar === 0 && !empty($output)) {
            $first = trim(is_array($output) ? $output[0] : $output);
            return $first === '' ? null : $first;
        }
        return null;
    }

    private function createOptimizedPdf($pdfPath, $pages, $outputPath) {
        try {
            $pagesList = implode(' ', $pages);
            $cmd = sprintf(
                'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dCompatibilityLevel=1.7 -dPDFSETTINGS=/screen -sOutputFile=%s %s -c "[%s] { } for (r) file run pdfpage" 2>&1',
                escapeshellarg($outputPath), escapeshellarg($pdfPath), $pagesList
            );
            exec($cmd, $output, $returnVar);
            return $returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    public function getPdfPageCount($pdfPath) {
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

    private function sanitizeFilename($filename) {
        $clean = preg_replace('/[^\p{Arabic}a-zA-Z0-9\-_\.\s]/u', '_', (string)$filename);
        $clean = preg_replace('/\s+/', '_', $clean);
        $clean = preg_replace('/[_\.]{2,}/', '_', $clean);
        $clean = trim($clean, '_-.');
        return $clean === '' ? 'document_' . time() : $clean;
    }

    private function looksLikeGarbled($content) {
        if (empty($content)) return true;
        preg_match_all('/[^\p{Arabic}\p{N}\s\p{P}]/u', $content, $matches);
        $garbledCount = count($matches[0] ?? []);
        $totalLength = mb_strlen($content);
        return $totalLength > 0 && ($garbledCount / $totalLength) > 0.3;
    }

    private function cleanupTemp() {
        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) return;
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) @unlink($file);
        }
        foreach (glob($tempDir . "/*") as $file) {
            if (filemtime($file) < time() - 3600) @unlink($file);
        }
        $this->tempFiles = [];
        $this->imageCache = [];
        $this->barcodeCache = [];
        $this->textCache = [];
        $this->ocrCache = [];
        gc_collect_cycles();
    }

    public function __destruct() {
        $this->cleanupTemp();
    }
}