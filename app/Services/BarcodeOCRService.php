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
    private $pageCountCache = [];

    /**
     * معالجة PDF فائقة السرعة - النسخة المحسنة
     */
    public function processPdfUltraFast($upload, $pdfPath)
    {
        $startTime = microtime(true);
        Log::info("🚀 STARTING ENHANCED ULTRA FAST PDF PROCESSING", [
            'upload_id' => $upload->id,
            'pdf_path' => $pdfPath,
            'file_size' => file_exists($pdfPath) ? filesize($pdfPath) : 0
        ]);

        // ⚡ إعدادات واقعية بناءً على مساحة tmpfs
        set_time_limit(180); // 3 دقائق واقعية
        ini_set('memory_limit', '1024M'); // 1GB واقعي

        if (!file_exists($pdfPath)) {
            throw new Exception("PDF file not found: " . $pdfPath);
        }

        // ⚡ تنظيف سريع للمجموعات القديمة
        Group::where('upload_id', $upload->id)->delete();

        // ⚡ الحصول على عدد الصفحات بسرعة قصوى مع cache
        $pageCount = $this->getPdfPageCountEnhanced($pdfPath);
        Log::info("Page count determined", [
            'pages' => $pageCount,
            'upload_id' => $upload->id,
            'method' => 'enhanced'
        ]);

        if ($pageCount === 0) {
            throw new Exception("PDF file has no pages");
        }

        // ⚡ استراتيجية ذكية للكشف عن الباركود الفاصل
        $separatorBarcode = $this->findSeparatorBarcodeSmart($pdfPath, $pageCount);

        Log::info("Separator barcode determined", [
            'barcode' => $separatorBarcode,
            'upload_id' => $upload->id,
            'method' => 'smart_detection'
        ]);

        // ⚡ تقسيم ذكي وفائق السرعة
        $sections = $this->smartFastSplit($pdfPath, $pageCount, $separatorBarcode);

        Log::info("Sections split completed", [
            'sections_count' => count($sections),
            'upload_id' => $upload->id,
            'total_pages' => $pageCount
        ]);

        // ⚡ إنشاء المجموعات بسرعة قصوى مع معالجة متوازية
        $createdGroups = $this->createGroupsEnhanced($sections, $pdfPath, $upload, $separatorBarcode);

        $endTime = microtime(true);
        $processingTime = round($endTime - $startTime, 2);

        Log::info("🎉 ENHANCED ULTRA FAST PROCESSING COMPLETED", [
            'upload_id' => $upload->id,
            'processing_time_seconds' => $processingTime,
            'groups_created' => count($createdGroups),
            'total_pages' => $pageCount,
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
     * كشف ذكي عن الباركود الفاصل
     */
    private function findSeparatorBarcodeSmart($pdfPath, $pageCount)
    {
        // ⚡ استراتيجية عينات ذكية
        $samplePages = $this->getSmartSamplePages($pageCount);

        $barcodes = [];
        $barcodeFrequency = [];

        foreach ($samplePages as $page) {
            $barcode = $this->readPageBarcodeEnhanced($pdfPath, $page);

            if ($barcode) {
                $barcodes[] = $barcode;
                $barcodeFrequency[$barcode] = ($barcodeFrequency[$barcode] ?? 0) + 1;
            }
        }

        // ⚡ اختيار الباركود الأكثر تكراراً كفاصل
        if (!empty($barcodeFrequency)) {
            arsort($barcodeFrequency);
            $mostFrequent = array_key_first($barcodeFrequency);

            // تأكيد من عينات إضافية
            $confirmationSamples = $this->getConfirmationPages($pageCount, array_keys($barcodeFrequency));
            $confirmed = $this->confirmSeparatorBarcode($pdfPath, $mostFrequent, $confirmationSamples);

            if ($confirmed) {
                return $mostFrequent;
            }
        }

        // ⚡ استخدام باركود افتراضي ذكي
        return 'separator_' . md5_file($pdfPath) . '_' . time();
    }

    /**
     * الحصول على عينات ذكية من الصفحات
     */
    private function getSmartSamplePages($pageCount)
    {
        $samples = [];

        // ⚡ دائماً الصفحة الأولى
        $samples[] = 1;

        // ⚡ الصفحة الأخيرة
        if ($pageCount > 1) {
            $samples[] = $pageCount;
        }

        // ⚡ عينات منتصف ذكية
        if ($pageCount > 5) {
            $midPoint = ceil($pageCount / 2);
            $samples[] = $midPoint;

            // عينات ربعية إضافية
            $quarter = ceil($pageCount / 4);
            $samples[] = $quarter;
            $samples[] = $pageCount - $quarter;
        }

        // ⚡ عينات عشوائية إضافية للوثائق الكبيرة
        if ($pageCount > 20) {
            $randomSamples = max(2, ceil($pageCount * 0.05)); // 5% عينات عشوائية
            for ($i = 0; $i < $randomSamples; $i++) {
                $randomPage = rand(2, $pageCount - 1);
                if (!in_array($randomPage, $samples)) {
                    $samples[] = $randomPage;
                }
            }
        }

        return array_unique($samples);
    }

    /**
     * الحصول على صفحات تأكيد إضافية
     */
    private function getConfirmationPages($pageCount, $existingBarcodes)
    {
        $pages = [];
        $maxSamples = min(5, ceil($pageCount * 0.1)); // 10% كحد أقصى

        for ($i = 0; $i < $maxSamples; $i++) {
            $page = rand(2, $pageCount - 1);
            if (!in_array($page, $pages)) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    /**
     * تأكيد الباركود الفاصل
     */
    private function confirmSeparatorBarcode($pdfPath, $barcode, $confirmationPages)
    {
        $matches = 0;
        $totalChecked = 0;

        foreach ($confirmationPages as $page) {
            $detectedBarcode = $this->readPageBarcodeEnhanced($pdfPath, $page);
            $totalChecked++;

            if ($detectedBarcode === $barcode) {
                $matches++;
            }

            // ⚡ توقف مبكر إذا كان التأكيد كافياً
            if ($matches >= 2 && $totalChecked >= 3) {
                break;
            }
        }

        // ⚡ نسبة تأكيد 40% كافية
        return $matches > 0 && ($matches / $totalChecked) >= 0.4;
    }

    /**
     * تقسيم ذكي وفائق السرعة
     */
    private function smartFastSplit($pdfPath, $pageCount, $separatorBarcode)
    {
        $sections = [];
        $currentSection = [];
        $lastBarcodePage = 0;

        // ⚡ استراتيجية تقسيم متقدمة
        $checkInterval = $this->calculateOptimalCheckInterval($pageCount);

        for ($page = 1; $page <= $pageCount; $page++) {
            $currentSection[] = $page;

            // ⚡ فحص الباركود في فترات مثالية
            $shouldCheck = $page === 1 ||
                          $page === $pageCount ||
                          ($page - $lastBarcodePage) >= $checkInterval ||
                          $page % $checkInterval === 0;

            if ($shouldCheck) {
                $barcode = $this->readPageBarcodeEnhanced($pdfPath, $page);

                if ($barcode === $separatorBarcode) {
                    $lastBarcodePage = $page;

                    // ⚡ إنشاء قسم جديد مع الاحتفاظ بالصفحة الحالية
                    if (count($currentSection) > 1) {
                        $sections[] = $currentSection;
                        $currentSection = [$page];
                    }
                }
            }
        }

        // إضافة القسم الأخير
        if (!empty($currentSection)) {
            $sections[] = $currentSection;
        }

        // ⚡ دمج الأقسام الصغيرة جداً
        return $this->mergeSmallSections($sections, $pageCount);
    }

    /**
     * حساب الفترة المثالية للفحص
     */
    private function calculateOptimalCheckInterval($pageCount)
    {
        if ($pageCount <= 10) return 2;
        if ($pageCount <= 50) return 5;
        if ($pageCount <= 100) return 10;
        if ($pageCount <= 500) return 20;
        return 30; // للوثائق الكبيرة جداً
    }

    /**
     * دمج الأقسام الصغيرة
     */
    private function mergeSmallSections($sections, $totalPages)
    {
        $mergedSections = [];
        $currentMerge = [];

        $maxSectionSize = max(10, ceil($totalPages * 0.1)); // 10% كحد أقصى للقسم

        foreach ($sections as $section) {
            if (count($currentMerge) + count($section) <= $maxSectionSize) {
                $currentMerge = array_merge($currentMerge, $section);
            } else {
                if (!empty($currentMerge)) {
                    $mergedSections[] = $currentMerge;
                }
                $currentMerge = $section;
            }
        }

        if (!empty($currentMerge)) {
            $mergedSections[] = $currentMerge;
        }

        return $mergedSections;
    }

    /**
     * قراءة باركود محسنة مع cache
     */
    private function readPageBarcodeEnhanced($pdfPath, $page)
    {
        $cacheKey = 'barcode_' . md5($pdfPath) . '_page_' . $page;

        // ⚡ محاولة الاسترجاع من cache
        if (isset($this->barcodeCache[$cacheKey])) {
            return $this->barcodeCache[$cacheKey];
        }

        // ⚡ cache في Redis للجلسة (لمدة 10 دقائق)
        $redisKey = 'barcode_scan_' . $cacheKey;
        $cachedBarcode = Redis::get($redisKey);

        if ($cachedBarcode !== null) {
            $this->barcodeCache[$cacheKey] = $cachedBarcode;
            return $cachedBarcode;
        }

        try {
            $imagePath = $this->convertToImageEnhanced($pdfPath, $page);
            if (!$imagePath) {
                return $this->cacheBarcodeResult($cacheKey, $redisKey, null);
            }

            $barcode = $this->scanBarcodeEnhanced($imagePath);

            // تنظيف الصورة المؤقتة فوراً
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }

            return $this->cacheBarcodeResult($cacheKey, $redisKey, $barcode);

        } catch (Exception $e) {
            Log::debug("Enhanced barcode reading failed", [
                'page' => $page,
                'error' => $e->getMessage()
            ]);
            return $this->cacheBarcodeResult($cacheKey, $redisKey, null);
        }
    }

    /**
     * cache نتائج الباركود
     */
    private function cacheBarcodeResult($memoryKey, $redisKey, $value)
    {
        $this->barcodeCache[$memoryKey] = $value;

        if ($value !== null) {
            Redis::setex($redisKey, 600, $value); // 10 دقائق
        } else {
            Redis::setex($redisKey, 300, 'null'); // 5 دقائق للقيم الفارغة
        }

        return $value;
    }

    /**
     * تحويل محسن إلى صورة
     */
    private function convertToImageEnhanced($pdfPath, $page)
    {
        $tempDir = '/tmp/pdf_images'; // استخدام tmpfs للسرعة
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $pngPath = "{$tempDir}/enhanced_page_{$page}_" . time() . '_' . rand(1000, 9999) . '.png';

        // ⚡ إعدادات متوازنة بين السرعة والجودة
        $resolution = $this->getOptimalResolution($pdfPath);

        $cmd = sprintf(
            'pdftoppm -f %d -l %d -png -singlefile -r %d -aa yes -aaVector yes %s %s 2>&1',
            intval($page),
            intval($page),
            $resolution,
            escapeshellarg($pdfPath),
            escapeshellarg(str_replace('.png', '', $pngPath))
        );

        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && file_exists($pngPath)) {
            return $pngPath;
        }

        // ⚡ محاولة بديلة
        return $this->convertToImageFallback($pdfPath, $page, $tempDir);
    }

    /**
     * الحصول على الدقة المثالية
     */
    private function getOptimalResolution($pdfPath)
    {
        $fileSize = file_exists($pdfPath) ? filesize($pdfPath) : 0;

        if ($fileSize > 50 * 1024 * 1024) { // ملفات كبيرة جداً
            return 100;
        } elseif ($fileSize > 10 * 1024 * 1024) { // ملفات كبيرة
            return 120;
        } else { // ملفات عادية
            return 150;
        }
    }

    /**
     * بديل لتحويل الصور
     */
    private function convertToImageFallback($pdfPath, $page, $tempDir)
    {
        $pngPath = "{$tempDir}/fallback_page_{$page}_" . time() . '.png';

        // ⚡ استخدام ImageMagick كبديل
        $cmd = sprintf(
            'convert -density 150 -quality 85 -alpha remove %s[%d] %s 2>&1',
            escapeshellarg($pdfPath),
            $page - 1, // ImageMagick يبدأ من 0
            escapeshellarg($pngPath)
        );

        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && file_exists($pngPath)) {
            return $pngPath;
        }

        return null;
    }

    /**
     * مسح باركود محسن
     */
    private function scanBarcodeEnhanced($imagePath)
    {
        if (!file_exists($imagePath)) {
            return null;
        }

        // ⚡ محاولة متعددة بمعالجات مختلفة
        $barcode = $this->scanWithZbar($imagePath);

        if (!$barcode) {
            $barcode = $this->scanWithOpenCV($imagePath);
        }

        if (!$barcode) {
            $barcode = $this->scanWithSimpleOCR($imagePath);
        }

        return $barcode;
    }

    /**
     * المسح باستخدام zbarimg
     */
    private function scanWithZbar($imagePath)
    {
        $cmd = sprintf('zbarimg -q --raw %s 2>&1', escapeshellarg($imagePath));
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && !empty($output) && is_array($output)) {
            return trim($output[0]);
        }

        return null;
    }

    /**
     * المسح باستخدام OpenCV (إذا متوفر)
     */
    private function scanWithOpenCV($imagePath)
    {
        $cmd = sprintf('python3 -c "
import cv2
import sys
img = cv2.imread(\"%s\")
detector = cv2.QRCodeDetector()
data, bbox, _ = detector.detectAndDecode(img)
if data:
    print(data)
    sys.exit(0)
sys.exit(1)
" 2>&1', $imagePath);

        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && !empty($output)) {
            return trim($output[0]);
        }

        return null;
    }

    /**
     * مسح OCR بسيط للباركود النصي
     */
    private function scanWithSimpleOCR($imagePath)
    {
        // ⚡ محاولة بسيطة باستخدام tesseract للباركود النصي
        $cmd = sprintf('tesseract %s stdout --psm 8 -c tessedit_char_whitelist=0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ 2>&1',
                      escapeshellarg($imagePath));
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && !empty($output)) {
            $text = trim(implode('', $output));
            // ⚡ تصفية النتائج التي تشبه الباركود
            if (preg_match('/^[A-Z0-9]{8,20}$/', $text)) {
                return $text;
            }
        }

        return null;
    }

    /**
     * إنشاء مجموعات محسنة
     */
    private function createGroupsEnhanced($sections, $pdfPath, $upload, $separatorBarcode)
    {
        $createdGroups = [];
        $totalSections = count($sections);

        foreach ($sections as $index => $pages) {
            if (empty($pages)) continue;

            try {
                // ⚡ اسم ملف محسن
                $filename = $this->generateEnhancedFilename($upload->original_filename, $index, $separatorBarcode, $pages);
                $filenameSafe = $filename . '.pdf';

                $directory = "groups";
                $fullDir = storage_path("app/private/{$directory}");
                if (!file_exists($fullDir)) {
                    mkdir($fullDir, 0775, true);
                }

                $outputPath = "{$fullDir}/{$filenameSafe}";
                $dbPath = "{$directory}/{$filenameSafe}";

                // ⚡ إنشاء PDF محسن
                if ($this->createPdfEnhanced($pdfPath, $pages, $outputPath)) {
                    $group = Group::create([
                        'code' => $separatorBarcode . '_' . ($index + 1),
                        'pdf_path' => $dbPath,
                        'pages_count' => count($pages),
                        'user_id' => $upload->user_id,
                        'upload_id' => $upload->id,
                        'section_index' => $index + 1
                    ]);

                    $createdGroups[] = $group;

                    Log::debug("Enhanced group created", [
                        'group_id' => $group->id,
                        'pages_count' => count($pages),
                        'filename' => $filenameSafe,
                        'section_index' => $index + 1
                    ]);
                }

            } catch (Exception $e) {
                Log::error("Enhanced group creation failed", [
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
     * إنشاء اسم ملف محسن
     */
    private function generateEnhancedFilename($originalFilename, $index, $barcode, $pages)
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalFilename, PATHINFO_FILENAME));
        $safeName = substr($safeName, 0, 30);

        $pageRange = count($pages) > 1 ?
            'pages_' . min($pages) . '_' . max($pages) :
            'page_' . $pages[0];

        return $safeName . '_' . ($index + 1) . '_' . substr(md5($barcode), 0, 6) . '_' . $pageRange . '_' . time();
    }

    /**
     * إنشاء PDF محسن
     */
    private function createPdfEnhanced($pdfPath, $pages, $outputPath)
    {
        try {
            $outputDir = dirname($outputPath);
            if (!file_exists($outputDir)) {
                mkdir($outputDir, 0775, true);
            }

            // ⚡ محاولة مع Ghostscript بإعدادات محسنة
            $success = $this->createWithGhostscriptEnhanced($pdfPath, $pages, $outputPath);

            if (!$success) {
                // ⚡ محاولة مع pdftk إذا متوفر
                $success = $this->createWithPdftk($pdfPath, $pages, $outputPath);
            }

            if (!$success) {
                // ⚡ طريقة طوارئ بسيطة
                $success = $this->createWithPdfUnite($pdfPath, $pages, $outputPath);
            }

            return $success && file_exists($outputPath) && filesize($outputPath) > 500; // حد أدنى واقعي

        } catch (Exception $e) {
            Log::error("Enhanced PDF creation exception", [
                'error' => $e->getMessage(),
                'pages_count' => count($pages),
                'output_path' => $outputPath
            ]);
            return false;
        }
    }

    /**
     * إنشاء PDF باستخدام Ghostscript محسن
     */
    private function createWithGhostscriptEnhanced($pdfPath, $pages, $outputPath)
    {
        $pageList = implode(' ', array_map(function($page) {
            return "-dPageList=" . $page;
        }, $pages));

        $cmd = sprintf(
            'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite ' .
            '-dCompatibilityLevel=1.4 -dPDFSETTINGS=/ebook ' . // ⚡ إعدادات متوازنة
            '-dEmbedAllFonts=true -dSubsetFonts=true ' .       // ⚡ تضمين الخطوط المهمة فقط
            '-dCompressPages=true -dUseCIEColor=false ' .      // ⚡ ضغط متوازن
            '-dAutoRotatePages=/None ' .                       // ⚡ منع التدوير التلقائي
            '%s -sOutputFile=%s %s 2>&1',
            $pageList,
            escapeshellarg($outputPath),
            escapeshellarg($pdfPath)
        );

        exec($cmd, $output, $returnVar);

        return $returnVar === 0;
    }

    /**
     * إنشاء PDF باستخدام pdftk
     */
    private function createWithPdftk($pdfPath, $pages, $outputPath)
    {
        $cmdCheck = 'which pdftk 2>&1';
        exec($cmdCheck, $outputCheck, $returnCheck);

        if ($returnCheck !== 0) {
            return false;
        }

        $pagesString = implode(' ', $pages);
        $cmd = sprintf(
            'pdftk %s cat %s output %s 2>&1',
            escapeshellarg($pdfPath),
            $pagesString,
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $returnVar);
        return $returnVar === 0;
    }

    /**
     * إنشاء PDF باستخدام pdfunite
     */
    private function createWithPdfUnite($pdfPath, $pages, $outputPath)
    {
        $tempFiles = [];
        $tempDir = '/tmp/pdf_split_' . time();

        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        try {
            // ⚡ استخراج الصفحات الفردية أولاً
            foreach ($pages as $page) {
                $tempFile = "{$tempDir}/page_{$page}.pdf";
                $cmd = sprintf(
                    'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dFirstPage=%d -dLastPage=%d -sOutputFile=%s %s 2>&1',
                    $page,
                    $page,
                    escapeshellarg($tempFile),
                    escapeshellarg($pdfPath)
                );

                exec($cmd, $output, $returnVar);
                if ($returnVar === 0 && file_exists($tempFile)) {
                    $tempFiles[] = $tempFile;
                }
            }

            if (count($tempFiles) === count($pages)) {
                // ⚡ دمج الصفحات
                $cmd = sprintf(
                    'pdfunite %s %s 2>&1',
                    implode(' ', array_map('escapeshellarg', $tempFiles)),
                    escapeshellarg($outputPath)
                );

                exec($cmd, $output, $returnVar);
                return $returnVar === 0;
            }

            return false;
        } finally {
            // ⚡ تنظيف الملفات المؤقتة
            foreach ($tempFiles as $tempFile) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
            if (file_exists($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    /**
     * عد الصفحات محسن مع cache
     */
    private function getPdfPageCountEnhanced($pdfPath)
    {
        $cacheKey = 'pagecount_' . md5_file($pdfPath);

        if (isset($this->pageCountCache[$cacheKey])) {
            return $this->pageCountCache[$cacheKey];
        }

        // ⚡ cache في Redis
        $redisKey = 'pdf_pagecount_' . $cacheKey;
        $cachedCount = Redis::get($redisKey);

        if ($cachedCount !== null) {
            $count = (int)$cachedCount;
            $this->pageCountCache[$cacheKey] = $count;
            return $count;
        }

        $count = $this->getPdfPageCountUltraFast($pdfPath);

        // ⚡ حفظ في cache
        $this->pageCountCache[$cacheKey] = $count;
        Redis::setex($redisKey, 3600, $count); // ساعة واحدة

        return $count;
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
        return $this->getPdfPageCountEnhanced($pdfPath);
    }

    /**
     * الحفاظ على الدالة الأصلية للتوافق
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

        // ⚡ طريقة الطوارئ
        $cmd = 'strings ' . escapeshellarg($pdfPath) . ' | grep -c "/Page" | head -1';
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && isset($output[0]) && is_numeric($output[0])) {
            return max(1, (int)$output[0]);
        }

        throw new Exception("Cannot determine page count");
    }
}
