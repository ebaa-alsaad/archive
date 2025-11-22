<?php

namespace App\Providers;

use App\Services\BarcodeOCRService;
use App\Services\DeepSeekOCRService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
     public function register()
    {
        $this->app->singleton(BarcodeOCRService::class, function ($app) {
            return new BarcodeOCRService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
