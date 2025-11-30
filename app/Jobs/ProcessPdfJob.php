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

        $tmpFile = sys_get_temp_dir() . '/upload_' . $upload->id . '_' . uniqid() . '.pdf';

        try {
            $upload->update(['status' => 'processing']);

            // Download S3/MinIO file to temp local path
            $s3Disk = Storage::disk('s3');
            $stream = $s3Disk->readStream($upload->stored_filename);

            if ($stream === false) {
                throw new \Exception('Unable to open S3 stream for ' . $upload->stored_filename);
            }

            $tmpFp = fopen($tmpFile, 'w');
            if (!$tmpFp) {
                throw new \Exception('Unable to create temporary file: ' . $tmpFile);
            }

            while (!feof($stream)) {
                fwrite($tmpFp, fread($stream, 1024 * 1024)); // قراءة 1MB chunks
            }

            fclose($tmpFp);
            if (is_resource($stream)) fclose($stream);

            // Call service to process the local file
            $barcodeService->processPdfFromLocalPath($upload);

            // update upload (status & pages)
            $upload->update([
                'status' => 'completed',
                'total_pages' => $upload->total_pages ?? 0
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessPdfJob failed', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage()
            ]);

            $upload->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

        } finally {
            // cleanup temporary file
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }
}
