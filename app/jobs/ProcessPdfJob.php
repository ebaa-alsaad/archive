<?php

namespace App\Jobs;

use App\Models\Upload;
use App\Services\BarcodeOCRService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ProcessPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 دقيقة
    public $tries = 3;

    protected $upload;

    public function __construct(Upload $upload)
    {
        $this->upload = $upload;
    }

    public function handle(BarcodeOCRService $barcodeService)
    {
        Log::info("🚀 بدء معالجة PDF", ['upload_id' => $this->upload->id]);

        $this->upload->update([
            'status' => 'processing',
            'started_at' => now()
        ]);

        Redis::set("upload_progress:{$this->upload->id}", 0);

        try {
            $groups = $barcodeService->processPdf($this->upload);

            $this->upload->update([
                'status' => 'completed',
                'completed_at' => now(),
                'groups_count' => count($groups),
                'error_message' => null
            ]);

            Redis::set("upload_progress:{$this->upload->id}", 100);

            Log::info("✅ اكتملت معالجة PDF", [
                'upload_id' => $this->upload->id,
                'groups_count' => count($groups)
            ]);

        } catch (\Exception $e) {
            Log::error("❌ فشلت معالجة PDF", [
                'upload_id' => $this->upload->id,
                'error' => $e->getMessage()
            ]);

            $this->upload->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'failed_at' => now()
            ]);

            Redis::del("upload_progress:{$this->upload->id}");

            throw $e; // إعادة رمي الخطأ للـ queue
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error("💥 فشل الـ Job تماماً", [
            'upload_id' => $this->upload->id,
            'error' => $exception->getMessage()
        ]);
    }
}
