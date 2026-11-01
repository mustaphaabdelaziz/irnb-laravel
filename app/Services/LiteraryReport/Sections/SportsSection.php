<?php

namespace App\Services\LiteraryReport\Sections;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;
use App\Models\PlayerAchievement;
use App\Services\Competitions\CompetitionRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * Results of the games played in the period. MatchStats works per season; the
 * literary report's free period needs a date range, so this queries games directly.
 */
final class SportsSection implements Section
{
    private const RECORD_KEYS = ['played', 'wins', 'draws', 'losses', 'goals_for', 'goals_against'];

    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$start, $end] = [$from->toDateString(), $to->toDateString()];

        $games = Game::query()
            ->with(['competition:id,name', 'team:id,name'])
            ->where('status', GameStatus::Played)
            ->whereBetween('date', [$start, $end])
            ->orderBy('date')
            ->get();

        $competitions = $games->groupBy(fn (Game $g) => $g->competition_id ?? 0)
            ->map(fn ($group) => [
                'name' => $group->first()->competition?->name,
                'team' => $group->map(fn (Game $g) => $g->team?->name)->filter()->unique()->sort()->implode(app()->getLocale() === 'ar' ? '، ' : ', '),
            ] + Arr::only(CompetitionRecord::fromGames($group), self::RECORD_KEYS))
            ->sortBy(fn (array $row) => [$row['name'] === null, (string) $row['name']])
            ->values()->all();

        $goals = GameEvent::query()
            ->where('type', 'goal')
            ->whereNotNull('player_id')
            ->where(fn ($q) => $q->whereNull('subtype')->orWhere('subtype', '!=', 'opponent_own_goal'))
            ->whereIn('game_id', $games->pluck('id'))
            ->selectRaw('player_id, COUNT(*) as goals')
            ->groupBy('player_id')
            ->orderByDesc('goals')
            ->orderBy('player_id')
            ->limit(10)
            ->pluck('goals', 'player_id');
        $players = Player::query()->whereIn('id', $goals->keys())->get(['id', ...Player::NAME_COLUMNS])->keyBy('id');

        $record = Arr::only(CompetitionRecord::fromGames($games), self::RECORD_KEYS);

        return [
            // The record skips played games without scores, so the headline count comes from it.
            'played' => $record['played'],
            'record' => $record,
            'competitions' => $competitions,
            'scorers' => $goals->map(fn ($n, $playerId) => ['name' => $players->get($playerId)?->fullname, 'goals' => (int) $n])->values()->all(),
            'achievements' => PlayerAchievement::query()
                ->with(['player' => fn ($q) => $q->select(['id', ...Player::NAME_COLUMNS])])
                ->whereDate('date', '>=', $start)->whereDate('date', '<=', $end)
                ->orderBy('date')
                ->get()
                ->map(fn (PlayerAchievement $a) => ['date' => $a->date->toDateString(), 'title' => $a->title, 'player' => $a->player?->fullname])
                ->all(),
        ];
    }
}
