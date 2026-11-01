<?php

namespace App\Reports\Finance;

use App\Models\Category;
use App\Models\Player;
use App\Models\Subscription;
use App\Reports\Column;
use App\Reports\Concerns\ReadsObligations;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Support\UiLang;
use Illuminate\Support\Collection;

/**
 * Every subscription a player still owes something on: net owed (after the
 * discount), paid, remaining, due date and days overdue. Exceptional
 * charges count as debt like annual ones. With no filter, the remaining
 * total equals the players' total debt.
 */
class OutstandingPayments extends Report
{
    use ReadsObligations;

    /** @var array<string, Collection<int, array<string, mixed>>> */
    private array $memo = [];

    public function key(): string
    {
        return 'debtors';
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
        return 'alert';
    }

    public function filters(): array
    {
        return [
            ...$this->obligationFilters(),
            Filter::select('overdue', 'reports.filter.overdue', [
                ['value' => 1, 'label' => UiLang::get('reports.filter.overdue_only')],
            ]),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('membership_id', 'col.membership_id'),
            new Column('name', 'col.full_name'),
            new Column('phone', 'col.phone'),
            new Column('category', 'col.category'),
            new Column('subscription', 'reports.filter.subscription'),
            new Column('net_owed', 'reports.col.net_owed', Column::MONEY, true),
            new Column('paid', 'col.amount_paid', Column::MONEY, true),
            new Column('remaining', 'reports.col.remaining', Column::MONEY, true),
            new Column('due_date', 'col.due_date', Column::DATE),
            new Column('days_overdue', 'reports.col.days_overdue', Column::NUMBER),
        ];
    }

    public function sortable(): array
    {
        return ['name' => 'name', 'remaining' => 'remaining', 'due_date' => 'due_date', 'days_overdue' => 'days_overdue', 'subscription' => 'subscription'];
    }

    public function defaultSort(): array
    {
        return ['remaining', 'desc'];
    }

    public function landscape(): bool
    {
        return true;
    }

    public function rows(ReportFilters $filters): Collection
    {
        return $this->memo[serialize($filters->values())] ??= $this->debts($filters);
    }

    public function totals(ReportFilters $filters): array
    {
        $rows = $this->rows($filters);

        return [
            'net_owed' => round($rows->sum('net_owed'), 2),
            'paid' => round($rows->sum('paid'), 2),
            'remaining' => round($rows->sum('remaining'), 2),
        ];
    }

    public function summary(ReportFilters $filters): array
    {
        $rows = $this->rows($filters);

        return [
            ['label' => 'reports.kpi.debtors', 'value' => $rows->pluck('player_id')->unique()->count(), 'format' => 'number'],
            ['label' => 'reports.kpi.obligations', 'value' => $rows->count(), 'format' => 'number'],
            ['label' => 'reports.kpi.remaining', 'value' => round($rows->sum('remaining'), 2), 'format' => 'money'],
            ['label' => 'reports.kpi.overdue_amount', 'value' => round($rows->where('days_overdue', '>', 0)->sum('remaining'), 2), 'format' => 'money'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        $rows = $this->rows($filters);
        $by = fn (string $field) => $rows->groupBy($field)
            ->map(fn (Collection $g, $label) => ['key' => (string) $label, 'label' => (string) ($label ?: UiLang::get('reports.none')), 'count' => round($g->sum('remaining'), 2)])
            ->sortByDesc('count')->values()->all();

        return [
            ['type' => 'bar', 'title' => 'reports.chart.remaining_by_subscription', 'stats' => $by('subscription'), 'unit' => 'money'],
            ['type' => 'bar', 'title' => 'reports.chart.remaining_by_category', 'stats' => $by('category'), 'unit' => 'money'],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function debts(ReportFilters $filters): Collection
    {
        $categories = Category::all()->keyBy('id');
        $today = today();

        return $this->obligations($filters)
            ->filter(fn (object $o) => $o->money['remaining'] > 0)
            ->map(function (object $o) use ($categories, $today) {
                $due = $o->due_date ? substr((string) $o->due_date, 0, 10) : null;
                $phones = is_string($o->phones) ? json_decode($o->phones, true) : $o->phones;

                return [
                    'player_id' => (int) $o->player_id,
                    'membership_id' => $o->membership_id,
                    'name' => Player::fullnameOf($o->lastname, $o->firstname, $o->nickname, $o->father, $o->grandfather, $o->gender),
                    'phone' => collect($phones ?? [])->filter()->first(),
                    'category' => $categories->get($o->category_id)?->localized_name,
                    'subscription' => $o->label ?: $o->subscription_name.($o->subscription_year ? ' ('.Subscription::seasonLabel((int) $o->subscription_year).')' : ''),
                    'net_owed' => round($o->money['net_owed'], 2),
                    'paid' => round($o->money['paid'], 2),
                    'remaining' => round($o->money['remaining'], 2),
                    'due_date' => $due,
                    'days_overdue' => $due && $due < $today->toDateString() ? (int) $today->diffInDays($due, true) : 0,
                ];
            })
            ->when($filters->filled('overdue'), fn (Collection $rows) => $rows->where('days_overdue', '>', 0))
            ->values();
    }
}
