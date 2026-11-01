<?php

namespace App\Reports\Equipment;

use App\Models\EquipmentHistory;
use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;

/**
 * The equipment history across every item: checkouts, returns, repairs,
 * losses, finds, receptions and splits, labelled like the item history page.
 */
class EquipmentLifecycle extends Report
{
    public function key(): string
    {
        return 'lifecycle';
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
        return 'refresh';
    }

    public function filters(): array
    {
        return [
            Filter::multi('event_type', 'reports.filter.event', EquipmentHistory::query()->distinct()->orderBy('event_type')->pluck('event_type')
                ->map(fn (string $e) => ['value' => $e, 'label' => self::eventLabel($e)])->all()),
            Filter::date('from', 'reports.filter.from'),
            Filter::date('to', 'reports.filter.to'),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('date', 'col.date', Column::DATE),
            new Column('event', 'reports.filter.event'),
            new Column('catalog', 'col.catalog'),
            new Column('item', 'col.item'),
            new Column('by', 'col.recorded_by'),
        ];
    }

    public function sortable(): array
    {
        return ['date' => 'equipment_histories.event_timestamp', 'event' => 'equipment_histories.event_type'];
    }

    public function defaultSort(): array
    {
        return ['date', 'desc'];
    }

    public function landscape(): bool
    {
        return false;
    }

    public function rows(ReportFilters $filters): Builder
    {
        return $this->base($filters)->with(['item.catalog:id,name', 'user:id,name']);
    }

    /** @param EquipmentHistory $record */
    public function row(mixed $record): array
    {
        return [
            'date' => substr((string) $record->event_timestamp, 0, 10),
            'event' => self::eventLabel($record->event_type),
            'catalog' => $record->item?->catalog?->name,
            'item' => $record->item?->designation ?: $record->item?->unique_identifier,
            'by' => $record->user?->name,
        ];
    }

    public function summary(ReportFilters $filters): array
    {
        return [['label' => 'reports.kpi.events', 'value' => $this->base($filters)->count(), 'format' => 'number']];
    }

    public function charts(ReportFilters $filters): array
    {
        $byType = $this->base($filters)->toBase()->groupBy('event_type')
            ->selectRaw('event_type, COUNT(*) as c')->orderByDesc('c')->get();

        return [
            ['type' => 'bar', 'title' => 'reports.chart.by_event', 'unit' => 'reports.kpi.events', 'stats' => $byType
                ->map(fn ($r) => ['key' => (string) $r->event_type, 'label' => self::eventLabel($r->event_type), 'count' => (int) $r->c])->all()],
        ];
    }

    /** As the item history page: "Repair Complete" reads event_repair_complete. */
    public static function eventLabel(?string $type): string
    {
        $key = 'event_'.str_replace(' ', '_', strtolower((string) $type));

        return UiLang::get($key, str_replace('_', ' ', (string) $type));
    }

    /** @return Builder<EquipmentHistory> */
    private function base(ReportFilters $filters): Builder
    {
        $query = EquipmentHistory::query();

        if ($filters->filled('event_type')) {
            $query->whereIn('event_type', $filters->get('event_type'));
        }
        if ($filters->filled('from')) {
            $query->whereDate('event_timestamp', '>=', $filters->get('from'));
        }
        if ($filters->filled('to')) {
            $query->whereDate('event_timestamp', '<=', $filters->get('to'));
        }

        return $query;
    }
}
