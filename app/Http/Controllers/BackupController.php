<?php

namespace App\Http\Controllers;

use App\Jobs\CreateBackup;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use App\Services\Backup\RestoreFailedAfterSwapException;
use Illuminate\Http\Request;
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
        return Inertia::render('Backups/Index', [
            'settings' => $settings->all(),
            'destinationWritable' => $settings->destinationIsWritable(),
            'backups' => $settings->destinationIsWritable() ? $backups->list() : [],
        ]);
    }

    /** Manual backup. Synchronous on purpose — the user is waiting on the result. */
    public function store(BackupService $backups)
    {
        $lock = Cache::lock('backup', 300);

        if (! $lock->get()) {
            return back()->with('error', 'A backup or restore is already running. Please try again in a moment.');
        }

        try {
            $backups->create();
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        } finally {
            $lock->release();
        }

        return back()->with('success', 'Backup created successfully.');
    }

    /**
     * Heartbeat from the layout: once on launch, then every 30 minutes while the
     * app stays open. There is no scheduler on desktop — the app only runs when
     * it is open — so this is how an automatic backup actually fires.
     */
    public function tick(Request $request, BackupSettings $settings)
    {
        $trigger = $request->string('trigger')->toString() === 'launch' ? 'launch' : 'heartbeat';

        if (! $settings->isDue($trigger)) {
            return response()->json(['dispatched' => false]);
        }

        CreateBackup::dispatch();

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

        return back()->with('success', 'Backup settings saved.');
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

        return back()->with('success', 'Backup destination set.');
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
     */
    public function restore(Request $request, BackupService $backups)
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
            return back()->with('error', 'A backup or restore is already running. Please try again in a moment.');
        }

        $leftoverMedia = null;
        $failedAfterSwap = false;
        $errorMessage = null;

        try {
            $leftoverMedia = $backups->restore($backups->resolve($data['name']));
        } catch (RestoreFailedAfterSwapException $e) {
            report($e);
            $failedAfterSwap = true;
            $errorMessage = $e->getMessage();
        } catch (\Throwable $e) {
            report($e);
            $errorMessage = $e->getMessage();
        } finally {
            $lock->release();
        }

        if ($errorMessage !== null && ! $failedAfterSwap) {
            // Failed before the point of no return: the live data is untouched,
            // so the app is safe to keep using exactly as it is. No relaunch.
            return back()->with('error', $errorMessage);
        }

        // From here on the live database has already been swapped — either the
        // restore succeeded outright, or it failed after the swap. Either way the
        // queue worker was already asked to stop to get this far, so the app must
        // relaunch regardless of the outcome (see RestoreFailedAfterSwapException).
        if ($failedAfterSwap) {
            App::relaunch();

            return back()->with('error', $errorMessage);
        }

        // Success. It may still have left a superseded media folder behind — a
        // full duplicate of every photo — when that folder could not be deleted.
        // That is NOT a failure (BackupService::restore() only ever returns it,
        // never throws for it), so it is surfaced as part of the success message
        // instead of being discarded.
        $message = $leftoverMedia !== null
            ? "Backup restored successfully. A leftover folder from your previous data could not be removed automatically — please delete it by hand: {$leftoverMedia}"
            : 'Backup restored successfully.';

        // The database underneath the running app has just been swapped. Reopen on it cleanly.
        App::relaunch();

        return back()->with('success', $message);
    }

    public function reveal(Request $request, BackupService $backups)
    {
        $settings = app(BackupSettings::class);

        $name = $request->string('name')->toString();

        $path = $name !== ''
            ? $backups->resolve($name)
            : $settings->destination();

        if ($path) {
            Shell::showInFolder($path);
        }

        return back();
    }

    public function destroy(string $name, BackupService $backups)
    {
        try {
            @unlink($backups->resolve($name));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Backup deleted successfully.');
    }
}
