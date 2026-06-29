<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Services\Finance\ResolvePaymentStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlayerTransactionController extends Controller
{
    public function store(Request $request, Player $player): RedirectResponse
    {
        $validated = $request->validate([
            'player_subscription_id' => ['required', 'integer', 'exists:player_subscriptions,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:subscription,donation,debt_payment'],
            'description' => ['nullable', 'string'],
        ]);

        $playerSub = PlayerSubscription::findOrFail($validated['player_subscription_id']);

        if ((int) $playerSub->player_id !== (int) $player->id) {
            abort(403, 'Subscription does not belong to this player.');
        }

        DB::transaction(function () use ($validated, $player, $playerSub, $request) {
            $paymentService = app(ResolvePaymentStatusService::class);

            $newPaid = (float) $playerSub->amount_paid + (float) $validated['amount'];
            $status = $paymentService->handle($newPaid, (float) $playerSub->amount_owed);

            $transaction = Transaction::create([
                'amount' => $validated['amount'],
                'transaction_date' => now(),
                'transaction_type' => 'income',
                'category' => $validated['category'],
                'description' => $validated['description'] ?? null,
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'related_entity_type' => 'Player',
                'related_entity_id' => $player->id,
                'recorded_by_user_id' => $request->user()?->id,
                'status' => $status,
                'fiscal_year' => $playerSub->year,
            ]);

            $playerSub->update([
                'amount_paid' => $newPaid,
                'transaction_id' => $transaction->id,
            ]);
        });

        return redirect()->route('players.show', $player)
            ->with('success', 'Payment recorded successfully.');
    }

    public function update(Request $request, Player $player, Transaction $transaction): RedirectResponse
    {
        // Guard against IDOR: the transaction must belong to this player.
        if ($transaction->related_entity_type !== 'Player' || (int) $transaction->related_entity_id !== (int) $player->id) {
            abort(403, 'Transaction does not belong to this player.');
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $transaction->update($validated);

        return redirect()->route('players.show', $player)
            ->with('success', 'Payment updated successfully.');
    }
}
