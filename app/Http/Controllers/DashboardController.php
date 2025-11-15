<?php

namespace App\Http\Controllers;

use App\Models\{Upload,Group};
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $uploadsCount = Upload::count();
        $groupsCount = Group::count();

        $uploads = Upload::latest()->take(6)->get();

        return view('dashboard', compact(
            'uploadsCount',
            'groupsCount',
            'uploads'
        ));
    }
}
