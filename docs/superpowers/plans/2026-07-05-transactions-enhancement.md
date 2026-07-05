# Transactions Enhancement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the Transactions UI to the existing `finance_categories` system (income/expense split + manageable categories), add CCP/bank payment-detail fields, a searchable player select, and a filtered stats bar — and fix the player subscription/payment flow to a correct cash-basis model (bugs F1–F6).

**Architecture:** Two phases. Phase 1 makes money correct: subscriptions are obligations (never income), payments are the only income transactions and link to their subscription via a new FK, and one recomputed `players.outstanding_debt` cache feeds every debt reader. Phase 2 is UI: transaction forms/index consume `finance_categories`, reveal CCP/bank fields, use a searchable player combobox, and show income/expense/net/debt tiles.

**Tech Stack:** Laravel 12 (PHP 8.2+), PHPUnit 12 (`php artisan test`), Inertia + Vue 3 (`<script setup>`), Tailwind, vue-i18n. No new PHP or JS dependencies.

## Global Constraints

- Money model is **cash-basis**: income = money actually received. A subscription is a debt until paid and is **never** itself an income transaction. (Spec §G.)
- **Debt = mandatory subscriptions only.** Optional subs are tracked and displayed but excluded from debt. (Spec §G.)
- No new dependencies (PHP or npm). Reuse existing components (`StatCard`, `InputLabel`, `TextInput`, `InputError`, `Icon`, `ConfirmModal`).
- Currency is DZD; format money with the existing `useFormatMoney` composable in Vue.
- Vue files use `<script setup>` + `vue-i18n` `t()`; every user-facing string goes through `t()` with keys added to `en.json`, `fr.json`, `ar.json`.
- Follow existing patterns: FormRequest validation, `Inertia::render`, `route()` names, `archived` boolean (not soft deletes).
- Tests: PHPUnit with `#[Test]` attribute + `RefreshDatabase`. Run a single test with `php artisan test --filter=TestName`.
- Existing bill marker (a subscription bill, not a real payment): `transaction_type='income'` AND `category='subscription'` AND `status='Unpaid'`. A recorded payment is never `Unpaid`.

---

## File Structure

**Phase 1 — correctness**
- Create `database/migrations/2026_07_05_000001_add_subscription_payment_links.php` — adds `transactions.player_subscription_id`, `player_subscriptions.is_mandatory`.
- Create `app/Services/Finance/RecalculatePlayerDebtService.php` — single source of truth for `amount_paid` + `outstanding_debt`.
- Create `app/Console/Commands/FixSubscriptionBilling.php` — one-time cleanup (link + snapshot + recompute always; delete bills on `--force`).
- Modify `app/Models/Transaction.php` — fillable + `playerSubscription()` relation.
- Modify `app/Models/PlayerSubscription.php` — fillable + `payments()` relation + exemption-aware accessors.
- Modify `app/Models/Player.php` — `calculateTotalDebt()` = mandatory + non-exempt rule.
- Modify `app/Observers/TransactionObserver.php` — recompute the linked subscription on save/delete.
- Modify `app/Services/Player/RegisterPlayerService.php` — no bill; snapshot `is_mandatory`; recompute.
- Modify `app/Http/Controllers/SubscriptionController.php` — `assign`/`assignOne`: no bill; snapshot; recompute.
- Modify `app/Http/Controllers/PlayerTransactionController.php` — link `player_subscription_id`; drop manual math.
- Modify `app/Http/Controllers/PlayerController.php` — index reads `outstanding_debt` as `total_debt`.
- Modify `app/Http/Controllers/DashboardController.php` — debt from `outstanding_debt` cache.
- Modify tests: `tests/Feature/Services/RegisterPlayerServiceTest.php`.
- Create tests: `tests/Feature/SubscriptionBillingTest.php`, `tests/Feature/Console/FixSubscriptionBillingTest.php`.

**Phase 2 — UI**
- Create `database/migrations/2026_07_05_000002_add_payment_detail_fields_to_transactions.php`.
- Modify `app/Models/Transaction.php` — fillable payment-detail columns.
- Modify `app/Http/Requests/Transaction/StoreTransactionRequest.php` — finance-category + payment-detail rules.
- Modify `app/Http/Controllers/TransactionController.php` — pass categories/club-CCP; stats; derive legacy `category`; finance-category filter.
- Create `resources/js/Components/SearchableSelect.vue`.
- Create `resources/js/Components/CategoryManager.vue`.
- Create `resources/js/Pages/Transactions/Partials/TransactionForm.vue`.
- Modify `resources/js/Pages/Transactions/Create.vue`, `Edit.vue` — render the shared form.
- Modify `resources/js/Pages/Transactions/Index.vue` — stats bar, finance-category filter/column, category-manager launch.
- Modify `resources/js/i18n/en.json`, `fr.json`, `ar.json`.
- Create test: `tests/Feature/TransactionFinanceCategoryTest.php`.

---

# PHASE 1 — Subscription / payment correctness

### Task 1: Schema — link payments to subscriptions + snapshot mandatory

**Files:**
- Create: `database/migrations/2026_07_05_000001_add_subscription_payment_links.php`
- Modify: `app/Models/Transaction.php` (fillable + relation)
- Modify: `app/Models/PlayerSubscription.php` (fillable + relation)

**Interfaces:**
- Produces: `transactions.player_subscription_id` (nullable FK), `player_subscriptions.is_mandatory` (bool, default true). `Transaction::playerSubscription()` belongsTo; `PlayerSubscription::payments()` hasMany(Transaction, 'player_subscription_id').

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_07_05_000001_add_subscription_payment_links.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('player_subscription_id')->nullable()->after('related_entity_id')
                ->constrained('player_subscriptions')->nullOnDelete();
            $table->index('player_subscription_id');
        });

        Schema::table('player_subscriptions', function (Blueprint $table) {
            $table->boolean('is_mandatory')->default(true)->after('status_at_time');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('player_subscription_id');
        });
        Schema::table('player_subscriptions', function (Blueprint $table) {
            $table->dropColumn('is_mandatory');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: migrates `2026_07_05_000001_add_subscription_payment_links` with no error.

- [ ] **Step 3: Add fillable + relation to `Transaction`**

In `app/Models/Transaction.php`, add `'player_subscription_id'` to `$fillable` (after `'related_entity_id'`), and add this relation next to the other `belongsTo` methods:

```php
public function playerSubscription(): BelongsTo
{
    return $this->belongsTo(PlayerSubscription::class);
}
```

- [ ] **Step 4: Add fillable + relation to `PlayerSubscription`**

In `app/Models/PlayerSubscription.php`, add `'is_mandatory'` to `$fillable`, cast it, and add a `payments()` relation:

```php
// in $fillable: add 'is_mandatory'
// in casts(): add 'is_mandatory' => 'boolean',

public function payments(): HasMany
{
    return $this->hasMany(Transaction::class, 'player_subscription_id');
}
```

Add `use Illuminate\Database\Eloquent\Relations\HasMany;` if not present.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_05_000001_add_subscription_payment_links.php app/Models/Transaction.php app/Models/PlayerSubscription.php
git commit -m "feat: link payments to subscriptions and snapshot is_mandatory"
```

---

### Task 2: Recompute service + exemption-aware model rules

**Files:**
- Create: `app/Services/Finance/RecalculatePlayerDebtService.php`
- Modify: `app/Models/PlayerSubscription.php` (accessors use `payments`)
- Modify: `app/Models/Player.php` (`calculateTotalDebt()`)
- Test: `tests/Feature/SubscriptionBillingTest.php`

**Interfaces:**
- Consumes: `PlayerSubscription::payments()`, `PlayerSubscription::$is_mandatory`, `ResolvePaymentStatusService::handle()`.
- Produces:
  - `RecalculatePlayerDebtService::forSubscription(PlayerSubscription $sub): void` — sets `amount_paid` (= sum of linked non-archived payments) then calls `forPlayer`.
  - `RecalculatePlayerDebtService::forPlayer(Player $player): void` — sets `players.outstanding_debt` = sum of `remaining_amount` over the player's **mandatory** subs.
  - `PlayerSubscription::isExempt(): bool` — true if any linked non-archived payment has `category='donation'`.
  - `Player::calculateTotalDebt(): float` — mandatory + non-exempt remaining.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SubscriptionBillingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\Finance\RecalculatePlayerDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function makePlayer(float $debt = 0): Player
    {
        return Player::create([
            'membership_id' => '9'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => 'P'.$this->seq,
            'lastname' => 'Test',
            'is_student' => true,
            'outstanding_debt' => $debt,
        ]);
    }

    private function makeSub(Player $player, float $owed, bool $mandatory = true): PlayerSubscription
    {
        return PlayerSubscription::create([
            'player_id' => $player->id,
            'subscription_id' => null,
            'transaction_id' => null,
            'year' => (int) now()->year,
            'status_at_time' => 'student',
            'is_mandatory' => $mandatory,
            'amount_owed' => $owed,
            'amount_paid' => 0,
        ]);
    }

    private function pay(PlayerSubscription $sub, float $amount, string $category = 'subscription'): Transaction
    {
        return Transaction::create([
            'amount' => $amount,
            'transaction_date' => now(),
            'transaction_type' => 'income',
            'category' => $category,
            'status' => 'Paid',
            'related_entity_type' => 'Player',
            'related_entity_id' => $sub->player_id,
            'player_subscription_id' => $sub->id,
            'fiscal_year' => $sub->year,
        ]);
    }

    #[Test]
    public function amount_paid_is_the_sum_of_linked_payments(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);

        $this->pay($sub, 500);
        $this->pay($sub, 300);

        app(RecalculatePlayerDebtService::class)->forSubscription($sub->fresh());

        $this->assertSame(800.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(1200.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function optional_subscriptions_are_excluded_from_debt(): void
    {
        $player = $this->makePlayer();
        $mandatory = $this->makeSub($player, 2000, true);
        $optional = $this->makeSub($player, 1500, false);

        app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());

        // only the mandatory 2000 counts
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function a_donation_payment_exempts_the_subscription(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);
        $this->pay($sub, 100, 'donation');

        app(RecalculatePlayerDebtService::class)->forSubscription($sub->fresh());

        $this->assertTrue($sub->fresh()->isExempt());
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=SubscriptionBillingTest`
Expected: FAIL (class `RecalculatePlayerDebtService` not found / `isExempt` undefined).

- [ ] **Step 3: Update `PlayerSubscription` accessors**

In `app/Models/PlayerSubscription.php`, replace `getRemainingAmountAttribute` / `getPaymentStatusAttribute` and add `isExempt`:

```php
public function isExempt(): bool
{
    return $this->payments->firstWhere(fn ($t) => ! $t->archived && $t->category === 'donation') !== null;
}

public function getRemainingAmountAttribute(): float
{
    if ($this->isExempt()) {
        return 0.0;
    }

    return max(0.0, (float) $this->amount_owed - (float) $this->amount_paid);
}

public function getPaymentStatusAttribute(): string
{
    if ($this->isExempt()) {
        return 'exempt';
    }
    if ($this->getRemainingAmountAttribute() <= 0 && (float) $this->amount_paid > 0) {
        return 'paid';
    }
    if ((float) $this->amount_paid > 0) {
        return 'partial';
    }

    return 'unpaid';
}
```

Note: `isExempt()` reads the `payments` relation collection; callers that iterate many subs should eager-load `payments` (done in Task 7's controllers).

- [ ] **Step 4: Update `Player::calculateTotalDebt()`**

In `app/Models/Player.php`, replace the method body:

```php
public function calculateTotalDebt(): float
{
    return (float) $this->playerSubscriptions()
        ->where('is_mandatory', true)
        ->with('payments')
        ->get()
        ->sum(fn (PlayerSubscription $sub) => $sub->remaining_amount);
}
```

- [ ] **Step 5: Create the service**

Create `app/Services/Finance/RecalculatePlayerDebtService.php`:

```php
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
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=SubscriptionBillingTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Finance/RecalculatePlayerDebtService.php app/Models/PlayerSubscription.php app/Models/Player.php tests/Feature/SubscriptionBillingTest.php
git commit -m "feat: recompute service + mandatory/exempt debt rules"
```

---

### Task 3: Observer recomputes the linked subscription on payment change

**Files:**
- Modify: `app/Observers/TransactionObserver.php`
- Test: `tests/Feature/SubscriptionBillingTest.php` (add cases)

**Interfaces:**
- Consumes: `RecalculatePlayerDebtService::forSubscription`.
- Produces: saving/deleting a `Transaction` with `player_subscription_id` recomputes that subscription + its player automatically.

- [ ] **Step 1: Add failing tests (edit/delete resync)**

Append to `tests/Feature/SubscriptionBillingTest.php`:

```php
    #[Test]
    public function editing_a_payment_amount_resyncs_debt(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);
        $payment = $this->pay($sub, 1000);

        $this->assertSame(1000.0, (float) $player->fresh()->outstanding_debt);

        $payment->update(['amount' => 500]);

        $this->assertSame(500.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(1500.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function archiving_a_payment_raises_debt_again(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);
        $payment = $this->pay($sub, 2000);

        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);

        $payment->update(['archived' => true]);

        $this->assertSame(0.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=SubscriptionBillingTest`
Expected: the two new tests FAIL (observer not recomputing).

- [ ] **Step 3: Hook the observer**

In `app/Observers/TransactionObserver.php`, add the import `use App\Models\PlayerSubscription;` and `use App\Services\Finance\RecalculatePlayerDebtService;`, then call a new helper from `saved` and `deleted`:

```php
public function saved(Transaction $transaction): void
{
    $this->recompute($transaction);
    $this->recomputeSubscription($transaction);
}

public function deleted(Transaction $transaction): void
{
    $this->recompute($transaction);
    $this->recomputeSubscription($transaction);
}

private function recomputeSubscription(Transaction $transaction): void
{
    if (! $transaction->player_subscription_id) {
        return;
    }

    $sub = PlayerSubscription::find($transaction->player_subscription_id);
    if ($sub) {
        app(RecalculatePlayerDebtService::class)->forSubscription($sub);
    }
}
```

(The service uses `saveQuietly()` so this does not re-trigger the observer.)

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter=SubscriptionBillingTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Observers/TransactionObserver.php tests/Feature/SubscriptionBillingTest.php
git commit -m "feat: recompute subscription debt on payment save/delete"
```

---

### Task 4: Registration creates the obligation only (no bill)

**Files:**
- Modify: `app/Services/Player/RegisterPlayerService.php`
- Modify: `tests/Feature/Services/RegisterPlayerServiceTest.php`

**Interfaces:**
- Consumes: `RecalculatePlayerDebtService::forPlayer`.
- Produces: registering a player creates `PlayerSubscription` rows (`is_mandatory=true`, `amount_paid=0`, `transaction_id=null`) and **no** transaction; `outstanding_debt` reflects the mandatory owed total.

- [ ] **Step 1: Update the existing test to the new behavior**

In `tests/Feature/Services/RegisterPlayerServiceTest.php`, replace the assertions block (lines ~49–56) with:

```php
        $this->assertDatabaseCount('player_subscriptions', 1);
        $this->assertDatabaseCount('transactions', 0);

        $playerSubscription = PlayerSubscription::query()->firstOrFail();

        $this->assertSame((float) $subscription->amount_student, (float) $playerSubscription->amount_owed);
        $this->assertTrue((bool) $playerSubscription->is_mandatory);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
```

Remove the now-unused `use App\Models\Transaction;` import if the file no longer references it.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=RegisterPlayerServiceTest`
Expected: FAIL (transactions count is currently 1; `is_mandatory`/`outstanding_debt` not set).

- [ ] **Step 3: Update `RegisterPlayerService`**

In `app/Services/Player/RegisterPlayerService.php`, inside the `foreach ($subscriptions as $subscription)` loop, delete the `Transaction::query()->create([...])` block and its `$transaction` variable, and change the `PlayerSubscription::create` call to:

```php
PlayerSubscription::query()->create([
    'player_id' => $player->id,
    'subscription_id' => $subscription->id,
    'transaction_id' => null,
    'year' => (int) $subscription->year,
    'status_at_time' => $isStudent ? 'student' : 'worker',
    'is_mandatory' => true,
    'amount_owed' => $amountOwed,
    'amount_paid' => 0,
]);
```

After the loop (still inside the DB transaction, before `return`), recompute:

```php
app(\App\Services\Finance\RecalculatePlayerDebtService::class)->forPlayer($player);
```

Remove the now-unused `use App\Models\Transaction;` import.

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter=RegisterPlayerServiceTest`
Expected: PASS.

- [ ] **Step 5: Run the player index + import tests (regression)**

Run: `php artisan test --filter=PlayerIndexDebtTest && php artisan test --filter=PlayerImportTest`
Expected: PASS. (These do not assert on the removed bill; PlayerIndexDebtTest's `total_debt=2000` still holds — verified once Task 7 makes the index read the cache. If run before Task 7 it may still pass via the existing subquery; if it fails, proceed and re-run after Task 7.)

- [ ] **Step 6: Commit**

```bash
git add app/Services/Player/RegisterPlayerService.php tests/Feature/Services/RegisterPlayerServiceTest.php
git commit -m "feat: registration creates subscription obligation without a bill (F1)"
```

---

### Task 5: `assign` / `assignOne` create the obligation only (no bill)

**Files:**
- Modify: `app/Http/Controllers/SubscriptionController.php`
- Test: `tests/Feature/SubscriptionBillingTest.php` (add cases)

**Interfaces:**
- Produces: assigning a subscription creates a `PlayerSubscription` (`is_mandatory` snapshot from the subscription) and no transaction; recomputes the player's debt. Optional assignment leaves `outstanding_debt` unchanged.

- [ ] **Step 1: Add failing tests**

Append to `tests/Feature/SubscriptionBillingTest.php` (add `use App\Models\User;` at top):

```php
    #[Test]
    public function assigning_optional_subscription_does_not_add_debt_or_income(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
        $player = $this->makePlayer();
        $optional = Subscription::create([
            'name' => 'Camp', 'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('subscriptions.assignOne', $optional), ['player_id' => $player->id])
            ->assertRedirect();

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('player_subscriptions', 1);
        $this->assertFalse((bool) PlayerSubscription::first()->is_mandatory);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function assigning_mandatory_subscription_adds_debt_without_income(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
        $player = $this->makePlayer();
        $mandatory = Subscription::create([
            'name' => 'Annual', 'year' => (int) now()->year,
            'amount_student' => 2000, 'amount_worker' => 3000,
            'is_mandatory' => true, 'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('subscriptions.assignOne', $mandatory), ['player_id' => $player->id])
            ->assertRedirect();

        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=SubscriptionBillingTest`
Expected: the two new tests FAIL (a bill transaction is created; `is_mandatory`/`outstanding_debt` not set).

- [ ] **Step 3: Fix `assign()`**

In `app/Http/Controllers/SubscriptionController.php`, inside `assign()`'s `foreach ($newPlayerIds as $playerId)` loop, delete the `Transaction::create([...])` block and change the `PlayerSubscription::create` call to:

```php
PlayerSubscription::create([
    'player_id' => $player->id,
    'subscription_id' => $subscription->id,
    'transaction_id' => null,
    'year' => $subscription->year,
    'status_at_time' => $player->is_student ? 'student' : 'worker',
    'is_mandatory' => (bool) $subscription->is_mandatory,
    'amount_owed' => $amountOwed,
    'amount_paid' => 0,
]);
app(\App\Services\Finance\RecalculatePlayerDebtService::class)->forPlayer($player);
```

- [ ] **Step 4: Fix `assignOne()`**

In `assignOne()`, delete the `Transaction::create([...])` block and change the `PlayerSubscription::create` call to the same shape:

```php
PlayerSubscription::create([
    'player_id' => $player->id,
    'subscription_id' => $subscription->id,
    'transaction_id' => null,
    'year' => $subscription->year,
    'status_at_time' => $player->is_student ? 'student' : 'worker',
    'is_mandatory' => (bool) $subscription->is_mandatory,
    'amount_owed' => $amountOwed,
    'amount_paid' => 0,
]);
app(\App\Services\Finance\RecalculatePlayerDebtService::class)->forPlayer($player);
```

Leave the `Transaction` import in place only if still used elsewhere in the file; otherwise remove it.

- [ ] **Step 5: Run to verify pass**

Run: `php artisan test --filter=SubscriptionBillingTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/SubscriptionController.php tests/Feature/SubscriptionBillingTest.php
git commit -m "feat: assign/assignOne create obligation without a bill (F1)"
```

---

### Task 6: Player payment records link to the subscription

**Files:**
- Modify: `app/Http/Controllers/PlayerTransactionController.php`
- Test: `tests/Feature/SubscriptionBillingTest.php` (add case)

**Interfaces:**
- Produces: `PlayerTransactionController@store` sets `player_subscription_id` and no longer hand-increments `amount_paid` (the observer recompute is authoritative). `@update` resyncs via the observer.

- [ ] **Step 1: Add failing test (store links + recomputes)**

Append to `tests/Feature/SubscriptionBillingTest.php`:

```php
    #[Test]
    public function recording_a_payment_links_it_and_lowers_debt(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);
        app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());

        $this->actingAs($admin)
            ->post(route('players.transactions.store', $player), [
                'player_subscription_id' => $sub->id,
                'amount' => 800,
                'category' => 'subscription',
                'payment_method' => 'cash',
            ])
            ->assertRedirect();

        $payment = Transaction::firstOrFail();
        $this->assertSame($sub->id, $payment->player_subscription_id);
        $this->assertSame(800.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(1200.0, (float) $player->fresh()->outstanding_debt);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=SubscriptionBillingTest`
Expected: new test FAIL (`player_subscription_id` is null on the payment).

- [ ] **Step 3: Update `store()`**

In `app/Http/Controllers/PlayerTransactionController.php`, inside the `DB::transaction` closure of `store()`, add `'player_subscription_id' => $playerSub->id,` to the `Transaction::create([...])` array, and change the subscription update so it no longer hand-computes `amount_paid` (the observer recompute owns it):

```php
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

$playerSub->update(['transaction_id' => $transaction->id]);
```

(`$status` is still computed for the transaction's own `status` field. `amount_paid` is recomputed by the observer once the payment is saved.)

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter=SubscriptionBillingTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PlayerTransactionController.php tests/Feature/SubscriptionBillingTest.php
git commit -m "feat: link player payments to their subscription (F3/F4/F5)"
```

---

### Task 7: Unify all debt readers on the cache

**Files:**
- Modify: `app/Http/Controllers/PlayerController.php` (index subquery → cache; show eager-load `payments`)
- Modify: `app/Http/Controllers/DashboardController.php` (debt from cache)
- Test: `tests/Feature/PlayerIndexDebtTest.php` (still green), `tests/Feature/DashboardTest.php` (still green)

**Interfaces:**
- Consumes: `players.outstanding_debt` (kept current by Tasks 2–6).
- Produces: index passes `players.data.*.total_debt` from `outstanding_debt`; dashboard `stats.outstandingDebt` + `unpaidPlayers` from the cache.

- [ ] **Step 1: Replace the index debt subquery**

In `app/Http/Controllers/PlayerController.php@index`, delete the `$debtSubquery` heredoc and the `->selectRaw("$debtSubquery as total_debt")`; select the cached column with the same alias so the Vue contract (`player.total_debt`) is unchanged:

```php
$query = Player::query()
    ->select('players.*')
    ->selectRaw('players.outstanding_debt as total_debt')
    ->with(['category', 'position', 'memberJob']);
```

- [ ] **Step 2: Point show at the same rule (eager-load payments)**

In `PlayerController@show`, change the `playerSubscriptions.transaction` eager-load to also load `payments` so the exemption accessor does not N+1:

```php
$player->load([
    'category',
    'position',
    'memberJob',
    'emergencyContacts',
    'achievements',
    'playerSubscriptions.subscription',
    'playerSubscriptions.transaction',
    'playerSubscriptions.payments',
    'equipmentRentals.equipmentItem.catalog',
]);
```

`'totalDebt' => $player->calculateTotalDebt()` stays (live, mandatory + non-exempt) and equals `outstanding_debt`.

- [ ] **Step 3: Replace the dashboard debt computation**

In `app/Http/Controllers/DashboardController.php@__invoke`, delete the `$debtExpression`, `$playerDebtSubquery`, and the `$outstandingDebt` subquery block, and compute from the cache:

```php
$totalPlayers = Player::query()->where('archived', false)->count();
$unpaidPlayers = Player::query()->where('archived', false)->where('outstanding_debt', '>', 0)->count();
$paidPlayers = max(0, $totalPlayers - $unpaidPlayers);
$outstandingDebt = (float) Player::query()->where('archived', false)->sum('outstanding_debt');
```

Remove the now-unused `use Illuminate\Support\Facades\DB;` only if nothing else in the file uses `DB`.

- [ ] **Step 4: Run the debt + dashboard tests**

Run: `php artisan test --filter=PlayerIndexDebtTest && php artisan test --filter=DashboardTest`
Expected: PASS. (`PlayerIndexDebtTest` expects `total_debt=2000`; registration now sets `outstanding_debt=2000` via Task 4, read here as `total_debt`.)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PlayerController.php app/Http/Controllers/DashboardController.php
git commit -m "feat: read cached outstanding_debt in index + dashboard (F2/F6)"
```

---

### Task 8: One-time cleanup command

**Files:**
- Create: `app/Console/Commands/FixSubscriptionBilling.php`
- Test: `tests/Feature/Console/FixSubscriptionBillingTest.php`

**Interfaces:**
- Produces: `php artisan finance:fix-subscription-billing [--force]`. Always (non-destructive): backfill `is_mandatory`, link real payments to subs by `player_id`+`fiscal_year` (excluding `Unpaid` bills), recompute all debts, print a reconciliation report. Only with `--force`: delete the bill transactions (income + category=subscription + status=Unpaid).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Console/FixSubscriptionBillingTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FixSubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function makePlayer(float $debt = 0): Player
    {
        return Player::create([
            'membership_id' => '9'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => 'P'.$this->seq,
            'lastname' => 'Test',
            'is_student' => true,
            'outstanding_debt' => $debt,
        ]);
    }

    private function seedLegacy(): array
    {
        $sub = Subscription::create([
            'name' => 'Annual', 'year' => 2025,
            'amount_student' => 2000, 'amount_worker' => 3000,
            'is_mandatory' => true, 'is_active' => true,
        ]);
        $player = $this->makePlayer();

        // a legacy bill (to be deleted) + a real payment (to be kept + linked)
        $bill = Transaction::create([
            'amount' => 2000, 'transaction_date' => now(), 'transaction_type' => 'income',
            'category' => 'subscription', 'status' => 'Unpaid', 'payment_account' => '/',
            'related_entity_type' => 'Player', 'related_entity_id' => $player->id, 'fiscal_year' => 2025,
        ]);
        $payment = Transaction::create([
            'amount' => 1200, 'transaction_date' => now(), 'transaction_type' => 'income',
            'category' => 'subscription', 'status' => 'Partial',
            'related_entity_type' => 'Player', 'related_entity_id' => $player->id, 'fiscal_year' => 2025,
        ]);
        $ps = PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => $sub->id, 'transaction_id' => $payment->id,
            'year' => 2025, 'status_at_time' => 'student', 'is_mandatory' => true,
            'amount_owed' => 2000, 'amount_paid' => 1200,
        ]);

        return compact('player', 'ps', 'bill', 'payment');
    }

    #[Test]
    public function dry_run_reports_but_keeps_everything(): void
    {
        $this->seedLegacy();

        $this->artisan('finance:fix-subscription-billing')->assertSuccessful();

        // dry-run: bill still present
        $this->assertDatabaseHas('transactions', ['status' => 'Unpaid', 'category' => 'subscription']);
    }

    #[Test]
    public function force_deletes_bills_links_payments_and_recomputes(): void
    {
        ['player' => $player, 'ps' => $ps, 'payment' => $payment] = $this->seedLegacy();

        $this->artisan('finance:fix-subscription-billing --force')->assertSuccessful();

        // bill gone, payment kept + linked
        $this->assertDatabaseMissing('transactions', ['status' => 'Unpaid', 'category' => 'subscription']);
        $this->assertSame($ps->id, $payment->fresh()->player_subscription_id);
        // recomputed: paid=1200, debt = 2000-1200 = 800
        $this->assertSame(1200.0, (float) $ps->fresh()->amount_paid);
        $this->assertSame(800.0, (float) $player->fresh()->outstanding_debt);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=FixSubscriptionBillingTest`
Expected: FAIL (command `finance:fix-subscription-billing` does not exist).

- [ ] **Step 3: Write the command**

Create `app/Console/Commands/FixSubscriptionBilling.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Services\Finance\RecalculatePlayerDebtService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixSubscriptionBilling extends Command
{
    protected $signature = 'finance:fix-subscription-billing {--force : Actually delete bill transactions}';

    protected $description = 'Cash-basis cleanup: drop subscription bills, link payments, recompute debts.';

    public function handle(RecalculatePlayerDebtService $debt): int
    {
        // 1. Snapshot is_mandatory from the parent subscription (nulls default true).
        $updated = DB::table('player_subscriptions')
            ->whereIn('subscription_id', function ($q) {
                $q->select('id')->from('subscriptions')->where('is_mandatory', false);
            })
            ->update(['is_mandatory' => false]);
        $this->info("Marked {$updated} subscription(s) as optional.");

        // 2. Link real payments (income, not Unpaid) to a subscription by player+year.
        $linked = 0;
        $payments = Transaction::query()
            ->where('transaction_type', 'income')
            ->where('archived', false)
            ->where('status', '!=', 'Unpaid')
            ->whereNull('player_subscription_id')
            ->where('related_entity_type', 'Player')
            ->get();

        foreach ($payments as $payment) {
            $sub = PlayerSubscription::query()
                ->where('player_id', $payment->related_entity_id)
                ->where('year', $payment->fiscal_year)
                ->orderByDesc('is_mandatory')
                ->first()
                ?? PlayerSubscription::query()->where('transaction_id', $payment->id)->first();

            if ($sub) {
                $payment->forceFill(['player_subscription_id' => $sub->id])->saveQuietly();
                $linked++;
            } else {
                $this->warn("Unresolved payment #{$payment->id} (player {$payment->related_entity_id}, year {$payment->fiscal_year}).");
            }
        }
        $this->info("Linked {$linked} payment(s) to subscriptions.");

        // 3. Identify bills (income + subscription + Unpaid).
        $billsQuery = Transaction::query()
            ->where('transaction_type', 'income')
            ->where('category', 'subscription')
            ->where('status', 'Unpaid');
        $billCount = (clone $billsQuery)->count();

        if ($this->option('force')) {
            (clone $billsQuery)->delete();
            $this->info("Deleted {$billCount} bill transaction(s).");
        } else {
            $this->warn("[dry-run] Would delete {$billCount} bill transaction(s). Re-run with --force.");
        }

        // 4. Recompute every subscription's paid + every player's debt; reconcile.
        PlayerSubscription::with('payments', 'player')->chunkById(200, function ($subs) use ($debt) {
            foreach ($subs as $sub) {
                $before = (float) $sub->amount_paid;
                $debt->forSubscription($sub);
                $after = (float) $sub->fresh()->amount_paid;
                if (abs($before - $after) > 0.001) {
                    $this->warn("Reconcile: sub #{$sub->id} amount_paid {$before} -> {$after}.");
                }
            }
        });

        Player::query()->chunkById(200, fn ($players) => $players->each(fn ($p) => $debt->forPlayer($p)));

        $this->info('Recompute complete.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter=FixSubscriptionBillingTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/FixSubscriptionBilling.php tests/Feature/Console/FixSubscriptionBillingTest.php
git commit -m "feat: finance:fix-subscription-billing cleanup command"
```

- [ ] **Step 6: Full Phase 1 regression**

Run: `php artisan test`
Expected: green. Fix any test that asserted the removed subscription bills (search tests for `'status' => 'Unpaid'` tied to subscriptions). Commit fixes if needed.

> **Deploy note (document in the PR):** run `php artisan migrate && php artisan finance:fix-subscription-billing --force` together — the migration adds the columns, and the command must run before new payments are recorded so historical `amount_paid` stays correct.

---

# PHASE 2 — Transactions UI enhancement

### Task 9: Payment-detail columns + finance-category wiring (backend)

**Files:**
- Create: `database/migrations/2026_07_05_000002_add_payment_detail_fields_to_transactions.php`
- Modify: `app/Models/Transaction.php` (fillable)
- Modify: `app/Http/Requests/Transaction/StoreTransactionRequest.php`
- Modify: `app/Http/Controllers/TransactionController.php` (`create`/`edit`/`store`/`update`)
- Test: `tests/Feature/TransactionFinanceCategoryTest.php`

**Interfaces:**
- Produces: `create`/`edit` pass `financeCategories` (`id,type,name,color`) + `clubCcp` (`{account_number,key,holder}` or null). `store`/`update` accept `finance_category_id` (type must match `transaction_type`), derive the legacy `category` string, and persist `payment_ccp_key`, `payment_bank_name`, `payment_holder`, `payment_reference`, `payment_account`.

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_07_05_000002_add_payment_detail_fields_to_transactions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('payment_ccp_key')->nullable()->after('payment_account');
            $table->string('payment_bank_name')->nullable()->after('payment_ccp_key');
            $table->string('payment_holder')->nullable()->after('payment_bank_name');
            $table->string('payment_reference')->nullable()->after('payment_holder');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['payment_ccp_key', 'payment_bank_name', 'payment_holder', 'payment_reference']);
        });
    }
};
```

Run: `php artisan migrate` → migrates without error.

- [ ] **Step 2: Add the columns to `Transaction::$fillable`**

In `app/Models/Transaction.php`, add to `$fillable`: `'payment_ccp_key'`, `'payment_bank_name'`, `'payment_holder'`, `'payment_reference'`.

- [ ] **Step 3: Write the failing test**

Create `tests/Feature/TransactionFinanceCategoryTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionFinanceCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function it_rejects_a_category_whose_type_mismatches(): void
    {
        $expenseCat = FinanceCategory::create(['type' => 'expense', 'name' => 'Rent', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->post(route('transactions.store'), [
                'transaction_type' => 'income',
                'finance_category_id' => $expenseCat->id,
                'amount' => 100,
                'transaction_date' => now()->toDateString(),
                'payment_method' => 'cash',
                'status' => 'Paid',
            ])
            ->assertSessionHasErrors('finance_category_id');
    }

    #[Test]
    public function it_stores_finance_category_and_ccp_fields(): void
    {
        $incomeCat = FinanceCategory::create(['type' => 'income', 'name' => 'Donations', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->post(route('transactions.store'), [
                'transaction_type' => 'income',
                'finance_category_id' => $incomeCat->id,
                'amount' => 500,
                'transaction_date' => now()->toDateString(),
                'payment_method' => 'ccp',
                'payment_account' => '0012345678',
                'payment_ccp_key' => '55',
                'payment_holder' => 'Club IRNB',
                'status' => 'Paid',
            ])
            ->assertRedirect();

        $tx = Transaction::firstOrFail();
        $this->assertSame($incomeCat->id, $tx->finance_category_id);
        $this->assertSame('55', $tx->payment_ccp_key);
        $this->assertSame('donations', $tx->category); // legacy string derived from the category name
    }
}
```

- [ ] **Step 4: Run to verify failure**

Run: `php artisan test --filter=TransactionFinanceCategoryTest`
Expected: FAIL (validation still requires the old string `category`).

- [ ] **Step 5: Update `StoreTransactionRequest`**

Replace `app/Http/Requests/Transaction/StoreTransactionRequest.php` `rules()`:

```php
public function rules(): array
{
    return [
        'amount' => ['required', 'numeric', 'min:0'],
        'transaction_date' => ['nullable', 'date'],
        'transaction_type' => ['required', 'in:income,expense'],
        'finance_category_id' => [
            'required',
            \Illuminate\Validation\Rule::exists('finance_categories', 'id')
                ->where('type', $this->input('transaction_type')),
        ],
        'sub_category' => ['nullable', 'string', 'max:255'],
        'payment_method' => ['nullable', 'string', 'max:255'],
        'payment_account' => ['nullable', 'string', 'max:255'],
        'payment_ccp_key' => ['nullable', 'string', 'max:255'],
        'payment_bank_name' => ['nullable', 'string', 'max:255'],
        'payment_holder' => ['nullable', 'string', 'max:255'],
        'payment_reference' => ['nullable', 'string', 'max:255'],
        'related_entity_type' => ['nullable', 'string', 'max:255'],
        'related_entity_id' => ['nullable', 'integer'],
        'description' => ['nullable', 'string'],
        'status' => ['nullable', 'in:Paid,Partial,Unpaid,Exempt'],
        'receipt' => ['nullable', 'file', 'max:10240'],
        'fiscal_year' => ['nullable', 'integer', 'min:2000', 'max:'.(date('Y') + 1)],
    ];
}
```

The `Rule::exists(...)->where('type', ...)` makes a mismatched category fail `finance_category_id`.

- [ ] **Step 6: Update `TransactionController` create/edit/store/update**

In `app/Http/Controllers/TransactionController.php`:

Add a private helper and use it in `create()` and `edit()`:

```php
use App\Models\FinanceCategory;
use App\Models\WebsiteConfig;

private function formOptions(): array
{
    return [
        'financeCategories' => FinanceCategory::where('is_active', true)
            ->orderBy('type')->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'type', 'name', 'color']),
        'clubCcp' => WebsiteConfig::query()->first()?->banking_info['ccp'] ?? null,
        'players' => Player::where('archived', false)
            ->orderBy('lastname')->orderBy('firstname')
            ->get(['id', 'firstname', 'lastname']),
    ];
}
```

`create()` returns `Inertia::render('Transactions/Create', $this->formOptions())`.
`edit()` returns `Inertia::render('Transactions/Edit', ['transaction' => $transaction, ...$this->formOptions()])`.

In `store()` and `update()`, after `$validated = $request->validated();`, derive the legacy `category` string from the chosen finance category (keeps the NOT-NULL column + legacy consumers working):

```php
$financeCategory = FinanceCategory::find($validated['finance_category_id']);
$validated['category'] = \Illuminate\Support\Str::slug($financeCategory->name, '_');
```

(Everything else in `store`/`update` — receipt handling, fiscal-year guard — stays.)

- [ ] **Step 7: Run to verify pass**

Run: `php artisan test --filter=TransactionFinanceCategoryTest`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_05_000002_add_payment_detail_fields_to_transactions.php app/Models/Transaction.php app/Http/Requests/Transaction/StoreTransactionRequest.php app/Http/Controllers/TransactionController.php tests/Feature/TransactionFinanceCategoryTest.php
git commit -m "feat: transactions use finance categories + payment detail fields (backend)"
```

---

### Task 10: Index stats bar + finance-category filter (backend)

**Files:**
- Modify: `app/Http/Controllers/TransactionController.php@index`
- Test: `tests/Feature/TransactionFinanceCategoryTest.php` (add case)

**Interfaces:**
- Produces: `index` passes `stats = {income, expense, net, debts}` (income/expense/net honor filters; debts = `SUM(players.outstanding_debt)`), eager-loads `financeCategory`, filters by `finance_category_id`, and passes `financeCategories` for the filter dropdown.

- [ ] **Step 1: Add failing test**

Append to `tests/Feature/TransactionFinanceCategoryTest.php`:

```php
    #[Test]
    public function index_exposes_filtered_stats(): void
    {
        $inc = FinanceCategory::create(['type' => 'income', 'name' => 'Donations', 'is_active' => true]);
        $exp = FinanceCategory::create(['type' => 'expense', 'name' => 'Rent', 'is_active' => true]);
        Transaction::create(['amount' => 900, 'transaction_type' => 'income', 'category' => 'donations', 'finance_category_id' => $inc->id, 'status' => 'Paid', 'transaction_date' => now(), 'fiscal_year' => now()->year]);
        Transaction::create(['amount' => 400, 'transaction_type' => 'expense', 'category' => 'rent', 'finance_category_id' => $exp->id, 'status' => 'Paid', 'transaction_date' => now(), 'fiscal_year' => now()->year]);

        $this->actingAs($this->admin())
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transactions/Index')
                ->where('stats.income', 900)
                ->where('stats.expense', 400)
                ->where('stats.net', 500)
                ->has('stats.debts'));
    }
```

Add `use Inertia\Testing\AssertableInertia;` if you prefer typed closures.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=TransactionFinanceCategoryTest`
Expected: new test FAIL (no `stats` prop).

- [ ] **Step 3: Update `index()`**

In `TransactionController@index`, after building `$query` and applying filters but **before** `paginate`, and add a `finance_category_id` filter. Replace the tail of the method:

```php
// existing string filters stay; add finance category filter:
if ($request->filled('finance_category_id')) {
    $query->where('finance_category_id', $request->input('finance_category_id'));
}

$statsBase = clone $query;
$income = (float) (clone $statsBase)->where('transaction_type', 'income')->sum('amount');
$expense = (float) (clone $statsBase)->where('transaction_type', 'expense')->sum('amount');

$transactions = $query->with(['recordedBy', 'receivedBy', 'financeCategory'])
    ->latest('transaction_date')
    ->paginate(25)
    ->withQueryString();

return Inertia::render('Transactions/Index', [
    'transactions' => $transactions,
    'filters' => $request->only(['search', 'type', 'category', 'finance_category_id', 'fiscal_year', 'status', 'date_from', 'date_to']),
    'financeCategories' => \App\Models\FinanceCategory::where('is_active', true)
        ->orderBy('type')->orderBy('sort_order')->orderBy('name')->get(['id', 'type', 'name', 'color']),
    'stats' => [
        'income' => $income,
        'expense' => $expense,
        'net' => $income - $expense,
        'debts' => (float) \App\Models\Player::where('archived', false)->sum('outstanding_debt'),
    ],
]);
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter=TransactionFinanceCategoryTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/TransactionController.php tests/Feature/TransactionFinanceCategoryTest.php
git commit -m "feat: transactions index stats bar + finance category filter (backend)"
```

---

### Task 11: `SearchableSelect` component

**Files:**
- Create: `resources/js/Components/SearchableSelect.vue`

**Interfaces:**
- Produces: `<SearchableSelect v-model="id" :options="[{value,label}]" :placeholder="..." />` — typeahead filter, keyboard nav (Up/Down/Enter/Esc), clearable; emits `update:modelValue`.

- [ ] **Step 1: Create the component**

Create `resources/js/Components/SearchableSelect.vue`:

```vue
<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    options: { type: Array, default: () => [] }, // [{ value, label }]
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const open = ref(false);
const queryText = ref('');
const active = ref(0);
const root = ref(null);

const selected = computed(() => props.options.find((o) => String(o.value) === String(props.modelValue)) || null);

const filtered = computed(() => {
    const q = queryText.value.trim().toLowerCase();
    if (!q) return props.options;
    return props.options.filter((o) => o.label.toLowerCase().includes(q));
});

function choose(option) {
    emit('update:modelValue', option ? option.value : '');
    open.value = false;
    queryText.value = '';
}

function onKey(e) {
    if (!open.value && (e.key === 'ArrowDown' || e.key === 'Enter')) { open.value = true; return; }
    if (e.key === 'ArrowDown') { active.value = Math.min(active.value + 1, filtered.value.length - 1); e.preventDefault(); }
    else if (e.key === 'ArrowUp') { active.value = Math.max(active.value - 1, 0); e.preventDefault(); }
    else if (e.key === 'Enter') { if (filtered.value[active.value]) choose(filtered.value[active.value]); e.preventDefault(); }
    else if (e.key === 'Escape') { open.value = false; }
}

function onBlur(e) {
    if (root.value && !root.value.contains(e.relatedTarget)) open.value = false;
}
</script>

<template>
    <div ref="root" class="relative" @focusout="onBlur">
        <button type="button" :disabled="disabled" @click="open = !open" @keydown="onKey"
            class="mt-1 flex w-full items-center justify-between rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-start text-sm shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 disabled:opacity-50">
            <span :class="selected ? 'text-slate-900 dark:text-slate-100' : 'text-slate-400'">{{ selected ? selected.label : (placeholder || '-') }}</span>
            <span class="flex items-center gap-1">
                <span v-if="selected" class="text-slate-400 hover:text-rose-500" @click.stop="choose(null)">&times;</span>
                <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </span>
        </button>

        <div v-if="open" class="absolute z-20 mt-1 w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-lg">
            <input v-model="queryText" @keydown="onKey" autofocus
                class="w-full rounded-t-lg border-0 border-b border-slate-200 dark:border-slate-700 bg-transparent px-3 py-2 text-sm focus:ring-0" :placeholder="placeholder" />
            <ul class="max-h-56 overflow-y-auto py-1">
                <li v-for="(o, i) in filtered" :key="o.value" @mousedown.prevent="choose(o)"
                    class="cursor-pointer px-3 py-2 text-sm"
                    :class="i === active ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300' : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800'">
                    {{ o.label }}
                </li>
                <li v-if="!filtered.length" class="px-3 py-2 text-sm text-slate-400">-</li>
            </ul>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Build check**

Run: `npm run build`
Expected: builds without Vue compile errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/SearchableSelect.vue
git commit -m "feat: SearchableSelect combobox component"
```

---

### Task 12: `CategoryManager` component + wiring

**Files:**
- Create: `resources/js/Components/CategoryManager.vue`

**Interfaces:**
- Consumes: routes `finance.categories.store|update|destroy`, prop `categories` (`[{id,type,name,color,is_active,transactions_count}]`), prop `show`; emits `close`.
- Produces: modal with Income/Expense columns; inline add/edit/delete via Inertia `router`.

- [ ] **Step 1: Create the component**

Create `resources/js/Components/CategoryManager.vue`:

```vue
<script setup>
import Modal from '@/Components/Modal.vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t } = useI18n();
const props = defineProps({
    show: Boolean,
    categories: { type: Array, default: () => [] },
});
defineEmits(['close']);

const draft = ref({ income: { name: '', color: '#10b981' }, expense: { name: '', color: '#ef4444' } });

function byType(type) {
    return props.categories.filter((c) => c.type === type);
}
function add(type) {
    if (!draft.value[type].name.trim()) return;
    router.post(route('finance.categories.store'), { type, name: draft.value[type].name, color: draft.value[type].color }, {
        preserveScroll: true,
        onSuccess: () => { draft.value[type] = { name: '', color: type === 'income' ? '#10b981' : '#ef4444' }; },
    });
}
function save(cat) {
    router.put(route('finance.categories.update', cat.id), { type: cat.type, name: cat.name, color: cat.color, is_active: cat.is_active }, { preserveScroll: true });
}
function remove(cat) {
    router.delete(route('finance.categories.destroy', cat.id), { preserveScroll: true });
}
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="$emit('close')">
        <div class="p-6">
            <h2 class="mb-4 text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('manage_categories') }}</h2>
            <div class="grid gap-6 sm:grid-cols-2">
                <div v-for="type in ['income', 'expense']" :key="type">
                    <h3 class="mb-2 text-sm font-semibold uppercase text-slate-500">{{ t(type) }}</h3>
                    <ul class="space-y-2">
                        <li v-for="cat in byType(type)" :key="cat.id" class="flex items-center gap-2">
                            <input type="color" v-model="cat.color" @change="save(cat)" class="h-7 w-7 rounded border-0 bg-transparent p-0" />
                            <input v-model="cat.name" @blur="save(cat)" class="flex-1 rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 py-1 text-sm" />
                            <button type="button" @click="remove(cat)" class="text-rose-500 hover:text-rose-700" :title="t('delete')">&times;</button>
                        </li>
                    </ul>
                    <div class="mt-2 flex items-center gap-2">
                        <input type="color" v-model="draft[type].color" class="h-7 w-7 rounded border-0 bg-transparent p-0" />
                        <input v-model="draft[type].name" @keyup.enter="add(type)" :placeholder="t('new_category')" class="flex-1 rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 py-1 text-sm" />
                        <button type="button" @click="add(type)" class="rounded-lg bg-primary-600 px-3 py-1 text-sm font-medium text-white hover:bg-primary-700">+</button>
                    </div>
                </div>
            </div>
            <p class="mt-4 text-xs text-slate-400">{{ t('category_in_use_hint') }}</p>
        </div>
    </Modal>
</template>
```

Note: server rejects deleting a category that has transactions; the flash error surfaces via the app's existing flash handling.

- [ ] **Step 2: Build check**

Run: `npm run build`
Expected: builds. (Confirm `@/Components/Modal.vue` exists; it is used elsewhere in the app.)

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/CategoryManager.vue
git commit -m "feat: CategoryManager modal (create/edit/delete finance categories)"
```

---

### Task 13: Shared `TransactionForm` (categories, CCP/bank, searchable player)

**Files:**
- Create: `resources/js/Pages/Transactions/Partials/TransactionForm.vue`
- Modify: `resources/js/Pages/Transactions/Create.vue`
- Modify: `resources/js/Pages/Transactions/Edit.vue`

**Interfaces:**
- Consumes: props `transaction`, `financeCategories`, `players`, `clubCcp`.
- Produces: a single form component both pages render; binds `finance_category_id` (filtered by type), CCP/bank conditional fields, and a `SearchableSelect` player.

- [ ] **Step 1: Create `TransactionForm.vue`**

Create `resources/js/Pages/Transactions/Partials/TransactionForm.vue`:

```vue
<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const props = defineProps({
    transaction: { type: Object, default: null },
    financeCategories: { type: Array, default: () => [] },
    players: { type: Array, default: () => [] },
    clubCcp: { type: Object, default: null },
});

const isEdit = !!props.transaction;

const form = useForm({
    transaction_type: props.transaction?.transaction_type || 'income',
    finance_category_id: props.transaction?.finance_category_id || '',
    amount: props.transaction?.amount || '',
    transaction_date: props.transaction?.transaction_date ? String(props.transaction.transaction_date).slice(0, 10) : new Date().toISOString().slice(0, 10),
    description: props.transaction?.description || '',
    payment_method: props.transaction?.payment_method || 'cash',
    payment_account: props.transaction?.payment_account || '',
    payment_ccp_key: props.transaction?.payment_ccp_key || '',
    payment_bank_name: props.transaction?.payment_bank_name || '',
    payment_holder: props.transaction?.payment_holder || '',
    payment_reference: props.transaction?.payment_reference || '',
    status: props.transaction?.status || 'Paid',
    related_entity_id: props.transaction?.related_entity_id || '',
    receipt: null,
});

const categoryOptions = computed(() => props.financeCategories.filter((c) => c.type === form.transaction_type));
const playerOptions = computed(() => props.players.map((p) => ({ value: p.id, label: p.fullname || `${p.firstname} ${p.lastname}` })));

// reset category when the type flips and the selection no longer matches
function onTypeChange() {
    const stillValid = categoryOptions.value.some((c) => String(c.id) === String(form.finance_category_id));
    if (!stillValid) form.finance_category_id = '';
}

function useClubCcp() {
    if (!props.clubCcp) return;
    form.payment_account = props.clubCcp.accountNumber || props.clubCcp.account_number || '';
    form.payment_ccp_key = props.clubCcp.key || '';
    form.payment_holder = props.clubCcp.holder || '';
}

function submit() {
    form.transform((data) => ({
        ...data,
        related_entity_id: data.related_entity_id || null,
        related_entity_type: data.related_entity_id ? 'Player' : null,
        ...(isEdit ? { _method: 'put' } : {}),
    })).post(isEdit ? route('transactions.update', props.transaction.id) : route('transactions.store'), { forceFormData: true });
}
</script>

<template>
    <form @submit.prevent="submit" class="mx-auto max-w-2xl space-y-6">
        <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel :value="t('type')" />
                    <select v-model="form.transaction_type" @change="onTypeChange" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm" required>
                        <option value="income">{{ t('income') }}</option>
                        <option value="expense">{{ t('expense') }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel :value="t('category')" />
                    <select v-model="form.finance_category_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm" required>
                        <option value="" disabled>-</option>
                        <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <InputError :message="form.errors.finance_category_id" class="mt-1" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel :value="t('amount')" />
                    <TextInput v-model="form.amount" type="number" step="0.01" min="0" class="mt-1 w-full" required />
                    <InputError :message="form.errors.amount" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('date')" />
                    <TextInput v-model="form.transaction_date" type="date" class="mt-1 w-full" required />
                    <InputError :message="form.errors.transaction_date" class="mt-1" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel :value="t('payment_method')" />
                    <select v-model="form.payment_method" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm">
                        <option value="cash">{{ t('cash') }}</option>
                        <option value="bank">{{ t('bank_transfer') }}</option>
                        <option value="ccp">CCP</option>
                        <option value="baridimob">BaridiMob</option>
                        <option value="other">{{ t('other') }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel :value="t('status')" />
                    <select v-model="form.status" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm">
                        <option value="Paid">{{ t('paid') }}</option>
                        <option value="Partial">{{ t('partial') }}</option>
                        <option value="Unpaid">{{ t('unpaid') }}</option>
                        <option value="Exempt">{{ t('exempt') }}</option>
                    </select>
                </div>
            </div>

            <!-- CCP details -->
            <div v-if="form.payment_method === 'ccp'" class="rounded-xl bg-slate-50 dark:bg-slate-950 p-4 space-y-3">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">{{ t('ccp_details') }}</p>
                    <button v-if="clubCcp" type="button" @click="useClubCcp" class="text-xs font-medium text-primary-600 hover:underline">{{ t('use_club_ccp') }}</button>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div><InputLabel :value="t('ccp_number')" /><TextInput v-model="form.payment_account" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="t('ccp_key')" /><TextInput v-model="form.payment_ccp_key" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="t('holder')" /><TextInput v-model="form.payment_holder" class="mt-1 w-full" /></div>
                </div>
            </div>

            <!-- Bank details -->
            <div v-if="form.payment_method === 'bank'" class="rounded-xl bg-slate-50 dark:bg-slate-950 p-4 space-y-3">
                <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">{{ t('bank_details') }}</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><InputLabel :value="t('bank_name')" /><TextInput v-model="form.payment_bank_name" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="t('account_number')" /><TextInput v-model="form.payment_account" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="t('holder')" /><TextInput v-model="form.payment_holder" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="t('reference')" /><TextInput v-model="form.payment_reference" class="mt-1 w-full" /></div>
                </div>
            </div>

            <div v-if="players.length">
                <InputLabel :value="t('player')" />
                <SearchableSelect v-model="form.related_entity_id" :options="playerOptions" :placeholder="t('search_player')" />
            </div>

            <div>
                <InputLabel :value="t('description')" />
                <textarea v-model="form.description" rows="3" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm" />
            </div>

            <div>
                <InputLabel :value="t('receipt')" />
                <div v-if="transaction?.receipt_url" class="mb-2">
                    <a :href="transaction.receipt_url" target="_blank" class="text-sm font-medium text-primary-600 hover:underline">{{ t('view') }} ↗</a>
                </div>
                <input type="file" accept="image/*,application/pdf" @change="form.receipt = $event.target.files[0]"
                    class="text-sm text-slate-600 dark:text-slate-300 file:me-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100" />
                <InputError :message="form.errors.receipt" class="mt-1" />
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="route('transactions.index')"><SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton></Link>
            <PrimaryButton :disabled="form.processing">{{ isEdit ? t('save_changes') : t('save') }}</PrimaryButton>
        </div>
    </form>
</template>
```

- [ ] **Step 2: Rewrite `Create.vue` to use the shared form**

Replace `resources/js/Pages/Transactions/Create.vue` body with a thin wrapper:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TransactionForm from '@/Pages/Transactions/Partials/TransactionForm.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({
    financeCategories: { type: Array, default: () => [] },
    players: { type: Array, default: () => [] },
    clubCcp: { type: Object, default: null },
});
</script>

<template>
    <Head :title="t('add_transaction')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('transactions.index')" class="text-slate-400 hover:text-slate-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('add_transaction') }}</h1>
            </div>
        </template>
        <TransactionForm :finance-categories="financeCategories" :players="players" :club-ccp="clubCcp" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 3: Rewrite `Edit.vue` to use the shared form**

Replace `resources/js/Pages/Transactions/Edit.vue` body:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TransactionForm from '@/Pages/Transactions/Partials/TransactionForm.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({
    transaction: Object,
    financeCategories: { type: Array, default: () => [] },
    players: { type: Array, default: () => [] },
    clubCcp: { type: Object, default: null },
});
</script>

<template>
    <Head :title="t('edit')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('transactions.index')" class="text-slate-400 hover:text-slate-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('edit') }}</h1>
            </div>
        </template>
        <TransactionForm :transaction="transaction" :finance-categories="financeCategories" :players="players" :club-ccp="clubCcp" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 4: Build check**

Run: `npm run build`
Expected: builds without errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Transactions/Partials/TransactionForm.vue resources/js/Pages/Transactions/Create.vue resources/js/Pages/Transactions/Edit.vue
git commit -m "feat: shared transaction form with finance categories, CCP/bank fields, searchable player"
```

---

### Task 14: Index page — stats bar, category filter/column, manager launch

**Files:**
- Modify: `resources/js/Pages/Transactions/Index.vue`

**Interfaces:**
- Consumes: `stats`, `financeCategories` (from Task 10), `CategoryManager`.

- [ ] **Step 1: Add stats bar + finance-category filter + manager to `Index.vue`**

In `resources/js/Pages/Transactions/Index.vue`:

1. Extend `defineProps` with `stats: Object` and `financeCategories: { type: Array, default: () => [] }`.
2. Import `StatCard` and `CategoryManager`, add `const showCategories = ref(false);` and a `financeCategoryFilter` ref bound to `props.filters?.finance_category_id`.
3. Replace the string `categoryFilter` select with a finance-category select (grouped by type), and include `finance_category_id` in `applyFilters` + `exportUrl`.
4. Render a stats row above the filters and the manager modal:

```vue
<!-- stats bar (above the Filters block) -->
<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
    <StatCard :label="t('total_income')" :value="formatMoney(stats?.income || 0)" icon="money" color="emerald" suffix="DZD" />
    <StatCard :label="t('total_expense')" :value="formatMoney(stats?.expense || 0)" icon="money" color="rose" suffix="DZD" />
    <StatCard :label="t('net_balance')" :value="formatMoney(stats?.net || 0)" icon="chart" :color="(stats?.net || 0) >= 0 ? 'emerald' : 'rose'" suffix="DZD" />
    <StatCard :label="t('outstanding_debts')" :value="formatMoney(stats?.debts || 0)" icon="money" color="amber" suffix="DZD" />
</div>
```

Add a "Manage categories" button in the header actions:

```vue
<button @click="showCategories = true" class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800"><Icon name="cog" /> {{ t('manage_categories') }}</button>
```

Replace the category filter select:

```vue
<select v-model="financeCategoryFilter" class="rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm">
    <option value="">{{ t('all_categories') }}</option>
    <optgroup :label="t('income')">
        <option v-for="c in financeCategories.filter((x) => x.type === 'income')" :key="c.id" :value="c.id">{{ c.name }}</option>
    </optgroup>
    <optgroup :label="t('expense')">
        <option v-for="c in financeCategories.filter((x) => x.type === 'expense')" :key="c.id" :value="c.id">{{ c.name }}</option>
    </optgroup>
</select>
```

Update the category table cell to show the finance category name with color dot, falling back to the legacy string:

```vue
<td class="whitespace-nowrap px-4 py-3 text-sm text-slate-700 dark:text-slate-200">
    <span v-if="tx.finance_category" class="inline-flex items-center gap-1.5">
        <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: tx.finance_category.color || '#94a3b8' }"></span>
        {{ tx.finance_category.name }}
    </span>
    <span v-else>{{ tx.category }}</span>
</td>
```

Add the modal before `</AuthenticatedLayout>`:

```vue
<CategoryManager :show="showCategories" :categories="financeCategories" @close="showCategories = false" />
```

Update `applyFilters` and `exportUrl` and the `watch(...)` to include `finance_category_id: financeCategoryFilter.value || undefined`.

- [ ] **Step 2: Build check**

Run: `npm run build`
Expected: builds. (If `Icon name="cog"`/`"chart"` are missing in `Icon.vue`, use an existing icon name — check `resources/js/Components/Icon.vue`.)

- [ ] **Step 3: Verify index still renders (feature test)**

Run: `php artisan test --filter=TransactionFinanceCategoryTest`
Expected: PASS (index test still green).

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Transactions/Index.vue
git commit -m "feat: transactions index stats bar, finance category filter/column, manager launch"
```

---

### Task 15: i18n keys

**Files:**
- Modify: `resources/js/i18n/en.json`, `resources/js/i18n/fr.json`, `resources/js/i18n/ar.json`

**Interfaces:**
- Produces: keys used across Phase 2 exist in all three locales.

- [ ] **Step 1: Add keys**

Add to each locale file (translate values appropriately for fr/ar; en shown):

```
"ccp_details": "CCP details",
"ccp_number": "CCP number",
"ccp_key": "Key (clé)",
"holder": "Holder",
"bank_details": "Bank details",
"bank_name": "Bank name",
"account_number": "Account number (RIB)",
"reference": "Reference",
"use_club_ccp": "Use club CCP",
"search_player": "Search player…",
"manage_categories": "Manage categories",
"new_category": "New category…",
"category_in_use_hint": "A category linked to transactions cannot be deleted.",
"total_income": "Total income",
"total_expense": "Total expense",
"net_balance": "Net balance",
"outstanding_debts": "Outstanding debts"
```

For `fr.json` use e.g. `"ccp_number": "Numéro CCP"`, `"ccp_key": "Clé"`, `"total_income": "Total des recettes"`, `"total_expense": "Total des dépenses"`, `"net_balance": "Solde net"`, `"outstanding_debts": "Dettes en cours"`, `"manage_categories": "Gérer les catégories"`, `"search_player": "Rechercher un joueur…"`, `"holder": "Titulaire"`, `"bank_name": "Banque"`, `"account_number": "Numéro de compte (RIB)"`, `"reference": "Référence"`, `"use_club_ccp": "Utiliser le CCP du club"`, `"bank_details": "Détails bancaires"`, `"ccp_details": "Détails CCP"`, `"new_category": "Nouvelle catégorie…"`, `"category_in_use_hint": "Une catégorie liée à des transactions ne peut pas être supprimée."`

For `ar.json` use Arabic equivalents, e.g. `"total_income": "إجمالي المداخيل"`, `"total_expense": "إجمالي المصاريف"`, `"net_balance": "الرصيد الصافي"`, `"outstanding_debts": "الديون المستحقة"`, `"manage_categories": "إدارة الفئات"`, `"ccp_number": "رقم الحساب البريدي"`, `"ccp_key": "المفتاح"`, `"holder": "صاحب الحساب"`, `"bank_name": "البنك"`, `"account_number": "رقم الحساب (RIB)"`, `"reference": "المرجع"`, `"use_club_ccp": "استخدام حساب النادي البريدي"`, `"search_player": "ابحث عن لاعب…"`, `"bank_details": "تفاصيل البنك"`, `"ccp_details": "تفاصيل الحساب البريدي"`, `"new_category": "فئة جديدة…"`, `"category_in_use_hint": "لا يمكن حذف فئة مرتبطة بمعاملات."`

- [ ] **Step 2: Build + full test suite**

Run: `npm run build && php artisan test`
Expected: build succeeds; test suite green.

- [ ] **Step 3: Commit**

```bash
git add resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json
git commit -m "feat: i18n keys for transactions enhancement"
```

---

## Manual verification (after all tasks)

Run the app (`npm run dev` + `php artisan serve`, or the project's run skill) and verify:
1. New transaction: type=income shows only income categories; switching to expense swaps the list and clears a mismatched pick.
2. Payment method = CCP reveals CCP number/clé/holder; "Use club CCP" prefills them; = bank reveals bank fields.
3. Player field is a searchable combobox.
4. Index shows income/expense/net/debt tiles that respond to filters (except debt).
5. "Manage categories" modal creates/renames/recolors/deletes; deleting an in-use category shows the guard error.
6. A player's Show page debt equals the Players index debt equals the dashboard figure; recording a payment lowers all three; editing/deleting that payment resyncs them.
