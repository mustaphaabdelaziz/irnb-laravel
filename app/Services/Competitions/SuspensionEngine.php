<?php

namespace App\Services\Competitions;

use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Suspension;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds a competition's automatic bans from its played match reports, in
 * match order: a sanction counting as red gives red_ban_matches; sanctions
 * counting as yellow add up to yellow_threshold, give yellow_ban_matches and
 * the count restarts. Manual bans are never touched. Friendlies (no
 * competition) never reach here.
 */
final class SuspensionEngine
{
    public function recompute(Competition $competition): void
    {
        DB::transaction(function () use ($competition) {
            Suspension::where('competition_id', $competition->id)->where('source', 'auto')->delete();

            $games = Game::query()->where('competition_id', $competition->id)->where('status', GameStatus::Played->value)
                ->orderBy('date')->orderBy('id')->get(['id', 'date', 'rules']);
            if ($games->isEmpty()) {
                return;
            }
            $events = GameEvent::query()->whereIn('game_id', $games->modelKeys())->where('type', 'sanction')->whereNotNull('player_id')
                ->orderByRaw('period is null')->orderBy('period')->orderByRaw('minute is null')->orderBy('minute')->orderBy('id')->get()->groupBy('game_id');

            $red = (int) $competition->red_ban_matches;
            $threshold = $competition->yellow_threshold === null ? null : (int) $competition->yellow_threshold;
            $yellowBan = (int) $competition->yellow_ban_matches;
            $yellows = [];

            foreach ($games as $game) {
                foreach ($events->get($game->id, []) as $event) {
                    $kind = $game->countsAs($event->subtype);
                    if ($kind === 'red' && $red > 0) {
                        $this->ban($competition, $event->player_id, $game, 'red', $red);
                    } elseif ($kind === 'yellow' && $threshold !== null) {
                        $yellows[$event->player_id] = ($yellows[$event->player_id] ?? 0) + 1;
                        if ($yellows[$event->player_id] >= $threshold) {
                            $yellows[$event->player_id] = 0;
                            if ($yellowBan > 0) {
                                $this->ban($competition, $event->player_id, $game, 'yellows', $yellowBan);
                            }
                        }
                    }
                }
            }
        });
    }

    private function ban(Competition $competition, int $playerId, Game $game, string $cause, int $matches): void
    {
        Suspension::create([
            'player_id' => $playerId,
            'competition_id' => $competition->id,
            'team_id' => $competition->team_id,
            'source' => 'auto',
            'kind' => 'matches',
            'matches_count' => $matches,
            'starts_on' => $game->date,
            'cause' => $cause,
            'game_id' => $game->id,
        ]);
    }
}
