<?php

namespace App\Services\Backup;

use Illuminate\Support\Carbon;
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
        $files = glob($destination.DIRECTORY_SEPARATOR.self::PREFIX.'*.zip') ?: [];

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

    public function restore(string $zipPath): void
    {
        throw new RuntimeException('Not implemented yet.');
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

    private function writeArchive(string $target): void
    {
        $tempDb = $this->tempPath('db-'.$this->timestamp().'.sqlite');

        $this->vacuumInto($tempDb);

        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tempDb);

            throw new RuntimeException("Could not write the backup file: {$target}");
        }

        try {
            $zip->addFile($tempDb, 'database.sqlite');

            $mediaFiles = 0;

            foreach ($this->mediaFiles() as $absolute => $relative) {
                $zip->addFile($absolute, 'media/'.$relative);
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
            @unlink($target);

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

    /** @return iterable<string, string> absolute path => path relative to the media root */
    private function mediaFiles(): iterable
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
}
