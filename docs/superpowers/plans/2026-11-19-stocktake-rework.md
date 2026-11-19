# Stock-take Rework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Counters type how many units they find, units on loan and lost lots stay off the count sheet, and the report/PDF/export show the numbers in the app language.

**Architecture:** `InventoryController::store` snapshots only units in the club (new `on_loan_quantity` column for the «N معارة» note). `counts` accepts a per-line `found_quantity` capped at the line's expected. `complete` keeps writing off missing units through `EquipmentStockService::writeOffMissing`, with a safety net. `Inventory/Session.vue` gets a number box, a note box and unit tiles (pure helpers in `resources/js/lib/stocktake.js`). The PDF and export show «47 / 50».

**Tech Stack:** Laravel 13, PHPUnit `#[Test]`, Inertia 2 + Vue 3, `node --test` for JS helpers, mPDF Blade view, Playwright help screenshots.

**Spec:** `docs/superpowers/specs/2026-11-19-stocktake-rework-design.md`.

## Global Constraints

- Work in worktree `D:\irnb-stocktake` on branch `feat/stocktake` (vendor + node_modules copied, no junctions). Nothing pushed; merge to local main only after the phase checks.
- Stage explicit files only, never `git add -A`. Commit after each step that passes. Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- i18n: insert-only text edits in `resources/js/i18n/{ar,fr,en}.json` and `lang/{ar,fr}.json` (no reformatting the file, no re-sorting). `npm run i18n:check` must pass. A key segment after a message must not be a String method name (`link`, `search`, …).
- The word «دفعة» (lot) is never shown to users.
- Run commands in the foreground; leave no background server or job running when you report.
- `php artisan test` refuses while config is cached: run `php artisan config:clear` first.
- Seeders and sweeps never run against the real `database/database.sqlite`.

---

### Task 1: Count sheet backend (store, counts, complete)

**Files:**
- Create: `database/migrations/2026_11_19_100001_add_on_loan_quantity_to_inventory_session_items.php`
- Modify: `app/Models/InventorySessionItem.php` (fillable + cast)
- Modify: `app/Http/Controllers/InventoryController.php:39-91` (store), `:110-137` (counts), `:174-230` (complete)
- Test: `tests/Feature/StocktakeCountSheetTest.php` (new)

**Interfaces:**
- Produces: column `inventory_session_items.on_loan_quantity` (int, default 0), serialized on each line; `counts` payload per item `{id, found:boolean, found_quantity:int|null, actual_condition, actual_location, note}`; validation error key `items.{i}.found_quantity` when above the line's expected.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\InventorySession;
use App\Models\InventorySessionItem;
use App\Models\User;
use App\Services\Equipment\EquipmentLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The count sheet lists only units in the club, and counters type numbers. */
class StocktakeCountSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function lot(int $quantity, string $status = 'Available'): EquipmentItem
    {
        $catalog = EquipmentCatalog::firstOrCreate(['name' => 'Cones'], ['category' => 'Training']);

        return EquipmentItem::create([
            'catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01',
            'quantity' => $quantity, 'status' => $status, 'condition' => 'Good',
        ]);
    }

    private function lend(EquipmentItem $item, int $quantity): void
    {
        app(EquipmentLifecycleService::class)->rentOut($item, null, [
            'type' => 'rental', 'quantity' => $quantity, 'checkout_date' => now()->toDateTimeString(),
            'external_name' => 'نادي الجيران', 'external_phone' => '0555000000',
        ]);
    }

    private function start(): InventorySession
    {
        $this->actingAs($this->admin)->post(route('inventory.store'), ['type' => 'monthly', 'session_date' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        return InventorySession::latest('id')->firstOrFail();
    }

    private function line(InventorySession $s, EquipmentItem $item): ?InventorySessionItem
    {
        return $s->items()->where('equipment_item_id', $item->id)->first();
    }

    #[Test]
    public function units_on_loan_are_left_off_the_expected_count_and_noted(): void
    {
        $lot = $this->lot(20);
        $this->lend($lot, 5);

        $session = $this->start();
        $line = $this->line($session, $lot);

        $this->assertSame(15, $line->expected_quantity);
        $this->assertSame(5, $line->on_loan_quantity);
        $this->assertSame(15, $session->fresh()->total_expected);
    }

    #[Test]
    public function an_item_fully_on_loan_has_no_line(): void
    {
        $unit = $this->lot(1);
        $this->lend($unit, 1);

        $this->assertNull($this->line($this->start(), $unit));
    }

    #[Test]
    public function lost_lots_are_off_the_sheet_and_under_repair_stays(): void
    {
        $lost = $this->lot(3, 'Lost');
        $repair = $this->lot(1, 'Under Repair');
        $retired = $this->lot(2, 'Retired');

        $session = $this->start();

        $this->assertNull($this->line($session, $lost));
        $this->assertNull($this->line($session, $retired));
        $this->assertSame(1, $this->line($session, $repair)->expected_quantity);
    }

    #[Test]
    public function a_typed_partial_count_writes_off_only_the_missing_units(): void
    {
        $lot = $this->lot(40);
        $session = $this->start();
        $line = $this->line($session, $lot);

        $this->actingAs($this->admin)->put(route('inventory.counts', $session), ['items' => [
            ['id' => $line->id, 'found' => true, 'found_quantity' => 38, 'note' => 'قمعان تحت المدرج؟'],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('inventory.complete', $session))->assertSessionHasNoErrors();

        $this->assertSame(38, $lot->fresh()->quantity);
        $this->assertSame(2, (int) EquipmentItem::where('status', 'Lost')->sum('quantity'));
        $this->assertSame('قمعان تحت المدرج؟', $line->fresh()->note);
        $this->assertSame(38, $session->fresh()->total_found);
        $this->assertSame(2, $session->fresh()->total_missing);
    }

    #[Test]
    public function a_count_above_expected_is_refused(): void
    {
        $lot = $this->lot(20);
        $this->lend($lot, 5);
        $session = $this->start();
        $line = $this->line($session, $lot);

        $this->actingAs($this->admin)->put(route('inventory.counts', $session), ['items' => [
            ['id' => $line->id, 'found' => true, 'found_quantity' => 16],
        ]])->assertSessionHasErrors('items.0.found_quantity');

        $this->assertFalse($line->fresh()->counted);
    }

    #[Test]
    public function a_loan_made_during_the_count_is_never_written_off(): void
    {
        $lot = $this->lot(10);
        $session = $this->start();
        $line = $this->line($session, $lot);
        $this->lend($lot, 4); // after the sheet was made

        $this->actingAs($this->admin)->put(route('inventory.counts', $session), ['items' => [
            ['id' => $line->id, 'found' => true, 'found_quantity' => 5],
        ]]);
        $this->actingAs($this->admin)->post(route('inventory.complete', $session));

        // 5 expected-but-missing, but only 6 are in the club and 5 were seen: write off 1.
        $this->assertSame(1, (int) EquipmentItem::where('status', 'Lost')->sum('quantity'));
        $this->assertSame(9, $lot->fresh()->quantity);
    }

    #[Test]
    public function an_item_lost_since_the_sheet_was_made_is_skipped(): void
    {
        $lot = $this->lot(6);
        $session = $this->start();
        $line = $this->line($session, $lot);
        $lot->update(['status' => 'Lost']);

        $this->actingAs($this->admin)->put(route('inventory.counts', $session), ['items' => [
            ['id' => $line->id, 'found' => false, 'found_quantity' => 0],
        ]]);
        $this->actingAs($this->admin)->post(route('inventory.complete', $session));

        $this->assertSame(1, EquipmentItem::count(), 'no second lost lot');
        $this->assertSame(6, $lot->fresh()->quantity);
    }

    #[Test]
    public function a_single_unit_marked_missing_is_written_off(): void
    {
        $unit = $this->lot(1, 'Under Repair');
        $session = $this->start();
        $line = $this->line($session, $unit);

        $this->actingAs($this->admin)->put(route('inventory.counts', $session), ['items' => [
            ['id' => $line->id, 'found' => false],
        ]]);
        $this->actingAs($this->admin)->post(route('inventory.complete', $session));

        $this->assertSame('Lost', $unit->fresh()->status);
    }
}
```

If `rentOut` with `null` rentable needs different option names, read `app/Services/Equipment/EquipmentLifecycleService.php:28-80` and `database/seeders/HelpDemoEquipmentSeeder.php:150-156` (an outsider loan) and adjust only the `lend()` helper.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan config:clear && php artisan test --filter=StocktakeCountSheetTest`
Expected: FAIL (`on_loan_quantity` column missing, expected 20 not 15, lost lot still on the sheet, 16 accepted).

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Units of the lot that were out on loan when the count sheet was made.
     * They are not expected on the shelf; the line only notes them.
     */
    public function up(): void
    {
        Schema::table('inventory_session_items', function (Blueprint $table) {
            $table->unsignedInteger('on_loan_quantity')->default(0)->after('expected_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_session_items', function (Blueprint $table) {
            $table->dropColumn('on_loan_quantity');
        });
    }
};
```

Model `app/Models/InventorySessionItem.php`: add `'on_loan_quantity'` to `$fillable` after `'expected_quantity'`, and `'on_loan_quantity' => 'integer'` to `casts()`.

- [ ] **Step 4: `store` snapshot**

Replace the snapshot block (lines 67-83) with:

```php
            // Only units in the club are counted: lost lots are already written
            // off, and units out on loan are with the borrower, not on a shelf.
            $stock = app(EquipmentStockService::class);
            $items = EquipmentItem::whereNotIn('status', ['Retired', 'Lost'])
                ->with('rentals')
                ->get(['id', 'status', 'condition', 'location', 'quantity']);

            $expectedTotal = 0;
            foreach ($items as $it) {
                $onLoan = min($it->quantity, $stock->outstandingQuantity($it));
                $expected = $it->quantity - $onLoan;
                if ($expected < 1) {
                    continue; // wholly on loan: nothing to find
                }

                InventorySessionItem::create([
                    'inventory_session_id' => $session->id,
                    'equipment_item_id' => $it->id,
                    'expected_status' => $it->status,
                    'expected_condition' => $it->condition,
                    'expected_location' => $it->location,
                    'expected_quantity' => $expected,
                    'on_loan_quantity' => $onLoan,
                ]);
                $expectedTotal += $expected;
            }
            // Expected is a number of units to find, not a number of lines.
            $session->update(['total_expected' => $expectedTotal]);
```

`->with('rentals')` makes `outstandingQuantity` sum in memory (no N+1); `get([...])` must keep `id` so the relation loads.

- [ ] **Step 5: `counts` validation and save**

Keep the existing rules and add a per-line cap after `validate()`:

```php
        $lines = $session->items()->pluck('expected_quantity', 'id');
        $errors = [];
        foreach ($data['items'] as $i => $row) {
            $max = $lines[$row['id']] ?? null;
            if ($max !== null && isset($row['found_quantity']) && $row['found_quantity'] > $max) {
                $errors["items.$i.found_quantity"] = __('validation.max.numeric', ['attribute' => __('Found'), 'max' => $max]);
            }
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
```

In the update, a found line with no number means all expected units:

```php
                    'found_quantity' => $row['found']
                        ? ($row['found_quantity'] ?? ($lines[$row['id']] ?? 1))
                        : 0,
```

and set `'found' => $row['found'] && (($row['found_quantity'] ?? 1) > 0)` so typing 0 counts as not found.

- [ ] **Step 6: `complete` safety net**

Inside the loop, after `if (! $line->counted) continue;`, add:

```php
                // The item was lost or retired after the sheet was made: it has
                // already left stock, so the count must not condemn it again.
                if (! $line->item || in_array($line->item->status, ['Lost', 'Retired'], true)) {
                    continue;
                }
```

and cap the write-off at units physically in the club now (status ignored, so units under repair can still be written off):

```php
                if ($missingUnits > 0) {
                    $inClub = max(0, $line->item->quantity - $stock->outstandingQuantity($line->item));
                    $toWriteOff = min($missingUnits, max(0, $inClub - $foundUnits));
                    if ($toWriteOff > 0) {
                        // Only the missing units are condemned; the rest stay in service.
                        $stock->writeOffMissing($line->item, $toWriteOff, $session->conducted_by_user_id);
                    }
                }
```

(Replace the old `if ($line->item && $missingUnits > 0)` block. Totals `$found`/`$missing` stay as computed from the sheet.)

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter="StocktakeCountSheetTest|Inventory|Stocktake|ActivityEquipmentEvents"`
Expected: all PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_11_19_100001_add_on_loan_quantity_to_inventory_session_items.php app/Models/InventorySessionItem.php app/Http/Controllers/InventoryController.php tests/Feature/StocktakeCountSheetTest.php
git commit -m "feat(stocktake): count only units in the club, typed found numbers, safe write-off"
```

---

### Task 2: Count sheet screen (number box, note, on-loan note, unit tiles)

**Files:**
- Create: `resources/js/lib/stocktake.js`
- Test: `tests/js/stocktake.test.mjs`
- Modify: `resources/js/Pages/Inventory/Session.vue:28-37` (state), `:97-124` (tiles + save), `:194-210` (line markup)
- Modify: `resources/js/i18n/ar.json`, `fr.json`, `en.json` (insert keys)

**Interfaces:**
- Consumes: line fields `expected_quantity`, `on_loan_quantity`, `found_quantity`, `counted`, `found`, `note` (Task 1); `counts` payload `{id, found, found_quantity, actual_condition, actual_location, note}`.
- Produces: `initialFound(line) → number`, `unitTotals(lines, state) → {expected, found, missing}`, `clampFound(value, expected) → number` in `resources/js/lib/stocktake.js`; i18n keys `inventory_sheet.on_loan` («{n} معارة»), `inventory_sheet.of` («من {n}»), `inventory_sheet.note_placeholder` («ملاحظة»).

- [ ] **Step 1: Write the failing JS test**

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initialFound, unitTotals, clampFound } from '../../resources/js/lib/stocktake.js';

test('a new line starts with every expected unit found', () => {
    assert.equal(initialFound({ counted: false, expected_quantity: 40 }), 40);
    assert.equal(initialFound({ counted: true, found: true, found_quantity: 38, expected_quantity: 40 }), 38);
    assert.equal(initialFound({ counted: true, found: false, found_quantity: 0, expected_quantity: 40 }), 0);
    assert.equal(initialFound({ counted: true, found: true, found_quantity: null, expected_quantity: 1 }), 1);
});

test('typed numbers stay between 0 and expected', () => {
    assert.equal(clampFound('', 40), 0);
    assert.equal(clampFound(-3, 40), 0);
    assert.equal(clampFound(52, 40), 40);
    assert.equal(clampFound('38', 40), 38);
    assert.equal(clampFound(2.7, 40), 2);
});

test('tiles count units, not lines', () => {
    const lines = [{ id: 1, expected_quantity: 40 }, { id: 2, expected_quantity: 1 }, { id: 3, expected_quantity: 18 }];
    const state = { 1: { qty: 38 }, 2: { qty: 0 }, 3: { qty: 18 } };
    assert.deepEqual(unitTotals(lines, state), { expected: 59, found: 56, missing: 3 });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `node --test tests/js/stocktake.test.mjs`
Expected: FAIL, module not found.

- [ ] **Step 3: Implement the helpers**

```js
// Count-sheet arithmetic for Inventory/Session.vue. Everything is in units:
// a line of 40 cones where 38 turned up is 38 found and 2 missing.

export function initialFound(line) {
    if (!line.counted) return line.expected_quantity;
    if (!line.found) return 0;
    return line.found_quantity ?? line.expected_quantity;
}

export function clampFound(value, expected) {
    const n = Math.floor(Number(value));
    if (!Number.isFinite(n) || n < 0) return 0;
    return Math.min(n, expected);
}

export function unitTotals(lines, state) {
    let expected = 0;
    let found = 0;
    for (const line of lines) {
        expected += line.expected_quantity;
        found += state[line.id]?.qty ?? 0;
    }
    return { expected, found, missing: expected - found };
}
```

Run: `node --test tests/js/stocktake.test.mjs` → PASS. Commit:

```bash
git add resources/js/lib/stocktake.js tests/js/stocktake.test.mjs
git commit -m "feat(stocktake): unit arithmetic helpers for the count sheet"
```

- [ ] **Step 4: i18n keys (insert-only)**

Insert into each file, next to other `inventory`-area keys, as a nested object if the file nests (check how `"inventory"`-prefixed keys are stored with `grep -n '"inventory' resources/js/i18n/ar.json`; follow that style):

- ar: `"inventory_sheet": { "on_loan": "{n} معارة", "of": "من {n}", "note_placeholder": "ملاحظة" }`
- fr: `"inventory_sheet": { "on_loan": "{n} prêtés", "of": "sur {n}", "note_placeholder": "Note" }`
- en: `"inventory_sheet": { "on_loan": "{n} on loan", "of": "of {n}", "note_placeholder": "Note" }`

Run: `npm run i18n:check` → OK.

- [ ] **Step 5: Wire `Session.vue`**

Imports: `import { initialFound, unitTotals, clampFound } from '@/lib/stocktake';`

State per line (replace lines 30-37):

```js
props.session.items.forEach((line) => {
    state[line.id] = {
        qty: initialFound(line),
        actual_condition: line.actual_condition || line.expected_condition || '',
        actual_location: line.actual_location ?? line.expected_location ?? '',
        note: line.note || '',
    };
});
```

Tiles (replace `countedCount`/`foundCount`):

```js
const totals = computed(() => unitTotals(props.session.items, state));
```

and in the template use `totals.expected` / `totals.found` / `totals.missing` while `isOpen` (keep `session.total_*` when completed).

`markAllFound`: `props.session.items.forEach((l) => { state[l.id].qty = l.expected_quantity; });`

One payload builder used by both `saveCounts` and `saveCountsThen` (remove the duplicate):

```js
function payload() {
    return props.session.items.map((line) => {
        const qty = clampFound(state[line.id].qty, line.expected_quantity);
        return {
            id: line.id,
            found: qty > 0,
            found_quantity: qty,
            actual_condition: state[line.id].actual_condition || null,
            actual_location: state[line.id].actual_location || null,
            note: state[line.id].note || null,
        };
    });
}
```

Line markup (inside the `v-for="line in grp.lines"` row):
- Subtitle: after the location, add `<span v-if="line.on_loan_quantity > 0"> · {{ t('inventory_sheet.on_loan', { n: line.on_loan_quantity }) }}</span>`.
- Found control: when `line.expected_quantity === 1`, keep the two buttons but set `state[line.id].qty = 1` / `= 0` and highlight on `qty > 0` / `qty === 0` (keep the `data-help` attributes `stk-found-*` / `stk-missing-*`). When `> 1`: a label `{{ t('found') }}`, `<input type="number" min="0" :max="line.expected_quantity" v-model.number="state[line.id].qty" @blur="state[line.id].qty = clampFound(state[line.id].qty, line.expected_quantity)" :disabled="!canCount" :data-help="'stk-qty-' + line.equipment_item_id" class="w-16 rounded-lg border-slate-200 py-1 text-center text-xs dark:border-slate-700 dark:bg-slate-800" dir="ltr">` and `{{ t('inventory_sheet.of', { n: line.expected_quantity }) }}`.
- Condition/location selects: `:disabled="!canCount || !state[line.id].qty"`.
- Note: `<input v-model="state[line.id].note" maxlength="500" :placeholder="t('inventory_sheet.note_placeholder')" :disabled="!canCount" :data-help="'stk-note-' + line.equipment_item_id" class="w-full rounded-lg border-slate-200 py-1 text-xs sm:w-40 dark:border-slate-700 dark:bg-slate-800">`.
- Keep the row wrapping at 320 px (`flex-wrap` already there).

- [ ] **Step 6: Build and check**

Run: `npm run build && npm run test:js && npm run i18n:check`
Expected: build OK, all JS tests pass, i18n OK.

Manual check (foreground, stop it after): `php artisan serve --port=8766 --no-reload` on a scratch copy is not needed; it is enough that the build passes here. The browser check happens in Task 5.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/Inventory/Session.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(stocktake): typed counts, notes, on-loan note and unit tiles on the count sheet"
```

---

### Task 3: Report, PDF and export show the numbers

**Files:**
- Modify: `resources/js/Pages/Inventory/Session.vue:131-133` (discrepancies) and the completed table (`:219-241`)
- Modify: `resources/views/pdf/inventory-report.blade.php`
- Modify: `app/Http/Controllers/InventoryController.php` (`export`)
- Modify: `lang/ar.json`, `lang/fr.json` (insert missing PDF words), `app/Support/UiLang.php` catalogs only if the export headers need a new key (check where `col.result` lives with `grep -rn "col.result" app lang resources`)
- Test: `tests/Feature/EquipmentStocktakePdfTest.php` (add tests), `tests/Feature/StocktakeExportTest.php` (new)

**Interfaces:**
- Consumes: `expected_quantity`, `found_quantity`, `note` on lines (Task 1).
- Produces: a discrepancy = counted line that is not found, or `found_quantity < expected_quantity`, or condition/location changed. Found cell: `"{found} / {expected}"` when `expected_quantity > 1`, else found/missing word.

- [ ] **Step 1: Failing PDF tests**

Add to `EquipmentStocktakePdfTest`:

```php
    #[Test]
    public function a_partly_found_line_prints_found_of_expected(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Cones', 'category' => 'Training']);
        $item = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 38, 'condition' => 'Good']);
        $session = InventorySession::create(['reference' => 'INV-2', 'type' => 'monthly', 'session_date' => '2026-10-01', 'status' => 'completed', 'total_expected' => 40, 'total_found' => 38, 'total_missing' => 2]);
        InventorySessionItem::create([
            'inventory_session_id' => $session->id, 'equipment_item_id' => $item->id,
            'expected_status' => 'Available', 'expected_condition' => 'Good', 'expected_quantity' => 40,
            'counted' => true, 'found' => true, 'found_quantity' => 38, 'note' => 'تحت المدرج',
        ]);

        app()->setLocale('ar');
        $session->load(['items.item.catalog:id,name', 'conductedBy:id,name', 'participants.participant']);
        $html = view('pdf.inventory-report', ['club' => ClubHeader::data(), 'session' => $session])->render();

        $this->assertStringContainsString('38 / 40', $html);
        $this->assertStringContainsString('تحت المدرج', $html);
    }

    #[Test]
    public function the_arabic_stocktake_pdf_has_no_english_words(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'كرة', 'category' => 'Balls']);
        $item = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 1, 'condition' => 'Good']);
        $session = InventorySession::create(['reference' => 'INV-3', 'type' => 'ad_hoc', 'session_date' => '2026-10-01', 'status' => 'in_progress']);
        InventorySessionItem::create([
            'inventory_session_id' => $session->id, 'equipment_item_id' => $item->id,
            'expected_status' => 'Available', 'expected_condition' => 'Good', 'expected_quantity' => 1,
            'counted' => true, 'found' => false, 'found_quantity' => 0,
        ]);

        app()->setLocale('ar');
        $session->load(['items.item.catalog:id,name', 'conductedBy:id,name', 'participants.participant']);
        $html = view('pdf.inventory-report', ['club' => ClubHeader::data(), 'session' => $session])->render();

        $text = strip_tags(preg_replace('#<(style|script)\b.*?</\1>#s', '', $html));
        $text = preg_replace('/INV-\d+|\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?/', '', $text);
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z]{3,}/', $text, 'English left in: '.$text);
    }
```

Run: `php artisan test --filter=EquipmentStocktakePdfTest` → the two new tests FAIL.

- [ ] **Step 2: PDF view**

In `resources/views/pdf/inventory-report.blade.php`:
- The discrepancy filter (around line 27) also keeps `$l->counted && $l->found_quantity !== null && $l->found_quantity < $l->expected_quantity`.
- Found cell:

```blade
<td class="{{ $l->found ? 'found' : 'missing' }}">
    @if ($l->expected_quantity > 1)
        <span dir="ltr">{{ (int) $l->found_quantity }} / {{ $l->expected_quantity }}</span>
    @else
        {{ $l->found ? __('Found') : __('Missing') }}
    @endif
</td>
```

- Type and status: replace `__(ucwords(str_replace('_', ' ', $session->type)))` and the status equivalent with `\App\Support\UiLang::get($session->type)` and `\App\Support\UiLang::get($session->status)` if those keys exist in the UI catalog (check `grep -n '"ad_hoc"\|"in_progress"\|"monthly"' resources/js/i18n/ar.json`); otherwise add `"Monthly"`, `"Quarterly"`, `"Yearly"`, `"Ad Hoc"`, `"In Progress"`, `"Completed"` to `lang/ar.json` and `lang/fr.json` (insert-only).
- Every other `__('…')` in the view (`Participants`, `Item`, `Actual`, `Note`, `No discrepancies`, …): make sure each has an entry in `lang/ar.json` and `lang/fr.json`; insert the missing ones. Arabic: «المشاركون», «المعدة», «الفعلي», «ملاحظة», «لا فوارق»; use the wording the screen already uses where one exists (`grep` the screen key in `resources/js/i18n/ar.json`).

Run the PDF tests → PASS.

- [ ] **Step 3: Completed report on screen**

In `Session.vue`, the `discrepancies` filter adds `|| (l.found_quantity != null && l.found_quantity < l.expected_quantity)`. The found cell shows `<span dir="ltr">{{ l.found_quantity ?? 0 }} / {{ l.expected_quantity }}</span>` when `l.expected_quantity > 1`, the icon otherwise.

- [ ] **Step 4: Export test and change**

```php
<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\InventorySession;
use App\Models\InventorySessionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StocktakeExportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_export_has_found_and_expected_numbers_and_the_note(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
        $catalog = EquipmentCatalog::create(['name' => 'Cones', 'category' => 'Training']);
        $item = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 40]);
        $session = InventorySession::create(['reference' => 'INV-9', 'type' => 'monthly', 'session_date' => '2026-10-01', 'status' => 'in_progress']);
        InventorySessionItem::create([
            'inventory_session_id' => $session->id, 'equipment_item_id' => $item->id,
            'expected_status' => 'Available', 'expected_quantity' => 40,
            'counted' => true, 'found' => true, 'found_quantity' => 38, 'note' => 'تحت المدرج',
        ]);

        $csv = $this->actingAs($admin)->get(route('inventory.export', [$session, 'format' => 'csv']))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('38', $csv);
        $this->assertStringContainsString('40', $csv);
        $this->assertStringContainsString('تحت المدرج', $csv);
    }
}
```

(If the export format parameter is named differently, read `App\Support\Export::format` and adjust the query string only.)

In `InventoryController::export`, add two columns right after the result column: found quantity (`$l->counted ? (int) ($l->found ? ($l->found_quantity ?? $l->expected_quantity) : 0) : ''`) and expected quantity (`$l->expected_quantity`), with headers `col.found_quantity` / `col.expected_quantity`. Add those keys wherever `col.result` is defined (insert-only), ar «العدد الموجود» / «العدد المتوقع», fr «Quantité trouvée» / «Quantité attendue», en «Found qty» / «Expected qty».

Run: `php artisan test --filter="StocktakeExportTest|EquipmentStocktakePdfTest|ExportMenuWiring"` → PASS; `npm run build && npm run i18n:check` → OK.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Inventory/Session.vue resources/views/pdf/inventory-report.blade.php app/Http/Controllers/InventoryController.php lang/ar.json lang/fr.json tests/Feature/EquipmentStocktakePdfTest.php tests/Feature/StocktakeExportTest.php
# plus any catalog file where col.* keys were inserted
git commit -m "feat(stocktake): report, PDF and export show found/expected numbers in the app language"
```

---

### Task 4: Demo data, help article and screenshots

**Files:**
- Modify: `database/seeders/HelpDemoEquipmentSeeder.php:250-273` (`snapshot`) and the open count's counted state if the article needs it
- Modify: `tests/Feature/Help/HelpDemoEquipmentSeederTest.php` (numbers the article quotes)
- Modify: `resources/help/ar/equipment/stock-take.md`
- Modify: `resources/help/shots.js:1416-1455` (`eq-stock-take-*` entries)
- Retake: `public/help-shots/ar/**/eq-stock-take-*` (light + dark)

**Interfaces:**
- Consumes: store rule (Task 1), data-help targets `stk-qty-<itemId>`, `stk-note-<itemId>`, `stk-found-*`, `stk-missing-*` (Task 2), report numbers (Task 3).

- [ ] **Step 1: Seeder follows the store rule**

`snapshot()` must match `InventoryController::store`: skip `Retired` and `Lost`, `expected = quantity − outstanding` (`$this->stock->outstandingQuantity($it)` with `->with('rentals')`), skip lines with `expected < 1`, store `on_loan_quantity`, `total_expected` = sum of expected. For the completed count (`$found = true`) set `found_quantity = expected`.

In the open count (today), the demo state is: 2 balls on loan to أحمد (line shows «2 معارة»), «مرمى 1» lent to مراد بلقاسم (no line), «ساعة توقيت 2» under repair (on the sheet). Add to `HelpDemoEquipmentSeederTest` assertions for: the balls line `on_loan_quantity = 2` and expected = lot − 2; no line for «مرمى 1»; a line for «ساعة توقيت 2»; `total_expected` equal to the sum of lines.

Run: `php artisan test --filter=HelpDemoEquipmentSeeder` → PASS. Commit.

- [ ] **Step 2: Rewrite the article**

`resources/help/ar/equipment/stock-take.md` — keep front matter (add keywords `معارة`, `عدد`), keep steps 1-4, 6-7, warning, tip and the report paragraph, and update:
- Intro: «الجرد يقارن ما في البرنامج بما في المخازن فعلًا. يضع البرنامج في ورقة العد كل ما يجب أن يكون في المخازن. وأنت تكتب كم وجدت.»
- Example: «… وجد كل شيء إلا قمعين من الأقماع الأربعين، و«ساعة توقيت 3». و«مرمى 1» ليس في ورقة العد، لأنه معار لمراد بلقاسم.»
- Step 4 end: lines are per numbered unit or group of units; equipment out on loan is not on the sheet, and a group with some units lent shows «2 معارة» under its name and expects only the rest; lost equipment is not on the sheet; equipment «قيد الإصلاح» is on it.
- Step 5 becomes «اكتب كم وجدت»: single numbered unit → «مفقود» button as today (ساعة توقيت 3); a group → type the number in the box «موجود [ ] من 40» (type 38 for «قمع تدريب»); can't type more than the number after «من»; extra units come in through «استلام مخزون»; note box «ملاحظة» per line; «تحديد الكل موجود» puts every line back to full. Remove the «مرمى 1 … فاتركه موجود» sentence.
- Step 7 (إنهاء): the missing units become «مفقود» in their catalog page (2 cones split off as «مفقود», the other 38 stay); the tiles show units.
- Warning: drop «والوحدة المفقودة من قبل تظهر في ورقة العد أيضًا…» (no longer true); keep «استعادة».
- Report paragraph: the PDF shows «38 / 40» for groups and the notes; remove «تُكتب فيه الحالات بلغة البرنامج» only if still true wording — keep it (now everything is in the app language).
- Never use the word «دفعة».

Follow `resources/help/README.md` (or the help article rules already used in `resources/help/ar/equipment/*.md`): one action per step, every click ringed in a figure, quote only text the user can see.

- [ ] **Step 3: Shots**

In `resources/help/shots.js`, `eq-stock-take-lines`: before the shot, fill the cones box with 38 (`page.locator('[data-help="stk-qty-<conesItemId>"]').fill('38')`) and click «مفقود» for «ساعة توقيت 3»; targets include `stk-qty-<conesItemId>`, `stk-missing-9`, `stk-note-<id>`, `stk-condition-7`, `stk-location-7`. Find item ids with the seeded demo DB the shot script uses (read `scripts/help-shots.mjs` for how it seeds; never seed `database/database.sqlite`). Update the article's `::figure` rings to the new targets.

Run: `npm run build && npm run help:shots -- eq-stock-take` (check `scripts/help-shots.mjs` for the filter syntax; full run if no filter) then `php artisan help:check`.
Expected: shots written light + dark, guards silent, `Help content OK — 73 article(s).` If one shot flakes, rerun that shot alone. Older unrelated shots that changed only because of date-relative data: restore them with `git checkout -- <file>`; keep only the stock-take shots.

- [ ] **Step 4: Commit**

```bash
git add database/seeders/HelpDemoEquipmentSeeder.php tests/Feature/Help/HelpDemoEquipmentSeederTest.php resources/help/ar/equipment/stock-take.md resources/help/shots.js public/help-shots/ar/<the eq-stock-take files>
git commit -m "docs(help): stock-take article follows typed counts and on-loan rules; shots retaken"
```

---

### Task 5: Phase checks, browser QA, merge to local main

**Files:** none new (fixes only if a check fails).

- [ ] **Step 1: Full checks**

Run, all in the foreground:
- `php artisan config:clear && php artisan test` → all pass (≈3245).
- `npm run test:js` → all pass.
- `npm run i18n:check` → OK.
- `npm run build` → OK.
- `php artisan help:check` → 73 articles OK.

- [ ] **Step 2: Migration dry run**

On a VACUUM INTO copy of the dev DB (never the original): `php artisan migrate --force` with `DB_DATABASE=<copy>` and `APP_CONFIG_CACHE=<nonexistent path>` → only `2026_11_19_100001` runs; `migrate:rollback --step=1` then `migrate` again OK. Delete the copy.

- [ ] **Step 3: Browser QA (Playwright, demo DB from the help-shots seeding, port 8765, stop the server after)**

Check on desktop width and 320 px, light and dark:
- open count: balls line shows «2 معارة» and expects the rest; «مرمى 1» has no line; «ساعة توقيت 2» has a line;
- type 38 in the cones box, 52 is clamped to 40 on blur, «مفقود» on ساعة توقيت 3, a note; tiles show units live; «حفظ العد» then reload keeps the numbers and note;
- «إنهاء الجرد»: 2 cones become a «مفقود» group on the catalog page, 38 stay; the report shows «38 / 40» and the note; PDF opens with no English; CSV export has the numbers;
- a view-only user sees the sheet but cannot type.

- [ ] **Step 4: Merge**

Only when every check passes: in `D:\irnb-laravel`, check `git status` (untracked files are expected: HANDOFF.md etc.), `git log -1 main` equals the base the branch started from or the branch contains it, then `git merge --ff-only feat/stocktake`. Rebuild main assets: `npm run build`. Do NOT push. Migrate the dev DB only after a VACUUM INTO backup in `storage/app/private/db-backups/` (replace the older backup; keep one).
