<?php

namespace App\Services\Competitions;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may change a team's squads, matches and call-ups. Role permissions
 * decide WHAT a user may do; this decides FOR WHICH TEAMS. A user whose role
 * is limited to its own teams (`own_teams_only`, the coach role) manages only
 * the teams they are assigned to for that season, and still views every team.
 */
final class TeamAccess
{
    public static function isScoped(User $user): bool
    {
        return ! $user->isGodAdmin() && (bool) $user->role?->own_teams_only;
    }

    public static function canManage(User $user, Team $team, int $season): bool
    {
        if (! self::isScoped($user)) {
            return true;
        }

        return DB::table('team_coaches')
            ->where('team_id', $team->id)->where('season_start_year', $season)->where('user_id', $user->id)
            ->exists();
    }

    public static function authorize(User $user, Team $team, int $season): void
    {
        abort_unless(self::canManage($user, $team, $season), 403);
    }

    /** @return list<int>|null the team ids the user manages in $season; null means every team. */
    public static function manageableTeamIds(User $user, int $season): ?array
    {
        if (! self::isScoped($user)) {
            return null;
        }

        return DB::table('team_coaches')->where('user_id', $user->id)->where('season_start_year', $season)
            ->pluck('team_id')->map(fn ($id) => (int) $id)->all();
    }
}
