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

        // === الإصلاح: استخدام private disk بدل local ===
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
     * البحث عن جميع الصفحات التي تحتوي على نص الباركود
     */
    private function findPagesWithBarcodeText($pdfPath, $pageCount, $barcodePages)
    {
        $barcodeToAllPages = [];

        foreach ($barcodePages as $barcodePage => $barcodeValue) {
            Log::info("Searching for pages containing barcode: $barcodeValue");

            $allPagesWithThisBarcode = [];

            for ($page = 1; $page <= $pageCount; $page++) {
                // === إزالة استبعاد صفحة الباركود الأصلية ===
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

            // === تأكد من إضافة صفحة الباركود الأصلية ===
            // if (!in_array($barcodePage, $allPagesWithThisBarcode)) {
            //     $allPagesWithThisBarcode[] = $barcodePage;
            //     Log::info("Added barcode source page: $barcodePage");
            // }

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
            // تحويل الصفحة إلى نص باستخدام pdftotext
            $tempDir = storage_path("app/temp");
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0775, true);
            }

            $tempTextFile = $tempDir . "/page_{$page}_text_" . time() . ".txt";

            $cmd = "pdftotext -f {$page} -l {$page} -layout \"" . $pdfPath . "\" \"" . $tempTextFile . "\"";
            Log::info("Running pdftotext command: $cmd");

            shell_exec($cmd);

            if (file_exists($tempTextFile)) {
                $content = file_get_contents($tempTextFile);
                $contains = str_contains($content, $searchText);

                // تنظيف الملف المؤقت
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

        // تنظيف الملف المؤقت
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

        $cmd = "pdftoppm -f $page -l $page -png -r 200 -singlefile " .
               escapeshellarg($pdfPath) . " " .
               escapeshellarg($tempDir . "/" . $baseName);

        Log::info("Running pdftoppm command: $cmd");
        $output = shell_exec($cmd . " 2>&1");
        Log::info("pdftoppm output: " . $output);

        sleep(2);

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

    private function findZBarPath()
    {
        if ($this->zbarPath) {
            return $this->zbarPath;
        }

        $possiblePaths = [
            'C:\Program Files\ZBar\bin\zbarimg.exe',
            'C:\Program Files (x86)\ZBar\bin\zbarimg.exe',
        ];

        $programFiles = glob("C:\Program Files*\ZBar*\bin\zbarimg.exe");
        $possiblePaths = array_merge($possiblePaths, $programFiles);

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                Log::info("Found ZBar at: $path");
                $this->zbarPath = $path;
                return $path;
            }
        }

        $output = shell_exec('zbarimg -h 2>&1');
        if (strpos($output, 'Usage:') !== false || strpos($output, 'options:') !== false) {
            Log::info("ZBar found in PATH");
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

        $cmd = "\"$zbarPath\" -q --raw " . escapeshellarg($imagePath);
        Log::info("Running ZBar command: $cmd");
        $output = shell_exec($cmd);

        if ($output) {
            $barcode = trim($output);
            Log::info("Barcode found: $barcode");
            return $barcode;
        }

        Log::warning("No barcode found in image");
        return null;
    }

    public function checkDependencies()
    {
        // التحقق من pdftoppm و pdftotext
        $dependencies = [
            'pdftoppm' => 'pdftoppm -v',
            'pdftotext' => 'pdftotext -v'
        ];

        foreach ($dependencies as $name => $cmd) {
            $output = shell_exec($cmd . " 2>&1");
            if (strpos($output, 'not recognized') !== false || !$output) {
                Log::error("$name is missing");
                return false;
            }
            Log::info("$name is working");
        }

        return true;
    }

    public function getPageCount($pdfPath)
    {
        try {
            $pdf = new Fpdi();
            return $pdf->setSourceFile($pdfPath);
        } catch (Exception $e) {
            Log::error("Error getting page count: " . $e->getMessage());
            throw $e;
        }
    }

    private function createGroupedPdfFiles($barcodeToAllPages, $pdfPath, $upload)
    {
        $created = [];

        foreach ($barcodeToAllPages as $barcode => $pages) {
            // تخطي إذا لم توجد صفحات
            if (empty($pages)) {
                continue;
            }

            $created[] = $this->createPdfGroup($pages, $barcode, $pdfPath, $upload);
        }

        return $created;
    }

    private function createPdfGroup($pages, $barcode, $pdfPath, $upload)
    {
        $pdf = new Fpdi();
        $pdf->setSourceFile($pdfPath);

        foreach ($pages as $page) {
            $id = $pdf->importPage($page);
            $pdf->AddPage();
            $pdf->useTemplate($id);
        }

        $filename = "{$barcode}.pdf";
        $directory = "groups";
        $full = storage_path("app/$directory");

        if (!file_exists($full)) {
            mkdir($full, 0775, true);
        }

        $path = "$full/$filename";
        $pdf->Output($path, 'F');

        return Group::create([
            'code' => $barcode,
            'pdf_path' => "$directory/$filename",
            'pages_count' => count($pages),
            'user_id' => $upload->user_id,
            'upload_id' => $upload->id
        ]);
    }
}
