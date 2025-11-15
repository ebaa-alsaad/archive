<?php

namespace App\Providers;

use App\Services\DeepSeekOCRService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
     public function register()
    {
        $this->app->singleton(DeepSeekOCRService::class, function ($app) {
            return new DeepSeekOCRService();
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
