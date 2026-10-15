<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\Competitions\CallUpCandidates;
use App\Services\Competitions\GameMinutes;
use App\Services\Competitions\TeamAccess;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class GameCallUpController extends Controller
{
    /**
     * Saves who is called up. A row is kept for each called-up player and
     * for each player left out WITH a reason; the rest have no row. A player
     * dropped from the call-up loses any lineup role (phase 2) with it.
     */
    public function update(Request $request, Game $game, CallUpCandidates $candidates): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $game->team, $game->season_start_year);
        $allowed = $candidates->for($game)->keyBy('id');
        $data = $request->validate([
            'players' => ['present', 'array'],
            'players.*.player_id' => ['required', 'integer', 'distinct', Rule::in($allowed->keys()->all())],
            'players.*.called_up' => ['required', 'boolean'],
            'players.*.not_called_reason' => ['nullable', 'string', 'max:150'],
        ]);

        $called = collect($data['players'])->filter(fn (array $row) => (bool) $row['called_up'])->count();
        if ($called > (int) $game->rule('max_called_up')) {
            throw ValidationException::withMessages(['players' => 'comp.error.too_many_called']);
        }

        $removedEvents = 0;
        DB::transaction(function () use ($game, $data, $allowed, &$removedEvents) {
            // A number the coach set for this match (shirt_override) wins; everyone else
            // wears their roster number unless an override already took it. Numbers are
            // cleared first so a renumbered roster never trips the unique index.
            $before = GamePlayer::where('game_id', $game->id)->get()->keyBy('player_id');
            $departed = $before->filter(fn (GamePlayer $gp) => ! $allowed->has($gp->player_id) && $gp->shirt_number !== null)
                ->map(fn (GamePlayer $gp) => $gp->shirt_number);
            $overrides = $before->filter(fn (GamePlayer $gp) => $gp->shirt_override && $gp->shirt_number !== null)
                ->map(fn (GamePlayer $gp) => (int) $gp->shirt_number);
            GamePlayer::where('game_id', $game->id)->update(['shirt_number' => null]);
            // Reloaded AFTER clearing, so every number written below is seen as a change.
            $existing = GamePlayer::where('game_id', $game->id)->get()->keyBy('player_id');

            $calledIds = [];
            $keep = [];
            foreach ($data['players'] as $row) {
                $calledUp = (bool) $row['called_up'];
                $reason = $calledUp ? null : (trim((string) ($row['not_called_reason'] ?? '')) ?: null);
                if (! $calledUp && $reason === null) {
                    continue;
                }
                $keep[] = (int) $row['player_id'];
                if ($calledUp) {
                    $calledIds[] = (int) $row['player_id'];
                }
            }

            $taken = [];
            foreach ($calledIds as $playerId) {
                if ($overrides->has($playerId)) {
                    $taken[$playerId] = $overrides[$playerId];
                }
            }
            $assign = [];
            foreach ($calledIds as $playerId) {
                if (isset($taken[$playerId])) {
                    $assign[$playerId] = [$taken[$playerId], true];

                    continue;
                }
                $rosterShirt = $allowed[$playerId]->shirt_number;
                $assign[$playerId] = [$rosterShirt !== null && ! in_array($rosterShirt, $taken, true) ? $rosterShirt : null, false];
            }

            foreach ($data['players'] as $row) {
                $playerId = (int) $row['player_id'];
                if (! in_array($playerId, $keep, true)) {
                    continue;
                }
                $calledUp = (bool) $row['called_up'];
                $attributes = $calledUp
                    ? ['called_up' => true, 'not_called_reason' => null, 'shirt_number' => $assign[$playerId][0], 'shirt_override' => $assign[$playerId][1]]
                    : ['called_up' => false, 'not_called_reason' => trim((string) $row['not_called_reason']), 'shirt_number' => null, 'shirt_override' => false,
                        'role' => 'none', 'slot' => null, 'pos_x' => null, 'pos_y' => null, 'is_captain' => false, 'is_goalkeeper' => false,
                        // Out of the match, out of its report too.
                        'played' => false, 'minutes_played' => null, 'sets_played' => null, 'rating' => null, 'coach_note' => null,
                        'is_mvp' => false, 'position_id' => null];
                $current = $existing->get($playerId);
                $current
                    ? $current->update($attributes)
                    : GamePlayer::create($attributes + ['game_id' => $game->id, 'player_id' => $playerId]);
            }

            // Only current candidates can be dropped: a player who has since left
            // the roster or squad keeps their row (and number, if still free), so
            // re-saving an old match never erases who played in it.
            GamePlayer::where('game_id', $game->id)->whereIn('player_id', $allowed->keys())->whereNotIn('player_id', $keep)->delete();
            $used = array_filter(array_column($assign, 0), fn ($n) => $n !== null);
            foreach ($departed as $playerId => $shirt) {
                if (! in_array((int) $shirt, $used, true)) {
                    GamePlayer::where('game_id', $game->id)->where('player_id', $playerId)->update(['shirt_number' => $shirt]);
                }
            }

            // Players no longer called up leave the report: their events go, and an
            // assist they gave is cleared from the scorer's goal.
            $out = $allowed->keys()->map(fn ($id) => (int) $id)->diff($calledIds)->values()->all();
            if ($out !== []) {
                $removedEvents = GameEvent::where('game_id', $game->id)->where(fn ($q) => $q
                    ->whereIn('player_id', $out)
                    ->orWhere(fn ($q) => $q->where('type', 'substitution')->whereIn('related_player_id', $out)))->delete();
                $removedEvents += GameEvent::where('game_id', $game->id)->where('type', '!=', 'substitution')
                    ->whereIn('related_player_id', $out)->update(['related_player_id' => null]);
                if ($removedEvents > 0 && ($game->status === GameStatus::Played || $game->events()->exists())) {
                    app(GameMinutes::class)->refresh($game);
                }
            }
        });

        return back()->with('success', $removedEvents > 0 ? 'flash.call_up_saved_events_removed' : 'flash.call_up_saved');
    }

    /** The convocation sheet to post or share: match, meeting point and the called-up players. */
    public function convocation(Game $game, PdfService $pdf): Response
    {
        $game->load(['team.category', 'competition', 'opponent', 'venue']);
        $locale = app()->getLocale();

        $players = $game->players()->where('called_up', true)->with('player.category')->get()
            ->sortBy([
                fn (GamePlayer $a, GamePlayer $b) => ($a->shirt_number ?? 999) <=> ($b->shirt_number ?? 999),
                fn (GamePlayer $a, GamePlayer $b) => strcmp($a->player->fullname, $b->player->fullname),
            ])
            ->map(fn (GamePlayer $gp) => [
                'shirt' => $gp->shirt_number,
                'name' => $gp->player->fullname,
                'category' => $gp->player->category?->localized_name,
            ])->values()->all();

        $coaches = $game->team->coachesFor($game->season_start_year)->get()
            ->sortBy(fn (User $u) => $u->pivot->role === 'head' ? 0 : 1)
            ->map(fn (User $u) => $u->fullname)->values()->all();

        $html = view('pdf.game-convocation', [
            'club' => ClubHeader::data(),
            'game' => [
                'team' => $game->team->name.' · '.$game->team->category?->localized_name,
                'opponent' => $game->opponent->name,
                'side' => $game->side->value,
                'competition' => $game->competition?->name,
                'round' => $game->round_label,
                'dateLabel' => CarbonImmutable::createFromFormat('!Y-m-d', $game->date)->locale($locale)->translatedFormat('l j F Y'),
                'kickoff' => $game->kickoff_time,
                'meeting_time' => $game->meeting_time,
                'meeting_place' => $game->meeting_place,
                'venue' => $game->venue?->name,
                'address' => $game->venue?->address,
            ],
            'players' => $players,
            'coaches' => $coaches,
        ])->render();

        return $pdf->stream($html, "convocation-{$game->id}-{$game->date}.pdf", $locale === 'ar');
    }
}
