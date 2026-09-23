<?php

namespace App\Services\Player;

use App\Models\Player;
use App\Models\WebsiteConfig;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * The permanent number on a player's paper folder.
 *
 * One club-wide sequence rather than a per-year or per-category one: a
 * category changes every season, and a file that has to move every season is
 * a file that gets lost. The number is allocated once, never reused, and the
 * cabinet is sorted by it.
 */
final class FileNumber
{
    private const DEFAULT_DRAWER_SIZE = 100;

    public static function next(): int
    {
        return (int) Player::query()->max('file_number') + 1;
    }

    /**
     * Give a player their number. Does nothing when they already have one —
     * re-registering or editing must never renumber a folder.
     */
    public static function assign(Player $player): int
    {
        if ($player->file_number !== null) {
            return (int) $player->file_number;
        }

        // The unique index is the real guard; two registrations at the same
        // moment both read the same max, and the loser simply takes the next.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = self::next();

            try {
                $player->forceFill(['file_number' => $candidate])->save();

                return $candidate;
            } catch (QueryException $exception) {
                if (! self::isDuplicate($exception)) {
                    throw $exception;
                }

                $player->file_number = null;
            }
        }

        throw new RuntimeException('Could not allocate a file number after 5 attempts.');
    }

    /** Zero-padded for the folder label and the screen; empty when unassigned. */
    public static function format(?int $number): string
    {
        return $number === null ? '' : str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    public static function drawerSize(): int
    {
        $size = (int) (WebsiteConfig::singleton()->settings['fileDrawerSize'] ?? self::DEFAULT_DRAWER_SIZE);

        return ($size >= 10 && $size <= 1000) ? $size : self::DEFAULT_DRAWER_SIZE;
    }

    /** Which drawer holds this file: 1-100 is drawer 1, 101-200 drawer 2, and so on. */
    public static function drawer(int $number, ?int $size = null): int
    {
        return (int) ceil($number / ($size ?? self::drawerSize()));
    }

    private static function isDuplicate(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            || str_contains(strtoupper($exception->getMessage()), 'UNIQUE');
    }
}
