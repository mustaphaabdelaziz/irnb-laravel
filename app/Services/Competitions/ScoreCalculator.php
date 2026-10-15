<?php

namespace App\Services\Competitions;

/** The final score from per-period rows: summed for goals/points, sets won for volleyball. */
final class ScoreCalculator
{
    /**
     * @param  list<?array{0: ?int, 1: ?int}>  $rows  one [us, them] per period (and extra time); empty rows are skipped
     * @return array{0: int, 1: int}|null null when no row is filled in
     */
    public static function derive(array $rows, string $scoring): ?array
    {
        $filled = array_values(array_filter($rows, fn ($row) => is_array($row) && isset($row[0], $row[1])));
        if ($filled === []) {
            return null;
        }
        if ($scoring === 'sets') {
            return [
                count(array_filter($filled, fn (array $r) => $r[0] > $r[1])),
                count(array_filter($filled, fn (array $r) => $r[1] > $r[0])),
            ];
        }

        return [array_sum(array_column($filled, 0)), array_sum(array_column($filled, 1))];
    }
}
