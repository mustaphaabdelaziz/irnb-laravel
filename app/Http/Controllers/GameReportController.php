<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Services\Competitions\GameMinutes;
use App\Services\Competitions\ReportValidator;
use App\Services\Competitions\ScoreCalculator;
use App\Services\Competitions\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GameReportController extends Controller
{
    /**
     * Saves the whole report at once: scores, the timeline (replaced), and the
     * per-player grid. The match becomes "played"; minutes are worked out from
     * the line-up and timeline unless the format has rolling substitutions.
     */
    public function update(Request $request, Game $game, ReportValidator $validator, GameMinutes $minutes): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $game->team, $game->season_start_year);
        $periods = (int) $game->rule('periods_count');
        $data = $request->validate([
            'period_scores' => ['present', 'array', 'max:'.($periods + 1)],
            'period_scores.*' => ['nullable', 'array', 'size:2'],
            'period_scores.*.*' => ['nullable', 'integer', 'min:0', 'max:999'],
            'extra_time_played' => ['sometimes', 'boolean'],
            'our_score' => ['nullable', 'integer', 'min:0', 'max:999'],
            'their_score' => ['nullable', 'integer', 'min:0', 'max:999'],
            'shootout_us' => ['nullable', 'integer', 'min:0', 'max:99'],
            'shootout_them' => ['nullable', 'integer', 'min:0', 'max:99'],
            'events' => ['present', 'array', 'max:200'],
            'events.*.type' => ['required', Rule::in(GameEvent::TYPES)],
            'events.*.subtype' => ['nullable', 'string', 'max:32'],
            'events.*.period' => ['nullable', 'integer', 'min:1', 'max:'.($periods + 1)],
            'events.*.minute' => ['nullable', 'integer', 'min:0', 'max:300'],
            'events.*.player_id' => ['nullable', 'integer'],
            'events.*.related_player_id' => ['nullable', 'integer'],
            'players' => ['present', 'array'],
            'players.*.player_id' => ['required', 'integer', 'distinct'],
            'players.*.played' => ['sometimes', 'boolean'],
            'players.*.minutes_played' => ['nullable', 'integer', 'min:0', 'max:300'],
            'players.*.sets_played' => ['nullable', 'integer', 'min:0', 'max:9'],
            'players.*.rating' => ['nullable', 'integer', 'min:1', 'max:10'],
            'players.*.coach_note' => ['nullable', 'string', 'max:2000'],
            'mvp_player_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['extra_time_played'] = $request->boolean('extra_time_played');

        $roles = $game->players()->where('called_up', true)->get(['player_id', 'role'])
            ->mapWithKeys(fn (GamePlayer $gp) => [$gp->player_id => $gp->role === 'starter' ? 'starter' : 'bench'])->all();
        $errors = $validator->errors($game, $roles, $data);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $rows = array_map(fn ($row) => is_array($row) ? [$row[0] ?? null, $row[1] ?? null] : [null, null], $data['period_scores']);
        $score = ScoreCalculator::derive($rows, (string) $game->rule('scoring'));
        if ($score === null && ($data['our_score'] === null || $data['their_score'] === null)) {
            throw ValidationException::withMessages(['our_score' => 'comp.error.score_required']);
        }
        [$us, $them] = $score ?? [(int) $data['our_score'], (int) $data['their_score']];

        DB::transaction(function () use ($game, $data, $rows, $us, $them, $minutes) {
            $game->update([
                'period_scores' => $rows,
                'extra_time_played' => $data['extra_time_played'],
                'our_score' => $us,
                'their_score' => $them,
                'shootout_us' => $data['shootout_us'] ?? null,
                'shootout_them' => $data['shootout_them'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => GameStatus::Played,
            ]);

            $game->events()->delete();
            foreach ($data['events'] as $event) {
                $game->events()->create([
                    'type' => $event['type'],
                    'subtype' => $event['subtype'] ?? null,
                    'period' => $event['period'] ?? null,
                    'minute' => $event['minute'] ?? null,
                    'player_id' => $event['player_id'] ?? null,
                    'related_player_id' => $event['related_player_id'] ?? null,
                ]);
            }

            $rolling = (bool) $game->rule('rolling_subs');
            $sets = $game->rule('scoring') === 'sets';
            $grid = collect($data['players'])->keyBy('player_id');
            foreach ($game->players()->where('called_up', true)->get() as $gp) {
                $row = $grid->get($gp->player_id, []);
                $attributes = [
                    'rating' => $row['rating'] ?? null,
                    'coach_note' => $row['coach_note'] ?? null,
                    'is_mvp' => (int) ($data['mvp_player_id'] ?? 0) === $gp->player_id,
                ];
                if ($rolling) {
                    $attributes += [
                        'played' => (bool) ($row['played'] ?? false),
                        'minutes_played' => $sets ? null : ($row['minutes_played'] ?? null),
                        'sets_played' => $sets ? ($row['sets_played'] ?? null) : null,
                    ];
                }
                $gp->update($attributes);
            }

            $minutes->refresh($game->fresh());
        });

        return back()->with('success', 'flash.report_saved');
    }
}
