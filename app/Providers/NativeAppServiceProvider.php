<?php

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
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
     * it can be served. A marker file ensures the copy runs once only, so later
     * user changes are never overwritten on subsequent launches.
     *
     * On EVERY launch we then run pending migrations against the runtime DB.
     * Migrations are idempotent (only unrun ones apply), so this is a no-op
     * after the first boot of each version — but it lets a user who updates the
     * app over an existing install pick up new tables/columns (e.g. the roles /
     * permissions schema) without losing their data.
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

        if (! file_exists($marker)) {
            // 1. Seed the database. The seed ships inside the app bundle
            //    (base_path), not the user's redirected storage_path.
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

        // 3. Keep the runtime schema current across app updates — but only when
        //    the bundled migration set actually changed, so ordinary launches
        //    skip the migrate cost and the window opens faster. The signature is
        //    derived from the migration filenames shipped in the app bundle
        //    (base_path, read-only), so it changes exactly when a new release
        //    adds migrations — no dependency on a runtime version env var, which
        //    isn't reliably present in the packaged app. Wrapped so a migration
        //    hiccup can never block the app from opening.
        try {
            $files = glob(base_path('database/migrations/*.php')) ?: [];
            sort($files);
            $signature = md5(implode('|', array_map('basename', $files)));

            $migratedMarker = dirname($dbPath).DIRECTORY_SEPARATOR.'.migrated';
            $lastSignature = is_file($migratedMarker) ? trim((string) @file_get_contents($migratedMarker)) : null;

            if ($lastSignature !== $signature) {
                Artisan::call('migrate', ['--force' => true]);
                @file_put_contents($migratedMarker, $signature);
            }
        } catch (\Throwable $e) {
            report($e);
        }
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
