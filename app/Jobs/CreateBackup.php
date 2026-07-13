<?php

namespace App\Jobs;

use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * The automatic backup path. Queued so a scheduled backup never blocks a request
 * — NativePHP already auto-starts a worker for the `default` queue.
 *
 * Manual backups do NOT go through this: the user is watching and wants the error.
 *
 * ShouldBeUnique, and it is not decoration. BackupController::tick() evaluates
 * isDue() at DISPATCH time, but only this job's create() calls markRun() — so
 * nothing is marked as run until the job actually runs. QUEUE_CONNECTION=database,
 * so jobs persist on disk and outlive the process. If the worker is dead — exactly
 * the state a pre-swap restore abort used to leave behind — every 30-minute
 * heartbeat enqueued another job, and on the next launch the worker drained them
 * all: each one runs create() then prune(), so N backups written seconds apart fill
 * the retention window and evict every genuinely old backup. The backup feature
 * destroys the user's backup history.
 *
 * Two independent guards, because they fail differently:
 *
 * 1. ShouldBeUnique stops a second job being enqueued while one is still sitting in
 *    the queue unrun. Laravel takes the lock in PendingDispatch::shouldDispatch(),
 *    through the cache store — CACHE_STORE=file (.env), and the file store is a
 *    LockProvider, so this works as configured.
 *
 * 2. isDue() is re-checked HERE, at run time, against last_run_at. That is what
 *    holds if the lock is ever lost: a restore swaps the database (and with it the
 *    `jobs` table) out from underneath the app, and $uniqueFor is finite precisely
 *    so a job that vanishes with it cannot leave a lock behind that disables
 *    automatic backups forever. Whatever slips past guard 1, guard 2 makes the
 *    second run a no-op — the first one's markRun() has already moved last_run_at.
 */
class CreateBackup implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Bounded on purpose. A lock that never expires would be a second way to break
     * automatic backups: if the queued job disappears without running (a restore
     * replaces the database the `jobs` table lives in), the lock it left behind
     * would block every future dispatch. An hour is longer than any backup takes
     * and shorter than the shortest backup interval that can actually recur.
     */
    public int $uniqueFor = 3600;

    /** @param  string  $trigger  'launch' or 'heartbeat' — see BackupSettings::isDue() */
    public function __construct(public string $trigger = 'heartbeat') {}

    public function handle(BackupService $backups, BackupSettings $settings): void
    {
        // Re-checked here, not just at dispatch: see the class docblock. A backup that
        // has become un-due while this job waited in the queue is not a backup that
        // needs taking — it is the same backup, taken again.
        if (! $settings->isDue($this->trigger)) {
            return;
        }

        // Skips silently if a manual backup or a restore is already running.
        Cache::lock('backup', 300)->get(function () use ($backups) {
            $backups->create();
        });
    }
}
