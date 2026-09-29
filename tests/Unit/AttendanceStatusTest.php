<?php

namespace Tests\Unit;

use App\Enums\AttendanceStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AttendanceStatusTest extends TestCase
{
    #[Test]
    public function only_late_and_left_early_take_minutes(): void
    {
        $withMinutes = array_filter(AttendanceStatus::cases(), fn ($s) => $s->takesMinutes());

        $this->assertSame([AttendanceStatus::Late, AttendanceStatus::LeftEarly], array_values($withMinutes));
    }

    #[Test]
    public function excused_absence_requires_a_reason_and_not_training_allows_one(): void
    {
        $this->assertTrue(AttendanceStatus::AbsentExcused->requiresReason());
        $this->assertTrue(AttendanceStatus::NotTraining->takesReason());
        $this->assertFalse(AttendanceStatus::NotTraining->requiresReason());
        $this->assertFalse(AttendanceStatus::AbsentUnexcused->takesReason());
        $this->assertSame(
            ['present', 'late', 'left_early', 'not_training', 'absent_excused', 'absent_unexcused'],
            AttendanceStatus::values(),
        );
    }
}
