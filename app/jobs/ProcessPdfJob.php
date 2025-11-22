<?php

namespace App\Jobs;

use App\Models\Upload;
use App\Models\Group;
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

    protected $upload;

    public function __construct(Upload $upload)
    {
        $this->upload = $upload;
    }

    public function handle(BarcodeOCRService $barcodeService)
    {
        $this->upload->update(['status' => 'processing']);
        Redis::set("upload_progress:{$this->upload->id}", 1);

        try {
            $groups = $barcodeService->processPdf($this->upload);

            // تحديث progress تدريجي على Redis
            foreach ($groups as $i => $group) {
                $percent = intval((($i+1)/count($groups)) * 100);
                Redis::set("upload_progress:{$this->upload->id}", $percent);
            }

            $this->upload->update([
                'status' => 'completed',
                'total_pages' => $barcodeService->getPdfPageCount(
                    storage_path("app/private/{$this->upload->stored_filename}")
                ),
                'error_message' => null
            ]);

            Redis::set("upload_progress:{$this->upload->id}", 100);

            Log::info("PDF processed successfully", [
                'upload_id' => $this->upload->id,
                'groups_count' => count($groups)
            ]);

        } catch (\Exception $e) {
            $this->upload->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            Redis::del("upload_progress:{$this->upload->id}");
            Log::error("PDF processing failed", [
                'upload_id' => $this->upload->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
