<?php

namespace App\Enums;

/**
 * A grading period inside a school year. Schools grade per trimester.
 */
enum AcademicPeriod: string
{
    case T1 = 'T1';
    case T2 = 'T2';
    case T3 = 'T3';

    /** Position inside one school year. */
    public function rank(): int
    {
        return match ($this) {
            self::T1 => 1,
            self::T2 => 2,
            self::T3 => 3,
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
