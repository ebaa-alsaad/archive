<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Storage;

class BarcodeOCRService
{
    public function processPdf($pdfPath, $upload)
    {
        $workDir = storage_path("app/tmp/{$upload->id}");
        if (!is_dir($workDir)) mkdir($workDir, 0777, true);

        $pageCount = $this->getPageCount($pdfPath);

        for ($i = 1; $i <= $pageCount; $i++) {

            $singlePage = "$workDir/page_$i.pdf";

            // استخراج الصفحة
            shell_exec("qpdf '$pdfPath' --pages '$pdfPath' $i-$i -- '$singlePage'");

            // استخراج صورة الصفحة
            shell_exec("pdfimages -png '$singlePage' '$workDir/page_$i'");

            $png = "$workDir/page_{$i}-000.png";
            if (!file_exists($png)) continue;

            // قراءة الباركود إن وجد
            $barcode = trim(shell_exec("zxing '$png' 2>/dev/null"));

            // OCR fallback
            if (!$barcode) {
                $text = $this->runOcr($png);
            } else {
                $text = $barcode . "\n" . $this->runOcr($png);
            }

            // 🔍 استخراج البيانات الهامة
            $extracted = $this->extractImportantData($text);

            Storage::disk('local')->put("results/{$upload->id}/page_$i.json", json_encode([
                'page'      => $i,
                'barcode'   => $barcode,
                'text'      => $text,
                'number'    => $extracted['number'] ?? null,
                'entry_no'  => $extracted['entry_no'] ?? null,
                'invoice'   => $extracted['invoice'] ?? null,
                'date'      => $extracted['date'] ?? null,
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    private function runOcr($image)
    {
        $out = $image . "_ocr";
        shell_exec("tesseract '$image' '$out' -l ara+eng --oem 1 --psm 6");
        return file_exists("$out.txt") ? file_get_contents("$out.txt") : "";
    }

    /**
     * ⭐ استخراج رقم القيد / رقم السند / التاريخ
     */
    private function extractImportantData($text)
    {
        $clean = trim(str_replace(["\r", "\n"], " ", $text));

        $data = [];

        // رقم القيد Entry No
        if (preg_match('/(?:رقم القيد|Entry No|Entry)\s*[:\-]?\s*(\d{2,10})/iu', $clean, $m))
            $data['entry_no'] = $m[1];

        // رقم السند Voucher / سند
        if (preg_match('/(?:رقم السند|Voucher No|Voucher)\s*[:\-]?\s*(\d{2,12})/iu', $clean, $m))
            $data['number'] = $m[1];

        // رقم الفاتورة Invoice
        if (preg_match('/(?:فاتورة|Invoice No|Invoice)\s*[:\-]?\s*(\d{2,12})/iu', $clean, $m))
            $data['invoice'] = $m[1];

        // التاريخ (يدعم عدة صيغ)
        if (preg_match('/(\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2})/u', $clean, $m)) {
            $data['date'] = $m[1];
        }
        else if (preg_match('/(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})/u', $clean, $m)) {
            $data['date'] = $m[1];
        }

        return $data;
    }

    private function getPageCount($pdf)
    {
        $pages = shell_exec("pdfinfo '$pdf' | grep Pages");
        return intval(preg_replace('/\D/', '', $pages));
    }
}
