<?php

namespace App\Support;

use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * The club's clock. Dates — today's attendance, the season a payment lands
 * in, "overdue" — are the club's local dates, so the app runs in the timezone
 * chosen in Settings (Algiers when none, or an unknown one, is saved).
 *
 * Applied once per request at boot. Saved date-times are not converted: a
 * value written before the switch keeps the wall-clock time it was saved with.
 * A long-running process (queue worker, scheduler) keeps the timezone it
 * booted with: restart it after the setting changes.
 */
final class ClubTimezone
{
    public const DEFAULT = 'Africa/Algiers';

    /**
     * How dates are sent to the screens: local time WITH its offset. Laravel's
     * default is UTC, where a `date` (midnight here) is still the day before.
     */
    public const JSON_FORMAT = 'Y-m-d\TH:i:s.uP';

    /** The Settings timezone when it is a real one, else Algiers. Never creates the settings row. */
    public static function name(): string
    {
        try {
            // A failed read (no table yet: fresh install, before migrate) is not
            // memoized (ClubSettings), so the next read tries again.
            return self::valid(ClubSettings::get('timezone'));
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

    /** Point Laravel and PHP at the club's timezone, and send dates in it. */
    public static function apply(): void
    {
        $timezone = self::name();

        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);

        // A bare Carbon in a JSON payload (Inertia props, JSON responses).
        // Model attributes go through serializeDate() — SerializesClubDates.
        Date::serializeUsing(self::JSON_FORMAT);
    }
}
