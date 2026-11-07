<?php

namespace App\Providers;

use App\Services\Backup\LiveDatabase;
use App\Support\ProductBrand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;
use RuntimeException;

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
     * On the very first launch, populate the user's data directory: the club's
     * records from the app's pre-ASIREM folder, or else the seed database and
     * the bundled public media (see populateDataFolder()). A marker file
     * ensures this runs once only, so later user changes are never overwritten
     * on subsequent launches.
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

        $this->populateDataFolderOnce($dbPath);

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
                // BackupBeforeMigrating copies the database aside as `migrate`
                // starts (here or in the shell's own migrate run), so an update
                // that rewrites data can be undone by hand.
                Artisan::call('migrate', ['--force' => true]);
                @file_put_contents($migratedMarker, $signature);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function populateDataFolderOnce(string $dbPath): void
    {
        $marker = dirname($dbPath).DIRECTORY_SEPARATOR.'.seeded';

        if (file_exists($marker)) {
            return;
        }

        try {
            $this->populateDataFolder($dbPath);
            @file_put_contents($marker, (string) time());
        } catch (\Throwable $e) {
            // Nothing was replaced: the swap is a single rename(), so the
            // database is whole. No marker, so the next launch tries again —
            // and the seed never lands on a club whose records are still in
            // the old folder.
            report($e);
        }
    }

    /**
     * First launch: fill the new data folder with the club's records from the
     * app's pre-ASIREM folder, or, on a PC that never ran it, with the seed and
     * the bundled public media (logos, uploads).
     */
    protected function populateDataFolder(string $dbPath): void
    {
        if ($this->adoptLegacyData($dbPath)) {
            return;
        }

        // The seed ships inside the app bundle (base_path), not the user's
        // redirected storage_path.
        $seed = $this->seedDatabasePath();
        if (file_exists($seed)) {
            $this->replaceDatabase($dbPath, $seed);
        }

        $this->copyDirectory(base_path('storage/app/public'), storage_path('app/public'));
    }

    protected function seedDatabasePath(): string
    {
        return base_path('storage/app/seed/database.sqlite');
    }

    /**
     * Copy the data of the app's pre-ASIREM name into this, its new data folder.
     *
     * Electron names the data folder after APP_NAME (%APPDATA%\sport-club
     * before, %APPDATA%\asirem now), so the renamed app starts on an empty
     * folder. Without this, an update would open on the seed instead of the
     * club's own records. The old folder is only read: it is the only copy if
     * anything here goes wrong.
     *
     * False when there is no old folder; throws when there is one but its
     * database could not be moved in, with the new database left whole.
     */
    protected function adoptLegacyData(string $dbPath): bool
    {
        $userData = dirname($dbPath, 2);

        foreach (ProductBrand::LEGACY_DATA_DIRS as $name) {
            $legacy = dirname($userData).DIRECTORY_SEPARATOR.$name;
            $legacyDb = $legacy.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.basename($dbPath);

            if (strcasecmp($legacy, $userData) === 0 || ! is_file($legacyDb)) {
                continue;
            }

            $this->replaceDatabase($dbPath, $legacyDb);

            // Media, player documents, minutes, receipts.
            $this->copyDirectory($legacy.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app', storage_path('app'));

            return true;
        }

        return false;
    }

    /**
     * Put a copy of $source in place of the live database.
     *
     * By the time this runs, the Electron shell has already migrated the live
     * database and the queue worker holds it open. Deleting or copying over it
     * (as 3.0.0 did) fails silently on Windows and leaves the worker's -wal
     * beside the new bytes: the club's first launch opened on "database disk
     * image is malformed". So, as the backup restore does: stage the copy beside
     * the live file, take the live file out of every process's hands, then one
     * rename(), which Windows only allows when nobody holds it. Any failure
     * throws with the live database untouched.
     */
    protected function replaceDatabase(string $dbPath, string $source): void
    {
        $incoming = $dbPath.'.new';

        $this->stageDatabase($source, $incoming);

        $live = $this->liveDatabase($dbPath);

        try {
            $live->release();

            if (! @rename($incoming, $dbPath)) {
                throw new RuntimeException("The database is still in use, so it could not be replaced: {$dbPath}.");
            }

            // The marker belonged to the database just replaced: `migrate`
            // must run on this one.
            @unlink(dirname($dbPath).DIRECTORY_SEPARATOR.'.migrated');
        } finally {
            $this->removeStaged($incoming);

            // release() stopped the worker; the app carries on, so it comes
            // back, now on the new database.
            $live->restartQueueWorker();
        }
    }

    /**
     * A self-contained, checked copy of $source at $target: its -wal (the
     * newest writes) folded in, nothing beside it. $source is only read.
     */
    protected function stageDatabase(string $source, string $target): void
    {
        $this->removeStaged($target);
        $pdo = null;

        try {
            if (! @copy($source, $target)) {
                throw new RuntimeException("Could not copy the database: {$source}.");
            }

            // Not the -shm: SQLite rebuilds it from the -wal.
            if (is_file($source.'-wal') && ! @copy($source.'-wal', $target.'-wal')) {
                throw new RuntimeException("Could not copy the database's newest writes: {$source}-wal.");
            }

            $pdo = new \PDO('sqlite:'.$target, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $check = (string) $pdo->query('PRAGMA quick_check')->fetchColumn();

            if ($check !== 'ok') {
                throw new RuntimeException("The database to copy in is damaged ({$check}): {$source}.");
            }

            $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)');
            $pdo = null;

            if ((new LiveDatabase($target))->sidecars() !== []) {
                throw new RuntimeException("Could not fold the newest writes into the copy: {$target}.");
            }
        } catch (\Throwable $e) {
            $pdo = null;
            $this->removeStaged($target);

            throw $e;
        }
    }

    protected function liveDatabase(string $dbPath): LiveDatabase
    {
        return new LiveDatabase($dbPath);
    }

    private function removeStaged(string $target): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($target.$suffix);
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
        //
        // The bundled static php.exe ships with no php.ini, so PHP falls back
        // to its compiled-in defaults: upload_max_filesize=2M, post_max_size=8M,
        // max_file_uploads=20 (verified by running the packaged php.exe
        // directly). P3 promises 10 MB per file for player documents, board
        // minutes and transaction receipts; social activities accept attached
        // files up to 20 MB and up to 20 photos of 8 MB per request — all well
        // past the 2M default — so without these overrides PHP rejects the
        // upload before Laravel's own `max:` validation rules ever run.
        // memory_limit leaves room to decode large photos with GD. NativePHP
        // passes this array straight through as `-d` flags to `php -S`; the
        // upload settings are PHP_INI_PERDIR and memory_limit PHP_INI_ALL, all
        // settable via -d.
        $settings = [
            'opcache.enable' => '1',
            'opcache.enable_cli' => '1',
            'opcache.memory_consumption' => '128',
            'opcache.interned_strings_buffer' => '16',
            'opcache.max_accelerated_files' => '20000',
            'opcache.validate_timestamps' => '0',
            'upload_max_filesize' => '20M',
            'post_max_size' => '200M',
            'max_file_uploads' => '20',
            'memory_limit' => '512M',
        ];

        if (($fileCache = $this->opcacheFileCache()) && ($blacklist = $this->opcacheBlacklist($fileCache))) {
            $settings['opcache.file_cache'] = $fileCache;
            $settings['opcache.blacklist_filename'] = $blacklist;
        }

        return $settings;
    }

    /**
     * The shell's `artisan optimize` rewrites the cached config before every
     * launch (it holds that launch's secret and ports), and with
     * validate_timestamps=0 the file cache would hand the next launch's server
     * the previous launch's config: the shell rejects its calls and the window
     * never opens. So OPcache never caches that one file (~3 ms a request to
     * compile). The other cached files (routes, events, manifests) are the
     * same on every launch of a build. No blacklist written → no file cache.
     */
    protected function opcacheBlacklist(string $fileCache): ?string
    {
        $path = $fileCache.DIRECTORY_SEPARATOR.'blacklist.txt';

        // Laravel builds the path with mixed separators on Windows.
        $config = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, app()->getCachedConfigPath());

        $written = @file_put_contents($path, $config.PHP_EOL);

        return $written === false ? null : $path;
    }

    /**
     * Every launch starts several short-lived PHP processes (optimize, the
     * server, the queue worker, the scheduler each minute), and OPcache's
     * memory dies with each one, so each re-parses the ~500 framework files it
     * loads. A file cache in the user's data folder keeps the compiled scripts
     * between processes and launches (PHP boot measured 1.5 s → 1.0 s).
     *
     * With validate_timestamps=0 a cached script is never re-checked against
     * its source, so the folder is keyed on the build id: an update starts a
     * fresh folder and the previous build's is deleted. No build id, or the
     * debug mode `native:serve` runs in (code being edited) → no file cache.
     */
    protected function opcacheFileCache(): ?string
    {
        if (config('app.debug')) {
            return null;
        }

        $buildId = $this->buildId();
        if ($buildId === null || ! preg_match('/^[A-Za-z0-9-]+$/', $buildId)) {
            return null;
        }

        $root = storage_path('framework'.DIRECTORY_SEPARATOR.'opcache');
        $dir = $root.DIRECTORY_SEPARATOR.$buildId;

        if (! is_dir($dir)) {
            foreach (glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $old) {
                File::deleteDirectory($old);
            }
            @mkdir($dir, 0777, true);
        }

        return is_dir($dir) && is_writable($dir) ? $dir : null;
    }

    /** The id `app:stamp-build` wrote into the bundle before the build. */
    protected function buildId(): ?string
    {
        $id = trim((string) @file_get_contents(base_path('build-id')));

        return $id === '' ? null : $id;
    }
}
