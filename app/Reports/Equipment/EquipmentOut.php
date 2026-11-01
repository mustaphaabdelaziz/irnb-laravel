<?php

namespace App\Reports\Equipment;

use App\Models\EquipmentCategory;
use App\Models\EquipmentRental;
use App\Models\Player;
use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Everything currently out: rentals and work assignments, never mixed in a
 * total. Only a rental can run late — an assignment is open-ended
 * (EquipmentRental::is_overdue).
 */
class EquipmentOut extends Report
{
    public function key(): string
    {
        return 'out';
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
        return 'box';
    }

    public function filters(): array
    {
        return [
            Filter::select('type', 'col.type', [
                ['value' => 'rental', 'label' => UiLang::get('rented')],
                ['value' => 'assignment', 'label' => UiLang::get('equipment.assigned_badge')],
            ]),
            Filter::select('overdue', 'reports.filter.overdue', [['value' => 1, 'label' => UiLang::get('reports.filter.overdue_only')]]),
            Filter::multi('category', 'col.category', EquipmentCategory::query()->orderBy('name')->pluck('name')
                ->map(fn (string $n) => ['value' => $n, 'label' => $n])->all()),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('holder', 'reports.col.holder'),
            new Column('phone', 'col.phone'),
            new Column('type', 'col.type'),
            new Column('catalog', 'col.catalog'),
            new Column('item', 'col.item'),
            new Column('quantity', 'reports.col.quantity_out', Column::NUMBER, true),
            new Column('checkout_date', 'reports.col.checkout_date', Column::DATE),
            new Column('due_date', 'col.due_date', Column::DATE),
            new Column('days_overdue', 'reports.col.days_overdue', Column::NUMBER),
        ];
    }

    public function sortable(): array
    {
        return [
            'checkout_date' => 'equipment_rentals.checkout_date',
            'due_date' => 'equipment_rentals.due_date',
            'quantity' => '(equipment_rentals.quantity - equipment_rentals.returned_quantity)',
            'catalog' => 'equipment_catalogs.name',
        ];
    }

    public function defaultSort(): array
    {
        return ['checkout_date', 'asc'];
    }

    public function rows(ReportFilters $filters): Builder
    {
        return $this->base($filters)->select('equipment_rentals.*', 'equipment_catalogs.name as catalog_name')
            ->with(['rentable', 'equipmentItem']);
    }

    /** @param EquipmentRental $record */
    public function row(mixed $record): array
    {
        $holder = $record->rentable;
        $phone = $record->external_phone ?: ($holder instanceof Player ? collect($holder->phones ?? [])->filter()->first() : null);

        return [
            'holder' => $record->recipient_name,
            'phone' => $phone,
            'type' => $record->type === 'assignment' ? UiLang::get('equipment.assigned_badge') : UiLang::get('rented'),
            'catalog' => $record->catalog_name,
            'item' => $record->equipmentItem?->designation ?: $record->equipmentItem?->unique_identifier,
            'quantity' => $record->outstanding_quantity,
            'checkout_date' => $record->checkout_date?->format('Y-m-d'),
            'due_date' => $record->due_date?->format('Y-m-d'),
            'days_overdue' => $record->is_overdue ? (int) $record->due_date->diffInDays(today(), true) : null,
        ];
    }

    public function totals(ReportFilters $filters): array
    {
        return ['quantity' => (int) $this->base($filters)->sum(DB::raw('equipment_rentals.quantity - equipment_rentals.returned_quantity'))];
    }

    public function summary(ReportFilters $filters): array
    {
        $units = $this->base($filters)->groupBy('equipment_rentals.type')
            ->selectRaw('equipment_rentals.type as type, SUM(equipment_rentals.quantity - equipment_rentals.returned_quantity) as units')
            ->pluck('units', 'type');

        return [
            ['label' => 'reports.kpi.units_rented', 'value' => (int) ($units['rental'] ?? 0), 'format' => 'number'],
            ['label' => 'reports.kpi.units_assigned', 'value' => (int) ($units['assignment'] ?? 0), 'format' => 'number'],
            ['label' => 'reports.kpi.overdue_rentals', 'value' => $this->overdue($this->base($filters))->count(), 'format' => 'number'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        $byCategory = $this->base($filters)->groupBy('equipment_catalogs.category')
            ->selectRaw('equipment_catalogs.category as c, SUM(equipment_rentals.quantity - equipment_rentals.returned_quantity) as units')
            ->orderByDesc('units')->get();

        return [
            ['type' => 'bar', 'title' => 'reports.chart.units_by_category', 'unit' => 'reports.kpi.units', 'stats' => $byCategory
                ->map(fn ($r) => ['key' => (string) $r->c, 'label' => $r->c ?: UiLang::get('reports.none'), 'count' => (int) $r->units])->all()],
        ];
    }

    /** @return Builder<EquipmentRental> open loans with units still out */
    private function base(ReportFilters $filters): Builder
    {
        $query = EquipmentRental::query()
            ->join('equipment_items', 'equipment_items.id', '=', 'equipment_rentals.equipment_item_id')
            ->join('equipment_catalogs', 'equipment_catalogs.id', '=', 'equipment_items.catalog_id')
            ->whereNull('equipment_rentals.return_date')
            ->whereColumn('equipment_rentals.quantity', '>', 'equipment_rentals.returned_quantity');

        if ($filters->filled('type')) {
            $query->where('equipment_rentals.type', $filters->get('type'));
        }
        if ($filters->filled('category')) {
            $query->whereIn('equipment_catalogs.category', $filters->get('category'));
        }
        if ($filters->filled('overdue')) {
            $this->overdue($query);
        }

        return $query;
    }

    /** The is_overdue rule in SQL: a rental whose due date is today or past (due_date->isPast()). */
    private function overdue(Builder $query): Builder
    {
        return $query->where('equipment_rentals.type', '!=', 'assignment')
            ->whereNotNull('equipment_rentals.due_date')
            ->whereDate('equipment_rentals.due_date', '<=', today()->toDateString());
    }
}
