<?php
namespace App\Services;

use App\Models\Group;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Redis;

class BarcodeOCRService
{
    private $uploadId;
    private $pdfHash;
    private $imageCache = [];
    private $barcodeCache = [];
    private $ocrCache = [];
    private $textCache = [];

    public function processPdf(\App\Models\Upload $upload, $disk = 'private')
    {
        if (Redis::get("processing_{$upload->id}")) {
            Log::warning("Processing already in progress", ['upload_id'=>$upload->id]);
            return [];
        }
        Redis::setex("processing_{$upload->id}", 7200, '1');

        $this->uploadId = $upload->id;
        set_time_limit(0);
        ini_set('memory_limit','2048M');

        $pdfPath = Storage::disk($disk)->path($upload->stored_filename);
        if (!file_exists($pdfPath)) {
            throw new Exception("PDF not found: $pdfPath");
        }

        // compute hash
        $this->pdfHash = md5_file($pdfPath);

        // cleanup old groups
        Group::where('upload_id', $upload->id)->delete();

        // page count
        $pageCount = $this->getPdfPageCount($pdfPath);
        $upload->update(['total_pages'=>$pageCount,'status'=>'processing']);

        // separator barcode from page 1
        $separator = $this->readPageBarcode($pdfPath, 1) ?? 'DEFAULT_SEPARATOR';

        // split
        $sections = $this->splitPagesByBarcode($pdfPath, $pageCount, $separator);

        $created = [];
        $total = count($sections);
        foreach ($sections as $i => $pages) {
            if (empty($pages)) continue;

            $this->updateProgress(60 + intval(($i/$total)*35), "إنشاء المجموعة " . ($i+1) . " من $total");

            $filenameBase = $this->generateFilenameWithOCR($pdfPath, $pages, $i, $separator);
            $safe = $this->sanitizeFilename($filenameBase);
            $dir = "groups";
            $fullDir = storage_path("app/private/{$dir}");
            if (!file_exists($fullDir)) mkdir($fullDir, 0775, true);

            $outputPath = "{$fullDir}/{$safe}.pdf";
            $dbPath = "{$dir}/{$safe}.pdf";

            // create PDF for these pages
            $ok = $this->createPdf($pdfPath, $pages, $outputPath);
            if ($ok && filesize($outputPath) > 1024) {
                $group = Group::create([
                    'upload_id' => $upload->id,
                    'user_id' => $upload->user_id,
                    'code' => $separator,
                    'pdf_path' => $dbPath,
                    'pages_count' => count($pages)
                ]);
                $created[] = $group;
            } else {
                if (file_exists($outputPath)) @unlink($outputPath);
                Log::warning("Failed to create pdf for group", ['upload'=>$upload->id,'pages'=>$pages]);
            }
        }

        $this->updateProgress(100, 'انتهت المعالجة');
        $upload->update(['status'=>'completed']);
        Redis::del("processing_{$upload->id}");
        return $created;
    }

    private function splitPagesByBarcode($pdfPath, $pageCount, $separator)
    {
        $sections = [];
        $current = [];
        for ($p=1;$p<=$pageCount;$p++) {
            $barcode = $this->readPageBarcode($pdfPath, $p);
            if ($barcode === $separator) {
                if (!empty($current)) {
                    $sections[] = $current;
                }
                $current = [];
            } else {
                $current[] = $p;
            }
        }
        if (!empty($current)) $sections[] = $current;
        return $sections;
    }

    private function createPdf($pdfPath, $pages, $outputPath)
    {
        $pageList = implode(' ', array_map(fn($p) => "-dPageList=$p", $pages));
        $outputDir = dirname($outputPath);
        if (!file_exists($outputDir)) mkdir($outputDir, 0775, true);

        $cmd = sprintf(
            'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dCompatibilityLevel=1.7 -dPDFSETTINGS=/prepress %s -sOutputFile=%s %s 2>&1',
            $pageList, escapeshellarg($outputPath), escapeshellarg($pdfPath)
        );
        exec($cmd, $out, $ret);
        if ($ret !== 0) {
            // try pdftk fallback
            if ($this->tryPdftk($pdfPath, $pages, $outputPath)) {
                return true;
            }
            Log::error("gs failed", ['ret'=>$ret,'out'=>$out]);
            return false;
        }
        return file_exists($outputPath) && filesize($outputPath) > 1000;
    }

    private function tryPdftk($pdfPath, $pages, $outputPath)
    {
        $pagesStr = implode(' ', $pages);
        $cmd = sprintf('pdftk %s cat %s output %s 2>&1', escapeshellarg($pdfPath), $pagesStr, escapeshellarg($outputPath));
        exec($cmd, $o, $r);
        return $r === 0 && file_exists($outputPath);
    }

    private function convertToImage($pdfPath, $page)
    {
        $cacheKey = "{$this->pdfHash}::page::{$page}";
        if (isset($this->imageCache[$cacheKey]) && file_exists($this->imageCache[$cacheKey])) return $this->imageCache[$cacheKey];

        $tmp = storage_path('app/temp');
        if (!file_exists($tmp)) mkdir($tmp, 0775, true);
        $base = "{$tmp}/page_{$this->pdfHash}_{$page}";
        $png = "{$base}.png";

        if (file_exists($png)) return $this->imageCache[$cacheKey] = $png;

        $cmd = sprintf('pdftoppm -f %d -l %d -png -singlefile %s %s 2>&1', $page, $page, escapeshellarg($pdfPath), escapeshellarg($base));
        exec($cmd, $out, $ret);
        if ($ret === 0 && file_exists($png)) return $this->imageCache[$cacheKey] = $png;
        return null;
    }

    private function scanBarcode($image)
    {
        $cmd = sprintf('zbarimg -q --raw %s 2>&1', escapeshellarg($image));
        exec($cmd, $out, $ret);
        if ($ret === 0 && !empty($out)) {
            return trim(is_array($out)?$out[0]:$out);
        }
        return null;
    }

    private function readPageBarcode($pdfPath, $page)
    {
        $cacheKey = "{$this->pdfHash}::barcode::{$page}";
        if (isset($this->barcodeCache[$cacheKey])) return $this->barcodeCache[$cacheKey];

        $img = $this->convertToImage($pdfPath, $page);
        if (!$img) return $this->barcodeCache[$cacheKey] = null;
        $b = $this->scanBarcode($img);
        return $this->barcodeCache[$cacheKey] = $b;
    }

    private function extractWithPdftotext($pdfPath, $page)
    {
        $cacheKey = "{$this->pdfHash}::pdftotext::{$page}";
        if (isset($this->textCache[$cacheKey])) return $this->textCache[$cacheKey];

        $tmp = storage_path('app/temp');
        if (!file_exists($tmp)) mkdir($tmp, 0775, true);
        $txt = "{$tmp}/pdftxt_{$this->pdfHash}_{$page}.txt";
        $cmd = sprintf('pdftotext -f %d -l %d -layout %s %s 2>&1', $page, $page, escapeshellarg($pdfPath), escapeshellarg($txt));
        exec($cmd, $out, $ret);
        $content = file_exists($txt) ? trim(preg_replace('/\s+/u',' ',file_get_contents($txt))) : '';
        return $this->textCache[$cacheKey] = $content;
    }

    private function extractTextWithOCR($pdfPath, $page)
    {
        $cacheKey = "{$this->pdfHash}::ocr::{$page}";
        if (isset($this->ocrCache[$cacheKey])) return $this->ocrCache[$cacheKey];

        $img = $this->convertToImage($pdfPath, $page);
        if (!$img) return $this->ocrCache[$cacheKey] = '';

        $tmp = storage_path('app/temp');
        $base = "{$tmp}/ocr_{$this->pdfHash}_{$page}";
        $cmd = sprintf('tesseract %s %s -l ara --psm 6 2>&1', escapeshellarg($img), escapeshellarg($base));
        exec($cmd, $out, $ret);
        $txtFile = $base . '.txt';
        $content = file_exists($txtFile) ? trim(preg_replace('/\s+/u',' ',file_get_contents($txtFile))) : '';
        @unlink($txtFile);
        return $this->ocrCache[$cacheKey] = $content;
    }

    private function generateFilenameWithOCR($pdfPath, $pages, $index, $barcode)
    {
        $first = $pages[0] ?? 1;
        $content = $this->extractWithPdftotext($pdfPath, $first);
        if (empty($content) || mb_strlen($content) < 40) {
            $content = $this->extractTextWithOCR($pdfPath, $first);
        }

        // patterns (سند, قيد, فاتورة)
        $patterns = [
            '/رقم\s*السند\s*[:\-]?\s*(\d{2,})/ui',
            '/السند\s*[:\-]?\s*(\d{2,})/ui',
            '/قيد\s*[:\-]?\s*(\d+)/ui',
            '/فاتورة\s*[:\-]?\s*(\d+)/ui',
            '/(\d{4}-\d{2}-\d{2})/ui',
            '/(\d{2}\/\d{2}\/\d{4})/ui'
        ];
        foreach ($patterns as $pat) {
            if (preg_match($pat, $content, $m)) {
                return $m[1];
            }
        }

        // fallback
        return $barcode . '_' . ($index + 1);
    }

    private function sanitizeFilename($f)
    {
        $clean = preg_replace('/[^\p{Arabic}a-zA-Z0-9\-_\.]/u','_', (string)$f);
        $clean = preg_replace('/[_\.]{2,}/','_', $clean);
        $clean = trim($clean, '_');
        return $clean ?: 'file_'.time();
    }

    private function getPdfPageCount($pdfPath)
    {
        $cmd = 'pdfinfo ' . escapeshellarg($pdfPath) . ' 2>&1';
        exec($cmd, $out, $ret);
        if ($ret !== 0) throw new Exception("pdfinfo failed");
        foreach ($out as $line) {
            if (preg_match('/Pages:\s*(\d+)/i',$line,$m)) return (int)$m[1];
        }
        throw new Exception("Unable to get pages");
    }

    private function updateProgress($progress, $message = '')
    {
        if ($this->uploadId) {
            try {
                Redis::setex("upload_progress:{$this->uploadId}", 3600, $progress);
                Redis::setex("upload_message:{$this->uploadId}", 3600, $message);
            } catch (\Exception $e){
                Log::warning('updateProgress failed: '.$e->getMessage());
            }
        }
    }
}
