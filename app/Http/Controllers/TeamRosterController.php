<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Services\Competitions\TeamAccess;
use App\Services\Competitions\TeamRoster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamRosterController extends Controller
{
    public function update(Request $request, Team $team, TeamRoster $roster): RedirectResponse
    {
        $data = $request->validate([
            'season' => ['required', 'integer', 'min:2000', 'max:2100'],
            'players' => ['present', 'array'],
            'players.*.player_id' => ['required', 'integer', 'distinct', 'exists:players,id'],
            'players.*.shirt_number' => ['nullable', 'integer', 'min:0', 'max:99', 'distinct'],
        ]);
        TeamAccess::authorize($request->user(), $team, (int) $data['season']);

        $roster->replace($team, (int) $data['season'], $data['players']);

        return back()->with('success', 'flash.team_roster_saved');
    }

    public function copy(Request $request, Team $team, TeamRoster $roster): RedirectResponse
    {
        $season = (int) $request->validate(['season' => ['required', 'integer', 'min:2000', 'max:2100']])['season'];
        TeamAccess::authorize($request->user(), $team, $season);

        if (DB::table('team_players')->where('team_id', $team->id)->where('season_start_year', $season)->exists()) {
            return back()->with('error', 'flash.team_roster_not_empty');
        }
        $roster->copyFromPreviousSeason($team, $season);

        return back()->with('success', 'flash.team_roster_copied');
    }
}
