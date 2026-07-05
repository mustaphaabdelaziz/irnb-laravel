<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Guard against a cached config silently overriding phpunit's :memory:
     * database. If bootstrap/cache/config.php exists, tests would run against
     * the real DB_DATABASE file and RefreshDatabase would WIPE live data.
     * Abort before parent::setUp() (which triggers RefreshDatabase migrations).
     */
    protected function setUp(): void
    {
        $cachedConfig = dirname(__DIR__).'/bootstrap/cache/config.php';
        if (file_exists($cachedConfig)) {
            throw new \RuntimeException(
                'Cached config detected during tests. Run `php artisan config:clear` first '
                .'(or use `composer test`, which clears it) — a cached config overrides '
                .'phpunit\'s :memory: database and RefreshDatabase would wipe real data.'
            );
        }

        parent::setUp();
    }
}
