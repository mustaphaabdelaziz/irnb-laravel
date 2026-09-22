<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\Finance\DefaultRegisterResolver;
use App\Services\Finance\RecalculatePlayerDebtService;
use App\Services\Finance\ResolvePaymentStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlayerTransactionController extends Controller
{
    public function store(Request $request, Player $player): RedirectResponse
    {
        $validated = $request->validate([
            'player_subscription_id' => ['nullable', 'integer', 'exists:player_subscriptions,id'],
            'subscription_id' => ['nullable', 'integer', 'exists:subscriptions,id'],
            'amount' => ['required_if:category,donation', 'required_if:category,debt_payment', 'nullable', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:subscription,donation,debt_payment'],
            'description' => ['nullable', 'string'],
            'is_exempt' => ['nullable', 'boolean'],
            'finance_account_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_accounts', 'id')->where('is_active', true),
            ],
        ]);

        $userId = $request->user()?->id;
        $method = $validated['payment_method'] ?? null;
        $description = $validated['description'] ?? null;
        $submittedFinanceAccountId = isset($validated['finance_account_id'])
            ? (int) $validated['finance_account_id']
            : null;

        if ($validated['category'] === 'subscription') {
            // The selectable list is the whole catalog, so a chosen subscription may
            // not be assigned to this player yet — assign it on demand.
            $sub = $this->resolvePlayerSubscription($player, $validated);

            // The Exempt checkbox is the single control for the flag (set or clear).
            $exempt = (bool) ($validated['is_exempt'] ?? false);
            $sub->update(['is_exempt' => $exempt]);

            // Exempt waives the subscription — no payment is recorded.
            if (! $exempt && ! empty($validated['amount'])) {
                $financeAccountId = $submittedFinanceAccountId ?? $this->defaultRegisterId($player, $sub);
                $this->createSubscriptionPayment($sub, $player, (float) $validated['amount'], $method, $description, $userId, $financeAccountId);
            }

            app(RecalculatePlayerDebtService::class)->forPlayer($player);
        } else {
            $financeAccountId = $submittedFinanceAccountId ?? $this->defaultRegisterId($player);

            DB::transaction(fn () => $this->createPlayerLevelPayment(
                $player,
                $validated['category'],
                (float) $validated['amount'],
                $method,
                $description,
                $userId,
                $financeAccountId,
            ));
        }

        return redirect()->route('players.show', $player)
            ->with('success', 'flash.payment_recorded');
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
            'finance_account_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_accounts', 'id')->where('is_active', true),
            ],
        ]);

        $userId = $request->user()?->id;
        $method = $validated['payment_method'] ?? null;
        $description = $validated['description'] ?? null;
        $financeAccountId = isset($validated['finance_account_id'])
            ? (int) $validated['finance_account_id']
            : $transaction->finance_account_id;

        DB::transaction(function () use ($transaction, $player, $validated, $method, $description, $userId, $financeAccountId) {
            $transaction->update(['archived' => true]);

            if ($transaction->category === 'subscription' && $transaction->player_subscription_id) {
                $sub = PlayerSubscription::find($transaction->player_subscription_id);
                if ($sub) {
                    // amount_paid was recomputed (minus the archived tx) by the observer.
                    $sub->refresh();
                    $this->createSubscriptionPayment($sub, $player, (float) $validated['amount'], $method, $description, $userId, $financeAccountId);
                }
            } else {
                $this->createPlayerLevelPayment($player, $transaction->category, (float) $validated['amount'], $method, $description, $userId, $financeAccountId);
            }
        });

        app(RecalculatePlayerDebtService::class)->forPlayer($player);

        return redirect()->route('players.show', $player)
            ->with('success', 'flash.payment_updated');
    }

    /** Remove = archive (soft): the row leaves the table but stays for audit. */
    public function destroy(Player $player, Transaction $transaction): RedirectResponse
    {
        $this->assertTransactionBelongsToPlayer($transaction, $player);

        $transaction->update(['archived' => true]);

        app(RecalculatePlayerDebtService::class)->forPlayer($player);

        return redirect()->route('players.show', $player)
            ->with('success', 'flash.payment_removed');
    }

    /**
     * Record a subscription payment. Any amount beyond the subscription's remaining
     * balance is split off into a separate player-level donation.
     */
    private function createSubscriptionPayment(
        PlayerSubscription $sub,
        Player $player,
        float $amount,
        ?string $method,
        ?string $description,
        ?int $userId,
        ?int $financeAccountId,
    ): void {
        DB::transaction(function () use ($sub, $player, $amount, $method, $description, $userId, $financeAccountId) {
            // remaining_amount is discount- and exemption-aware: only what is
            // genuinely still owed may land on the subscription, the rest is a donation.
            $remaining = (float) $sub->remaining_amount;
            $subPortion = min($amount, $remaining);
            $donationPortion = round($amount - $subPortion, 2);

            if ($subPortion > 0) {
                // Status is judged against what is owed after any discount, so paying
                // the net in full stamps the payment Paid rather than Partial.
                $status = app(ResolvePaymentStatusService::class)
                    ->handle((float) $sub->amount_paid + $subPortion, (float) $sub->net_owed);

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
                    'finance_account_id' => $financeAccountId,
                    'status' => $status,
                    'fiscal_year' => $sub->year,
                ]);

                $sub->update(['transaction_id' => $transaction->id]);
            }

            if ($donationPortion > 0) {
                $this->createPlayerLevelPayment($player, 'donation', $donationPortion, $method, $description, $userId, $financeAccountId);
            }
        });
    }

    /**
     * Resolve the PlayerSubscription for a subscription payment. Accepts either an
     * existing player_subscription_id, or a catalog subscription_id which is assigned
     * to the player on demand (first payment against a not-yet-assigned subscription)
     * when it applies to the player's category.
     */
    private function resolvePlayerSubscription(Player $player, array $validated): PlayerSubscription
    {
        if (! empty($validated['player_subscription_id'])) {
            $sub = PlayerSubscription::findOrFail($validated['player_subscription_id']);
            $this->assertSubBelongsToPlayer($sub, $player);

            return $sub;
        }

        if (! empty($validated['subscription_id'])) {
            $catalog = Subscription::findOrFail($validated['subscription_id']);
            $existing = PlayerSubscription::query()
                ->where('player_id', $player->id)
                ->where('subscription_id', $catalog->id)
                ->first();

            if ($existing) {
                return $existing;
            }

            if (! $catalog->appliesToCategory($player->category_id)) {
                throw ValidationException::withMessages([
                    'subscription_id' => __('This subscription does not apply to this player\'s category.'),
                ]);
            }

            return $catalog->assignTo($player);
        }

        throw ValidationException::withMessages([
            'subscription_id' => 'A subscription is required.',
        ]);
    }

    private function createPlayerLevelPayment(
        Player $player,
        string $category,
        float $amount,
        ?string $method,
        ?string $description,
        ?int $userId,
        ?int $financeAccountId,
    ): void {
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
            'finance_account_id' => $financeAccountId,
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

    /**
     * Where a payment lands when the form left the register empty: the register
     * of a single-branch, single-category subscription, else the player's default.
     */
    private function defaultRegisterId(Player $player, ?PlayerSubscription $sub = null): ?int
    {
        $registers = DefaultRegisterResolver::load();
        $player->loadMissing('branches:id');
        $subscription = $sub?->loadMissing(['subscription.branches:id', 'subscription.categories:id'])->subscription;

        $register = ($subscription ? $registers->forSubscription($subscription) : null)
            ?? $registers->forPlayer($player);

        return $register?->id;
    }

    private function assertTransactionBelongsToPlayer(Transaction $transaction, Player $player): void
    {
        if ($transaction->related_entity_type !== 'Player' || (int) $transaction->related_entity_id !== (int) $player->id) {
            abort(403, 'Transaction does not belong to this player.');
        }
    }
}
