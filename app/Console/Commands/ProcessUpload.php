<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\UploadController;

class ProcessUpload extends Command
{
    protected $signature = 'process:upload {uploadId}';
    protected $description = 'Process a PDF upload in background';

    public function handle()
    {
        $uploadId = $this->argument('uploadId');
        $this->info("Starting background processing for upload: {$uploadId}");

        $controller = app(UploadController::class);
        $controller->process($uploadId);

        $this->info("Background processing completed for upload: {$uploadId}");
    }
}
