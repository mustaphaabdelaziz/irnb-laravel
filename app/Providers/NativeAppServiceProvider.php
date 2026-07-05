<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        $this->firstRunSetup();

        Window::open();
    }

    /**
     * On the very first launch, populate the user's data directory from the
     * bundle: copy the seed database over the empty one NativePHP creates, and
     * copy the bundled public media (logos, uploads) into the user's storage so
     * it can be served. A marker file ensures this runs once only, so later
     * user changes are never overwritten on subsequent launches.
     */
    protected function firstRunSetup(): void
    {
        if (! config('nativephp-internal.running')) {
            return;
        }

        $dbPath = config('nativephp-internal.database_path');
        if (! $dbPath) {
            return;
        }

        $marker = dirname($dbPath).DIRECTORY_SEPARATOR.'.seeded';
        if (file_exists($marker)) {
            return;
        }

        // 1. Seed the database. The seed ships inside the app bundle (base_path),
        //    not the user's redirected storage_path.
        $seed = base_path('storage/app/seed/database.sqlite');
        if (file_exists($seed)) {
            // Release the SQLite handle so the file can be replaced on Windows.
            DB::disconnect();
            @unlink($dbPath.'-wal');
            @unlink($dbPath.'-shm');
            @copy($seed, $dbPath);
        }

        // 2. Seed public media so logos/uploads are present and servable.
        $this->copyDirectory(base_path('storage/app/public'), storage_path('app/public'));

        @file_put_contents($marker, (string) time());
    }

    /**
     * Recursively copy a directory tree (used to seed bundled public media into
     * the user's writable storage directory on first launch).
     */
    protected function copyDirectory(string $from, string $to): void
    {
        if (! is_dir($from)) {
            return;
        }

        if (! is_dir($to)) {
            @mkdir($to, 0777, true);
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            $target = $to.DIRECTORY_SEPARATOR.$items->getSubPathname();

            if ($item->isDir()) {
                @mkdir($target, 0777, true);
            } else {
                @copy($item->getPathname(), $target);
            }
        }
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        // Enable OPcache in the bundled PHP so request handling doesn't
        // re-compile the app on every hit. The bundle is read-only, so
        // validate_timestamps=0 skips per-file stat() calls. The NativePHP
        // server runs the CLI SAPI, hence enable_cli. This is the biggest
        // lever on desktop cold-start / per-request latency.
        return [
            'opcache.enable' => '1',
            'opcache.enable_cli' => '1',
            'opcache.memory_consumption' => '128',
            'opcache.interned_strings_buffer' => '16',
            'opcache.max_accelerated_files' => '20000',
            'opcache.validate_timestamps' => '0',
        ];
    }
}
