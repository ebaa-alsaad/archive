<?php

namespace App\Services;

use Exception;
use App\Models\Group;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BarcodeOCRService
{
    /**
     * معالجة PDF مبسطة وموثوقة
     */
    public function processPdf($upload, $disk = 'private')
    {
        $startTime = microtime(true);

        $pdfPath = Storage::disk($disk)->path($upload->stored_filename);

        Log::info("🔵 SIMPLE PDF PROCESSING STARTED", [
            'upload_id' => $upload->id,
            'pdf_path' => $pdfPath,
            'file_exists' => file_exists($pdfPath) ? 'yes' : 'no'
        ]);

        if (!file_exists($pdfPath)) {
            throw new Exception("PDF file not found: " . $pdfPath);
        }

        // تنظيف المجموعات القديمة
        Group::where('upload_id', $upload->id)->delete();

        // الحصول على عدد الصفحات بطريقة بسيطة
        $pageCount = $this->getPdfPageCountSimple($pdfPath);
        Log::info("📄 Page count determined", ['pages' => $pageCount]);

        if ($pageCount === 0) {
            throw new Exception("PDF file has no pages");
        }

        // تقسيم بسيط - كل 10 صفحات مجموعة
        $sections = $this->simpleSplit($pageCount);
        Log::info("📑 Sections created", ['sections_count' => count($sections)]);

        // إنشاء المجموعات
        $createdGroups = $this->createGroupsSimple($sections, $pdfPath, $upload);

        $endTime = microtime(true);
        $processingTime = round($endTime - $startTime, 2);

        Log::info("✅ SIMPLE PROCESSING COMPLETED", [
            'upload_id' => $upload->id,
            'processing_time' => $processingTime,
            'groups_created' => count($createdGroups),
            'pages_per_second' => round($pageCount / max(1, $processingTime), 2)
        ]);

        return [
            'groups' => $createdGroups,
            'total_pages' => $pageCount,
            'sections_count' => count($sections),
            'processing_time_seconds' => $processingTime
        ];
    }

    /**
     * تقسيم بسيط - كل 10 صفحات مجموعة
     */
    private function simpleSplit($pageCount)
    {
        $sections = [];
        $pagesPerSection = 10;

        for ($i = 0; $i < $pageCount; $i += $pagesPerSection) {
            $section = range($i + 1, min($i + $pagesPerSection, $pageCount));
            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * إنشاء مجموعات بسيطة
     */
    private function createGroupsSimple($sections, $pdfPath, $upload)
    {
        $createdGroups = [];

        foreach ($sections as $index => $pages) {
            try {
                $filename = $this->generateSimpleFilename($upload->original_filename, $index, $pages);
                $filenameSafe = $filename . '.pdf';

                $directory = "groups";
                $fullDir = storage_path("app/private/{$directory}");
                if (!file_exists($fullDir)) {
                    mkdir($fullDir, 0775, true);
                }

                $outputPath = "{$fullDir}/{$filenameSafe}";
                $dbPath = "{$directory}/{$filenameSafe}";

                // إنشاء PDF بسيط
                if ($this->createPdfSimple($pdfPath, $pages, $outputPath)) {
                    $group = Group::create([
                        'code' => 'section_' . ($index + 1),
                        'pdf_path' => $dbPath,
                        'pages_count' => count($pages),
                        'user_id' => $upload->user_id,
                        'upload_id' => $upload->id
                    ]);

                    $createdGroups[] = $group;

                    Log::debug("✅ Group created", [
                        'group_id' => $group->id,
                        'pages_count' => count($pages),
                        'filename' => $filenameSafe
                    ]);
                }

            } catch (Exception $e) {
                Log::error("❌ Group creation failed", [
                    'section_index' => $index,
                    'error' => $e->getMessage()
                ]);
                // استمرار المعالجة رغم الخطأ
            }
        }

        return $createdGroups;
    }

    /**
     * إنشاء PDF بسيط
     */
    private function createPdfSimple($pdfPath, $pages, $outputPath)
    {
        try {
            $pagesString = implode(' ', $pages);

            // استخدام pdftk إذا متوفر (أسرع)
            $cmdCheck = 'which pdftk 2>&1';
            exec($cmdCheck, $outputCheck, $returnCheck);

            if ($returnCheck === 0) {
                $cmd = sprintf(
                    'pdftk %s cat %s output %s 2>&1',
                    escapeshellarg($pdfPath),
                    $pagesString,
                    escapeshellarg($outputPath)
                );
            } else {
                // استخدام ghostscript كبديل
                $pageList = implode(' ', array_map(function($page) {
                    return "-dPageList=" . $page;
                }, $pages));

                $cmd = sprintf(
                    'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite %s -sOutputFile=%s %s 2>&1',
                    $pageList,
                    escapeshellarg($outputPath),
                    escapeshellarg($pdfPath)
                );
            }

            exec($cmd, $output, $returnVar);

            $success = $returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 1000;

            if (!$success) {
                Log::warning("PDF creation had issues", [
                    'returnVar' => $returnVar,
                    'file_size' => file_exists($outputPath) ? filesize($outputPath) : 0,
                    'output' => implode(', ', $output)
                ]);
            }

            return $success;

        } catch (Exception $e) {
            Log::error("❌ PDF creation failed", [
                'error' => $e->getMessage(),
                'pages_count' => count($pages)
            ]);
            return false;
        }
    }

    /**
     * عد الصفحات بطريقة بسيطة
     */
    private function getPdfPageCountSimple($pdfPath)
    {
        // محاولة مع pdfinfo
        $cmd = 'pdfinfo ' . escapeshellarg($pdfPath) . ' 2>&1';
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0) {
            foreach ($output as $line) {
                if (preg_match('/Pages:\s*(\d+)/i', $line, $matches)) {
                    return (int)$matches[1];
                }
            }
        }

        // طريقة بديلة
        $cmd = 'qpdf --show-npages ' . escapeshellarg($pdfPath) . ' 2>&1';
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && isset($output[0]) && is_numeric($output[0])) {
            return (int)$output[0];
        }

        // طريقة طوارئ
        return 10; // افتراضي آمن
    }

    /**
     * إنشاء اسم ملف بسيط
     */
    private function generateSimpleFilename($originalFilename, $index, $pages)
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalFilename, PATHINFO_FILENAME));
        $safeName = substr($safeName, 0, 20);

        $pageRange = count($pages) > 1 ?
            'pages_' . min($pages) . '_' . max($pages) :
            'page_' . $pages[0];

        return $safeName . '_' . ($index + 1) . '_' . $pageRange . '_' . time();
    }
}
