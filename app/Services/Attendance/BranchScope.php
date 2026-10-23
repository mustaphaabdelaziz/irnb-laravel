<?php

namespace App\Services\Attendance;

use Illuminate\Database\Query\Builder;

/**
 * The one branch condition of the attendance reports. A mark belongs to its
 * session's branch (`training_sessions.branch_id`), so a two-sport player's
 * numbers are computed per sport. Null = no condition: a club without
 * branches, where every session is club-wide.
 *
 * Written `+column = ?` on purpose. Real databases have no sqlite_stat1
 * (never ANALYZEd), and without statistics sqlite leads by an equality on an
 * indexed column over a date range: a plain `branch_id = ?` would walk the
 * branch's whole history through `training_sessions_branch_id_index`
 * instead of the period through the date index (and, in InjurySpells'
 * per-player "latest mark" probes, sort it for every player). The unary plus
 * keeps the index out of reach on sqlite and is a no-op on MySQL.
 */
final class BranchScope
{
    public static function apply(Builder $query, ?int $branchId, string $column = 'training_sessions.branch_id'): Builder
    {
        return $branchId === null ? $query : $query->whereRaw('+'.$column.' = ?', [$branchId]);
    }
}
