<?php

namespace App\Services;

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
        set_time_limit(300);

        // === المسار الصحيح لـ Ubuntu ===
        $pdfPath = Storage::disk('private')->path($upload->stored_filename);

        if (!file_exists($pdfPath)) {
            throw new Exception("PDF file not found: " . $pdfPath);
        }

        $pageCount = $this->getPageCount($pdfPath);
        Log::info("PDF has $pageCount pages");

        // الخطوة 1: العثور على الباركود في الصفحات
        $barcodePages = $this->findBarcodePages($pdfPath, $pageCount);

        if (empty($barcodePages)) {
            throw new Exception("No barcodes found in PDF");
        }

        // الخطوة 2: لكل باركود، ابحث عن جميع الصفحات التي تحتوي على هذا الرقم
        $barcodeToAllPages = $this->findPagesWithBarcodeText($pdfPath, $pageCount, $barcodePages);

        return $this->createGroupedPdfFiles($barcodeToAllPages, $upload);
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
     * البحث عن جميع الصفحات التي تحتوي على نص الباركود
     */
    private function findPagesWithBarcodeText($pdfPath, $pageCount, $barcodePages)
    {
        $barcodeToAllPages = [];

        foreach ($barcodePages as $barcodePage => $barcodeValue) {
            Log::info("Searching for pages containing barcode: $barcodeValue");

            $allPagesWithThisBarcode = [];

            for ($page = 1; $page <= $pageCount; $page++) {
                // استبعاد صفحة الباركود الأصلية
                if ($page == $barcodePage) continue;

                try {
                    if ($this->pageContainsText($pdfPath, $page, $barcodeValue)) {
                        Log::info("Page $page contains barcode text: $barcodeValue");
                        $allPagesWithThisBarcode[] = $page;
                    }
                } catch (Exception $e) {
                    Log::error("Error searching page $page: " . $e->getMessage());
                }
            }

            // ترتيب الصفحات
            sort($allPagesWithThisBarcode);

            $barcodeToAllPages[$barcodeValue] = $allPagesWithThisBarcode;
            Log::info("Barcode $barcodeValue found in pages: " . implode(', ', $allPagesWithThisBarcode));
        }

        return $barcodeToAllPages;
    }

    /**
     * التحقق إذا كانت الصفحة تحتوي على النص المطلوب
     */
    private function pageContainsText($pdfPath, $page, $searchText)
    {
        try {
            $tempDir = storage_path("app/temp");
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0775, true);
            }

            $tempTextFile = $tempDir . "/page_{$page}_text_" . time() . ".txt";

            // === الأمر المعدل لـ Ubuntu ===
            $cmd = "pdftotext -f {$page} -l {$page} -layout " . escapeshellarg($pdfPath) . " " . escapeshellarg($tempTextFile) . " 2>&1";
            Log::info("Running pdftotext command: $cmd");

            $output = shell_exec($cmd);
            Log::info("pdftotext output: " . $output);

            if (file_exists($tempTextFile)) {
                $content = file_get_contents($tempTextFile);
                $contains = str_contains($content, $searchText);

                unlink($tempTextFile);
                return $contains;
            }

            return false;
        } catch (Exception $e) {
            Log::error("Error checking text in page $page: " . $e->getMessage());
            return false;
        }
    }

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

    private function convertToImage($pdfPath, $page)
    {
        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $baseName = "page_{$page}_" . time();
        $pngPath = $tempDir . "/" . $baseName . ".png";

        // === الأمر المعدل لـ Ubuntu ===
        $cmd = "pdftoppm -f $page -l $page -png -r 200 -singlefile " .
               escapeshellarg($pdfPath) . " " .
               escapeshellarg($tempDir . "/" . $baseName) . " 2>&1";

        Log::info("Running pdftoppm command: $cmd");
        $output = shell_exec($cmd);
        Log::info("pdftoppm output: " . $output);

        // وقت انتظار أقل على Linux
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
     * البحث عن مسار ZBar على Ubuntu
     */
    private function findZBarPath()
    {
        if ($this->zbarPath) {
            return $this->zbarPath;
        }

        // === المسارات على Ubuntu ===
        $possiblePaths = [
            '/usr/bin/zbarimg',
            '/usr/local/bin/zbarimg',
            '/bin/zbarimg',
            'zbarimg' // إذا كان في PATH
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                Log::info("Found ZBar at: $path");
                $this->zbarPath = $path;
                return $path;
            }
        }

        // التحقق من وجود zbarimg في PATH
        $output = shell_exec('which zbarimg 2>&1');
        if (!empty(trim($output)) {
            Log::info("ZBar found in PATH: $output");
            $this->zbarPath = 'zbarimg';
            return 'zbarimg';
        }

        Log::warning("ZBar not found in any known location");
        return null;
    }

    private function scanBarcode($imagePath)
    {
        $zbarPath = $this->findZBarPath();

        if (!$zbarPath) {
            Log::warning("ZBar not available, skipping barcode scan");
            return null;
        }

        // === الأمر المعدل لـ Ubuntu ===
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
     * التحقق من التبعيات على Ubuntu
     */
    public function checkDependencies()
    {
        $dependencies = [
            'pdftoppm' => 'pdftoppm -v',
            'pdftotext' => 'pdftotext -v',
            'zbarimg' => 'zbarimg --version'
        ];

        $allGood = true;

        foreach ($dependencies as $name => $cmd) {
            $output = shell_exec($cmd . " 2>&1");

            $isMissing = strpos($output, 'not found') !== false ||
                        strpos($output, 'command not found') !== false ||
                        empty($output);

            if ($isMissing) {
                Log::error("$name is missing on Ubuntu");
                $allGood = false;
            } else {
                Log::info("$name is working on Ubuntu: " . substr($output, 0, 50));
            }
        }

        return $allGood;
    }

    public function getPageCount($pdfPath)
    {
        try {
            $pdf = new Fpdi();
            return $pdf->setSourceFile($pdfPath);
        } catch (Exception $e) {
            Log::error("Error getting page count: " . $e->getMessage());

            // محاولة بديلة باستخدام pdfinfo على Ubuntu
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

    private function createGroupedPdfFiles($barcodeToAllPages, $upload)
    {
        $created = [];

        foreach ($barcodeToAllPages as $barcode => $pages) {
            if (empty($pages)) {
                continue;
            }

            $created[] = $this->createPdfGroup($pages, $barcode, $upload);
        }

        return $created;
    }

    private function createPdfGroup($pages, $barcode, $upload)
    {
        // استخدام الملف الأصلي من private storage
        $pdfPath = Storage::disk('private')->path($upload->stored_filename);

        $pdf = new Fpdi();
        $pdf->setSourceFile($pdfPath);

        foreach ($pages as $page) {
            $id = $pdf->importPage($page);
            $pdf->AddPage();
            $pdf->useTemplate($id);
        }

        $filename = "{$barcode}.pdf";
        $directory = "groups";
        $full = storage_path("app/{$directory}");

        if (!file_exists($full)) {
            mkdir($full, 0775, true);
        }

        $path = "{$full}/{$filename}";
        $pdf->Output($path, 'F');

        // المسار الذي سيتم حفظه في قاعدة البيانات
        $dbPath = "{$directory}/{$filename}";

        Log::info("PDF group created for barcode: $barcode with " . count($pages) . " pages");

        return Group::create([
            'code' => $barcode,
            'pdf_path' => $dbPath,
            'pages_count' => count($pages),
            'user_id' => $upload->user_id,
            'upload_id' => $upload->id
        ]);
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
}
