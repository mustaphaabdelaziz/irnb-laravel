# Equipment Lot Model — Implementation Plan (Package A)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a single `equipment_items` row represent N identical units, so count-tracked equipment (dossards, balls) no longer needs one row per unit, while serialized equipment keeps working exactly as it does today.

**Architecture:** A row in `equipment_items` becomes a **lot**: N units sharing a catalog, condition, purchase date and price. A serialized item is the degenerate lot of size 1. Availability stops being read from the `status` column and becomes derived — `quantity − Σ(open rental quantity)` — which reduces to today's behaviour when N = 1. All lot arithmetic lives in one new `EquipmentStockService`.

**Tech Stack:** Laravel 13, PHP 8.3, Inertia 2 + Vue 3, Tailwind 3, SQLite (dev, test **and** production/desktop), PHPUnit 12 with `RefreshDatabase`.

Spec: `docs/superpowers/specs/2026-07-20-post-testing-enhancements-design.md` (Package A).

## Global Constraints

- **SQLite is the only dialect.** `DB_CONNECTION=sqlite` in `.env`, `config/database.php:20`, and `phpunit.xml`. SQLite cannot `ALTER TABLE ... ADD CONSTRAINT`, so the spec's `quantity = 1 OR unique_identifier IS NULL` check is enforced in the **model and Form Request**, not the database. Do not attempt a DB-level check constraint.
- **Tests are PHPUnit, not Pest.** Use `#[Test]` attributes from `PHPUnit\Framework\Attributes\Test`, `use RefreshDatabase;`, and extend `Tests\TestCase`. Follow `tests/Feature/EquipmentItemManagementTest.php` for style.
- Run tests with `php artisan test --filter=<TestClass>`. There is no Sail in this project.
- **There is no `PlayerFactory`.** Only `RoleFactory` and `UserFactory` exist in `database/factories/`. Build players directly, as the existing tests do, with a unique `membership_id`:

```php
    private int $membership = 202600000;

    private function player(): Player
    {
        return Player::create([
            'firstname' => 'Ali',
            'lastname' => 'B',
            'membership_id' => (string) ++$this->membership,
            'join_year' => 2026,
        ]);
    }
```
- **Existing behaviour for single-unit lots must not change.** Every existing test in `tests/Feature/Equipment*.php` must still pass at every commit. They are the regression net for this refactor.
- `equipment_catalogs.requires_serial` backfills to `true` for existing rows, defaults to `false` for new ones.
- Money is `decimal:2`. Dates use the existing casts (`purchase_date` => `date`).
- Never write raw SQL string interpolation; use query bindings.
- Commit after every task with the message shown in that task's final step.

## Status semantics — read before Task 1

`equipment_items.status` currently drives availability (`COUNT(*) WHERE status = 'Available'`). That cannot describe a lot of 20 where 10 are out.

**New rule:**

- Availability is **always** derived: `quantity − Σ(rental.quantity − rental.returned_quantity)` over rentals with `return_date IS NULL`.
- `status` keeps only **lot-level dispositions**: `Available`, `Under Repair`, `Lost`, `Retired`, `Out of Service`. Any of the last four zeroes the whole lot's availability.
- `status = 'Rented'` is still **set for single-unit lots only**, preserving today's behaviour and today's tests. Multi-unit lots stay `Available` and let the formula do the work.

The formula handles both without branching: a serialized item at `status = 'Rented'` has one open rental, so `1 − 1 = 0`.

## File Structure

**Created**
- `database/migrations/2026_07_20_000001_add_quantity_to_equipment_items_table.php` — lot columns
- `database/migrations/2026_07_20_000002_add_requires_serial_to_equipment_catalogs_table.php` — catalog flag, drops dead `item_count`
- `database/migrations/2026_07_20_000003_create_branch_equipment_item_table.php` — branch pivot
- `database/migrations/2026_07_20_000004_add_lot_columns_to_equipment_rentals_table.php` — type, quantity, partial returns, return notes
- `database/migrations/2026_07_20_000005_add_quantities_to_inventory_session_items_table.php` — stock-take quantities
- `app/Services/Equipment/EquipmentStockService.php` — all lot arithmetic
- `app/Http/Requests/Equipment/ReceiveStockRequest.php` — receive-stock validation
- `app/Http/Requests/Equipment/SplitLotRequest.php` — split validation
- `tests/Feature/EquipmentLotModelTest.php` — availability maths
- `tests/Feature/EquipmentReceiveStockTest.php` — purchase decoupling
- `tests/Feature/EquipmentLotRentalTest.php` — partial returns, assignment type
- `tests/Feature/EquipmentBranchTaggingTest.php` — branch pivot + filter

**Modified**
- `app/Models/EquipmentItem.php` — fillable, casts, `branches()`, availability accessor
- `app/Models/EquipmentCatalog.php` — fillable, `requires_serial`, `SUM(quantity)` counts
- `app/Models/EquipmentRental.php` — fillable, casts, `outstanding_quantity`
- `app/Models/Branch.php` — `equipmentItems()`
- `app/Services/Equipment/EquipmentLifecycleService.php` — quantity-aware rent/return
- `app/Http/Controllers/EquipmentItemController.php` — drop auto-Transaction, add receive/split, `SUM(quantity)` reports
- `app/Http/Controllers/EquipmentCatalogController.php` — `requires_serial`, branch filter, counts
- `resources/js/Pages/Equipment/Catalog/Show.vue` — lot table, receive modal, split action, rent/assign form
- `resources/js/Pages/Equipment/Catalog/{Create,Edit,Index}.vue` — `requires_serial`, quantity display
- `resources/js/Pages/Equipment/Inventory.vue` — quantity-based totals

---

### Task 1: Lot columns on `equipment_items`

**Files:**
- Create: `database/migrations/2026_07_20_000001_add_quantity_to_equipment_items_table.php`
- Modify: `app/Models/EquipmentItem.php`
- Test: `tests/Feature/EquipmentLotModelTest.php`

**Interfaces:**
- Produces: `EquipmentItem->quantity` (int, default 1), `EquipmentItem->received_via` (string), `unique_identifier` now nullable. Later tasks rely on all three.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentLotModelTest extends TestCase
{
    use RefreshDatabase;

    private function catalog(): EquipmentCatalog
    {
        return EquipmentCatalog::create(['name' => 'Match Ball', 'category' => 'Balls']);
    }

    #[Test]
    public function an_item_defaults_to_a_lot_of_one(): void
    {
        $item = EquipmentItem::create([
            'catalog_id' => $this->catalog()->id,
            'unique_identifier' => 'IRNB-2026-BALL-00001',
            'purchase_date' => '2026-01-01',
        ]);

        $this->assertSame(1, $item->fresh()->quantity);
        $this->assertSame('purchase', $item->fresh()->received_via);
    }

    #[Test]
    public function a_lot_can_hold_many_units_without_a_serial(): void
    {
        $item = EquipmentItem::create([
            'catalog_id' => $this->catalog()->id,
            'unique_identifier' => null,
            'purchase_date' => '2026-01-01',
            'quantity' => 20,
        ]);

        $this->assertSame(20, $item->fresh()->quantity);
        $this->assertNull($item->fresh()->unique_identifier);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=EquipmentLotModelTest`
Expected: FAIL — SQLite reports `table equipment_items has no column named quantity`, and the second test fails on the `unique_identifier` NOT NULL constraint.

- [ ] **Step 3: Write the migration**

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
            $table->unsignedInteger('quantity')->default(1)->after('catalog_id');
            $table->string('received_via')->default('purchase')->after('purchase_date');
            $table->string('unique_identifier')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'received_via']);
            $table->string('unique_identifier')->nullable(false)->change();
        });
    }
};
```

`received_via` is a plain string rather than an enum: SQLite has no native enum, Laravel emits a `varchar` with a check constraint for `enum()`, and that constraint cannot later be altered. Allowed values (`purchase`, `donation`, `opening_balance`, `adjustment`) are enforced in the Form Request in Task 7.

- [ ] **Step 4: Update the model**

In `app/Models/EquipmentItem.php`, add `'quantity'` and `'received_via'` to `$fillable` (after `'catalog_id'` and `'purchase_date'` respectively), and add the cast:

```php
    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'quantity' => 'integer',
        ];
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=EquipmentLotModelTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Verify no regression**

Run: `php artisan test --filter=Equipment`
Expected: PASS — all existing equipment tests still green.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_20_000001_add_quantity_to_equipment_items_table.php app/Models/EquipmentItem.php tests/Feature/EquipmentLotModelTest.php
git commit -m "feat(equipment): an item row becomes a lot of N units"
```

---

### Task 2: Catalog `requires_serial`, drop dead `item_count`

**Files:**
- Create: `database/migrations/2026_07_20_000002_add_requires_serial_to_equipment_catalogs_table.php`
- Modify: `app/Models/EquipmentCatalog.php`, `app/Http/Controllers/EquipmentCatalogController.php:163`
- Test: `tests/Feature/EquipmentLotModelTest.php`

**Interfaces:**
- Produces: `EquipmentCatalog->requires_serial` (bool). Task 7 and the Vue forms branch on it.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/EquipmentLotModelTest.php`:

```php
    #[Test]
    public function new_catalogs_default_to_count_tracking(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Dossards', 'category' => 'Apparel']);

        $this->assertFalse($catalog->fresh()->requires_serial);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=new_catalogs_default_to_count_tracking`
Expected: FAIL — `no such column: requires_serial`.

- [ ] **Step 3: Write the migration**

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
        Schema::table('equipment_catalogs', function (Blueprint $table) {
            $table->boolean('requires_serial')->default(false)->after('category');
        });

        // Existing catalogs already hold serialized items; flipping them would
        // contradict their own data. Only new catalogs get the bulk default.
        DB::table('equipment_catalogs')->update(['requires_serial' => true]);

        Schema::table('equipment_catalogs', function (Blueprint $table) {
            $table->dropColumn('item_count');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_catalogs', function (Blueprint $table) {
            $table->dropColumn('requires_serial');
            $table->unsignedInteger('item_count')->default(0);
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `app/Models/EquipmentCatalog.php`: remove `'item_count'` from `$fillable`, add `'requires_serial'`; remove the `'item_count' => 'integer'` cast and add `'requires_serial' => 'boolean'`.

- [ ] **Step 5: Remove the last `item_count` reader**

`app/Http/Controllers/EquipmentCatalogController.php:163` emits an `item_count` CSV column that actually reads `$c->items_count` from `withCount`. Leave the CSV header text alone (import templates depend on it) but confirm the value expression is `$c->items_count`, not `$c->item_count`. If it reads the dropped column, change it to `items_count`.

- [ ] **Step 6: Run tests**

Run: `php artisan test --filter=Equipment`
Expected: PASS — including `EquipmentCatalogImportExportTest`.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_20_000002_add_requires_serial_to_equipment_catalogs_table.php app/Models/EquipmentCatalog.php app/Http/Controllers/EquipmentCatalogController.php tests/Feature/EquipmentLotModelTest.php
git commit -m "feat(equipment): catalogs choose serial or count tracking"
```

---

### Task 3: Rental lot columns

**Files:**
- Create: `database/migrations/2026_07_20_000004_add_lot_columns_to_equipment_rentals_table.php`
- Modify: `app/Models/EquipmentRental.php`
- Test: `tests/Feature/EquipmentLotRentalTest.php`

**Interfaces:**
- Produces: `EquipmentRental->type` (`rental`|`assignment`), `->quantity`, `->returned_quantity`, `->return_notes`, and accessor `->outstanding_quantity` (int). Task 4's availability formula consumes `outstanding_quantity`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentLotRentalTest extends TestCase
{
    use RefreshDatabase;

    private function lot(int $quantity = 20): EquipmentItem
    {
        $catalog = EquipmentCatalog::create(['name' => 'Dossards', 'category' => 'Apparel']);

        return EquipmentItem::create([
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-01-01',
            'quantity' => $quantity,
        ]);
    }

    #[Test]
    public function a_rental_defaults_to_one_unit_of_type_rental(): void
    {
        $rental = EquipmentRental::create([
            'equipment_item_id' => $this->lot()->id,
            'rentable_type' => Player::class,
            'rentable_id' => $this->player()->id,
            'checkout_date' => now(),
        ]);

        $rental = $rental->fresh();
        $this->assertSame('rental', $rental->type);
        $this->assertSame(1, $rental->quantity);
        $this->assertSame(0, $rental->returned_quantity);
    }

    #[Test]
    public function outstanding_quantity_accounts_for_partial_returns(): void
    {
        $rental = EquipmentRental::create([
            'equipment_item_id' => $this->lot()->id,
            'rentable_type' => Player::class,
            'rentable_id' => $this->player()->id,
            'checkout_date' => now(),
            'quantity' => 10,
            'returned_quantity' => 6,
        ]);

        $this->assertSame(4, $rental->fresh()->outstanding_quantity);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=EquipmentLotRentalTest`
Expected: FAIL — `no such column: type`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_rentals', function (Blueprint $table) {
            $table->string('type')->default('rental')->after('rentable_id')->index();
            $table->unsignedInteger('quantity')->default(1)->after('type');
            $table->unsignedInteger('returned_quantity')->default(0)->after('quantity');
            $table->text('return_notes')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_rentals', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'quantity', 'returned_quantity', 'return_notes']);
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `app/Models/EquipmentRental.php`, add `'type'`, `'quantity'`, `'returned_quantity'`, `'return_notes'` to `$fillable`; add `'quantity' => 'integer'` and `'returned_quantity' => 'integer'` to the casts; and add:

```php
    public function getOutstandingQuantityAttribute(): int
    {
        return max(0, $this->quantity - $this->returned_quantity);
    }

    public function getIsAssignmentAttribute(): bool
    {
        return $this->type === 'assignment';
    }
```

Then make `getIsOverdueAttribute()` ignore assignments — an assignment has no due date and is never overdue. The existing body returns false when `return_date` is set or `due_date` is null; add the type guard as the first condition:

```php
    public function getIsOverdueAttribute(): bool
    {
        if ($this->type === 'assignment') {
            return false;
        }

        // ... existing body unchanged
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=EquipmentLotRentalTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Verify no regression**

Run: `php artisan test --filter=Equipment`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_20_000004_add_lot_columns_to_equipment_rentals_table.php app/Models/EquipmentRental.php tests/Feature/EquipmentLotRentalTest.php
git commit -m "feat(equipment): rentals carry quantity, type and partial returns"
```

---

### Task 4: `EquipmentStockService::availableQuantity()`

**Files:**
- Create: `app/Services/Equipment/EquipmentStockService.php`
- Modify: `app/Models/EquipmentItem.php`
- Test: `tests/Feature/EquipmentLotModelTest.php`

**Interfaces:**
- Produces: `EquipmentStockService::availableQuantity(EquipmentItem $item): int` and `EquipmentItem->available_quantity`. Every later task and every count in the app consumes this.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/EquipmentLotModelTest.php` (add the `use` statements for `EquipmentRental`, `Player`, and `App\Services\Equipment\EquipmentStockService`):

```php
    private function lot(int $quantity, string $status = 'Available'): EquipmentItem
    {
        return EquipmentItem::create([
            'catalog_id' => $this->catalog()->id,
            'purchase_date' => '2026-01-01',
            'quantity' => $quantity,
            'status' => $status,
        ]);
    }

    private function rent(EquipmentItem $item, int $quantity, int $returned = 0): EquipmentRental
    {
        return EquipmentRental::create([
            'equipment_item_id' => $item->id,
            'rentable_type' => Player::class,
            'rentable_id' => $this->player()->id,
            'checkout_date' => now(),
            'quantity' => $quantity,
            'returned_quantity' => $returned,
        ]);
    }

    #[Test]
    public function availability_subtracts_open_rentals(): void
    {
        $lot = $this->lot(20);
        $this->rent($lot, 10);
        $this->rent($lot, 3);

        $this->assertSame(7, app(EquipmentStockService::class)->availableQuantity($lot->fresh()));
    }

    #[Test]
    public function availability_counts_partially_returned_units_as_back(): void
    {
        $lot = $this->lot(20);
        $this->rent($lot, 10, returned: 6);

        $this->assertSame(16, app(EquipmentStockService::class)->availableQuantity($lot->fresh()));
    }

    #[Test]
    public function a_closed_rental_frees_the_units(): void
    {
        $lot = $this->lot(20);
        $rental = $this->rent($lot, 10);
        $rental->update(['return_date' => now(), 'returned_quantity' => 10]);

        $this->assertSame(20, app(EquipmentStockService::class)->availableQuantity($lot->fresh()));
    }

    #[Test]
    public function a_lot_level_disposition_zeroes_availability(): void
    {
        $service = app(EquipmentStockService::class);

        foreach (['Under Repair', 'Lost', 'Retired', 'Out of Service'] as $status) {
            $lot = $this->lot(20, $status);

            $this->assertSame(0, $service->availableQuantity($lot), "status {$status} should zero availability");
        }
    }

    #[Test]
    public function a_serialized_item_reduces_to_the_old_behaviour(): void
    {
        $item = $this->lot(1);
        $service = app(EquipmentStockService::class);

        $this->assertSame(1, $service->availableQuantity($item));

        $this->rent($item, 1);
        $item->update(['status' => 'Rented']);

        $this->assertSame(0, $service->availableQuantity($item->fresh()));
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=EquipmentLotModelTest`
Expected: FAIL — `Class "App\Services\Equipment\EquipmentStockService" not found`.

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services\Equipment;

use App\Models\EquipmentItem;

class EquipmentStockService
{
    /** Statuses that make an entire lot unavailable regardless of rentals. */
    public const BLOCKING_STATUSES = ['Under Repair', 'Lost', 'Retired', 'Out of Service'];

    /**
     * Units of this lot that can be issued right now.
     *
     * Availability is always derived, never read from `status`. For a
     * single-unit lot this reduces to the historical behaviour: a rented
     * item has one open rental, so 1 - 1 = 0.
     */
    public function availableQuantity(EquipmentItem $item): int
    {
        if (in_array($item->status, self::BLOCKING_STATUSES, true)) {
            return 0;
        }

        return max(0, $item->quantity - $this->outstandingQuantity($item));
    }

    /** Units currently out on open rentals or assignments. */
    public function outstandingQuantity(EquipmentItem $item): int
    {
        return (int) $item->rentals()
            ->whereNull('return_date')
            ->selectRaw('COALESCE(SUM(quantity - returned_quantity), 0) as outstanding')
            ->value('outstanding');
    }
}
```

- [ ] **Step 4: Expose it on the model**

Add to `app/Models/EquipmentItem.php`:

```php
    public function getAvailableQuantityAttribute(): int
    {
        return app(\App\Services\Equipment\EquipmentStockService::class)->availableQuantity($this);
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=EquipmentLotModelTest`
Expected: PASS (all tests including the 5 new ones)

- [ ] **Step 6: Commit**

```bash
git add app/Services/Equipment/EquipmentStockService.php app/Models/EquipmentItem.php tests/Feature/EquipmentLotModelTest.php
git commit -m "feat(equipment): derive availability from quantity and open rentals"
```

---

### Task 5: Convert every count to `SUM(quantity)`

This is the task where a missed call site produces a **silently wrong number rather than an error**. Each conversion gets an assertion.

**Files:**
- Modify: `app/Models/EquipmentCatalog.php:47-50`, `app/Http/Controllers/EquipmentItemController.php:209-274`, `app/Http/Controllers/EquipmentCatalogController.php:22-48,50-70`
- Test: `tests/Feature/EquipmentLotModelTest.php`

**Interfaces:**
- Consumes: `EquipmentStockService::availableQuantity()` from Task 4.
- Produces: `EquipmentCatalog->available_count` and `->total_quantity` now measured in units, not rows.

- [ ] **Step 1: Write the failing test**

```php
    #[Test]
    public function catalog_counts_are_measured_in_units_not_rows(): void
    {
        $catalog = $this->catalog();

        EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 20]);
        EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 5, 'condition' => 'Damaged']);

        $catalog = $catalog->fresh();

        $this->assertSame(25, $catalog->total_quantity);
        $this->assertSame(25, $catalog->available_count);
    }

    #[Test]
    public function rented_units_leave_the_available_count(): void
    {
        $catalog = $this->catalog();
        $lot = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 20]);
        $this->rent($lot, 8);

        $this->assertSame(12, $catalog->fresh()->available_count);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=catalog_counts_are_measured_in_units`
Expected: FAIL — `total_quantity` is null and `available_count` returns `2` (the row count) instead of `25`.

- [ ] **Step 3: Rewrite the catalog accessors**

Replace `getAvailableCountAttribute()` in `app/Models/EquipmentCatalog.php`:

```php
    public function getTotalQuantityAttribute(): int
    {
        return (int) $this->items()->sum('quantity');
    }

    public function getAvailableCountAttribute(): int
    {
        return $this->items()
            ->get()
            ->sum(fn (EquipmentItem $item) => $item->available_quantity);
    }
```

`available_count` keeps its name so existing Vue bindings and controllers do not break; only its meaning becomes units.

Loading the items to sum a derived value is acceptable here — a catalog holds a handful of lots, not thousands of rows, and it is now *fewer* rows than before the lot model. If a catalog ever grows large this becomes a single aggregate query joining open rentals; do not pre-optimise it now.

- [ ] **Step 4: Update the inventory report**

In `app/Http/Controllers/EquipmentItemController.php:209-274`, the status summary, condition breakdown and category breakdown all use `count()` / `COUNT(*)`. Change each aggregate to `SUM(quantity)`. For the raw query builder blocks:

```php
// before:  ->selectRaw('status, COUNT(*) as total')
// after:
->selectRaw('status, SUM(quantity) as total')
```

Apply the same substitution to the condition and category breakdown queries in that method. The overdue-rentals query at `:249-254` counts rentals, not units — leave its `COUNT` alone but add `type` filtering in Task 9.

- [ ] **Step 5: Update the catalog list**

`EquipmentCatalogController::index()` uses `withCount('items')`, which counts rows. Add the unit total alongside it:

```php
->withCount('items')
->withSum('items as units_total', 'quantity')
```

The Vue list shows `units_total` as the headline number and keeps `items_count` as the lot count.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=Equipment`
Expected: PASS — new count tests green, all existing equipment tests still green.

- [ ] **Step 7: Commit**

```bash
git add app/Models/EquipmentCatalog.php app/Http/Controllers/EquipmentItemController.php app/Http/Controllers/EquipmentCatalogController.php tests/Feature/EquipmentLotModelTest.php
git commit -m "feat(equipment): count units instead of rows"
```

---

### Task 6: Branch pivot

**Files:**
- Create: `database/migrations/2026_07_20_000003_create_branch_equipment_item_table.php`, `tests/Feature/EquipmentBranchTaggingTest.php`
- Modify: `app/Models/EquipmentItem.php`, `app/Models/Branch.php`

**Interfaces:**
- Produces: `EquipmentItem->branches()` BelongsToMany, `Branch->equipmentItems()` BelongsToMany, and a `branch_id` filter that includes untagged lots.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentBranchTaggingTest extends TestCase
{
    use RefreshDatabase;

    private function lot(): EquipmentItem
    {
        $catalog = EquipmentCatalog::create(['name' => 'Dossards', 'category' => 'Apparel']);

        return EquipmentItem::create([
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-01-01',
            'quantity' => 50,
        ]);
    }

    #[Test]
    public function a_lot_can_belong_to_several_branches(): void
    {
        $lot = $this->lot();
        $football = Branch::create(['name_ar' => 'كرة القدم', 'name_fr' => 'Football', 'name_en' => 'Football']);
        $basket = Branch::create(['name_ar' => 'سلة', 'name_fr' => 'Basket', 'name_en' => 'Basketball']);

        $lot->branches()->sync([$football->id, $basket->id]);

        $this->assertCount(2, $lot->fresh()->branches);
    }

    #[Test]
    public function filtering_by_branch_includes_club_wide_lots(): void
    {
        $football = Branch::create(['name_ar' => 'كرة القدم', 'name_fr' => 'Football', 'name_en' => 'Football']);

        $tagged = $this->lot();
        $tagged->branches()->sync([$football->id]);

        $clubWide = $this->lot();   // no branches — usable by everyone

        $ids = EquipmentItem::forBranch($football->id)->pluck('id');

        $this->assertTrue($ids->contains($tagged->id), 'tagged lot must appear');
        $this->assertTrue($ids->contains($clubWide->id), 'untagged lot is club-wide and must appear');
    }
}
```

Check `Branch`'s actual fillable before running — if the model requires more columns, add them to the `Branch::create()` calls above.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=EquipmentBranchTaggingTest`
Expected: FAIL — `Call to undefined method App\Models\EquipmentItem::branches()`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_equipment_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'equipment_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_equipment_item');
    }
};
```

- [ ] **Step 4: Add the relations and the scope**

`app/Models/EquipmentItem.php`:

```php
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_equipment_item');
    }

    /**
     * Lots usable by a branch: those tagged with it, plus untagged
     * (club-wide) lots, which are shared across the whole club.
     */
    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        if (! $branchId) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($branchId) {
            $q->whereHas('branches', fn (Builder $b) => $b->where('branches.id', $branchId))
                ->orWhereDoesntHave('branches');
        });
    }
```

Add `use Illuminate\Database\Eloquent\Builder;` and `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` to the imports.

`app/Models/Branch.php`:

```php
    public function equipmentItems(): BelongsToMany
    {
        return $this->belongsToMany(EquipmentItem::class, 'branch_equipment_item');
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=EquipmentBranchTaggingTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_20_000003_create_branch_equipment_item_table.php app/Models/EquipmentItem.php app/Models/Branch.php tests/Feature/EquipmentBranchTaggingTest.php
git commit -m "feat(equipment): tag lots with branches, untagged means club-wide"
```

---

### Task 7: Receive stock — purchase becomes deliberate

**Files:**
- Create: `app/Http/Requests/Equipment/ReceiveStockRequest.php`, `tests/Feature/EquipmentReceiveStockTest.php`
- Modify: `app/Services/Equipment/EquipmentStockService.php`, `app/Http/Controllers/EquipmentItemController.php:34-78`, `routes/web.php`

**Interfaces:**
- Consumes: `EquipmentStockService` from Task 4.
- Produces: `EquipmentStockService::receive(array $data, ?int $userId): EquipmentItem`, route `equipment.stock.receive` (POST `/equipment/stock/receive`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentReceiveStockTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function catalog(): EquipmentCatalog
    {
        return EquipmentCatalog::create(['name' => 'Dossards', 'category' => 'Apparel']);
    }

    #[Test]
    public function receiving_stock_with_the_expense_box_ticked_creates_one_transaction(): void
    {
        $catalog = $this->catalog();

        $this->actingAs($this->user())->post(route('equipment.stock.receive'), [
            'catalog_id' => $catalog->id,
            'quantity' => 50,
            'unit_price' => 120,
            'purchase_date' => '2026-03-15',
            'condition' => 'New',
            'record_expense' => true,
        ])->assertRedirect();

        $this->assertSame(1, Transaction::count());

        $transaction = Transaction::first();
        $this->assertSame('expense', $transaction->transaction_type);
        $this->assertEquals(6000, $transaction->amount, 'amount is unit price x quantity');
        $this->assertSame(2026, $transaction->fiscal_year, 'fiscal year comes from the purchase date, not today');

        $lot = EquipmentItem::first();
        $this->assertSame(50, $lot->quantity);
        $this->assertSame($transaction->id, $lot->purchase_transaction_id);
    }

    #[Test]
    public function receiving_stock_without_the_expense_box_touches_no_finance(): void
    {
        $this->actingAs($this->user())->post(route('equipment.stock.receive'), [
            'catalog_id' => $this->catalog()->id,
            'quantity' => 30,
            'unit_price' => 120,
            'purchase_date' => '2026-03-15',
            'condition' => 'New',
            'record_expense' => false,
            'received_via' => 'donation',
        ])->assertRedirect();

        $this->assertSame(0, Transaction::count());
        $this->assertSame('donation', EquipmentItem::first()->received_via);
    }

    #[Test]
    public function adding_a_single_item_no_longer_creates_a_transaction_as_a_side_effect(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Match Ball', 'category' => 'Balls', 'requires_serial' => true]);

        $this->actingAs($this->user())->post(route('equipment.items.store'), [
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-01-01',
            'condition' => 'New',
            'purchase_price' => 4500,
        ])->assertRedirect();

        $this->assertSame(0, Transaction::count(), 'adding an item must not spend money');
    }
```

Closing brace for the class follows.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=EquipmentReceiveStockTest`
Expected: FAIL — `Route [equipment.stock.receive] not defined`, and the third test fails because a Transaction *is* currently created.

- [ ] **Step 3: Write the Form Request**

```php
<?php

namespace App\Http\Requests\Equipment;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveStockRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'catalog_id' => ['required', 'exists:equipment_catalogs,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_date' => ['required', 'date'],
            'condition' => ['required', 'in:New,Good,Fair,Poor,Damaged'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'received_via' => ['nullable', 'in:purchase,donation,opening_balance,adjustment'],
            'record_expense' => ['boolean'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['exists:branches,id'],
        ];
    }
}
```

- [ ] **Step 4: Add `receive()` to the service**

```php
    public function receive(array $data, ?int $userId = null): EquipmentItem
    {
        return DB::transaction(function () use ($data, $userId) {
            $quantity = (int) $data['quantity'];
            $unitPrice = isset($data['unit_price']) ? (float) $data['unit_price'] : null;
            $purchaseDate = $data['purchase_date'];

            $transactionId = null;

            if (($data['record_expense'] ?? false) && $unitPrice > 0) {
                $transaction = Transaction::create([
                    'transaction_type' => 'expense',
                    'category' => 'equipment',
                    'status' => 'Paid',
                    'amount' => $unitPrice * $quantity,
                    'transaction_date' => $purchaseDate,
                    'description' => "Equipment purchase: {$quantity} x catalog #{$data['catalog_id']}",
                    'recorded_by_user_id' => $userId,
                    // fiscal year follows the purchase date, not today
                    'fiscal_year' => Carbon::parse($purchaseDate)->year,
                ]);

                $transactionId = $transaction->id;
            }

            $item = EquipmentItem::create([
                'catalog_id' => $data['catalog_id'],
                'quantity' => $quantity,
                'purchase_date' => $purchaseDate,
                'condition' => $data['condition'],
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'received_via' => $data['received_via'] ?? 'purchase',
                'purchase_transaction_id' => $transactionId,
                'status' => 'Available',
            ]);

            if (! empty($data['branch_ids'])) {
                $item->branches()->sync($data['branch_ids']);
            }

            EquipmentHistory::create([
                'item_id' => $item->id,
                'user_id' => $userId,
                'event_type' => 'Received',
                'details' => [
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'received_via' => $item->received_via,
                    'transaction_id' => $transactionId,
                ],
                'event_timestamp' => now(),
            ]);

            return $item;
        });
    }
```

Add `use App\Models\EquipmentHistory;`, `use App\Models\Transaction;`, `use Illuminate\Support\Carbon;` and `use Illuminate\Support\Facades\DB;` to the service imports.

Confirm the exact `transactions` column names against `app/Models/Transaction.php` before running — this project has evolved its finance schema and the fillable list is authoritative.

- [ ] **Step 5: Add the controller action and route**

In `app/Http/Controllers/EquipmentItemController.php`:

```php
    public function receive(ReceiveStockRequest $request, EquipmentStockService $stock)
    {
        $stock->receive($request->validated(), $request->user()->id);

        return back()->with('success', 'flash.equipment_stock_received');
    }
```

In `routes/web.php`, beside the other equipment item routes:

```php
Route::post('equipment/stock/receive', [EquipmentItemController::class, 'receive'])->name('equipment.stock.receive');
```

Use a `flash.*` key here rather than an English string — Package B converts the rest, and new code should not add to the debt.

- [ ] **Step 6: Remove the automatic Transaction from `store()`**

In `EquipmentItemController::store()` (`:34-78`), delete the block that creates a `Transaction` from `purchase_price` (`:59-73`) and the assignment of `purchase_transaction_id` that depends on it. Adding an item records what it is worth; it never spends money. Purchases go through `receive()`.

Leave the CSV importer's transaction block at `:391-404` alone for now — it is covered by `EquipmentImportExportTest` and changing it belongs with the import work, not here. Note it in the commit body.

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter=EquipmentReceiveStockTest`
Expected: PASS (3 tests)

Run: `php artisan test --filter=Equipment`
Expected: PASS. If `EquipmentItemManagementTest` asserted that storing an item creates a Transaction, that assertion encoded the bug — update it to assert no Transaction is created, and say so in the commit body.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Equipment/ReceiveStockRequest.php app/Services/Equipment/EquipmentStockService.php app/Http/Controllers/EquipmentItemController.php routes/web.php tests/Feature/EquipmentReceiveStockTest.php
git commit -m "feat(equipment): receive stock as a deliberate act

Adding an item no longer creates an expense Transaction as a side effect.
Purchases go through receive-stock, where recording the expense is an
explicit checkbox. Also fixes fiscal_year, which used the current year
instead of the purchase date's year.

The CSV importer still creates transactions on import; that path is
unchanged here."
```

---

### Task 8: Split a lot by condition

**Files:**
- Create: `app/Http/Requests/Equipment/SplitLotRequest.php`
- Modify: `app/Services/Equipment/EquipmentStockService.php`, `app/Http/Controllers/EquipmentItemController.php`, `routes/web.php`
- Test: `tests/Feature/EquipmentLotModelTest.php`

**Interfaces:**
- Produces: `EquipmentStockService::splitLot(EquipmentItem $item, int $quantity, string $condition, ?int $userId, ?string $notes): EquipmentItem` returning the **new** lot; route `equipment.stock.split`.

- [ ] **Step 1: Write the failing test**

```php
    #[Test]
    public function splitting_a_lot_moves_units_into_a_new_row(): void
    {
        $lot = $this->lot(20);

        $damaged = app(EquipmentStockService::class)->splitLot($lot, 3, 'Damaged', null, 'punctured');

        $this->assertSame(17, $lot->fresh()->quantity);
        $this->assertSame(3, $damaged->quantity);
        $this->assertSame('Damaged', $damaged->condition);
        $this->assertSame($lot->catalog_id, $damaged->catalog_id);
    }

    #[Test]
    public function a_split_cannot_exceed_available_units(): void
    {
        $lot = $this->lot(20);
        $this->rent($lot, 18);

        $this->expectException(\InvalidArgumentException::class);

        app(EquipmentStockService::class)->splitLot($lot->fresh(), 5, 'Damaged', null, null);
    }

    #[Test]
    public function splitting_records_history_on_both_lots(): void
    {
        $lot = $this->lot(20);

        $damaged = app(EquipmentStockService::class)->splitLot($lot, 3, 'Damaged', null, null);

        $this->assertDatabaseHas('equipment_histories', ['item_id' => $lot->id, 'event_type' => 'Split Out']);
        $this->assertDatabaseHas('equipment_histories', ['item_id' => $damaged->id, 'event_type' => 'Split In']);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=splitting_a_lot`
Expected: FAIL — `Call to undefined method ...::splitLot()`.

- [ ] **Step 3: Implement `splitLot()`**

```php
    /**
     * Move `$quantity` units out of a lot into a new lot with a different
     * condition — "3 of these 20 balls are punctured". Atomic: the two
     * rows must never disagree about the total.
     */
    public function splitLot(EquipmentItem $item, int $quantity, string $condition, ?int $userId = null, ?string $notes = null): EquipmentItem
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Split quantity must be at least 1.');
        }

        $available = $this->availableQuantity($item);

        if ($quantity > $available) {
            throw new \InvalidArgumentException("Cannot split {$quantity} units: only {$available} are available in this lot.");
        }

        return DB::transaction(function () use ($item, $quantity, $condition, $userId, $notes) {
            $item->decrement('quantity', $quantity);

            $new = EquipmentItem::create([
                'catalog_id' => $item->catalog_id,
                'quantity' => $quantity,
                'purchase_date' => $item->purchase_date,
                'condition' => $condition,
                'location' => $item->location,
                'status' => 'Available',
                'received_via' => $item->received_via,
                'notes' => $notes,
            ]);

            $new->branches()->sync($item->branches()->pluck('branches.id')->all());

            EquipmentHistory::create([
                'item_id' => $item->id,
                'user_id' => $userId,
                'event_type' => 'Split Out',
                'details' => ['quantity' => $quantity, 'to_item_id' => $new->id, 'condition' => $condition, 'notes' => $notes],
                'event_timestamp' => now(),
            ]);

            EquipmentHistory::create([
                'item_id' => $new->id,
                'user_id' => $userId,
                'event_type' => 'Split In',
                'details' => ['quantity' => $quantity, 'from_item_id' => $item->id, 'condition' => $condition, 'notes' => $notes],
                'event_timestamp' => now(),
            ]);

            return $new;
        });
    }
```

Splitting is capped at *available* units, not total, because units that are out on rental cannot be reclassified — they are not in your hands to inspect.

- [ ] **Step 4: Add the Form Request, controller action and route**

`app/Http/Requests/Equipment/SplitLotRequest.php`:

```php
<?php

namespace App\Http\Requests\Equipment;

use Illuminate\Foundation\Http\FormRequest;

class SplitLotRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1'],
            'condition' => ['required', 'in:New,Good,Fair,Poor,Damaged'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
```

Controller:

```php
    public function split(SplitLotRequest $request, EquipmentItem $item, EquipmentStockService $stock)
    {
        try {
            $stock->splitLot($item, (int) $request->quantity, $request->condition, $request->user()->id, $request->notes);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'flash.equipment_lot_split');
    }
```

Route:

```php
Route::post('equipment/items/{item}/split', [EquipmentItemController::class, 'split'])->name('equipment.stock.split');
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=EquipmentLotModelTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/Equipment/SplitLotRequest.php app/Services/Equipment/EquipmentStockService.php app/Http/Controllers/EquipmentItemController.php routes/web.php tests/Feature/EquipmentLotModelTest.php
git commit -m "feat(equipment): split a lot to reclassify damaged units"
```

---

### Task 9: Quantity-aware rent, assign, and partial return

**Files:**
- Modify: `app/Services/Equipment/EquipmentLifecycleService.php:13-63`, `app/Http/Requests/Equipment/RentEquipmentRequest.php`, `app/Http/Controllers/EquipmentItemController.php:132-169`
- Test: `tests/Feature/EquipmentLotRentalTest.php`

**Interfaces:**
- Consumes: `EquipmentStockService::availableQuantity()` (Task 4), rental columns (Task 3).
- Produces: `rentOut(EquipmentItem, Model $rentable, array $options)` and `returnItem(EquipmentRental, array $options)`.

- [ ] **Step 1: Write the failing test**

```php
    #[Test]
    public function ten_units_can_be_issued_from_a_lot_of_twenty(): void
    {
        $lot = $this->lot(20);
        $player = $this->player();

        app(EquipmentLifecycleService::class)->rentOut($lot, $player, [
            'quantity' => 10,
            'checkout_date' => '2026-03-01',
            'due_date' => '2026-03-31',
        ]);

        $this->assertSame(10, app(EquipmentStockService::class)->availableQuantity($lot->fresh()));
        $this->assertSame('Available', $lot->fresh()->status, 'a multi-unit lot stays Available');
    }

    #[Test]
    public function issuing_more_than_available_is_rejected(): void
    {
        $lot = $this->lot(20);

        $this->expectException(\InvalidArgumentException::class);

        app(EquipmentLifecycleService::class)->rentOut($lot, $this->player(), ['quantity' => 25]);
    }

    #[Test]
    public function a_partial_return_leaves_the_rest_outstanding(): void
    {
        $lot = $this->lot(20);
        $rental = app(EquipmentLifecycleService::class)->rentOut($lot, $this->player(), ['quantity' => 10]);

        app(EquipmentLifecycleService::class)->returnItem($rental, ['quantity' => 6, 'condition' => 'Good']);

        $rental = $rental->fresh();
        $this->assertSame(6, $rental->returned_quantity);
        $this->assertNull($rental->return_date, 'the rental stays open until every unit is back');
        $this->assertSame(16, app(EquipmentStockService::class)->availableQuantity($lot->fresh()));
    }

    #[Test]
    public function returning_the_last_unit_closes_the_rental(): void
    {
        $lot = $this->lot(20);
        $rental = app(EquipmentLifecycleService::class)->rentOut($lot, $this->player(), ['quantity' => 10]);

        app(EquipmentLifecycleService::class)->returnItem($rental, ['quantity' => 6]);
        app(EquipmentLifecycleService::class)->returnItem($rental->fresh(), ['quantity' => 4]);

        $this->assertNotNull($rental->fresh()->return_date);
        $this->assertSame(20, app(EquipmentStockService::class)->availableQuantity($lot->fresh()));
    }

    #[Test]
    public function the_checkout_note_survives_a_return(): void
    {
        $lot = $this->lot(1);
        $rental = app(EquipmentLifecycleService::class)->rentOut($lot, $this->player(), [
            'quantity' => 1,
            'notes' => 'handed over at training',
        ]);

        app(EquipmentLifecycleService::class)->returnItem($rental, ['quantity' => 1, 'notes' => 'returned dirty']);

        $rental = $rental->fresh();
        $this->assertSame('handed over at training', $rental->notes);
        $this->assertSame('returned dirty', $rental->return_notes);
    }

    #[Test]
    public function an_assignment_is_never_overdue(): void
    {
        $lot = $this->lot(5);
        $rental = app(EquipmentLifecycleService::class)->rentOut($lot, $this->player(), [
            'quantity' => 1,
            'type' => 'assignment',
            'due_date' => '2020-01-01',
        ]);

        $this->assertFalse($rental->fresh()->is_overdue);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=EquipmentLotRentalTest`
Expected: FAIL — `rentOut()` currently takes `(?string $dueDate, ?int $userId, ?string $notes)`, so passing an array throws a TypeError.

- [ ] **Step 3: Rewrite `rentOut()`**

```php
    public function rentOut(EquipmentItem $item, Model $rentable, array $options = []): EquipmentRental
    {
        $quantity = (int) ($options['quantity'] ?? 1);
        $type = $options['type'] ?? 'rental';
        $available = app(EquipmentStockService::class)->availableQuantity($item);

        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        if ($quantity > $available) {
            throw new \InvalidArgumentException("Cannot issue {$quantity} unit(s): only {$available} available.");
        }

        return DB::transaction(function () use ($item, $rentable, $options, $quantity, $type) {
            // Status is a lot-level disposition. Only a single-unit lot flips
            // to Rented; a multi-unit lot stays Available and lets the
            // availability formula do the work.
            if ($item->quantity === 1) {
                $item->update(['status' => 'Rented']);
            }

            $rental = EquipmentRental::create([
                'equipment_item_id' => $item->id,
                'rentable_type' => $rentable->getMorphClass(),
                'rentable_id' => $rentable->getKey(),
                'type' => $type,
                'quantity' => $quantity,
                'returned_quantity' => 0,
                'checkout_date' => $options['checkout_date'] ?? now(),
                'due_date' => $type === 'assignment' ? null : ($options['due_date'] ?? null),
                'notes' => $options['notes'] ?? null,
            ]);

            $this->logHistory($item, $options['user_id'] ?? null, $type === 'assignment' ? 'Assigned' : 'Checkout', [
                'rentable_type' => $rentable->getMorphClass(),
                'rentable_id' => $rentable->getKey(),
                'quantity' => $quantity,
                'due_date' => $rental->due_date,
            ]);

            return $rental;
        });
    }
```

An assignment never carries a due date — it is open-ended by definition, so one cannot be stored and then silently ignored.

- [ ] **Step 4: Rewrite `returnItem()`**

```php
    public function returnItem(EquipmentRental $rental, array $options = []): void
    {
        $item = $rental->equipmentItem;
        $quantity = (int) ($options['quantity'] ?? $rental->outstanding_quantity);
        $condition = $options['condition'] ?? $item->condition;

        if ($rental->return_date !== null) {
            throw new \InvalidArgumentException('This rental is already closed.');
        }

        if ($quantity < 1 || $quantity > $rental->outstanding_quantity) {
            throw new \InvalidArgumentException("Cannot return {$quantity} unit(s): {$rental->outstanding_quantity} outstanding.");
        }

        DB::transaction(function () use ($item, $rental, $options, $quantity, $condition) {
            $returned = $rental->returned_quantity + $quantity;
            $fullyReturned = $returned >= $rental->quantity;

            $rental->update([
                'returned_quantity' => $returned,
                // The rental closes only when every unit is back.
                'return_date' => $fullyReturned ? ($options['return_date'] ?? now()) : null,
                // Written to its own column so the checkout note survives.
                'return_notes' => $options['notes'] ?? $rental->return_notes,
            ]);

            if ($item->quantity === 1 && $fullyReturned) {
                $item->update(['status' => 'Available', 'condition' => $condition]);
            }

            $this->logHistory($item, $options['user_id'] ?? null, 'Return', [
                'rental_id' => $rental->id,
                'quantity' => $quantity,
                'returned_condition' => $condition,
                'fully_returned' => $fullyReturned,
            ]);
        });
    }
```

For a multi-unit lot the returned condition is **not** written back to the lot — if 6 of 10 dossards come back damaged, that is a `splitLot()` call, not a mutation of all 20. The UI offers "return" and "return as damaged" as separate actions, the second one splitting after returning.

Add `use App\Services\Equipment\EquipmentStockService;` to the imports.

- [ ] **Step 5: Update the callers**

`EquipmentItemController::rent()` (`:132-152`) and `returnItem()` (`:154-169`) pass positional arguments. Convert both to the array form, passing `quantity`, `type`, `checkout_date`, `due_date`, `notes` and `user_id` from the request. Wrap each in `try { ... } catch (\InvalidArgumentException $e) { return back()->with('error', $e->getMessage()); }` so a rejected quantity surfaces as a flash rather than a 500.

Extend `RentEquipmentRequest` rules:

```php
            'quantity' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'in:rental,assignment'],
            'checkout_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:checkout_date'],
```

The old `after:today` rule on `due_date` is replaced: a rental can be backdated, so the due date must be after the *checkout* date, not after today.

Also update `markAsLost()` (`:100-117`), which closes the active rental with `'notes' => 'Marked as lost'` — change that to `'return_notes'` and set `returned_quantity` to `quantity` so availability maths stays consistent.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=EquipmentLotRentalTest`
Expected: PASS (8 tests)

Run: `php artisan test`
Expected: PASS. `EquipmentItemManagementTest` and `EquipmentHistoryTest` call `rentOut`/`returnItem` with positional arguments — update those call sites to the array form. This is a deliberate signature change, so fixing the callers is part of the task.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Equipment/EquipmentLifecycleService.php app/Http/Requests/Equipment/RentEquipmentRequest.php app/Http/Controllers/EquipmentItemController.php tests/Feature/
git commit -m "feat(equipment): quantity-aware rentals, assignments and partial returns

rentOut/returnItem take an options array so a lot can issue N units.
Adds the assignment type (open-ended, never overdue) and fixes the
return note overwriting the checkout note."
```

---

### Task 10: Stock-take quantities

**Files:**
- Create: `database/migrations/2026_07_20_000005_add_quantities_to_inventory_session_items_table.php`
- Modify: `app/Models/InventorySessionItem.php`, `app/Http/Controllers/InventoryController.php`
- Test: `tests/Feature/InventoryQuantityTest.php`

**Interfaces:**
- Produces: `expected_quantity`, `found_quantity` on `inventory_session_items`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\InventorySession;
use App\Models\InventorySessionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InventoryQuantityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_count_line_records_expected_and_found_quantities(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Dossards', 'category' => 'Apparel']);
        $lot = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 50]);
        $session = InventorySession::create(['reference' => 'INV-1', 'session_date' => '2026-07-20', 'type' => 'ad_hoc']);

        $line = InventorySessionItem::create([
            'inventory_session_id' => $session->id,
            'equipment_item_id' => $lot->id,
            'expected_status' => 'Available',
            'expected_quantity' => 50,
            'found_quantity' => 47,
            'counted' => true,
        ]);

        $line = $line->fresh();
        $this->assertSame(50, $line->expected_quantity);
        $this->assertSame(47, $line->found_quantity);
        $this->assertSame(3, $line->missing_quantity);
    }
}
```

Verify `InventorySession`'s required columns against its migration before running; add any missing required fields to the `create()` call.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=InventoryQuantityTest`
Expected: FAIL — `no such column: expected_quantity`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_session_items', function (Blueprint $table) {
            $table->unsignedInteger('expected_quantity')->default(1)->after('expected_location');
            $table->unsignedInteger('found_quantity')->nullable()->after('found');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_session_items', function (Blueprint $table) {
            $table->dropColumn(['expected_quantity', 'found_quantity']);
        });
    }
};
```

- [ ] **Step 4: Update the model**

Add both columns to `$fillable`, cast both to `integer`, and add:

```php
    public function getMissingQuantityAttribute(): int
    {
        return max(0, $this->expected_quantity - (int) $this->found_quantity);
    }
```

- [ ] **Step 5: Populate `expected_quantity` when a session opens**

In `InventoryController`, where session lines are generated from items, set `expected_quantity` to the lot's `quantity`. The session totals (`total_expected`, `total_found`, `total_missing` on `inventory_sessions`) must sum quantities rather than count rows — update those aggregates in the same method.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=Inventory`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_20_000005_add_quantities_to_inventory_session_items_table.php app/Models/InventorySessionItem.php app/Http/Controllers/InventoryController.php tests/Feature/InventoryQuantityTest.php
git commit -m "feat(inventory): count units per lot during a stock-take"
```

---

### Task 11: Catalog forms — choose tracking mode

**Files:**
- Modify: `resources/js/Pages/Equipment/Catalog/Create.vue:16-23`, `Edit.vue:19-26`, `Index.vue`
- Modify: `app/Http/Requests/Equipment/StoreEquipmentCatalogRequest.php`, and the matching update request
- Test: manual, plus `tests/Feature/EquipmentLotModelTest.php` assertion on the stored flag

- [ ] **Step 1: Add the rule**

Add to both catalog Form Requests:

```php
            'requires_serial' => ['boolean'],
```

- [ ] **Step 2: Add the control to Create.vue**

Add `requires_serial: false` to the `useForm({...})` object at `:16-23`, and render a checkbox below the category select:

```vue
<label class="flex items-start gap-2">
  <input type="checkbox" v-model="form.requires_serial" class="mt-1 rounded border-gray-300" />
  <span>
    <span class="font-medium">{{ t('equipment.track_each_unit') }}</span>
    <span class="block text-sm text-gray-500">{{ t('equipment.track_each_unit_hint') }}</span>
  </span>
</label>
```

Add `equipment.track_each_unit` ("Track each unit individually") and `equipment.track_each_unit_hint` ("Give every unit its own serial number and history. Leave off for items you count, like bibs or balls.") to all three of `resources/js/i18n/{ar,en,fr}.json`.

- [ ] **Step 3: Mirror it in Edit.vue**

Same field in the `useForm` at `:19-26` and the same control. Disable the checkbox when the catalog already has items — switching mode with stock on hand is a data mess:

```vue
<input type="checkbox" v-model="form.requires_serial" :disabled="catalog.items_count > 0" ... />
```

Show the reason when disabled so it does not look broken.

- [ ] **Step 4: Show units in the list**

`Index.vue` currently shows a row count. Show `units_total` (added in Task 5) as the headline, with the lot count as secondary text.

- [ ] **Step 5: Build and eyeball**

Run: `npm run build`
Expected: builds with no errors. Then load `/equipment/catalogs/create` and confirm the checkbox is unticked by default.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Equipment/Catalog/ resources/js/i18n/ app/Http/Requests/Equipment/
git commit -m "feat(equipment): catalog form chooses serial or count tracking"
```

---

### Task 12: Catalog show page — lots, receive, split

**Files:**
- Modify: `resources/js/Pages/Equipment/Catalog/Show.vue`
- Modify: `app/Http/Controllers/EquipmentCatalogController.php:50-70`

- [ ] **Step 1: Pass the new props**

In `EquipmentCatalogController::show()`, eager-load `branches` on items, and pass `branches` (all club branches, for the pickers) alongside the existing `storageLocations` and `players`. Include each item's `available_quantity`.

- [ ] **Step 2: Add the condition breakdown**

Above the items table, render a summary strip for count-tracked catalogs:

```
Good 15   Fair 2   Damaged 3     |     Available 11   Out 4   Total 20
```

Computed client-side from the items array, grouped by `condition`.

- [ ] **Step 3: Add quantity and branches to the items table**

Add a **Qty** column showing `available_quantity / quantity`, and a branches cell rendering each branch as a chip, or an "All branches" chip when the array is empty. Hide the serial column entirely when `catalog.requires_serial` is false.

- [ ] **Step 4: Add the Receive stock modal**

Fields: quantity, unit price, purchase date, condition, location, branches (multi-select), notes, and a **Record as expense** checkbox **checked by default**. Posts to `equipment.stock.receive`.

When the box is unticked, reveal a `received_via` select (donation / opening balance / adjustment) so the reason is captured.

- [ ] **Step 5: Add the split action**

A per-row "Mark N as damaged" action opening a small modal: quantity, target condition, notes. Posts to `equipment.stock.split`. Cap the quantity input at the row's `available_quantity`.

- [ ] **Step 6: Build and test manually**

Run: `npm run build`

Then exercise the flow: create a count-tracked catalog, receive 20 units, confirm the count reads 20, split 3 as damaged, confirm 17 / 3 and that no Transaction appeared when the expense box was unticked.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/Equipment/Catalog/Show.vue app/Http/Controllers/EquipmentCatalogController.php
git commit -m "feat(equipment): lot quantities, receive stock and split on the catalog page"
```

---

### Task 13: Rent / assign form

**Files:**
- Modify: `resources/js/Pages/Equipment/Catalog/Show.vue:73-79,454`

- [ ] **Step 1: Add the missing fields to `rentForm`**

```js
const rentForm = useForm({
  equipment_item_id: null,
  rentable_type: 'Player',
  rentable_id: null,
  type: 'rental',
  quantity: 1,
  checkout_date: new Date().toISOString().slice(0, 10),
  due_date: '',
  notes: '',
})
```

- [ ] **Step 2: Add a rental/assignment toggle**

Two radio buttons. Selecting **assignment** hides the due-date field entirely — an assignment is open-ended, and showing a field the backend discards would be a lie.

- [ ] **Step 3: Fix the hardcoded `rentable_type`**

`:75` hardcodes `'Player'`, so equipment can never go to a staff member despite full backend support. Add a Player/User toggle that switches the `SearchableSelect`'s option source. Pass a `users` list from the controller alongside `players`.

- [ ] **Step 4: Add the quantity input**

A number input, `min="1"`, `:max="selectedItem.available_quantity"`, hidden when the lot holds a single unit. Show "N available" next to it.

- [ ] **Step 5: Add the cross-branch soft warning**

When the chosen player's branches do not intersect the lot's branches (and the lot is not club-wide), show an inline amber warning — "This equipment belongs to Football; the player is in Basketball" — and **still allow submitting**. Never block.

- [ ] **Step 6: Add partial return to the return modal**

Add a quantity input defaulting to the outstanding quantity, capped at it. Show "6 of 10 returned" once a partial return exists.

- [ ] **Step 7: Build and test manually**

Run: `npm run build`

Exercise: issue 10 of 20 dossards to a player, confirm availability reads 10, return 6, confirm it reads 16 and the rental is still open.

- [ ] **Step 8: Commit**

```bash
git add resources/js/Pages/Equipment/Catalog/Show.vue app/Http/Controllers/EquipmentCatalogController.php
git commit -m "feat(equipment): issue quantities, assign to staff, return partially"
```

---

### Task 14: Inventory report in units

**Files:**
- Modify: `resources/js/Pages/Equipment/Inventory.vue`, `app/Http/Controllers/EquipmentItemController.php:209-274`

- [ ] **Step 1: Confirm the backend sums quantities**

Task 5 changed these aggregates. Verify each stat card number is a `SUM(quantity)` and not a row count by seeding a lot of 20 and checking the report reads 20.

- [ ] **Step 2: Add total asset value**

`Inventory.vue` imports `useFormatMoney` (`:7,:10`) and never renders a money figure. Add a total value card: `SUM(quantity × unit price)` across lots, using the item's own purchase price where set and falling back to the catalog's reference value.

- [ ] **Step 3: Add value per branch**

A small table of asset value grouped by branch, with club-wide lots in their own row. This is the report the branch pivot from Task 6 unlocks.

- [ ] **Step 4: Filter the overdue table by type**

The overdue query must exclude `type = 'assignment'` — assignments have no due date and must never appear as overdue.

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS, entire suite.

Run: `npm run build`
Expected: clean build.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Equipment/Inventory.vue app/Http/Controllers/EquipmentItemController.php
git commit -m "feat(equipment): inventory report in units, with asset value per branch"
```

---

## Verification

After Task 14, before considering Package A done:

- [ ] `php artisan test` — entire suite passes, not just the equipment filter
- [ ] `npm run build` — clean
- [ ] `./vendor/bin/pint --test` — no style violations
- [ ] Manual: create a count-tracked catalog, receive 50 dossards with the expense box ticked, confirm exactly one Transaction exists with the right amount and a fiscal year matching the purchase date
- [ ] Manual: receive 20 more with the box unticked, confirm no new Transaction and the catalog reads 70
- [ ] Manual: issue 10 to a player, return 6, confirm 66 available and the rental still open
- [ ] Manual: split 3 as damaged, confirm the condition strip reads correctly and the total is still 70
- [ ] Manual: create a serialized catalog and confirm the old flow is unchanged — serial generated, one unit, rent and return work
- [ ] Manual: tag a lot to one branch, filter by another branch, confirm club-wide lots still appear

## Deferred

Not in this package; each needs its own decision:

- The CSV importer still creates a Transaction per imported item (`EquipmentItemController.php:391-404`). Aligning import with the receive-stock model is follow-up work.
- `SerialNumberService::nextSequence()` (`:90-102`) pulls every serial into PHP to compute a max. Should be a SQL `MAX`, but it is untouched by the lot model.
- Status `Out of Service` and `EquipmentLifecycleService::retire()` remain unreachable — open decision 2 in the spec.
