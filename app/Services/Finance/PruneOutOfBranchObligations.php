<?php

namespace App\Services\Finance;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;

/**
 * A branch subscription is only for that branch's players. When its branches
 * change, the players left outside lose the obligation — but only while it is
 * still untouched (unpaid, not exempt, no payment on file). Anything paid,
 * partly paid or exempt stays, so no recorded money loses its obligation.
 */
class PruneOutOfBranchObligations
{
    public function __construct(private RecalculatePlayerDebtService $debt) {}

    /** @return int the number of obligations removed */
    public function forSubscription(Subscription $subscription): int
    {
        $branchIds = $subscription->branches()->pluck('branches.id');

        if ($branchIds->isEmpty()) {
            return 0;
        }

        $stale = PlayerSubscription::query()
            ->where('subscription_id', $subscription->id)
            ->where('is_exempt', false)
            ->where('amount_paid', 0)
            ->whereDoesntHave('payments', fn ($payment) => $payment->where('archived', false))
            ->whereDoesntHave('player.branches', fn ($branch) => $branch->whereIn('branches.id', $branchIds))
            ->get()
            // A full discount settles it with no payment: that counts as paid.
            ->filter(fn (PlayerSubscription $ps) => $ps->payment_status === 'unpaid');

        if ($stale->isEmpty()) {
            return 0;
        }

        PlayerSubscription::whereKey($stale->modelKeys())->delete();

        Player::whereKey($stale->pluck('player_id')->unique()->all())
            ->get()
            ->each(fn (Player $player) => $this->debt->forPlayer($player));

        return $stale->count();
    }
}
