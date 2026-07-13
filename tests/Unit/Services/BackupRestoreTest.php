<?php

namespace Tests\Unit\Services;

use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use App\Services\Backup\RestoreFailedAfterSwapException;
use Native\Desktop\Contracts\QueueWorker as QueueWorkerContract;
use Native\Desktop\DataObjects\QueueConfig;
use Native\Desktop\Facades\QueueWorker;
use Native\Desktop\Facades\Settings;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeNativeSettings;
use Tests\TestCase;
use ZipArchive;

class BackupRestoreTest extends TestCase
{
    private string $root;

    private string $dbPath;

    /** The directory the media root lives *in* — replacing it with a file blocks the swap. */
    private string $mediaHolder;

    private string $mediaPath;

    private string $destination;

    private BackupSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::swap(new FakeNativeSettings);

        // The fixture lives under storage_path(), not sys_get_temp_dir(), on purpose.
        // In production the media root (storage_path('app/public')) and the restore
        // staging area (storage_path('app/backup-tmp')) are on one volume by
        // construction, which is what lets the restore move media with a plain
        // rename(). A fixture rooted in the system temp dir would sit on a different
        // volume (C: vs D: here), where a directory rename can never succeed — i.e. it
        // would test a topology the app cannot actually be in.
        $this->root = storage_path('app').DIRECTORY_SEPARATOR.'backup-restore-test-'.uniqid();
        $this->dbPath = $this->root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'database.sqlite';

        // The media root is nested one level down (as in production, where it is
        // storage/app/public), which gives the tests a way to make the media swap
        // fail: turn the directory that holds it into a file. rename() cannot move a
        // directory underneath a file, and neither can mkdir() create one there.
        $this->mediaHolder = $this->root.DIRECTORY_SEPARATOR.'media';
        $this->mediaPath = $this->mediaHolder.DIRECTORY_SEPARATOR.'public';
        $this->destination = $this->root.DIRECTORY_SEPARATOR.'dest';

        mkdir(dirname($this->dbPath), 0777, true);
        mkdir($this->mediaPath, 0777, true);
        mkdir($this->destination, 0777, true);

        $pdo = new \PDO('sqlite:'.$this->dbPath);

        // WAL, because the packaged app is ALWAYS in WAL and nothing else is reachable:
        // NativeServiceProvider::rewriteDatabase() runs `PRAGMA journal_mode=WAL` on
        // config('nativephp-internal.database_path') on every boot (line 204), which is
        // the exact file AppServiceProvider hands to BackupService — and the setting is
        // persistent in the file header. A non-WAL fixture tests a topology the app can
        // never be in, and it is what let a restore that silently reverts itself past a
        // green suite: with no -wal and no -shm there is no stale WAL to checkpoint back
        // over the restored database.
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT)');
        $pdo->exec('CREATE TABLE players (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO players (name) VALUES ('Ali')");
        $pdo = null;

        $this->assertSame(
            'wal',
            (new \PDO('sqlite:'.$this->dbPath))->query('PRAGMA journal_mode')->fetchColumn(),
            'The fixture database must be in WAL mode — the packaged app is never in any other.',
        );

        file_put_contents($this->mediaPath.DIRECTORY_SEPARATOR.'photo.jpg', 'ORIGINAL');

        // Start from a clean staging root so "did this restore leak staging?" is answerable.
        foreach ($this->stagingDirs() as $leftover) {
            $this->deleteTree($leftover);
        }

        $this->settings = new BackupSettings;
        $this->settings->put(['destination' => $this->destination]);
    }

    protected function tearDown(): void
    {
        // A test may have left the live DB read-only or otherwise locked; make sure
        // the tree can still be removed.
        @chmod($this->dbPath, 0666);

        $this->deleteTree($this->root);

        foreach ($this->stagingDirs() as $leftover) {
            $this->deleteTree($leftover);
        }

        parent::tearDown();
    }

    private function service(): BackupService
    {
        return new BackupService($this->settings, $this->dbPath, $this->mediaPath);
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /** Every staging directory restore() may have left behind in storage/app/backup-tmp. */
    private function stagingDirs(): array
    {
        return glob(storage_path('app/backup-tmp').DIRECTORY_SEPARATOR.'restore-*') ?: [];
    }

    private function players(): array
    {
        $pdo = new \PDO('sqlite:'.$this->dbPath);

        return $pdo->query('SELECT name FROM players ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * players(), but tolerant of a database that can no longer be read at all.
     *
     * A stale WAL checkpointed back over a restored database does not necessarily
     * produce the OLD database — it writes the old pages it happens to hold over the new
     * file at their page offsets, which can leave a mixture SQLite refuses to open. That
     * is still the bug, so it has to be reportable rather than an unhandled PDOException.
     */
    private function playersOrFailure(): array|string
    {
        try {
            return $this->players();
        } catch (\Throwable $e) {
            return 'UNREADABLE: '.$e->getMessage();
        }
    }

    private function snapshots(): array
    {
        return glob($this->destination.DIRECTORY_SEPARATOR.BackupService::SNAPSHOT_DIR.DIRECTORY_SEPARATOR.'*.zip') ?: [];
    }

    /** The media root as a name => bytes map, so a merged tree is impossible to miss. */
    private function mediaTree(): array
    {
        if (! is_dir($this->mediaPath)) {
            return [];
        }

        $tree = [];

        foreach (scandir($this->mediaPath) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $tree[$entry] = file_get_contents($this->mediaPath.DIRECTORY_SEPARATOR.$entry);
        }

        ksort($tree);

        return $tree;
    }

    /**
     * Runs a restore that is expected to fail and hands back the exception.
     *
     * The fail() call is deliberately OUTSIDE the try: PHPUnit's AssertionFailedError
     * extends RuntimeException, so a `$this->fail()` written inside a try/catch
     * (RuntimeException) block would be caught by that very catch — silently turning
     * "the restore wrongly succeeded" into a passing test.
     */
    private function failedRestore(string $backup): RuntimeException
    {
        $caught = null;

        try {
            $this->service()->restore($backup);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        if ($caught === null) {
            $this->fail('Expected the restore to fail, but it reported success.');
        }

        return $caught;
    }

    /**
     * Makes the media swap impossible: the media root's parent becomes a plain file,
     * so the restored tree can neither be rename()d into place nor mkdir()'d there.
     * The database has already been swapped by the time this bites, so it exercises
     * step 4 past the point of no return.
     */
    private function makeMediaSwapImpossible(): void
    {
        $this->deleteTree($this->mediaHolder);
        file_put_contents($this->mediaHolder, 'a file where the media directory should be');
    }

    /**
     * A rejected backup must be a no-op: steps 1-3 never touch live data. Asserting
     * only "an exception was thrown" would pass even if the restore had already
     * eaten the database.
     */
    private function assertLiveDataUntouched(): void
    {
        $this->assertSame(['Ali'], $this->players(), 'The live database was modified by a rejected restore.');
        $this->assertSame(['photo.jpg' => 'ORIGINAL'], $this->mediaTree(), 'The live media was modified by a rejected restore.');
        $this->assertSame([], $this->snapshots(), 'A rejected restore must not write a pre-restore snapshot.');
        $this->assertSame([], $this->stagingDirs(), 'A rejected restore left a staging directory behind.');
    }

    #[Test]
    public function it_restores_both_the_database_and_the_media(): void
    {
        $backup = $this->service()->create();

        // Diverge from the backup: add a row, overwrite the photo.
        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec("INSERT INTO players (name) VALUES ('Omar')");
        file_put_contents($this->mediaPath.DIRECTORY_SEPARATOR.'photo.jpg', 'CHANGED');

        $this->assertSame(['Ali', 'Omar'], $this->players());

        // Drop the connection before restoring — this test stands in for the
        // DB::disconnect() that restore() performs on the live Laravel connection.
        // It is the *clean* topology: nobody at all is holding the database. The
        // production topology — a second process (the NativePHP queue worker) still
        // holding it open — is covered by
        // restoring_while_the_queue_worker_holds_the_database_must_not_silently_no_op().
        $pdo = null;

        // A clean restore returns null: there is no leftover for the caller to deal with.
        // Success is a return value, never an exception.
        $this->assertNull($this->service()->restore($backup));

        $this->assertSame(['Ali'], $this->players());
        $this->assertSame('ORIGINAL', file_get_contents($this->mediaPath.DIRECTORY_SEPARATOR.'photo.jpg'));
        $this->assertSame([], $this->stagingDirs(), 'A successful restore left a staging directory behind.');
    }

    #[Test]
    public function restoring_while_the_queue_worker_holds_the_database_must_not_silently_no_op(): void
    {
        // THE regression test. This is not an edge case — it is the only topology the
        // packaged app ever runs in, and the restore used to quietly do nothing in it.
        //
        // NativeServiceProvider calls fireUpQueueWorkers() on every non-console boot
        // (line 148) and QueueWorker::up() spawns `queue:work` as a persistent child
        // process against config('nativephp-internal.database_path') — the exact file
        // BackupService is handed. That child holds the .sqlite, the -wal AND the -shm
        // open for the life of the app, and DB::disconnect() cannot reach into another
        // process to close them.
        //
        // The old code @unlink()ed -wal/-shm without checking (both always failed), let
        // rename() fail, and fell back to copy()-ing the backup over the live database
        // file — leaving a stale -wal full of the OLD database's pages beside it and the
        // worker's -shm wal-index still declaring it current. The worker never noticed the
        // swap, and when its handle closed (the App::relaunch() after a "successful"
        // restore is exactly what closes it) it became the last connection and
        // checkpointed that stale WAL back over the freshly restored database, reverting
        // it. The media, meanwhile, really had been replaced. integrity_check said "ok".
        $backup = $this->service()->create();

        // The queue worker: a second connection, opened before the restore and still open
        // throughout it, which restore()'s DB::disconnect() has no way of closing.
        $worker = new \PDO('sqlite:'.$this->dbPath);

        // Diverge from the backup ON THE WORKER'S CONNECTION and leave it there. These
        // pages are now sitting UNCHECKPOINTED in the -wal: nothing has written them back
        // into the main database file. This is the loaded gun.
        $worker->exec("INSERT INTO players (name) VALUES ('Omar')");
        file_put_contents($this->mediaPath.DIRECTORY_SEPARATOR.'photo.jpg', 'CHANGED');

        $this->assertFileExists($this->dbPath.'-wal', 'The fixture must be in WAL mode.');
        $this->assertGreaterThan(0, filesize($this->dbPath.'-wal'), 'The WAL must hold uncheckpointed pages.');
        $this->assertSame(['Ali', 'Omar'], $this->players());

        $failure = null;

        try {
            $this->service()->restore($backup);
        } catch (RuntimeException $e) {
            $failure = $e;
        }

        // The worker's handle closes — as it does when the app relaunches straight after a
        // restore. It is the last connection, so SQLite now checkpoints whatever is still
        // in the WAL into the main database file. If a stale WAL survived the swap, this
        // is the moment the restore is undone.
        $worker = null;

        $players = $this->playersOrFailure();
        $media = $this->mediaTree();

        // The invariant, and the only one that matters: the database and the media must
        // tell the same story. Either the restore aborted and BOTH are still the live
        // ones, or it succeeded and BOTH are the backup's. A database that stayed live
        // while the media became the backup's is the silent corruption — the club is left
        // with rows referencing photos that no longer exist, and nothing reports a thing.
        $liveIntact = $players === ['Ali', 'Omar'] && $media === ['photo.jpg' => 'CHANGED'];
        $restored = $players === ['Ali'] && $media === ['photo.jpg' => 'ORIGINAL'];

        $this->assertTrue(
            $liveIntact || $restored,
            "The restore left the database and the media disagreeing.\n"
            .'  database: '.json_encode($players)."\n"
            .'  media:    '.json_encode($media)."\n"
            .'  restore:  '.($failure === null ? 'reported SUCCESS' : 'threw: '.$failure->getMessage())."\n",
        );

        if ($failure !== null) {
            // A clean abort is a correct outcome — the live data is whole and the user is
            // told what to do. It must never be a silent no-op dressed up as success.
            $this->assertTrue($liveIntact, 'A failed restore must leave the live data exactly as it was.');
            $this->assertCount(1, $this->snapshots());
            $this->assertStringContainsString($this->snapshots()[0], $failure->getMessage());
        } else {
            $this->assertSame(['Ali'], $players, 'The restore reported success but the database is still the live one.');
            $this->assertSame(['photo.jpg' => 'ORIGINAL'], $media);
        }

        $this->assertFileDoesNotExist($this->dbPath.'.new', 'The incoming database was left staged at <db>.new.');
        $this->assertSame([], $this->stagingDirs(), 'The restore left a staging directory behind.');
    }

    /**
     * Reads the MAIN database file on its own, with no -wal/-shm beside it.
     *
     * This is what tells "the row is committed and merged into the database" apart from
     * "the row is committed but lives only in the WAL" — a distinction players() cannot
     * make, because SQLite reads straight through the WAL and reports both identically.
     */
    private function mainDatabaseFileOnly(): array|string
    {
        $copy = $this->root.DIRECTORY_SEPARATOR.'main-only-'.uniqid().'.sqlite';

        if (! @copy($this->dbPath, $copy)) {
            return 'UNCOPYABLE';
        }

        try {
            return (new \PDO('sqlite:'.$copy))
                ->query('SELECT name FROM players ORDER BY id')
                ->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return 'UNREADABLE: '.$e->getMessage();
        }
    }

    #[Test]
    public function it_checkpoints_a_killed_workers_orphaned_wal_instead_of_deleting_it(): void
    {
        // THE data-loss test, and the one the other two could never take.
        //
        // it_aborts_..._when_the_wal_cannot_be_removed() and
        // restoring_while_the_queue_worker_holds_the_database_must_not_silently_no_op()
        // both hold a live PDO across the whole restore, so the -wal is pinned and every
        // unlink() of it fails. The destructive branch is never entered, which is exactly
        // why this shipped. The packaged app is not in that topology at the moment it
        // matters: QueueWorker::down() KILLS the worker, and a killed process never
        // checkpoints — so by the time the sidecars are touched, NOTHING holds them and
        // the unlink SUCCEEDS.
        //
        // What that -wal contains is not scratch. The worker holds a connection for the
        // app's entire life, so the web process's per-request close is never the last
        // connection and never checkpoints; everything the club has entered since SQLite's
        // last automatic checkpoint (~1000 pages) is in that file and nowhere else.
        // Deleting it reverts them to that checkpoint. And the rename() that follows fails
        // on nothing more than a plain read handle — Defender, the search indexer, a
        // respawned worker — at which point the restore aborts saying "Nothing was
        // changed", over the top of the day's work it has just destroyed.
        //
        // So: a REAL child php.exe, killed exactly where the runtime kills it (inside
        // releaseDatabase()), and a plain fopen() to fail the rename. Against the old code
        // the live database comes back holding only 'Ali'.
        $backup = $this->service()->create();

        // The queue worker. proc_open's ARRAY form on purpose: the string form wraps the
        // command in cmd.exe, and proc_terminate would then kill the SHELL and leave
        // php.exe alive holding the database — the kill has to land on the process that
        // actually has the handles.
        $holder = $this->root.DIRECTORY_SEPARATOR.'holder.php';
        file_put_contents($holder, <<<'PHP'
            <?php
            // Commits a row that stays in the -wal, says so, then holds the connection open
            // until it is killed. It must never get the chance to checkpoint.
            $pdo = new PDO('sqlite:'.$argv[1]);
            $pdo->exec("INSERT INTO players (name) VALUES ('Omar')");
            echo "READY\n";
            flush();
            sleep(60);
            PHP);

        $process = proc_open(
            [PHP_BINARY, $holder, $this->dbPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $this->assertIsResource($process, 'Could not start the stand-in queue worker.');

        $reader = null;

        try {
            $this->assertSame('READY', trim((string) fgets($pipes[1])), 'The stand-in worker never committed its row.');

            // The loaded gun: 'Omar' is COMMITTED, but he is only in the -wal. The main
            // database file itself has never heard of him.
            clearstatcache();
            $this->assertFileExists($this->dbPath.'-wal');
            $this->assertGreaterThan(0, filesize($this->dbPath.'-wal'), 'The WAL must hold uncheckpointed pages.');
            $this->assertSame(['Ali', 'Omar'], $this->players(), 'SQLite reads through the WAL, so the row is live.');
            $this->assertSame(
                ['Ali'],
                $this->mainDatabaseFileOnly(),
                "The row must exist ONLY in the -wal — otherwise this test isn't testing anything.",
            );

            // QueueWorker::down() kills the child. Swapped in so the kill lands at exactly
            // the point in restore() where the real runtime's kill lands: inside
            // releaseDatabase(), step 4b — AFTER the snapshot has been taken. That ordering
            // is the bug's precondition. While the worker is alive through step 3, the
            // snapshot's own VACUUM INTO connection is not the last one and so does not
            // checkpoint; the -wal therefore survives into step 4b, where the worker dies
            // and leaves it orphaned, unheld, and deletable.
            config(['nativephp-internal.running' => true]);

            QueueWorker::swap(new class($process) implements QueueWorkerContract
            {
                public function __construct(private $process) {}

                public function up(QueueConfig $config): void {}

                public function down(string $alias): void
                {
                    // proc_terminate is what QueueWorker::down() amounts to: the child is
                    // killed, not asked to stop. proc_close then waits for it to be reaped,
                    // so its file handles are released — which is what makes the old code's
                    // unlink() of the -wal succeed.
                    if (is_resource($this->process)) {
                        proc_terminate($this->process);
                        proc_close($this->process);
                        $this->process = null;
                    }
                }
            });

            // A plain read handle on the main database file, held by something that is not
            // SQLite: antivirus, the search indexer, or the queue worker the runtime has
            // just respawned. It does NOT stop the -wal being unlinked — but it does stop
            // the rename(), because Windows opens it without FILE_SHARE_DELETE. The two do
            // not fail under the same conditions, which is the assumption the old design
            // rested on and the reason it was wrong.
            $reader = fopen($this->dbPath, 'r');
            $this->assertIsResource($reader);

            $failure = $this->failedRestore($backup);
        } finally {
            if (is_resource($reader)) {
                fclose($reader);
            }

            // Belt and braces: if an assertion blew up before down() ran, don't leak a php.exe.
            if (is_resource($process)) {
                @proc_terminate($process);
                @proc_close($process);
            }
        }

        // THE ASSERTION. The restore aborted, so the club must still have everything they
        // had — including the row that was only ever in the WAL. Against the old code this
        // comes back as ['Ali']: the -wal was deleted, and with it every transaction
        // committed since SQLite's last automatic checkpoint.
        $this->assertSame(
            ['Ali', 'Omar'],
            $this->players(),
            'The restore DESTROYED committed data: the orphaned WAL was deleted instead of being '
            .'checkpointed into the database, reverting the club to its last automatic checkpoint.',
        );

        // And it is really in the database now, not still hiding in a WAL that the next
        // thing to touch this file could throw away.
        $this->assertSame(
            ['Ali', 'Omar'],
            $this->mainDatabaseFileOnly(),
            'The orphaned WAL was not folded into the database file itself.',
        );

        // The abort must not claim to have got further than it did — and, crucially, must
        // not tell the user that nothing happened while quietly having eaten their data.
        // Both halves of that are now true, and the message is allowed to say so.
        $this->assertStringContainsString('Nothing was changed', $failure->getMessage());
        $this->assertStringNotContainsString('failed partway through', $failure->getMessage());

        // The snapshot is named anyway, and it genuinely holds Omar: VACUUM INTO reads
        // through the WAL, so the undo point was complete even while the row was only there.
        $snapshots = $this->snapshots();
        $this->assertCount(1, $snapshots);
        $this->assertStringContainsString($snapshots[0], $failure->getMessage());

        $this->assertSame(['photo.jpg' => 'ORIGINAL'], $this->mediaTree(), 'The media was swapped by an aborted restore.');
        $this->assertFileDoesNotExist($this->dbPath.'.new', 'The incoming database was left staged at <db>.new.');
        $this->assertSame([], $this->stagingDirs(), 'The failed restore left a staging directory behind.');
    }

    #[Test]
    public function it_aborts_with_the_live_database_intact_when_the_wal_cannot_be_removed(): void
    {
        // The -wal and -shm cannot be deleted while another process holds the database
        // open (SQLite's Windows VFS opens without FILE_SHARE_DELETE), and a restore that
        // proceeds anyway leaves a stale WAL that will be checkpointed back over the
        // restored database. So the removal is verified, and if it fails the restore must
        // stop dead — nothing destructive has run at that point, the live database is
        // whole, and the snapshot is already on disk, so aborting costs the user nothing.
        $backup = $this->service()->create();

        $marker = dirname($this->dbPath).DIRECTORY_SEPARATOR.'.migrated';
        file_put_contents($marker, 'signature-of-the-current-migration-set');

        file_put_contents($this->mediaPath.DIRECTORY_SEPARATOR.'photo.jpg', 'CHANGED');

        // Holds the .sqlite, the -wal and the -shm — exactly as the queue worker does.
        $worker = new \PDO('sqlite:'.$this->dbPath);
        $worker->exec("INSERT INTO players (name) VALUES ('Omar')");

        $this->assertFileExists($this->dbPath.'-wal');

        $caught = null;

        try {
            $this->service()->restore($backup);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        // Read the live database while the worker is STILL holding it — and read it as
        // DATA, not as bytes.
        //
        // This used to compare md5_file() before and after, on the theory that an aborted
        // restore must leave the main file untouched to the byte. That is no longer true,
        // and it was never really the point. releaseDatabase() now CHECKPOINTS the WAL into
        // the database instead of deleting it, so the main file legitimately changes here:
        // it gains the very rows the old code used to throw away. A checkpoint is a merge —
        // the same thing SQLite does to this file on its own schedule — not a loss.
        //
        // The invariant is "an abort loses nothing", so that is what is measured.
        clearstatcache();
        $liveDuring = $this->playersOrFailure();
        $walStillThere = file_exists($this->dbPath.'-wal');
        $incomingLeft = file_exists($this->dbPath.'.new');

        $worker = null;

        $this->assertNotNull($caught, 'The restore must abort when the WAL files cannot be removed.');

        // The error has to name the file that is in the way, say the restore changed
        // nothing, and tell the user how to get out of it — a retry into the same locked
        // WAL is not a fix.
        $this->assertStringContainsString($this->dbPath.'-wal', $caught->getMessage());
        $this->assertStringContainsString('Nothing was changed', $caught->getMessage());
        $this->assertStringContainsString('close the application', $caught->getMessage());

        // And, as every error past the snapshot must, it names the snapshot.
        $snapshots = $this->snapshots();
        $this->assertCount(1, $snapshots);
        $this->assertStringContainsString($snapshots[0], $caught->getMessage());

        // Nothing destructive ran. The uncheckpointed write is still there — merged into
        // the database now rather than sitting in the WAL, but there, which is the whole
        // difference between a checkpoint and an unlink. The WAL sidecar itself is still on
        // disk (the worker is holding it), the media is untouched, and the marker was never
        // dropped.
        $this->assertSame(['Ali', 'Omar'], $liveDuring, 'An aborted restore lost the uncheckpointed write.');
        $this->assertTrue($walStillThere, 'The live WAL was destroyed by an aborted restore.');
        $this->assertFalse($incomingLeft, 'The incoming database was left staged at <db>.new.');

        $this->assertSame(['Ali', 'Omar'], $this->players(), 'The live database lost the uncheckpointed write.');
        $this->assertSame(['photo.jpg' => 'CHANGED'], $this->mediaTree(), 'The media was swapped by an aborted restore.');
        $this->assertFileExists($marker, 'The marker was dropped even though the database was never swapped.');
        $this->assertSame([], $this->stagingDirs(), 'The failed restore left a staging directory behind.');
    }

    #[Test]
    public function it_stops_the_native_queue_worker_before_swapping_the_database(): void
    {
        // The worker holds the database, the -wal and the -shm open, and nothing in this
        // process can close another process's handles — so the restore has to ask the
        // NativePHP runtime to stop the child before it swaps anything. That path only
        // exists inside the packaged app, so the guard is opened here and the runtime's
        // HTTP API is stood in for by the facade's own fake.
        config(['nativephp-internal.running' => true]);

        $worker = QueueWorker::fake();

        $backup = $this->service()->create();

        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec("INSERT INTO players (name) VALUES ('Omar')");
        $pdo = null;

        $this->service()->restore($backup);

        // 'default' is the worker config/nativephp.php declares, and QueueWorker::down()
        // maps it to the `queue_default` child process.
        $worker->assertDown('default');

        $this->assertSame(['Ali'], $this->players());
        $this->assertSame(['photo.jpg' => 'ORIGINAL'], $this->mediaTree());
    }

    #[Test]
    public function it_writes_a_pre_restore_snapshot_before_overwriting_anything(): void
    {
        $backup = $this->service()->create();

        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec("INSERT INTO players (name) VALUES ('Omar')");
        $pdo = null; // See it_restores_both_the_database_and_the_media(): stands in for DB::disconnect().

        $this->service()->restore($backup);

        $snapshots = $this->snapshots();
        $this->assertCount(1, $snapshots);

        // The snapshot holds the data as it was *just before* the restore — Omar included.
        $extracted = $this->root.DIRECTORY_SEPARATOR.'snap';
        mkdir($extracted, 0777, true);

        $zip = new ZipArchive;
        $zip->open($snapshots[0]);
        $zip->extractTo($extracted);
        $zip->close();

        $snapshotPdo = new \PDO('sqlite:'.$extracted.DIRECTORY_SEPARATOR.'database.sqlite');
        $names = $snapshotPdo->query('SELECT name FROM players ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame(['Ali', 'Omar'], $names);
    }

    #[Test]
    public function it_deletes_the_migrated_marker_so_the_next_launch_re_migrates(): void
    {
        $marker = dirname($this->dbPath).DIRECTORY_SEPARATOR.'.migrated';
        file_put_contents($marker, 'old-signature');

        $backup = $this->service()->create();

        $this->service()->restore($backup);

        $this->assertFileDoesNotExist($marker);
    }

    #[Test]
    public function a_failure_past_the_point_of_no_return_names_the_snapshot_in_the_error(): void
    {
        $backup = $this->service()->create();

        $this->makeMediaSwapImpossible();

        $e = $this->failedRestore($backup);

        $snapshots = $this->snapshots();

        $this->assertCount(1, $snapshots, 'A snapshot must have been written before the swap.');
        $this->assertStringContainsString(
            $snapshots[0],
            $e->getMessage(),
            'The error must name the pre-restore snapshot — it is the only way back.',
        );

        // The CLASS is the signal, and it is the whole reason RestoreFailedAfterSwapException
        // exists. BackupController::restore() reads it to decide whether the app MUST
        // relaunch: past the swap the live database has already been replaced and the queue
        // worker has already been killed to get there, so leaving the app running as it is
        // is not an option — even though this call failed. Nothing used to pin it: deleting
        // the `throw new RestoreFailedAfterSwapException` and throwing a plain
        // RuntimeException instead left the entire suite green, and the controller silently
        // stopped relaunching after the one failure that cannot be walked away from.
        $this->assertInstanceOf(
            RestoreFailedAfterSwapException::class,
            $e,
            'A failure past the swap must be distinguishable BY CLASS from a pre-swap one, '
            .'or the controller cannot know the app has to relaunch.',
        );

        // And it carries the snapshot as data, not only inside the prose: the controller
        // persists that path (BackupSettings::recordRestore()) so the banner still names it
        // after the relaunch, and re-parsing it out of an English sentence is exactly the
        // string-matching this exception class exists to avoid.
        $this->assertSame($snapshots[0], $e->snapshot);
    }

    #[Test]
    public function an_abort_before_the_swap_puts_the_queue_worker_back(): void
    {
        // releaseDatabase() KILLS the queue worker — it is the other process holding the
        // live database open, and the swap cannot happen while it does. When the restore
        // then aborts BEFORE the swap (the sidecars never clear, as here), the live data is
        // provably intact and the app carries on running... with a dead worker. Nothing else
        // restarts it, so every queued job stops running for the rest of the session:
        // including the automatic CreateBackup, whose heartbeat goes on enqueueing jobs that
        // nobody drains. The restore has to put back what it took down.
        config(['nativephp-internal.running' => true]);

        $worker = QueueWorker::fake();

        $backup = $this->service()->create();

        // A second connection this process cannot close, so the -wal/-shm never clear and
        // releaseDatabase() aborts — before the swap, with the live database whole.
        $holder = new \PDO('sqlite:'.$this->dbPath);
        $holder->exec("INSERT INTO players (name) VALUES ('Omar')");

        $e = $this->failedRestore($backup);

        $holder = null;

        $this->assertNotInstanceOf(
            RestoreFailedAfterSwapException::class,
            $e,
            'This restore must abort before the swap — otherwise it is not testing the abort path.',
        );
        $this->assertStringContainsString('Nothing was changed', $e->getMessage());

        // Taken down to attempt the swap...
        $worker->assertDown('default');

        // ...and put back, with the config it was spawned with, once the swap was abandoned.
        $worker->assertUp(fn (QueueConfig $config) => $config->alias === 'default'
            && $config->queuesToConsume === ['default']);
    }

    #[Test]
    public function the_migrated_marker_is_gone_even_when_the_media_swap_fails(): void
    {
        // Regression test. The marker used to be deleted at the *end* of step 4, after
        // the media swap. If the database was replaced and the media swap then threw,
        // the unlink was skipped — leaving an old-schema database behind a marker
        // claiming the schema is current. NativeAppServiceProvider::firstRunSetup()
        // trusts that marker and skips `migrate`, so the app boots on a schema that is
        // missing tables the code requires and every request dies. The marker must be
        // dropped the moment the database is swapped, not after the media.
        $marker = dirname($this->dbPath).DIRECTORY_SEPARATOR.'.migrated';
        file_put_contents($marker, 'signature-of-the-current-migration-set');

        $backup = $this->service()->create();

        // Diverge, so we can tell the restored database apart from the live one.
        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec("INSERT INTO players (name) VALUES ('Omar')");
        $pdo = null;

        $this->makeMediaSwapImpossible();

        $this->failedRestore($backup);

        // The database really was replaced — so the app is now on the backup's schema...
        $this->assertSame(['Ali'], $this->players(), 'The database should already have been swapped in.');

        // ...which means the marker must be gone, or the next launch will skip migrate.
        $this->assertFileDoesNotExist($marker, 'A half-finished restore left the .migrated marker behind.');
    }

    #[Test]
    public function a_failed_media_swap_never_leaves_a_merged_or_half_deleted_media_tree(): void
    {
        // Regression test for the copy-then-delete fallback. On Windows a single open
        // handle (antivirus, the indexer, an image viewer) makes rename() of the media
        // directory fail. The fallback would then copy the backup's media *into* the old
        // tree it had failed to delete, and deleteDirectory() — which returns void and
        // swallows every failure — let it report success either way. Depending on how
        // the copy and the delete interleave that leaves the media root as old ∪ backup,
        // or (as it happens here) emptied outright. Both come from the same defect: a
        // move that mutates the tree and then lies about whether it worked.
        //
        // A plain rename() cannot get this wrong: it either moves the whole directory or
        // does nothing at all. The guarantee is that the live tree is left EXACTLY as it
        // was — never a mixture, never half-deleted.
        file_put_contents($this->mediaPath.DIRECTORY_SEPARATOR.'in-backup-only.jpg', 'FROM-BACKUP');

        $backup = $this->service()->create();

        // Live media now diverges from the backup's: one file only the live tree has,
        // one only the backup has. A merge would be visible as both being present.
        unlink($this->mediaPath.DIRECTORY_SEPARATOR.'in-backup-only.jpg');
        file_put_contents($this->mediaPath.DIRECTORY_SEPARATOR.'live-only.jpg', 'LIVE-ONLY');

        $before = $this->mediaTree();
        $this->assertSame(['live-only.jpg', 'photo.jpg'], array_keys($before));

        // Hold a handle open inside the media root: this is what makes rename() fail,
        // exactly as antivirus or an image viewer does on a user's machine.
        $handle = fopen($this->mediaPath.DIRECTORY_SEPARATOR.'photo.jpg', 'r');

        try {
            $this->failedRestore($backup);
        } finally {
            fclose($handle);
        }

        $after = $this->mediaTree();

        // Either wholly the original or absent — never a mixture.
        $this->assertArrayNotHasKey(
            'in-backup-only.jpg',
            $after,
            'The backup\'s media was merged into the surviving live media tree.',
        );
        $this->assertSame($before, $after, 'The live media must be left exactly as it was.');
        $this->assertSame([], $this->stagingDirs(), 'The failed restore left a staging directory behind.');
    }

    #[Test]
    public function staging_is_cleaned_up_when_the_snapshot_fails(): void
    {
        // Regression test. The snapshot() call used to sit outside the try/finally that
        // removes the staging directory, so a snapshot failure (USB stick unplugged,
        // disk full) left a full plaintext copy of the database and every photo in
        // storage/app/backup-tmp — which nothing in the app ever prunes.
        $backup = $this->service()->create();

        // Move the backup somewhere safe, then take the destination away. snapshot()
        // needs a writable destination, so it now throws — after staging is populated.
        $safe = $this->root.DIRECTORY_SEPARATOR.'safe';
        mkdir($safe, 0777, true);
        $safeBackup = $safe.DIRECTORY_SEPARATOR.basename($backup);
        copy($backup, $safeBackup);

        $this->deleteTree($this->destination);
        $this->assertDirectoryDoesNotExist($this->destination);

        $e = $this->failedRestore($safeBackup);

        $this->assertStringContainsString('backup folder is not available', $e->getMessage());

        $this->assertSame(
            [],
            $this->stagingDirs(),
            'A restore that failed at the snapshot step leaked its staging directory.',
        );

        // And it stopped before touching anything.
        $this->assertSame(['Ali'], $this->players());
        $this->assertSame(['photo.jpg' => 'ORIGINAL'], $this->mediaTree());
    }

    #[Test]
    public function the_live_database_survives_when_the_incoming_copy_cannot_be_staged(): void
    {
        // Regression test for the swap order. The restore used to unlink -wal/-shm and
        // then copy() straight over the live database file. copy() truncates its
        // destination as it opens it, so a copy that fails (a full disk — the snapshot
        // zip was just written to this same drive) destroyed the live database on its
        // way out. The incoming database is now landed at <db>.new first and only
        // rename()d into place once it is completely on disk, so a failure to write it
        // leaves the live database exactly as it was.
        //
        // The failure is forced by occupying <db>.new with a directory, which no copy()
        // can overwrite.
        //
        // The fixture is in WAL mode (see setUp()), but no -wal exists by the time step 4
        // runs when nobody else holds the database: closing the last connection — step 3's
        // snapshot() opens and closes a PDO to run VACUUM INTO — checkpoints the WAL into
        // the main file and deletes both sidecars. A -wal that survives into the swap needs
        // a second process pinning it open, which is
        // it_aborts_with_the_live_database_intact_when_the_wal_cannot_be_removed()'s job.
        // What this test guards is the ordering: the incoming database is staged first, so
        // failing to write it runs nothing destructive at all.
        $backup = $this->service()->create();

        // Live data diverges from the backup, so a swapped-in database is unmistakable.
        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec("INSERT INTO players (name) VALUES ('Omar')");
        $pdo = null;

        $this->assertSame(['Ali', 'Omar'], $this->players());

        $marker = dirname($this->dbPath).DIRECTORY_SEPARATOR.'.migrated';
        file_put_contents($marker, 'signature');

        mkdir($this->dbPath.'.new', 0777, true);

        $e = $this->failedRestore($backup);

        // Nothing ran at all, so the message must say exactly that — and must NOT open with
        // "The restore failed partway through.", which is what it used to do on this very
        // path while its own next sentence said, correctly, that nothing had changed. Two
        // contradictory sentences in one message leave the user unable to tell whether they
        // need to go and restore the snapshot, and that ambiguity is precisely what made it
        // possible to ship a restore that destroyed the WAL and then reported a no-op.
        $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        $this->assertStringNotContainsString('failed partway through', $e->getMessage());

        // It still names the snapshot, as a belt-and-braces reference.
        $this->assertStringContainsString($this->snapshots()[0], $e->getMessage());

        // Nothing destructive may have run: the live database is still the live one.
        $this->assertSame(['Ali', 'Omar'], $this->players(), 'The live database was destroyed by a failed copy.');
        $this->assertSame(['photo.jpg' => 'ORIGINAL'], $this->mediaTree(), 'The media was swapped despite the database copy failing.');
        $this->assertFileExists($marker, 'The marker was dropped even though the database was never replaced.');
        $this->assertSame([], $this->stagingDirs());
    }

    #[Test]
    public function a_superseded_media_folder_that_cannot_be_deleted_is_returned_not_thrown(): void
    {
        // deleteDirectory() used to return void and swallow every failure, so the
        // superseded media tree (storage/app/public.old-<ts>) could outlive a *successful*
        // restore with nobody the wiser: a full duplicate of every photo the club owns,
        // which nothing in the app ever prunes. And because its name is only
        // second-granular, a second restore inside the same second would find the name
        // already taken, fail to move the live media aside, and abort with the database
        // already swapped. So it has to be reported.
        //
        // But it must not be reported by THROWING. This is a success with a caveat, and an
        // exception cannot say that: the caller cannot tell it apart from a real failure
        // except by string-matching, so it takes the failure branch and skips its success
        // path — including the App::relaunch() the restore depends on — leaving the app
        // running against a database that has just been swapped underneath it. The leftover
        // comes back as a return value; the caller decides what to say about it.
        //
        // A read-only file reproduces the failure honestly: Windows unlink() refuses one,
        // so the recursive delete genuinely cannot finish.
        $locked = $this->mediaPath.DIRECTORY_SEPARATOR.'locked.jpg';
        file_put_contents($locked, 'LOCKED');

        $backup = $this->service()->create();

        // Marked read-only only AFTER the backup was taken, so the copy inside the zip is
        // an ordinary file: the restored tree still drops into place cleanly and it is
        // purely the retired tree that cannot be removed.
        chmod($locked, 0444);

        try {
            // The restore SUCCEEDED, so it must not throw.
            $leftover = $this->service()->restore($backup);

            $retired = glob($this->mediaHolder.DIRECTORY_SEPARATOR.'public.old-*') ?: [];

            $this->assertCount(1, $retired, 'The undeletable media tree should still be on disk.');
            $this->assertSame(
                $retired[0],
                $leftover,
                'The restore must hand the leftover folder back to the caller — only the user can clear it.',
            );

            // The restore really did land: database and media are the backup's.
            $this->assertSame(['Ali'], $this->players());
            $this->assertSame(
                ['locked.jpg' => 'LOCKED', 'photo.jpg' => 'ORIGINAL'],
                $this->mediaTree(),
                'The restored media must be in place even though the old tree could not be deleted.',
            );
            $this->assertSame([], $this->stagingDirs());
        } finally {
            // Let tearDown() remove the tree.
            foreach (glob($this->mediaHolder.DIRECTORY_SEPARATOR.'public.old-*'.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @chmod($file, 0666);
            }
        }
    }

    #[Test]
    public function it_rejects_a_backup_whose_manifest_promises_media_the_archive_does_not_hold(): void
    {
        // The mirror image of restoring_a_backup_that_held_no_media_empties_the_media_tree,
        // and just as damaging. The manifest says the club HAD photos, but the archive
        // carries no media/ tree — a truncated zip, or one built by something that dropped
        // it. Keying the media swap off "is there a media/ directory in staging?" restores
        // the database on its own and silently skips the media, handing the club rows that
        // point at photos which are in neither the backup nor the live tree.
        $backup = $this->service()->create();

        $zip = new ZipArchive;
        $zip->open($backup);
        $database = $zip->getFromName('database.sqlite');
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        // The real backup did hold the club's one photo...
        $this->assertSame(1, $manifest['media_files']);

        // ...but this archive carries the same manifest with the media/ entries stripped.
        $stripped = $this->destination.DIRECTORY_SEPARATOR.BackupService::PREFIX.'2026-01-05_000000.zip';

        $zip = new ZipArchive;
        $zip->open($stripped, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->addFromString('database.sqlite', $database);
        $zip->close();

        $e = $this->failedRestore($stripped);

        $this->assertStringContainsString('incomplete', $e->getMessage());
        $this->assertStringContainsString('1 media file', $e->getMessage());

        // Caught in staging, before the snapshot: nothing was touched.
        $this->assertLiveDataUntouched();
    }

    #[Test]
    public function restoring_a_backup_that_held_no_media_empties_the_media_tree(): void
    {
        // A backup taken while the club had zero photos carries no media/ entry in the
        // zip at all. The restore used to key its media swap off "is there a media/
        // directory in the staging area?", so restoring such a backup replaced the
        // database and left TODAY's photos sitting there — the club is handed rows that
        // reference nothing and files the backup says never existed, which is not "exactly
        // as it was". The manifest records media_files, which tells an intentionally empty
        // media tree apart from one that is merely absent from the archive.
        $this->deleteTree($this->mediaPath);
        mkdir($this->mediaPath, 0777, true);
        $this->assertSame([], $this->mediaTree());

        $backup = $this->service()->create();

        // The claim the restore keys off has to actually be in the manifest.
        $zip = new ZipArchive;
        $zip->open($backup);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertFalse($zip->locateName('media/'), 'A media-less backup should carry no media entry.');
        $zip->close();

        $this->assertSame(0, $manifest['media_files']);

        // The club uploads photos *after* the backup was taken.
        file_put_contents($this->mediaPath.DIRECTORY_SEPARATOR.'uploaded-later.jpg', 'ADDED-AFTER-THE-BACKUP');

        $this->service()->restore($backup);

        $this->assertDirectoryExists($this->mediaPath, 'The media root must still exist — just empty.');
        $this->assertSame(
            [],
            $this->mediaTree(),
            'Restoring a backup that held no media must leave no media behind.',
        );
        $this->assertSame([], $this->stagingDirs(), 'A successful restore left a staging directory behind.');
    }

    #[Test]
    public function it_rejects_a_backup_belonging_to_another_application(): void
    {
        $foreign = $this->destination.DIRECTORY_SEPARATOR.BackupService::PREFIX.'2026-01-01_000000.zip';

        $zip = new ZipArchive;
        $zip->open($foreign, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode(['app' => 'SomeOtherApp']));
        $zip->addFromString('database.sqlite', 'irrelevant');
        $zip->close();

        $this->assertStringContainsString('different application', $this->failedRestore($foreign)->getMessage());

        $this->assertLiveDataUntouched();
    }

    #[Test]
    public function it_rejects_a_backup_whose_database_is_corrupt_without_touching_the_live_data(): void
    {
        $corrupt = $this->destination.DIRECTORY_SEPARATOR.BackupService::PREFIX.'2026-01-02_000000.zip';

        $zip = new ZipArchive;
        $zip->open($corrupt, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode(['app' => config('app.name')]));
        $zip->addFromString('database.sqlite', 'this is definitely not a sqlite file');
        $zip->close();

        $e = $this->failedRestore($corrupt);

        $this->assertStringContainsString('corrupt', $e->getMessage());

        // The other half of the post-swap signal, and it has to be pinned from both sides.
        // Nothing was swapped here — the backup was rejected in staging — so this must NOT
        // be a RestoreFailedAfterSwapException: BackupController::restore() relaunches the
        // whole app on that class, and throwing the user out of the application to recover
        // from a restore that changed nothing at all is not a recovery. It is a bug that
        // looks like one.
        $this->assertNotInstanceOf(RestoreFailedAfterSwapException::class, $e);

        $this->assertLiveDataUntouched();
    }

    #[Test]
    public function it_rejects_a_zip_that_is_missing_the_database(): void
    {
        $incomplete = $this->destination.DIRECTORY_SEPARATOR.BackupService::PREFIX.'2026-01-03_000000.zip';

        $zip = new ZipArchive;
        $zip->open($incomplete, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode(['app' => config('app.name')]));
        $zip->close();

        $this->assertStringContainsString('no manifest or database', $this->failedRestore($incomplete)->getMessage());

        $this->assertLiveDataUntouched();
    }

    #[Test]
    public function it_rejects_a_file_that_is_not_a_zip(): void
    {
        $junk = $this->destination.DIRECTORY_SEPARATOR.BackupService::PREFIX.'2026-01-04_000000.zip';
        file_put_contents($junk, 'not a zip at all');

        $this->assertStringContainsString('not a readable backup archive', $this->failedRestore($junk)->getMessage());

        $this->assertLiveDataUntouched();
    }
}
