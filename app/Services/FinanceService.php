<?php

namespace App\Services;

use App\Models\FinanceAccount;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\User;
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
     * Recompute each account's running balance from its transactions.
     */
    public function recomputeAccountBalances(): void
    {
        FinanceAccount::query()->each(function (FinanceAccount $account) {
            $income = (float) Transaction::where('finance_account_id', $account->id)
                ->where('archived', false)->where('transaction_type', 'income')->sum('amount');
            $expense = (float) Transaction::where('finance_account_id', $account->id)
                ->where('archived', false)->where('transaction_type', 'expense')->sum('amount');
            $account->current_balance = (float) $account->opening_balance + $income - $expense;
            $account->save();
        });
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

            // Carry the closing balance into the next year as its opening balance.
            $next = FiscalYear::firstOrNew(['year' => $fiscalYear->year + 1]);
            $next->fill([
                'start_date' => ($fiscalYear->year + 1).'-01-01',
                'end_date' => ($fiscalYear->year + 1).'-12-31',
                'status' => $next->exists ? $next->status : 'open',
                'opening_balance' => $closing,
            ])->save();

            return $fiscalYear;
        });
    }

    /**
     * Reopen a closed year (and clear the carried-forward opening balance on the
     * next year if it is still open and was set from this year's close).
     */
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
