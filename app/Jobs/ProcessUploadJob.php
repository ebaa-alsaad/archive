namespace App\Jobs;

use App\Models\Upload;
use App\Services\BarcodeOCRService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Storage;

class ProcessUploadJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    protected $upload;

    public function __construct(Upload $upload)
    {
        $this->upload = $upload;
    }

    public function handle(BarcodeOCRService $barcodeService)
    {
        $this->upload->update(['status' => 'processing']);

        $fullPath = Storage::disk('private')->path($this->upload->stored_filename);
        $pageCount = $barcodeService->getPdfPageCount($fullPath);
        $this->upload->update(['total_pages' => $pageCount]);

        $groups = $barcodeService->processPdf($this->upload, 'private');

        $this->upload->update(['status' => 'completed']);
    }
}
