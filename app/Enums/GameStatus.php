<?php

namespace App\Enums;

/** A match's life: scheduled until played, or postponed / cancelled. */
enum GameStatus: string
{
    case Scheduled = 'scheduled';
    case Played = 'played';
    case Postponed = 'postponed';
    case Cancelled = 'cancelled';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
