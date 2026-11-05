<?php

namespace App\Services\Dashboard\Support;

use App\Support\FiscalPeriod;
use Illuminate\Support\Facades\DB;

/**
 * When a subscription line falls due, on every driver.
 *
 * Joining text differs between them: || concatenates on SQLite and PostgreSQL,
 * but is a logical OR on MySQL and MariaDB. PostgreSQL also refuses to mix the
 * date column with text inside COALESCE, so it needs the cast.
 */
class EffectiveDueDate
{
    /** A SQL expression: the line's due date, or without one the end of its season. */
    public static function expression(): string
    {
        return self::forDriver(DB::connection()->getDriverName(), FiscalPeriod::endSuffix());
    }

    public static function forDriver(string $driver, string $suffix): string
    {
        $seasonEnd = match ($driver) {
            'sqlite' => "(player_subscriptions.year || '{$suffix}')",
            'pgsql' => "CAST(player_subscriptions.year || '{$suffix}' AS date)",
            default => "CONCAT(player_subscriptions.year, '{$suffix}')",
        };

        return "COALESCE(player_subscriptions.due_date, {$seasonEnd})";
    }
}
