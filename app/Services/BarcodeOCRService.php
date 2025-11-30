<?php

namespace App\Services;

use Exception;
use App\Models\Group;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class BarcodeOCRService
{
    private $imageCache = [];
    private $barcodeCache = [];

    /**
     * معالجة PDF فائقة السرعة - الحل الجذري
     */
    public function processPdfUltraFast($upload, $pdfPath)
    {
        $startTime = microtime(true);
        Log::info("🚀 STARTING ULTRA FAST PDF PROCESSING", [
            'upload_id' => $upload->id,
            'pdf_path' => $pdfPath
        ]);

        // ⚡ زيادة الحدود للسرعة القصوى
        set_time_limit(0);
        ini_set('memory_limit', '4096M');

        if (!file_exists($pdfPath)) {
            throw new Exception("PDF file not found: " . $pdfPath);
        }

        // ⚡ تنظيف سريع للمجموعات القديمة
        Group::where('upload_id', $upload->id)->delete();

        // ⚡ الحصول على عدد الصفحات بسرعة قصوى
        $pageCount = $this->getPdfPageCountUltraFast($pdfPath);
        Log::info("Page count determined", ['pages' => $pageCount, 'upload_id' => $upload->id]);

        if ($pageCount === 0) {
            throw new Exception("PDF file has no pages");
        }

        // ⚡ قراءة الباركود الفاصل من الصفحة الأولى فقط - لتسريع العملية
        $separatorBarcode = $this->readFirstPageBarcodeUltraFast($pdfPath);

        Log::info("Separator barcode determined", [
            'barcode' => $separatorBarcode,
            'upload_id' => $upload->id
        ]);

        // ⚡ تقسيم فائق السرعة
        $sections = $this->ultraFastSplit($pdfPath, $pageCount, $separatorBarcode);

        Log::info("Sections split completed", [
            'sections_count' => count($sections),
            'upload_id' => $upload->id
        ]);

        // ⚡ إنشاء المجموعات بسرعة قصوى
        $createdGroups = $this->createGroupsUltraFast($sections, $pdfPath, $upload, $separatorBarcode);

        $endTime = microtime(true);
        $processingTime = round($endTime - $startTime, 2);

        Log::info("🎉 ULTRA FAST PROCESSING COMPLETED", [
            'upload_id' => $upload->id,
            'processing_time_seconds' => $processingTime,
            'groups_created' => count($createdGroups),
            'total_pages' => $pageCount
        ]);

        return [
            'groups' => $createdGroups,
            'total_pages' => $pageCount,
            'sections_count' => count($sections),
            'processing_time_seconds' => $processingTime
        ];
    }

    /**
     * تقسيم فائق السرعة
     */
    private function ultraFastSplit($pdfPath, $pageCount, $separatorBarcode)
    {
        $sections = [];
        $currentSection = [];

        // ⚡ استراتيجية تقسيم ذكية: افحص 20% من الصفحات فقط لتسريع العملية
        $sampleRate = max(1, floor($pageCount * 0.2)); // فحص 20% من الصفحات

        for ($page = 1; $page <= $pageCount; $page++) {
            $currentSection[] = $page;

            // ⚡ فحص الباركود في عينات محددة فقط
            $shouldCheckBarcode = $page === 1 || $page % $sampleRate === 0 || $page === $pageCount;

            if ($shouldCheckBarcode) {
                $barcode = $this->readPageBarcodeUltraFast($pdfPath, $page);

                if ($barcode === $separatorBarcode && count($currentSection) > 1) {
                    // احتفظ بالصفحة الحالية في القسم الجديد
                    $lastPage = array_pop($currentSection);
                    if (!empty($currentSection)) {
                        $sections[] = $currentSection;
                    }
                    $currentSection = [$lastPage];
                }
            }
        }

        // إضافة القسم الأخير
        if (!empty($currentSection)) {
            $sections[] = $currentSection;
        }

        return $sections;
    }

    /**
     * قراءة الباركود من الصفحة الأولى فقط
     */
    private function readFirstPageBarcodeUltraFast($pdfPath)
    {
        try {
            $barcode = $this->readPageBarcodeUltraFast($pdfPath, 1);
            return $barcode ?? 'default_separator_' . time();
        } catch (Exception $e) {
            Log::warning("First page barcode reading failed, using default", [
                'error' => $e->getMessage()
            ]);
            return 'default_separator_' . time();
        }
    }

    /**
     * قراءة باركود فائقة السرعة
     */
    private function readPageBarcodeUltraFast($pdfPath, $page)
    {
        $cacheKey = md5($pdfPath) . '_page_' . $page;

        if (isset($this->barcodeCache[$cacheKey])) {
            return $this->barcodeCache[$cacheKey];
        }

        try {
            $imagePath = $this->convertToImageUltraFast($pdfPath, $page);
            if (!$imagePath) {
                return $this->barcodeCache[$cacheKey] = null;
            }

            $barcode = $this->scanBarcodeUltraFast($imagePath);

            // تنظيف الصورة المؤقتة
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }

            return $this->barcodeCache[$cacheKey] = $barcode;

        } catch (Exception $e) {
            Log::debug("Barcode reading failed for page", [
                'page' => $page,
                'error' => $e->getMessage()
            ]);
            return $this->barcodeCache[$cacheKey] = null;
        }
    }

    /**
     * تحويل إلى صورة فائق السرعة
     */
    private function convertToImageUltraFast($pdfPath, $page)
    {
        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $pngPath = "{$tempDir}/fast_page_{$page}_" . time() . '_' . rand(1000, 9999) . '.png';

        // ⚡ استخدام إعدادات سريعة جداً
        $cmd = sprintf(
            'pdftoppm -f %d -l %d -png -singlefile -r 150 %s %s 2>&1',
            intval($page),
            intval($page),
            escapeshellarg($pdfPath),
            escapeshellarg(str_replace('.png', '', $pngPath))
        );

        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && file_exists($pngPath)) {
            return $pngPath;
        }

        return null;
    }

    /**
     * مسح باركود فائق السرعة
     */
    private function scanBarcodeUltraFast($imagePath)
    {
        if (!file_exists($imagePath)) {
            return null;
        }

        $cmd = sprintf('zbarimg -q --raw %s 2>&1', escapeshellarg($imagePath));
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && !empty($output) && is_array($output)) {
            return trim($output[0]);
        }

        return null;
    }

    /**
     * إنشاء مجموعات فائقة السرعة
     */
    private function createGroupsUltraFast($sections, $pdfPath, $upload, $separatorBarcode)
    {
        $createdGroups = [];
        $totalSections = count($sections);

        foreach ($sections as $index => $pages) {
            if (empty($pages)) continue;

            try {
                // ⚡ اسم ملف فائق السرعة - بدون OCR
                $filename = $this->generateUltraFastFilename($upload->original_filename, $index, $separatorBarcode);
                $filenameSafe = $filename . '.pdf';

                $directory = "groups";
                $fullDir = storage_path("app/private/{$directory}");
                if (!file_exists($fullDir)) {
                    mkdir($fullDir, 0775, true);
                }

                $outputPath = "{$fullDir}/{$filenameSafe}";
                $dbPath = "{$directory}/{$filenameSafe}";

                // ⚡ إنشاء PDF فائق السرعة
                if ($this->createPdfUltraFast($pdfPath, $pages, $outputPath)) {
                    $group = Group::create([
                        'code' => $separatorBarcode,
                        'pdf_path' => $dbPath,
                        'pages_count' => count($pages),
                        'user_id' => $upload->user_id,
                        'upload_id' => $upload->id
                    ]);

                    $createdGroups[] = $group;

                    Log::debug("Group created ultra fast", [
                        'group_id' => $group->id,
                        'pages_count' => count($pages),
                        'filename' => $filenameSafe
                    ]);
                }

            } catch (Exception $e) {
                Log::error("Ultra fast group creation failed", [
                    'section_index' => $index,
                    'pages_count' => count($pages),
                    'error' => $e->getMessage()
                ]);
                // استمرار المعالجة رغم الخطأ
            }
        }

        return $createdGroups;
    }

    /**
     * إنشاء اسم ملف فائق السرعة
     */
    private function generateUltraFastFilename($originalFilename, $index, $barcode)
    {
        // ⚡ اسم بسيط وسريع بدون معالجة نصية
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalFilename);
        $safeName = substr($safeName, 0, 20);

        return $safeName . '_section_' . ($index + 1) . '_' . substr(md5($barcode), 0, 8) . '_' . time();
    }

    /**
     * إنشاء PDF فائق السرعة
     */
    private function createPdfUltraFast($pdfPath, $pages, $outputPath)
    {
        try {
            $outputDir = dirname($outputPath);
            if (!file_exists($outputDir)) {
                mkdir($outputDir, 0775, true);
            }

            // ⚡ Ghostscript بإعدادات فائقة السرعة
            $pageList = implode(' ', array_map(function($page) {
                return "-dPageList=" . $page;
            }, $pages));

            $cmd = sprintf(
                'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite ' .
                '-dCompatibilityLevel=1.4 -dPDFSETTINGS=/screen ' . // ⚡ إعدادات شاشة للسرعة
                '-dEmbedAllFonts=false -dSubsetFonts=false ' .      // ⚡ عدم تضمين الخطوط
                '-dCompressPages=false -dUseCIEColor=false ' .     // ⚡ إعدادات سرعة
                '%s -sOutputFile=%s %s 2>&1',
                $pageList,
                escapeshellarg($outputPath),
                escapeshellarg($pdfPath)
            );

            exec($cmd, $output, $returnVar);

            $success = $returnVar === 0 &&
                      file_exists($outputPath) &&
                      filesize($outputPath) > 1000; // ⚡ حد أدنى منخفض

            if (!$success) {
                Log::warning("Ultra fast PDF creation failed, trying fallback", [
                    'returnVar' => $returnVar,
                    'file_size' => file_exists($outputPath) ? filesize($outputPath) : 0,
                    'pages_count' => count($pages)
                ]);

                // ⚡ محاولة بديلة سريعة
                $success = $this->pdfFallbackUltraFast($pdfPath, $pages, $outputPath);
            }

            return $success;

        } catch (Exception $e) {
            Log::error("Ultra fast PDF creation exception", [
                'error' => $e->getMessage(),
                'pages_count' => count($pages),
                'output_path' => $outputPath
            ]);
            return false;
        }
    }

    /**
     * بديل فائق السرعة لإنشاء PDF
     */
    private function pdfFallbackUltraFast($pdfPath, $pages, $outputPath)
    {
        try {
            // ⚡ استخدام pdftk إذا متوفر (أسرع)
            $cmdCheck = 'which pdftk 2>&1';
            exec($cmdCheck, $outputCheck, $returnCheck);

            if ($returnCheck === 0) {
                $pagesString = implode(' ', $pages);
                $cmd = sprintf(
                    'pdftk %s cat %s output %s 2>&1',
                    escapeshellarg($pdfPath),
                    $pagesString,
                    escapeshellarg($outputPath)
                );

                exec($cmd, $output, $returnVar);
                return $returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 1000;
            }

            return false;

        } catch (Exception $e) {
            Log::warning("PDF fallback failed", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * عد الصفحات فائق السرعة
     */
    private function getPdfPageCountUltraFast($pdfPath)
    {
        // ⚡ محاولة سريعة مع pdfinfo
        $cmd = 'pdfinfo ' . escapeshellarg($pdfPath) . ' 2>&1';
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0) {
            foreach ($output as $line) {
                if (preg_match('/Pages:\s*(\d+)/i', $line, $matches)) {
                    return (int)$matches[1];
                }
            }
        }

        // ⚡ طريقة بديلة سريعة
        $cmd = 'qpdf --show-npages ' . escapeshellarg($pdfPath) . ' 2>&1';
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && isset($output[0]) && is_numeric($output[0])) {
            return (int)$output[0];
        }

        // ⚡ طريقة الطوارئ - عد الأسطر التي تحتوي على /Page
        $cmd = 'strings ' . escapeshellarg($pdfPath) . ' | grep -c "/Page" | head -1';
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && isset($output[0]) && is_numeric($output[0])) {
            return max(1, (int)$output[0]);
        }

        throw new Exception("Cannot determine page count quickly");
    }

    /**
     * دعم التوافق مع الدوال القديمة
     */
    public function processPdf($upload, $disk = 'private')
    {
        $pdfPath = Storage::disk($disk)->path($upload->stored_filename);
        return $this->processPdfUltraFast($upload, $pdfPath);
    }

    public function getPdfPageCount($pdfPath)
    {
        return $this->getPdfPageCountUltraFast($pdfPath);
    }
}
