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
}
