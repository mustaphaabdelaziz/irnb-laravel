<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Models\Formation;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Position;
use App\Services\Competitions\DefaultFormation;
use App\Services\Competitions\GameMinutes;
use App\Services\Competitions\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GameLineupController extends Controller
{
    /**
     * Saves the line-up: starters with their slot (and free placement, shirt,
     * captain, goalkeeper); every other called-up player is on the bench.
     * Limits are blocking only where they break the match (too many starters,
     * a slot or number used twice); "too few" is a warning shown on the page.
     */
    public function update(Request $request, Game $game, GameMinutes $minutes): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $game->team, $game->season_start_year);
        $called = $game->players()->where('called_up', true)->pluck('player_id')->map(fn ($id) => (int) $id)->all();
        $data = $request->validate([
            'formation_id' => ['nullable', 'integer', Rule::exists('formations', 'id')->where('game_format_id', $game->game_format_id)],
            'players' => ['present', 'array'],
            'players.*.player_id' => ['required', 'integer', 'distinct', Rule::in($called)],
            'players.*.role' => ['required', Rule::in(['starter', 'bench'])],
            'players.*.slot' => ['nullable', 'integer', 'min:0', 'max:29'],
            'players.*.pos_x' => ['nullable', 'integer', 'min:0', 'max:100'],
            'players.*.pos_y' => ['nullable', 'integer', 'min:0', 'max:100'],
            // No "distinct" here: two equal numbers are caught below as comp.error.shirt_taken.
            'players.*.shirt_number' => ['nullable', 'integer', 'min:0', 'max:99'],
            'players.*.is_captain' => ['sometimes', 'boolean'],
            'players.*.is_goalkeeper' => ['sometimes', 'boolean'],
        ]);

        $formation = isset($data['formation_id']) ? Formation::find($data['formation_id']) : null;
        // Without a formation the format's automatic shape gives the spots (labels "1".."N", no positions).
        $spots = $formation?->slots ?? DefaultFormation::slots((int) $game->rule('players_on_field'));

        $rows = collect($data['players']);
        $starters = $rows->where('role', 'starter');
        $slots = $starters->pluck('slot')->filter(fn ($slot) => $slot !== null);
        $goalkeepers = $rows->filter(fn (array $r) => ! empty($r['is_goalkeeper']))->count();
        $error = match (true) {
            $starters->count() > (int) $game->rule('players_on_field') => 'comp.error.too_many_starters',
            $spots !== [] && $slots->count() !== $starters->count() => 'comp.error.slot_required',
            $slots->contains(fn (int $slot) => $slot >= count($spots)) => 'comp.error.invalid_slot',
            $slots->count() !== $slots->unique()->count() => 'comp.error.slot_taken',
            $rows->filter(fn (array $r) => ! empty($r['is_captain']))->count() > 1 => 'comp.error.one_captain',
            $goalkeepers > 1 || ($goalkeepers > 0 && ! $game->hasGoalkeeper()) => 'comp.error.one_goalkeeper',
            default => null,
        };
        if ($error !== null) {
            throw ValidationException::withMessages(['players' => $error]);
        }

        $positions = Position::query()->where('branch_id', $game->team->branch_id)->pluck('id', 'abbreviation');
        $roster = DB::table('team_players')->where('team_id', $game->team_id)->where('season_start_year', $game->season_start_year)
            ->pluck('shirt_number', 'player_id');

        // The number each called-up player will wear: the typed one, else the roster's, else the one already on the match.
        $byPlayer = $rows->keyBy('player_id');
        $effective = [];
        foreach (GamePlayer::where('game_id', $game->id)->where('called_up', true)->get() as $gp) {
            $row = $byPlayer->get($gp->player_id);
            $effective[$gp->player_id] = $row !== null && isset($row['shirt_number'])
                ? (int) $row['shirt_number']
                : (isset($roster[$gp->player_id]) ? (int) $roster[$gp->player_id] : $gp->shirt_number);
        }
        $numbers = array_filter($effective, fn ($n) => $n !== null);
        if (count($numbers) !== count(array_unique($numbers))) {
            throw ValidationException::withMessages(['players' => 'comp.error.shirt_taken']);
        }

        DB::transaction(function () use ($game, $byPlayer, $effective, $formation, $positions, $roster, $minutes) {
            $game->update(['formation_id' => $formation?->id]);
            // Numbers first cleared, so moving #7 from one player to another never trips the unique index.
            GamePlayer::where('game_id', $game->id)->update(['shirt_number' => null]);

            foreach (GamePlayer::where('game_id', $game->id)->where('called_up', true)->get() as $gp) {
                $row = $byPlayer->get($gp->player_id);
                $starter = ($row['role'] ?? 'bench') === 'starter';
                $label = $starter && $formation && isset($row['slot']) ? ($formation->slots[$row['slot']]['label'] ?? null) : null;
                $rosterShirt = isset($roster[$gp->player_id]) ? (int) $roster[$gp->player_id] : null;
                $shirt = $effective[$gp->player_id];

                $gp->update([
                    'role' => $starter ? 'starter' : 'bench',
                    'slot' => $starter ? ($row['slot'] ?? null) : null,
                    'pos_x' => $starter ? ($row['pos_x'] ?? null) : null,
                    'pos_y' => $starter ? ($row['pos_y'] ?? null) : null,
                    'position_id' => $label !== null ? ($positions[$label] ?? null) : null,
                    'shirt_number' => $shirt,
                    'shirt_override' => $shirt !== $rosterShirt,
                    'is_captain' => (bool) ($row['is_captain'] ?? false),
                    'is_goalkeeper' => (bool) ($row['is_goalkeeper'] ?? false),
                ]);
            }

            if ($game->status === GameStatus::Played || $game->events()->exists()) {
                $minutes->refresh($game);
            }
        });

        return back()->with('success', 'flash.lineup_saved');
    }
}
