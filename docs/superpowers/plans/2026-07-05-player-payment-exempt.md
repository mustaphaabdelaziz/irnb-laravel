# Player Payment Form + Subscription Exemption Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the player profile record subscription payments, player-level donations, and player-level legacy debt payments, and let staff mark a subscription exempt via an explicit flag.

**Architecture:** Backend `PlayerTransactionController::store` branches on payment category — subscription payments keep the existing pay-down flow; donation/debt payments become player-level income `Transaction`s with `player_subscription_id = null`. Exemption moves off the "has a donation payment" heuristic onto a real `is_exempt` boolean on `player_subscriptions`, toggled by a new nested endpoint and a per-row button. The Vue `Players/Show.vue` modal gains a category selector and a filtered subscription list.

**Tech Stack:** Laravel 11 (PHP 8.2+), Inertia + Vue 3, vue-i18n, Vite, PHPUnit (`#[Test]` attributes, `RefreshDatabase`).

## Global Constraints

- Money amounts validate `min:0.01`; donation/debt amounts are uncapped.
- Payment categories are exactly `subscription`, `donation`, `debt_payment`.
- Exempt subscriptions must be: not unpaid, not counted in `outstanding_debt`, and generate no income.
- Reuse the existing `TransactionObserver` (auto-fills `fiscal_year_id`, `finance_category_id`, `finance_account_id`; no-ops recompute when `player_subscription_id` is null).
- `outstanding_debt` is a cached column refreshed via `App\Services\Finance\RecalculatePlayerDebtService`.
- Backend tests: `php artisan test`. Frontend verification: `npm run build` (no JS test runner in this project).
- Follow existing test helpers in `tests/Feature/SubscriptionBillingTest.php` (`makePlayer`, `makeSub`, `pay`).

---

### Task 1: `is_exempt` flag — migration + model

Move exemption from "has a non-archived donation payment" to an explicit boolean, backfilling existing exemptions so live data keeps its current exempt subscriptions.

**Files:**
- Create: `database/migrations/2026_07_05_000001_add_is_exempt_to_player_subscriptions.php`
- Modify: `app/Models/PlayerSubscription.php`
- Test: `tests/Feature/SubscriptionBillingTest.php` (replace one test, add two)

**Interfaces:**
- Produces: `PlayerSubscription.is_exempt` (bool, fillable, cast); `PlayerSubscription::isExempt(): bool` now returns `(bool) $this->is_exempt`. `remaining_amount` and `payment_status` accessors are unchanged and continue to call `isExempt()`.

- [ ] **Step 1: Replace the donation-exemption test with a flag test in `tests/Feature/SubscriptionBillingTest.php`**

Delete the existing `a_donation_payment_exempts_the_subscription` test (lines 90-101) and add these three tests in its place:

```php
#[Test]
public function an_exempt_subscription_is_excluded_from_debt(): void
{
    $player = $this->makePlayer();
    $sub = $this->makeSub($player, 2000);
    app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());
    $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);

    $sub->update(['is_exempt' => true]);
    app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());

    $this->assertTrue($sub->fresh()->isExempt());
    $this->assertSame('exempt', $sub->fresh()->payment_status);
    $this->assertSame(0.0, (float) $sub->fresh()->remaining_amount);
    $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);
}

#[Test]
public function a_donation_payment_no_longer_exempts_the_subscription(): void
{
    $player = $this->makePlayer();
    $sub = $this->makeSub($player, 2000);
    $this->pay($sub, 100, 'donation');

    app(RecalculatePlayerDebtService::class)->forSubscription($sub->fresh());

    // Donation is now player-level income; it does not waive the subscription.
    $this->assertFalse($sub->fresh()->isExempt());
}

#[Test]
public function existing_donation_exemptions_are_backfilled(): void
{
    $player = $this->makePlayer();
    $sub = $this->makeSub($player, 2000);
    // Simulate a pre-migration donation-based exemption: a donation payment
    // exists but is_exempt was reset to its default so we can prove the
    // backfill logic re-flags it.
    $this->pay($sub, 100, 'donation');
    $sub->forceFill(['is_exempt' => false])->saveQuietly();

    // Re-run the backfill query the migration performs.
    \Illuminate\Support\Facades\DB::table('player_subscriptions')
        ->whereIn('id', function ($q) {
            $q->select('player_subscription_id')->from('transactions')
                ->where('category', 'donation')->where('archived', false)
                ->whereNotNull('player_subscription_id');
        })
        ->update(['is_exempt' => true]);

    $this->assertTrue($sub->fresh()->isExempt());
}
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `php artisan test --filter=SubscriptionBillingTest`
Expected: FAIL — `is_exempt` column/attribute does not exist (SQL error or the assertions fail).

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_05_000001_add_is_exempt_to_player_subscriptions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_subscriptions', function (Blueprint $table) {
            $table->boolean('is_exempt')->default(false)->after('is_mandatory');
        });

        // Preserve existing exemptions: any subscription that currently has a
        // non-archived donation payment was exempt under the old isExempt() rule.
        DB::table('player_subscriptions')
            ->whereIn('id', function ($q) {
                $q->select('player_subscription_id')->from('transactions')
                    ->where('category', 'donation')
                    ->where('archived', false)
                    ->whereNotNull('player_subscription_id');
            })
            ->update(['is_exempt' => true]);
    }

    public function down(): void
    {
        Schema::table('player_subscriptions', function (Blueprint $table) {
            $table->dropColumn('is_exempt');
        });
    }
};
```

- [ ] **Step 4: Update the model `app/Models/PlayerSubscription.php`**

Add `'is_exempt'` to `$fillable` (after `'is_mandatory'`):

```php
        'is_mandatory',
        'is_exempt',
```

Add the cast inside `casts()` (after `'is_mandatory' => 'boolean',`):

```php
            'is_mandatory' => 'boolean',
            'is_exempt' => 'boolean',
```

Replace the body of `isExempt()`:

```php
    public function isExempt(): bool
    {
        return (bool) $this->is_exempt;
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=SubscriptionBillingTest`
Expected: PASS (all tests in the file, including the three new ones).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_05_000001_add_is_exempt_to_player_subscriptions.php app/Models/PlayerSubscription.php tests/Feature/SubscriptionBillingTest.php
git commit -m "feat: explicit is_exempt flag on player_subscriptions"
```

---

### Task 2: Exempt toggle endpoint

Add a nested route + controller that flips `is_exempt` and refreshes the player's cached debt.

**Files:**
- Create: `app/Http/Controllers/PlayerSubscriptionController.php`
- Modify: `routes/web.php:77` (add near the other player-nested routes)
- Test: `tests/Feature/PlayerSubscriptionExemptTest.php`

**Interfaces:**
- Consumes: `PlayerSubscription.is_exempt` (Task 1), `RecalculatePlayerDebtService::forPlayer(Player)`.
- Produces: route `players.subscriptions.exempt` — `PATCH /players/{player}/subscriptions/{subscription}/exempt`, method `PlayerSubscriptionController::exempt(Player $player, PlayerSubscription $subscription)`.

- [ ] **Step 1: Write the failing test `tests/Feature/PlayerSubscriptionExemptTest.php`**

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\User;
use App\Services\Finance\RecalculatePlayerDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerSubscriptionExemptTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function makePlayer(): Player
    {
        return Player::create([
            'membership_id' => '900000001',
            'firstname' => 'Ex', 'lastname' => 'Empt',
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
    }

    private function makeSub(Player $player): PlayerSubscription
    {
        return PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => null, 'transaction_id' => null,
            'year' => (int) now()->year, 'status_at_time' => 'student',
            'is_mandatory' => true, 'amount_owed' => 2000, 'amount_paid' => 0,
        ]);
    }

    #[Test]
    public function exempt_toggles_the_flag_and_clears_debt(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player);
        app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);

        $this->actingAs($this->admin())
            ->patch(route('players.subscriptions.exempt', [$player, $sub]))
            ->assertRedirect();

        $this->assertTrue((bool) $sub->fresh()->is_exempt);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);

        // Toggling again un-exempts and restores debt.
        $this->actingAs($this->admin())
            ->patch(route('players.subscriptions.exempt', [$player, $sub]))
            ->assertRedirect();

        $this->assertFalse((bool) $sub->fresh()->is_exempt);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function cannot_exempt_a_subscription_of_another_player(): void
    {
        $player = $this->makePlayer();
        $other = Player::create([
            'membership_id' => '900000002', 'firstname' => 'Other', 'lastname' => 'Guy',
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
        $sub = $this->makeSub($other);

        $this->actingAs($this->admin())
            ->patch(route('players.subscriptions.exempt', [$player, $sub]))
            ->assertForbidden();

        $this->assertFalse((bool) $sub->fresh()->is_exempt);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=PlayerSubscriptionExemptTest`
Expected: FAIL — route `players.subscriptions.exempt` is not defined.

- [ ] **Step 3: Create the controller `app/Http/Controllers/PlayerSubscriptionController.php`**

```php
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
```

- [ ] **Step 4: Register the route in `routes/web.php`**

Directly after the `players.transactions.update` route (line 78), add:

```php
    // Player subscription exemption (nested)
    Route::patch('/players/{player}/subscriptions/{subscription}/exempt', [PlayerSubscriptionController::class, 'exempt'])->name('players.subscriptions.exempt');
```

Add the import near the other controller imports at the top of the file:

```php
use App\Http\Controllers\PlayerSubscriptionController;
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=PlayerSubscriptionExemptTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/PlayerSubscriptionController.php routes/web.php tests/Feature/PlayerSubscriptionExemptTest.php
git commit -m "feat: exempt toggle endpoint for player subscriptions"
```

---

### Task 3: Player-level donation & debt payments (backend)

Make `player_subscription_id` conditional and branch `store` so donation/debt payments are recorded as player-level income.

**Files:**
- Modify: `app/Http/Controllers/PlayerTransactionController.php:15-59`
- Test: `tests/Feature/PlayerLevelPaymentTest.php`

**Interfaces:**
- Consumes: existing `players.transactions.store` route, `ResolvePaymentStatusService`, `TransactionObserver`.
- Produces: `store` accepts `category ∈ {subscription, donation, debt_payment}`; `player_subscription_id` required only when `category = subscription`.

- [ ] **Step 1: Write the failing test `tests/Feature/PlayerLevelPaymentTest.php`**

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\RecalculatePlayerDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerLevelPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function makePlayer(): Player
    {
        return Player::create([
            'membership_id' => '900000009', 'firstname' => 'Pay', 'lastname' => 'Level',
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
    }

    #[Test]
    public function a_donation_is_recorded_player_level_with_no_subscription(): void
    {
        $player = $this->makePlayer();

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'amount' => 500,
                'category' => 'donation',
                'payment_method' => 'cash',
            ])
            ->assertRedirect();

        $tx = Transaction::firstOrFail();
        $this->assertSame('donation', $tx->category);
        $this->assertNull($tx->player_subscription_id);
        $this->assertSame('income', $tx->transaction_type);
        $this->assertSame('Paid', $tx->status);
        $this->assertSame('Player', $tx->related_entity_type);
        $this->assertSame($player->id, (int) $tx->related_entity_id);
        $this->assertSame(500.0, (float) $tx->amount);
    }

    #[Test]
    public function a_debt_payment_is_recorded_player_level_and_does_not_touch_subscription_debt(): void
    {
        $player = $this->makePlayer();
        $sub = PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => null, 'transaction_id' => null,
            'year' => (int) now()->year, 'status_at_time' => 'student',
            'is_mandatory' => true, 'amount_owed' => 2000, 'amount_paid' => 0,
        ]);
        app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'amount' => 700,
                'category' => 'debt_payment',
                'payment_method' => 'cash',
            ])
            ->assertRedirect();

        $tx = Transaction::firstOrFail();
        $this->assertSame('debt_payment', $tx->category);
        $this->assertNull($tx->player_subscription_id);
        // Legacy debt payment is flat income; the subscription balance is untouched.
        $this->assertSame(0.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function a_subscription_payment_still_requires_a_subscription(): void
    {
        $player = $this->makePlayer();

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'amount' => 700,
                'category' => 'subscription',
                'payment_method' => 'cash',
            ])
            ->assertSessionHasErrors('player_subscription_id');

        $this->assertDatabaseCount('transactions', 0);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=PlayerLevelPaymentTest`
Expected: FAIL — donation/debt currently require `player_subscription_id` (validation error), so the redirect assertions fail.

- [ ] **Step 3: Rewrite `store` in `app/Http/Controllers/PlayerTransactionController.php`**

Replace the whole `store` method (lines 15-59) with:

```php
    public function store(Request $request, Player $player): RedirectResponse
    {
        $validated = $request->validate([
            'player_subscription_id' => ['required_if:category,subscription', 'nullable', 'integer', 'exists:player_subscriptions,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:subscription,donation,debt_payment'],
            'description' => ['nullable', 'string'],
        ]);

        if ($validated['category'] === 'subscription') {
            $this->recordSubscriptionPayment($request, $player, $validated);
        } else {
            $this->recordPlayerLevelPayment($request, $player, $validated);
        }

        return redirect()->route('players.show', $player)
            ->with('success', 'Payment recorded successfully.');
    }

    private function recordSubscriptionPayment(Request $request, Player $player, array $validated): void
    {
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
                'player_subscription_id' => $playerSub->id,
                'recorded_by_user_id' => $request->user()?->id,
                'status' => $status,
                'fiscal_year' => $playerSub->year,
            ]);

            $playerSub->update([
                'transaction_id' => $transaction->id,
            ]);
        });
    }

    private function recordPlayerLevelPayment(Request $request, Player $player, array $validated): void
    {
        DB::transaction(function () use ($validated, $player, $request) {
            Transaction::create([
                'amount' => $validated['amount'],
                'transaction_date' => now(),
                'transaction_type' => 'income',
                'category' => $validated['category'],
                'description' => $validated['description'] ?? null,
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'related_entity_type' => 'Player',
                'related_entity_id' => $player->id,
                'player_subscription_id' => null,
                'recorded_by_user_id' => $request->user()?->id,
                'status' => 'Paid',
                'fiscal_year' => (int) now()->year,
            ]);
        });
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=PlayerLevelPaymentTest`
Expected: PASS.

- [ ] **Step 5: Run the full billing suite to confirm no regressions**

Run: `php artisan test --filter=SubscriptionBillingTest`
Expected: PASS (subscription payment path unchanged).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/PlayerTransactionController.php tests/Feature/PlayerLevelPaymentTest.php
git commit -m "feat: player-level donation and legacy debt payments"
```

---

### Task 4: Add-Payment modal — category selector + filtered subscriptions (frontend)

Add the category `<select>`, show the subscription `<select>` only for subscription payments, and list only payable (obligatory + optional, non-fully-paid, non-exempt) subscriptions.

**Files:**
- Modify: `resources/js/Pages/Players/Show.vue` (script: `paymentForm`, add computed + watcher; template: modal lines ~221-258)
- Modify: `resources/js/i18n/en.json`, `resources/js/i18n/fr.json`, `resources/js/i18n/ar.json`

**Interfaces:**
- Consumes: backend `store` category branching (Task 3); `player.player_subscriptions[*].remaining_amount`, `.is_mandatory`.
- Produces: `paymentForm.category` drives which fields submit; no interface consumed by later tasks.

- [ ] **Step 1: Add i18n keys**

In `resources/js/i18n/en.json` add (alongside existing keys):

```json
    "payment_category": "Payment category",
    "debt_payment": "Debt payment",
    "optional": "optional",
```

In `resources/js/i18n/fr.json` add:

```json
    "payment_category": "Type de paiement",
    "debt_payment": "Paiement de dette",
    "optional": "facultatif",
```

In `resources/js/i18n/ar.json` add:

```json
    "payment_category": "نوع الدفع",
    "debt_payment": "دفع دين",
    "optional": "اختياري",
```

- [ ] **Step 2: Add the `payableSubscriptions` computed and a category watcher in the `<script setup>` of `resources/js/Pages/Players/Show.vue`**

After the `transactions` computed (line 25), add:

```js
const payableSubscriptions = computed(() =>
    subscriptions.value.filter(s => parseFloat(s.remaining_amount ?? 0) > 0)
);
```

After the `paymentForm` definition (after line 36), add a watcher that clears the subscription when the category is not a subscription payment. Add `watch` to the existing `vue` import on line 14 (`import { ref, computed, watch } from 'vue';`), then:

```js
watch(() => paymentForm.category, (category) => {
    if (category !== 'subscription') {
        paymentForm.player_subscription_id = '';
    }
});
```

- [ ] **Step 3: Add the category selector and gate the subscription field in the modal**

In `resources/js/Pages/Players/Show.vue`, inside the modal's `<div class="mt-4 space-y-4">` (line 225), make the category selector the FIRST child (before the subscription `<div>` at line 226):

```html
                    <div>
                        <InputLabel :value="t('payment_category')" />
                        <select v-model="paymentForm.category" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="subscription">{{ t('subscription') }}</option>
                            <option value="donation">{{ t('donation') }}</option>
                            <option value="debt_payment">{{ t('debt_payment') }}</option>
                        </select>
                    </div>
```

Then replace the existing subscription `<div>` (lines 226-234) with a gated version that iterates `payableSubscriptions` and marks optional subs:

```html
                    <div v-if="paymentForm.category === 'subscription'">
                        <InputLabel :value="t('subscription')" />
                        <select v-model="paymentForm.player_subscription_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="">{{ t('select_subscription') }}</option>
                            <option v-for="sub in payableSubscriptions" :key="sub.id" :value="sub.id">
                                {{ sub.subscription?.name }} ({{ sub.subscription?.year }}){{ sub.is_mandatory ? '' : ' — ' + t('optional') }} — {{ t('remaining') }}: {{ formatMoney(sub.remaining_amount) }}
                            </option>
                        </select>
                        <InputError :message="paymentForm.errors.player_subscription_id" class="mt-1" />
                    </div>
```

- [ ] **Step 4: Build to verify the frontend compiles**

Run: `npm run build`
Expected: build completes with no errors.

- [ ] **Step 5: Manual verification**

Open a player profile → Add Payment. Confirm: category selector shows the three options; switching to Donation/Debt payment hides the subscription field; the subscription list shows obligatory and optional (labelled) subs and omits fully-paid/exempt ones.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Players/Show.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json
git commit -m "feat: payment category selector and filtered subscription list"
```

---

### Task 5: Subscriptions table — Exempt button + status (frontend)

Add a per-row Exempt/Un-exempt check button and render the exempt status correctly.

**Files:**
- Modify: `resources/js/Pages/Players/Show.vue` (script: `paymentStatus` + `toggleExempt`; template: subscriptions table rows ~172-185)
- Modify: `resources/js/i18n/en.json`, `resources/js/i18n/fr.json`, `resources/js/i18n/ar.json`

**Interfaces:**
- Consumes: `players.subscriptions.exempt` route (Task 2); `sub.is_exempt`.
- Produces: none.

- [ ] **Step 1: Add i18n keys**

In `resources/js/i18n/en.json`:

```json
    "exempt": "Exempt",
    "exempt_remove": "Remove exemption",
```

In `resources/js/i18n/fr.json`:

```json
    "exempt": "Exempté",
    "exempt_remove": "Retirer l'exemption",
```

In `resources/js/i18n/ar.json`:

```json
    "exempt": "معفى",
    "exempt_remove": "إلغاء الإعفاء",
```

- [ ] **Step 2: Add `toggleExempt` and fix `paymentStatus` in `<script setup>`**

Replace the `paymentStatus` function (lines 51-57) so exemption takes precedence:

```js
function paymentStatus(sub) {
    if (sub.is_exempt) return 'exempt';
    const remaining = parseFloat(sub.remaining_amount ?? 0);
    const paid = parseFloat(sub.amount_paid ?? 0);
    if (remaining <= 0) return 'paid';
    if (paid > 0) return 'partial';
    return 'unpaid';
}
```

After `deletePlayer` (line 49), add:

```js
function toggleExempt(sub) {
    router.patch(route('players.subscriptions.exempt', [props.player.id, sub.id]), {}, {
        preserveScroll: true,
    });
}
```

- [ ] **Step 3: Add the Exempt button to each subscription row**

In the subscriptions table body, the current row (lines 172-185) ends with a status `<td>` holding `<Badge :label="paymentStatus(sub)" ... />`. Add an actions `<td>` immediately after that status cell:

```html
                                <td class="px-4 py-3 text-sm">
                                    <button
                                        type="button"
                                        @click="toggleExempt(sub)"
                                        class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset"
                                        :class="sub.is_exempt
                                            ? 'bg-slate-100 text-slate-700 ring-slate-300 dark:bg-slate-700 dark:text-slate-200 dark:ring-slate-600'
                                            : 'text-slate-500 ring-slate-300 hover:bg-slate-50 dark:text-slate-400 dark:ring-slate-600 dark:hover:bg-slate-800'"
                                        :title="sub.is_exempt ? t('exempt_remove') : t('exempt')"
                                    >
                                        <span v-if="sub.is_exempt">✓</span>
                                        {{ t('exempt') }}
                                    </button>
                                </td>
```

Add a matching header cell in the table `<thead>` after the status header (near line 163-166, the last `<th>` before `</tr>`):

```html
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400"></th>
```

- [ ] **Step 4: Build to verify the frontend compiles**

Run: `npm run build`
Expected: build completes with no errors.

- [ ] **Step 5: Manual verification**

On a player profile, click Exempt on a subscription row → the row shows the exempt badge (slate) and the debt total drops; the button shows a check and its title flips to "Remove exemption". Click again → reverts.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Players/Show.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json
git commit -m "feat: per-subscription exempt toggle button in player profile"
```

---

### Task 6: Show all player transactions in the profile history

Player-level donation/debt payments have no linked subscription, so the current history table (derived from `subscriptions.map(s => s.transaction)`) would never show them — and that derivation also shows only one transaction per subscription. Pass the full transaction list from the controller and render it.

**Files:**
- Modify: `app/Http/Controllers/PlayerController.php:145-163` (`show`)
- Modify: `resources/js/Pages/Players/Show.vue` (script: `props`, `transactions` computed)
- Test: `tests/Feature/PlayerLevelPaymentTest.php` (add one assertion-style test)

**Interfaces:**
- Consumes: `Transaction` rows with `related_entity_type = 'Player'`, `related_entity_id = player.id` (produced by Task 3 and the existing subscription flow).
- Produces: Inertia prop `transactions` (array) on `Players/Show`.

- [ ] **Step 1: Write the failing test in `tests/Feature/PlayerLevelPaymentTest.php`**

Add this test to the existing class:

```php
#[Test]
public function player_profile_receives_player_level_transactions(): void
{
    $player = $this->makePlayer();

    $this->actingAs($this->admin())
        ->post(route('players.transactions.store', $player), [
            'amount' => 500, 'category' => 'donation', 'payment_method' => 'cash',
        ])->assertRedirect();

    $this->actingAs($this->admin())
        ->get(route('players.show', $player))
        ->assertInertia(fn ($page) => $page
            ->component('Players/Show')
            ->has('transactions', 1)
            ->where('transactions.0.category', 'donation'));
}
```

Note: this uses Inertia's testing assertions (`Inertia\Testing\AssertableInertia` via `assertInertia`), already available in Laravel + Inertia projects.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=player_profile_receives_player_level_transactions`
Expected: FAIL — the `transactions` prop does not exist on the `Players/Show` response.

- [ ] **Step 3: Pass transactions from `PlayerController::show`**

In `app/Http/Controllers/PlayerController.php`, add the `Transaction` import at the top with the other model imports:

```php
use App\Models\Transaction;
```

Replace the `return Inertia::render('Players/Show', [...])` block in `show()` (lines 159-162) with:

```php
        $transactions = Transaction::query()
            ->where('related_entity_type', 'Player')
            ->where('related_entity_id', $player->id)
            ->where('archived', false)
            ->orderByDesc('transaction_date')
            ->get();

        return Inertia::render('Players/Show', [
            'player' => $player,
            'transactions' => $transactions,
            'totalDebt' => $player->calculateTotalDebt(),
        ]);
```

- [ ] **Step 4: Consume the prop in `resources/js/Pages/Players/Show.vue`**

Add `transactions` to `defineProps` (after `totalDebt: Number,` on line 21):

```js
    totalDebt: Number,
    transactions: { type: Array, default: () => [] },
```

Replace the `transactions` computed (line 25) so it uses the prop instead of deriving from subscriptions:

```js
const transactions = computed(() => props.transactions ?? []);
```

- [ ] **Step 5: Run the test + build**

Run: `php artisan test --filter=PlayerLevelPaymentTest`
Expected: PASS.

Run: `npm run build`
Expected: build completes with no errors.

- [ ] **Step 6: Manual verification**

Record a donation and a debt payment on a player, then reload the profile: both appear in Transaction history (with `+` income styling), alongside subscription payments.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/PlayerController.php resources/js/Pages/Players/Show.vue tests/Feature/PlayerLevelPaymentTest.php
git commit -m "feat: show all player transactions (incl. donations, debt payments) in profile"
```

---

## Self-Review Notes

- **Spec coverage:** category selector (Task 4) ✓; player-level donation + legacy debt (Task 3) ✓; filtered obligatory+optional, hide paid/exempt list (Task 4) ✓; explicit `is_exempt` flag + backfill (Task 1) ✓; exempt endpoint (Task 2) ✓; exempt button + status fix (Task 5) ✓; i18n keys (Tasks 4-5) ✓.
- **Behavior change:** removing donation-based exemption breaks the old `a_donation_payment_exempts_the_subscription` test — Task 1 Step 1 replaces it explicitly.
- **Type consistency:** `is_exempt` (bool) is defined in Task 1 and consumed identically in Tasks 2, 4, 5; route name `players.subscriptions.exempt` defined in Task 2 and used in Task 5; `payableSubscriptions` defined and used within Task 4.
- **Visibility gap:** player-level donations/debt payments would otherwise never render on the profile — Task 6 replaces the subscription-derived history with the full player transaction list.
