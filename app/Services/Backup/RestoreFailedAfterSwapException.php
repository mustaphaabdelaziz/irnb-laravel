<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Thrown by BackupService::restore() when a failure happens AFTER the database
 * swap — the rename() at the "point of no return" inside step 4c — has already
 * completed.
 *
 * Why this exists: by the time restore() can throw this, the live database has
 * already been replaced by the backup's and the NativePHP queue worker has
 * already been asked to stop (see releaseDatabase()/stopQueueWorker()). A
 * caller that cannot tell this case apart from an ordinary pre-swap failure
 * would leave the app running against a swapped database with a dead queue
 * worker — every request would keep working, but no queued job (including the
 * automatic CreateBackup job) would ever run again until the process restarts.
 * The caller MUST relaunch (or tell the user to restart the app) in this case,
 * even though restore() itself failed.
 *
 * Deliberately not signalled via the message text: restore()'s messages are
 * written for the person reading them, and matching against that text from
 * calling code would silently break the moment the wording changes. This
 * class is the load-bearing signal instead.
 */
class RestoreFailedAfterSwapException extends RuntimeException
{
    /**
     * @param  string|null  $snapshot  the pre-restore snapshot, as DATA and not only inside
     *                                 the prose. The message names it because the user reads
     *                                 the message; the property carries it because
     *                                 BackupController stores the path (BackupSettings::
     *                                 recordRestore()) so the banner still has it after the
     *                                 relaunch, and re-parsing it out of the sentence would
     *                                 be the string-matching this class exists to avoid.
     */
    public function __construct(
        string $message,
        public readonly ?string $snapshot = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
