<?php

namespace App\Http\Controllers;

use Aws\S3\S3Client;
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
    protected S3Client $s3;
    protected string $bucket;
    protected string $region;

    public function __construct()
    {
        $this->bucket = env('AWS_BUCKET');
        $this->region = env('AWS_DEFAULT_REGION') ?: 'us-east-1';

        $this->s3 = new S3Client([
            'version' => 'latest',
            'region' => $this->region,
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => true, // ضروري لـ MinIO
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ],
            'http' => ['verify' => false],
        ]);
    }

    public function create() { return view('uploads.create'); }

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
        $upload->delete();
        return redirect()->route('uploads.index')->with('success', 'Upload deleted.');
    }

    // ----------------------
    // S3 / MinIO Multipart Upload
    // ----------------------
    public function initMultipart(Request $request)
    {
        $request->validate([
            'filename' => 'required|string',
            'content_type' => 'required|string',
        ]);

        $userId = auth()->id() ?? 'anonymous';
        $key = "users/{$userId}/uploads/" . Str::uuid() . '-' . basename($request->filename);

        $result = $this->s3->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ACL' => 'private',
            'ContentType' => $request->content_type,
        ]);

        return response()->json([
            'success' => true,
            'uploadId' => $result['UploadId'],
            'key' => $key,
        ]);
    }

    public function presignPart(Request $request)
    {
        $request->validate([
            'key' => 'required|string',
            'uploadId' => 'required|string',
            'partNumber' => 'required|integer|min:1',
        ]);

        $command = $this->s3->getCommand('UploadPart', [
            'Bucket' => $this->bucket,
            'Key' => $request->key,
            'UploadId' => $request->uploadId,
            'PartNumber' => $request->partNumber,
        ]);

        $presignedRequest = $this->s3->createPresignedRequest($command, '+30 minutes');

        return response()->json(['url' => (string) $presignedRequest->getUri()]);
    }

    public function completeMultipart(Request $request)
    {
        $request->validate([
            'key' => 'required|string',
            'uploadId' => 'required|string',
            'parts' => 'required|array|min:1',
            'original_filename' => 'nullable|string',
        ]);

        $parts = $request->parts;
        usort($parts, fn($a, $b) => intval($a['PartNumber']) <=> intval($b['PartNumber']));

        $params = [
            'Bucket' => $this->bucket,
            'Key' => $request->key,
            'UploadId' => $request->uploadId,
            'MultipartUpload' => [
                'Parts' => array_map(fn($p) => [
                    'ETag' => trim($p['ETag'], "\"'"),
                    'PartNumber' => intval($p['PartNumber']),
                ], $parts)
            ]
        ];

        try {
            $result = $this->s3->completeMultipartUpload($params);
        } catch (\Exception $e) {
            Log::error('completeMultipartUpload failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }

        $upload = Upload::create([
            'user_id' => auth()->id(),
            'original_filename' => $request->original_filename ?? basename($request->key),
            'stored_filename' => $request->key,
            's3_etag' => $result['ETag'] ?? null,
            'status' => 'queued'
        ]);

        ProcessPdfJob::dispatch($upload->id);

        return response()->json(['success' => true, 'upload_id' => $upload->id, 's3_result' => $result]);
    }

    public function abortMultipart(Request $request)
    {
        $request->validate(['key' => 'required|string','uploadId' => 'required|string']);

        try {
            $this->s3->abortMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $request->key,
                'UploadId' => $request->uploadId,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed aborting multipart', ['err' => $e->getMessage()]);
        }

        return response()->json(['success' => true]);
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

        $cmd = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $upload->stored_filename,
        ]);

        $presigned = $this->s3->createPresignedRequest($cmd, '+10 minutes');

        return redirect((string)$presigned->getUri());
    }

    public function downloadAllGroupsZip(Upload $upload)
    {
        $zipFileName = 'upload_' . $upload->id . '_groups.zip';
        $zipPath = storage_path('app/temp/' . $zipFileName);

        if (!file_exists(dirname($zipPath))) mkdir(dirname($zipPath), 0755, true);

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($upload->groups as $group) {
            $cmd = $this->s3->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $group->pdf_path,
            ]);
            $presigned = $this->s3->createPresignedRequest($cmd, '+5 minutes');
            $content = file_get_contents((string)$presigned->getUri());
            $zip->addFromString(basename($group->pdf_path), $content);
        }

        $zip->close();
        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

    public function processPdfFromLocalPath(string $localPath, Upload $upload)
    {
        // مثال: عد الصفحات وحفظ العدد
        $totalPages = 0;
        $upload->update(['status' => 'processed','total_pages' => $totalPages,'error_message' => null]);
        return true;
    }
}
