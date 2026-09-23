<?php

namespace Tests\Unit\Services;

use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use Illuminate\Support\Carbon;
use Native\Desktop\Facades\Settings;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeNativeSettings;
use Tests\TestCase;
use ZipArchive;

class BackupServiceTest extends TestCase
{
    private string $root;

    private string $dbPath;

    private string $mediaPath;

    private string $destination;

    private BackupSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::swap(new FakeNativeSettings);

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup-svc-'.uniqid();
        $this->dbPath = $this->root.'/data/database.sqlite';
        $this->mediaPath = $this->root.'/media';
        $this->destination = $this->root.'/dest';

        mkdir(dirname($this->dbPath), 0777, true);
        mkdir($this->mediaPath.'/logos', 0777, true);
        mkdir($this->destination, 0777, true);

        // A realistic source DB: WAL mode, a migrations table, and a data table.
        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT)');
        $pdo->exec("INSERT INTO migrations (migration) VALUES ('0001_create_users')");
        $pdo->exec('CREATE TABLE players (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO players (name) VALUES ('Ali')");

        file_put_contents($this->mediaPath.'/logos/club.png', 'PNG-BYTES');

        $this->settings = new BackupSettings;
        $this->settings->put(['destination' => $this->destination]);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

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

    #[Test]
    public function create_writes_a_zip_holding_the_database_the_media_and_a_manifest(): void
    {
        $path = $this->service()->create();

        $this->assertFileExists($path);
        $this->assertStringStartsWith(BackupService::PREFIX, basename($path));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $this->assertNotFalse($zip->locateName('database.sqlite'));
        $this->assertNotFalse($zip->locateName('media/logos/club.png'));
        $this->assertSame('PNG-BYTES', $zip->getFromName('media/logos/club.png'));

        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertSame(config('app.name'), $manifest['app']);
        $this->assertSame(1, $manifest['media_files']);
        $this->assertSame($this->service()->schemaSignature(), $manifest['schema_signature']);
        $this->assertGreaterThan(0, $manifest['db_bytes']);
    }

    #[Test]
    public function the_snapshotted_database_is_consistent_and_readable(): void
    {
        $path = $this->service()->create();

        $extracted = $this->root.'/extracted';
        mkdir($extracted, 0777, true);

        $zip = new ZipArchive;
        $zip->open($path);
        $zip->extractTo($extracted);
        $zip->close();

        $pdo = new \PDO('sqlite:'.$extracted.'/database.sqlite');

        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame('Ali', $pdo->query('SELECT name FROM players')->fetchColumn());
    }

    #[Test]
    public function create_stamps_last_run_at(): void
    {
        $this->assertNull($this->settings->all()['last_run_at']);

        $this->service()->create();

        $this->assertNotNull($this->settings->all()['last_run_at']);
    }

    #[Test]
    public function create_fails_loudly_when_no_destination_is_set(): void
    {
        $this->settings->put(['destination' => null]);

        $this->expectException(RuntimeException::class);

        $this->service()->create();
    }

    #[Test]
    public function list_returns_backups_newest_first_and_ignores_the_pre_restore_folder(): void
    {
        $service = $this->service();

        file_put_contents($this->destination.'/'.BackupService::PREFIX.'2026-07-01_100000.zip', 'a');
        file_put_contents($this->destination.'/'.BackupService::PREFIX.'2026-07-03_100000.zip', 'b');
        file_put_contents($this->destination.'/unrelated.zip', 'c');
        mkdir($this->destination.'/'.BackupService::SNAPSHOT_DIR, 0777, true);
        file_put_contents($this->destination.'/'.BackupService::SNAPSHOT_DIR.'/pre-restore-2026-07-02_100000.zip', 'd');

        $names = array_column($service->list(), 'name');

        $this->assertSame([
            BackupService::PREFIX.'2026-07-03_100000.zip',
            BackupService::PREFIX.'2026-07-01_100000.zip',
        ], $names);
    }

    #[Test]
    public function prune_keeps_only_the_newest_n_and_never_touches_the_snapshot_folder(): void
    {
        $this->settings->put(['retention' => 2]);

        foreach (['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04'] as $day) {
            file_put_contents($this->destination.'/'.BackupService::PREFIX.$day.'_100000.zip', 'x');
        }

        $snapshotDir = $this->destination.'/'.BackupService::SNAPSHOT_DIR;
        mkdir($snapshotDir, 0777, true);
        file_put_contents($snapshotDir.'/pre-restore-2026-07-01_090000.zip', 'keep-me');

        $deleted = $this->service()->prune();

        $this->assertSame(2, $deleted);
        $this->assertCount(2, $this->service()->list());
        $this->assertFileExists($this->destination.'/'.BackupService::PREFIX.'2026-07-04_100000.zip');
        $this->assertFileExists($this->destination.'/'.BackupService::PREFIX.'2026-07-03_100000.zip');
        $this->assertFileDoesNotExist($this->destination.'/'.BackupService::PREFIX.'2026-07-01_100000.zip');
        $this->assertFileExists($snapshotDir.'/pre-restore-2026-07-01_090000.zip');
    }

    #[Test]
    public function a_retention_of_zero_keeps_everything(): void
    {
        $this->settings->put(['retention' => 0]);

        foreach (['2026-07-01', '2026-07-02', '2026-07-03'] as $day) {
            file_put_contents($this->destination.'/'.BackupService::PREFIX.$day.'_100000.zip', 'x');
        }

        $this->assertSame(0, $this->service()->prune());
        $this->assertCount(3, $this->service()->list());
    }

    #[Test]
    public function resolve_rejects_path_traversal_and_foreign_names(): void
    {
        $service = $this->service();

        foreach (['../../evil.zip', 'evil.zip', BackupService::PREFIX.'x.txt'] as $name) {
            try {
                $service->resolve($name);
                $this->fail("Expected {$name} to be rejected.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function resolve_returns_the_absolute_path_of_a_real_backup(): void
    {
        $name = BackupService::PREFIX.'2026-07-01_100000.zip';
        file_put_contents($this->destination.'/'.$name, 'x');

        $this->assertSame(
            $this->destination.DIRECTORY_SEPARATOR.$name,
            $this->service()->resolve($name),
        );
    }

    #[Test]
    public function list_ignores_a_leftover_tmp_staging_file_and_prune_does_not_count_it(): void
    {
        // A crash mid-write (kill -9, OOM, force-quit) can leave a
        // sport-club-backup-*.zip.tmp staging file behind. It must never be
        // presented as a real backup, nor eat into the retention count.
        file_put_contents($this->destination.'/'.BackupService::PREFIX.'2026-07-01_100000.zip', 'a');
        file_put_contents($this->destination.'/'.BackupService::PREFIX.'2026-07-02_100000.zip.tmp', 'partial-write');

        $service = $this->service();

        $names = array_column($service->list(), 'name');

        $this->assertSame([BackupService::PREFIX.'2026-07-01_100000.zip'], $names);

        $this->settings->put(['retention' => 1]);

        $this->assertSame(0, $service->prune());
        $this->assertFileExists($this->destination.'/'.BackupService::PREFIX.'2026-07-01_100000.zip');
        $this->assertFileExists($this->destination.'/'.BackupService::PREFIX.'2026-07-02_100000.zip.tmp');
    }

    #[Test]
    public function create_leaves_no_staging_file_behind_on_success(): void
    {
        $path = $this->service()->create();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $zip->close();

        $this->assertFileDoesNotExist($path.'.tmp');
        $this->assertSame([], glob($this->destination.DIRECTORY_SEPARATOR.'*.tmp') ?: []);
    }

    #[Test]
    public function list_finds_backups_when_the_destination_path_contains_glob_metacharacters(): void
    {
        // A Windows folder picker can hand back a path containing *, ?, [ or ] —
        // glob() would otherwise read those as pattern syntax and silently match
        // nothing, hiding every existing backup and defeating retention.
        $weirdDestination = $this->root.'/dest[1]';
        mkdir($weirdDestination, 0777, true);
        file_put_contents($weirdDestination.'/'.BackupService::PREFIX.'2026-07-01_100000.zip', 'a');

        $this->settings->put(['destination' => $weirdDestination]);

        $names = array_column($this->service()->list(), 'name');

        $this->assertSame([BackupService::PREFIX.'2026-07-01_100000.zip'], $names);
    }

    #[Test]
    public function a_failed_finalize_rename_leaves_no_backup_at_the_final_name_and_cleans_up_staging(): void
    {
        // Simulating a hard kill mid-write isn't practical in-process, but the
        // exact window it threatens is provable directly: force the finalize
        // rename() to fail *after* the zip has already been fully and
        // successfully written to the staging file, by occupying the final name
        // with a directory. The archive must never land at the final name, and
        // the staging file must not be left behind.
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00'));

        $finalPath = $this->destination.'/'.BackupService::PREFIX.'2026-07-05_100000.zip';
        mkdir($finalPath, 0777, true);

        try {
            $this->service()->create();
            $this->fail('Expected create() to throw when the final path cannot be finalized.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertTrue(is_dir($finalPath), 'The pre-existing directory at the final name must be left untouched.');
        $this->assertFileDoesNotExist($finalPath.'.tmp');
    }

    #[Test]
    public function create_throws_and_leaves_no_backup_when_a_media_file_cannot_be_added(): void
    {
        // ZipArchive::addFile() returns false — it does not throw — when the
        // source file no longer exists at the moment it is registered (e.g. a
        // player photo deleted or renamed mid-backup). If that return value is
        // ignored, ZipArchive::close() can still succeed for every other entry,
        // producing a "successful" backup that is silently missing that photo.
        // This subclass simulates exactly that: a file mediaFiles() reports as
        // present at scan time but that is gone by the time it would be zipped.
        $service = new class($this->settings, $this->dbPath, $this->mediaPath) extends BackupService
        {
            protected function mediaFiles(): iterable
            {
                yield sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup-svc-vanished-'.uniqid().'.jpg' => 'vanished.jpg';
            }
        };

        try {
            $service->create();
            $this->fail('Expected create() to throw when a media file cannot be added to the zip.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], glob($this->destination.DIRECTORY_SEPARATOR.BackupService::PREFIX.'*.zip') ?: []);
        $this->assertSame([], glob($this->destination.DIRECTORY_SEPARATOR.'*.tmp') ?: []);
    }
}
