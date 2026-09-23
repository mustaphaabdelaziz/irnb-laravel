<?php

namespace App\Support;

/**
 * Normalises stored public-disk file URLs (logos, photos, receipts) to a
 * host-relative `/media/<path>` form.
 *
 * Uploads used to be persisted as absolute URLs (e.g.
 * `http://localhost:8000/storage/branding/x.png`). Those break in two ways:
 *   - Web: the hardcoded host/port is wrong behind any other origin, and the
 *     `public/storage` symlink is often missing → 404.
 *   - Desktop: the NativePHP window runs on a dynamic 127.0.0.1 port that never
 *     matches the baked-in host, and there is no `/storage` route.
 *
 * The `GET /media/{path}` route serves straight from the public disk on BOTH
 * builds, and a host-relative path resolves against whatever origin is serving
 * the page. Normalising on read makes existing rows work without a data migration.
 */
class Media
{
    public static function path(?string $url): ?string
    {
        if (! $url) {
            return $url;
        }

        // Only rewrite URLs that point at the public disk (a `media/` or
        // `storage/` segment). Genuinely external URLs are left untouched.
        if (! preg_match('#(?:^|/)(?:media|storage)/(.+)$#i', $url, $m)) {
            return $url;
        }

        return '/media/'.ltrim($m[1], '/');
    }

    /**
     * Resolve a stored media URL (`/media/...`, `/storage/...`, or a legacy
     * absolute URL) to an absolute file on disk, for callers that need a real
     * filesystem path rather than a URL — mPDF images, chiefly, which cannot
     * fetch a `/media/...` route.
     *
     * Tries `storage/app/public` first, then falls back to the `public/storage`
     * symlink some deployments still serve from. Rejects `..` path segments
     * and drive-letter/absolute paths outright, and — belt and braces — also
     * requires the resolved file to `realpath()` inside the base directory it
     * came from, so a crafted URL can never read a file outside the public
     * disk.
     */
    public static function localFile(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $rel = preg_replace('#^https?://[^/]+#i', '', $url);
        $rel = preg_replace('#^/?(?:media|storage)/#i', '', $rel);
        $rel = ltrim(str_replace('\\', '/', $rel), '/');

        if ($rel === '' || preg_match('#^[A-Za-z]:#', $rel)) {
            return null;
        }

        $segments = explode('/', $rel);
        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            return null;
        }

        return self::containedFile(storage_path('app/public'), $rel)
            ?? self::containedFile(public_path('storage'), $rel);
    }

    /**
     * $rel resolved under $base, but only if the real, symlink-resolved path
     * still lives inside $base — the actual guard against traversal, since
     * segment-checking a URL string can be fooled by encoding tricks that
     * realpath() cannot.
     */
    private static function containedFile(string $base, string $rel): ?string
    {
        $full = $base.'/'.$rel;

        if (! is_file($full)) {
            return null;
        }

        $realBase = realpath($base);
        $realFull = realpath($full);

        if ($realBase === false || $realFull === false) {
            return null;
        }

        $realBase = rtrim(str_replace('\\', '/', $realBase), '/');
        $realFull = str_replace('\\', '/', $realFull);

        if ($realFull !== $realBase && ! str_starts_with($realFull, $realBase.'/')) {
            return null;
        }

        return $realFull;
    }
}
