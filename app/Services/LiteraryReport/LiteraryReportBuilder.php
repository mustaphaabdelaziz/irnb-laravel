<?php

namespace App\Services\LiteraryReport;

use App\Models\User;

final class LiteraryReportBuilder
{
    /** The module whose view right each data section needs; other sections need none. */
    public const MODULES = [
        'members' => 'players',
        'board' => 'board',
        'attendance' => 'attendance',
        'sports' => 'games',
        'activities' => 'activities',
        'finance' => 'finance',
    ];

    public static function allows(string $key, User $user): bool
    {
        return ! isset(self::MODULES[$key]) || $user->hasPermission(self::MODULES[$key], 'view');
    }
}
