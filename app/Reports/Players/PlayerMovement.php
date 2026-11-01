<?php

namespace App\Reports\Players;

use App\Models\Branch;
use App\Models\Player;
use App\Reports\Column;
use App\Reports\Concerns\FiltersPlayers;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who joined and who left. Joins follow the join year on the player record
 * (a year, not a date); departures follow the date the player left.
 */
class PlayerMovement extends Report
{
    use FiltersPlayers;

    public function key(): string
    {
        return 'movement';
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
        $joinYears = Player::query()->whereNotNull('join_year')->distinct()->pluck('join_year')->map(fn ($y) => (int) $y);
        $leftYears = Player::query()->whereNotNull('left_at')->pluck('left_at')->map(fn ($d) => (int) substr((string) $d, 0, 4));
        $years = $joinYears->merge($leftYears)->push((int) now()->year)->unique()->sortDesc()->values();

        return [
            Filter::select('movement', 'reports.filter.movement', [
                ['value' => 'joined', 'label' => UiLang::get('reports.movement.joined')],
                ['value' => 'left', 'label' => UiLang::get('reports.movement.left')],
            ], 'joined'),
            Filter::select('year', 'reports.filter.year', $years->map(fn (int $y) => ['value' => $y, 'label' => (string) $y])->all(), (int) now()->year),
            $this->categoryFilter(),
            $this->branchFilter(),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('membership_id', 'col.membership_id'),
            new Column('name', 'col.full_name'),
            new Column('category', 'col.category'),
            new Column('branches', 'col.branches'),
            new Column('join_year', 'col.join_year', Column::NUMBER),
            new Column('left_at', 'reports.col.left_at', Column::DATE),
            new Column('status', 'col.status'),
        ];
    }

    public function sortable(): array
    {
        return ['membership_id' => 'membership_id', 'name' => 'lastname', 'join_year' => 'join_year', 'left_at' => 'left_at'];
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function rows(ReportFilters $filters): Builder
    {
        return $this->base($filters, (string) $filters->get('movement'))->with(['category', 'status', 'branches']);
    }

    /** @param Player $record */
    public function row(mixed $record): array
    {
        return [
            'membership_id' => $record->membership_id,
            'name' => $record->fullname,
            'category' => $record->category?->localized_name,
            'branches' => $record->branches->map(fn (Branch $b) => $b->localized_name)->implode(', '),
            'join_year' => $record->join_year,
            'left_at' => $record->left_at?->format('Y-m-d'),
            'status' => $record->status?->localized_name,
        ];
    }

    public function summary(ReportFilters $filters): array
    {
        return [
            ['label' => 'reports.movement.joined', 'value' => $this->base($filters, 'joined')->count(), 'format' => 'number'],
            ['label' => 'reports.movement.left', 'value' => $this->base($filters, 'left')->count(), 'format' => 'number'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        $joined = $this->applyCategoryBranch(Player::query()->whereNotNull('join_year'), $filters)->toBase()
            ->groupBy('join_year')->orderBy('join_year')->selectRaw('join_year as y, COUNT(*) as c')->get();
        $left = $this->applyCategoryBranch(Player::query()->whereNotNull('left_at'), $filters)->toBase()
            ->selectRaw('SUBSTR(left_at, 1, 4) as y, COUNT(*) as c')->groupBy('y')->orderBy('y')->get();

        $series = fn ($rows) => $rows->map(fn ($r) => ['key' => (string) $r->y, 'label' => (string) $r->y, 'count' => (int) $r->c])->take(-10)->values()->all();

        return [
            ['type' => 'bar', 'title' => 'reports.chart.joins_by_year', 'stats' => $series($joined)],
            ['type' => 'bar', 'title' => 'reports.chart.leavers_by_year', 'stats' => $series($left)],
        ];
    }

    /** @return Builder<Player> */
    private function base(ReportFilters $filters, string $movement): Builder
    {
        $year = (int) $filters->get('year');
        $query = $movement === 'left'
            ? Player::query()->whereDate('left_at', '>=', $year.'-01-01')->whereDate('left_at', '<', ($year + 1).'-01-01')
            : Player::query()->where('join_year', $year);

        return $this->applyCategoryBranch($query, $filters);
    }
}
