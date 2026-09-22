<?php

namespace App\Support;

/**
 * Server-side access to the UI's own label catalogs (resources/js/i18n/*.json).
 *
 * Text the server renders for a screen — generated transaction titles now,
 * export headers and values later — must read exactly like the screen around
 * it, so it comes from the same catalogs instead of a second copy in
 * lang/*.json. The desktop build ships resources/js/i18n, so this works there.
 */
final class UiLang
{
    private const LOCALES = ['ar', 'fr', 'en'];

    /** @var array<string, array<string, string>> */
    private static array $catalogs = [];

    /** The label for $key in $locale (default: the app locale), else English, else $default, else the key. */
    public static function get(string $key, ?string $default = null, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return self::catalog($locale)[$key]
            ?? self::catalog('en')[$key]
            ?? $default
            ?? $key;
    }

    /** @return array<string, string> */
    private static function catalog(string $locale): array
    {
        if (! in_array($locale, self::LOCALES, true)) {
            $locale = 'en';
        }

        if (isset(self::$catalogs[$locale])) {
            return self::$catalogs[$locale];
        }

        $path = resource_path("js/i18n/{$locale}.json");

        return self::$catalogs[$locale] = is_file($path)
            ? (json_decode((string) file_get_contents($path), true) ?: [])
            : [];
    }
}
