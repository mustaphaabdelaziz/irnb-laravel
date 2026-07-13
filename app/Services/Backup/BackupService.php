<?php

namespace App\Services\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Creates and restores full backups: the SQLite database plus the uploaded media
 * tree, in a single zip.
 *
 * Pure filesystem + zip. No HTTP, no Inertia, and no facades other than through
 * BackupSettings — so it is fully testable against temp directories.
 */
class BackupService
{
    public const PREFIX = 'sport-club-backup-';

    public const SNAPSHOT_DIR = 'pre-restore';

    public function __construct(
        private BackupSettings $settings,
        private string $databasePath,
        private string $mediaPath,
    ) {}

    /** Writes a backup to the destination, prunes old ones, returns the new path. */
    public function create(): string
    {
        $path = $this->requireDestination()
            .DIRECTORY_SEPARATOR.self::PREFIX.$this->timestamp().'.zip';

        $this->writeArchive($path);

        $this->settings->markRun();
        $this->prune();

        return $path;
    }

    /**
     * Writes an undo point before a restore. Lives in its own subfolder and is
     * never pruned — it is the only thing standing between a mis-clicked restore
     * and permanent data loss.
     */
    public function snapshot(): string
    {
        $dir = $this->requireDestination().DIRECTORY_SEPARATOR.self::SNAPSHOT_DIR;

        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create the snapshot folder: {$dir}");
        }

        $path = $dir.DIRECTORY_SEPARATOR.'pre-restore-'.$this->timestamp().'.zip';

        $this->writeArchive($path);

        return $path;
    }

    /** @return array<int, array{name: string, path: string, bytes: int, created_at: string}> */
    public function list(): array
    {
        $destination = $this->settings->destination();

        if (! $destination || ! is_dir($destination)) {
            return [];
        }

        // glob() does not descend, so the pre-restore/ subfolder is excluded for free.
        // The destination itself is user-chosen (e.g. via a Windows folder picker) and
        // may contain *, ?, [ or ] — glob() would read those as pattern syntax and
        // silently match nothing, so only the directory portion is escaped here; the
        // '*.zip' suffix we append ourselves is meant to stay a wildcard.
        $files = glob($this->escapeGlobPath($destination).DIRECTORY_SEPARATOR.self::PREFIX.'*.zip') ?: [];

        $backups = array_map(fn (string $file) => [
            'name' => basename($file),
            'path' => $file,
            'bytes' => filesize($file) ?: 0,
            'created_at' => Carbon::createFromTimestamp(filemtime($file))->toIso8601String(),
        ], $files);

        // The timestamp is in the filename in Y-m-d_His form, so lexical sort == chronological.
        usort($backups, fn (array $a, array $b) => strcmp($b['name'], $a['name']));

        return $backups;
    }

    /** Enforces the retention setting. Returns how many files were deleted. */
    public function prune(): int
    {
        $retention = $this->settings->all()['retention'];

        if ($retention <= 0) {
            return 0;
        }

        $deleted = 0;

        foreach (array_slice($this->list(), $retention) as $backup) {
            if (@unlink($backup['path'])) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** Turns a bare filename from the UI into an absolute path inside the destination. */
    public function resolve(string $name): string
    {
        $destination = $this->requireDestination();

        if (basename($name) !== $name
            || ! str_starts_with($name, self::PREFIX)
            || ! str_ends_with($name, '.zip')) {
            throw new RuntimeException('That is not a valid backup file name.');
        }

        $path = $destination.DIRECTORY_SEPARATOR.$name;

        if (! is_file($path)) {
            throw new RuntimeException('That backup no longer exists.');
        }

        return $path;
    }

    /**
     * Replaces the live database and media with the contents of a backup.
     *
     * Ordering is the whole safety story: validate, verify, and snapshot BEFORE
     * writing anything. Steps 1-3 are non-destructive — a foreign or corrupt
     * backup is rejected with the live data still intact. From step 4 on, the
     * pre-restore snapshot is the undo path, and its location is surfaced in any
     * error message.
     *
     * Never queue this: the queue tables live inside the database being replaced.
     */
    public function restore(string $zipPath): void
    {
        if (! is_file($zipPath)) {
            throw new RuntimeException('That backup file could not be found.');
        }

        // 1. Open and identify.
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('That file is not a readable backup archive.');
        }

        $manifestRaw = $zip->getFromName('manifest.json');

        if ($manifestRaw === false || $zip->locateName('database.sqlite') === false) {
            $zip->close();

            throw new RuntimeException('That archive is not a backup — it has no manifest or database inside.');
        }

        $manifest = json_decode($manifestRaw, true);

        if (! is_array($manifest) || ($manifest['app'] ?? null) !== config('app.name')) {
            $zip->close();

            throw new RuntimeException('That backup belongs to a different application.');
        }

        // 2. Unpack to a staging area and verify the database before trusting it.
        $staging = $this->tempPath('restore-'.$this->timestamp());
        $this->deleteDirectory($staging);

        if (! @mkdir($staging, 0777, true) && ! is_dir($staging)) {
            $zip->close();

            throw new RuntimeException("Could not create the working folder: {$staging}");
        }

        $extracted = $zip->extractTo($staging);
        $zip->close();

        if (! $extracted) {
            $this->deleteDirectory($staging);

            throw new RuntimeException('The backup archive could not be unpacked.');
        }

        $stagedDb = $staging.DIRECTORY_SEPARATOR.'database.sqlite';

        try {
            $this->assertHealthyDatabase($stagedDb);
        } catch (\Throwable $e) {
            $this->deleteDirectory($staging);

            throw $e;
        }

        // 3. Undo point. Still non-destructive.
        $snapshot = $this->snapshot();

        // 4. Past here, the snapshot is the only way back.
        try {
            DB::disconnect();

            @unlink($this->databasePath.'-wal');
            @unlink($this->databasePath.'-shm');

            if (! @copy($stagedDb, $this->databasePath)) {
                throw new RuntimeException('Could not write the restored database into place.');
            }

            $stagedMedia = $staging.DIRECTORY_SEPARATOR.'media';

            if (is_dir($stagedMedia)) {
                $retired = $this->mediaPath.'.old-'.$this->timestamp();

                if (is_dir($this->mediaPath) && ! $this->moveDirectory($this->mediaPath, $retired)) {
                    throw new RuntimeException('Could not move the current media folder aside.');
                }

                if (! $this->moveDirectory($stagedMedia, $this->mediaPath)) {
                    // Put the originals back before giving up.
                    if (is_dir($retired)) {
                        $this->moveDirectory($retired, $this->mediaPath);
                    }

                    throw new RuntimeException('Could not write the restored media into place.');
                }

                $this->deleteDirectory($retired);
            }

            // Force NativeAppServiceProvider::firstRunSetup() to re-run migrate on the
            // next launch. Without this, restoring a backup taken on an older schema
            // into a newer app leaves the DB missing tables the code expects.
            @unlink(dirname($this->databasePath).DIRECTORY_SEPARATOR.'.migrated');
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'The restore failed partway through. Your previous data was saved first and is safe in: '.$snapshot,
                0,
                $e,
            );
        } finally {
            $this->deleteDirectory($staging);
        }
    }

    /**
     * Identifies the schema the backup was taken against. Same value
     * NativeAppServiceProvider::firstRunSetup() uses to decide whether to migrate.
     */
    public function schemaSignature(): string
    {
        $files = glob(base_path('database/migrations/*.php')) ?: [];
        sort($files);

        return md5(implode('|', array_map('basename', $files)));
    }

    private function requireDestination(): string
    {
        $destination = $this->settings->destination();

        if (! $destination) {
            throw new RuntimeException('No backup folder has been chosen yet.');
        }

        if (! is_dir($destination) || ! is_writable($destination)) {
            throw new RuntimeException("The backup folder is not available: {$destination}");
        }

        return $destination;
    }

    /**
     * Builds the zip at a staging path and only puts it at $target once it is
     * fully and successfully written.
     *
     * If ZipArchive were opened on $target directly, the file would exist at its
     * real, list()-matched name from the very first byte written — complete only
     * after close(). A kill -9, an OOM, or the user force-quitting the Electron
     * app mid-write would then leave a truncated file that list() presents as a
     * legitimate backup and prune() counts toward retention, potentially evicting
     * a good older backup to make room for it. Writing to $target.'.tmp' and
     * rename()-ing onto $target only after a successful close() means a crash can
     * leave at most an orphaned .tmp file (invisible to list(), since its glob
     * pattern requires a .zip suffix) — never a fake backup at the real name.
     */
    private function writeArchive(string $target): void
    {
        $staging = $target.'.tmp';
        $tempDb = $this->tempPath('db-'.$this->timestamp().'.sqlite');

        try {
            $this->vacuumInto($tempDb);

            $zip = new ZipArchive;

            if ($zip->open($staging, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Could not write the backup file: {$target}");
            }

            try {
                if (! @$zip->addFile($tempDb, 'database.sqlite')) {
                    throw new RuntimeException('Could not add the database to the backup.');
                }

                $mediaFiles = 0;

                foreach ($this->mediaFiles() as $absolute => $relative) {
                    // addFile() returns false rather than throwing when the source
                    // file has vanished or become unreadable since it was listed
                    // (a photo deleted or locked mid-backup). ZipArchive::close()
                    // can still succeed afterwards, so without this check a single
                    // missing file would yield a "successful" backup that is
                    // silently incomplete — exactly what this feature exists to
                    // prevent.
                    if (! @$zip->addFile($absolute, 'media/'.$relative)) {
                        throw new RuntimeException("Could not add a file to the backup: {$absolute}");
                    }

                    $mediaFiles++;
                }

                $zip->addFromString('manifest.json', json_encode([
                    'app' => config('app.name'),
                    'app_id' => config('nativephp.app_id'),
                    'app_version' => config('nativephp.version'),
                    'created_at' => Carbon::now()->toIso8601String(),
                    'schema_signature' => $this->schemaSignature(),
                    'db_bytes' => filesize($tempDb) ?: 0,
                    'media_files' => $mediaFiles,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                if (! $zip->close()) {
                    throw new RuntimeException("Could not finish writing the backup file: {$target}");
                }
            } catch (\Throwable $e) {
                // Never leave a half-written zip behind — it would look like a valid backup.
                @$zip->close();

                throw $e;
            }

            if (! @rename($staging, $target)) {
                throw new RuntimeException("Could not finalize the backup file: {$target}");
            }
        } catch (\Throwable $e) {
            @unlink($staging);

            throw $e;
        } finally {
            @unlink($tempDb);
        }
    }

    /**
     * Snapshots the live database through SQLite itself. VACUUM INTO is atomic and
     * WAL-safe: it needs no write lock and produces a single consistent file, so
     * there is no torn copy and no -wal/-shm to reconcile.
     */
    private function vacuumInto(string $target): void
    {
        @unlink($target);

        $pdo = new \PDO('sqlite:'.$this->databasePath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->prepare('VACUUM INTO ?')->execute([$target]);
    }

    /**
     * @return iterable<string, string> absolute path => path relative to the media root
     *
     * Protected (not private) so tests can override it to simulate a source
     * file vanishing between being listed and being added to the archive —
     * private methods aren't virtual, so a test subclass couldn't otherwise
     * intercept this without genuinely racing the filesystem.
     */
    protected function mediaFiles(): iterable
    {
        if (! is_dir($this->mediaPath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->mediaPath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->mediaPath) + 1));

            yield $file->getPathname() => $relative;
        }
    }

    /** Rejects a database that SQLite cannot read, or that is not one of ours. */
    private function assertHealthyDatabase(string $path): void
    {
        if (! is_file($path)) {
            throw new RuntimeException('That backup does not contain a database.');
        }

        try {
            $pdo = new \PDO('sqlite:'.$path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('integrity_check failed');
            }

            $hasMigrations = (int) $pdo
                ->query("SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = 'migrations'")
                ->fetchColumn();

            if ($hasMigrations === 0) {
                throw new RuntimeException('no migrations table');
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'The database inside that backup is corrupt or unreadable. Nothing was changed.',
                0,
                $e,
            );
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;

            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * Moves a directory tree from $from to $to.
     *
     * Tries an atomic rename() first — the normal case, since in production
     * both the media path and the restore staging area are derived from
     * storage_path() (see AppServiceProvider) and always share one filesystem.
     * Falls back to a recursive copy-then-delete when rename() cannot do this
     * atomically (e.g. the two paths sit on different drives/volumes), so a
     * restore does not fail outright just because of where a path happens to
     * live.
     */
    private function moveDirectory(string $from, string $to): bool
    {
        if (@rename($from, $to)) {
            return true;
        }

        if (! $this->copyDirectory($from, $to)) {
            $this->deleteDirectory($to);

            return false;
        }

        $this->deleteDirectory($from);

        return true;
    }

    private function copyDirectory(string $from, string $to): bool
    {
        if (! is_dir($to) && ! @mkdir($to, 0777, true) && ! is_dir($to)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $target = $to.DIRECTORY_SEPARATOR.substr($item->getPathname(), strlen($from) + 1);

            if ($item->isDir()) {
                if (! is_dir($target) && ! @mkdir($target, 0777, true) && ! is_dir($target)) {
                    return false;
                }
            } elseif (! @copy($item->getPathname(), $target)) {
                return false;
            }
        }

        return true;
    }

    private function tempPath(string $name): string
    {
        $dir = storage_path('app/backup-tmp');

        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create the working folder: {$dir}");
        }

        return $dir.DIRECTORY_SEPARATOR.$name;
    }

    private function timestamp(): string
    {
        return Carbon::now()->format('Y-m-d_His');
    }

    /**
     * Escapes glob() metacharacters (* ? [ ]) in a literal path so a destination
     * folder whose name happens to contain one of them is matched as text, not
     * interpreted as a pattern. Each special character is wrapped in a one-char
     * bracket class: [*], [?] and [[] match themselves literally, and []] matches
     * a literal ] because a ] immediately after [ is taken literally rather than
     * closing the class.
     */
    private function escapeGlobPath(string $path): string
    {
        return preg_replace('/([*?\[\]])/', '[$1]', $path);
    }
}
