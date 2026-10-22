<?php

namespace App\Services\Backup;

use Illuminate\Console\Events\CommandStarting;

/**
 * Takes the pre-migration copy as the `migrate` command starts, whoever runs
 * it. On an app update the desktop shell runs `artisan migrate --force` in its
 * own process before the app's `booted` hook — a backup taken from that hook
 * would copy the already-migrated database.
 *
 * Desktop only: on the web, migrations run by hand at deploy time. If the copy
 * fails it throws, which stops the migration (retried next launch) rather than
 * running it with no way back.
 */
class BackupBeforeMigrating
{
    public function __construct(private PreMigrationBackup $backup) {}

    public function handle(CommandStarting $event): void
    {
        if ($event->command !== 'migrate' || ! config('nativephp-internal.running')) {
            return;
        }

        $this->backup->run();
    }
}
