<?php

namespace App\Jobs;

use Throwable;
use App\Models\Upload;
use Illuminate\Bus\Queueable;
use App\Services\BarcodeOCRService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ProcessPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800;
    public $tries = 3;

    protected $upload;

    public function __construct(Upload $upload)
    {
        $this->upload = $upload;
    }

    public function handle(BarcodeOCRService $barcodeService)
    {
        Log::info("🚀 Starting PDF processing job", ['upload_id' => $this->upload->id]);

        $this->upload->update([
            'status' => 'processing',
            'started_at' => now()
        ]);

        Redis::setex("upload_progress:{$this->upload->id}", 3600, 0);

        try {
            $groups = $barcodeService->processPdf($this->upload);

            $this->upload->update([
                'status' => 'completed',
                'completed_at' => now(),
                'groups_count' => count($groups),
                'error_message' => null
            ]);

            Redis::setex("upload_progress:{$this->upload->id}", 3600, 100);

            Log::info("🎉 PDF processed successfully", [
                'upload_id' => $this->upload->id,
                'groups_count' => count($groups),
                'processing_time' => now()->diffInSeconds($this->upload->started_at)
            ]);

        } catch (Throwable $e) {
            Log::error("💥 PDF processing job failed", [
                'upload_id' => $this->upload->id,
                'error' => $e->getMessage()
            ]);
            $this->fail($e);
        }
    }

    public function failed(Throwable $exception)
    {
        $this->upload->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'failed_at' => now()
        ]);

        Redis::del("upload_progress:{$this->upload->id}");

        Log::error("💥 PDF processing job failed completely", [
            'upload_id' => $this->upload->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
