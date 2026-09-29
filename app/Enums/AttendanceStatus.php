<?php

namespace App\Enums;

/**
 * How a player took part in one training session. Every status is counted on
 * its own in reports; none is folded into another.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case LeftEarly = 'left_early';
    case NotTraining = 'not_training';   // came, did not train (injured, sick)
    case AbsentExcused = 'absent_excused';
    case AbsentUnexcused = 'absent_unexcused';

    /** Late and left-early marks carry how many minutes. */
    public function takesMinutes(): bool
    {
        return $this === self::Late || $this === self::LeftEarly;
    }

    public function takesReason(): bool
    {
        return $this === self::AbsentExcused || $this === self::NotTraining;
    }

    public function requiresReason(): bool
    {
        return $this === self::AbsentExcused;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
