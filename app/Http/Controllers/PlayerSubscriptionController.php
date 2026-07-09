<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Services\Finance\RecalculatePlayerDebtService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerSubscriptionController extends Controller
{
    public function __construct(private RecalculatePlayerDebtService $debt)
    {
    }

    public function update(Request $request, Player $player, PlayerSubscription $playerSubscription): RedirectResponse
    {
        $this->ensureOwnership($player, $playerSubscription);

        $validated = $request->validate([
            'amount_owed' => ['required', 'numeric', 'min:0'],
            'is_exempt' => ['nullable', 'boolean'],
            'due_date' => ['nullable', 'date'],
        ]);

        $playerSubscription->update([
            'amount_owed' => $validated['amount_owed'],
            'is_exempt' => $request->boolean('is_exempt'),
            'due_date' => $validated['due_date'] ?? null,
        ]);

        $this->debt->forPlayer($player);

        return back()->with('success', 'Subscription updated.');
    }

    public function destroy(Player $player, PlayerSubscription $playerSubscription): RedirectResponse
    {
        $this->ensureOwnership($player, $playerSubscription);

        // Guard: keep the obligation while it still has recorded payments.
        if ($playerSubscription->payments()->where('archived', false)->exists()) {
            return back()->with('error', 'Remove this subscription\'s payments before deleting it.');
        }

        $playerSubscription->delete();
        $this->debt->forPlayer($player);

        return back()->with('success', 'Subscription removed.');
    }

    private function ensureOwnership(Player $player, PlayerSubscription $playerSubscription): void
    {
        if ((int) $playerSubscription->player_id !== (int) $player->id) {
            throw new NotFoundHttpException;
        }
    }
}
