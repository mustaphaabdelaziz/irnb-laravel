<?php

namespace App\Services\Player\Merge;

use App\Services\Backup\BackupService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * A backup before every merge (owner 2026-11-26): a full backup into the
 * chosen folder's never-pruned pre-merge/ subfolder; without one, a copy of
 * the SQLite file next to it. If no backup can be written, the merge must not run.
 */
class PreMergeBackup
{
    public function __construct(private BackupService $backups) {}

    /** Path of the backup written. */
    public function run(): string
    {
        try {
            return $this->backups->preMerge();
        } catch (Throwable $e) {
            $config = config('database.connections.'.DB::getDefaultConnection());
            $path = (string) ($config['database'] ?? '');
            if (($config['driver'] ?? null) !== 'sqlite' || $path === '' || $path === ':memory:' || str_contains($path, 'mode=memory') || ! is_file($path)) {
                throw $e; // the caller reports it
            }

            // The fallback may work: keep the reason the real backup failed in the log.
            report($e);

            $base = dirname($path).DIRECTORY_SEPARATOR.'database.pre-merge-'.now()->format('Ymd-His');
            $target = $base.'.sqlite';
            for ($n = 2; file_exists($target); $n++) {
                $target = $base.'-'.$n.'.sqlite';
            }

            // Same as PreMigrationBackup: release the app's handle, let SQLite write one consistent copy.
            DB::disconnect();
            $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->prepare('VACUUM INTO ?')->execute([$target]);
            $pdo = null;

            if (! is_file($target)) {
                throw new RuntimeException("The pre-merge backup could not be written to {$target}.");
            }

            return $target;
        }
    }
}
