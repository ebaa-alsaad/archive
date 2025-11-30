<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Upload;
use App\Models\Group;
use App\Jobs\ProcessPdfJob;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class UploadController extends Controller
{
    public function create()
    {
        return view('uploads.create');
    }

    public function index()
    {
        $uploads = Upload::withCount('groups')->orderBy('created_at', 'desc')->paginate(20);
        return view('uploads.index', compact('uploads'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'original_filename' => 'required|string',
            'stored_filename' => 'required|string',
        ]);

        $upload = Upload::create([
            'user_id' => auth()->id(),
            'original_filename' => $request->original_filename,
            'stored_filename' => $request->stored_filename,
            'status' => 'queued'
        ]);

        ProcessPdfJob::dispatch($upload->id);

        return redirect()->route('uploads.index')->with('success', 'Upload created and queued.');
    }

    public function show(Upload $upload)
    {
        $upload->load('groups');
        return view('uploads.show', compact('upload'));
    }

    public function update(Request $request, Upload $upload)
    {
        $request->validate([
            'original_filename' => 'required|string',
            'status' => 'required|string',
        ]);

        $upload->update($request->only('original_filename', 'status'));
        return redirect()->route('uploads.show', $upload)->with('success', 'Upload updated.');
    }

    public function destroy(Upload $upload)
    {
        // حذف الملف المحفوظ
        if (Storage::disk('public')->exists($upload->stored_filename)) {
            Storage::disk('public')->delete($upload->stored_filename);
        }

        $upload->delete();
        return redirect()->route('uploads.index')->with('success', 'Upload deleted.');
    }

    // ----------------------
    // Local File Upload System
    // ----------------------

    /**
     * بدء عملية رفع الملف
     */
    public function initUpload(Request $request)
    {
        $request->validate([
            'filename' => 'required|string',
            'content_type' => 'required|string',
        ]);

        $userId = auth()->id() ?? 'anonymous';
        $uniqueId = Str::uuid();
        $fileName = $uniqueId . '-' . basename($request->filename);
        $filePath = "uploads/{$userId}/{$fileName}";

        // إنشاء مجلد مؤقت للرفع
        $tempDir = "uploads/temp/{$uniqueId}";
        Storage::disk('local')->makeDirectory($tempDir);

        return response()->json([
            'success' => true,
            'uploadId' => $uniqueId,
            'key' => $filePath,
            'tempDir' => $tempDir
        ]);
    }

    /**
     * رفع جزء من الملف
     */
    public function uploadChunk(Request $request)
    {
        $request->validate([
            'key' => 'required|string',
            'uploadId' => 'required|string',
            'chunkNumber' => 'required|integer|min:1',
            'totalChunks' => 'required|integer|min:1',
            'file' => 'required|file'
        ]);

        try {
            $chunk = $request->file('file');
            $chunkNumber = $request->chunkNumber;
            $uploadId = $request->uploadId;

            // حفظ الجزء في المجلد المؤقت
            $chunkPath = "uploads/temp/{$uploadId}/chunk_{$chunkNumber}";
            Storage::disk('local')->put($chunkPath, file_get_contents($chunk->getRealPath()));

            // إذا كان هذا آخر جزء، دمج الأجزاء
            if ($chunkNumber == $request->totalChunks) {
                return $this->mergeChunks($request->key, $uploadId, $request->totalChunks);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم رفع الجزء بنجاح',
                'chunkNumber' => $chunkNumber
            ]);

        } catch (\Exception $e) {
            Log::error('Upload chunk failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * دمج الأجزاء وإنشاء الملف النهائي
     */
    private function mergeChunks($filePath, $uploadId, $totalChunks)
    {
        try {
            $finalPath = "public/{$filePath}";

            // التأكد من وجود المجلد النهائي
            Storage::disk('local')->makeDirectory(dirname($finalPath));

            // فتح الملف النهائي للكتابة
            $finalFullPath = Storage::disk('local')->path($finalPath);
            $finalFile = fopen($finalFullPath, 'wb');

            // دمج جميع الأجزاء
            for ($i = 1; $i <= $totalChunks; $i++) {
                $chunkPath = "uploads/temp/{$uploadId}/chunk_{$i}";
                $chunkContent = Storage::disk('local')->get($chunkPath);
                fwrite($finalFile, $chunkContent);

                // حذف الجزء بعد الدمج
                Storage::disk('local')->delete($chunkPath);
            }

            fclose($finalFile);

            // حذف المجلد المؤقت
            Storage::disk('local')->deleteDirectory("uploads/temp/{$uploadId}");

            // حفظ المعلومات في قاعدة البيانات
            $upload = Upload::create([
                'user_id' => auth()->id(),
                'original_filename' => basename($filePath),
                'stored_filename' => $filePath,
                'status' => 'queued'
            ]);

            ProcessPdfJob::dispatch($upload->id);

            return response()->json([
                'success' => true,
                'message' => 'تم رفع الملف بالكامل بنجاح',
                'upload_id' => $upload->id
            ]);

        } catch (\Exception $e) {
            Log::error('Merge chunks failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * إلغاء عملية الرفع
     */
    public function abortUpload(Request $request)
    {
        $request->validate([
            'uploadId' => 'required|string'
        ]);

        try {
            // حذف المجلد المؤقت وجميع الأجزاء
            Storage::disk('local')->deleteDirectory("uploads/temp/{$request->uploadId}");
            return response()->json(['success' => true, 'message' => 'تم إلغاء الرفع']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function checkStatus($id)
    {
        $upload = Upload::find($id);
        if (!$upload) return response()->json(['success' => false, 'error' => 'Upload not found'], 404);

        return response()->json([
            'success' => true,
            'status' => $upload->status,
            'message' => $upload->error_message,
            'groups_count' => $upload->groups()->count(),
            'total_pages' => $upload->total_pages
        ]);
    }

    public function showFile($uploadId)
    {
        $upload = Upload::find($uploadId);
        if (!$upload) abort(404);

        $filePath = $upload->stored_filename;

        if (!Storage::disk('public')->exists($filePath)) {
            abort(404);
        }

        return response()->file(Storage::disk('public')->path($filePath));
    }

    public function downloadAllGroupsZip(Upload $upload)
    {
        $zipFileName = 'upload_' . $upload->id . '_groups.zip';
        $zipPath = storage_path('app/temp/' . $zipFileName);

        if (!file_exists(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach ($upload->groups as $group) {
                if (Storage::disk('public')->exists($group->pdf_path)) {
                    $fileContent = Storage::disk('public')->get($group->pdf_path);
                    $zip->addFromString(basename($group->pdf_path), $fileContent);
                }
            }
            $zip->close();
        }

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

    /**
     * رفع مباشر للملفات الصغيرة (بدون تقسيم)
     */
    public function directUpload(Request $request)
    {
        $request->validate([
            'files.*' => 'required|file|max:102400', // 100MB كحد أقصى
        ]);

        $uploadedFiles = [];

        foreach ($request->file('files') as $file) {
            $originalName = $file->getClientOriginalName();
            $fileName = time() . '_' . Str::random(10) . '_' . $originalName;
            $filePath = "uploads/" . auth()->id() . "/" . $fileName;

            // حفظ الملف
            Storage::disk('public')->put($filePath, file_get_contents($file->getRealPath()));

            // حفظ في قاعدة البيانات
            $upload = Upload::create([
                'user_id' => auth()->id(),
                'original_filename' => $originalName,
                'stored_filename' => $filePath,
                'status' => 'queued',
                'file_size' => $file->getSize(),
            ]);

            $uploadedFiles[] = $upload;

            // معالجة الـ PDF
            ProcessPdfJob::dispatch($upload->id);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم رفع الملفات بنجاح',
            'files' => $uploadedFiles
        ]);
    }
}
