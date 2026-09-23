<?php

namespace App\Support;

use App\Models\WebsiteConfig;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The club's season. A sports season rarely matches the calendar year — this
 * club's starts in September — so "this season" is defined once, from a
 * setting, and every feature that needs it (board tables, documents that are
 * renewed each season) reads it here instead of inventing its own rule.
 */
final class Season
{
    private const DEFAULT_START_MONTH = 9;

    private function __construct(public readonly int $startYear, private readonly int $startMonth) {}

    /** The month a season starts in, 1-12. Falls back to September when unset or out of range. */
    public static function startMonth(): int
    {
        $month = (int) (WebsiteConfig::singleton()->settings['seasonStartMonth'] ?? self::DEFAULT_START_MONTH);

        return ($month >= 1 && $month <= 12) ? $month : self::DEFAULT_START_MONTH;
    }

    public static function current(): self
    {
        return self::forDate(CarbonImmutable::now());
    }

    public static function forDate(DateTimeInterface|string $date): self
    {
        $moment = CarbonImmutable::parse($date);
        $startMonth = self::startMonth();

        // Before the start month the date still belongs to the season that opened last year.
        $startYear = $moment->month >= $startMonth ? $moment->year : $moment->year - 1;

        return new self($startYear, $startMonth);
    }

    public static function forStartYear(int $startYear): self
    {
        return new self($startYear, self::startMonth());
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::create($this->startYear, $this->startMonth, 1)->startOfDay();
    }

    public function end(): CarbonImmutable
    {
        return $this->start()->addYear()->subDay()->endOfDay();
    }

    public function contains(DateTimeInterface|string $date): bool
    {
        return CarbonImmutable::parse($date)->between($this->start(), $this->end());
    }

    /** "2026/27" — or just "2026" when the season is a calendar year. */
    public function label(): string
    {
        if ($this->startMonth === 1) {
            return (string) $this->startYear;
        }

        return $this->startYear.'/'.str_pad((string) (($this->startYear + 1) % 100), 2, '0', STR_PAD_LEFT);
    }
}
