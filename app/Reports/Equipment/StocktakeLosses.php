<?php

namespace App\Reports\Equipment;

use App\Models\InventorySession;
use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Support\UiLang;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Completed stock-takes with the units expected, found and missing, and what
 * the missing units were worth (lot price, else the catalog's reference
 * price). Missing = expected − found per line, never negative
 * (InventorySessionItem::missing_quantity); an uncounted line is all missing.
 */
class StocktakeLosses extends Report
{
    /** @var array<string, Collection<int, array<string, mixed>>> */
    private array $memo = [];

    public function key(): string
    {
        return 'stocktake';
    }

    public function category(): string
    {
        return 'equipment';
    }

    public function module(): string
    {
        return 'inventory';
    }

    public function icon(): string
    {
        return 'clipboard';
    }

    public function filters(): array
    {
        return [
            Filter::date('from', 'reports.filter.from'),
            Filter::date('to', 'reports.filter.to'),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('reference', 'reports.col.reference'),
            new Column('date', 'col.date', Column::DATE),
            new Column('expected', 'reports.col.expected_units', Column::NUMBER, true),
            new Column('found', 'col.found', Column::NUMBER, true),
            new Column('missing', 'reports.col.missing_units', Column::NUMBER, true),
            new Column('missing_value', 'reports.col.missing_value', Column::MONEY, true),
        ];
    }

    public function sortable(): array
    {
        return ['date' => 'date', 'missing' => 'missing', 'missing_value' => 'missing_value'];
    }

    public function defaultSort(): array
    {
        return ['date', 'desc'];
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

        return [
            'expected' => (int) $rows->sum('expected'),
            'found' => (int) $rows->sum('found'),
            'missing' => (int) $rows->sum('missing'),
            'missing_value' => round($rows->sum('missing_value'), 2),
        ];
    }

    public function summary(ReportFilters $filters): array
    {
        $t = $this->totals($filters);

        return [
            ['label' => 'reports.kpi.sessions', 'value' => $this->rows($filters)->count(), 'format' => 'number'],
            ['label' => 'reports.col.missing_units', 'value' => $t['missing'], 'format' => 'number'],
            ['label' => 'reports.col.missing_value', 'value' => $t['missing_value'], 'format' => 'money'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        return [
            ['type' => 'bar', 'title' => 'reports.chart.missing_value_by_session', 'unit' => 'money', 'stats' => $this->rows($filters)
                ->sortBy('date')->map(fn (array $r) => ['key' => $r['reference'], 'label' => $r['reference'], 'count' => $r['missing_value']])->values()->all()],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function compute(ReportFilters $filters): Collection
    {
        $sessions = InventorySession::query()->where('status', 'completed')
            ->when($filters->filled('from'), fn ($q) => $q->whereDate('session_date', '>=', $filters->get('from')))
            ->when($filters->filled('to'), fn ($q) => $q->whereDate('session_date', '<=', $filters->get('to')))
            ->get(['id', 'reference', 'session_date']);

        if ($sessions->isEmpty()) {
            return collect();
        }

        $lines = DB::table('inventory_session_items')
            ->join('equipment_items', 'equipment_items.id', '=', 'inventory_session_items.equipment_item_id')
            ->join('equipment_catalogs', 'equipment_catalogs.id', '=', 'equipment_items.catalog_id')
            ->whereIn('inventory_session_items.inventory_session_id', $sessions->modelKeys())
            ->groupBy('inventory_session_items.inventory_session_id')
            ->selectRaw('inventory_session_items.inventory_session_id as session_id,
                SUM(inventory_session_items.expected_quantity) as expected,
                SUM(COALESCE(inventory_session_items.found_quantity, 0)) as found,
                SUM(CASE WHEN inventory_session_items.expected_quantity > COALESCE(inventory_session_items.found_quantity, 0)
                    THEN inventory_session_items.expected_quantity - COALESCE(inventory_session_items.found_quantity, 0) ELSE 0 END) as missing,
                SUM(CASE WHEN inventory_session_items.expected_quantity > COALESCE(inventory_session_items.found_quantity, 0)
                    THEN (inventory_session_items.expected_quantity - COALESCE(inventory_session_items.found_quantity, 0))
                        * COALESCE(equipment_items.unit_price, equipment_catalogs.purchase_price, 0) ELSE 0 END) as missing_value')
            ->get()->keyBy('session_id');

        return $sessions->map(fn (InventorySession $s) => [
            'reference' => $s->reference ?: UiLang::get('reports.none'),
            'date' => substr((string) $s->session_date, 0, 10),
            'expected' => (int) ($lines[$s->id]->expected ?? 0),
            'found' => (int) ($lines[$s->id]->found ?? 0),
            'missing' => (int) ($lines[$s->id]->missing ?? 0),
            'missing_value' => round((float) ($lines[$s->id]->missing_value ?? 0), 2),
        ])->values();
    }
}
