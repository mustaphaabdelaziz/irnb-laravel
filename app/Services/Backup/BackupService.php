<?php

namespace App\Services\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Facades\QueueWorker;
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

    /**
     * How long to wait for another process to let go of the database's -wal/-shm.
     *
     * Stopping the queue worker is asynchronous — see stopQueueWorker() — so the
     * child is still being torn down when QueueWorker::down() returns, and Windows
     * holds its file handles until the process is fully reaped. Checking once,
     * immediately, would race the kill and abort a restore that was about to work.
     */
    private const HANDLE_RELEASE_TIMEOUT_MS = 5000;

    private const HANDLE_RELEASE_POLL_MS = 100;

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
     * Within step 4 the point of no return is a single rename() (4c). Everything
     * before it — staging the incoming database, stopping the queue worker, folding the
     * WAL back into the database — either succeeds or aborts with the live database
     * untouched, and there is no longer any path that writes over the live file in place,
     * nor any that DELETES anything the live database is made of. That is deliberate: a
     * restore that cannot get exclusive hold of the database must FAIL, loudly, with the
     * data intact. It must never half-succeed, and it must never quietly subtract.
     *
     * Never queue this: the queue tables live inside the database being replaced.
     *
     * @return string|null the superseded media folder, when the restore SUCCEEDED but
     *                     that folder could not be deleted afterwards and has to be
     *                     removed by hand. null on a clean restore. A failed restore
     *                     throws — success is never signalled with an exception.
     */
    public function restore(string $zipPath): ?string
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

            $mediaFiles = (int) ($manifest['media_files'] ?? 0);

            // The converse of the empty-media case below, and just as damaging. The
            // manifest says the club HAD photos, but the archive carries no media/ tree:
            // the zip is truncated, or was built by something that dropped it. Keying the
            // media swap off "is there a media/ directory in staging?" would then restore
            // the database on its own and silently skip the media — handing the club rows
            // that point at photos which are in neither the backup nor (after the swap)
            // the live tree. Checked in staging, before the snapshot, so it costs nothing.
            if ($mediaFiles > 0 && ! is_dir($stagedMedia)) {
                throw new RuntimeException(
                    "That backup is incomplete: it says it holds {$mediaFiles} media file(s), "
                    .'but there are none inside the archive. Nothing was changed.'
                );
            }

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
            if ($mediaFiles === 0 && ! is_dir($stagedMedia)
                && ! @mkdir($stagedMedia, 0777, true) && ! is_dir($stagedMedia)) {
                throw new RuntimeException("Could not prepare the restored media folder: {$stagedMedia}");
            }

            // 3. Undo point. Still non-destructive.
            $snapshot = $this->snapshot();

            // 4. Past here, the snapshot is the only way back.
            $strandedMedia = null;
            $retiredMedia = null;
            $incoming = $this->databasePath.'.new';

            // The point of no return, tracked explicitly, because the error message that
            // comes out of the catch below depends entirely on which side of it we failed
            // on — and getting that wrong is not cosmetic. See the catch.
            $swapped = false;

            try {
                // 4a. Land the incoming database BESIDE the live one, before anything is
                // released or removed. A failure here — a full disk, say; the snapshot zip
                // was just written to this same drive — has then destroyed nothing.
                if (! @copy($stagedDb, $incoming)) {
                    throw new RuntimeException('Could not write the restored database into place.');
                }

                // 4b. Take the live database out of everyone's hands, and PROVE it — without
                // destroying anything to do so. Throws if it cannot, with the live database
                // still whole, WAL included.
                $this->releaseDatabase();

                // 4c. The swap: one rename(), and nothing else.
                //
                // There used to be a copy()-over-the-live-file fallback here, on the
                // grounds that Windows refuses to rename() over a file another process
                // holds open (SQLite does not open with FILE_SHARE_DELETE) and the queue
                // worker always holds this one — so a bare rename() could never succeed in
                // the packaged app. The premise was right; the conclusion was catastrophic.
                //
                // copy() writes the backup's bytes over the live *main* database file while
                // leaving the stale -wal (full of the OLD database's pages) on disk and the
                // worker's -shm wal-index still declaring it current. The worker never sees
                // the swap — in WAL mode its cache is validated against the shm wal-index,
                // not the main file's change counter — and when its handle finally closes
                // (the relaunch straight after a "successful" restore is exactly what closes
                // it) it becomes the last connection and checkpoints that stale WAL back
                // over the freshly restored database, reverting it. integrity_check says
                // "ok". The restore reports success, the media really is the backup's, and
                // the database is not: rows then reference photos that no longer exist.
                //
                // So the fallback is gone, and the rename() is load-bearing in a second way:
                // Windows lets it succeed ONLY when nobody holds the database open, which
                // makes it the final proof that 4b did its job. If the worker is somehow
                // still alive, this fails — and a loud failure with the live data intact is
                // the correct outcome. A "successful" no-op is not.
                if (! @rename($incoming, $this->databasePath)) {
                    throw new RuntimeException(
                        'The database file is still in use, so the restored database could not be put into place.'
                    );
                }

                // Crossed. Everything from here on fails LOUDLY and points at the snapshot;
                // everything before it fails saying, truthfully, that nothing was changed.
                $swapped = true;

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
                //
                // The path goes in the message, not just in the exception. The outer catch
                // is the only text the user ever sees, and a retry will hit this same locked
                // marker, so "here is the file, delete it" is the difference between a fix
                // and a loop.
                $marker = dirname($this->databasePath).DIRECTORY_SEPARATOR.'.migrated';

                if (is_file($marker) && ! @unlink($marker) && is_file($marker)) {
                    throw new RuntimeException(
                        "The migration marker could not be removed: {$marker}. Please delete that file "
                        .'yourself before trying the restore again.'
                    );
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
                // Safe on every path that reaches here, and only because the copy()-over-the-
                // live-file fallback is gone: the swap is now a single rename(), which is
                // all-or-nothing. Either it succeeded — and there is nothing left at <db>.new
                // for this to remove — or the live database was never touched and <db>.new is
                // a redundant second copy of data that is still sitting in the backup archive.
                // There is no longer any state in which the live database is torn or
                // half-swapped, so this can never delete the last whole copy of anything.
                @unlink($incoming);

                // The cause is carried in the text, not just chained as `previous`. Only this
                // outer message is ever shown, so a reason that lives solely in $e — "the
                // -wal is still open", the path of a migration marker that must be deleted
                // before retrying — would leave the user staring at the snapshot line with no
                // idea what to do, and walk them into a retry that fails the same way.
                //
                // But WHICH story is told matters just as much as the cause. This used to
                // open with "The restore failed partway through." on every path, including
                // the ones where nothing had run at all — a staging copy that could not be
                // written, a database that could not be taken out of use — whose own text
                // said, correctly, that nothing was changed. The message contradicted itself
                // in consecutive sentences, and a user who cannot tell which half to believe
                // cannot tell whether they need to go and restore the snapshot. That is not a
                // wording nit: it is what would have let the WAL deletion above go unnoticed,
                // by telling the club "nothing happened" on the very run that ate their day's
                // data. So the two cases are now said in two different voices.
                if ($swapped) {
                    // Past the point of no return: the database on disk really is the backup's
                    // now, and the media may be in any state. The snapshot is the way back and
                    // the message's only job is to point at it.
                    $message = 'The restore failed partway through. '.$e->getMessage()
                        .' Your previous data was saved first and is safe in: '.$snapshot;

                    if ($strandedMedia !== null) {
                        $message .= ' Your media files could not be put back automatically — they are in: '.$strandedMedia;
                    }
                } else {
                    // Nothing was swapped, and that is a guarantee rather than a hope: step 4a
                    // only writes to <db>.new, step 4b only ever checkpoints (it deletes
                    // nothing), and the rename() is all-or-nothing. The live database and media
                    // are exactly what they were when the user pressed the button. Say that
                    // plainly, and say what to do about it.
                    //
                    // The snapshot is still named — as a belt-and-braces reference rather than
                    // a rescue instruction. It costs one line, and it is the thing that would
                    // save them if this reasoning were ever wrong.
                    $message = $e->getMessage()
                        .' Nothing was changed — your data is exactly as it was. Please close the '
                        .'application completely, reopen it, and try the restore again. A snapshot of '
                        .'your data was saved before the restore started, just in case, in: '.$snapshot;
                }

                throw new RuntimeException($message, 0, $e);
            }

            // The restore itself is complete and correct. All that is left is removing the
            // superseded media tree — but "left it behind" cannot be swallowed the way a
            // void deleteDirectory() used to swallow it. That directory is a full duplicate
            // of every photo the club had, nothing in the app ever prunes it, and because
            // its name is only second-granular, a second restore inside the same second
            // would find the name taken, fail to move the live media aside, and abort with
            // the database already swapped.
            //
            // So it is reported — but NOT by throwing. This is a success with a caveat, and
            // an exception cannot say that: a caller has no way to tell it apart from a real
            // failure except by string-matching the message, so it takes the failure branch
            // and skips its success path — including the App::relaunch() that a restore
            // depends on — leaving the app running against a database that has just been
            // swapped out underneath it. The leftover comes back as a return value instead,
            // and the caller decides what to tell the user.
            if ($retiredMedia !== null && ! $this->deleteDirectory($retiredMedia)) {
                Log::warning('The restore succeeded, but the superseded media folder could not be deleted.', [
                    'leftover' => $retiredMedia,
                    'snapshot' => $snapshot,
                ]);

                return $retiredMedia;
            }

            return null;
        } finally {
            $this->deleteDirectory($staging);
        }
    }

    /**
     * Takes the live database out of every process's hands, and proves that it did —
     * WITHOUT destroying anything to get there.
     *
     * Called BEFORE the swap, and throws rather than pressing on: at the point it runs
     * nothing destructive has happened yet — the live database is whole, the incoming
     * one is staged beside it, and the pre-restore snapshot is on disk — so aborting
     * here is free. Pressing on is what corrupts the club's data.
     *
     * The -wal is the whole story here, and it used to be @unlink()ed.
     *
     * QueueWorker::down() KILLS the worker; a killed process never checkpoints. The -wal
     * it leaves behind therefore holds transactions the club has already COMMITTED and
     * which are not in the .sqlite yet — and in the packaged app that file is essentially
     * never empty, because the worker holds a connection for the app's entire life, so
     * the web process's per-request close is never the last connection and never triggers
     * a checkpoint. Everything the user entered since SQLite's last automatic checkpoint
     * (~1000 pages) lives ONLY in that file.
     *
     * Deleting it reverts the club to that last auto-checkpoint. And the deletion
     * SUCCEEDS the moment the killed worker's handles are released — while the rename()
     * that follows still fails if any non-SQLite reader (Defender, the search indexer, a
     * respawned worker) so much as has the main file open for reading, because that is a
     * share mode without FILE_SHARE_DELETE. The two do not fail under the same
     * conditions, so the restore could destroy the WAL and then abort saying "Nothing was
     * changed" over the top of it. Verified on Windows: both unlinks succeed, this method
     * passes, the rename fails, and the row that was only in the WAL is gone.
     *
     * So the WAL is CHECKPOINTED, never deleted. That merges its pages INTO the database
     * — the same thing SQLite does routinely on its own — and closing our connection is
     * what removes the sidecars, which SQLite only does when nobody else is holding them.
     * If someone still is, they stay, and this aborts. Non-destructively, either way.
     */
    private function releaseDatabase(): void
    {
        $this->stopQueueWorker();

        // Our own connection. It cannot close anyone else's, which is what the queue
        // worker above is for.
        DB::disconnect();

        // The wait is not defensive padding, and neither is checkpointing INSIDE it.
        //
        // Stopping the queue worker is asynchronous (see stopQueueWorker()): down() returns
        // as soon as the Electron runtime has accepted the request, and the child is torn
        // down after that. So a single checkpoint here would usually run while the worker is
        // still alive — leaving our connection not the last one, the sidecars still on disk,
        // and nothing to remove them afterwards, since a killed process never closes its
        // connection cleanly. That would abort every restore in the packaged app. Retrying
        // the checkpoint as we poll means that the moment the worker is actually reaped, the
        // next attempt merges its orphaned WAL and its close clears both sidecars.
        //
        // Every iteration is a merge or a no-op. Nothing in this loop can lose a byte.
        $deadline = microtime(true) + self::HANDLE_RELEASE_TIMEOUT_MS / 1000;

        do {
            if ($this->remainingSidecars() === []) {
                return;
            }

            $this->checkpointWal();

            if ($this->remainingSidecars() === []) {
                return;
            }

            usleep(self::HANDLE_RELEASE_POLL_MS * 1000);
        } while (microtime(true) < $deadline);

        $remaining = $this->remainingSidecars();

        if ($remaining === []) {
            return;
        }

        // Someone still has the database open, so its WAL cannot be folded in and the swap
        // cannot go ahead. Nothing has been destroyed to find that out: the sidecars are
        // still there, holding whatever they held. The caller turns this into the user's
        // "nothing was changed" message.
        throw new RuntimeException(
            'The database is still open in another program, so it could not be taken out of use: '
            ."{$remaining[0]}."
        );
    }

    /**
     * Folds the write-ahead log into the database file itself.
     *
     * TRUNCATE rather than PASSIVE so the WAL is emptied rather than merely copied
     * forward, and so a WAL orphaned by a killed worker cannot be replayed again later.
     *
     * Failure is not fatal and is not thrown: this is one attempt among many, and the
     * "are the sidecars actually gone?" check in releaseDatabase() is the real gate. A
     * checkpoint that comes back busy — someone else is still reading — simply leaves the
     * sidecars in place, which is precisely what makes that check abort.
     */
    private function checkpointWal(): void
    {
        $pdo = null;

        try {
            $pdo = new \PDO('sqlite:'.$this->databasePath, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (\Throwable) {
            // Fall through: releaseDatabase()'s poll is still the gate.
        } finally {
            // Drop OUR handle whatever happened — in a finally, because if the checkpoint
            // threw, this connection would otherwise stay open for the rest of the method
            // and hold the -wal and the -shm itself, and the poll would then be looking at
            // our own connection and abort a restore that was about to work.
            //
            // Closing it is also what REMOVES the sidecars: SQLite deletes them when the
            // last connection closes. That is what makes this a merge rather than a
            // deletion — the data goes into the database on the way out.
            $pdo = null;
        }
    }

    /**
     * The -wal/-shm still sitting beside the live database, -wal first so it is the one
     * an error message names.
     *
     * @return array<int, string>
     */
    private function remainingSidecars(): array
    {
        $remaining = [];

        foreach (['-wal', '-shm'] as $suffix) {
            $sidecar = $this->databasePath.$suffix;

            clearstatcache(true, $sidecar);

            if (file_exists($sidecar)) {
                $remaining[] = $sidecar;
            }
        }

        return $remaining;
    }

    /**
     * Asks the NativePHP runtime to stop the queue worker — the OTHER process holding
     * the live database open.
     *
     * NativeServiceProvider::configureApp() calls fireUpQueueWorkers() on every
     * non-console boot (line 148), and QueueWorker::up() spawns `queue:work` as a
     * *persistent* child process (QueueWorker.php:31-46) against
     * config('nativephp-internal.database_path') — the exact file being restored. It
     * keeps handles open on the .sqlite, the -wal and the -shm for the life of the app,
     * and DB::disconnect() cannot reach into another process to close them.
     *
     * This is a REQUEST, not a guarantee, and the code downstream treats it as one.
     * QueueWorker::down() posts to the Electron runtime's HTTP API and hands nothing
     * back: ChildProcess::stop() (ChildProcess.php:132-137) discards the response and
     * returns void. So it cannot tell us whether the child actually died, whether the
     * runtime honoured the request for a process registered as persistent rather than
     * respawning it, or — since the kill is asynchronous and Windows keeps a dead
     * process's handles until it is reaped — whether it has died YET. A failure to
     * reach the runtime at all is therefore logged, not thrown: it is not the evidence
     * that matters.
     *
     * The evidence that matters is on the filesystem. releaseDatabase() will not continue
     * until the -wal and -shm are provably gone — and it gets them gone by CHECKPOINTING
     * the WAL into the database, which SQLite only lets the last connection finish (and
     * which is the only safe move anyway: this kill leaves behind a WAL full of committed
     * rows that a worker killed mid-life never wrote back). The rename() that follows can
     * only succeed on Windows if nobody holds the database open at all. Between them, a
     * worker that ignored this, respawned, or is merely slow to die produces a clean abort
     * with the live data untouched — never a silent half-restore, and never a WAL deleted
     * out from under the club's most recent work.
     *
     * Guarded on nativephp-internal.running: outside the packaged app — the web build,
     * artisan, the test suite — there is no runtime to talk to and no worker to stop.
     */
    private function stopQueueWorker(): void
    {
        if (! config('nativephp-internal.running')) {
            return;
        }

        // Every configured worker, not just 'default': they all run `queue:work` against
        // this one database, so any of them left standing holds the swap up.
        $aliases = array_keys((array) config('nativephp.queue_workers', []));

        try {
            foreach ($aliases ?: ['default'] as $alias) {
                QueueWorker::down($alias);
            }
        } catch (\Throwable $e) {
            Log::warning('The restore could not ask the queue worker to stop; continuing to the WAL check.', [
                'exception' => $e->getMessage(),
            ]);
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
