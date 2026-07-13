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

        // Everything below runs inside the finally, so the staging directory is removed
        // on EVERY exit path — including a failing snapshot(). Staging holds a plaintext
        // copy of the whole database and every photo, and nothing else in the app ever
        // prunes storage/app/backup-tmp, so a leak here accumulates a full unencrypted
        // copy of the club's data per failed restore.
        try {
            try {
                $extracted = $zip->extractTo($staging);
            } finally {
                // extractTo() returns false for most failures but *throws* for some
                // (libzip surfaces certain errors as exceptions), and a throw here would
                // otherwise skip the close() below and leak the archive handle for the
                // rest of the request — on Windows that also pins the zip file itself,
                // so the user could not move or delete the backup they just tried to
                // restore. close() belongs on every exit path, not the happy one.
                $zip->close();
            }

            if (! $extracted) {
                throw new RuntimeException('The backup archive could not be unpacked.');
            }

            $stagedDb = $staging.DIRECTORY_SEPARATOR.'database.sqlite';
            $stagedMedia = $staging.DIRECTORY_SEPARATOR.'media';

            $this->assertHealthyDatabase($stagedDb);

            // A backup taken while the club had zero photos carries no media/ entry at
            // all, so there is nothing in staging to swap in. Skipping the media swap in
            // that case would replace the database and leave TODAY's photos standing:
            // the club ends up with rows that reference nothing and files the backup says
            // never existed — not "exactly as it was". The manifest counted the media
            // files at backup time, which is what tells "the backup genuinely had none"
            // apart from "the archive is missing its media". So materialise the empty tree
            // here and let the ordinary swap below wipe the live one, exactly as it would
            // for any other media set. Done in staging, before the snapshot, so a failure
            // to prepare it is still non-destructive.
            if (($manifest['media_files'] ?? null) === 0 && ! is_dir($stagedMedia)
                && ! @mkdir($stagedMedia, 0777, true) && ! is_dir($stagedMedia)) {
                throw new RuntimeException("Could not prepare the restored media folder: {$stagedMedia}");
            }

            // 3. Undo point. Still non-destructive.
            $snapshot = $this->snapshot();

            // 4. Past here, the snapshot is the only way back.
            $strandedMedia = null;
            $retiredMedia = null;

            try {
                // Closes the app's own connection so it is not the thing holding the
                // database open. It cannot close anyone else's — see the swap below.
                DB::disconnect();

                // Land the incoming database beside the live one BEFORE destroying
                // anything. Unlinking -wal/-shm first would turn a failed copy (the
                // snapshot zip was just written to this same drive, which is now full)
                // from "copy failed, nothing lost" into "copy failed, and every
                // transaction still sitting in the uncheckpointed WAL is gone".
                $incoming = $this->databasePath.'.new';

                if (! @copy($stagedDb, $incoming)) {
                    @unlink($incoming);

                    throw new RuntimeException('Could not write the restored database into place.');
                }

                @unlink($this->databasePath.'-wal');
                @unlink($this->databasePath.'-shm');

                // rename() first, copy() as the fallback — and the fallback is NOT
                // belt-and-braces, it is the path production actually takes. Do not
                // "simplify" this back to a bare rename():
                //
                // NativePHP forces `queue.default = database` and fires up its queue
                // workers on every non-console boot (NativeServiceProvider:144,148),
                // pointing that connection at config('nativephp-internal.database_path')
                // (NativeServiceProvider:201) — the very file this method is restoring.
                // config/nativephp.php declares a `default` worker, and QueueWorker::up()
                // spawns it as a *persistent* child process running `queue:work`, which
                // keeps a PDO handle open on the database for the whole life of the app.
                // The DB::disconnect() above closes this process's connection; it has no
                // way to close another process's. Windows will not rename a file over one
                // that anybody still holds open (SQLite does not open with
                // FILE_SHARE_DELETE) — verified: it fails with "Accès refusé (code 5)"
                // — so a bare rename() can never once succeed in the packaged app. Every
                // restore would reach the point of no return, fail, and send the user off
                // to recover from a snapshot they did not need, leaving another full copy
                // of the database and every photo on the drive each time they retried.
                //
                // copy() over an open file DOES succeed on Windows, and by this line it is
                // safe: the incoming database is already whole at $incoming (so a failure
                // mid-copy cannot lose data that was not already captured), and the
                // pre-restore snapshot is on disk. rename() stays first because it is
                // atomic when it can run at all; copy() is what makes the restore possible
                // when it cannot. The app relaunches straight after a successful restore
                // (the controller calls App::relaunch()), so the worker's now-stale handle
                // is short-lived.
                if (! @rename($incoming, $this->databasePath) && ! @copy($incoming, $this->databasePath)) {
                    @unlink($incoming);

                    throw new RuntimeException('Could not write the restored database into place.');
                }

                // No-op after a successful rename; removes the staging copy after a
                // successful fallback copy. Either way, nothing is left at <db>.new.
                @unlink($incoming);

                // The database on disk is now the backup's, possibly on an older schema.
                // Drop the marker IMMEDIATELY — before the media swap, which can still
                // fail. NativeAppServiceProvider::firstRunSetup() skips `migrate` when
                // this marker's signature matches the current migration set, so leaving
                // it behind after the database has already been replaced would let the
                // app boot on an old schema while claiming to be current: every request
                // then dies on a missing table, and the self-heal is defeated in exactly
                // the case it exists for. An unlink() that quietly fails puts us in that
                // same state, so it is checked: better a loud failure naming the snapshot
                // than an app that boots into a schema mismatch on the next launch.
                $marker = dirname($this->databasePath).DIRECTORY_SEPARATOR.'.migrated';

                if (is_file($marker) && ! @unlink($marker) && is_file($marker)) {
                    throw new RuntimeException("Could not remove the migration marker: {$marker}");
                }

                if (is_dir($stagedMedia)) {
                    $retired = $this->mediaPath.'.old-'.$this->timestamp();

                    // rename() and nothing else. There is deliberately no copy-then-delete
                    // fallback: the staging directory (storage_path('app/backup-tmp')) and
                    // the media root (storage_path('app/public')) are both under
                    // storage_path(), so they are on one volume by construction and rename()
                    // can always do the job atomically. A fallback that copies into a target
                    // it did not create merges the backup's media over media it failed to
                    // remove — on Windows a single open handle (antivirus, the indexer, an
                    // image viewer) is enough to make rename() fail — and then reports
                    // success, leaving old ∪ backup with no error. A plain rename() fails
                    // loudly with the live tree whole, which is the only safe way to be wrong.
                    if (is_dir($this->mediaPath) && ! @rename($this->mediaPath, $retired)) {
                        throw new RuntimeException('Could not move the current media folder aside.');
                    }

                    if (! @rename($stagedMedia, $this->mediaPath)) {
                        // Put the originals back before giving up. If even that fails, the
                        // media survives ONLY at $retired, so the error has to name it.
                        if (is_dir($retired) && ! @rename($retired, $this->mediaPath)) {
                            $strandedMedia = $retired;
                        }

                        throw new RuntimeException('Could not write the restored media into place.');
                    }

                    // The restored tree is confirmed in place, so the old one is now dead
                    // weight — but it is only cleaned up below, OUTSIDE this try. Failing
                    // to delete it is not a failed restore, and must not be reported as
                    // one: the data is all where it should be.
                    $retiredMedia = $retired;
                }
            } catch (\Throwable $e) {
                $message = 'The restore failed partway through. Your previous data was saved first and is safe in: '.$snapshot;

                if ($strandedMedia !== null) {
                    $message .= ' Your media files could not be put back automatically — they are in: '.$strandedMedia;
                }

                throw new RuntimeException($message, 0, $e);
            }

            // The restore itself is complete and correct. All that is left is removing the
            // superseded media tree — but "left it behind" cannot be swallowed the way a
            // void deleteDirectory() used to swallow it. That directory is a full duplicate
            // of every photo the club had, nothing in the app ever prunes it, and because
            // its name is only second-granular, a second restore inside the same second
            // would find the name taken, fail to move the live media aside, and abort with
            // the database already swapped. So it is reported — accurately: the restore
            // SUCCEEDED, and the leftover just has to be deleted by hand.
            if ($retiredMedia !== null && ! $this->deleteDirectory($retiredMedia)) {
                throw new RuntimeException(
                    'Your data was restored successfully, but the folder holding your previous media files '
                    .'could not be removed afterwards. Nothing will clean it up, so please delete it yourself: '
                    .$retiredMedia
                    .' (Your pre-restore snapshot is in: '.$snapshot.')',
                );
            }
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

                // Like addFile(), addFromString() reports failure by returning false
                // rather than throwing. A backup with no manifest is rejected by
                // restore() as "not a backup", so an unchecked failure here would
                // hand the user a zip that only reveals itself as useless on the day
                // they need it.
                $manifestAdded = @$zip->addFromString('manifest.json', json_encode([
                    'app' => config('app.name'),
                    'app_id' => config('nativephp.app_id'),
                    'app_version' => config('nativephp.version'),
                    'created_at' => Carbon::now()->toIso8601String(),
                    'schema_signature' => $this->schemaSignature(),
                    'db_bytes' => filesize($tempDb) ?: 0,
                    'media_files' => $mediaFiles,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                if (! $manifestAdded) {
                    throw new RuntimeException('Could not add the manifest to the backup.');
                }

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

    /**
     * Removes a directory tree. Returns whether it is actually gone.
     *
     * The bool is not decoration: on Windows a single open handle (antivirus, the
     * indexer, an image viewer) is enough to make an unlink or an rmdir fail, and a
     * caller that cannot tell success from failure will happily report a clean restore
     * while a full duplicate of the club's photos survives on disk. Deletion continues
     * past a failing entry rather than bailing out — a partially removed tree is no
     * worse than an untouched one here, and pressing on removes as much as possible —
     * but every failure is carried through to the result.
     */
    private function deleteDirectory(string $dir): bool
    {
        if (! is_dir($dir)) {
            return true;
        }

        $deleted = true;

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;

            // Written so the delete always runs before the && — never let short-circuit
            // evaluation skip an entry just because an earlier one failed.
            $entryDeleted = is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
            $deleted = $entryDeleted && $deleted;
        }

        return @rmdir($dir) && $deleted;
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
