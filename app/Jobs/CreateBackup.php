<?php

namespace App\Jobs;

use App\Services\Backup\BackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * The automatic backup path. Queued so a scheduled backup never blocks a request
 * — NativePHP already auto-starts a worker for the `default` queue.
 *
 * Manual backups do NOT go through this: the user is watching and wants the error.
 */
class CreateBackup implements ShouldQueue
{
    use Queueable;

    public function handle(BackupService $backups): void
    {
        // Skips silently if a manual backup or a restore is already running.
        Cache::lock('backup', 300)->get(function () use ($backups) {
            $backups->create();
        });
    }
}
