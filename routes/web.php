<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;

// الصفحة الرئيسية
Route::get('/', function () {
    return view('auth.login');
});

// Routes المحمية بـ Auth
Route::middleware(['auth'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
// ----------------------
    // Upload Routes
    // ----------------------
    Route::prefix('uploads')->name('uploads.')->controller(UploadController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{upload}', 'show')->name('show');
        Route::get('/{upload}/edit', 'edit')->name('edit');
        Route::patch('/{upload}', 'update')->name('update');
        Route::delete('/{upload}', 'destroy')->name('destroy');

        // Custom routes

        Route::get('/uploaded-file/{upload}', 'showFile')->name('show_file');
        Route::get('/{upload}/download-all',  'downloadAllGroupsZip')->name('download_all_groups');
        Route::post('/chunk', 'uploadChunk')->name('chunk');
        Route::post('/init', 'initUpload')->name('init');

        Route::post('/process','process')->name('process');
        Route::post('/presign', 'generatePresignedUrl')->name('presign');

        // multipart s3 endpoints
        Route::post('/s3/init', 'initMultipart')->name('s3.init');
        Route::post('/s3/part',  'presignPart')->name('s3.part');
        Route::post('/s3/complete', 'completeMultipart')->name('s3.complete');
        Route::post('/s3/abort',  'abortMultipart')->name('s3.abort');

        // status & file
        Route::get('/{upload}/status',  'checkStatus')->name('status');
        Route::get('/file/{id}', 'showFile')->name('file');


    });

     // ----------------------
    // Group Routes
    // ----------------------
    Route::prefix('groups')->name('groups.')->controller(GroupController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{group}', 'show')->name('show');
        Route::get('/{group}/edit', 'edit')->name('edit');
        Route::patch('/{group}', 'update')->name('update');
        Route::delete('/{group}', 'destroy')->name('destroy');

        // Custom routes
        Route::get('/{group}/download', 'download')->name('download');
        Route::get('/upload/{upload}', 'indexByUpload')->name('for_upload');
    });


     // ----------------------
    // Profile Routes
    // ----------------------
    Route::prefix('profile')->name('profile.')->controller(ProfileController::class)->group(function () {
        Route::get('/', 'edit')->name('edit');
        Route::patch('/', 'update')->name('update');
        Route::delete('/', 'destroy')->name('destroy');
    });
});


Route::get('/system-check', function () {
    $checks = [
        'deepseek_api_key' => !empty(env('DEEPSEEK_API_KEY')),
        'storage_link' => file_exists(public_path('storage')),
        'php_version' => PHP_VERSION,
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
    ];

    return response()->json($checks);
});

require __DIR__.'/auth.php';
