<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The desktop app talks to its Electron side over HTTP, and NativePHP makes
 * that call while registering its service provider — before the app has
 * booted. If the call fails, every request answers 500.
 *
 * Electron passes the address as "http://localhost:4000/api/". Windows ships
 * no `localhost` line in its hosts file, so resolving that name goes through
 * the DNS client, which stalls while the machine is offline: the club then
 * cannot save anything until it is back online. A literal loopback address
 * needs no name resolution, so config/nativephp-internal.php rewrites the
 * host — and these tests keep it rewritten.
 */
class DesktopApiHostTest extends TestCase
{
    /** @return array<string, mixed> */
    private function overrides(?string $apiUrl): array
    {
        $previousEnv = $_ENV['NATIVEPHP_API_URL'] ?? null;
        $previousServer = $_SERVER['NATIVEPHP_API_URL'] ?? null;

        if ($apiUrl === null) {
            unset($_ENV['NATIVEPHP_API_URL'], $_SERVER['NATIVEPHP_API_URL']);
        } else {
            $_ENV['NATIVEPHP_API_URL'] = $apiUrl;
            $_SERVER['NATIVEPHP_API_URL'] = $apiUrl;
        }

        try {
            return require config_path('nativephp-internal.php');
        } finally {
            unset($_ENV['NATIVEPHP_API_URL'], $_SERVER['NATIVEPHP_API_URL']);

            if ($previousEnv !== null) {
                $_ENV['NATIVEPHP_API_URL'] = $previousEnv;
            }
            if ($previousServer !== null) {
                $_SERVER['NATIVEPHP_API_URL'] = $previousServer;
            }
        }
    }

    #[Test]
    public function the_effective_api_url_never_needs_name_resolution(): void
    {
        $this->assertStringNotContainsStringIgnoringCase(
            'localhost',
            (string) config('nativephp-internal.api_url'),
            'The desktop API address must be a literal IP: resolving a name stalls while offline.',
        );
    }

    #[Test]
    public function a_localhost_address_from_electron_is_rewritten_to_loopback(): void
    {
        $this->assertSame(
            'http://127.0.0.1:4000/api/',
            $this->overrides('http://localhost:4000/api/')['api_url'],
        );
    }

    #[Test]
    public function the_port_and_path_electron_chose_are_kept(): void
    {
        $this->assertSame(
            'http://127.0.0.1:51789/api/',
            $this->overrides('http://localhost:51789/api/')['api_url'],
        );
    }

    #[Test]
    public function an_address_that_is_already_an_ip_is_left_alone(): void
    {
        $this->assertSame(
            'http://127.0.0.1:4000/api/',
            $this->overrides('http://127.0.0.1:4000/api/')['api_url'],
        );
    }

    #[Test]
    public function a_host_that_merely_starts_with_localhost_is_left_alone(): void
    {
        // localhost.example.test is a different machine, not the loopback.
        $this->assertSame(
            'http://localhost.example.test:4000/api/',
            $this->overrides('http://localhost.example.test:4000/api/')['api_url'],
        );
    }

    #[Test]
    public function the_default_is_loopback_when_electron_passes_nothing(): void
    {
        $this->assertSame('http://127.0.0.1:4000/api/', $this->overrides(null)['api_url']);
    }
}
