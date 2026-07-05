<?php

namespace App\Services\Player;

use App\Models\Player;

class MembershipNumber
{
    /** Next per-year sequence: highest existing suffix for that year + 1 (1 if none). */
    public static function nextSequence(int $year): int
    {
        $prefix = sprintf('%04d', $year);

        $max = (int) Player::query()
            ->where('membership_id', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTR(membership_id, 5) AS INTEGER)) as m')
            ->value('m');

        return $max + 1;
    }

    /** Format a membership id as YYYYNNNNN (4-digit year + 5-digit zero-padded sequence). */
    public static function format(int $year, int $seq): string
    {
        return sprintf('%04d%05d', $year, $seq);
    }
}
