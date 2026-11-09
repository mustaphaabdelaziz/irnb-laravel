# Help Center Part 3a — Equipment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the nine equipment problems the owner chose, then add the `equipment` help topic: 9 Arabic articles with screenshots on a seeded demo store room.

**Architecture:** Fixes first (Tasks 1–5), each with feature tests, so the articles describe corrected behaviour. Then the same Help Center engine as Parts 1–2 (`resources/help/ar/<topic>/<slug>.md` → `HelpArticleParser` → `HelpLibrary` → Vue): a new `HelpDemoEquipmentSeeder` called last from the guarded `HelpDemoSeeder`, `data-help` attributes on the equipment pages, shots in `resources/help/shots.js`, and three article tasks of three articles each. No Help framework change.

**Tech Stack:** Laravel 13, Inertia 2 + Vue 3, PHPUnit-style tests (`#[Test]`), Playwright screenshot script (`npm run help:shots`).

Spec: `docs/superpowers/specs/2026-11-09-help-center-part3-design.md` (phase 3a). References: `resources/help/README.md` (authoring guide), `resources/help/ar/finance/*.md` and `resources/help/ar/settings/*.md` (house style), `docs/superpowers/plans/2026-11-09-help-center-part2d-settings-users.md` (previous phase, same task shapes).

## Owner decisions for 3a (2026-11-09)

All nine proposed fixes were chosen:

1. «مصدر الاستلام» is required when «تسجيل كمصروف» is unticked.
2. «تسجيل كمصروف» with «سعر الوحدة» empty or 0 → validation error.
3. Conditions shown in Arabic in «تقرير المخزون» and the stock-take page; purchase expense descriptions translated.
4. The return form starts on the unit's current condition, not «جيد».
5. A catalog cannot be deleted (alone or in a bulk delete) while one of its units is out.
6. Stock-take references continue from the highest number used this year.
7. Equipment, stock-take, equipment-category and storage-place buttons are hidden without the right.
8. Renaming a storage place renames it on the units stored there.
9. Unit import gets «تسجيل كمصروف» (off by default) + caisse; no expense unless ticked.

## Global Constraints

- Offline only; no CDN. Screenshots in `public/help-shots/<locale>/` (never `public/help/` — it shadows the `/help` route).
- Help UI uses app tokens (`primary-*`, `dark:`), dark text `primary-300`; markers `amber-500`.
- Articles Arabic only; UI strings in ar/fr/en, flat dotted keys, insert-only (text edits, never re-serialise the JSON), no key segment named after a JS String method (`search`, `link`, `replace`, `split`, `match`…). Laravel JSON strings (`lang/ar.json`, `lang/fr.json`) for server-made texts; custom validation messages in `lang/{ar,fr,en}/validation.php` (see `branch_code_needs_latin`).
- Rights (`config/permissions.php` + `app/Support/PermissionMap.php`: the last route-name segment `store`/`create` → add, `destroy`/`delete` → delete, `index`/`show`/`export`/`template`/`history`/`inventory`/`preview-serial` → view, **anything else → edit**, including `bulk-destroy`, `import`, `receive`, `rent`, `return`, `repair`, `split`, `counts`, `participants`, `complete`). Modules: `equipment` (catalogs, units, «المعدات المُخرَجة», «تقرير المخزون»), `inventory` (stock-take), `equipment_categories`, `storage_locations`. `equipment.out` is view. Articles say which right a step needs when it is not view.
- Sidebar: group «المعدات» → «العتــاد» (copy the tatweel exactly; key `equipments`), «المعدات المُخرَجة», «الجرد»; «تهيئة التطبيق» → «فئات المعدات», «أماكن التخزين».
- Writing rules: simple MSA, singular imperative, ≤ 15 words a sentence, one action per step (filling adjacent fields of one form card is one step), every clicked element ringed (sidebar clicks too), phone hint where a step relies on the sidebar («في الهاتف، افتح القائمة أولًا بزر ☰»), exact labels from `resources/js/i18n/ar.json` in «…», no emoji standing in for app icons, every fact checked in code, ⚠️ warning for irreversible actions, 3–7 steps, ≤ 1 tip, ≤ 1 warning, worked example followed through.
- **Icon-only buttons** (`IconButton`: the name slides open on hover and is the tooltip/aria-label): describe the icon («زر علامة +», «زر القلم», «زر سلة المهملات») and quote the hover name the way `resources/help/ar/settings/add-branch.md` does («عندما تضع الفأرة عليه يظهر اسمه «تعديل».»). No colour words for buttons (owner rule); «الملوّن» only where colour is the point.
- UI wording quirks to state right: the row button's hover name is «إيجار» but the form's toggle says «إعارة» / «تخصيص»; no money is charged for a loan. The reclassify button's hover name is `equipment.mark_damaged` — read its Arabic value in `ar.json` (expected «إعادة تصنيف وحدات»).
- Lessons from Parts 1–2: walk each article literally against the component's watchers and defaults; take each screenshot in the state the reader is in at that step; a step holds one action; reviewers repeatedly catch two actions in one step and unringed sidebar clicks.
- **Shots must never save anything**, except where a shot says so and undoes it in `after`. Never press «حفظ» / «إيجار» / «إرجاع» / «إضافة» / «حذف» / «إنهاء الجرد» / «حفظ العد» for real. «إنهاء الجرد» and some deletes use a native `confirm()`; ring the button, never screenshot the native dialog.
- Demo data only in the throwaway screenshot DB: the new seeder calls `HelpDemoSeeder::assertThrowawayDatabase()` first. Production is PostgreSQL; seeders and sweeps never run there. Queries must work on SQLite, MySQL and PostgreSQL (no SQLite-only functions).
- Git: stage explicit files only (main folder has unrelated untracked `.claude/`, `ASIRAM.png`, `HANDOFF.md`, `brag-output/`, a PDF). Never `git add -A`.
- Worktree: work in a git worktree (`superpowers:using-git-worktrees`); copy `vendor/` and `node_modules/` from the main folder (no junctions), then `npm install` for playwright/sharp and restore `package-lock.json`'s `name` field if npm changed it; `npx playwright install chromium` if missing. Copy `players/branches/opponents` media into the worktree's storage only if a shot needs it (none in 3a).
- Tests: `php artisan test --filter=<Name>`; full suite only in the worktree without `bootstrap/cache/config.php`: `php -d memory_limit=1G vendor/bin/phpunit`.

## File map

| File | Responsibility |
|---|---|
| `app/Http/Requests/Equipment/ReceiveStockRequest.php` (modify) | fixes 1–2 validation |
| `app/Services/Equipment/EquipmentStockService.php` (modify) | translated expense description |
| `app/Http/Controllers/EquipmentItemController.php` (modify) | unit import expense choice, translated description, return default |
| `app/Http/Controllers/EquipmentOutController.php` (modify) | `item_condition` on each open rental |
| `app/Http/Controllers/EquipmentCatalogController.php` (modify) | refuse delete while units are out |
| `app/Http/Controllers/InventoryController.php` (modify) | reference from the highest number |
| `app/Http/Controllers/StorageLocationController.php` (modify) | rename carries to units |
| `lang/ar.json`, `lang/fr.json`, `lang/{ar,fr,en}/validation.php` (modify) | server texts |
| `resources/js/lib/stateLabel.js` (create) | one helper: enum value → translated label |
| `resources/js/Pages/Equipment/Catalog/{Index,Show}.vue`, `Equipment/{Inventory,History,Out}.vue`, `Inventory/{Index,Session}.vue`, `Components/ReturnRentalModal.vue`, `Settings/{EquipmentCategories,StorageLocations}.vue`, `Equipment/Catalog/Create.vue` (modify) | fixes + `data-help` attributes |
| `resources/js/i18n/{ar,fr,en}.json` (modify) | new UI strings, `help.topic.equipment` |
| `resources/js/Components/Sidebar/SidebarSection.vue` (modify) | nav `data-help` for the five equipment entries |
| `resources/help/topics.php` (modify) | `equipment` topic |
| `database/seeders/HelpDemoEquipmentSeeder.php` (create), `HelpDemoSeeder.php` (modify) | demo store room |
| `tests/Feature/...` (create/modify) | one test file per fix task, seeder test |
| `resources/help/shots.js` (modify) | new shots |
| `resources/help/ar/equipment/*.md` (create) | 9 articles |
| `public/help-shots/ar/**` (generated) | screenshots + boxes |

---

### Task 1: Receive stock — required source, price for an expense, translated expense text

**Files:**
- Modify: `app/Http/Requests/Equipment/ReceiveStockRequest.php`
- Modify: `app/Services/Equipment/EquipmentStockService.php:209`
- Modify: `resources/js/Pages/Equipment/Catalog/Show.vue:131-160` (form defaults) and `:784-793` (source select)
- Modify: `lang/ar.json`, `lang/fr.json`, `lang/ar/validation.php`, `lang/fr/validation.php`, `lang/en/validation.php`
- Test: `tests/Feature/EquipmentReceiveStockTest.php` (add tests; update existing posts)

**Interfaces:**
- Produces: validation keys `validation.equipment_source_required`, `validation.equipment_expense_needs_price`; JSON string `"Equipment purchase: :count unit(s)"`; the receive form starts with `received_via: ''`.

- [ ] **Step 1: Write the failing tests** — append to `tests/Feature/EquipmentReceiveStockTest.php`:

```php
    #[Test]
    public function without_an_expense_the_source_must_be_chosen(): void
    {
        $this->actingAs($this->user())->post(route('equipment.stock.receive'), [
            'catalog_id' => $this->catalog()->id,
            'quantity' => 5,
            'purchase_date' => '2026-03-15',
            'condition' => 'New',
            'record_expense' => false,
        ])->assertSessionHasErrors('received_via');

        $this->assertSame(0, EquipmentItem::count());
    }

    #[Test]
    public function an_expense_needs_a_unit_price_above_zero(): void
    {
        foreach (['', 0] as $price) {
            $this->actingAs($this->user())->post(route('equipment.stock.receive'), [
                'catalog_id' => $this->catalog()->id,
                'quantity' => 5,
                'unit_price' => $price,
                'purchase_date' => '2026-03-15',
                'condition' => 'New',
                'record_expense' => true,
            ])->assertSessionHasErrors('unit_price');
        }

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, EquipmentItem::count());
    }

    #[Test]
    public function the_expense_description_follows_the_app_language(): void
    {
        app()->setLocale('ar');

        $this->actingAs($this->user())->post(route('equipment.stock.receive'), [
            'catalog_id' => $this->catalog()->id,
            'quantity' => 4,
            'unit_price' => 100,
            'purchase_date' => '2026-03-15',
            'condition' => 'New',
            'record_expense' => true,
        ])->assertRedirect();

        $this->assertSame('شراء معدات: 4 وحدة', Transaction::first()->description);
    }
```

Check first how the app picks the request locale (grep `setLocale` in `app/Http/Middleware`): if a middleware resets the locale from the user's `preferred_lng`, create the user with `preferred_lng => 'ar'` instead of calling `app()->setLocale('ar')`.

- [ ] **Step 2: Run** `php artisan test --filter=EquipmentReceiveStockTest` — the three new tests FAIL.

- [ ] **Step 3: Validation** — in `ReceiveStockRequest::rules()` replace the `unit_price` and `received_via` lines:

```php
            // An expense of 0 would be silently skipped: ask for the price instead.
            'unit_price' => $this->boolean('record_expense')
                ? ['required', 'numeric', 'gt:0']
                : ['nullable', 'numeric', 'min:0'],
            // When no money is spent the reason must be chosen (the form starts empty).
            'received_via' => [
                Rule::requiredIf(fn () => ! $this->boolean('record_expense')),
                'nullable',
                'in:purchase,donation,opening_balance,adjustment',
            ],
```

Add:

```php
    public function messages(): array
    {
        return [
            'unit_price.required' => __('validation.equipment_expense_needs_price'),
            'unit_price.gt' => __('validation.equipment_expense_needs_price'),
            'received_via.required' => __('validation.equipment_source_required'),
        ];
    }
```

`purchase` stays accepted without an expense (the API and the demo seeder record stock whose expense was entered in Transactions); the form only offers the three other choices.

- [ ] **Step 4: Messages** — `lang/ar/validation.php` (next to `branch_code_needs_latin`):

```php
    'equipment_expense_needs_price' => 'اكتب سعر الوحدة لتسجيل المصروف.',
    'equipment_source_required' => 'اختر مصدر الاستلام.',
```

`lang/fr/validation.php`: `'Saisissez le prix unitaire pour enregistrer la dépense.'`, `'Choisissez la provenance.'`. `lang/en/validation.php`: `'Enter the unit price to record the expense.'`, `'Choose where the stock came from.'`.

- [ ] **Step 5: Translated description** — `EquipmentStockService.php:209`:

```php
                    'description' => __('Equipment purchase: :count unit(s)', ['count' => $quantity]),
```

`lang/ar.json`: `"Equipment purchase: :count unit(s)": "شراء معدات: :count وحدة",` · `lang/fr.json`: `"Equipment purchase: :count unit(s)": "Achat de matériel : :count unité(s)",` (insert-only, keep the file's key order style).

- [ ] **Step 6: The form** — `Show.vue` `receiveForm`: `received_via: '',`. In the source select (the `v-if="!receiveForm.record_expense"` block) add a first `<option value="" disabled>{{ t('equipment.choose_source') }}</option>`, `required` on the `<select>`, and `<InputError :message="receiveForm.errors.received_via" class="mt-1" />` after it. Keep the `transform` (it sends `purchase` when the box is ticked). After a successful save, `receiveForm.reset('notes', 'quantity', 'designation')` already leaves `received_via` as chosen — fine. i18n `equipment.choose_source`: ar «اختر المصدر», fr «Choisir la provenance», en «Choose the source» (check `equipment.choose_source` is free and `equipment` isn't a flat message key).

- [ ] **Step 7: Update existing posts** — every test that posts to `equipment.stock.receive` without `record_expense => true` must now send a source. Find them: `grep -rln "equipment.stock.receive" tests`. Add `'received_via' => 'donation'` to those payloads (or `'purchase'` where the test is about a purchase recorded elsewhere). Posts with `record_expense => true` and no or zero `unit_price` must get a price or assert the new error. Do not weaken any assertion.

- [ ] **Step 8: Run** `php artisan test --filter=Equipment` and `php artisan test --filter=ClosedSeasonLock` and `--filter=ActivityEquipmentEvents` — all PASS. `npm run i18n:check`.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/Equipment/ReceiveStockRequest.php app/Services/Equipment/EquipmentStockService.php resources/js/Pages/Equipment/Catalog/Show.vue lang/ar.json lang/fr.json lang/ar/validation.php lang/fr/validation.php lang/en/validation.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/
git commit -m "fix(equipment): receive stock asks for the source and a price for an expense"
```

(Stage only the test files you changed — list them explicitly.)

---

### Task 2: Unit import — expense only when asked

**Files:**
- Modify: `app/Http/Controllers/EquipmentItemController.php:495-565` (`import`)
- Modify: `resources/js/Pages/Equipment/Catalog/Show.vue:339-353` (import form) and `:662-684` (import modal)
- Modify: `lang/ar.json`, `lang/fr.json`, `resources/js/i18n/{ar,fr,en}.json`
- Test: `tests/Feature/EquipmentImportExportTest.php`

**Interfaces:**
- Consumes: `financeAccounts` prop already on `Show.vue` (same list as the receive form), `useFinanceAccountLabel`.
- Produces: import accepts `record_expense` (boolean, default false) and `finance_account_id` (active account); JSON string `"Equipment purchase: :identifier"`.

- [ ] **Step 1: Write the failing tests** — in `tests/Feature/EquipmentImportExportTest.php`, read how the existing tests build an upload (CSV through `UploadedFile::fake()->createWithContent` or a helper) and reuse that helper. Add:

```php
    #[Test]
    public function importing_units_with_prices_records_no_expense_unless_asked(): void
    {
        // one row with a purchase price of 1500, built like the file's other import tests
        $this->importUnits(['record_expense' => false]);

        $this->assertSame(0, Transaction::count());
        $this->assertEquals(1500, EquipmentItem::first()->unit_price ?? 1500);
    }

    #[Test]
    public function importing_units_with_the_box_ticked_records_one_expense_per_priced_row_in_the_chosen_caisse(): void
    {
        $account = FinanceAccount::where('is_active', true)->firstOrFail();

        $this->importUnits(['record_expense' => true, 'finance_account_id' => $account->id]);

        $this->assertSame(1, Transaction::count());
        $transaction = Transaction::first();
        $this->assertSame($account->id, $transaction->finance_account_id);
        $this->assertEquals(1500, $transaction->amount);
    }
```

Write `importUnits(array $extra)` as a private helper posting the one-row file plus `$extra` to `route('equipment.items.import', $catalog)`. If the item model has no `unit_price` set by import today, drop that assertion line (do not add a new behaviour here). If no active `FinanceAccount` exists in a fresh test DB, create one the way other finance tests do (grep `FinanceAccount::create` in tests). Update any existing test that expects an expense from a plain import: it now passes `record_expense => true`.

- [ ] **Step 2: Run** `php artisan test --filter=EquipmentImportExportTest` — the first new test FAILS (an expense is created today).

- [ ] **Step 3: Controller** — in `import()`:

```php
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
            'record_expense' => ['boolean'],
            'finance_account_id' => ['nullable', 'integer', Rule::exists('finance_accounts', 'id')->where('is_active', true)],
        ]);
        $recordExpense = $request->boolean('record_expense');
        $accountId = $request->integer('finance_account_id') ?: null;
```

(import `Illuminate\Validation\Rule` if missing). Pass `$recordExpense, $accountId` into the row closure (`use (...)`) and change the condition and the transaction:

```php
                    if ($recordExpense && is_numeric($data['purchase_price']) && (float) $data['purchase_price'] > 0) {
                        $transaction = Transaction::create([
                            'amount' => (float) $data['purchase_price'],
                            'transaction_date' => $item->purchase_date,
                            'transaction_type' => 'expense',
                            'category' => 'equipment',
                            'description' => __('Equipment purchase: :identifier', ['identifier' => $item->unique_identifier]),
                            'recorded_by_user_id' => $request->user()?->id,
                            'finance_account_id' => $accountId,
                            'status' => 'Paid',
                        ]);
```

`lang/ar.json`: `"Equipment purchase: :identifier": "شراء معدات: :identifier",` · `lang/fr.json`: `"Equipment purchase: :identifier": "Achat de matériel : :identifier",`.

- [ ] **Step 4: The modal** — `importForm = useForm({ file: null, record_expense: false, finance_account_id: props.financeAccounts[0]?.id || '' })`; `importForm.reset()` after success keeps these defaults. In the modal, between the file input and the buttons, add the same checkbox card and caisse select as the receive form (copy its markup: `equipment.record_as_expense`, a new hint key `equipment.import_expense_hint` — ar «كل سطر فيه سعر شراء يُسجَّل مصروفًا مدفوعًا من الصندوق المختار.», fr «Chaque ligne avec un prix d'achat devient une dépense payée depuis la caisse choisie.», en «Each row with a purchase price becomes a paid expense from the chosen caisse.»; then `source_register` select shown only when ticked, `InputError` for `finance_account_id`). Also check `import_items_hint` text: if it says prices become expenses, reword it (ar/fr/en) to drop that.

- [ ] **Step 5: Run** `php artisan test --filter=Equipment`, `npm run i18n:check`, `npm run build` — PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/EquipmentItemController.php resources/js/Pages/Equipment/Catalog/Show.vue lang/ar.json lang/fr.json resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/EquipmentImportExportTest.php
git commit -m "fix(equipment): importing units records an expense only when asked"
```

---

### Task 3: Arabic condition labels and the return form's condition

**Files:**
- Create: `resources/js/lib/stateLabel.js`, `tests/js/stateLabel.test.mjs`
- Modify: `resources/js/Pages/Equipment/Catalog/Show.vue:330-337`, `resources/js/Pages/Equipment/History.vue:87-93`, `resources/js/Pages/Equipment/Inventory.vue:130`, `resources/js/Pages/Inventory/Session.vue:191,198,228,229`
- Modify: `resources/js/Components/ReturnRentalModal.vue`, `resources/js/Pages/Equipment/Out.vue:112`, `app/Http/Controllers/EquipmentOutController.php:40-57`
- Test: `tests/Feature/EquipmentOutPageTest.php` (create if no out-page test exists; grep `equipment.out` in tests first and extend that file instead)

**Interfaces:**
- Produces: `stateLabel(t, value)` → string (translated via the lowercased, underscored key; raw value when no key; `'—'` for empty); `ReturnRentalModal` prop `condition` (String, default `'Good'`); out-page rows carry `item_condition`.

- [ ] **Step 1: Failing JS test** — `tests/js/stateLabel.test.mjs` (match the style of existing `tests/js/*.mjs`, `node:test` + `assert`):

```js
import test from 'node:test';
import assert from 'node:assert/strict';
import { stateLabel } from '../../resources/js/lib/stateLabel.js';

const dict = { new: 'جديد', under_repair: 'قيد الإصلاح' };
const t = (k) => dict[k] ?? k;

test('translates through the lowercased underscored key', () => {
    assert.equal(stateLabel(t, 'New'), 'جديد');
    assert.equal(stateLabel(t, 'Under Repair'), 'قيد الإصلاح');
});
test('falls back to the raw value, and a dash when empty', () => {
    assert.equal(stateLabel(t, 'Weird'), 'Weird');
    assert.equal(stateLabel(t, null), '—');
});
```

Run `node --test tests/js/stateLabel.test.mjs` — FAIL (module missing).

- [ ] **Step 2: Helper** — `resources/js/lib/stateLabel.js`:

```js
// Translate an equipment enum value (status/condition) via its lowercased,
// underscored i18n key ("Under Repair" → under_repair), falling back to the raw value.
export function stateLabel(t, value) {
    if (!value) return '—';
    const key = String(value).toLowerCase().replaceAll(' ', '_');
    const translated = t(key);
    return translated === key ? value : translated;
}
```

Run the test — PASS. Replace the local `stateLabel` in `Show.vue` and `History.vue` with `const stateLabel = (v) => stateLabelOf(t, v);` (import `{ stateLabel as stateLabelOf }`). In `Inventory.vue:130` print `stateLabel(c.condition)`; in `Session.vue` print `stateLabel(...)` at lines 191, 198 (option text; keep `:value="c"`), 228, 229.

- [ ] **Step 3: Failing feature test** — out-page rows expose the unit's condition:

```php
    #[Test]
    public function each_open_rental_carries_its_units_condition(): void
    {
        $user = User::factory()->admin()->create(['email_verified_at' => now()]);
        // a single-unit item in condition 'Fair', rented to a player (build it the way EquipmentAssignmentTest / EquipmentLotRentalTest do)
        $this->actingAs($user)->get(route('equipment.out'))
            ->assertInertia(fn ($page) => $page->where('rentals.data.0.item_condition', 'Fair'));
    }
```

Run — FAIL.

- [ ] **Step 4: Implement** — `EquipmentOutController` row: `'item_condition' => $rental->equipmentItem?->condition,`. `ReturnRentalModal.vue`: add prop `condition: { type: String, default: 'Good' }`; in the `watch` after `form.reset()` set `form.condition = CONDITIONS.includes(props.condition) ? props.condition : 'Good';`. `Show.vue:657`: `<ReturnRentalModal ... :condition="selectedItem?.condition" />`. `Out.vue:112`: `:condition="returning?.item_condition"`. Leave the server default `'Good'` in `returnItem()` for API callers that send nothing.

- [ ] **Step 5: Run** the new test, `php artisan test --filter=Equipment`, `node --test tests/js/*.mjs`, `npm run build` — PASS. Open «تقرير المخزون» and a stock-take session in the dev app in Arabic: conditions read «جديد/جيد/…».

- [ ] **Step 6: Commit**

```bash
git add resources/js/lib/stateLabel.js tests/js/stateLabel.test.mjs resources/js/Pages/Equipment/Catalog/Show.vue resources/js/Pages/Equipment/History.vue resources/js/Pages/Equipment/Inventory.vue resources/js/Pages/Inventory/Session.vue resources/js/Components/ReturnRentalModal.vue resources/js/Pages/Equipment/Out.vue app/Http/Controllers/EquipmentOutController.php tests/Feature/<the out-page test file>
git commit -m "fix(equipment): conditions shown in the user's language; return starts on the unit's condition"
```

---

### Task 4: Safety — catalog delete while out, stock-take numbering, storage rename

**Files:**
- Modify: `app/Http/Controllers/EquipmentCatalogController.php:162-190`
- Modify: `app/Http/Controllers/InventoryController.php:47-53`
- Modify: `app/Http/Controllers/StorageLocationController.php:31-40`
- Modify: `resources/js/i18n/{ar,fr,en}.json` (flash key)
- Test: `tests/Feature/EquipmentCatalogDeleteTest.php` (create), `tests/Feature/InventoryReferenceTest.php` (create), `tests/Feature/StorageLocationTest.php` (extend)

**Interfaces:**
- Produces: flash key `flash.equipment_catalog_units_out`.

- [ ] **Step 1: Failing tests**

`tests/Feature/EquipmentCatalogDeleteTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentCatalogDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    /** A catalog with one lot of 5, 2 of them out on an open rental. */
    private function catalogWithUnitsOut(): EquipmentCatalog
    {
        $catalog = EquipmentCatalog::create(['name' => 'Ballons', 'category' => 'Balls']);
        $item = EquipmentItem::create(['catalog_id' => $catalog->id, 'quantity' => 5, 'condition' => 'New', 'status' => 'Available', 'purchase_date' => '2026-01-10']);
        // Create the open rental the way EquipmentLotRentalTest does (rentable, type 'rental', quantity 2, checkout_date, no return_date).
        return $catalog;
    }

    #[Test]
    public function a_catalog_with_units_out_cannot_be_deleted(): void
    {
        $catalog = $this->catalogWithUnitsOut();

        $this->actingAs($this->admin())->delete(route('equipment.catalogs.destroy', $catalog))
            ->assertSessionHas('error', 'flash.equipment_catalog_units_out');

        $this->assertModelExists($catalog);
    }

    #[Test]
    public function a_bulk_delete_containing_such_a_catalog_deletes_nothing(): void
    {
        $out = $this->catalogWithUnitsOut();
        $free = EquipmentCatalog::create(['name' => 'Cônes', 'category' => 'Training Equipment']);

        $this->actingAs($this->admin())->delete(route('equipment.catalogs.bulk-destroy'), ['ids' => [$out->id, $free->id]])
            ->assertSessionHas('error', 'flash.equipment_catalog_units_out');

        $this->assertModelExists($out);
        $this->assertModelExists($free);
    }

    #[Test]
    public function a_catalog_whose_units_are_all_back_can_be_deleted(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Chasubles', 'category' => 'Apparel']);

        $this->actingAs($this->admin())->delete(route('equipment.catalogs.destroy', $catalog))
            ->assertRedirect(route('equipment.catalogs.index'));

        $this->assertModelMissing($catalog);
    }
}
```

Check the bulk route's HTTP verb and payload in `routes/web.php` / `EquipmentBulkDeleteTest` and match them. Fill in the rental creation from an existing test.

`tests/Feature/InventoryReferenceTest.php`: start two sessions (`post(route('inventory.store'), ['type' => 'yearly', 'session_date' => '2026-11-01'])`), delete the first (`delete(route('inventory.destroy', $first))`), start a third → assert it gets `INV-<year>-03` and the request succeeds (today it collides with `-02`). Use `now()->format('Y')` for the year.

`tests/Feature/StorageLocationTest.php`: add

```php
    #[Test]
    public function renaming_a_storage_place_renames_it_on_its_units(): void
    {
        // admin; StorageLocation 'Magasin'; one EquipmentItem with location 'Magasin', one with 'Vestiaire'
        // put(route('storage-locations.update', $loc), ['name' => 'Magasin central'])
        // assert the first item's location is 'Magasin central', the second still 'Vestiaire'
    }
```

Write the body following the comments with the file's existing helpers.

- [ ] **Step 2: Run** the three files — the new tests FAIL.

- [ ] **Step 3: Catalog delete** — in `EquipmentCatalogController`:

```php
    /** Units still out with someone: deleting would erase who holds them. */
    private function hasUnitsOut(array $catalogIds): bool
    {
        return EquipmentRental::query()
            ->whereNull('return_date')
            ->whereHas('equipmentItem', fn ($q) => $q->whereIn('catalog_id', $catalogIds))
            ->exists();
    }
```

At the top of `destroy()`: `if ($this->hasUnitsOut([$catalog->id])) { return back()->with('error', 'flash.equipment_catalog_units_out'); }`. In `bulkDestroy()` after loading `$catalogs`: same check with `$catalogs->pluck('id')->all()`. Import `App\Models\EquipmentRental`. Check the relation name on `EquipmentRental` (`equipmentItem`, used by `EquipmentOutController`).

i18n `flash.equipment_catalog_units_out`: ar «لا يمكن الحذف: بعض وحدات هذه المعدات ما زالت خارج المخزن. أرجعها أولًا.», fr «Suppression impossible : des unités sont encore sorties. Faites-les d'abord revenir.», en «Cannot delete: some units are still out. Take them back first.».

- [ ] **Step 4: Stock-take reference** — replace the `$seq` line:

```php
            // From the highest number used this year: a count would reuse a
            // deleted session's successor and collide with the unique reference.
            $prefix = 'INV-'.$year.'-';
            $seq = InventorySession::query()
                ->where('reference', 'like', $prefix.'%')
                ->pluck('reference')
                ->map(fn (string $ref) => (int) substr($ref, strlen($prefix)))
                ->max() + 1;
```

If `InventorySession` uses `SoftDeletes`, add `->withTrashed()` (the unique index still sees trashed rows).

- [ ] **Step 5: Storage rename** — in `update()`:

```php
        DB::transaction(function () use ($storageLocation, $data) {
            $old = $storageLocation->name;
            $storageLocation->update($data);
            if ($old !== $data['name']) {
                EquipmentItem::where('location', $old)->update(['location' => $data['name']]);
            }
        });
```

Import `DB` and `EquipmentItem`. Leave stock-take snapshots (`expected_location`) as they were: they are history.

- [ ] **Step 6: Run** the three files and `php artisan test --filter=Equipment`, `--filter=Inventory`, `npm run i18n:check` — PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/EquipmentCatalogController.php app/Http/Controllers/InventoryController.php app/Http/Controllers/StorageLocationController.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/EquipmentCatalogDeleteTest.php tests/Feature/InventoryReferenceTest.php tests/Feature/StorageLocationTest.php
git commit -m "fix(equipment): no catalog delete while units are out; stock-take numbers; storage rename carries to units"
```

---

### Task 5: Hide equipment buttons without the right

**Files:**
- Modify: `resources/js/Pages/Equipment/Catalog/Index.vue`, `Catalog/Show.vue`, `Inventory/Index.vue`, `Inventory/Session.vue`, `Settings/EquipmentCategories.vue`, `Settings/StorageLocations.vue`

**Interfaces:**
- Consumes: `useCan()` from `resources/js/Composables/useCan.js` → `{ can(module, action) }` (superadmin → always true).

Each button gets the right the server checks (see Global Constraints):

| Page | Element | `v-if` |
|---|---|---|
| Catalog/Index | «إضافة معدات» (`add_equipment`) | `can('equipment','add')` |
| Catalog/Index | import, «حذف المحدد», row pencil | `can('equipment','edit')` |
| Catalog/Index | row trash | `can('equipment','delete')` |
| Catalog/Show | «استلام مخزون», import, catalog pencil, «حذف المحدد», row return/«إيجار»/reclassify/repair/«تم الإصلاح»/«مفقود»/«استعادة»/unit pencil | `can('equipment','edit')` (combine with each button's existing `v-if` using `&&`) |
| Catalog/Show | unit trash | existing condition `&& can('equipment','delete')` |
| Inventory/Index | «بدء الجرد» and its form | `can('inventory','add')` |
| Inventory/Index | row trash | `can('inventory','delete')` |
| Session | participant ×, «إضافة», participants save, «تحديد الكل موجود», «حفظ العد», «إنهاء الجرد» | existing `isOpen` condition `&& can('inventory','edit')` |
| Session | «موجود»/«مفقود» toggles, condition/location selects | `:disabled="!canCount"` where `const canCount = computed(() => isOpen.value && can('inventory','edit'))` (or the page's open flag) |
| EquipmentCategories | add form (whole `<form>`) | `can('equipment_categories','add')` |
| EquipmentCategories | pencil / trash | `can('equipment_categories','edit')` / `can('equipment_categories','delete')` |
| StorageLocations | add form / pencil / trash | `can('storage_locations','add'|'edit'|'delete')` |

The bulk-select checkboxes stay (selection alone does nothing). History and export stay visible (view).

- [ ] **Step 1: Implement** the table: `import { useCan } from '@/Composables/useCan';` and `const { can } = useCan();` in each page; add the conditions. Do not change layout classes; a hidden add form must not leave an empty card (wrap the card if needed).

- [ ] **Step 2: Verify** — `npm run build`; in the dev app create (or reuse) a role with only `equipment: view`, `inventory: view`, `equipment_categories: view`, `storage_locations: view`; sign in as a user with it and open the catalog list, a catalog page, «الجرد», a stock-take session, «فئات المعدات», «أماكن التخزين»: no action button shows; as a superadmin everything shows. Then `php artisan test --filter=Equipment` (no PHP change, sanity).

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Equipment/Catalog/Index.vue resources/js/Pages/Equipment/Catalog/Show.vue resources/js/Pages/Inventory/Index.vue resources/js/Pages/Inventory/Session.vue resources/js/Pages/Settings/EquipmentCategories.vue resources/js/Pages/Settings/StorageLocations.vue
git commit -m "fix(equipment): hide actions the user has no right to"
```

---

### Task 6: `equipment` topic, nav targets and demo store room

**Files:**
- Modify: `resources/help/topics.php`, `resources/js/i18n/{ar,fr,en}.json` (`help.topic.equipment`)
- Modify: `resources/js/Components/Sidebar/SidebarSection.vue:70`
- Create: `database/seeders/HelpDemoEquipmentSeeder.php`
- Modify: `database/seeders/HelpDemoSeeder.php` (call it after `HelpDemoUsersSeeder`)
- Test: `tests/Feature/Help/HelpDemoEquipmentSeederTest.php`

**Interfaces:**
- Consumes: `HelpDemoSeeder::assertThrowawayDatabase()`; `EquipmentCategory`, `StorageLocation`, `EquipmentCatalog`; `EquipmentStockService::receive(array $data, ?int $userId, ?User $actor): EquipmentItem` and `splitLot(EquipmentItem, int $qty, string $condition, ?int $userId, ?string $notes): EquipmentItem`; `EquipmentLifecycleService` (`rentOut`, `returnItem`, `sendToRepair` — read their signatures in `app/Services/Equipment/EquipmentLifecycleService.php`); `InventoryController::store` as the model for a stock-take session (snapshot lines); demo players from `HelpDemoSeeder` (player 1 أحمد بن علي; find أمين قاسمي and one swimmer by name, not id).
- Produces: topic `equipment`; nav `data-help`: `nav-equipment` (/equipment/catalogs), `nav-equipment-out` (/equipment/out), `nav-stocktake` (/equipment/stocktake), `nav-equipment-categories` (/equipment-categories), `nav-storage-locations` (/storage-locations); the demo facts below; catalog ids, the stopwatch serials and the stock-take session id written into the task report for the shot tasks.

**Demo facts** (everything dated relative to the seed day `D`; nothing creates a `Transaction` — the 18 000 «شراء كرات وأقماع» expense of `HelpDemoFinanceSeeder` already covers the balls and cones, so the finance totals the 2c articles quote stay unchanged):

Equipment categories: rename the migration's English categories to Arabic and give codes (keep ids): Balls → «كرات» `BALL`, Goals & Nets → «مرامي وشباك» `GOAL`, Training Equipment → «معدات التدريب» `TRN`, Apparel → «ملابس» `APP`, Protective Gear → «معدات الحماية» `PRT`, Accessories → «إكسسوارات» `ACC`, Maintenance → «صيانة» `MNT`, Other → «أخرى» `OTH` (renaming must carry to catalogs the way the category controller does; set the catalogs' `category` after renaming).

Storage places: «المخزن الرئيسي», «غرفة الملابس», «مخزن المسبح».

| # | Catalog | Category | Price | Tracked | Received (via service) | Then |
|---|---|---|---|---|---|---|
| 1 | «كرة قدم مقاس 5» | كرات | 3 000 | no | 20, D−60, «جديد», «المخزن الرئيسي», branch Football, `received_via` purchase, no expense | 2 lent (إعارة) to أحمد بن علي D−10, expected 7 days → «متأخر» |
| 2 | «قمع تدريب» | معدات التدريب | 150 | no | 40, D−60, «جديد», «المخزن الرئيسي», shared (no branch), purchase, no expense | 10 lent to أمين قاسمي D−20, returned D−13 |
| 3 | «صدرية تدريب» | ملابس | 600 | no | 30, D−45, «جيد», «غرفة الملابس», Football, donation | split 3 → «تالف» |
| 4 | «ساعة توقيت» | إكسسوارات | 2 500 | yes | 3, D−30, «جديد», «المخزن الرئيسي», shared, opening balance, designation «ساعة توقيت» → «ساعة توقيت 1…3» | unit 2 sent to repair D−5 |
| 5 | «مرمى متنقل» | مرامي وشباك | 18 000 | yes | 2, D−90, «جيد», «المخزن الرئيسي», Football, opening balance, designation «مرمى» → «مرمى 1», «مرمى 2» | «مرمى 1» lent to outside person «مراد بلقاسم» 0550123456, D−2, expected 5 days |
| 6 | «نظارات سباحة» | معدات الحماية | 800 | no | 25, D−40, «جديد», «مخزن المسبح», Swimming, donation | 1 handed out (تخصيص) to a swimmer player |

Stock-take: one **completed** session, type yearly, dated D−100 (or the season start if later), all lines found (mirror `InventoryController::store` + `complete` with nothing missing — no Lost marks); one **in progress** session, type monthly, dated D, notes «جرد شهري», participants أمين قاسمي; lines snapshotted after all the above; no counts saved yet. Its reference must follow the fixed numbering (Task 4).

- [ ] **Step 1: Write the failing test** — `tests/Feature/Help/HelpDemoEquipmentSeederTest.php` (copy the shape of `HelpDemoUsersSeederTest`: `travelTo('2026-11-18 10:00:00')`, `seed(HelpDemoSeeder::class)`). Assert:
  - 6 catalogs with the names above; categories «كرات» code `BALL` etc.; 3 storage places;
  - catalog 1 available = 18 of 20, one open rental to player أحمد بن علي, `is_overdue` true;
  - catalog 2: 40 available, its rental returned (`return_date` not null);
  - catalog 3: lots 27 «جيد» + 3 «تالف»;
  - catalog 4: 3 units, serials non-null and distinct, designations «ساعة توقيت 1/2/3», unit 2 status `Under Repair`;
  - catalog 5: «مرمى 1» out to external «مراد بلقاسم» (not overdue), «مرمى 2» available;
  - catalog 6: one open assignment;
  - `Transaction::where('category', 'equipment')->count()` = 1 (the finance seeder's own entry; check its category value) and total expense still 100 700 (the 2c figure: assert the same way `HelpDemoFinanceSeederTest` does);
  - 2 inventory sessions: one `completed` with no Lost item, one `in_progress` dated the seed day;
  - no `EquipmentItem` with status `Lost`.

Run `php artisan test --filter=HelpDemoEquipmentSeederTest` — FAIL (class missing).

- [ ] **Step 2: Seeder** — `HelpDemoEquipmentSeeder::run()`: `HelpDemoSeeder::assertThrowawayDatabase();` then build the table through the services, as `demo` (actor + user id) so history and activity look real. Use `CarbonImmutable::today()` for D. For back-dated rentals pass the dates to the lifecycle service if it accepts them; otherwise update `checkout_date` / `due_date` / `return_date` right after (and keep the history rows consistent: update their `event_timestamp`). Call it from `HelpDemoSeeder::run()` after `HelpDemoUsersSeeder`.

- [ ] **Step 3: Run** the seeder test and `php artisan test --filter=Help` — PASS (older seeder tests too: nothing they count changed).

- [ ] **Step 4: Topic and nav** — `topics.php` after `settings`:

```php
        'equipment' => ['icon' => 'equipment', 'module' => 'equipment', 'articles' => ['equipment-lists', 'add-catalog', 'receive-stock', 'lend-assign', 'return-equipment', 'damage-repair-lost', 'import-export', 'stock-take', 'inventory-report']],
```

i18n `help.topic.equipment` after `help.topic.settings`: ar «المعدات والمخزن», fr «Matériel et stock», en «Equipment & stock». `SidebarSection.vue:70`: add the five `'/equipment/catalogs': 'nav-equipment', '/equipment/out': 'nav-equipment-out', '/equipment/stocktake': 'nav-stocktake', '/equipment-categories': 'nav-equipment-categories', '/storage-locations': 'nav-storage-locations'` entries to the map. Check in `HelpLibrary` that `equipment-lists` (module `equipment_categories`) and `stock-take` (module `inventory`) show for a user with those rights only (article module overrides the topic's). `help:check` will complain about missing articles until Tasks 7–9: if `topics.php` listing unknown slugs fails `help:check`, add the topic line in Task 7 instead and keep only the nav map, i18n and seeder here.

- [ ] **Step 5: Run** `php artisan help:check`, `php artisan test --filter=Help`, `npm run i18n:check`, `npm run build` — PASS.

- [ ] **Step 6: Commit**

```bash
git add database/seeders/HelpDemoEquipmentSeeder.php database/seeders/HelpDemoSeeder.php tests/Feature/Help/HelpDemoEquipmentSeederTest.php resources/js/Components/Sidebar/SidebarSection.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json resources/help/topics.php
git commit -m "feat(help): equipment demo store room, topic and nav targets"
```

---

## Article tasks (7–9): common procedure

For each article: (1) read the page component(s) and controller for every step and quote labels from `ar.json`; (2) add `data-help` attributes (attribute only) to what the steps ring; (3) add shots to `resources/help/shots.js` (reader's state at that step: `before` fills fields / opens the modal, never saves; use `navIntoView(page, '<nav name>')` for sidebar targets as the 2d shots do); (4) `npm run build && npm run help:shots -- <names>`; (5) open every new light and dark image and check rings land on the right elements; (6) write the article with the house front matter (`title` «كيف…», `summary`, `module` when not `equipment`, `pages`, `keywords`, `related`), an `:::example` that follows the demo data, `:::step` cards with `::figure`; (7) `php artisan help:check` + `php artisan test --filter=Help`; (8) commit the articles, shots, `shots.js` and the touched Vue files explicitly.

Name shots `eq-<slug>-<state>`; name targets `eq-…` (catalog/units), `stk-…` (stock-take), `eqcat-…`, `loc-…`. Keep any shot that writes **last** in `shots.js` with an `after` undo (none planned in 3a).

### Task 7: Articles — `equipment-lists`, `add-catalog`, `receive-stock`

**Files:** Create `resources/help/ar/equipment/{equipment-lists,add-catalog,receive-stock}.md`; modify `shots.js`, `Settings/EquipmentCategories.vue`, `Settings/StorageLocations.vue`, `Equipment/Catalog/{Index,Create,Show}.vue` (`data-help` only); `public/help-shots/ar/**`.

1. `equipment-lists` — module `equipment_categories`, pages `[equipment-categories.index, storage-locations.index]`. Example: add the category «حقائب» with code `BAG`, then the place «مخزن القاعة». Steps: open «فئات المعدات» (nav ring) → type «الاسم» + «الرمز» (+ «الوصف») → «إضافة» (needs add) → open «أماكن التخزين» (nav ring) → type the place name → «إضافة». Facts: empty code → first 4 Latin letters of the name, else `CAT`; the code enters each unit's serial; renaming a category renames it on its catalogs; a used category can't be deleted (hover name «الفئة مستخدمة من طرف معدات ولا يمكن حذفها» — check key); tip: renaming a place now renames it on stored units (Task 4). The storage part needs `storage_locations` add: say so.
2. `add-catalog` — pages `[equipment.catalogs.index, equipment.catalogs.create]`. Example: «حبل قفز», category «معدات التدريب», price 400. Steps: open «العتــاد» (nav ring) → «إضافة معدات» (`add_equipment`, icon +; needs add) → «الاسم», «الفئة», «العلامة التجارية», «السعر» → «تتبع كل وحدة على حدة» explained (one numbered unit each, for valuable items like the demo «ساعة توقيت»; locked once stock is received — quote `equipment` lock message from `ar.json`) → «حفظ» → the catalog page opens (message «تم إنشاء كتالوج المعدات بنجاح.»). Similar-name hint: type «كرة قدم» to show «توجد معدات باسم مشابه: …» in a shot (never blocks). Say adding a catalog does not add stock: next article.
3. `receive-stock` — pages `[equipment.catalogs.show]`. Example: 10 «قمع تدريب» bought at 150 from «صندوق أقل من 14 سنة» or the caisse the demo shows first. Steps: open the catalog (row link) → «استلام مخزون» (icon +; needs edit) → «الكمية», «سعر الوحدة» (pre-filled from the catalog price), «التاريخ», «الحالة», «الموقع», branch chips (empty = shared) → tick «تسجيل كمصروف» and pick «الصندوق الذي يدفع المصروف» (one expense = price × quantity, paid, dated on the purchase date; error if no price — Task 1) → «حفظ» (message «تم استلام المخزون بنجاح.»). Second path, in its own step: box unticked → «مصدر الاستلام» must be chosen (تبرع / جرد افتتاحي / تسوية). Tracked catalogs: one shot on «ساعة توقيت» showing «المعرّف / الرقم التسلسلي» preview and «التسمية» (N numbered units, max 200, «التسمية N» continues after the highest number) — as a tip or its own step. Warning: an expense recorded here is a real expense in «المعاملات»; deleting the units later keeps it.

### Task 8: Articles — `lend-assign`, `return-equipment`, `damage-repair-lost`

**Files:** Create `resources/help/ar/equipment/{lend-assign,return-equipment,damage-repair-lost}.md`; modify `shots.js`, `Equipment/Catalog/Show.vue`, `Equipment/Out.vue`, `Components/ReturnRentalModal.vue` (`data-help` only); `public/help-shots/ar/**`.

1. `lend-assign` — pages `[equipment.catalogs.show]`. Example: lend 2 «كرة قدم مقاس 5» to a player for 7 days (demo: أحمد بن علي already holds 2 — pick another player in the shot, e.g. أمين قاسمي). Steps: open the catalog → row send icon (hover «إيجار»; needs edit) → choose «إعارة» or «تخصيص» (assignment = handed out for use, players only, never overdue — quote `equipment.assignment_hint`) → «لاعب» + pick the player, or «شخص خارجي» + «الاسم الكامل» + «الهاتف» (switching clears the choice) → «الكمية» (lots only; error if more than available), «تاريخ الإيجار», «مدة الإعارة المتوقعة» (shows «تاريخ الإرجاع»; empty = never overdue) → «إيجار» (message «تم تسليم المعدات بنجاح.»). Say no money is charged. The cross-branch warning never blocks.
2. `return-equipment` — pages `[equipment.out, equipment.catalogs.show]`. Example: أحمد بن علي brings back the 2 balls (shown «متأخر»). Steps: open «المعدات المُخرَجة» (nav ring) → tabs «الإعارات» / «التسليمات للعمل», search, the «متأخر» badge → return icon (hover «إرجاع»; needs edit) → «الكمية» (default = all still out; fewer keeps the loan open), «تاريخ الإرجاع», «الحالة» (starts on the unit's condition — Task 3; applied to single units only, for a lot use reclassify), «ملاحظات» → «إرجاع» (message «تم إرجاع المعدات بنجاح.»). Tip: the same return icon sits in the catalog's «بحوزة» column.
3. `damage-repair-lost` — pages `[equipment.catalogs.show, equipment.items.history]`. Example: 3 of the 30 «صدرية تدريب» are torn (demo already shows the split: use «قمع تدريب» 2 broken for the shot), the stopwatch «ساعة توقيت 2» at repair. Steps: reclassify icon (hover from `equipment.mark_damaged`; lots > 1 with available units) → «الكمية» + «الحالة» (default «تالف») → save (message «تم إعادة تصنيف الوحدات بنجاح.»; a new lot) → repair icon «إرسال للإصلاح» (only when available; confirm) → «تم الإصلاح» when back (confirm) → «مفقود» (closes the open loan) and «استعادة». Fact: repair and lost act on the whole lot: reclassify first to lose 3 of 20. Tip: «السجل» (history icon) shows every event of a unit — one shot of History for «ساعة توقيت 2».

### Task 9: Articles — `import-export`, `stock-take`, `inventory-report`

**Files:** Create `resources/help/ar/equipment/{import-export,stock-take,inventory-report}.md`; modify `shots.js`, `Equipment/Catalog/{Index,Show}.vue`, `Inventory/{Index,Session}.vue`, `Equipment/Inventory.vue`, the reports-centre page if a step rings it (`data-help` only); `public/help-shots/ar/**`.

1. `import-export` — pages `[equipment.catalogs.index, equipment.catalogs.show]`. Example: import a catalog list from Excel. Steps: «استيراد» on the list (icon upload; needs edit) → «تحميل النموذج» → fill columns (name, category, brand, price, description; the category must already exist) → choose the file + «استيراد» (message «تم استيراد :count معدة بنجاح.» with the count; exact duplicates skipped, similar names imported with a warning line) → units import on a catalog page: same flow, each row = one numbered unit, and «تسجيل كمصروف» + caisse (off by default, Task 2) → «تصدير» (xlsx/csv). Warning: with the box ticked, every priced row becomes a paid expense.
2. `stock-take` — module `inventory`, pages `[inventory.index, inventory.show]`. Example: the demo's in-progress «جرد شهري». Steps: open «الجرد» (nav ring) → «بدء الجرد» (needs inventory add) + «النوع», «التاريخ», «ملاحظة» (shot with the form open, not submitted) → on the session: «تحديد الكل موجود», then «موجود»/«مفقود» per line, condition/location selects → «حفظ العد» (needs edit) → «المشاركون» («إضافة مشارك», players only, «حفظ») → «إنهاء الجرد» (ring only; native confirm) → «تقرير» (PDF) and «تصدير». Warning: ending marks every «مفقود» unit as lost — cannot be undone (deleting the session doesn't undo it). Facts: lots count all-or-nothing; units already lost appear but marking them «موجود» doesn't restore them (use «استعادة»).
3. `inventory-report` — pages `[equipment.inventory]`. Example: check what the club owns before the general assembly. Steps: «العتــاد» → «تقرير المخزون» (icon clipboard) → totals, «قيمة الأصول» per branch, conditions (now Arabic), overdue loans → «طباعة» → reports centre equipment reports («قائمة المخزون», «العتاد خارج المخزن», «الوضعية والقيمة», «حركات العتاد», «خسائر الجرد»; the last needs the inventory right; the centre itself needs the reports right — check `ReportsCenterController` and say so). Ring the reports-centre sidebar entry if the step opens it.

---

### Task 10: Phase checks and merge

- [ ] **Step 1:** In the worktree: `php artisan help:check` (48 articles), `php artisan test --filter=Help`, full suite `php -d memory_limit=1G vendor/bin/phpunit`, `node --test tests/js/*.mjs`, `npm run i18n:check`, `npm run build`.
- [ ] **Step 2:** Full `npm run help:shots` run; restore any older image that only changed by encoding noise or random receipt codes (`git diff --stat public/help-shots` — keep only real changes; an older shot that shows the sidebar may legitimately change if nav attributes moved nothing visible: it should not).
- [ ] **Step 3:** Manual Playwright pass on the demo DB: the 9 articles light/dark at 1280 and 320 px; the `?` panel on the catalog list, a catalog page, «المعدات المُخرَجة», «الجرد», «فئات المعدات»; Ctrl+K «مخزون» and «إعارة»; a user with only `equipment: view` sees articles 2–7 and 9 but not 1 and 8, and no action buttons (Task 5).
- [ ] **Step 4:** Whole-phase review (spec 3a section + this plan), fix findings, then `git fetch`, merge `main` into the branch, rerun Step 1, merge into local `main` (`push-only-verified-tip`: nothing is pushed without the owner's word).
- [ ] **Step 5:** Update `HANDOFF.md` (untracked) and the `help-center-part3` memory: what merged, owner nits found while documenting, deploy notes (no migration in 3a; online deploy needs `npm ci && npm run build`).
