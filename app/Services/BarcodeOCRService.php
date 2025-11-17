<?php

namespace App\Services;

use Exception;
use App\Models\Group;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BarcodeOCRService
{
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

        // قراءة كل الصفحات للباركود
        $barcodes = [];
        for ($page = 1; $page <= $pageCount; $page++) {
            $barcode = $this->readPageBarcode($pdfPath, $page);
            $barcodes[$page] = $barcode ?: null;
        }

        // استخدام الباركود الأول كفاصل
        $separatorBarcode = $barcodes[1] ?? 'default_barcode';
        Log::info("Using separator barcode: {$separatorBarcode}");

        // تقسيم الصفحات إلى أقسام حسب الباركود المتكرر - بدون تضمين صفحة الباركود
        $sections = [];
        $currentSection = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            if ($barcodes[$page] === $separatorBarcode) {
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

            // استخدام OCR لاستخراج النص وتسمية الملفات
            $filename = $this->generateFilenameWithOCR($pdfPath, $pages, $index, $separatorBarcode);
            $filenameSafe = $filename . '.pdf';

            $directory = "groups";
            $fullDir = storage_path("app/private/{$directory}");
            if (!file_exists($fullDir)) mkdir($fullDir, 0775, true);

            $outputPath = "{$fullDir}/{$filenameSafe}";
            $dbPath = "{$directory}/{$filenameSafe}";

            $pdfCreated = $this->createPdfWithPoppler($pdfPath, $pages, $outputPath);

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
     * إنشاء اسم ملف باستخدام OCR لاستخراج النص
     */
    private function generateFilenameWithOCR($pdfPath, $pages, $index, $barcode)
    {
        $firstPage = $pages[0];
        $content = $this->extractTextWithOCR($pdfPath, $firstPage);

        Log::info("OCR extracted content from page {$firstPage}: " . $content);

        // 1. البحث عن رقم القيد بأنماط مختلفة
        $qeedPatterns = [
            '/رقم\s*القيد\s*[:\-]?\s*(\d+)/u',
            '/القيد\s*[:\-]?\s*(\d+)/u',
            '/قيد\s*[:\-]?\s*(\d+)/u',
            '/رقم\s*[:\-]?\s*(\d+).*قيد/u',
            '/(\b77\d{2}\b)/u' // بحث عن 77xx بشكل خاص
        ];

        foreach ($qeedPatterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $filename = 'قيد_' . $matches[1];
                Log::info("Found رقم القيد via OCR: {$filename} - Pattern: {$pattern}");
                return $this->sanitizeFilename($filename);
            }
        }

        // 2. البحث عن التاريخ بأنماط مختلفة
        $datePatterns = [
            '/(\d{2}\/\d{2}\/\d{4})/u', // 03/10/2023
            '/(\d{2}-\d{2}-\d{4})/u',   // 03-10-2023
            '/(\d{1,2}\/\d{1,2}\/\d{4})/u', // 3/10/2023
            '/(\d{4}-\d{2}-\d{2})/u',   // 2023-10-03
            '/(\d{1,2}\s*\/\s*\d{1,2}\s*\/\s*\d{4})/u' // 03 / 10 / 2023
        ];

        foreach ($datePatterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $date = preg_replace('/\s+/', '', $matches[1]); // إزالة المسافات
                $cleanDate = str_replace('/', '-', $date);
                $filename = 'مستند_' . $cleanDate;
                Log::info("Found تاريخ via OCR: {$filename} - Pattern: {$pattern}");
                return $this->sanitizeFilename($filename);
            }
        }

        // 3. البحث عن أي أرقام مهمة
        if (preg_match('/(\b\d{4,5}\b)/u', $content, $matches)) {
            $number = $matches[1];
            // تجاهل الأرقام التي تبدو كسنوات (مثل 2020, 2023)
            if (!in_array($number, ['2020', '2021', '2022', '2023', '2024', '2025'])) {
                $filename = 'مستند_' . $number;
                Log::info("Found general number via OCR: {$filename}");
                return $this->sanitizeFilename($filename);
            }
        }

        // 4. استخدام أسماء وصفيّة بناءً على محتوى الصفحة
        $descriptiveName = $this->getDescriptiveNameFromContent($content, $index, $barcode);
        Log::info("Using descriptive name: {$descriptiveName}");
        return $this->sanitizeFilename($descriptiveName);
    }

    /**
     * إنشاء اسم وصفي بناءً على محتوى النص
     */
    private function getDescriptiveNameFromContent($content, $index, $barcode)
    {
        // البحث عن كلمات مفتاحية في المحتوى
        $keywords = [
            'كشف حساب' => 'كشف_حساب',
            'تقرير' => 'تقرير',
            'فاتورة' => 'فاتورة',
            'سند' => 'سند',
            'قيد' => 'قيد',
            'حركة' => 'حركة',
            'مصروفات' => 'مصروفات',
            'شركة' => 'شركة'
        ];

        foreach ($keywords as $arabic => $filenamePart) {
            if (strpos($content, $arabic) !== false) {
                return $filenamePart . '_' . $barcode . '_' . ($index + 1);
            }
        }

        // إذا لم يوجد شيء، استخدام التاريخ الحالي
        return 'مستند_' . date('Y-m-d') . '_' . ($index + 1);
    }

    /**
     * استخراج النص باستخدام OCR
     */
    private function extractTextWithOCR($pdfPath, $page)
    {
        try {
            $imagePath = $this->convertToHighQualityImage($pdfPath, $page);
            if (!$imagePath) return '';

            $tempDir = storage_path("app/temp");
            $outputFile = $tempDir . "/ocr_output_" . time();

            // تجربة إعدادات Tesseract مختلفة
            $tesseractConfigs = [
                "-l ara+eng --psm 6 -c tessedit_char_whitelist=0123456789/:-", // للتواريخ والأرقام
                "-l ara+eng --psm 4", // للكتلة الواحدة
                "-l ara+eng --psm 3", // تلقائي
                "-l ara+eng --psm 6"  // كتلة موحدة
            ];

            $bestContent = '';
            $bestLength = 0;

            foreach ($tesseractConfigs as $config) {
                $cmd = "tesseract " . escapeshellarg($imagePath) . " " .
                    escapeshellarg($outputFile) . " {$config} 2>&1";

                Log::info("Running OCR with config: {$config}");
                exec($cmd, $output, $returnVar);

                if (file_exists($outputFile . '.txt')) {
                    $content = file_get_contents($outputFile . '.txt');
                    $content = preg_replace('/\s+/', ' ', $content);
                    $content = trim($content);

                    // اختيار المحتوى الأفضل (الأطول)
                    if (strlen($content) > $bestLength) {
                        $bestContent = $content;
                        $bestLength = strlen($content);
                    }

                    @unlink($outputFile . '.txt');
                }
            }

            Log::info("Best OCR content for page {$page}, length: " . $bestLength);

            @unlink($imagePath);
            return $bestContent;

        } catch (Exception $e) {
            Log::error("OCR extraction failed: " . $e->getMessage());
            return '';
        }
    }

    /**
     * تحويل صفحة PDF إلى صورة عالية الجودة لـ OCR
     */
    private function convertToHighQualityImage($pdfPath, $page)
    {
        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

        $base = "page_{$page}_ocr_" . time();
        $pngPath = "{$tempDir}/{$base}.png";

        // زيادة الدقة وتحسين الإعدادات
        $cmd = "pdftoppm -f {$page} -l {$page} -png -r 300 -aa yes -aaVector yes -singlefile " .
            escapeshellarg($pdfPath) . " " .
            escapeshellarg("{$tempDir}/{$base}") . " 2>&1";

        exec($cmd, $output, $returnVar);

        if (file_exists($pngPath)) {
            Log::info("High quality image created for OCR: {$pngPath}");

            // تحسين الصورة باستخدام ImageMagick إذا كان متاحاً
            $this->enhanceImageForOCR($pngPath);

            return $pngPath;
        } else {
            Log::error("Failed to create high quality image for page {$page}");
            return null;
        }
    }

    /**
     * تحسين الصورة لتحسين دقة OCR
     */
    private function enhanceImageForOCR($imagePath)
    {
        try {
            $commands = [
                // زيادة التباين
                "convert {$imagePath} -contrast-stretch 0 -alpha remove {$imagePath}",
                // تحسين الحدة
                "convert {$imagePath} -sharpen 0x1.0 {$imagePath}",
                // تحويل إلى أبيض وأسود
                "convert {$imagePath} -colorspace Gray {$imagePath}"
            ];

            foreach ($commands as $cmd) {
                exec($cmd . " 2>&1", $output, $returnVar);
            }

            Log::info("Image enhanced for OCR: {$imagePath}");
        } catch (Exception $e) {
            Log::warning("Image enhancement failed: " . $e->getMessage());
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
     * إنشاء PDF باستخدام poppler-utils
     */
    private function createPdfWithPoppler($pdfPath, $pages, $outputPath)
    {
        try {
            $tempDir = storage_path("app/temp/poppler_" . time());
            if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

            $tempFiles = [];

            foreach ($pages as $page) {
                $tempFile = "{$tempDir}/page_{$page}.pdf";
                $cmd = "pdfseparate -f {$page} -l {$page} " . escapeshellarg($pdfPath) . " " . escapeshellarg($tempFile) . " 2>&1";
                exec($cmd, $output, $returnVar);

                if ($returnVar === 0 && file_exists($tempFile)) {
                    $tempFiles[] = $tempFile;
                }
            }

            if (!empty($tempFiles)) {
                $filesList = implode(' ', array_map('escapeshellarg', $tempFiles));
                $cmd = "pdfunite {$filesList} " . escapeshellarg($outputPath) . " 2>&1";
                exec($cmd, $output, $returnVar);
            }

            foreach ($tempFiles as $tempFile) {
                @unlink($tempFile);
            }
            @rmdir($tempDir);

            return $returnVar === 0 && file_exists($outputPath);
        } catch (Exception $e) {
            Log::error("PDF creation with poppler failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * قراءة الباركود من صفحة PDF
     */
    private function readPageBarcode($pdfPath, $page)
    {
        $imagePath = $this->convertToImage($pdfPath, $page);
        if (!$imagePath) return null;

        $barcode = $this->scanBarcode($imagePath);
        @unlink($imagePath);

        return $barcode;
    }

    /**
     * تحويل صفحة PDF إلى صورة PNG (للباركود)
     */
    private function convertToImage($pdfPath, $page)
    {
        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

        $base = "page_{$page}_" . time();
        $pngPath = "{$tempDir}/{$base}.png";

        $cmd = "pdftoppm -f {$page} -l {$page} -png -singlefile " .
               escapeshellarg($pdfPath) . " " .
               escapeshellarg("{$tempDir}/{$base}") . " 2>&1";
        exec($cmd);

        return file_exists($pngPath) ? $pngPath : null;
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
