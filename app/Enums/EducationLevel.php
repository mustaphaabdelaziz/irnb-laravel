<?php

namespace App\Enums;

enum EducationLevel: string
{
    case Primary = 'primary';
    case Middle = 'middle';
    case Secondary = 'secondary';
    case Vocational = 'vocational';
    case Licence = 'licence';
    case Master = 'master';
    case Doctorate = 'doctorate';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
