<?php

namespace App\Services\Backup;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Copies the SQLite database aside before an app update migrates it, so a
 * migration that rewrites data (e.g. re-sorting transactions into seasons) can
 * always be undone by hand. The copy sits beside the live file as
 * database.pre-migrate-<Ymd-His>.sqlite and is never pruned.
 *
 * Does nothing when no migration is pending, when the connection is not SQLite,
 * or when the database is in memory / not yet a file.
 */
class PreMigrationBackup
{
    public function __construct(private Migrator $migrator) {}

    /** @return string|null the backup's path, or null when nothing was copied */
    public function run(?string $connection = null): ?string
    {
        $connection ??= DB::getDefaultConnection();
        $config = config("database.connections.{$connection}");

        if (($config['driver'] ?? null) !== 'sqlite') {
            return null;
        }

        $path = $config['database'] ?? '';
        if ($path === '' || $path === ':memory:' || str_contains($path, 'mode=memory') || ! is_file($path)) {
            return null;
        }

        if (! $this->hasPendingMigrations($connection)) {
            return null;
        }

        $target = dirname($path).DIRECTORY_SEPARATOR
            .'database.pre-migrate-'.now()->format('Ymd-His').'.sqlite';

        // Release the app's handle first, then let SQLite itself write the copy:
        // VACUUM INTO produces one consistent file even in WAL mode or while
        // another process (the queue worker) still has the database open.
        DB::disconnect($connection);

        $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->prepare('VACUUM INTO ?')->execute([$target]);
        $pdo = null;

        if (! is_file($target)) {
            throw new RuntimeException("The pre-migration backup could not be written to {$target}.");
        }

        return $target;
    }

    private function hasPendingMigrations(string $connection): bool
    {
        return $this->migrator->usingConnection($connection, function () {
            if (! $this->migrator->repositoryExists()) {
                return true;
            }

            $files = $this->migrator->getMigrationFiles(
                array_merge([database_path('migrations')], $this->migrator->paths())
            );

            return array_diff(array_keys($files), $this->migrator->getRepository()->getRan()) !== [];
        });
    }
}
