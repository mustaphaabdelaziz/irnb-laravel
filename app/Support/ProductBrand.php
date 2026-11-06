<?php

namespace App\Support;

/**
 * The product's own name — ASIREM — as opposed to the club using it.
 *
 * Every club keeps its own name and logo (see ClubIdentity); ASIREM shows
 * around them: the desktop app, the login page, the tab title, the sidebar
 * footer, and in place of a club logo or name that was never set.
 * resources/js/lib/productBrand.js mirrors the name and image paths.
 */
final class ProductBrand
{
    public const NAME = 'ASIREM';

    public const FAVICON = '/brand/asirem-favicon.png';

    /**
     * APP_NAME values the desktop app shipped under before ASIREM.
     *
     * Backups made by those builds carry the old name in their manifest, and
     * the packaged runtime does not always see NATIVEPHP_APP_ID, so the name
     * can be the only thing a restore has to match on.
     */
    public const LEGACY_APP_NAMES = ['SPORT_CLUB', 'SportClub'];

    /**
     * Electron data folders (%APPDATA%\<name>) of those builds. The folder is
     * the slug of APP_NAME, so renaming the app moves it; the first launch of
     * ASIREM copies the old one across.
     */
    public const LEGACY_DATA_DIRS = ['sport-club'];
}
