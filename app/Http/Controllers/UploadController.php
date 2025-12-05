<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\BarcodeOCRService;
use ZipArchive;
use Illuminate\Support\Facades\File;

class UploadController extends Controller
{
    protected $barcodeService;

    public function __construct(BarcodeOCRService $barcodeService)
    {
        $this->barcodeService = $barcodeService;
    }

    public function index()
    {
        $uploads = Upload::with(['user', 'groups'])->orderByDesc('created_at')->paginate(10);
        return view('uploads.index', compact('uploads'));
    }

    public function create()
    {
        return view('uploads.create');
    }

    public function show(Upload $upload)
    {
        $upload->load(['groups', 'user']);
        return view('uploads.show', compact('upload'));
    }

    public function showFile(Upload $upload)
    {
        $disk = 'private';
        if (!$upload->stored_filename || !Storage::disk($disk)->exists($upload->stored_filename)) {
            abort(404, 'الملف غير موجود.');
        }

        return Storage::disk($disk)->response($upload->stored_filename);
    }

    public function store(Request $request)
    {
        ini_set('memory_limit', '1024M');
        ini_set('max_execution_time', 1200);

        $request->validate([
            'pdf_file' => 'required|mimes:pdf|max:256000'
        ]);

        try {
            $file = $request->file('pdf_file');
            $storedName = $file->store('uploads', 'private');

            $upload = Upload::create([
                'original_filename' => $file->getClientOriginalName(),
                'stored_filename' => $storedName,
                'status' => 'processing',
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "تم رفع الملف بنجاح. جاري المعالجة...",
                'upload_id' => $upload->id,
                'status' => 'processing'
            ]);

        } catch (\Exception $e) {
            Log::error('Upload failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function process($uploadId)
    {
        try {
            $upload = Upload::findOrFail($uploadId);

            if ($upload->status !== 'processing') {
                return response()->json([
                    'success' => false,
                    'error' => 'الملف ليس في حالة معالجة.'
                ]);
            }

            $groups = $this->barcodeService->processPdf($upload);

            $upload->update(['status' => 'completed']);

            return response()->json([
                'success' => true,
                'message' => 'تمت معالجة الملف بنجاح.',
                'groups_count' => count($groups),
                'group_files' => array_map(fn($g) => $g->pdf_path, $groups)
            ]);

        } catch (\Exception $e) {
            Log::error('Processing failed', ['upload_id' => $uploadId, 'error' => $e->getMessage()]);

            if (isset($upload)) {
                $upload->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            }

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function downloadAllGroupsZip(Upload $upload)
    {
        if ($upload->status !== 'completed' || $upload->groups->isEmpty()) {
            return redirect()->back()->with('error', 'لا يمكن تحميل ملف ZIP.');
        }

        $zipFileName = 'groups_for_' . $upload->original_filename . '.zip';
        $tempPath = storage_path('app/temp/' . $zipFileName);

        if (!File::isDirectory(storage_path('app/temp'))) {
            File::makeDirectory(storage_path('app/temp'), 0755, true);
        }

        $zip = new ZipArchive;
        if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach ($upload->groups as $group) {
                if ($group->pdf_path && Storage::disk('private')->exists($group->pdf_path)) {
                    $zip->addFromString(basename($group->pdf_path), Storage::disk('private')->get($group->pdf_path));
                }
            }
            $zip->close();
        }

        return response()->download($tempPath, $zipFileName)->deleteFileAfterSend(true);
    }

    public function destroy(Upload $upload)
    {
        if ($upload->stored_filename) {
            Storage::disk('private')->delete($upload->stored_filename);
        }

        $upload->groups->each(function ($group) {
            if ($group->pdf_path) {
                Storage::disk('private')->delete($group->pdf_path);
            }
            $group->delete();
        });

        $upload->delete();

        return response()->json(['success' => true]);
    }
}
