<?php

namespace App\Observers;

use App\Exceptions\ClosedFiscalYearException;
use App\Models\FiscalYear;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Services\Finance\DefaultRegisterResolver;
use App\Services\Finance\RecalculatePlayerDebtService;
use App\Services\FinanceService;
use App\Support\FiscalPeriod;
use App\Support\LegacyFinanceCategory;

class TransactionObserver
{
    /**
     * Keep every transaction linked to a fiscal year, a chart-of-accounts
     * category and an account, whichever path created it.
     */
    public function saving(Transaction $transaction): void
    {
        // The season of the transaction's own date — never a caller's guess.
        $key = FiscalPeriod::keyForDate($transaction->transaction_date ?: now());
        $transaction->fiscal_year = $key;

        $fy = FiscalYear::firstOrCreate(
            ['year' => $key],
            [
                'start_date' => FiscalPeriod::start($key)->toDateString(),
                'end_date' => FiscalPeriod::end($key)->toDateString(),
                'status' => 'open',
                'opening_balance' => 0,
            ]
        );
        $transaction->fiscal_year_id = $fy->id;

        if ($fy->isClosed()) {
            throw new ClosedFiscalYearException($key);
        }

        $originalKey = (int) $transaction->getOriginal('fiscal_year');
        if ($transaction->exists && $originalKey && $originalKey !== $key && $this->isClosed($originalKey)) {
            throw new ClosedFiscalYearException($originalKey);
        }

        // Every transaction gets a category; one only carrying a legacy slug is
        // mapped to an existing category, never given a new one (LegacyFinanceCategory).
        if (empty($transaction->finance_category_id)) {
            $transaction->finance_category_id = LegacyFinanceCategory::resolve(
                (string) $transaction->transaction_type,
                $transaction->category,
            )->id;
        }

        if (empty($transaction->finance_account_id)) {
            $player = $transaction->related_entity_type === 'Player' && $transaction->related_entity_id
                ? Player::with('branches:id')->find($transaction->related_entity_id)
                : null;
            $registers = DefaultRegisterResolver::load();

            $transaction->finance_account_id = ($player
                ? $registers->forPlayer($player)
                : $registers->for(null, null))?->id;
        }
    }

    public function deleting(Transaction $transaction): void
    {
        if ($transaction->fiscal_year && $this->isClosed((int) $transaction->fiscal_year)) {
            throw new ClosedFiscalYearException((int) $transaction->fiscal_year);
        }
    }

    private function isClosed(int $key): bool
    {
        return FiscalYear::where('year', $key)->where('status', 'closed')->exists();
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
