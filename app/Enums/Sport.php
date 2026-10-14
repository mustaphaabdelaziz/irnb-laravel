<?php

namespace App\Enums;

/** The sport a game format is played under; drives presets and (Phase 2) the pitch drawing. */
enum Sport: string
{
    case Football = 'football';
    case Futsal = 'futsal';
    case Handball = 'handball';
    case Basketball = 'basketball';
    case Volleyball = 'volleyball';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
