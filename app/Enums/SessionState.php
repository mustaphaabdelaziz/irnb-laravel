<?php

namespace App\Enums;

/** Only held sessions count in reports. Saving marks makes a session held. */
enum SessionState: string
{
    case Planned = 'planned';
    case Held = 'held';
    case Cancelled = 'cancelled';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
