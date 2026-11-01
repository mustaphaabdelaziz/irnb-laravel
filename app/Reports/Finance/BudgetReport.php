<?php

namespace App\Reports\Finance;

use App\Models\FiscalYear;
use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Services\Finance\BudgetVsActual;
use App\Support\FiscalPeriod;
use App\Support\UiLang;
use Illuminate\Support\Collection;

/** Planned against actual per finance category for a season: the Finance page's budget card as a document. */
class BudgetReport extends Report
{
    public function key(): string
    {
        return 'budget';
    }

    public function category(): string
    {
        return 'finance';
    }

    public function module(): string
    {
        return 'finance';
    }

    public function icon(): string
    {
        return 'money';
    }

    public function filters(): array
    {
        $years = FiscalYear::query()->orderByDesc('year')->pluck('year')->map(fn ($y) => (int) $y);
        $current = FiscalPeriod::currentKey();

        return [
            Filter::select('season', 'reports.filter.season', $years
                ->map(fn (int $key) => ['value' => $key, 'label' => FiscalPeriod::label($key)])->all(),
                $years->contains($current) ? $current : $years->first()),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('category', 'col.category'),
            new Column('type', 'col.type'),
            new Column('planned', 'reports.col.planned', Column::MONEY),
            new Column('actual', 'reports.col.actual', Column::MONEY),
            new Column('variance', 'reports.col.variance', Column::MONEY),
            new Column('used', 'reports.col.used', Column::PERCENT),
        ];
    }

    public function sortable(): array
    {
        return ['category' => 'category', 'planned' => 'planned', 'actual' => 'actual', 'variance' => 'variance', 'used' => 'used'];
    }

    public function landscape(): bool
    {
        return false;
    }

    public function rows(ReportFilters $filters): Collection
    {
        return collect($this->budget($filters))->map(fn (array $r) => [
            'category' => $r['name'] ?? UiLang::get('reports.uncategorised'),
            'type' => UiLang::get($r['type'], $r['type']),
            'planned' => round($r['planned'], 2),
            'actual' => round($r['actual'], 2),
            'variance' => round($r['variance'], 2),
            'used' => $r['planned'] > 0 ? round($r['actual'] / $r['planned'] * 100, 1) : null,
        ]);
    }

    public function summary(ReportFilters $filters): array
    {
        $rows = collect($this->budget($filters));
        $side = fn (string $type, string $field) => round($rows->where('type', $type)->sum($field), 2);

        return [
            ['label' => 'reports.kpi.income_planned', 'value' => $side('income', 'planned'), 'format' => 'money'],
            ['label' => 'reports.kpi.income', 'value' => $side('income', 'actual'), 'format' => 'money'],
            ['label' => 'reports.kpi.expense_planned', 'value' => $side('expense', 'planned'), 'format' => 'money'],
            ['label' => 'reports.kpi.expense', 'value' => $side('expense', 'actual'), 'format' => 'money'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function budget(ReportFilters $filters): array
    {
        $fy = FiscalYear::where('year', $filters->get('season'))->first();

        return $fy ? BudgetVsActual::for($fy) : [];
    }
}
