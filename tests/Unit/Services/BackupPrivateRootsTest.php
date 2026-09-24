<?php

namespace Tests\Unit\Services;

use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use App\Services\Backup\RestoreFailedAfterSwapException;
use InvalidArgumentException;
use Native\Desktop\Facades\Settings;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeNativeSettings;
use Tests\TestCase;
use ZipArchive;

class BackupPrivateRootsTest extends TestCase
{
    private string $root;

    private string $dbPath;

    private string $mediaPath;

    private string $documentsPath;

    private string $minutesPath;

    private string $receiptsPath;

    private string $destination;

    private BackupSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::swap(new FakeNativeSettings);

        // Under storage_path(), like BackupRestoreTest: the restore moves folders with
        // a plain rename(), which only works on the volume the staging area lives on.
        $this->root = storage_path('app').DIRECTORY_SEPARATOR.'backup-private-test-'.uniqid();
        $this->dbPath = $this->root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'database.sqlite';
        $this->mediaPath = $this->root.DIRECTORY_SEPARATOR.'public';
        $this->documentsPath = $this->root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'player-documents';
        $this->minutesPath = $this->root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'minutes';
        $this->receiptsPath = $this->root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'receipts';
        $this->destination = $this->root.DIRECTORY_SEPARATOR.'dest';

        mkdir(dirname($this->dbPath), 0777, true);
        mkdir($this->mediaPath, 0777, true);
        mkdir($this->documentsPath.DIRECTORY_SEPARATOR.'7', 0777, true);
        mkdir($this->minutesPath, 0777, true);
        mkdir($this->receiptsPath, 0777, true);
        mkdir($this->destination, 0777, true);

        // WAL, as the packaged app always is (see BackupRestoreTest).
        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT)');
        $pdo->exec('CREATE TABLE players (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO players (name) VALUES ('Ali')");
        $pdo = null;

        file_put_contents($this->mediaPath.DIRECTORY_SEPARATOR.'photo.jpg', 'PHOTO');
        file_put_contents($this->documentsPath.DIRECTORY_SEPARATOR.'7'.DIRECTORY_SEPARATOR.'scan.pdf', 'SCAN');
        file_put_contents($this->minutesPath.DIRECTORY_SEPARATOR.'agm.pdf', 'MINUTES');
        file_put_contents($this->receiptsPath.DIRECTORY_SEPARATOR.'invoice.jpg', 'RECEIPT');

        $this->settings = new BackupSettings;
        $this->settings->put(['destination' => $this->destination]);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        foreach (glob(storage_path('app/backup-tmp').DIRECTORY_SEPARATOR.'restore-*') ?: [] as $leftover) {
            $this->deleteTree($leftover);
        }

        parent::tearDown();
    }

    private function service(): BackupService
    {
        return new BackupService($this->settings, $this->dbPath, $this->mediaPath, [
            'player-documents' => $this->documentsPath,
            'minutes' => $this->minutesPath,
            'receipts' => $this->receiptsPath,
        ]);
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

    /** relative path (forward slashes) => contents */
    private function tree(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $tree = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                $tree[$relative] = file_get_contents($file->getPathname());
            }
        }

        ksort($tree);

        return $tree;
    }

    private function players(): array
    {
        return (new \PDO('sqlite:'.$this->dbPath))->query('SELECT name FROM players ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** A copy of $backup without its private/ entries, with $manifest written in. */
    private function rewrite(string $backup, array $manifest): string
    {
        $source = new ZipArchive;
        $source->open($backup);

        $copy = $this->destination.DIRECTORY_SEPARATOR.BackupService::PREFIX.'2026-01-05_000000.zip';
        $target = new ZipArchive;
        $target->open($copy, ZipArchive::CREATE);

        for ($i = 0; $i < $source->numFiles; $i++) {
            $name = $source->getNameIndex($i);

            if ($name === 'manifest.json' || str_starts_with($name, 'private/')) {
                continue;
            }

            $target->addFromString($name, $source->getFromIndex($i));
        }

        $target->addFromString('manifest.json', json_encode($manifest));
        $target->close();
        $source->close();

        return $copy;
    }

    private function manifestOf(string $backup): array
    {
        $zip = new ZipArchive;
        $zip->open($backup);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        return $manifest;
    }

    #[Test]
    public function a_backup_carries_each_private_root_and_counts_it(): void
    {
        $backup = $this->service()->create();

        $zip = new ZipArchive;
        $zip->open($backup);
        $this->assertSame('SCAN', $zip->getFromName('private/player-documents/7/scan.pdf'));
        $this->assertSame('MINUTES', $zip->getFromName('private/minutes/agm.pdf'));
        $this->assertSame('RECEIPT', $zip->getFromName('private/receipts/invoice.jpg'));
        $this->assertSame('PHOTO', $zip->getFromName('media/photo.jpg'));
        $zip->close();

        $manifest = $this->manifestOf($backup);
        $this->assertSame(['player-documents' => 1, 'minutes' => 1, 'receipts' => 1], $manifest['private_roots']);
        $this->assertSame(1, $manifest['media_files'], 'private files are not counted as media');
    }

    #[Test]
    public function restoring_puts_the_private_roots_back_exactly_as_they_were(): void
    {
        $backup = $this->service()->create();

        file_put_contents($this->documentsPath.DIRECTORY_SEPARATOR.'7'.DIRECTORY_SEPARATOR.'scan.pdf', 'CHANGED');
        file_put_contents($this->documentsPath.DIRECTORY_SEPARATOR.'7'.DIRECTORY_SEPARATOR.'later.pdf', 'ADDED-AFTER');
        unlink($this->minutesPath.DIRECTORY_SEPARATOR.'agm.pdf');
        file_put_contents($this->receiptsPath.DIRECTORY_SEPARATOR.'invoice.jpg', 'EDITED');

        $this->assertNull($this->service()->restore($backup));

        $this->assertSame(['7/scan.pdf' => 'SCAN'], $this->tree($this->documentsPath));
        $this->assertSame(['agm.pdf' => 'MINUTES'], $this->tree($this->minutesPath));
        $this->assertSame(['invoice.jpg' => 'RECEIPT'], $this->tree($this->receiptsPath));
        $this->assertSame(['photo.jpg' => 'PHOTO'], $this->tree($this->mediaPath));
        $this->assertSame([], glob($this->root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'*.old-*') ?: [], 'retired folders are cleaned up');
    }

    #[Test]
    public function a_root_that_did_not_exist_yet_is_created_by_the_restore(): void
    {
        $backup = $this->service()->create();
        $this->deleteTree($this->root.DIRECTORY_SEPARATOR.'private');

        $this->service()->restore($backup);

        $this->assertSame(['7/scan.pdf' => 'SCAN'], $this->tree($this->documentsPath));
        $this->assertSame(['agm.pdf' => 'MINUTES'], $this->tree($this->minutesPath));
        $this->assertSame(['invoice.jpg' => 'RECEIPT'], $this->tree($this->receiptsPath));
    }

    #[Test]
    public function a_backup_from_before_private_storage_restores_empty_private_roots(): void
    {
        $manifest = $this->manifestOf($backup = $this->service()->create());
        unset($manifest['private_roots']);
        $legacy = $this->rewrite($backup, $manifest);

        $this->service()->restore($legacy);

        $this->assertDirectoryExists($this->documentsPath);
        $this->assertSame([], $this->tree($this->documentsPath));
        $this->assertSame([], $this->tree($this->minutesPath));
        $this->assertSame([], $this->tree($this->receiptsPath));
        $this->assertSame(['Ali'], $this->players());
    }

    #[Test]
    public function it_rejects_a_backup_whose_manifest_promises_private_files_it_does_not_hold(): void
    {
        $manifest = $this->manifestOf($backup = $this->service()->create());
        $stripped = $this->rewrite($backup, $manifest); // private/ removed, counts kept

        $caught = null;
        try {
            $this->service()->restore($stripped);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'the restore should have been refused');
        $this->assertStringContainsString('incomplete', $caught->getMessage());

        // Caught in staging, before the snapshot: nothing changed.
        $this->assertSame(['7/scan.pdf' => 'SCAN'], $this->tree($this->documentsPath));
        $this->assertSame(['agm.pdf' => 'MINUTES'], $this->tree($this->minutesPath));
        $this->assertSame(['invoice.jpg' => 'RECEIPT'], $this->tree($this->receiptsPath));
        $this->assertSame([], glob($this->destination.DIRECTORY_SEPARATOR.BackupService::SNAPSHOT_DIR.DIRECTORY_SEPARATOR.'*.zip') ?: []);
    }

    #[Test]
    public function a_service_without_private_roots_behaves_as_before(): void
    {
        $backup = (new BackupService($this->settings, $this->dbPath, $this->mediaPath))->create();

        $zip = new ZipArchive;
        $zip->open($backup);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertStringStartsNotWith('private/', $zip->getNameIndex($i));
        }
        $zip->close();

        $this->assertSame([], $this->manifestOf($backup)['private_roots']);
    }

    #[Test]
    public function a_private_root_name_must_be_a_plain_folder_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BackupService($this->settings, $this->dbPath, $this->mediaPath, ['../escape' => $this->documentsPath]);
    }

    #[Test]
    public function a_failed_private_swap_puts_back_every_folder_already_swapped(): void
    {
        $backup = $this->service()->create();

        // Live data diverges from the backup in every tree.
        file_put_contents($this->mediaPath.DIRECTORY_SEPARATOR.'photo.jpg', 'LIVE-PHOTO');
        file_put_contents($this->documentsPath.DIRECTORY_SEPARATOR.'7'.DIRECTORY_SEPARATOR.'scan.pdf', 'LIVE-SCAN');
        file_put_contents($this->minutesPath.DIRECTORY_SEPARATOR.'agm.pdf', 'LIVE-MINUTES');

        // An open handle inside the LAST root makes its rename() fail on Windows, after the
        // database, the media and the first two private roots have already been swapped.
        $handle = fopen($this->receiptsPath.DIRECTORY_SEPARATOR.'invoice.jpg', 'r');

        $caught = null;
        try {
            $this->service()->restore($backup);
        } catch (RestoreFailedAfterSwapException $e) {
            $caught = $e;
        } finally {
            fclose($handle);
        }

        $this->assertNotNull($caught, 'a failure past the database swap must say so by class');

        // Every tree swapped before the failure is back to what it was: never a mixture.
        $this->assertSame(['photo.jpg' => 'LIVE-PHOTO'], $this->tree($this->mediaPath));
        $this->assertSame(['7/scan.pdf' => 'LIVE-SCAN'], $this->tree($this->documentsPath));
        $this->assertSame(['agm.pdf' => 'LIVE-MINUTES'], $this->tree($this->minutesPath));
        $this->assertSame(['invoice.jpg' => 'RECEIPT'], $this->tree($this->receiptsPath));
        $this->assertSame([], glob($this->root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'*.old-*') ?: []);
        $this->assertSame([], glob($this->root.DIRECTORY_SEPARATOR.'public.old-*') ?: []);
        $this->assertStringContainsString($caught->snapshot, $caught->getMessage());
    }

    #[Test]
    public function a_superseded_private_folder_that_cannot_be_deleted_is_returned_not_thrown(): void
    {
        $locked = $this->minutesPath.DIRECTORY_SEPARATOR.'locked.pdf';
        file_put_contents($locked, 'LOCKED');

        $backup = $this->service()->create();

        // Read-only after the backup: the restored copy is ordinary, only the retired
        // folder cannot be removed (Windows unlink() refuses a read-only file).
        chmod($locked, 0444);

        try {
            $leftover = $this->service()->restore($backup);

            $retired = glob($this->root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'minutes.old-*') ?: [];

            $this->assertCount(1, $retired);
            $this->assertSame($retired[0], $leftover);
            $this->assertSame(['agm.pdf' => 'MINUTES', 'locked.pdf' => 'LOCKED'], $this->tree($this->minutesPath));
        } finally {
            // Let tearDown() remove the tree, wherever the read-only file ended up.
            foreach (glob($this->root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'minutes*'.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @chmod($file, 0666);
            }
        }
    }
}
