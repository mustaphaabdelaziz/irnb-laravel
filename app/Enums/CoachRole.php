<?php

namespace App\Enums;

/** A coach's role in a team for a season. */
enum CoachRole: string
{
    case Head = 'head';
    case Assistant = 'assistant';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
