<?php

namespace App\Reports\Competitions;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Reports\Column;
use App\Reports\Concerns\FiltersGames;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Services\Competitions\MatchStats;
use Illuminate\Support\Collection;

/**
 * Per player, over the season's played matches: how often they were on the
 * match list, called up, left out, and — from MatchStats, the statistics
 * page's own count — appearances, starts, minutes or sets.
 */
class CompetitionAppearances extends Report
{
    use FiltersGames;

    /** @var array<string, Collection<int, array<string, mixed>>> */
    private array $memo = [];

    public function __construct(private readonly MatchStats $stats) {}

    public function key(): string
    {
        return 'appearances';
    }

    public function category(): string
    {
        return 'competitions';
    }

    public function module(): string
    {
        return 'games';
    }

    public function icon(): string
    {
        return 'players';
    }

    public function filters(): array
    {
        return $this->gameFilters();
    }

    public function columns(): array
    {
        return [
            new Column('name', 'comp.player'),
            new Column('listed', 'reports.col.listed', Column::NUMBER),
            new Column('called', 'reports.col.called', Column::NUMBER),
            new Column('not_called', 'reports.col.not_called', Column::NUMBER),
            new Column('appearances', 'comp.stats.col_appearances', Column::NUMBER),
            new Column('starts', 'comp.stats.col_starts', Column::NUMBER),
            new Column('minutes', 'comp.stats.col_minutes', Column::NUMBER),
            new Column('sets', 'comp.stats.col_sets', Column::NUMBER),
            new Column('reasons', 'comp.not_called_reason'),
        ];
    }

    public function sortable(): array
    {
        return ['name' => 'name', 'called' => 'called', 'appearances' => 'appearances', 'starts' => 'starts', 'minutes' => 'minutes', 'not_called' => 'not_called'];
    }

    public function defaultSort(): array
    {
        return ['appearances', 'desc'];
    }

    public function rows(ReportFilters $filters): Collection
    {
        return $this->memo[serialize($filters->values())] ??= $this->compute($filters);
    }

    public function summary(ReportFilters $filters): array
    {
        $rows = $this->rows($filters);

        return [
            ['label' => 'reports.kpi.players', 'value' => $rows->count(), 'format' => 'number'],
            ['label' => 'reports.kpi.played', 'value' => $this->games($filters)->count(), 'format' => 'number'],
            ['label' => 'comp.stats.col_appearances', 'value' => (int) $rows->sum('appearances'), 'format' => 'number'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        return [
            ['type' => 'bar', 'title' => 'reports.chart.minutes_by_player', 'unit' => 'comp.stats.col_minutes', 'stats' => $this->rows($filters)
                ->where('minutes', '>', 0)->sortByDesc('minutes')->take(15)
                ->map(fn (array $r) => ['key' => $r['name'], 'label' => $r['name'], 'count' => $r['minutes']])->values()->all()],
        ];
    }

    /** @return Collection<int, Game> played matches in the filters */
    private function games(ReportFilters $filters): Collection
    {
        return $this->applyGameFilters(Game::query()->where('status', GameStatus::Played->value), $filters)->get(['id']);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function compute(ReportFilters $filters): Collection
    {
        $season = (int) $filters->get('season');
        $teamId = $filters->filled('team_id') ? (int) $filters->get('team_id') : null;
        $competitionId = $filters->filled('competition_id') ? (int) $filters->get('competition_id') : null;

        // MatchStats keys by player and sport; a player in two sports has two lines there.
        $stats = collect($this->stats->players($season, $teamId, $competitionId))->groupBy('player_id');

        $list = GamePlayer::query()->whereIn('game_id', $this->games($filters)->modelKeys())
            ->get(['player_id', 'called_up', 'not_called_reason'])->groupBy('player_id');
        $names = Player::query()->whereIn('id', $list->keys())->get(['id', ...Player::NAME_COLUMNS])->keyBy('id');

        return $list->map(function (Collection $lines, $playerId) use ($stats, $names) {
            $s = $stats->get($playerId, collect());
            $reasons = $lines->where('called_up', false)->pluck('not_called_reason')->filter()->countBy()->sortDesc()->keys()->take(3);

            return [
                'name' => $names->get($playerId)?->fullname ?? '',
                'listed' => $lines->count(),
                'called' => $lines->where('called_up', true)->count(),
                'not_called' => $lines->where('called_up', false)->count(),
                'appearances' => (int) $s->sum('appearances'),
                'starts' => (int) $s->sum('starts'),
                'minutes' => (int) $s->sum('minutes'),
                'sets' => (int) $s->sum('sets'),
                'reasons' => $reasons->implode(', '),
            ];
        })->values();
    }
}
