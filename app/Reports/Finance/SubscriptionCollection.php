<?php

namespace App\Reports\Finance;

use App\Models\Subscription;
use App\Reports\Column;
use App\Reports\Concerns\ReadsObligations;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Support\UiLang;
use Illuminate\Support\Collection;

/**
 * Per subscription: who is enrolled, who paid, partly paid, owes all or is
 * exempt, what was billed after discounts, collected and still due. Exempt
 * lines are counted apart, never as paid or unpaid.
 */
class SubscriptionCollection extends Report
{
    use ReadsObligations;

    /** @var array<string, Collection<int, object>> */
    private array $memo = [];

    public function key(): string
    {
        return 'collection';
    }

    public function category(): string
    {
        return 'finance';
    }

    public function module(): string
    {
        return 'subscriptions';
    }

    public function icon(): string
    {
        return 'subscriptions';
    }

    public function filters(): array
    {
        return $this->obligationFilters();
    }

    public function columns(): array
    {
        return [
            new Column('subscription', 'reports.filter.subscription'),
            new Column('kind', 'subscription_kind'),
            new Column('enrolled', 'reports.col.enrolled', Column::NUMBER, true),
            new Column('paid', 'reports.col.paid_count', Column::NUMBER, true),
            new Column('partial', 'reports.col.partial_count', Column::NUMBER, true),
            new Column('unpaid', 'reports.col.unpaid_count', Column::NUMBER, true),
            new Column('exempt', 'reports.col.exempt_count', Column::NUMBER, true),
            new Column('billed', 'reports.col.billed', Column::MONEY, true),
            new Column('collected', 'reports.col.collected', Column::MONEY, true),
            new Column('remaining', 'reports.col.remaining', Column::MONEY, true),
            new Column('rate', 'reports.col.rate', Column::PERCENT),
        ];
    }

    public function sortable(): array
    {
        return ['subscription' => 'subscription', 'enrolled' => 'enrolled', 'remaining' => 'remaining', 'rate' => 'rate', 'collected' => 'collected'];
    }

    public function landscape(): bool
    {
        return true;
    }

    public function rows(ReportFilters $filters): Collection
    {
        return $this->all($filters)->groupBy('subscription_id')->map(function (Collection $lines) {
            $first = $lines->first();

            return [
                'subscription' => $first->subscription_name.($first->subscription_year ? ' ('.Subscription::seasonLabel((int) $first->subscription_year).')' : ''),
                'kind' => UiLang::get('subscription_kind_'.$first->subscription_kind, (string) $first->subscription_kind),
                ...$this->figures($lines),
            ];
        })->values();
    }

    public function totals(ReportFilters $filters): array
    {
        $figures = $this->figures($this->all($filters));
        unset($figures['rate']);

        return $figures;
    }

    public function summary(ReportFilters $filters): array
    {
        $f = $this->figures($this->all($filters));

        return [
            ['label' => 'reports.col.enrolled', 'value' => $f['enrolled'], 'format' => 'number'],
            ['label' => 'reports.col.billed', 'value' => $f['billed'], 'format' => 'money'],
            ['label' => 'reports.col.collected', 'value' => $f['collected'], 'format' => 'money'],
            ['label' => 'reports.col.remaining', 'value' => $f['remaining'], 'format' => 'money'],
            ['label' => 'reports.col.rate', 'value' => $f['rate'], 'format' => 'percent'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        $f = $this->figures($this->all($filters));

        return [
            ['type' => 'doughnut', 'title' => 'reports.chart.payment_status', 'unit' => 'reports.col.enrolled', 'stats' => [
                ['key' => 'paid', 'label' => UiLang::get('reports.col.paid_count'), 'count' => $f['paid'], 'color' => '#16a34a'],
                ['key' => 'partial', 'label' => UiLang::get('reports.col.partial_count'), 'count' => $f['partial'], 'color' => '#f59e0b'],
                ['key' => 'unpaid', 'label' => UiLang::get('reports.col.unpaid_count'), 'count' => $f['unpaid'], 'color' => '#dc2626'],
                ['key' => 'exempt', 'label' => UiLang::get('reports.col.exempt_count'), 'count' => $f['exempt'], 'color' => '#94a3b8'],
            ]],
        ];
    }

    /** @return Collection<int, object> */
    private function all(ReportFilters $filters): Collection
    {
        return $this->memo[serialize($filters->values())] ??= $this->obligations($filters);
    }

    /** @return array{enrolled: int, paid: int, partial: int, unpaid: int, exempt: int, billed: float, collected: float, remaining: float, rate: ?float} */
    private function figures(Collection $lines): array
    {
        $status = $lines->countBy(fn (object $o) => $o->money['status']);
        $priced = $lines->reject(fn (object $o) => $o->money['status'] === 'exempt');
        $billed = round($priced->sum(fn (object $o) => $o->money['net_owed']), 2);
        $collected = round($priced->sum(fn (object $o) => $o->money['paid']), 2);

        return [
            'enrolled' => $lines->count(),
            'paid' => (int) ($status['paid'] ?? 0),
            'partial' => (int) ($status['partial'] ?? 0),
            'unpaid' => (int) ($status['unpaid'] ?? 0),
            'exempt' => (int) ($status['exempt'] ?? 0),
            'billed' => $billed,
            'collected' => $collected,
            'remaining' => round($priced->sum(fn (object $o) => $o->money['remaining']), 2),
            'rate' => $billed > 0 ? round($collected / $billed * 100, 1) : null,
        ];
    }
}
