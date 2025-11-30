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
            'file_exists' => file_exists($pdfPath) ? 'yes' : 'no',
            'file_size' => file_exists($pdfPath) ? filesize($pdfPath) : 0
        ]);

        if (!file_exists($pdfPath)) {
            throw new Exception("PDF file not found: " . $pdfPath);
        }

        // التحقق من أن الملف غير فارغ
        if (filesize($pdfPath) === 0) {
            throw new Exception("PDF file is empty: " . $pdfPath);
        }

        // تنظيف المجموعات القديمة
        Group::where('upload_id', $upload->id)->delete();
        Log::info("🧹 Old groups cleaned", ['upload_id' => $upload->id]);

        // الحصول على عدد الصفحات بطريقة بسيطة
        $pageCount = $this->getPdfPageCountSimple($pdfPath);
        Log::info("📄 Page count determined", [
            'pages' => $pageCount,
            'upload_id' => $upload->id
        ]);

        if ($pageCount === 0) {
            throw new Exception("PDF file has no pages");
        }

        // تقسيم بسيط - كل 10 صفحات مجموعة
        $sections = $this->simpleSplit($pageCount);
        Log::info("📑 Sections created", [
            'sections_count' => count($sections),
            'upload_id' => $upload->id
        ]);

        // إنشاء المجموعات
        $createdGroups = $this->createGroupsSimple($sections, $pdfPath, $upload);

        $endTime = microtime(true);
        $processingTime = round($endTime - $startTime, 2);

        Log::info("✅ SIMPLE PROCESSING COMPLETED", [
            'upload_id' => $upload->id,
            'processing_time' => $processingTime,
            'groups_created' => count($createdGroups),
            'pages_per_second' => round($pageCount / max(1, $processingTime), 2),
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
     * تقسيم بسيط - كل 10 صفحات مجموعة
     */
    private function simpleSplit($pageCount)
    {
        $sections = [];
        $pagesPerSection = 10;

        Log::debug("Splitting {$pageCount} pages into sections of {$pagesPerSection}");

        for ($i = 0; $i < $pageCount; $i += $pagesPerSection) {
            $section = range($i + 1, min($i + $pagesPerSection, $pageCount));
            $sections[] = $section;

            Log::debug("Created section", [
                'section_index' => count($sections) - 1,
                'pages' => $section
            ]);
        }

        return $sections;
    }

    /**
     * إنشاء مجموعات بسيطة
     */
    private function createGroupsSimple($sections, $pdfPath, $upload)
    {
        $createdGroups = [];
        $totalGroupsCreated = 0;
        $totalGroupsFailed = 0;

        Log::info("🛠️ Starting group creation", [
            'upload_id' => $upload->id,
            'total_sections' => count($sections)
        ]);

        foreach ($sections as $index => $pages) {
            try {
                Log::debug("Creating group for section", [
                    'section_index' => $index,
                    'pages_count' => count($pages),
                    'pages' => $pages
                ]);

                $filename = $this->generateSimpleFilename($upload->original_filename, $index, $pages);
                $filenameSafe = $filename . '.pdf';

                $directory = "groups";
                $fullDir = storage_path("app/private/{$directory}");

                // إنشاء المجلد إذا لم يكن موجوداً
                if (!file_exists($fullDir)) {
                    if (!mkdir($fullDir, 0775, true)) {
                        throw new Exception("Failed to create directory: {$fullDir}");
                    }
                    Log::debug("Created directory", ['path' => $fullDir]);
                }

                $outputPath = "{$fullDir}/{$filenameSafe}";
                $dbPath = "{$directory}/{$filenameSafe}";

                // إنشاء PDF بسيط
                Log::debug("Creating PDF for group", [
                    'output_path' => $outputPath,
                    'pages_count' => count($pages)
                ]);

                if ($this->createPdfSimple($pdfPath, $pages, $outputPath)) {
                    $group = Group::create([
                        'code' => 'section_' . ($index + 1),
                        'pdf_path' => $dbPath,
                        'pages_count' => count($pages),
                        'user_id' => $upload->user_id,
                        'upload_id' => $upload->id,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);

                    $createdGroups[] = $group;
                    $totalGroupsCreated++;

                    Log::info("✅ Group created successfully", [
                        'group_id' => $group->id,
                        'upload_id' => $upload->id,
                        'pages_count' => count($pages),
                        'filename' => $filenameSafe,
                        'file_size' => file_exists($outputPath) ? filesize($outputPath) : 0
                    ]);
                } else {
                    $totalGroupsFailed++;
                    Log::warning("❌ PDF creation failed for section", [
                        'section_index' => $index,
                        'pages_count' => count($pages)
                    ]);
                }

            } catch (Exception $e) {
                $totalGroupsFailed++;
                Log::error("❌ Group creation failed", [
                    'section_index' => $index,
                    'upload_id' => $upload->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                // استمرار المعالجة رغم الخطأ
            }
        }

        Log::info("🎯 Group creation summary", [
            'upload_id' => $upload->id,
            'total_groups_created' => $totalGroupsCreated,
            'total_groups_failed' => $totalGroupsFailed,
            'success_rate' => $totalGroupsCreated > 0 ?
                round(($totalGroupsCreated / ($totalGroupsCreated + $totalGroupsFailed)) * 100, 2) : 0
        ]);

        return $createdGroups;
    }

    /**
     * إنشاء PDF بسيط
     */
    private function createPdfSimple($pdfPath, $pages, $outputPath)
    {
        try {
            // تنظيف المسار إذا كان الملف موجوداً مسبقاً
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            $pagesString = implode(' ', $pages);

            Log::debug("Creating PDF with pages", [
                'input_path' => $pdfPath,
                'output_path' => $outputPath,
                'pages' => $pages,
                'pages_string' => $pagesString
            ]);

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

                Log::debug("Using pdftk command", ['command' => $cmd]);
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

                Log::debug("Using ghostscript command", ['command' => $cmd]);
            }

            exec($cmd, $output, $returnVar);

            $success = $returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 1000;

            if ($success) {
                Log::debug("PDF created successfully", [
                    'output_path' => $outputPath,
                    'file_size' => filesize($outputPath),
                    'return_code' => $returnVar
                ]);
            } else {
                Log::warning("PDF creation had issues", [
                    'returnVar' => $returnVar,
                    'file_size' => file_exists($outputPath) ? filesize($outputPath) : 0,
                    'output' => implode(', ', $output),
                    'command' => $cmd
                ]);

                // محاولة بديلة إذا فشلت الطريقة الأولى
                if (!$success && $returnCheck !== 0) {
                    $success = $this->fallbackPdfCreation($pdfPath, $pages, $outputPath);
                }
            }

            return $success;

        } catch (Exception $e) {
            Log::error("❌ PDF creation failed", [
                'error' => $e->getMessage(),
                'pages_count' => count($pages),
                'output_path' => $outputPath,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * طريقة بديلة لإنشاء PDF
     */
    private function fallbackPdfCreation($pdfPath, $pages, $outputPath)
    {
        try {
            Log::debug("Trying fallback PDF creation method");

            // استخدام python و PyPDF2 كبديل أخير
            $pagesList = implode(',', array_map(function($page) {
                return strval($page - 1); // PyPDF2 يبدأ من 0
            }, $pages));

            $pythonScript = "
import PyPDF2
import sys

input_path = '{$pdfPath}'
output_path = '{$outputPath}'
pages = [{$pagesList}]

try:
    with open(input_path, 'rb') as input_file:
        reader = PyPDF2.PdfReader(input_file)
        writer = PyPDF2.PdfWriter()

        for page_num in pages:
            if page_num < len(reader.pages):
                writer.add_page(reader.pages[page_num])

        with open(output_path, 'wb') as output_file:
            writer.write(output_file)
        print('success')
except Exception as e:
    print(str(e))
    sys.exit(1)
";

            $tempScriptPath = tempnam(sys_get_temp_dir(), 'pdf_merge_') . '.py';
            file_put_contents($tempScriptPath, $pythonScript);

            $cmd = "python3 " . escapeshellarg($tempScriptPath) . " 2>&1";
            exec($cmd, $output, $returnVar);

            // تنظيف الملف المؤقت
            if (file_exists($tempScriptPath)) {
                unlink($tempScriptPath);
            }

            $success = $returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 1000;

            if ($success) {
                Log::debug("Fallback PDF creation succeeded");
            } else {
                Log::warning("Fallback PDF creation also failed", [
                    'returnVar' => $returnVar,
                    'output' => implode(', ', $output)
                ]);
            }

            return $success;

        } catch (Exception $e) {
            Log::error("Fallback PDF creation failed", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * عد الصفحات بطريقة بسيطة
     */
    private function getPdfPageCountSimple($pdfPath)
    {
        Log::debug("Counting PDF pages", ['pdf_path' => $pdfPath]);

        // محاولة مع pdfinfo
        $cmd = 'pdfinfo ' . escapeshellarg($pdfPath) . ' 2>&1';
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0) {
            foreach ($output as $line) {
                if (preg_match('/Pages:\s*(\d+)/i', $line, $matches)) {
                    $count = (int)$matches[1];
                    Log::debug("PDF page count from pdfinfo", ['count' => $count]);
                    return $count;
                }
            }
        }

        Log::debug("pdfinfo failed, trying qpdf", [
            'returnVar' => $returnVar,
            'output' => implode(', ', $output)
        ]);

        // طريقة بديلة مع qpdf
        $cmd = 'qpdf --show-npages ' . escapeshellarg($pdfPath) . ' 2>&1';
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && isset($output[0]) && is_numeric($output[0])) {
            $count = (int)$output[0];
            Log::debug("PDF page count from qpdf", ['count' => $count]);
            return $count;
        }

        Log::debug("qpdf failed, trying strings method", [
            'returnVar' => $returnVar,
            'output' => implode(', ', $output)
        ]);

        // طريقة طوارئ - عد أسائل /Page
        $cmd = 'strings ' . escapeshellarg($pdfPath) . ' | grep -c "/Page" | head -1';
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && isset($output[0]) && is_numeric($output[0])) {
            $count = max(1, (int)$output[0]);
            Log::debug("PDF page count from strings", ['count' => $count]);
            return $count;
        }

        Log::warning("All page count methods failed, using default", [
            'pdf_path' => $pdfPath
        ]);

        // طريقة طوارئ أخيرة
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

        $filename = $safeName . '_' . ($index + 1) . '_' . $pageRange . '_' . time();

        Log::debug("Generated filename", [
            'original' => $originalFilename,
            'safe_name' => $safeName,
            'page_range' => $pageRange,
            'final_filename' => $filename
        ]);

        return $filename;
    }

    /**
     * التحقق من توفر الأدوات المطلوبة
     */
    public function checkDependencies()
    {
        $dependencies = [
            'pdftk' => false,
            'ghostscript' => false,
            'pdfinfo' => false,
            'qpdf' => false,
            'python3' => false
        ];

        foreach ($dependencies as $tool => &$available) {
            $cmd = "which {$tool} 2>&1";
            exec($cmd, $output, $returnVar);
            $available = $returnVar === 0;
        }

        Log::info("Dependency check", $dependencies);

        return $dependencies;
    }

    /**
     * تنظيف الملفات المؤقتة للمجموعات
     */
    public function cleanupGroupFiles($uploadId)
    {
        try {
            $groups = Group::where('upload_id', $uploadId)->get();
            $deletedCount = 0;

            foreach ($groups as $group) {
                if ($group->pdf_path && Storage::exists($group->pdf_path)) {
                    Storage::delete($group->pdf_path);
                    $deletedCount++;
                }
            }

            Log::info("Group files cleanup completed", [
                'upload_id' => $uploadId,
                'deleted_files' => $deletedCount,
                'total_groups' => count($groups)
            ]);

            return $deletedCount;

        } catch (Exception $e) {
            Log::error("Group files cleanup failed", [
                'upload_id' => $uploadId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}
