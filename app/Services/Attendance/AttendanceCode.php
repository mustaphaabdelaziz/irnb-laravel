<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;

/**
 * The short codes written on the paper sheet and typed into the month grid:
 * P, R<min>, D<min>, B, AE, AN. An empty cell means present.
 */
final class AttendanceCode
{
    private const SIMPLE = [
        'P' => AttendanceStatus::Present,
        'B' => AttendanceStatus::NotTraining,
        'AE' => AttendanceStatus::AbsentExcused,
        'AN' => AttendanceStatus::AbsentUnexcused,
    ];

    /** @return array{status: AttendanceStatus, minutes: ?int}|null null when the code is not valid */
    public static function parse(?string $code): ?array
    {
        $code = strtoupper(str_replace(' ', '', (string) $code));

        if ($code === '') {
            return ['status' => AttendanceStatus::Present, 'minutes' => null];
        }

        if (isset(self::SIMPLE[$code])) {
            return ['status' => self::SIMPLE[$code], 'minutes' => null];
        }

        if (preg_match('/^([RD])(\d{1,3})$/', $code, $m) && (int) $m[2] > 0 && (int) $m[2] <= 600) {
            return [
                'status' => $m[1] === 'R' ? AttendanceStatus::Late : AttendanceStatus::LeftEarly,
                'minutes' => (int) $m[2],
            ];
        }

        return null;
    }

    public static function format(AttendanceStatus $status, ?int $minutes): string
    {
        return match ($status) {
            AttendanceStatus::Late => 'R'.$minutes,
            AttendanceStatus::LeftEarly => 'D'.$minutes,
            default => array_search($status, self::SIMPLE, true),
        };
    }
}
