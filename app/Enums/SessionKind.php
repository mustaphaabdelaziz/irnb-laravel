<?php

namespace App\Enums;

/** Regular sessions come from the weekly schedule; the others are added by hand. */
enum SessionKind: string
{
    case Regular = 'regular';
    case Preseason = 'preseason';
    case Extra = 'extra';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
