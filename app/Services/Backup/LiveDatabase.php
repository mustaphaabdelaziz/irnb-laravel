<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Native\Desktop\DataObjects\QueueConfig;
use Native\Desktop\Facades\QueueWorker;
use RuntimeException;

/**
 * The SQLite file the running app is using, and the one safe way to take it out
 * of every process's hands before it is replaced.
 *
 * Shared by the backup restore and the desktop's first-launch setup (seed copy,
 * legacy-folder adoption). Both used to delete or copy over the live file; on
 * Windows that fails silently while the queue worker holds it, and a copy that
 * lands under the worker's -wal and -shm is reverted or corrupted the moment
 * the worker lets go. The replacement must be: stage the new file beside the
 * live one, release() this, then one rename() — which Windows only allows when
 * nobody holds the file, so it is also the proof that release() worked.
 */
class LiveDatabase
{
    /**
     * @param  int  $timeoutMs  How long to wait for another process to let go of the
     *                          -wal/-shm. Stopping the queue worker is asynchronous — see
     *                          stopQueueWorker() — so the child is still being torn down
     *                          when QueueWorker::down() returns, and Windows holds its file
     *                          handles until the process is fully reaped. Checking once,
     *                          immediately, would race the kill and abort a swap that was
     *                          about to work.
     */
    public function __construct(
        private string $path,
        private int $timeoutMs = 5000,
        private int $pollMs = 100,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Takes the live database out of every process's hands, and proves that it did —
     * WITHOUT destroying anything to get there.
     *
     * Called BEFORE the swap, and throws rather than pressing on: at the point it runs
     * nothing destructive has happened yet — the live database is whole and the incoming
     * one is staged beside it — so aborting here is free. Pressing on is what corrupts
     * the club's data.
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
     * conditions, so a swap could destroy the WAL and then abort saying "Nothing was
     * changed" over the top of it. Verified on Windows: both unlinks succeed, the rename
     * fails, and the row that was only in the WAL is gone.
     *
     * So the WAL is CHECKPOINTED, never deleted. That merges its pages INTO the database
     * — the same thing SQLite does routinely on its own — and closing our connection is
     * what removes the sidecars, which SQLite only does when nobody else is holding them.
     * If someone still is, they stay, and this aborts. Non-destructively, either way.
     */
    public function release(): void
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
        // connection cleanly. Retrying the checkpoint as we poll means that the moment the
        // worker is actually reaped, the next attempt merges its orphaned WAL and its close
        // clears both sidecars.
        //
        // Every iteration is a merge or a no-op. Nothing in this loop can lose a byte.
        $deadline = microtime(true) + $this->timeoutMs / 1000;

        do {
            if ($this->sidecars() === []) {
                return;
            }

            $this->checkpoint();

            if ($this->sidecars() === []) {
                return;
            }

            usleep($this->pollMs * 1000);
        } while (microtime(true) < $deadline);

        $remaining = $this->sidecars();

        if ($remaining === []) {
            return;
        }

        // Someone still has the database open, so its WAL cannot be folded in and the swap
        // cannot go ahead. Nothing has been destroyed to find that out: the sidecars are
        // still there, holding whatever they held.
        throw new RuntimeException(
            'The database is still open in another program, so it could not be taken out of use: '
            ."{$remaining[0]}."
        );
    }

    /**
     * Folds a database's write-ahead log into the file itself and closes it, which
     * removes its -wal and -shm when nothing else holds them.
     *
     * TRUNCATE rather than PASSIVE so the WAL is emptied rather than merely copied
     * forward, and so a WAL orphaned by a killed worker cannot be replayed again later.
     *
     * Failure is not fatal and is not thrown: this is one attempt among many, and the
     * "are the sidecars actually gone?" check in release() is the real gate. A
     * checkpoint that comes back busy — someone else is still reading — simply leaves the
     * sidecars in place, which is precisely what makes that check abort.
     */
    public function checkpoint(): void
    {
        $pdo = null;

        try {
            $pdo = new \PDO('sqlite:'.$this->path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,

                // The busy timeout, and it is what makes $timeoutMs an actual upper bound
                // rather than a wish.
                //
                // pdo_sqlite installs a 60-SECOND busy timeout by default, and
                // wal_checkpoint(TRUNCATE) does not come back busy under it — it WAITS. So a
                // foreign process holding nothing more than an open read transaction (measured:
                // a reader held for 30s blocked the checkpoint for the full 30s) can freeze one
                // pass of the poll, and with it the request and the Electron window, for up to
                // a minute — while the deadline the poll is checking says 5 seconds.
                //
                // 2s per attempt keeps the wait long enough to be useful (the point of waiting
                // at all is that a checkpoint which blocks briefly on the dying worker then
                // SUCCEEDS, which is what clears the sidecars) while keeping a single blocking
                // pass inside the budget the poll is policing.
                \PDO::ATTR_TIMEOUT => 2,
            ]);

            $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (\Throwable) {
            // Fall through: release()'s poll is still the gate.
        } finally {
            // Drop OUR handle whatever happened — in a finally, because if the checkpoint
            // threw, this connection would otherwise stay open and hold the -wal and the
            // -shm itself, and the poll would then be looking at our own connection.
            //
            // Closing it is also what REMOVES the sidecars: SQLite deletes them when the
            // last connection closes. That is what makes this a merge rather than a
            // deletion — the data goes into the database on the way out.
            $pdo = null;
        }
    }

    /**
     * The -wal/-shm still sitting beside the database, -wal first so it is the one an
     * error message names.
     *
     * @return array<int, string>
     */
    public function sidecars(): array
    {
        $remaining = [];

        foreach (['-wal', '-shm'] as $suffix) {
            $sidecar = $this->path.$suffix;

            clearstatcache(true, $sidecar);

            if (file_exists($sidecar)) {
                $remaining[] = $sidecar;
            }
        }

        return $remaining;
    }

    /**
     * Starts the queue worker back up after release() stopped it: after a swap that
     * aborted BEFORE the rename(), or after a first-launch setup that carries on running.
     *
     * Left dead, every queued job stops running for the rest of the session — including
     * the automatic CreateBackup, whose 30-minute heartbeat keeps enqueueing jobs that
     * nobody drains. Only call this when release() actually ran: posting up() for a
     * worker that is still alive can make Electron spawn a SECOND `queue:work` child and
     * orphan the first, which then holds the database open for the rest of the session.
     *
     * Rebuilt from config exactly as NativeServiceProvider::fireUpQueueWorkers() does, so
     * the worker comes back with the queues, memory limit and timeout it was spawned with.
     *
     * Best-effort and non-throwing: it runs where a more important error may be on its
     * way to the user, and a failure to respawn a worker must not replace it.
     *
     * Guarded on nativephp-internal.running: outside the packaged app there is no
     * runtime to talk to and no worker to restart.
     */
    public function restartQueueWorker(): void
    {
        if (! config('nativephp-internal.running')) {
            return;
        }

        try {
            foreach (QueueConfig::fromConfigArray((array) config('nativephp.queue_workers', [])) as $queueConfig) {
                QueueWorker::up($queueConfig);
            }
        } catch (\Throwable $e) {
            Log::warning('The queue worker could not be restarted; automatic backups will not run until the app is restarted.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Asks the NativePHP runtime to stop the queue worker — the OTHER process holding
     * the live database open.
     *
     * NativeServiceProvider::configureApp() calls fireUpQueueWorkers() on every
     * non-console boot, and QueueWorker::up() spawns `queue:work` as a *persistent*
     * child process against config('nativephp-internal.database_path'). It keeps
     * handles open on the .sqlite, the -wal and the -shm for the life of the app, and
     * DB::disconnect() cannot reach into another process to close them.
     *
     * This is a REQUEST, not a guarantee, and the code downstream treats it as one.
     * QueueWorker::down() posts to the Electron runtime's HTTP API and hands nothing
     * back, so it cannot tell us whether the child actually died, or — since the kill is
     * asynchronous and Windows keeps a dead process's handles until it is reaped —
     * whether it has died YET. A failure to reach the runtime at all is therefore
     * logged, not thrown: it is not the evidence that matters.
     *
     * The evidence that matters is on the filesystem. release() will not continue until
     * the -wal and -shm are provably gone, and the rename() that follows can only
     * succeed on Windows if nobody holds the database open at all. Between them, a
     * worker that ignored this, respawned, or is merely slow to die produces a clean
     * abort with the live data untouched.
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
            Log::warning('Could not ask the queue worker to stop; continuing to the WAL check.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
