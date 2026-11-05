<?php

namespace App\Services\Finance;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Services\Player\RegistrationDisplay;
use Illuminate\Support\Collection;

/**
 * Which registration a printed receipt names: the branch of the subscription it
 * settles when that subscription has exactly one; a group uses it only if all
 * its subscription lines share it; otherwise the main registration.
 */
class ReceiptRegistration
{
    /** @param  Collection<int, Transaction>  $lines  every transaction on the paper */
    public function shown(Player $player, Collection $lines): ?BranchRegistration
    {
        return RegistrationDisplay::make()->shown($player, $this->sharedBranch($lines));
    }

    /**
     * A line with no subscription (the donation of what was paid beyond them) has
     * no say. A line whose subscription was deleted, or spans several branches, does.
     *
     * @param  Collection<int, Transaction>  $lines
     */
    private function sharedBranch(Collection $lines): ?int
    {
        $ids = $lines->pluck('player_subscription_id')->filter()->unique();
        if ($ids->isEmpty()) {
            return null;
        }

        $branches = PlayerSubscription::with('subscription.branches:id')->whereKey($ids->all())->get()
            ->map(function (PlayerSubscription $line) {
                $own = $line->subscription?->branches;

                return $own !== null && $own->count() === 1 ? (int) $own->first()->id : null;
            });

        return $branches->count() === $ids->count() && $branches->unique()->count() === 1 ? $branches->first() : null;
    }
}
