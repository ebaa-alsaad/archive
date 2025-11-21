<?php

namespace App\Jobs;

use App\Models\Upload;
use App\Services\BarcodeOCRService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;

class ProcessPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $uploadId;

    public function __construct($uploadId)
    {
        $this->uploadId = $uploadId;
    }

    public function handle()
    {
        $upload = Upload::findOrFail($this->uploadId);

        $upload->update(['status' => 'processing']);

        try {
            $service = new BarcodeOCRService();
            $service->processPdf(storage_path('app/uploads/' . $upload->stored_filename), $upload);
            $upload->update(['status' => 'done']);
        } catch (\Exception $e) {
            $upload->update([
                'status' => 'failed',
            ]);
        }
    }
}
