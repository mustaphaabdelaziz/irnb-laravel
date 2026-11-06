<?php

namespace App\Reports\Players;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Reports\Column;
use App\Reports\Concerns\FiltersPlayers;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Services\Player\RegistrationCounts;
use App\Services\Player\RegistrationDisplay;
use App\Support\CategoryLabels;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Every player matching the filters, with identity, contact and club details. */
class PlayerDirectory extends Report
{
    use FiltersPlayers;

    /** Set by rows(): the runner calls row() on this same instance. */
    private ?int $rowBranch = null;

    private ?RegistrationDisplay $display = null;

    public function key(): string
    {
        return 'directory';
    }

    public function category(): string
    {
        return 'players';
    }

    public function module(): string
    {
        return 'players';
    }

    public function icon(): string
    {
        return 'players';
    }

    public function filters(): array
    {
        $joinYears = Player::query()->whereNotNull('join_year')->distinct()->orderByDesc('join_year')->pluck('join_year');

        return [
            Filter::multi('category_id', 'col.category', CategoryLabels::make()->options()),
            Filter::multi('branch_id', 'col.branches', Branch::orderBy('name')->get()
                ->map(fn (Branch $b) => ['value' => $b->id, 'label' => $b->localized_name])->all()),
            Filter::select('gender', 'col.gender', [
                ['value' => 'Male', 'label' => UiLang::get('male', 'Male')],
                ['value' => 'Female', 'label' => UiLang::get('female', 'Female')],
            ]),
            Filter::multi('status_id', 'col.status', array_map(
                fn (array $s) => ['value' => $s['id'], 'label' => $s['name']], PlayerStatus::options())),
            Filter::number('age_min', 'reports.filter.age_min', 0, 99),
            Filter::number('age_max', 'reports.filter.age_max', 0, 99),
            Filter::select('join_year', 'col.join_year', $joinYears
                ->map(fn ($y) => ['value' => (int) $y, 'label' => (string) $y])->all()),
            Filter::select('archived', 'reports.filter.archived', [
                ['value' => 0, 'label' => UiLang::get('reports.filter.active_only')],
                ['value' => 1, 'label' => UiLang::get('reports.filter.archived_only')],
            ], 0),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('membership_id', 'col.membership_id'),
            new Column('name', 'col.full_name'),
            new Column('birthdate', 'col.birthdate', Column::DATE),
            new Column('age', 'reports.col.age', Column::NUMBER),
            new Column('gender', 'col.gender'),
            new Column('phone', 'col.phone'),
            new Column('category', 'col.category'),
            new Column('branches', 'col.branches'),
            new Column('status', 'col.status'),
            new Column('join_year', 'col.join_year', Column::NUMBER),
        ];
    }

    public function sortable(): array
    {
        return [
            'membership_id' => 'membership_id',
            'name' => 'lastname',
            'birthdate' => 'birthdate',
            'join_year' => 'join_year',
        ];
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function landscape(): bool
    {
        return true;
    }

    public function note(ReportFilters $filters): ?string
    {
        return $this->overlapNote($filters);
    }

    public function rows(ReportFilters $filters): Builder
    {
        $this->rowBranch = $this->shownBranch($filters);
        $this->display = RegistrationDisplay::make();

        return $this->base($filters)
            ->with(['category', 'status', 'branches'])
            ->when($this->rowBranch !== null, fn ($q) => $q->with(['registrations.category', 'registrations.status']));
    }

    /** @param Player $record */
    public function row(mixed $record): array
    {
        $this->display->overlay($record, $this->rowBranch);

        return [
            'membership_id' => $record->membership_id,
            'name' => $record->fullname,
            'birthdate' => $record->birthdate?->format('Y-m-d'),
            'age' => $record->birthdate?->age,
            'gender' => $record->gender ? UiLang::get(strtolower($record->gender), $record->gender) : null,
            'phone' => collect($record->phones ?? [])->filter()->first(),
            'category' => $record->category?->localized_name,
            'branches' => $record->branches->map(fn (Branch $b) => $b->localized_name)->implode(', '),
            'status' => $record->status?->localized_name,
            'join_year' => $record->join_year,
        ];
    }

    public function summary(ReportFilters $filters): array
    {
        $row = $this->base($filters)->toBase()->selectRaw(
            "COUNT(*) as total, SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END) as boys, SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END) as girls"
        )->first();
        $birthdates = $this->base($filters)->whereNotNull('birthdate')->pluck('birthdate');
        $averageAge = $birthdates->isEmpty() ? null : round($birthdates->avg(fn ($d) => Carbon::parse($d)->age), 1);

        return [
            ['label' => 'reports.kpi.players', 'value' => (int) $row->total, 'format' => 'number'],
            ['label' => 'reports.kpi.boys', 'value' => (int) $row->boys, 'format' => 'number'],
            ['label' => 'reports.kpi.girls', 'value' => (int) $row->girls, 'format' => 'number'],
            ['label' => 'reports.kpi.average_age', 'value' => $averageAge, 'format' => 'number'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        $byCategory = RegistrationCounts::byCategory(
            $this->base($filters),
            $this->listFilters($filters)['branches'],
            $this->includesLeft($filters),
            $this->listFilters($filters)['categories'],
        );

        $byGender = $this->base($filters)->toBase()
            ->groupBy('gender')->selectRaw('gender, COUNT(*) as c')->orderByDesc('c')->get();

        return [
            ['type' => 'bar', 'title' => 'reports.chart.by_category', 'stats' => $byCategory->map(fn (object $r) => [
                'key' => (int) $r->key,
                'label' => $r->name ?? UiLang::get('reports.none'),
                'count' => $r->total,
            ])->values()->all()],
            ['type' => 'doughnut', 'title' => 'reports.chart.by_gender', 'stats' => $byGender->map(fn ($r) => [
                'key' => (string) ($r->gender ?? 'none'),
                'label' => $r->gender ? UiLang::get(strtolower($r->gender), $r->gender) : UiLang::get('reports.none'),
                'count' => (int) $r->c,
            ])->all()],
        ];
    }

    /** @return Builder<Player> */
    private function base(ReportFilters $filters): Builder
    {
        $query = Player::query()->where('archived', (bool) $filters->get('archived'));

        $query->listedWhere(
            ...$this->listFilters($filters),
            statuses: $filters->filled('status_id') ? (array) $filters->get('status_id') : null,
        );
        if ($filters->filled('gender')) {
            $query->where('gender', $filters->get('gender'));
        }
        if ($filters->filled('join_year')) {
            $query->where('join_year', $filters->get('join_year'));
        }
        // Age in whole years today: age_min..age_max inclusive.
        if ($filters->filled('age_min')) {
            $query->whereDate('birthdate', '<=', today()->subYears($filters->get('age_min')));
        }
        if ($filters->filled('age_max')) {
            $query->whereDate('birthdate', '>', today()->subYears($filters->get('age_max') + 1));
        }

        return $query;
    }
}
