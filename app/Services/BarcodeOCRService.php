<?php

namespace App\Services;

use Exception;
use App\Models\Group;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BarcodeOCRService
{
    private $imageCache = [];    // cacheKey => path
    private $barcodeCache = [];  // cacheKey => barcode|null
    private $textCache = [];     // cacheKey => text
    private $ocrCache = [];      // cacheKey => text
    private $pdfHash = null;     // hash of current PDF

    /**
     * المعالجة الرئيسية للملف
     */
    public function processPdf($upload)
    {
        set_time_limit(1800);
        ini_set('max_execution_time', 1800);
        ini_set('memory_limit', '2048M');

        $pdfPath = Storage::disk('private')->path($upload->stored_filename);
        if (!file_exists($pdfPath)) throw new Exception("PDF file not found: " . $pdfPath);

        $this->pdfHash = md5_file($pdfPath);
        $pageCount = $this->getPdfPageCount($pdfPath);

        Log::info("Processing PDF", ['path' => $pdfPath, 'pages' => $pageCount]);

        // قراءة barcode الفاصل من الصفحة الأولى
        $separatorBarcode = $this->readPageBarcode($pdfPath, 1) ?? 'default_barcode';
        Log::info("Using separator barcode", ['separator' => $separatorBarcode]);

        // تقسيم الصفحات حسب barcode
        $sections = [];
        $currentSection = [];
        for ($page = 1; $page <= $pageCount; $page++) {
            $barcode = $this->readPageBarcode($pdfPath, $page);
            if ($barcode === $separatorBarcode) {
                if (!empty($currentSection)) $sections[] = $currentSection;
                $currentSection = [];
            } else {
                $currentSection[] = $page;
            }
        }
        if (!empty($currentSection)) $sections[] = $currentSection;

        $createdGroups = [];
        foreach ($sections as $index => $pages) {
            if (empty($pages)) continue;

            $filename = $this->generateFilenameWithOCR($pdfPath, $pages, $index, $separatorBarcode);
            $filenameSafe = $filename . '.pdf';

            $directory = "groups";
            $fullDir = storage_path("app/private/{$directory}");
            if (!file_exists($fullDir)) mkdir($fullDir, 0775, true);

            $outputPath = "{$fullDir}/{$filenameSafe}";
            $dbPath = "{$directory}/{$filenameSafe}";

            if ($this->createQuickPdf($pdfPath, $pages, $outputPath)) {
                $group = Group::create([
                    'code' => $separatorBarcode,
                    'pdf_path' => $dbPath,
                    'pages_count' => count($pages),
                    'user_id' => $upload->user_id,
                    'upload_id' => $upload->id
                ]);
                $createdGroups[] = $group;
            }
        }

        // تنظيف الملفات المؤقتة
        $this->cleanupTemp();

        Log::info("Processing completed", [
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
        $content = $this->extractWithPdftotext($pdfPath, $firstPage);

        if (empty($content) || mb_strlen($content) < 40 || $this->looksLikeGarbled($content) || $this->tooManyNonArabic($content)) {
            $content = $this->extractTextWithOCR($pdfPath, $firstPage);
        }

        foreach (['قيد','فاتورة','سند'] as $type) {
            $patterns = $this->getPatterns($type);
            $number = $this->findDocumentNumber($content, $type, $patterns);
            if ($number) return $this->sanitizeFilename($number);
        }

        $date = $this->findDate($content);
        if ($date) return $this->sanitizeFilename($date);

        return $this->sanitizeFilename($barcode . '_' . ($index + 1));
    }

    private function getPatterns($type) {
        return match($type) {
            'قيد' => ['رقم\s*القيد\s*[:\-]?\s*(\d+)', 'القيد\s*[:\-]?\s*(\d+)','قيد\s*[:\-]?\s*(\d+)'],
            'فاتورة' => ['رقم\s*الفاتورة\s*[:\-]?\s*(\d+)', 'الفاتورة\s*[:\-]?\s*(\d+)', 'فاتورة\s*[:\-]?\s*(\d+)'],
            'سند' => ['رقم\s*السند\s*[:\-]?\s*(\d+)', 'السند\s*[:\-]?\s*(\d+)', 'سند\s*[:\-]?\s*(\d+)'],
            default => []
        };
    }

    /**
     * pdftotext مع كاش على القرص والذاكرة
     */
    private function extractWithPdftotext($pdfPath, $page)
    {
        $cacheKey = $this->pdfHash . '::pdftotext::' . $page;
        if (isset($this->textCache[$cacheKey])) return $this->textCache[$cacheKey];

        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

        $tempFile = "{$tempDir}/pdftxt_{$cacheKey}.txt";

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

    /**
     * OCR باستخدام tesseract مع كاش
     */
    private function extractTextWithOCR($pdfPath, $page)
    {
        $cacheKey = $this->pdfHash . '::ocr::' . $page;
        if (isset($this->ocrCache[$cacheKey])) return $this->ocrCache[$cacheKey];

        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) return $this->ocrCache[$cacheKey] = '';

            $tempDir = storage_path("app/temp");
            if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

            $outputFileBase = "{$tempDir}/ocr_{$cacheKey}";

            $cmd = sprintf(
                'tesseract %s %s -l ara --psm 6 2>&1',
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

    /**
     * تحويل صفحة PDF إلى صورة PNG (مع كاش)
     */
    private function convertToImage($pdfPath, $page)
    {
        $cacheKey = $this->pdfHash . '::page::' . $page;
        if (isset($this->imageCache[$cacheKey]) && file_exists($this->imageCache[$cacheKey])) {
            return $this->imageCache[$cacheKey];
        }

        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

        $base = "page_{$cacheKey}";
        $pngPath = "{$tempDir}/{$base}.png";

        if (file_exists($pngPath)) return $this->imageCache[$cacheKey] = $pngPath;

        $cmd = sprintf(
            'pdftoppm -f %d -l %d -png -singlefile %s %s 2>&1',
            intval($page),
            intval($page),
            escapeshellarg($pdfPath),
            escapeshellarg("{$tempDir}/{$base}")
        );

        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && file_exists($pngPath)) return $this->imageCache[$cacheKey] = $pngPath;

        exec(str_replace('-singlefile', '', $cmd), $output2, $returnVar2);
        if ($returnVar2 === 0 && file_exists($pngPath)) return $this->imageCache[$cacheKey] = $pngPath;

        Log::warning("convertToImage failed", ['page' => $page, 'returnVar' => $returnVar]);
        return null;
    }

    /**
     * قراءة الباركود من صفحة PDF (مع كاش)
     */
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

            return $returnVar === 0 && file_exists($outputPath);
        } catch (Exception $e) {
            Log::warning("Quick PDF creation failed", ['error' => $e->getMessage()]);
            return false;
        }
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

        foreach (glob($tempDir . "/*") as $file) @unlink($file);
    }

    private function looksLikeGarbled($content)
    {
        if (empty($content)) return true;
        preg_match_all('/[^\p{Arabic}\p{N}\s\p{P}]/u', $content, $m);
        return isset($m[0]) && count($m[0]) > 20;
    }

    private function tooManyNonArabic($content)
    {
        if (empty($content)) return false;
        return preg_match('/[a-zA-Z]{20,}/', $content);
    }

    private function findDocumentNumber($content, $documentType, $patterns)
    {
        if (empty($content) || mb_strlen($content) < 2) return null;

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

    private function findDate($content)
    {
        if (empty($content) || mb_strlen($content) < 4) return null;

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
}
