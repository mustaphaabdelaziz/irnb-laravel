<?php

namespace App\Services\Documents;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Per-year document numbers (payment orders now; purchase orders and the
 * mail register later): the year's highest number plus one. Gaps stay —
 * cancelled documents keep their number. Each numbered table carries a unique
 * index on (year, number), so two people saving at the same instant cannot
 * share a number: the second insert fails and retrying() runs it again.
 */
final class YearlySequence
{
    /** The next number for $year. Call inside the DB transaction that inserts it. */
    public static function next(string $table, int $year): int
    {
        return (int) DB::table($table)->where('year', $year)->max('number') + 1;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $attempt
     * @return T
     */
    public static function retrying(callable $attempt): mixed
    {
        try {
            return $attempt();
        } catch (UniqueConstraintViolationException) {
            return $attempt();
        }
    }
}
