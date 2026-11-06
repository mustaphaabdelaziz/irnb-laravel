<?php

namespace App\Support;

use App\Models\WebsiteConfig;

/**
 * The club's name, and what to show before a club has set one.
 *
 * A club sets its name in Settings -> General. Until it does, every surface
 * shows the product's own name, ASIREM — never another club's name, because
 * the app is sold to more than one club. The defaults live here and only here;
 * resources/js/Composables/useClubIdentity.js mirrors the short name for the
 * rare page that renders without shared props.
 */
final class ClubIdentity
{
    public const DEFAULT_SHORT_NAME = ProductBrand::NAME;

    /** @var array{ar: string, fr: string, en: string} */
    public const DEFAULT_NAME = [
        'ar' => 'أسيرم',
        'fr' => ProductBrand::NAME,
        'en' => ProductBrand::NAME,
    ];

    /** @var array{ar: string, fr: string, en: string} */
    public const DEFAULT_TAGLINE = [
        'ar' => 'منصة إدارة الرياضة',
        'fr' => 'Plateforme de gestion sportive',
        'en' => 'Sports Management Platform',
    ];

    /** The saved short name, or the neutral default when none is set. */
    public static function shortName(?WebsiteConfig $config): string
    {
        $saved = trim((string) ($config?->club_short_name ?? ''));

        return $saved !== '' ? $saved : self::DEFAULT_SHORT_NAME;
    }

    /**
     * The saved full name per locale, or the neutral default.
     *
     * A club that filled in only one language keeps its own name: the client
     * falls back across the saved locales before reaching the default, because
     * the club's real name in Arabic beats a generic placeholder in French.
     *
     * @return array<string, string>
     */
    public static function name(?WebsiteConfig $config): array
    {
        $saved = array_filter(
            (array) ($config?->club_name ?? []),
            fn ($value): bool => is_string($value) && trim($value) !== '',
        );

        return $saved !== [] ? $saved : self::DEFAULT_NAME;
    }
}
