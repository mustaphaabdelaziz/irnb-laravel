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

    /** Primary school grades out of 10; every later level out of 20. */
    public function scale(): int
    {
        return $this === self::Primary ? 10 : 20;
    }

    public function passMark(): float
    {
        return $this->scale() / 2;
    }
}
