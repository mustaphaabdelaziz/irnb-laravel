<?php

namespace App\Enums;

/** Distinctions a school awards for a trimester, highest first. */
enum AcademicCertificate: string
{
    case Excellence = 'excellence';
    case Congratulations = 'congratulations';
    case Encouragement = 'encouragement';
    case HonorRoll = 'honor_roll';

    public function rank(): int
    {
        return match ($this) {
            self::Excellence => 4,
            self::Congratulations => 3,
            self::Encouragement => 2,
            self::HonorRoll => 1,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
