<?php

namespace App\Reports\Competitions;

use App\Models\Competition;
use App\Models\Suspension;
use App\Models\Team;
use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Services\Competitions\SuspensionStatus;
use App\Support\Season;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Every suspension, automatic or entered by hand, with matches served and
 * remaining and whether it is in force — evaluated by SuspensionStatus, as
 * on the competition page.
 */
class CompetitionSuspensions extends Report
{
    /** @var array<string, Collection<int, array<string, mixed>>> */
    private array $memo = [];

    public function __construct(private readonly SuspensionStatus $status) {}

    public function key(): string
    {
        return 'suspensions';
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
        return 'xcircle';
    }

    public function filters(): array
    {
        return [
            Filter::select('team_id', 'team', Team::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Team $t) => ['value' => $t->id, 'label' => $t->name])->all()),
            Filter::select('competition_id', 'comp.competition', Competition::query()->orderByDesc('season_start_year')->orderBy('name')->get()
                ->map(fn (Competition $c) => ['value' => $c->id, 'label' => $c->name.' ('.Season::forStartYear($c->season_start_year)->label().')'])->all()),
            Filter::select('active', 'comp.ban.active', [['value' => 1, 'label' => UiLang::get('reports.filter.active_bans')]]),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('player', 'comp.player'),
            new Column('scope', 'comp.ban.scope'),
            new Column('cause', 'comp.ban.cause'),
            new Column('length', 'comp.ban.length'),
            new Column('served', 'comp.ban.served', Column::NUMBER),
            new Column('remaining', 'reports.col.remaining_matches', Column::NUMBER),
            new Column('until', 'comp.ban.ends_on', Column::DATE),
            new Column('active', 'comp.ban.active'),
        ];
    }

    public function sortable(): array
    {
        return ['player' => 'player', 'remaining' => 'remaining', 'active' => 'active_flag'];
    }

    public function defaultSort(): array
    {
        return ['active', 'desc'];
    }

    public function rows(ReportFilters $filters): Collection
    {
        return $this->memo[serialize($filters->values())] ??= $this->compute($filters);
    }

    public function summary(ReportFilters $filters): array
    {
        $rows = $this->rows($filters);

        return [
            ['label' => 'reports.kpi.suspensions', 'value' => $rows->count(), 'format' => 'number'],
            ['label' => 'comp.ban.active', 'value' => $rows->where('active_flag', true)->count(), 'format' => 'number'],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function compute(ReportFilters $filters): Collection
    {
        $list = Suspension::query()
            ->when($filters->filled('team_id'), fn (Builder $q) => $q->where('team_id', $filters->get('team_id')))
            ->when($filters->filled('competition_id'), fn (Builder $q) => $q->where('competition_id', $filters->get('competition_id')))
            ->with(['player', 'competition:id,name'])->orderByDesc('id')->get();
        $eval = $this->status->evaluate($list);

        return $list->map(function (Suspension $s) use ($eval) {
            $e = $eval[$s->id];
            $length = $s->kind === 'dates'
                ? trim(substr((string) $s->starts_on, 0, 10).' → '.substr((string) $s->ends_on, 0, 10))
                : str_replace('{n}', (string) $s->matches_count, UiLang::get('reports.ban_matches'));

            return [
                'player' => $s->player?->fullname,
                'scope' => $s->competition?->name ?? UiLang::get('comp.ban.all_competitions'),
                'cause' => $s->source === 'auto' && $s->cause ? UiLang::get('comp.flag.cause_'.$s->cause, $s->cause) : ($s->reason ?: UiLang::get('comp.ban.manual')),
                'length' => $length,
                'served' => $e['served'],
                'remaining' => $e['remaining'],
                'until' => $e['until'] ? substr((string) $e['until'], 0, 10) : null,
                'active' => UiLang::get($e['active'] ? 'reports.yes' : 'reports.no'),
                'active_flag' => $e['active'],
            ];
        })
            ->when($filters->filled('active'), fn (Collection $rows) => $rows->where('active_flag', true))
            ->values();
    }
}
