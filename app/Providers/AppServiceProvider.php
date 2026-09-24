<?php

namespace App\Providers;

use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // On desktop the DB lives in Electron's userData, not in database/.
        $this->app->singleton(BackupService::class, fn ($app) => new BackupService(
            $app->make(BackupSettings::class),
            config('nativephp-internal.database_path') ?: database_path('database.sqlite'),
            storage_path('app/public'),
            [
                // Private (P3): served only through authenticated routes, so they live
                // on the private disk — and must still come back with a restore.
                'player-documents' => storage_path('app/private/player-documents'),
                'minutes' => storage_path('app/private/minutes'),
                'receipts' => storage_path('app/private/receipts'),
            ],
        ));
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
