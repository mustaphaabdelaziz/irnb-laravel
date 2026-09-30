<?php

namespace App\Services\Activity;

use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The time window an activity report covers: this calendar month (default),
 * the club's current season, or a custom inclusive day range. Anything
 * unparseable falls back to the month rather than erroring, so a bad link
 * still shows a sensible report.
 */
final class ActivityPeriod
{
    public const MONTH = 'month';

    public const SEASON = 'season';

    public const CUSTOM = 'custom';

    private function __construct(
        public readonly string $key,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        private readonly string $label,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return match ($request->query('period')) {
            self::SEASON => self::season(),
            self::CUSTOM => self::custom($request->query('from'), $request->query('to')) ?? self::month(),
            default => self::month(),
        };
    }

    /** The current season unless the request picks a period (the profile card, the at-risk list, the injuries). */
    public static function fromRequestOrSeason(Request $request): self
    {
        return $request->query('period') === null ? self::season() : self::fromRequest($request);
    }

    public static function month(): self
    {
        $now = CarbonImmutable::now();

        return new self(self::MONTH, $now->startOfMonth(), $now->endOfMonth(), $now->format('Y-m'));
    }

    public static function season(): self
    {
        $season = Season::current();

        return new self(self::SEASON, $season->start(), $season->end(), $season->label());
    }

    /** Null when either day is missing or malformed, or the range is reversed. */
    public static function custom(mixed $from, mixed $to): ?self
    {
        $start = self::day($from);
        $end = self::day($to);

        if ($start === null || $end === null || $start->greaterThan($end)) {
            return null;
        }

        return new self(
            self::CUSTOM,
            $start->startOfDay(),
            $end->endOfDay(),
            $start->toDateString().' – '.$end->toDateString(),
        );
    }

    /** @return array{period: string, from: string, to: string, label: string} */
    public function toArray(): array
    {
        return [
            'period' => $this->key,
            'from' => $this->start->toDateString(),
            'to' => $this->end->toDateString(),
            'label' => $this->label,
        ];
    }

    /** A strict Y-m-d day (no overflow such as 2026-02-30), else null. */
    private static function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $day instanceof CarbonImmutable && $day->format('Y-m-d') === $value ? $day : null;
    }
}
