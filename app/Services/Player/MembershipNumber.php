<?php

namespace App\Services\Player;

use App\Models\Player;

class MembershipNumber
{
    /** Clamp a year into [1900,9999] so the formatted id fits membership_id string(10). */
    private static function clampYear(int $year): int
    {
        return max(1900, min(9999, $year));
    }

    /** Next per-year sequence: highest existing suffix for that year + 1 (1 if none). */
    public static function nextSequence(int $year): int
    {
        $prefix = sprintf('%04d', self::clampYear($year));

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
        return sprintf('%04d%05d', self::clampYear($year), $seq);
    }

    /** Generate the next unique membership id for a year, skipping any collision. */
    public static function generateUnique(int $year): string
    {
        do {
            $candidate = self::format($year, self::nextSequence($year));
        } while (Player::query()->where('membership_id', $candidate)->exists());

        return $candidate;
    }
}
