<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Services\Finance\RecalculatePlayerDebtService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerSubscriptionController extends Controller
{
    public function __construct(private RecalculatePlayerDebtService $debt)
    {
    }

    /**
     * Record a manual/previous debt: an obligation not tied to a subscription plan,
     * e.g. debt carried over from before the app. Only the remaining amount owed is
     * entered, so it starts "unpaid" and is paid down through the normal payment flow.
     */
    public function store(Request $request, Player $player): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'amount_owed' => ['required', 'numeric', 'min:0.01'],
            'year' => ['required', 'integer', 'min:1900', 'max:2999'],
            'due_date' => ['nullable', 'date'],
            'is_exempt' => ['nullable', 'boolean'],
        ]);

        PlayerSubscription::create([
            'player_id' => $player->id,
            'subscription_id' => null,
            'label' => $validated['label'],
            'transaction_id' => null,
            'year' => $validated['year'],
            'status_at_time' => $player->is_student ? 'student' : 'worker',
            'is_mandatory' => true,
            'is_legacy' => true,
            'is_exempt' => $request->boolean('is_exempt'),
            'amount_owed' => $validated['amount_owed'],
            'amount_paid' => 0,
            'due_date' => $validated['due_date'] ?? null,
        ]);

        $this->debt->forPlayer($player);

        return back()->with('success', 'Previous debt added.');
    }

    public function update(Request $request, Player $player, PlayerSubscription $playerSubscription): RedirectResponse
    {
        $this->ensureOwnership($player, $playerSubscription);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2999'],
            'amount_owed' => ['required', 'numeric', 'min:0'],
            'is_exempt' => ['nullable', 'boolean'],
            'due_date' => ['nullable', 'date'],
            'discount_type' => ['nullable', 'in:percent,amount'],
            'discount_value' => [
                'nullable',
                'required_with:discount_type',
                'numeric',
                'min:0',
                // A percentage over 100 would mean giving money back.
                Rule::when($request->input('discount_type') === 'percent', ['max:100']),
            ],
        ]);

        $discountType = $validated['discount_type'] ?? null;

        $playerSubscription->fill([
            'amount_owed' => $validated['amount_owed'],
            'is_exempt' => $request->boolean('is_exempt'),
            'due_date' => $validated['due_date'] ?? null,
            // Clearing the type clears the value, so no orphan discount is left behind.
            'discount_type' => $discountType,
            'discount_value' => $discountType ? $validated['discount_value'] : null,
        ]);

        // Label/year are only editable on manual debts (no attached subscription plan).
        if ($playerSubscription->subscription_id === null) {
            if (array_key_exists('label', $validated)) {
                $playerSubscription->label = $validated['label'];
            }
            if (! empty($validated['year'])) {
                $playerSubscription->year = $validated['year'];
            }
        }

        $playerSubscription->save();

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
