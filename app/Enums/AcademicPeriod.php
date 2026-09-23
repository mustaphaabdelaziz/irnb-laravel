<?php

namespace App\Enums;

/**
 * A grading period inside a school year. Universities grade per semester,
 * schools per trimester, and either may also publish a yearly average.
 */
enum AcademicPeriod: string
{
    case T1 = 'T1';
    case S1 = 'S1';
    case T2 = 'T2';
    case S2 = 'S2';
    case T3 = 'T3';
    case Annual = 'ANNUAL';

    /** Position inside one school year; the yearly average always comes last. */
    public function rank(): int
    {
        return match ($this) {
            self::T1 => 1,
            self::S1 => 2,
            self::T2 => 3,
            self::S2 => 4,
            self::T3 => 5,
            self::Annual => 6,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** The same rank as a SQL CASE over $column, for ORDER BY in raw queries. */
    public static function rankSql(string $column): string
    {
        $whens = collect(self::cases())
            ->map(fn (self $p) => "WHEN '{$p->value}' THEN {$p->rank()}")
            ->implode(' ');

        return "CASE {$column} {$whens} ELSE 0 END";
    }
}
