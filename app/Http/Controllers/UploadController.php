<?php
namespace App\Http\Controllers;

use App\Jobs\ProcessUpload;
use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use TusPhp\Tus\Server as TusServer;
use TusPhp\Handler\Dispatcher;
use TusPhp\Middleware\Cors;
use Illuminate\Support\Str;
use ZipArchive;

class UploadController extends Controller
{
    // Tus server endpoint (receives chunk uploads)
    public function tusServer(Request $request)
    {
        // configure tus server to use storage directory storage/app/tus
        $server = new TusServer('file'); // use file store
        $server->setApiPath('/tus'); // doit match client
        $server->setUploadDir(storage_path('app/tus'));

        // create dispatcher to handle request
        $response = $server->serve();
        $response->send();
        exit; // tus-php handles response directly
    }

    // Called by client after tus upload is complete with the tus file URI
    public function complete(Request $request)
    {
        $request->validate([
            'upload_url' => 'required|string',
            'original_filename' => 'required|string'
        ]);

        $uploadUrl = $request->input('upload_url'); // e.g. /files/<id>
        $original = $request->input('original_filename');

        // tus-php stores files in storage/app/tus/<file id>
        // extract id from URL (last path segment)
        $id = basename(parse_url($uploadUrl, PHP_URL_PATH));

        $tusDir = storage_path("app/tus");
        $src = "{$tusDir}/{$id}";

        if (!file_exists($src)) {
            return response()->json(['success'=>false,'error'=>'Tus file not found on server'], 404);
        }

        // move to private storage and record Upload
        $newName = 'uploads/' . Str::random(10) . '_' . preg_replace('/\s+/', '_', $original);
        Storage::disk('private')->put($newName, file_get_contents($src));

        $upload = Upload::create([
            'original_filename' => $original,
            'stored_filename' => $newName,
            'tus_path' => $id,
            'status' => 'queued',
            'user_id' => auth()->id(),
        ]);

        // remove tus temp file to free space
        @unlink($src);

        // dispatch job
        ProcessUpload::dispatch($upload->id);

        return response()->json([
            'success'=>true,
            'upload_id'=>$upload->id,
            'message'=>'Uploaded and queued for processing'
        ]);
    }

    // progress endpoint (reads redis)
    public function progress($uploadId)
    {
        $progress = cache()->store('redis')->get("upload_progress:{$uploadId}") ?? Redis::get("upload_progress:{$uploadId}") ?? 0;
        $msg = cache()->store('redis')->get("upload_message:{$uploadId}") ?? Redis::get("upload_message:{$uploadId}") ?? '';
        return response()->json(['progress'=>(int)$progress,'message'=>$msg]);
    }

    public function index()
    {
        $uploads = Upload::orderBy('created_at','desc')->paginate(20);
        return view('uploads.index', compact('uploads'));
    }

    // download all groups as zip
    public function downloadAllGroupsZip(Upload $upload)
    {
        if ($upload->groups()->count() === 0) {
            return redirect()->back()->with('error','لا توجد مجموعات للتحميل');
        }
        $zipName = 'groups_for_' . pathinfo($upload->original_filename, PATHINFO_FILENAME) . '.zip';
        $temp = storage_path('app/temp/'.$zipName);
        if (!file_exists(dirname($temp))) mkdir(dirname($temp), 0775, true);

        $zip = new ZipArchive;
        if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($upload->groups as $g) {
                $path = storage_path('app/private/' . $g->pdf_path);
                if (file_exists($path)) {
                    $zip->addFile($path, basename($g->pdf_path));
                }
            }
            $zip->close();
            return response()->download($temp, $zipName)->deleteFileAfterSend(true);
        }

        return redirect()->back()->with('error','فشل إنشاء ZIP');
    }

    // delete upload (and groups)
    public function destroy(Upload $upload)
    {
        // delete stored file
        if ($upload->stored_filename && Storage::disk('private')->exists($upload->stored_filename)) {
            Storage::disk('private')->delete($upload->stored_filename);
        }

        // delete group files
        foreach ($upload->groups as $g) {
            if (Storage::disk('private')->exists($g->pdf_path)) {
                Storage::disk('private')->delete($g->pdf_path);
            }
            $g->delete();
        }

        $upload->delete();

        return response()->json(['success'=>true]);
    }
}
