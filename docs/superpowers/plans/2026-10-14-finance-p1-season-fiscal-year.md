# Finance P1 — Fiscal Year Follows the Season — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every transaction belongs to the season (Sept → Aug) that contains its date; fiscal years, totals, locks, reports and dashboard all speak seasons.

**Architecture:** One helper, `App\Support\FiscalPeriod`, built on the existing `App\Support\Season`, turns a date into a season key (the season's END year, e.g. 2026/27 → 2027) and a key into dates/labels. `TransactionObserver` always derives `fiscal_year` from `transaction_date` with it and refuses writes into closed seasons (`ClosedFiscalYearException`). Readers (Finance page, PDF, dashboard) switch to the same helper. A migration re-buckets existing rows.

**Tech Stack:** Laravel 13, PHP 8.3, PHPUnit (`#[Test]`, `RefreshDatabase`), Inertia + Vue 3, vue-i18n flat dotted keys, SQLite.

Spec: `docs/superpowers/specs/2026-10-14-finance-simplify-design.md` (section P1).

## Global Constraints

- Branch: `feat/finance-season-year` from `main`. Stage explicit file paths only (never a directory); the tree has untracked `.claude/` and `database/sport-club-backup-*.zip` that must never be committed.
- Season key = season END year; when `seasonStartMonth` is 1 the key is the plain calendar year. Label "2026/2027" (calendar: "2026"). Same rule as `Subscription::seasonLabel`.
- Start month comes only from `Season::startMonth()` (setting `seasonStartMonth`, default 9). Never hard-code September.
- Flash messages are `['key' => 'flash.x', 'params' => [...]]`; every new `flash.*` key goes into `resources/js/i18n/{ar,fr,en}.json` (flat dotted keys) and `npm run i18n:check` must pass.
- Offline-only: no CDN/remote resources.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Test runner: `php artisan test --filter=<Name>`; full suite `php artisan test`.

## File map

| File | Responsibility |
|---|---|
| `app/Support/FiscalPeriod.php` (new) | date ↔ season key, bounds, label, month order, SQL due-date suffix |
| `app/Exceptions/ClosedFiscalYearException.php` (new) | refusal to write into a closed season; renders a flash redirect |
| `app/Observers/TransactionObserver.php` | derive season key + FY row; closed-season guard on save/delete |
| `app/Models/FiscalYear.php` | `season_label` appended attribute |
| `app/Models/Subscription.php` | `seasonLabel`/`currentSeasonEndYear`/`assignTo` delegate to FiscalPeriod |
| `app/Services/FinanceService.php` | `closeYear` carries into next season with season dates; never touches a closed next season |
| `app/Http/Controllers/FiscalYearController.php` | season dates, season labels in flashes |
| `app/Http/Controllers/TransactionController.php`, `app/Http/Requests/Transaction/StoreTransactionRequest.php` | stop accepting/deriving `fiscal_year`; drop per-controller lock checks |
| `app/Http/Controllers/PlayerTransactionController.php`, `app/Services/Equipment/EquipmentStockService.php`, `app/Http/Controllers/EquipmentItemController.php`, `app/Console/Commands/BackfillFinance.php`, `app/Console/Commands/ImportMongoJsonData.php` | stop setting calendar years |
| `app/Http/Controllers/FinanceController.php`, `resources/js/Pages/Finance/Index.vue`, `resources/js/Pages/Finance/Settings.vue` | season labels, Sep→Aug trend, season picker |
| `app/Http/Controllers/ReportController.php`, `resources/views/pdf/financial-summary.blade.php` | PDF by `fiscal_year`, category names not slugs |
| `app/Services/Dashboard/DashboardFilters.php`, `MemberStats.php`, `OverviewStats.php`, `HeroStats.php`, `resources/js/Components/Dashboard/DashboardFilterBar.vue` | "This season" range, season renewal, season due dates |
| `app/Services/Finance/SeasonRebucketer.php` (new), `database/migrations/2026_10_14_000001_rebucket_fiscal_years_by_season.php` (new) | one-time re-bucket of existing data |

---

### Task 1: `FiscalPeriod` helper; Subscription delegates to it

**Files:**
- Create: `app/Support/FiscalPeriod.php`
- Modify: `app/Models/Subscription.php` (`assignTo` ~:119, `seasonLabel` ~:138, `currentSeasonEndYear` ~:144, `calendarSeason`)
- Modify: `app/Models/FiscalYear.php` (append `season_label`)
- Test: `tests/Feature/FiscalPeriodTest.php` (new)

**Interfaces — Produces:**
- `FiscalPeriod::keyForDate(DateTimeInterface|string $date): int`
- `FiscalPeriod::currentKey(): int`
- `FiscalPeriod::season(int $key): Season`
- `FiscalPeriod::start(int $key): CarbonImmutable`, `FiscalPeriod::end(int $key): CarbonImmutable`
- `FiscalPeriod::label(int $key): string`
- `FiscalPeriod::months(): list<int>` — month numbers in season order (Sept start → `[9,10,11,12,1,…,8]`)
- `FiscalPeriod::endSuffix(): string` — `'-08-31'` for a September season; appended to a key year in SQL
- `FiscalYear` JSON gains `season_label`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\Subscription;
use App\Models\WebsiteConfig;
use App\Support\FiscalPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalPeriodTest extends TestCase
{
    use RefreshDatabase;

    private function startMonth(int $month): void
    {
        $config = WebsiteConfig::singleton();
        $config->update(['settings' => [...$config->settings, 'seasonStartMonth' => $month]]);
    }

    #[Test]
    public function a_date_belongs_to_the_season_ending_in_the_key_year(): void
    {
        $this->assertSame(2026, FiscalPeriod::keyForDate('2026-08-31'));
        $this->assertSame(2027, FiscalPeriod::keyForDate('2026-09-01'));
        $this->assertSame(2027, FiscalPeriod::keyForDate('2026-10-14'));
    }

    #[Test]
    public function a_key_knows_its_bounds_label_and_month_order(): void
    {
        $this->assertSame('2026-09-01', FiscalPeriod::start(2027)->toDateString());
        $this->assertSame('2027-08-31', FiscalPeriod::end(2027)->toDateString());
        $this->assertSame('2026/2027', FiscalPeriod::label(2027));
        $this->assertSame([9, 10, 11, 12, 1, 2, 3, 4, 5, 6, 7, 8], FiscalPeriod::months());
        $this->assertSame('-08-31', FiscalPeriod::endSuffix());
    }

    #[Test]
    public function a_calendar_season_keys_by_the_plain_year(): void
    {
        $this->startMonth(1);

        $this->assertSame(2026, FiscalPeriod::keyForDate('2026-10-14'));
        $this->assertSame('2026-01-01', FiscalPeriod::start(2026)->toDateString());
        $this->assertSame('2026-12-31', FiscalPeriod::end(2026)->toDateString());
        $this->assertSame('2026', FiscalPeriod::label(2026));
        $this->assertSame('-12-31', FiscalPeriod::endSuffix());
    }

    #[Test]
    public function the_current_key_matches_the_subscription_default(): void
    {
        Carbon::setTestNow('2026-10-14');

        $this->assertSame(2027, FiscalPeriod::currentKey());
        $this->assertSame(2027, Subscription::currentSeasonEndYear());
        $this->assertSame('2026/2027', Subscription::seasonLabel(2027));
    }

    #[Test]
    public function a_fiscal_year_serializes_its_season_label(): void
    {
        $fy = FiscalYear::create(['year' => 2027, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31', 'status' => 'open', 'opening_balance' => 0]);

        $this->assertSame('2026/2027', $fy->toArray()['season_label']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FiscalPeriodTest`
Expected: FAIL — `Class "App\Support\FiscalPeriod" not found`.

- [ ] **Step 3: Write `app/Support/FiscalPeriod.php`**

```php
<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The club's fiscal year is its season. A season is keyed by the year it
 * ENDS in (2026/27 → 2027) — the same key annual subscriptions use — or by
 * the plain year when the club's season is the calendar year. Every place
 * that books money into a year, or reads a year's totals, goes through here.
 */
final class FiscalPeriod
{
    public static function isCalendar(): bool
    {
        return Season::startMonth() === 1;
    }

    public static function keyForDate(DateTimeInterface|string $date): int
    {
        $season = Season::forDate($date);

        return self::isCalendar() ? $season->startYear : $season->startYear + 1;
    }

    public static function currentKey(): int
    {
        return self::keyForDate(CarbonImmutable::now());
    }

    public static function season(int $key): Season
    {
        return Season::forStartYear(self::isCalendar() ? $key : $key - 1);
    }

    public static function start(int $key): CarbonImmutable
    {
        return self::season($key)->start();
    }

    public static function end(int $key): CarbonImmutable
    {
        return self::season($key)->end();
    }

    /** "2026/2027", or "2026" when the season is the calendar year. */
    public static function label(int $key): string
    {
        return self::isCalendar() ? (string) $key : ($key - 1).'/'.$key;
    }

    /** @return list<int> month numbers in season order, first month first */
    public static function months(): array
    {
        $first = Season::startMonth();

        return array_map(fn (int $i) => (($first - 1 + $i) % 12) + 1, range(0, 11));
    }

    /** "-08-31": appended to a key year in SQL it gives that season's last day. */
    public static function endSuffix(): string
    {
        // 2001 is not a leap year, so a March season ends on the 28th.
        return self::end(2001)->format('-m-d');
    }
}
```

- [ ] **Step 4: Delegate in `app/Models/Subscription.php`**

Add `use App\Support\FiscalPeriod;`. Replace the bodies:

```php
    public static function seasonLabel(int $endYear): string
    {
        return FiscalPeriod::label($endYear);
    }

    /** The end year of the season running today — the default for a new annual subscription. */
    public static function currentSeasonEndYear(): int
    {
        return FiscalPeriod::currentKey();
    }
```

In `assignTo`, replace `'year' => $this->year ?? (int) now()->year,` with `'year' => $this->year ?? FiscalPeriod::currentKey(),` and update the comment above it to "a one-off charge takes the season it was assigned in."

Make `calendarSeason()` return `FiscalPeriod::isCalendar()`; if it has no other callers after this change (`grep -rn "calendarSeason" app`), delete it and the now-unused `Season` import.

- [ ] **Step 5: Append `season_label` in `app/Models/FiscalYear.php`**

```php
use App\Support\FiscalPeriod;
use Illuminate\Database\Eloquent\Casts\Attribute;

    protected $appends = ['season_label'];

    protected function seasonLabel(): Attribute
    {
        return Attribute::get(fn () => FiscalPeriod::label((int) $this->year));
    }
```

- [ ] **Step 6: Run tests**

Run: `php artisan test --filter="FiscalPeriodTest|SeasonTest|Subscription"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Support/FiscalPeriod.php app/Models/Subscription.php app/Models/FiscalYear.php tests/Feature/FiscalPeriodTest.php
git commit -m "feat(finance): FiscalPeriod — one rule for which season a date belongs to"
```

---

### Task 2: Every transaction is booked into the season of its date

**Files:**
- Modify: `app/Observers/TransactionObserver.php:22-33`
- Modify: `app/Http/Requests/Transaction/StoreTransactionRequest.php` (drop the `fiscal_year` rule)
- Modify: `app/Http/Controllers/TransactionController.php` (`store` ~:148-162: drop the `fiscal_year` default)
- Modify: `app/Http/Controllers/PlayerTransactionController.php:223,300` (drop `fiscal_year`)
- Modify: `app/Services/Equipment/EquipmentStockService.php:211-213` (drop `fiscal_year` and its comment)
- Modify: `app/Http/Controllers/EquipmentItemController.php:542` (drop `fiscal_year`)
- Modify: `app/Console/Commands/BackfillFinance.php` (season keys and dates)
- Modify: `app/Console/Commands/ImportMongoJsonData.php` (`resolveFiscalYear` ~:1140)
- Test: `tests/Feature/TransactionSeasonYearTest.php` (new)

**Interfaces — Consumes:** `FiscalPeriod::keyForDate`, `start`, `end` (Task 1). **Produces:** the invariant `transactions.fiscal_year === FiscalPeriod::keyForDate(transaction_date)` and a `fiscal_years` row with season dates for that key.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\Player;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionSeasonYearTest extends TestCase
{
    use RefreshDatabase;

    private function tx(string $date, array $extra = []): Transaction
    {
        return Transaction::create([
            'amount' => 1000, 'transaction_date' => $date, 'transaction_type' => 'income',
            'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid',
        ] + $extra);
    }

    #[Test]
    public function a_transaction_lands_in_the_season_of_its_date(): void
    {
        $this->assertSame(2026, $this->tx('2026-08-31')->fiscal_year);
        $october = $this->tx('2026-10-06');

        $this->assertSame(2027, $october->fiscal_year);
        $fy = FiscalYear::find($october->fiscal_year_id);
        $this->assertSame(2027, $fy->year);
        $this->assertSame('2026-09-01', $fy->start_date->toDateString());
        $this->assertSame('2027-08-31', $fy->end_date->toDateString());
    }

    #[Test]
    public function a_passed_fiscal_year_is_ignored_in_favour_of_the_date(): void
    {
        $this->assertSame(2027, $this->tx('2026-10-06', ['fiscal_year' => 2026])->fiscal_year);
    }

    #[Test]
    public function changing_the_date_moves_the_transaction_to_its_new_season(): void
    {
        $tx = $this->tx('2026-08-20');
        $tx->update(['transaction_date' => '2026-09-02']);

        $this->assertSame(2027, $tx->fresh()->fiscal_year);
    }

    #[Test]
    public function a_backdated_manual_entry_uses_its_own_season_not_today(): void
    {
        Carbon::setTestNow('2026-10-14');
        $admin = User::factory()->admin()->create();
        $category = \App\Models\FinanceCategory::create(['type' => 'income', 'name' => 'Donations', 'is_active' => true]);

        $this->actingAs($admin)->post(route('transactions.store'), [
            'title' => 'Old gift', 'amount' => 500, 'transaction_type' => 'income',
            'transaction_date' => '2026-05-10', 'finance_category_id' => $category->id,
            'payment_method' => 'cash', 'status' => 'Paid',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2026, Transaction::where('title', 'Old gift')->value('fiscal_year'));
    }

    #[Test]
    public function a_subscription_payment_is_booked_by_payment_date(): void
    {
        Carbon::setTestNow('2026-10-14');
        $admin = User::factory()->admin()->create();
        $player = Player::factory()->create();
        $sub = Subscription::create(['name' => 'Annual', 'year' => 2026, 'amount_student' => 1000, 'amount_worker' => 1000, 'is_mandatory' => true, 'is_active' => true, 'kind' => 'annual']);
        $owed = $sub->assignTo($player);

        $this->actingAs($admin)->post(route('players.transactions.store', $player), [
            'player_subscription_id' => $owed->id, 'amount' => 1000, 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        // A 2025/26 debt paid in October 2026 is 2026/27 cash.
        $this->assertSame(2027, Transaction::where('player_subscription_id', $owed->id)->value('fiscal_year'));
    }
}
```

Before running, open `PlayerTransactionController@store` (:22-40) and `StoreTransactionRequest::rules()` and adjust the two POST payloads above to the exact required field names they validate (e.g. if `store` needs `amount` + `player_subscription_id` + `payment_method` only, keep as is). Do not change what the test asserts.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TransactionSeasonYearTest`
Expected: FAIL — October date gives `2026`.

- [ ] **Step 3: Rewrite the year block in `TransactionObserver::saving`**

Replace lines 24-33 (the `if (empty($transaction->fiscal_year))` block and the `FiscalYear::firstOrCreate`) with:

```php
        // The season of the transaction's own date — never a caller's guess.
        $key = FiscalPeriod::keyForDate($transaction->transaction_date ?: now());
        $transaction->fiscal_year = $key;

        $fy = FiscalYear::firstOrCreate(
            ['year' => $key],
            [
                'start_date' => FiscalPeriod::start($key)->toDateString(),
                'end_date' => FiscalPeriod::end($key)->toDateString(),
                'status' => 'open',
                'opening_balance' => 0,
            ]
        );
        $transaction->fiscal_year_id = $fy->id;
```

Add `use App\Support\FiscalPeriod;`; remove `use Carbon\Carbon;` if now unused.

- [ ] **Step 4: Stop the writers from passing calendar years**

- `StoreTransactionRequest`: delete the line `'fiscal_year' => ['nullable', 'integer', 'min:2000', 'max:'.(date('Y') + 1)],`.
- `TransactionController@store`: delete
  ```php
        if (empty($validated['fiscal_year'])) {
            $validated['fiscal_year'] = now()->year;
        }
  ```
  (the closed-year check that follows is removed in Task 3).
- `PlayerTransactionController`: delete `'fiscal_year' => $sub->year,` (:223) and `'fiscal_year' => (int) now()->year,` (:300).
- `EquipmentStockService`: delete the two comment lines and `'fiscal_year' => Carbon::parse($purchaseDate)->year,`; remove `use Carbon\Carbon;` only if no other use remains (`grep -n "Carbon::" app/Services/Equipment/EquipmentStockService.php`).
- `EquipmentItemController`: delete `'fiscal_year' => now()->year,` (:542).
- `ImportMongoJsonData::resolveFiscalYear`: replace the body with `return FiscalPeriod::keyForDate($transactionDate);` and keep the signature (the legacy value is ignored by design — the observer derives it anyway); add the import.
- `BackfillFinance::handle`: replace step 1 and the per-row year with season keys:

```php
        // 1. Fiscal years (seasons) for every transaction date present in the data.
        $years = Transaction::query()->pluck('transaction_date')->filter()
            ->map(fn ($date) => FiscalPeriod::keyForDate($date))
            ->unique()->sort()->values();

        foreach ($years as $y) {
            FiscalYear::firstOrCreate(
                ['year' => $y],
                ['start_date' => FiscalPeriod::start($y)->toDateString(), 'end_date' => FiscalPeriod::end($y)->toDateString(), 'status' => 'open', 'opening_balance' => 0]
            );
        }
        $this->info('Fiscal years: '.$years->map(fn (int $y) => FiscalPeriod::label($y))->implode(', '));
```

  and inside the chunk loop replace `$year = $t->fiscal_year ?: (int) Carbon::parse($t->transaction_date)->format('Y');` with `$year = FiscalPeriod::keyForDate($t->transaction_date ?: now());`. Remove `use Carbon\Carbon;` if unused; add `use App\Support\FiscalPeriod;`.

- [ ] **Step 5: Run the new test**

Run: `php artisan test --filter=TransactionSeasonYearTest`
Expected: PASS.

- [ ] **Step 6: Run the finance-adjacent suites and fix calendar assumptions in tests**

Run: `php artisan test --filter="Transaction|Finance|Equipment|Subscription|Player|Activity|Report|Account|Import|FixSubscriptionBilling|UserActivity"`

Tests that build a transaction dated `now()` (Oct) with `'fiscal_year' => now()->year` and then read year `now()->year` now see the next key. Fix each failing test by expressing the intent, not the old number: replace `now()->year` meaning "the fiscal year" with `FiscalPeriod::currentKey()`, and literal years used with dates in Sep–Dec with `FiscalPeriod::keyForDate($date)`. Known spots: `ReportPdfTest:33,70`, `TransactionFinanceCategoryTest:75-76`, `FinanceCategoryTranslationTest:126,200`, `TransactionReceiptPrivateTest:72`, `AccountApprovalTest:64`, `SubscriptionBillingTest:58`, `Console/ImportMongoJsonDataTest:40`, `EquipmentReceiveStockTest:53,62-73`. Never weaken an assertion to make it pass; if a test asserts a calendar behaviour the spec removes, change the expected value to the season one and say so in the commit body.

Expected after fixes: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Observers/TransactionObserver.php app/Http/Requests/Transaction/StoreTransactionRequest.php app/Http/Controllers/TransactionController.php app/Http/Controllers/PlayerTransactionController.php app/Services/Equipment/EquipmentStockService.php app/Http/Controllers/EquipmentItemController.php app/Console/Commands/BackfillFinance.php app/Console/Commands/ImportMongoJsonData.php tests/Feature/TransactionSeasonYearTest.php
# plus each test file you edited in Step 6, listed by path
git commit -m "feat(finance): book every transaction into the season of its date"
```

---

### Task 3: A closed season is locked on every path

**Files:**
- Create: `app/Exceptions/ClosedFiscalYearException.php`
- Modify: `app/Observers/TransactionObserver.php` (guard in `saving`, new `deleting`)
- Modify: `app/Http/Controllers/TransactionController.php` (remove `yearClosed()` checks in `store`, `update`, `destroy` and the private `yearClosed()` method; keep `bulkDestroy`'s partition)
- Modify: `resources/js/i18n/{ar,fr,en}.json` (`flash.season_closed`), `lang/ar.json`, `lang/fr.json` (import row message)
- Test: `tests/Feature/ClosedSeasonLockTest.php` (new)

**Interfaces — Consumes:** `FiscalPeriod::label` (Task 1), observer derivation (Task 2). **Produces:** `ClosedFiscalYearException(int $year)`; flash `['key' => 'flash.season_closed', 'params' => ['season' => '2026/2027']]`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Exceptions\ClosedFiscalYearException;
use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClosedSeasonLockTest extends TestCase
{
    use RefreshDatabase;

    private function tx(string $date): Transaction
    {
        return Transaction::create([
            'amount' => 1000, 'transaction_date' => $date, 'transaction_type' => 'income',
            'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid',
        ]);
    }

    private function close(int $key): void
    {
        FiscalYear::where('year', $key)->update(['status' => 'closed']);
    }

    #[Test]
    public function no_transaction_can_be_added_to_a_closed_season(): void
    {
        $this->tx('2026-10-01');
        $this->close(2027);

        $this->expectException(ClosedFiscalYearException::class);
        $this->tx('2026-11-01');
    }

    #[Test]
    public function a_transaction_cannot_be_moved_out_of_or_into_a_closed_season(): void
    {
        $old = $this->tx('2026-05-01');
        $open = $this->tx('2026-10-01');
        $this->close(2026);

        try {
            $old->update(['transaction_date' => '2026-10-02']);
            $this->fail('moved out of a closed season');
        } catch (ClosedFiscalYearException) {
        }

        $this->expectException(ClosedFiscalYearException::class);
        $open->update(['transaction_date' => '2026-05-02']);
    }

    #[Test]
    public function a_transaction_in_a_closed_season_cannot_be_archived_or_deleted(): void
    {
        $tx = $this->tx('2026-10-01');
        $this->close(2027);

        try {
            $tx->update(['archived' => true]);
            $this->fail('archived inside a closed season');
        } catch (ClosedFiscalYearException) {
        }

        $this->expectException(ClosedFiscalYearException::class);
        $tx->delete();
    }

    #[Test]
    public function a_player_payment_into_a_closed_season_comes_back_as_a_flash_error(): void
    {
        Carbon::setTestNow('2026-10-14');
        $this->tx('2026-10-01');
        $this->close(2027);
        $admin = User::factory()->admin()->create();
        $player = Player::factory()->create();

        $this->actingAs($admin)
            ->from(route('players.show', $player))
            ->post(route('players.transactions.store', $player), ['amount' => 500, 'payment_method' => 'cash'])
            ->assertRedirect(route('players.show', $player))
            ->assertSessionHas('error', ['key' => 'flash.season_closed', 'params' => ['season' => '2026/2027']]);

        $this->assertSame(1, Transaction::count());
    }

    #[Test]
    public function the_manual_form_reports_the_closed_season(): void
    {
        $this->tx('2026-10-01');
        $this->close(2027);
        $admin = User::factory()->admin()->create();
        $category = FinanceCategory::create(['type' => 'income', 'name' => 'Donations', 'is_active' => true]);

        $this->actingAs($admin)->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'title' => 'Late', 'amount' => 500, 'transaction_type' => 'income',
                'transaction_date' => '2026-10-10', 'finance_category_id' => $category->id,
                'payment_method' => 'cash', 'status' => 'Paid',
            ])
            ->assertSessionHas('error', ['key' => 'flash.season_closed', 'params' => ['season' => '2026/2027']]);
    }
}
```

Adjust the player-payment POST fields to what `PlayerTransactionController@store` requires for a player-level (no subscription) payment; keep the assertions.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ClosedSeasonLockTest`
Expected: FAIL — `Class "App\Exceptions\ClosedFiscalYearException" not found`.

- [ ] **Step 3: Create `app/Exceptions/ClosedFiscalYearException.php`**

```php
<?php

namespace App\Exceptions;

use App\Support\FiscalPeriod;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Money cannot be written into, out of or removed from a closed season.
 * Thrown by TransactionObserver so every path — the form, player payments,
 * imports, equipment purchases — obeys the same lock.
 */
class ClosedFiscalYearException extends RuntimeException
{
    public function __construct(public readonly int $year)
    {
        parent::__construct(__('Season :season is closed. Reopen it to change its transactions.', [
            'season' => FiscalPeriod::label($year),
        ]));
    }

    public function render(): RedirectResponse
    {
        return back()->withInput()->with('error', [
            'key' => 'flash.season_closed',
            'params' => ['season' => FiscalPeriod::label($this->year)],
        ]);
    }
}
```

- [ ] **Step 4: Guard in `TransactionObserver`**

At the end of the year block from Task 2 (after `$transaction->fiscal_year_id = $fy->id;`) add:

```php
        if ($fy->isClosed()) {
            throw new ClosedFiscalYearException($key);
        }

        $originalKey = (int) $transaction->getOriginal('fiscal_year');
        if ($transaction->exists && $originalKey && $originalKey !== $key && $this->isClosed($originalKey)) {
            throw new ClosedFiscalYearException($originalKey);
        }
```

Add the method and helper:

```php
    public function deleting(Transaction $transaction): void
    {
        if ($transaction->fiscal_year && $this->isClosed((int) $transaction->fiscal_year)) {
            throw new ClosedFiscalYearException((int) $transaction->fiscal_year);
        }
    }

    private function isClosed(int $key): bool
    {
        return FiscalYear::where('year', $key)->where('status', 'closed')->exists();
    }
```

Add `use App\Exceptions\ClosedFiscalYearException;`.

- [ ] **Step 5: Remove the per-controller checks in `TransactionController`**

Delete the `yearClosed` blocks in `store` (`if ($this->yearClosed((int) $validated['fiscal_year'])) {...}`), `update` and `destroy`, and the private `yearClosed()` method. Leave `bulkDestroy` unchanged (it uses a query update that bypasses the observer and already skips closed years).

- [ ] **Step 6: Translations**

`resources/js/i18n/en.json`: `"flash.season_closed": "Season {season} is closed. Reopen it to change its transactions."`
`resources/js/i18n/fr.json`: `"flash.season_closed": "La saison {season} est clôturée. Rouvrez-la pour modifier ses transactions."`
`resources/js/i18n/ar.json`: `"flash.season_closed": "الموسم {season} مغلق. أعد فتحه لتعديل معاملاته."`
Insert each next to the existing `flash.year_closed_*` keys; delete `flash.year_closed_add`, `flash.year_closed_edit`, `flash.year_closed_delete` if `grep -rn "year_closed_" app resources/js tests` shows no remaining use (keep `flash.year_closed_*` used by bulk delete, if any).
`lang/fr.json`: `"Season :season is closed. Reopen it to change its transactions.": "La saison :season est clôturée. Rouvrez-la pour modifier ses transactions."`
`lang/ar.json`: same key → `"الموسم :season مغلق. أعد فتحه لتعديل معاملاته."`

- [ ] **Step 7: Run tests**

Run: `php artisan test --filter="ClosedSeasonLockTest|TransactionBulkDeleteAndFiscalYearDeleteTest|Transaction|PlayerTransaction|TransactionImport|Equipment"` then `npm run i18n:check`
Expected: PASS; tests that asserted `flash.year_closed_edit`/`_delete`/`_add` are updated to `flash.season_closed` with `params.season`.

- [ ] **Step 8: Commit**

```bash
git add app/Exceptions/ClosedFiscalYearException.php app/Observers/TransactionObserver.php app/Http/Controllers/TransactionController.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json lang/ar.json lang/fr.json tests/Feature/ClosedSeasonLockTest.php
# plus any test file updated in Step 7, by path
git commit -m "feat(finance): lock a closed season on every path that writes money"
```

---

### Task 4: Closing a season, creating one, and their messages speak seasons

**Files:**
- Modify: `app/Services/FinanceService.php` (`closeYear` ~:95-128, `reopenYear` docblock ~:127)
- Modify: `app/Http/Controllers/FiscalYearController.php` (`store` :15-33, flash params :32, :54, :61, :82)
- Modify: `app/Http/Controllers/FinanceController.php` (`settings()` passes `seasonOptions`)
- Modify: `resources/js/i18n/{ar,fr,en}.json` (`flash.fiscal_year_*` placeholders keep `{year}`/`{next}` — values become labels; no key change)
- Test: `tests/Feature/FiscalSeasonCloseTest.php` (new)

**Interfaces — Consumes:** `FiscalPeriod::start/end/label/currentKey`. **Produces:** Settings prop `seasonOptions: list<{value:int,label:string}>` (Task 5 uses it).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalSeasonCloseTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_new_fiscal_year_gets_season_dates(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('finance.years.store'), ['year' => 2027, 'opening_balance' => 0])
            ->assertSessionHas('success', ['key' => 'flash.fiscal_year_created', 'params' => ['year' => '2026/2027']]);

        $fy = FiscalYear::where('year', 2027)->first();
        $this->assertSame('2026-09-01', $fy->start_date->toDateString());
        $this->assertSame('2027-08-31', $fy->end_date->toDateString());
    }

    #[Test]
    public function closing_carries_the_balance_into_the_next_season(): void
    {
        Transaction::create(['amount' => 1000, 'transaction_date' => '2026-10-01', 'transaction_type' => 'income', 'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid']);
        $fy = FiscalYear::where('year', 2027)->first();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('finance.years.close', $fy))
            ->assertSessionHas('success', ['key' => 'flash.fiscal_year_closed', 'params' => ['year' => '2026/2027', 'next' => '2027/2028']]);

        $next = FiscalYear::where('year', 2028)->first();
        $this->assertSame('2027-09-01', $next->start_date->toDateString());
        $this->assertSame('2028-08-31', $next->end_date->toDateString());
        $this->assertEquals(1000, (float) $next->opening_balance);
    }

    #[Test]
    public function closing_never_rewrites_an_already_closed_next_season(): void
    {
        $fy = FiscalYear::create(['year' => 2027, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31', 'status' => 'open', 'opening_balance' => 0]);
        FiscalYear::create(['year' => 2028, 'start_date' => '2027-09-01', 'end_date' => '2028-08-31', 'status' => 'closed', 'opening_balance' => 500, 'closing_balance' => 500]);

        $this->actingAs(User::factory()->admin()->create())->post(route('finance.years.close', $fy));

        $this->assertEquals(500, (float) FiscalYear::where('year', 2028)->value('opening_balance'));
    }

    #[Test]
    public function settings_offers_season_choices(): void
    {
        Carbon::setTestNow('2026-10-14');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('finance.settings'))
            ->assertInertia(fn ($page) => $page
                ->where('seasonOptions.0', ['value' => 2028, 'label' => '2027/2028'])
                ->where('seasonOptions.1', ['value' => 2027, 'label' => '2026/2027']));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FiscalSeasonCloseTest`
Expected: FAIL — start date `2027-01-01`.

- [ ] **Step 3: `FiscalYearController`**

Add `use App\Support\FiscalPeriod;`. In `store` replace the date lines:

```php
            'start_date' => FiscalPeriod::start($data['year'])->toDateString(),
            'end_date' => FiscalPeriod::end($data['year'])->toDateString(),
```

Flash params become labels:
- store: `'params' => ['year' => FiscalPeriod::label($data['year'])]`
- close: `'params' => ['year' => FiscalPeriod::label($fiscalYear->year), 'next' => FiscalPeriod::label($fiscalYear->year + 1)]`
- reopen and destroy: `'params' => ['year' => FiscalPeriod::label($fiscalYear->year)]` (destroy: capture the label before deleting).

Also update `fiscal_year_not_empty` params at its call site the same way (`grep -n "fiscal_year_not_empty" app`).

- [ ] **Step 4: `FinanceService::closeYear` / `reopenYear`**

Replace the carry-forward block:

```php
            // Carry the closing balance into the next season as its opening
            // balance — unless that season is already closed (its figures stand).
            $nextKey = $fiscalYear->year + 1;
            $next = FiscalYear::firstOrNew(['year' => $nextKey]);
            if (! $next->isClosed()) {
                $next->fill([
                    'start_date' => FiscalPeriod::start($nextKey)->toDateString(),
                    'end_date' => FiscalPeriod::end($nextKey)->toDateString(),
                    'status' => $next->exists ? $next->status : 'open',
                    'opening_balance' => $closing,
                ])->save();
            }
```

Replace the `reopenYear` docblock with: `/** Reopen a closed season. The next season keeps the opening balance it was given at close. */`. Add `use App\Support\FiscalPeriod;`.

- [ ] **Step 5: `FinanceController::settings` season options**

Add to the Inertia props:

```php
            'seasonOptions' => collect(range(FiscalPeriod::currentKey() + 1, FiscalPeriod::currentKey() - 5))
                ->map(fn (int $key) => ['value' => $key, 'label' => FiscalPeriod::label($key)])
                ->values(),
```

- [ ] **Step 6: Run tests**

Run: `php artisan test --filter="FiscalSeasonCloseTest|TransactionBulkDeleteAndFiscalYearDeleteTest|FinanceReset"`
Expected: PASS (update any assertion on `flash.fiscal_year_*` params from integers to labels).

- [ ] **Step 7: Commit**

```bash
git add app/Services/FinanceService.php app/Http/Controllers/FiscalYearController.php app/Http/Controllers/FinanceController.php tests/Feature/FiscalSeasonCloseTest.php
git commit -m "feat(finance): create and close seasons, carrying balances season to season"
```

---

### Task 5: Finance page and Settings show seasons; trend runs Sep → Aug

**Files:**
- Modify: `app/Http/Controllers/FinanceController.php` (`index` :24-55: `season_label` in each year row, default selected year; `yearDetail` :128-142: month order, `season_label`)
- Modify: `resources/js/Pages/Finance/Index.vue` (tabs/table show `season_label`; month labels from `detail.monthly[].month`)
- Modify: `resources/js/Pages/Finance/Settings.vue` (years table + budget picker show `season_label`; add-year input → select of `seasonOptions`)
- Test: `tests/Feature/FinanceSeasonPageTest.php` (new)

**Interfaces — Consumes:** `seasonOptions` (Task 4), `FiscalYear.season_label` (Task 1), `FiscalPeriod::months/currentKey/label`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinanceSeasonPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_finance_page_defaults_to_the_current_season_and_orders_months_from_september(): void
    {
        Carbon::setTestNow('2026-10-14');
        Transaction::create(['amount' => 700, 'transaction_date' => '2026-10-06', 'transaction_type' => 'income', 'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('finance.index'))
            ->assertInertia(fn ($page) => $page
                ->where('selectedYear', 2027)
                ->where('years.0.season_label', '2026/2027')
                ->where('detail.season_label', '2026/2027')
                ->where('detail.monthly.0.month', 9)
                ->where('detail.monthly.1.month', 10)
                ->where('detail.monthly.1.income', 700)
                ->where('detail.monthly.11.month', 8));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FinanceSeasonPageTest`
Expected: FAIL — `years.0.season_label` missing / `monthly.0.month` is 1.

- [ ] **Step 3: `FinanceController`**

In `index`, add `'season_label' => FiscalPeriod::label($fy->year),` to each mapped year row, and change the default:

```php
        $selectedYear = (int) ($request->query('year') ?: (
            $years->firstWhere('year', FiscalPeriod::currentKey())['year'] ?? ($years->first()['year'] ?? FiscalPeriod::currentKey())
        ));
```

In `yearDetail`, replace `for ($m = 1; $m <= 12; $m++) {` with `foreach (FiscalPeriod::months() as $m) {` (body unchanged), and add `'season_label' => FiscalPeriod::label($fy->year),` to the returned array. Add `use App\Support\FiscalPeriod;`.

- [ ] **Step 4: `Finance/Index.vue`**

- Year tab text (line ~96) and all-years table cell (~228): `{{ y.season_label }}`.
- Replace `monthLabels`:

```js
const monthFormatter = computed(() => new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar' : (locale.value === 'fr' ? 'fr' : 'en'), { month: 'short' }));
const monthLabels = computed(() => (props.detail?.monthly || []).map((m) => monthFormatter.value.format(new Date(2000, m.month - 1, 1))));
```

- Anywhere the selected year is printed as a heading, use `detail.season_label`.

- [ ] **Step 5: `Finance/Settings.vue`**

- Props: add `seasonOptions: { type: Array, default: () => [] }`.
- Years table cell (~114) and budget `<option>` (~156): `{{ y.season_label }}`.
- `yearForm` default: `year: props.seasonOptions[1]?.value ?? null` (index 1 = current season; index 0 is next season).
- Replace the number input (~143) with:

```html
<select v-model="yearForm.year" class="w-36 rounded-lg border-slate-200 bg-white py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800">
    <option v-for="s in seasonOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
</select>
```

- `deleteYear` confirm param: `{ year: y.season_label }`.

- [ ] **Step 6: Run tests and build**

Run: `php artisan test --filter="FinanceSeasonPageTest|FiscalSeasonCloseTest|Finance"` then `npm run build`
Expected: PASS; build succeeds.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/FinanceController.php resources/js/Pages/Finance/Index.vue resources/js/Pages/Finance/Settings.vue tests/Feature/FinanceSeasonPageTest.php
git commit -m "feat(finance): Finance page and settings speak seasons, trend runs September to August"
```

---

### Task 6: PDF summary by season with category names

**Files:**
- Modify: `app/Http/Controllers/ReportController.php::financialSummary` (:84-110)
- Modify: `resources/views/pdf/financial-summary.blade.php:22,45`
- Test: `tests/Feature/ReportPdfTest.php` (add a test)

- [ ] **Step 1: Write the failing test** (append to `ReportPdfTest`; reuse that class's existing admin/user helper and PDF-capture approach — read the top of the file first and mirror how other tests inspect the rendered HTML, e.g. a fake PDF renderer or `view()` assertion)

```php
    #[Test]
    public function the_financial_summary_covers_one_season_and_names_categories(): void
    {
        Carbon::setTestNow('2026-10-14');
        $category = FinanceCategory::create(['type' => 'expense', 'name' => 'مصاريف اشغال المقر', 'is_active' => true]);
        Transaction::create(['amount' => 770, 'transaction_date' => '2026-10-11', 'transaction_type' => 'expense', 'category' => 'msaryf_ashghal_almkr', 'finance_category_id' => $category->id, 'payment_method' => 'cash', 'status' => 'Paid']);
        Transaction::create(['amount' => 999, 'transaction_date' => '2026-03-01', 'transaction_type' => 'expense', 'category' => 'other', 'payment_method' => 'cash', 'status' => 'Paid']);

        $html = $this->renderFinancialSummary(['year' => 2027]); // the helper this file uses to get the HTML

        $this->assertStringContainsString('2026/2027', $html);
        $this->assertStringContainsString('مصاريف اشغال المقر', $html);
        $this->assertStringNotContainsString('msaryf_ashghal_almkr', $html);
        $this->assertStringNotContainsString('999', $html);
    }
```

If `ReportPdfTest` has no HTML-capturing helper, add a private `renderFinancialSummary(array $query): string` that binds a fake for the PDF service the controller uses (`$this->pdf`) recording the `$html` argument — read `ReportController::__construct` for the class name.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=the_financial_summary_covers_one_season`
Expected: FAIL — slug present / 999 present.

- [ ] **Step 3: Implement in `ReportController::financialSummary`**

```php
        $year = (int) ($request->query('year') ?: FiscalPeriod::currentKey());

        $base = Transaction::query()->where('archived', false)->where('fiscal_year', $year);

        $totalIncome = (float) (clone $base)->where('transaction_type', 'income')->sum('amount');
        $totalExpense = (float) (clone $base)->where('transaction_type', 'expense')->sum('amount');
        $totalDonations = (float) (clone $base)->where('category', 'donation')->sum('amount');

        $names = FinanceCategory::query()->get()->keyBy('id');
        $byCategory = DB::table('transactions')
            ->where('archived', false)
            ->where('fiscal_year', $year)
            ->groupBy('transaction_type', 'finance_category_id')
            ->selectRaw('transaction_type, finance_category_id, SUM(amount) as total')
            ->orderBy('transaction_type')
            ->get()
            ->each(function ($row) use ($names) {
                $row->category = $names->get($row->finance_category_id)?->localized_name ?? __('Other');
            });
```

Pass `'season' => FiscalPeriod::label($year)` to the view; in the blade, line 22: `{{ __('Financial Summary') }} — {{ $season }}`. Line 45 stays `{{ $row->category }}` (now a name). Add `use App\Models\FinanceCategory; use App\Support\FiscalPeriod;`; drop `Carbon` import if unused. Ensure `"Other"` exists in `lang/ar.json` / `lang/fr.json` (`grep -n '"Other"' lang/*.json`; add `"Other": "أخرى"` / `"Other": "Autre"` if missing).

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=ReportPdfTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ReportController.php resources/views/pdf/financial-summary.blade.php tests/Feature/ReportPdfTest.php
# plus lang/ar.json lang/fr.json if edited
git commit -m "fix(reports): financial summary covers one season and prints category names"
```

---

### Task 7: Dashboard "This season", season renewals and due dates

**Files:**
- Modify: `app/Services/Dashboard/DashboardFilters.php` (`RANGES`, `fromRequest`, `resolveWindow`)
- Modify: `resources/js/Components/Dashboard/DashboardFilterBar.vue:25`
- Modify: `resources/js/i18n/{ar,fr,en}.json` (`dashboard.range_season`; remove `dashboard.range_year` if unused)
- Modify: `app/Services/Dashboard/MemberStats.php::renewalRate` (:230)
- Modify: `app/Services/Dashboard/OverviewStats.php::effectiveDueDate` (:141), `app/Services/Dashboard/HeroStats.php::effectiveDueDate` (:215)
- Test: `tests/Feature/Dashboard/DashboardFiltersTest.php` (replace the `year` assertions :60-64), `tests/Feature/Dashboard/SeasonDashboardTest.php` (new)

- [ ] **Step 1: Write the failing tests**

In `DashboardFiltersTest`, replace the `$year = $this->filters(['range' => 'year']);` block with:

```php
        $season = $this->filters(['range' => 'season']);
        $this->assertSame('2025-09-01', $season->from->toDateString());
        $this->assertSame('2026-08-31', $season->to->toDateString());
        $this->assertSame('2024-09-01', $season->prevFrom->toDateString());
        $this->assertSame('2025-08-31', $season->prevTo->toDateString());

        // Old bookmarks with range=year open the season view.
        $this->assertSame('season', $this->filters(['range' => 'year'])->range);
```

(This test's frozen "now" is in 2026 Q2 — check the `setTestNow` at the top of the file; if it is not between 2025-09-01 and 2026-08-31, adjust the expected dates to the season containing it.)

New `tests/Feature/Dashboard/SeasonDashboardTest.php`:

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Support\FiscalPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeasonDashboardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_subscription_without_due_date_is_due_at_the_end_of_its_season(): void
    {
        $sql = (new \ReflectionMethod(\App\Services\Dashboard\OverviewStats::class, 'effectiveDueDate'))->invoke(app(\App\Services\Dashboard\OverviewStats::class));
        $this->assertStringContainsString("'".FiscalPeriod::endSuffix()."'", $sql);
        $this->assertStringContainsString("'-08-31'", $sql);

        $sql = (new \ReflectionMethod(\App\Services\Dashboard\HeroStats::class, 'effectiveDueDate'))->invoke(app(\App\Services\Dashboard\HeroStats::class));
        $this->assertStringContainsString("'-08-31'", $sql);
    }
}
```

For renewal rate, add to the existing `tests/Feature/Dashboard/MemberStatsTest.php` (or the file that already tests `renewal_rate` — `grep -rln "renewal" tests`) a case: freeze now at `2026-10-14`, players subscribed with `player_subscriptions.year` 2026 (last season) and 2027 (this season), range `season` → rate = returned/previous computed from 2026 → 2027. Mirror that file's existing fixture helpers.

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter="DashboardFiltersTest|SeasonDashboardTest|renewal"`
Expected: FAIL.

- [ ] **Step 3: `DashboardFilters`**

```php
    public const RANGES = ['month', 'last_month', 'quarter', 'season', 'last12', 'all'];
```

In `fromRequest`, before the `in_array` check: `if ($range === 'year') { $range = 'season'; } // old links`.

In `resolveWindow` replace the `'year'` arm with:

```php
            'season' => [
                $from = Season::current()->start(),
                Season::current()->end(),
                $from->subYear(),
                $from->subDay()->endOfDay(),
            ],
```

Add `use App\Support\Season;`.

- [ ] **Step 4: Front end + i18n**

`DashboardFilterBar.vue:25`: `const RANGES = ['month', 'last_month', 'quarter', 'season', 'last12', 'all'];`
i18n: `"dashboard.range_season"`: en "This season", fr "Cette saison", ar "هذا الموسم". Remove `dashboard.range_year` from all three if `grep -rn "range_year" resources/js app` finds nothing else.

- [ ] **Step 5: Renewal rate and due dates**

`MemberStats::renewalRate`: replace `$year = $filters->anchor()->year;` with `$year = FiscalPeriod::keyForDate($filters->anchor());`; update the docblock: "How many of last season's subscribed members subscribed again this season."

`OverviewStats::effectiveDueDate` and `HeroStats::effectiveDueDate`:

```php
    private function effectiveDueDate(): string
    {
        // Without a due date a subscription is due when its season ends.
        return "COALESCE(player_subscriptions.due_date, (player_subscriptions.year || '".FiscalPeriod::endSuffix()."'))";
    }
```

Add `use App\Support\FiscalPeriod;` to the three files.

- [ ] **Step 6: Run dashboard suite**

Run: `php artisan test --filter=Dashboard` then `npm run i18n:check` and `npm run build`
Expected: PASS. Fix any dashboard test that asserted a Dec-31 overdue boundary by moving its dates to the season end (state it in the commit body).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Dashboard/DashboardFilters.php app/Services/Dashboard/MemberStats.php app/Services/Dashboard/OverviewStats.php app/Services/Dashboard/HeroStats.php resources/js/Components/Dashboard/DashboardFilterBar.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Dashboard/DashboardFiltersTest.php tests/Feature/Dashboard/SeasonDashboardTest.php
# plus the renewal test file you edited
git commit -m "feat(dashboard): 'This season' replaces 'This year'; renewals and due dates follow the season"
```

---

### Task 8: Re-bucket existing data into seasons

**Files:**
- Create: `app/Services/Finance/SeasonRebucketer.php`
- Create: `database/migrations/2026_10_14_000001_rebucket_fiscal_years_by_season.php`
- Test: `tests/Feature/SeasonRebucketerTest.php` (new)

**Interfaces — Consumes:** `FiscalPeriod`, `FinanceService::recomputeYear`. **Produces:** `SeasonRebucketer::run(): array{moved:int, years:int}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Services\Finance\SeasonRebucketer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeasonRebucketerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function calendar_booked_rows_move_to_the_season_of_their_date(): void
    {
        // Simulate legacy calendar data, bypassing the observer.
        $cal2026 = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open', 'opening_balance' => 0]);
        $cal2027 = FiscalYear::create(['year' => 2027, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => 'open', 'opening_balance' => 0]);
        $row = fn (string $date, string $type, int $amount, FiscalYear $fy) => DB::table('transactions')->insertGetId([
            'amount' => $amount, 'transaction_date' => $date, 'transaction_type' => $type, 'category' => 'donation',
            'payment_method' => 'cash', 'status' => 'Paid', 'archived' => false,
            'fiscal_year' => $fy->year, 'fiscal_year_id' => $fy->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $donation = $row('2026-10-06', 'income', 1000, $cal2026);   // calendar 2026 → season 2027
        $payment = $row('2026-10-06', 'income', 2000, $cal2027);    // subscription-year 2027 → stays 2027
        $expense = $row('2026-10-09', 'expense', 12000, $cal2026);  // → 2027
        $spring = $row('2026-03-01', 'income', 500, $cal2026);      // → 2026

        $result = app(SeasonRebucketer::class)->run();

        $this->assertSame(2, $result['moved']);
        $this->assertSame(2027, Transaction::find($donation)->fiscal_year);
        $this->assertSame($cal2027->id, Transaction::find($expense)->fiscal_year_id);
        $this->assertSame(2026, Transaction::find($spring)->fiscal_year);

        $s2027 = $cal2027->fresh();
        $this->assertSame('2026-09-01', $s2027->start_date->toDateString());
        $this->assertSame('2027-08-31', $s2027->end_date->toDateString());
        $this->assertEquals(3000, (float) $s2027->total_income);
        $this->assertEquals(12000, (float) $s2027->total_expense);
        $this->assertEquals(500, (float) $cal2026->fresh()->total_income);
        $this->assertSame('2025-09-01', $cal2026->fresh()->start_date->toDateString());
    }

    #[Test]
    public function running_twice_changes_nothing_the_second_time(): void
    {
        Transaction::create(['amount' => 100, 'transaction_date' => '2026-10-01', 'transaction_type' => 'income', 'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid']);

        app(SeasonRebucketer::class)->run();

        $this->assertSame(0, app(SeasonRebucketer::class)->run()['moved']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=SeasonRebucketerTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `app/Services/Finance/SeasonRebucketer.php`**

```php
<?php

namespace App\Services\Finance;

use App\Models\FiscalYear;
use App\Services\FinanceService;
use App\Support\FiscalPeriod;
use Illuminate\Support\Facades\DB;

/**
 * One-time move from calendar fiscal years to seasons: every transaction is
 * re-keyed to the season of its date and every fiscal-year row gets season
 * dates and fresh totals. Writes through the query builder on purpose — the
 * observer would refuse rows in closed years, and this is bookkeeping, not
 * a change to anyone's money. Safe to run again: a second run moves nothing.
 */
class SeasonRebucketer
{
    public function __construct(private FinanceService $finance) {}

    /** @return array{moved:int, years:int} */
    public function run(): array
    {
        return DB::transaction(function () {
            $moved = 0;
            $rowFor = [];

            DB::table('transactions')->whereNotNull('transaction_date')->orderBy('id')
                ->select('id', 'transaction_date', 'fiscal_year', 'fiscal_year_id')
                ->chunkById(500, function ($chunk) use (&$moved, &$rowFor) {
                    foreach ($chunk as $tx) {
                        $key = FiscalPeriod::keyForDate($tx->transaction_date);
                        $rowFor[$key] ??= $this->yearRow($key)->id;

                        if ((int) $tx->fiscal_year === $key && (int) $tx->fiscal_year_id === $rowFor[$key]) {
                            continue;
                        }

                        DB::table('transactions')->where('id', $tx->id)
                            ->update(['fiscal_year' => $key, 'fiscal_year_id' => $rowFor[$key]]);
                        $moved++;
                    }
                });

            $years = FiscalYear::all();
            foreach ($years as $fy) {
                $fy->forceFill([
                    'start_date' => FiscalPeriod::start($fy->year)->toDateString(),
                    'end_date' => FiscalPeriod::end($fy->year)->toDateString(),
                ])->save();
                $this->finance->recomputeYear($fy);
            }

            return ['moved' => $moved, 'years' => $years->count()];
        });
    }

    private function yearRow(int $key): FiscalYear
    {
        return FiscalYear::firstOrCreate(['year' => $key], [
            'start_date' => FiscalPeriod::start($key)->toDateString(),
            'end_date' => FiscalPeriod::end($key)->toDateString(),
            'status' => 'open',
            'opening_balance' => 0,
        ]);
    }
}
```

- [ ] **Step 4: Create the migration**

```php
<?php

use App\Services\Finance\SeasonRebucketer;
use Illuminate\Database\Migrations\Migration;

/**
 * Fiscal years become seasons (spec 2026-10-14, P1). Re-keys existing
 * transactions by their date and gives fiscal-year rows season dates.
 * Not reversible: the old calendar keys are not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(SeasonRebucketer::class)->run();
    }

    public function down(): void
    {
        // Intentionally empty — calendar keys cannot be reconstructed safely.
    }
};
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter=SeasonRebucketerTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Finance/SeasonRebucketer.php database/migrations/2026_10_14_000001_rebucket_fiscal_years_by_season.php tests/Feature/SeasonRebucketerTest.php
git commit -m "feat(finance): migrate existing transactions and fiscal years to seasons"
```

---

### Task 9: Full verification and live-copy dry run

**Files:** none new (fixes only where a check fails).

- [ ] **Step 1: Full suite**

Run: `php artisan test`
Expected: all green. Any failure: fix the code if it contradicts the spec, or the test if it encodes calendar-year behaviour the spec removed (note which in the commit body).

- [ ] **Step 2: Static checks**

Run: `vendor/bin/pint --dirty` then `npm run i18n:check` then `npm run build`
Expected: Pint fixes style only; i18n check passes; build succeeds.

- [ ] **Step 3: Remaining calendar assumptions**

Run: `grep -rn "'-01-01'\|'-12-31'\|startOfYear()\|endOfYear()\|whereYear(\|now()->year" app --include=*.php`
Expected: no hit that books or reads a FISCAL year (hits about birthdates, attendance or calendar charts are fine — list them in the report).

- [ ] **Step 4: Dry run on the restored live copy (owner go required)**

Ask the owner before touching `database/database.sqlite`. With the go:

```bash
cp database/database.sqlite database/database.pre-season-year-20261014.sqlite
php artisan migrate --force
```

Then verify with a read-only query (PDO, `PRAGMA query_only=1`): all 36 transactions have `fiscal_year = 2027`; `fiscal_years` 2027 shows income 50 000, expense 14 390, dates 2026-09-01 → 2027-08-31; row 2026 shows zero totals, dates 2025-09-01 → 2026-08-31; account balances unchanged (football treasury 96 610, Senior 83 000).

- [ ] **Step 5: Commit fixes (if any) and report**

```bash
git add <each fixed file by path>
git commit -m "test(finance): season-year suite fixes"
```

Report: suite count, i18n check, build, the live-copy numbers, and any calendar hits left on purpose.
