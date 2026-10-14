<?php

namespace App\Enums;

/** Where the club plays the match. */
enum GameSide: string
{
    case Home = 'home';
    case Away = 'away';
    case Neutral = 'neutral';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
