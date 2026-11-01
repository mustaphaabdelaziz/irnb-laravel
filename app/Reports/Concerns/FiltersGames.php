<?php

namespace App\Reports\Concerns;

use App\Models\Competition;
use App\Models\Team;
use App\Reports\Filter;
use App\Reports\ReportFilters;
use App\Support\Season;
use Illuminate\Database\Eloquent\Builder;

/** Season, team and competition filters shared by the competition reports. */
trait FiltersGames
{
    /** @return list<Filter> */
    protected function gameFilters(): array
    {
        return [
            Filter::select('season', 'reports.filter.season', Season::options(), Season::current()->startYear),
            Filter::select('team_id', 'team', Team::query()->with('category')->orderBy('name')->get()
                ->map(fn (Team $t) => ['value' => $t->id, 'label' => trim($t->name.' · '.$t->category?->localized_name, ' ·')])->all()),
            Filter::select('competition_id', 'comp.competition', Competition::query()->orderByDesc('season_start_year')->orderBy('name')->get()
                ->map(fn (Competition $c) => ['value' => $c->id, 'label' => $c->name.' ('.Season::forStartYear($c->season_start_year)->label().')'])->all()),
        ];
    }

    /** Narrows a games query (or one joined to `games` under $table) by season, team and competition. */
    protected function applyGameFilters(Builder $query, ReportFilters $filters, string $table = 'games'): Builder
    {
        $query->where($table.'.season_start_year', $filters->get('season'));
        if ($filters->filled('team_id')) {
            $query->where($table.'.team_id', $filters->get('team_id'));
        }
        if ($filters->filled('competition_id')) {
            $query->where($table.'.competition_id', $filters->get('competition_id'));
        }

        return $query;
    }
}
