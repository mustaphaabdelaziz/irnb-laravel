<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Inside the NativePHP desktop app the server runs on a dynamic
        // localhost port, so public-disk URLs must be relative to resolve
        // against whatever host/port the window is using.
        if (config('nativephp-internal.running')) {
            config(['filesystems.disks.public.url' => '/media']);
        }
    }
}
