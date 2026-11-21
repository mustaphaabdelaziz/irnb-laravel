<?php

namespace App\Services\Attendance;

use Illuminate\Database\Query\Builder;

/**
 * The one training-group condition of the attendance reports (statistics,
 * ranking and certificates, players at risk). A mark belongs to its session's
 * group (`training_sessions.group_id`), whatever group the player is in
 * today: a player who changed group mid-season has the marks until the move
 * in the old group and the rest in the new one. Null = no condition: every
 * session of the category, the ones with no group included, which is what
 * the reports have always counted.
 *
 * Written `+column = ?` for the reason BranchScope gives: `group_id` is
 * indexed, and without sqlite_stat1 a plain `group_id = ?` would make sqlite
 * lead by the group's whole history instead of the period through the date
 * index. With the unary plus the plan is the plan of "all groups" plus one
 * filter, so a group never costs more than the report without it. It is a
 * no-op on MySQL.
 */
final class GroupScope
{
    /** $query must read `training_sessions` under its own name. */
    public static function apply(Builder $query, ?int $groupId): Builder
    {
        return $groupId === null ? $query : $query->whereRaw('+training_sessions.group_id = ?', [$groupId]);
    }
}
