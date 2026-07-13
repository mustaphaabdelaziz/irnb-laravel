<?php

namespace Tests\Support;

/**
 * Stand-in for Native\Desktop\Settings (electron-store). The real one posts to
 * the Electron client over HTTP, which does not exist under test.
 * Swap it in with: Settings::swap(new FakeNativeSettings);
 */
class FakeNativeSettings
{
    public array $store = [];

    public function set(string $key, $value): void
    {
        $this->store[$key] = $value;
    }

    public function get(string $key, $default = null): mixed
    {
        $value = $this->store[$key] ?? null;

        if ($value === null) {
            return $default instanceof \Closure ? $default() : $default;
        }

        return $value;
    }

    public function forget(string $key): void
    {
        unset($this->store[$key]);
    }

    public function clear(): void
    {
        $this->store = [];
    }
}
