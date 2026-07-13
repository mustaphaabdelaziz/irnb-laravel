<?php

namespace Tests\Unit\Services;

use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
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
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT)');
        $pdo->exec('CREATE TABLE players (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO players (name) VALUES ('Ali')");

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
        // it_restores_while_the_queue_worker_still_holds_the_database_open().
        $pdo = null;

        $this->service()->restore($backup);

        $this->assertSame(['Ali'], $this->players());
        $this->assertSame('ORIGINAL', file_get_contents($this->mediaPath.DIRECTORY_SEPARATOR.'photo.jpg'));
        $this->assertSame([], $this->stagingDirs(), 'A successful restore left a staging directory behind.');
    }

    #[Test]
    public function it_restores_while_the_queue_worker_still_holds_the_database_open(): void
    {
        // This is not an edge case — it is the ONLY topology the packaged app ever runs
        // in, and a restore that cannot survive it is a restore that never works.
        //
        // NativeServiceProvider forces `queue.default = database` and calls
        // fireUpQueueWorkers() on every non-console boot (lines 144/148); it points that
        // connection at config('nativephp-internal.database_path') (line 201) — the exact
        // file AppServiceProvider hands to BackupService. config/nativephp.php declares a
        // `default` worker, and QueueWorker::up() spawns it as a *persistent* child
        // process running `queue:work`. That child holds an open PDO handle on the
        // database for the entire life of the app, and DB::disconnect() only closes the
        // web process's own connection — it cannot reach into another process.
        //
        // Windows refuses to rename() over a file that anyone still holds open (SQLite
        // does not open with FILE_SHARE_DELETE), so a swap built on rename() alone can
        // never complete in production: every restore would run all the way to the point
        // of no return, fail, and tell the user their data was saved in a snapshot they
        // do not need — while quietly leaving another full copy of the database and every
        // photo on the drive. copy()-ing over an open file DOES succeed on Windows, and
        // by the time the swap runs the incoming database is already whole at <db>.new
        // and the pre-restore snapshot is written, so the fallback is safe.
        $backup = $this->service()->create();

        // Diverge from the backup so a database that was not swapped is unmistakable.
        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec("INSERT INTO players (name) VALUES ('Omar')");
        file_put_contents($this->mediaPath.DIRECTORY_SEPARATOR.'photo.jpg', 'CHANGED');
        $pdo = null;

        $this->assertSame(['Ali', 'Omar'], $this->players());

        // The queue worker: a second connection, opened before the restore and still open
        // during it, that restore()'s DB::disconnect() has no way of closing. Idle, just
        // as the worker is between jobs — merely holding the handle is enough to block a
        // rename over the file.
        $worker = new \PDO('sqlite:'.$this->dbPath);
        $worker->query('SELECT count(*) FROM players')->fetchColumn();

        try {
            $this->service()->restore($backup);
        } finally {
            $worker = null;
        }

        $this->assertSame(['Ali'], $this->players(), 'The restore did not replace the database while it was held open.');
        $this->assertSame(
            ['photo.jpg' => 'ORIGINAL'],
            $this->mediaTree(),
            'The restore did not replace the media while the database was held open.',
        );
        $this->assertFileDoesNotExist($this->dbPath.'.new', 'The incoming database was left staged at <db>.new.');
        $this->assertSame([], $this->stagingDirs(), 'A successful restore left a staging directory behind.');
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
        // What this test does NOT assert is the survival of an uncheckpointed -wal file,
        // because at this point in restore() no -wal can exist: step 3's snapshot() runs
        // VACUUM INTO, which opens and closes a PDO on the live database, and closing the
        // last connection checkpoints the WAL into the main file and deletes -wal/-shm.
        // (Verified: this holds for a real WAL and even for a hand-written one.) The -wal
        // loss the old order risked therefore needs a second process pinning the WAL open
        // — and Windows will not unlink a file that process holds, so it cannot be staged
        // in-process. The observable guarantee is asserted instead: a failed incoming copy
        // runs nothing destructive.
        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo = null;

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

        $this->assertStringContainsString('restore failed partway through', $e->getMessage());

        // Nothing destructive may have run: the live database is still the live one.
        $this->assertSame(['Ali', 'Omar'], $this->players(), 'The live database was destroyed by a failed copy.');
        $this->assertSame(['photo.jpg' => 'ORIGINAL'], $this->mediaTree(), 'The media was swapped despite the database copy failing.');
        $this->assertFileExists($marker, 'The marker was dropped even though the database was never replaced.');
        $this->assertSame([], $this->stagingDirs());
    }

    #[Test]
    public function a_superseded_media_folder_that_cannot_be_deleted_is_reported_not_swallowed(): void
    {
        // deleteDirectory() used to return void and swallow every failure, so the
        // superseded media tree (storage/app/public.old-<ts>) could outlive a *successful*
        // restore with nobody the wiser: a full duplicate of every photo the club owns,
        // which nothing in the app ever prunes. And because its name is only
        // second-granular, a second restore inside the same second would find the name
        // already taken, fail to move the live media aside, and abort with the database
        // already swapped.
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

        $e = $this->failedRestore($backup);

        try {
            $retired = glob($this->mediaHolder.DIRECTORY_SEPARATOR.'public.old-*') ?: [];
            $snapshots = $this->snapshots();

            $this->assertCount(1, $retired, 'The undeletable media tree should still be on disk.');
            $this->assertCount(1, $snapshots);

            // The restore itself SUCCEEDED. Saying "failed partway through" here would send
            // the user off to recover from a snapshot they do not need — the very thing this
            // whole change exists to stop.
            $this->assertStringContainsString('restored successfully', $e->getMessage());
            $this->assertStringNotContainsString('failed partway through', $e->getMessage());

            // But the leftover is named, because only the user can clear it...
            $this->assertStringContainsString($retired[0], $e->getMessage());

            // ...and so is the snapshot, as every error past the point of no return must.
            $this->assertStringContainsString($snapshots[0], $e->getMessage());

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

        $this->assertStringContainsString('corrupt', $this->failedRestore($corrupt)->getMessage());

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
