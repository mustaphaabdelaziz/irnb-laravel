<?php

namespace Tests\Feature;

use App\Services\Backup\BackupService;
use App\Services\Player\Merge\PreMergeBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class PreMergeBackupTest extends TestCase
{
    private ?string $root = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        DB::purge('premerge');
        if ($this->root !== null) {
            foreach (glob($this->root.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->root);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_writes_a_pre_merge_backup_and_never_the_pruned_kind(): void
    {
        $this->mock(BackupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('preMerge')->once()->andReturn('pre-merge.zip');
            $mock->shouldNotReceive('create');
            $mock->shouldNotReceive('prune');
        });

        $this->assertSame('pre-merge.zip', app(PreMergeBackup::class)->run());
    }

    #[Test]
    public function without_a_sqlite_file_the_backup_failure_is_rethrown(): void
    {
        // phpunit runs on :memory:, so there is no file to copy.
        Exceptions::fake();
        $failure = new RuntimeException('No backup folder has been chosen yet.');
        $this->mock(BackupService::class, fn (MockInterface $mock) => $mock->shouldReceive('preMerge')->once()->andThrow($failure));

        try {
            app(PreMergeBackup::class)->run();
            $this->fail('The backup failure should have been rethrown.');
        } catch (RuntimeException $e) {
            $this->assertSame($failure, $e);
        }

        // The controller reports what it catches; reporting here too would log it twice.
        Exceptions::assertNothingReported();
    }

    #[Test]
    public function two_quick_fallback_copies_get_two_names(): void
    {
        Exceptions::fake();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pre-merge-'.uniqid();
        mkdir($this->root);
        $file = $this->root.DIRECTORY_SEPARATOR.'database.sqlite';
        $pdo = new \PDO('sqlite:'.$file);
        $pdo->exec('CREATE TABLE players (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo = null;
        config([
            'database.connections.premerge' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true],
            'database.default' => 'premerge',
        ]);
        $failure = new RuntimeException('No backup folder has been chosen yet.');
        $this->mock(BackupService::class, fn (MockInterface $mock) => $mock->shouldReceive('preMerge')->twice()->andThrow($failure));

        $first = app(PreMergeBackup::class)->run();
        $second = app(PreMergeBackup::class)->run();

        $this->assertNotSame($first, $second);
        $this->assertFileExists($first);
        $this->assertFileExists($second);
        // The copy worked, but why the real backup failed stays in the log.
        Exceptions::assertReported(fn (RuntimeException $e) => $e === $failure);
    }
}
