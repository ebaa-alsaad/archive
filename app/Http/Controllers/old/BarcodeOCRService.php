<?php

namespace App\Http\Controllers\old;

use Exception;
use App\Models\Group;
use setasign\Fpdi\Fpdi;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BarcodeOCRService
{
    private $zbarPath = null;

    public function processPdf($upload)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        $pdfPath = Storage::disk('private')->path($upload->stored_filename);

        if (!file_exists($pdfPath)) {
            throw new Exception("PDF file not found: " . $pdfPath);
        }

        $pageCount = $this->getPageCount($pdfPath);
        Log::info("Processing large PDF with $pageCount pages");

        // الخطوة 1: العثور على الباركود في الصفحات
        $barcodePages = $this->findBarcodePages($pdfPath, $pageCount);

        if (empty($barcodePages)) {
            throw new Exception("No barcodes found in PDF");
        }

        // الخطوة 2: تجميع الصفحات المرتبطة (بدون صفحات الباركود)
        $barcodeToAllPages = $this->findPagesWithBarcodeText($pdfPath, $pageCount, $barcodePages);

        // تنظيف الملفات المؤقتة
        $this->cleanupTempFiles();

        return $this->createGroupedPdfFiles($barcodeToAllPages, $pdfPath, $upload);
    }

    /**
     * العثور على الصفحات التي تحتوي على باركود
     */
    private function findBarcodePages($pdfPath, $pageCount)
    {
        $pageToBarcode = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            Log::info("Processing page $page for barcode");

            try {
                $barcode = $this->readPageBarcode($pdfPath, $page);

                if ($barcode) {
                    Log::info("Page $page barcode detected: $barcode");
                    $pageToBarcode[$page] = $barcode;
                }
            } catch (Exception $e) {
                Log::error("Error processing page $page: " . $e->getMessage());
            }
        }

        return $pageToBarcode;
    }

    /**
     * البحث الشامل عن الصفحات المرتبطة (بدون صفحات الباركود)
     */
    private function findPagesWithBarcodeText($pdfPath, $pageCount, $barcodePages)
    {
        $barcodeToAllPages = [];

        foreach ($barcodePages as $barcodePage => $barcodeValue) {
            Log::info("Searching for barcode: " . $this->getBarcodeDisplay($barcodeValue));

            // معالجة الباركود للبحث
            $searchPatterns = $this->generateSearchPatterns($barcodeValue);

            // البحث الشامل في جميع الصفحات (بدون صفحات الباركود)
            $relatedPages = $this->comprehensiveSearch($pdfPath, $pageCount, $barcodePage, $searchPatterns, array_keys($barcodePages));

            $barcodeToAllPages[$barcodeValue] = $relatedPages;
            Log::info("Barcode '{$this->getBarcodeDisplay($barcodeValue)}' associated with pages: " . implode(', ', $relatedPages));
        }

        return $barcodeToAllPages;
    }

    /**
     * بحث شامل في جميع الصفحات (بدون صفحات الباركود)
     */
    private function comprehensiveSearch($pdfPath, $pageCount, $barcodePage, $searchPatterns, $allBarcodePages)
    {
        $relatedPages = []; // لا نضيف صفحة الباركود الأصلية
        $foundPages = [];

        Log::info("Searching all $pageCount pages for " . count($searchPatterns) . " patterns (excluding barcode pages)");

        // البحث في جميع الصفحات بأنماط فعالة
        for ($page = 1; $page <= $pageCount; $page++) {
            // تخطي جميع صفحات الباركود
            if (in_array($page, $allBarcodePages)) {
                continue;
            }

            $content = $this->extractPageText($pdfPath, $page);

            foreach ($searchPatterns as $pattern) {
                if (strlen($pattern) >= 4 && str_contains($content, $pattern)) {
                    $foundPages[] = $page;
                    Log::info("Pattern '$pattern' found in page $page");
                    break; // انتقل للصفحة التالية
                }
            }

            // تحديث التقدم كل 10 صفحات
            if ($page % 10 === 0) {
                Log::info("Search progress: $page/$pageCount pages, found " . count($foundPages) . " matches");
            }
        }

        $relatedPages = array_merge($relatedPages, $foundPages);
        $relatedPages = array_unique($relatedPages);
        sort($relatedPages);

        Log::info("Search completed: " . count($relatedPages) . " related pages found (excluding barcode page)");

        return $relatedPages;
    }

    /**
     * توليد أنماط بحث من الباركود
     */
    private function generateSearchPatterns($barcode)
    {
        $patterns = [];

        // النمط الأصلي
        $patterns[] = $barcode;

        // إذا كان الباركود طويلاً (Base64)
        if (strlen($barcode) > 50) {
            try {
                $decoded = base64_decode($barcode);
                if ($decoded) {
                    // البحث عن أرقام في المحتوى المفكوك
                    if (preg_match_all('/\d{4,}/', $decoded, $matches)) {
                        $patterns = array_merge($patterns, $matches[0]);
                    }
                }
            } catch (Exception $e) {
                Log::warning("Base64 decoding failed for barcode pattern generation");
            }

            // أنماط للباركودات الطويلة
            $patterns[] = substr($barcode, 0, 15);
            $patterns[] = substr($barcode, -15);
        }

        // إزالة القيم الفارغة والتكرار
        $patterns = array_filter($patterns);
        $patterns = array_unique($patterns);

        Log::info("Generated " . count($patterns) . " search patterns");

        return $patterns;
    }

    /**
     * استخراج النص من الصفحة
     */
    private function extractPageText($pdfPath, $page)
    {
        try {
            $tempDir = storage_path("app/temp");
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0775, true);
            }

            $tempTextFile = $tempDir . "/extract_page_{$page}_" . time() . ".txt";

            $cmd = "pdftotext -f {$page} -l {$page} -layout " . escapeshellarg($pdfPath) . " " . escapeshellarg($tempTextFile) . " 2>&1";
            shell_exec($cmd);

            if (file_exists($tempTextFile)) {
                $content = file_get_contents($tempTextFile);
                unlink($tempTextFile);
                return $content;
            }

            return "";
        } catch (Exception $e) {
            Log::error("Error extracting text from page $page: " . $e->getMessage());
            return "";
        }
    }

    /**
     * تحويل صفحة PDF إلى صورة
     */
    private function convertToImage($pdfPath, $page)
    {
        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $baseName = "page_{$page}_" . time();
        $pngPath = $tempDir . "/" . $baseName . ".png";

        $cmd = "pdftoppm -f $page -l $page -png -r 200 -singlefile " .
               escapeshellarg($pdfPath) . " " .
               escapeshellarg($tempDir . "/" . $baseName) . " 2>&1";

        Log::info("Running pdftoppm command: $cmd");
        $output = shell_exec($cmd);

        sleep(1);

        if (file_exists($pngPath)) {
            return $pngPath;
        } else {
            $files = glob($tempDir . "/" . $baseName . ".*");
            if (!empty($files)) {
                return $files[0];
            }
            return null;
        }
    }

    /**
     * قراءة الباركود من الصفحة
     */
    private function readPageBarcode($pdfPath, $page)
    {
        $image = $this->convertToImage($pdfPath, $page);

        if (!$image) {
            Log::error("Failed to convert page $page to image");
            return null;
        }

        $barcode = $this->scanBarcode($image);

        if (file_exists($image)) {
            unlink($image);
        }

        return $barcode;
    }

    /**
     * البحث عن مسار ZBar
     */
    private function findZBarPath()
    {
        if ($this->zbarPath) {
            return $this->zbarPath;
        }

        $possiblePaths = [
            '/usr/bin/zbarimg',
            '/usr/local/bin/zbarimg',
            '/bin/zbarimg',
            'zbarimg'
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                Log::info("Found ZBar at: $path");
                $this->zbarPath = $path;
                return $path;
            }
        }

        $output = shell_exec('which zbarimg 2>&1');
        if (!empty(trim($output))) {
            Log::info("ZBar found in PATH: $output");
            $this->zbarPath = 'zbarimg';
            return 'zbarimg';
        }

        Log::warning("ZBar not found in any known location");
        return null;
    }

    /**
     * مسح الباركود من الصورة
     */
    private function scanBarcode($imagePath)
    {
        $zbarPath = $this->findZBarPath();

        if (!$zbarPath) {
            Log::warning("ZBar not available, skipping barcode scan");
            return null;
        }

        $cmd = escapeshellarg($zbarPath) . " -q --raw " . escapeshellarg($imagePath) . " 2>&1";
        Log::info("Running ZBar command: $cmd");
        $output = shell_exec($cmd);

        if ($output && trim($output)) {
            $barcode = trim($output);
            Log::info("Barcode found: $barcode");
            return $barcode;
        }

        Log::warning("No barcode found in image");
        return null;
    }

    /**
     * الحصول على عدد صفحات PDF
     */
    public function getPageCount($pdfPath)
    {
        try {
            $pdf = new Fpdi();
            return $pdf->setSourceFile($pdfPath);
        } catch (Exception $e) {
            Log::error("Error getting page count: " . $e->getMessage());

            try {
                $cmd = "pdfinfo " . escapeshellarg($pdfPath) . " 2>&1";
                $output = shell_exec($cmd);

                if (preg_match('/Pages:\s*(\d+)/', $output, $matches)) {
                    return (int)$matches[1];
                }
            } catch (Exception $e2) {
                Log::error("Alternative page count also failed: " . $e2->getMessage());
            }

            throw $e;
        }
    }

    /**
     * إنشاء ملفات PDF مجمعة
     */
    private function createGroupedPdfFiles($barcodeToAllPages, $pdfPath, $upload)
    {
        $created = [];

        foreach ($barcodeToAllPages as $barcode => $pages) {
            if (empty($pages)) {
                Log::warning("No related pages found for barcode: " . $this->getBarcodeDisplay($barcode));
                continue;
            }

            $group = $this->createPdfGroup($pages, $barcode, $pdfPath, $upload);

            if ($group) {
                $created[] = $group;
                Log::info("Successfully created group for barcode: " . $this->getBarcodeDisplay($barcode) . " with " . count($pages) . " related pages");
            } else {
                Log::error("Failed to create group for barcode: " . $this->getBarcodeDisplay($barcode));
            }
        }

        return $created;
    }

    /**
     * إنشاء مجموعة PDF واحدة
     */
    private function createPdfGroup($pages, $barcode, $pdfPath, $upload)
    {
        try {
            $pdf = new Fpdi();
            $pdf->setSourceFile($pdfPath);

            foreach ($pages as $page) {
                $id = $pdf->importPage($page);
                $pdf->AddPage();
                $pdf->useTemplate($id);
            }

            // إنشاء اسم ملف آمن
            $safeBarcode = $this->createSafeFilename($barcode);
            $filename = "{$safeBarcode}.pdf";
            $directory = "groups";
            $fullPath = storage_path("app/{$directory}");

            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0775, true);
            }

            $path = "{$fullPath}/{$filename}";
            $pdf->Output($path, 'F');

            $dbPath = "{$directory}/{$filename}";

            return Group::create([
                'code' => $barcode,
                'pdf_path' => $dbPath,
                'pages_count' => count($pages),
                'user_id' => $upload->user_id,
                'upload_id' => $upload->id
            ]);
        } catch (Exception $e) {
            Log::error("Error creating PDF group for barcode " . $this->getBarcodeDisplay($barcode) . ": " . $e->getMessage());
            return null;
        }
    }

    /**
     * إنشاء اسم ملف من الباركود
     */
    private function createSafeFilename($barcode)
    {
        // إذا كان الباركود طويلاً، نستخدم hash
        if (strlen($barcode) > 50) {
            return 'barcode_' . substr(md5($barcode), 0, 12);
        }

        // إزالة الأحرف غير الآمنة
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $barcode);
        $safeName = substr($safeName, 0, 50);

        return $safeName ?: 'unknown_barcode';
    }

    /**
     * تنظيف الملفات المؤقتة
     */
    public function cleanupTempFiles()
    {
        $tempDir = storage_path("app/temp");
        if (file_exists($tempDir)) {
            $files = glob($tempDir . "/*");
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            Log::info("Cleaned up temp files: " . count($files) . " files removed");
        }
    }

    /**
     * الحصول على عرض مختصر للباركود
     */
    private function getBarcodeDisplay($barcode)
    {
        if (strlen($barcode) > 30) {
            return substr($barcode, 0, 20) . '...' . substr($barcode, -10);
        }
        return $barcode;
    }
}
