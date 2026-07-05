<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Services\Finance\RecalculatePlayerDebtService;
use App\Services\Finance\ResolvePaymentStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlayerTransactionController extends Controller
{
    public function store(Request $request, Player $player): RedirectResponse
    {
        $validated = $request->validate([
            'player_subscription_id' => ['required_if:category,subscription', 'nullable', 'integer', 'exists:player_subscriptions,id'],
            'amount' => ['required_if:category,donation', 'required_if:category,debt_payment', 'nullable', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:subscription,donation,debt_payment'],
            'description' => ['nullable', 'string'],
            'is_exempt' => ['nullable', 'boolean'],
        ]);

        $userId = $request->user()?->id;
        $method = $validated['payment_method'] ?? null;
        $description = $validated['description'] ?? null;

        if ($validated['category'] === 'subscription') {
            $sub = PlayerSubscription::findOrFail($validated['player_subscription_id']);
            $this->assertSubBelongsToPlayer($sub, $player);

            // The Exempt checkbox is the single control for the flag (set or clear).
            $exempt = (bool) ($validated['is_exempt'] ?? false);
            $sub->update(['is_exempt' => $exempt]);

            // Exempt waives the subscription — no payment is recorded.
            if (! $exempt && ! empty($validated['amount'])) {
                $this->createSubscriptionPayment($sub, $player, (float) $validated['amount'], $method, $description, $userId);
            }

            app(RecalculatePlayerDebtService::class)->forPlayer($player);
        } else {
            DB::transaction(fn () => $this->createPlayerLevelPayment(
                $player, $validated['category'], (float) $validated['amount'], $method, $description, $userId
            ));
        }

        return redirect()->route('players.show', $player)
            ->with('success', 'Payment recorded successfully.');
    }

    /**
     * Edit = cancel + recreate: archive the original transaction and record a fresh one
     * from the edited values, re-running the subscription split or player-level path.
     */
    public function update(Request $request, Player $player, Transaction $transaction): RedirectResponse
    {
        $this->assertTransactionBelongsToPlayer($transaction, $player);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $userId = $request->user()?->id;
        $method = $validated['payment_method'] ?? null;
        $description = $validated['description'] ?? null;

        DB::transaction(function () use ($transaction, $player, $validated, $method, $description, $userId) {
            $transaction->update(['archived' => true]);

            if ($transaction->category === 'subscription' && $transaction->player_subscription_id) {
                $sub = PlayerSubscription::find($transaction->player_subscription_id);
                if ($sub) {
                    // amount_paid was recomputed (minus the archived tx) by the observer.
                    $sub->refresh();
                    $this->createSubscriptionPayment($sub, $player, (float) $validated['amount'], $method, $description, $userId);
                }
            } else {
                $this->createPlayerLevelPayment($player, $transaction->category, (float) $validated['amount'], $method, $description, $userId);
            }
        });

        app(RecalculatePlayerDebtService::class)->forPlayer($player);

        return redirect()->route('players.show', $player)
            ->with('success', 'Payment updated successfully.');
    }

    /** Remove = archive (soft): the row leaves the table but stays for audit. */
    public function destroy(Player $player, Transaction $transaction): RedirectResponse
    {
        $this->assertTransactionBelongsToPlayer($transaction, $player);

        $transaction->update(['archived' => true]);

        app(RecalculatePlayerDebtService::class)->forPlayer($player);

        return redirect()->route('players.show', $player)
            ->with('success', 'Payment removed.');
    }

    /**
     * Record a subscription payment. Any amount beyond the subscription's remaining
     * balance is split off into a separate player-level donation.
     */
    private function createSubscriptionPayment(PlayerSubscription $sub, Player $player, float $amount, ?string $method, ?string $description, ?int $userId): void
    {
        DB::transaction(function () use ($sub, $player, $amount, $method, $description, $userId) {
            $remaining = max(0.0, (float) $sub->amount_owed - (float) $sub->amount_paid);
            $subPortion = min($amount, $remaining);
            $donationPortion = round($amount - $subPortion, 2);

            if ($subPortion > 0) {
                $status = app(ResolvePaymentStatusService::class)
                    ->handle((float) $sub->amount_paid + $subPortion, (float) $sub->amount_owed);

                $transaction = Transaction::create([
                    'amount' => $subPortion,
                    'transaction_date' => now(),
                    'transaction_type' => 'income',
                    'category' => 'subscription',
                    'description' => $description,
                    'payment_method' => $method ?? 'cash',
                    'related_entity_type' => 'Player',
                    'related_entity_id' => $player->id,
                    'player_subscription_id' => $sub->id,
                    'recorded_by_user_id' => $userId,
                    'status' => $status,
                    'fiscal_year' => $sub->year,
                ]);

                $sub->update(['transaction_id' => $transaction->id]);
            }

            if ($donationPortion > 0) {
                $this->createPlayerLevelPayment($player, 'donation', $donationPortion, $method, $description, $userId);
            }
        });
    }

    private function createPlayerLevelPayment(Player $player, string $category, float $amount, ?string $method, ?string $description, ?int $userId): void
    {
        Transaction::create([
            'amount' => $amount,
            'transaction_date' => now(),
            'transaction_type' => 'income',
            'category' => $category,
            'description' => $description,
            'payment_method' => $method ?? 'cash',
            'related_entity_type' => 'Player',
            'related_entity_id' => $player->id,
            'player_subscription_id' => null,
            'recorded_by_user_id' => $userId,
            'status' => 'Paid',
            'fiscal_year' => (int) now()->year,
        ]);
    }

    private function assertSubBelongsToPlayer(PlayerSubscription $sub, Player $player): void
    {
        if ((int) $sub->player_id !== (int) $player->id) {
            abort(403, 'Subscription does not belong to this player.');
        }
    }

    private function assertTransactionBelongsToPlayer(Transaction $transaction, Player $player): void
    {
        if ($transaction->related_entity_type !== 'Player' || (int) $transaction->related_entity_id !== (int) $player->id) {
            abort(403, 'Transaction does not belong to this player.');
        }
    }
}
