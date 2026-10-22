<?php

namespace Tests\Feature;

use App\Console\Commands\CacheViewsOnce;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The desktop shell runs `artisan optimize` on every launch; recompiling every
 * Blade file each time cost seconds of startup on an antivirus-scanned disk.
 */
class CacheViewsOnceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'views-'.uniqid();
        mkdir($this->dir);
        config(['view.compiled' => $this->dir]);
        foreach (['blade.compiler', 'view.engine.resolver', 'view'] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function sentinel(): string
    {
        $path = $this->dir.DIRECTORY_SEPARATOR.'sentinel.php';
        file_put_contents($path, '<?php // left by the test');

        return $path;
    }

    #[Test]
    public function it_replaces_the_framework_view_cache_command(): void
    {
        $this->assertInstanceOf(CacheViewsOnce::class, Artisan::all()['view:cache']);
    }

    #[Test]
    public function the_desktop_app_compiles_once_then_keeps_the_compiled_views(): void
    {
        config(['nativephp-internal.running' => true]);
        $first = $this->sentinel();

        $this->artisan('view:cache')->assertSuccessful();

        $this->assertFileDoesNotExist($first, 'the first run clears and recompiles');
        $this->assertFileExists($this->dir.DIRECTORY_SEPARATOR.'.views-signature');
        $this->assertNotEmpty(glob($this->dir.DIRECTORY_SEPARATOR.'*.php'));

        $second = $this->sentinel();

        $this->artisan('view:cache')
            ->expectsOutputToContain('already cached')
            ->assertSuccessful();

        $this->assertFileExists($second, 'an unchanged set of views is not recompiled');
    }

    #[Test]
    public function the_desktop_app_recompiles_when_the_views_changed(): void
    {
        config(['nativephp-internal.running' => true]);
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'.views-signature', 'views of an older version');
        $sentinel = $this->sentinel();

        $this->artisan('view:cache')->assertSuccessful();

        $this->assertFileDoesNotExist($sentinel);
        $this->assertNotSame('views of an older version', file_get_contents($this->dir.DIRECTORY_SEPARATOR.'.views-signature'));
    }

    #[Test]
    public function the_web_always_recompiles(): void
    {
        config(['nativephp-internal.running' => false]);
        $this->artisan('view:cache')->assertSuccessful();
        $sentinel = $this->sentinel();

        $this->artisan('view:cache')->assertSuccessful();

        $this->assertFileDoesNotExist($sentinel);
        $this->assertFileDoesNotExist($this->dir.DIRECTORY_SEPARATOR.'.views-signature');
    }
}
