<?php

namespace App\Http\Controllers;

use App\Jobs\CreateBackup;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use App\Services\Backup\RestoreFailedAfterSwapException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\Shell;

/**
 * Desktop-only, superadmin-only. Route names are deliberately unmapped in
 * config/permissions.php: PermissionMap::resolve() returns null for them and
 * EnsurePermission passes them through, so the `superadmin` alias is the only gate.
 */
class BackupController extends Controller
{
    public function index(BackupSettings $settings, BackupService $backups)
    {
        // Read once. Every BackupSettings call is a Settings::get() HTTP round-trip to
        // Electron, and destinationIsWritable() re-reads all() each time it is asked —
        // so this used to cost three round-trips and two is_dir()/is_writable() probes,
        // each of which can block for seconds on an unplugged USB drive or an offline
        // network share.
        $all = $settings->all();

        return Inertia::render('Backups/Index', [
            'settings' => Arr::except($all, ['last_restore']),
            'destinationWritable' => $settings->destinationIsWritable($all),
            // No ternary on destinationWritable: list() already returns [] for a
            // destination that is missing.
            'backups' => $backups->list(),
            // The outcome of the last restore, which is NOT a flash message — a restore
            // relaunches the app and the flash dies with the process. See
            // BackupSettings::recordRestore() and restore() below. The page renders this
            // as a persistent banner and clears it through dismissLastRestore().
            'lastRestore' => $all['last_restore'],
        ]);
    }

    /** Manual backup. Synchronous on purpose — the user is waiting on the result. */
    public function store(BackupService $backups)
    {
        $lock = Cache::lock('backup', 300);

        if (! $lock->get()) {
            return back()->with('error', 'flash.backup_already_running');
        }

        try {
            $backups->create();
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        } finally {
            $this->releaseLock($lock);
        }

        return back()->with('success', 'flash.backup_created');
    }

    /**
     * Heartbeat from the layout: once on launch, then every 30 minutes while the
     * app stays open. There is no scheduler on desktop — the app only runs when
     * it is open — so this is how an automatic backup actually fires.
     *
     * isDue() is answered here, at dispatch time, but the job re-checks it when it
     * actually runs: the two are not the same moment, and CreateBackup is
     * ShouldBeUnique precisely because they can be far apart. See that class.
     */
    public function tick(Request $request, BackupSettings $settings)
    {
        $trigger = $request->string('trigger')->toString() === 'launch' ? 'launch' : 'heartbeat';

        if (! $settings->isDue($trigger)) {
            return response()->json(['dispatched' => false]);
        }

        CreateBackup::dispatch($trigger);

        return response()->json(['dispatched' => true]);
    }

    public function updateSettings(Request $request, BackupSettings $settings)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', Rule::in(BackupSettings::FREQUENCIES)],
            'retention' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $settings->put($data);

        return back()->with('success', 'flash.backup_settings_saved');
    }

    /** Opens the OS folder picker and stores whatever the user chose. */
    public function chooseFolder(BackupSettings $settings)
    {
        $path = Dialog::new()
            ->title('Choose a backup folder')
            ->button('Select')
            ->folders()
            ->open();

        if (! $path) {
            return back();
        }

        $settings->put(['destination' => $path]);

        return back()->with('success', 'flash.backup_destination_set');
    }

    /**
     * Restore is NEVER queued: the queue tables live inside the database being
     * replaced, so it has to run synchronously while the user is watching.
     *
     * BackupService::restore() throws RestoreFailedAfterSwapException specifically
     * when the live database has already been swapped (and the queue worker
     * already asked to stop) before the failure happened — see that class's
     * docblock. In that case the app cannot be left running as it is, even though
     * the restore itself failed, so this still relaunches. An ordinary failure
     * (anything caught before the point of no return) leaves the live data
     * untouched, so it must NOT relaunch — there is nothing to recover from and
     * the user can just try again.
     *
     * The outcome is PERSISTED, not flashed. App::relaunch() is an HTTP POST that
     * makes Electron tear down the window and the PHP child, and Laravel only writes
     * the session (flash bag included) in StartSession::terminate() — after the
     * response, by which time the app is already being killed. A flashed restore
     * outcome therefore races the relaunch it just fired, and the two messages that
     * matter most are the ones most likely to be lost: the post-swap failure whose
     * entire job is to name the pre-restore snapshot, and the success that names a
     * full duplicate of every photo the club has. BackupSettings::recordRestore()
     * puts it in electron-store — outside the database a restore is swapping and
     * outside the session the relaunch is destroying — and index() hands it to the
     * page as a banner that survives, does not auto-dismiss, and can be copied.
     */
    public function restore(Request $request, BackupService $backups, BackupSettings $settings)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'confirmation' => ['required', 'string'],
        ]);

        if (strtoupper(trim($data['confirmation'])) !== 'RESTORE') {
            throw ValidationException::withMessages([
                'confirmation' => 'Type RESTORE to confirm.',
            ]);
        }

        $lock = Cache::lock('backup', 600);

        if (! $lock->get()) {
            // Not a restore outcome — nothing was attempted, so nothing is recorded.
            // This one is safe to flash: no relaunch follows it.
            return back()->with('error', 'flash.backup_already_running');
        }

        $outcome = 'success';
        $message = 'Backup restored successfully.';
        $snapshot = null;
        $leftoverMedia = null;

        try {
            $leftoverMedia = $backups->restore($backups->resolve($data['name']));

            // Success, but the restore may still have left a superseded media folder
            // behind — a full duplicate of every photo — when that folder could not be
            // deleted. That is NOT a failure (restore() only ever RETURNS it, and never
            // throws for it), so it is surfaced as part of the success message instead
            // of being discarded.
            if ($leftoverMedia !== null) {
                $message = 'Backup restored successfully. A leftover folder from your previous data could not be '
                    ."removed automatically — please delete it by hand: {$leftoverMedia}";
            }
        } catch (RestoreFailedAfterSwapException $e) {
            report($e);

            $outcome = 'failed_after_swap';
            $message = $e->getMessage();
            $snapshot = $e->snapshot;
        } catch (\Throwable $e) {
            report($e);

            $outcome = 'failed';
            $message = $e->getMessage();
        } finally {
            $this->releaseLock($lock);
        }

        // Written BEFORE the relaunch, and non-throwing. If electron-store cannot be
        // reached, the user loses the message — but losing the relaunch on top of it
        // would leave the app running against a database that has just been swapped out
        // underneath it, which is far worse than a missing banner.
        try {
            $settings->recordRestore($outcome, $message, $snapshot, $leftoverMedia);
        } catch (\Throwable $e) {
            report($e);
        }

        // 'failed' is the only outcome that did NOT get past the point of no return: the
        // live data is untouched and the queue worker has been put back (see
        // BackupService::restartQueueWorker()), so the app is safe to keep using exactly
        // as it is. Both other outcomes have already swapped the database underneath the
        // running app and killed its queue worker, so both must reopen on it cleanly —
        // including the one where the restore FAILED. See RestoreFailedAfterSwapException.
        if ($outcome !== 'failed') {
            App::relaunch();
        }

        return back();
    }

    /** Dismisses the persistent restore banner. */
    public function dismissLastRestore(BackupSettings $settings)
    {
        $settings->clearRestore();

        return back();
    }

    public function reveal(Request $request, BackupService $backups, BackupSettings $settings)
    {
        $name = $request->string('name')->toString();
        $path = $settings->destination();

        if ($name !== '') {
            try {
                $path = $backups->resolve($name);
            } catch (\Throwable) {
                // The zip was deleted or renamed outside the app — in Explorer, by a
                // cleanup tool — while this page was open. resolve() throws for a file
                // that is no longer there, and an unhandled throw here is an Inertia
                // error modal on a button whose whole job is "show me the folder". Show
                // them the folder.
                $path = $settings->destination();
            }
        }

        if ($path) {
            Shell::showInFolder($path);
        }

        return back();
    }

    public function destroy(string $name, BackupService $backups)
    {
        try {
            $path = $backups->resolve($name);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        // unlink()'s return value is the only evidence there is, and discarding it is
        // how a failed delete gets reported as a success. On Windows a single open
        // handle — antivirus, an Explorer preview pane, the search indexer — makes it
        // fail; the same point BackupService::deleteDirectory() is built around. The
        // user would be told the backup was deleted and then watch it stay in the list.
        //
        // The is_file() re-check keeps the honest race honest: if something else removed
        // the file first, unlink() fails but the backup really is gone, which is what the
        // user asked for. (unlink() clears the stat cache for the path it was given.)
        if (! @unlink($path) && is_file($path)) {
            return back()->with(
                'error',
                "That backup could not be deleted — it may be open in another program: {$path}",
            );
        }

        return back()->with('success', 'flash.backup_deleted');
    }

    /**
     * Releasing the lock must never become the thing that fails the request.
     *
     * The `backup` lock depends on CACHE_STORE=file (.env, and .env.example): the file
     * store is a LockProvider, and — the part that matters here — it does NOT live in
     * the database. Under Laravel's own default, CACHE_STORE=database, the lock rows sit
     * in the very database a restore has just replaced, so this release would run
     * against a brand-new file through a connection to the old one. An exception thrown
     * from the `finally` block would then REPLACE the real restore outcome and skip the
     * App::relaunch() that has to follow a swap — the worst possible failure, produced
     * by nothing more than a cleanup step.
     *
     * The dependency is recorded here because nothing else in the app states it. And
     * even with the file store, a failing release is swallowed: a stale lock expires by
     * itself (300s/600s), a lost relaunch or a lost error message does not.
     */
    private function releaseLock(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
