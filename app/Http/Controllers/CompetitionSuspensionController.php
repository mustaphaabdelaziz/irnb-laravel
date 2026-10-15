<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use App\Models\Suspension;
use App\Services\Competitions\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Manual bans (federation decisions, club discipline), added from a
 * competition page: for this competition or all competitions, as N matches or
 * a date range. Automatic bans come from match reports and cannot be deleted.
 */
class CompetitionSuspensionController extends Controller
{
    public function store(Request $request, Competition $competition): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $competition->team, $competition->season_start_year);
        $roster = DB::table('team_players')->where('team_id', $competition->team_id)
            ->where('season_start_year', $competition->season_start_year)->pluck('player_id')->map(fn ($id) => (int) $id)->all();
        $data = $request->validate([
            'player_id' => ['required', 'integer', Rule::in($roster)],
            'scope' => ['required', Rule::in(['competition', 'all'])],
            'kind' => ['required', Rule::in(['matches', 'dates'])],
            'matches_count' => ['nullable', 'required_if:kind,matches', 'integer', 'min:1', 'max:50'],
            'starts_on' => ['nullable', 'required_if:kind,dates', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'required_if:kind,dates', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $dates = $data['kind'] === 'dates';

        Suspension::create([
            'player_id' => $data['player_id'],
            'competition_id' => $data['scope'] === 'competition' ? $competition->id : null,
            'team_id' => $competition->team_id,
            'source' => 'manual',
            'kind' => $data['kind'],
            'matches_count' => $dates ? null : (int) $data['matches_count'],
            // A matches ban starts counting from today unless a start date is given.
            'starts_on' => $data['starts_on'] ?? now()->toDateString(),
            'ends_on' => $dates ? $data['ends_on'] : null,
            'reason' => $data['reason'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'flash.suspension_added');
    }

    public function destroy(Request $request, Competition $competition, Suspension $suspension): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $competition->team, $competition->season_start_year);
        abort_unless($suspension->source === 'manual'
            && ($suspension->competition_id === $competition->id || ($suspension->competition_id === null && $suspension->team_id === $competition->team_id)), 403);
        $suspension->delete();

        return back()->with('success', 'flash.suspension_removed');
    }
}
