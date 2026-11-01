<?php

namespace App\Reports\Competitions;

use App\Enums\GameSide;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Reports\Column;
use App\Reports\Concerns\FiltersGames;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Services\Competitions\CompetitionRecord;
use App\Support\Season;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;

/** Every played match of a season with its score, and our W/D/L record over them (no league table). */
class CompetitionResults extends Report
{
    use FiltersGames;

    public function key(): string
    {
        return 'results';
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
        return 'flag';
    }

    public function filters(): array
    {
        return [
            ...$this->gameFilters(),
            Filter::select('side', 'comp.side', array_map(
                fn (string $s) => ['value' => $s, 'label' => UiLang::get('comp.side_'.$s)], GameSide::values())),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('date', 'col.date', Column::DATE),
            new Column('team', 'team'),
            new Column('competition', 'comp.competition'),
            new Column('round', 'comp.round_label'),
            new Column('opponent', 'comp.opponent'),
            new Column('side', 'comp.side'),
            new Column('score', 'reports.col.score'),
            new Column('result', 'reports.col.result'),
        ];
    }

    public function sortable(): array
    {
        return ['date' => 'date'];
    }

    public function defaultSort(): array
    {
        return ['date', 'desc'];
    }

    public function rows(ReportFilters $filters): Builder
    {
        return $this->base($filters)->with(['team', 'competition', 'opponent']);
    }

    /** @param Game $record */
    public function row(mixed $record): array
    {
        $outcome = $record->our_score <=> $record->their_score;
        $score = $record->our_score.' - '.$record->their_score;
        if ($record->shootout_us !== null && $record->shootout_them !== null) {
            $score .= ' ('.$record->shootout_us.' - '.$record->shootout_them.')';
        }

        return [
            'date' => substr((string) $record->date, 0, 10),
            'team' => $record->team?->name,
            'competition' => $record->competition?->name,
            'round' => $record->round_label,
            'opponent' => $record->opponent?->name,
            'side' => $record->side ? UiLang::get('comp.side_'.$record->side->value) : null,
            'score' => $score,
            'result' => UiLang::get(match ($outcome) {
                1 => 'reports.result.win', 0 => 'reports.result.draw', default => 'reports.result.loss'
            }),
        ];
    }

    public function summary(ReportFilters $filters): array
    {
        $record = CompetitionRecord::fromGames($this->base($filters)->get(['id', 'date', 'status', 'our_score', 'their_score']));

        return [
            ['label' => 'reports.kpi.played', 'value' => $record['played'], 'format' => 'number'],
            ['label' => 'reports.kpi.wins', 'value' => $record['wins'], 'format' => 'number'],
            ['label' => 'reports.kpi.draws', 'value' => $record['draws'], 'format' => 'number'],
            ['label' => 'reports.kpi.losses', 'value' => $record['losses'], 'format' => 'number'],
            ['label' => 'reports.kpi.goals', 'value' => $record['goals_for'].' - '.$record['goals_against'], 'format' => 'text'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        $record = CompetitionRecord::fromGames($this->base($filters)->get(['id', 'date', 'status', 'our_score', 'their_score']));

        return [
            ['type' => 'doughnut', 'title' => 'reports.chart.results', 'stats' => [
                ['key' => 'win', 'label' => UiLang::get('reports.result.win'), 'count' => $record['wins'], 'color' => '#16a34a'],
                ['key' => 'draw', 'label' => UiLang::get('reports.result.draw'), 'count' => $record['draws'], 'color' => '#94a3b8'],
                ['key' => 'loss', 'label' => UiLang::get('reports.result.loss'), 'count' => $record['losses'], 'color' => '#dc2626'],
            ], 'unit' => 'reports.kpi.played'],
        ];
    }

    /**
     * Played matches with a score: the same set CompetitionRecord counts.
     *
     * @return Builder<Game>
     */
    private function base(ReportFilters $filters): Builder
    {
        $query = Game::query()
            ->where('status', GameStatus::Played->value)
            ->whereNotNull('our_score')->whereNotNull('their_score');
        $this->applyGameFilters($query, $filters);

        if ($filters->filled('side')) {
            $query->where('side', $filters->get('side'));
        }

        return $query;
    }
}
