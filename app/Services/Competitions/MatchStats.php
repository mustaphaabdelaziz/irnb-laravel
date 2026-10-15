<?php

namespace App\Services\Competitions;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Models\Player;

/**
 * Player statistics from played matches: an appearance is a called-up player
 * marked "played" in a played match. Three narrow queries (games, players,
 * events) aggregated here — portable across sqlite and MySQL, and the
 * yellow/red mapping needs each match's own rules anyway. One row per player
 * and sport (minutes and sets never mix).
 */
final class MatchStats
{
    /** @return list<array<string, mixed>> */
    public function players(int $season, ?int $teamId = null, ?int $competitionId = null, ?int $playerId = null): array
    {
        $games = Game::query()->where('status', GameStatus::Played->value)->where('season_start_year', $season)
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->when($competitionId, fn ($q) => $q->where('competition_id', $competitionId))
            ->get(['id', 'rules'])->keyBy('id');
        if ($games->isEmpty()) {
            return [];
        }
        $rows = GamePlayer::query()->whereIn('game_id', $games->keys())->where('called_up', true)
            ->when($playerId, fn ($q) => $q->where('player_id', $playerId))
            ->get(['game_id', 'player_id', 'role', 'played', 'minutes_played', 'sets_played', 'rating', 'is_mvp']);
        if ($rows->isEmpty()) {
            return [];
        }
        $events = GameEvent::query()->whereIn('game_id', $games->keys())->whereIn('type', ['goal', 'sanction'])
            ->get(['game_id', 'type', 'subtype', 'player_id', 'related_player_id']);
        $names = Player::query()->whereIn('id', $rows->pluck('player_id')->unique())->get(['id', ...Player::NAME_COLUMNS])->keyBy('id');

        $sport = fn (int $gameId) => (string) ($games[$gameId]->rule('sport') ?? 'other');
        $stats = [];
        $slot = function (int $pid, string $sp) use (&$stats, $names) {
            return $stats["{$pid}|{$sp}"] ??= [
                'player_id' => $pid, 'name' => $names[$pid]?->fullname ?? '', 'sport' => $sp,
                'appearances' => 0, 'starts' => 0, 'minutes' => 0, 'sets' => 0, 'goals' => 0, 'assists' => 0,
                'yellows' => 0, 'reds' => 0, 'other_sanctions' => 0, 'avg_rating' => null, 'rated' => 0, 'motm' => 0, 'rating_sum' => 0,
            ];
        };

        foreach ($rows as $row) {
            $key = "{$row->player_id}|{$sport($row->game_id)}";
            $slot($row->player_id, $sport($row->game_id));
            if ($row->played) {
                $stats[$key]['appearances']++;
                if ($row->role === 'starter') {
                    $stats[$key]['starts']++;
                }
            }
            $stats[$key]['minutes'] += (int) $row->minutes_played;
            $stats[$key]['sets'] += (int) $row->sets_played;
            if ($row->rating !== null) {
                $stats[$key]['rating_sum'] += (int) $row->rating;
                $stats[$key]['rated']++;
            }
            if ($row->is_mvp) {
                $stats[$key]['motm']++;
            }
        }

        foreach ($events as $event) {
            $sp = $sport($event->game_id);
            if ($event->type === 'goal') {
                if ($event->subtype !== 'opponent_own_goal' && $event->player_id && isset($stats["{$event->player_id}|{$sp}"])) {
                    $stats["{$event->player_id}|{$sp}"]['goals']++;
                }
                if ($event->related_player_id && isset($stats["{$event->related_player_id}|{$sp}"])) {
                    $stats["{$event->related_player_id}|{$sp}"]['assists']++;
                }

                continue;
            }
            if (! $event->player_id || ! isset($stats["{$event->player_id}|{$sp}"])) {
                continue;
            }
            $field = match ($games[$event->game_id]->countsAs($event->subtype)) {
                'yellow' => 'yellows',
                'red' => 'reds',
                default => 'other_sanctions',
            };
            $stats["{$event->player_id}|{$sp}"][$field]++;
        }

        return collect($stats)->map(function (array $s) {
            $s['avg_rating'] = $s['rated'] > 0 ? round($s['rating_sum'] / $s['rated'], 1) : null;
            unset($s['rating_sum']);

            return $s;
        })->sortBy('name')->values()->all();
    }

    /** @return array{goals: list, assists: list, minutes: list, appearances: list, cards: list, rating: list} */
    public static function leaderboards(array $rows, int $top = 5): array
    {
        $board = fn (string $metric) => collect($rows)->filter(fn (array $r) => $r[$metric] > 0)
            ->sortByDesc($metric)->take($top)->values()->all();

        return [
            'goals' => $board('goals'),
            'assists' => $board('assists'),
            'minutes' => $board('minutes'),
            'appearances' => $board('appearances'),
            'cards' => collect($rows)->map(fn (array $r) => $r + ['card_points' => $r['reds'] * 3 + $r['yellows']])
                ->filter(fn (array $r) => $r['card_points'] > 0)->sortByDesc('card_points')->take($top)->values()->all(),
            'rating' => collect($rows)->filter(fn (array $r) => $r['rated'] >= 3)->sortByDesc('avg_rating')->take($top)->values()->all(),
        ];
    }

    /** One player's played matches of a season, newest first. @return list<array<string, mixed>> */
    public function games(int $playerId, int $season): array
    {
        $rows = GamePlayer::query()->where('player_id', $playerId)->where('called_up', true)
            ->whereHas('game', fn ($q) => $q->where('status', GameStatus::Played->value)->where('season_start_year', $season))
            ->with(['game.opponent', 'game.competition', 'game.team'])->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $events = GameEvent::query()->whereIn('game_id', $rows->pluck('game_id'))
            ->where(fn ($q) => $q->where('player_id', $playerId)->orWhere('related_player_id', $playerId))->get();

        return $rows->map(function (GamePlayer $gp) use ($events, $playerId) {
            $game = $gp->game;
            $mine = $events->where('game_id', $game->id);

            return [
                'game_id' => $game->id,
                'date' => $game->date,
                'team' => $game->team->name,
                'opponent' => $game->opponent->name,
                'competition' => $game->competition?->name,
                'side' => $game->side->value,
                'our_score' => $game->our_score,
                'their_score' => $game->their_score,
                'role' => $gp->role === 'starter' ? 'starter' : 'bench',
                'played' => $gp->played,
                'minutes' => $gp->minutes_played,
                'sets' => $gp->sets_played,
                'goals' => $mine->where('type', 'goal')->where('player_id', $playerId)->where('subtype', '!=', 'opponent_own_goal')->count(),
                'assists' => $mine->where('type', 'goal')->where('related_player_id', $playerId)->count(),
                'cards' => $mine->where('type', 'sanction')->where('player_id', $playerId)->pluck('subtype')->values()->all(),
                'rating' => $gp->rating,
                'motm' => $gp->is_mvp,
            ];
        })->sortByDesc('date')->values()->all();
    }
}
