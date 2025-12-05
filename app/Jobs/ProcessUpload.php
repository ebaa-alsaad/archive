<?php
namespace App\Jobs;

use App\Models\Upload;
use App\Services\BarcodeOCRService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $uploadId;

    public function __construct($uploadId)
    {
        $this->uploadId = $uploadId;
    }

    public function handle(BarcodeOCRService $service)
    {
        $upload = Upload::find($this->uploadId);
        if (!$upload) return;

        try {
            $upload->update(['status'=>'processing']);
            $groups = $service->processPdf($upload, 'private');

            // optional: after success delete original to free space
            if ($upload->stored_filename && Storage::disk('private')->exists($upload->stored_filename)) {
                Storage::disk('private')->delete($upload->stored_filename);
            }

            // (zip creation/export can be done here or on-demand)
        } catch (Exception $e) {
            Log::error('ProcessUpload failed', ['upload_id'=>$upload->id,'error'=>$e->getMessage()]);
            $upload->update(['status'=>'failed','error_message'=>$e->getMessage()]);
        }
    }
}
