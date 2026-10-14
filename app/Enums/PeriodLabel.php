<?php

namespace App\Enums;

/** What a sport calls a period of play. */
enum PeriodLabel: string
{
    case Half = 'half';
    case Quarter = 'quarter';
    case Set = 'set';
    case Period = 'period';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
