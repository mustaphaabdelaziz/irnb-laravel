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

    private string $mediaPath;

    private string $destination;

    private BackupSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::swap(new FakeNativeSettings);

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup-restore-'.uniqid();
        $this->dbPath = $this->root.'/data/database.sqlite';
        $this->mediaPath = $this->root.'/media';
        $this->destination = $this->root.'/dest';

        mkdir(dirname($this->dbPath), 0777, true);
        mkdir($this->mediaPath, 0777, true);
        mkdir($this->destination, 0777, true);

        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT)');
        $pdo->exec('CREATE TABLE players (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO players (name) VALUES ('Ali')");

        file_put_contents($this->mediaPath.'/photo.jpg', 'ORIGINAL');

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

    private function players(): array
    {
        $pdo = new \PDO('sqlite:'.$this->dbPath);

        return $pdo->query('SELECT name FROM players ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    #[Test]
    public function it_restores_both_the_database_and_the_media(): void
    {
        $backup = $this->service()->create();

        // Diverge from the backup: add a row, overwrite the photo.
        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec("INSERT INTO players (name) VALUES ('Omar')");
        file_put_contents($this->mediaPath.'/photo.jpg', 'CHANGED');

        $this->assertSame(['Ali', 'Omar'], $this->players());

        $this->service()->restore($backup);

        $this->assertSame(['Ali'], $this->players());
        $this->assertSame('ORIGINAL', file_get_contents($this->mediaPath.'/photo.jpg'));
    }

    #[Test]
    public function it_writes_a_pre_restore_snapshot_before_overwriting_anything(): void
    {
        $backup = $this->service()->create();

        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec("INSERT INTO players (name) VALUES ('Omar')");

        $this->service()->restore($backup);

        $snapshots = glob($this->destination.'/'.BackupService::SNAPSHOT_DIR.'/pre-restore-*.zip');
        $this->assertCount(1, $snapshots);

        // The snapshot holds the data as it was *just before* the restore — Omar included.
        $extracted = $this->root.'/snap';
        mkdir($extracted, 0777, true);

        $zip = new ZipArchive;
        $zip->open($snapshots[0]);
        $zip->extractTo($extracted);
        $zip->close();

        $snapshotPdo = new \PDO('sqlite:'.$extracted.'/database.sqlite');
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
    public function it_rejects_a_backup_belonging_to_another_application(): void
    {
        $foreign = $this->destination.'/'.BackupService::PREFIX.'2026-01-01_000000.zip';

        $zip = new ZipArchive;
        $zip->open($foreign, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode(['app' => 'SomeOtherApp']));
        $zip->addFromString('database.sqlite', 'irrelevant');
        $zip->close();

        try {
            $this->service()->restore($foreign);
            $this->fail('Expected a foreign backup to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('different application', $e->getMessage());
        }

        // Untouched.
        $this->assertSame(['Ali'], $this->players());
        $this->assertSame([], glob($this->destination.'/'.BackupService::SNAPSHOT_DIR.'/*.zip') ?: []);
    }

    #[Test]
    public function it_rejects_a_backup_whose_database_is_corrupt_without_touching_the_live_data(): void
    {
        $corrupt = $this->destination.'/'.BackupService::PREFIX.'2026-01-02_000000.zip';

        $zip = new ZipArchive;
        $zip->open($corrupt, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode(['app' => config('app.name')]));
        $zip->addFromString('database.sqlite', 'this is definitely not a sqlite file');
        $zip->close();

        try {
            $this->service()->restore($corrupt);
            $this->fail('Expected a corrupt backup to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('corrupt', $e->getMessage());
        }

        $this->assertSame(['Ali'], $this->players());
        $this->assertSame('ORIGINAL', file_get_contents($this->mediaPath.'/photo.jpg'));
    }

    #[Test]
    public function it_rejects_a_zip_that_is_missing_the_database(): void
    {
        $incomplete = $this->destination.'/'.BackupService::PREFIX.'2026-01-03_000000.zip';

        $zip = new ZipArchive;
        $zip->open($incomplete, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode(['app' => config('app.name')]));
        $zip->close();

        $this->expectException(RuntimeException::class);

        $this->service()->restore($incomplete);
    }

    #[Test]
    public function it_rejects_a_file_that_is_not_a_zip(): void
    {
        $junk = $this->destination.'/'.BackupService::PREFIX.'2026-01-04_000000.zip';
        file_put_contents($junk, 'not a zip at all');

        $this->expectException(RuntimeException::class);

        $this->service()->restore($junk);
    }
}
