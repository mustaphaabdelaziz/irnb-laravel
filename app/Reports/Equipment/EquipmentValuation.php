<?php

namespace App\Reports\Equipment;

use App\Models\Branch;
use App\Models\EquipmentItem;
use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Services\Equipment\EquipmentStockService;
use App\Support\UiLang;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Units and value per equipment category, with the Inventory page's rules:
 * units out come from open loans, blocked statuses are never available, and
 * lost or retired units carry no value (quantity × lot price, else the
 * catalog's reference price).
 */
class EquipmentValuation extends Report
{
    private const NO_VALUE = ['Lost', 'Retired'];

    /** @var array<string, Collection<int, array<string, mixed>>> */
    private array $memo = [];

    public function key(): string
    {
        return 'valuation';
    }

    public function category(): string
    {
        return 'equipment';
    }

    public function module(): string
    {
        return 'equipment';
    }

    public function icon(): string
    {
        return 'money';
    }

    public function filters(): array
    {
        return [
            Filter::select('branch_id', 'reports.filter.branch', Branch::orderBy('name')->get()
                ->map(fn (Branch $b) => ['value' => $b->id, 'label' => $b->localized_name])->all()),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('category', 'col.category'),
            new Column('units', 'reports.kpi.units', Column::NUMBER, true),
            new Column('available', 'available', Column::NUMBER, true),
            new Column('out', 'reports.col.quantity_out', Column::NUMBER, true),
            new Column('under_repair', 'reports.col.under_repair', Column::NUMBER, true),
            new Column('lost_retired', 'reports.col.lost_retired', Column::NUMBER, true),
            new Column('value', 'reports.kpi.asset_value', Column::MONEY, true),
        ];
    }

    public function sortable(): array
    {
        return ['category' => 'category', 'units' => 'units', 'value' => 'value'];
    }

    public function defaultSort(): array
    {
        return ['value', 'desc'];
    }

    public function landscape(): bool
    {
        return false;
    }

    public function rows(ReportFilters $filters): Collection
    {
        return $this->memo[serialize($filters->values())] ??= $this->compute($filters);
    }

    public function totals(ReportFilters $filters): array
    {
        $rows = $this->rows($filters);

        return collect(['units', 'available', 'out', 'under_repair', 'lost_retired', 'value'])
            ->mapWithKeys(fn (string $k) => [$k => $k === 'value' ? round($rows->sum($k), 2) : (int) $rows->sum($k)])->all();
    }

    public function summary(ReportFilters $filters): array
    {
        $t = $this->totals($filters);

        return [
            ['label' => 'reports.kpi.units', 'value' => $t['units'], 'format' => 'number'],
            ['label' => 'available', 'value' => $t['available'], 'format' => 'number'],
            ['label' => 'reports.col.quantity_out', 'value' => $t['out'], 'format' => 'number'],
            ['label' => 'reports.kpi.asset_value', 'value' => $t['value'], 'format' => 'money'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        return [
            ['type' => 'bar', 'title' => 'reports.chart.value_by_category', 'unit' => 'money', 'stats' => $this->rows($filters)
                ->sortByDesc('value')->map(fn (array $r) => ['key' => $r['category'], 'label' => $r['category'], 'count' => $r['value']])->values()->all()],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function compute(ReportFilters $filters): Collection
    {
        $blocked = "'".implode("','", EquipmentStockService::BLOCKING_STATUSES)."'";
        $noValue = "'".implode("','", self::NO_VALUE)."'";

        $query = DB::table('equipment_items')
            ->join('equipment_catalogs', 'equipment_catalogs.id', '=', 'equipment_items.catalog_id')
            ->leftJoin(DB::raw('(SELECT equipment_item_id, SUM(quantity - returned_quantity) AS out_units
                FROM equipment_rentals WHERE return_date IS NULL GROUP BY equipment_item_id) rentals_out'),
                'rentals_out.equipment_item_id', '=', 'equipment_items.id')
            ->groupBy('equipment_catalogs.category')
            ->selectRaw("equipment_catalogs.category as category,
                SUM(equipment_items.quantity) as units,
                SUM(CASE WHEN equipment_items.status IN ({$blocked}) THEN 0
                    ELSE equipment_items.quantity - COALESCE(rentals_out.out_units, 0) END) as available,
                COALESCE(SUM(rentals_out.out_units), 0) as out_units,
                SUM(CASE WHEN equipment_items.status = 'Under Repair' THEN equipment_items.quantity ELSE 0 END) as under_repair,
                SUM(CASE WHEN equipment_items.status IN ({$noValue}) THEN equipment_items.quantity ELSE 0 END) as lost_retired,
                SUM(CASE WHEN equipment_items.status IN ({$noValue}) THEN 0
                    ELSE equipment_items.quantity * COALESCE(equipment_items.unit_price, equipment_catalogs.purchase_price, 0) END) as value");

        // Lots usable by the branch: tagged with it, plus club-wide (untagged) lots.
        if ($filters->filled('branch_id')) {
            $query->whereIn('equipment_items.id', EquipmentItem::query()->forBranch($filters->get('branch_id'))->select('id'));
        }

        return $query->get()->map(fn ($r) => [
            'category' => $r->category ?: UiLang::get('reports.none'),
            'units' => (int) $r->units,
            'available' => (int) $r->available,
            'out' => (int) $r->out_units,
            'under_repair' => (int) $r->under_repair,
            'lost_retired' => (int) $r->lost_retired,
            'value' => round((float) $r->value, 2),
        ]);
    }
}
