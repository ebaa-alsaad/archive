<?php

namespace App\Services;

use Exception;
use App\Models\Group;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BarcodeOCRService
{
    /**
     * معالجة PDF باستخدام الباركود الفاصل
     */
    public function processPdf($upload, $disk = 'private')
    {
        $startTime = microtime(true);

        $pdfPath = Storage::disk($disk)->path($upload->stored_filename);

        Log::info("🔵 BARCODE PDF PROCESSING STARTED", [
            'upload_id' => $upload->id,
            'pdf_path' => $pdfPath,
            'file_exists' => file_exists($pdfPath) ? 'yes' : 'no',
            'file_size' => file_exists($pdfPath) ? filesize($pdfPath) : 0
        ]);

        if (!file_exists($pdfPath)) {
            throw new Exception("PDF file not found: " . $pdfPath);
        }

        if (filesize($pdfPath) === 0) {
            throw new Exception("PDF file is empty: " . $pdfPath);
        }

        // تنظيف المجموعات القديمة
        Group::where('upload_id', $upload->id)->delete();
        Log::info("🧹 Old groups cleaned", ['upload_id' => $upload->id]);

        // الحصول على عدد الصفحات
        $pageCount = $this->getPdfPageCountSimple($pdfPath);
        Log::info("📄 Page count determined", [
            'pages' => $pageCount,
            'upload_id' => $upload->id
        ]);

        if ($pageCount === 0) {
            throw new Exception("PDF file has no pages");
        }

        // ⚡ الكشف عن الباركود الفاصل وتحديد نقاط التقسيم
        $splitPoints = $this->detectSplitPoints($pdfPath, $pageCount);
        Log::info("🎯 Split points detected", [
            'split_points' => $splitPoints,
            'total_points' => count($splitPoints),
            'upload_id' => $upload->id
        ]);

        // تقسيم الصفحات بناءً على نقاط التقسيم
        $sections = $this->splitByBarcode($pageCount, $splitPoints);
        Log::info("📑 Sections created by barcode", [
            'sections_count' => count($sections),
            'upload_id' => $upload->id
        ]);

        // إنشاء المجموعات
        $createdGroups = $this->createGroupsWithBarcode($sections, $pdfPath, $upload);

        $endTime = microtime(true);
        $processingTime = round($endTime - $startTime, 2);

        Log::info("✅ BARCODE PROCESSING COMPLETED", [
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
     * الكشف عن نقاط التقسيم بناءً على الباركود
     */
    private function detectSplitPoints($pdfPath, $pageCount)
    {
        $splitPoints = [];
        $barcodeCache = [];

        Log::info("🔍 Scanning for barcode split points", [
            'total_pages' => $pageCount
        ]);

        // فحص الصفحات للعثور على الباركود الفاصل
        for ($page = 1; $page <= $pageCount; $page++) {
            try {
                $barcode = $this->readPageBarcode($pdfPath, $page);

                if ($barcode) {
                    $barcodeCache[$page] = $barcode;
                    Log::debug("Barcode found", [
                        'page' => $page,
                        'barcode' => $barcode
                    ]);

                    // إذا كان هذا الباركود مختلف عن الصفحة السابقة، فهو نقطة تقسيم
                    if ($page > 1 && isset($barcodeCache[$page - 1]) && $barcode !== $barcodeCache[$page - 1]) {
                        $splitPoints[] = $page;
                        Log::info("🎯 Split point detected", [
                            'page' => $page,
                            'current_barcode' => $barcode,
                            'previous_barcode' => $barcodeCache[$page - 1]
                        ]);
                    }
                }
            } catch (Exception $e) {
                Log::debug("Barcode scan failed for page", [
                    'page' => $page,
                    'error' => $e->getMessage()
                ]);
                // استمرار المسح رغم الخطأ
            }

            // تحديث التقدم كل 10 صفحات
            if ($page % 10 === 0) {
                Log::info("Barcode scan progress", [
                    'scanned_pages' => $page,
                    'total_pages' => $pageCount,
                    'split_points_found' => count($splitPoints)
                ]);
            }
        }

        // إضافة الصفحة الأولى كبداية إذا لم تكن موجودة
        if (!in_array(1, $splitPoints)) {
            array_unshift($splitPoints, 1);
        }

        // إضافة الصفحة الأخيرة كنهاية
        $splitPoints[] = $pageCount + 1;

        Log::info("🎯 Final split points", [
            'split_points' => $splitPoints,
            'total_segments' => count($splitPoints) - 1
        ]);

        return $splitPoints;
    }

    /**
     * تقسيم الصفحات بناءً على نقاط التقسيم
     */
    private function splitByBarcode($pageCount, $splitPoints)
    {
        $sections = [];

        for ($i = 0; $i < count($splitPoints) - 1; $i++) {
            $start = $splitPoints[$i];
            $end = $splitPoints[$i + 1] - 1;

            // إنشاء مجموعة من الصفحات (بدون صفحة الباركود الأولى)
            $pages = range($start + 1, $end); // تخطي صفحة الباركود

            // إذا كانت المجموعة تحتوي على صفحات فعلية
            if (!empty($pages) && $pages[0] <= $pageCount) {
                $sections[] = [
                    'pages' => $pages,
                    'barcode_page' => $start, // صفحة الباركود
                    'section_index' => $i
                ];

                Log::debug("Section created", [
                    'section_index' => $i,
                    'barcode_page' => $start,
                    'content_pages' => $pages,
                    'pages_count' => count($pages)
                ]);
            }
        }

        return $sections;
    }

    /**
     * إنشاء المجموعات مع الباركود
     */
    private function createGroupsWithBarcode($sections, $pdfPath, $upload)
    {
        $createdGroups = [];
        $totalGroupsCreated = 0;
        $totalGroupsFailed = 0;

        Log::info("🛠️ Starting barcode-based group creation", [
            'upload_id' => $upload->id,
            'total_sections' => count($sections)
        ]);

        foreach ($sections as $sectionData) {
            try {
                $pages = $sectionData['pages'];
                $barcodePage = $sectionData['barcode_page'];
                $sectionIndex = $sectionData['section_index'];

                Log::debug("Creating group for barcode section", [
                    'section_index' => $sectionIndex,
                    'barcode_page' => $barcodePage,
                    'content_pages_count' => count($pages),
                    'content_pages' => $pages
                ]);

                // استخراج البيانات من صفحة الباركود للتسمية
                $documentData = $this->extractDocumentData($pdfPath, $barcodePage);

                // إنشاء اسم الملف بناءً على البيانات المستخرجة
                $filename = $this->generateDocumentFilename($documentData, $sectionIndex);
                $filenameSafe = $filename . '.pdf';

                $directory = "groups";
                $fullDir = storage_path("app/private/{$directory}");

                if (!file_exists($fullDir)) {
                    if (!mkdir($fullDir, 0775, true)) {
                        throw new Exception("Failed to create directory: {$fullDir}");
                    }
                }

                $outputPath = "{$fullDir}/{$filenameSafe}";
                $dbPath = "{$directory}/{$filenameSafe}";

                // إنشاء PDF بدون صفحة الباركود
                Log::debug("Creating PDF without barcode page", [
                    'output_path' => $outputPath,
                    'content_pages_count' => count($pages),
                    'barcode_page_excluded' => $barcodePage
                ]);

                if ($this->createPdfSimple($pdfPath, $pages, $outputPath)) {
                    $group = Group::create([
                        'code' => $documentData['code'] ?? 'document_' . ($sectionIndex + 1),
                        'pdf_path' => $dbPath,
                        'pages_count' => count($pages),
                        'user_id' => $upload->user_id,
                        'upload_id' => $upload->id,
                        'document_data' => json_encode($documentData),
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);

                    $createdGroups[] = $group;
                    $totalGroupsCreated++;

                    Log::info("✅ Barcode group created successfully", [
                        'group_id' => $group->id,
                        'upload_id' => $upload->id,
                        'pages_count' => count($pages),
                        'filename' => $filenameSafe,
                        'document_data' => $documentData,
                        'file_size' => file_exists($outputPath) ? filesize($outputPath) : 0
                    ]);
                } else {
                    $totalGroupsFailed++;
                    Log::warning("❌ PDF creation failed for barcode section", [
                        'section_index' => $sectionIndex,
                        'barcode_page' => $barcodePage
                    ]);
                }

            } catch (Exception $e) {
                $totalGroupsFailed++;
                Log::error("❌ Barcode group creation failed", [
                    'section_index' => $sectionIndex,
                    'upload_id' => $upload->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        Log::info("🎯 Barcode group creation summary", [
            'upload_id' => $upload->id,
            'total_groups_created' => $totalGroupsCreated,
            'total_groups_failed' => $totalGroupsFailed,
            'success_rate' => $totalGroupsCreated > 0 ?
                round(($totalGroupsCreated / ($totalGroupsCreated + $totalGroupsFailed)) * 100, 2) : 0
        ]);

        return $createdGroups;
    }

    /**
     * قراءة الباركود من صفحة PDF
     */
    private function readPageBarcode($pdfPath, $page)
    {
        try {
            $imagePath = $this->convertPageToImage($pdfPath, $page);
            if (!$imagePath) {
                return null;
            }

            $barcode = $this->scanBarcodeFromImage($imagePath);

            // تنظيف الصورة المؤقتة
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }

            return $barcode;

        } catch (Exception $e) {
            Log::debug("Barcode reading failed", [
                'page' => $page,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * تحويل صفحة PDF إلى صورة
     */
    private function convertPageToImage($pdfPath, $page)
    {
        $tempDir = '/tmp/pdf_barcode_scan';
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $pngPath = "{$tempDir}/barcode_page_{$page}_" . time() . '_' . rand(1000, 9999) . '.png';

        // استخدام pdftoppm لتحويل الصفحة إلى صورة
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
     * مسح الباركود من الصورة
     */
    private function scanBarcodeFromImage($imagePath)
    {
        if (!file_exists($imagePath)) {
            return null;
        }

        // استخدام zbarimg لقراءة الباركود
        $cmd = sprintf('zbarimg -q --raw %s 2>&1', escapeshellarg($imagePath));
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && !empty($output) && is_array($output)) {
            return trim($output[0]);
        }

        return null;
    }

    /**
     * استخراج بيانات المستند من صفحة الباركود
     */
    private function extractDocumentData($pdfPath, $barcodePage)
    {
        $documentData = [
            'code' => null,
            'type' => 'unknown',
            'number' => null,
            'date' => null,
            'barcode' => null
        ];

        try {
            // قراءة الباركود
            $barcode = $this->readPageBarcode($pdfPath, $barcodePage);
            if ($barcode) {
                $documentData['barcode'] = $barcode;
                $documentData['code'] = $barcode;
            }

            // تحويل صفحة الباركود إلى نص باستخدام OCR
            $text = $this->extractTextFromPage($pdfPath, $barcodePage);
            if ($text) {
                // البحث عن أنماط البيانات في النص
                $documentData = array_merge($documentData, $this->parseDocumentText($text));
            }

            Log::debug("Document data extracted", [
                'barcode_page' => $barcodePage,
                'document_data' => $documentData,
                'text_sample' => substr($text, 0, 100) . '...'
            ]);

        } catch (Exception $e) {
            Log::debug("Document data extraction failed", [
                'barcode_page' => $barcodePage,
                'error' => $e->getMessage()
            ]);
        }

        return $documentData;
    }

    /**
     * استخراج النص من صفحة PDF
     */
    private function extractTextFromPage($pdfPath, $page)
    {
        try {
            // استخدام pdftotext لاستخراج النص
            $tempTextPath = '/tmp/pdf_text_' . time() . '.txt';

            $cmd = sprintf(
                'pdftotext -f %d -l %d -layout %s %s 2>&1',
                intval($page),
                intval($page),
                escapeshellarg($pdfPath),
                escapeshellarg($tempTextPath)
            );

            exec($cmd, $output, $returnVar);

            if ($returnVar === 0 && file_exists($tempTextPath)) {
                $text = file_get_contents($tempTextPath);
                unlink($tempTextPath);
                return $text;
            }

            return null;

        } catch (Exception $e) {
            Log::debug("Text extraction failed", [
                'page' => $page,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * تحليل نص المستند للعثور على البيانات المهمة - النسخة المحسنة
     */
    private function parseDocumentText($text)
    {
        $data = [
            'type' => 'unknown',
            'number' => null,
            'date' => null,
            'additional_info' => []
        ];

        // تنظيف النص وتحسينه
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        Log::debug("Parsing document text", ['text_sample' => substr($text, 0, 200)]);

        // أنماط البحث عن أنواع المستندات
        $patterns = [
            'قيد' => [
                '/(رقم القيد|رقم_القيد|القيد|قيد)[\s:]*([A-Za-z0-9\-_]+)/i',
                '/(قيد)[\s]*([0-9]+)/i'
            ],
            'سند' => [
                '/(رقم السند|رقم_السند|السند|سند)[\s:]*([A-Za-z0-9\-_]+)/i',
                '/(سند)[\s]*([0-9]+)/i'
            ],
            'فاتورة' => [
                '/(رقم الفاتورة|رقم_الفاتورة|الفاتورة|فاتورة)[\s:]*([A-Za-z0-9\-_]+)/i',
                '/(فاتورة)[\s]*([0-9]+)/i',
                '/(invoice|INVOICE)[\s:]*([A-Za-z0-9\-_]+)/i'
            ],
            'عقد' => [
                '/(رقم العقد|رقم_العقد|العقد|عقد)[\s:]*([A-Za-z0-9\-_]+)/i'
            ],
            'شيك' => [
                '/(رقم الشيك|رقم_الشيك|الشيك|شيك)[\s:]*([A-Za-z0-9\-_]+)/i'
            ]
        ];

        // البحث عن نوع المستند ورقمه
        foreach ($patterns as $type => $typePatterns) {
            foreach ($typePatterns as $pattern) {
                if (preg_match($pattern, $text, $matches)) {
                    $data['type'] = $type;
                    $data['number'] = trim($matches[2]);
                    Log::debug("Document type and number found", [
                        'type' => $type,
                        'number' => $data['number'],
                        'pattern' => $pattern
                    ]);
                    break 2;
                }
            }
        }

        // البحث عن التواريخ بأنماط مختلفة
        $datePatterns = [
            '/(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/', // 01/01/2023
            '/(\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2})/',   // 2023/01/01
            '/(\d{1,2}\s*[\-]\s*\d{1,2}\s*[\-]\s*\d{2,4})/', // 01-01-2023
            '/(\d{1,2}\s*[\/]\s*\d{1,2}\s*[\/]\s*\d{2,4})/'  // 01/01/2023
        ];

        foreach ($datePatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $data['date'] = trim($matches[1]);
                Log::debug("Document date found", ['date' => $data['date']]);
                break;
            }
        }

        // البحث عن معلومات إضافية
        $this->extractAdditionalInfo($text, $data);

        return $data;
    }

    /**
     * استخراج معلومات إضافية من نص المستند
     */
    private function extractAdditionalInfo($text, &$data)
    {
        // البحث عن المبالغ
        if (preg_match('/(مبلغ|قيمة|المبلغ|القيمة)[\s:]*([0-9,\.]+)/i', $text, $matches)) {
            $data['additional_info']['amount'] = trim($matches[2]);
        }

        // البحث عن الأسماء
        if (preg_match('/(اسم|الاسم|مقدم|المقدم)[\s:]*([\p{Arabic}a-zA-Z\s]+)/iu', $text, $matches)) {
            $data['additional_info']['name'] = trim($matches[2]);
        }

        // البحث عن الجهة
        if (preg_match('/(جهة|الجهة|مؤسسة|المؤسسة|شركة|الشركة)[\s:]*([\p{Arabic}a-zA-Z\s]+)/iu', $text, $matches)) {
            $data['additional_info']['organization'] = trim($matches[2]);
        }

        // البحث عن الوصف
        if (preg_match('/(وصف|الوصف|بيان|البيان)[\s:]*([\p{Arabic}a-zA-Z0-9\s\-_]+)/iu', $text, $matches)) {
            $data['additional_info']['description'] = trim($matches[2]);
        }
    }

    /**
     * إنشاء اسم ملف بناءً على بيانات المستند
     */
    private function generateDocumentFilename($documentData, $sectionIndex)
    {
        $filenameParts = [];

        // إضافة نوع المستند
        if ($documentData['type'] !== 'unknown') {
            $filenameParts[] = $this->sanitizeFilename($documentData['type']);
        } else {
            $filenameParts[] = 'مستند';
        }

        // إضافة رقم المستند
        if ($documentData['number']) {
            $filenameParts[] = $this->sanitizeFilename($documentData['number']);
        } else {
            $filenameParts[] = ($sectionIndex + 1);
        }

        // إضافة التاريخ إذا موجود
        if ($documentData['date']) {
            $cleanDate = $this->sanitizeFilename($documentData['date']);
            $filenameParts[] = $cleanDate;
        }

        // إضافة الباركود المختصر إذا لم يكن هناك رقم مستند
        if (!$documentData['number'] && $documentData['barcode']) {
            $barcodeShort = substr($documentData['barcode'], 0, 6);
            $filenameParts[] = $barcodeShort;
        }

        $filename = implode('_', $filenameParts) . '_' . time();

        // تنظيف اسم الملف نهائياً
        $filename = $this->sanitizeFilename($filename);
        $filename = substr($filename, 0, 100); // حد أقصى معقول

        Log::debug("Generated document filename", [
            'document_data' => $documentData,
            'filename_parts' => $filenameParts,
            'final_filename' => $filename
        ]);

        return $filename;
    }

    /**
     * تنظيف اسم الملف من الأحرف غير المسموحة
     */
    private function sanitizeFilename($filename)
    {
        // استبدال المساحات والرموز غير المرغوبة
        $filename = preg_replace('/[\/\\\:\*\?"<>\|]/', '_', $filename);
        $filename = preg_replace('/\s+/', '_', $filename);
        $filename = preg_replace('/_{2,}/', '_', $filename);
        $filename = trim($filename, '_');

        return $filename;
    }

    /**
     * إنشاء PDF بسيط
     */
    private function createPdfSimple($pdfPath, $pages, $outputPath)
    {
        try {
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            $pagesString = implode(' ', $pages);

            // استخدام pdftk إذا متوفر
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

            if (!$success && $returnCheck !== 0) {
                $success = $this->fallbackPdfCreation($pdfPath, $pages, $outputPath);
            }

            return $success;

        } catch (Exception $e) {
            Log::error("PDF creation failed", [
                'error' => $e->getMessage(),
                'pages_count' => count($pages)
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
     * عد الصفحات
     */
    private function getPdfPageCountSimple($pdfPath)
    {
        $cmd = 'pdfinfo ' . escapeshellarg($pdfPath) . ' 2>&1';
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0) {
            foreach ($output as $line) {
                if (preg_match('/Pages:\s*(\d+)/i', $line, $matches)) {
                    return (int)$matches[1];
                }
            }
        }

        $cmd = 'qpdf --show-npages ' . escapeshellarg($pdfPath) . ' 2>&1';
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && isset($output[0]) && is_numeric($output[0])) {
            return (int)$output[0];
        }

        return 10;
    }

    /**
     * طريقة طوارئ لاستخراج النص باستخدام tesseract OCR
     */
    private function extractTextWithOCR($imagePath)
    {
        try {
            if (!file_exists($imagePath)) {
                return null;
            }

            $cmd = sprintf(
                'tesseract %s stdout -l ara+eng --psm 6 2>&1',
                escapeshellarg($imagePath)
            );

            exec($cmd, $output, $returnVar);

            if ($returnVar === 0 && !empty($output)) {
                return implode(' ', $output);
            }

            return null;

        } catch (Exception $e) {
            Log::debug("OCR text extraction failed", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * التحقق من توفر الأدوات المطلوبة في النظام
     */
    public function checkDependencies()
    {
        $dependencies = [
            'pdftk' => ['available' => false, 'purpose' => 'PDF manipulation'],
            'ghostscript' => ['available' => false, 'purpose' => 'PDF processing'],
            'pdfinfo' => ['available' => false, 'purpose' => 'PDF info extraction'],
            'qpdf' => ['available' => false, 'purpose' => 'PDF processing'],
            'python3' => ['available' => false, 'purpose' => 'fallback PDF processing'],
            'pdftoppm' => ['available' => false, 'purpose' => 'PDF to image conversion'],
            'zbarimg' => ['available' => false, 'purpose' => 'barcode scanning'],
            'tesseract' => ['available' => false, 'purpose' => 'OCR text extraction'],
            'pdftotext' => ['available' => false, 'purpose' => 'text extraction from PDF']
        ];

        foreach ($dependencies as $tool => &$info) {
            $cmd = "which {$tool} 2>&1";
            exec($cmd, $output, $returnVar);
            $info['available'] = $returnVar === 0;

            // اختبار إضافي للأدوات المهمة
            if ($info['available']) {
                $info['version'] = $this->getToolVersion($tool);
            }
        }

        Log::info("System dependencies check", $dependencies);

        return $dependencies;
    }

    /**
     * الحصول على إصدار الأداة
     */
    private function getToolVersion($tool)
    {
        try {
            $cmd = "{$tool} --version 2>&1 | head -1";
            exec($cmd, $output, $returnVar);

            if ($returnVar === 0 && !empty($output)) {
                return trim($output[0]);
            }

            return 'unknown';
        } catch (Exception $e) {
            return 'error';
        }
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

                    Log::debug("Group file deleted", [
                        'group_id' => $group->id,
                        'file_path' => $group->pdf_path
                    ]);
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

    /**
     * اختبار معالجة PDF مع بيانات تجريبية
     */
    public function testPdfProcessing($testFilePath)
    {
        try {
            if (!file_exists($testFilePath)) {
                throw new Exception("Test file not found: " . $testFilePath);
            }

            $testResults = [
                'file_exists' => file_exists($testFilePath),
                'file_size' => filesize($testFilePath),
                'page_count' => $this->getPdfPageCountSimple($testFilePath),
                'dependencies' => $this->checkDependencies(),
                'barcode_test' => [],
                'text_extraction_test' => null
            ];

            // اختبار قراءة الباركود من الصفحة الأولى
            $testResults['barcode_test']['page_1'] = $this->readPageBarcode($testFilePath, 1);

            // اختبار استخراج النص من الصفحة الأولى
            $testText = $this->extractTextFromPage($testFilePath, 1);
            $testResults['text_extraction_test'] = [
                'success' => !empty($testText),
                'text_sample' => $testText ? substr($testText, 0, 200) . '...' : null,
                'text_length' => $testText ? strlen($testText) : 0
            ];

            // اختبار تقسيم بسيط
            $testResults['split_test'] = $this->simpleSplit($testResults['page_count']);

            Log::info("PDF processing test completed", $testResults);

            return $testResults;

        } catch (Exception $e) {
            Log::error("PDF processing test failed", [
                'error' => $e->getMessage(),
                'test_file' => $testFilePath
            ]);

            return [
                'error' => $e->getMessage(),
                'success' => false
            ];
        }
    }

    /**
     * الحصول على إحصائيات المعالجة
     */
    public function getProcessingStats()
    {
        return [
            'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
            'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
            'processing_time' => microtime(true) - LARAVEL_START,
            'dependencies' => $this->checkDependencies()
        ];
    }

    /**
     * معالجة سريعة بدون باركود (للحالات البسيطة)
     */
    public function processPdfSimple($upload, $disk = 'private')
    {
        $startTime = microtime(true);

        $pdfPath = Storage::disk($disk)->path($upload->stored_filename);
        $pageCount = $this->getPdfPageCountSimple($pdfPath);

        // تقسيم بسيط - كل 5 صفحات مجموعة
        $sections = [];
        $pagesPerSection = 5;

        for ($i = 0; $i < $pageCount; $i += $pagesPerSection) {
            $section = range($i + 1, min($i + $pagesPerSection, $pageCount));
            $sections[] = $section;
        }

        $createdGroups = $this->createGroupsSimple($sections, $pdfPath, $upload);

        $endTime = microtime(true);
        $processingTime = round($endTime - $startTime, 2);

        return [
            'groups' => $createdGroups,
            'total_pages' => $pageCount,
            'sections_count' => count($sections),
            'processing_time_seconds' => $processingTime,
            'method' => 'simple'
        ];
    }

    /**
     * إنشاء مجموعات بسيطة (دعم للطريقة البسيطة)
     */
    private function createGroupsSimple($sections, $pdfPath, $upload)
    {
        $createdGroups = [];
        $totalGroupsCreated = 0;
        $totalGroupsFailed = 0;

        Log::info("🛠️ Starting simple group creation", [
            'upload_id' => $upload->id,
            'total_sections' => count($sections)
        ]);

        foreach ($sections as $index => $pages) {
            try {
                $filename = $this->generateSimpleFilename($upload->original_filename, $index, $pages);
                $filenameSafe = $filename . '.pdf';

                $directory = "groups";
                $fullDir = storage_path("app/private/{$directory}");

                if (!file_exists($fullDir)) {
                    if (!mkdir($fullDir, 0775, true)) {
                        throw new Exception("Failed to create directory: {$fullDir}");
                    }
                }

                $outputPath = "{$fullDir}/{$filenameSafe}";
                $dbPath = "{$directory}/{$filenameSafe}";

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

                    Log::info("✅ Simple group created successfully", [
                        'group_id' => $group->id,
                        'upload_id' => $upload->id,
                        'pages_count' => count($pages),
                        'filename' => $filenameSafe
                    ]);
                } else {
                    $totalGroupsFailed++;
                }

            } catch (Exception $e) {
                $totalGroupsFailed++;
                Log::error("❌ Simple group creation failed", [
                    'section_index' => $index,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $createdGroups;
    }

    /**
     * إنشاء اسم ملف بسيط (دعم للطريقة البسيطة)
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

    /**
     * تقسيم بسيط (دعم للطريقة البسيطة)
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
}
