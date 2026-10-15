# Finance P3 — Transactions Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Transactions page follows the Players page pattern (headline strip, foldable statistics, visible filters, mobile cards), opens on the current season, and never shows a raw slug, an English seeded account name, an ISO date or an English pagination label anywhere. One finance-categories manager serves Transactions and Finance Settings. The i18n check also catches missing template keys.

**Architecture:** Backend first. Two small helpers come first: `App\Support\FinanceAccountLabel`, the PHP twin of `useFinanceAccountLabel.js`, and `App\Support\LegacyFinanceCategory`, which maps slugs to the seeded categories. These feed the export, the dashboard and the observer. `TransactionController@index` gets the season default, the visible filter set, page size, the strip (`ModuleStats::transactions`) and three statistics cards, each ignoring its own filter. `FinanceCategoryController` enforces the category rules. The Vue work comes after that. Shared pieces come first (ConfirmModal, Pagination, StatTile/StatCard/StatBars, date and status helpers), then CategoryManager, the Index page, and Show/Create/Edit.

**Tech Stack:** Laravel 13, PHPUnit (`#[Test]`, `RefreshDatabase`), Inertia 2 + Vue 3 `<script setup>`, Tailwind, vue-i18n flat dotted keys.

Spec: `docs/superpowers/specs/2026-10-14-finance-simplify-design.md` §P3, plus the P2 follow-ups (dashboard accounts, ConfirmModal variant and blank fade-out, duplicate chip on the Caisses page, blank caisse select when editing a Fonds Initial entry).

## Global Constraints

- Work in worktree `D:\irnb-season`, branch `feat/transactions-redesign`. Shell: `cd /d/irnb-season && …`. Stage explicit file paths only. Never commit `.claude/`, `.superpowers/`, `public/build` or `*.zip`. Every commit message ends with a blank line and `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Season = `App\Support\FiscalPeriod`.** Keys are the season end year (2026/27 → `2027`). Labels come from `FiscalPeriod::label()`. Never compute calendar years.
- **Resolved decisions (stated here so no task re-decides them):**
  1. The list opens on the **current season**. `fiscal_year=all` shows every season; `fiscal_year=<key>` shows one. A date range with no explicit season is not narrowed to the current one: the dates decide.
  2. The **status column is dropped** from the list (shown on Show). The `status` URL filter keeps working and shows as a removable chip. It gets no menu.
  3. The **`category` URL filter (stored slug) is removed**. No screen links with it, and it had no control. Search now matches finance-category names in every language instead of the slug.
  4. **Strip:** income, expenses and net follow every active filter. **Debts stay club-wide**, and the label says so ("Members' debts (all)").
  5. **Statistics cards:** "Income by category" and "Expenses by category" rank **amounts**. "Entries by caisse" ranks **counts**. Each card ignores its own filter, like Players.
  6. **Slug → category:** the seeded category is matched by `code` (SUB, DON, ARR, MEM, EQP, …), then a category named like the slug (the legacy twin), then the single "Other income" (OIN) or "Other expense" (OEX). `debt_payment` → ARR "Arrears / Debts". Existing duplicate categories are **not** merged (owner decision, see Task 13).
  7. **Category names:** one field per language. Older rows hold their only name in `name` with `name_ar` null. The manager shows Arabic `name` text in the Arabic field. Latin text goes in the English field for built-in rows and in the French field for club-typed rows. The server keeps a base `name`: the sent `name`, else English, else French, else Arabic. A language left empty therefore falls back to that base, and an Arabic-only category shows Arabic in fr/en too. Built-in (`is_system`) rows keep their base `name` and `type`. They can be renamed per language, never deleted.
  8. Server-rendered labels (export, dashboard accounts and transfers, statistics cards) use `FinanceAccountLabel` (PHP). Page-rendered labels use `useFinanceAccountLabel.js`. Both must follow the same rules: Fonds Initial → `opening_fund`, coffre → `caisses.coffre_of`, legacy "X Cash Register" → its category's name, the seeded club "Cash" → `caisses.club_cash`, a branch caisse → "name — branch".
- Permissions are route-name derived (`config/permissions.php`). **No new routes.** UI gates: Add/Import/Template = `can('transactions','add')`; edit = `can('transactions','edit')`; delete and bulk = `can('transactions','delete')`; "Finance categories" menu item = `can('finance','edit')`; inside the manager, add = `finance/add`, edit = `finance/edit`, delete = `finance/delete`.
- Flash format: `'flash.x'` or `['key' => 'flash.x', 'params' => [...]]`. UI keys are added with `node scripts/i18n-add.mjs .superpowers/i18n/<file>.json`, where the file is `{ "key": { "ar": "…", "fr": "…", "en": "…" } }` built from each task's table. The script keeps the catalogs sorted; a one-time reorder of ~51 out-of-order keys in the first diff is expected. Validation messages thrown with `__()` go in `lang/ar.json` and `lang/fr.json`.
- RTL-safe classes only (`ms-/me-/ps-/pe-`, `start/end`, `text-start/end`). Directional icons get `rtl:rotate-180` (or `rotate-180 rtl:rotate-0` for "next"). Use `ConfirmModal`, never `window.confirm`. Offline-only (no CDN). Dark-mode variants on every new colour.
- Tests: never use the Player factory; create players with `Player::create([...])` as in `tests/Feature/TransactionSeasonYearTest.php` and `tests/Feature/Dashboard/ModuleStatsTest.php`. Use `User::factory()->admin()->create()` and `['preferred_lng' => 'ar'|'fr'|'en']` to pick the request locale. Inertia page tests need `public/build`; it exists in the worktree, run `npm run build` if missing. If tests fail en masse with "Cached config detected", run `php artisan config:clear`.

## File map

| File | Change |
|---|---|
| `app/Support/FinanceAccountLabel.php` (new) | PHP account labels |
| `app/Support/LegacyFinanceCategory.php` (new) | slug → existing finance category |
| `app/Models/Transaction.php`, `app/Models/FinanceTransfer.php` | `serializeDate`; `transaction_date` → `datetime:Y-m-d` |
| `app/Observers/TransactionObserver.php` | uses `LegacyFinanceCategory`, never creates from a slug |
| `app/Support/TransactionTitle.php` | fallback = type label, never the slug |
| `app/Services/Dashboard/OverviewStats.php`, `FinanceStats.php` | category names, account labels, opening fund hidden |
| `app/Services/Dashboard/ModuleStats.php` | `transactions(Builder)` |
| `app/Http/Controllers/TransactionController.php` | index/export/filters/formOptions |
| `app/Http/Controllers/FinanceCategoryController.php` | rules (is_system, count, base name, type lock) |
| `lang/ar/pagination.php`, `lang/fr/pagination.php` (new); `lang/ar.json`, `lang/fr.json` | translations |
| `scripts/i18n-check.mjs` | literal template-key scan |
| `resources/js/Composables/useFormatDate.js` (new), `useFinanceAccountLabel.js` | dates; club cash label |
| `resources/js/lib/statusLabels.js` | `paymentStatusColor` |
| `resources/js/Components/ConfirmModal.vue`, `Pagination.vue`, `StatBars.vue`, `StatCard.vue`, `Dashboard/StatTile.vue` | shared UI |
| `resources/js/Components/CategoryManager.vue` | rewrite |
| `resources/js/Pages/Transactions/Index.vue` | rewrite |
| `resources/js/Pages/Transactions/Show.vue`, `Create.vue`, `Edit.vue`, `Partials/TransactionForm.vue` | arrows, dates, colours, default caisse, locked account |
| `resources/js/Pages/Finance/Settings.vue`, `Registers.vue`, `Index.vue`; `Dashboard/Partials/FinanceTab.vue`; `Players/Show.vue`; `Subscriptions/Show.vue` | follow-ups |
| `resources/js/i18n/{ar,fr,en}.json` | keys |
| Tests (new): `FinanceAccountLabelTest`, `TransactionSerializationTest`, `TransactionLabelsTest`, `TransactionCategoryMappingTest`, `FinanceCategoryRulesTest`, `TransactionsIndexTest`, `TransactionFormOptionsTest` | |
| Tests (updated): `TransactionTitleTest`, `FinanceCategoryTranslationTest`, `TransactionFinanceCategoryTest`, `ListMultiSelectFiltersTest`, `Dashboard/FinanceStatsTest`, `Dashboard/ActivityFeedLabelTest`, `Dashboard/ModuleStatsTest` | |

---

### Task 1: One format for dates and account names

**Files:**
- Create: `app/Support/FinanceAccountLabel.php`, `resources/js/Composables/useFormatDate.js`
- Modify: `app/Models/Transaction.php` (casts at :74-82), `app/Models/FinanceTransfer.php`, `resources/js/Composables/useFinanceAccountLabel.js`, i18n catalogs
- Test: `tests/Feature/FinanceAccountLabelTest.php`, `tests/Feature/TransactionSerializationTest.php` (new)

**Interfaces — Produces:**
- `FinanceAccountLabel::for(?FinanceAccount): string`. It reads the `branch` and `category` relations, so callers eager-load them (`Transaction::FINANCE_ACCOUNT_LABEL` already does for a transaction's account). It returns `''` for null.
- `useFormatDate()` → `{ formatDate, formatDateTime }`.
- `Transaction` and `FinanceTransfer` JSON: dates are `Y-m-d`, timestamps `Y-m-d H:i`. The app timezone is UTC, so timestamps read in UTC; no screen shows a transaction timestamp today.

- [ ] **Step 1: i18n key**

| key | en | fr | ar |
|---|---|---|---|
| `caisses.club_cash` | Club cash | Caisse du club | صندوق النادي |

- [ ] **Step 2: Failing tests**

`tests/Feature/FinanceAccountLabelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FinanceAccount;
use App\Support\FinanceAccountLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinanceAccountLabelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function accounts_are_named_like_the_caisses_page(): void
    {
        app()->setLocale('fr');
        $branch = Branch::create(['name' => 'Football']);
        $senior = Category::create(['name' => 'Senior', 'name_fr' => 'Séniors']);
        $legacy = FinanceAccount::create([
            'branch_id' => $branch->id, 'category_id' => $senior->id, 'parent_account_id' => $branch->treasury->id,
            'name' => 'Senior Cash Register', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ]);
        $named = FinanceAccount::create([
            'branch_id' => $branch->id, 'parent_account_id' => $branch->treasury->id,
            'name' => 'Caisse cadets', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ]);
        $bank = FinanceAccount::create(['name' => 'BNA', 'type' => 'bank', 'is_active' => true, 'currency' => 'DZD']);
        $fund = FinanceAccount::where('is_opening_fund', true)->first()
            ?? FinanceAccount::create(['name' => 'Opening Fund', 'type' => 'cash', 'is_opening_fund' => true, 'is_active' => true, 'currency' => 'DZD']);
        // Seeded by the create_finance_tables migration.
        $cash = FinanceAccount::whereNull('branch_id')->where('name', 'Cash')->firstOrFail();

        $label = fn (FinanceAccount $a) => FinanceAccountLabel::for($a->fresh(['branch', 'category']));

        $this->assertSame('Coffre — Football', $label($branch->treasury));
        $this->assertSame('Séniors — Football', $label($legacy));
        $this->assertSame('Caisse cadets — Football', $label($named));
        $this->assertSame('BNA', $label($bank));
        $this->assertSame('Fonds Initial', $label($fund));
        $this->assertSame('Caisse du club', $label($cash));
        $this->assertSame('', FinanceAccountLabel::for(null));
    }
}
```

`tests/Feature/TransactionSerializationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceTransfer;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionSerializationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function transactions_and_money_moves_reach_the_screens_as_plain_dates(): void
    {
        Carbon::setTestNow('2026-10-15 09:41:27');

        $tx = Transaction::create([
            'amount' => 10, 'transaction_type' => 'income', 'category' => 'donation', 'status' => 'Paid',
            'transaction_date' => '2026-10-01 14:30:00',
        ])->fresh()->toArray();

        $this->assertSame('2026-10-01', $tx['transaction_date']);
        $this->assertSame('2026-10-15 09:41', $tx['created_at']);

        $a = FinanceAccount::create(['name' => 'A', 'type' => 'cash', 'opening_balance' => 100, 'is_active' => true, 'currency' => 'DZD']);
        $b = FinanceAccount::create(['name' => 'B', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD']);
        $move = FinanceTransfer::create([
            'from_account_id' => $a->id, 'to_account_id' => $b->id, 'amount' => 5, 'transfer_date' => '2026-10-02',
        ])->fresh()->toArray();

        $this->assertSame('2026-10-02', $move['transfer_date']);
        $this->assertSame('2026-10-15 09:41', $move['created_at']);
    }
}
```

- [ ] **Step 3: Run to verify failure**

Run: `php artisan test --filter="FinanceAccountLabelTest|TransactionSerializationTest"`
Expected: FAIL. The `FinanceAccountLabel` class is missing, `transaction_date` is ISO, and `created_at` is ISO.

- [ ] **Step 4: Implement**

`app/Support/FinanceAccountLabel.php`:

```php
<?php

namespace App\Support;

use App\Models\FinanceAccount;

/**
 * One name for a money account wherever the server writes it (export,
 * dashboard, statistics cards) — the PHP twin of
 * resources/js/Composables/useFinanceAccountLabel.js; keep both in step.
 *
 * Fonds Initial and coffres are named by the app, never by their stored
 * English name ("Opening Fund", "Football Treasury"). Caisses created
 * automatically before 2026-10 ("Senior Cash Register") show their
 * category's name; the seeded club-level "Cash" shows the club-cash label.
 * Reads the branch and category relations: eager-load them.
 */
final class FinanceAccountLabel
{
    /** Name the create_finance_tables migration gave the first club-level cash account. */
    private const SEEDED_CLUB_CASH = 'Cash';

    public static function for(?FinanceAccount $account): string
    {
        if (! $account) {
            return '';
        }

        if ($account->is_opening_fund) {
            return UiLang::get('opening_fund');
        }

        if ($account->is_treasury) {
            return strtr(UiLang::get('caisses.coffre_of'), ['{branch}' => self::branch($account)]);
        }

        $name = self::caisseName($account);

        return $account->branch ? $name.' — '.self::branch($account) : $name;
    }

    private static function branch(FinanceAccount $account): string
    {
        return $account->branch?->localized_name ?: UiLang::get('caisses.club_level');
    }

    private static function caisseName(FinanceAccount $account): string
    {
        $name = (string) $account->name;

        if ($account->category && str_ends_with($name, ' Cash Register')) {
            return $account->category->localized_name;
        }

        if (! $account->branch && $name === self::SEEDED_CLUB_CASH) {
            return UiLang::get('caisses.club_cash');
        }

        return $name;
    }
}
```

`app/Models/Transaction.php`: add `use DateTimeInterface;`. In `casts()`, `'transaction_date' => 'datetime'` becomes `'transaction_date' => 'datetime:Y-m-d'`. Add below `casts()`:

```php
    /** Timestamps reach the screens as "Y-m-d H:i", never as ISO strings with a timezone suffix. */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i');
    }
```

`app/Models/FinanceTransfer.php`: add the same `use DateTimeInterface;` and the same `serializeDate` method. `transfer_date` already has `date:Y-m-d`.

`resources/js/Composables/useFormatDate.js`:

```js
import { usePage } from '@inertiajs/vue3';

const INTL_LOCALE = { ar: 'ar-DZ', fr: 'fr-FR', en: 'en-GB' };

/**
 * Dates as the app's language writes them ("1 oct. 2026", "1 أكتوبر 2026").
 * The server sends dates as "Y-m-d" and timestamps as "Y-m-d H:i"; both are
 * read as local time, so a date never slips a day across a timezone.
 */
export function useFormatDate() {
    const page = usePage();
    const locale = INTL_LOCALE[page.props.locale] ?? 'fr-FR';

    const parse = (value) => {
        if (!value) return null;
        const text = String(value);
        const date = /^\d{4}-\d{2}-\d{2}$/.test(text) ? new Date(`${text}T00:00:00`) : new Date(text.replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? null : date;
    };

    const formatDate = (value) => {
        const date = parse(value);
        return date ? date.toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
    };

    const formatDateTime = (value) => {
        const date = parse(value);
        return date ? date.toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
    };

    return { formatDate, formatDateTime };
}
```

`resources/js/Composables/useFinanceAccountLabel.js`: in `caisseName`, after the `isLegacyAutoName` branch, add:

```js
        // The club-level cash account the app seeded, under its English seed name.
        if (!account.branch && !account.is_treasury && account.name === 'Cash') {
            return t('caisses.club_cash');
        }
```

Add one sentence to the docblock above `useFinanceAccountLabel`: `Keep in step with app/Support/FinanceAccountLabel.php.`

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter="FinanceAccountLabelTest|TransactionSerializationTest|Transaction|Finance"`
Expected: PASS. If a test asserts the old ISO `transaction_date`, update it to `Y-m-d` and keep the assertion's intent.

- [ ] **Step 6: Commit**

```bash
git add app/Support/FinanceAccountLabel.php app/Models/Transaction.php app/Models/FinanceTransfer.php resources/js/Composables/useFormatDate.js resources/js/Composables/useFinanceAccountLabel.js resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/FinanceAccountLabelTest.php tests/Feature/TransactionSerializationTest.php
git commit -m "feat(finance): one label for money accounts on the server; plain dates in transaction JSON

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Slugs are never shown

**Files:**
- Modify: `app/Support/TransactionTitle.php` (`categoryLabel`, :86-99), `app/Services/Dashboard/OverviewStats.php` (`activity`, :231-251), `app/Services/Dashboard/FinanceStats.php` (`summary`, :48-70), `app/Http/Controllers/TransactionController.php` (`export`, :97-121)
- Test: `tests/Feature/TransactionTitleTest.php` (rewrite the test at :102-115), `tests/Feature/Dashboard/ActivityFeedLabelTest.php`, `tests/Feature/Dashboard/FinanceStatsTest.php`, `tests/Feature/TransactionLabelsTest.php` (new)

- [ ] **Step 1: Failing tests**

In `TransactionTitleTest`, replace `a_category_slug_that_collides_with_an_unrelated_ui_key_is_headlined_not_mistranslated` with:

```php
    #[Test]
    public function without_its_category_an_entry_is_named_by_its_type_never_its_slug(): void
    {
        // Deliberately not eager-loading financeCategory: the fallback path.
        $tx = Transaction::create([
            'amount' => 100, 'transaction_type' => 'expense', 'category' => 'msaryf_ashghal_almkr', 'status' => 'Paid',
        ]);

        app()->setLocale('fr');
        $this->assertSame(UiLang::get('expense'), TransactionTitle::for($tx));
        $this->assertStringNotContainsString('Msaryf', TransactionTitle::for($tx));
    }
```

Add `use App\Support\UiLang;` to the test.

Append to `ActivityFeedLabelTest` (add `use App\Models\FinanceCategory;`):

```php
    #[Test]
    public function an_untitled_transaction_is_named_by_its_category_never_its_slug(): void
    {
        app()->setLocale('ar');
        $works = FinanceCategory::create(['type' => 'expense', 'name' => 'مصاريف أشغال المقر', 'is_active' => true]);
        Transaction::create([
            'amount' => 770, 'transaction_type' => 'expense', 'category' => 'msaryf_ashghal_almkr',
            'finance_category_id' => $works->id, 'status' => 'Paid', 'transaction_date' => now(),
        ]);

        $this->assertSame('مصاريف أشغال المقر', $this->activity()[0]['label']);
    }
```

Append to `FinanceStatsTest`:

```php
    #[Test]
    public function an_untitled_largest_expense_is_named_by_its_category(): void
    {
        $works = $this->category('expense', 'مصاريف أشغال المقر');
        $this->transaction('expense', 770, '2026-05-02', ['category' => 'msaryf_ashghal_almkr', 'finance_category_id' => $works->id]);

        $this->assertSame('مصاريف أشغال المقر', $this->summary()['largest_expense']['meta']['label']);
    }
```

`tests/Feature/TransactionLabelsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionLabelsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_export_names_categories_and_caisses_never_slugs(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        $branch = Branch::create(['name' => 'Football']);
        $works = FinanceCategory::create(['type' => 'expense', 'name' => 'مصاريف أشغال المقر', 'is_active' => true]);
        Transaction::create([
            'title' => 'Peinture', 'amount' => 770, 'transaction_type' => 'expense', 'category' => 'msaryf_ashghal_almkr',
            'finance_category_id' => $works->id, 'finance_account_id' => $branch->treasury->id,
            'status' => 'Paid', 'payment_method' => 'cash', 'transaction_date' => '2026-10-11 16:00:00',
        ]);

        $csv = $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'ar']))
            ->get(route('transactions.export', ['format' => 'csv']))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('مصاريف أشغال المقر', $csv);
        $this->assertStringContainsString('خزينة Football', $csv);
        $this->assertStringContainsString('2026-10-11', $csv);
        $this->assertStringNotContainsString('16:00', $csv);
        $this->assertStringNotContainsString('msaryf', $csv);
        $this->assertStringNotContainsString('Football Treasury', $csv);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter="TransactionTitleTest|ActivityFeedLabelTest|FinanceStatsTest|TransactionLabelsTest"`
Expected: the four new tests FAIL (headline slug, raw slug, raw slug, raw account name and time).

- [ ] **Step 3: Implement**

`TransactionTitle::categoryLabel` becomes:

```php
    private static function categoryLabel(Transaction $transaction): string
    {
        if ($transaction->relationLoaded('financeCategory') && $transaction->financeCategory) {
            return $transaction->financeCategory->localized_name;
        }

        // No category to name it by (not loaded, deleted, never linked). The
        // app's own payment kinds have labels; anything else is named by its
        // type — never by the stored slug, which for an Arabic category name is
        // an unreadable transliteration ("msaryf_ashghal_almkr").
        $slug = (string) $transaction->category;
        if (in_array($slug, self::SYSTEM_CATEGORY_SLUGS, true)) {
            return UiLang::get($slug, Str::headline($slug));
        }

        return UiLang::get((string) ($transaction->transaction_type ?: 'transaction'), 'Transaction');
    }
```

`OverviewStats::activity`: replace the `$transactions` query with:

```php
        $locale = in_array(app()->getLocale(), ['ar', 'fr', 'en'], true) ? app()->getLocale() : 'en';
        $categoryName = "COALESCE(NULLIF(finance_categories.name_{$locale}, ''), finance_categories.name)";

        // Transactions (title and amount) are finance data: not even queried without the right.
        $transactions = ! $money ? Collection::make() : DB::table('transactions')
            ->leftJoin('finance_categories', 'finance_categories.id', '=', 'transactions.finance_category_id')
            ->where('transactions.archived', false)
            ->when($filters->branchId !== null, fn ($q) => $q->whereIn(
                'transactions.finance_account_id',
                DB::table('finance_accounts')->where('branch_id', $filters->branchId)->select('id'),
            ))
            ->orderByDesc('transactions.transaction_date')
            ->limit(10)
            ->get([
                'transactions.id', 'transactions.transaction_date', 'transactions.amount', 'transactions.transaction_type',
                'transactions.title', 'transactions.description', DB::raw("{$categoryName} as category_name"),
            ])
            ->map(fn (object $t): array => [
                'type' => 'transaction',
                'at' => (string) $t->transaction_date,
                // Never the stored slug: the category's own name, else the type.
                'label' => $t->title ?: ($t->description ?: ($t->category_name ?: UiLang::get((string) $t->transaction_type))),
                'amount' => $t->transaction_type === 'income' ? (float) $t->amount : -(float) $t->amount,
                'id' => $t->id,
            ]);
```

Add `use App\Support\UiLang;` to `OverviewStats`.

`FinanceStats::summary`: replace the `$largest` query and its `meta`:

```php
        $largest = $this->windowed($filters)
            ->where('transactions.transaction_type', 'expense')
            ->with('financeCategory:id,name,name_ar,name_fr,name_en')
            ->orderByDesc('transactions.amount')
            ->first([
                'transactions.id', 'transactions.amount', 'transactions.title', 'transactions.description',
                'transactions.finance_category_id', 'transactions.transaction_date',
            ]);
```

```php
                'meta' => $largest ? [
                    // Never the stored slug: title, description, the category's name, else "Expense".
                    'label' => $largest->title ?: ($largest->description ?: ($largest->financeCategory?->localized_name ?: UiLang::get('expense'))),
                    'date' => $largest->transaction_date?->toDateString(),
                ] : null,
```

Add `use App\Support\UiLang;` to `FinanceStats`.

`TransactionController::export`: replace the query and the date, category and register cells:

```php
        $query = Transaction::query()
            ->with(['recordedBy', ...TransactionTitle::RELATIONS, ...Transaction::FINANCE_ACCOUNT_LABEL])
            ->where('archived', false);
        $this->applyFilters($query, $request);

        $rows = $query->latest('transaction_date')->get()->map(fn (Transaction $t) => [
            $t->transaction_date?->format('Y-m-d'),
            TransactionTitle::for($t),
            UiLang::get((string) $t->transaction_type, $t->transaction_type),
            // The category's own name, never the stored slug.
            $t->financeCategory?->localized_name ?? UiLang::get((string) $t->transaction_type),
            (float) $t->amount,
            $t->status ? UiLang::get(strtolower($t->status), $t->status) : null,
            $t->payment_method ? UiLang::get(self::PAYMENT_METHOD_KEYS[$t->payment_method] ?? $t->payment_method, $t->payment_method) : null,
            FinanceAccountLabel::for($t->financeAccount),
            $t->description,
            $t->recordedBy?->name,
        ])->all();
```

Add `use App\Support\FinanceAccountLabel;` to the controller.

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter="TransactionTitleTest|ActivityFeedLabelTest|FinanceStatsTest|TransactionLabelsTest|ListMultiSelectFiltersTest|Dashboard"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/TransactionTitle.php app/Services/Dashboard/OverviewStats.php app/Services/Dashboard/FinanceStats.php app/Http/Controllers/TransactionController.php tests/Feature/TransactionTitleTest.php tests/Feature/Dashboard/ActivityFeedLabelTest.php tests/Feature/Dashboard/FinanceStatsTest.php tests/Feature/TransactionLabelsTest.php
git commit -m "fix(finance): category names and caisse labels instead of slugs on the dashboard, titles and export

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: The observer stops creating categories from slugs

**Files:**
- Create: `app/Support/LegacyFinanceCategory.php`
- Modify: `app/Observers/TransactionObserver.php` (:49-55; drop the unused `Str` and `FinanceCategory` imports)
- Test: `tests/Feature/TransactionCategoryMappingTest.php` (new); update `tests/Feature/TransactionTitleTest.php` (:26-29 and :120-123) and `tests/Feature/FinanceCategoryTranslationTest.php` (:196-208)

**Interfaces — Produces:** `LegacyFinanceCategory::resolve(string $type, ?string $slug): FinanceCategory`. It never creates a category, except to recreate the "Other income" or "Other expense" catch-all once if the club deleted it.

- [ ] **Step 1: Failing tests** — `tests/Feature/TransactionCategoryMappingTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionCategoryMappingTest extends TestCase
{
    use RefreshDatabase;

    private function tx(string $type, string $slug, array $extra = []): Transaction
    {
        return Transaction::create([
            'amount' => 100, 'transaction_type' => $type, 'category' => $slug,
            'status' => 'Paid', 'payment_method' => 'cash', 'transaction_date' => now(),
        ] + $extra);
    }

    #[Test]
    public function the_apps_own_payment_kinds_land_in_the_seeded_categories(): void
    {
        $before = FinanceCategory::count();

        $this->assertSame('SUB', $this->tx('income', 'subscription')->financeCategory->code);
        $this->assertSame('DON', $this->tx('income', 'donation')->financeCategory->code);
        $this->assertSame('ARR', $this->tx('income', 'debt_payment')->financeCategory->code);
        $this->assertSame('MEM', $this->tx('income', 'membership_fee')->financeCategory->code);
        $this->assertSame('EQP', $this->tx('expense', 'equipment')->financeCategory->code);
        $this->assertSame('EQP', $this->tx('expense', 'equipment_purchase')->financeCategory->code);

        $this->assertSame($before, FinanceCategory::count());
    }

    #[Test]
    public function an_unknown_slug_lands_in_other_never_in_a_new_category(): void
    {
        $before = FinanceCategory::count();

        $this->assertSame('OEX', $this->tx('expense', 'msaryf_ashghal_almkr')->financeCategory->code);
        $this->assertSame('OIN', $this->tx('income', 'miscellaneous')->financeCategory->code);
        $this->assertSame('OIN', $this->tx('income', '')->financeCategory->code);

        $this->assertSame($before, FinanceCategory::count());
        $this->assertFalse(FinanceCategory::where('name', 'Msaryf Ashghal Almkr')->exists());
    }

    #[Test]
    public function a_club_that_removed_a_seeded_category_falls_back_to_its_legacy_twin(): void
    {
        FinanceCategory::where('code', 'SUB')->delete();
        $twin = FinanceCategory::create(['type' => 'income', 'name' => 'Subscription', 'is_active' => true]);

        $this->assertSame($twin->id, $this->tx('income', 'subscription')->finance_category_id);
    }

    #[Test]
    public function a_missing_other_category_is_recreated_once(): void
    {
        FinanceCategory::where('code', 'OEX')->delete();

        $first = $this->tx('expense', 'rent-of-the-hall');
        $second = $this->tx('expense', 'paint');

        $this->assertSame($first->finance_category_id, $second->finance_category_id);
        $other = $first->financeCategory;
        $this->assertSame('OEX', $other->code);
        $this->assertTrue($other->is_system);
        $this->assertSame(1, FinanceCategory::where('type', 'expense')->where('code', 'OEX')->count());
    }

    #[Test]
    public function a_category_chosen_by_the_caller_is_kept(): void
    {
        $rent = FinanceCategory::create(['type' => 'expense', 'name' => 'Rent', 'is_active' => true]);

        $this->assertSame($rent->id, $this->tx('expense', 'rent', ['finance_category_id' => $rent->id])->finance_category_id);
    }
}
```

Update the existing tests to the new mapping:
- `TransactionTitleTest`: in `subscriptionPayment()` (:26-29) and in `a_line_without_its_own_label_falls_back_to_the_subscriptions_name` (:120-123), replace the `FinanceCategory::updateOrCreate(['type' => 'income', 'name' => 'Subscription'], [...])` call with `FinanceCategory::where('type', 'income')->where('code', 'SUB')->update(['name_fr' => 'Cotisation', 'name_ar' => 'اشتراك']);`. The assertions stay the same, because subscription payments now land in SUB.
- `FinanceCategoryTranslationTest::categories_auto_created_by_a_transaction_get_stock_translations` is replaced by:

```php
    #[Test]
    public function a_debt_payment_lands_in_the_seeded_arrears_category(): void
    {
        $tx = Transaction::create([
            'amount' => 300, 'transaction_type' => 'income', 'category' => 'debt_payment',
            'status' => 'Paid', 'transaction_date' => now(),
        ]);

        $this->assertSame('ARR', $tx->financeCategory->code);
        $this->assertFalse(FinanceCategory::where('name', 'Debt Payment')->exists());
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=TransactionCategoryMappingTest`
Expected: FAIL. Slugs still create "Subscription", "Msaryf Ashghal Almkr", and so on.

- [ ] **Step 3: Implement** — `app/Support/LegacyFinanceCategory.php`

```php
<?php

namespace App\Support;

use App\Models\FinanceCategory;
use Illuminate\Support\Str;

/**
 * The finance category for a transaction that arrives with only a legacy slug
 * in `category` — player payments, equipment purchases, old imports.
 *
 * Never creates a category from a slug: the slug of an Arabic name is a
 * transliteration ("msaryf_ashghal_almkr"), and title-casing "subscription"
 * planted a "Subscription" next to the seeded "Subscriptions".
 *
 * Order: the seeded category for a known slug (matched by code, so a club
 * rename is harmless) → a category already named like the slug (a club that
 * deleted the seeded one but kept the legacy twin) → the single "Other" of
 * that type, recreated once if it was deleted.
 */
final class LegacyFinanceCategory
{
    /** Slug => code of the category seeded by create_finance_tables. */
    private const CODES = [
        'income' => [
            'subscription' => 'SUB', 'subscriptions' => 'SUB',
            'membership_fee' => 'MEM', 'membership_fees' => 'MEM',
            'donation' => 'DON', 'donations' => 'DON',
            'grant' => 'GRT', 'grants' => 'GRT', 'subsidy' => 'GRT',
            'debt_payment' => 'ARR', 'debts' => 'ARR', 'arrears' => 'ARR',
            'event' => 'EVR', 'events' => 'EVR',
        ],
        'expense' => [
            'equipment' => 'EQP', 'equipment_purchase' => 'EQP',
            'maintenance' => 'MNT', 'repairs' => 'MNT',
            'supplies' => 'SUP',
            'works' => 'WRK', 'construction' => 'WRK',
            'utilities' => 'UTL',
            'salaries' => 'SAL', 'wages' => 'SAL',
            'event' => 'EVE', 'events' => 'EVE',
            'administrative' => 'ADM',
        ],
    ];

    /** Type => [code, base name] of the catch-all category. */
    private const OTHER = [
        'income' => ['OIN', 'Other Income'],
        'expense' => ['OEX', 'Other Expense'],
    ];

    public static function resolve(string $type, ?string $slug): FinanceCategory
    {
        $type = $type === 'expense' ? 'expense' : 'income';
        $slug = str_replace(['-', ' '], '_', mb_strtolower(trim((string) $slug)));

        $code = self::CODES[$type][$slug] ?? null;
        if ($code && $seeded = self::byCode($type, $code)) {
            return $seeded;
        }

        if ($slug !== '') {
            $twin = FinanceCategory::where('type', $type)
                ->where('name', Str::title(str_replace('_', ' ', $slug)))
                ->orderBy('id')->first();
            if ($twin) {
                return $twin;
            }
        }

        [$otherCode, $otherName] = self::OTHER[$type];

        return self::byCode($type, $otherCode)
            ?? FinanceCategory::firstOrCreate(
                ['type' => $type, 'name' => $otherName],
                ['code' => $otherCode, 'is_active' => true, 'is_system' => true],
            );
    }

    private static function byCode(string $type, string $code): ?FinanceCategory
    {
        return FinanceCategory::where('type', $type)->where('code', $code)->orderBy('id')->first();
    }
}
```

In `TransactionObserver::saving`, replace the block at :49-55 with:

```php
        // Every transaction gets a category; one only carrying a legacy slug is
        // mapped to an existing category, never given a new one (LegacyFinanceCategory).
        if (empty($transaction->finance_category_id)) {
            $transaction->finance_category_id = LegacyFinanceCategory::resolve(
                (string) $transaction->transaction_type,
                $transaction->category,
            )->id;
        }
```

Add `use App\Support\LegacyFinanceCategory;` and remove `use App\Models\FinanceCategory;` and `use Illuminate\Support\Str;`. After the edit, run grep to confirm neither is still used in the file.

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter="TransactionCategoryMappingTest|TransactionTitleTest|FinanceCategoryTranslationTest|PlayerPaymentFlowTest|PlayerLevelPaymentTest|FixSubscriptionBillingTest|ImportMongoJsonDataTest|BackfillFinanceTest|ReportPdfTest|Equipment"`
Expected: PASS. If a test looks up an auto-created category by its legacy title ("Subscription", "Donation", "Debt Payment"), switch it to the seeded code (SUB, DON, ARR) and keep the intent. `BackfillFinance` keeps its own map; it is a one-off command and out of scope.

- [ ] **Step 5: Commit**

```bash
git add app/Support/LegacyFinanceCategory.php app/Observers/TransactionObserver.php tests/Feature/TransactionCategoryMappingTest.php tests/Feature/TransactionTitleTest.php tests/Feature/FinanceCategoryTranslationTest.php
git commit -m "fix(finance): payments without a category land in the seeded categories, never in new ones made from slugs

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Finance category rules on the server

**Files:**
- Modify: `app/Http/Controllers/FinanceCategoryController.php` (full replacement below)
- Modify: `resources/js/i18n/{ar,fr,en}.json`, `lang/ar.json`, `lang/fr.json`
- Test: `tests/Feature/FinanceCategoryRulesTest.php` (new); `FinanceCategoryTranslationTest::store_and_update_persist_the_translations` must stay green unchanged

**Interfaces — Produces:**
- POST `finance.categories.store` and PUT `finance.categories.update` accept `name_ar|name_fr|name_en`, at least one of them required (or a legacy `name`), plus `color`, `is_active` and `type`.
- Errors: `name_ar` (no name in any language); `name` (taken).
- Flash: `flash.category_is_system`, `['key' => 'flash.category_has_transactions', 'params' => ['count' => n]]`.

- [ ] **Step 1: i18n**

UI catalogs. Update the existing key and add the new one:

| key | en | fr | ar |
|---|---|---|---|
| `flash.category_has_transactions` | This category is used by {count} transaction(s), so it cannot be deleted. Deactivate it instead. | Cette catégorie est utilisée par {count} transaction(s) : elle ne peut pas être supprimée. Désactivez-la plutôt. | هذه الفئة مستعملة في {count} معاملة، لذا لا يمكن حذفها. يمكنك تعطيلها بدلاً من ذلك. |
| `flash.category_is_system` | A built-in category cannot be deleted; it can be renamed. | Une catégorie intégrée ne peut pas être supprimée ; elle peut être renommée. | لا يمكن حذف فئة أساسية، لكن يمكن تغيير اسمها. |

`lang/ar.json` gets `"A finance category with this name already exists.": "توجد فئة مالية بهذا الاسم مسبقاً."`. `lang/fr.json` gets `"A finance category with this name already exists.": "Une catégorie financière porte déjà ce nom."`.

- [ ] **Step 2: Failing tests** — `tests/Feature/FinanceCategoryRulesTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinanceCategoryRulesTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    private function admin(): User
    {
        return $this->admin ??= User::factory()->admin()->create();
    }

    private function useCategory(FinanceCategory $category, int $times = 1): void
    {
        foreach (range(1, $times) as $i) {
            Transaction::create([
                'amount' => 10 * $i, 'transaction_type' => $category->type, 'category' => 'x',
                'finance_category_id' => $category->id, 'status' => 'Paid', 'transaction_date' => now(),
            ]);
        }
    }

    #[Test]
    public function a_built_in_category_cannot_be_deleted_but_can_be_renamed(): void
    {
        $equipment = FinanceCategory::where('code', 'EQP')->firstOrFail();

        $this->actingAs($this->admin())->delete(route('finance.categories.destroy', $equipment))
            ->assertSessionHas('error', 'flash.category_is_system');
        $this->assertModelExists($equipment);

        $this->actingAs($this->admin())->put(route('finance.categories.update', $equipment), [
            'type' => 'income', 'name_ar' => 'عتاد', 'name_fr' => 'Matériel', 'name_en' => 'Gear', 'color' => '#000000', 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $equipment->refresh();
        $this->assertSame('Equipment', $equipment->name);   // base name kept: the app finds it by it
        $this->assertSame('expense', $equipment->type);     // and its side of the books
        $this->assertSame('Gear', $equipment->name_en);
        $this->assertSame('عتاد', $equipment->name_ar);
        $this->assertSame('#000000', $equipment->color);
    }

    #[Test]
    public function a_used_category_cannot_be_deleted_and_the_message_counts_its_transactions(): void
    {
        $rent = FinanceCategory::create(['type' => 'expense', 'name' => 'Rent', 'is_active' => true]);
        $this->useCategory($rent, 2);

        $this->actingAs($this->admin())->delete(route('finance.categories.destroy', $rent))
            ->assertSessionHas('error', ['key' => 'flash.category_has_transactions', 'params' => ['count' => 2]]);
        $this->assertModelExists($rent);
    }

    #[Test]
    public function an_unused_category_is_deleted(): void
    {
        $rent = FinanceCategory::create(['type' => 'expense', 'name' => 'Rent', 'is_active' => true]);

        $this->actingAs($this->admin())->delete(route('finance.categories.destroy', $rent))
            ->assertSessionHas('success', 'flash.category_deleted');
        $this->assertModelMissing($rent);
    }

    #[Test]
    public function a_category_typed_in_arabic_only_stays_arabic_in_every_language(): void
    {
        $this->actingAs($this->admin())->post(route('finance.categories.store'), [
            'type' => 'expense', 'name_ar' => 'مصاريف أشغال المقر', 'color' => '#ef4444',
        ])->assertSessionHasNoErrors();

        $category = FinanceCategory::where('name_ar', 'مصاريف أشغال المقر')->sole();
        $this->assertSame('مصاريف أشغال المقر', $category->name);
        app()->setLocale('fr');
        $this->assertSame('مصاريف أشغال المقر', $category->localized_name);
    }

    #[Test]
    public function the_base_name_prefers_english_then_french(): void
    {
        $this->actingAs($this->admin())->post(route('finance.categories.store'), [
            'type' => 'expense', 'name_ar' => 'كراء', 'name_fr' => 'Loyer',
        ])->assertSessionHasNoErrors();
        $category = FinanceCategory::where('name_ar', 'كراء')->sole();
        $this->assertSame('Loyer', $category->name);

        $this->actingAs($this->admin())->put(route('finance.categories.update', $category), [
            'type' => 'expense', 'name_ar' => 'كراء', 'name_fr' => 'Loyer', 'name_en' => 'Rent',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Rent', $category->fresh()->name);
    }

    #[Test]
    public function a_name_is_needed_in_at_least_one_language(): void
    {
        $this->actingAs($this->admin())->post(route('finance.categories.store'), ['type' => 'income', 'color' => '#000000'])
            ->assertSessionHasErrors('name_ar');
    }

    #[Test]
    public function two_categories_of_one_type_cannot_share_a_name(): void
    {
        $this->actingAs($this->admin())->post(route('finance.categories.store'), ['type' => 'expense', 'name_en' => 'Rent'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin())->post(route('finance.categories.store'), ['type' => 'expense', 'name_en' => 'Rent'])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function a_used_category_keeps_its_type(): void
    {
        $rent = FinanceCategory::create(['type' => 'expense', 'name' => 'Rent', 'is_active' => true]);
        $this->useCategory($rent);

        $this->actingAs($this->admin())->put(route('finance.categories.update', $rent), ['type' => 'income', 'name_en' => 'Rent'])
            ->assertSessionHasNoErrors();
        $this->assertSame('expense', $rent->fresh()->type);
    }
}
```

- [ ] **Step 3: Run to verify failure**

Run: `php artisan test --filter=FinanceCategoryRulesTest`
Expected: FAIL. A system category can be deleted, the flash has no count, and `name` is required.

- [ ] **Step 4: Implement** — replace `app/Http/Controllers/FinanceCategoryController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Models\FinanceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The finance-categories manager (CategoryManager.vue), opened from the
 * Transactions page and from Finance settings.
 *
 * Names come per language. The stored base `name` — the unique key and the
 * fallback for any language left empty — is the one sent, else English, else
 * French, else Arabic, so a category typed in Arabic only stays Arabic
 * everywhere. Built-in categories (is_system) keep their base name and type:
 * the app finds them by code and name (LegacyFinanceCategory,
 * finance:backfill). They can be renamed per language, never deleted.
 */
class FinanceCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['name'] = $this->baseName($data);
        $this->assertNameFree($data['type'], $data['name']);

        FinanceCategory::create($data + ['is_active' => true, 'is_system' => false]);

        return back()->with('success', 'flash.category_added');
    }

    public function update(Request $request, FinanceCategory $financeCategory): RedirectResponse
    {
        $data = $this->validateData($request);

        // Entries already booked keep their side of the books.
        if ($financeCategory->is_system || $financeCategory->transactions()->exists()) {
            $data['type'] = $financeCategory->type;
        }

        if ($financeCategory->is_system) {
            unset($data['name']);
        } else {
            $data['name'] = $this->baseName($data);
            $this->assertNameFree($data['type'], $data['name'], $financeCategory->id);
        }

        $financeCategory->update($data);

        return back()->with('success', 'flash.category_updated');
    }

    public function destroy(FinanceCategory $financeCategory): RedirectResponse
    {
        if ($financeCategory->is_system) {
            return back()->with('error', 'flash.category_is_system');
        }

        $used = $financeCategory->transactions()->count();
        if ($used > 0) {
            return back()->with('error', ['key' => 'flash.category_has_transactions', 'params' => ['count' => $used]]);
        }

        $financeCategory->delete();

        return back()->with('success', 'flash.category_deleted');
    }

    /** @return array<string, mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(['income', 'expense'])],
            // Legacy single name (older callers); the manager sends the three below.
            'name' => ['nullable', 'string', 'max:120'],
            // At least one language; the error sits on the form's first field.
            'name_ar' => ['nullable', 'string', 'max:120', 'required_without_all:name,name_fr,name_en'],
            'name_fr' => ['nullable', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:16'],
            'color' => ['nullable', 'string', 'max:16'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function baseName(array $data): string
    {
        foreach (['name', 'name_en', 'name_fr', 'name_ar'] as $field) {
            if (filled($data[$field] ?? null)) {
                return trim((string) $data[$field]);
            }
        }

        return '';
    }

    private function assertNameFree(string $type, string $name, ?int $ignoreId = null): void
    {
        $taken = FinanceCategory::query()
            ->where('type', $type)
            ->where('name', $name)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => __('A finance category with this name already exists.'),
            ]);
        }
    }
}
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter="FinanceCategoryRulesTest|FinanceCategoryTranslationTest|FinanceSettings|FlashTranslation"` and `npm run i18n:check`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/FinanceCategoryController.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json lang/ar.json lang/fr.json tests/Feature/FinanceCategoryRulesTest.php
git commit -m "feat(finance): category rules on the server — built-in kept, used ones counted, names per language

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Transactions list backend — season default, visible filters, strip and statistics

**Files:**
- Modify: `app/Http/Controllers/TransactionController.php` (constants :40-58, `index` :60-95, `applyFilters` :317-348; new private helpers)
- Modify: `app/Services/Dashboard/ModuleStats.php` (class docblock, new `transactions()`)
- Create: `lang/ar/pagination.php`, `lang/fr/pagination.php`
- Test: `tests/Feature/TransactionsIndexTest.php` (new); update `tests/Feature/TransactionFinanceCategoryTest.php::index_exposes_filtered_stats`, `tests/Feature/ListMultiSelectFiltersTest.php`, `tests/Feature/Dashboard/ModuleStatsTest.php`

**Interfaces — Produces** (props of `Transactions/Index`):
- `transactions` (paginator; `per_page` ∈ 25/50/100/200)
- `strip` (`[{key: tx_income|tx_expense|tx_net|tx_debts, value, format: 'money'}]`)
- `incomeByCategory`, `expenseByCategory` (`[{key: id|null, label: string|null, count: amount}]`)
- `byAccount` (`[{key: id|null, label: string, count: int}]`)
- `filters` (`search, date_from, date_to, per_page, type[], status[], finance_category_id[], finance_account_id[], fiscal_year[]`)
- Closures: `financeCategories` (all, with `transactions_count`), `financeAccounts` (as today), `seasons` (`[{value: '2027', label: '2026/2027'}]`, newest first, always including the current one), `currentSeason` (int)

The `stats` prop is removed.

- [ ] **Step 1: Pagination translations**

`lang/ar/pagination.php`:

```php
<?php

// The list draws its own arrows (Pagination.vue); these are the words beside them.
return [
    'previous' => 'السابق',
    'next' => 'التالي',
];
```

`lang/fr/pagination.php`: the same file with `'previous' => 'Précédent'` and `'next' => 'Suivant'`.

- [ ] **Step 2: Failing tests** — `tests/Feature/TransactionsIndexTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionsIndexTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Season 2026/2027 (key 2027) is the current one.
        Carbon::setTestNow('2026-10-15 10:00:00');
    }

    private function props(array $query = []): array
    {
        $this->admin ??= User::factory()->admin()->create();

        return $this->actingAs($this->admin)->get(route('transactions.index', $query))->assertOk()->viewData('page')['props'];
    }

    private function tx(string $description, string $date, array $extra = []): Transaction
    {
        return Transaction::create([
            'description' => $description, 'amount' => 100, 'transaction_date' => $date,
            'transaction_type' => 'income', 'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid',
        ] + $extra);
    }

    /** @return list<string> */
    private function listed(array $query = []): array
    {
        return collect($this->props($query)['transactions']['data'])->pluck('description')->sort()->values()->all();
    }

    private function tile(array $props, string $key): float
    {
        return (float) collect($props['strip'])->firstWhere('key', $key)['value'];
    }

    private function account(string $name): FinanceAccount
    {
        return FinanceAccount::create(['name' => $name, 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD']);
    }

    #[Test]
    public function the_list_opens_on_the_current_season(): void
    {
        $this->tx('now', '2026-10-01');
        $this->tx('last-season', '2026-03-01');

        $props = $this->props();

        $this->assertSame(['now'], collect($props['transactions']['data'])->pluck('description')->all());
        $this->assertSame(2027, $props['currentSeason']);
        $this->assertArrayNotHasKey('fiscal_year', $props['filters']);
        $this->assertSame(['value' => '2027', 'label' => '2026/2027'], $props['seasons'][0]);
        $this->assertContains(['value' => '2026', 'label' => '2025/2026'], $props['seasons']);
        $this->assertArrayNotHasKey('stats', $props);
    }

    #[Test]
    public function every_season_or_one_past_season_can_be_chosen(): void
    {
        $this->tx('now', '2026-10-01');
        $this->tx('last-season', '2026-03-01');

        $this->assertSame(['last-season', 'now'], $this->listed(['fiscal_year' => 'all']));
        $this->assertSame(['last-season'], $this->listed(['fiscal_year' => '2026']));
        $this->assertSame(['all'], $this->props(['fiscal_year' => 'all'])['filters']['fiscal_year']);
    }

    #[Test]
    public function a_date_range_alone_decides_the_days_whatever_the_season(): void
    {
        $this->tx('march', '2026-03-10');
        $this->tx('april', '2026-04-10 18:30:00');
        $this->tx('october', '2026-10-01');

        // Inclusive of the whole last day, and not narrowed to the current season.
        $this->assertSame(['april', 'march'], $this->listed(['date_from' => '2026-03-01', 'date_to' => '2026-04-10']));
        // A malformed date is ignored rather than compared as text.
        $this->assertSame(['october'], $this->listed(['date_from' => 'yesterday']));
    }

    #[Test]
    public function the_strip_follows_the_filters_but_debts_stay_club_wide(): void
    {
        $a = $this->account('A');
        $b = $this->account('B');
        $this->tx('in-a', '2026-10-01', ['amount' => 900, 'finance_account_id' => $a->id]);
        $this->tx('out-a', '2026-10-02', ['amount' => 400, 'transaction_type' => 'expense', 'category' => 'supplies', 'finance_account_id' => $a->id]);
        $this->tx('in-b', '2026-10-03', ['amount' => 50, 'finance_account_id' => $b->id]);
        Player::create(['membership_id' => '202600001', 'firstname' => 'Amine', 'lastname' => 'Benali', 'outstanding_debt' => 1200]);

        $props = $this->props(['finance_account_id' => [$a->id]]);
        $this->assertSame(['tx_income', 'tx_expense', 'tx_net', 'tx_debts'], collect($props['strip'])->pluck('key')->all());
        $this->assertSame(900.0, $this->tile($props, 'tx_income'));
        $this->assertSame(400.0, $this->tile($props, 'tx_expense'));
        $this->assertSame(500.0, $this->tile($props, 'tx_net'));
        $this->assertSame(1200.0, $this->tile($props, 'tx_debts'));

        $all = $this->props();
        $this->assertSame(950.0, $this->tile($all, 'tx_income'));
        $this->assertSame(1200.0, $this->tile($all, 'tx_debts'));
    }

    #[Test]
    public function each_statistics_card_ignores_its_own_filter(): void
    {
        $donations = FinanceCategory::where('code', 'DON')->firstOrFail();
        $grants = FinanceCategory::where('code', 'GRT')->firstOrFail();
        $equipment = FinanceCategory::where('code', 'EQP')->firstOrFail();
        $this->tx('d', '2026-10-01', ['amount' => 300, 'finance_category_id' => $donations->id]);
        $this->tx('g', '2026-10-02', ['amount' => 200, 'finance_category_id' => $grants->id]);
        $this->tx('e', '2026-10-03', ['amount' => 80, 'transaction_type' => 'expense', 'finance_category_id' => $equipment->id]);

        $props = $this->props(['finance_category_id' => [$donations->id]]);

        // The list is narrowed…
        $this->assertSame(['d'], collect($props['transactions']['data'])->pluck('description')->all());
        // …the category cards are not, so another bar can still be clicked.
        $income = collect($props['incomeByCategory'])->keyBy('key');
        $this->assertEquals(300, $income[$donations->id]['count']);
        $this->assertEquals(200, $income[$grants->id]['count']);
        $this->assertSame($grants->localized_name, $income[$grants->id]['label']);
        $this->assertEquals(80, collect($props['expenseByCategory'])->firstWhere('key', $equipment->id)['count']);
        // The caisse card follows the category filter: one entry, labelled.
        $this->assertSame(1, collect($props['byAccount'])->sum('count'));
        $this->assertNotSame('', collect($props['byAccount'])->first()['label']);
    }

    #[Test]
    public function a_payment_status_from_a_link_filters_and_shows_and_the_old_slug_filter_is_gone(): void
    {
        $this->tx('paid', '2026-10-01');
        $this->tx('partial', '2026-10-02', ['status' => 'Partial']);

        $props = $this->props(['status' => 'Partial']);
        $this->assertSame(['partial'], collect($props['transactions']['data'])->pluck('description')->all());
        $this->assertSame(['Partial'], $props['filters']['status']);

        // `category` (the stored slug) had no control on the page: it no longer filters.
        $this->assertSame(['paid', 'partial'], $this->listed(['category' => 'nothing-matches']));
    }

    #[Test]
    public function the_page_size_can_be_chosen(): void
    {
        foreach (range(1, 30) as $i) {
            $this->tx("t{$i}", '2026-10-01');
        }

        $this->assertSame(25, $this->props()['transactions']['per_page']);
        $this->assertSame(50, $this->props(['per_page' => 50])['transactions']['per_page']);
        $this->assertSame(25, $this->props(['per_page' => 7])['transactions']['per_page']);
        $this->assertSame('50', $this->props(['per_page' => 50])['filters']['per_page']);
    }

    #[Test]
    public function search_finds_a_category_by_its_name_never_by_its_slug(): void
    {
        $works = FinanceCategory::create(['type' => 'expense', 'name' => 'مصاريف أشغال المقر', 'is_active' => true]);
        $this->tx('hq', '2026-10-01', ['transaction_type' => 'expense', 'finance_category_id' => $works->id, 'category' => 'msaryf_ashghal_almkr']);
        $this->tx('other', '2026-10-02');

        $this->assertSame(['hq'], $this->listed(['search' => 'أشغال']));
        $this->assertSame([], $this->listed(['search' => 'msaryf']));
    }

    #[Test]
    public function finance_categories_come_with_their_usage_including_inactive_ones(): void
    {
        $old = FinanceCategory::create(['type' => 'income', 'name' => 'Old', 'is_active' => false]);
        $this->tx('x', '2026-10-01', ['finance_category_id' => $old->id]);

        $row = collect($this->props()['financeCategories'])->firstWhere('id', $old->id);
        $this->assertSame(1, $row['transactions_count']);
    }

    #[Test]
    public function pagination_speaks_the_users_language(): void
    {
        foreach (range(1, 26) as $i) {
            $this->tx("t{$i}", '2026-10-01');
        }

        $this->admin = User::factory()->admin()->create(['preferred_lng' => 'ar']);
        $links = $this->props()['transactions']['links'];
        $this->assertSame('السابق', $links[0]['label']);
        $this->assertSame('التالي', end($links)['label']);

        $this->admin = User::factory()->admin()->create(['preferred_lng' => 'fr']);
        $links = $this->props()['transactions']['links'];
        $this->assertSame('Précédent', $links[0]['label']);
        $this->assertSame('Suivant', end($links)['label']);
    }
}
```

Update the existing tests:
- `TransactionFinanceCategoryTest::index_exposes_filtered_stats`: replace the four `stats.*` assertions with:

```php
                ->where('strip', fn ($strip) => collect($strip)->pluck('value', 'key')->map(fn ($v) => (float) $v)->all()
                    == ['tx_income' => 900.0, 'tx_expense' => 400.0, 'tx_net' => 500.0, 'tx_debts' => 0.0]));
```

- `ListMultiSelectFiltersTest`: its transactions are dated 2026-03-01, which is a past season. In `txTitles()`, call `$this->props('transactions.index', $query + ['fiscal_year' => 'all'])`. In `the_transactions_export_honours_arrays`, add `'fiscal_year' => 'all'` to the export route parameters.
- `Dashboard/ModuleStatsTest`: add

```php
    #[Test]
    public function the_transactions_strip_sums_the_query_it_is_given(): void
    {
        foreach ([[500, 'income'], [120, 'expense']] as [$amount, $type]) {
            \App\Models\Transaction::create(['amount' => $amount, 'transaction_type' => $type, 'category' => 'x', 'status' => 'Paid', 'transaction_date' => now()]);
        }
        $this->player(['outstanding_debt' => 300]);

        $strip = collect(app(ModuleStats::class)->transactions(\App\Models\Transaction::query()->where('archived', false)))->keyBy('key');

        $this->assertSame(500.0, $strip['tx_income']['value']);
        $this->assertSame(120.0, $strip['tx_expense']['value']);
        $this->assertSame(380.0, $strip['tx_net']['value']);
        $this->assertSame(300.0, $strip['tx_debts']['value']);
        $this->assertSame('money', $strip['tx_net']['format']);
    }
```

- [ ] **Step 3: Run to verify failure**

Run: `php artisan test --filter="TransactionsIndexTest|ModuleStatsTest"`
Expected: FAIL. There is no `strip`, no season default, the `category` filter still applies, there is no `per_page`, and pagination labels are English.

- [ ] **Step 4: `ModuleStats::transactions`**

In the class docblock, replace the paragraph "Strips are unfiltered totals …" with:

```php
 * Strips are unfiltered totals for the module, not the page's current filter —
 * except Transactions: a ledger's first question is "how much in what I am
 * looking at", so its strip sums the list's own query. Members' debts stay a
 * club-wide figure there (and say so): a debt has no season, caisse or category.
```

Add `use Illuminate\Database\Eloquent\Builder;`, then:

```php
    /**
     * @param  Builder<\App\Models\Transaction>  $filtered  the list query, filters applied
     * @return list<array<string, mixed>>
     */
    public function transactions(Builder $filtered): array
    {
        $row = (clone $filtered)->toBase()
            ->reorder()
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income, "
                ."COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense"
            )
            ->first();

        $income = round((float) ($row->income ?? 0), 2);
        $expense = round((float) ($row->expense ?? 0), 2);
        $debts = (float) DB::table('players')->where('archived', false)->sum('outstanding_debt');

        return [
            $this->tile('tx_income', $income, 'money'),
            $this->tile('tx_expense', $expense, 'money'),
            $this->tile('tx_net', round($income - $expense, 2), 'money'),
            $this->tile('tx_debts', round($debts, 2), 'money'),
        ];
    }
```

- [ ] **Step 5: `TransactionController`**

Imports to add: `App\Models\FiscalYear` (already present), `App\Services\Dashboard\ModuleStats`, `App\Support\FinanceAccountLabel` (from Task 2), `App\Support\FiscalPeriod`, `Illuminate\Support\Arr`.

Replace the two filter constants (:40-58) with:

```php
    /** Page sizes the list offers; anything else falls back to the first. */
    private const PER_PAGE = [25, 50, 100, 200];

    /**
     * Select filters (query parameter => column); each takes several values.
     * Every one has a control on the page (status: a removable chip). The old
     * `category` slug filter is gone — it had no control and no caller.
     */
    private const LIST_FILTER_COLUMNS = [
        'type' => 'transaction_type',
        'status' => 'status',
        'finance_category_id' => 'finance_category_id',
        'finance_account_id' => 'finance_account_id',
    ];

    /** Which values each select filter can apply (ListFilter specs). */
    private const LIST_FILTER_SPECS = [
        'type' => ['income', 'expense'],
        'status' => ['Paid', 'Partial', 'Unpaid', 'Exempt'],
        'finance_category_id' => ListFilter::IDS,
        'finance_account_id' => ListFilter::IDS,
        // Season keys or "all"; absent = the current season (see applyFilters).
        'fiscal_year' => 'ids|all',
    ];
```

Replace `index()` with:

```php
    public function index(Request $request, ModuleStats $moduleStats): Response
    {
        $query = Transaction::query()->where('archived', false);
        $this->applyFilters($query, $request);

        $perPage = in_array((int) $request->input('per_page'), self::PER_PAGE, true)
            ? (int) $request->input('per_page')
            : self::PER_PAGE[0];

        $transactions = (clone $query)
            ->with(['recordedBy', 'receivedBy', ...TransactionTitle::RELATIONS, ...Transaction::FINANCE_ACCOUNT_LABEL])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Transaction $transaction) => TransactionTitle::decorate($transaction));

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            // Everything below until `filters` follows the filters, so the page reloads it with the list.
            'strip' => $moduleStats->transactions($query),
            'incomeByCategory' => $this->categoryStats($request, 'income'),
            'expenseByCategory' => $this->categoryStats($request, 'expense'),
            'byAccount' => $this->accountStats($request),
            // Select filters come back as lists, whatever shape was sent.
            'filters' => ListFilter::echo($request, self::LIST_FILTER_SPECS, ['search', 'date_from', 'date_to', 'per_page']),
            // Lookups as closures so filter reloads (partial) skip these queries.
            // Every category, inactive too: old entries stay filterable, and the
            // categories manager needs each one's usage.
            'financeCategories' => fn () => FinanceCategory::withCount('transactions')
                ->orderBy('type')->orderBy('sort_order')->orderBy('name')->get(),
            'financeAccounts' => fn () => FinanceAccount::query()
                ->where('is_opening_fund', false)
                ->with([
                    'branch:id,name,name_ar,name_fr,name_en',
                    'category:id,name,name_ar,name_fr,name_en',
                ])
                ->orderBy('sort_order')->orderBy('name')->get(),
            'seasons' => fn () => $this->seasonOptions(),
            'currentSeason' => fn () => FiscalPeriod::currentKey(),
        ]);
    }
```

Replace `applyFilters()` with:

```php
    /**
     * The list filters, shared by the index, its statistics and its export so
     * the file always matches what the page shows.
     *
     * @param  Builder<Transaction>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $like = "%{$search}%";
            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                // The category by its name in any language — never the stored
                // slug, a transliteration for Arabic names.
                ->orWhereIn('finance_category_id', FinanceCategory::query()
                    ->where(fn (Builder $c) => $c->where('name', 'like', $like)
                        ->orWhere('name_ar', 'like', $like)
                        ->orWhere('name_fr', 'like', $like)
                        ->orWhere('name_en', 'like', $like))
                    ->select('id'))
                // A player's payments, found by any part of the name or the membership ID.
                ->orWhere(fn (Builder $p) => $p
                    ->where('related_entity_type', 'Player')
                    ->whereIn('related_entity_id', Player::query()->search($search)->select('id'))));
        }

        // Each may hold several values (key[]=a&key[]=b) or one scalar from
        // an old link: OR within a filter, AND across filters.
        foreach (self::LIST_FILTER_COLUMNS as $param => $column) {
            $values = ListFilter::get($request, $param, self::LIST_FILTER_SPECS[$param]);

            if ($values !== []) {
                $query->whereIn($column, $values);
            }
        }

        $from = $this->dateParam($request, 'date_from');
        $to = $this->dateParam($request, 'date_to');
        if ($from) {
            $query->whereDate('transaction_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('transaction_date', '<=', $to);
        }

        // Season: absent = the current one, unless a date range already says
        // which days to show; "all" = every season.
        $seasons = ListFilter::get($request, 'fiscal_year', self::LIST_FILTER_SPECS['fiscal_year']);
        if ($seasons === []) {
            if (! $from && ! $to) {
                $query->where('fiscal_year', FiscalPeriod::currentKey());
            }
        } elseif (! in_array('all', $seasons, true)) {
            $query->whereIn('fiscal_year', array_map('intval', $seasons));
        }
    }

    /** A Y-m-d date from the query string, or null for anything else. */
    private function dateParam(Request $request, string $key): ?string
    {
        $value = (string) $request->input($key, '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * The list's population for one statistics card minus the filter that card
     * drives, so clicking a bar never empties its own card (as on Players).
     *
     * @return Builder<Transaction>
     */
    private function statsQuery(Request $request, string $except): Builder
    {
        $query = Transaction::query()->where('archived', false);
        $this->applyFilters($query, new Request(Arr::except($request->query(), [$except])));

        return $query;
    }

    /**
     * One side of the books by finance category, as amounts, largest first.
     *
     * @return list<array{key: int|null, label: string|null, count: float}>
     */
    private function categoryStats(Request $request, string $type): array
    {
        $rows = $this->statsQuery($request, 'finance_category_id')
            ->where('transaction_type', $type)
            ->toBase()
            ->groupBy('finance_category_id')
            ->selectRaw('finance_category_id, SUM(amount) as total')
            ->orderByDesc('total')
            ->get();
        $names = FinanceCategory::whereIn('id', $rows->pluck('finance_category_id')->filter())->get()->keyBy('id');

        return $rows->map(fn (object $row): array => [
            'key' => $row->finance_category_id ? (int) $row->finance_category_id : null,
            'label' => $names->get($row->finance_category_id)?->localized_name,
            'count' => round((float) $row->total, 2),
        ])->values()->all();
    }

    /**
     * Entries per caisse, most used first, labelled like the Caisses page.
     *
     * @return list<array{key: int|null, label: string, count: int}>
     */
    private function accountStats(Request $request): array
    {
        $rows = $this->statsQuery($request, 'finance_account_id')
            ->toBase()
            ->groupBy('finance_account_id')
            ->selectRaw('finance_account_id, COUNT(*) as total')
            ->orderByDesc('total')
            ->get();
        $accounts = FinanceAccount::query()
            ->with(['branch:id,name,name_ar,name_fr,name_en', 'category:id,name,name_ar,name_fr,name_en'])
            ->whereIn('id', $rows->pluck('finance_account_id')->filter())
            ->get()->keyBy('id');

        return $rows->map(fn (object $row): array => [
            'key' => $row->finance_account_id ? (int) $row->finance_account_id : null,
            'label' => FinanceAccountLabel::for($accounts->get($row->finance_account_id)),
            'count' => (int) $row->total,
        ])->values()->all();
    }

    /**
     * Seasons the filter offers: every fiscal-year row plus the current
     * season, newest first, labelled "2026/2027".
     *
     * @return list<array{value: string, label: string}>
     */
    private function seasonOptions(): array
    {
        return FiscalYear::query()->pluck('year')
            ->map(fn ($year) => (int) $year)
            ->push(FiscalPeriod::currentKey())
            ->unique()->sortDesc()->values()
            ->map(fn (int $key) => ['value' => (string) $key, 'label' => FiscalPeriod::label($key)])
            ->all();
    }
```

`store` and `update` keep writing `category = Str::slug(name, '_')`. `TransactionFinanceCategoryTest` asserts it, and the slug is never displayed.

- [ ] **Step 6: Run tests**

Run: `php artisan test --filter="TransactionsIndexTest|ModuleStatsTest|TransactionFinanceCategoryTest|ListMultiSelectFiltersTest|TransactionPlayerLookupTest|TransactionTitleFlowTest|FinanceCategoryTranslationTest|CashRegisterManagementTest|ClosedSeasonLockTest|TransactionLabelsTest"`
Expected: PASS. Any other test that lists transactions dated outside 2026/27 (check against `FiscalPeriod::currentKey()` at run time) gets `'fiscal_year' => 'all'` in its query; keep its assertions.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/TransactionController.php app/Services/Dashboard/ModuleStats.php lang/ar/pagination.php lang/fr/pagination.php tests/Feature/TransactionsIndexTest.php tests/Feature/TransactionFinanceCategoryTest.php tests/Feature/ListMultiSelectFiltersTest.php tests/Feature/Dashboard/ModuleStatsTest.php
git commit -m "feat(transactions): list opens on the current season; strip and statistics follow the filters; page size; no hidden filters

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Form defaults — the resolver's caisse, and a locked account on edit

**Files:**
- Modify: `app/Http/Controllers/TransactionController.php` (`formOptions`, :355-390)
- Test: `tests/Feature/TransactionFormOptionsTest.php` (new)

**Interfaces — Produces** (Create/Edit props):
- `defaultFinanceAccountId` (int|null): `DefaultRegisterResolver::for(null, null)` (the server default for an entry without a player), else the first selectable account; never the Fonds Initial.
- `lockedAccount` (object|null, Edit only): the entry's own account when the pickers hide it (Fonds Initial, inactive), with `branch` and `category` for `accountLabel`.
- `financeCategories` on Edit also includes the entry's own category when it is inactive.

- [ ] **Step 1: Failing tests** — `tests/Feature/TransactionFormOptionsTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionFormOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function props(string $route, array $params = []): array
    {
        return $this->actingAs(User::factory()->admin()->create())
            ->get(route($route, $params))->assertOk()->viewData('page')['props'];
    }

    private function fund(): FinanceAccount
    {
        return FinanceAccount::where('is_opening_fund', true)->first()
            ?? FinanceAccount::create(['name' => 'Opening Fund', 'type' => 'cash', 'is_opening_fund' => true, 'is_active' => true, 'currency' => 'DZD', 'sort_order' => -1]);
    }

    #[Test]
    public function a_new_entry_starts_on_the_club_cash_the_resolver_picks(): void
    {
        $this->fund();
        // Seeded by create_finance_tables.
        $cash = FinanceAccount::whereNull('branch_id')->where('name', 'Cash')->firstOrFail();

        $props = $this->props('transactions.create');

        $this->assertSame($cash->id, $props['defaultFinanceAccountId']);
        $this->assertNull($props['lockedAccount']);
    }

    #[Test]
    public function without_a_club_cash_the_first_caisse_listed_is_the_default_never_the_opening_fund(): void
    {
        $fund = $this->fund();
        FinanceAccount::whereNull('branch_id')->where('name', 'Cash')->update(['is_active' => false]);
        $branch = Branch::create(['name' => 'Football']);

        $props = $this->props('transactions.create');

        $this->assertSame($branch->treasury->id, $props['defaultFinanceAccountId']);
        $this->assertNotSame($fund->id, $props['defaultFinanceAccountId']);
    }

    #[Test]
    public function editing_an_entry_on_the_opening_fund_keeps_it_visible_and_locked(): void
    {
        $fund = $this->fund();
        $tx = Transaction::create([
            'title' => 'Avant', 'amount' => 100, 'transaction_type' => 'income', 'category' => 'donation',
            'status' => 'Paid', 'transaction_date' => now(), 'finance_account_id' => $fund->id,
        ]);

        $props = $this->props('transactions.edit', $tx);

        $this->assertSame($fund->id, $props['lockedAccount']['id']);
        $this->assertTrue($props['lockedAccount']['is_opening_fund']);
        $this->assertFalse(collect($props['financeAccounts'])->contains('id', $fund->id));
    }

    #[Test]
    public function editing_keeps_the_entrys_inactive_category_selectable(): void
    {
        $old = FinanceCategory::create(['type' => 'income', 'name' => 'Old', 'is_active' => false]);
        $tx = Transaction::create([
            'title' => 'Old one', 'amount' => 100, 'transaction_type' => 'income', 'category' => 'old',
            'finance_category_id' => $old->id, 'status' => 'Paid', 'transaction_date' => now(),
        ]);

        $this->assertTrue(collect($this->props('transactions.edit', $tx)['financeCategories'])->contains('id', $old->id));
        $this->assertFalse(collect($this->props('transactions.create')['financeCategories'])->contains('id', $old->id));
    }
}
```

- [ ] **Step 2: Run** `php artisan test --filter=TransactionFormOptionsTest` — expect FAIL (missing props; inactive category absent).

- [ ] **Step 3: Implement** — in `formOptions()`:

Replace the `financeCategories` entry with:

```php
            // Active categories, plus the entry's own one if it was deactivated since.
            'financeCategories' => FinanceCategory::query()
                ->where(fn (Builder $q) => $q->where('is_active', true)
                    ->when($transaction?->finance_category_id, fn (Builder $q, $id) => $q->orWhere('id', $id)))
                ->orderBy('type')->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'type', 'name', 'name_ar', 'name_fr', 'name_en', 'color']),
```

Replace the `'financeAccounts' => $financeAccounts,` line with:

```php
            'financeAccounts' => $financeAccounts,
            // Where a new entry starts: the server's own default for an entry
            // without a player (DefaultRegisterResolver), else the first caisse
            // listed. The pickers never list the Fonds Initial.
            'defaultFinanceAccountId' => $registers->for(null, null)?->id ?? $financeAccounts->first()?->id,
            // An entry booked on an account the pickers hide (the Fonds Initial,
            // an inactive caisse) keeps showing it — read-only — instead of a blank select.
            'lockedAccount' => $transaction?->finance_account_id && ! $financeAccounts->contains('id', $transaction->finance_account_id)
                ? FinanceAccount::query()
                    ->with(['branch:id,name,name_ar,name_fr,name_en', 'category:id,name,name_ar,name_fr,name_en'])
                    ->find($transaction->finance_account_id, ['id', 'branch_id', 'category_id', 'name', 'type', 'is_treasury', 'is_opening_fund'])
                : null,
```

- [ ] **Step 4: Run** `php artisan test --filter="TransactionFormOptionsTest|TransactionTitleFlowTest|TransactionFinanceCategoryTest|CashRegisterManagementTest"` — PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/TransactionController.php tests/Feature/TransactionFormOptionsTest.php
git commit -m "feat(transactions): form starts on the resolver's caisse; editing keeps a hidden account and an inactive category visible

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Dashboard Finance tab — labelled accounts, Fonds Initial hidden

**Files:**
- Modify: `app/Services/Dashboard/FinanceStats.php` (`accounts` :202-231, `transfers` :234-280)
- Modify: `resources/js/Pages/Dashboard/Partials/FinanceTab.vue` (accounts list :193-212, transfers list :235-246, `tileMeta` :46)
- Test: `tests/Feature/Dashboard/FinanceStatsTest.php`

**Interfaces — Produces:** each account row is `{id, name, label, branch, is_treasury, opening, current}`. Each recent transfer is `{id, from, to, amount, date}`, where `from` and `to` are labels.

- [ ] **Step 1: Failing tests** (append to `FinanceStatsTest`; add `use App\Models\FinanceTransfer;` if missing)

```php
    #[Test]
    public function accounts_are_labelled_like_the_caisses_page_and_an_empty_opening_fund_is_hidden(): void
    {
        app()->setLocale('fr');
        $branch = Branch::create(['name' => 'Football']);
        $fund = FinanceAccount::where('is_opening_fund', true)->first()
            ?? $this->account(['name' => 'Opening Fund', 'is_opening_fund' => true]);
        app(FinanceService::class)->recomputeAccountBalances();

        $accounts = collect($this->finance()['accounts']);

        $this->assertNull($accounts->firstWhere('id', $fund->id));
        $this->assertSame('Coffre — Football', $accounts->firstWhere('id', $branch->treasury->id)['label']);
        $this->assertSame('Caisse du club', $accounts->firstWhere('name', 'Cash')['label']);
    }

    #[Test]
    public function an_opening_fund_still_holding_money_stays_listed(): void
    {
        app()->setLocale('fr');
        $fund = FinanceAccount::where('is_opening_fund', true)->first()
            ?? $this->account(['name' => 'Opening Fund', 'is_opening_fund' => true]);
        $fund->update(['opening_balance' => 500]);
        app(FinanceService::class)->recomputeAccountBalances();

        $row = collect($this->finance()['accounts'])->firstWhere('id', $fund->id);

        $this->assertNotNull($row);
        $this->assertSame('Fonds Initial', $row['label']);
    }

    #[Test]
    public function recent_transfers_name_both_accounts(): void
    {
        app()->setLocale('fr');
        $branch = Branch::create(['name' => 'Football']);
        $cash = FinanceAccount::whereNull('branch_id')->where('name', 'Cash')->firstOrFail();
        FinanceTransfer::create(['from_account_id' => $cash->id, 'to_account_id' => $branch->treasury->id, 'amount' => 50, 'transfer_date' => '2026-05-03']);

        $recent = $this->finance()['transfers']['recent'][0];

        $this->assertSame('Caisse du club', $recent['from']);
        $this->assertSame('Coffre — Football', $recent['to']);
        $this->assertSame('2026-05-03', $recent['date']);
    }
```

- [ ] **Step 2: Run** `php artisan test --filter=FinanceStatsTest` — expect FAIL (no `label`, fund listed, raw transfer names).

- [ ] **Step 3: Implement** (add `use App\Models\FinanceAccount; use App\Models\FinanceTransfer; use App\Support\FinanceAccountLabel;`)

```php
    /**
     * Active accounts, labelled like the Caisses page. The Fonds Initial is
     * bookkeeping rather than a place money sits: listed only while it still
     * holds some, so no money disappears from view.
     */
    private function accounts(DashboardFilters $filters): array
    {
        return FinanceAccount::query()
            ->with(['branch:id,name,name_ar,name_fr,name_en', 'category:id,name,name_ar,name_fr,name_en'])
            ->where('is_active', true)
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->orderByDesc('is_treasury')->orderBy('sort_order')->orderBy('id')
            ->get()
            ->reject(fn (FinanceAccount $a): bool => $a->is_opening_fund && abs((float) $a->current_balance) < 0.005)
            ->map(fn (FinanceAccount $a): array => [
                'id' => $a->id,
                'name' => (string) $a->name,
                'label' => FinanceAccountLabel::for($a),
                'branch' => $a->branch?->localized_name,
                'is_treasury' => (bool) $a->is_treasury,
                'opening' => round((float) $a->opening_balance, 2),
                'current' => round((float) $a->current_balance, 2),
            ])
            ->values()
            ->all();
    }
```

In `transfers()`, keep `$query` and `$totals`. Replace the `$recent` block with:

```php
        $recentIds = (clone $query)
            ->orderByDesc('finance_transfers.transfer_date')
            ->orderByDesc('finance_transfers.id')
            ->limit(5)
            ->pluck('finance_transfers.id');

        $labelled = ['branch:id,name,name_ar,name_fr,name_en', 'category:id,name,name_ar,name_fr,name_en'];
        $recent = FinanceTransfer::query()
            ->with(['fromAccount' => fn ($q) => $q->with($labelled), 'toAccount' => fn ($q) => $q->with($labelled)])
            ->whereIn('id', $recentIds)
            ->orderByDesc('transfer_date')->orderByDesc('id')
            ->get()
            ->map(fn (FinanceTransfer $tr): array => [
                'id' => $tr->id,
                'from' => FinanceAccountLabel::for($tr->fromAccount),
                'to' => FinanceAccountLabel::for($tr->toAccount),
                'amount' => round((float) $tr->amount, 2),
                'date' => $tr->transfer_date?->toDateString() ?? '',
            ])
            ->values()
            ->all();
```

`FinanceTab.vue`:
- import `useFormatDate` and `const { formatDate } = useFormatDate();`.
- Account row: replace the inner `<div class="flex min-w-0 items-center gap-2">…</div>` (name, treasury badge, branch span) with `<span class="min-w-0 truncate text-sm font-medium">{{ account.label || account.name }}</span>`. The label already says "coffre" and names the branch. Remove the `Badge` import if no other use remains.
- Transfer row: `<p class="truncate text-sm">{{ transfer.from }} <Icon name="arrow" class="mx-1 inline size-3.5 align-[-2px] rtl:rotate-180" /> {{ transfer.to }}</p>` and `<p class="text-xs text-muted-foreground">{{ formatDate(transfer.date) }}</p>`. The arrow badge icon at :238 also gets `rtl:rotate-180`.
- `tileMeta`: `` `${tile.meta.label} · ${formatDate(tile.meta.date)}` ``.
- Delete `dashboard.fin_treasury` from the three catalogs if `grep -rn "fin_treasury" resources/js app` shows no use.

- [ ] **Step 4: Run** `php artisan test --filter="FinanceStatsTest|DashboardPageTest"`, `npm run build`, `npm run i18n:check` — PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Dashboard/FinanceStats.php resources/js/Pages/Dashboard/Partials/FinanceTab.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Dashboard/FinanceStatsTest.php
git commit -m "fix(dashboard): finance tab names accounts like the Caisses page and hides an empty Fonds Initial

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: The i18n check also reads the templates

**Files:**
- Modify: `scripts/i18n-check.mjs`

The current check resolves keys through `t()` with `fallbackLocale: 'en'`, so a key missing only in ar or fr passes. The new part reads each catalog directly.

- [ ] **Step 1: Implement.** Change the imports to:

```js
import { createI18n } from 'vue-i18n';
import { readFileSync, readdirSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { STATUS_KEYS } from '../resources/js/lib/statusLabels.js';
```

Insert after the existing per-locale loop, before `if (failures)`:

```js
// Every literal t('key') / $t("key") in the Vue and JS sources must exist in
// each catalog itself. Read the files directly: through t() the English
// fallback would hide a key missing in ar or fr. Keys built at runtime
// (template literals, concatenation) cannot be checked; they are listed.
const sourceRoot = fileURLToPath(new URL('../resources/js', import.meta.url));
const LITERAL = /(?<![\w.$])\$?t\(\s*(['"])([^'"\\\n]+)\1\s*(?=[,)])/g;
const DYNAMIC = /(?<![\w.$])\$?t\(\s*(`[^`]*`|['"][^'"\n]*['"]\s*\+)/g;

const sources = [];
const walkSources = (dir) => {
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, e.name);
    if (e.isDirectory()) {
      if (e.name !== 'i18n') walkSources(p);
    } else if (/\.(vue|js)$/.test(e.name)) sources.push(p);
  }
};
walkSources(sourceRoot);

const templateKeys = new Map(); // key -> first file using it
const dynamicUses = [];
for (const file of sources) {
  const src = readFileSync(file, 'utf8');
  for (const m of src.matchAll(LITERAL)) if (!templateKeys.has(m[2])) templateKeys.set(m[2], relative(sourceRoot, file));
  for (const m of src.matchAll(DYNAMIC)) dynamicUses.push(`${relative(sourceRoot, file)}: ${m[1]}`);
}

for (const locale of ['ar', 'fr', 'en']) {
  for (const [key, file] of templateKeys) {
    if (!Object.hasOwn(messages[locale], key)) {
      console.error(`  [${locale}] missing: ${key}  (${file})`);
      failures++;
    }
  }
}

if (dynamicUses.length) {
  console.warn(`! ${dynamicUses.length} runtime-built key(s) not checked:`);
  for (const use of dynamicUses) console.warn(`    ${use}`);
}
```

Change the last line to:

```js
console.log(`✓ all ${keys.size} flash keys, ${statusKeys.size} status keys and ${templateKeys.size} template keys resolve in ar/fr/en`);
```

- [ ] **Step 2: Prove it bites.** Create the throwaway file `resources/js/zz-probe.js` with `t('zz_probe_missing');`. Run `npm run i18n:check`. Expected: exit 1 with `[ar] missing: zz_probe_missing`, plus fr and en. Delete the file.
- [ ] **Step 3: Run** `npm run i18n:check` — expected ✓ (the scan finds 0 missing keys today), with the runtime-built list as a warning.
- [ ] **Step 4: Commit**

```bash
git add scripts/i18n-check.mjs
git commit -m "chore(i18n): the check fails on a literal template key missing in any language

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Shared UI — ConfirmModal, Pagination, stat tiles, status colours, follow-ups

**Files:**
- Modify: `resources/js/Components/ConfirmModal.vue`, `Pagination.vue`, `StatBars.vue`, `StatCard.vue`, `Dashboard/StatTile.vue`, `resources/js/lib/statusLabels.js`
- Modify: `resources/js/Pages/Finance/Settings.vue` (confirm variant), `Finance/Registers.vue` (:141 chip), `Finance/Index.vue` (:119-122), `Players/Show.vue` (:248-253, :423), `Subscriptions/Show.vue` (:98-103)
- Modify: i18n catalogs

- [ ] **Step 1: i18n key**

| key | en | fr | ar |
|---|---|---|---|
| `currency_symbol` | DZD | DA | د.ج |

- [ ] **Step 2: `ConfirmModal.vue`** — full replacement:

```vue
<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Modal from './Modal.vue';
import DangerButton from './DangerButton.vue';
import PrimaryButton from './PrimaryButton.vue';
import SecondaryButton from './SecondaryButton.vue';

const { t } = useI18n();

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: '' },
    message: { type: String, default: '' },
    confirmLabel: { type: String, default: '' },
    // e.g. "Keep meeting" — a plain "Cancel" next to "Cancel meeting" misleads.
    cancelLabel: { type: String, default: '' },
    // Disables the confirm button while the request runs.
    busy: { type: Boolean, default: false },
    // 'danger' (default) for what destroys something; 'primary' for a confirm
    // that loses nothing, e.g. closing or reopening a season.
    variant: { type: String, default: 'danger' },
});

const emit = defineEmits(['confirm', 'cancel']);

// Pages clear the state behind title/message the moment the dialog is
// answered, while it is still fading out. Show the last copy given while
// open, so the closing box never goes blank.
const copy = ref({});
watch(
    () => [props.show, props.title, props.message, props.confirmLabel, props.cancelLabel, props.variant],
    () => {
        if (!props.show) return;
        copy.value = {
            title: props.title,
            message: props.message,
            confirmLabel: props.confirmLabel,
            cancelLabel: props.cancelLabel,
            variant: props.variant,
        };
    },
    { immediate: true },
);
</script>

<template>
    <Modal :show="show" @close="emit('cancel')" max-width="md">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                {{ copy.title || t('confirm_action') }}
            </h3>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
                {{ copy.message || t('are_you_sure') }}
            </p>
            <!-- Optional extra fields, e.g. a required reason. -->
            <slot />
            <div class="mt-6 flex flex-wrap justify-end gap-3">
                <SecondaryButton type="button" @click="emit('cancel')">{{ copy.cancelLabel || t('cancel') }}</SecondaryButton>
                <PrimaryButton v-if="copy.variant === 'primary'" type="button" :disabled="busy" @click="emit('confirm')">{{ copy.confirmLabel || t('confirm') }}</PrimaryButton>
                <DangerButton v-else type="button" :disabled="busy" @click="emit('confirm')">{{ copy.confirmLabel || t('delete') }}</DangerButton>
            </div>
        </div>
    </Modal>
</template>
```

`Finance/Settings.vue`: in `pendingCopy`, add `variant: 'primary'` to the `close` and `reopen` cases and `variant: 'danger'` to the others (and to the empty return). Pass `:variant="pendingCopy.variant"` to `<ConfirmModal>`.

- [ ] **Step 3: `Pagination.vue`** — full replacement. It also accepts a bare `links` array, which `Games/Index.vue` passes and which previously never rendered.

```vue
<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';

/**
 * Page links under a list: a Laravel paginator, or just its `links` array.
 * The first and last links are "previous" and "next". They show as arrows
 * pointing the way the page reads (mirrored in Arabic), with the server's
 * translated word (lang/<locale>/pagination.php) beside them from sm up.
 * Everything wraps instead of overflowing a phone.
 */
const props = defineProps({
    links: { type: [Object, Array], required: true },
});

const { t } = useI18n();

const all = computed(() => (Array.isArray(props.links) ? props.links : props.links?.links ?? []));
const meta = computed(() => (Array.isArray(props.links) ? null : props.links));
// Laravel always sends [previous, …pages, next]: three entries = one page.
const visible = computed(() => (meta.value ? meta.value.last_page > 1 : all.value.length > 3));
const previous = computed(() => all.value[0] ?? null);
const next = computed(() => all.value[all.value.length - 1] ?? null);
const pages = computed(() => all.value.slice(1, -1));
// "&laquo; Précédent" -> "Précédent": the arrow is drawn, the word is kept.
const word = (link, fallback) => String(link?.label ?? '').replace(/&laquo;|&raquo;|[«»]/g, '').trim() || fallback;

const base = 'inline-flex h-8 min-w-8 items-center justify-center gap-1 rounded-md px-2.5 text-sm font-medium transition-colors';
const idle = 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800';
const off = 'text-slate-300 dark:text-slate-600';
</script>

<template>
    <nav v-if="visible" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-slate-200 px-1 py-3 dark:border-slate-800">
        <p v-if="meta" class="text-sm text-slate-500 dark:text-slate-400">
            {{ t('showing') }} <span class="tabular-nums">{{ meta.from }}–{{ meta.to }}</span>
            {{ t('of') }} <span class="tabular-nums">{{ meta.total }}</span> {{ t('results') }}
        </p>
        <div class="flex flex-wrap items-center gap-1">
            <Link v-if="previous?.url" :href="previous.url" preserve-scroll :class="[base, idle]" :aria-label="word(previous, t('previous'))">
                <Icon name="back" class="rtl:rotate-180" />
                <span class="hidden sm:inline">{{ word(previous, t('previous')) }}</span>
            </Link>
            <span v-else :class="[base, off]" aria-hidden="true"><Icon name="back" class="rtl:rotate-180" /></span>

            <template v-for="(link, i) in pages" :key="`${i}-${link.label}`">
                <Link v-if="link.url" :href="link.url" preserve-scroll :aria-current="link.active ? 'page' : undefined"
                    :class="[base, 'tabular-nums', link.active ? 'bg-primary-600 text-white hover:bg-primary-600' : idle]">{{ link.label }}</Link>
                <span v-else :class="[base, off]">{{ link.label }}</span>
            </template>

            <Link v-if="next?.url" :href="next.url" preserve-scroll :class="[base, idle]" :aria-label="word(next, t('next'))">
                <span class="hidden sm:inline">{{ word(next, t('next')) }}</span>
                <Icon name="back" class="rotate-180 rtl:rotate-0" />
            </Link>
            <span v-else :class="[base, off]" aria-hidden="true"><Icon name="back" class="rotate-180 rtl:rotate-0" /></span>
        </div>
    </nav>
</template>
```

- [ ] **Step 4: Money tiles shrink and show the currency**

`Dashboard/StatTile.vue`:
- add `import { useI18n } from 'vue-i18n';` and `const { t } = useI18n();`.
- add `min-w-0` to the root `<component>` class.
- the value `<p>` class becomes `mt-2 text-2xl font-semibold leading-tight tracking-tight tabular-nums [overflow-wrap:anywhere] sm:text-[1.75rem] sm:leading-none`. Keep the `:class` binding.
- its content becomes `{{ display }}<span v-if="format === 'money' && !isEmpty" class="ms-1 text-sm font-medium text-muted-foreground">{{ t('currency_symbol') }}</span>`.

`StatCard.vue`:
- add `import { useI18n } from 'vue-i18n'; const { t } = useI18n();`.
- add the prop `currency: { type: Boolean, default: false }`, commented `// money value: the club currency (i18n currency_symbol) follows it`.
- add `min-w-0` to the `<article>` class.
- the value `<p>` class becomes `figure mt-3 text-2xl font-extrabold [overflow-wrap:anywhere] sm:text-3xl`.
- the suffix becomes `<span v-if="suffix || currency" class="ms-1 text-base font-medium text-slate-400">{{ suffix || t('currency_symbol') }}</span>`.

`Finance/Index.vue` :119-122: add `currency` to the four `StatCard`s.

`Players/Show.vue` :423: replace the literal `DZD` with `{{ t('currency_symbol') }}`.

`StatBars.vue`:
- add the prop `format: { type: String, default: 'number' }`, commented `// 'money' when count holds amounts (Transactions): shown as money`.
- `import { useFormatMoney } from '@/Composables/useFormatMoney';` and `const { formatMoney } = useFormatMoney();`, plus `const shown = (n) => (props.format === 'money' ? formatMoney(n) : n);`.
- in the template use `{{ shown(selectedTotal) }}`, `{{ shown(total) }}` and `{{ shown(stat.count) }}`.

- [ ] **Step 5: One payment-status colour everywhere**

Append to `resources/js/lib/statusLabels.js`:

```js
/** Badge colour of a payment status, the same on every screen. */
export function paymentStatusColor(code) {
    const key = String(code ?? '').toLowerCase();
    if (key === 'paid') return 'emerald';
    if (key === 'partial') return 'amber';
    if (key === 'exempt') return 'slate';
    return 'rose';
}
```

`Players/Show.vue` and `Subscriptions/Show.vue`: delete the local `statusColor` and import `paymentStatusColor` from `@/lib/statusLabels`. Replace `statusColor(` with `paymentStatusColor(` at its call sites (:488 and :276).

- [ ] **Step 6: Caisses page duplicate chip (follow-up c)** — `Registers.vue` :141. The chip's `v-if` becomes `c.category && caisseName(c) !== categoryLabel(c.category)`. If `isLegacyAutoName` is no longer used elsewhere in the file, drop it from the destructure at :29.

- [ ] **Step 7: Build and test**

Run: `npm run build`, `npm run i18n:check`, `php artisan test --filter="Finance|Dashboard|Players|Subscription"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add resources/js/Components/ConfirmModal.vue resources/js/Components/Pagination.vue resources/js/Components/StatBars.vue resources/js/Components/StatCard.vue resources/js/Components/Dashboard/StatTile.vue resources/js/lib/statusLabels.js resources/js/Pages/Finance/Settings.vue resources/js/Pages/Finance/Registers.vue resources/js/Pages/Finance/Index.vue resources/js/Pages/Players/Show.vue resources/js/Pages/Subscriptions/Show.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(ui): confirm dialog keeps its text and has a primary variant; RTL pagination; money tiles wrap with the club currency

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: One finance-categories manager (Transactions and Settings)

**Files:**
- Rewrite: `resources/js/Components/CategoryManager.vue`
- Modify: `resources/js/Pages/Finance/Settings.vue` (remove the "Chart of accounts" section :192-231 and its script :22-23, :80-85, the `delete-category` branch in `pendingCopy` and `runPending`; add a card and the manager)
- Modify: i18n catalogs

**Interfaces — Consumes:** Task 4 routes and fields; `categories` with `transactions_count`, `is_system`, `is_active`, `name`, `name_ar`, `name_fr`, `name_en`, `color`, `type`. Both Settings (`FinanceController::settings` already sends `withCount('transactions')`) and Transactions (Task 5) provide them.

- [ ] **Step 1: i18n keys**

| key | en | fr | ar |
|---|---|---|---|
| `fincat.title` | Finance categories | Catégories financières | فئات المداخيل والمصاريف |
| `fincat.subtitle` | Sort income and expenses so the totals are easy to read. | Classez recettes et dépenses pour lire facilement les totaux. | صنّف المداخيل والمصاريف لتسهل قراءة المجاميع. |
| `fincat.add` | Add category | Ajouter une catégorie | إضافة فئة |
| `fincat.edit` | Edit category | Modifier la catégorie | تعديل الفئة |
| `fincat.name_ar` | Name in Arabic | Nom en arabe | الاسم بالعربية |
| `fincat.name_fr` | Name in French | Nom en français | الاسم بالفرنسية |
| `fincat.name_en` | Name in English | Nom en anglais | الاسم بالإنجليزية |
| `fincat.names_hint` | Fill in at least one language. A language left empty shows one of the others. | Remplissez au moins une langue. Une langue laissée vide affiche l'une des autres. | املأ لغة واحدة على الأقل. اللغة المتروكة فارغة تعرض الاسم بإحدى اللغات الأخرى. |
| `fincat.color` | Colour | Couleur | اللون |
| `fincat.active` | Active (offered when adding a transaction) | Active (proposée à l'ajout d'une transaction) | نشطة (تظهر عند إضافة معاملة) |
| `fincat.built_in` | Built-in | Intégrée | أساسية |
| `fincat.inactive` | Inactive | Inactive | غير نشطة |
| `fincat.used_by` | {count} transaction(s) | {count} transaction(s) | {count} معاملة |
| `fincat.delete_rule` | Built-in categories and categories already used cannot be deleted; deactivate them instead. | Les catégories intégrées ou déjà utilisées ne peuvent pas être supprimées ; désactivez-les plutôt. | لا يمكن حذف الفئات الأساسية أو المستعملة؛ يمكن تعطيلها بدلاً من ذلك. |
| `fincat.delete_title` | Delete this category? | Supprimer cette catégorie ? | حذف هذه الفئة؟ |
| `fincat.delete_message` | "{name}" will be removed, with its budget lines. | « {name} » sera supprimée, avec ses lignes de budget. | سيتم حذف «{name}» مع أسطر ميزانيتها. |
| `fincat.empty` | No category yet. | Aucune catégorie pour l'instant. | لا توجد فئات بعد. |
| `fincat.manage` | Manage categories | Gérer les catégories | إدارة الفئات |
| `fincat.settings_hint` | Income and expense categories used by transactions and budgets. | Catégories de recettes et de dépenses utilisées par les transactions et les budgets. | فئات المداخيل والمصاريف المستعملة في المعاملات والميزانيات. |

- [ ] **Step 2: `CategoryManager.vue`** — full replacement:

```vue
<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useCan } from '@/Composables/useCan';

/**
 * The one place finance categories are managed — opened from the
 * Transactions "more" menu and from Finance settings.
 *
 * One name field per language. Older categories hold their only name in
 * `name`: Arabic text is shown in the Arabic field; Latin text in the English
 * field for built-in categories (seeded in English) and in the French field
 * for ones the club typed. The server keeps a base name (English, else French,
 * else Arabic) that fills any language left empty
 * (FinanceCategoryController). Edits go through a form, never into the
 * `categories` prop.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    // Every category, active or not, with transactions_count.
    categories: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const { t } = useI18n();
const { can } = useCan();

const ARABIC = /[\u0600-\u06FF]/;
const DEFAULT_COLOR = { income: '#10b981', expense: '#ef4444' };

const languages = computed(() => [
    { key: 'name_ar', label: t('fincat.name_ar'), dir: 'rtl' },
    { key: 'name_fr', label: t('fincat.name_fr'), dir: 'ltr' },
    { key: 'name_en', label: t('fincat.name_en'), dir: 'ltr' },
]);

const groups = computed(() => ['income', 'expense'].map((type) => ({
    type,
    items: props.categories.filter((c) => c.type === type),
})));

const label = (c) => c.localized_name || c.name;
const used = (c) => Number(c.transactions_count) || 0;
const deletable = (c) => !c.is_system && used(c) === 0 && can('finance', 'delete');

// --- Add / edit: one form; `editing` is the category, null for a new one ---
const formOpen = ref(false);
const editing = ref(null);
const form = useForm({ type: 'income', name_ar: '', name_fr: '', name_en: '', color: DEFAULT_COLOR.income, is_active: true });

function fill(values) {
    Object.entries(values).forEach(([key, value]) => { form[key] = value; });
    form.clearErrors();
}

function namesOf(c) {
    const base = c.name || '';
    const arabic = ARABIC.test(base);
    return {
        name_ar: c.name_ar || (arabic ? base : ''),
        name_fr: c.name_fr || (!arabic && !c.is_system ? base : ''),
        name_en: c.name_en || (!arabic && c.is_system ? base : ''),
    };
}

function openAdd(type) {
    editing.value = null;
    fill({ type, name_ar: '', name_fr: '', name_en: '', color: DEFAULT_COLOR[type], is_active: true });
    formOpen.value = true;
}

function openEdit(c) {
    editing.value = c;
    fill({ type: c.type, ...namesOf(c), color: c.color || DEFAULT_COLOR[c.type], is_active: c.is_active !== false });
    formOpen.value = true;
}

function save() {
    // preserveState: the page keeps this dialog (and its filters) open across the reload.
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => { formOpen.value = false; } };
    if (editing.value) form.put(route('finance.categories.update', editing.value.id), options);
    else form.post(route('finance.categories.store'), options);
}

// --- Delete ---
const deleting = ref(null);
function confirmDelete() {
    const c = deleting.value;
    deleting.value = null;
    router.delete(route('finance.categories.destroy', c.id), { preserveScroll: true, preserveState: true });
}

function close() {
    formOpen.value = false;
    emit('close');
}
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="close">
        <div class="p-5 sm:p-6">
            <div class="mb-4 flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('fincat.title') }}</h2>
                    <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ t('fincat.subtitle') }}</p>
                </div>
                <IconButton icon="close" :label="t('close')" variant="ghost" @click="close" />
            </div>

            <!-- Add / edit -->
            <form v-if="formOpen" class="space-y-4 rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200 dark:bg-slate-800/50 dark:ring-slate-700" @submit.prevent="save">
                <p class="text-sm font-bold text-slate-700 dark:text-slate-200">
                    {{ editing ? t('fincat.edit') : t('fincat.add') }} · {{ form.type === 'income' ? t('income') : t('expense') }}
                </p>
                <InputError :message="form.errors.name" />
                <div class="grid gap-3 sm:grid-cols-3">
                    <label v-for="l in languages" :key="l.key" class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                        {{ l.label }}
                        <input v-model="form[l.key]" :dir="l.dir" maxlength="120"
                            class="mt-1 w-full rounded-lg border-slate-300 bg-white py-1.5 text-sm text-slate-900 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" />
                        <InputError :message="form.errors[l.key]" class="mt-1" />
                    </label>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('fincat.names_hint') }}</p>
                <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input v-model="form.color" type="color" class="h-8 w-10 cursor-pointer rounded border-0 bg-transparent p-0" />
                        {{ t('fincat.color') }}
                    </label>
                    <label v-if="editing" class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                        {{ t('fincat.active') }}
                    </label>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <SecondaryButton type="button" @click="formOpen = false">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton type="submit" :disabled="form.processing">{{ t('save') }}</PrimaryButton>
                </div>
            </form>

            <!-- Lists -->
            <div v-else class="grid gap-6 sm:grid-cols-2">
                <section v-for="g in groups" :key="g.type" class="min-w-0">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <h3 class="text-xs font-bold uppercase tracking-wide"
                            :class="g.type === 'income' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">
                            {{ g.type === 'income' ? t('income') : t('expense') }}
                        </h3>
                        <IconButton v-if="can('finance', 'add')" icon="plus" :label="t('fincat.add')" variant="primary" plain size="sm" @click="openAdd(g.type)" />
                    </div>
                    <p v-if="!g.items.length" class="py-4 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('fincat.empty') }}</p>
                    <ul v-else class="max-h-80 space-y-1.5 overflow-y-auto">
                        <li v-for="c in g.items" :key="c.id" class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                            <span class="h-3 w-3 shrink-0 rounded-full" :style="{ backgroundColor: c.color || '#94a3b8' }" aria-hidden="true"></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100" :class="{ 'opacity-60': c.is_active === false }">{{ label(c) }}</p>
                                <p class="flex flex-wrap gap-x-2 text-[11px] text-slate-500 dark:text-slate-400">
                                    <span v-if="used(c)">{{ t('fincat.used_by', { count: used(c) }) }}</span>
                                    <span v-if="c.is_system">{{ t('fincat.built_in') }}</span>
                                    <span v-if="c.is_active === false">{{ t('fincat.inactive') }}</span>
                                </p>
                            </div>
                            <IconButton v-if="can('finance', 'edit')" icon="pencil" :label="t('edit')" plain size="sm" @click="openEdit(c)" />
                            <IconButton v-if="deletable(c)" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleting = c" />
                        </li>
                    </ul>
                </section>
            </div>
            <p v-if="!formOpen" class="mt-4 text-xs text-slate-500 dark:text-slate-400">{{ t('fincat.delete_rule') }}</p>
        </div>
    </Modal>

    <ConfirmModal
        :show="!!deleting"
        :title="t('fincat.delete_title')"
        :message="deleting ? t('fincat.delete_message', { name: label(deleting) }) : ''"
        @confirm="confirmDelete"
        @cancel="deleting = null"
    />
</template>
```

- [ ] **Step 3: `Finance/Settings.vue`**
- Script:
  - `import CategoryManager from '@/Components/CategoryManager.vue';` and `const showCategories = ref(false);`.
  - Delete `income`/`expense` computed, `catForm`, `addCategory` and `deleteCategory`.
  - In `pendingCopy`, remove the default delete-category case; it returns `{ title: '', message: '', label: '', variant: 'danger' }` for an unknown action.
  - In `runPending`, the last `else` branch becomes `else if (p.action === 'delete-year')` and the category branch is removed.
  - Update the comment at :36 to `'close' | 'reopen' | 'delete-year'`.
- Template: replace the "Chart of accounts" `<section>` with:

```html
            <!-- Finance categories: one manager, shared with the Transactions page -->
            <section class="card flex flex-wrap items-center justify-between gap-3 p-5">
                <div class="min-w-0">
                    <p class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('fincat.title') }}</p>
                    <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ t('fincat.settings_hint') }}</p>
                </div>
                <IconButton icon="categories" :label="t('fincat.manage')" @click="showCategories = true" />
            </section>
```

  and before `</AuthenticatedLayout>`: `<CategoryManager :show="showCategories" :categories="categories" @close="showCategories = false" />`.
- Delete now-unused keys after `grep -rn "'<key>'" resources/js app` shows no use: `chart_of_accounts`, `income_categories`, `expense_categories`, `category_name`, `add_category`, `new_category`, `translations`, `category_in_use_hint`.

- [ ] **Step 4: Build and test** — `npm run build`, `npm run i18n:check`, `php artisan test --filter="Finance"` — PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/CategoryManager.vue resources/js/Pages/Finance/Settings.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(finance): one categories manager — names per language, colour, active, guarded delete; Settings opens it

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: The Transactions page

**Files:**
- Rewrite: `resources/js/Pages/Transactions/Index.vue`
- Modify: i18n catalogs

**Interfaces — Consumes:** Task 5 props; Task 9 components (`StatBars format="money"`, `Pagination`, `ConfirmModal`); Task 10 `CategoryManager`; `useFormatDate` (Task 1).

- [ ] **Step 1: i18n keys**

| key | en | fr | ar |
|---|---|---|---|
| `strip.tx_income` | Income | Recettes | المداخيل |
| `strip.tx_expense` | Expenses | Dépenses | المصاريف |
| `strip.tx_net` | Net balance | Solde net | الرصيد الصافي |
| `strip.tx_debts` | Members' debts (all) | Dettes des membres (toutes) | ديون الأعضاء (الكل) |
| `tx.all_seasons` | All seasons | Toutes les saisons | كل المواسم |
| `tx.season_option` | Season {season} | Saison {season} | موسم {season} |
| `tx.date_from` | From | Du | من |
| `tx.date_to` | To | Au | إلى |
| `tx.by_income_category` | Income by category | Recettes par catégorie | المداخيل حسب الفئة |
| `tx.by_expense_category` | Expenses by category | Dépenses par catégorie | المصاريف حسب الفئة |
| `tx.by_caisse` | Entries by caisse | Opérations par caisse | العمليات حسب الصندوق |
| `tx.entries` | entries | opérations | عملية |
| `tx.uncategorised` | Uncategorised | Sans catégorie | بدون فئة |
| `tx.no_caisse` | No caisse | Sans caisse | بدون صندوق |
| `tx.status_chip` | Status: {status} | Statut : {status} | الحالة: {status} |
| `tx.remove_filter` | Remove this filter | Retirer ce filtre | إزالة هذا المرشح |
| `tx.delete_title` | Delete this transaction? | Supprimer cette transaction ? | حذف هذه المعاملة؟ |
| `tx.delete_message` | It stops counting in the totals and the caisse balance, and stays in the activity history. | Elle ne compte plus dans les totaux ni dans le solde de la caisse, et reste dans l'historique d'activité. | لن تُحتسب بعد الآن في المجاميع ولا في رصيد الصندوق، وتبقى في سجل النشاط. |

- [ ] **Step 2: `Pages/Transactions/Index.vue`** — full replacement:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatStrip from '@/Components/Dashboard/StatStrip.vue';
import StatBars from '@/Components/StatBars.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import MultiSelectFilter from '@/Components/MultiSelectFilter.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Dropdown from '@/Components/Dropdown.vue';
import Icon from '@/Components/Icon.vue';
import IconButton from '@/Components/IconButton.vue';
import CategoryManager from '@/Components/CategoryManager.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { asList, useListFilters } from '@/Composables/useListFilters';
import { useBulkSelection } from '@/Composables/useBulkSelection';
import { useCan } from '@/Composables/useCan';
import { useFinanceAccountLabel } from '@/Composables/useFinanceAccountLabel';
import { useFormatDate } from '@/Composables/useFormatDate';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useStatusLabel } from '@/Composables/useStatusLabel';
import { readJson, writeJson } from '@/lib/safeStorage';

const props = defineProps({
    transactions: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    strip: { type: Array, default: () => [] },
    incomeByCategory: { type: Array, default: () => [] },
    expenseByCategory: { type: Array, default: () => [] },
    byAccount: { type: Array, default: () => [] },
    financeCategories: { type: Array, default: () => [] },
    financeAccounts: { type: Array, default: () => [] },
    seasons: { type: Array, default: () => [] },
    currentSeason: { type: Number, default: null },
});

const { t } = useI18n();
const { can } = useCan();
const { formatMoney } = useFormatMoney();
const { formatDate } = useFormatDate();
const { statusLabel } = useStatusLabel();
const { accountLabel } = useFinanceAccountLabel();

// --- Filters: each lives in the URL and has a control (or a chip) here ---
const search = ref(props.filters?.search || '');
const typeFilter = ref(asList(props.filters?.type));
const categoryFilter = ref(asList(props.filters?.finance_category_id));
const accountFilter = ref(asList(props.filters?.finance_account_id));
// Payment status has no menu — nearly every entry is Paid — but a link may
// carry it; it then shows as a removable chip below the filters.
const statusFilter = ref(asList(props.filters?.status));
// '' = the server default: the current season, or every season once a date
// range decides which days to show. Otherwise 'all' or one season key.
const seasonFilter = ref(asList(props.filters?.fiscal_year)[0] ?? '');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');
// Page size; empty = the server default (25). A view preference, not a filter.
const perPage = ref(props.filters?.per_page ? String(props.filters.per_page) : '');

const hasDates = computed(() => !!(dateFrom.value || dateTo.value));
const currentSeasonKey = computed(() => (props.currentSeason ? String(props.currentSeason) : ''));
// The select always names what the list shows, the default included.
const seasonSelect = computed({
    get: () => seasonFilter.value || (hasDates.value ? 'all' : currentSeasonKey.value),
    set: (value) => {
        const isDefault = hasDates.value ? value === 'all' : value === currentSeasonKey.value;
        seasonFilter.value = isDefault ? '' : value;
    },
});

const { params: filterParams, loading: filtering } = useListFilters('transactions.index', () => ({
    search: search.value,
    type: typeFilter.value,
    finance_category_id: categoryFilter.value,
    finance_account_id: accountFilter.value,
    status: statusFilter.value,
    fiscal_year: seasonFilter.value,
    date_from: dateFrom.value,
    date_to: dateTo.value,
    per_page: perPage.value,
}), { only: ['transactions', 'filters', 'strip', 'incomeByCategory', 'expenseByCategory', 'byAccount'] });

const hasActiveFilters = computed(() => Object.keys(filterParams.value).some((k) => k !== 'per_page'));

function clearFilters() {
    search.value = '';
    typeFilter.value = [];
    categoryFilter.value = [];
    accountFilter.value = [];
    statusFilter.value = [];
    seasonFilter.value = '';
    dateFrom.value = '';
    dateTo.value = '';
}

// A reload changes the height of the statistics above the filter bar, which
// would slide the bar (and the box being typed in). Measure before the new
// rows render and scroll back by the difference — as on Players.
const filterBar = ref(null);
let filterBarTop = null;
watch(() => props.transactions, () => {
    filterBarTop = filterBar.value?.getBoundingClientRect().top ?? null;
}, { flush: 'pre' });
watch(() => props.transactions, () => {
    const top = filterBar.value?.getBoundingClientRect().top;
    if (filterBarTop === null || top === undefined) return;
    const shift = top - filterBarTop;
    if (Math.abs(shift) >= 1) window.scrollBy({ top: shift, behavior: 'instant' });
}, { flush: 'post' });

// --- Filter options ---
const typeOptions = computed(() => [
    { value: 'income', label: t('income') },
    { value: 'expense', label: t('expense') },
]);
const categoryOptions = computed(() => ['income', 'expense'].flatMap((type) => props.financeCategories
    .filter((c) => c.type === type)
    .map((c) => ({ value: c.id, label: c.localized_name || c.name, group: type === 'income' ? t('income') : t('expense') }))));
const accountOptions = computed(() => props.financeAccounts.map((a) => ({ value: a.id, label: accountLabel(a) })));

// --- Statistics (folded state remembered per browser) ---
const STATS_KEY = 'transactions.stats';
const statsOpen = ref(readJson(STATS_KEY, true) !== false);
watch(statsOpen, (open) => writeJson(STATS_KEY, open));

const incomeChips = computed(() => props.incomeByCategory.map((r) => ({ key: r.key ?? '', label: r.label || t('tx.uncategorised'), count: r.count })));
const expenseChips = computed(() => props.expenseByCategory.map((r) => ({ key: r.key ?? '', label: r.label || t('tx.uncategorised'), count: r.count })));
const accountChips = computed(() => props.byAccount.map((r) => ({ key: r.key ?? '', label: r.label || t('tx.no_caisse'), count: r.count })));

// --- Header "more" menu ---
const FORMATS = ['xlsx', 'csv'];
const withFormat = (href, format) => {
    const url = new URL(href, window.location.origin);
    url.searchParams.set('format', format);
    return url.pathname + url.search;
};
// The export matches the current view (same filters).
const exportUrl = computed(() => route('transactions.export', filterParams.value));
const menuItem = 'flex w-full items-center gap-2.5 px-4 py-2 text-start text-sm text-slate-700 transition-colors hover:bg-slate-100 focus:bg-slate-100 focus:outline-none dark:text-slate-200 dark:hover:bg-slate-700 dark:focus:bg-slate-700';
const menuHeading = 'px-4 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500';
const menuIcon = 'text-base text-slate-400 dark:text-slate-500';
const fieldClass = 'h-9 w-full rounded-lg border-slate-300 bg-white py-1 text-sm text-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200';

const showCategories = ref(false);

const importInput = ref(null);
function onImport(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    router.post(route('transactions.import.store'), { file }, {
        forceFormData: true,
        onFinish: () => { if (importInput.value) importInput.value.value = ''; },
    });
}

// --- Rows ---
const isIncome = (tx) => tx.transaction_type === 'income';
const signedAmount = (tx) => `${isIncome(tx) ? '+' : '−'}${formatMoney(tx.amount)}`;
const amountClass = (tx) => (isIncome(tx) ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400');
const categoryName = (tx) => tx.finance_category?.localized_name || tx.finance_category?.name || t('tx.uncategorised');
const titleOf = (tx) => tx.display_title || categoryName(tx);

const canEdit = computed(() => can('transactions', 'edit'));
const canDelete = computed(() => can('transactions', 'delete'));

// --- Delete (one, or the selection) ---
const deleteId = ref(null);
function destroy() {
    const id = deleteId.value;
    deleteId.value = null;
    router.delete(route('transactions.destroy', id), { preserveScroll: true });
}

// The selection resets whenever the filters replace the rows.
const { selected, allSelected, toggleAll, toggleOne, clear: clearSelection } = useBulkSelection(
    computed(() => props.transactions.data),
    filterParams,
);
const bulkDeletePending = ref(false);
function bulkDestroy() {
    bulkDeletePending.value = false;
    router.post(route('transactions.bulkDestroy'), { ids: selected.value }, {
        preserveScroll: true,
        onSuccess: () => clearSelection(),
    });
}
</script>

<template>
    <Head :title="t('transactions')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="min-w-0 truncate text-lg font-bold text-slate-900 dark:text-slate-100 sm:text-xl">{{ t('transactions') }}</h1>
                <div class="flex shrink-0 items-center gap-2">
                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <IconButton icon="more" :label="t('more_actions')" />
                        </template>
                        <template #content>
                            <div role="menu" class="py-1">
                                <template v-if="can('transactions', 'add')">
                                    <button type="button" role="menuitem" :class="menuItem" @click="importInput?.click()">
                                        <Icon name="upload" :class="menuIcon" /> {{ t('import') }}
                                    </button>
                                    <p :class="menuHeading">{{ t('template') }}</p>
                                    <a v-for="f in FORMATS" :key="`tpl-${f}`" :href="withFormat(route('transactions.import.template'), f)" download role="menuitem" :class="menuItem">
                                        <Icon name="document" :class="menuIcon" /> {{ t(`export.format.${f}`) }}
                                    </a>
                                </template>
                                <p :class="menuHeading">{{ t('export') }}</p>
                                <a v-for="f in FORMATS" :key="`exp-${f}`" :href="withFormat(exportUrl, f)" download role="menuitem" :class="menuItem">
                                    <Icon name="download" :class="menuIcon" /> {{ t(`export.format.${f}`) }}
                                </a>
                                <button v-if="can('finance', 'edit')" type="button" role="menuitem" :class="[menuItem, 'mt-1 border-t border-slate-100 dark:border-slate-700']" @click="showCategories = true">
                                    <Icon name="categories" :class="menuIcon" /> {{ t('fincat.title') }}
                                </button>
                            </div>
                        </template>
                    </Dropdown>
                    <input ref="importInput" type="file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="hidden" @change="onImport" />
                    <IconButton v-if="can('transactions', 'add')" :href="route('transactions.create')" icon="plus" :label="t('add_transaction')" variant="primary" />
                </div>
            </div>
        </template>

        <StatStrip :tiles="strip" class="mb-4" />

        <div class="space-y-4">
            <!-- Statistics: where the money came from and went, and which caisses were used. Click a bar to filter. -->
            <section class="space-y-3">
                <button type="button" :aria-expanded="statsOpen" aria-controls="transaction-stats" @click="statsOpen = !statsOpen"
                    class="inline-flex items-center gap-1.5 rounded-md text-sm font-semibold text-slate-600 transition-colors hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:text-slate-300 dark:hover:text-white dark:focus-visible:ring-offset-slate-950">
                    <Icon name="chevron" class="text-base transition-transform" :class="statsOpen ? '' : '-rotate-90 rtl:rotate-90'" />
                    {{ t('statistics') }}
                </button>
                <div v-if="statsOpen" id="transaction-stats" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <StatBars v-model="categoryFilter" :title="t('tx.by_income_category')" :stats="incomeChips" format="money" :unit="t('currency_symbol')" />
                    <StatBars v-model="categoryFilter" :title="t('tx.by_expense_category')" :stats="expenseChips" format="money" :unit="t('currency_symbol')" />
                    <StatBars v-model="accountFilter" :title="t('tx.by_caisse')" :stats="accountChips" :unit="t('tx.entries')" class="md:col-span-2 xl:col-span-1" />
                </div>
            </section>

            <!-- Filters -->
            <div ref="filterBar" class="flex flex-wrap items-center gap-3">
                <div class="w-full sm:w-64">
                    <SearchInput v-model="search" :loading="filtering" :placeholder="t('search')" />
                </div>
                <MultiSelectFilter v-model="typeFilter" collapse-all :options="typeOptions" :label="t('type')" :placeholder="`${t('type')}: ${t('all')}`" class="min-w-0 flex-1 sm:w-40 sm:flex-none" />
                <MultiSelectFilter v-model="categoryFilter" :options="categoryOptions" :label="t('category')" :placeholder="t('all_categories')" class="min-w-0 flex-1 sm:w-52 sm:flex-none" />
                <MultiSelectFilter v-model="accountFilter" :options="accountOptions" :label="t('cash_register')" :placeholder="t('all_cash_registers')" class="min-w-0 flex-1 sm:w-52 sm:flex-none" />
                <label class="min-w-0 flex-1 sm:w-48 sm:flex-none">
                    <span class="sr-only">{{ t('season') }}</span>
                    <select v-model="seasonSelect" :class="fieldClass">
                        <option value="all">{{ t('tx.all_seasons') }}</option>
                        <option v-for="s in seasons" :key="s.value" :value="s.value">{{ t('tx.season_option', { season: s.label }) }}</option>
                    </select>
                </label>
                <label class="flex min-w-0 flex-1 items-center gap-2 text-sm text-slate-500 dark:text-slate-400 sm:flex-none">
                    <span class="shrink-0">{{ t('tx.date_from') }}</span>
                    <input v-model="dateFrom" type="date" :max="dateTo || undefined" :class="fieldClass" />
                </label>
                <label class="flex min-w-0 flex-1 items-center gap-2 text-sm text-slate-500 dark:text-slate-400 sm:flex-none">
                    <span class="shrink-0">{{ t('tx.date_to') }}</span>
                    <input v-model="dateTo" type="date" :min="dateFrom || undefined" :class="fieldClass" />
                </label>
                <IconButton v-if="hasActiveFilters" icon="filteroff" :label="t('clear_filters')" variant="danger" @click="clearFilters" />
            </div>

            <!-- A filter with no menu here (payment status, from a link) shows as a chip: nothing filters out of sight. -->
            <div v-if="statusFilter.length" class="flex flex-wrap items-center gap-2">
                <button v-for="s in statusFilter" :key="s" type="button"
                    :aria-label="`${t('tx.remove_filter')}: ${t('tx.status_chip', { status: statusLabel('payment', s) })}`"
                    class="inline-flex items-center gap-1.5 rounded-full bg-primary-50 px-3 py-1 text-xs font-semibold text-primary-700 ring-1 ring-primary-200 transition-colors hover:bg-primary-100 dark:bg-primary-500/10 dark:text-primary-300 dark:ring-primary-500/30 dark:hover:bg-primary-500/20"
                    @click="statusFilter = statusFilter.filter((v) => v !== s)">
                    {{ t('tx.status_chip', { status: statusLabel('payment', s) }) }}
                    <Icon name="close" class="text-sm" />
                </button>
            </div>

            <!-- Results. With a filter on they keep at least a screen of height so a short result does not drag the scroll up. -->
            <div class="space-y-4" :class="{ 'min-h-screen': hasActiveFilters }">
                <div v-if="selected.length" class="flex flex-wrap items-center gap-3 rounded-xl bg-primary-50 px-4 py-2.5 ring-1 ring-primary-200 dark:bg-primary-900/20 dark:ring-primary-800">
                    <span class="text-sm font-medium text-primary-800 dark:text-primary-200">{{ t('selected_count', { count: selected.length }) }}</span>
                    <div class="ms-auto flex flex-wrap items-center gap-2">
                        <IconButton icon="close" :label="t('cancel')" variant="ghost" @click="clearSelection" />
                        <IconButton icon="trash" :label="t('delete_selected')" variant="danger" @click="bulkDeletePending = true" />
                    </div>
                </div>

                <div :class="{ 'opacity-60': filtering }" :aria-busy="filtering" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 transition-opacity dark:bg-slate-900 dark:ring-slate-800">
                    <!-- Phones: one card per entry -->
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800 md:hidden">
                        <li v-for="tx in transactions.data" :key="tx.id" class="flex gap-3 px-4 py-3"
                            :class="selected.includes(tx.id) ? 'bg-primary-50/50 dark:bg-primary-900/10' : ''">
                            <input v-if="canDelete" type="checkbox" :checked="selected.includes(tx.id)" :aria-label="t('select_row')" @change="toggleOne(tx.id)"
                                class="mt-1 rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                            <div class="min-w-0 flex-1 space-y-1">
                                <div class="flex items-start justify-between gap-3">
                                    <Link :href="route('transactions.show', tx.id)" class="min-w-0 break-words text-sm font-semibold text-slate-900 hover:text-primary-600 dark:text-slate-100 dark:hover:text-primary-400">{{ titleOf(tx) }}</Link>
                                    <span class="shrink-0 text-sm font-bold tabular-nums" :class="amountClass(tx)" dir="ltr">{{ signedAmount(tx) }}</span>
                                </div>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ formatDate(tx.transaction_date) }} · {{ accountLabel(tx.finance_account) }}</p>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                        <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: tx.finance_category?.color || '#94a3b8' }" aria-hidden="true"></span>
                                        {{ categoryName(tx) }}
                                    </span>
                                    <Link v-if="tx.player_summary" :href="route('players.show', tx.player_summary.id)" class="text-xs text-primary-600 hover:underline dark:text-primary-400">{{ tx.player_summary.name }}</Link>
                                    <div class="ms-auto flex items-center gap-1">
                                        <IconButton v-if="canEdit" :href="route('transactions.edit', tx.id)" icon="pencil" :label="t('edit')" plain size="sm" />
                                        <IconButton v-if="canDelete" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = tx.id" />
                                    </div>
                                </div>
                            </div>
                        </li>
                        <li v-if="!transactions.data.length" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</li>
                    </ul>

                    <!-- md and up: the table -->
                    <div class="hidden overflow-x-auto md:block">
                        <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                            <thead class="bg-slate-50 dark:bg-slate-950">
                                <tr>
                                    <th v-if="canDelete" class="w-10 px-4 py-3">
                                        <input type="checkbox" :checked="allSelected" :aria-label="t('select_all')" @change="toggleAll"
                                            class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                                    </th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('date') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('type') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('title') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('category') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('cash_register') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('player') }}</th>
                                    <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('amount') }}</th>
                                    <!-- Pinned to the end edge so edit/delete stay reachable however far the row scrolls. -->
                                    <th class="sticky end-0 bg-slate-50 px-3 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 shadow-[-8px_0_8px_-8px_rgba(15,23,42,0.15)] rtl:shadow-[8px_0_8px_-8px_rgba(15,23,42,0.15)] dark:bg-slate-950 dark:text-slate-400">{{ t('actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="tx in transactions.data" :key="tx.id" class="group"
                                    :class="selected.includes(tx.id) ? 'bg-primary-50/50 dark:bg-primary-900/10' : 'hover:bg-slate-50 dark:hover:bg-slate-800'">
                                    <td v-if="canDelete" class="w-10 px-4 py-3">
                                        <input type="checkbox" :checked="selected.includes(tx.id)" :aria-label="t('select_row')" @change="toggleOne(tx.id)"
                                            class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ formatDate(tx.transaction_date) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <Badge :label="isIncome(tx) ? t('income') : t('expense')" :color="isIncome(tx) ? 'emerald' : 'rose'" />
                                    </td>
                                    <td class="max-w-xs px-4 py-3 text-sm">
                                        <Link :href="route('transactions.show', tx.id)" class="block truncate font-medium text-slate-900 hover:text-primary-600 dark:text-slate-100 dark:hover:text-primary-400">{{ titleOf(tx) }}</Link>
                                        <span v-if="tx.description" class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ tx.description }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-700 dark:text-slate-200">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: tx.finance_category?.color || '#94a3b8' }" aria-hidden="true"></span>
                                            {{ categoryName(tx) }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ accountLabel(tx.finance_account) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm">
                                        <template v-if="tx.player_summary">
                                            <Link :href="route('players.show', tx.player_summary.id)" class="text-primary-600 hover:underline dark:text-primary-400">{{ tx.player_summary.name }}</Link>
                                            <span class="block font-mono text-xs text-slate-400">{{ tx.player_summary.membership_id }}</span>
                                        </template>
                                        <span v-else class="text-slate-400">—</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-end text-sm font-semibold tabular-nums" :class="amountClass(tx)">
                                        <span dir="ltr">{{ signedAmount(tx) }}</span>
                                    </td>
                                    <td class="sticky end-0 whitespace-nowrap bg-white px-3 py-2 text-end shadow-[-8px_0_8px_-8px_rgba(15,23,42,0.15)] group-hover:bg-slate-50 rtl:shadow-[8px_0_8px_-8px_rgba(15,23,42,0.15)] dark:bg-slate-900 dark:group-hover:bg-slate-800">
                                        <div class="flex items-center justify-end gap-1">
                                            <IconButton :href="route('transactions.show', tx.id)" icon="eye" :label="t('details')" variant="primary" plain size="sm" />
                                            <IconButton v-if="canEdit" :href="route('transactions.edit', tx.id)" icon="pencil" :label="t('edit')" plain size="sm" />
                                            <IconButton v-if="canDelete" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = tx.id" />
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!transactions.data.length">
                                    <td colspan="9" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-2">
                        <label class="inline-flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                            <select v-model="perPage" class="rounded-lg border-slate-300 py-1 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                                <option value="">25</option>
                                <option v-for="n in [50, 100, 200]" :key="n" :value="String(n)">{{ n }}</option>
                            </select>
                            {{ t('per_page') }}
                        </label>
                        <Pagination :links="transactions" class="min-w-0 flex-1" />
                    </div>
                </div>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :title="t('tx.delete_title')" :message="t('tx.delete_message')" @confirm="destroy" @cancel="deleteId = null" />
        <ConfirmModal :show="bulkDeletePending" :message="t('confirm_bulk_transaction_delete', { count: selected.length })" @confirm="bulkDestroy" @cancel="bulkDeletePending = false" />

        <CategoryManager :show="showCategories" :categories="financeCategories" @close="showCategories = false" />
    </AuthenticatedLayout>
</template>
```

Remove `manage_categories` from the catalogs if grep then shows no use. The `details` key is used by Players and stays.

- [ ] **Step 3: Build and test** — `npm run build`, `npm run i18n:check`, `php artisan test --filter="Transaction"` — PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Transactions/Index.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(transactions): page follows the Players pattern — strip, statistics, visible filters, phone cards, more menu

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Show, Create, Edit and the form

**Files:**
- Modify: `resources/js/Pages/Transactions/Show.vue`, `Create.vue`, `Edit.vue`, `Partials/TransactionForm.vue`

**Interfaces — Consumes:** Task 6 props `defaultFinanceAccountId` and `lockedAccount`; `paymentStatusColor` (Task 9); `useFormatDate` (Task 1); keys `tx.uncategorised`, `currency_symbol`, `back`.

- [ ] **Step 1: `Show.vue`**

Script imports become `Badge, Icon, IconButton, Head, Link, computed, useI18n, useCan, useFormatMoney, useFormatDate, useFinanceAccountLabel, useStatusLabel, paymentStatusColor`. Delete `typeColor`/`statusColor` and add:

```js
const props = defineProps({ transaction: { type: Object, required: true } });
const { can } = useCan();
const { formatDate } = useFormatDate();
const isIncome = computed(() => props.transaction.transaction_type === 'income');
// The category's own name — never the stored slug.
const categoryName = computed(() => props.transaction.finance_category?.localized_name
    || props.transaction.finance_category?.name || t('tx.uncategorised'));
```

Template changes:
- Header row: `flex flex-wrap items-center justify-between gap-2`. Inner title wrapper: `flex min-w-0 items-center gap-2`. Title `h1` gets `min-w-0 truncate`.
- Replace the `<svg>` back arrow with `<Link :href="route('transactions.index')" :aria-label="t('back')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:text-slate-500 dark:hover:bg-slate-800 dark:hover:text-slate-300"><Icon name="back" class="rtl:rotate-180" /></Link>`.
- Edit `IconButton` gets `v-if="can('transactions', 'edit')"`.
- Amount: `<p class="mt-1 text-2xl font-bold tabular-nums [overflow-wrap:anywhere]" :class="isIncome ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'"><span dir="ltr">{{ isIncome ? '+' : '−' }}{{ formatMoney(transaction.amount) }}</span> <span class="text-sm font-medium text-slate-400">{{ t('currency_symbol') }}</span></p>`.
- Type badge: `:color="isIncome ? 'emerald' : 'rose'"`. Status badge: `:color="paymentStatusColor(transaction.status)"`.
- Category: `{{ categoryName }}` (no `transaction.category` fallback). Date: `{{ formatDate(transaction.transaction_date) }}`.

- [ ] **Step 2: `Create.vue` and `Edit.vue`**
- Import `Icon`.
- Replace the `<svg>` back link with the same `Link` + `Icon` markup as Show.
- Add the props `defaultFinanceAccountId: { type: Number, default: null }` and `lockedAccount: { type: Object, default: null }`, and pass them as `:default-finance-account-id="defaultFinanceAccountId" :locked-account="lockedAccount"` to `TransactionForm`.

- [ ] **Step 3: `TransactionForm.vue`**
- Add props `defaultFinanceAccountId: { type: Number, default: null }` and `lockedAccount: { type: Object, default: null }`.
- `finance_account_id: props.transaction?.finance_account_id || props.defaultFinanceAccountId || ''`, commented `// The resolver's caisse (TransactionController::formOptions), never the Fonds Initial.`
- In the caisse `<select>`, after the placeholder option, add:

```html
                        <!-- Booked on an account the pickers hide (Fonds Initial, inactive caisse): shown, not choosable. -->
                        <option v-if="lockedAccount" :value="lockedAccount.id" disabled>{{ accountLabel(lockedAccount) }}</option>
```

- [ ] **Step 4: Build and test** — `npm run build`, `npm run i18n:check`, `php artisan test --filter="Transaction"` — PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Transactions/Show.vue resources/js/Pages/Transactions/Create.vue resources/js/Pages/Transactions/Edit.vue resources/js/Pages/Transactions/Partials/TransactionForm.vue
git commit -m "fix(transactions): mirrored back arrows, local dates, one status colour; form starts on the right caisse and shows a locked one

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Verification

- [ ] **Step 1:** `php artisan test` — all green.
- [ ] **Step 2:** `vendor/bin/pint --test` on the changed PHP files (`git diff --name-only main -- '*.php'`); fix with `vendor/bin/pint <files>` and commit. Then `npm run i18n:check` (template scan included, 0 missing) and `npm run build`.
- [ ] **Step 3: Leftovers grep.** Each of the following must show only intended hits:
  - `grep -rn "Str::title" app/Observers` → none.
  - `grep -rn "->category\b" app/Services/Dashboard app/Http/Controllers/TransactionController.php` → only the slug write in store/update and the system-slug check.
  - `grep -rn "DZD" resources/js --include=*.vue` → only the currency `<option>` in Settings/General and the form default in CaisseFormModal.
  - `grep -rn "window.confirm" resources/js` → none.
  - `grep -rn "M15 19l-7-7 7-7" resources/js/Pages/Transactions` → none.
- [ ] **Step 4: Browser QA (controller does this, not a subagent).** Use a copy of the live data (backup first), `php artisan migrate` (no new migrations in P3), app at 127.0.0.1:2026. Test in ar (RTL) and fr, desktop width and 390px phone width:
  1. Transactions opens on 2026/2027. The strip reads 50 000 income / 14 390 expenses / net 35 610, and "Members' debts (all)" shows the club debt. Values wrap on a phone, and the currency follows (د.ج / DA).
  2. Statistics fold and stay folded after a reload. Clicking a category bar filters the list, and the other bars stay clickable. The caisse card shows "Coffre — Football"-style labels.
  3. Season select: "All seasons" shows last season's rows. Setting a date range alone shows all seasons in the range, and the select reads "All seasons". The clear-filters button resets to the current season.
  4. `?status=Partial` shows a chip that removes the filter. `?category=x` filters nothing.
  5. The table has no status column. Dates read "11 oct. 2026" / Arabic month names. Amounts are ± with green/red in dark mode. No row shows "msaryf_…", "Opening Fund", "Cash" or "Treasury".
  6. Phone: cards (title, amount, date · caisse, category chip, edit/delete). The bulk bar's actions sit at the end edge. Per-page 50 works. Pagination arrows point the reading way, wrap, and say السابق/التالي or Précédent/Suivant.
  7. "More" menu: import, template xlsx/csv, export xlsx/csv (export matches the filters), and Finance categories.
  8. Category manager, from Transactions and from Finance → Settings (the old list is gone):
     - An Arabic-only category shows in the Arabic field; the base name is unchanged on save.
     - Renaming a built-in category in fr shows the new name in fr.
     - Colour change is saved only on Save.
     - A used category has no delete button and shows its count.
     - Deleting an unused category goes through the confirm dialog.
     - Built-in and inactive badges show.
  9. Delete one transaction and a selection: ConfirmModal, and the text stays during the fade-out.
  10. Show: back arrow mirrored in ar, formatted date, status colour. Create: caisse pre-selected (never the Fonds Initial). Edit an entry booked on the Fonds Initial: the select shows "Fonds Initial" greyed out.
  11. Dashboard Finance tab: the Fonds Initial is hidden at 0, accounts are labelled like the Caisses page, transfer arrows point the reading way, dates are formatted.
  12. Finance → Settings: close/reopen season uses a primary (not red) confirm button; delete year stays red; the text does not blank while closing. Caisses page: a legacy caisse saved once shows no duplicate category chip.
- [ ] **Step 5:** commit any fixes (explicit paths), then report: the list of commits, test count, and the open owner decisions below.

**Owner decisions to raise in the report (not implemented):**
1. Merge the existing duplicate categories (e.g. "Subscription" → "Subscriptions", "Donation" → "Donations", "Debt Payment" → "Arrears / Debts") with a one-off data migration? New entries already land in the seeded ones.
2. Should `debt_payment` map to "Arrears / Debts" (chosen) or keep a separate "Debt payment" category?
3. The app timezone is UTC. Timestamps (`Y-m-d H:i`) read in UTC; switching to `Africa/Algiers` is a separate, app-wide change.

---

## Self-review against the spec (§P3 + follow-ups)

| Spec item | Task |
|---|---|
| StatStrip from `ModuleStats::transactions()` honouring filters; debts global and labelled; `strip.tx_*` keys | 5, 11 |
| Foldable Statistics (`transactions.stats`), StatBars by finance category and by caisse, click toggles the filter | 5, 9, 11 |
| Filters: search, type, category, caisse, season (default current, FiscalPeriod), date range, clear IconButton | 5, 11 |
| No hidden URL filters (`status` chip, `fiscal_year` select, dates inputs, `category` removed) | 5, 11 |
| Header: Add primary + "More" (import, template, export, manage categories) | 11 |
| Table columns, status dropped (on Show), ± `tabular-nums` dark colours, locale dates | 11, 12 |
| Mobile card list < md; per-page; bulk bar `ms-auto`; ConfirmModal single + bulk | 11 |
| Show/Create/Edit: mirrored arrows, formatted dates, consistent status colours, resolver default caisse | 6, 9, 12 |
| Slugs never shown: OverviewStats, FinanceStats largest, export columns, TransactionTitle fallback | 2 |
| Observer maps slugs to existing categories, falls back to Other | 3 |
| Pagination ar/fr lang files; Pagination.vue mirrors and wraps | 5, 9 |
| `serializeDate` on Transaction and FinanceTransfer; app-locale formatting | 1, 11, 12 |
| Seeded account names translated (`opening_fund`, `caisses.club_cash`) | 1, 7 |
| StatCard/StatTile shrink/wrap; currency from `currency_symbol` | 9 |
| CategoryManager: grouped, ar/fr/en + colour + active, ConfirmModal delete blocked with count, no prop mutation, `is_system` rename-only enforced server-side; Settings list removed | 4, 10 |
| Arabic-only names: presentation and save rule stated | Global Constraints 7, 4, 10 |
| i18n check scans literal template keys, warns on dynamic ones | 8 |
| Follow-up (a) dashboard accounts: Fonds Initial hidden unless ≠ 0, labels | 7 |
| Follow-up (b) ConfirmModal `variant` + copy kept during fade-out; Settings close/reopen primary | 9 |
| Follow-up (c) duplicate chip on the Caisses page | 9 |
| Follow-up (d) Fonds Initial shown disabled on Edit | 6, 12 |
| Tests: index props (stats follow filters, season default), observer mapping, PDF summary names (already covered by `ReportPdfTest::the_financial_summary_covers_one_season_and_names_categories`, P1), pagination translations, i18n check | 3, 5, 8 |
