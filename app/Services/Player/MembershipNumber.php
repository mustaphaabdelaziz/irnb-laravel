<?php

namespace App\Services\Player;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Player;

class MembershipNumber
{
    /** New format: FB-2022-00017. */
    private const PATTERN = '/^[A-Z]{2,6}-\d{4}-\d{5}$/';

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

    /** An ID from before branch codes. Null is not an ID, so not legacy. */
    public static function isLegacy(?string $id): bool
    {
        return $id !== null && preg_match(self::PATTERN, $id) !== 1;
    }

    public static function compose(string $code, int $year, int $seq): string
    {
        return sprintf('%s-%04d-%05d', $code, self::clampYear($year), $seq);
    }

    /** The next free ID for a branch and year. Players hold a copy, so both tables count. */
    public static function forBranch(Branch $branch, int $year): string
    {
        $prefix = sprintf('%s-%04d-', $branch->code, self::clampYear($year));

        $max = BranchRegistration::query()->where('membership_id', 'like', $prefix.'%')->pluck('membership_id')
            ->merge(Player::query()->where('membership_id', 'like', $prefix.'%')->pluck('membership_id'))
            ->map(fn (string $id) => (int) substr($id, strlen($prefix)))
            ->max();

        return self::compose($branch->code, $year, (int) $max + 1);
    }
}
