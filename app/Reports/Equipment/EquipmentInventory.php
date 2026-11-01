<?php

namespace App\Reports\Equipment;

use App\Models\Branch;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every equipment lot across catalogs. Units and asset value follow the
 * Inventory page exactly: value = quantity × the lot's unit price, else the
 * catalog's reference price, and lost/retired lots carry no value. Units out
 * come from open rentals and open assignments, never added together.
 */
class EquipmentInventory extends Report
{
    /** Statuses that carry no asset value (EquipmentItemController::inventory). */
    private const NO_VALUE = ['Lost', 'Retired'];

    public function key(): string
    {
        return 'inventory';
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
        return 'equipment';
    }

    public function filters(): array
    {
        $distinct = fn (string $column) => EquipmentItem::query()->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column);

        return [
            Filter::multi('category', 'col.category', EquipmentCategory::query()->orderBy('name')->pluck('name')
                ->map(fn (string $n) => ['value' => $n, 'label' => $n])->all()),
            Filter::multi('status', 'col.status', $distinct('status')
                ->map(fn (string $s) => ['value' => $s, 'label' => EquipmentItem::stateLabel($s)])->all()),
            Filter::multi('condition', 'col.condition', $distinct('condition')
                ->map(fn (string $c) => ['value' => $c, 'label' => EquipmentItem::stateLabel($c)])->all()),
            Filter::multi('location', 'col.location', $distinct('location')
                ->map(fn (string $l) => ['value' => $l, 'label' => $l])->all()),
            Filter::select('branch_id', 'reports.filter.branch', Branch::orderBy('name')->get()
                ->map(fn (Branch $b) => ['value' => $b->id, 'label' => $b->localized_name])->all()),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('catalog', 'col.catalog'),
            new Column('category', 'col.category'),
            new Column('designation', 'col.designation'),
            new Column('serial', 'reports.col.serial'),
            new Column('quantity', 'reports.col.quantity', Column::NUMBER, true),
            new Column('condition', 'col.condition'),
            new Column('status', 'col.status'),
            new Column('location', 'col.location'),
            new Column('branches', 'col.branches'),
            new Column('unit_price', 'reports.col.unit_price', Column::MONEY),
            new Column('value', 'reports.col.value', Column::MONEY),
            new Column('purchase_date', 'reports.col.purchase_date', Column::DATE),
        ];
    }

    public function sortable(): array
    {
        return [
            'catalog' => 'equipment_catalogs.name',
            'category' => 'equipment_catalogs.category',
            'quantity' => 'equipment_items.quantity',
            'status' => 'equipment_items.status',
            'purchase_date' => 'equipment_items.purchase_date',
        ];
    }

    public function defaultSort(): array
    {
        return ['catalog', 'asc'];
    }

    public function rows(ReportFilters $filters): Builder
    {
        return $this->base($filters)
            ->select('equipment_items.*', 'equipment_catalogs.name as catalog_name', 'equipment_catalogs.category as catalog_category', 'equipment_catalogs.purchase_price as catalog_price')
            ->with('branches');
    }

    /** @param EquipmentItem $record */
    public function row(mixed $record): array
    {
        $price = $record->unit_price ?? $record->catalog_price;

        return [
            'catalog' => $record->catalog_name,
            'category' => $record->catalog_category,
            'designation' => $record->designation,
            'serial' => $record->unique_identifier,
            'quantity' => (int) $record->quantity,
            'condition' => EquipmentItem::stateLabel($record->condition),
            'status' => EquipmentItem::stateLabel($record->status),
            'location' => $record->location,
            'branches' => $record->branches->isEmpty()
                ? UiLang::get('reports.club_wide')
                : $record->branches->map(fn (Branch $b) => $b->localized_name)->implode(', '),
            'unit_price' => $price !== null ? (float) $price : null,
            'value' => in_array($record->status, self::NO_VALUE, true) ? 0.0 : round((int) $record->quantity * (float) ($price ?? 0), 2),
            'purchase_date' => $record->purchase_date ? substr((string) $record->purchase_date, 0, 10) : null,
        ];
    }

    public function totals(ReportFilters $filters): array
    {
        return ['quantity' => (int) $this->base($filters)->sum('equipment_items.quantity')];
    }

    public function summary(ReportFilters $filters): array
    {
        $lots = $this->base($filters)->count();
        $units = (int) $this->base($filters)->sum('equipment_items.quantity');
        $value = (float) $this->base($filters)->toBase()
            ->whereNotIn('equipment_items.status', self::NO_VALUE)
            ->selectRaw('COALESCE(SUM(equipment_items.quantity * COALESCE(equipment_items.unit_price, equipment_catalogs.purchase_price, 0)), 0) as v')
            ->value('v');

        $out = EquipmentRental::query()
            ->whereNull('return_date')
            ->whereIn('equipment_item_id', $this->base($filters)->select('equipment_items.id'))
            ->groupBy('type')
            ->selectRaw('type, COALESCE(SUM(quantity - returned_quantity), 0) as units')
            ->pluck('units', 'type');

        return [
            ['label' => 'reports.kpi.lots', 'value' => $lots, 'format' => 'number'],
            ['label' => 'reports.kpi.units', 'value' => $units, 'format' => 'number'],
            ['label' => 'reports.kpi.units_rented', 'value' => (int) ($out['rental'] ?? 0), 'format' => 'number'],
            ['label' => 'reports.kpi.units_assigned', 'value' => (int) ($out['assignment'] ?? 0), 'format' => 'number'],
            ['label' => 'reports.kpi.asset_value', 'value' => round($value, 2), 'format' => 'money'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        $byStatus = $this->base($filters)->toBase()->groupBy('equipment_items.status')
            ->selectRaw('equipment_items.status as s, SUM(equipment_items.quantity) as c')->orderByDesc('c')->get();
        $byCategory = $this->base($filters)->toBase()->groupBy('equipment_catalogs.category')
            ->selectRaw('equipment_catalogs.category as s, SUM(equipment_items.quantity) as c')->orderByDesc('c')->get();

        return [
            ['type' => 'doughnut', 'title' => 'reports.chart.units_by_status', 'stats' => $byStatus->map(fn ($r) => [
                'key' => (string) $r->s, 'label' => EquipmentItem::stateLabel($r->s) ?? UiLang::get('reports.none'), 'count' => (int) $r->c,
            ])->all(), 'unit' => 'reports.kpi.units'],
            ['type' => 'bar', 'title' => 'reports.chart.units_by_category', 'stats' => $byCategory->map(fn ($r) => [
                'key' => (string) $r->s, 'label' => $r->s ?: UiLang::get('reports.none'), 'count' => (int) $r->c,
            ])->all(), 'unit' => 'reports.kpi.units'],
        ];
    }

    /** @return Builder<EquipmentItem> */
    private function base(ReportFilters $filters): Builder
    {
        $query = EquipmentItem::query()
            ->join('equipment_catalogs', 'equipment_catalogs.id', '=', 'equipment_items.catalog_id');

        if ($filters->filled('category')) {
            $query->whereIn('equipment_catalogs.category', $filters->get('category'));
        }
        foreach (['status', 'condition', 'location'] as $column) {
            if ($filters->filled($column)) {
                $query->whereIn('equipment_items.'.$column, $filters->get($column));
            }
        }
        // Lots usable by the branch: tagged with it, plus club-wide (untagged) lots.
        if ($filters->filled('branch_id')) {
            $query->forBranch($filters->get('branch_id'));
        }

        return $query;
    }
}
