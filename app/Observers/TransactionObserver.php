<?php

namespace App\Observers;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Services\Finance\RecalculatePlayerDebtService;
use App\Services\FinanceService;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TransactionObserver
{
    /**
     * Keep every transaction linked to a fiscal year, a chart-of-accounts
     * category and an account, whichever path created it.
     */
    public function saving(Transaction $transaction): void
    {
        if (empty($transaction->fiscal_year)) {
            $date = $transaction->transaction_date ?: now();
            $transaction->fiscal_year = (int) Carbon::parse($date)->format('Y');
        }

        $fy = FiscalYear::firstOrCreate(
            ['year' => $transaction->fiscal_year],
            ['start_date' => $transaction->fiscal_year.'-01-01', 'end_date' => $transaction->fiscal_year.'-12-31', 'status' => 'open', 'opening_balance' => 0]
        );
        $transaction->fiscal_year_id = $fy->id;

        if (empty($transaction->finance_category_id) && $transaction->category) {
            $cat = FinanceCategory::firstOrCreate(
                ['type' => $transaction->transaction_type, 'name' => Str::title(str_replace(['_', '-'], ' ', (string) $transaction->category))],
                ['is_active' => true, 'is_system' => false]
            );
            $transaction->finance_category_id = $cat->id;
        }

        if (empty($transaction->finance_account_id)) {
            $transaction->finance_account_id = FinanceAccount::where('type', 'cash')->orderBy('id')->value('id');
        }
    }

    public function saved(Transaction $transaction): void
    {
        $this->recompute($transaction);
        $this->recomputeSubscription($transaction);
    }

    public function deleted(Transaction $transaction): void
    {
        $this->recompute($transaction);
        $this->recomputeSubscription($transaction);
    }

    private function recompute(Transaction $transaction): void
    {
        $finance = app(FinanceService::class);
        $finance->recomputeAccountBalances();
        if ($transaction->fiscalYear) {
            $finance->recomputeYear($transaction->fiscalYear);
        }
    }

    private function recomputeSubscription(Transaction $transaction): void
    {
        if (! $transaction->player_subscription_id) {
            return;
        }

        $sub = PlayerSubscription::find($transaction->player_subscription_id);
        if ($sub) {
            app(RecalculatePlayerDebtService::class)->forSubscription($sub);
        }
    }
}
