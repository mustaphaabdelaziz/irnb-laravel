<?php

namespace App\Services\Backup;

use App\Support\ProductBrand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

/**
 * Creates and restores full backups: the SQLite database, the uploaded media
 * tree and the configured private folders (player documents, minutes, receipts),
 * in a single zip.
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
     * Stopping the queue worker is asynchronous — see LiveDatabase::stopQueueWorker() — so the
     * child is still being torn down when QueueWorker::down() returns, and Windows
     * holds its file handles until the process is fully reaped. Checking once,
     * immediately, would race the kill and abort a restore that was about to work.
     */
    private const HANDLE_RELEASE_TIMEOUT_MS = 5000;

    private const HANDLE_RELEASE_POLL_MS = 100;

    /**
     * @param  array<string, string>  $privateRoots  archive name => live folder. Files that must
     *                                               never be public (player documents, board minutes, receipts) live outside the
     *                                               media tree; each root is carried, checked and swapped exactly like
     *                                               the media tree.
     */
    public function __construct(
        private BackupSettings $settings,
        private string $databasePath,
        private string $mediaPath,
        private array $privateRoots = [],
    ) {
        foreach (array_keys($privateRoots) as $name) {
            if (! is_string($name) || ! preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
                throw new \InvalidArgumentException("Not a usable private backup root name: {$name}");
            }
        }
    }

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
     * @return string|null the superseded media or private folder(s), when the restore
     *                     SUCCEEDED but they could not be deleted afterwards and have to
     *                     be removed by hand (several are joined with ' ; '). null on a
     *                     clean restore. A failed restore throws — success is never
     *                     signalled with an exception.
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

        // Identity is EITHER-OR, and deliberately so.
        //
        // This used to be app name alone. But APP_NAME is .env-settable — this codebase
        // builds both the IRNB instance and a generic white-label from one source, and a
        // club can rename its own app — so a rename between taking a backup and needing it
        // told the club "That backup belongs to a different application" about its own data,
        // with no way past it.
        //
        // app_id is the stabler identity and has been in the manifest all along. It is not a
        // straight swap, though: NATIVEPHP_APP_ID is not reliably present in the packaged
        // runtime's env, which is exactly why the name was picked originally. So either one
        // matching is enough to accept, and only a backup that matches NEITHER — or one whose
        // manifest carries neither, and so identifies nothing at all — is refused. That still
        // turns away a genuinely foreign archive, which agrees with us on neither count.
        //
        // The app was renamed to ASIREM, so a name it shipped under before counts as ours too:
        // otherwise every backup taken before the rename would be refused by the renamed app.
        $matches = is_array($manifest) && (
            $this->identityMatches($manifest['app_id'] ?? null, config('nativephp.app_id'))
            || $this->identityMatches($manifest['app'] ?? null, config('app.name'))
            || in_array($manifest['app'] ?? null, ProductBrand::LEGACY_APP_NAMES, true)
        );

        if (! $matches) {
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

            // The private roots get the same two checks as the media tree, here in
            // staging and before the snapshot, so a truncated archive is still refused
            // with nothing changed.
            $stagedPrivate = $this->stagePrivateRoots($staging, is_array($manifest) ? $manifest : []);

            // 3. Undo point. Still non-destructive. It carries the private roots too
            // (writeArchive() includes them), so it undoes every folder swapped below.
            $snapshot = $this->snapshot();

            // 4. Past here, the snapshot is the only way back.
            $strandedMedia = null;
            $strandedPrivate = [];
            $retiredMedia = null;
            $retiredPrivate = [];

            // Every folder already swapped: live => [its staged source, the retired
            // original or null]. A later swap that fails puts ALL of these back, so the
            // file trees are never left half the backup's and half today's.
            $swappedFolders = [];
            $incoming = $this->databasePath.'.new';

            // The point of no return, tracked explicitly, because the error message that
            // comes out of the catch below depends entirely on which side of it we failed
            // on — and getting that wrong is not cosmetic. See the catch.
            $swapped = false;

            // Whether the queue worker was actually taken down, which is NOT the same
            // question as "did we abort before the swap?" — see the catch.
            $stopped = false;

            try {
                // 4a. Land the incoming database BESIDE the live one, before anything is
                // released or removed. A failure here — a full disk, say; the snapshot zip
                // was just written to this same drive — has then destroyed nothing. Note
                // that it also runs BEFORE the worker is stopped, which is why the restart
                // in the catch cannot simply key off !$swapped.
                if (! @copy($stagedDb, $incoming)) {
                    throw new RuntimeException('Could not write the restored database into place.');
                }

                // 4b. Take the live database out of everyone's hands, and PROVE it — without
                // destroying anything to do so. Throws if it cannot, with the live database
                // still whole, WAL included.
                //
                // Flagged BEFORE the call, not after: releaseDatabase() stops the worker as
                // its very first act and can still throw afterwards (the WAL never clears).
                // Setting this on the far side would miss exactly that case — the one where
                // the worker is most definitely down and most definitely needs putting back.
                // Claiming we stopped it when the call throws before it got that far is the
                // harmless direction to be wrong: restartQueueWorker() is best-effort and
                // idempotent enough for the runtime, whereas failing to restart a worker we
                // really did kill silently ends automatic backups for the session.
                $stopped = true;
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
                    $swappedFolders[$this->mediaPath] = [$stagedMedia, is_dir($retired) ? $retired : null];
                }

                // The private roots, one by one, exactly like the media tree above:
                // rename() only, the originals put back on failure, never a copy that
                // could merge the backup's files into today's.
                foreach ($stagedPrivate as $name => $stagedDir) {
                    $live = $this->privateRoots[$name];
                    $retired = $live.'.old-'.$this->timestamp();
                    $parent = dirname($live);

                    if (! is_dir($parent) && ! @mkdir($parent, 0777, true) && ! is_dir($parent)) {
                        throw new RuntimeException("Could not prepare the folder that holds {$name}: {$parent}");
                    }

                    if (is_dir($live) && ! @rename($live, $retired)) {
                        throw new RuntimeException("Could not move the current {$name} folder aside.");
                    }

                    if (! @rename($stagedDir, $live)) {
                        if (is_dir($retired) && ! @rename($retired, $live)) {
                            $strandedPrivate[] = $retired;
                        }

                        throw new RuntimeException("Could not write the restored {$name} folder into place.");
                    }

                    $swappedFolders[$live] = [$stagedDir, is_dir($retired) ? $retired : null];

                    if (is_dir($retired)) {
                        $retiredPrivate[] = $retired;
                    }
                }
            } catch (\Throwable $e) {
                // Put back every folder that was already swapped, newest first: the
                // restored copy goes back into staging (removed in the finally), then
                // the original returns to its place. A folder whose original cannot be
                // put back is named in the message — it is the only copy outside the
                // snapshot. Empty unless a swap failed after an earlier one succeeded.
                foreach (array_reverse($swappedFolders, true) as $live => [$source, $original]) {
                    $restoredMovedOut = @rename($live, $source);

                    if ($original !== null && (! $restoredMovedOut || ! @rename($original, $live))) {
                        if ($live === $this->mediaPath) {
                            $strandedMedia = $original;
                        } else {
                            $strandedPrivate[] = $original;
                        }
                    }
                }

                // Safe on every path that reaches here, and only because the copy()-over-the-
                // live-file fallback is gone: the swap is now a single rename(), which is
                // all-or-nothing. Either it succeeded — and there is nothing left at <db>.new
                // for this to remove — or the live database was never touched and <db>.new is
                // a redundant second copy of data that is still sitting in the backup archive.
                // There is no longer any state in which the live database is torn or
                // half-swapped, so this can never delete the last whole copy of anything.
                @unlink($incoming);

                if (! $swapped && $stopped) {
                    // Nothing was swapped, so the app carries on running against the live
                    // database — but releaseDatabase() has already KILLED the queue worker to
                    // get here, and nothing else brings it back until the app is closed and
                    // reopened. Left dead, every queued job stops running: including the
                    // automatic CreateBackup, whose 30-minute heartbeat would go on enqueueing
                    // jobs that nobody drains. Put back what was taken down.
                    //
                    // BOTH conditions, and $stopped is not belt-and-braces. Step 4a — the copy
                    // to <db>.new — runs before releaseDatabase(), so a full disk or a denied
                    // write on the userData volume aborts with the worker still ALIVE. Keying
                    // this off !$swapped alone then posts QueueWorker::up() for a
                    // `queue_default` alias that is still running: in the packaged app that is
                    // at best a no-op error, and at worst Electron spawns a SECOND `queue:work`
                    // child and overwrites the alias — orphaning the first php.exe, which
                    // down() can no longer reach and which holds the SQLite file open for the
                    // rest of the session. Every later restore then aborts at "The database is
                    // still open in another program", so one disk-full attempt bricks restore
                    // until the app is restarted. Only put back what was actually taken down.
                    //
                    // And only before the swap. Past it the worker must STAY down — it is
                    // holding handles on a database that has just been replaced underneath it,
                    // and the caller relaunches the whole app instead.
                    $this->restartQueueWorker();
                }

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

                    if ($strandedPrivate !== []) {
                        $message .= ' Some private files could not be put back automatically — they are in: '
                            .implode(' ; ', $strandedPrivate);
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

                // The exception CLASS carries the same signal as $swapped, for callers outside
                // this method (BackupController) that need to decide whether the app is safe to
                // keep running as-is or must relaunch — even though this call failed either way.
                // Deliberately not left to string-matching the message above: that text is
                // written for the person reading it, and it is not what the code should be
                // parsing to make a decision this consequential.
                if ($swapped) {
                    throw new RestoreFailedAfterSwapException($message, $snapshot, $e);
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
            $leftovers = [];

            foreach (array_filter([$retiredMedia, ...$retiredPrivate]) as $retired) {
                if (! $this->deleteDirectory($retired)) {
                    $leftovers[] = $retired;
                }
            }

            if ($leftovers !== []) {
                Log::warning('The restore succeeded, but a superseded folder could not be deleted.', [
                    'leftover' => $leftovers,
                    'snapshot' => $snapshot,
                ]);

                // One folder (the usual case) comes back exactly as before; several are
                // listed together so the user hears about every one of them.
                return implode(' ; ', $leftovers);
            }

            return null;
        } finally {
            $this->deleteDirectory($staging);
        }
    }

    /**
     * Takes the live database out of every process's hands, and proves that it did,
     * without destroying anything: stops the queue worker, checkpoints the -wal, and
     * throws while anyone still holds it. See LiveDatabase::release() for why the WAL
     * is checkpointed and never deleted.
     */
    private function releaseDatabase(): void
    {
        $this->liveDatabase()->release();
    }

    /**
     * Starts the queue worker back up after a restore that aborted BEFORE the swap —
     * only ever after releaseDatabase() stopped it. See LiveDatabase::restartQueueWorker().
     */
    private function restartQueueWorker(): void
    {
        $this->liveDatabase()->restartQueueWorker();
    }

    private function liveDatabase(): LiveDatabase
    {
        return new LiveDatabase($this->databasePath, self::HANDLE_RELEASE_TIMEOUT_MS, self::HANDLE_RELEASE_POLL_MS);
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

    /**
     * One half of the backup's identity against ours.
     *
     * A missing or empty value on EITHER side is not a match — never a match. Two nulls are
     * not evidence that the archive is ours; they are the absence of evidence, and treating
     * them as agreement would let a manifest with no identity at all (or an app_id we cannot
     * read in this runtime) match anything.
     */
    private function identityMatches(mixed $theirs, mixed $ours): bool
    {
        return is_string($theirs) && $theirs !== ''
            && is_string($ours) && $ours !== ''
            && $theirs === $ours;
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

                $privateFiles = [];

                foreach ($this->privateRoots as $name => $root) {
                    $privateFiles[$name] = 0;

                    foreach ($this->privateFiles($root) as $absolute => $relative) {
                        // Same rule as the media: a file that cannot be added fails the
                        // whole backup rather than producing a silently incomplete one.
                        if (! @$zip->addFile($absolute, 'private/'.$name.'/'.$relative)) {
                            throw new RuntimeException("Could not add a file to the backup: {$absolute}");
                        }

                        $privateFiles[$name]++;
                    }
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
                    // An object even when empty, so "no private files" ({}) is told apart
                    // from a backup made before private roots existed (no key at all).
                    'private_roots' => (object) $privateFiles,
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
        yield from $this->filesUnder($this->mediaPath);
    }

    /**
     * @return iterable<string, string> absolute path => path relative to $root
     *
     * Protected for the same reason as mediaFiles(): a test can stand in for a
     * file that vanishes between being listed and being archived.
     */
    protected function privateFiles(string $root): iterable
    {
        yield from $this->filesUnder($root);
    }

    /** @return iterable<string, string> absolute path => path relative to $root */
    private function filesUnder(string $root): iterable
    {
        if (! is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

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
     * For every configured private root, the staged folder the swap will move into
     * place — checked the way the media tree is checked, before anything is touched.
     *
     * A root the manifest counts files for must be in the archive, or the backup is
     * incomplete. A root it counts none for — or every root, for a backup made before
     * private roots existed (no `private_roots` key) — is staged EMPTY, so the restore
     * leaves it exactly as it was at backup time rather than keeping today's files
     * beside a database that knows nothing about them.
     *
     * @return array<string, string> name => staged folder
     */
    private function stagePrivateRoots(string $staging, array $manifest): array
    {
        $counts = is_array($manifest['private_roots'] ?? null) ? $manifest['private_roots'] : [];
        $staged = [];

        foreach (array_keys($this->privateRoots) as $name) {
            $dir = $staging.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.$name;
            $expected = (int) ($counts[$name] ?? 0);

            if ($expected > 0 && ! is_dir($dir)) {
                throw new RuntimeException(
                    "That backup is incomplete: it says it holds {$expected} file(s) in {$name}, "
                    .'but there are none inside the archive. Nothing was changed.'
                );
            }

            if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
                throw new RuntimeException("Could not prepare the restored {$name} folder: {$dir}");
            }

            $staged[$name] = $dir;
        }

        return $staged;
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
