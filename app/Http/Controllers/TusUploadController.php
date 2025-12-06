<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use App\Jobs\ProcessUploadJob;
use TusPhp\Tus\Server as TusServer;
use Illuminate\Support\Facades\Storage;

class TusUploadController extends Controller
{
    protected $tus;

    public function __construct()
    {
        $this->tus = new TusServer('file');

        // API path يجب أن يتوافق مع JS endpoint
        $this->tus->setApiPath('/uploads/chunk');
        $this->tus->setUploadDir(storage_path('app/uploads_tmp'));
    }

    public function handle()
    {
        $response = $this->tus->serve();

        // إذا اكتمل الرفع
        if ($response->getStatusCode() === 204) {

            $fileMeta = $this->tus->getFileMeta();

            $originalName = $fileMeta['original_name'] ?? $fileMeta['name'] ?? 'file.pdf';
            $userId = $fileMeta['user_id'] ?? auth()->id();
            $tmpFileName  = $fileMeta['file_name'];

            $finalName = 'uploads/' . uniqid() . '_' . $originalName;

            rename(
                storage_path("app/uploads_tmp/$tmpFileName"),
                storage_path("app/$finalName")
            );

            // إنشاء سجل DB
            $upload = Upload::create([
                'original_filename' => $originalName,
                'stored_filename' => $finalName,
                'status' => 'processing', // مباشرة بعد اكتمال الرفع
                'user_id' => $userId,
            ]);

            // بدء المعالجة باستخدام Queue
            ProcessUploadJob::dispatch($upload);

            return response()->json(['success' => true, 'upload_id' => $upload->id]);
        }

        return $response->send();
    }
}
