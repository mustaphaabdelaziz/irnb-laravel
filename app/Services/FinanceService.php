<?php

namespace App\Services;

use App\Models\FinanceAccount;
use App\Models\FinanceTransfer;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FiscalPeriod;
use Illuminate\Support\Facades\DB;

class FinanceService
{
    /**
     * Income/expense/net for a single fiscal year (live, from transactions).
     *
     * @return array{income: float, expense: float, net: float}
     */
    public function yearTotals(int $year): array
    {
        $base = Transaction::query()->where('archived', false)->where('fiscal_year', $year);
        $income = (float) (clone $base)->where('transaction_type', 'income')->sum('amount');
        $expense = (float) (clone $base)->where('transaction_type', 'expense')->sum('amount');

        return ['income' => $income, 'expense' => $expense, 'net' => $income - $expense];
    }

    /**
     * Recompute and persist cached totals on a fiscal year.
     */
    public function recomputeYear(FiscalYear $fiscalYear): FiscalYear
    {
        $t = $this->yearTotals($fiscalYear->year);
        $fiscalYear->total_income = $t['income'];
        $fiscalYear->total_expense = $t['expense'];
        if ($fiscalYear->isClosed()) {
            $fiscalYear->closing_balance = (float) $fiscalYear->opening_balance + $t['net'];
        }
        $fiscalYear->save();

        return $fiscalYear;
    }

    /**
     * Recompute each account's running balance from transactions and internal
     * transfers. Transfers change register balances but never income/expense.
     */
    public function recomputeAccountBalances(): void
    {
        $accounts = FinanceAccount::query()->orderBy('id')->get();

        // Three grouped queries for every account at once, not four per account.
        $ledger = Transaction::query()
            ->where('archived', false)
            ->whereNotNull('finance_account_id')
            ->groupBy('finance_account_id')
            ->selectRaw("finance_account_id, SUM(CASE transaction_type WHEN 'income' THEN amount WHEN 'expense' THEN -amount ELSE 0 END) AS net")
            ->pluck('net', 'finance_account_id');
        $incoming = FinanceTransfer::query()->groupBy('to_account_id')
            ->selectRaw('to_account_id, SUM(amount) AS total')->pluck('total', 'to_account_id');
        $outgoing = FinanceTransfer::query()->groupBy('from_account_id')
            ->selectRaw('from_account_id, SUM(amount) AS total')->pluck('total', 'from_account_id');

        $own = $accounts->mapWithKeys(fn (FinanceAccount $account) => [
            $account->id => (float) $account->opening_balance
                + (float) ($ledger[$account->id] ?? 0)
                + (float) ($incoming[$account->id] ?? 0)
                - (float) ($outgoing[$account->id] ?? 0),
        ]);

        // A treasury is a roll-up, not a duplicate ledger entry. Its displayed
        // balance is its own cash plus every child category register. Moving
        // money between a child and its treasury therefore preserves the branch
        // total while changing where the cash is held.
        $childrenByParent = $accounts->whereNotNull('parent_account_id')->groupBy('parent_account_id');

        foreach ($accounts as $account) {
            $balance = $own[$account->id];

            if ($account->is_treasury) {
                $balance += $childrenByParent->get($account->id, collect())
                    ->sum(fn (FinanceAccount $child) => $own[$child->id]);
            }

            // save() writes nothing when the balance did not change.
            $account->current_balance = $balance;
            $account->save();
        }
    }

    /**
     * The cash held in the account itself. A coffre's current_balance is a
     * roll-up of its own cash plus its caisses; this removes the caisses, so
     * a coffre can never hand out money that sits in one of its caisses.
     * Reads the stored balances: run recomputeAccountBalances() first.
     */
    public function ownBalance(FinanceAccount $account): float
    {
        $balance = (float) $account->current_balance;

        if ($account->is_treasury) {
            $balance -= (float) $account->childAccounts()->sum('current_balance');
        }

        return round($balance, 2);
    }

    /**
     * Close (enclose) a fiscal year: lock it, compute its closing balance, and
     * carry that balance forward as the opening balance of the next year.
     */
    public function closeYear(FiscalYear $fiscalYear, User $user): FiscalYear
    {
        if ($fiscalYear->isClosed()) {
            return $fiscalYear;
        }

        return DB::transaction(function () use ($fiscalYear, $user) {
            $t = $this->yearTotals($fiscalYear->year);
            $closing = (float) $fiscalYear->opening_balance + $t['net'];

            $fiscalYear->forceFill([
                'status' => 'closed',
                'total_income' => $t['income'],
                'total_expense' => $t['expense'],
                'closing_balance' => $closing,
                'closed_at' => now(),
                'closed_by_user_id' => $user->id,
            ])->save();

            // Carry the closing balance into the next season as its opening
            // balance — unless that season is already closed (its figures stand).
            $nextKey = $fiscalYear->year + 1;
            $next = FiscalYear::firstOrNew(['year' => $nextKey]);
            if (! $next->isClosed()) {
                $next->fill([
                    'start_date' => FiscalPeriod::start($nextKey)->toDateString(),
                    'end_date' => FiscalPeriod::end($nextKey)->toDateString(),
                    'status' => $next->exists ? $next->status : 'open',
                    'opening_balance' => $closing,
                ])->save();
            }

            return $fiscalYear;
        });
    }

    /** Reopen a closed season. The next season keeps the opening balance it was given at close. */
    public function reopenYear(FiscalYear $fiscalYear): FiscalYear
    {
        $fiscalYear->forceFill([
            'status' => 'open',
            'closing_balance' => null,
            'closed_at' => null,
            'closed_by_user_id' => null,
        ])->save();

        return $fiscalYear;
    }
}
