# Finance Category Translations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finance category labels follow the active app language (ar / fr / en), with translations editable from the /transactions "Manage categories" modal.

**Architecture:** Mirror the existing per-locale pattern of `App\Models\Category`: nullable `name_ar` / `name_fr` / `name_en` columns plus an appended `localized_name` accessor resolved server-side from the locale that `SetLocale` middleware sets. `name` stays the base name, uniqueness key and legacy-slug source. A migration backfills translations for the 16 built-in categories.

**Tech Stack:** Laravel 13, PHPUnit 12 (`#[Test]` attribute style), SQLite, Inertia + Vue 3 `<script setup>`, vue-i18n flat JSON catalogs.

**Spec:** `docs/superpowers/specs/2026-09-18-finance-category-translations-design.md`

## Global Constraints

- Locales are exactly `ar`, `fr`, `en`; columns are `name_ar`, `name_fr`, `name_en`.
- Fallback rule everywhere: `name_{locale}` if non-empty, else `name`. Never a blank label.
- `name` stays required and unique per `type`; translations are `nullable|string|max:120`, never unique-checked.
- The backfill only writes columns that are still `null`; it never overwrites a user-entered translation.
- Frontend displays `x.localized_name || x.name`.
- New i18n key `translations` must exist in `en.json`, `fr.json` and `ar.json`.
- **No commits during execution.** The working tree already holds unrelated uncommitted edits in the same files (`TransactionController.php`, `FinanceController.php`, `Transactions/Index.vue`, i18n JSON, …). Staging these files would sweep that work into the commit. Leave all changes unstaged; the user commits when ready.

## Test Commands

- Single file: `php artisan test --filter=FinanceCategoryTranslationTest`
- Full suite: `php artisan test`
- i18n catalog check: `npm run i18n:check`
- Frontend build: `npm run build`

---

### Task 1: Locale columns, backfill migration, model accessor, controller validation

**Files:**
- Create: `database/migrations/2026_09_18_000001_add_locale_names_to_finance_categories.php`
- Modify: `app/Models/FinanceCategory.php`
- Modify: `app/Http/Controllers/FinanceCategoryController.php` (`validateData()`)
- Test: `tests/Feature/FinanceCategoryTranslationTest.php` (create)

**Interfaces:**
- Produces: columns `finance_categories.name_ar|name_fr|name_en` (nullable string); `FinanceCategory::$localized_name` (string, appended to every serialization); public `backfill(): void` method on the migration instance (idempotent, fills nulls only).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/FinanceCategoryTranslationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinanceCategoryTranslationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_18_000001_add_locale_names_to_finance_categories.php';

    private function admin(string $locale = 'en'): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'is_active' => true,
            'email_verified_at' => now(),
            'preferred_lng' => $locale,
        ]);
    }

    private function runBackfill(): void
    {
        (require database_path(self::MIGRATION))->backfill();
    }

    #[Test]
    public function localized_name_follows_the_app_locale_and_falls_back_to_the_base_name(): void
    {
        $cat = FinanceCategory::create([
            'type' => 'expense', 'name' => 'Rent',
            'name_ar' => 'كراء', 'name_fr' => 'Loyer', 'name_en' => null,
            'is_active' => true,
        ]);

        app()->setLocale('ar');
        $this->assertSame('كراء', $cat->localized_name);

        app()->setLocale('fr');
        $this->assertSame('Loyer', $cat->localized_name);

        app()->setLocale('en');
        $this->assertSame('Rent', $cat->localized_name);
    }

    #[Test]
    public function migration_translates_the_built_in_categories(): void
    {
        $donations = FinanceCategory::where('code', 'DON')->firstOrFail();

        $this->assertSame('تبرعات', $donations->name_ar);
        $this->assertSame('Dons', $donations->name_fr);
        $this->assertSame('Donations', $donations->name_en);
    }

    #[Test]
    public function backfill_translates_custom_categories_matching_a_built_in_name(): void
    {
        $custom = FinanceCategory::create(['type' => 'income', 'name' => 'Donation', 'is_active' => true]);

        $this->runBackfill();

        $custom->refresh();
        $this->assertSame('Dons', $custom->name_fr);
        $this->assertSame('تبرعات', $custom->name_ar);
        // Matched by name only: keep the user's own English spelling via the fallback.
        $this->assertNull($custom->name_en);
    }

    #[Test]
    public function backfill_never_overwrites_a_translation_the_user_entered(): void
    {
        $donations = FinanceCategory::where('code', 'DON')->firstOrFail();
        $donations->update(['name_fr' => 'Dons et mécénat']);

        $this->runBackfill();

        $this->assertSame('Dons et mécénat', $donations->fresh()->name_fr);
    }

    #[Test]
    public function store_and_update_persist_the_translations(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('finance.categories.store'), [
                'type' => 'expense', 'name' => 'Rent',
                'name_ar' => 'كراء', 'name_fr' => 'Loyer', 'name_en' => 'Rent',
            ])
            ->assertSessionHasNoErrors();

        $cat = FinanceCategory::where('type', 'expense')->where('name', 'Rent')->firstOrFail();
        $this->assertSame('كراء', $cat->name_ar);
        $this->assertSame('Loyer', $cat->name_fr);

        $this->actingAs($admin)
            ->put(route('finance.categories.update', $cat), [
                'type' => 'expense', 'name' => 'Rent', 'name_fr' => 'Location',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Location', $cat->fresh()->name_fr);
        $this->assertSame('كراء', $cat->fresh()->name_ar);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=FinanceCategoryTranslationTest`
Expected: FAIL — SQL error `no such column: name_ar` / missing migration file.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_09_18_000001_add_locale_names_to_finance_categories.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Built-in category translations keyed by code: [en, fr, ar]. */
    private const TRANSLATIONS = [
        'SUB' => ['Subscriptions', 'Abonnements', 'اشتراكات'],
        'MEM' => ['Membership Fees', 'Cotisations', 'رسوم العضوية'],
        'DON' => ['Donations', 'Dons', 'تبرعات'],
        'GRT' => ['Grants / Subsidies', 'Subventions', 'منح / إعانات'],
        'ARR' => ['Arrears / Debts', 'Arriérés / Dettes', 'متأخرات / ديون'],
        'EVR' => ['Events Revenue', 'Recettes des événements', 'إيرادات التظاهرات'],
        'OIN' => ['Other Income', 'Autres recettes', 'إيرادات أخرى'],
        'EQP' => ['Equipment', 'Équipement', 'معدات'],
        'MNT' => ['Maintenance & Repairs', 'Maintenance et réparations', 'صيانة وإصلاحات'],
        'SUP' => ['Supplies', 'Fournitures', 'لوازم'],
        'WRK' => ['Works / Construction', 'Travaux / Construction', 'أشغال / بناء'],
        'UTL' => ['Utilities', 'Charges (eau, électricité)', 'فواتير الخدمات'],
        'SAL' => ['Salaries / Wages', 'Salaires', 'رواتب وأجور'],
        'EVE' => ['Events Expenses', 'Dépenses des événements', 'مصاريف التظاهرات'],
        'ADM' => ['Administrative', 'Frais administratifs', 'مصاريف إدارية'],
        'OEX' => ['Other Expense', 'Autres dépenses', 'مصاريف أخرى'],
    ];

    /** Singular spellings users typed for custom categories (lowercase) => code. */
    private const ALIASES = [
        'subscription' => 'SUB',
        'membership fee' => 'MEM',
        'donation' => 'DON',
        'grant' => 'GRT',
        'subsidy' => 'GRT',
        'salary' => 'SAL',
        'utility' => 'UTL',
        'supply' => 'SUP',
    ];

    public function up(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->string('name_ar')->nullable()->after('name');
            $table->string('name_fr')->nullable()->after('name_ar');
            $table->string('name_en')->nullable()->after('name_fr');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropColumn(['name_ar', 'name_fr', 'name_en']);
        });
    }

    /**
     * Fill empty translations for built-in categories. Matches by code first,
     * then by name. Only null columns are written, so re-running is safe and
     * user-entered translations survive.
     */
    public function backfill(): void
    {
        $byName = self::ALIASES;
        foreach (self::TRANSLATIONS as $code => [$en]) {
            $byName[mb_strtolower($en)] = $code;
        }

        foreach (DB::table('finance_categories')->get() as $row) {
            $matchedByCode = $row->code && isset(self::TRANSLATIONS[$row->code]);
            $code = $matchedByCode ? $row->code : ($byName[mb_strtolower(trim($row->name))] ?? null);
            if (! $code) {
                continue;
            }

            [$en, $fr, $ar] = self::TRANSLATIONS[$code];
            $wanted = ['name_ar' => $ar, 'name_fr' => $fr];
            // A name-only match keeps its own English spelling via the fallback.
            if ($matchedByCode) {
                $wanted['name_en'] = $en;
            }

            $updates = array_filter($wanted, fn ($value, $column) => $row->{$column} === null, ARRAY_FILTER_USE_BOTH);
            if ($updates) {
                DB::table('finance_categories')->where('id', $row->id)->update($updates);
            }
        }
    }
};
```

- [ ] **Step 4: Add the model accessor**

Replace `app/Models/FinanceCategory.php` `$fillable` and add `$appends` + accessor (keep casts and relations unchanged):

```php
    protected $fillable = [
        'type', 'name', 'name_ar', 'name_fr', 'name_en', 'code', 'parent_id', 'color', 'sort_order', 'is_active', 'is_system',
    ];

    protected $appends = [
        'localized_name',
    ];

    /**
     * The category name in the current app locale, falling back to the base name.
     */
    public function getLocalizedNameAttribute(): string
    {
        $column = 'name_'.app()->getLocale();

        return $this->{$column} ?: $this->name;
    }
```

- [ ] **Step 5: Accept translations in the controller**

In `app/Http/Controllers/FinanceCategoryController.php` `validateData()`, add after the `'name'` rule:

```php
            'name_ar' => ['nullable', 'string', 'max:120'],
            'name_fr' => ['nullable', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=FinanceCategoryTranslationTest`
Expected: PASS (5 tests).

- [ ] **Step 7: Migrate the local database**

Run: `php artisan migrate`
Expected: `2026_09_18_000001_add_locale_names_to_finance_categories ... DONE`.
Then verify: `php artisan tinker --execute="echo App\Models\FinanceCategory::pluck('name_fr','name');"`
Expected: local "Subscription" → `Abonnements`, "Donation" → `Dons`.

---

### Task 2: Serve localized names to transaction and finance pages

**Files:**
- Modify: `app/Http/Controllers/TransactionController.php` (`index()` financeCategories select, `export()` category column, `show()` eager loads, `formOptions()` financeCategories select)
- Modify: `app/Http/Controllers/FinanceController.php` (`yearDetail()` breakdown query + budget rows)
- Test: `tests/Feature/FinanceCategoryTranslationTest.php` (append)

**Interfaces:**
- Consumes: `FinanceCategory::$localized_name`, columns `name_ar|name_fr|name_en` (Task 1).
- Produces: Inertia props — `financeCategories[].localized_name` on Transactions Index/Create/Edit; `transaction.finance_category.localized_name` on Transactions Show; `detail.income_by_category[].name`, `detail.expense_by_category[].name`, `detail.budget[].name` already localized on Finance Index.

- [ ] **Step 1: Write the failing tests**

Add imports at the top of `tests/Feature/FinanceCategoryTranslationTest.php`:

```php
use App\Models\FiscalYear;
use App\Models\Transaction;
```

Append these methods inside the class:

```php
    private function donationTransaction(): Transaction
    {
        FiscalYear::firstOrCreate(['year' => now()->year], [
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'open',
            'opening_balance' => 0,
        ]);
        $donations = FinanceCategory::where('code', 'DON')->firstOrFail();

        return Transaction::create([
            'amount' => 900, 'transaction_type' => 'income', 'category' => 'donations',
            'finance_category_id' => $donations->id, 'status' => 'Paid',
            'transaction_date' => now(), 'fiscal_year' => now()->year,
        ]);
    }

    #[Test]
    public function transactions_index_labels_categories_in_the_user_language(): void
    {
        $this->donationTransaction();

        $this->actingAs($this->admin('fr'))
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('financeCategories', fn ($cats) => collect($cats)->firstWhere('name', 'Donations')['localized_name'] === 'Dons')
                ->where('transactions.data.0.finance_category.localized_name', 'Dons'));
    }

    #[Test]
    public function transaction_form_options_are_localized(): void
    {
        $this->actingAs($this->admin('ar'))
            ->get(route('transactions.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('financeCategories', fn ($cats) => collect($cats)->firstWhere('name', 'Donations')['localized_name'] === 'تبرعات'));
    }

    #[Test]
    public function transaction_show_exposes_the_localized_category(): void
    {
        $tx = $this->donationTransaction();

        $this->actingAs($this->admin('fr'))
            ->get(route('transactions.show', $tx))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('transaction.finance_category.localized_name', 'Dons'));
    }

    #[Test]
    public function finance_dashboard_breakdown_and_budget_use_the_user_language(): void
    {
        $this->donationTransaction();

        $this->actingAs($this->admin('ar'))
            ->get(route('finance.index', ['year' => now()->year]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.income_by_category.0.name', 'تبرعات')
                ->where('detail.budget.0.name', 'تبرعات'));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=FinanceCategoryTranslationTest`
Expected: the 5 Task 1 tests PASS; `transaction_form_options_are_localized` and `transactions_index_...` FAIL (`localized_name` is `'Donations'` because `name_*` columns are not selected), `transaction_show_...` FAIL (`finance_category` missing), `finance_dashboard_...` FAIL (`'Donations'` ≠ `'تبرعات'`).

- [ ] **Step 3: Select locale columns in TransactionController**

In `index()`, change the `financeCategories` query's final call from
`->get(['id', 'type', 'name', 'color'])` to:

```php
                ->orderBy('type')->orderBy('sort_order')->orderBy('name')->get(['id', 'type', 'name', 'name_ar', 'name_fr', 'name_en', 'color']),
```

In `formOptions()`, change the `financeCategories` `->get(['id', 'type', 'name', 'color'])` to:

```php
                ->get(['id', 'type', 'name', 'name_ar', 'name_fr', 'name_en', 'color']),
```

In `show()`, add `'financeCategory',` to the `$transaction->load([...])` list:

```php
        $transaction->load([
            'recordedBy',
            'receivedBy',
            'financeCategory',
            'financeAccount.branch',
            'financeAccount.category',
            'playerSubscriptions.player',
            'playerSubscriptions.transaction',
        ]);
```

In `export()`, change the category column line to:

```php
            $t->financeCategory?->localized_name ?? $t->category,
```

- [ ] **Step 4: Localize the finance dashboard**

In `app/Http/Controllers/FinanceController.php` `yearDetail()`, replace the `$byCategory` query with:

```php
        $byCategory = DB::table('transactions as t')
            ->leftJoin('finance_categories as c', 'c.id', '=', 't.finance_category_id')
            ->where('t.archived', false)
            ->where('t.fiscal_year', $year)
            ->groupBy('t.transaction_type', 'c.id', 'c.name', 'c.name_ar', 'c.name_fr', 'c.name_en', 'c.color')
            ->selectRaw('t.transaction_type as type, c.id as category_id, c.name, c.name_ar, c.name_fr, c.name_en, c.color, SUM(t.amount) as total, COUNT(*) as count')
            ->get()
            ->map(function ($row) {
                // Same fallback as FinanceCategory::localized_name.
                $row->name = ($row->{'name_'.app()->getLocale()} ?? null) ?: $row->name;
                unset($row->name_ar, $row->name_fr, $row->name_en);

                return $row;
            });
```

In the `$budgetRows` map, change `'name' => $c->name` to `'name' => $c->localized_name`:

```php
                    'category_id' => $c->id, 'name' => $c->localized_name, 'type' => $c->type, 'color' => $c->color,
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=FinanceCategoryTranslationTest`
Expected: PASS (9 tests).

- [ ] **Step 6: Run the full suite for regressions**

Run: `php artisan test`
Expected: same pass/fail set as before this task plus the 9 new passes. Note any pre-existing failures before starting Task 1 so they are not attributed to this change.

---

### Task 3: Manage-categories editor and localized labels in the UI

**Files:**
- Modify: `resources/js/Components/CategoryManager.vue` (full rewrite below)
- Modify: `resources/js/Pages/Transactions/Index.vue:122,125,161`
- Modify: `resources/js/Pages/Transactions/Partials/TransactionForm.vue:97`
- Modify: `resources/js/Pages/Transactions/Show.vue:61`
- Modify: `resources/js/Pages/Finance/Settings.vue:140,162,175`
- Modify: `resources/js/i18n/en.json`, `fr.json`, `ar.json` (new key `translations`)

**Interfaces:**
- Consumes: `financeCategories[]` items `{ id, type, name, name_ar, name_fr, name_en, color, localized_name }` (Task 2); routes `finance.categories.store|update|destroy` accepting `name_ar|name_fr|name_en` (Task 1).

- [ ] **Step 1: Add the i18n key**

Insert after the `"transactions": ...,` line (line 782) in each catalog:

- `resources/js/i18n/en.json`: `    "translations": "Translations",`
- `resources/js/i18n/fr.json`: `    "translations": "Traductions",`
- `resources/js/i18n/ar.json`: `    "translations": "الترجمات",`

Command (each file keeps valid JSON because the preceding line already ends with a comma):

```bash
sed -i '/^    "transactions": /a\    "translations": "Translations",' resources/js/i18n/en.json
sed -i '/^    "transactions": /a\    "translations": "Traductions",' resources/js/i18n/fr.json
sed -i '/^    "transactions": /a\    "translations": "الترجمات",' resources/js/i18n/ar.json
node -e "for (const l of ['en','fr','ar']) console.log(l, require('./resources/js/i18n/'+l+'.json').translations)"
```

Expected output: `en Translations`, `fr Traductions`, `ar الترجمات`.

- [ ] **Step 2: Rewrite CategoryManager.vue**

Replace the whole file `resources/js/Components/CategoryManager.vue` with:

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

const LOCALES = [
    { key: 'name_ar', label: 'العربية', dir: 'rtl' },
    { key: 'name_fr', label: 'Français', dir: 'ltr' },
    { key: 'name_en', label: 'English', dir: 'ltr' },
];
const DEFAULT_COLOR = { income: '#10b981', expense: '#ef4444' };
const inputClass = 'w-full rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 py-1 text-sm';

function blankDraft(type) {
    return { name: '', name_ar: '', name_fr: '', name_en: '', color: DEFAULT_COLOR[type], showTranslations: false };
}

const draft = ref({ income: blankDraft('income'), expense: blankDraft('expense') });
const editingId = ref(null);
const edit = ref({ name: '', name_ar: '', name_fr: '', name_en: '' });
const error = ref('');

function byType(type) {
    return props.categories.filter((c) => c.type === type);
}
function label(cat) {
    return cat.localized_name || cat.name;
}
function onError(errors) {
    error.value = Object.values(errors)[0] || t('save_failed');
}
function payload(cat, overrides = {}) {
    return {
        type: cat.type,
        name: cat.name,
        name_ar: cat.name_ar,
        name_fr: cat.name_fr,
        name_en: cat.name_en,
        color: cat.color,
        ...overrides,
    };
}

function add(type) {
    const d = draft.value[type];
    if (!d.name.trim()) return;
    router.post(route('finance.categories.store'), {
        type, name: d.name, name_ar: d.name_ar, name_fr: d.name_fr, name_en: d.name_en, color: d.color,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            draft.value[type] = blankDraft(type);
            error.value = '';
        },
        onError,
    });
}
function toggleEdit(cat) {
    if (editingId.value === cat.id) {
        editingId.value = null;
        return;
    }
    editingId.value = cat.id;
    edit.value = { name: cat.name, name_ar: cat.name_ar || '', name_fr: cat.name_fr || '', name_en: cat.name_en || '' };
}
function saveEdit(cat) {
    if (!edit.value.name.trim()) return;
    router.put(route('finance.categories.update', cat.id), payload(cat, edit.value), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
            error.value = '';
        },
        onError,
    });
}
function saveColor(cat) {
    router.put(route('finance.categories.update', cat.id), payload(cat), {
        preserveScroll: true,
        onSuccess: () => { error.value = ''; },
        onError,
    });
}
function remove(cat) {
    router.delete(route('finance.categories.destroy', cat.id), {
        preserveScroll: true,
        // The shared toast already renders flash.error, and it translates the
        // key. Reading the raw prop here would print "flash.category_in_use".
        onSuccess: () => { error.value = ''; },
    });
}
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="$emit('close')">
        <div class="p-6">
            <h2 class="mb-4 text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('manage_categories') }}</h2>
            <p v-if="error" class="mb-3 rounded-lg bg-rose-50 dark:bg-rose-900/30 px-3 py-2 text-sm text-rose-700 dark:text-rose-300">{{ error }}</p>
            <div class="grid gap-6 sm:grid-cols-2">
                <div v-for="type in ['income', 'expense']" :key="type">
                    <h3 class="mb-2 text-sm font-semibold uppercase text-slate-500">{{ t(type) }}</h3>
                    <ul class="space-y-2">
                        <li v-for="cat in byType(type)" :key="cat.id">
                            <div class="flex items-center gap-2">
                                <input type="color" v-model="cat.color" @change="saveColor(cat)" class="h-7 w-7 rounded border-0 bg-transparent p-0" />
                                <span class="flex-1 truncate text-sm text-slate-800 dark:text-slate-200">{{ label(cat) }}</span>
                                <button type="button" @click="toggleEdit(cat)" class="text-slate-400 hover:text-primary-600" :title="t('edit')">&#9998;</button>
                                <button type="button" @click="remove(cat)" class="text-rose-500 hover:text-rose-700" :title="t('delete')">&times;</button>
                            </div>
                            <div v-if="editingId === cat.id" class="mt-2 space-y-1.5 rounded-lg bg-slate-50 p-2 dark:bg-slate-800/50">
                                <label class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ t('default') }}
                                    <input v-model="edit.name" @keyup.enter="saveEdit(cat)" :class="['mt-0.5', inputClass]" required />
                                </label>
                                <label v-for="l in LOCALES" :key="l.key" class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ l.label }}
                                    <input v-model="edit[l.key]" @keyup.enter="saveEdit(cat)" :dir="l.dir" :class="['mt-0.5', inputClass]" />
                                </label>
                                <div class="flex justify-end gap-2 pt-1">
                                    <button type="button" @click="editingId = null" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">{{ t('cancel') }}</button>
                                    <button type="button" @click="saveEdit(cat)" class="rounded-lg bg-primary-600 px-3 py-1 text-sm font-medium text-white hover:bg-primary-700">{{ t('save') }}</button>
                                </div>
                            </div>
                        </li>
                    </ul>
                    <div class="mt-2 flex items-center gap-2">
                        <input type="color" v-model="draft[type].color" class="h-7 w-7 rounded border-0 bg-transparent p-0" />
                        <input v-model="draft[type].name" @keyup.enter="add(type)" :placeholder="t('new_category')" :class="['flex-1', inputClass]" />
                        <button type="button" @click="add(type)" class="rounded-lg bg-primary-600 px-3 py-1 text-sm font-medium text-white hover:bg-primary-700">+</button>
                    </div>
                    <button type="button" @click="draft[type].showTranslations = !draft[type].showTranslations" class="mt-1 text-xs text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                        {{ draft[type].showTranslations ? '▾' : '▸' }} {{ t('translations') }}
                    </button>
                    <div v-if="draft[type].showTranslations" class="mt-1 space-y-1.5">
                        <input v-for="l in LOCALES" :key="l.key" v-model="draft[type][l.key]" @keyup.enter="add(type)" :dir="l.dir" :placeholder="l.label" :class="inputClass" />
                    </div>
                </div>
            </div>
            <p class="mt-4 text-xs text-slate-400">{{ t('category_in_use_hint') }}</p>
        </div>
    </Modal>
</template>
```

- [ ] **Step 3: Show localized labels in pages**

`resources/js/Pages/Transactions/Index.vue`
- line 122: `{{ c.name }}` → `{{ c.localized_name || c.name }}`
- line 125: `{{ c.name }}` → `{{ c.localized_name || c.name }}`
- line 161: `{{ tx.finance_category.name }}` → `{{ tx.finance_category.localized_name || tx.finance_category.name }}`

`resources/js/Pages/Transactions/Partials/TransactionForm.vue`
- line 97: `{{ c.name }}` → `{{ c.localized_name || c.name }}`

`resources/js/Pages/Transactions/Show.vue`
- line 61: `{{ transaction.category }}` →
  `{{ transaction.finance_category?.localized_name || transaction.finance_category?.name || transaction.category }}`

`resources/js/Pages/Finance/Settings.vue`
- lines 140, 162, 175: `<span class="truncate">{{ c.name }}</span>` → `<span class="truncate">{{ c.localized_name || c.name }}</span>`

Verify nothing was missed:

Run: `grep -n "c\.name }}\|finance_category\.name }}" resources/js/Pages/Transactions/Index.vue resources/js/Pages/Transactions/Partials/TransactionForm.vue resources/js/Pages/Finance/Settings.vue`
Expected: no output.

- [ ] **Step 4: Check i18n catalogs and build**

Run: `npm run i18n:check`
Expected: exit 0, no failures.

Run: `npm run build`
Expected: `✓ built in …` with no errors.

- [ ] **Step 5: Manual check in the browser**

Start `start.bat`, open `http://127.0.0.1:2026/transactions`, then:
1. Switch language to Français → category filter options and table badges show French names (e.g. "Dons").
2. Manage categories → ✎ on a category → fill العربية / Français / English → Save → label updates; switch language → label follows.
3. Add a category with the "Translations" toggle open → new row shows the translation for the active language.
4. Open a transaction → "Category" shows the localized name.
5. `/finance` → doughnut labels and budget-vs-actual names follow the language.
