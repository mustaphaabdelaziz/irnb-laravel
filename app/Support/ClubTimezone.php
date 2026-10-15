<?php

namespace App\Support;

use App\Models\WebsiteConfig;
use Throwable;

/**
 * The club's clock. Dates — today's attendance, the season a payment lands
 * in, "overdue" — are the club's local dates, so the app runs in the timezone
 * chosen in Settings (Algiers when none, or an unknown one, is saved).
 *
 * Applied once per request at boot. Saved date-times are not converted: a
 * value written before the switch keeps the wall-clock time it was saved with.
 */
final class ClubTimezone
{
    public const DEFAULT = 'Africa/Algiers';

    /** The Settings timezone when it is a real one, else Algiers. Never creates the settings row. */
    public static function name(): string
    {
        try {
            // A failed read (no table yet: fresh install, before migrate) is not
            // memoized — once() keeps nothing when its callback throws.
            return once(fn (): string => self::valid((WebsiteConfig::query()->first()?->settings ?? [])['timezone'] ?? null));
        } catch (Throwable) {
            return self::DEFAULT;
        }
    }

    public static function valid(mixed $timezone): string
    {
        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : self::DEFAULT;
    }

    /** Point Laravel and PHP at the club's timezone. */
    public static function apply(): void
    {
        $timezone = self::name();

        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
    }
}
