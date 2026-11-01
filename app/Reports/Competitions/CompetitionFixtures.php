<?php

namespace App\Reports\Competitions;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Reports\Column;
use App\Reports\Concerns\FiltersGames;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;

/** The match calendar of a season: date, kick-off, team, opponent, venue and status, in date order. */
class CompetitionFixtures extends Report
{
    use FiltersGames;

    public function key(): string
    {
        return 'fixtures';
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
        return 'calendar';
    }

    public function filters(): array
    {
        return [
            ...$this->gameFilters(),
            Filter::multi('status', 'col.status', array_map(
                fn (string $s) => ['value' => $s, 'label' => UiLang::get('comp.status_'.$s, $s)], GameStatus::values())),
            Filter::date('from', 'reports.filter.from'),
            Filter::date('to', 'reports.filter.to'),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('date', 'col.date', Column::DATE),
            new Column('kickoff', 'reports.col.kickoff'),
            new Column('team', 'team'),
            new Column('competition', 'comp.competition'),
            new Column('round', 'comp.round_label'),
            new Column('opponent', 'comp.opponent'),
            new Column('side', 'comp.side'),
            new Column('venue', 'reports.col.venue'),
            new Column('status', 'col.status'),
        ];
    }

    public function sortable(): array
    {
        return ['date' => 'date'];
    }

    public function defaultSort(): array
    {
        return ['date', 'asc'];
    }

    public function rows(ReportFilters $filters): Builder
    {
        return $this->base($filters)->with(['team', 'competition', 'opponent', 'venue']);
    }

    /** @param Game $record */
    public function row(mixed $record): array
    {
        return [
            'date' => substr((string) $record->date, 0, 10),
            'kickoff' => $record->kickoff_time ? substr((string) $record->kickoff_time, 0, 5) : null,
            'team' => $record->team?->name,
            'competition' => $record->competition?->name,
            'round' => $record->round_label,
            'opponent' => $record->opponent?->name,
            'side' => $record->side ? UiLang::get('comp.side_'.$record->side->value) : null,
            'venue' => $record->venue?->name,
            'status' => $record->status ? UiLang::get('comp.status_'.$record->status->value, $record->status->value) : null,
        ];
    }

    public function summary(ReportFilters $filters): array
    {
        $byStatus = $this->base($filters)->toBase()->groupBy('status')->selectRaw('status, COUNT(*) as c')->pluck('c', 'status');

        return array_map(fn (string $s) => [
            'label' => 'comp.status_'.$s, 'value' => (int) ($byStatus[$s] ?? 0), 'format' => 'number',
        ], GameStatus::values());
    }

    /** @return Builder<Game> */
    private function base(ReportFilters $filters): Builder
    {
        $query = $this->applyGameFilters(Game::query(), $filters);

        if ($filters->filled('status')) {
            $query->whereIn('status', $filters->get('status'));
        }
        if ($filters->filled('from')) {
            $query->whereDate('date', '>=', $filters->get('from'));
        }
        if ($filters->filled('to')) {
            $query->whereDate('date', '<=', $filters->get('to'));
        }

        return $query;
    }
}
