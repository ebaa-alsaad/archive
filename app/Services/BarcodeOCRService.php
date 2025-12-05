<?php

namespace App\Services;

use App\Models\Group;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class BarcodeOCRService
{
    private $uploadId;
    private $pdfHash;
    private $imageCache = [];
    private $barcodeCache = [];
    private $ocrCache = [];
    private $textCache = [];

    public function processPdf($upload, $disk = 'private')
    {
        if (Redis::get("processing_{$upload->id}")) {
            Log::warning("Processing already in progress", ['upload_id' => $upload->id]);
            return [];
        }

        Redis::setex("processing_{$upload->id}", 7200, 'true');
        $this->uploadId = $upload->id;

        set_time_limit(3600);
        ini_set('memory_limit', '2048M');

        $pdfPath = Storage::disk($disk)->path($upload->stored_filename);
        if (!file_exists($pdfPath)) throw new Exception("PDF file not found: $pdfPath");

        $this->pdfHash = md5_file($pdfPath);

        // حذف أي مجموعات قديمة
        Group::where('upload_id', $upload->id)->delete();

        $pageCount = $this->getPdfPageCount($pdfPath);
        $separatorBarcode = $this->readPageBarcode($pdfPath, 1) ?? 'default_barcode';

        // تقسيم الصفحات إلى مجموعات
        $sections = $this->splitPagesByBarcode($pdfPath, $pageCount, $separatorBarcode);

        $createdGroups = [];
        $totalSections = count($sections);
            foreach ($sections as $index => $pages) {
                if (empty($pages)) continue;

                $this->updateProgress(
                    60 + (($index / $totalSections) * 35),
                    "جاري إنشاء المجموعة " . ($index + 1) . " من $totalSections..."
                );

            $filename = $this->generateFilenameWithOCR($pdfPath, $pages, $index, $separatorBarcode);
            $directory = "groups";
            $outputPath = storage_path("app/private/{$directory}/{$filename}.pdf");
            $dbPath = "{$directory}/{$filename}.pdf";

            $this->createPdf($pdfPath, $pages, $outputPath);

            $group = Group::create([
                'code' => $separatorBarcode,
                'pdf_path' => $dbPath,
                'pages_count' => count($pages),
                'user_id' => $upload->user_id,
                'upload_id' => $upload->id
            ]);

            $createdGroups[] = $group;
        }

        $this->updateProgress(100, 'تم الانتهاء من المعالجة');

        Redis::del("processing_{$upload->id}");

        return $createdGroups;
    }

    private function splitPagesByBarcode($pdfPath, $pageCount, $separatorBarcode)
    {
        $sections = [];
        $currentSection = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            $barcode = $this->readPageBarcode($pdfPath, $page);

            if ($barcode === $separatorBarcode && !empty($currentSection)) {
                $sections[] = $currentSection;
                $currentSection = [];
            } else {
                $currentSection[] = $page;
            }
        }

        if (!empty($currentSection)) {
            $sections[] = $currentSection;
        }

        return $sections;
    }

    private function createPdf($pdfPath, $pages, $outputPath)
    {
        $pageList = implode(' ', array_map(fn($p) => "-dPageList=$p", $pages));
        $outputDir = dirname($outputPath);
        if (!file_exists($outputDir)) mkdir($outputDir, 0775, true);

        $cmd = sprintf(
            'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dCompatibilityLevel=1.7 -dPDFSETTINGS=/prepress %s -sOutputFile=%s %s 2>&1',
            $pageList,
            escapeshellarg($outputPath),
            escapeshellarg($pdfPath)
        );

        exec($cmd, $output, $returnVar);
        return $returnVar === 0 && file_exists($outputPath);
    }

    private function readPageBarcode($pdfPath, $page)
    {
        $cacheKey = $this->pdfHash . '::barcode::' . $page;
        if (isset($this->barcodeCache[$cacheKey])) return $this->barcodeCache[$cacheKey];

        $imagePath = $this->convertToImage($pdfPath, $page);
        if (!$imagePath) return null;

        $cmd = sprintf('zbarimg -q --raw %s 2>&1', escapeshellarg($imagePath));
        exec($cmd, $output, $returnVar);

        $barcode = $returnVar === 0 && !empty($output) ? trim($output[0]) : null;
        return $this->barcodeCache[$cacheKey] = $barcode;
    }

    private function convertToImage($pdfPath, $page)
    {
        $cacheKey = $this->pdfHash . '::page::' . $page;
        if (isset($this->imageCache[$cacheKey])) return $this->imageCache[$cacheKey];

        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

        $pngPath = "{$tempDir}/page_{$cacheKey}.png";
        if (file_exists($pngPath)) return $this->imageCache[$cacheKey] = $pngPath;

        $cmd = sprintf(
            'pdftoppm -f %d -l %d -png -singlefile %s %s 2>&1',
            $page, $page, escapeshellarg($pdfPath), escapeshellarg("{$tempDir}/page_{$cacheKey}")
        );

        exec($cmd, $output, $returnVar);
        return $returnVar === 0 && file_exists($pngPath) ? $this->imageCache[$cacheKey] = $pngPath : null;
    }

    public function getPdfPageCount($pdfPath)
    {
        $cmd = 'pdfinfo ' . escapeshellarg($pdfPath) . ' 2>&1';
        exec($cmd, $output, $returnVar);
        if ($returnVar !== 0) throw new Exception("Unable to read PDF info");

        foreach ($output as $line) {
            if (preg_match('/Pages:\s*(\d+)/i', $line, $matches)) {
                return (int)$matches[1];
            }
        }
        throw new Exception("Page count not found");
    }

    private function generateFilenameWithOCR($pdfPath, $pages, $index, $barcode)
    {
        $firstPage = $pages[0];

        // نص من pdftotext
        $content = $this->extractWithPdftotext($pdfPath, $firstPage);

        // إذا النص ضعيف، استخدم OCR
        if (empty($content) || mb_strlen($content) < 40 || $this->looksLikeGarbled($content) || $this->tooManyNonArabic($content)) {
            $content = $this->extractTextWithOCR($pdfPath, $firstPage);
        }

        // 1. البحث عن رقم السند
        $sanedNumber = $this->findDocumentNumber($content, 'سند', [
            'رقم\s*السند\s*[:\-]?\s*(\d{2,})',
            'السند\s*[:\-]?\s*(\d{2,})',
            'سند\s*[:\-]?\s*(\d{2,})',
            'سند\s*رقم\s*[:\-]?\s*(\d{2,})',
            '(\d{3})\s*سند',
            'سند\s*(\d{3})'
        ]);
        if ($sanedNumber) return $this->sanitizeFilename($sanedNumber);

        // 2. البحث عن رقم القيد
        $qeedNumber = $this->findDocumentNumber($content, 'قيد', [
            'رقم\s*القيد\s*[:\-]?\s*(\d+)',
            'القيد\s*[:\-]?\s*(\d+)',
            'قيد\s*[:\-]?\s*(\d+)',
        ]);
        if ($qeedNumber) return $this->sanitizeFilename($qeedNumber);

        // 3. البحث عن التاريخ
        $date = $this->findDate($content);
        if ($date) return $this->sanitizeFilename($date);

        // 4. fallback: barcode + رقم القسم
        return $this->sanitizeFilename($barcode . '_' . ($index + 1));
    }

    private function extractWithPdftotext($pdfPath, $page)
    {
        $cacheKey = $this->pdfHash . '::pdftotext::' . $page;
        if (isset($this->textCache[$cacheKey])) return $this->textCache[$cacheKey];

        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

        $tempFile = "{$tempDir}/pdftxt_{$cacheKey}.txt";
        if (file_exists($tempFile)) return $this->textCache[$cacheKey] = trim(file_get_contents($tempFile));

        $cmd = sprintf('pdftotext -f %d -l %d -layout %s %s 2>&1',
            intval($page),
            intval($page),
            escapeshellarg($pdfPath),
            escapeshellarg($tempFile)
        );
        exec($cmd, $output, $returnVar);

        $content = file_exists($tempFile) ? trim(file_get_contents($tempFile)) : '';
        return $this->textCache[$cacheKey] = $content;
    }

    private function extractTextWithOCR($pdfPath, $page)
    {
        $cacheKey = $this->pdfHash . '::ocr::' . $page;
        if (isset($this->ocrCache[$cacheKey])) return $this->ocrCache[$cacheKey];

        $imagePath = $this->convertToImage($pdfPath, $page);
        if (!$imagePath) return $this->ocrCache[$cacheKey] = '';

        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

        $outputFileBase = "{$tempDir}/ocr_{$cacheKey}";
        $cmd = sprintf('tesseract %s %s -l ara --psm 6 2>&1', escapeshellarg($imagePath), escapeshellarg($outputFileBase));
        exec($cmd, $output, $returnVar);

        $textFile = $outputFileBase . '.txt';
        $content = file_exists($textFile) ? trim(file_get_contents($textFile)) : '';
        @unlink($textFile);

        return $this->ocrCache[$cacheKey] = $content;
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
        foreach ($patterns as $pattern) {
            if (preg_match('/' . $pattern . '/ui', $content, $matches)) {
                return $matches[1] ?? null;
            }
        }
        return null;
    }

    private function findDate($content)
    {
        $patterns = [
            '/(\d{2}\/\d{2}\/\d{4})/u',
            '/(\d{2}-\d{2}-\d{4})/u',
            '/(\d{4}-\d{2}-\d{2})/u'
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                return str_replace('/', '-', $matches[1]);
            }
        }
        return null;
    }

    private function sanitizeFilename($filename)
    {
        $clean = preg_replace('/[^\p{Arabic}a-zA-Z0-9\-_\.]/u', '_', (string)$filename);
        $clean = preg_replace('/[_\.]{2,}/', '_', $clean);
        $clean = preg_replace('/^[^0-9\p{Arabic}a-zA-Z]+|[^0-9\p{Arabic}a-zA-Z]+$/u', '', $clean);
        return $clean === '' ? 'file_' . time() : $clean;
    }

    private function updateProgress($progress, $message = '')
{
    if ($this->uploadId) {
        try {
            Redis::setex("upload_progress:{$this->uploadId}", 3600, $progress);
            Redis::setex("upload_message:{$this->uploadId}", 3600, $message);
            Log::debug("Progress updated", [
                'upload_id' => $this->uploadId,
                'progress' => $progress,
                'message' => $message
            ]);
        } catch (Exception $e) {
            Log::warning("Failed to update progress", [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage()
            ]);
        }
    }
}

}
