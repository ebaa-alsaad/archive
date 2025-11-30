<?php

namespace App\Jobs;

use App\Models\Upload;
use App\Services\BarcodeOCRService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ProcessPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uploadId;

    public function __construct(int $uploadId)
    {
        $this->uploadId = $uploadId;
    }

    public function handle(BarcodeOCRService $barcodeService)
    {
        $upload = Upload::find($this->uploadId);
        if (!$upload) return;

        try {
            $upload->update(['status' => 'processing']);

            // المسار الكامل للملف
            $filePath = $upload->stored_filename;

            if (!Storage::disk('public')->exists($filePath)) {
                throw new \Exception('الملف غير موجود: ' . $filePath);
            }

            // الحصول على المسار الفعلي للملف
            $localPath = Storage::disk('public')->path($filePath);

            // معالجة الملف باستخدام الخدمة المحسنة
            $barcodeService->processPdfFromLocalPath($localPath, $upload);

            Log::info('PDF processing completed', ['upload_id' => $this->uploadId]);

        } catch (\Exception $e) {
            Log::error('ProcessPdfJob failed', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage(),
                'file_path' => $upload->stored_filename ?? 'unknown'
            ]);

            $upload->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
        }
    }
}
