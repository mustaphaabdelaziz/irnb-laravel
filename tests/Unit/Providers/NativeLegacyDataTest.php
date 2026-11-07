<?php

namespace Tests\Unit\Providers;

use App\Providers\NativeAppServiceProvider;
use App\Services\Backup\LiveDatabase;
use Illuminate\Support\Facades\File;
use Native\Desktop\Contracts\QueueWorker as QueueWorkerContract;
use Native\Desktop\DataObjects\QueueConfig;
use Native\Desktop\Facades\QueueWorker;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Renaming the desktop app to ASIREM moved its Electron data folder from
 * %APPDATA%\sport-club to %APPDATA%\asirem. The first launch under the new
 * name must bring the club's records along instead of opening on the seed.
 *
 * By the time the provider boots, the Electron shell has already run
 * `migrate` on the new, empty database and the first web request has spawned
 * the queue worker, which holds that database open. 3.0.0 deleted and copied
 * over the live file anyway: on Windows the deletes fail silently, the copy
 * lands under the worker's -wal and -shm, and the club's first launch opened
 * on "database disk image is malformed" (2026-11-06). These tests run real
 * SQLite files and a real child process standing in for the worker.
 */
class NativeLegacyDataTest extends TestCase
{
    private string $appData;

    private string $originalStorage;

    /** @var array<int, resource> */
    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStorage = $this->app->storagePath();
        $this->appData = sys_get_temp_dir().DIRECTORY_SEPARATOR.'native-appdata-'.uniqid();

        // What NativePHP has made by the time the provider boots: the new
        // database, already migrated by the shell, with storage redirected
        // next to it.
        File::ensureDirectoryExists($this->path('asirem/storage/app'));
        $this->makeDatabase($this->dbPath(), []);
        $this->app->useStoragePath($this->path('asirem/storage'));
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            if (is_resource($child)) {
                @proc_terminate($child);
                @proc_close($child);
            }
        }

        File::deleteDirectory($this->appData);
        $this->app->useStoragePath($this->originalStorage);

        parent::tearDown();
    }

    #[Test]
    public function the_old_database_and_files_move_into_the_new_folder(): void
    {
        // 'Omar' is committed but lives only in the old folder's -wal: the
        // move must not lose the club's newest writes.
        $this->makeDatabase($this->path('sport-club/database/database.sqlite'), ['Ali'], walOnly: ['Omar']);
        $this->assertFileExists($this->path('sport-club/database/database.sqlite-wal'));
        File::ensureDirectoryExists($this->path('sport-club/storage/app/public/players'));
        File::ensureDirectoryExists($this->path('sport-club/storage/app/private/receipts'));
        file_put_contents($this->path('sport-club/storage/app/public/players/1.jpg'), 'PHOTO');
        file_put_contents($this->path('sport-club/storage/app/private/receipts/r.pdf'), 'RECEIPT');
        file_put_contents($this->path('asirem/database/.migrated'), 'signature of the empty database');
        $before = $this->fingerprint('sport-club/database');

        $this->assertTrue($this->provider()->adopt($this->dbPath()));

        $this->assertSame(['Ali', 'Omar'], $this->names($this->dbPath()));
        $this->assertSame('ok', $this->quickCheck($this->dbPath()));
        $this->assertFileDoesNotExist($this->dbPath().'.new');
        $this->assertSame('PHOTO', file_get_contents($this->path('asirem/storage/app/public/players/1.jpg')));
        $this->assertSame('RECEIPT', file_get_contents($this->path('asirem/storage/app/private/receipts/r.pdf')));

        // The marker belonged to the database that was replaced, so `migrate`
        // must run on the club's.
        $this->assertFileDoesNotExist($this->path('asirem/database/.migrated'));

        // The old folder stays exactly as it was: it is the fallback copy.
        $this->assertSame($before, $this->fingerprint('sport-club/database'));
    }

    #[Test]
    public function a_pc_that_never_ran_the_old_app_keeps_the_fresh_database(): void
    {
        $this->makeDatabase($this->dbPath(), ['Fresh']);

        $this->assertFalse($this->provider()->adopt($this->dbPath()));

        $this->assertSame(['Fresh'], $this->names($this->dbPath()));
    }

    #[Test]
    public function the_queue_worker_holding_the_new_database_cannot_corrupt_the_move(): void
    {
        // The 3.0.0 launch: the worker has the new database open, with a
        // committed row still in its -wal. QueueWorker::down() kills it, as
        // the runtime does.
        $this->makeDatabase($this->path('sport-club/database/database.sqlite'), ['Ali']);
        $worker = $this->holdDatabase($this->dbPath(), killedOnDown: true);

        $this->assertTrue($this->provider()->adopt($this->dbPath()));

        $this->assertSame('ok', $this->quickCheck($this->dbPath()));
        $this->assertSame(['Ali'], $this->names($this->dbPath()));
        $this->assertFileDoesNotExist($this->dbPath().'-wal');
        $this->assertSame(['default'], $worker->downs);

        // The app carries on, so the worker comes back on the club's database.
        $this->assertNotEmpty($worker->ups);
    }

    #[Test]
    public function a_database_that_cannot_be_released_is_left_whole_and_the_move_is_tried_next_launch(): void
    {
        // A worker that survives down() (respawned, or slow to die): the
        // move must give up with the new database untouched — never copy
        // over it — and must not mark the folder as set up.
        $this->makeDatabase($this->path('sport-club/database/database.sqlite'), ['Ali']);
        $worker = $this->holdDatabase($this->dbPath(), killedOnDown: false);
        $provider = $this->provider();

        $provider->setup($this->dbPath());

        $this->assertSame(['Fresh'], $this->names($this->dbPath()));
        $this->assertSame('ok', $this->quickCheck($this->dbPath()));
        $this->assertFileDoesNotExist($this->dbPath().'.new');
        $this->assertFileDoesNotExist($this->path('asirem/database/.seeded'));
        $this->assertSame([], $provider->seeded, 'The seed must never replace a club whose data is in the old folder.');
        $this->assertNotEmpty($worker->ups, 'The worker that was stopped must be put back.');
    }

    #[Test]
    public function a_damaged_old_database_is_not_moved_in(): void
    {
        File::ensureDirectoryExists($this->path('sport-club/database'));
        file_put_contents($this->path('sport-club/database/database.sqlite'), str_repeat('not a database ', 400));
        $this->makeDatabase($this->dbPath(), ['Fresh']);

        try {
            $this->provider()->adopt($this->dbPath());
            $this->fail('A damaged database must not be adopted.');
        } catch (\RuntimeException|\PDOException) {
            // Expected.
        }

        $this->assertSame(['Fresh'], $this->names($this->dbPath()));
        $this->assertFileDoesNotExist($this->dbPath().'.new');
    }

    #[Test]
    public function a_new_pc_gets_the_seed_through_the_same_safe_swap(): void
    {
        $seed = $this->path('bundle/seed.sqlite');
        $this->makeDatabase($seed, ['Seeded']);
        $worker = $this->holdDatabase($this->dbPath(), killedOnDown: true);
        $provider = $this->provider($seed);

        $provider->setup($this->dbPath());

        $this->assertSame(['Seeded'], $this->names($this->dbPath()));
        $this->assertSame('ok', $this->quickCheck($this->dbPath()));
        $this->assertFileExists($this->path('asirem/database/.seeded'));
        $this->assertSame(['default'], $worker->downs);
    }

    private function provider(?string $seed = null): object
    {
        return new class($seed) extends NativeAppServiceProvider
        {
            /** @var array<int, string> */
            public array $seeded = [];

            public function __construct(private ?string $seed) {}

            public function adopt(string $dbPath): bool
            {
                return $this->adoptLegacyData($dbPath);
            }

            /** firstRunSetup() up to, not including, `migrate`. */
            public function setup(string $dbPath): void
            {
                $this->populateDataFolderOnce($dbPath);
            }

            protected function seedDatabasePath(): string
            {
                $this->seeded[] = (string) $this->seed;

                return (string) $this->seed;
            }

            protected function liveDatabase(string $dbPath): LiveDatabase
            {
                // The worker that ignores down() would otherwise hold the test for 5 s.
                return new LiveDatabase($dbPath, timeoutMs: 500);
            }
        };
    }

    /**
     * A real php.exe holding the database open with a committed row ('Fresh')
     * still in its -wal, as the queue worker does. The faked runtime kills it
     * on down() — or not.
     */
    private function holdDatabase(string $db, bool $killedOnDown): object
    {
        $script = $this->path('holder.php');
        file_put_contents($script, <<<'PHP'
            <?php
            $pdo = new PDO('sqlite:'.$argv[1]);
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec("INSERT INTO players (name) VALUES ('Fresh')");
            echo "READY\n";
            flush();
            sleep(60);
            PHP);

        // Array form: the string form wraps cmd.exe, and killing the shell
        // would leave php.exe holding the database.
        $process = proc_open([PHP_BINARY, $script, $db], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $this->children[] = $process;
        $this->assertSame('READY', trim((string) fgets($pipes[1])));

        clearstatcache();
        $this->assertGreaterThan(0, filesize($db.'-wal'), 'The row must sit in the -wal.');

        config(['nativephp-internal.running' => true]);

        $worker = new class($process, $killedOnDown) implements QueueWorkerContract
        {
            /** @var array<int, string> */
            public array $downs = [];

            /** @var array<int, string> */
            public array $ups = [];

            public function __construct(private $process, private bool $killedOnDown) {}

            public function up(QueueConfig $config): void
            {
                $this->ups[] = $config->alias;
            }

            public function down(string $alias): void
            {
                $this->downs[] = $alias;

                if ($this->killedOnDown && is_resource($this->process)) {
                    proc_terminate($this->process);
                    proc_close($this->process);
                    $this->process = null;
                }
            }
        };

        QueueWorker::swap($worker);

        return $worker;
    }

    /**
     * @param  array<int, string>  $names  checkpointed into the database file
     * @param  array<int, string>  $walOnly  committed, but left in the -wal beside it
     */
    private function makeDatabase(string $path, array $names, array $walOnly = []): void
    {
        File::ensureDirectoryExists(dirname($path));
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($path.$suffix);
        }

        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE players (id INTEGER PRIMARY KEY, name TEXT)');
        foreach ($names as $name) {
            $pdo->prepare('INSERT INTO players (name) VALUES (?)')->execute([$name]);
        }
        $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)');

        if ($walOnly === []) {
            $pdo = null;

            return;
        }

        foreach ($walOnly as $name) {
            $pdo->prepare('INSERT INTO players (name) VALUES (?)')->execute([$name]);
        }

        // Snapshot the files while the connection still holds the row in the
        // -wal; closing it would checkpoint.
        $copy = $path.'.hold';
        copy($path, $copy);
        copy($path.'-wal', $copy.'-wal');
        $pdo = null;
        rename($copy, $path);
        rename($copy.'-wal', $path.'-wal');
        @unlink($path.'-shm');
    }

    /** @return array<int, string> */
    private function names(string $db): array
    {
        $pdo = new \PDO('sqlite:'.$db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        return $pdo->query('SELECT name FROM players ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function quickCheck(string $db): string
    {
        $pdo = new \PDO('sqlite:'.$db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        return (string) $pdo->query('PRAGMA quick_check')->fetchColumn();
    }

    /** @return array<string, string> file name => md5 */
    private function fingerprint(string $relative): array
    {
        $out = [];
        foreach (glob($this->path($relative).DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            $out[basename($file)] = md5_file($file);
        }

        return $out;
    }

    private function dbPath(): string
    {
        return $this->path('asirem/database/database.sqlite');
    }

    private function path(string $relative): string
    {
        return $this->appData.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
