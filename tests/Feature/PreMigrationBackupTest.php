<?php

namespace Tests\Feature;

use App\Services\Backup\PreMigrationBackup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreMigrationBackupTest extends TestCase
{
    private string $dir;

    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'premigrate-'.uniqid();
        mkdir($this->dir);
        $this->dbPath = $this->dir.DIRECTORY_SEPARATOR.'database.sqlite';
        touch($this->dbPath);

        config(['database.connections.premigrate' => [
            'driver' => 'sqlite',
            'database' => $this->dbPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        DB::connection('premigrate')->statement('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');
        DB::connection('premigrate')->statement('CREATE TABLE notes (body TEXT)');
        DB::connection('premigrate')->table('notes')->insert(['body' => 'keep me']);
    }

    protected function tearDown(): void
    {
        DB::purge('premigrate');
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function pending_migrations_copy_the_database_beside_itself_first(): void
    {
        Carbon::setTestNow('2026-10-14 19:43:03');

        $path = app(PreMigrationBackup::class)->run('premigrate');

        $expected = $this->dir.DIRECTORY_SEPARATOR.'database.pre-migrate-20261014-194303.sqlite';
        $this->assertSame($expected, $path);
        $this->assertFileExists($expected);

        $copy = new \PDO('sqlite:'.$expected);
        $this->assertSame('keep me', $copy->query('SELECT body FROM notes')->fetchColumn());
    }

    #[Test]
    public function nothing_is_copied_when_every_migration_has_already_run(): void
    {
        $names = array_map(
            fn (string $file) => basename($file, '.php'),
            glob(database_path('migrations/*.php')) ?: []
        );
        DB::connection('premigrate')->table('migrations')->insert(
            array_map(fn (string $name) => ['migration' => $name, 'batch' => 1], $names)
        );

        $this->assertNull(app(PreMigrationBackup::class)->run('premigrate'));
        $this->assertSame([], glob($this->dir.DIRECTORY_SEPARATOR.'database.pre-migrate-*') ?: []);
    }

    #[Test]
    public function an_in_memory_database_is_never_backed_up(): void
    {
        // The test suite's default connection is SQLite :memory: — no file to copy.
        $this->assertNull(app(PreMigrationBackup::class)->run());
    }
}
