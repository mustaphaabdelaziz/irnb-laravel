<?php

namespace App\Services\Dashboard\Support;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single place that knows how each entity reaches a branch.
 *
 * Players, subscriptions and equipment reach it through pivots; transactions
 * reach it through their finance account. Re-deriving that per widget is how a
 * branch filter ends up meaning four different things on one page.
 *
 * A player who left a branch still counts in it here, because their debt there
 * is still owed. A figure about current members must add its own active rule.
 */
class BranchScope
{
    public static function players(Builder $query, ?int $branchId): Builder
    {
        return $branchId === null
            ? $query
            : $query->whereHas('branches', fn (Builder $q) => $q->whereKey($branchId));
    }

    public static function subscriptions(Builder $query, ?int $branchId): Builder
    {
        return $branchId === null
            ? $query
            : $query->whereHas('branches', fn (Builder $q) => $q->whereKey($branchId));
    }

    public static function equipmentItems(Builder $query, ?int $branchId): Builder
    {
        return $branchId === null
            ? $query
            : $query->whereHas('branches', fn (Builder $q) => $q->whereKey($branchId));
    }

    public static function transactions(Builder $query, ?int $branchId): Builder
    {
        return $branchId === null
            ? $query
            : $query->whereHas('financeAccount', fn (Builder $q) => $q->where('branch_id', $branchId));
    }

    /** Player ids in a branch, for raw query builders that cannot use whereHas. */
    public static function playerIdsQuery(?int $branchId): ?QueryBuilder
    {
        if ($branchId === null) {
            return null;
        }

        return DB::table('branch_player')
            ->where('branch_id', $branchId)
            ->select('player_id');
    }
}
