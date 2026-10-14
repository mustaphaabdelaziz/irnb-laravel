<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\Competitions\CallUpCandidates;
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
        if ($called > $game->format->max_called_up) {
            throw ValidationException::withMessages(['players' => 'comp.error.too_many_called']);
        }

        DB::transaction(function () use ($game, $data, $allowed) {
            // Numbers are cleared first (like TeamRoster::replace), so a renumbered
            // roster never trips the unique (game, shirt number) index. Phase 1 has
            // no per-match override: a called-up player wears their roster number.
            $departed = GamePlayer::where('game_id', $game->id)->whereNotIn('player_id', $allowed->keys())
                ->whereNotNull('shirt_number')->pluck('shirt_number', 'player_id');
            GamePlayer::where('game_id', $game->id)->update(['shirt_number' => null]);
            $existing = GamePlayer::where('game_id', $game->id)->get()->keyBy('player_id');
            $keep = [];
            $taken = [];
            foreach ($data['players'] as $row) {
                $calledUp = (bool) $row['called_up'];
                $reason = $calledUp ? null : (trim((string) ($row['not_called_reason'] ?? '')) ?: null);
                if (! $calledUp && $reason === null) {
                    continue;
                }
                $playerId = (int) $row['player_id'];
                $keep[] = $playerId;
                $current = $existing->get($playerId);

                $attributes = ['called_up' => $calledUp, 'not_called_reason' => $reason];
                if ($calledUp) {
                    $attributes['shirt_number'] = $taken[] = $allowed[$playerId]->shirt_number;
                } else {
                    $attributes += ['role' => 'none', 'slot' => null, 'is_captain' => false, 'is_goalkeeper' => false];
                }

                $current
                    ? $current->update($attributes)
                    : GamePlayer::create($attributes + ['game_id' => $game->id, 'player_id' => $playerId]);
            }
            // Only current candidates can be dropped: a player who has since left
            // the roster or squad keeps their row (and number, if still free), so
            // re-saving an old match never erases who played in it.
            GamePlayer::where('game_id', $game->id)->whereIn('player_id', $allowed->keys())->whereNotIn('player_id', $keep)->delete();
            foreach ($departed as $playerId => $shirt) {
                if (! in_array((int) $shirt, $taken, true)) {
                    GamePlayer::where('game_id', $game->id)->where('player_id', $playerId)->update(['shirt_number' => $shirt]);
                }
            }
        });

        return back()->with('success', 'flash.call_up_saved');
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
