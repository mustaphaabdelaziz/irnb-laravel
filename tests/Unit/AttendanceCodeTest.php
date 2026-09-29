<?php

namespace Tests\Unit;

use App\Enums\AttendanceStatus;
use App\Services\Attendance\AttendanceCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AttendanceCodeTest extends TestCase
{
    public static function valid(): array
    {
        return [
            'empty is present' => ['', AttendanceStatus::Present, null],
            'null is present' => [null, AttendanceStatus::Present, null],
            'P' => ['p', AttendanceStatus::Present, null],
            'late' => ['R15', AttendanceStatus::Late, 15],
            'late spaced' => [' r 5 ', AttendanceStatus::Late, 5],
            'left early' => ['D10', AttendanceStatus::LeftEarly, 10],
            'late at the cap' => ['R600', AttendanceStatus::Late, 600],
            'not training' => ['B', AttendanceStatus::NotTraining, null],
            'excused' => ['ae', AttendanceStatus::AbsentExcused, null],
            'unexcused' => ['AN', AttendanceStatus::AbsentUnexcused, null],
        ];
    }

    #[Test]
    #[DataProvider('valid')]
    public function it_parses_codes(?string $code, AttendanceStatus $status, ?int $minutes): void
    {
        $this->assertSame(['status' => $status, 'minutes' => $minutes], AttendanceCode::parse($code));
    }

    #[Test]
    public function it_rejects_unknown_codes_and_late_without_minutes(): void
    {
        foreach (['X', 'R', 'R0', 'D', 'R1000', 'R601', 'A'] as $code) {
            $this->assertNull(AttendanceCode::parse($code), $code);
        }
    }

    #[Test]
    public function format_round_trips(): void
    {
        foreach (['P', 'R15', 'D10', 'B', 'AE', 'AN'] as $code) {
            $parsed = AttendanceCode::parse($code);
            $this->assertSame($code, AttendanceCode::format($parsed['status'], $parsed['minutes']));
        }
    }
}
