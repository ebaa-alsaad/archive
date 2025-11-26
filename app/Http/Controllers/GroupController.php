<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\{Group,Upload};
use Illuminate\Support\Facades\Storage;

class GroupController extends Controller
{
    public function index()
    {
        $groups = Group::latest()->paginate(12);
        return view('groups.index', compact('groups'));
    }

    /**
     *
     * @param  \App\Models\Group  $group
     * @return \Illuminate\View\View
     */
    public function show(Group $group)
    {
        $group->load('upload.user');

        return view('groups.show', compact('group'));
    }

    public function indexByUpload(Upload $upload)
    {
        $groups = Group::where('upload_id', $upload->id)
                       ->with('user')
                       ->latest()
                       ->paginate(20);

        return view('groups.index', [
            'groups' => $groups,
            'upload' => $upload,
        ]);
    }

    public function download(Group $group)
    {
        $absolute = storage_path('app/private/' . $group->pdf_path);

        if (!file_exists($absolute)) {
            abort(404, "الملف غير موجود: $absolute");
        }

        return response()->file($absolute);
    }




}
