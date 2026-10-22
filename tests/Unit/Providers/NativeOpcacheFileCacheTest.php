<?php

namespace Tests\Unit\Providers;

use App\Providers\NativeAppServiceProvider;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Desktop PHP processes share compiled scripts through an OPcache file cache
 * keyed on the build id, so an update can never run the previous build's code.
 */
class NativeOpcacheFileCacheTest extends TestCase
{
    private string $storage;

    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStorage = $this->app->storagePath();
        $this->storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'native-storage-'.uniqid();
        mkdir($this->storage);
        $this->app->useStoragePath($this->storage);
        // The packaged app runs with debug off; native:serve (debug on) does not cache.
        config(['app.debug' => false]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        $this->app->useStoragePath($this->originalStorage);

        parent::tearDown();
    }

    private function ini(?string $buildId): array
    {
        $provider = new class($buildId) extends NativeAppServiceProvider
        {
            public function __construct(private ?string $id) {}

            protected function buildId(): ?string
            {
                return $this->id;
            }
        };

        return $provider->phpIni();
    }

    private function cacheRoot(): string
    {
        return $this->storage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'opcache';
    }

    #[Test]
    public function a_stamped_build_gets_its_own_file_cache_folder(): void
    {
        $ini = $this->ini('20261022-abcd1234');

        $expected = $this->cacheRoot().DIRECTORY_SEPARATOR.'20261022-abcd1234';
        $this->assertSame($expected, $ini['opcache.file_cache']);
        $this->assertDirectoryExists($expected);
        $this->assertSame('0', $ini['opcache.validate_timestamps']);
        $this->assertSame('10M', $ini['upload_max_filesize']);
    }

    #[Test]
    public function the_cached_config_is_never_served_from_the_file_cache(): void
    {
        // optimize rewrites it each launch (new secret): a cached copy from the
        // previous launch made the shell reject the app and the window never opened.
        $ini = $this->ini('20261022-abcd1234');

        $this->assertFileExists($ini['opcache.blacklist_filename']);
        $this->assertSame(
            [str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->app->getCachedConfigPath())],
            file($ini['opcache.blacklist_filename'], FILE_IGNORE_NEW_LINES),
        );
        $this->assertArrayNotHasKey('opcache.blacklist_filename', $this->ini(null));
    }

    #[Test]
    public function a_new_build_drops_the_previous_builds_cache(): void
    {
        $this->ini('20261001-old00000');
        file_put_contents($this->cacheRoot().DIRECTORY_SEPARATOR.'20261001-old00000'.DIRECTORY_SEPARATOR.'x.bin', 'stale');

        $this->ini('20261022-new11111');

        $this->assertDirectoryDoesNotExist($this->cacheRoot().DIRECTORY_SEPARATOR.'20261001-old00000');
        $this->assertDirectoryExists($this->cacheRoot().DIRECTORY_SEPARATOR.'20261022-new11111');
    }

    #[Test]
    public function the_same_build_keeps_its_cache(): void
    {
        $this->ini('20261022-same0000');
        $kept = $this->cacheRoot().DIRECTORY_SEPARATOR.'20261022-same0000'.DIRECTORY_SEPARATOR.'x.bin';
        file_put_contents($kept, 'compiled');

        $this->ini('20261022-same0000');

        $this->assertFileExists($kept);
    }

    #[Test]
    public function without_a_build_id_there_is_no_file_cache(): void
    {
        $this->assertArrayNotHasKey('opcache.file_cache', $this->ini(null));
        $this->assertArrayNotHasKey('opcache.file_cache', $this->ini('../escape'));
        $this->assertDirectoryDoesNotExist($this->cacheRoot());
    }

    #[Test]
    public function native_serve_while_developing_has_no_file_cache(): void
    {
        config(['app.debug' => true]);

        $this->assertArrayNotHasKey('opcache.file_cache', $this->ini('20261022-abcd1234'));
    }
}
