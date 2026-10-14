<?php

namespace App\Http\Controllers;

use App\Enums\CoachRole;
use App\Models\Team;
use App\Services\Competitions\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeamCoachController extends Controller
{
    /** Replaces the team's coaches for one season. Admin-only: coach assignment is what TeamAccess scopes coaches by. */
    public function update(Request $request, Team $team): RedirectResponse
    {
        abort_if(TeamAccess::isScoped($request->user()), 403);
        $data = $request->validate([
            'season' => ['required', 'integer', 'min:2000', 'max:2100'],
            'coaches' => ['present', 'array'],
            'coaches.*.user_id' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'coaches.*.role' => ['required', Rule::enum(CoachRole::class)],
        ]);
        $season = (int) $data['season'];

        DB::transaction(function () use ($team, $season, $data) {
            DB::table('team_coaches')->where('team_id', $team->id)->where('season_start_year', $season)->delete();
            $now = now();
            DB::table('team_coaches')->insert(array_map(fn (array $c) => [
                'team_id' => $team->id, 'season_start_year' => $season, 'user_id' => $c['user_id'],
                'role' => $c['role'], 'created_at' => $now, 'updated_at' => $now,
            ], $data['coaches']));
        });

        return back()->with('success', 'flash.team_coaches_saved');
    }
}
