<?php

namespace App\Services;

use Exception;
use App\Models\Group;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BarcodeOCRService
{
    private $tempDir;

    public function __construct()
    {
        $this->tempDir = storage_path("app/temp");
        if (!file_exists($this->tempDir)) {
            mkdir($this->tempDir, 0775, true);
        }
    }

    /**
     * التحقق من جميع الأدوات المطلوبة
     */
    private function checkDependencies()
    {
        $tools = ['gs', 'pdfinfo', 'pdftotext', 'pdftoppm', 'tesseract', 'zbarimg'];
        $missing = [];

        foreach ($tools as $tool) {
            $output = [];
            exec("which {$tool} 2>/dev/null", $output, $returnVar);
            if ($returnVar !== 0 || empty($output)) {
                $missing[] = $tool;
            }
        }

        if (!empty($missing)) {
            throw new Exception("الأدوات التالية غير مثبتة: " . implode(', ', $missing));
        }

        return true;
    }

    /**
     * المعالجة الرئيسية مع تحسينات كبيرة
     */
    public function processPdf($upload)
    {
        Log::info("بدء معالجة الملف", ['upload_id' => $upload->id]);

        try {
            // زيادة وقت التنفيذ والذاكرة
            set_time_limit(0);
            ini_set('memory_limit', '1024M');
            ini_set('max_execution_time', 0);

            // التحقق من التبعيات
            $this->checkDependencies();

            $pdfPath = Storage::disk('private')->path($upload->stored_filename);
            
            if (!file_exists($pdfPath)) {
                throw new Exception("الملف غير موجود: " . $pdfPath);
            }

            // التحقق من حجم الملف
            if (filesize($pdfPath) === 0) {
                throw new Exception("الملف فارغ");
            }

            Log::info("جاري معالجة الملف: " . basename($pdfPath));

            // الحصول على عدد الصفحات
            $pageCount = $this->getPdfPageCount($pdfPath);
            Log::info("عدد الصفحات: " . $pageCount);

            if ($pageCount === 0) {
                throw new Exception("لا توجد صفحات في الملف");
            }

            // قراءة الباركود من الصفحة الأولى فقط
            $separatorBarcode = $this->readPageBarcode($pdfPath, 1);
            if (!$separatorBarcode) {
                $separatorBarcode = 'default_' . time();
                Log::warning("لم يتم العثور على باركود، استخدام قيمة افتراضية: " . $separatorBarcode);
            }

            Log::info("باركود الفاصل: " . $separatorBarcode);

            // تقسيم بسيط وسريع للصفحات
            $sections = $this->simpleSplit($pdfPath, $pageCount, $separatorBarcode);
            Log::info("تم تقسيم الملف إلى " . count($sections) . " قسم");

            if (empty($sections)) {
                throw new Exception("لم يتم العثور على أقسام في الملف");
            }

            // معالجة الأقسام
            $createdGroups = $this->createGroupFiles($sections, $pdfPath, $separatorBarcode, $upload);

            Log::info("تم الانتهاء من المعالجة بنجاح", [
                'groups_created' => count($createdGroups)
            ]);

            return $createdGroups;

        } catch (Exception $e) {
            Log::error("فشل في معالجة الملف", [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            throw new Exception("خطأ في معالجة الملف: " . $e->getMessage());
        }
    }

    /**
     * تقسيم مبسط وسريع للصفحات
     */
    private function simpleSplit($pdfPath, $pageCount, $separatorBarcode)
    {
        $sections = [];
        $currentSection = [];

        Log::info("بدء تقسيم الصفحات...");

        for ($page = 1; $page <= $pageCount; $page++) {
            try {
                // قراءة الباركود للصفحة الحالية
                $barcode = $this->readPageBarcode($pdfPath, $page);
                
                if ($barcode === $separatorBarcode) {
                    // بداية قسم جديد
                    if (!empty($currentSection)) {
                        $sections[] = $currentSection;
                        $currentSection = [];
                    }
                } else {
                    $currentSection[] = $page;
                }
            } catch (Exception $e) {
                Log::warning("خطأ في الصفحة {$page}: " . $e->getMessage());
                $currentSection[] = $page;
            }
        }

        // إضافة القسم الأخير
        if (!empty($currentSection)) {
            $sections[] = $currentSection;
        }

        return $sections;
    }

    /**
     * إنشاء ملفات المجموعات
     */
    private function createGroupFiles($sections, $pdfPath, $separatorBarcode, $upload)
    {
        $createdGroups = [];
        $directory = "groups";
        $fullDir = storage_path("app/private/{$directory}");
        
        if (!file_exists($fullDir)) {
            mkdir($fullDir, 0775, true);
        }

        foreach ($sections as $index => $pages) {
            if (empty($pages)) continue;

            try {
                $sectionNumber = $index + 1;
                Log::info("معالجة القسم {$sectionNumber} (" . count($pages) . " صفحة)");

                // إنشاء اسم ملف بسيط
                $filename = $this->sanitizeFilename($separatorBarcode . '_' . $sectionNumber);
                $filenameSafe = $filename . '.pdf';
                $outputPath = "{$fullDir}/{$filenameSafe}";
                $dbPath = "{$directory}/{$filenameSafe}";

                // إنشاء ملف PDF
                if ($this->createPdf($pdfPath, $pages, $outputPath)) {
                    // حفظ في قاعدة البيانات
                    $group = Group::create([
                        'code' => $separatorBarcode,
                        'pdf_path' => $dbPath,
                        'pages_count' => count($pages),
                        'user_id' => $upload->user_id,
                        'upload_id' => $upload->id
                    ]);

                    $createdGroups[] = $group;
                    Log::info("تم إنشاء المجموعة {$group->id}: {$filenameSafe}");
                }

            } catch (Exception $e) {
                Log::error("خطأ في القسم {$sectionNumber}: " . $e->getMessage());
                continue;
            }
        }

        return $createdGroups;
    }

    /**
     * إنشاء ملف PDF
     */
    private function createPdf($pdfPath, $pages, $outputPath)
    {
        try {
            $firstPage = min($pages);
            $lastPage = max($pages);

            $cmd = "gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite " .
                   "-dFirstPage={$firstPage} -dLastPage={$lastPage} " .
                   "-sOutputFile=" . escapeshellarg($outputPath) . " " .
                   escapeshellarg($pdfPath) . " 2>&1";

            exec($cmd, $output, $returnVar);

            return $returnVar === 0 && file_exists($outputPath);

        } catch (Exception $e) {
            Log::error("خطأ في إنشاء PDF: " . $e->getMessage());
            return false;
        }
    }

    /**
     * قراءة الباركود من الصفحة
     */
    private function readPageBarcode($pdfPath, $page)
    {
        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) return null;

            $cmd = "zbarimg -q --raw " . escapeshellarg($imagePath) . " 2>&1";
            exec($cmd, $output, $returnVar);

            @unlink($imagePath);

            if ($returnVar === 0 && !empty($output)) {
                return trim($output[0]);
            }

            return null;

        } catch (Exception $e) {
            Log::warning("خطأ في قراءة الباركود للصفحة {$page}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * تحويل صفحة PDF إلى صورة
     */
    private function convertToImage($pdfPath, $page)
    {
        try {
            $base = "page_{$page}_" . time();
            $pngPath = "{$this->tempDir}/{$base}.png";

            $cmd = "pdftoppm -f {$page} -l {$page} -png -singlefile " .
                   escapeshellarg($pdfPath) . " " .
                   escapeshellarg("{$this->tempDir}/{$base}") . " 2>&1";
            
            exec($cmd);

            return file_exists($pngPath) ? $pngPath : null;

        } catch (Exception $e) {
            Log::warning("خطأ في تحويل الصفحة {$page} إلى صورة: " . $e->getMessage());
            return null;
        }
    }

    /**
     * الحصول على عدد صفحات PDF
     */
    private function getPdfPageCount($pdfPath)
    {
        $cmd = "pdfinfo " . escapeshellarg($pdfPath) . " 2>&1";
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            throw new Exception("لا يمكن قراءة معلومات الملف: " . implode("\n", $output));
        }

        foreach ($output as $line) {
            if (preg_match('/Pages:\s*(\d+)/i', $line, $matches)) {
                return (int)$matches[1];
            }
        }

        throw new Exception("لم يتم العثور على عدد الصفحات");
    }

    /**
     * تنظيف اسم الملف
     */
    private function sanitizeFilename($filename)
    {
        $clean = preg_replace('/[^\p{Arabic}a-zA-Z0-9_-]/u', '_', $filename);
        $clean = preg_replace('/_{2,}/', '_', $clean);
        return trim($clean, '_');
    }

    /**
     * الدوال المفقودة من الكود الأصلي - مضافة الآن
     */

    /**
     * استخراج النص باستخدام pdftotext
     */
    private function extractWithPdftotext($pdfPath, $page)
    {
        try {
            $tempFile = $this->tempDir . "/pdftotext_" . time() . ".txt";

            $cmd = "pdftotext -f {$page} -l {$page} -layout " . 
                   escapeshellarg($pdfPath) . " " . escapeshellarg($tempFile) . " 2>&1";
            
            exec($cmd, $output, $returnVar);

            $content = '';
            if (file_exists($tempFile)) {
                $content = file_get_contents($tempFile);
                $content = preg_replace('/\s+/', ' ', $content);
                $content = trim($content);
                @unlink($tempFile);
            }

            return $content;

        } catch (Exception $e) {
            Log::error("استخراج النص فشل: " . $e->getMessage());
            return '';
        }
    }

    /**
     * استخراج النص باستخدام OCR
     */
    private function extractTextWithOCR($pdfPath, $page)
    {
        try {
            $imagePath = $this->convertToImage($pdfPath, $page);
            if (!$imagePath) return '';

            $outputFile = $this->tempDir . "/ocr_" . time();

            $cmd = "tesseract " . escapeshellarg($imagePath) . " " .
                   escapeshellarg($outputFile) . " -l ara --psm 6 2>&1";

            exec($cmd, $output, $returnVar);

            $content = '';
            if (file_exists($outputFile . '.txt')) {
                $content = file_get_contents($outputFile . '.txt');
                $content = preg_replace('/\s+/', ' ', $content);
                $content = trim($content);
                @unlink($outputFile . '.txt');
            }

            @unlink($imagePath);
            return $content;

        } catch (Exception $e) {
            Log::error("OCR فشل: " . $e->getMessage());
            return '';
        }
    }

    /**
     * البحث عن رقم المستند
     */
    private function findDocumentNumber($content, $documentType, $patterns)
    {
        foreach ($patterns as $pattern) {
            $fullPattern = '/' . $pattern . '/ui';
            if (preg_match($fullPattern, $content, $matches)) {
                $number = $matches[1];
                Log::info("تم العثور على {$documentType}: {$number}");
                return $number;
            }
        }
        return null;
    }

    /**
     * البحث عن تاريخ
     */
    private function findDate($content)
    {
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
            
            $cmd = "gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite " .
                   "-dFirstPage={$firstPage} -dLastPage={$lastPage} " .
                   "-sOutputFile=" . escapeshellarg($outputPath) . " " .
                   escapeshellarg($pdfPath) . " 2>&1";
            
            exec($cmd, $output, $returnVar);
            
            return $returnVar === 0 && file_exists($outputPath);
            
        } catch (Exception $e) {
            Log::error("إنشاء PDF فشل: " . $e->getMessage());
            return false;
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
            return trim($output[0]);
        }
        return null;
    }

    /**
     * إنشاء اسم ملف باستخدام OCR
     */
    private function generateFilenameWithOCR($pdfPath, $pages, $index, $barcode)
    {
        $firstPage = $pages[0];
        
        // استخدام pdftotext أولاً لأنه أسرع
        $content = $this->extractWithPdftotext($pdfPath, $firstPage);
        
        // إذا لم يحصل على محتوى جيد، استخدم OCR
        if (empty($content) || strlen($content) < 50) {
            $content = $this->extractTextWithOCR($pdfPath, $firstPage);
        }

        Log::info("المحتوى المستخرج من الصفحة {$firstPage}: " . substr($content, 0, 200));

        // البحث عن رقم القيد
        $qeedNumber = $this->findDocumentNumber($content, 'قيد', [
            'رقم\s*القيد\s*[:\-]?\s*(\d+)',
            'القيد\s*[:\-]?\s*(\d+)',
            'قيد\s*[:\-]?\s*(\d+)',
            'رقم\s*[:\-]?\s*(\d+).*قيد'
        ]);

        if ($qeedNumber) {
            return $this->sanitizeFilename($qeedNumber);
        }

        // البحث عن رقم الفاتورة
        $invoiceNumber = $this->findDocumentNumber($content, 'فاتورة', [
            'رقم\s*الفاتورة\s*[:\-]?\s*(\d+)',
            'الفاتورة\s*[:\-]?\s*(\d+)',
            'فاتورة\s*[:\-]?\s*(\d+)'
        ]);

        if ($invoiceNumber) {
            return $this->sanitizeFilename($invoiceNumber);
        }

        // البحث عن رقم السند
        $sanedNumber = $this->findDocumentNumber($content, 'سند', [
            'رقم\s*السند\s*[:\-]?\s*(\d+)',
            'السند\s*[:\-]?\s*(\d+)',
            'سند\s*[:\-]?\s*(\d+)'
        ]);

        if ($sanedNumber) {
            return $this->sanitizeFilename($sanedNumber);
        }

        // البحث عن تاريخ
        $date = $this->findDate($content);
        if ($date) {
            return $this->sanitizeFilename($date);
        }

        // اسم افتراضي
        return $this->sanitizeFilename($barcode . '_' . ($index + 1));
    }

    public function __destruct()
    {
        // تنظيف الملفات المؤقتة القديمة
        try {
            $files = glob($this->tempDir . "/*.{png,jpg,txt}", GLOB_BRACE);
            $now = time();
            
            foreach ($files as $file) {
                if (is_file($file) && ($now - filemtime($file)) > 3600) {
                    @unlink($file);
                }
            }
        } catch (Exception $e) {
            // تجاهل أخطاء التنظيف
        }
    }
}