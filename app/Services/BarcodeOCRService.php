<?php

namespace App\Services;

use Exception;
use App\Models\Group;
use App\Models\Upload;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Redis;

class BarcodeOCRService
{
    private $pdfHash = null;
    private $tempFiles = [];

    /**
     * المعالجة الرئيسية - سريعة وموثوقة
     */
    public function processPdf(Upload $upload)
    {
        set_time_limit(1800);
        ini_set('max_execution_time', 1800);
        ini_set('memory_limit', '1024M');

        $pdfPath = Storage::disk('private')->path($upload->stored_filename);
        if (!file_exists($pdfPath)) {
            throw new Exception("PDF file not found: " . $pdfPath);
        }

        $this->pdfHash = md5_file($pdfPath);
        $pageCount = $this->getPdfPageCount($pdfPath);
        $upload->update(['total_pages' => $pageCount]);

        Log::info("🎯 بدء معالجة PDF", [
            'upload_id' => $upload->id,
            'pages' => $pageCount,
            'file_size' => filesize($pdfPath)
        ]);

        try {
            // 1. تقسيم الملف إلى أقسام
            $sections = $this->quickSplitPdf($pdfPath, $pageCount);

            if (empty($sections)) {
                throw new Exception("لم يتم العثور على أقسام في الملف");
            }

            Log::info("✅ تم تقسيم الملف", [
                'sections_count' => count($sections),
                'sections_pages' => array_map('count', $sections)
            ]);

            // 2. معالجة الأقسام
            $createdGroups = $this->quickProcessSections($pdfPath, $sections, $upload);

            Log::info("🎉 اكتملت معالجة PDF", [
                'upload_id' => $upload->id,
                'groups_created' => count($createdGroups)
            ]);

            return $createdGroups;

        } catch (Exception $e) {
            Log::error("❌ فشلت معالجة PDF", [
                'upload_id' => $upload->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } finally {
            $this->cleanupTemp();
        }
    }

    /**
     * تقسيم سريع للملف
     */
    private function quickSplitPdf($pdfPath, $pageCount)
    {
        $sections = [];
        $currentSection = [];

        // البحث عن الباركود الفاصل
        $separatorBarcode = $this->findSeparatorBarcode($pdfPath, min(5, $pageCount));

        Log::info("✂️ جاري التقسيم باستخدام الباركود", ['separator' => $separatorBarcode]);

        for ($page = 1; $page <= $pageCount; $page++) {
            $barcode = $this->readPageBarcode($pdfPath, $page);

            // إذا كان هذا هو الباركود الفاصل وليس الصفحة الأولى، نبدأ قسم جديد
            if ($barcode === $separatorBarcode && $page > 1 && !empty($currentSection)) {
                $sections[] = $currentSection;
                $currentSection = [];
            }

            $currentSection[] = $page;
        }

        // إضافة القسم الأخير
        if (!empty($currentSection)) {
            $sections[] = $currentSection;
        }

        return $sections;
    }

    /**
     * البحث عن الباركود الفاصل
     */
    private function findSeparatorBarcode($pdfPath, $sampleSize)
    {
        $barcodes = [];

        for ($page = 1; $page <= $sampleSize; $page++) {
            $barcode = $this->readPageBarcode($pdfPath, $page);
            if ($barcode) {
                $barcodes[$barcode] = ($barcodes[$barcode] ?? 0) + 1;
            }
        }

        if (!empty($barcodes)) {
            arsort($barcodes);
            return array_key_first($barcodes);
        }

        return 'default_separator';
    }

    /**
     * معالجة سريعة للأقسام
     */
    private function quickProcessSections($pdfPath, $sections, $upload)
    {
        $createdGroups = [];

        foreach ($sections as $index => $pages) {
            if (empty($pages)) continue;

            $group = $this->quickCreateGroup($pdfPath, $pages, $index, $upload);
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
     * إنشاء مجموعة سريعة
     */
    private function quickCreateGroup($pdfPath, $pages, $index, $upload)
    {
        try {
            // اسم بسيط وسريع
            $filename = "مستند_" . ($index + 1) . "_" . date('Y-m-d') . ".pdf";
            $directory = "groups";
            $fullDir = storage_path("app/private/{$directory}");

            if (!is_dir($fullDir)) {
                mkdir($fullDir, 0755, true);
            }

            $outputPath = "{$fullDir}/{$filename}";
            $dbPath = "{$directory}/{$filename}";

            // إنشاء ملف PDF
            if ($this->createPdfFile($pdfPath, $pages, $outputPath)) {
                $group = Group::create([
                    'code' => 'section_' . ($index + 1),
                    'pdf_path' => $dbPath,
                    'pages_count' => count($pages),
                    'user_id' => $upload->user_id,
                    'upload_id' => $upload->id,
                    'filename' => $filename
                ]);

                Log::info("✅ تم إنشاء المجموعة", [
                    'group_id' => $group->id,
                    'filename' => $filename,
                    'pages_count' => count($pages)
                ]);

                return $group;
            }

        } catch (Exception $e) {
            Log::error("❌ فشل إنشاء المجموعة", [
                'upload_id' => $upload->id,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * إنشاء ملف PDF - الطريقة الموثوقة
     */
    private function createPdfFile($pdfPath, $pages, $outputPath)
    {
        if (empty($pages)) return false;

        // المحاولة الأولى: pdftk
        $pagesList = implode(' ', $pages);
        $cmd = "pdftk \"{$pdfPath}\" cat {$pagesList} output \"{$outputPath}\" 2>&1";
        exec($cmd, $output, $returnCode);

        if ($returnCode === 0 && file_exists($outputPath) && filesize($outputPath) > 0) {
            return true;
        }

        // المحاولة الثانية: ghostscript
        $firstPage = min($pages);
        $lastPage = max($pages);
        $cmd = "gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dFirstPage={$firstPage} -dLastPage={$lastPage} -sOutputFile=\"{$outputPath}\" \"{$pdfPath}\" 2>&1";
        exec($cmd, $output, $returnCode);

        return ($returnCode === 0) && file_exists($outputPath) && (filesize($outputPath) > 0);
    }

    /**
     * قراءة الباركود من الصفحة
     */
    private function readPageBarcode($pdfPath, $page)
    {
        try {
            $imagePath = $this->convertPageToImage($pdfPath, $page);
            if (!$imagePath) return null;

            $cmd = "zbarimg -q --raw \"{$imagePath}\" 2>/dev/null";
            exec($cmd, $output, $returnCode);

            if ($returnCode === 0 && !empty($output)) {
                return trim(is_array($output) ? $output[0] : $output);
            }

        } catch (Exception $e) {
            // تجاهل الأخطاء
        }

        return null;
    }

    /**
     * تحويل صفحة PDF إلى صورة
     */
    private function convertPageToImage($pdfPath, $page)
    {
        $tempDir = storage_path("app/temp");
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $baseName = "page_" . md5($pdfPath . $page);
        $imagePath = "{$tempDir}/{$baseName}.png";

        if (file_exists($imagePath)) {
            $this->tempFiles[] = $imagePath;
            return $imagePath;
        }

        $cmd = "pdftoppm -f {$page} -l {$page} -png -singlefile -r 100 \"{$pdfPath}\" \"{$tempDir}/{$baseName}\" 2>/dev/null";
        exec($cmd, $output, $returnCode);

        if ($returnCode === 0 && file_exists($imagePath)) {
            $this->tempFiles[] = $imagePath;
            return $imagePath;
        }

        return null;
    }

    /**
     * الحصول على عدد صفحات PDF
     */
    public function getPdfPageCount($pdfPath)
    {
        $cmd = "pdfinfo \"{$pdfPath}\" 2>/dev/null";
        exec($cmd, $output, $returnCode);

        if ($returnCode === 0) {
            foreach ($output as $line) {
                if (preg_match('/Pages:\s*(\d+)/i', $line, $matches)) {
                    return (int)$matches[1];
                }
            }
        }

        throw new Exception("تعذر تحديد عدد صفحات PDF");
    }

    /**
     * تنظيف الملفات المؤقتة
     */
    private function cleanupTemp()
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    public function __destruct()
    {
        $this->cleanupTemp();
    }
}
