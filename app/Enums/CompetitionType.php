<?php

namespace App\Enums;

/** What kind of competition a team enters; friendlies may also stand alone without one. */
enum CompetitionType: string
{
    case League = 'league';
    case Cup = 'cup';
    case Tournament = 'tournament';
    case Friendly = 'friendly';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
