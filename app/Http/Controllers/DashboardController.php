<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Models\{Upload,Group};

class DashboardController extends Controller
{
    public function index()
    {
        $uploadsCount = Upload::count();
        $groupsCount = Group::count();
        $usersCount = User::count();

        $uploads = Upload::latest()->take(6)->get();

        return view('dashboard', compact(
            'uploadsCount',
            'groupsCount',
            'usersCount',
            'uploads'
        ));
    }
}
