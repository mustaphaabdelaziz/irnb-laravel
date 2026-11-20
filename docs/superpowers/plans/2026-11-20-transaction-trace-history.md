# Transaction Trace and History Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The transaction page shows who recorded a transaction and when, every later edit with old → new values, cancellation or correction, grouped-payment siblings, hidden payment details, and one merged history card.

**Architecture:** Edits write a `transaction_revisions` row (the diff) linked to the `transaction_updated` activity log row (who/when/impersonator). A corrected player payment points at its original through `transactions.replaces_transaction_id`. A read-only service, `TransactionHistory`, merges the row itself, activity logs, revisions, replacements and receipt prints into one timeline that `TransactionController::show` passes to the page.

**Tech Stack:** Laravel 13, PHPUnit class tests (`#[Test]`, `RefreshDatabase`), Inertia + Vue 3 `<script setup>`, vue-i18n flat dotted keys, SQLite (dev/desktop) and MySQL.

**Spec:** `docs/superpowers/specs/2026-11-20-transaction-trace-history-design.md`

## Global Constraints

- Work in a git worktree on branch `feat/transaction-trace` (superpowers:using-git-worktrees). Copy `vendor` and `node_modules` into the worktree; never junction them.
- The main folder holds the owner's untracked files (`.claude/`, `HANDOFF.md`, `_bmad/`, `brag-output/`, `ASIRAM.png`, a PDF, `ASIREM-summary-for-NotebookLM.md`). Never stage them. Stage explicit file paths only, never a directory.
- Do not push. The owner decides when `main` is pushed.
- Tests are PHPUnit classes in `tests/Feature`, not Pest functions. Run one class with `php artisan test --filter=<ClassName>`.
- Visibility: Transactions → view. No new permission, no new route.
- Activity is recorded explicitly in controllers, never from model observers.
- The activity log stores no free text. Old/new titles and descriptions go to `transaction_revisions.diff` only.
- i18n: flat dotted keys in `resources/js/i18n/{ar,fr,en}.json`, inserted without reformatting the file, called with `t()` directly (never `te()`). No key segment named after a String method (`link`, `search`, `replace`, `at`, `sub`, `big`, `small`, `fixed`, `anchor`, `length`).
- No CDN or remote resource. The desktop app runs offline.
- Player names always come from `fullname`; user names from `User::fullname`.
- Help Center: an Arabic article change needs its `fr` and `en` twins in the same commit; `php artisan help:check` and `php artisan test --filter=Help` must pass.
- Commit messages are conventional (`feat(transactions): …`) and end with:
  `Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>`

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_11_20_100000_create_transaction_revisions_table.php` | New table |
| `database/migrations/2026_11_20_100001_add_replaces_transaction_id_to_transactions.php` | Correction link column |
| `app/Models/TransactionRevision.php` | One edit's diff |
| `app/Models/Transaction.php` | `revisions()`, `replaces()`, `replacements()` |
| `app/Models/ActivityLog.php` | `revision()` |
| `app/Services/Finance/TransactionChanges.php` | Snapshot + compare tracked fields, attach labels |
| `app/Services/Finance/TransactionHistory.php` | Timeline, trace, cancellation, group (read-only) |
| `app/Http/Controllers/TransactionController.php` | `update` records the edit; `show` passes the new props |
| `app/Http/Controllers/PlayerTransactionController.php` | `update` sets `replaces_transaction_id` |
| `config/activity.php` | Drop `transactions.update` (now explicit) |
| `app/Services/Finance/FinanceResetService.php` | Wipe revisions |
| `resources/js/lib/transactionActor.js` | "Admin (as Karim)" label |
| `resources/js/Pages/Transactions/Partials/TransactionHistory.vue` | Timeline card body |
| `resources/js/Pages/Transactions/Show.vue` | Banner, details, trace line, group card |
| `resources/js/i18n/{ar,fr,en}.json` | 22 new keys each |
| `resources/help/{ar,fr,en}/finance/transaction-history.md` | New article |
| `resources/help/{ar,fr,en}/subscriptions/receipts.md` | Card renamed in one sentence |
| `resources/help/shots.js` | New shot `fin-tx-history` |

---

### Task 1: Schema and models

**Files:**
- Create: `database/migrations/2026_11_20_100000_create_transaction_revisions_table.php`
- Create: `database/migrations/2026_11_20_100001_add_replaces_transaction_id_to_transactions.php`
- Create: `app/Models/TransactionRevision.php`
- Modify: `app/Models/Transaction.php` (relations, after `playerSubscriptions()`)
- Modify: `app/Models/ActivityLog.php` (relation, after `subject()`)
- Test: `tests/Feature/TransactionRevisionSchemaTest.php`

**Interfaces:**
- Produces: table `transaction_revisions(id, transaction_id, activity_log_id, diff, created_at)`; column `transactions.replaces_transaction_id`; `TransactionRevision` (fillable `transaction_id`, `activity_log_id`, `diff`; `diff` cast to array); `Transaction::revisions(): HasMany`, `Transaction::replaces(): BelongsTo`, `Transaction::replacements(): HasMany`; `ActivityLog::revision(): HasOne`.

The JSON column is named `diff`, not `changes`: Eloquent models already have an internal `$changes` property.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TransactionRevisionSchemaTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Models\TransactionRevision;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionRevisionSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function tx(): Transaction
    {
        return Transaction::create([
            'amount' => 200, 'transaction_type' => 'income', 'category' => 'donation', 'status' => 'Paid',
        ]);
    }

    #[Test]
    public function a_revision_belongs_to_its_transaction_and_its_log(): void
    {
        $tx = $this->tx();
        $log = ActivityRecorder::record(User::factory()->admin()->create(), ActivityAction::TRANSACTION_UPDATED, $tx);

        $revision = TransactionRevision::create([
            'transaction_id' => $tx->id,
            'activity_log_id' => $log->id,
            'diff' => ['amount' => ['old' => 200, 'new' => 300]],
        ]);

        $this->assertTrue($tx->revisions()->sole()->is($revision));
        $this->assertTrue($log->fresh()->revision->is($revision));
        $this->assertEquals(['amount' => ['old' => 200, 'new' => 300]], $revision->fresh()->diff);
        $this->assertNotNull($revision->fresh()->created_at);
    }

    #[Test]
    public function revisions_are_deleted_with_their_transaction(): void
    {
        $tx = $this->tx();
        TransactionRevision::create(['transaction_id' => $tx->id, 'diff' => ['title' => ['old' => 'a', 'new' => 'b']]]);

        $tx->delete();

        $this->assertSame(0, TransactionRevision::count());
    }

    #[Test]
    public function a_revision_outlives_its_activity_log(): void
    {
        $tx = $this->tx();
        $log = ActivityRecorder::record(null, ActivityAction::TRANSACTION_UPDATED, $tx);
        $revision = TransactionRevision::create(['transaction_id' => $tx->id, 'activity_log_id' => $log->id, 'diff' => []]);

        ActivityLog::whereKey($log->id)->delete();

        $this->assertNull($revision->fresh()->activity_log_id);
    }

    #[Test]
    public function deleting_the_original_unlinks_its_replacement(): void
    {
        $original = $this->tx();
        $replacement = $this->tx();
        $replacement->forceFill(['replaces_transaction_id' => $original->id])->save();

        $this->assertTrue($replacement->fresh()->replaces->is($original));
        $this->assertTrue($original->replacements()->sole()->is($replacement));

        $original->delete();

        $this->assertNull($replacement->fresh()->replaces_transaction_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TransactionRevisionSchemaTest`
Expected: FAIL — `Class "App\Models\TransactionRevision" not found`.

- [ ] **Step 3: Create the migrations**

`database/migrations/2026_11_20_100000_create_transaction_revisions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What an edit changed on a transaction (old → new per field). Who and when
     * stay on the activity log row; the diff lives here so it is deleted with
     * the transaction and the activity log keeps holding no free text.
     */
    public function up(): void
    {
        Schema::create('transaction_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_log_id')->nullable()->constrained('activity_logs')->nullOnDelete();
            $table->json('diff');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_revisions');
    }
};
```

`database/migrations/2026_11_20_100001_add_replaces_transaction_id_to_transactions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A corrected player payment is archived and recorded again. The new row
     * points at the one it replaces, so each page can link to the other.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('replaces_transaction_id')->nullable()->after('payment_group')
                ->constrained('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_transaction_id');
        });
    }
};
```

- [ ] **Step 4: Create the model and relations**

`app/Models/TransactionRevision.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One edit of a transaction: the tracked fields that changed, as
 * { field: { old, new } } (see TransactionChanges). The column is `diff`
 * because Eloquent keeps its own $changes property.
 */
class TransactionRevision extends Model
{
    use SerializesClubDates;

    const UPDATED_AT = null;

    protected $fillable = ['transaction_id', 'activity_log_id', 'diff'];

    protected function casts(): array
    {
        return ['diff' => 'array'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** Who edited and when; null once that log row is gone. */
    public function activityLog(): BelongsTo
    {
        return $this->belongsTo(ActivityLog::class);
    }
}
```

In `app/Models/Transaction.php`, add after `playerSubscriptions()`:

```php
    /** Every recorded edit of this transaction (TransactionController::update). */
    public function revisions(): HasMany
    {
        return $this->hasMany(TransactionRevision::class);
    }

    /** The archived payment this one corrects (PlayerTransactionController::update). */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_transaction_id');
    }

    /** The payment(s) recorded in place of this one when it was corrected. */
    public function replacements(): HasMany
    {
        return $this->hasMany(self::class, 'replaces_transaction_id');
    }
```

In `app/Models/ActivityLog.php`, add `use Illuminate\Database\Eloquent\Relations\HasOne;` and, after `subject()`:

```php
    /** What a `transaction_updated` event changed; older events have none. */
    public function revision(): HasOne
    {
        return $this->hasOne(TransactionRevision::class);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=TransactionRevisionSchemaTest`
Expected: PASS (4 tests). If `deleting_the_original_unlinks_its_replacement` fails, the foreign key was not created on SQLite: stop and report, do not work around it.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_11_20_100000_create_transaction_revisions_table.php database/migrations/2026_11_20_100001_add_replaces_transaction_id_to_transactions.php app/Models/TransactionRevision.php app/Models/Transaction.php app/Models/ActivityLog.php tests/Feature/TransactionRevisionSchemaTest.php
git commit -m "feat(transactions): revisions table and correction link column"
```

---

### Task 2: `TransactionChanges`

**Files:**
- Create: `app/Services/Finance/TransactionChanges.php`
- Test: `tests/Feature/TransactionChangesTest.php`

**Interfaces:**
- Produces:
  - `TransactionChanges::snapshot(Transaction $transaction): array<string, mixed>` — the 14 tracked fields, normalised.
  - `TransactionChanges::between(array $before, array $after): array<string, array{old: mixed, new: mixed}>` — changed fields only, in display order.
  - `TransactionChanges::withLabels(array $changes): array` — adds `old_label` / `new_label` to `finance_category_id` and `finance_account_id`.

The snapshot is taken before and after the save, so a value the observer sets (default register, today's date for an undated entry) is the real one.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TransactionChangesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Services\Finance\TransactionChanges;
use App\Support\FinanceAccountLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionChangesTest extends TestCase
{
    use RefreshDatabase;

    private function tx(array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'title' => 'Hall rent', 'amount' => 1000, 'transaction_date' => '2026-11-10',
            'transaction_type' => 'expense', 'category' => 'rent', 'payment_method' => 'cash', 'status' => 'Paid',
        ], $overrides));
    }

    /** @return array<string, array<string, mixed>> */
    private function changesAfter(Transaction $tx, array $update): array
    {
        $before = TransactionChanges::snapshot($tx);
        $tx->update($update);

        return TransactionChanges::between($before, TransactionChanges::snapshot($tx));
    }

    #[Test]
    public function only_the_changed_fields_are_reported(): void
    {
        $changes = $this->changesAfter($this->tx(), ['amount' => '1500.00', 'title' => 'Hall rent']);

        $this->assertSame(['amount' => ['old' => 1000.0, 'new' => 1500.0]], $changes);
    }

    #[Test]
    public function the_same_amount_written_differently_is_no_change(): void
    {
        $this->assertSame([], $this->changesAfter($this->tx(), ['amount' => '1000']));
    }

    #[Test]
    public function an_empty_text_and_no_text_are_the_same(): void
    {
        $this->assertSame([], $this->changesAfter($this->tx(['description' => null]), ['description' => '']));
    }

    #[Test]
    public function the_date_is_compared_by_day(): void
    {
        $tx = $this->tx();

        $this->assertSame([], $this->changesAfter($tx, ['transaction_date' => '2026-11-10 15:30:00']));
        $this->assertSame(
            ['transaction_date' => ['old' => '2026-11-10', 'new' => '2026-11-12']],
            $this->changesAfter($tx, ['transaction_date' => '2026-11-12']),
        );
    }

    #[Test]
    public function text_changes_keep_the_old_and_new_wording(): void
    {
        $changes = $this->changesAfter($this->tx(), ['title' => 'Hall rent — March', 'description' => 'Paid late']);

        $this->assertSame([
            'title' => ['old' => 'Hall rent', 'new' => 'Hall rent — March'],
            'description' => ['old' => null, 'new' => 'Paid late'],
        ], $changes);
    }

    #[Test]
    public function category_and_register_changes_carry_their_names(): void
    {
        // Names no migration seeds: finance_categories is unique on (type, name).
        $rent = FinanceCategory::create(['type' => 'expense', 'name' => 'Trace Rent', 'is_active' => true]);
        $bills = FinanceCategory::create(['type' => 'expense', 'name' => 'Trace Bills', 'is_active' => true]);
        $caisseA = FinanceAccount::create(['name' => 'Caisse A', 'type' => 'cash']);
        $caisseB = FinanceAccount::create(['name' => 'Caisse B', 'type' => 'cash']);
        $tx = $this->tx(['finance_category_id' => $rent->id, 'finance_account_id' => $caisseA->id]);

        $changes = TransactionChanges::withLabels($this->changesAfter($tx, [
            'finance_category_id' => $bills->id,
            'finance_account_id' => $caisseB->id,
        ]));

        $this->assertSame([
            'old' => $rent->id, 'new' => $bills->id,
            'old_label' => $rent->fresh()->localized_name, 'new_label' => $bills->fresh()->localized_name,
        ], $changes['finance_category_id']);
        $this->assertSame([
            'old' => $caisseA->id, 'new' => $caisseB->id,
            'old_label' => FinanceAccountLabel::for($caisseA), 'new_label' => FinanceAccountLabel::for($caisseB),
        ], $changes['finance_account_id']);
    }

    #[Test]
    public function labels_are_added_only_where_a_category_or_register_changed(): void
    {
        $changes = ['amount' => ['old' => 1.0, 'new' => 2.0]];

        $this->assertSame($changes, TransactionChanges::withLabels($changes));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TransactionChangesTest`
Expected: FAIL — `Class "App\Services\Finance\TransactionChanges" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Services/Finance/TransactionChanges.php`:

```php
<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Support\FinanceAccountLabel;

/**
 * What an edit changed on a transaction. Take a snapshot before the save and
 * one after, then compare: values the observer sets on save (a default
 * register, today's date for an undated entry) are then the real ones.
 *
 * Season fields are not tracked: they follow the date.
 */
final class TransactionChanges
{
    /** Tracked fields, in the order the history lists them, with how each is compared. */
    public const FIELDS = [
        'title' => 'text',
        'amount' => 'money',
        'transaction_date' => 'date',
        'transaction_type' => 'text',
        'finance_category_id' => 'id',
        'finance_account_id' => 'id',
        'payment_method' => 'text',
        'payment_account' => 'text',
        'payment_ccp_key' => 'text',
        'payment_bank_name' => 'text',
        'payment_holder' => 'text',
        'payment_reference' => 'text',
        'status' => 'text',
        'description' => 'text',
    ];

    /** @return array<string, mixed> */
    public static function snapshot(Transaction $transaction): array
    {
        $values = [];

        foreach (self::FIELDS as $field => $kind) {
            $raw = $transaction->getAttribute($field);
            $empty = $raw === null || $raw === '';

            $values[$field] = match ($kind) {
                'money' => round((float) $raw, 2),
                // By day: the column keeps no meaningful hour.
                'date' => $raw?->format('Y-m-d'),
                'id' => $empty ? null : (int) $raw,
                // "" and null are the same empty field.
                default => $empty ? null : (string) $raw,
            };
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function between(array $before, array $after): array
    {
        $changes = [];

        foreach (array_keys(self::FIELDS) as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;

            if ($old !== $new) {
                $changes[$field] = ['old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }

    /**
     * Names the category and register on both sides, as they are called now:
     * the history can still say which one it was after it is deleted.
     *
     * @param  array<string, array<string, mixed>>  $changes
     * @return array<string, array<string, mixed>>
     */
    public static function withLabels(array $changes): array
    {
        if (isset($changes['finance_category_id'])) {
            $names = FinanceCategory::whereIn('id', self::ids($changes['finance_category_id']))
                ->get()
                ->mapWithKeys(fn (FinanceCategory $category) => [$category->id => $category->localized_name]);

            $changes['finance_category_id'] = self::labelled($changes['finance_category_id'], $names->all());
        }

        if (isset($changes['finance_account_id'])) {
            $names = FinanceAccount::with(['branch', 'category'])
                ->whereIn('id', self::ids($changes['finance_account_id']))
                ->get()
                ->mapWithKeys(fn (FinanceAccount $account) => [$account->id => FinanceAccountLabel::for($account)]);

            $changes['finance_account_id'] = self::labelled($changes['finance_account_id'], $names->all());
        }

        return $changes;
    }

    /** @return list<int> */
    private static function ids(array $change): array
    {
        return array_values(array_filter([$change['old'] ?? null, $change['new'] ?? null]));
    }

    /** @param array<int, string> $names */
    private static function labelled(array $change, array $names): array
    {
        return [
            'old' => $change['old'],
            'new' => $change['new'],
            'old_label' => $change['old'] === null ? null : ($names[$change['old']] ?? null),
            'new_label' => $change['new'] === null ? null : ($names[$change['new']] ?? null),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TransactionChangesTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/TransactionChanges.php tests/Feature/TransactionChangesTest.php
git commit -m "feat(transactions): compare a transaction before and after an edit"
```

---

### Task 3: Record edits with their diff

**Files:**
- Modify: `app/Http/Controllers/TransactionController.php` (`update`, around lines 233–269)
- Modify: `config/activity.php:66` (remove the `transactions.update` line)
- Test: `tests/Feature/TransactionEditHistoryTest.php`

**Interfaces:**
- Consumes: `TransactionChanges::snapshot()`, `::between()`, `::withLabels()` (Task 2); `TransactionRevision` (Task 1); `ActivityRecorder::record(?User, string, ?Model, array): ActivityLog`.
- Produces: every changing edit through `transactions.update` yields exactly one `transaction_updated` activity log (subject = the transaction, no properties) and one `TransactionRevision` whose `activity_log_id` is that log. A replaced receipt file adds `'receipt' => ['replaced' => true]` to the diff. An edit that changes nothing yields neither.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TransactionEditHistoryTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\TransactionRevision;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Support\FiscalPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionEditHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->admin = User::factory()->admin()->create(['email_verified_at' => now(), 'preferred_lng' => 'en']);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $category = FinanceCategory::updateOrCreate(['type' => 'income', 'name' => 'Donation'], ['is_active' => true]);

        return array_merge([
            'title' => 'Sponsor gift',
            'transaction_type' => 'income',
            'finance_category_id' => $category->id,
            'amount' => 3000,
            'transaction_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'status' => 'Paid',
        ], $overrides);
    }

    private function stored(): Transaction
    {
        $this->actingAs($this->admin)->post(route('transactions.store'), $this->payload())->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    private function edits(): int
    {
        return ActivityLog::where('action', ActivityAction::TRANSACTION_UPDATED)->count();
    }

    #[Test]
    public function an_edit_is_logged_once_with_what_changed(): void
    {
        $tx = $this->stored();

        $this->actingAs($this->admin)
            ->put(route('transactions.update', $tx), $this->payload(['amount' => 4500]))
            ->assertRedirect(route('transactions.show', $tx));

        $log = ActivityLog::where('action', ActivityAction::TRANSACTION_UPDATED)->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame($tx->id, $log->subject_id);
        $this->assertNull($log->properties);

        $revision = TransactionRevision::sole();
        $this->assertSame($tx->id, $revision->transaction_id);
        $this->assertSame($log->id, $revision->activity_log_id);
        $this->assertEquals(['amount' => ['old' => 3000, 'new' => 4500]], $revision->diff);
    }

    #[Test]
    public function saving_the_form_unchanged_records_nothing(): void
    {
        $tx = $this->stored();

        $this->actingAs($this->admin)
            ->put(route('transactions.update', $tx), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->edits());
        $this->assertSame(0, TransactionRevision::count());
    }

    #[Test]
    public function a_title_edit_keeps_the_old_wording_out_of_the_activity_log(): void
    {
        $tx = $this->stored();

        $this->actingAs($this->admin)
            ->put(route('transactions.update', $tx), $this->payload(['title' => 'Sponsor gift — Café du Port']))
            ->assertSessionHasNoErrors();

        $this->assertEquals(
            ['title' => ['old' => 'Sponsor gift', 'new' => 'Sponsor gift — Café du Port']],
            TransactionRevision::sole()->diff,
        );
        $this->assertNull(ActivityLog::where('action', ActivityAction::TRANSACTION_UPDATED)->sole()->properties);
    }

    #[Test]
    public function a_category_change_is_stored_with_both_names(): void
    {
        $tx = $this->stored();
        $other = FinanceCategory::create(['type' => 'income', 'name' => 'Trace Sponsoring', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->put(route('transactions.update', $tx), $this->payload(['finance_category_id' => $other->id]))
            ->assertSessionHasNoErrors();

        $change = TransactionRevision::sole()->diff['finance_category_id'];
        $this->assertSame($other->id, $change['new']);
        $this->assertSame($other->fresh()->localized_name, $change['new_label']);
        $this->assertNotNull($change['old_label']);
    }

    #[Test]
    public function a_new_receipt_file_is_recorded_without_its_name(): void
    {
        $tx = $this->stored();

        $this->actingAs($this->admin)
            ->put(route('transactions.update', $tx), $this->payload([
                'receipt' => UploadedFile::fake()->create('facture-secrete.pdf', 100, 'application/pdf'),
            ]))
            ->assertSessionHasNoErrors();

        $revision = TransactionRevision::sole();
        $this->assertSame(['receipt' => ['replaced' => true]], $revision->diff);
        $this->assertStringNotContainsString('facture', (string) $revision->getRawOriginal('diff'));
    }

    #[Test]
    public function an_edit_refused_by_a_closed_season_records_nothing(): void
    {
        $tx = $this->stored();
        FiscalYear::where('year', FiscalPeriod::keyForDate(now()))->update(['status' => 'closed']);

        $this->actingAs($this->admin)->from(route('transactions.edit', $tx))
            ->put(route('transactions.update', $tx), $this->payload(['amount' => 9999]))
            ->assertSessionHas('error');

        $this->assertSame(3000.0, (float) $tx->fresh()->amount);
        $this->assertSame(0, $this->edits());
        $this->assertSame(0, TransactionRevision::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TransactionEditHistoryTest`
Expected: FAIL — the first test fails on `TransactionRevision::sole()` (no revision row); `saving_the_form_unchanged_records_nothing` fails with 1 edit logged (the route middleware still records it).

- [ ] **Step 3: Stop the middleware from recording the edit**

In `config/activity.php`, delete this line (line 66):

```php
    'transactions.update' => ActivityAction::TRANSACTION_UPDATED,
```

The file's header comment already says routes whose controllers record an explicit event are absent.

- [ ] **Step 4: Record the edit in the controller**

In `app/Http/Controllers/TransactionController.php`, add the imports:

```php
use App\Models\TransactionRevision;
use App\Services\Finance\TransactionChanges;
```

Replace the `try { $transaction->update($validated); } catch …` block of `update()` with:

```php
        try {
            DB::transaction(function () use ($request, $transaction, $validated) {
                $before = TransactionChanges::snapshot($transaction);

                $transaction->update($validated);

                $changes = TransactionChanges::between($before, TransactionChanges::snapshot($transaction));

                if ($request->hasFile('receipt')) {
                    // The fact only: file names never reach the history.
                    $changes['receipt'] = ['replaced' => true];
                }

                // The form saved as it was: not an edit.
                if ($changes === []) {
                    return;
                }

                $log = ActivityRecorder::record($request->user(), ActivityAction::TRANSACTION_UPDATED, $transaction);

                TransactionRevision::create([
                    'transaction_id' => $transaction->id,
                    'activity_log_id' => $log->id,
                    'diff' => TransactionChanges::withLabels($changes),
                ]);
            });
        } catch (Throwable $e) {
            // Rejected (e.g. closed season): keep the old file, drop the new upload.
            $receipts->delete($validated['receipt_filename'] ?? null);

            throw $e;
        }
```

Leave the rest of the method (the `$previousFile` cleanup and the redirect) as it is.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=TransactionEditHistoryTest`
Expected: PASS (6 tests).

Run: `php artisan test --filter="ClosedSeasonLockTest|TransactionTitleFlowTest|TransactionReceiptPrivateTest|ActivityMoneyEventsTest|UsersBatchTest"`
Expected: PASS. A test that counted on the middleware's `transaction_updated` event for an unchanged form must now send a real change; fix the test's payload, not the controller.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/TransactionController.php config/activity.php tests/Feature/TransactionEditHistoryTest.php
git commit -m "feat(transactions): keep old and new values of every edit"
```

---

### Task 4: Link a corrected payment to its original

**Files:**
- Modify: `app/Http/Controllers/PlayerTransactionController.php` (`update`, the `payment_group` block around lines 164–168)
- Modify: `tests/Feature/MultiSubscriptionPaymentTest.php` (`an_edited_line_stays_in_its_payment`, one assertion)
- Test: `tests/Feature/TransactionCorrectionLinkTest.php`

**Interfaces:**
- Consumes: column `transactions.replaces_transaction_id` (Task 1).
- Produces: every transaction created by `players.transactions.update` has `replaces_transaction_id` = the archived original's id.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TransactionCorrectionLinkTest.php`:

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

class TransactionCorrectionLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Player $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $this->player = Player::create([
            'membership_id' => '8000000001', 'firstname' => 'Flow', 'lastname' => 'Test',
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
    }

    private function line(float $owed): PlayerSubscription
    {
        $line = PlayerSubscription::create([
            'player_id' => $this->player->id, 'subscription_id' => null, 'transaction_id' => null,
            'year' => (int) now()->year, 'status_at_time' => 'student',
            'is_mandatory' => true, 'amount_owed' => $owed, 'amount_paid' => 0,
        ]);
        app(RecalculatePlayerDebtService::class)->forPlayer($this->player->fresh());

        return $line;
    }

    private function pay(PlayerSubscription $line, float $amount): Transaction
    {
        $this->actingAs($this->admin)->post(route('players.transactions.store', $this->player), [
            'player_subscription_id' => $line->id,
            'category' => 'subscription',
            'amount' => $amount,
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    #[Test]
    public function a_corrected_payment_points_at_the_one_it_replaces(): void
    {
        $original = $this->pay($this->line(2000), 800);

        $this->actingAs($this->admin)
            ->put(route('players.transactions.update', [$this->player, $original]), ['amount' => 500, 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();

        $replacement = Transaction::where('archived', false)->sole();
        $this->assertSame($original->id, $replacement->replaces_transaction_id);
        $this->assertNull($original->fresh()->replaces_transaction_id);
    }

    #[Test]
    public function every_row_of_a_split_correction_points_at_the_original(): void
    {
        $original = $this->pay($this->line(500), 500);

        // 800 against a 500 line: 500 on the subscription, 300 as a donation.
        $this->actingAs($this->admin)
            ->put(route('players.transactions.update', [$this->player, $original]), ['amount' => 800, 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Transaction::where('archived', false)->count());
        $this->assertSame(2, Transaction::where('replaces_transaction_id', $original->id)->count());
    }

    #[Test]
    public function a_new_payment_replaces_nothing(): void
    {
        $this->assertNull($this->pay($this->line(2000), 800)->replaces_transaction_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TransactionCorrectionLinkTest`
Expected: FAIL — `a_corrected_payment_points_at_the_one_it_replaces`: null is not the original's id.

- [ ] **Step 3: Set the link**

In `app/Http/Controllers/PlayerTransactionController.php`, inside `update()`, replace:

```php
            // Still part of the payment it was taken with: the grouped receipt keeps listing it.
            if ($transaction->payment_group && $created !== []) {
                Transaction::whereKey(array_map(fn (Transaction $t) => $t->id, $created))
                    ->update(['payment_group' => $transaction->payment_group]);
            }
```

with:

```php
            // Each new row points at the one it corrects. It is also still part of
            // the payment that one was taken with: the grouped receipt keeps listing it.
            if ($created !== []) {
                Transaction::whereKey(array_map(fn (Transaction $t) => $t->id, $created))
                    ->update(array_filter([
                        'replaces_transaction_id' => $transaction->id,
                        'payment_group' => $transaction->payment_group,
                    ]));
            }
```

- [ ] **Step 4: Cover the grouped case in the existing test**

In `tests/Feature/MultiSubscriptionPaymentTest.php`, in `an_edited_line_stays_in_its_payment`, add after the `payment_group` assertion:

```php
        $this->assertSame($second->id, $replacement->replaces_transaction_id);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter="TransactionCorrectionLinkTest|MultiSubscriptionPaymentTest|PlayerPaymentFlowTest|DebtPaymentTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/PlayerTransactionController.php tests/Feature/TransactionCorrectionLinkTest.php tests/Feature/MultiSubscriptionPaymentTest.php
git commit -m "feat(transactions): link a corrected payment to the one it replaces"
```

---

### Task 5: `TransactionHistory` service

**Files:**
- Create: `app/Services/Finance/TransactionHistory.php`
- Test: `tests/Feature/TransactionHistoryTest.php`

**Interfaces:**
- Consumes: `TransactionChanges::snapshot()`, `::between()` (Task 2); `TransactionRevision`, `Transaction::replaces_transaction_id`, `ActivityLog::revision()` (Task 1); `Transaction::receiptLines(array $with)`; `TransactionTitle::for()`, `TransactionTitle::RELATIONS`; `FinanceAccountLabel::for()`.
- Produces:
  - `TransactionHistory::entries(Transaction $transaction): list<array>` — oldest first. Each entry: `type` (`recorded|correction|edited|cancelled|replaced|printed`), `at` (`'Y-m-d H:i'` or null), `user` (?string), `as_user` (?string), `changes` (`list<array{field: string, old: mixed, new: mixed}>`), `links` (`list<array{id: int}>`), `print` (`?array{copy_number: int, format: string, code: string}`).
  - `TransactionHistory::trace(array $entries): array{recorded: ?array, modified: ?array}` — each side `{type, at, user, as_user}`.
  - `TransactionHistory::cancellation(Transaction $transaction, array $entries): ?array{at: ?string, user: ?string, as_user: ?string, replaced_by: list<int>}` — null for a live transaction.
  - `TransactionHistory::group(Transaction $transaction): ?array{total: float, lines: list<array{id: int, title: string, amount: float, current: bool}>}` — null unless the payment group has at least two lines.

`user` is the person really at the keyboard: when an admin acted logged in as another account, `user` is the admin and `as_user` the account.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TransactionHistoryTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\ReceiptPrint;
use App\Models\Transaction;
use App\Models\TransactionRevision;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Finance\RecalculatePlayerDebtService;
use App\Services\Finance\TransactionHistory;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['email_verified_at' => now(), 'name' => 'Cashier One', 'preferred_lng' => 'en']);
    }

    private function tx(array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'title' => 'Hall rent', 'amount' => 1000, 'transaction_type' => 'expense', 'category' => 'rent',
            'payment_method' => 'cash', 'status' => 'Paid', 'recorded_by_user_id' => $this->admin->id,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $category = FinanceCategory::updateOrCreate(['type' => 'income', 'name' => 'Donation'], ['is_active' => true]);

        return array_merge([
            'title' => 'Sponsor gift', 'transaction_type' => 'income', 'finance_category_id' => $category->id,
            'amount' => 3000, 'transaction_date' => now()->toDateString(), 'payment_method' => 'cash', 'status' => 'Paid',
        ], $overrides);
    }

    /** @return list<string> */
    private function types(array $entries): array
    {
        return array_column($entries, 'type');
    }

    #[Test]
    public function a_transaction_with_no_log_still_says_who_recorded_it_and_when(): void
    {
        $tx = $this->tx();

        $entries = TransactionHistory::entries($tx);

        $this->assertCount(1, $entries);
        $this->assertSame('recorded', $entries[0]['type']);
        $this->assertSame('Cashier One', $entries[0]['user']);
        $this->assertNull($entries[0]['as_user']);
        $this->assertSame($tx->created_at->format('Y-m-d H:i'), $entries[0]['at']);
        $this->assertSame([], $entries[0]['changes']);
    }

    #[Test]
    public function an_old_edit_shows_without_detail(): void
    {
        $tx = $this->tx();
        ActivityRecorder::record($this->admin, ActivityAction::TRANSACTION_UPDATED, $tx);

        $entries = TransactionHistory::entries($tx);

        $this->assertSame(['recorded', 'edited'], $this->types($entries));
        $this->assertSame('Cashier One', $entries[1]['user']);
        $this->assertSame([], $entries[1]['changes']);
    }

    #[Test]
    public function an_edit_lists_old_and_new_values_with_category_names(): void
    {
        $this->actingAs($this->admin)->post(route('transactions.store'), $this->payload())->assertSessionHasNoErrors();
        $tx = Transaction::latest('id')->firstOrFail();
        $other = FinanceCategory::create(['type' => 'income', 'name' => 'Trace Sponsoring', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->put(route('transactions.update', $tx), $this->payload(['amount' => 4500, 'finance_category_id' => $other->id]))
            ->assertSessionHasNoErrors();

        $edited = collect(TransactionHistory::entries($tx->fresh()))->firstWhere('type', 'edited');
        $changes = collect($edited['changes'])->keyBy('field');

        $this->assertEquals(3000, $changes['amount']['old']);
        $this->assertEquals(4500, $changes['amount']['new']);
        $this->assertSame($other->fresh()->localized_name, $changes['finance_category_id']['new']);
        $this->assertIsString($changes['finance_category_id']['old']);
    }

    #[Test]
    public function a_deleted_category_falls_back_to_the_name_kept_at_edit_time(): void
    {
        $tx = $this->tx();
        $log = ActivityRecorder::record($this->admin, ActivityAction::TRANSACTION_UPDATED, $tx);
        TransactionRevision::create([
            'transaction_id' => $tx->id,
            'activity_log_id' => $log->id,
            'diff' => ['finance_category_id' => ['old' => 99998, 'new' => 99999, 'old_label' => 'Old rent', 'new_label' => 'New rent']],
        ]);

        $change = TransactionHistory::entries($tx)[1]['changes'][0];

        $this->assertSame(['field' => 'finance_category_id', 'old' => 'Old rent', 'new' => 'New rent'], $change);
    }

    #[Test]
    public function work_done_logged_in_as_someone_names_the_real_person_first(): void
    {
        $member = User::factory()->admin()->create(['name' => 'Karim Desk']);

        $this->actingAs($member)
            ->withSession([Impersonation::SESSION_KEY => $this->admin->id])
            ->post(route('transactions.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $entry = TransactionHistory::entries(Transaction::latest('id')->firstOrFail())[0];

        $this->assertSame('Cashier One', $entry['user']);
        $this->assertSame('Karim Desk', $entry['as_user']);
    }

    #[Test]
    public function a_cancellation_is_the_last_entry_and_fills_the_banner(): void
    {
        $tx = $this->tx();

        $this->actingAs($this->admin)->delete(route('transactions.destroy', $tx))->assertRedirect();

        $tx = $tx->fresh();
        $entries = TransactionHistory::entries($tx);
        $this->assertSame(['recorded', 'cancelled'], $this->types($entries));

        $banner = TransactionHistory::cancellation($tx, $entries);
        $this->assertSame('Cashier One', $banner['user']);
        $this->assertSame($entries[1]['at'], $banner['at']);
        $this->assertSame([], $banner['replaced_by']);

        $this->assertSame('cancelled', TransactionHistory::trace($entries)['modified']['type']);
    }

    #[Test]
    public function a_live_transaction_has_no_banner_and_no_modification(): void
    {
        $tx = $this->tx();
        $entries = TransactionHistory::entries($tx);

        $this->assertNull(TransactionHistory::cancellation($tx, $entries));
        $this->assertSame('Cashier One', TransactionHistory::trace($entries)['recorded']['user']);
        $this->assertNull(TransactionHistory::trace($entries)['modified']);
    }

    #[Test]
    public function a_corrected_payment_and_its_replacement_point_at_each_other(): void
    {
        $player = Player::create([
            'membership_id' => '8000000002', 'firstname' => 'Flow', 'lastname' => 'Test',
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
        $line = PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => null, 'transaction_id' => null,
            'year' => (int) now()->year, 'status_at_time' => 'student',
            'is_mandatory' => true, 'amount_owed' => 2000, 'amount_paid' => 0,
        ]);
        app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());

        $this->actingAs($this->admin)->post(route('players.transactions.store', $player), [
            'player_subscription_id' => $line->id, 'category' => 'subscription', 'amount' => 800, 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();
        $original = Transaction::latest('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('players.transactions.update', [$player, $original]), ['amount' => 500, 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();
        $replacement = Transaction::where('archived', false)->sole();

        $old = TransactionHistory::entries($original->fresh());
        $this->assertSame(['recorded', 'replaced'], $this->types($old));
        $this->assertSame([['id' => $replacement->id]], $old[1]['links']);
        $this->assertSame([$replacement->id], TransactionHistory::cancellation($original->fresh(), $old)['replaced_by']);

        $new = TransactionHistory::entries($replacement);
        $this->assertSame('correction', $new[0]['type']);
        $this->assertSame([['id' => $original->id]], $new[0]['links']);
        $amount = collect($new[0]['changes'])->firstWhere('field', 'amount');
        $this->assertEquals(800, $amount['old']);
        $this->assertEquals(500, $amount['new']);
    }

    #[Test]
    public function every_printed_receipt_is_an_entry_with_its_code(): void
    {
        $tx = $this->tx();
        ReceiptPrint::create([
            'transaction_id' => $tx->id, 'transaction_number' => $tx->id, 'code' => '123456789012',
            'copy_number' => 1, 'format' => ReceiptPrint::FORMAT_THERMAL,
            'printed_by_user_id' => $this->admin->id, 'snapshot' => [],
        ]);

        $entries = TransactionHistory::entries($tx);

        $this->assertSame(['recorded', 'printed'], $this->types($entries));
        $this->assertSame(['copy_number' => 1, 'format' => 'thermal', 'code' => '1234 5678 9012'], $entries[1]['print']);
        $this->assertSame('Cashier One', $entries[1]['user']);
        // Printing is not a modification.
        $this->assertNull(TransactionHistory::trace($entries)['modified']);
    }

    #[Test]
    public function payments_taken_together_are_listed_with_their_total(): void
    {
        $group = (string) Str::uuid();
        $first = $this->tx(['amount' => 1000, 'transaction_type' => 'income', 'payment_group' => $group]);
        $second = $this->tx(['amount' => 600, 'transaction_type' => 'income', 'payment_group' => $group]);

        $result = TransactionHistory::group($first);

        $this->assertSame(1600.0, $result['total']);
        $this->assertSame([$first->id, $second->id], array_column($result['lines'], 'id'));
        $this->assertSame([true, false], array_column($result['lines'], 'current'));
        $this->assertSame('Hall rent', $result['lines'][0]['title']);

        $this->assertNull(TransactionHistory::group($this->tx()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TransactionHistoryTest`
Expected: FAIL — `Class "App\Services\Finance\TransactionHistory" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Services/Finance/TransactionHistory.php`:

```php
<?php

namespace App\Services\Finance;

use App\Models\ActivityLog;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\ReceiptPrint;
use App\Models\Transaction;
use App\Models\TransactionRevision;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Support\FinanceAccountLabel;
use App\Support\TransactionTitle;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * A transaction's story for its page: who recorded it and when, every edit
 * with old and new values, cancellation or correction, every receipt printed.
 * Read-only; built from the row itself, the activity log, the revisions, the
 * replacement rows and the print log.
 *
 * In an entry, `user` is the person at the keyboard. When an admin acted
 * logged in as another account, `as_user` is that account.
 */
final class TransactionHistory
{
    private const USER = 'id,name,firstname,lastname';

    /** The events that mean "this row was recorded". */
    private const RECORDING = [
        ActivityAction::TRANSACTION_RECORDED,
        ActivityAction::PAYMENT_RECORDED,
        ActivityAction::PAYMENT_EDITED,
    ];

    /** What a corrected player payment can differ by from its original. */
    private const CORRECTION_FIELDS = ['amount', 'payment_method', 'finance_account_id', 'description'];

    /** Entries of the same second, in the order they can only have happened. */
    private const ORDER = ['recorded' => 0, 'correction' => 0, 'edited' => 1, 'printed' => 2, 'cancelled' => 3, 'replaced' => 3];

    /** The fields whose stored value is an id shown by its name. */
    private const NAMED = ['finance_category_id', 'finance_account_id'];

    /** @return list<array<string, mixed>> oldest first */
    public static function entries(Transaction $transaction): array
    {
        $logs = self::logsFor($transaction);
        $entries = [self::firstEntry($transaction, $logs)];

        foreach ($logs->where('action', ActivityAction::TRANSACTION_UPDATED) as $log) {
            $entries[] = self::entry('edited', $log->occurred_at, $log->user, $log->impersonator, $log->revision?->diff ?? []);
        }

        // An edit whose log row is gone: the values remain, the author does not.
        $orphans = TransactionRevision::where('transaction_id', $transaction->id)->whereNull('activity_log_id')->orderBy('id')->get();
        foreach ($orphans as $revision) {
            $entries[] = self::entry('edited', $revision->created_at, null, null, $revision->diff);
        }

        foreach ($logs->where('action', ActivityAction::TRANSACTION_CANCELLED) as $log) {
            $entries[] = self::entry('cancelled', $log->occurred_at, $log->user, $log->impersonator);
        }

        $replacements = Transaction::query()
            ->with('recordedBy:'.self::USER)
            ->where('replaces_transaction_id', $transaction->id)
            ->orderBy('id')
            ->get();
        if ($replacements->isNotEmpty()) {
            $first = $replacements->first();
            $entries[] = self::entry(
                'replaced',
                $first->created_at,
                $first->recordedBy,
                self::recordingLog(self::logsFor($first))?->impersonator,
                links: $replacements->modelKeys(),
            );
        }

        $prints = ReceiptPrint::with('printedBy:'.self::USER)
            ->where('transaction_number', $transaction->id)
            ->orderBy('copy_number')
            ->get();
        foreach ($prints as $print) {
            $entries[] = self::entry('printed', $print->created_at, $print->printedBy, null, print: [
                'copy_number' => $print->copy_number,
                'format' => $print->format,
                'code' => $print->displayCode(),
            ]);
        }

        usort($entries, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        return self::presented($entries);
    }

    /**
     * Who recorded the transaction, and who last changed it (edit, cancellation
     * or correction). Not `updated_at`: bulk queries touch it too.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array{recorded: ?array<string, mixed>, modified: ?array<string, mixed>}
     */
    public static function trace(array $entries): array
    {
        $entries = collect($entries);
        $pick = fn (?array $entry) => $entry
            ? ['type' => $entry['type'], 'at' => $entry['at'], 'user' => $entry['user'], 'as_user' => $entry['as_user']]
            : null;

        return [
            'recorded' => $pick($entries->first(fn (array $e) => in_array($e['type'], ['recorded', 'correction'], true))),
            'modified' => $pick($entries->last(fn (array $e) => in_array($e['type'], ['edited', 'cancelled', 'replaced'], true))),
        ];
    }

    /**
     * For an archived transaction: who cancelled it and when, and the rows
     * recorded in its place when it was a correction. Null for a live one.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return ?array{at: ?string, user: ?string, as_user: ?string, replaced_by: list<int>}
     */
    public static function cancellation(Transaction $transaction, array $entries): ?array
    {
        if (! $transaction->archived) {
            return null;
        }

        $entries = collect($entries);
        $replaced = $entries->firstWhere('type', 'replaced');
        $source = $entries->last(fn (array $e) => $e['type'] === 'cancelled') ?? $replaced;

        return [
            // Archived by a path that left no event (an old fix): the row's own clock.
            'at' => $source['at'] ?? $transaction->updated_at?->format('Y-m-d H:i'),
            'user' => $source['user'] ?? null,
            'as_user' => $source['as_user'] ?? null,
            'replaced_by' => array_column($replaced['links'] ?? [], 'id'),
        ];
    }

    /**
     * The payments taken on the same receipt, this one included, with their
     * total. Null when the transaction was paid alone.
     *
     * @return ?array{total: float, lines: list<array{id: int, title: string, amount: float, current: bool}>}
     */
    public static function group(Transaction $transaction): ?array
    {
        if (! $transaction->payment_group) {
            return null;
        }

        $lines = $transaction->receiptLines(TransactionTitle::RELATIONS);
        if ($lines->count() < 2) {
            return null;
        }

        return [
            'total' => round((float) $lines->sum(fn (Transaction $line) => (float) $line->amount), 2),
            'lines' => $lines->map(fn (Transaction $line) => [
                'id' => $line->id,
                'title' => TransactionTitle::for($line),
                'amount' => (float) $line->amount,
                'current' => $line->is($transaction),
            ])->values()->all(),
        ];
    }

    /** The row itself: recorded, or recorded as the correction of another payment. */
    private static function firstEntry(Transaction $transaction, Collection $logs): array
    {
        $log = self::recordingLog($logs);
        $original = $transaction->replaces_transaction_id
            ? Transaction::find($transaction->replaces_transaction_id)
            : null;

        if (! $original) {
            return self::entry('recorded', $transaction->created_at, $transaction->recordedBy ?? $log?->user, $log?->impersonator);
        }

        // One payment corrected into one: say what differs. Split into several
        // rows (subscription + donation), an amount comparison would mislead.
        $changes = Transaction::where('replaces_transaction_id', $original->id)->count() === 1
            ? array_intersect_key(
                TransactionChanges::between(TransactionChanges::snapshot($original), TransactionChanges::snapshot($transaction)),
                array_flip(self::CORRECTION_FIELDS),
            )
            : [];

        return self::entry(
            'correction',
            $transaction->created_at,
            $transaction->recordedBy ?? $log?->user,
            $log?->impersonator,
            $changes,
            [$original->id],
        );
    }

    /** @return Collection<int, ActivityLog> */
    private static function logsFor(Transaction $transaction): Collection
    {
        return ActivityLog::query()
            ->with(['user:'.self::USER, 'impersonator:'.self::USER, 'revision'])
            ->where('subject_type', $transaction->getMorphClass())
            ->where('subject_id', $transaction->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    private static function recordingLog(Collection $logs): ?ActivityLog
    {
        return $logs->first(fn (ActivityLog $log) => in_array($log->action, self::RECORDING, true));
    }

    /**
     * @param  array<string, array<string, mixed>>  $changes  as stored: { field: { old, new, … } }
     * @param  list<int>  $links
     */
    private static function entry(
        string $type,
        ?DateTimeInterface $at,
        ?User $user,
        ?User $impersonator,
        array $changes = [],
        array $links = [],
        ?array $print = null,
    ): array {
        return [
            'type' => $type,
            'at' => $at?->format('Y-m-d H:i'),
            'user' => ($impersonator ?? $user)?->fullname,
            'as_user' => $impersonator ? $user?->fullname : null,
            'changes' => $changes,
            'links' => array_map(fn (int $id) => ['id' => $id], $links),
            'print' => $print,
            'sort' => [$at?->getTimestamp() ?? 0, self::ORDER[$type]],
        ];
    }

    /**
     * Stored diffs become display rows: a category or register is shown by
     * its name — today's when it still exists, else the one kept at edit time.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private static function presented(array $entries): array
    {
        $names = self::names($entries);

        return array_map(function (array $entry) use ($names) {
            $rows = [];
            foreach ($entry['changes'] as $field => $change) {
                $rows[] = [
                    'field' => $field,
                    'old' => self::shown($field, $change, 'old', $names),
                    'new' => self::shown($field, $change, 'new', $names),
                ];
            }

            $entry['changes'] = $rows;
            unset($entry['sort']);

            return $entry;
        }, $entries);
    }

    /** @param array<string, array<int, string>> $names */
    private static function shown(string $field, array $change, string $side, array $names): mixed
    {
        $value = $change[$side] ?? null;

        if (! in_array($field, self::NAMED, true) || $value === null) {
            return $value;
        }

        return $names[$field][$value] ?? $change[$side.'_label'] ?? '#'.$value;
    }

    /**
     * Today's names of every category and register the entries mention: one query per kind.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, array<int, string>>
     */
    private static function names(array $entries): array
    {
        $ids = fn (string $field) => collect($entries)
            ->map(fn (array $entry) => $entry['changes'][$field] ?? null)
            ->filter()
            ->flatMap(fn (array $change) => [$change['old'] ?? null, $change['new'] ?? null])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $categories = $ids('finance_category_id');
        $accounts = $ids('finance_account_id');

        return [
            'finance_category_id' => $categories === [] ? [] : FinanceCategory::whereIn('id', $categories)->get()
                ->mapWithKeys(fn (FinanceCategory $category) => [$category->id => $category->localized_name])->all(),
            'finance_account_id' => $accounts === [] ? [] : FinanceAccount::with(['branch', 'category'])->whereIn('id', $accounts)->get()
                ->mapWithKeys(fn (FinanceAccount $account) => [$account->id => FinanceAccountLabel::for($account)])->all(),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TransactionHistoryTest`
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/TransactionHistory.php tests/Feature/TransactionHistoryTest.php
git commit -m "feat(transactions): build a transaction's history from its records"
```

---

### Task 6: Pass the history to the page; wipe revisions on reset

**Files:**
- Modify: `app/Http/Controllers/TransactionController.php` (`show`, lines 146–177; new private `season()`)
- Modify: `app/Services/Finance/FinanceResetService.php:81`
- Modify: `tests/Feature/ReceiptPrintTest.php` (`the_transaction_page_lists_its_printed_copies`)
- Modify: `tests/Feature/FinanceResetTest.php` (`seedFinance`, `assertWiped`)
- Test: `tests/Feature/TransactionShowTraceTest.php`

**Interfaces:**
- Consumes: `TransactionHistory::entries()`, `::trace()`, `::cancellation()`, `::group()` (Task 5).
- Produces: Inertia props of `Transactions/Show`: `transaction`, `history`, `trace`, `cancellation`, `group`, `season` (`?array{label: string, closed: bool}`). The `prints` prop is removed (prints are `printed` entries of `history`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TransactionShowTraceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FiscalPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionShowTraceTest extends TestCase
{
    use RefreshDatabase;

    private function tx(User $recorder): Transaction
    {
        return Transaction::create([
            'title' => 'Hall rent', 'amount' => 1000, 'transaction_type' => 'expense', 'category' => 'rent',
            'payment_method' => 'cash', 'status' => 'Paid', 'recorded_by_user_id' => $recorder->id,
        ]);
    }

    #[Test]
    public function anyone_who_may_view_transactions_sees_the_trace_and_history(): void
    {
        $recorder = User::factory()->admin()->create(['name' => 'Cashier One']);
        $tx = $this->tx($recorder);
        $viewer = User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::create(['key' => 'reader', 'name' => ['en' => 'Reader'], 'permissions' => ['transactions' => ['view']]])->id,
        ]);

        $this->actingAs($viewer)->get(route('transactions.show', $tx))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Transactions/Show')
                ->has('history', 1)
                ->where('history.0.type', 'recorded')
                ->where('history.0.user', 'Cashier One')
                ->where('trace.recorded.user', 'Cashier One')
                ->where('trace.recorded.at', $tx->created_at->format('Y-m-d H:i'))
                ->where('trace.modified', null)
                ->where('cancellation', null)
                ->where('group', null)
                ->where('season.label', FiscalPeriod::label((int) $tx->fiscal_year))
                ->where('season.closed', false)
                ->missing('prints'));
    }

    #[Test]
    public function a_cancelled_transaction_in_a_closed_season_says_so(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Cashier One']);
        $tx = $this->tx($admin);
        $this->actingAs($admin)->delete(route('transactions.destroy', $tx))->assertRedirect();
        FiscalYear::where('year', $tx->fiscal_year)->update(['status' => 'closed']);

        $this->actingAs($admin)->get(route('transactions.show', $tx))
            ->assertInertia(fn (Assert $page) => $page
                ->where('cancellation.user', 'Cashier One')
                ->where('cancellation.replaced_by', [])
                ->where('trace.modified.type', 'cancelled')
                ->where('season.closed', true));
    }

    #[Test]
    public function the_page_no_longer_sends_the_recorder_account(): void
    {
        $admin = User::factory()->admin()->create();
        $tx = $this->tx($admin);

        $props = $this->actingAs($admin)->get(route('transactions.show', $tx))->viewData('page')['props'];

        $this->assertArrayNotHasKey('email', $props['transaction']['recorded_by'] ?? []);
        $this->assertArrayNotHasKey('received_by', $props['transaction']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TransactionShowTraceTest`
Expected: FAIL — the `history` prop is missing.

- [ ] **Step 3: Update `show()`**

In `app/Http/Controllers/TransactionController.php`, add the import:

```php
use App\Services\Finance\TransactionHistory;
```

Replace the whole `show()` method with:

```php
    public function show(Transaction $transaction): Response
    {
        // Eager-load the transaction relation too: PlayerSubscription appends
        // remaining_amount/payment_status accessors that read ->transaction (else N+1).
        $transaction->load([
            // Name columns only: the trace shows who, the page needs nothing else of the account.
            'recordedBy:id,name,firstname,lastname',
            'financeCategory',
            ...TransactionTitle::RELATIONS,
            ...Transaction::FINANCE_ACCOUNT_LABEL,
            'playerSubscriptions.player',
            'playerSubscriptions.transaction',
        ]);

        TransactionTitle::decorate($transaction);

        $history = TransactionHistory::entries($transaction);

        return Inertia::render('Transactions/Show', [
            'transaction' => $transaction,
            // Recorded, edited, cancelled, printed: one list, oldest first.
            'history' => $history,
            'trace' => TransactionHistory::trace($history),
            'cancellation' => TransactionHistory::cancellation($transaction, $history),
            'group' => TransactionHistory::group($transaction),
            'season' => $this->season($transaction),
        ]);
    }

    /**
     * The season a transaction is booked in and whether it is closed. Read
     * with a query, not the fiscalYear relation: once loaded, the relation
     * would be serialized over the `fiscal_year` column of the same name.
     *
     * @return ?array{label: string, closed: bool}
     */
    private function season(Transaction $transaction): ?array
    {
        if (! $transaction->fiscal_year) {
            return null;
        }

        $key = (int) $transaction->fiscal_year;

        return [
            'label' => FiscalPeriod::label($key),
            'closed' => FiscalYear::where('year', $key)->where('status', 'closed')->exists(),
        ];
    }
```

`ReceiptPrint` is no longer used in this method. Remove `use App\Models\ReceiptPrint;` from the controller only if no other method in the file uses it (search the file for `ReceiptPrint`).

- [ ] **Step 4: Update the existing print test**

In `tests/Feature/ReceiptPrintTest.php`, replace the assertion chain of `the_transaction_page_lists_its_printed_copies` with:

```php
        $this->actingAs($this->admin)->get(route('transactions.show', $transaction))
            ->assertInertia(fn (Assert $page) => $page
                ->has('history', 2)
                ->where('history.1.type', 'printed')
                ->where('history.1.print.copy_number', 1)
                ->where('history.1.print.code', $print->displayCode())
                ->where('history.1.user', 'Cashier One'));
```

- [ ] **Step 5: Wipe revisions on finance reset**

In `tests/Feature/FinanceResetTest.php`, at the end of `seedFinance()` (before the `Storage::disk('local')->put(...)` line) add:

```php
        DB::table('transaction_revisions')->insert([
            'transaction_id' => $tx, 'diff' => json_encode(['amount' => ['old' => 900, 'new' => 1000]]), 'created_at' => now(),
        ]);
```

and in `assertWiped()` add `'transaction_revisions'` to the table list:

```php
        foreach (['subscriptions', 'subscription_tarifications', 'subscription_cost_lines', 'player_subscriptions', 'transaction_revisions', 'transactions', 'branch_subscription', 'finance_transfers', 'budgets'] as $table) {
```

In `app/Services/Finance/FinanceResetService.php`, add the delete right after the `receipt_prints` line:

```php
            DB::table('receipt_prints')->delete();
            DB::table('transaction_revisions')->delete();
            DB::table('transactions')->delete();
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter="TransactionShowTraceTest|ReceiptPrintTest|FinanceResetTest|TransactionBulkDeleteAndFiscalYearDeleteTest"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/TransactionController.php app/Services/Finance/FinanceResetService.php tests/Feature/TransactionShowTraceTest.php tests/Feature/ReceiptPrintTest.php tests/Feature/FinanceResetTest.php
git commit -m "feat(transactions): send trace, history and season to the transaction page"
```

---

### Task 7: The page

**Files:**
- Modify: `resources/js/i18n/ar.json`, `resources/js/i18n/fr.json`, `resources/js/i18n/en.json`
- Create: `resources/js/lib/transactionActor.js`
- Create: `resources/js/Pages/Transactions/Partials/TransactionHistory.vue`
- Modify: `resources/js/Pages/Transactions/Show.vue` (whole file)

**Interfaces:**
- Consumes: props `transaction`, `history`, `trace`, `cancellation`, `group`, `season` (Task 6), with the entry shape of Task 5.
- Produces: `actorLabel(t, entry): string`; `data-help` anchors `tx-cancelled`, `tx-trace`, `tx-group`, `tx-prints` (kept: `resources/help/shots.js` reads the receipt code with `[data-help="tx-prints"] li .font-mono`).

Inside the history card, the receipt code is the only element with the class `font-mono`. Do not use that class for anything else in the card.

- [ ] **Step 1: Add the i18n keys**

In each catalog, insert the block on the lines right after the `"tx.uncategorised"` entry. Do not reorder or reformat the file.

`resources/js/i18n/ar.json`:

```json
    "tx_history.acting_as": "بصفة {user}",
    "tx_history.field_account": "رقم الحساب",
    "tx_history.field_register": "الصندوق",
    "tx_history.heading": "سجل المعاملة",
    "tx_history.no_detail": "عُدّلت قبل تفعيل حفظ التغييرات: لا تفاصيل.",
    "tx_history.receipt_replaced": "أُرفق ملف جديد",
    "tx_history.type_cancelled": "أُلغيت",
    "tx_history.type_correction": "سُجّلت تصحيحًا للمعاملة",
    "tx_history.type_edited": "عُدّلت",
    "tx_history.type_printed": "طُبع وصل",
    "tx_history.type_recorded": "سُجّلت",
    "tx_history.type_replaced": "صُحّحت بالمعاملة",
    "tx_show.attached_receipt": "الوصل المرفق",
    "tx_show.cancelled_banner": "هذه المعاملة ملغاة",
    "tx_show.corrected_banner": "هذه المعاملة صُحّحت واستُبدلت",
    "tx_show.corrected_by": "المعاملة الجديدة:",
    "tx_show.corrects": "تصحيح للمعاملة",
    "tx_show.group_heading": "دُفعت في الوصل نفسه",
    "tx_show.this_one": "هذه المعاملة",
    "tx_trace.modified_by": "آخر تعديل:",
    "tx_trace.recorded_by": "سجّلها:",
    "tx_trace.unknown_user": "غير معروف",
```

`resources/js/i18n/fr.json`:

```json
    "tx_history.acting_as": "en tant que {user}",
    "tx_history.field_account": "N° de compte",
    "tx_history.field_register": "Caisse",
    "tx_history.heading": "Historique de la transaction",
    "tx_history.no_detail": "Modifiée avant l'enregistrement des changements : pas de détail.",
    "tx_history.receipt_replaced": "Nouveau fichier joint",
    "tx_history.type_cancelled": "Annulée",
    "tx_history.type_correction": "Enregistrée en correction de la transaction",
    "tx_history.type_edited": "Modifiée",
    "tx_history.type_printed": "Reçu imprimé",
    "tx_history.type_recorded": "Enregistrée",
    "tx_history.type_replaced": "Corrigée par la transaction",
    "tx_show.attached_receipt": "Justificatif joint",
    "tx_show.cancelled_banner": "Cette transaction est annulée",
    "tx_show.corrected_banner": "Cette transaction a été corrigée et remplacée",
    "tx_show.corrected_by": "Nouvelle transaction :",
    "tx_show.corrects": "Corrige la transaction",
    "tx_show.group_heading": "Payées sur le même reçu",
    "tx_show.this_one": "Cette transaction",
    "tx_trace.modified_by": "Dernière modification :",
    "tx_trace.recorded_by": "Enregistrée par :",
    "tx_trace.unknown_user": "Inconnu",
```

`resources/js/i18n/en.json`:

```json
    "tx_history.acting_as": "as {user}",
    "tx_history.field_account": "Account number",
    "tx_history.field_register": "Cash register",
    "tx_history.heading": "Transaction history",
    "tx_history.no_detail": "Edited before changes were kept: no detail.",
    "tx_history.receipt_replaced": "New file attached",
    "tx_history.type_cancelled": "Cancelled",
    "tx_history.type_correction": "Recorded as a correction of transaction",
    "tx_history.type_edited": "Edited",
    "tx_history.type_printed": "Receipt printed",
    "tx_history.type_recorded": "Recorded",
    "tx_history.type_replaced": "Corrected by transaction",
    "tx_show.attached_receipt": "Attached receipt",
    "tx_show.cancelled_banner": "This transaction is cancelled",
    "tx_show.corrected_banner": "This transaction was corrected and replaced",
    "tx_show.corrected_by": "New transaction:",
    "tx_show.corrects": "Corrects transaction",
    "tx_show.group_heading": "Paid on the same receipt",
    "tx_show.this_one": "This transaction",
    "tx_trace.modified_by": "Last modified:",
    "tx_trace.recorded_by": "Recorded by:",
    "tx_trace.unknown_user": "Unknown",
```

- [ ] **Step 2: Create the actor label helper**

Create `resources/js/lib/transactionActor.js`:

```js
/**
 * Who did something on a transaction: "Karim", or "Admin (as Karim)" when an
 * admin acted logged in as Karim's account. `entry` is a history entry, the
 * trace's recorded/modified side or the cancellation: { user, as_user }.
 */
export function actorLabel(t, entry) {
    if (!entry?.user) return t('tx_trace.unknown_user');

    return entry.as_user
        ? `${entry.user} (${t('tx_history.acting_as', { user: entry.as_user })})`
        : entry.user;
}
```

- [ ] **Step 3: Create the history component**

Create `resources/js/Pages/Transactions/Partials/TransactionHistory.vue`:

```vue
<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatDate } from '@/Composables/useFormatDate';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useStatusLabel } from '@/Composables/useStatusLabel';
import { actorLabel } from '@/lib/transactionActor';

defineProps({
    // TransactionHistory::entries — oldest first.
    entries: { type: Array, required: true },
});

const { t } = useI18n();
const { formatDate, formatDateTime } = useFormatDate();
const { formatMoney } = useFormatMoney();
const { statusLabel } = useStatusLabel();

const typeLabel = (type) => ({
    recorded: t('tx_history.type_recorded'),
    correction: t('tx_history.type_correction'),
    edited: t('tx_history.type_edited'),
    cancelled: t('tx_history.type_cancelled'),
    replaced: t('tx_history.type_replaced'),
    printed: t('tx_history.type_printed'),
}[type] ?? type);

const fieldLabel = (field) => ({
    title: t('title'),
    amount: t('amount'),
    transaction_date: t('date'),
    transaction_type: t('type'),
    finance_category_id: t('category'),
    finance_account_id: t('tx_history.field_register'),
    payment_method: t('payment_method'),
    payment_account: t('tx_history.field_account'),
    payment_ccp_key: t('ccp_key'),
    payment_bank_name: t('bank_name'),
    payment_holder: t('holder'),
    payment_reference: t('reference'),
    status: t('status'),
    description: t('description'),
    receipt: t('tx_show.attached_receipt'),
}[field] ?? field);

// A stored value as the page writes it everywhere else. Categories and
// registers arrive already named by the server.
const shown = (field, value) => {
    if (value === null || value === undefined || value === '') return '—';

    switch (field) {
        case 'amount': return formatMoney(value);
        case 'transaction_date': return formatDate(value);
        case 'transaction_type': return value === 'income' ? t('income') : t('expense');
        case 'status': return statusLabel('payment', value);
        case 'payment_method': return statusLabel('payment_method', value);
        default: return String(value);
    }
};

// Amounts keep their digit order in Arabic too.
const isNumber = (field) => field === 'amount';
</script>

<template>
    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
        <li v-for="(entry, index) in entries" :key="index" class="py-3 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-medium text-slate-900 dark:text-slate-100">
                    {{ typeLabel(entry.type) }}
                    <Link
                        v-for="target in entry.links"
                        :key="target.id"
                        :href="route('transactions.show', target.id)"
                        class="ms-1 text-primary-600 hover:underline dark:text-primary-300"
                        dir="ltr"
                    >#{{ target.id }}</Link>
                    <template v-if="entry.print">
                        <span class="ms-1">· {{ entry.print.copy_number === 1 ? t('receipts.original') : t('receipts.duplicate', { n: entry.print.copy_number }) }}</span>
                        <span class="ms-1 text-xs text-slate-500 dark:text-slate-400">{{ t(`receipts.format.${entry.print.format}`) }}</span>
                    </template>
                </span>
                <span class="text-slate-600 dark:text-slate-300">{{ formatDateTime(entry.at) }} · {{ actorLabel(t, entry) }}</span>
                <span v-if="entry.print" class="font-mono text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ entry.print.code }}</span>
            </div>

            <div v-if="entry.changes.length" class="mt-2 space-y-1 rounded-lg bg-slate-50 px-3 py-2 text-xs dark:bg-slate-950">
                <div v-for="change in entry.changes" :key="change.field" class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <span class="font-medium text-slate-500 dark:text-slate-400">{{ fieldLabel(change.field) }}</span>
                    <template v-if="change.field === 'receipt'">
                        <span class="text-slate-900 dark:text-slate-100">{{ t('tx_history.receipt_replaced') }}</span>
                    </template>
                    <template v-else>
                        <span class="text-slate-500 line-through [overflow-wrap:anywhere] dark:text-slate-400" :dir="isNumber(change.field) ? 'ltr' : null">{{ shown(change.field, change.old) }}</span>
                        <span aria-hidden="true" class="text-slate-400 rtl:rotate-180">→</span>
                        <span class="font-medium text-slate-900 [overflow-wrap:anywhere] dark:text-slate-100" :dir="isNumber(change.field) ? 'ltr' : null">{{ shown(change.field, change.new) }}</span>
                    </template>
                </div>
            </div>
            <p v-else-if="entry.type === 'edited'" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('tx_history.no_detail') }}</p>
        </li>
    </ul>
</template>
```

- [ ] **Step 4: Rewrite `Show.vue`**

Replace the whole content of `resources/js/Pages/Transactions/Show.vue` with:

```vue
<script setup>
import FitFigure from '@/Components/Stats/FitFigure.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import Icon from '@/Components/Icon.vue';
import IconButton from '@/Components/IconButton.vue';
import TransactionHistory from '@/Pages/Transactions/Partials/TransactionHistory.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCurrency } from '@/Composables/useCurrency';
import { useCan } from '@/Composables/useCan';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFormatDate } from '@/Composables/useFormatDate';
import { useFinanceAccountLabel } from '@/Composables/useFinanceAccountLabel';
import { useStatusLabel } from '@/Composables/useStatusLabel';
import { paymentStatusColor } from '@/lib/statusLabels';
import { actorLabel } from '@/lib/transactionActor';

const { t } = useI18n();
const { currencyLabel } = useCurrency();
const { statusLabel } = useStatusLabel();
const { accountLabel } = useFinanceAccountLabel();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    transaction: { type: Object, required: true },
    // The transaction's story, oldest first: recorded, edited, cancelled, printed.
    history: { type: Array, default: () => [] },
    // { recorded, modified }: who recorded it, who last changed it.
    trace: { type: Object, default: null },
    // Set for a cancelled or corrected transaction: { at, user, as_user, replaced_by }.
    cancellation: { type: Object, default: null },
    // The payments taken on the same receipt: { total, lines }.
    group: { type: Object, default: null },
    // { label, closed }
    season: { type: Object, default: null },
});
const { can } = useCan();
const { formatDate, formatDateTime } = useFormatDate();
const isIncome = computed(() => props.transaction.transaction_type === 'income');
// The category's own name — never the stored slug.
const categoryName = computed(() => props.transaction.finance_category?.localized_name
    || props.transaction.finance_category?.name || t('tx.uncategorised'));
// Filled for a CCP or bank payment only; labelled as the form labels them.
const paymentDetails = computed(() => [
    { label: props.transaction.payment_method === 'ccp' ? t('ccp_number') : t('account_number'), value: props.transaction.payment_account },
    { label: t('ccp_key'), value: props.transaction.payment_ccp_key },
    { label: t('bank_name'), value: props.transaction.payment_bank_name },
    { label: t('holder'), value: props.transaction.payment_holder },
    { label: t('reference'), value: props.transaction.payment_reference },
].filter((row) => row.value));
</script>

<template>
    <Head :title="t('transaction')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2">
                    <Link :href="route('transactions.index')" :aria-label="t('back')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-300"><Icon name="back" class="rtl:rotate-180" /></Link>
                    <h1 class="min-w-0 truncate text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('transaction') }} #{{ transaction.id }}</h1>
                </div>
                <div class="flex items-center gap-2">
                    <IconButton :href="route('transactions.ticket', transaction.id)" external target="_blank" icon="print" :label="t('receipts.print_ticket')" variant="primary" data-help="tx-print-ticket" />
                    <IconButton :href="route('transactions.receipt', transaction.id)" external target="_blank" icon="document" :label="t('receipts.print_a4')" data-help="tx-print-a4" />
                    <IconButton v-if="can('transactions', 'edit')" :href="route('transactions.edit', transaction.id)" icon="pencil" :label="t('edit')" />
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Cancelled, or corrected and replaced: said first, with who and when -->
            <div v-if="cancellation" data-help="tx-cancelled" class="rounded-2xl bg-rose-50 p-4 text-sm text-rose-800 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/30">
                <p class="font-semibold">{{ cancellation.replaced_by.length ? t('tx_show.corrected_banner') : t('tx_show.cancelled_banner') }}</p>
                <p class="mt-1">{{ actorLabel(t, cancellation) }} · {{ formatDateTime(cancellation.at) }}</p>
                <p v-if="cancellation.replaced_by.length" class="mt-1">
                    {{ t('tx_show.corrected_by') }}
                    <Link v-for="id in cancellation.replaced_by" :key="id" :href="route('transactions.show', id)" class="ms-1 font-semibold underline" dir="ltr">#{{ id }}</Link>
                </p>
            </div>

            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="mb-5 border-b border-slate-100 pb-4 dark:border-slate-800">
                    <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('title') }}</p>
                    <h2 class="mt-1 text-lg font-bold text-slate-900 dark:text-slate-100">{{ transaction.display_title }}</h2>
                    <Link v-if="transaction.player_summary" :href="route('players.show', transaction.player_summary.id)" class="mt-1 inline-flex items-center gap-2 text-sm text-primary-600 hover:underline dark:text-primary-300">
                        {{ transaction.player_summary.name }}
                        <span class="font-mono text-xs text-slate-400">{{ transaction.player_summary.membership_id }}</span>
                    </Link>
                    <p v-if="transaction.replaces_transaction_id" class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                        {{ t('tx_show.corrects') }}
                        <Link :href="route('transactions.show', transaction.replaces_transaction_id)" class="ms-1 font-medium text-primary-600 hover:underline dark:text-primary-300" dir="ltr">#{{ transaction.replaces_transaction_id }}</Link>
                    </p>
                </div>
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div data-help="tx-show-amount" class="[container-type:inline-size]">
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('amount') }}</p>
                        <p class="mt-1 flex flex-wrap items-baseline gap-x-1 text-2xl font-bold tabular-nums [overflow-wrap:anywhere]" :class="isIncome ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'"><FitFigure dir="ltr" :value="(isIncome ? '+' : '−') + formatMoney(transaction.amount)" /><span class="text-sm font-medium text-slate-400">{{ currencyLabel }}</span></p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('type') }}</p>
                        <div class="mt-2"><Badge :label="isIncome ? t('income') : t('expense')" :color="isIncome ? 'emerald' : 'rose'" /></div>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('status') }}</p>
                        <div class="mt-2"><Badge :label="statusLabel('payment', transaction.status)" :color="paymentStatusColor(transaction.status)" /></div>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('category') }}</p>
                        <p class="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">{{ categoryName }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('date') }}</p>
                        <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ formatDate(transaction.transaction_date) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('payment_method') }}</p>
                        <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ statusLabel('payment_method', transaction.payment_method) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">
                            {{ isIncome ? t('destination_register') : t('source_register') }}
                        </p>
                        <p class="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">
                            {{ accountLabel(transaction.finance_account) }}
                        </p>
                    </div>
                    <div v-if="season">
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('season') }}</p>
                        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-900 dark:text-slate-100">
                            <span dir="ltr">{{ season.label }}</span>
                            <Badge v-if="season.closed" :label="t('closed')" color="slate" />
                        </p>
                    </div>
                    <div v-for="row in paymentDetails" :key="row.label">
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ row.label }}</p>
                        <p class="mt-1 text-sm text-slate-900 [overflow-wrap:anywhere] dark:text-slate-100">{{ row.value }}</p>
                    </div>
                    <div v-if="transaction.receipt_url">
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('tx_show.attached_receipt') }}</p>
                        <a :href="transaction.receipt_url" target="_blank" class="mt-1 inline-block text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">{{ t('view') }} ↗</a>
                    </div>
                    <div v-if="transaction.description" class="sm:col-span-2 lg:col-span-3">
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('description') }}</p>
                        <p class="mt-1 text-sm text-slate-700 dark:text-slate-200">{{ transaction.description }}</p>
                    </div>
                </div>

                <!-- Who recorded it and when; who last changed it -->
                <div v-if="trace?.recorded" data-help="tx-trace" class="mt-6 flex flex-wrap gap-x-8 gap-y-2 border-t border-slate-100 pt-4 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    <p>
                        {{ t('tx_trace.recorded_by') }}
                        <span class="font-medium text-slate-700 dark:text-slate-200">{{ actorLabel(t, trace.recorded) }} · {{ formatDateTime(trace.recorded.at) }}</span>
                    </p>
                    <p v-if="trace.modified">
                        {{ t('tx_trace.modified_by') }}
                        <span class="font-medium text-slate-700 dark:text-slate-200">{{ actorLabel(t, trace.modified) }} · {{ formatDateTime(trace.modified.at) }}</span>
                    </p>
                </div>
            </div>

            <!-- Related player subscriptions -->
            <div v-if="transaction.player_subscriptions?.length" class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h3 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('players') }}</h3>
                <div class="space-y-2">
                    <div v-for="ps in transaction.player_subscriptions" :key="ps.id" class="flex items-center justify-between rounded-lg bg-slate-50 dark:bg-slate-950 px-4 py-3">
                        <Link :href="route('players.show', ps.player_id)" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">
                            {{ ps.player?.fullname }}
                        </Link>
                        <span class="text-sm text-slate-600 dark:text-slate-300">{{ formatMoney(ps.amount_paid) }}</span>
                    </div>
                </div>
            </div>

            <!-- Paid on the same receipt: the other lines and the total -->
            <div v-if="group" data-help="tx-group" class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h3 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('tx_show.group_heading') }}</h3>
                <div class="space-y-2">
                    <div v-for="line in group.lines" :key="line.id" class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 dark:bg-slate-950 px-4 py-3">
                        <span v-if="line.current" class="flex min-w-0 flex-wrap items-center gap-2 text-sm font-medium text-slate-900 dark:text-slate-100">
                            <span dir="ltr">#{{ line.id }}</span> {{ line.title }}
                            <Badge :label="t('tx_show.this_one')" color="slate" />
                        </span>
                        <Link v-else :href="route('transactions.show', line.id)" class="min-w-0 text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">
                            <span dir="ltr">#{{ line.id }}</span> {{ line.title }}
                        </Link>
                        <span class="text-sm tabular-nums text-slate-600 dark:text-slate-300" dir="ltr">{{ formatMoney(line.amount) }}</span>
                    </div>
                </div>
                <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-3 text-sm font-semibold text-slate-900 dark:border-slate-800 dark:text-slate-100">
                    <span>{{ t('total') }}</span>
                    <span class="flex items-baseline gap-1 tabular-nums"><span dir="ltr">{{ formatMoney(group.total) }}</span><span class="text-xs font-medium text-slate-400">{{ currencyLabel }}</span></span>
                </div>
            </div>

            <!-- History: recorded, edited, cancelled, printed — who and when -->
            <div data-help="tx-prints" class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('tx_history.heading') }}</h3>
                    <Link :href="route('receipts.verify')" class="text-sm text-primary-600 hover:underline dark:text-primary-300">{{ t('receipts.verify_title') }}</Link>
                </div>
                <TransactionHistory :entries="history" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 5: Check the catalogs and build**

Run: `npm run i18n:check`
Expected: exit 0, no "does not resolve" and no missing-key line.

Run: `npm run build`
Expected: build succeeds.

`receipts.never_printed` is no longer used by any page. If `npm run i18n:check` reports it as unused, delete that key from the three catalogs; otherwise leave the catalogs as they are.

- [ ] **Step 6: Check the page in the browser**

Serve the worktree on its own port with the dev database copied in (see the worktree notes: copy `database/database.sqlite` and the `players`, `branches`, `opponents` media folders into the worktree; run `php artisan migrate` there):

Run: `php artisan serve --port=2027 --no-reload`

Open `http://127.0.0.1:2027/transactions` and check, in Arabic then in French, light then dark, and at a 320 px wide window:

1. A plain transaction: trace line shows «سجّلها: <name> · <date, time>»; history has one «سُجّلت» line.
2. Edit its amount and title: back on the page, «آخر تعديل» appears; history has an «عُدّلت» line with `old → new` for both fields; the arrow points from the old value to the new one in Arabic.
3. Print the ticket, reload: a «طُبع وصل · أصلي» line with the 12-digit code.
4. From a player page, correct a payment: the old transaction's page shows the rose banner with a link to the new one; the new one shows «تصحيح للمعاملة #N» under the title and a «سُجّلت تصحيحًا للمعاملة #N» line with the amount change.
5. A grouped payment (several subscriptions paid at once): «دُفعت في الوصل نفسه» lists every line, marks the current one, shows the total.
6. Delete a transaction, open its URL: rose banner «هذه المعاملة ملغاة».
7. Nothing overflows horizontally at 320 px.

Fix what is wrong before committing.

- [ ] **Step 7: Commit**

```bash
git add resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json resources/js/lib/transactionActor.js resources/js/Pages/Transactions/Partials/TransactionHistory.vue resources/js/Pages/Transactions/Show.vue
git commit -m "feat(transactions): trace line, history card and payment details on the page"
```

---

### Task 8: Help Center

**Files:**
- Create: `resources/help/ar/finance/transaction-history.md`
- Create: `resources/help/fr/finance/transaction-history.md`
- Create: `resources/help/en/finance/transaction-history.md`
- Modify: `resources/help/{ar,fr,en}/subscriptions/receipts.md` (one sentence each)
- Modify: `resources/help/shots.js` (new shot; one comment)
- Create/replace: `public/help-shots/{ar,fr,en}/` images of the shots `fin-tx-history`, `receipt-tx-show`, `fin-tx-saved`

**Interfaces:**
- Consumes: `data-help="tx-trace"` and `data-help="tx-prints"` (Task 7); the existing shot `receipt-tx-index`.

Read `resources/help/README.md` before writing: sentence length (≤ 15 words), 3–7 steps, UI labels exactly as the catalogs spell them.

- [ ] **Step 1: Add the shot**

In `resources/help/shots.js`, right after the `receipt-tx-show` shot object, add:

```js
    {
        // أحمد's payment page: the trace line under the first card, then «سجل المعاملة».
        name: 'fin-tx-history',
        url: '/players/1',
        targets: ['tx-trace', 'tx-prints'],
        before: openAhmedTransaction,
    },
```

In the same file, in the comment above `ahmedReceiptCode`, replace `«الوصولات المطبوعة»` with `«سجل المعاملة»`.

- [ ] **Step 2: Write the Arabic article**

Create `resources/help/ar/finance/transaction-history.md`:

```markdown
---
title: كيف أعرف من سجّل معاملة ومن عدّلها
summary: افتح صفحة المعاملة لترى من سجّلها ومتى، وكل تعديل أو إلغاء أو طباعة بعدها.
module: transactions
pages: [transactions.show, transactions.index]
keywords: [من سجّل, سجل المعاملة, تتبع, تعديل معاملة, التاريخ والساعة, من عدّل, من ألغى, تصحيح دفعة, مراجعة]
related: [finance/record-transaction, finance/cancel-transaction, subscriptions/receipts]
---

كل معاملة تحفظ قصتها: من سجّلها، ومتى، وما تغيّر فيها بعد ذلك.

:::example
دفع **أحمد بن علي** «اشتراك سنوي»: 3000 د.ج. نفتح صفحة دفعته لنرى من سجّلها.
:::

:::step افتح صفحة «المعاملات»
في الهاتف، افتح القائمة أولًا بزر ☰. في القائمة، اضغط على «المعاملات» تحت «المالية». لا تراها؟ اضغط على «المالية» لتظهر. اكتب «أحمد» في خانة البحث.
::figure receipt-tx-index ring=nav-transactions,tx-search,tx-row-first
:::

:::step اضغط على عنوان المعاملة
اضغط على عنوان دفعة أحمد. تُفتح صفحة المعاملة.
::figure fin-tx-history ring=tx-trace
:::

:::step اقرأ سطر «سجّلها:»
السطر في أسفل البطاقة الأولى. فيه اسم المستخدم، ثم التاريخ والساعة. إن عُدّلت المعاملة، يظهر بجانبه «آخر تعديل:».
::figure fin-tx-history ring=tx-trace zoom=tx-trace
:::

:::step اقرأ «سجل المعاملة»
انزل إلى بطاقة «سجل المعاملة». كل سطر حدث واحد: تسجيل، تعديل، إلغاء، أو طباعة وصل. في كل سطر الاسم والتاريخ والساعة. سطر التعديل يكتب القيمة القديمة مشطوبة، ثم الجديدة.
::figure fin-tx-history ring=tx-prints
:::

عُدّلت المعاملة قبل إضافة هذا السجل؟ يظهر السطر «عُدّلت» بلا تفاصيل.

صُحّحت دفعة لاعب؟ الدفعة القديمة تحمل رابطًا إلى الجديدة، والجديدة رابطًا إلى القديمة.

:::tip
ترى «بصفة» بعد اسم؟ مدير دخل بحساب ذلك المستخدم. الاسم الأول هو من قام بالعمل.
:::
```

- [ ] **Step 3: Write the French twin**

Create `resources/help/fr/finance/transaction-history.md`:

```markdown
---
title: Comment savoir qui a enregistré une transaction et qui l'a modifiée
summary: Ouvrez la page de la transaction pour voir qui l'a enregistrée et quand, puis chaque modification, annulation ou impression.
module: transactions
pages: [transactions.show, transactions.index]
keywords: [qui a enregistré, historique de la transaction, suivi, modifier une transaction, date et heure, qui a modifié, qui a annulé, correction de paiement, contrôle]
related: [finance/record-transaction, finance/cancel-transaction, subscriptions/receipts]
---

Chaque transaction garde son histoire : qui l'a enregistrée, quand, et ce qui a changé ensuite.

:::example
**أحمد بن علي** a payé « اشتراك سنوي » : 3000 DA. Nous ouvrons la page de son paiement pour voir qui l'a enregistré.
:::

:::step Ouvrez la page « Transactions »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Transactions » sous « Finances ». Vous ne la voyez pas ? Cliquez sur « Finances » pour l'afficher. Tapez « أحمد » dans la zone de recherche.
::figure receipt-tx-index ring=nav-transactions,tx-search,tx-row-first
:::

:::step Cliquez sur le titre de la transaction
Cliquez sur le titre du paiement de أحمد. La page de la transaction s'ouvre.
::figure fin-tx-history ring=tx-trace
:::

:::step Lisez la ligne « Enregistrée par : »
La ligne est en bas de la première carte. Elle donne le nom de l'utilisateur, puis la date et l'heure. Si la transaction a été modifiée, « Dernière modification : » s'affiche à côté.
::figure fin-tx-history ring=tx-trace zoom=tx-trace
:::

:::step Lisez « Historique de la transaction »
Descendez jusqu'à la carte « Historique de la transaction ». Chaque ligne est un événement : enregistrement, modification, annulation ou impression d'un reçu. Chaque ligne porte le nom, la date et l'heure. Une modification affiche l'ancienne valeur barrée, puis la nouvelle.
::figure fin-tx-history ring=tx-prints
:::

La transaction a été modifiée avant l'ajout de cet historique ? La ligne « Modifiée » s'affiche sans détail.

Le paiement d'un joueur a été corrigé ? L'ancien paiement porte un lien vers le nouveau, et le nouveau vers l'ancien.

:::tip
Vous voyez « en tant que » après un nom ? Un administrateur s'est connecté avec le compte de cet utilisateur. Le premier nom est celui qui a fait l'action.
:::
```

- [ ] **Step 4: Write the English twin**

Create `resources/help/en/finance/transaction-history.md`:

```markdown
---
title: How do I see who recorded a transaction and who edited it
summary: Open the transaction's page to see who recorded it and when, then every edit, cancellation or print after that.
module: transactions
pages: [transactions.show, transactions.index]
keywords: [who recorded, transaction history, tracking, edit a transaction, date and time, who edited, who cancelled, payment correction, review]
related: [finance/record-transaction, finance/cancel-transaction, subscriptions/receipts]
---

Every transaction keeps its story: who recorded it, when, and what changed afterwards.

:::example
**أحمد بن علي** paid “اشتراك سنوي”: 3000 DZD. We open his payment's page to see who recorded it.
:::

:::step Open the “Transactions” page
On a phone, first open the menu with the ☰ button. In the menu, click “Transactions” under “Finance”. You don't see it? Click “Finance” to show it. Type “أحمد” in the search box.
::figure receipt-tx-index ring=nav-transactions,tx-search,tx-row-first
:::

:::step Click the transaction's title
Click the title of أحمد's payment. The transaction's page opens.
::figure fin-tx-history ring=tx-trace
:::

:::step Read the “Recorded by:” line
The line is at the bottom of the first card. It gives the user's name, then the date and time. If the transaction was edited, “Last modified:” shows next to it.
::figure fin-tx-history ring=tx-trace zoom=tx-trace
:::

:::step Read “Transaction history”
Scroll down to the “Transaction history” card. Each line is one event: recording, edit, cancellation or receipt print. Each line carries the name, the date and the time. An edit shows the old value struck through, then the new one.
::figure fin-tx-history ring=tx-prints
:::

Was the transaction edited before this history existed? The “Edited” line shows without detail.

Was a player's payment corrected? The old payment carries a link to the new one, and the new one to the old.

:::tip
You see “as” after a name? An administrator was logged in with that user's account. The first name is the one who did the action.
:::
```

- [ ] **Step 5: Rename the card in the receipts article**

`resources/help/ar/subscriptions/receipts.md` — replace:

```
في صفحة الدفعة، تحت «الوصولات المطبوعة»، تجد كل نسخة ورمزها.
```
with:
```
في صفحة الدفعة، في «سجل المعاملة»، تجد كل نسخة ورمزها.
```

`resources/help/fr/subscriptions/receipts.md` — replace:

```
Sur la page du paiement, sous « Reçus imprimés », vous trouvez chaque copie et son code.
```
with:
```
Sur la page du paiement, dans « Historique de la transaction », vous trouvez chaque copie et son code.
```

`resources/help/en/subscriptions/receipts.md` — replace:

```
On the payment's page, under “Printed receipts”, you find every copy and its code.
```
with:
```
On the payment's page, in “Transaction history”, you find every copy and its code.
```

Leave the other mention of «الوصولات المطبوعة» / « Reçus imprimés » / “Printed receipts” in that article: it describes the verify page, which keeps that heading.

- [ ] **Step 6: Take the screenshots**

The transaction page changed, so the two existing shots of it are retaken with the new one, in the three languages:

```bash
npm run build
npm run help:shots -- fin-tx-history receipt-tx-show fin-tx-saved
npm run help:shots -- --locale=fr fin-tx-history receipt-tx-show fin-tx-saved
npm run help:shots -- --locale=en fin-tx-history receipt-tx-show fin-tx-saved
```

Expected: each run ends without "target missing". Open the three `fin-tx-history` images (light and dark) in `public/help-shots/ar`, `fr`, `en` and check the trace line and the history card are visible and ringed.

If Chromium is not installed: `npx playwright install chromium`, then rerun.

- [ ] **Step 7: Check the help content**

Run: `php artisan help:check`
Expected: no error. If it names a sentence as too long, split that sentence in two in the article and in its two twins, then rerun.

Run: `php artisan test --filter=Help`
Expected: PASS.

- [ ] **Step 8: Commit**

List the image files the three runs wrote (`git status --short public/help-shots`) and stage each by its path, with the article and script files:

```bash
git add resources/help/ar/finance/transaction-history.md resources/help/fr/finance/transaction-history.md resources/help/en/finance/transaction-history.md resources/help/ar/subscriptions/receipts.md resources/help/fr/subscriptions/receipts.md resources/help/en/subscriptions/receipts.md resources/help/shots.js
git add <each public/help-shots/{ar,fr,en}/ file listed by git status for the three shots>
git commit -m "docs(help): who recorded a transaction and who edited it"
```

---

### Task 9: Whole-branch verification

**Files:** none new.

- [ ] **Step 1: Format**

Run: `vendor/bin/pint --dirty`
Expected: no file left to fix. If Pint changed files, rerun the tests of the tasks those files belong to, then commit the formatting:

```bash
git add <the files Pint changed>
git commit -m "style: pint"
```

- [ ] **Step 2: Full test suite**

Run: `php artisan test`
Expected: PASS, no failure. A failing test outside this feature that also fails on `main` is not caused by this branch: note it in the report, do not fix it here.

- [ ] **Step 3: Front-end checks**

Run: `npm run i18n:check`
Expected: exit 0.

Run: `npm run build`
Expected: build succeeds.

- [ ] **Step 4: Migrations run both ways**

Run: `php artisan migrate:rollback --step=2`, then `php artisan migrate` (in the worktree, against its copied database).
Expected: both succeed; `transactions.replaces_transaction_id` and `transaction_revisions` exist again.

- [ ] **Step 5: Report**

Report to the owner: commits on `feat/transaction-trace`, test count, what the browser check showed, and the three limits from the spec (old edits without detail, past corrections unlinked, prints without the "logged in as" marker). After the owner approves the merge, `php artisan migrate` must run on the dev database of the main folder, and a new desktop build is needed for the other PC.
