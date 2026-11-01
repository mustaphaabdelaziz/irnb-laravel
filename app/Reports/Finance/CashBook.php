<?php

namespace App\Reports\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceTransfer;
use App\Models\Transaction;
use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Support\FinanceAccountLabel;
use App\Support\FiscalPeriod;
use App\Support\TransactionTitle;
use App\Support\UiLang;
use Illuminate\Support\Collection;

/**
 * One cash register's statement: opening balance, every entry and transfer
 * in date order with the running balance, then the closing balance. Same
 * ledger as FinanceService::recomputeAccountBalances(): opening balance +
 * counted income − counted expenses + transfers in − transfers out. For a
 * coffre this is the cash it holds itself, not the roll-up of its caisses.
 */
class CashBook extends Report
{
    /** @var array<string, array{opening: float, rows: Collection<int, array<string, mixed>>}> */
    private array $memo = [];

    public function key(): string
    {
        return 'cashbook';
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
        return 'wallet';
    }

    public function filters(): array
    {
        $accounts = FinanceAccount::query()
            ->where('is_opening_fund', false)
            ->with(['branch:id,name,name_ar,name_fr,name_en', 'category:id,name,name_ar,name_fr,name_en'])
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (FinanceAccount $a) => ['value' => $a->id, 'label' => FinanceAccountLabel::for($a)])->all();

        return [
            Filter::select('account_id', 'col.cash_register', $accounts, $accounts[0]['value'] ?? null),
            Filter::date('from', 'reports.filter.from'),
            Filter::date('to', 'reports.filter.to'),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('date', 'col.date', Column::DATE),
            new Column('label', 'col.description'),
            new Column('in', 'reports.col.money_in', Column::MONEY, true),
            new Column('out', 'reports.col.money_out', Column::MONEY, true),
            new Column('balance', 'reports.col.balance', Column::MONEY),
        ];
    }

    /** A statement reads in date order only: the running balance depends on it. */
    public function sortable(): array
    {
        return [];
    }

    public function landscape(): bool
    {
        return false;
    }

    public function rows(ReportFilters $filters): Collection
    {
        return $this->statement($filters)['rows'];
    }

    public function totals(ReportFilters $filters): array
    {
        $rows = $this->statement($filters)['rows'];

        return ['in' => round($rows->sum('in'), 2), 'out' => round($rows->sum('out'), 2)];
    }

    public function summary(ReportFilters $filters): array
    {
        ['opening' => $opening, 'rows' => $rows] = $this->statement($filters);
        $in = round($rows->sum('in'), 2);
        $out = round($rows->sum('out'), 2);

        return [
            ['label' => 'reports.kpi.opening_balance', 'value' => $opening, 'format' => 'money'],
            ['label' => 'reports.kpi.money_in', 'value' => $in, 'format' => 'money'],
            ['label' => 'reports.kpi.money_out', 'value' => $out, 'format' => 'money'],
            ['label' => 'reports.kpi.closing_balance', 'value' => round($opening + $in - $out, 2), 'format' => 'money'],
        ];
    }

    /** @return array{opening: float, rows: Collection<int, array<string, mixed>>} */
    private function statement(ReportFilters $filters): array
    {
        $key = serialize($filters->values());
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $account = FinanceAccount::find($filters->get('account_id'));
        if ($account === null) {
            return $this->memo[$key] = ['opening' => 0.0, 'rows' => collect()];
        }

        // Without a start date the statement opens with the current season.
        $from = $filters->get('from') ?? FiscalPeriod::start(FiscalPeriod::currentKey())->toDateString();
        $to = $filters->get('to');

        $opening = round((float) $account->opening_balance + $this->movementsBefore($account, $from), 2);

        $entries = Transaction::query()->where('archived', false)->where('finance_account_id', $account->id)
            ->whereDate('transaction_date', '>=', $from)
            ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to))
            ->with(TransactionTitle::RELATIONS)->get()
            ->map(fn (Transaction $t) => [
                'sort' => substr((string) $t->transaction_date, 0, 10).'|t'.str_pad((string) $t->id, 10, '0', STR_PAD_LEFT),
                'date' => substr((string) $t->transaction_date, 0, 10),
                'label' => TransactionTitle::for($t),
                'in' => $t->transaction_type === 'income' ? round((float) $t->amount, 2) : null,
                'out' => $t->transaction_type === 'expense' ? round((float) $t->amount, 2) : null,
            ]);

        $transfers = FinanceTransfer::query()
            ->where(fn ($q) => $q->where('from_account_id', $account->id)->orWhere('to_account_id', $account->id))
            ->whereDate('transfer_date', '>=', $from)
            ->when($to, fn ($q) => $q->whereDate('transfer_date', '<=', $to))
            ->with(['fromAccount.branch', 'fromAccount.category', 'toAccount.branch', 'toAccount.category'])->get()
            ->map(function (FinanceTransfer $tr) use ($account) {
                $incoming = (int) $tr->to_account_id === (int) $account->id;
                $other = FinanceAccountLabel::for($incoming ? $tr->fromAccount : $tr->toAccount);

                return [
                    'sort' => substr((string) $tr->transfer_date, 0, 10).'|x'.str_pad((string) $tr->id, 10, '0', STR_PAD_LEFT),
                    'date' => substr((string) $tr->transfer_date, 0, 10),
                    'label' => str_replace('{name}', $other, UiLang::get($incoming ? 'reports.cashbook.transfer_from' : 'reports.cashbook.transfer_to')),
                    'in' => $incoming ? round((float) $tr->amount, 2) : null,
                    'out' => $incoming ? null : round((float) $tr->amount, 2),
                ];
            });

        $balance = $opening;
        $rows = $entries->concat($transfers)->sortBy('sort')->values()->map(function (array $row) use (&$balance) {
            $balance = round($balance + ($row['in'] ?? 0) - ($row['out'] ?? 0), 2);
            unset($row['sort']);

            return $row + ['balance' => $balance];
        });

        return $this->memo[$key] = ['opening' => $opening, 'rows' => $rows];
    }

    /** Net effect on the account of everything dated before $from. */
    private function movementsBefore(FinanceAccount $account, string $from): float
    {
        $ledger = (float) Transaction::query()->where('archived', false)->where('finance_account_id', $account->id)
            ->whereDate('transaction_date', '<', $from)
            ->selectRaw("COALESCE(SUM(CASE transaction_type WHEN 'income' THEN amount WHEN 'expense' THEN -amount ELSE 0 END), 0) AS net")
            ->value('net');
        $in = (float) FinanceTransfer::query()->where('to_account_id', $account->id)->whereDate('transfer_date', '<', $from)->sum('amount');
        $out = (float) FinanceTransfer::query()->where('from_account_id', $account->id)->whereDate('transfer_date', '<', $from)->sum('amount');

        return $ledger + $in - $out;
    }
}
