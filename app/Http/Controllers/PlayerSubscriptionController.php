<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Services\Finance\RecalculatePlayerDebtService;
use Illuminate\Http\RedirectResponse;

class PlayerSubscriptionController extends Controller
{
    public function exempt(Player $player, PlayerSubscription $subscription): RedirectResponse
    {
        if ((int) $subscription->player_id !== (int) $player->id) {
            abort(403, 'Subscription does not belong to this player.');
        }

        $subscription->update(['is_exempt' => ! $subscription->is_exempt]);

        app(RecalculatePlayerDebtService::class)->forPlayer($player);

        return redirect()->route('players.show', $player)
            ->with('success', $subscription->is_exempt ? 'Subscription exempted.' : 'Exemption removed.');
    }
}
