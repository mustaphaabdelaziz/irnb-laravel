<?php

namespace App\Services\Player;

use App\Models\Player;

class MembershipNumber
{
    /** Next per-year sequence: highest existing suffix for that year + 1 (1 if none). */
    public static function nextSequence(int $year): int
    {
        $prefix = sprintf('%04d', $year);

        $max = Player::query()
            ->where('membership_id', 'like', $prefix.'%')
            ->pluck('membership_id')
            ->map(fn ($id) => (int) substr((string) $id, 4))
            ->max();

        return (int) $max + 1;
    }

    /** Format a membership id as YYYYNNNNN (4-digit year + 5-digit zero-padded sequence). */
    public static function format(int $year, int $seq): string
    {
        $year = max(1900, min(9999, $year));

        return sprintf('%04d%05d', $year, $seq);
    }
}
