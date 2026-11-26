<?php

namespace App\Services\Attendance;

/**
 * The Present · Absent · Rate totals printed on the attendance sheets, from
 * the marks of the cells printed. Not the points-based score of the
 * statistics: present = trained (even partly), absent = excused or not,
 * «came, did not train» neither; a not_counted code is not expected;
 * an unmarked cell is not passed in, so it counts nowhere.
 */
final class SheetTotals
{
    public const PRESENT = ['present', 'late', 'left_early'];

    public const ABSENT = ['absent_excused', 'absent_unexcused'];

    public function __construct(private readonly AttendanceStatusCatalog $catalog) {}

    /**
     * @param  iterable<string>  $statuses  status keys of one player's marked cells
     * @return array{present: int, absent: int, expected: int, rate: ?float}
     */
    public function of(iterable $statuses): array
    {
        $present = $absent = $expected = 0;
        foreach ($statuses as $status) {
            $behaviour = $this->catalog->behaviour((string) $status);
            if ($behaviour === AttendanceStatusCatalog::NOT_COUNTED) {
                continue;
            }
            $expected++;
            if (in_array($behaviour, self::PRESENT, true)) {
                $present++;
            } elseif (in_array($behaviour, self::ABSENT, true)) {
                $absent++;
            }
        }

        return self::row($present, $absent, $expected);
    }

    /**
     * @param  list<array{present: int, absent: int, expected: int}>  $rows
     * @return array{present: int, absent: int, expected: int, rate: ?float}
     */
    public static function sum(array $rows): array
    {
        return self::row(
            array_sum(array_column($rows, 'present')),
            array_sum(array_column($rows, 'absent')),
            array_sum(array_column($rows, 'expected')),
        );
    }

    /** "90.0%", or "—" when nothing was expected. */
    public static function rateLabel(?float $rate): string
    {
        return $rate === null ? '—' : number_format($rate, 1).'%';
    }

    /** @return array{present: int, absent: int, expected: int, rate: ?float} */
    private static function row(int $present, int $absent, int $expected): array
    {
        return [
            'present' => $present,
            'absent' => $absent,
            'expected' => $expected,
            'rate' => $expected > 0 ? round($present / $expected * 100, 1) : null,
        ];
    }
}
