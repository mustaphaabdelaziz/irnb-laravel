# Equipment Item Serial Auto-Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Auto-generate equipment item serials as `{CLUB}-{YYYY}-{CODE}-{NNNNN}` (e.g. `IRNB-2026-BALL-00001`) on the server, read-only in the UI.

**Architecture:** A new `SerialNumberService` is the single source of truth. It reads the club short-name from `WebsiteConfig`, the 4-digit year from the item's `purchase_date`, and a category `code` (new column on `equipment_categories`), then computes a per-(year+category) zero-padded counter. The controller assigns the serial inside the existing DB transaction; a small GET endpoint feeds a read-only preview in the add-item form.

**Tech Stack:** Laravel 13 (PHP 8.3), Inertia + Vue 3, PHPUnit (attribute style, `RefreshDatabase`), SQLite (desktop) / MySQL (web).

## Global Constraints

- Serial format: `{CLUB}-{YYYY}-{CODE}-{NNNNN}` — CLUB uppercased alnum (fallback `CLUB`), YYYY 4-digit, CODE uppercased alnum, NNNNN min 5 digits zero-padded, widening past 99999.
- Counter scope: resets per `(year, category code)`.
- Serial is server-generated and read-only; never accept it from the client.
- Existing `equipment_items.unique_identifier` values are left untouched (out of scope).
- `equipment_catalogs.category` is a **name string** matched to `EquipmentCategory.name` (no FK). Preserve that.
- Match existing test conventions: `namespace Tests\Feature;`, `use RefreshDatabase;`, `#[Test]` attribute, `extends Tests\TestCase`.
- Test command on Windows: `php artisan test --filter=<Name>`.

---

### Task 1: Category `code` column + derivation helper

**Files:**
- Create: `database/migrations/2026_07_06_000001_add_code_to_equipment_categories_table.php`
- Modify: `app/Models/EquipmentCategory.php`
- Test: `tests/Feature/EquipmentCategoryCodeTest.php`

**Interfaces:**
- Produces: `EquipmentCategory::deriveCode(string $name): string` — uppercased, non-alphanumerics stripped, first 4 chars; returns `CAT` when nothing alphanumeric remains. Column `equipment_categories.code` (nullable string).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EquipmentCategoryCodeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EquipmentCategory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentCategoryCodeTest extends TestCase
{
    #[Test]
    public function it_derives_a_four_char_uppercase_code_from_the_name(): void
    {
        $this->assertSame('BALL', EquipmentCategory::deriveCode('Balls'));
        $this->assertSame('GOAL', EquipmentCategory::deriveCode('Goals & Nets'));
        $this->assertSame('APPA', EquipmentCategory::deriveCode('Apparel'));
    }

    #[Test]
    public function it_falls_back_to_cat_when_no_alphanumerics_remain(): void
    {
        $this->assertSame('CAT', EquipmentCategory::deriveCode('—— ——'));
    }

    #[Test]
    public function it_handles_names_shorter_than_four_chars(): void
    {
        $this->assertSame('AX', EquipmentCategory::deriveCode('Ax'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EquipmentCategoryCodeTest`
Expected: FAIL — `Call to undefined method App\Models\EquipmentCategory::deriveCode()`

- [ ] **Step 3: Add the migration**

Create `database/migrations/2026_07_06_000001_add_code_to_equipment_categories_table.php`:

```php
<?php

use App\Models\EquipmentCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Short code used to build equipment item serials
     * ({CLUB}-{YYYY}-{CODE}-{NNNNN}). Backfilled from the category name so
     * generation never hits a null code; admins can refine in Settings.
     */
    public function up(): void
    {
        Schema::table('equipment_categories', function (Blueprint $table) {
            $table->string('code')->nullable()->after('name');
        });

        foreach (DB::table('equipment_categories')->get() as $category) {
            DB::table('equipment_categories')
                ->where('id', $category->id)
                ->update(['code' => EquipmentCategory::deriveCode($category->name)]);
        }
    }

    public function down(): void
    {
        Schema::table('equipment_categories', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
```

- [ ] **Step 4: Add `code` to the model (fillable + derivation helper)**

In `app/Models/EquipmentCategory.php`, change the fillable line and add the static method:

```php
    protected $fillable = ['name', 'code', 'description'];

    /**
     * Default serial code derived from a category name: first four
     * alphanumeric characters, uppercased. Falls back to CAT.
     */
    public static function deriveCode(string $name): string
    {
        $alnum = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');

        return $alnum === '' ? 'CAT' : substr($alnum, 0, 4);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=EquipmentCategoryCodeTest`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_06_000001_add_code_to_equipment_categories_table.php app/Models/EquipmentCategory.php tests/Feature/EquipmentCategoryCodeTest.php
git commit -m "feat: add code column + deriveCode helper to equipment categories"
```

---

### Task 2: SerialNumberService

**Files:**
- Create: `app/Services/Equipment/SerialNumberService.php`
- Test: `tests/Feature/EquipmentSerialServiceTest.php`

**Interfaces:**
- Consumes: `EquipmentCategory::deriveCode()` (Task 1), `WebsiteConfig::singleton()`, `EquipmentItem`, `EquipmentCatalog`.
- Produces:
  - `SerialNumberService::generate(EquipmentItem $item): string` — builds the serial from the item's catalog (→ category code) and `purchase_date`; does **not** save.
  - `SerialNumberService::assign(EquipmentItem $item): void` — sets `unique_identifier` and saves the item, retrying up to 3 times on a unique-constraint collision.
  - `SerialNumberService::previewNext(int $catalogId, string $purchaseDate): string` — the serial the next item would receive, without persisting anything.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EquipmentSerialServiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\WebsiteConfig;
use App\Services\Equipment\SerialNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentSerialServiceTest extends TestCase
{
    use RefreshDatabase;

    private function setClubShortName(?string $short): void
    {
        $config = WebsiteConfig::singleton();
        $config->club_short_name = $short;
        $config->save();
    }

    private function catalogFor(string $categoryName, string $code): EquipmentCatalog
    {
        EquipmentCategory::create(['name' => $categoryName, 'code' => $code]);

        return EquipmentCatalog::create(['name' => $categoryName.' item', 'category' => $categoryName]);
    }

    private function makeItem(EquipmentCatalog $catalog, string $purchaseDate): EquipmentItem
    {
        $item = new EquipmentItem(['catalog_id' => $catalog->id, 'purchase_date' => $purchaseDate]);
        app(SerialNumberService::class)->assign($item);

        return $item;
    }

    #[Test]
    public function it_generates_a_serial_in_the_expected_format(): void
    {
        $this->setClubShortName('IRNB');
        $catalog = $this->catalogFor('Balls', 'BALL');

        $item = $this->makeItem($catalog, '2026-05-01');

        $this->assertSame('IRNB-2026-BALL-00001', $item->unique_identifier);
    }

    #[Test]
    public function it_increments_the_counter_within_the_same_year_and_category(): void
    {
        $this->setClubShortName('IRNB');
        $catalog = $this->catalogFor('Balls', 'BALL');

        $first = $this->makeItem($catalog, '2026-05-01');
        $second = $this->makeItem($catalog, '2026-09-15');

        $this->assertSame('IRNB-2026-BALL-00001', $first->unique_identifier);
        $this->assertSame('IRNB-2026-BALL-00002', $second->unique_identifier);
    }

    #[Test]
    public function the_counter_resets_per_year(): void
    {
        $this->setClubShortName('IRNB');
        $catalog = $this->catalogFor('Balls', 'BALL');

        $y2026 = $this->makeItem($catalog, '2026-05-01');
        $y2027 = $this->makeItem($catalog, '2027-01-02');

        $this->assertSame('IRNB-2026-BALL-00001', $y2026->unique_identifier);
        $this->assertSame('IRNB-2027-BALL-00001', $y2027->unique_identifier);
    }

    #[Test]
    public function distinct_categories_do_not_share_a_counter(): void
    {
        $this->setClubShortName('IRNB');
        $balls = $this->catalogFor('Balls', 'BALL');
        $goals = $this->catalogFor('Goals', 'GOAL');

        $ball = $this->makeItem($balls, '2026-05-01');
        $goal = $this->makeItem($goals, '2026-05-01');

        $this->assertSame('IRNB-2026-BALL-00001', $ball->unique_identifier);
        $this->assertSame('IRNB-2026-GOAL-00001', $goal->unique_identifier);
    }

    #[Test]
    public function the_club_abbreviation_falls_back_when_short_name_is_missing(): void
    {
        $this->setClubShortName(null);
        $catalog = $this->catalogFor('Balls', 'BALL');

        $item = $this->makeItem($catalog, '2026-05-01');

        $this->assertSame('CLUB-2026-BALL-00001', $item->unique_identifier);
    }

    #[Test]
    public function preview_next_returns_the_next_serial_without_persisting(): void
    {
        $this->setClubShortName('IRNB');
        $catalog = $this->catalogFor('Balls', 'BALL');
        $this->makeItem($catalog, '2026-05-01');

        $service = app(SerialNumberService::class);
        $preview = $service->previewNext($catalog->id, '2026-07-01');

        $this->assertSame('IRNB-2026-BALL-00002', $preview);
        $this->assertSame(1, EquipmentItem::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EquipmentSerialServiceTest`
Expected: FAIL — `Class "App\Services\Equipment\SerialNumberService" not found`

- [ ] **Step 3: Write the service**

Create `app/Services/Equipment/SerialNumberService.php`:

```php
<?php

namespace App\Services\Equipment;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\WebsiteConfig;
use Carbon\Carbon;
use Illuminate\Database\QueryException;

class SerialNumberService
{
    /**
     * Build the serial for an item ({CLUB}-{YYYY}-{CODE}-{NNNNN}).
     * Does not persist. The item must have catalog_id and purchase_date set.
     */
    public function generate(EquipmentItem $item): string
    {
        $prefix = sprintf(
            '%s-%s-%s-',
            $this->clubAbbreviation(),
            Carbon::parse($item->purchase_date)->format('Y'),
            $this->categoryCode($item),
        );

        $next = $this->nextSequence($prefix);
        $width = max(5, strlen((string) $next));

        return $prefix.str_pad((string) $next, $width, '0', STR_PAD_LEFT);
    }

    /**
     * Assign a freshly generated serial and save. Retries on the rare
     * unique-constraint collision (concurrent insert on the web path).
     */
    public function assign(EquipmentItem $item): void
    {
        for ($attempt = 1; ; $attempt++) {
            $item->unique_identifier = $this->generate($item);

            try {
                $item->save();

                return;
            } catch (QueryException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    /** The serial the next item for this catalog + purchase date would get. */
    public function previewNext(int $catalogId, string $purchaseDate): string
    {
        return $this->generate(new EquipmentItem([
            'catalog_id' => $catalogId,
            'purchase_date' => $purchaseDate,
        ]));
    }

    private function clubAbbreviation(): string
    {
        $short = WebsiteConfig::singleton()->club_short_name;
        $abbr = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $short) ?? '');

        return $abbr === '' ? 'CLUB' : $abbr;
    }

    private function categoryCode(EquipmentItem $item): string
    {
        /** @var EquipmentCatalog|null $catalog */
        $catalog = $item->catalog;
        $categoryName = $catalog?->category;

        if (! $categoryName) {
            return 'CAT';
        }

        $code = EquipmentCategory::where('name', $categoryName)->value('code');

        return strtoupper($code ?: EquipmentCategory::deriveCode($categoryName));
    }

    private function nextSequence(string $prefix): int
    {
        $max = 0;

        foreach (EquipmentItem::where('unique_identifier', 'like', $prefix.'%')->pluck('unique_identifier') as $uid) {
            $tail = substr((string) $uid, strlen($prefix));
            if (ctype_digit($tail)) {
                $max = max($max, (int) $tail);
            }
        }

        return $max + 1;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=EquipmentSerialServiceTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Equipment/SerialNumberService.php tests/Feature/EquipmentSerialServiceTest.php
git commit -m "feat: add SerialNumberService for equipment item serials"
```

---

### Task 3: Controller store + preview endpoint

**Files:**
- Modify: `app/Http/Controllers/EquipmentItemController.php:25-61` (store) and constructor `:21-23`
- Modify: `routes/web.php:103` (add preview route)
- Test: `tests/Feature/EquipmentSerialStoreTest.php`

**Interfaces:**
- Consumes: `SerialNumberService::assign()`, `SerialNumberService::previewNext()` (Task 2).
- Produces: route `equipment.items.preview-serial` → `GET /equipment/items/preview-serial?catalog_id=&purchase_date=` returning JSON `{ "serial": "IRNB-2026-BALL-00001" }`. `store` no longer reads `unique_identifier` from the request.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EquipmentSerialStoreTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\User;
use App\Models\WebsiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentSerialStoreTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalog(): EquipmentCatalog
    {
        $config = WebsiteConfig::singleton();
        $config->club_short_name = 'IRNB';
        $config->save();

        EquipmentCategory::create(['name' => 'Balls', 'code' => 'BALL']);

        return EquipmentCatalog::create(['name' => 'Match Ball', 'category' => 'Balls']);
    }

    #[Test]
    public function storing_an_item_generates_the_serial_and_ignores_any_client_identifier(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $catalog = $this->seedCatalog();

        $this->actingAs($user)
            ->post(route('equipment.items.store'), [
                'catalog_id' => $catalog->id,
                'purchase_date' => '2026-05-01',
                'condition' => 'New',
                'unique_identifier' => 'HACKED-999', // must be ignored
            ])
            ->assertRedirect();

        $item = EquipmentItem::sole();
        $this->assertSame('IRNB-2026-BALL-00001', $item->unique_identifier);
    }

    #[Test]
    public function storing_a_second_item_increments_the_counter(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $catalog = $this->seedCatalog();

        foreach (['2026-05-01', '2026-06-01'] as $date) {
            $this->actingAs($user)->post(route('equipment.items.store'), [
                'catalog_id' => $catalog->id,
                'purchase_date' => $date,
                'condition' => 'New',
            ]);
        }

        $this->assertSame(
            ['IRNB-2026-BALL-00001', 'IRNB-2026-BALL-00002'],
            EquipmentItem::orderBy('id')->pluck('unique_identifier')->all(),
        );
    }

    #[Test]
    public function the_preview_endpoint_returns_the_next_serial(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $catalog = $this->seedCatalog();

        $this->actingAs($user)
            ->getJson(route('equipment.items.preview-serial', [
                'catalog_id' => $catalog->id,
                'purchase_date' => '2026-05-01',
            ]))
            ->assertOk()
            ->assertJson(['serial' => 'IRNB-2026-BALL-00001']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EquipmentSerialStoreTest`
Expected: FAIL — `unique_identifier` still required (validation error / 302 with errors) and route `equipment.items.preview-serial` not defined.

- [ ] **Step 3: Inject the service into the controller**

In `app/Http/Controllers/EquipmentItemController.php`, add the import and constructor dependency:

```php
use App\Services\Equipment\SerialNumberService;
```

```php
    public function __construct(
        private EquipmentLifecycleService $lifecycle,
        private SerialNumberService $serials,
    ) {}
```

- [ ] **Step 4: Rewrite `store` to generate the serial**

Replace the `store` method body (`:25-61`) with:

```php
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'catalog_id' => ['required', 'integer', 'exists:equipment_catalogs,id'],
            'purchase_date' => ['required', 'date'],
            'condition' => ['nullable', 'string', 'in:New,Good,Fair,Poor,Damaged'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($validated, $request) {
            $item = new EquipmentItem([
                'catalog_id' => $validated['catalog_id'],
                'purchase_date' => $validated['purchase_date'],
                'condition' => $validated['condition'] ?? 'New',
                'location' => $validated['location'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Assigns unique_identifier and saves (with collision retry).
            $this->serials->assign($item);

            if (! empty($validated['purchase_price'])) {
                $purchaseTransaction = Transaction::create([
                    'amount' => $validated['purchase_price'],
                    'transaction_date' => $validated['purchase_date'],
                    'transaction_type' => 'expense',
                    'category' => 'equipment',
                    'description' => 'Equipment purchase: '.$item->unique_identifier,
                    'recorded_by_user_id' => $request->user()?->id,
                    'status' => 'Paid',
                    'fiscal_year' => now()->year,
                ]);

                $item->purchase_transaction_id = $purchaseTransaction->id;
                $item->save();
            }
        });

        return redirect()->route('equipment.catalogs.show', $validated['catalog_id'])
            ->with('success', 'Equipment item added successfully.');
    }

    public function previewSerial(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'catalog_id' => ['required', 'integer', 'exists:equipment_catalogs,id'],
            'purchase_date' => ['required', 'date'],
        ]);

        return response()->json([
            'serial' => $this->serials->previewNext($validated['catalog_id'], $validated['purchase_date']),
        ]);
    }
```

Note: `EquipmentItem` does not set `status`, matching the previous behaviour — the DB column default applies.

- [ ] **Step 5: Register the preview route**

In `routes/web.php`, immediately after line 103 (`equipment.items.store`), add:

```php
    Route::get('/equipment/items/preview-serial', [EquipmentItemController::class, 'previewSerial'])->name('equipment.items.preview-serial');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=EquipmentSerialStoreTest`
Expected: PASS (3 tests)

- [ ] **Step 7: Run the full equipment suite (no regressions)**

Run: `php artisan test --filter=Equipment`
Expected: PASS (all equipment tests, including `EquipmentHistoryTest`)

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/EquipmentItemController.php routes/web.php tests/Feature/EquipmentSerialStoreTest.php
git commit -m "feat: generate equipment serial on store + preview endpoint"
```

---

### Task 4: Category code in Settings UI

**Files:**
- Modify: `app/Http/Controllers/EquipmentCategoryController.php:23-50` (store + update validation)
- Modify: `resources/js/Pages/Settings/EquipmentCategories.vue`
- Test: `tests/Feature/EquipmentCategoryCodeTest.php` (extend from Task 1)

**Interfaces:**
- Consumes: `EquipmentCategory::deriveCode()` (Task 1).
- Produces: category `store`/`update` accept an optional `code`; stored uppercased, or derived from the name when blank.

- [ ] **Step 1: Write the failing test (append to the Task 1 test file)**

Add to `tests/Feature/EquipmentCategoryCodeTest.php`. Add these imports at the top (the file currently has none beyond the class):

```php
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
```

Add `use RefreshDatabase;` as the first line inside the class body, then add these methods:

```php
    #[Test]
    public function creating_a_category_stores_an_uppercased_code(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->post(route('equipment-categories.store'), [
            'name' => 'Training Equipment',
            'code' => 'trn',
        ])->assertRedirect();

        $this->assertDatabaseHas('equipment_categories', ['name' => 'Training Equipment', 'code' => 'TRN']);
    }

    #[Test]
    public function creating_a_category_without_a_code_derives_one(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->post(route('equipment-categories.store'), [
            'name' => 'Apparel',
        ])->assertRedirect();

        $this->assertDatabaseHas('equipment_categories', ['name' => 'Apparel', 'code' => 'APPA']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EquipmentCategoryCodeTest`
Expected: FAIL — new category has `code` null (validation drops it), assertions fail.

- [ ] **Step 3: Update controller validation + normalisation**

In `app/Http/Controllers/EquipmentCategoryController.php`, replace `store` and `update` with:

```php
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:equipment_categories,name'],
            'code' => ['nullable', 'string', 'max:10', 'alpha_num'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['code'] = $this->normaliseCode($validated['code'] ?? null, $validated['name']);

        EquipmentCategory::create($validated);

        return back()->with('success', 'Category created successfully.');
    }

    public function update(Request $request, EquipmentCategory $equipmentCategory): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:equipment_categories,name,'.$equipmentCategory->id],
            'code' => ['nullable', 'string', 'max:10', 'alpha_num'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['code'] = $this->normaliseCode($validated['code'] ?? null, $validated['name']);

        // Catalogs reference the category by name, so a rename must cascade.
        if ($validated['name'] !== $equipmentCategory->name) {
            $equipmentCategory->catalogs()->update(['category' => $validated['name']]);
        }

        $equipmentCategory->update($validated);

        return back()->with('success', 'Category updated successfully.');
    }

    private function normaliseCode(?string $code, string $name): string
    {
        return $code ? strtoupper($code) : EquipmentCategory::deriveCode($name);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=EquipmentCategoryCodeTest`
Expected: PASS (5 tests)

- [ ] **Step 5: Add the code field to the Settings Vue page**

In `resources/js/Pages/Settings/EquipmentCategories.vue`:

Change both forms to include `code`:

```js
const form = useForm({ name: '', code: '', description: '' });
const editForm = useForm({ name: '', code: '', description: '' });
```

In `startEdit`, carry the code over:

```js
function startEdit(cat) {
    editingId.value = cat.id;
    editForm.name = cat.name;
    editForm.code = cat.code || '';
    editForm.description = cat.description || '';
}
```

In the add form (inside the `<form @submit.prevent="addCategory" ...>`), add a code input between the name and description blocks:

```html
                <div class="w-28">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('code') }}</label>
                    <TextInput v-model="form.code" class="mt-1 w-full uppercase" placeholder="BALL" />
                    <InputError :message="form.errors.code" class="mt-1" />
                </div>
```

Add a `Code` column header after the name `<th>`:

```html
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('code') }}</th>
```

Add the matching cell after the name `<td>` in the row loop:

```html
                            <td class="px-4 py-3">
                                <TextInput v-if="editingId === cat.id" v-model="editForm.code" class="w-24 uppercase" />
                                <span v-else class="font-mono text-sm text-slate-700 dark:text-slate-200">{{ cat.code || '-' }}</span>
                            </td>
```

Update the empty-state row `colspan` from `4` to `5`.

- [ ] **Step 6: Add the `code` i18n key**

In each of `resources/js/i18n/en.json`, `fr.json`, `ar.json`, add a `"code"` key near the existing `"name"` key:
- en: `"code": "Code"`
- fr: `"code": "Code"`
- ar: `"code": "الرمز"`

(If a `"code"` key already exists in a file, leave it as-is.)

- [ ] **Step 7: Build the frontend to catch compile errors**

Run: `npm run build`
Expected: builds with no errors.

- [ ] **Step 8: Manual verification**

Start the app (`php artisan serve` + `npm run dev` if not already running). Go to **Settings → Equipment Categories**. Confirm: the add form has a Code field; adding "Balls" with code "ball" stores "BALL"; adding a category with a blank code derives one; editing a row lets you change the code.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/EquipmentCategoryController.php resources/js/Pages/Settings/EquipmentCategories.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json tests/Feature/EquipmentCategoryCodeTest.php
git commit -m "feat: manage equipment category code in settings"
```

---

### Task 5: Read-only serial preview in the add-item form

**Files:**
- Modify: `resources/js/Pages/Equipment/Catalog/Show.vue`

**Interfaces:**
- Consumes: `GET /equipment/items/preview-serial` (Task 3).
- Produces: no new interface; the add-item form shows a read-only serial and no longer sends `unique_identifier`.

- [ ] **Step 1: Drop `unique_identifier` from the add-item form**

In `resources/js/Pages/Equipment/Catalog/Show.vue`, change `addItemForm` (remove the `unique_identifier` line):

```js
const addItemForm = useForm({
    catalog_id: props.catalog.id,
    purchase_date: new Date().toISOString().slice(0, 10),
    condition: 'New',
    location: '',
    purchase_price: props.catalog.purchase_price || '',
    notes: '',
});
```

- [ ] **Step 2: Add preview state + fetch, and open-modal / date-change wiring**

Add a `serialPreview` ref (near the other refs, after `const repairItemId = ref(null);`):

```js
const serialPreview = ref('');

async function fetchSerialPreview() {
    serialPreview.value = '…';
    try {
        const params = new URLSearchParams({
            catalog_id: props.catalog.id,
            purchase_date: addItemForm.purchase_date,
        });
        const res = await fetch(`/equipment/items/preview-serial?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        serialPreview.value = res.ok ? (await res.json()).serial : '';
    } catch {
        serialPreview.value = '';
    }
}

function openAddItem() {
    showAddItemModal.value = true;
    fetchSerialPreview();
}
```

Import `watch` alongside `ref`:

```js
import { ref, watch } from 'vue';
```

Add a watcher (after the functions, before `statusColor`) so the preview tracks the purchase date while the modal is open:

```js
watch(() => addItemForm.purchase_date, () => {
    if (showAddItemModal.value) fetchSerialPreview();
});
```

- [ ] **Step 3: Update the "add" button and the reset call**

Change the add button handler (`:124`) from `@click="showAddItemModal = true"` to:

```html
                    <button @click="openAddItem" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 transition-colors">
```

In `addItem`'s `onSuccess`, change `addItemForm.reset('unique_identifier', 'notes')` to:

```js
            addItemForm.reset('notes');
```

- [ ] **Step 4: Replace the editable ID field with a read-only preview**

Replace the ID / Serial block (`:208-212`) with:

```html
                        <div>
                            <InputLabel value="ID / Serial" />
                            <div class="mt-1 flex items-center rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 px-3 py-2 font-mono text-sm text-slate-700 dark:text-slate-200">
                                {{ serialPreview || '—' }}
                            </div>
                            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ t('assigned_on_save') }}</p>
                        </div>
```

- [ ] **Step 5: Add the `assigned_on_save` i18n key**

In `resources/js/i18n/en.json`, `fr.json`, `ar.json`, add:
- en: `"assigned_on_save": "Auto-generated on save"`
- fr: `"assigned_on_save": "Généré automatiquement à l'enregistrement"`
- ar: `"assigned_on_save": "يُنشأ تلقائيًا عند الحفظ"`

- [ ] **Step 6: Build the frontend**

Run: `npm run build`
Expected: builds with no errors.

- [ ] **Step 7: Manual verification**

With the app running, open a catalog (e.g. `/equipment/catalogs/5`), click **+ Add**. Confirm: the ID / Serial field shows a live value like `IRNB-2026-BALL-00001` (read-only), changing the purchase date to a different year updates it, and saving creates an item whose serial matches. Add a second item and confirm the counter increments.

- [ ] **Step 8: Commit**

```bash
git add resources/js/Pages/Equipment/Catalog/Show.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json
git commit -m "feat: read-only auto serial preview in add-item form"
```

---

## Final verification

- [ ] Run the full test suite: `php artisan test`
- [ ] Run the equipment subset explicitly: `php artisan test --filter=Equipment`
- [ ] `npm run build` succeeds.
- [ ] Manual end-to-end: create a category with a code → create a catalog in that category → add an item → serial follows `{CLUB}-{YYYY}-{CODE}-{NNNNN}`.

## Notes / risks

- **Transaction + retry on SQLite:** `assign()` retries on a unique-constraint `QueryException`. On the desktop (SQLite, single user) collisions are effectively impossible; the retry is a safety net for the concurrent web path. If a future DB aborts the surrounding transaction on constraint violation, revisit whether `assign` should run before opening the outer transaction.
- **Preview is best-effort:** two people adding items to the same catalog simultaneously could both preview `…00002`; the definitive number is assigned on save via `assign()`, and the DB unique constraint guarantees no duplicate.
- **Ziggy:** the preview endpoint is fetched by hardcoded path (`/equipment/items/preview-serial`), not the `route()` helper, so no Ziggy regeneration is needed on the client.
