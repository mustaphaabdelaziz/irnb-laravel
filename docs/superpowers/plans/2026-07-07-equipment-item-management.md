# Equipment Item Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give equipment items an optional designation and let staff edit and delete items.

**Architecture:** Add a nullable `designation` column to `equipment_items`; extend the existing `EquipmentItemController` with `update` (PUT) and `destroy` (DELETE) actions guarded by a rented-item check; wire an edit modal and a delete confirm into the catalog Show page. The serial (`unique_identifier`) stays server-owned and immutable.

**Tech Stack:** Laravel 13 (PHP 8.3), Inertia + Vue 3, PHPUnit (attribute style, `RefreshDatabase`), SQLite (desktop) / MySQL (web).

## Global Constraints

- `designation` is optional: `nullable|string|max:255`.
- Editable fields: `designation`, `purchase_date`, `condition`, `location`, `notes`. `unique_identifier` is **never** accepted from the client or changed; the serial is not regenerated on edit.
- `condition` values: `New,Good,Fair,Poor,Damaged`.
- Delete is **refused while the item is Rented** (status `Rented` or an `activeRental` exists). Otherwise the item plus its `equipment_rentals` and `equipment_histories` rows are removed; the purchase `Transaction` is **kept**.
- New `equipment.items.update` / `equipment.items.destroy` routes go in the authenticated group (`auth,verified,approved`), beside the other `equipment.items.*` routes — NOT the admin group.
- Test conventions: `namespace Tests\Feature;`, `use RefreshDatabase;`, `#[Test]`, `extends Tests\TestCase`. A non-admin verified user (`User::factory()->create(['email_verified_at' => now()])`) satisfies these routes. Test command (Windows): `php artisan test --filter=<Name>`.
- Vue changes have no JS test runner: verify with `npm run build`.

---

### Task 1: `designation` column, model, and create path

**Files:**
- Create: `database/migrations/2026_07_07_000001_add_designation_to_equipment_items_table.php`
- Modify: `app/Models/EquipmentItem.php` (fillable)
- Modify: `app/Http/Controllers/EquipmentItemController.php` (`store`, lines 28-70)
- Test: `tests/Feature/EquipmentItemManagementTest.php`

**Interfaces:**
- Produces: column `equipment_items.designation` (nullable string); `store` accepts an optional `designation` field.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EquipmentItemManagementTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentItemManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function catalog(): EquipmentCatalog
    {
        // 'Balls' is a seeded category (code 'BALL' after the code migration);
        // a matching catalog lets the serial generator resolve a code.
        return EquipmentCatalog::create(['name' => 'Match Ball', 'category' => 'Balls']);
    }

    #[Test]
    public function storing_an_item_persists_its_designation(): void
    {
        $catalog = $this->catalog();

        $this->actingAs($this->user())->post(route('equipment.items.store'), [
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-05-01',
            'condition' => 'New',
            'designation' => 'T-shirt n° 10',
        ])->assertRedirect();

        $this->assertSame('T-shirt n° 10', EquipmentItem::sole()->designation);
    }

    #[Test]
    public function designation_is_optional(): void
    {
        $catalog = $this->catalog();

        $this->actingAs($this->user())->post(route('equipment.items.store'), [
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-05-01',
            'condition' => 'New',
        ])->assertRedirect();

        $item = EquipmentItem::sole();
        $this->assertNull($item->designation);
        $this->assertNotNull($item->unique_identifier);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EquipmentItemManagementTest`
Expected: FAIL — `designation` is not persisted (column/fillable/validation missing), first test asserts non-null and fails.

- [ ] **Step 3: Add the migration**

Create `database/migrations/2026_07_07_000001_add_designation_to_equipment_items_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->string('designation')->nullable()->after('unique_identifier');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->dropColumn('designation');
        });
    }
};
```

- [ ] **Step 4: Add `designation` to the model's fillable**

In `app/Models/EquipmentItem.php`, add `'designation'` to `$fillable` right after `'unique_identifier'`:

```php
    protected $fillable = [
        'catalog_id',
        'unique_identifier',
        'designation',
        'purchase_date',
        'status',
        'condition',
        'location',
        'purchase_transaction_id',
        'notes',
    ];
```

- [ ] **Step 5: Accept and store `designation` in `store`**

In `app/Http/Controllers/EquipmentItemController.php`, add the validation rule and the built field.

Add to the `$request->validate([...])` array in `store` (after the `catalog_id` line):

```php
            'designation' => ['nullable', 'string', 'max:255'],
```

Add to the `new EquipmentItem([...])` array (after `'catalog_id' => ...,`):

```php
                'designation' => $validated['designation'] ?? null,
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=EquipmentItemManagementTest`
Expected: PASS (2 tests)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_07_000001_add_designation_to_equipment_items_table.php app/Models/EquipmentItem.php app/Http/Controllers/EquipmentItemController.php tests/Feature/EquipmentItemManagementTest.php
git commit -m "feat: add optional designation to equipment items"
```

---

### Task 2: `update` and `destroy` actions + routes + history payload

**Files:**
- Modify: `app/Http/Controllers/EquipmentItemController.php` (add `update`, `destroy`; extend `history` payload)
- Modify: `routes/web.php` (add two routes after `equipment.items.preview-serial`, line ~104)
- Test: `tests/Feature/EquipmentItemManagementTest.php` (extend)

**Interfaces:**
- Consumes: `designation` column (Task 1).
- Produces: routes `equipment.items.update` (PUT `/equipment/items/{item}`) and `equipment.items.destroy` (DELETE `/equipment/items/{item}`). `update` accepts `designation, purchase_date, condition, location, notes`. `history` payload gains `designation`.

- [ ] **Step 1: Write the failing tests (append to the Task 1 test file)**

Add these imports to `tests/Feature/EquipmentItemManagementTest.php`:

```php
use App\Models\EquipmentHistory;
use App\Models\EquipmentRental;
use App\Models\Transaction;
```

Add these methods to the class:

```php
    private function item(EquipmentCatalog $catalog, array $attrs = []): EquipmentItem
    {
        return EquipmentItem::create(array_merge([
            'catalog_id' => $catalog->id,
            'unique_identifier' => 'IRNB-2026-BALL-00001',
            'purchase_date' => '2026-05-01',
            'status' => 'Available',
            'condition' => 'New',
        ], $attrs));
    }

    #[Test]
    public function updating_an_item_changes_editable_fields_but_not_the_serial(): void
    {
        $item = $this->item($this->catalog(), ['designation' => 'old']);

        $this->actingAs($this->user())->put(route('equipment.items.update', $item), [
            'designation' => 'T-shirt n° 9',
            'purchase_date' => '2026-06-15',
            'condition' => 'Good',
            'location' => 'Locker A',
            'notes' => 'hem repaired',
        ])->assertRedirect();

        $item->refresh();
        $this->assertSame('T-shirt n° 9', $item->designation);
        $this->assertSame('Good', $item->condition);
        $this->assertSame('Locker A', $item->location);
        $this->assertSame('hem repaired', $item->notes);
        $this->assertSame('2026-06-15', $item->purchase_date->toDateString());
        $this->assertSame('IRNB-2026-BALL-00001', $item->unique_identifier);
    }

    #[Test]
    public function deleting_an_item_removes_it_and_its_history_but_keeps_the_purchase_transaction(): void
    {
        $catalog = $this->catalog();
        $transaction = Transaction::create([
            'amount' => 100,
            'transaction_date' => '2026-05-01',
            'transaction_type' => 'expense',
            'category' => 'equipment',
            'description' => 'Equipment purchase: IRNB-2026-BALL-00001',
            'status' => 'Paid',
            'fiscal_year' => 2026,
        ]);
        $item = $this->item($catalog, ['purchase_transaction_id' => $transaction->id]);
        EquipmentHistory::create([
            'item_id' => $item->id,
            'event_type' => 'Purchase',
            'details' => [],
            'event_timestamp' => now(),
        ]);

        $this->actingAs($this->user())->delete(route('equipment.items.destroy', $item))->assertRedirect();

        $this->assertDatabaseMissing('equipment_items', ['id' => $item->id]);
        $this->assertSame(0, EquipmentHistory::where('item_id', $item->id)->count());
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    }

    #[Test]
    public function deleting_a_rented_item_is_refused(): void
    {
        $catalog = $this->catalog();
        $item = $this->item($catalog, ['status' => 'Rented']);
        EquipmentRental::create([
            'equipment_item_id' => $item->id,
            'rentable_type' => 'Player',
            'rentable_id' => 1,
            'checkout_date' => now(),
        ]);

        $this->actingAs($this->user())->delete(route('equipment.items.destroy', $item))->assertRedirect();

        $this->assertDatabaseHas('equipment_items', ['id' => $item->id]);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=EquipmentItemManagementTest`
Expected: FAIL — routes `equipment.items.update` / `equipment.items.destroy` are not defined.

- [ ] **Step 3: Add `update` and `destroy` to the controller**

In `app/Http/Controllers/EquipmentItemController.php`, add these two methods after `previewSerial` (after line 82):

```php
    public function update(Request $request, EquipmentItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'designation' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['required', 'date'],
            'condition' => ['nullable', 'string', 'in:New,Good,Fair,Poor,Damaged'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        // Serial (unique_identifier) is immutable identity — never touched here.
        $item->update($validated);

        return back()->with('success', 'Equipment item updated successfully.');
    }

    public function destroy(EquipmentItem $item): RedirectResponse
    {
        if ($item->status === 'Rented' || $item->activeRental) {
            return back()->with('error', 'Cannot delete a rented item. Return it first.');
        }

        $catalogId = $item->catalog_id;

        // Remove the item and its audit/rental rows explicitly (env-independent,
        // does not rely on DB cascade). The purchase Transaction is left intact.
        DB::transaction(function () use ($item) {
            $item->rentals()->delete();
            $item->histories()->delete();
            $item->delete();
        });

        return redirect()->route('equipment.catalogs.show', $catalogId)
            ->with('success', 'Equipment item deleted successfully.');
    }
```

- [ ] **Step 4: Add `designation` to the `history` payload**

In `app/Http/Controllers/EquipmentItemController.php`, in the `history` method's `Inertia::render('Equipment/History', [...])` `item` array, add a `designation` line right after the `unique_identifier` line:

```php
                'unique_identifier' => $item->unique_identifier,
                'designation' => $item->designation,
```

- [ ] **Step 5: Register the routes**

In `routes/web.php`, immediately after the `equipment.items.preview-serial` route (line ~104), add:

```php
    Route::put('/equipment/items/{item}', [EquipmentItemController::class, 'update'])->name('equipment.items.update');
    Route::delete('/equipment/items/{item}', [EquipmentItemController::class, 'destroy'])->name('equipment.items.destroy');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=EquipmentItemManagementTest`
Expected: PASS (5 tests)

- [ ] **Step 7: Run the equipment suite (no regressions)**

Run: `php artisan test --filter=Equipment`
Expected: PASS (all equipment tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/EquipmentItemController.php routes/web.php tests/Feature/EquipmentItemManagementTest.php
git commit -m "feat: edit and delete equipment items"
```

---

### Task 3: Frontend — designation column, edit modal, delete, history display

**Files:**
- Modify: `resources/js/Pages/Equipment/Catalog/Show.vue`
- Modify: `resources/js/Pages/Equipment/History.vue`
- Modify: `resources/js/i18n/en.json`, `fr.json`, `ar.json`

**Interfaces:**
- Consumes: `equipment.items.update`, `equipment.items.destroy` routes (Task 2); `item.designation` field.

- [ ] **Step 1: Add `designation` to the add-item form + reset**

In `resources/js/Pages/Equipment/Catalog/Show.vue`, add `designation: ''` to `addItemForm` (after `catalog_id`):

```js
const addItemForm = useForm({
    catalog_id: props.catalog.id,
    designation: '',
    purchase_date: new Date().toISOString().slice(0, 10),
    condition: 'New',
    location: '',
    purchase_price: props.catalog.purchase_price || '',
    notes: '',
});
```

In `addItem`'s `onSuccess`, change `addItemForm.reset('notes')` to:

```js
            addItemForm.reset('notes', 'designation');
```

- [ ] **Step 2: Add edit + delete state and handlers**

In `Show.vue`, after the `serialPreview` ref block (after line 45) add the edit form, and after `repairItemId` add the delete ref. Insert this block near the other `useForm` declarations (e.g. after `returnForm`):

```js
const showEditModal = ref(false);
const deleteItemId = ref(null);

const editItemForm = useForm({
    designation: '',
    purchase_date: '',
    condition: 'Good',
    location: '',
    notes: '',
});

function openEdit(item) {
    selectedItem.value = item;
    editItemForm.designation = item.designation || '';
    editItemForm.purchase_date = item.purchase_date ? String(item.purchase_date).slice(0, 10) : '';
    editItemForm.condition = item.condition || 'Good';
    editItemForm.location = item.location || '';
    editItemForm.notes = item.notes || '';
    editItemForm.clearErrors();
    showEditModal.value = true;
}

function submitEdit() {
    editItemForm.put(route('equipment.items.update', selectedItem.value.id), {
        onSuccess: () => { showEditModal.value = false; },
    });
}

function deleteItem() {
    const id = deleteItemId.value;
    deleteItemId.value = null;
    router.delete(route('equipment.items.destroy', id), { preserveState: false });
}
```

- [ ] **Step 3: Add a Designation input to the add-item modal**

In `Show.vue`, in the add-item modal form, add a designation field right after the serial preview block (the `<div>` containing the read-only serial and the `assigned_on_save` note), before the purchase_date/condition grid:

```html
                        <div>
                            <InputLabel :value="t('designation')" />
                            <TextInput v-model="addItemForm.designation" class="mt-1 w-full" placeholder="e.g. T-shirt n° 10" />
                            <InputError :message="addItemForm.errors.designation" class="mt-1" />
                        </div>
```

- [ ] **Step 4: Add the Designation column to the items table**

In `Show.vue` items table, add a header `<th>` right after the `identifier` header:

```html
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('designation') }}</th>
```

And a matching cell right after the `unique_identifier` `<td>`:

```html
                                <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{{ item.designation || '—' }}</td>
```

- [ ] **Step 5: Add edit + delete buttons to the actions cell**

In `Show.vue`, in the actions `<td>` `<div class="flex items-center justify-end gap-2">`, add an edit and a delete button (place after the existing history `<Link>`):

```html
                                        <button @click="openEdit(item)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200" :title="t('edit')">✏️</button>
                                        <button @click="deleteItemId = item.id" class="text-sm text-rose-500 hover:text-rose-700" :title="t('delete')">🗑️</button>
```

- [ ] **Step 6: Add the edit modal and delete confirm**

In `Show.vue`, inside the `<Teleport to="body">`, add the edit modal after the add-item modal `</div>` (the one closing the add modal wrapper), mirroring the add modal's styling:

```html
            <!-- Edit Item Modal -->
            <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50" @click.self="showEditModal = false">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('edit') }} — {{ selectedItem?.unique_identifier }}</h3>
                    <form @submit.prevent="submitEdit" class="mt-4 space-y-3">
                        <div>
                            <InputLabel :value="t('designation')" />
                            <TextInput v-model="editItemForm.designation" class="mt-1 w-full" />
                            <InputError :message="editItemForm.errors.designation" class="mt-1" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel :value="t('purchase_date')" />
                                <TextInput v-model="editItemForm.purchase_date" type="date" class="mt-1 w-full" />
                                <InputError :message="editItemForm.errors.purchase_date" class="mt-1" />
                            </div>
                            <div>
                                <InputLabel :value="t('condition')" />
                                <select v-model="editItemForm.condition" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                    <option v-for="c in ['New','Good','Fair','Poor','Damaged']" :key="c" :value="c">{{ c }}</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <InputLabel :value="t('location')" />
                            <TextInput v-model="editItemForm.location" class="mt-1 w-full" />
                        </div>
                        <div>
                            <InputLabel :value="t('notes')" />
                            <textarea v-model="editItemForm.notes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="showEditModal = false" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                            <PrimaryButton :disabled="editItemForm.processing">{{ t('save') }}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
```

Then add a delete confirm alongside the existing repair/lost `ConfirmModal`s (near the end of the template, after the `mark_as_lost` ConfirmModal):

```html
        <ConfirmModal :show="!!deleteItemId" :message="t('are_you_sure')" @confirm="deleteItem" @cancel="deleteItemId = null" />
```

- [ ] **Step 7: Show designation on the history page**

In `resources/js/Pages/Equipment/History.vue`, add a Designation cell to the item info-card grid, right after the `identifier` `<div>` (after line 60):

```html
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('designation') }}</p>
                        <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ item.designation || '-' }}</p>
                    </div>
```

- [ ] **Step 8: Add the `designation` i18n key**

In `resources/js/i18n/en.json`, `fr.json`, `ar.json`, add a `"designation"` key near the existing `"identifier"`/`"condition"` keys (skip a file that already has it):
- en: `"designation": "Designation"`
- fr: `"designation": "Désignation"`
- ar: `"designation": "التسمية"`

- [ ] **Step 9: Build the frontend**

Run: `npm run build`
Expected: builds with no errors.

- [ ] **Step 10: Verify JSON validity**

Confirm the three i18n files still parse (e.g. `node -e "['en','fr','ar'].forEach(l=>JSON.parse(require('fs').readFileSync('resources/js/i18n/'+l+'.json')))"`).
Expected: no output / no error.

- [ ] **Step 11: Manual verification (deferred to controller)**

With the app running, open a catalog. Confirm: the add-item modal has a Designation field; the items table shows a Designation column and ✏️/🗑️ buttons; ✏️ opens a prefilled edit modal that saves; 🗑️ prompts and deletes; the history page shows the designation.

- [ ] **Step 12: Commit**

```bash
git add resources/js/Pages/Equipment/Catalog/Show.vue resources/js/Pages/Equipment/History.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json
git commit -m "feat: item designation column, edit modal, delete in catalog UI"
```

---

## Final verification

- [ ] `php artisan test` — full suite green.
- [ ] `npm run build` — succeeds.
- [ ] Manual end-to-end: add an item with a designation → edit it (change designation/condition/location) → delete a non-rented item → confirm a rented item can't be deleted.

## Notes / risks

- **Delete is a hard delete** and removes the item's rental + history audit rows (the item no longer exists). The purchase `Transaction` is intentionally kept. This matches the approved spec.
- **Editing `purchase_date` does not regenerate the serial** and does not update the linked purchase `Transaction`'s date — both are intentional per the spec's out-of-scope list.
- The `equipment.items.update`/`destroy` routes are model-bound (`{item}`), so a non-existent id returns 404 automatically.
