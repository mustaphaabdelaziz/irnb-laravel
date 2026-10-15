<?php

namespace App\Support;

use App\Models\WebsiteConfig;

/**
 * The Settings JSON (WebsiteConfig `settings`) for code that only reads it:
 * the season start month, the timezone, the currency. One query per request,
 * shared by all of them, and it never creates the settings row. Saving the
 * WebsiteConfig flushes the memo (WebsiteConfig::booted).
 */
final class ClubSettings
{
    /** @return array<string, mixed> */
    public static function all(): array
    {
        return once(fn (): array => WebsiteConfig::query()->first()?->settings ?? []);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }
}
