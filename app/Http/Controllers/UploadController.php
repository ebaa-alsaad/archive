<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use App\Jobs\ProcessUploadJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use TusPhp\Tus\Server as TusServer;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    /**
     * قائمة الملفات
     */
    public function index()
    {
        $uploads = Upload::latest()->paginate(20);
        return view('uploads.index', compact('uploads'));
    }

    /**
     * صفحة الرفع
     */
    public function create()
    {
        return view('uploads.create');
    }

    /**
     * نقطة tus الرئيسية (رفع مباشر)
     */
    public function tusServer(Request $request)
    {
        $server = new TusServer('file');
        $server->setUploadDir(storage_path('app/tus'));

        $response = $server->serve();

        $status = $response->getStatusCode();

        // بعد انتهاء رفع ملف بالكامل
        if ($status === 204) {
            $fileMeta = $server->getCache()->get($server->getKey());

            if ($fileMeta && isset($fileMeta['file_path'])) {
                // تحويل ملف tus المؤقت إلى storage/archives
                $finalName = Str::uuid() . '.pdf';
                $finalPath = 'archives/' . $finalName;

                Storage::put($finalPath, file_get_contents($fileMeta['file_path']));
            }
        }

        return $response->send();
    }

    /**
     * إكمال الرفع (Called by Uppy after successful upload)
     */
    public function complete(Request $request)
    {
        $request->validate([
            'original_filename' => 'required|string',
            'upload_url'        => 'required|string',
        ]);

        // استخراج اسم الملف النهائي على السيرفر
        $filePath = $this->getTusFilePath($request->upload_url);

        if (!$filePath || !Storage::exists($filePath)) {
            return response()->json([
                'error' => 'File not found on server',
                'path'  => $filePath,
            ], 404);
        }

        // إنشاء سجل في قاعدة البيانات
        $upload = Upload::create([
            'original_filename' => $request->original_filename,
            'stored_path'       => $filePath,
            'status'            => 'pending',
        ]);

        // تشغيل الجوب
        ProcessUploadJob::dispatch($upload->id);

        return response()->json([
            'success'   => true,
            'upload_id' => $upload->id,
        ]);
    }

    /**
     * استخراج مسار الملف من رابط Uppy
     */
    private function getTusFilePath($uploadUrl)
    {
        // مثال رابط من Uppy:
        // https://domain.com/tus/30213132aabbccdd
        $parts = explode('/tus/', $uploadUrl);

        if (count($parts) !== 2) {
            return null;
        }

        $id = $parts[1];

        // مسار tus temp
        $folder = storage_path("app/tus/$id");

        if (!file_exists($folder)) {
            return null;
        }

        // داخل كل مجلد tus يوجد ملف واحد فقط
        $files = scandir($folder);
        $file = collect($files)->reject(fn ($f) => in_array($f, ['.', '..']))->first();

        if (!$file) {
            return null;
        }

        return "tus/$id/$file";
    }

    /**
     * إرجاع progress — في حال احتجته (اختياري)
     */
    public function progress($uploadId)
    {
        $upload = Upload::findOrFail($uploadId);

        return response()->json([
            'status' => $upload->status,
            'processed_pages' => $upload->processed_pages,
            'total_pages'     => $upload->total_pages,
        ]);
    }

    /**
     * تحميل جميع المجموعات بصيغة ZIP
     */
    public function downloadAllGroupsZip(Upload $upload)
    {
        $dir = "groups/{$upload->id}";

        if (!Storage::exists($dir)) {
            return back()->with('error', 'لا توجد مجموعات متاحة لهذا الرفع.');
        }

        $zipName = "groups_{$upload->id}.zip";
        $zipPath = storage_path("app/$zipName");

        $zip = new \ZipArchive;

        if ($zip->open($zipPath, \ZipArchive::CREATE) === TRUE) {

            $files = Storage::files($dir);

            foreach ($files as $file) {
                $zip->addFile(storage_path("app/$file"), basename($file));
            }

            $zip->close();
        }

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

    /**
     * حذف رفع كامل + الملفات + المجموعات
     */
    public function destroy(Upload $upload)
    {
        if ($upload->stored_path && Storage::exists($upload->stored_path)) {
            Storage::delete($upload->stored_path);
        }

        $groupsPath = "groups/{$upload->id}";
        if (Storage::exists($groupsPath)) {
            Storage::deleteDirectory($groupsPath);
        }

        $upload->delete();

        return back()->with('success', 'تم حذف الرفع بنجاح');
    }
}
