<?php

namespace App\Reports\Players;

use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Reports\Column;
use App\Reports\Concerns\FiltersPlayers;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Support\UiLang;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Headcount per category split by gender, with age bands: the printable twin of the players list charts. */
class PlayerDemographics extends Report
{
    use FiltersPlayers;

    /** Age bands in whole years, upper bound inclusive (null = no bound). */
    private const AGE_BANDS = [[null, 9], [10, 12], [13, 15], [16, 18], [19, 23], [24, null]];

    public function key(): string
    {
        return 'demographics';
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
        return [
            $this->branchFilter(),
            Filter::multi('status_id', 'col.status', array_map(
                fn (array $s) => ['value' => $s['id'], 'label' => $s['name']], PlayerStatus::options())),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('category', 'col.category'),
            new Column('total', 'reports.kpi.players', Column::NUMBER, true),
            new Column('boys', 'reports.kpi.boys', Column::NUMBER, true),
            new Column('girls', 'reports.kpi.girls', Column::NUMBER, true),
            new Column('unknown', 'reports.col.gender_unknown', Column::NUMBER, true),
            new Column('average_age', 'reports.kpi.average_age', Column::NUMBER),
        ];
    }

    public function sortable(): array
    {
        return ['category' => 'category', 'total' => 'total', 'boys' => 'boys', 'girls' => 'girls'];
    }

    public function defaultSort(): array
    {
        return ['total', 'desc'];
    }

    public function landscape(): bool
    {
        return false;
    }

    public function rows(ReportFilters $filters): Collection
    {
        $names = Category::all()->keyBy('id');

        return $this->players($filters)->groupBy('category_id')->map(fn (Collection $group, $categoryId) => [
            'category' => $names->get($categoryId)?->localized_name ?? UiLang::get('reports.none'),
            'total' => $group->count(),
            'boys' => $group->where('gender', 'Male')->count(),
            'girls' => $group->where('gender', 'Female')->count(),
            'unknown' => $group->whereNotIn('gender', ['Male', 'Female'])->count(),
            'average_age' => $this->averageAge($group),
        ])->values();
    }

    public function totals(ReportFilters $filters): array
    {
        $players = $this->players($filters);

        return [
            'total' => $players->count(),
            'boys' => $players->where('gender', 'Male')->count(),
            'girls' => $players->where('gender', 'Female')->count(),
            'unknown' => $players->whereNotIn('gender', ['Male', 'Female'])->count(),
        ];
    }

    public function summary(ReportFilters $filters): array
    {
        $players = $this->players($filters);

        return [
            ['label' => 'reports.kpi.players', 'value' => $players->count(), 'format' => 'number'],
            ['label' => 'reports.kpi.boys', 'value' => $players->where('gender', 'Male')->count(), 'format' => 'number'],
            ['label' => 'reports.kpi.girls', 'value' => $players->where('gender', 'Female')->count(), 'format' => 'number'],
            ['label' => 'reports.kpi.average_age', 'value' => $this->averageAge($players), 'format' => 'number'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        $players = $this->players($filters);
        $ages = $players->pluck('birthdate')->filter()->map(fn ($d) => Carbon::parse($d)->age);

        $bands = array_map(function (array $band) use ($ages) {
            [$min, $max] = $band;
            $label = $min === null ? '≤ '.$max : ($max === null ? $min.'+' : $min.'–'.$max);

            return [
                'key' => $label,
                'label' => $label,
                'count' => $ages->filter(fn (int $a) => ($min === null || $a >= $min) && ($max === null || $a <= $max))->count(),
            ];
        }, self::AGE_BANDS);
        $bands[] = ['key' => 'none', 'label' => UiLang::get('reports.none'), 'count' => $players->whereNull('birthdate')->count()];

        return [
            ['type' => 'bar', 'title' => 'reports.chart.by_age', 'stats' => array_values(array_filter($bands, fn ($b) => $b['count'] > 0))],
            ['type' => 'doughnut', 'title' => 'reports.chart.by_gender', 'stats' => [
                ['key' => 'Male', 'label' => UiLang::get('male', 'Male'), 'count' => $players->where('gender', 'Male')->count()],
                ['key' => 'Female', 'label' => UiLang::get('female', 'Female'), 'count' => $players->where('gender', 'Female')->count()],
            ]],
        ];
    }

    /** @return Collection<int, Player> the filtered, non-archived players (three columns only) */
    private function players(ReportFilters $filters): Collection
    {
        $query = Player::query()->where('archived', false);
        $this->applyCategoryBranch($query, $filters);
        if ($filters->filled('status_id')) {
            $query->whereIn('status_id', $filters->get('status_id'));
        }

        return $query->toBase()->get(['category_id', 'gender', 'birthdate']);
    }

    private function averageAge(Collection $players): ?float
    {
        $ages = $players->pluck('birthdate')->filter()->map(fn ($d) => Carbon::parse($d)->age);

        return $ages->isEmpty() ? null : round($ages->avg(), 1);
    }
}
