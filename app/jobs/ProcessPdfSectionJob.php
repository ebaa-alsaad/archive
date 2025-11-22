<?php

namespace App\Jobs;

use App\Models\Upload;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use App\Services\BarcodeOCRService;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ProcessPdfSectionJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries = 2;

    public function __construct(
        private string $pdfPath,
        private array $pages,
        private int $index,
        private string $separatorBarcode,
        private int $uploadId,
        private string $pdfHash
    ) {}

    public function handle()
    {
        if ($this->batch() && $this->batch()->cancelled()) {
            return;
        }

        $upload = Upload::find($this->uploadId);
        if (!$upload) {
            Log::error("Upload not found", ['upload_id' => $this->uploadId]);
            return;
        }

        try {
            $service = app(BarcodeOCRService::class);
            $group = $service->processSection(
                $this->pdfPath,
                $this->pages,
                $this->index,
                $this->separatorBarcode,
                $upload
            );

            if ($group) {
                Log::info("Section processed successfully", [
                    'section_index' => $this->index,
                    'group_id' => $group->id,
                    'pages_count' => count($this->pages)
                ]);
            } else {
                Log::warning("Section processing returned no group", [
                    'section_index' => $this->index,
                    'pages_count' => count($this->pages)
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Section processing failed", [
                'section_index' => $this->index,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error("ProcessPdfSectionJob failed", [
            'upload_id' => $this->uploadId,
            'section_index' => $this->index,
            'error' => $exception->getMessage()
        ]);
    }
}