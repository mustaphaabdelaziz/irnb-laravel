<?php

namespace App\Enums;

/** How a sport counts the score. */
enum ScoringType: string
{
    case Goals = 'goals';
    case Points = 'points';
    case Sets = 'sets';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
