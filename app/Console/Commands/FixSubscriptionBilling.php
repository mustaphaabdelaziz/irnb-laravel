<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Services\Finance\RecalculatePlayerDebtService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixSubscriptionBilling extends Command
{
    protected $signature = 'finance:fix-subscription-billing {--force : Actually delete bill transactions}';

    protected $description = 'Cash-basis cleanup: snapshot is_mandatory, link payments, and recompute debts (always run); pass --force to also delete legacy Unpaid subscription bills.';

    public function handle(RecalculatePlayerDebtService $debt): int
    {
        $this->info('Snapshot, link, and recompute steps always run; only legacy-bill deletion is gated behind --force.');

        // 1. Snapshot is_mandatory from the parent subscription (nulls default true).
        $updated = DB::table('player_subscriptions')
            ->whereIn('subscription_id', function ($q) {
                $q->select('id')->from('subscriptions')->where('is_mandatory', false);
            })
            ->update(['is_mandatory' => false]);
        $this->info("Marked {$updated} subscription(s) as optional.");

        // 2. Link real payments (income, not Unpaid) to a subscription by player+year.
        $linked = 0;
        Transaction::query()
            ->where('transaction_type', 'income')
            ->where('archived', false)
            ->where('status', '!=', 'Unpaid')
            ->whereNull('player_subscription_id')
            ->where('related_entity_type', 'Player')
            // Only subscription/debt_payment income reduces a subscription balance; donations and other
            // income are intentionally left unlinked so the year-match heuristic can't over-credit or
            // wrongly exempt a subscription. (Donations recorded against a subscription in the live app
            // already carry player_subscription_id from creation and are unaffected.)
            ->whereIn('category', ['subscription', 'debt_payment'])
            ->chunkById(200, function ($payments) use (&$linked) {
                foreach ($payments as $payment) {
                    $sub = PlayerSubscription::query()
                        ->where('player_id', $payment->related_entity_id)
                        ->where('year', $payment->fiscal_year)
                        ->orderByDesc('is_mandatory')
                        ->first()
                        ?? PlayerSubscription::query()->where('transaction_id', $payment->id)->first();

                    if ($sub) {
                        $payment->forceFill(['player_subscription_id' => $sub->id])->saveQuietly();
                        $linked++;
                    } else {
                        $this->warn("Unresolved payment #{$payment->id} (player {$payment->related_entity_id}, year {$payment->fiscal_year}).");
                    }
                }
            });
        $this->info("Linked {$linked} payment(s) to subscriptions.");

        // 3. Identify bills (income + subscription + Unpaid).
        $billsQuery = Transaction::query()
            ->where('transaction_type', 'income')
            ->where('category', 'subscription')
            ->where('status', 'Unpaid');
        $billCount = (clone $billsQuery)->count();

        if ($this->option('force')) {
            (clone $billsQuery)->delete();
            $this->info("Deleted {$billCount} bill transaction(s).");
        } else {
            $this->warn("[dry-run] Would delete {$billCount} bill transaction(s). Re-run with --force.");
        }

        // 4. Recompute every subscription's paid + every player's debt; reconcile.
        PlayerSubscription::with('payments', 'player')->chunkById(200, function ($subs) use ($debt) {
            foreach ($subs as $sub) {
                $before = (float) $sub->amount_paid;
                $debt->forSubscription($sub);
                $after = (float) $sub->fresh()->amount_paid;
                if (abs($before - $after) > 0.001) {
                    $this->warn("Reconcile: sub #{$sub->id} amount_paid {$before} -> {$after}.");
                }
            }
        });

        Player::query()->chunkById(200, fn ($players) => $players->each(fn ($p) => $debt->forPlayer($p)));

        $this->info('Recompute complete.');

        return self::SUCCESS;
    }
}
