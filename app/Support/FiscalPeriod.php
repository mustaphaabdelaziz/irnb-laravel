<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The club's fiscal year is its season. A season is keyed by the year it
 * ENDS in (2026/27 → 2027) — the same key annual subscriptions use — or by
 * the plain year when the club's season is the calendar year. Every place
 * that books money into a year, or reads a year's totals, goes through here.
 */
final class FiscalPeriod
{
    public static function isCalendar(): bool
    {
        return Season::startMonth() === 1;
    }

    public static function keyForDate(DateTimeInterface|string $date): int
    {
        $season = Season::forDate($date);

        return self::isCalendar() ? $season->startYear : $season->startYear + 1;
    }

    public static function currentKey(): int
    {
        return self::keyForDate(CarbonImmutable::now());
    }

    public static function season(int $key): Season
    {
        return Season::forStartYear(self::isCalendar() ? $key : $key - 1);
    }

    public static function start(int $key): CarbonImmutable
    {
        return self::season($key)->start();
    }

    public static function end(int $key): CarbonImmutable
    {
        return self::season($key)->end();
    }

    /** "2026/2027", or "2026" when the season is the calendar year. */
    public static function label(int $key): string
    {
        return self::isCalendar() ? (string) $key : ($key - 1).'/'.$key;
    }

    /** @return list<int> month numbers in season order, first month first */
    public static function months(): array
    {
        $first = Season::startMonth();

        return array_map(fn (int $i) => (($first - 1 + $i) % 12) + 1, range(0, 11));
    }

    /** "-08-31": appended to a key year in SQL it gives that season's last day. */
    public static function endSuffix(): string
    {
        // 2001 is not a leap year, so a March season ends on the 28th.
        return self::end(2001)->format('-m-d');
    }
}
