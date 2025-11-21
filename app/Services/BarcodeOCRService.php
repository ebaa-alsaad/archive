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

            // استخراج صفحة واحدة باستخدام qpdf (أسرع بكثير)
            shell_exec("qpdf '$pdfPath' --pages '$pdfPath' $i-$i -- '$singlePage'");

            // استخراج صورة الصفحة بسرعة باستخدام pdfimages
            shell_exec("pdfimages -png '$singlePage' '$workDir/page_$i'");

            $png = "$workDir/page_{$i}-000.png";
            if (!file_exists($png)) continue;

            // قراءة الباركود باستخدام ZXing
            $barcode = trim(shell_exec("zxing '$png' 2>/dev/null"));

            if (!$barcode) {
                // fallback OCR فقط عند الحاجة
                $text = $this->runOcr($png);
            } else {
                $text = $barcode;
            }

            // مثال استخراج رقم السند
            preg_match('/(\d{3,10})/', $text, $m);
            $number = $m[1] ?? null;

            Storage::disk('local')->put("results/{$upload->id}/page_$i.json", json_encode([
                'page' => $i,
                'barcode' => $barcode,
                'text' => $text,
                'number' => $number
            ]));
        }
    }

    private function runOcr($image)
    {
        $out = $image . "_ocr";
        shell_exec("tesseract '$image' '$out' -l ara --oem 1 --psm 6");
        return file_get_contents("$out.txt") ?: "";
    }

    private function getPageCount($pdf)
    {
        $pages = shell_exec("pdfinfo '$pdf' | grep Pages");
        return intval(preg_replace('/\D/', '', $pages));
    }
}
