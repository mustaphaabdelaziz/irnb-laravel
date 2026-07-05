<?php

namespace App\Services\Finance;

use App\Models\Player;
use App\Models\PlayerSubscription;

class RecalculatePlayerDebtService
{
    /**
     * Recompute one subscription's amount_paid from its linked payments,
     * then refresh the owning player's cached outstanding_debt.
     */
    public function forSubscription(PlayerSubscription $sub): void
    {
        $paid = (float) $sub->payments()->where('archived', false)->sum('amount');
        $sub->forceFill(['amount_paid' => $paid])->saveQuietly();

        if ($sub->player) {
            $this->forPlayer($sub->player);
        }
    }

    /**
     * Recompute a player's cached outstanding_debt = sum of remaining over
     * their mandatory, non-exempt subscriptions.
     */
    public function forPlayer(Player $player): void
    {
        $debt = $player->calculateTotalDebt();
        $player->forceFill(['outstanding_debt' => $debt])->saveQuietly();
    }
}
