<?php

namespace App\Enums;

/** Why an excused (or not-training) player missed the session. */
enum AbsenceReason: string
{
    case Injury = 'injury';
    case Illness = 'illness';
    case School = 'school';
    case Family = 'family';
    case Travel = 'travel';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
