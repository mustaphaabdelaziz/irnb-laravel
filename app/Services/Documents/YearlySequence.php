<?php

namespace App\Services\Documents;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Per-year document numbers (payment orders, purchase orders, the mail
 * register): the year's highest number plus one. Gaps stay — cancelled
 * documents keep their number. Each numbered table carries a unique index on
 * its sequence columns (year, number — on mail: direction, year, number), so
 * two people saving at the same instant cannot share a number: the second
 * insert fails and retrying() runs it again.
 */
final class YearlySequence
{
    /**
     * The next number for $year among the rows matching $where (e.g. one
     * mail direction). Call inside the DB transaction that inserts it.
     *
     * @param  array<string, mixed>  $where  column => value
     */
    public static function next(string $table, int $year, array $where = []): int
    {
        return (int) DB::table($table)
            ->where('year', $year)
            ->when($where !== [], fn ($query) => $query->where($where))
            ->max('number') + 1;
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
