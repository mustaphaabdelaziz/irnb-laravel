<?php

namespace App\Reports\Competitions;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;
use App\Reports\Column;
use App\Reports\Concerns\FiltersGames;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Support\UiLang;
use Illuminate\Support\Collection;

/**
 * Every sanction recorded in played matches, with what it counts as under
 * that match's own rules (Game::countsAs): yellow, red or another sanction.
 */
class CompetitionDiscipline extends Report
{
    use FiltersGames;

    /** @var array<string, Collection<int, array<string, mixed>>> */
    private array $memo = [];

    public function key(): string
    {
        return 'discipline';
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
        return 'alert';
    }

    public function filters(): array
    {
        return $this->gameFilters();
    }

    public function columns(): array
    {
        return [
            new Column('date', 'col.date', Column::DATE),
            new Column('team', 'team'),
            new Column('opponent', 'comp.opponent'),
            new Column('player', 'comp.player'),
            new Column('sanction', 'reports.col.sanction'),
            new Column('counts_as', 'reports.col.counts_as'),
            new Column('minute', 'reports.col.minute', Column::NUMBER),
        ];
    }

    public function sortable(): array
    {
        return ['date' => 'date', 'player' => 'player', 'sanction' => 'sanction'];
    }

    public function defaultSort(): array
    {
        return ['date', 'desc'];
    }

    public function landscape(): bool
    {
        return false;
    }

    public function rows(ReportFilters $filters): Collection
    {
        return $this->memo[serialize($filters->values())] ??= $this->compute($filters);
    }

    public function summary(ReportFilters $filters): array
    {
        $count = $this->rows($filters)->countBy('counts_code');

        return [
            ['label' => 'comp.stats.col_yellows', 'value' => (int) ($count['yellow'] ?? 0), 'format' => 'number'],
            ['label' => 'comp.stats.col_reds', 'value' => (int) ($count['red'] ?? 0), 'format' => 'number'],
            ['label' => 'reports.kpi.other_sanctions', 'value' => (int) ($count['other'] ?? 0), 'format' => 'number'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        return [
            ['type' => 'bar', 'title' => 'reports.chart.sanctions_by_player', 'unit' => 'comp.sanctions', 'stats' => $this->rows($filters)
                ->groupBy('player')->map(fn (Collection $g, string $p) => ['key' => $p, 'label' => $p, 'count' => $g->count()])
                ->sortByDesc('count')->take(15)->values()->all()],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function compute(ReportFilters $filters): Collection
    {
        $games = $this->applyGameFilters(Game::query()->where('status', GameStatus::Played->value), $filters)
            ->with(['team:id,name', 'opponent:id,name'])->get()->keyBy('id');
        if ($games->isEmpty()) {
            return collect();
        }

        $events = GameEvent::query()->whereIn('game_id', $games->keys())->where('type', 'sanction')->whereNotNull('player_id')
            ->get(['game_id', 'subtype', 'minute', 'player_id']);
        $names = Player::query()->whereIn('id', $events->pluck('player_id')->unique())->get(['id', ...Player::NAME_COLUMNS])->keyBy('id');

        return $events->map(function (GameEvent $e) use ($games, $names) {
            $game = $games[$e->game_id];
            $counts = $game->countsAs($e->subtype) ?? 'other';

            return [
                'date' => substr((string) $game->date, 0, 10),
                'team' => $game->team?->name,
                'opponent' => $game->opponent?->name,
                'player' => $names[$e->player_id]?->fullname ?? '',
                'sanction' => UiLang::get('comp.sanction.'.$e->subtype, (string) $e->subtype),
                'counts_as' => UiLang::get('reports.counts_as.'.$counts),
                'counts_code' => $counts,
                'minute' => $e->minute,
            ];
        })->values();
    }
}
