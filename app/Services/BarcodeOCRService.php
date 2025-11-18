<?php

namespace App\Services;

use Exception;
use App\Models\Group;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BarcodeOCRService
{
    private $imageCache = [];
    private $barcodeCache = [];
    private $textCache = [];
    private $ocrCache = [];

    /**
     * المعالجة الرئيسية للملف
     */
    public function processPdf($upload)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M'); // رفعت شوي للسلامة مع ملفات كبيرة

        $pdfPath = Storage::disk('private')->path($upload->stored_filename);

        if (!file_exists($pdfPath)) {
            throw new Exception("PDF file not found: " . $pdfPath);
        }

        $pageCount = $this->getPdfPageCount($pdfPath);
        Log::info("Processing PDF with {$pageCount} pages");

        // قراءة الباركود من الصفحة الأولى (separator) — استخدم الكاش داخل الدالة
        $separatorBarcode = $this->readPageBarcode($pdfPath, 1) ?? 'default_barcode';
        Log::info("Using separator barcode: {$separatorBarcode}");

        // تقسيم الصفحات إلى أقسام حسب الباركود المتكرر
        $sections = [];
        $currentSection = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            $barcode = $this->readPageBarcode($pdfPath, $page);
            if ($barcode === $separatorBarcode) {
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

            // استخدام نفس الآلية للتسمية لجميع الملفات
            $filename = $this->generateFilenameWithOCR($pdfPath, $pages, $index, $separatorBarcode);
            $filenameSafe = $filename . '.pdf';

            $directory = "groups";
            $fullDir = storage_path("app/private/{$directory}");
            if (!file_exists($fullDir)) mkdir($fullDir, 0775, true);

            $outputPath = "{$fullDir}/{$filenameSafe}";
            $dbPath = "{$directory}/{$filenameSafe}";

            // استخدام طريقة سريعة لإنشاء PDF (ghostscript مع ضبط)
            $pdfCreated = $this->createQuickPdf($pdfPath, $pages, $outputPath);

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
     * إنشاء اسم ملف باستخدام OCR لاستخراج النص - نفس الآلية للجميع
     */
    private function generateFilenameWithOCR($pdfPath, $pages, $index, $barcode)
    {
        $firstPage = $pages[0];

        // استخدام pdftotext أولاً لأنه أسرع (مع كاش داخلي)
        $content = $this->extractWithPdftotext($pdfPath, $firstPage);

        // قرار ذكي عن تشغيل OCR: ليس فقط طول النص بل بعض علامات التحريف
        if (
            empty($content) ||
            mb_strlen($content) < 40 ||
            preg_match('/[a-zA-Z]{20,}/', $content) || // سلسلة إنجليزية طويلة مش منطقية بالعربية
            $this->looksLikeGarbled($content)
        ) {
            // النص ضعيف أو محرف → استخدم OCR فقط عند الحاجة
            $content = $this->extractTextWithOCR($pdfPath, $firstPage);
        }

        Log::debug("Extracted content snippet (page {$firstPage}): " . mb_substr($content, 0, 200));

        // 1. الأولوية: البحث عن رقم القيد
        $qeedNumber = $this->findDocumentNumber($content, 'قيد', [
            'رقم\s*القيد\s*[:\-]?\s*(\d+)',
            'القيد\s*[:\-]?\s*(\d+)',
            'قيد\s*[:\-]?\s*(\d+)',
            'رقم\s*[:\-]?\s*(\d+).*قيد'
        ]);

        if ($qeedNumber) {
            Log::info("Found رقم القيد: {$qeedNumber}");
            return $this->sanitizeFilename($qeedNumber);
        }

        // 2. الثانية: البحث عن رقم الفاتورة
        $invoiceNumber = $this->findDocumentNumber($content, 'فاتورة', [
            'رقم\s*الفاتورة\s*[:\-]?\s*(\d+)',
            'الفاتورة\s*[:\-]?\s*(\d+)',
            'فاتورة\s*[:\-]?\s*(\d+)'
        ]);

        if ($invoiceNumber) {
            Log::info("Found رقم الفاتورة: {$invoiceNumber}");
            return $this->sanitizeFilename($invoiceNumber);
        }

        // 3. الثالثة: البحث عن رقم السند
        $sanedNumber = $this->findDocumentNumber($content, 'سند', [
            'رقم\s*السند\s*[:\-]?\s*(\d+)',
            'السند\s*[:\-]?\s*(\d+)',
            'سند\s*[:\-]?\s*(\d+)'
        ]);

        if ($sanedNumber) {
            Log::info("Found رقم السند: {$sanedNumber}");
            return $this->sanitizeFilename($sanedNumber);
        }

        // 4. الرابعة: البحث عن تاريخ
        $date = $this->findDate($content);
        if ($date) {
            Log::info("Found تاريخ: {$date}");
            return $this->sanitizeFilename($date);
        }

        // 5. الخيار الأخير: اسم وصفي مع الباركود
        $filename = $barcode . '_' . ($index + 1);
        Log::info("Using fallback filename: {$filename}");
        return $this->sanitizeFilename($filename);
    }

    /**
     * استخراج النص باستخدام pdftotext (أسرع) مع كاش على القرص والذاكرة
     */
    private function extractWithPdftotext($pdfPath, $page)
    {
        // كاش بالذاكرة
        $cacheKey = md5($pdfPath . '::' . $page);
        if (isset($this->textCache[$cacheKey])) {
            return $this->textCache[$cacheKey];
        }

        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

        $tempFile = $tempDir . "/pdftxt_" . $cacheKey . ".txt";

        // لو ملف النص موجود مسبقاً على القرص — استرجاع سريع
        if (file_exists($tempFile)) {
            $content = trim(preg_replace('/\s+/u', ' ', file_get_contents($tempFile)));
            return $this->textCache[$cacheKey] = $content;
        }

        $cmd = "pdftotext -f {$page} -l {$page} -layout " .
            escapeshellarg($pdfPath) . " " . escapeshellarg($tempFile) . " 2>&1";

        exec($cmd);

        $content = '';
        if (file_exists($tempFile)) {
            $content = trim(preg_replace('/\s+/u', ' ', file_get_contents($tempFile)));
        }

        // خزّنه في الذاكرة
        return $this->textCache[$cacheKey] = $content;
    }

    /**
     * استخراج النص باستخدام OCR (للحالات الصعبة فقط) + كاش
     */
    private function extractTextWithOCR($pdfPath, $page)
    {
        $cacheKey = md5($pdfPath . '::ocr::' . $page);
        if (isset($this->ocrCache[$cacheKey])) {
            return $this->ocrCache[$cacheKey];
        }

        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) return $this->ocrCache[$cacheKey] = '';

            $tempDir = storage_path("app/temp");
            if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

            $outputFileBase = $tempDir . "/ocr_" . $cacheKey;

            // استخدام إعداد سريع واحد فقط (psm 6 مناسب للصفحات)
            $cmd = "tesseract " . escapeshellarg($imagePath) . " " .
                   escapeshellarg($outputFileBase) . " -l ara --psm 6 2>&1";

            exec($cmd, $output, $returnVar);

            $content = '';
            if (file_exists($outputFileBase . '.txt')) {
                $content = trim(preg_replace('/\s+/u', ' ', file_get_contents($outputFileBase . '.txt')));
                @unlink($outputFileBase . '.txt');
            }

            // لا نحذف الصورة هنا لأننا نستفيد من كاش الصور (imageCache)
            return $this->ocrCache[$cacheKey] = $content;

        } catch (Exception $e) {
            Log::error("OCR extraction failed: " . $e->getMessage());
            return $this->ocrCache[$cacheKey] = '';
        }
    }

    /**
     * نظرة سريعة إن النص يشبه "تلف" أو محرف
     */
    private function looksLikeGarbled($content)
    {
        if (empty($content)) return true;

        // عدّ الحروف غير العربية/أرقام/مسافة - إن كانت كثيرة فغالباً محرف
        if (preg_match_all('/[^\p{Arabic}\p{N}\s\p{P}]/u', $content, $m) && count($m[0]) > 20) {
            return true;
        }

        // لو فيه سلسلة إنجليزية طويلة جداً فهذا غريب في المستند العربي
        if (preg_match('/[a-zA-Z]{20,}/', $content)) {
            return true;
        }

        return false;
    }

    /**
     * البحث الديناميكي عن أرقام المستندات
     */
    private function findDocumentNumber($content, $documentType, $patterns)
    {
        if (empty($content) || mb_strlen($content) < 2) return null;

        foreach ($patterns as $pattern) {
            $fullPattern = '/' . $pattern . '/ui';
            if (preg_match($fullPattern, $content, $matches)) {
                $number = $matches[1];
                Log::info("Found {$documentType}: {$number}");
                return $number;
            }
        }
        return null;
    }

    /**
     * البحث عن التاريخ
     */
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

    /**
     * إنشاء PDF سريع باستخدام ghostscript
     */
    private function createQuickPdf($pdfPath, $pages, $outputPath)
    {
        try {
            $firstPage = min($pages);
            $lastPage = max($pages);

            // استخدم PDFSETTINGS مناسب لجودة عالية واستقرار
            $cmd = "gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite " .
                   "-dCompatibilityLevel=1.7 -dPDFSETTINGS=/prepress " .
                   "-dFirstPage={$firstPage} -dLastPage={$lastPage} " .
                   "-sOutputFile=" . escapeshellarg($outputPath) . " " .
                   escapeshellarg($pdfPath) . " 2>&1";

            exec($cmd, $output, $returnVar);

            return $returnVar === 0 && file_exists($outputPath);

        } catch (Exception $e) {
            Log::error("Quick PDF creation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * تنظيف اسم الملف
     */
    private function sanitizeFilename($filename)
    {
        // استبدال الأحرف الغير مرغوبة بشرطة سفلية
        $clean = preg_replace('/[^\p{Arabic}a-zA-Z0-9\-_\.]/u', '_', $filename);
        // إزالة تكرار الشرطات
        $clean = preg_replace('/[_\.]{2,}/', '_', $clean);
        // إزالة non-word من البداية والنهاية
        $clean = preg_replace('/^[^0-9\p{Arabic}a-zA-Z]+|[^0-9\p{Arabic}a-zA-Z]+$/u', '', $clean);
        // ضمان ألا يكون فارغاً
        return $clean === '' ? 'file_' . time() : $clean;
    }

    /**
     * تحويل صفحة PDF إلى صورة PNG (مع كاش)
     */
    private function convertToImage($pdfPath, $page)
    {
        // مفتاح الكاش مرتبط بالـ pdfPath+page
        $cacheKey = md5($pdfPath . '::' . $page);
        if (isset($this->imageCache[$cacheKey]) && file_exists($this->imageCache[$cacheKey])) {
            return $this->imageCache[$cacheKey];
        }

        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) mkdir($tempDir, 0775, true);

        $base = "page_{$page}_" . $cacheKey;
        $pngPath = "{$tempDir}/{$base}.png";

        // إذا الصورة موجودة من قبل لا تعيد تحويلها
        if (file_exists($pngPath)) {
            return $this->imageCache[$cacheKey] = $pngPath;
        }

        $cmd = "pdftoppm -f {$page} -l {$page} -png -singlefile " .
            escapeshellarg($pdfPath) . " " .
            escapeshellarg("{$tempDir}/{$base}") . " 2>&1";

        exec($cmd);

        if (file_exists($pngPath)) {
            return $this->imageCache[$cacheKey] = $pngPath;
        }

        return null;
    }

    /**
     * قراءة الباركود من صفحة PDF (مع كاش)
     */
    private function readPageBarcode($pdfPath, $page)
    {
        $cacheKey = md5($pdfPath . '::barcode::' . $page);
        if (isset($this->barcodeCache[$cacheKey])) {
            return $this->barcodeCache[$cacheKey];
        }

        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) return $this->barcodeCache[$cacheKey] = null;

            $barcode = $this->scanBarcode($imagePath);

            return $this->barcodeCache[$cacheKey] = $barcode;

        } catch (Exception $e) {
            Log::error("Barcode reading failed for page {$page}: " . $e->getMessage());
            return $this->barcodeCache[$cacheKey] = null;
        }
    }

    /**
     * مسح الباركود من الصورة
     */
    private function scanBarcode($imagePath)
    {
        $cmd = "zbarimg -q --raw " . escapeshellarg($imagePath) . " 2>&1";
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && !empty($output)) {
            // ممكن zbarimg يرجع عدة أسطر، نستخدم السطر الأول
            $first = trim(is_array($output) ? $output[0] : $output);
            return $first === '' ? null : $first;
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
