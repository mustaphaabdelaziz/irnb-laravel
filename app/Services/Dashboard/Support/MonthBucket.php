<?php

namespace App\Services\Dashboard\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Month bucketing that works on both deployments.
 *
 * The web install and the NativePHP desktop build can sit on different
 * drivers, so the grouping expression is resolved per driver rather than
 * hardcoded. The alternative — loading every row and bucketing in PHP, as the
 * old dashboard did for its yearly revenue chart — hydrates the whole table to
 * produce twelve numbers.
 */
class MonthBucket
{
    /** A SQL expression grouping $column into 'YYYY-MM'. */
    public static function expression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    /** A SQL expression for the month of $column, as a number from 1 to 12. */
    public static function monthNumber(string $column): string
    {
        return self::monthNumberFor(DB::connection()->getDriverName(), $column);
    }

    public static function monthNumberFor(string $driver, string $column): string
    {
        return match ($driver) {
            'sqlite' => "CAST(strftime('%m', {$column}) AS INTEGER)",
            'pgsql' => "CAST(EXTRACT(MONTH FROM {$column}) AS INTEGER)",
            default => "MONTH({$column})",
        };
    }

    /**
     * The twelve month keys ending with $end's month, oldest first.
     *
     * @return list<string>
     */
    public static function lastTwelve(CarbonImmutable $end): array
    {
        $start = $end->startOfMonth()->subMonths(11);

        return array_map(
            fn (int $offset): string => $start->addMonths($offset)->format('Y-m'),
            range(0, 11),
        );
    }

    /**
     * Fill a keyed aggregate into a dense series matching $labels.
     *
     * @param  array<string, float|int>  $keyed
     * @param  list<string>  $labels
     * @return list<float>
     */
    public static function series(array $keyed, array $labels): array
    {
        return array_map(
            fn (string $label): float => (float) ($keyed[$label] ?? 0),
            $labels,
        );
    }
}
