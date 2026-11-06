<?php

namespace Tests\Unit\Providers;

use App\Providers\NativeAppServiceProvider;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Renaming the desktop app to ASIREM moved its Electron data folder from
 * %APPDATA%\sport-club to %APPDATA%\asirem. The first launch under the new
 * name must bring the club's records along instead of opening on the seed.
 */
class NativeLegacyDataTest extends TestCase
{
    private string $appData;

    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStorage = $this->app->storagePath();
        $this->appData = sys_get_temp_dir().DIRECTORY_SEPARATOR.'native-appdata-'.uniqid();

        // What NativePHP has made by the time the provider boots: an empty
        // database in the new folder, with storage redirected next to it.
        File::ensureDirectoryExists($this->path('asirem/database'));
        File::ensureDirectoryExists($this->path('asirem/storage/app'));
        file_put_contents($this->path('asirem/database/database.sqlite'), 'EMPTY');
        $this->app->useStoragePath($this->path('asirem/storage'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->appData);
        $this->app->useStoragePath($this->originalStorage);

        parent::tearDown();
    }

    private function path(string $relative): string
    {
        return $this->appData.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function adopt(): bool
    {
        $provider = new class extends NativeAppServiceProvider
        {
            public function __construct() {}

            public function run(string $dbPath): bool
            {
                return $this->adoptLegacyData($dbPath);
            }
        };

        return $provider->run($this->path('asirem/database/database.sqlite'));
    }

    #[Test]
    public function the_old_database_and_files_move_into_the_new_folder(): void
    {
        File::ensureDirectoryExists($this->path('sport-club/database'));
        File::ensureDirectoryExists($this->path('sport-club/storage/app/public/players'));
        File::ensureDirectoryExists($this->path('sport-club/storage/app/private/receipts'));
        file_put_contents($this->path('sport-club/database/database.sqlite'), 'CLUB DATA');
        file_put_contents($this->path('sport-club/database/database.sqlite-wal'), 'NEWEST WRITES');
        file_put_contents($this->path('sport-club/storage/app/public/players/1.jpg'), 'PHOTO');
        file_put_contents($this->path('sport-club/storage/app/private/receipts/r.pdf'), 'RECEIPT');

        $this->assertTrue($this->adopt());

        $this->assertSame('CLUB DATA', file_get_contents($this->path('asirem/database/database.sqlite')));
        $this->assertSame('NEWEST WRITES', file_get_contents($this->path('asirem/database/database.sqlite-wal')));
        $this->assertSame('PHOTO', file_get_contents($this->path('asirem/storage/app/public/players/1.jpg')));
        $this->assertSame('RECEIPT', file_get_contents($this->path('asirem/storage/app/private/receipts/r.pdf')));

        // The old folder stays as it was: it is the fallback copy.
        $this->assertSame('CLUB DATA', file_get_contents($this->path('sport-club/database/database.sqlite')));
    }

    #[Test]
    public function a_pc_that_never_ran_the_old_app_keeps_the_fresh_database(): void
    {
        $this->assertFalse($this->adopt());

        $this->assertSame('EMPTY', file_get_contents($this->path('asirem/database/database.sqlite')));
    }

    #[Test]
    public function a_stale_wal_in_the_new_folder_does_not_survive_the_move(): void
    {
        // A -wal left by the empty database would be replayed over the club's.
        File::ensureDirectoryExists($this->path('sport-club/database'));
        file_put_contents($this->path('sport-club/database/database.sqlite'), 'CLUB DATA');
        file_put_contents($this->path('asirem/database/database.sqlite-wal'), 'STALE');

        $this->assertTrue($this->adopt());

        $this->assertFileDoesNotExist($this->path('asirem/database/database.sqlite-wal'));
    }
}
