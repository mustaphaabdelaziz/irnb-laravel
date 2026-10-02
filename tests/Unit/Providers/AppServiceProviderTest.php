<?php

namespace Tests\Unit\Providers;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The desktop app is served by `php -S`, one request at a time: prefetching
 * every page chunk after load queued the page's own requests (the attendance
 * calendar) behind ~150 asset downloads. The web keeps prefetching.
 */
class AppServiceProviderTest extends TestCase
{
    private function bootWith(bool $desktop): ?string
    {
        config(['nativephp-internal.running' => $desktop]);
        $vite = new Vite;
        $this->app->instance(Vite::class, $vite);
        Facade::clearResolvedInstances();

        (new AppServiceProvider($this->app))->boot();

        return (fn () => $this->prefetchStrategy)->call($vite);
    }

    #[Test]
    public function the_web_prefetches_page_chunks(): void
    {
        $this->assertNotNull($this->bootWith(desktop: false));
    }

    #[Test]
    public function the_desktop_app_does_not_prefetch(): void
    {
        $this->assertNull($this->bootWith(desktop: true));
    }
}
