<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Finance\DefaultRegisterResolver;
use App\Services\Finance\PlayerPaymentRecorder;
use App\Services\Finance\RecalculatePlayerDebtService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlayerTransactionController extends Controller
{
    public function __construct(private readonly PlayerPaymentRecorder $payments) {}

    public function store(Request $request, Player $player): RedirectResponse
    {
        $validated = $request->validate([
            'player_subscription_id' => ['nullable', 'integer', 'exists:player_subscriptions,id'],
            'subscription_id' => ['nullable', 'integer', 'exists:subscriptions,id'],
            // Several obligations paid at once, in the order the money settles them.
            'payables' => ['nullable', 'array', 'max:50'],
            'payables.*.subscription_id' => ['nullable', 'integer', 'exists:subscriptions,id'],
            'payables.*.player_subscription_id' => ['nullable', 'integer', 'exists:player_subscriptions,id'],
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
            $subs = $this->resolvePayables($player, $validated);

            // The Exempt checkbox is the single control for the flag (set or clear).
            $exempt = (bool) ($validated['is_exempt'] ?? false);
            $subs->each->update(['is_exempt' => $exempt]);

            // Exempt waives the subscriptions — no payment is recorded.
            if (! $exempt && ! empty($validated['amount'])) {
                $single = $subs->count() === 1 ? $subs->first() : null;
                $financeAccountId = $submittedFinanceAccountId ?? $this->defaultRegisterId($player, $single);
                $amount = (float) $validated['amount'];

                DB::transaction(fn () => $this->recordPayment(
                    $request,
                    ActivityAction::PAYMENT_RECORDED,
                    $single
                        ? $this->payments->subscription($single, $player, $amount, $method, $description, $userId, $financeAccountId)
                        : $this->payments->group($subs, $player, $amount, $method, $description, $userId, $financeAccountId),
                ));
            }

            app(RecalculatePlayerDebtService::class)->forPlayer($player);
        } else {
            $financeAccountId = $submittedFinanceAccountId ?? $this->defaultRegisterId($player);

            DB::transaction(fn () => $this->recordPayment($request, ActivityAction::PAYMENT_RECORDED, [
                $this->payments->playerLevel(
                    $player,
                    $validated['category'],
                    (float) $validated['amount'],
                    $method,
                    $description,
                    $userId,
                    $financeAccountId,
                ),
            ]));
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

        DB::transaction(function () use ($request, $transaction, $player, $validated, $method, $description, $userId, $financeAccountId) {
            $transaction->update(['archived' => true]);
            $created = [];
            // The replacement is the same payment corrected, so it keeps the
            // day the money came in — and with it, its season.
            $date = $transaction->transaction_date;

            if ($transaction->category === 'subscription' && $transaction->player_subscription_id) {
                $sub = PlayerSubscription::find($transaction->player_subscription_id);
                if ($sub) {
                    // amount_paid was recomputed (minus the archived tx) by the observer.
                    $sub->refresh();
                    $created = $this->payments->subscription($sub, $player, (float) $validated['amount'], $method, $description, $userId, $financeAccountId, $date);
                }
            } else {
                $created = [$this->payments->playerLevel($player, $transaction->category, (float) $validated['amount'], $method, $description, $userId, $financeAccountId, $date)];
            }

            // Still part of the payment it was taken with: the grouped receipt keeps listing it.
            if ($transaction->payment_group && $created !== []) {
                Transaction::whereKey(array_map(fn (Transaction $t) => $t->id, $created))
                    ->update(['payment_group' => $transaction->payment_group]);
            }

            // One event for the edit: the archive-and-recreate is not a
            // cancellation plus a new payment.
            $this->recordPayment($request, ActivityAction::PAYMENT_EDITED, $created);
        });

        app(RecalculatePlayerDebtService::class)->forPlayer($player);

        return redirect()->route('players.show', $player)
            ->with('success', 'flash.payment_updated');
    }

    /** Remove = archive (soft): the row leaves the table but stays for audit. */
    public function destroy(Request $request, Player $player, Transaction $transaction): RedirectResponse
    {
        $this->assertTransactionBelongsToPlayer($transaction, $player);

        // Already archived: nothing changes, so no second cancellation is recorded.
        if (! $transaction->archived) {
            DB::transaction(function () use ($request, $transaction) {
                $transaction->update(['archived' => true]);

                ActivityRecorder::record($request->user(), ActivityAction::TRANSACTION_CANCELLED, $transaction, [
                    'amount' => (float) $transaction->amount,
                ]);
            });
        }

        app(RecalculatePlayerDebtService::class)->forPlayer($player);

        return redirect()->route('players.show', $player)
            ->with('success', 'flash.payment_removed');
    }

    /**
     * One payment event for the transactions a request created: the subject is
     * the main one (listed first), the amount is their total. Nothing created →
     * nothing recorded.
     *
     * @param  list<Transaction>  $created
     */
    private function recordPayment(Request $request, string $action, array $created): void
    {
        if ($created === []) {
            return;
        }

        $total = array_sum(array_map(fn (Transaction $t) => (float) $t->amount, $created));

        ActivityRecorder::record($request->user(), $action, $created[0], [
            'amount' => round($total, 2),
        ]);
    }

    /**
     * The obligations a subscription payment settles, in the order picked, each
     * once: the payables list, or the single subscription_id / player_subscription_id.
     * Nothing is assigned when one of them does not apply to the player.
     *
     * @return Collection<int, PlayerSubscription>
     */
    private function resolvePayables(Player $player, array $validated): Collection
    {
        $items = $validated['payables'] ?? [];
        if ($items === []) {
            $items = [Arr::only($validated, ['player_subscription_id', 'subscription_id'])];
        }

        return DB::transaction(fn () => collect($items)
            ->map(fn (array $item) => $this->resolvePlayerSubscription($player, $item))
            ->unique('id')
            ->values());
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

            if (! $catalog->appliesTo($player)) {
                throw ValidationException::withMessages([
                    'subscription_id' => __('This subscription does not apply to this player\'s category or branch.'),
                ]);
            }

            return $catalog->assignTo($player);
        }

        throw ValidationException::withMessages([
            'subscription_id' => 'A subscription is required.',
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
     * of a one-branch subscription, else the player's default.
     */
    private function defaultRegisterId(Player $player, ?PlayerSubscription $sub = null): ?int
    {
        $registers = DefaultRegisterResolver::load();
        $player->loadMissing('registrations');
        $subscription = $sub?->loadMissing(['subscription.branches:id', 'subscription.categories:id'])->subscription;

        $register = ($subscription ? $registers->forSubscription($subscription, $player) : null)
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
