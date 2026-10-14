<?php

namespace App\Support\Import;

use App\Models\Position;
use Illuminate\Support\Collection;

/**
 * Finds a position by abbreviation or name, case-insensitively. Positions are
 * per sport, so the same "GK" may exist once per branch. Among several
 * matches the rule is: one of the player's own branches ($branchIds) first,
 * then one tied to no sport, then the oldest (the positions that existed
 * before positions became per sport, which were all football ones).
 */
final class PositionMatcher
{
    /**
     * @param  Collection<int, Position>  $positions
     * @param  list<int>  $branchIds  the player's branches, when known
     */
    public static function match(Collection $positions, ?string $value, array $branchIds = []): ?int
    {
        $needle = mb_strtolower(trim((string) $value));
        if ($needle === '') {
            return null;
        }

        $matches = $positions
            ->filter(fn (Position $p) => mb_strtolower((string) $p->abbreviation) === $needle || mb_strtolower((string) $p->name) === $needle)
            ->sortBy('id');

        $best = $matches->first(fn (Position $p) => $p->branch_id !== null && in_array((int) $p->branch_id, $branchIds, true))
            ?? $matches->first(fn (Position $p) => $p->branch_id === null)
            ?? $matches->first();

        return $best?->id;
    }
}
