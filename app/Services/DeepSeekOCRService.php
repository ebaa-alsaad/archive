<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use App\Models\{Upload, Group};
use Exception;

class DeepSeekOCRService
{
    private $apiKey;
    private $apiUrl;

    public function __construct()
    {
        $this->apiKey = env('DEEPSEEK_API_KEY');
        $this->apiUrl = env('DEEPSEEK_OCR_URL', 'https://api.deepseek.com/v1/ocr');
    }

    /**
     * المعالجة الرئيسية باستخدام DeepSeek-OCR
     */
    public function processPdfWithDeepSeekOCR(Upload $upload)
    {
        $pdfPath = Storage::disk('local')->path($upload->stored_filename);
        
        Log::info('Processing PDF', [
            'stored_filename' => $upload->stored_filename,
            'full_path' => $pdfPath,
            'file_exists' => file_exists($pdfPath),
            'storage_path' => storage_path('app/' . $upload->stored_filename)
        ]);

         if (!file_exists($pdfPath)) {
            // حاول بالطريقة البديلة
            $altPath = storage_path('app/' . $upload->stored_filename);
            Log::info('Trying alternative path', ['alt_path' => $altPath, 'exists' => file_exists($altPath)]);
            
            if (!file_exists($altPath)) {
                throw new Exception("PDF file not found. Tried: {$pdfPath} and {$altPath}");
            }
            $pdfPath = $altPath;
        }

        $pageCount = $this->getPdfPageCount($pdfPath);
        Log::info("PDF has {$pageCount} pages");

        $pageToBarcode = [];
        $successfulPages = 0;

        // معالجة كل صفحة
        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            try {
                Log::info("Processing page {$pageNumber}/{$pageCount}");
                
                $barcodeData = $this->processPageWithDeepSeek($pdfPath, $pageNumber);
                
                if ($barcodeData['success'] && $barcodeData['barcode']) {
                    $pageToBarcode[$pageNumber] = $barcodeData['barcode'];
                    $successfulPages++;
                    Log::info("✅ Page {$pageNumber}: Found barcode - {$barcodeData['barcode']}");
                } else {
                    Log::warning("❌ Page {$pageNumber}: No barcode found - {$barcodeData['error']}");
                }

            } catch (Exception $e) {
                Log::error("🚨 Page {$pageNumber} processing failed: " . $e->getMessage());
                continue;
            }
        }

        Log::info("DeepSeek-OCR processing completed. Successful pages: {$successfulPages}/{$pageCount}");

        if (empty($pageToBarcode)) {
            throw new Exception("No barcodes found in the entire PDF document");
        }

        // تجميع الصفحات وإنشاء ملفات PDF
        return $this->createGroupedPdfFiles($pageToBarcode, $pdfPath, $upload);
    }

    /**
     * معالجة صفحة واحدة مع DeepSeek-OCR
     */
    private function processPageWithDeepSeek($pdfPath, $pageNumber)
    {
        // تحويل الصفحة إلى صورة
        $imagePath = $this->convertPdfPageToImage($pdfPath, $pageNumber);
        if (!$imagePath) {
            return ['success' => false, 'error' => 'Failed to convert page to image'];
        }

        try {
            // استدعاء DeepSeek-OCR API
            $ocrResult = $this->callDeepSeekOCRAPI($imagePath);
            
            // تنظيف الصورة المؤقتة
            @unlink($imagePath);

            if (!$ocrResult['success']) {
                return ['success' => false, 'error' => $ocrResult['error']];
            }

            // استخراج الباركود من النتيجة
            $barcode = $this->extractBarcodeFromOCRText($ocrResult['text']);
            
            if ($barcode) {
                return [
                    'success' => true,
                    'barcode' => $barcode,
                    'full_text' => $ocrResult['text']
                ];
            }

            return ['success' => false, 'error' => 'No valid barcode pattern found'];

        } catch (Exception $e) {
            @unlink($imagePath);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * استدعاء DeepSeek-OCR API
     */
    private function callDeepSeekOCRAPI($imagePath)
    {
        if (empty($this->apiKey)) {
            throw new Exception("DeepSeek API key is not configured");
        }

        $imageContent = file_get_contents($imagePath);
        if (!$imageContent) {
            throw new Exception("Failed to read image file");
        }

        $base64Image = base64_encode($imageContent);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(60)->post($this->apiUrl, [
            'image' => $base64Image,
            'model' => 'deepseek-ocr',
            'options' => [
                'detect_barcodes' => true,
                'extract_text' => true,
                'language' => 'ar+en',
                'enable_confidence' => true
            ]
        ]);

        if ($response->failed()) {
            $statusCode = $response->status();
            $errorBody = $response->body();
            throw new Exception("API request failed with status {$statusCode}: {$errorBody}");
        }

        $data = $response->json();

        if (!isset($data['text_blocks']) || empty($data['text_blocks'])) {
            return ['success' => false, 'error' => 'No text blocks found in OCR result'];
        }

        // تجميع النص من جميع الكتل
        $fullText = '';
        foreach ($data['text_blocks'] as $block) {
            if (isset($block['text'])) {
                $fullText .= $block['text'] . ' ';
            }
        }

        return [
            'success' => true,
            'text' => trim($fullText),
            'raw_data' => $data
        ];
    }

    /**
     * استخراج الباركود من النص
     */
    private function extractBarcodeFromOCRText($text)
    {
        if (empty($text)) {
            return null;
        }

        Log::info("OCR Extracted Text: " . substr($text, 0, 200) . "...");

        // أنماط الباركود الشائعة
        $barcodePatterns = [
            // أرقام فقط (8-20 رقم)
            '/\b\d{8,20}\b/',
            
            // حروف وأرقام (8-20 حرف)
            '/\b[A-Z0-9]{8,20}\b/',
            
            // باركود مع prefixes
            '/BARCODE[\-\s:]?\s*([A-Z0-9]{8,20})/i',
            '/CODE[\-\s:]?\s*([A-Z0-9]{8,20})/i',
            '/ID[\-\s:]?\s*([A-Z0-9]{8,20})/i',
            
            // بالعربية
            '/رقم[\-\s:]?\s*([A-Z0-9]{8,20})/i',
            '/كود[\-\s:]?\s*([A-Z0-9]{8,20})/i',
            '/رمز[\-\s:]?\s*([A-Z0-9]{8,20})/i',
            
            // أنماط خاصة
            '/[A-Z0-9]{4}[\-\s]?[A-Z0-9]{4}[\-\s]?[A-Z0-9]{4}[\-\s]?[A-Z0-9]{4}/',
        ];

        foreach ($barcodePatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $potentialBarcode = trim(end($matches));
                
                if ($this->isValidBarcode($potentialBarcode)) {
                    Log::info("✅ Valid barcode found: {$potentialBarcode} with pattern: {$pattern}");
                    return $potentialBarcode;
                }
            }
        }

        return null;
    }

    /**
     * التحقق من صحة الباركود
     */
    private function isValidBarcode($barcode)
    {
        if (empty($barcode)) {
            return false;
        }

        $length = strlen($barcode);
        
        // الطول المناسب للباركود
        if ($length < 8 || $length > 20) {
            return false;
        }

        // تجنب الأرقام المتسلسلة
        if (preg_match('/^(\d)\1+$/', $barcode)) {
            return false;
        }

        // تجنب التواريخ
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $barcode) ||
            preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $barcode)) {
            return false;
        }

        // يجب أن يحتوي على أرقام أو حروف فقط
        if (!preg_match('/^[A-Z0-9]+$/', $barcode)) {
            return false;
        }

        return true;
    }

    /**
     * تحويل صفحة PDF إلى صورة
     */
    private function convertPdfPageToImage($pdfPath, $pageNumber)
    {
        $tempBase = storage_path("app/temp/deepseek_page_{$pageNumber}_" . time());
        $tempPath = "{$tempBase}-1.png";

        // إنشاء مجلد temp إذا لم يكن موجوداً
        $tempDir = storage_path("app/temp");
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $command = "pdftoppm -f {$pageNumber} -l {$pageNumber} -png -r 300 -aa yes " .
                  escapeshellarg($pdfPath) . " " . escapeshellarg($tempBase);

        $output = shell_exec($command . " 2>&1");

        if (!file_exists($tempPath)) {
            Log::error("Failed to convert page {$pageNumber} to image. Command: {$command}, Output: {$output}");
            return null;
        }

        return $tempPath;
    }

    /**
     * الحصول على عدد صفحات PDF
     */
    private function getPdfPageCount($pdfPath)
    {
        $pdf = new Fpdi();
        return $pdf->setSourceFile($pdfPath);
    }

    /**
     * إنشاء ملفات PDF مجمعة
     */
    private function createGroupedPdfFiles($pageToBarcode, $originalPdfPath, Upload $upload)
    {
        $barcodeGroups = [];

        // تجميع الصفحات حسب الباركود
        foreach ($pageToBarcode as $pageNumber => $barcode) {
            if (!isset($barcodeGroups[$barcode])) {
                $barcodeGroups[$barcode] = [];
            }
            $barcodeGroups[$barcode][] = $pageNumber;
        }

        $createdGroups = [];

        foreach ($barcodeGroups as $barcode => $pages) {
            try {
                $group = $this->createPdfFromPages($pages, $barcode, $originalPdfPath, $upload);
                $createdGroups[] = $group;
                Log::info("✅ Created PDF group for barcode '{$barcode}' with " . count($pages) . " pages");
            } catch (Exception $e) {
                Log::error("❌ Failed to create PDF group for barcode '{$barcode}': " . $e->getMessage());
            }
        }

        return $createdGroups;
    }

    /**
     * إنشاء PDF من صفحات محددة
     */
    private function createPdfFromPages($pages, $barcode, $originalPdfPath, Upload $upload)
    {
        $pdf = new Fpdi();
        $pdf->setSourceFile($originalPdfPath);

        foreach ($pages as $pageNumber) {
            $templateId = $pdf->importPage($pageNumber);
            $pdf->AddPage();
            $pdf->useTemplate($templateId);
        }

        $filename = "barcode_{$barcode}_" . time() . ".pdf";
        $directory = "groups";
        $fullPath = storage_path("app/{$directory}");

        if (!file_exists($fullPath)) {
            mkdir($fullPath, 0755, true);
        }

        $outputPath = "{$fullPath}/{$filename}";
        $pdf->Output($outputPath, 'F');

        // حفظ في قاعدة البيانات
        $group = Group::create([
            'code' => $barcode,
            'pdf_path' => "{$directory}/{$filename}",
            'pages_count' => count($pages),
            'user_id' => $upload->user_id,
            'upload_id' => $upload->id
        ]);

        return $group;
    }

    /**
     * فحص حالة API
     */
    public function checkAPIStatus()
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(10)->get($this->apiUrl . '/status');

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'message' => $response->successful() ? 'API is operational' : 'API is not responding'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}