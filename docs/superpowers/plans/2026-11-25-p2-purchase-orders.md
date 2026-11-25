# P2 — Purchase Orders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Purchase Orders register ("Bon de commande" / «سند طلب»): draft → sent → received (optional stock receive + prefilled draft payment order) or cancelled, with catalog or free-text lines, prices editable until a payment order is linked, a printable A4 form, the payment-order link both ways (paying it points the received equipment to the expense), and the import of the one old Access order.

**Architecture:** New `purchase_orders` + `purchase_order_lines` tables, `PurchaseOrder` / `PurchaseOrderLine` models, `PurchaseOrderService` (every state change is one DB transaction with an atomic conditional claim, like `PaymentOrderService`), `PurchaseOrderException` flash pattern, thin `PurchaseOrderController` + `PurchaseOrderPrintController`. Numbers come from the existing `YearlySequence` (`NN/YYYY`, own sequence). The payment-order side gains a `purchase_order_id` link validated by one model scope (`PurchaseOrder::linkable`) and claimed atomically (`PurchaseOrder::claimForPaymentOrder`); `PaymentOrderService::markPaid` tags the order's equipment with the new expense. New role module `purchase_orders` (finance group, canonical actions, no approve).

**Tech Stack:** Laravel 13, Inertia + Vue 3 (`<script setup>`), mPDF (`App\Services\Pdf\PdfService`), PHPUnit feature tests (`#[Test]`, `RefreshDatabase`), SQLite (dev/desktop) + MySQL compatible SQL.

Spec: `docs/superpowers/specs/2026-11-25-access-legacy-documents-design.md` — sections "Owner decisions", "Shared rules", "P2 — Purchase orders", "Import of old Access data". P1 code on `main` is the pattern to mirror.

## Global Constraints

- **Worktree:** all work happens in `D:\irnb-purchase-orders` on branch `feat/purchase-orders`, created from `main`: `git worktree add -b feat/purchase-orders D:/irnb-purchase-orders main`. Copy `vendor` (a real copy, no junctions): `cp -r D:/irnb-laravel/vendor D:/irnb-purchase-orders/vendor`; copy `node_modules` the same way: `cp -r D:/irnb-laravel/node_modules D:/irnb-purchase-orders/node_modules` (no `npm ci`: the app works offline); copy `.env`: `cp D:/irnb-laravel/.env D:/irnb-purchase-orders/.env`; then once in the worktree: `npm run build`. Never run tests in `D:\irnb-laravel` (its cached config makes tests refuse to run). If `bootstrap/cache/config.php` exists in the worktree, run `php artisan config:clear` first.
- **Tests:** single file `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/X.php` (from the worktree root); full suite `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit`. Never run two test processes at once. Every new test class calls `$this->withoutVite()` in `setUp()` (new page components are not in the Vite manifest until a rebuild).
- **Staging:** stage explicit paths only (`git add <paths>`), never `git add -A` / `git add .` — the repo root holds untracked private files (an `.accdb`, PDFs, notes).
- **Commits:** conventional message, body ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **SQL:** portable SQLite + MySQL — no `strftime`, no `||` concatenation, no SQLite-only functions; years computed in PHP (`Carbon::parse($date)->year`, `substr($date, 0, 4)`).
- **SQLite schema changes:** never rebuild `equipment_items` inside a transaction (its children `equipment_histories`, `branch_equipment_item`, inventory lines cascade on delete). A column with a foreign key on an existing table is added on SQLite with raw `ALTER TABLE … ADD COLUMN … REFERENCES … ON DELETE SET NULL`.
- **State changes:** every transition is an atomic conditional claim — `Model::whereKey($id)->where(<expected state>)->update([...])`, refuse when it returns 0 — inside `DB::transaction`, exactly like `PaymentOrderService`.
- **Purchase orders are never deleted** (no destroy route); cancelled orders keep their number. Number `NN/YYYY` (calendar year of `order_date`, 2-digit pad, wider when > 99). Legacy rows: `number` null, `is_legacy` true, `legacy_reference` text, read-only.
- **i18n:** ar / fr / en, flat dotted keys, added only through `node scripts/i18n-add.mjs <keys.json>` (insert-only: never re-run it on an existing key, never re-sort catalogs). Keys file: any scratch path, e.g. `C:\Users\MUSTAPHA\AppData\Local\Temp\p2-tN-keys.json`. Purchase-order keys use the prefix `pur.` (`purchase` and `order` are already messages, so `purchase.x` / `order.x` would break). No key segment after a message may be a String method name (`link`, `search`, `at`, `replace`, `trim`, `concat`, `split`…). Vue uses `t()` directly, never `te()`. `npm run i18n:check` must pass after every task that adds keys; every `flash.*` key emitted by PHP must exist in all three catalogs.
- **Activity log properties:** ids, references, amounts / totals / counts only — never names, RIBs or free text.
- **Permissions:** route-name derived (`config/permissions.php`). Module `purchase_orders`, finance group, canonical actions (`view`, `add`, `edit`, `delete` — "create" is `add` in this codebase), no extra action. Print → `view`; send / receive / cancel / create-payment-order → `edit` via `overrides`. Stock tick = the `equipment.stock.receive` route's right; payment-order tick and button also need `payment_orders` → `add`. Grant migration: system roles full, others none, idempotent.
- **PDFs:** mPDF via `PdfService::stream($html, $filename, $rtl)`; `<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">`, RTL when `app()->getLocale() === 'ar'`; `<bdo dir="ltr">` around numbers, dates, references, phones; layout with tables only (mPDF ignores div flex / inline-block lines); text with line breaks via `{!! nl2br(e(...)) !!}` (mPDF ignores `pre-wrap`); labels via `UiLang::get()`; draft prints show the P1 banner (`<div class="draft">{{ $L('po.pdf.draft') }}</div>`, same CSS).
- **UI:** action buttons are `IconButton`; dark mode on every colored class (`dark:text-primary-300`, never `-400`); layouts work at 320 px (`grid-cols-1` base, tables in `overflow-x-auto`); no CDN / remote resources; never name an Inertia `useForm` field `progress` (or another reserved name).
- **Test data:** never real bank numbers — use `00100000000000001234`-style fakes; fake names ("Test Sport Shop", "Président Test").

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Models/Role.php` (modify) | `purchase_orders` in `MODULES` + `MODULE_GROUPS['finance']` |
| `config/permissions.php` (modify) | `purchase-orders` prefix + overrides (send / receive / cancel / payment-order → edit, print → view) |
| `database/migrations/2026_11_25_200000_grant_purchase_order_permissions.php` | system roles get the module |
| `database/migrations/2026_11_25_200001_create_purchase_orders_tables.php` | `purchase_orders`, `purchase_order_lines` |
| `database/migrations/2026_11_25_200002_link_purchase_orders.php` | FK on `payment_orders.purchase_order_id`, new `equipment_items.purchase_order_id` |
| `app/Models/PurchaseOrder.php`, `app/Models/PurchaseOrderLine.php` | constants, reference, total, price rules, `linkable` scope, atomic claim |
| `app/Models/PaymentOrder.php`, `EquipmentItem.php`, `Supplier.php` (modify) | relations; supplier in use when ordered from |
| `app/Services/Finance/PaymentOrderService.php` (modify) | claim the linked purchase order on create / update; `markPaid` tags its equipment |
| `app/Http/Requests/PaymentOrder/SavePaymentOrderRequest.php`, `app/Http/Controllers/PaymentOrderController.php` (modify) | `purchase_order_id` rule, form options, show link |
| `app/Exceptions/PurchaseOrderException.php` | refusals rendered as flash errors |
| `app/Services/Finance/PurchaseOrderService.php` | create / update / send / cancel / receive / createPaymentOrder |
| `app/Services/Equipment/EquipmentStockService.php` (modify) | received and split rows carry `purchase_order_id` |
| `app/Services/Activity/ActivityAction.php`, `ActivitySubjectLink.php` (modify) | six `PURCHASE_ORDER_*` codes, subject link |
| `app/Http/Requests/PurchaseOrder/SavePurchaseOrderRequest.php` | full form (draft) or prices only (sent / received) |
| `app/Http/Controllers/PurchaseOrderController.php` | register pages + actions |
| `app/Http/Controllers/PurchaseOrderPrintController.php`, `resources/views/pdf/purchase-order.blade.php` | the A4 form |
| `resources/js/Pages/PurchaseOrders/{Index,Create,Edit,Show}.vue`, `Partials/PurchaseOrderForm.vue` | UI |
| `resources/js/Pages/PaymentOrders/{Create,Edit,Show}.vue`, `Partials/PaymentOrderForm.vue` (modify) | purchase-order picker, back link |
| `resources/js/Composables/useNavigation.js`, `resources/js/lib/statusLabels.js` (modify) | menu entry, status labels |
| `app/Services/Legacy/LegacyAccessImporter.php`, `scripts/legacy/export-access.ps1`, `tests/Fixtures/legacy-access.json` (modify) | optional `purchase_orders` section |

---

### Task 1: `purchase_orders` right + grant migration

**Files:**
- Modify: `app/Models/Role.php:23-50`
- Modify: `config/permissions.php` (modules list, overrides)
- Create: `database/migrations/2026_11_25_200000_grant_purchase_order_permissions.php`
- Modify: `resources/js/i18n/ar.json`, `fr.json`, `en.json` (via script)
- Test: `tests/Feature/PurchaseOrderRightsTest.php`

**Interfaces:**
- Produces: module key `purchase_orders` (group `finance`, actions `['view','add','edit','delete']`); route prefix `purchase-orders` → `purchase_orders`; exact overrides `purchase-orders.send|receive|cancel|payment-order.store` → `edit`, `purchase-orders.print` → `view`; i18n key `purchase_orders`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PurchaseOrderRightsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchaseOrderRightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[Test]
    public function purchase_orders_is_a_finance_module_with_the_canonical_actions_only(): void
    {
        $this->assertContains('purchase_orders', Role::MODULES);
        $this->assertContains('purchase_orders', Role::MODULE_GROUPS['finance']);
        $this->assertSame(['view', 'add', 'edit', 'delete'], Role::allPermissions()['purchase_orders']);
        $this->assertArrayNotHasKey('purchase_orders', Role::EXTRA_ACTIONS);
    }

    #[Test]
    public function routes_map_to_the_rights_the_spec_names(): void
    {
        $this->assertSame(['purchase_orders', 'view'], PermissionMap::resolve('purchase-orders.index'));
        $this->assertSame(['purchase_orders', 'view'], PermissionMap::resolve('purchase-orders.show'));
        $this->assertSame(['purchase_orders', 'view'], PermissionMap::resolve('purchase-orders.print'));
        $this->assertSame(['purchase_orders', 'add'], PermissionMap::resolve('purchase-orders.create'));
        $this->assertSame(['purchase_orders', 'add'], PermissionMap::resolve('purchase-orders.store'));
        $this->assertSame(['purchase_orders', 'edit'], PermissionMap::resolve('purchase-orders.update'));
        foreach (['send', 'receive', 'cancel', 'payment-order.store'] as $action) {
            $this->assertSame(['purchase_orders', 'edit'], PermissionMap::resolve('purchase-orders.'.$action), $action);
        }
    }

    #[Test]
    public function the_grant_migration_gives_system_roles_the_module_and_runs_twice_safely(): void
    {
        $system = Role::factory()->create(['is_system' => true, 'permissions' => ['players' => ['view']]]);
        $trimmed = Role::factory()->create(['is_system' => true, 'permissions' => ['purchase_orders' => ['view']]]);
        $other = Role::factory()->create(['is_system' => false, 'permissions' => ['players' => ['view']]]);
        $migration = require database_path('migrations/2026_11_25_200000_grant_purchase_order_permissions.php');

        $migration->up();
        $migration->up();

        $this->assertSame(['view', 'add', 'edit', 'delete'], $system->fresh()->permissions['purchase_orders']);
        $this->assertSame(['view'], $system->fresh()->permissions['players']);
        // A system role someone already trimmed keeps its choice.
        $this->assertSame(['view'], $trimmed->fresh()->permissions['purchase_orders']);
        $this->assertArrayNotHasKey('purchase_orders', $other->fresh()->permissions);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderRightsTest.php`
Expected: FAIL (`purchase_orders` not in `Role::MODULES`, PermissionMap returns null, migration file missing).

- [ ] **Step 3: Add the module to `Role`**

In `app/Models/Role.php`, in `MODULE_GROUPS` replace

```php
        'finance' => ['transactions', 'payment_orders', 'finance', 'reports'],
```

with

```php
        'finance' => ['transactions', 'payment_orders', 'purchase_orders', 'finance', 'reports'],
```

and in `MODULES` replace

```php
        'transactions', 'payment_orders', 'finance', 'reports',
```

with

```php
        'transactions', 'payment_orders', 'purchase_orders', 'finance', 'reports',
```

- [ ] **Step 4: Map the routes in `config/permissions.php`**

In `modules`, after `'payment-orders' => 'payment_orders',` add:

```php
        'purchase-orders' => 'purchase_orders',
```

In `overrides`, after `'transactions.payment-order.store' => ['payment_orders', 'add'],` add:

```php
        // Purchase orders have no approve step (money is controlled at the payment order).
        // Sending, receiving, cancelling and making the payment order are edits; printing reads.
        'purchase-orders.send' => ['purchase_orders', 'edit'],
        'purchase-orders.receive' => ['purchase_orders', 'edit'],
        'purchase-orders.cancel' => ['purchase_orders', 'edit'],
        'purchase-orders.payment-order.store' => ['purchase_orders', 'edit'],
        'purchase-orders.print' => ['purchase_orders', 'view'],
```

- [ ] **Step 5: Grant migration**

Create `database/migrations/2026_11_25_200000_grant_purchase_order_permissions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders. Owner's rule for new rights: they start empty except the
 * system roles (full access). A migration because the desktop build never
 * seeds. Idempotent: a module a role already has is left alone.
 */
return new class extends Migration
{
    private const GRANT = [
        'purchase_orders' => ['view', 'add', 'edit', 'delete'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->where('is_system', true)->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            foreach (self::GRANT as $module => $actions) {
                $permissions[$module] ??= $actions;
            }
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            foreach (array_keys(self::GRANT) as $module) {
                unset($permissions[$module]);
            }
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
```

- [ ] **Step 6: Module label (role editor + menu)**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p2-t1-keys.json`:

```json
{
    "purchase_orders": { "ar": "سندات الطلب", "fr": "Bons de commande", "en": "Purchase orders" }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p2-t1-keys.json`
Expected: `✓ 1 key(s) written to ar/en/fr`

- [ ] **Step 7: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderRightsTest.php`
Expected: PASS (3 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PerListPermissionsTest.php`
Expected: PASS (MODULES and MODULE_GROUPS still list the same modules).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/RoleExtraActionsTest.php`
Expected: PASS.
Run: `npm run i18n:check`
Expected: `✓ all … keys … resolve in ar/fr/en`.

- [ ] **Step 8: Commit**

```bash
git add app/Models/Role.php config/permissions.php database/migrations/2026_11_25_200000_grant_purchase_order_permissions.php tests/Feature/PurchaseOrderRightsTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(roles): purchase orders module in the finance group

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 2: Purchase order tables and models

**Files:**
- Create: `database/migrations/2026_11_25_200001_create_purchase_orders_tables.php`
- Create: `database/migrations/2026_11_25_200002_link_purchase_orders.php`
- Create: `app/Models/PurchaseOrder.php`
- Create: `app/Models/PurchaseOrderLine.php`
- Modify: `app/Models/PaymentOrder.php` (relation after `transaction()`)
- Modify: `app/Models/EquipmentItem.php:21-33` (fillable), `:50-53` (relation)
- Modify: `app/Models/Supplier.php` (relation + `isInUse()`)
- Test: `tests/Feature/PurchaseOrderModelTest.php`
- Modify (test): `tests/Feature/FinanceResetTest.php`

**Interfaces:**
- Consumes: `YearlySequence::next(string $table, int $year): int` (P1).
- Produces:
  - `PurchaseOrder` constants `DRAFT='draft'`, `SENT='sent'`, `RECEIVED='received'`, `CANCELLED='cancelled'`, `STATUSES`; appended attribute `reference` (`NN/YYYY` or `legacy_reference`); methods `isEditable(): bool`, `hasActivePaymentOrder(): bool`, `canEditPrices(): bool`, `isFullyPriced(): bool`, `total(): float`; scope `linkable(?int $supplierId = null, ?int $exceptPaymentOrderId = null)`; `static claimForPaymentOrder(int $id, int $supplierId, ?int $exceptPaymentOrderId = null): bool`; relations `supplier()`, `lines()`, `paymentOrders()`, `equipmentItems()`, `createdBy()`, `sentBy()`, `receivedBy()`, `cancelledBy()`.
  - `PurchaseOrderLine::amountFor(int $quantity, mixed $unitPrice): ?float`; relations `purchaseOrder()`, `catalog()`.
  - `PaymentOrder::purchaseOrder(): BelongsTo`; `EquipmentItem::purchaseOrder(): BelongsTo` + fillable `purchase_order_id`; `Supplier::purchaseOrders(): HasMany`.
  - Columns: see migrations below.

- [ ] **Step 1: Write the failing model test**

Create `tests/Feature/PurchaseOrderModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Services\Documents\YearlySequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchaseOrderModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function supplier(string $name = 'Test Sport Shop'): Supplier
    {
        return Supplier::firstOrCreate(['name' => $name], ['bank_name' => 'BNA', 'rib' => '00100000000000001234']);
    }

    /** @param list<array{designation: string, quantity: int, unit_price: ?float}> $lines */
    private function order(array $attributes = [], array $lines = [['designation' => 'Ballon', 'quantity' => 10, 'unit_price' => 1500.0]]): PurchaseOrder
    {
        $order = PurchaseOrder::create(array_merge([
            'year' => 2026, 'number' => 1, 'status' => PurchaseOrder::DRAFT, 'order_date' => '2026-11-25',
            'supplier_id' => $this->supplier()->id,
        ], $attributes));
        foreach ($lines as $i => $line) {
            $order->lines()->create([
                ...$line,
                'amount' => PurchaseOrderLine::amountFor($line['quantity'], $line['unit_price']),
                'sort_order' => $i + 1,
            ]);
        }

        return $order->fresh('lines');
    }

    private function paymentOrder(PurchaseOrder $purchaseOrder, string $status = PaymentOrder::DRAFT): PaymentOrder
    {
        return PaymentOrder::create([
            'year' => 2026, 'number' => (int) PaymentOrder::max('number') + 1, 'status' => $status,
            'order_date' => '2026-11-25', 'subject' => 'Bon', 'amount' => 100,
            'beneficiary_type' => 'supplier', 'beneficiary_id' => $purchaseOrder->supplier_id,
            'purchase_order_id' => $purchaseOrder->id,
        ]);
    }

    #[Test]
    public function the_reference_is_number_slash_year_or_the_legacy_text(): void
    {
        $this->assertSame('03/2026', $this->order(['number' => 3])->reference);
        $this->assertSame('114/2026', $this->order(['number' => 114])->reference);
        $this->assertSame('01/2021', $this->order([
            'number' => null, 'is_legacy' => true, 'legacy_reference' => '01/2021', 'year' => 2021, 'supplier_id' => null,
        ])->reference);
    }

    #[Test]
    public function the_sequence_is_per_year_ignores_legacy_rows_and_a_number_is_used_once(): void
    {
        $this->order(['number' => 1]);
        $this->order(['number' => 2]);
        $this->order(['number' => null, 'is_legacy' => true, 'legacy_reference' => '07/2026']);

        $this->assertSame(3, DB::transaction(fn () => YearlySequence::next('purchase_orders', 2026)));
        $this->assertSame(1, DB::transaction(fn () => YearlySequence::next('purchase_orders', 2027)));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->order(['number' => 2]);
    }

    #[Test]
    public function line_amounts_and_the_total_follow_the_prices(): void
    {
        $this->assertSame(4501.5, PurchaseOrderLine::amountFor(3, 1500.5));
        $this->assertSame(4501.5, PurchaseOrderLine::amountFor(3, '1500.50'));
        $this->assertNull(PurchaseOrderLine::amountFor(3, null));

        $priced = $this->order([], [
            ['designation' => 'Ballon', 'quantity' => 10, 'unit_price' => 1500.0],
            ['designation' => 'Gonfleur', 'quantity' => 2, 'unit_price' => 750.0],
        ]);
        $this->assertSame('15000.00', $priced->lines[0]->amount);
        $this->assertSame(16500.0, $priced->total());
        $this->assertTrue($priced->isFullyPriced());

        $partly = $this->order(['number' => 2], [
            ['designation' => 'Ballon', 'quantity' => 10, 'unit_price' => 1500.0],
            ['designation' => 'Caméra', 'quantity' => 1, 'unit_price' => null],
        ]);
        $this->assertFalse($partly->isFullyPriced());
        $this->assertSame(15000.0, $partly->total());
    }

    #[Test]
    public function only_a_draft_is_editable_and_prices_stay_open_until_an_active_payment_order(): void
    {
        $this->assertTrue($this->order()->isEditable());

        $sent = $this->order(['number' => 2, 'status' => PurchaseOrder::SENT]);
        $this->assertFalse($sent->isEditable());
        $this->assertTrue($sent->canEditPrices());

        $received = $this->order(['number' => 3, 'status' => PurchaseOrder::RECEIVED]);
        $payment = $this->paymentOrder($received);
        $this->assertTrue($received->hasActivePaymentOrder());
        $this->assertFalse($received->canEditPrices());

        $payment->update(['status' => PaymentOrder::CANCELLED]);
        $this->assertFalse($received->hasActivePaymentOrder());
        $this->assertTrue($received->canEditPrices());

        $this->assertFalse($this->order(['number' => 4, 'status' => PurchaseOrder::CANCELLED])->canEditPrices());
        $legacy = $this->order(['number' => null, 'is_legacy' => true, 'legacy_reference' => '01/2021', 'year' => 2021, 'status' => PurchaseOrder::RECEIVED]);
        $this->assertFalse($legacy->isEditable());
        $this->assertFalse($legacy->canEditPrices());
    }

    #[Test]
    public function only_a_received_order_of_the_supplier_without_an_active_payment_order_is_linkable(): void
    {
        $received = $this->order(['status' => PurchaseOrder::RECEIVED]);
        $other = $this->supplier('Other Shop');

        $this->assertFalse(PurchaseOrder::claimForPaymentOrder($received->id, $other->id));
        $this->assertTrue(PurchaseOrder::claimForPaymentOrder($received->id, $received->supplier_id));

        $active = $this->paymentOrder($received);
        $this->assertFalse(PurchaseOrder::claimForPaymentOrder($received->id, $received->supplier_id));
        // The payment order that already holds it may keep it (editing that draft).
        $this->assertTrue(PurchaseOrder::claimForPaymentOrder($received->id, $received->supplier_id, $active->id));

        $active->update(['status' => PaymentOrder::CANCELLED]);
        $this->assertTrue(PurchaseOrder::claimForPaymentOrder($received->id, $received->supplier_id));

        $sent = $this->order(['number' => 2, 'status' => PurchaseOrder::SENT]);
        $this->assertFalse(PurchaseOrder::claimForPaymentOrder($sent->id, $sent->supplier_id));
        $legacy = $this->order(['number' => null, 'is_legacy' => true, 'legacy_reference' => '01/2021', 'year' => 2021, 'status' => PurchaseOrder::RECEIVED]);
        $this->assertFalse(PurchaseOrder::claimForPaymentOrder($legacy->id, $legacy->supplier_id));

        $this->assertSame([$received->id], PurchaseOrder::query()->linkable()->pluck('id')->all());
        $this->assertSame([], PurchaseOrder::query()->linkable($other->id)->pluck('id')->all());
    }

    #[Test]
    public function the_links_are_real_foreign_keys_that_blank_on_delete(): void
    {
        $paymentKeys = collect(Schema::getForeignKeys('payment_orders'));
        $this->assertTrue($paymentKeys->contains(fn (array $fk) => $fk['columns'] === ['purchase_order_id'] && $fk['foreign_table'] === 'purchase_orders'));
        $equipmentKeys = collect(Schema::getForeignKeys('equipment_items'));
        $this->assertTrue($equipmentKeys->contains(fn (array $fk) => $fk['columns'] === ['purchase_order_id'] && $fk['foreign_table'] === 'purchase_orders'));

        $order = $this->order(['status' => PurchaseOrder::RECEIVED]);
        $payment = $this->paymentOrder($order);
        $catalog = EquipmentCatalog::create(['name' => 'Ballon', 'category' => 'Balls']);
        $item = EquipmentItem::create([
            'catalog_id' => $catalog->id, 'purchase_date' => '2026-11-25', 'quantity' => 10, 'purchase_order_id' => $order->id,
        ]);
        $this->assertSame($order->id, $item->fresh()->purchase_order_id);

        // Orders are never deleted by the app; the keys only guarantee nothing dangles.
        $order->delete();

        $this->assertSame(0, PurchaseOrderLine::count());
        $this->assertNull($payment->fresh()->purchase_order_id);
        $this->assertNull($item->fresh()->purchase_order_id);
    }

    #[Test]
    public function a_supplier_with_a_purchase_order_is_in_use(): void
    {
        $supplier = $this->supplier();
        $this->assertFalse($supplier->isInUse());

        $this->order();

        $this->assertTrue($supplier->fresh()->isInUse());
    }
}
```

- [ ] **Step 2: Add the reset guard test to `FinanceResetTest`**

In `tests/Feature/FinanceResetTest.php` replace the import

```php
use App\Models\PaymentOrder;
```

with

```php
use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
```

and replace

```php
    #[Test]
    public function the_command_changes_nothing_without_the_typed_word(): void
```

with

```php
    #[Test]
    public function purchase_orders_and_their_equipment_links_survive_the_reset(): void
    {
        $supplier = Supplier::create(['name' => 'Test Sport Shop']);
        $purchaseOrder = PurchaseOrder::create([
            'year' => 2026, 'number' => 1, 'status' => 'received', 'order_date' => '2026-03-01', 'supplier_id' => $supplier->id,
        ]);
        $purchaseOrder->lines()->create(['designation' => 'Ballon', 'quantity' => 2, 'unit_price' => 50, 'amount' => 100, 'sort_order' => 1]);
        $tx = Transaction::create([
            'amount' => 100, 'transaction_date' => '2026-03-02', 'transaction_type' => 'expense',
            'category' => 'other', 'fiscal_year' => 2026,
        ]);
        $payment = PaymentOrder::create([
            'year' => 2026, 'number' => 1, 'status' => 'paid', 'order_date' => '2026-03-02', 'subject' => 'Bon', 'amount' => 100,
            'transaction_id' => $tx->id, 'purchase_order_id' => $purchaseOrder->id,
        ]);
        $catalog = EquipmentCatalog::create(['name' => 'Ballon', 'category' => 'Balls']);
        $item = EquipmentItem::create([
            'catalog_id' => $catalog->id, 'purchase_date' => '2026-03-01', 'quantity' => 2,
            'purchase_order_id' => $purchaseOrder->id, 'purchase_transaction_id' => $tx->id,
        ]);

        app(FinanceResetService::class)->reset();

        $this->assertSame('received', $purchaseOrder->fresh()->status);
        $this->assertSame(1, $purchaseOrder->fresh()->lines()->count());
        $this->assertSame($purchaseOrder->id, $payment->fresh()->purchase_order_id);
        $this->assertSame($purchaseOrder->id, $item->fresh()->purchase_order_id);
        $this->assertNull($item->fresh()->purchase_transaction_id);
    }

    #[Test]
    public function the_command_changes_nothing_without_the_typed_word(): void
```

(The reset already leaves purchase orders alone; this test keeps it that way — spec: "Finance reset leaves purchase orders untouched".)

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderModelTest.php`
Expected: FAIL (`Class "App\Models\PurchaseOrder" not found`).

- [ ] **Step 4: Tables migration**

Create `database/migrations/2026_11_25_200001_create_purchase_orders_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders (سند طلب / bon de commande): draft → sent → received, or
 * cancelled. Numbered NN/YYYY per calendar year (own sequence); the one legacy
 * order imported from the old Access app keeps its text reference and no
 * number. Orders are never deleted. Lines are an equipment catalog item or
 * free text; prices are optional until a payment order is made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('number')->nullable();
            $table->string('legacy_reference', 50)->nullable();
            $table->boolean('is_legacy')->default(false);
            $table->string('legacy_source', 20)->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('order_date');
            // Required by the form; null only on the legacy order.
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requested_by', 200)->nullable();
            $table->string('delivery_mode', 200)->nullable();
            $table->string('payment_mode', 20)->nullable();
            $table->text('observation')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['year', 'number']);
            $table->index('status');
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_catalog_id')->nullable()->constrained('equipment_catalogs')->nullOnDelete();
            $table->string('designation', 255);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2)->nullable();
            // quantity × unit_price, computed server-side; null while unpriced.
            $table->decimal('amount', 14, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
```

- [ ] **Step 5: Link migration**

Create `database/migrations/2026_11_25_200002_link_purchase_orders.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders ↔ payment orders and equipment.
 *
 * payment_orders.purchase_order_id exists since P1 without a key. On SQLite a
 * key on an existing column means rebuilding the table; outside a transaction
 * Laravel can switch foreign_keys off for that rebuild, so nothing cascades.
 * equipment_items is never rebuilt on SQLite (its history, branch links and
 * stock-take lines would cascade away): the new column is added with a plain
 * ALTER TABLE … REFERENCES. Every step checks its own work, so a run that
 * failed half way finishes on the next run, and a second run changes nothing.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! $this->hasForeignKey('payment_orders', 'purchase_order_id')) {
            Schema::table('payment_orders', fn (Blueprint $t) => $t->foreign('purchase_order_id')->references('id')->on('purchase_orders')->nullOnDelete());
        }
        if ($this->isSqlite() && ! Schema::hasIndex('payment_orders', 'payment_orders_purchase_order_id_index')) {
            Schema::table('payment_orders', fn (Blueprint $t) => $t->index('purchase_order_id'));
        }

        if (! Schema::hasColumn('equipment_items', 'purchase_order_id')) {
            if ($this->isSqlite()) {
                DB::statement('ALTER TABLE equipment_items ADD COLUMN purchase_order_id INTEGER NULL REFERENCES purchase_orders(id) ON DELETE SET NULL');
            } else {
                Schema::table('equipment_items', fn (Blueprint $t) => $t->foreignId('purchase_order_id')->nullable()->after('purchase_transaction_id')->constrained('purchase_orders')->nullOnDelete());
            }
        }
        if ($this->isSqlite() && ! Schema::hasIndex('equipment_items', 'equipment_items_purchase_order_id_index')) {
            Schema::table('equipment_items', fn (Blueprint $t) => $t->index('purchase_order_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('equipment_items', 'purchase_order_id')) {
            if ($this->isSqlite() && Schema::hasIndex('equipment_items', 'equipment_items_purchase_order_id_index')) {
                Schema::table('equipment_items', fn (Blueprint $t) => $t->dropIndex('equipment_items_purchase_order_id_index'));
            }
            Schema::table('equipment_items', function (Blueprint $table) {
                $table->dropForeign(['purchase_order_id']);
                $table->dropColumn('purchase_order_id');
            });
        }
        if ($this->hasForeignKey('payment_orders', 'purchase_order_id')) {
            Schema::table('payment_orders', fn (Blueprint $t) => $t->dropForeign(['purchase_order_id']));
        }
        if ($this->isSqlite() && Schema::hasIndex('payment_orders', 'payment_orders_purchase_order_id_index')) {
            Schema::table('payment_orders', fn (Blueprint $t) => $t->dropIndex('payment_orders_purchase_order_id_index'));
        }
    }

    private function isSqlite(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(fn (array $fk) => $fk['columns'] === [$column]);
    }
};
```

- [ ] **Step 6: `PurchaseOrderLine` model**

Create `app/Models/PurchaseOrderLine.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ordered item: an equipment catalog item or free text; price optional until a payment order is made. */
class PurchaseOrderLine extends Model
{
    use SerializesClubDates;

    protected $fillable = [
        'purchase_order_id', 'equipment_catalog_id', 'designation', 'quantity', 'unit_price', 'amount', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /** quantity × unit price, or null while the line has no price. */
    public static function amountFor(int $quantity, mixed $unitPrice): ?float
    {
        if ($unitPrice === null || $unitPrice === '') {
            return null;
        }

        return round($quantity * (float) $unitPrice, 2);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(EquipmentCatalog::class, 'equipment_catalog_id');
    }
}
```

- [ ] **Step 7: `PurchaseOrder` model**

Create `app/Models/PurchaseOrder.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A purchase order to a supplier (سند طلب): draft → sent → received, or
 * cancelled. No approve step — money is controlled at the payment order made
 * from it, and at most one active (non-cancelled) payment order per order.
 */
class PurchaseOrder extends Model
{
    use SerializesClubDates;

    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::SENT, self::RECEIVED, self::CANCELLED];

    protected $fillable = [
        'year', 'number', 'legacy_reference', 'is_legacy', 'legacy_source', 'status', 'order_date',
        'supplier_id', 'requested_by', 'delivery_mode', 'payment_mode', 'observation',
        'created_by', 'sent_by', 'sent_at', 'received_by', 'received_at', 'cancelled_by', 'cancelled_at',
    ];

    protected $appends = ['reference'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'number' => 'integer',
            'is_legacy' => 'boolean',
            'order_date' => 'date:Y-m-d',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** NN/YYYY, or the old Access reference for the imported order. */
    protected function reference(): Attribute
    {
        return Attribute::make(get: fn () => $this->is_legacy || $this->number === null
            ? (string) $this->legacy_reference
            : sprintf('%02d/%d', $this->number, $this->year));
    }

    /** Everything is editable only while draft; imported orders never. */
    public function isEditable(): bool
    {
        return $this->status === self::DRAFT && ! $this->is_legacy;
    }

    public function hasActivePaymentOrder(): bool
    {
        return $this->paymentOrders()->where('payment_orders.status', '!=', PaymentOrder::CANCELLED)->exists();
    }

    /** Sent or received: unit prices stay editable until an active payment order is linked. */
    public function canEditPrices(): bool
    {
        return in_array($this->status, [self::SENT, self::RECEIVED], true)
            && ! $this->is_legacy
            && ! $this->hasActivePaymentOrder();
    }

    /** Every line has a price (the rule for the total in words and for making a payment order). */
    public function isFullyPriced(): bool
    {
        return $this->lines->isNotEmpty() && $this->lines->every(fn (PurchaseOrderLine $line) => $line->unit_price !== null);
    }

    /** Sum of the priced lines' amounts. */
    public function total(): float
    {
        return round((float) $this->lines->sum(fn (PurchaseOrderLine $line) => (float) $line->amount), 2);
    }

    /**
     * Received, not imported, with a supplier (this one when given), and no
     * active payment order — other than $exceptPaymentOrderId, the draft that
     * already holds the link while it is edited.
     */
    public function scopeLinkable(Builder $query, ?int $supplierId = null, ?int $exceptPaymentOrderId = null): void
    {
        $query->where('purchase_orders.status', self::RECEIVED)
            ->where('purchase_orders.is_legacy', false)
            ->whereNotNull('purchase_orders.supplier_id')
            ->when($supplierId !== null, fn (Builder $q) => $q->where('purchase_orders.supplier_id', $supplierId))
            ->whereDoesntHave('paymentOrders', fn (Builder $q) => $q
                ->where('payment_orders.status', '!=', PaymentOrder::CANCELLED)
                ->when($exceptPaymentOrderId, fn (Builder $q, int $id) => $q->whereKeyNot($id)));
    }

    /**
     * Atomically reserve this order for a payment order: the conditional
     * update succeeds only while it is still linkable, so two payment orders
     * saved at the same instant cannot both take it.
     */
    public static function claimForPaymentOrder(int $id, int $supplierId, ?int $exceptPaymentOrderId = null): bool
    {
        return self::query()->whereKey($id)->linkable($supplierId, $exceptPaymentOrderId)
            ->update(['updated_at' => now()]) === 1;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function paymentOrders(): HasMany
    {
        return $this->hasMany(PaymentOrder::class);
    }

    public function equipmentItems(): HasMany
    {
        return $this->hasMany(EquipmentItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
```

- [ ] **Step 8: Relations on `PaymentOrder`, `EquipmentItem`, `Supplier`**

In `app/Models/PaymentOrder.php` replace

```php
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
```

with

```php
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
```

In `app/Models/EquipmentItem.php` replace

```php
        'purchase_transaction_id',
        'notes',
```

with

```php
        'purchase_transaction_id',
        'purchase_order_id',
        'notes',
```

and replace

```php
    public function purchaseTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'purchase_transaction_id');
    }
```

with

```php
    public function purchaseTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'purchase_transaction_id');
    }

    /** The purchase order this lot was received from, if any. */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
```

In `app/Models/Supplier.php` replace

```php
    /** A supplier something points at stays (deactivate it instead). */
    public function isInUse(): bool
    {
        return $this->transactions()->exists() || $this->paymentOrders()->exists();
    }
```

with

```php
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** A supplier something points at stays (deactivate it instead). */
    public function isInUse(): bool
    {
        return $this->transactions()->exists() || $this->paymentOrders()->exists() || $this->purchaseOrders()->exists();
    }
```

- [ ] **Step 9: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderModelTest.php`
Expected: PASS (7 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/FinanceResetTest.php`
Expected: PASS.
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/SupplierListTest.php`
Expected: PASS.
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PaymentOrderModelTest.php`
Expected: PASS (the payment_orders rebuild kept its unique index).

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_11_25_200001_create_purchase_orders_tables.php database/migrations/2026_11_25_200002_link_purchase_orders.php app/Models/PurchaseOrder.php app/Models/PurchaseOrderLine.php app/Models/PaymentOrder.php app/Models/EquipmentItem.php app/Models/Supplier.php tests/Feature/PurchaseOrderModelTest.php tests/Feature/FinanceResetTest.php
git commit -F - <<'EOF'
feat(purchase-orders): tables, models and links to payment orders and equipment

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 3: Payment-order side — link a purchase order, tag equipment on payment

**Files:**
- Modify: `app/Services/Finance/PaymentOrderService.php` (imports, `create()`, `update()`, `markPaid()`, new private helper)
- Modify: `app/Http/Requests/PaymentOrder/SavePaymentOrderRequest.php`
- Modify: `app/Http/Controllers/PaymentOrderController.php` (`show()`, `edit()`, `formOptions()`)
- Modify: `lang/ar/validation.php`, `lang/fr/validation.php`, `lang/en/validation.php`
- Modify: `resources/js/i18n/*.json` (via script)
- Test: `tests/Feature/PaymentOrderPurchaseLinkTest.php`

**Interfaces:**
- Consumes: `PurchaseOrder::linkable()`, `PurchaseOrder::claimForPaymentOrder()` (Task 2), `PaymentOrder::purchaseOrder()`.
- Produces:
  - `PaymentOrderService::create(array $data, User $by)` / `update(...)` accept `purchase_order_id`; refuse with `PaymentOrderException('flash.payment_order_purchase_order_unavailable')` when the order is not linkable. Task 4 relies on `create()` doing this claim.
  - `markPaid()` sets `equipment_items.purchase_transaction_id` = the new expense on the purchase order's items where still null.
  - Inertia prop `purchaseOrders` on `PaymentOrders/Create` and `PaymentOrders/Edit`: `list<{id:int, reference:string, supplier_id:int, total:float}>`; `order.purchase_order` `{id, reference}` on `PaymentOrders/Show`.
  - Validation message key `validation.purchase_order_unavailable`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PaymentOrderPurchaseLinkTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Exceptions\PaymentOrderException;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\PaymentOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentOrderPurchaseLinkTest extends TestCase
{
    use RefreshDatabase;

    private PaymentOrderService $service;

    private User $admin;

    private Supplier $supplier;

    private FinanceCategory $category;

    private FinanceAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->service = app(PaymentOrderService::class);
        $this->admin = User::factory()->admin()->create();
        $this->supplier = Supplier::create(['name' => 'Test Sport Shop', 'bank_name' => 'BNA', 'rib' => '00100000000000001234']);
        $this->category = FinanceCategory::where('type', 'expense')->first()
            ?? FinanceCategory::updateOrCreate(['type' => 'expense', 'name' => 'Equipment'], ['is_active' => true]);
        $this->account = FinanceAccount::selectable()->first()
            ?? FinanceAccount::create([
                'name' => 'Caisse', 'type' => 'cash', 'opening_balance' => 0, 'current_balance' => 0,
                'currency' => 'DZD', 'is_active' => true,
            ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function purchaseOrder(string $status = PurchaseOrder::RECEIVED, ?Supplier $supplier = null, int $number = 1): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'year' => 2026, 'number' => $number, 'status' => $status, 'order_date' => '2026-11-20',
            'supplier_id' => ($supplier ?? $this->supplier)->id,
        ]);
        $order->lines()->create(['designation' => 'Ballon', 'quantity' => 10, 'unit_price' => 1500, 'amount' => 15000, 'sort_order' => 1]);

        return $order;
    }

    private function data(array $extra = []): array
    {
        return array_merge([
            'order_date' => '2026-11-25', 'subject' => 'Bon de commande 01/2026', 'amount' => 15000,
            'finance_category_id' => $this->category->id, 'finance_account_id' => $this->account->id,
            'payment_method' => 'bank', 'beneficiary_type' => 'supplier', 'beneficiary_id' => $this->supplier->id,
        ], $extra);
    }

    private function assertUnavailable(callable $call): void
    {
        try {
            $call();
            $this->fail('The purchase order should be refused.');
        } catch (PaymentOrderException $e) {
            $this->assertSame('flash.payment_order_purchase_order_unavailable', $e->flashKey);
        }
    }

    #[Test]
    public function a_payment_order_links_a_received_purchase_order_of_its_supplier(): void
    {
        $purchaseOrder = $this->purchaseOrder();

        $order = $this->service->create($this->data(['purchase_order_id' => $purchaseOrder->id]), $this->admin);

        $this->assertSame($purchaseOrder->id, $order->purchase_order_id);
        $this->assertTrue($purchaseOrder->fresh()->hasActivePaymentOrder());
    }

    #[Test]
    public function a_second_active_payment_order_is_refused_until_the_first_is_cancelled(): void
    {
        $purchaseOrder = $this->purchaseOrder();
        $first = $this->service->create($this->data(['purchase_order_id' => $purchaseOrder->id]), $this->admin);

        $this->assertUnavailable(fn () => $this->service->create($this->data(['purchase_order_id' => $purchaseOrder->id]), $this->admin));
        $this->assertSame(1, PaymentOrder::count());

        $this->service->cancel($first, $this->admin, 'Erreur de montant');
        $second = $this->service->create($this->data(['purchase_order_id' => $purchaseOrder->id]), $this->admin);
        $this->assertSame($purchaseOrder->id, $second->purchase_order_id);
    }

    #[Test]
    public function another_supplier_or_an_order_not_received_is_refused(): void
    {
        $other = Supplier::create(['name' => 'Other Shop']);
        $theirs = $this->purchaseOrder(PurchaseOrder::RECEIVED, $other, 1);
        $sent = $this->purchaseOrder(PurchaseOrder::SENT, null, 2);

        $this->assertUnavailable(fn () => $this->service->create($this->data(['purchase_order_id' => $theirs->id]), $this->admin));
        $this->assertUnavailable(fn () => $this->service->create($this->data(['purchase_order_id' => $sent->id]), $this->admin));
        $this->assertUnavailable(fn () => $this->service->create($this->data([
            'beneficiary_type' => 'user', 'beneficiary_id' => $this->admin->id, 'purchase_order_id' => $this->purchaseOrder(PurchaseOrder::RECEIVED, null, 3)->id,
        ]), $this->admin));
        $this->assertSame(0, PaymentOrder::count());
    }

    #[Test]
    public function editing_the_draft_keeps_its_own_link(): void
    {
        $purchaseOrder = $this->purchaseOrder();
        $order = $this->service->create($this->data(['purchase_order_id' => $purchaseOrder->id]), $this->admin);

        $updated = $this->service->update($order, $this->data(['subject' => 'Ballons U15', 'purchase_order_id' => $purchaseOrder->id]), $this->admin);

        $this->assertSame('Ballons U15', $updated->subject);
        $this->assertSame($purchaseOrder->id, $updated->purchase_order_id);
    }

    #[Test]
    public function paying_points_the_received_equipment_to_the_expense(): void
    {
        $purchaseOrder = $this->purchaseOrder();
        $catalog = EquipmentCatalog::create(['name' => 'Ballon', 'category' => 'Balls']);
        $earlier = Transaction::create([
            'title' => 'Achat antérieur', 'amount' => 10, 'transaction_date' => '2026-11-01', 'transaction_type' => 'expense',
            'category' => 'equipment', 'status' => 'Paid',
        ]);
        $fresh = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-11-22', 'quantity' => 10, 'purchase_order_id' => $purchaseOrder->id]);
        $alreadyLinked = EquipmentItem::create([
            'catalog_id' => $catalog->id, 'purchase_date' => '2026-11-22', 'quantity' => 1,
            'purchase_order_id' => $purchaseOrder->id, 'purchase_transaction_id' => $earlier->id,
        ]);
        $unrelated = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-11-22', 'quantity' => 5]);

        $order = $this->service->approve($this->service->create($this->data(['purchase_order_id' => $purchaseOrder->id]), $this->admin), $this->admin);
        $paid = $this->service->markPaid($order, $this->admin, '2026-11-26');

        $this->assertSame($paid->transaction_id, $fresh->fresh()->purchase_transaction_id);
        $this->assertSame($earlier->id, $alreadyLinked->fresh()->purchase_transaction_id);
        $this->assertNull($unrelated->fresh()->purchase_transaction_id);
    }

    #[Test]
    public function the_form_offers_only_linkable_purchase_orders(): void
    {
        $free = $this->purchaseOrder(PurchaseOrder::RECEIVED, null, 1);
        $taken = $this->purchaseOrder(PurchaseOrder::RECEIVED, null, 2);
        $this->purchaseOrder(PurchaseOrder::SENT, null, 3);
        $holder = $this->service->create($this->data(['purchase_order_id' => $taken->id]), $this->admin);

        $this->actingAs($this->admin)->get(route('payment-orders.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('purchaseOrders', 1)
                ->where('purchaseOrders.0.id', $free->id)
                ->where('purchaseOrders.0.reference', '01/2026')
                ->where('purchaseOrders.0.supplier_id', $this->supplier->id)
                ->where('purchaseOrders.0.total', fn ($total) => (float) $total === 15000.0));

        // Its own draft still lists the order it holds.
        $this->get(route('payment-orders.edit', $holder))
            ->assertInertia(fn (Assert $page) => $page->has('purchaseOrders', 2));
    }

    #[Test]
    public function the_form_refuses_a_purchase_order_of_another_supplier(): void
    {
        $theirs = $this->purchaseOrder(PurchaseOrder::RECEIVED, Supplier::create(['name' => 'Other Shop']));

        $this->actingAs($this->admin)->post(route('payment-orders.store'), $this->data(['purchase_order_id' => $theirs->id]))
            ->assertSessionHasErrors('purchase_order_id');
        $this->assertSame(0, PaymentOrder::count());
    }

    #[Test]
    public function the_payment_order_page_links_back_to_its_purchase_order(): void
    {
        $purchaseOrder = $this->purchaseOrder();
        $order = $this->service->create($this->data(['purchase_order_id' => $purchaseOrder->id]), $this->admin);

        $this->actingAs($this->admin)->get(route('payment-orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.purchase_order.id', $purchaseOrder->id)
                ->where('order.purchase_order.reference', '01/2026'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PaymentOrderPurchaseLinkTest.php`
Expected: FAIL (no refusal thrown, `purchaseOrders` prop missing, `order.purchase_order` missing).

- [ ] **Step 3: Service — claim on create / update, tag equipment on payment**

In `app/Services/Finance/PaymentOrderService.php`:

Replace the imports

```php
use App\Models\FinanceCategory;
use App\Models\PaymentOrder;
use App\Models\Supplier;
```

with

```php
use App\Models\EquipmentItem;
use App\Models\FinanceCategory;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
```

In `create()` replace

```php
            $year = Carbon::parse($data['order_date'])->year;
            $order = PaymentOrder::create([
```

with

```php
            $year = Carbon::parse($data['order_date'])->year;
            self::claimPurchaseOrder($data, null);
            $order = PaymentOrder::create([
```

In `update()` replace

```php
        return DB::transaction(function () use ($order, $data, $by) {
            $order->update(Arr::except($data, self::STATE_COLUMNS));
```

with

```php
        return DB::transaction(function () use ($order, $data, $by) {
            self::claimPurchaseOrder($data, $order->id);
            $order->update(Arr::except($data, self::STATE_COLUMNS));
```

In `markPaid()` replace

```php
            PaymentOrder::whereKey($order->id)->update(['transaction_id' => $transaction->id]);
```

with

```php
            PaymentOrder::whereKey($order->id)->update(['transaction_id' => $transaction->id]);
            // Money is recorded once, here: the equipment received from the linked
            // purchase order now points to this expense (lots already linked stay).
            if ($order->purchase_order_id !== null) {
                EquipmentItem::where('purchase_order_id', $order->purchase_order_id)
                    ->whereNull('purchase_transaction_id')
                    ->update(['purchase_transaction_id' => $transaction->id]);
            }
```

Add this private method just before `/** An order is approved and paid only with an expense category and a register. */`:

```php
    /**
     * A payment order may name a received purchase order of its own supplier
     * that has no other active payment order. The claim is atomic, so two
     * orders saved at the same instant cannot both take it.
     *
     * @param  array<string, mixed>  $data
     */
    private static function claimPurchaseOrder(array $data, ?int $exceptPaymentOrderId): void
    {
        $purchaseOrderId = $data['purchase_order_id'] ?? null;
        if ($purchaseOrderId === null || $purchaseOrderId === '') {
            return;
        }
        $supplierId = ($data['beneficiary_type'] ?? null) === 'supplier' ? (int) ($data['beneficiary_id'] ?? 0) : 0;

        if (! PurchaseOrder::claimForPaymentOrder((int) $purchaseOrderId, $supplierId, $exceptPaymentOrderId)) {
            throw new PaymentOrderException('flash.payment_order_purchase_order_unavailable');
        }
    }
```

- [ ] **Step 4: Request rule**

In `app/Http/Requests/PaymentOrder/SavePaymentOrderRequest.php` replace

```php
use App\Models\PaymentOrder;
use Illuminate\Foundation\Http\FormRequest;
```

with

```php
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
```

and replace

```php
            'observation' => ['nullable', 'string', 'max:2000'],
        ];
```

with

```php
            'observation' => ['nullable', 'string', 'max:2000'],
            // A received purchase order of the same supplier with no other active payment order.
            'purchase_order_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($order) {
                $supplierId = $this->input('beneficiary_type') === 'supplier' ? (int) $this->input('beneficiary_id') : 0;
                $linkable = PurchaseOrder::query()->whereKey((int) $value)
                    ->linkable($supplierId, $order instanceof PaymentOrder ? $order->id : null)
                    ->exists();
                if (! $linkable) {
                    $fail(__('validation.purchase_order_unavailable'));
                }
            }],
        ];
```

- [ ] **Step 5: Controller — form options and the back link**

In `app/Http/Controllers/PaymentOrderController.php`:

Replace

```php
use App\Models\PaymentOrder;
use App\Models\Supplier;
```

with

```php
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
```

In `show()` replace

```php
            'financeCategory:id,name,name_ar,name_fr,name_en', 'financeAccount:id,name', 'boardMeeting:id,title,meeting_date,pv_number', 'transaction:id,amount,transaction_date,archived',
```

with

```php
            'financeCategory:id,name,name_ar,name_fr,name_en', 'financeAccount:id,name', 'boardMeeting:id,title,meeting_date,pv_number', 'transaction:id,amount,transaction_date,archived',
            'purchaseOrder:id,year,number,is_legacy,legacy_reference',
```

In `edit()` replace

```php
        return Inertia::render('PaymentOrders/Edit', ['order' => $paymentOrder, ...$this->formOptions()]);
```

with

```php
        return Inertia::render('PaymentOrders/Edit', ['order' => $paymentOrder, ...$this->formOptions($paymentOrder)]);
```

Replace the signature and opening of `formOptions()`

```php
    /** @return array<string, mixed> */
    private function formOptions(): array
    {
```

with

```php
    /** @return array<string, mixed> */
    private function formOptions(?PaymentOrder $current = null): array
    {
```

and inside its returned array replace

```php
            'boardMeetings' => BoardMeeting::query()->whereNull('cancelled_at')->orderByDesc('meeting_date')->limit(200)
                ->get(['id', 'title', 'meeting_date', 'pv_number']),
        ];
```

with

```php
            'boardMeetings' => BoardMeeting::query()->whereNull('cancelled_at')->orderByDesc('meeting_date')->limit(200)
                ->get(['id', 'title', 'meeting_date', 'pv_number']),
            // Received purchase orders still free for a payment order (plus the one this draft holds).
            'purchaseOrders' => PurchaseOrder::query()->linkable(null, $current?->id)
                ->with('lines:id,purchase_order_id,amount,unit_price')
                ->orderByDesc('year')->orderByDesc('number')->limit(200)
                ->get(['id', 'year', 'number', 'is_legacy', 'legacy_reference', 'supplier_id'])
                ->map(fn (PurchaseOrder $po) => [
                    'id' => $po->id, 'reference' => $po->reference, 'supplier_id' => $po->supplier_id, 'total' => $po->total(),
                ])->values(),
        ];
```

- [ ] **Step 6: Messages**

In `lang/en/validation.php` replace

```php
    'equipment_source_required' => 'Choose where the stock came from.',
```

with

```php
    'equipment_source_required' => 'Choose where the stock came from.',
    'purchase_order_unavailable' => 'This purchase order is not available: it must be received, from the same supplier, with no other payment order.',
```

In `lang/fr/validation.php` replace

```php
    'equipment_source_required' => 'Choisissez la provenance.',
```

with

```php
    'equipment_source_required' => 'Choisissez la provenance.',
    'purchase_order_unavailable' => "Ce bon de commande n'est pas disponible : il doit être reçu, du même fournisseur, sans autre ordre de paiement.",
```

In `lang/ar/validation.php` replace

```php
    'equipment_source_required' => 'اختر مصدر الاستلام.',
```

with

```php
    'equipment_source_required' => 'اختر مصدر الاستلام.',
    'purchase_order_unavailable' => 'سند الطلب هذا غير متاح: يجب أن يكون مستلَمًا، من نفس المورد، وبلا أمر دفع آخر.',
```

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p2-t3-keys.json`:

```json
{
    "flash.payment_order_purchase_order_unavailable": {
        "ar": "سند الطلب هذا غير متاح: يجب أن يكون مستلَمًا، من نفس المورد، وبلا أمر دفع آخر.",
        "fr": "Ce bon de commande n'est pas disponible : il doit être reçu, du même fournisseur, sans autre ordre de paiement.",
        "en": "This purchase order is not available: it must be received, from the same supplier, with no other payment order."
    }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p2-t3-keys.json`
Expected: `✓ 1 key(s) written to ar/en/fr`

- [ ] **Step 7: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PaymentOrderPurchaseLinkTest.php`
Expected: PASS (8 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PaymentOrderServiceTest.php`
Expected: PASS.
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PaymentOrderHttpTest.php`
Expected: PASS.
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PaymentOrderPagesTest.php`
Expected: PASS.
Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Finance/PaymentOrderService.php app/Http/Requests/PaymentOrder/SavePaymentOrderRequest.php app/Http/Controllers/PaymentOrderController.php lang/ar/validation.php lang/fr/validation.php lang/en/validation.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/PaymentOrderPurchaseLinkTest.php
git commit -F - <<'EOF'
feat(payment-orders): link a received purchase order; paying tags its equipment

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 4: `PurchaseOrderService` (create, update, send, cancel, receive, create payment order)

**Files:**
- Create: `app/Exceptions/PurchaseOrderException.php`
- Create: `app/Services/Finance/PurchaseOrderService.php`
- Modify: `app/Services/Equipment/EquipmentStockService.php` (`receiveRow()`, `splitLot()`, `writeOffMissing()`)
- Modify: `app/Services/Activity/ActivityAction.php` (six constants, `ALL`, `AREAS['money']`)
- Modify: `app/Services/Activity/ActivitySubjectLink.php` (`MODULES`, `describe()`)
- Modify: `tests/Unit/ActivityRecorderTest.php:61` (125 → 131)
- Modify: `resources/js/i18n/*.json` (via script)
- Test: `tests/Feature/PurchaseOrderServiceTest.php`

**Interfaces:**
- Consumes: `PurchaseOrder`, `PurchaseOrderLine::amountFor()` (Task 2); `PaymentOrderService::create(array $data, User $by): PaymentOrder` with the `purchase_order_id` claim (Task 3); `EquipmentStockService::receive(array $data, ?int $userId, ?User $actor): EquipmentItem`; `ReceiveStockRequest::MAX_NUMBERED_UNITS` (200).
- Produces:
  - `PurchaseOrderException(string $flashKey)` with public readonly `$flashKey`; renders `back()->withInput()->with('error', $flashKey)`.
  - `PurchaseOrderService::create(array $data, User $by): PurchaseOrder` — `$data` keys `order_date`, `supplier_id`, `requested_by`, `delivery_mode`, `payment_mode`, `observation`, `lines` (list of `{equipment_catalog_id?, designation, quantity, unit_price?}`).
  - `update(PurchaseOrder $order, array $data, User $by): PurchaseOrder` — draft: same shape as create; sent / received: `lines` list of `{id, unit_price}` only.
  - `send(PurchaseOrder $order, User $by): PurchaseOrder`, `cancel(PurchaseOrder $order, User $by): PurchaseOrder`.
  - `receive(PurchaseOrder $order, User $by, string $receivedDate, bool $toStock = false, bool $makePaymentOrder = false): PurchaseOrder`.
  - `createPaymentOrder(PurchaseOrder $order, User $by): PaymentOrder`.
  - Flash keys `flash.purchase_order_locked`, `_year_locked`, `_prices_locked`, `_not_draft`, `_not_sent`, `_not_received`, `_cannot_cancel`, `_unpriced`, `_has_payment_order`, `_too_many_units`.
  - `ActivityAction::PURCHASE_ORDER_CREATED|UPDATED|SENT|RECEIVED|CANCELLED|PRINTED` (codes `purchase_order_created` …).
  - i18n key `pur.title` (also used by Tasks 6–7).
  - `EquipmentStockService::receive()` stores `$data['purchase_order_id']` on every created row; split / written-off rows keep their lot's `purchase_order_id`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PurchaseOrderServiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Exceptions\PurchaseOrderException;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Activity\ActivityAction;
use App\Services\Equipment\EquipmentStockService;
use App\Services\Finance\PaymentOrderService;
use App\Services\Finance\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchaseOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrderService $service;

    private User $user;

    private Supplier $supplier;

    private EquipmentCatalog $balls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-25 10:00:00');
        app()->setLocale('en');
        $this->service = app(PurchaseOrderService::class);
        $this->user = User::factory()->admin()->create();
        $this->supplier = Supplier::create(['name' => 'Test Sport Shop', 'bank_name' => 'BNA', 'rib' => '00100000000000001234']);
        $this->balls = EquipmentCatalog::create(['name' => 'Ballon taille 5', 'category' => 'Balls']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function data(array $extra = [], ?array $lines = null): array
    {
        return array_merge([
            'order_date' => '2026-11-25', 'supplier_id' => $this->supplier->id, 'requested_by' => 'Section football',
            'delivery_mode' => 'Livraison au club', 'payment_mode' => 'bank', 'observation' => null,
            'lines' => $lines ?? [
                ['equipment_catalog_id' => $this->balls->id, 'designation' => 'Ballon taille 5', 'quantity' => 10, 'unit_price' => 1500],
                ['equipment_catalog_id' => null, 'designation' => 'Gonfleur', 'quantity' => 2, 'unit_price' => 750],
            ],
        ], $extra);
    }

    private function sent(array $extra = [], ?array $lines = null): PurchaseOrder
    {
        return $this->service->send($this->service->create($this->data($extra, $lines), $this->user), $this->user);
    }

    private function assertRefused(string $key, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected {$key}.");
        } catch (PurchaseOrderException $e) {
            $this->assertSame($key, $e->flashKey);
        }
    }

    #[Test]
    public function create_numbers_a_draft_with_server_computed_line_amounts(): void
    {
        $first = $this->service->create($this->data(), $this->user);
        $second = $this->service->create($this->data(), $this->user);

        $this->assertSame(PurchaseOrder::DRAFT, $first->status);
        $this->assertSame('01/2026', $first->reference);
        $this->assertSame('02/2026', $second->reference);
        $this->assertSame($this->user->id, $first->created_by);
        $this->assertCount(2, $first->lines);
        $this->assertSame('15000.00', $first->lines[0]->amount);
        $this->assertSame(2, $first->lines[1]->sort_order);
        $this->assertSame(16500.0, $first->total());
        $this->assertDatabaseHas('activity_logs', ['action' => ActivityAction::PURCHASE_ORDER_CREATED, 'subject_id' => $first->id]);
    }

    #[Test]
    public function the_year_comes_from_the_order_date(): void
    {
        $this->assertSame('01/2025', $this->service->create($this->data(['order_date' => '2025-12-30']), $this->user)->reference);
    }

    #[Test]
    public function a_draft_is_fully_editable_but_stays_in_its_year(): void
    {
        $order = $this->service->create($this->data(), $this->user);

        $updated = $this->service->update($order, $this->data(['requested_by' => 'Section natation'], [
            ['equipment_catalog_id' => null, 'designation' => 'Lunettes', 'quantity' => 6, 'unit_price' => null],
        ]), $this->user);

        $this->assertSame('Section natation', $updated->requested_by);
        $this->assertCount(1, $updated->lines);
        $this->assertSame('Lunettes', $updated->lines[0]->designation);
        $this->assertNull($updated->lines[0]->amount);
        $this->assertDatabaseHas('activity_logs', ['action' => ActivityAction::PURCHASE_ORDER_UPDATED, 'subject_id' => $order->id]);

        $this->assertRefused('flash.purchase_order_year_locked', fn () => $this->service->update($updated, $this->data(['order_date' => '2027-01-02']), $this->user));
    }

    #[Test]
    public function sending_locks_everything_but_the_unit_prices(): void
    {
        $order = $this->sent();
        $this->assertSame(PurchaseOrder::SENT, $order->status);
        $this->assertSame($this->user->id, $order->sent_by);
        $this->assertNotNull($order->sent_at);
        $other = Supplier::create(['name' => 'Other Shop']);
        [$balls, $pump] = [$order->lines[0], $order->lines[1]];

        $this->service->update($order, [
            'supplier_id' => $other->id,
            'lines' => [
                ['id' => $balls->id, 'designation' => 'Changed', 'quantity' => 99, 'unit_price' => 1400],
                ['id' => $pump->id, 'unit_price' => null],
            ],
        ], $this->user);

        $order->refresh()->load('lines');
        $this->assertSame($this->supplier->id, $order->supplier_id);
        $this->assertSame('Ballon taille 5', $order->lines[0]->designation);
        $this->assertSame(10, $order->lines[0]->quantity);
        $this->assertSame('1400.00', $order->lines[0]->unit_price);
        $this->assertSame('14000.00', $order->lines[0]->amount);
        $this->assertNull($order->lines[1]->unit_price);
        $this->assertNull($order->lines[1]->amount);
        $this->assertFalse($order->isFullyPriced());
    }

    #[Test]
    public function prices_lock_once_an_active_payment_order_is_linked(): void
    {
        $order = $this->service->receive($this->sent(), $this->user, '2026-11-27');
        $payment = $this->service->createPaymentOrder($order, $this->user);
        $prices = ['lines' => [['id' => $order->lines[0]->id, 'unit_price' => 1400], ['id' => $order->lines[1]->id, 'unit_price' => 750]]];

        $this->assertRefused('flash.purchase_order_prices_locked', fn () => $this->service->update($order->fresh(), $prices, $this->user));

        app(PaymentOrderService::class)->cancel($payment, $this->user, 'Montant à revoir');
        $this->service->update($order->fresh(), $prices, $this->user);
        $this->assertSame('1400.00', $order->fresh()->lines[0]->unit_price);
    }

    #[Test]
    public function only_a_draft_with_lines_is_sent(): void
    {
        $order = $this->sent();

        $this->assertRefused('flash.purchase_order_not_draft', fn () => $this->service->send($order, $this->user));
    }

    #[Test]
    public function draft_and_sent_orders_cancel_and_keep_their_number_but_received_ones_do_not(): void
    {
        $draft = $this->service->cancel($this->service->create($this->data(), $this->user), $this->user);
        $this->assertSame(PurchaseOrder::CANCELLED, $draft->status);
        $this->assertSame('01/2026', $draft->reference);
        $this->assertSame($this->user->id, $draft->cancelled_by);

        $sent = $this->service->cancel($this->sent(), $this->user);
        $this->assertSame(PurchaseOrder::CANCELLED, $sent->status);
        $this->assertDatabaseHas('activity_logs', ['action' => ActivityAction::PURCHASE_ORDER_CANCELLED, 'subject_id' => $sent->id]);

        $received = $this->service->receive($this->sent(), $this->user, '2026-11-27');
        $this->assertRefused('flash.purchase_order_cannot_cancel', fn () => $this->service->cancel($received, $this->user));
    }

    #[Test]
    public function receiving_without_ticks_only_changes_the_status(): void
    {
        $order = $this->service->receive($this->sent(), $this->user, '2026-11-27');

        $this->assertSame(PurchaseOrder::RECEIVED, $order->status);
        $this->assertSame('2026-11-27', $order->received_at->format('Y-m-d'));
        $this->assertSame($this->user->id, $order->received_by);
        $this->assertSame(0, EquipmentItem::count());
        $this->assertSame(0, PaymentOrder::count());
        $this->assertDatabaseHas('activity_logs', ['action' => ActivityAction::PURCHASE_ORDER_RECEIVED, 'subject_id' => $order->id]);
    }

    #[Test]
    public function only_a_sent_order_is_received(): void
    {
        $draft = $this->service->create($this->data(), $this->user);

        $this->assertRefused('flash.purchase_order_not_sent', fn () => $this->service->receive($draft, $this->user, '2026-11-27'));
    }

    #[Test]
    public function receiving_into_stock_makes_one_lot_per_catalog_line_without_an_expense(): void
    {
        $order = $this->service->receive($this->sent(), $this->user, '2026-11-27', toStock: true);

        $items = EquipmentItem::all();
        $this->assertCount(1, $items, 'the free-text line never goes to stock');
        $lot = $items->first();
        $this->assertSame($this->balls->id, $lot->catalog_id);
        $this->assertSame(10, $lot->quantity);
        $this->assertEquals(1500, $lot->unit_price);
        $this->assertSame('Ballon taille 5', $lot->designation);
        $this->assertSame('New', $lot->condition);
        $this->assertSame('purchase', $lot->received_via);
        $this->assertSame('2026-11-27', $lot->purchase_date->format('Y-m-d'));
        $this->assertSame($order->id, $lot->purchase_order_id);
        $this->assertNull($lot->purchase_transaction_id);
        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function a_serial_catalog_line_becomes_numbered_units_all_tagged_with_the_order(): void
    {
        $config = WebsiteConfig::singleton();
        $config->club_short_name = 'IRNB';
        $config->save();
        EquipmentCategory::updateOrCreate(['name' => 'Balls'], ['code' => 'BALL']);
        $vests = EquipmentCatalog::create(['name' => 'GPS Vest', 'category' => 'Balls', 'requires_serial' => true]);

        $order = $this->service->receive($this->sent([], [
            ['equipment_catalog_id' => $vests->id, 'designation' => 'GPS Vest', 'quantity' => 3, 'unit_price' => null],
        ]), $this->user, '2026-11-27', toStock: true);

        $items = EquipmentItem::all();
        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertSame($order->id, $item->purchase_order_id);
            $this->assertSame(1, $item->quantity);
            $this->assertNull($item->unit_price);
        }
    }

    #[Test]
    public function too_many_numbered_units_are_refused_before_anything_changes(): void
    {
        $vests = EquipmentCatalog::create(['name' => 'GPS Vest', 'category' => 'Balls', 'requires_serial' => true]);
        $order = $this->sent([], [['equipment_catalog_id' => $vests->id, 'designation' => 'GPS Vest', 'quantity' => 201, 'unit_price' => 10]]);

        $this->assertRefused('flash.purchase_order_too_many_units', fn () => $this->service->receive($order, $this->user, '2026-11-27', toStock: true));
        $this->assertSame(PurchaseOrder::SENT, $order->fresh()->status);
        $this->assertSame(0, EquipmentItem::count());
    }

    #[Test]
    public function receiving_with_a_payment_order_prefills_a_draft_for_the_supplier(): void
    {
        $order = $this->service->receive($this->sent(), $this->user, '2026-11-27', makePaymentOrder: true);

        $payment = PaymentOrder::firstOrFail();
        $this->assertSame(PaymentOrder::DRAFT, $payment->status);
        $this->assertSame('supplier', $payment->beneficiary_type);
        $this->assertSame($this->supplier->id, $payment->beneficiary_id);
        $this->assertSame('BNA', $payment->beneficiary_bank);
        $this->assertSame('00100000000000001234', $payment->beneficiary_rib);
        $this->assertSame('16500.00', $payment->amount);
        $this->assertSame('Purchase order 01/2026', $payment->subject);
        $this->assertSame('bank', $payment->payment_method);
        $this->assertSame('2026-11-27', $payment->order_date->format('Y-m-d'));
        $this->assertSame($order->id, $payment->purchase_order_id);
        $this->assertSame(PurchaseOrder::RECEIVED, $order->status);
    }

    #[Test]
    public function a_payment_order_needs_every_price_and_nothing_changes_otherwise(): void
    {
        $order = $this->sent([], [['equipment_catalog_id' => null, 'designation' => 'Caméra', 'quantity' => 1, 'unit_price' => null]]);

        $this->assertRefused('flash.purchase_order_unpriced', fn () => $this->service->receive($order, $this->user, '2026-11-27', makePaymentOrder: true));
        $this->assertSame(PurchaseOrder::SENT, $order->fresh()->status);
        $this->assertSame(0, PaymentOrder::count());

        $received = $this->service->receive($order->fresh(), $this->user, '2026-11-27');
        $this->assertRefused('flash.purchase_order_unpriced', fn () => $this->service->createPaymentOrder($received, $this->user));
    }

    #[Test]
    public function a_received_order_has_at_most_one_active_payment_order(): void
    {
        $order = $this->service->receive($this->sent(), $this->user, '2026-11-27');
        $first = $this->service->createPaymentOrder($order, $this->user);
        $this->assertSame('2026-11-25', $first->order_date->format('Y-m-d'));

        $this->assertRefused('flash.purchase_order_has_payment_order', fn () => $this->service->createPaymentOrder($order->fresh(), $this->user));

        app(PaymentOrderService::class)->cancel($first, $this->user, 'Fournisseur a changé son RIB');
        $this->service->createPaymentOrder($order->fresh(), $this->user);
        $this->assertSame(2, PaymentOrder::count());
        $this->assertSame(1, PaymentOrder::where('status', '!=', PaymentOrder::CANCELLED)->count());
    }

    #[Test]
    public function a_payment_order_waits_for_reception(): void
    {
        $draft = $this->service->create($this->data(), $this->user);

        $this->assertRefused('flash.purchase_order_not_received', fn () => $this->service->createPaymentOrder($draft, $this->user));
        $this->assertRefused('flash.purchase_order_not_received', fn () => $this->service->createPaymentOrder($this->sent(), $this->user));
    }

    #[Test]
    public function a_stale_model_cannot_receive_twice(): void
    {
        $order = $this->sent();
        $a = $order->fresh();
        $b = $order->fresh();

        $this->service->receive($a, $this->user, '2026-11-27', toStock: true);

        $this->assertRefused('flash.purchase_order_not_sent', fn () => $this->service->receive($b, $this->user, '2026-11-27', toStock: true));
        $this->assertSame(1, EquipmentItem::count());
    }

    #[Test]
    public function a_legacy_order_refuses_every_change(): void
    {
        $legacy = PurchaseOrder::create([
            'year' => 2021, 'number' => null, 'legacy_reference' => '01/2021', 'is_legacy' => true, 'legacy_source' => 'purchase',
            'status' => PurchaseOrder::RECEIVED, 'order_date' => '2021-03-14',
        ]);
        $legacy->lines()->create(['designation' => 'Caméra', 'quantity' => 1, 'sort_order' => 1]);

        $this->assertRefused('flash.purchase_order_locked', fn () => $this->service->update($legacy, ['lines' => []], $this->user));
        $this->assertRefused('flash.purchase_order_not_draft', fn () => $this->service->send($legacy, $this->user));
        $this->assertRefused('flash.purchase_order_cannot_cancel', fn () => $this->service->cancel($legacy, $this->user));
        $this->assertRefused('flash.purchase_order_not_received', fn () => $this->service->createPaymentOrder($legacy, $this->user));
    }

    #[Test]
    public function a_split_lot_keeps_its_purchase_order(): void
    {
        $order = $this->service->receive($this->sent(), $this->user, '2026-11-27', toStock: true);
        $lot = EquipmentItem::firstOrFail();

        $part = app(EquipmentStockService::class)->splitLot($lot, 3, 'Damaged', $this->user->id);

        $this->assertSame($order->id, $part->purchase_order_id);
        $this->assertSame(10, (int) EquipmentItem::where('purchase_order_id', $order->id)->sum('quantity'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderServiceTest.php`
Expected: FAIL (`Class "App\Services\Finance\PurchaseOrderService" not found`).

- [ ] **Step 3: Exception**

Create `app/Exceptions/PurchaseOrderException.php`:

```php
<?php

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * A purchase order step the order's state refuses (editing a sent order's
 * lines, receiving a draft…). An expected refusal: rendered as the flash error
 * it names, never logged.
 */
class PurchaseOrderException extends RuntimeException
{
    public function __construct(public readonly string $flashKey)
    {
        parent::__construct($flashKey);
    }

    public function report(): void {}

    public function render(): RedirectResponse
    {
        return back()->withInput()->with('error', $this->flashKey);
    }
}
```

- [ ] **Step 4: Activity codes**

In `app/Services/Activity/ActivityAction.php` replace

```php
    const PAYMENT_ORDER_PRINTED = 'payment_order_printed';
```

with

```php
    const PAYMENT_ORDER_PRINTED = 'payment_order_printed';

    const PURCHASE_ORDER_CREATED = 'purchase_order_created';

    const PURCHASE_ORDER_UPDATED = 'purchase_order_updated';

    const PURCHASE_ORDER_SENT = 'purchase_order_sent';

    const PURCHASE_ORDER_RECEIVED = 'purchase_order_received';

    const PURCHASE_ORDER_CANCELLED = 'purchase_order_cancelled';

    const PURCHASE_ORDER_PRINTED = 'purchase_order_printed';
```

In `ALL` (8-space indent) replace

```php
        self::PAYMENT_ORDER_PRINTED,
        self::LEGACY_DATA_IMPORTED,
        self::FINANCE_RESET,
```

with

```php
        self::PAYMENT_ORDER_PRINTED,
        self::PURCHASE_ORDER_CREATED,
        self::PURCHASE_ORDER_UPDATED,
        self::PURCHASE_ORDER_SENT,
        self::PURCHASE_ORDER_RECEIVED,
        self::PURCHASE_ORDER_CANCELLED,
        self::PURCHASE_ORDER_PRINTED,
        self::LEGACY_DATA_IMPORTED,
        self::FINANCE_RESET,
```

In `AREAS['money']` (12-space indent) replace

```php
            self::PAYMENT_ORDER_PRINTED,
            self::LEGACY_DATA_IMPORTED,
        ],
```

with

```php
            self::PAYMENT_ORDER_PRINTED,
            self::PURCHASE_ORDER_CREATED,
            self::PURCHASE_ORDER_UPDATED,
            self::PURCHASE_ORDER_SENT,
            self::PURCHASE_ORDER_RECEIVED,
            self::PURCHASE_ORDER_CANCELLED,
            self::PURCHASE_ORDER_PRINTED,
            self::LEGACY_DATA_IMPORTED,
        ],
```

In `tests/Unit/ActivityRecorderTest.php` replace

```php
        $this->assertCount(125, ActivityAction::ALL);
```

with

```php
        $this->assertCount(131, ActivityAction::ALL);
```

- [ ] **Step 5: Subject link (activity report)**

In `app/Services/Activity/ActivitySubjectLink.php` replace

```php
use App\Models\PlayerDocument;
use App\Models\Role;
```

with

```php
use App\Models\PlayerDocument;
use App\Models\PurchaseOrder;
use App\Models\Role;
```

replace

```php
        PaymentOrder::class => ['payment_orders'],
```

with

```php
        PaymentOrder::class => ['payment_orders'],
        PurchaseOrder::class => ['purchase_orders'],
```

and replace

```php
            $subject instanceof PaymentOrder => [
                $subject->reference.' · '.$subject->subject,
                route('payment-orders.show', $subject),
            ],
```

with

```php
            $subject instanceof PaymentOrder => [
                $subject->reference.' · '.$subject->subject,
                route('payment-orders.show', $subject),
            ],
            // The route comes with the controller (Task 5); a link is only built when a log is displayed.
            $subject instanceof PurchaseOrder => [
                UiLang::get('pur.title').' '.$subject->reference,
                route('purchase-orders.show', $subject),
            ],
```

- [ ] **Step 6: Stock rows carry the purchase order**

In `app/Services/Equipment/EquipmentStockService.php`, in `receiveRow()` replace

```php
            'purchase_transaction_id' => $transactionId,
```

with

```php
            'purchase_transaction_id' => $transactionId,
            // Set only by a purchase order's reception (the Receive Stock form never sends it).
            'purchase_order_id' => $data['purchase_order_id'] ?? null,
```

In `splitLot()` replace

```php
                'received_via' => $item->received_via ?? 'purchase',
                'notes' => $notes,
```

with

```php
                'received_via' => $item->received_via ?? 'purchase',
                'purchase_order_id' => $item->purchase_order_id,
                'notes' => $notes,
```

In `writeOffMissing()` replace

```php
                'status' => 'Lost',
                'received_via' => $item->received_via ?? 'purchase',
```

with

```php
                'status' => 'Lost',
                'received_via' => $item->received_via ?? 'purchase',
                'purchase_order_id' => $item->purchase_order_id,
```

(Split and written-off units still come from the order: the order page's "units put into stock" and the payment-time expense link keep counting them.)

- [ ] **Step 7: The service**

Create `app/Services/Finance/PurchaseOrderService.php`:

```php
<?php

namespace App\Services\Finance;

use App\Exceptions\PurchaseOrderException;
use App\Http\Requests\Equipment\ReceiveStockRequest;
use App\Models\EquipmentItem;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Documents\YearlySequence;
use App\Services\Equipment\EquipmentStockService;
use App\Support\UiLang;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every purchase order state change, one DB transaction each, each guarded by
 * an atomic conditional claim: draft (numbered) → sent (supplier, lines and
 * quantities locked; unit prices open until a payment order is linked) →
 * received (optional stock + draft payment order) or cancelled. Money is
 * recorded once, when the linked payment order is paid.
 */
final class PurchaseOrderService
{
    /** Header fields a form may set; numbering, state and audit columns are the service's. */
    private const HEADER = ['order_date', 'supplier_id', 'requested_by', 'delivery_mode', 'payment_mode', 'observation'];

    public function __construct(
        private readonly PaymentOrderService $paymentOrders,
        private readonly EquipmentStockService $stock,
    ) {}

    /** @param array<string, mixed> $data validated form fields, with `lines` */
    public function create(array $data, User $by): PurchaseOrder
    {
        return YearlySequence::retrying(fn () => DB::transaction(function () use ($data, $by) {
            $year = Carbon::parse($data['order_date'])->year;
            $order = PurchaseOrder::create([
                ...Arr::only($data, self::HEADER),
                'year' => $year,
                'number' => YearlySequence::next('purchase_orders', $year),
                'status' => PurchaseOrder::DRAFT,
                'created_by' => $by->id,
            ]);
            $this->writeLines($order, $data['lines']);
            $order->load('lines');
            ActivityRecorder::record($by, ActivityAction::PURCHASE_ORDER_CREATED, $order, [
                'reference' => $order->reference,
                'total' => $order->isFullyPriced() ? $order->total() : null,
            ]);

            return $order->fresh('lines');
        }));
    }

    /**
     * Draft: every field and the lines. Sent or received: only the unit prices
     * (`lines` of `{id, unit_price}`), until an active payment order is linked.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PurchaseOrder $order, array $data, User $by): PurchaseOrder
    {
        if ($order->is_legacy || $order->status === PurchaseOrder::CANCELLED) {
            throw new PurchaseOrderException('flash.purchase_order_locked');
        }

        return $order->status === PurchaseOrder::DRAFT
            ? $this->updateDraft($order, $data, $by)
            : $this->updatePrices($order, $data, $by);
    }

    /** @param array<string, mixed> $data */
    private function updateDraft(PurchaseOrder $order, array $data, User $by): PurchaseOrder
    {
        // The number belongs to the year it was given in.
        if (Carbon::parse($data['order_date'])->year !== $order->year) {
            throw new PurchaseOrderException('flash.purchase_order_year_locked');
        }

        return DB::transaction(function () use ($order, $data, $by) {
            $claimed = PurchaseOrder::whereKey($order->id)
                ->where('status', PurchaseOrder::DRAFT)->where('is_legacy', false)
                ->update([...Arr::only($data, self::HEADER), 'updated_at' => now()]);
            if ($claimed === 0) {
                throw new PurchaseOrderException('flash.purchase_order_locked');
            }
            $this->writeLines($order, $data['lines']);
            ActivityRecorder::record($by, ActivityAction::PURCHASE_ORDER_UPDATED, $order, ['reference' => $order->reference]);

            return $order->fresh('lines');
        });
    }

    /** @param array<string, mixed> $data */
    private function updatePrices(PurchaseOrder $order, array $data, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data, $by) {
            $claimed = PurchaseOrder::whereKey($order->id)
                ->whereIn('status', [PurchaseOrder::SENT, PurchaseOrder::RECEIVED])->where('is_legacy', false)
                ->whereDoesntHave('paymentOrders', fn ($q) => $q->where('payment_orders.status', '!=', PaymentOrder::CANCELLED))
                ->update(['updated_at' => now()]);
            if ($claimed === 0) {
                throw new PurchaseOrderException('flash.purchase_order_prices_locked');
            }

            $prices = collect($data['lines'] ?? [])->mapWithKeys(fn (array $line) => [(int) $line['id'] => $line['unit_price'] ?? null]);
            foreach (PurchaseOrderLine::where('purchase_order_id', $order->id)->get() as $line) {
                if (! $prices->has($line->id)) {
                    continue;
                }
                $price = self::price($prices->get($line->id));
                $line->update(['unit_price' => $price, 'amount' => PurchaseOrderLine::amountFor($line->quantity, $price)]);
            }
            ActivityRecorder::record($by, ActivityAction::PURCHASE_ORDER_UPDATED, $order, ['reference' => $order->reference]);

            return $order->fresh('lines');
        });
    }

    public function send(PurchaseOrder $order, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $by) {
            $claimed = PurchaseOrder::whereKey($order->id)
                ->where('status', PurchaseOrder::DRAFT)->where('is_legacy', false)
                ->whereNotNull('supplier_id')->whereHas('lines')
                ->update(['status' => PurchaseOrder::SENT, 'sent_by' => $by->id, 'sent_at' => now()]);
            if ($claimed === 0) {
                throw new PurchaseOrderException('flash.purchase_order_not_draft');
            }
            ActivityRecorder::record($by, ActivityAction::PURCHASE_ORDER_SENT, $order, ['reference' => $order->reference]);

            return $order->fresh('lines');
        });
    }

    /** Draft or sent only; the order keeps its number. */
    public function cancel(PurchaseOrder $order, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $by) {
            $claimed = PurchaseOrder::whereKey($order->id)
                ->whereIn('status', [PurchaseOrder::DRAFT, PurchaseOrder::SENT])->where('is_legacy', false)
                ->update(['status' => PurchaseOrder::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => now()]);
            if ($claimed === 0) {
                throw new PurchaseOrderException('flash.purchase_order_cannot_cancel');
            }
            ActivityRecorder::record($by, ActivityAction::PURCHASE_ORDER_CANCELLED, $order, ['reference' => $order->reference]);

            return $order->fresh('lines');
        });
    }

    /**
     * The whole order arrives at once. Optional, in the same transaction:
     * catalog lines into stock (no expense — money is recorded at payment) and
     * a draft payment order to the supplier for the order's total.
     */
    public function receive(PurchaseOrder $order, User $by, string $receivedDate, bool $toStock = false, bool $makePaymentOrder = false): PurchaseOrder
    {
        $order->load('lines.catalog:id,requires_serial');
        if ($makePaymentOrder && ! self::payable($order)) {
            throw new PurchaseOrderException('flash.purchase_order_unpriced');
        }
        /** @var Collection<int, PurchaseOrderLine> $stockLines free-text lines never go to stock */
        $stockLines = $toStock ? $order->lines->whereNotNull('equipment_catalog_id')->values() : collect();
        foreach ($stockLines as $line) {
            if ($line->catalog?->requires_serial && $line->quantity > ReceiveStockRequest::MAX_NUMBERED_UNITS) {
                throw new PurchaseOrderException('flash.purchase_order_too_many_units');
            }
        }

        return DB::transaction(function () use ($order, $by, $receivedDate, $stockLines, $makePaymentOrder) {
            $claimed = PurchaseOrder::whereKey($order->id)
                ->where('status', PurchaseOrder::SENT)->where('is_legacy', false)
                ->update([
                    'status' => PurchaseOrder::RECEIVED,
                    'received_by' => $by->id,
                    'received_at' => Carbon::parse($receivedDate)->startOfDay(),
                ]);
            if ($claimed === 0) {
                throw new PurchaseOrderException('flash.purchase_order_not_sent');
            }

            foreach ($stockLines as $line) {
                $this->stock->receive([
                    'catalog_id' => $line->equipment_catalog_id,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price !== null ? (float) $line->unit_price : null,
                    'designation' => $line->designation,
                    'condition' => 'New',
                    'received_via' => 'purchase',
                    'record_expense' => false,
                    'purchase_date' => $receivedDate,
                    'purchase_order_id' => $order->id,
                ], $by->id, $by);
            }
            $paymentOrder = $makePaymentOrder ? $this->makePaymentOrder($order, $by, $receivedDate) : null;

            ActivityRecorder::record($by, ActivityAction::PURCHASE_ORDER_RECEIVED, $order, [
                'reference' => $order->reference,
                'stock_units' => $stockLines->isEmpty() ? null : (int) EquipmentItem::where('purchase_order_id', $order->id)->sum('quantity'),
                'payment_order_id' => $paymentOrder?->id,
            ]);

            return $order->fresh('lines');
        });
    }

    /** The "Create payment order" button of a received order (dated today). */
    public function createPaymentOrder(PurchaseOrder $order, User $by): PaymentOrder
    {
        if ($order->status !== PurchaseOrder::RECEIVED || $order->is_legacy) {
            throw new PurchaseOrderException('flash.purchase_order_not_received');
        }
        $order->load('lines');

        return DB::transaction(fn () => $this->makePaymentOrder($order, $by, now()->format('Y-m-d')));
    }

    /**
     * A draft payment order to the supplier (bank details from the supplier)
     * for the order's total. PaymentOrderService::create claims the purchase
     * order atomically, so a second active payment order cannot slip in.
     */
    private function makePaymentOrder(PurchaseOrder $order, User $by, string $date): PaymentOrder
    {
        if (! self::payable($order)) {
            throw new PurchaseOrderException('flash.purchase_order_unpriced');
        }
        if ($order->hasActivePaymentOrder()) {
            throw new PurchaseOrderException('flash.purchase_order_has_payment_order');
        }
        $supplier = Supplier::find($order->supplier_id);

        return $this->paymentOrders->create([
            'order_date' => $date,
            'subject' => Str::limit(UiLang::get('pur.title').' '.$order->reference, 150, ''),
            'amount' => $order->total(),
            'payment_method' => $order->payment_mode,
            'beneficiary_type' => 'supplier',
            'beneficiary_id' => $order->supplier_id,
            'beneficiary_bank' => $supplier?->bank_name,
            'beneficiary_rib' => $supplier?->rib,
            'purchase_order_id' => $order->id,
        ], $by);
    }

    /** Every line priced and something to pay. */
    private static function payable(PurchaseOrder $order): bool
    {
        return $order->isFullyPriced() && $order->total() > 0;
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(PurchaseOrder $order, array $lines): void
    {
        PurchaseOrderLine::where('purchase_order_id', $order->id)->delete();
        foreach (array_values($lines) as $index => $line) {
            $quantity = (int) $line['quantity'];
            $price = self::price($line['unit_price'] ?? null);
            PurchaseOrderLine::create([
                'purchase_order_id' => $order->id,
                'equipment_catalog_id' => $line['equipment_catalog_id'] ?? null,
                'designation' => Str::limit(trim((string) $line['designation']), 255, ''),
                'quantity' => $quantity,
                'unit_price' => $price,
                'amount' => PurchaseOrderLine::amountFor($quantity, $price),
                'sort_order' => $index + 1,
            ]);
        }
    }

    private static function price(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : round((float) $value, 2);
    }
}
```

- [ ] **Step 8: Translations (refusals, activity labels, title)**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p2-t4-keys.json`:

```json
{
    "pur.title": { "ar": "سند طلب", "fr": "Bon de commande", "en": "Purchase order" },
    "flash.purchase_order_locked": { "ar": "لم يعد بالإمكان تعديل سند الطلب هذا.", "fr": "Ce bon de commande ne peut plus être modifié.", "en": "This purchase order can no longer be changed." },
    "flash.purchase_order_year_locked": { "ar": "يجب أن يبقى التاريخ في سنة السند (رقمه تابع لتلك السنة).", "fr": "La date doit rester dans l'année du bon (son numéro appartient à cette année).", "en": "The date must stay in the order's year (its number belongs to that year)." },
    "flash.purchase_order_prices_locked": { "ar": "الأسعار مقفلة: أمر دفع مرتبط بهذا السند.", "fr": "Les prix sont verrouillés : un ordre de paiement est lié.", "en": "Prices are locked: a payment order is linked." },
    "flash.purchase_order_not_draft": { "ar": "لا يُرسَل إلا سند في حالة مسودة فيه مورد وسطر واحد على الأقل.", "fr": "Seul un brouillon avec un fournisseur et au moins une ligne peut être envoyé.", "en": "Only a draft with a supplier and at least one line can be sent." },
    "flash.purchase_order_not_sent": { "ar": "لا يُستلَم إلا سند مُرسَل.", "fr": "Seul un bon envoyé peut être reçu.", "en": "Only a sent order can be received." },
    "flash.purchase_order_not_received": { "ar": "يجب استلام السند أولاً.", "fr": "Le bon doit d'abord être reçu.", "en": "The order must be received first." },
    "flash.purchase_order_cannot_cancel": { "ar": "لا يُلغى إلا سند في حالة مسودة أو مُرسَل.", "fr": "Seul un bon brouillon ou envoyé peut être annulé.", "en": "Only a draft or sent order can be cancelled." },
    "flash.purchase_order_unpriced": { "ar": "يجب تسعير كل سطر قبل إنشاء أمر الدفع.", "fr": "Chaque ligne doit avoir un prix avant de créer l'ordre de paiement.", "en": "Every line needs a price before a payment order can be made." },
    "flash.purchase_order_has_payment_order": { "ar": "لهذا السند أمر دفع قائم.", "fr": "Ce bon a déjà un ordre de paiement en cours.", "en": "This purchase order already has a payment order." },
    "flash.purchase_order_too_many_units": { "ar": "عدد كبير جدًا من الوحدات المرقّمة في سطر واحد (200 كحد أقصى).", "fr": "Trop d'unités numérotées sur une ligne (200 au maximum).", "en": "Too many numbered units on one line (200 at most)." },
    "activity.action.purchase_order_created": { "ar": "إنشاء سند طلب", "fr": "Bon de commande créé", "en": "Purchase order created" },
    "activity.action.purchase_order_updated": { "ar": "تعديل سند طلب", "fr": "Bon de commande modifié", "en": "Purchase order edited" },
    "activity.action.purchase_order_sent": { "ar": "إرسال سند طلب", "fr": "Bon de commande envoyé", "en": "Purchase order sent" },
    "activity.action.purchase_order_received": { "ar": "استلام سند طلب", "fr": "Bon de commande reçu", "en": "Purchase order received" },
    "activity.action.purchase_order_cancelled": { "ar": "إلغاء سند طلب", "fr": "Bon de commande annulé", "en": "Purchase order cancelled" },
    "activity.action.purchase_order_printed": { "ar": "طباعة سند طلب", "fr": "Bon de commande imprimé", "en": "Purchase order printed" }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p2-t4-keys.json`
Expected: `✓ 17 key(s) written to ar/en/fr`

- [ ] **Step 9: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderServiceTest.php`
Expected: PASS (19 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Unit/ActivityRecorderTest.php`
Expected: PASS (131 codes, each in exactly one area).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/EquipmentReceiveStockTest.php`
Expected: PASS.
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/EquipmentLotJourneyTest.php`
Expected: PASS.
Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Exceptions/PurchaseOrderException.php app/Services/Finance/PurchaseOrderService.php app/Services/Equipment/EquipmentStockService.php app/Services/Activity/ActivityAction.php app/Services/Activity/ActivitySubjectLink.php tests/Unit/ActivityRecorderTest.php tests/Feature/PurchaseOrderServiceTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(purchase-orders): service for create, prices, send, cancel, receive and payment order

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 5: Controller, form request and routes

**Files:**
- Create: `app/Http/Requests/PurchaseOrder/SavePurchaseOrderRequest.php`
- Create: `app/Http/Controllers/PurchaseOrderController.php`
- Modify: `routes/web.php` (imports near line 64; routes after `transactions.payment-order.store`, line ~293)
- Modify: `resources/js/i18n/*.json` (via script)
- Test: `tests/Feature/PurchaseOrderHttpTest.php`

**Interfaces:**
- Consumes: `PurchaseOrderService` (Task 4), `PermissionMap::resolve(?string $routeName): ?array`.
- Produces routes: `purchase-orders.index` GET `/purchase-orders`, `.create` GET `/purchase-orders/create`, `.store` POST, `.show` GET `/purchase-orders/{purchaseOrder}`, `.edit` GET `/purchase-orders/{purchaseOrder}/edit`, `.update` PUT, `.send` POST `/purchase-orders/{purchaseOrder}/send`, `.receive` POST `…/receive` (fields `received_date`, `to_stock`, `payment_order`), `.cancel` POST `…/cancel`, `.payment-order.store` POST `…/payment-order`.
- Inertia props (Task 7 builds the pages on them):
  - `PurchaseOrders/Index`: `orders` (paginator of `{id, reference, order_date, supplier, requested_by, total: ?float, status, is_legacy}`), `filters`, `years`, `suppliers` (`{id, name}`).
  - `PurchaseOrders/Create`: `suppliers` (`{id, name, address, phone}`), `catalogs` (`{id, name}`).
  - `PurchaseOrders/Edit`: `order` (with `lines`), `pricesOnly: bool`, `suppliers`, `catalogs`.
  - `PurchaseOrders/Show`: `order` (model array + `supplier{id,name,address,phone}`, `lines[]` with `catalog{id,name}`, `payment_orders: list<{id, reference, status, amount}>`, `total: float`, `fully_priced: bool`, `equipment_units: int`), `can` `{edit, send, receive, cancel, receive_stock, payment_order_on_receive, create_payment_order, print}`.
  - `PurchaseOrderController::mayReceiveStock(User $user): bool` (public static).
- Flash keys `flash.purchase_order_created`, `_updated`, `_sent`, `_received`, `_cancelled`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PurchaseOrderHttpTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchaseOrderHttpTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private EquipmentCatalog $balls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->supplier = Supplier::create(['name' => 'Test Sport Shop', 'bank_name' => 'BNA', 'rib' => '00100000000000001234']);
        $this->balls = EquipmentCatalog::create(['name' => 'Ballon taille 5', 'category' => 'Balls']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'order_date' => '2026-11-25', 'supplier_id' => $this->supplier->id, 'requested_by' => 'Section football',
            'delivery_mode' => 'Livraison au club', 'payment_mode' => 'cheque', 'observation' => null,
            'lines' => [
                ['equipment_catalog_id' => $this->balls->id, 'designation' => 'Ballon taille 5', 'quantity' => 10, 'unit_price' => 1500],
                ['equipment_catalog_id' => null, 'designation' => 'Gonfleur', 'quantity' => 2, 'unit_price' => null],
            ],
        ], $extra);
    }

    private function service(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    private function draft(array $extra = []): PurchaseOrder
    {
        return $this->service()->create($this->payload($extra), User::factory()->admin()->create());
    }

    private function sent(array $extra = []): PurchaseOrder
    {
        return $this->service()->send($this->draft($extra), User::factory()->admin()->create());
    }

    private function pricedLines(): array
    {
        return ['lines' => [['equipment_catalog_id' => $this->balls->id, 'designation' => 'Ballon taille 5', 'quantity' => 10, 'unit_price' => 1500]]];
    }

    #[Test]
    public function an_editor_creates_a_draft_and_lands_on_it(): void
    {
        $user = $this->userWith(['purchase_orders' => ['view', 'add', 'edit']]);

        $response = $this->actingAs($user)->post(route('purchase-orders.store'), $this->payload());

        $order = PurchaseOrder::firstOrFail();
        $response->assertRedirect(route('purchase-orders.show', $order))->assertSessionHas('success', 'flash.purchase_order_created');
        $this->assertSame('01/2026', $order->reference);
        $this->assertCount(2, $order->lines);
    }

    #[Test]
    public function validation_needs_a_supplier_and_valid_lines(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('purchase-orders.store'), $this->payload(['supplier_id' => null]))
            ->assertSessionHasErrors('supplier_id');
        $this->post(route('purchase-orders.store'), $this->payload(['lines' => []]))
            ->assertSessionHasErrors('lines');
        $this->post(route('purchase-orders.store'), $this->payload(['lines' => [['designation' => '', 'quantity' => 0]]]))
            ->assertSessionHasErrors(['lines.0.designation', 'lines.0.quantity']);
        $this->post(route('purchase-orders.store'), $this->payload(['payment_mode' => 'barter']))
            ->assertSessionHasErrors('payment_mode');
        $this->assertSame(0, PurchaseOrder::count());
    }

    #[Test]
    public function a_view_only_user_cannot_create_or_send(): void
    {
        $order = $this->draft();
        $viewer = $this->userWith(['purchase_orders' => ['view']]);

        $this->actingAs($viewer)->post(route('purchase-orders.store'), $this->payload())->assertForbidden();
        $this->post(route('purchase-orders.send', $order))->assertForbidden();
        $this->assertSame(PurchaseOrder::DRAFT, $order->fresh()->status);
    }

    #[Test]
    public function a_draft_is_updated_and_a_sent_order_only_changes_its_prices(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->draft();

        $this->actingAs($admin)->put(route('purchase-orders.update', $order), $this->payload(['requested_by' => 'Section natation']))
            ->assertRedirect(route('purchase-orders.show', $order))->assertSessionHas('success', 'flash.purchase_order_updated');
        $this->assertSame('Section natation', $order->fresh()->requested_by);

        $this->post(route('purchase-orders.send', $order))->assertSessionHas('success', 'flash.purchase_order_sent');
        $lines = $order->fresh()->lines;
        $this->put(route('purchase-orders.update', $order), [
            'requested_by' => 'Ignored',
            'lines' => [['id' => $lines[0]->id, 'unit_price' => 1400], ['id' => $lines[1]->id, 'unit_price' => 900]],
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('Section natation', $order->requested_by);
        $this->assertSame('1400.00', $order->lines[0]->unit_price);
        $this->assertSame('1800.00', $order->lines[1]->amount);
    }

    #[Test]
    public function the_edit_page_turns_away_a_locked_order(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->service()->cancel($this->draft(), $admin);

        $this->actingAs($admin)->get(route('purchase-orders.edit', $order))
            ->assertRedirect(route('purchase-orders.show', $order))
            ->assertSessionHas('error', 'flash.purchase_order_locked');
    }

    #[Test]
    public function receiving_and_cancelling_answer_with_flashes(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = $this->draft();
        $sent = $this->sent();

        $this->actingAs($admin)->post(route('purchase-orders.receive', $draft), ['received_date' => '2026-11-27'])
            ->assertSessionHas('error', 'flash.purchase_order_not_sent');
        $this->post(route('purchase-orders.receive', $sent), ['received_date' => '2026-11-27'])
            ->assertSessionHas('success', 'flash.purchase_order_received');
        $this->assertSame(PurchaseOrder::RECEIVED, $sent->fresh()->status);

        $this->post(route('purchase-orders.cancel', $draft))->assertSessionHas('success', 'flash.purchase_order_cancelled');
        $this->post(route('purchase-orders.cancel', $sent))->assertSessionHas('error', 'flash.purchase_order_cannot_cancel');
        $this->post(route('purchase-orders.receive', $sent), [])->assertSessionHasErrors('received_date');
    }

    #[Test]
    public function receiving_into_stock_needs_the_equipment_receive_right(): void
    {
        $order = $this->sent();
        $buyer = $this->userWith(['purchase_orders' => ['view', 'edit']]);
        $storekeeper = $this->userWith(['purchase_orders' => ['view', 'edit'], 'equipment' => ['view', 'edit']]);

        $this->actingAs($buyer)->post(route('purchase-orders.receive', $order), ['received_date' => '2026-11-27', 'to_stock' => true])
            ->assertForbidden();
        $this->assertSame(PurchaseOrder::SENT, $order->fresh()->status);

        $this->actingAs($storekeeper)->post(route('purchase-orders.receive', $order), ['received_date' => '2026-11-27', 'to_stock' => true])
            ->assertSessionHas('success', 'flash.purchase_order_received');
        $this->assertSame(1, EquipmentItem::where('purchase_order_id', $order->id)->count());
    }

    #[Test]
    public function a_payment_order_on_receive_needs_the_payment_orders_add_right(): void
    {
        $order = $this->sent($this->pricedLines());
        $buyer = $this->userWith(['purchase_orders' => ['view', 'edit']]);
        $treasurer = $this->userWith(['purchase_orders' => ['view', 'edit'], 'payment_orders' => ['view', 'add']]);

        $this->actingAs($buyer)->post(route('purchase-orders.receive', $order), ['received_date' => '2026-11-27', 'payment_order' => true])
            ->assertForbidden();

        $this->actingAs($treasurer)->post(route('purchase-orders.receive', $order), ['received_date' => '2026-11-27', 'payment_order' => true])
            ->assertSessionHas('success', 'flash.purchase_order_received');
        $this->assertSame($order->id, PaymentOrder::firstOrFail()->purchase_order_id);
    }

    #[Test]
    public function the_payment_order_button_needs_both_rights_and_lands_on_the_new_order(): void
    {
        $order = $this->service()->receive($this->sent($this->pricedLines()), User::factory()->admin()->create(), '2026-11-27');
        $buyer = $this->userWith(['purchase_orders' => ['view', 'edit']]);
        $treasurer = $this->userWith(['purchase_orders' => ['view', 'edit'], 'payment_orders' => ['view', 'add']]);
        $reader = $this->userWith(['purchase_orders' => ['view'], 'payment_orders' => ['view', 'add']]);

        $this->actingAs($buyer)->post(route('purchase-orders.payment-order.store', $order))->assertForbidden();
        $this->actingAs($reader)->post(route('purchase-orders.payment-order.store', $order))->assertForbidden();
        $this->assertSame(0, PaymentOrder::count());

        $this->actingAs($treasurer)->post(route('purchase-orders.payment-order.store', $order))
            ->assertRedirect(route('payment-orders.show', PaymentOrder::firstOrFail()))
            ->assertSessionHas('success', 'flash.payment_order_created');
        $this->post(route('purchase-orders.payment-order.store', $order))
            ->assertSessionHas('error', 'flash.purchase_order_has_payment_order');
    }

    #[Test]
    public function the_register_lists_filters_and_finds_numbers(): void
    {
        $admin = User::factory()->admin()->create();
        $this->draft();
        $this->sent($this->pricedLines());
        $this->draft(['requested_by' => 'Section natation']);

        $this->actingAs($admin)->get(route('purchase-orders.index', ['status' => 'sent']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.reference', '02/2026')
                ->where('orders.data.0.supplier', 'Test Sport Shop')
                ->where('orders.data.0.total', fn ($total) => (float) $total === 15000.0)
                ->has('years')->has('suppliers'));
        $this->get(route('purchase-orders.index', ['q' => '03/2026']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.requested_by', 'Section natation'));
        $this->get(route('purchase-orders.index', ['q' => 'gonfleur']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 2)->where('orders.data.0.total', null));
        $this->get(route('purchase-orders.index', ['q' => 'sport shop']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 3));
    }

    #[Test]
    public function the_show_page_tells_what_the_viewer_may_do(): void
    {
        $order = $this->sent();
        $viewer = $this->userWith(['purchase_orders' => ['view']]);

        $this->actingAs($viewer)->get(route('purchase-orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.reference', '01/2026')
                ->where('order.supplier.name', 'Test Sport Shop')
                ->where('order.fully_priced', false)
                ->where('can.edit', false)->where('can.receive', false)->where('can.cancel', false)
                ->where('can.receive_stock', false)->where('can.create_payment_order', false)
                ->where('can.print', true));

        $this->actingAs(User::factory()->admin()->create())->get(route('purchase-orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.edit', true)->where('can.receive', true)->where('can.cancel', true)
                ->where('can.receive_stock', true)
                // One line has no price yet.
                ->where('can.payment_order_on_receive', false));
    }

    #[Test]
    public function there_is_no_delete_route(): void
    {
        $this->assertFalse(Route::has('purchase-orders.destroy'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderHttpTest.php`
Expected: FAIL (`Route [purchase-orders.store] not defined`).

- [ ] **Step 3: Form request**

Create `app/Http/Requests/PurchaseOrder/SavePurchaseOrderRequest.php`:

```php
<?php

namespace App\Http\Requests\PurchaseOrder;

use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A draft sends every field and its lines. Once sent, only the unit prices
 * can change (until a payment order is linked), so the form then sends just
 * `lines[*].id` and `lines[*].unit_price` — anything else is not validated,
 * so it never reaches the service.
 */
class SavePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $order = $this->route('purchaseOrder');
        $price = ['nullable', 'numeric', 'min:0', 'max:99999999'];

        if ($order instanceof PurchaseOrder && $order->status !== PurchaseOrder::DRAFT) {
            return [
                'lines' => ['required', 'array', 'min:1'],
                'lines.*.id' => ['required', 'integer'],
                'lines.*.unit_price' => $price,
            ];
        }
        $current = $order instanceof PurchaseOrder ? $order->supplier_id : null;

        return [
            'order_date' => ['required', 'date'],
            // An active supplier — or the one the draft already names, so a draft whose
            // supplier was deactivated later can still be edited.
            'supplier_id' => [
                'required', 'integer',
                Rule::exists('suppliers', 'id')
                    ->where(fn ($query) => $query->where(fn ($query) => $query->where('is_active', true)
                        ->when($current, fn ($query, $id) => $query->orWhere('id', $id)))),
            ],
            'requested_by' => ['nullable', 'string', 'max:200'],
            'delivery_mode' => ['nullable', 'string', 'max:200'],
            'payment_mode' => ['nullable', Rule::in(PaymentOrder::METHODS)],
            'observation' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.equipment_catalog_id' => ['nullable', 'integer', Rule::exists('equipment_catalogs', 'id')],
            'lines.*.designation' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price' => $price,
        ];
    }
}
```

- [ ] **Step 4: Controller**

Create `app/Http/Controllers/PurchaseOrderController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseOrder\SavePurchaseOrderRequest;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\PurchaseOrderService;
use App\Support\PermissionMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Finance → Purchase orders (سندات الطلب): register, form, status actions. */
class PurchaseOrderController extends Controller
{
    private const USER_COLUMNS = 'id,name,firstname,lastname';

    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'year' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(PurchaseOrder::STATUSES)],
            'supplier' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $like = '%'.($filters['q'] ?? '').'%';

        $orders = PurchaseOrder::query()
            ->with('supplier:id,name')
            ->withCount(['lines', 'lines as unpriced_count' => fn ($q) => $q->whereNull('unit_price')])
            ->withSum('lines', 'amount')
            ->when($filters['year'] ?? null, fn ($q, $year) => $q->where('year', $year))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['supplier'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(function ($q) use ($text, $like) {
                $q->where('requested_by', 'like', $like)
                    ->orWhere('legacy_reference', 'like', $like)
                    ->orWhereHas('supplier', fn ($q) => $q->where('name', 'like', $like))
                    ->orWhereHas('lines', fn ($q) => $q->where('designation', 'like', $like));
                // "3" or "03/2026" finds order number 3 (of 2026).
                if (preg_match('/^0*(\d+)(?:\/(\d{4}))?$/', trim($text), $m)) {
                    $q->orWhere(fn ($q) => $q->where('number', (int) $m[1])
                        ->when($m[2] ?? null, fn ($q, $year) => $q->where('year', (int) $year)));
                }
            }))
            ->orderByDesc('year')->orderByDesc('number')->orderByDesc('order_date')->orderByDesc('id')
            ->paginate(50)->withQueryString()
            ->through(fn (PurchaseOrder $order) => [
                'id' => $order->id,
                'reference' => $order->reference,
                'order_date' => $order->order_date?->format('Y-m-d'),
                'supplier' => $order->supplier?->name,
                'requested_by' => $order->requested_by,
                // Blank until every line has a price.
                'total' => (int) $order->lines_count > 0 && (int) $order->unpriced_count === 0 ? (float) $order->lines_sum_amount : null,
                'status' => $order->status,
                'is_legacy' => $order->is_legacy,
            ]);

        return Inertia::render('PurchaseOrders/Index', [
            'orders' => $orders,
            'filters' => $filters,
            'years' => PurchaseOrder::query()->distinct()->orderByDesc('year')->pluck('year'),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PurchaseOrders/Create', $this->formOptions());
    }

    public function store(SavePurchaseOrderRequest $request): RedirectResponse
    {
        $order = $this->orders->create($request->validated(), $request->user());

        return redirect()->route('purchase-orders.show', $order)->with('success', 'flash.purchase_order_created');
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $purchaseOrder->load([
            'supplier:id,name,address,phone', 'lines.catalog:id,name',
            'paymentOrders' => fn ($q) => $q->orderByDesc('id'),
            'createdBy:'.self::USER_COLUMNS, 'sentBy:'.self::USER_COLUMNS,
            'receivedBy:'.self::USER_COLUMNS, 'cancelledBy:'.self::USER_COLUMNS,
        ]);
        $user = $request->user();
        $status = $purchaseOrder->status;
        $mayEdit = $user->hasPermission('purchase_orders', 'edit') && ! $purchaseOrder->is_legacy;
        $hasActive = $purchaseOrder->paymentOrders->contains(fn (PaymentOrder $p) => $p->status !== PaymentOrder::CANCELLED);
        $payable = $purchaseOrder->isFullyPriced() && $purchaseOrder->total() > 0;
        $mayMakePaymentOrder = $user->hasPermission('payment_orders', 'add');

        return Inertia::render('PurchaseOrders/Show', [
            'order' => [
                ...$purchaseOrder->toArray(),
                'payment_orders' => $purchaseOrder->paymentOrders->map(fn (PaymentOrder $p) => [
                    'id' => $p->id, 'reference' => $p->reference, 'status' => $p->status, 'amount' => (float) $p->amount,
                ])->values(),
                'total' => $purchaseOrder->total(),
                'fully_priced' => $purchaseOrder->isFullyPriced(),
                'equipment_units' => (int) EquipmentItem::where('purchase_order_id', $purchaseOrder->id)->sum('quantity'),
            ],
            'can' => [
                'edit' => $mayEdit && ($status === PurchaseOrder::DRAFT
                    || (in_array($status, [PurchaseOrder::SENT, PurchaseOrder::RECEIVED], true) && ! $hasActive)),
                'send' => $mayEdit && $status === PurchaseOrder::DRAFT,
                'receive' => $mayEdit && $status === PurchaseOrder::SENT,
                'cancel' => $mayEdit && in_array($status, [PurchaseOrder::DRAFT, PurchaseOrder::SENT], true),
                // The two ticks of the receive dialog.
                'receive_stock' => self::mayReceiveStock($user)
                    && $purchaseOrder->lines->contains(fn (PurchaseOrderLine $line) => $line->equipment_catalog_id !== null),
                'payment_order_on_receive' => $payable && $mayMakePaymentOrder,
                'create_payment_order' => $mayEdit && $mayMakePaymentOrder && $payable && ! $hasActive
                    && $status === PurchaseOrder::RECEIVED,
                'print' => $status !== PurchaseOrder::CANCELLED,
            ],
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder): Response|RedirectResponse
    {
        if (! $purchaseOrder->isEditable() && ! $purchaseOrder->canEditPrices()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)->with('error', 'flash.purchase_order_locked');
        }
        $purchaseOrder->load('lines');

        return Inertia::render('PurchaseOrders/Edit', [
            'order' => $purchaseOrder,
            'pricesOnly' => $purchaseOrder->status !== PurchaseOrder::DRAFT,
            ...$this->formOptions($purchaseOrder),
        ]);
    }

    public function update(SavePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->orders->update($purchaseOrder, $request->validated(), $request->user());

        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('success', 'flash.purchase_order_updated');
    }

    public function send(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->orders->send($purchaseOrder, $request->user());

        return back()->with('success', 'flash.purchase_order_sent');
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate([
            'received_date' => ['required', 'date'],
            'to_stock' => ['boolean'],
            'payment_order' => ['boolean'],
        ]);
        $user = $request->user();
        $toStock = (bool) ($data['to_stock'] ?? false);
        $makePaymentOrder = (bool) ($data['payment_order'] ?? false);
        // Each tick is its own right: the equipment page's Receive Stock, adding a payment order.
        abort_if($toStock && ! self::mayReceiveStock($user), 403);
        abort_if($makePaymentOrder && ! $user->hasPermission('payment_orders', 'add'), 403);

        $this->orders->receive($purchaseOrder, $user, $data['received_date'], $toStock, $makePaymentOrder);

        return back()->with('success', 'flash.purchase_order_received');
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->orders->cancel($purchaseOrder, $request->user());

        return back()->with('success', 'flash.purchase_order_cancelled');
    }

    /** "Create payment order" on a received order with no active one. */
    public function paymentOrder(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        // The route checks purchase_orders → edit; what it makes is a payment order, so adding one is needed too.
        abort_unless($request->user()->hasPermission('payment_orders', 'add'), 403);
        $paymentOrder = $this->orders->createPaymentOrder($purchaseOrder, $request->user());

        return redirect()->route('payment-orders.show', $paymentOrder)->with('success', 'flash.payment_order_created');
    }

    /** The equipment page's Receive Stock right (whatever config/permissions maps equipment.stock.receive to). */
    public static function mayReceiveStock(User $user): bool
    {
        $required = PermissionMap::resolve('equipment.stock.receive');

        return $required === null || $user->hasPermission(...$required);
    }

    /** @return array<string, mixed> */
    private function formOptions(?PurchaseOrder $current = null): array
    {
        return [
            // Active suppliers, plus the one a draft already names.
            'suppliers' => Supplier::query()
                ->where(fn ($q) => $q->where('is_active', true)->when($current?->supplier_id, fn ($q, $id) => $q->orWhere('id', $id)))
                ->orderBy('name')->get(['id', 'name', 'address', 'phone']),
            'catalogs' => EquipmentCatalog::query()->orderBy('name')->get(['id', 'name']),
        ];
    }
}
```

- [ ] **Step 5: Routes**

In `routes/web.php` replace

```php
use App\Http\Controllers\PaymentOrderPrintController;
```

with

```php
use App\Http\Controllers\PaymentOrderPrintController;
use App\Http\Controllers\PurchaseOrderController;
```

and replace

```php
    Route::post('/transactions/{transaction}/payment-order', [PaymentOrderController::class, 'fromExpense'])->name('transactions.payment-order.store');
```

with

```php
    Route::post('/transactions/{transaction}/payment-order', [PaymentOrderController::class, 'fromExpense'])->name('transactions.payment-order.store');

    // Purchase orders (سند طلب): never deleted — cancelled instead, so the routes have no destroy.
    Route::resource('purchase-orders', PurchaseOrderController::class)->except(['destroy'])->parameters(['purchase-orders' => 'purchaseOrder']);
    Route::post('/purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'send'])->name('purchase-orders.send');
    Route::post('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
    Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
    Route::post('/purchase-orders/{purchaseOrder}/payment-order', [PurchaseOrderController::class, 'paymentOrder'])->name('purchase-orders.payment-order.store');
```

- [ ] **Step 6: Flash translations**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p2-t5-keys.json`:

```json
{
    "flash.purchase_order_created": { "ar": "تم إنشاء سند الطلب.", "fr": "Bon de commande créé.", "en": "Purchase order created." },
    "flash.purchase_order_updated": { "ar": "تم تعديل سند الطلب.", "fr": "Bon de commande modifié.", "en": "Purchase order updated." },
    "flash.purchase_order_sent": { "ar": "تم تسجيل إرسال سند الطلب.", "fr": "Bon de commande marqué envoyé.", "en": "Purchase order marked as sent." },
    "flash.purchase_order_received": { "ar": "تم تسجيل استلام سند الطلب.", "fr": "Bon de commande marqué reçu.", "en": "Purchase order marked as received." },
    "flash.purchase_order_cancelled": { "ar": "تم إلغاء سند الطلب.", "fr": "Bon de commande annulé.", "en": "Purchase order cancelled." }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p2-t5-keys.json`
Expected: `✓ 5 key(s) written to ar/en/fr`

- [ ] **Step 7: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderHttpTest.php`
Expected: PASS (12 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderRightsTest.php`
Expected: PASS.
Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/PurchaseOrder/SavePurchaseOrderRequest.php app/Http/Controllers/PurchaseOrderController.php routes/web.php tests/Feature/PurchaseOrderHttpTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(purchase-orders): controller, form request and routes

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 6: The printed purchase order

**Files:**
- Create: `app/Http/Controllers/PurchaseOrderPrintController.php`
- Create: `resources/views/pdf/purchase-order.blade.php`
- Modify: `routes/web.php` (import + one route)
- Modify: `resources/js/i18n/*.json` (via script)
- Test: `tests/Feature/PurchaseOrderPrintTest.php`

**Interfaces:**
- Consumes: `PurchaseOrder::isFullyPriced()`, `total()` (Task 2); `ClubHeader::data()`, `BoardSignatories::names()`, `AmountInWords::format(float, string)`, `PdfService::stream(string $html, string $filename, ?bool $rtl)` (P1); partials `pdf.partials.header`, `pdf.partials.signatures` (needs `$L`, `$signatories`).
- Produces: route `purchase-orders.print` GET `/purchase-orders/{purchaseOrder}/print` (right: `purchase_orders` → `view`, mapped in Task 1); 404 for a cancelled order; filename `bon-de-commande-NN-YYYY.pdf`; activity `PURCHASE_ORDER_PRINTED` `{reference}`; i18n keys `pur.pdf.title`, `pur.supplier`, `pur.requested_by`, `pur.delivery_mode`, `pur.payment_mode` (Task 7 reuses the last four).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PurchaseOrderPrintTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Pdf\PdfService;
use App\Support\AmountInWords;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchaseOrderPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @param list<array{0: string, 1: int, 2: ?float}>|null $lines designation, quantity, unit price */
    private function order(string $status = PurchaseOrder::SENT, ?array $lines = null): PurchaseOrder
    {
        $supplier = Supplier::create([
            'name' => 'Test Sport Shop', 'address' => '12 rue des Sports, Testville', 'phone' => '0550000000',
            'rib' => '00100000000000001234',
        ]);
        $order = PurchaseOrder::create([
            'year' => 2026, 'number' => 3, 'status' => $status, 'order_date' => '2026-11-25', 'supplier_id' => $supplier->id,
            'requested_by' => 'Section football', 'delivery_mode' => 'Livraison au club', 'payment_mode' => 'cheque',
            'observation' => "Livrer avant le match\nAppeler la veille",
        ]);
        foreach ($lines ?? [['Ballon taille 5', 10, 1500.0], ['Gonfleur', 2, 750.0]] as $i => [$designation, $quantity, $price]) {
            $order->lines()->create([
                'designation' => $designation, 'quantity' => $quantity, 'unit_price' => $price,
                'amount' => PurchaseOrderLine::amountFor($quantity, $price), 'sort_order' => $i + 1,
            ]);
        }

        return $order;
    }

    private function user(string $locale = 'fr'): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function spyPdf(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $html, string $filename, bool $rtl = true) use ($seen) {
                $seen['html'] = $html;
                $seen['filename'] = $filename;
                $seen['rtl'] = $rtl;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        return $seen;
    }

    #[Test]
    public function the_purchase_order_is_a_real_pdf_in_arabic(): void
    {
        $order = $this->order(PurchaseOrder::DRAFT);

        $response = $this->actingAs($this->user('ar'))->get(route('purchase-orders.print', $order))->assertOk();

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function the_french_print_shows_the_header_lines_total_words_and_signatures(): void
    {
        BoardMember::create(['board_term_id' => BoardTerm::current()?->id, 'name' => 'Président Test', 'role' => 'president', 'status' => 'active']);
        BoardMember::create(['board_term_id' => BoardTerm::current()?->id, 'name' => 'Trésorier Test', 'role' => 'treasurer', 'status' => 'active']);
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('purchase-orders.print', $this->order()))->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertSame('bon-de-commande-03-2026.pdf', $seen['filename']);
        foreach ([
            UiLang::get('pur.pdf.title', null, 'fr'), '03/2026', '25/11/2026', 'Test Sport Shop', '12 rue des Sports, Testville', '0550000000',
            'Section football', 'Livraison au club', UiLang::get('cheque', null, 'fr'), 'Ballon taille 5', 'Gonfleur',
            '1 500,00', '15 000,00', '16 500,00', AmountInWords::format(16500.0, 'fr'),
            'Livrer avant le match<br />', 'Président Test', 'Trésorier Test',
        ] as $text) {
            $this->assertStringContainsString($text, $seen['html'], $text);
        }
        $this->assertStringNotContainsString(UiLang::get('po.pdf.draft', null, 'fr'), $seen['html']);
    }

    #[Test]
    public function a_draft_carries_the_draft_banner(): void
    {
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('purchase-orders.print', $this->order(PurchaseOrder::DRAFT)))->assertOk();

        $this->assertStringContainsString(UiLang::get('po.pdf.draft', null, 'fr'), $seen['html']);
    }

    #[Test]
    public function an_unpriced_order_prints_blank_prices_and_no_total_in_words(): void
    {
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('purchase-orders.print', $this->order(PurchaseOrder::SENT, [
            ['Caméra de surveillance', 1, null], ['Câble', 3, 200.0],
        ])))->assertOk();

        $this->assertStringContainsString('Caméra de surveillance', $seen['html']);
        $this->assertStringContainsString('600,00', $seen['html']);
        $this->assertStringNotContainsString(UiLang::get('po.pdf.amount_words', null, 'fr'), $seen['html']);
        $this->assertStringNotContainsString(AmountInWords::format(600.0, 'fr'), $seen['html']);
    }

    #[Test]
    public function a_cancelled_order_does_not_print(): void
    {
        $this->actingAs($this->user())->get(route('purchase-orders.print', $this->order(PurchaseOrder::CANCELLED)))->assertNotFound();
    }

    #[Test]
    public function a_view_only_user_prints_and_the_print_is_logged(): void
    {
        $order = $this->order();
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'preferred_lng' => 'fr',
            'role_id' => Role::factory()->create(['permissions' => ['purchase_orders' => ['view']]])->id,
        ]);

        $this->actingAs($viewer)->get(route('purchase-orders.print', $order))->assertOk();

        $this->assertDatabaseHas('activity_logs', ['action' => ActivityAction::PURCHASE_ORDER_PRINTED, 'subject_id' => $order->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderPrintTest.php`
Expected: FAIL (`Route [purchase-orders.print] not defined`).

- [ ] **Step 3: Print controller**

Create `app/Http/Controllers/PurchaseOrderPrintController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Pdf\BoardSignatories;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\AmountInWords;
use App\Support\UiLang;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** "Bon de commande" / «سند طلب» on A4, in the app language. */
class PurchaseOrderPrintController extends Controller
{
    /** The UI label key of a payment method (same map as resources/js/lib/statusLabels.js). */
    private const METHOD_KEYS = ['bank' => 'bank_transfer', 'cash' => 'cash', 'ccp' => 'ccp', 'baridimob' => 'baridimob', 'cheque' => 'cheque'];

    public function __construct(private readonly PdfService $pdf) {}

    public function show(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        abort_if($purchaseOrder->status === PurchaseOrder::CANCELLED, 404);

        $purchaseOrder->load(['supplier:id,name,address,phone', 'lines']);
        $locale = app()->getLocale();
        $priced = $purchaseOrder->isFullyPriced();
        $total = $purchaseOrder->total();

        $html = view('pdf.purchase-order', [
            'club' => ClubHeader::data(),
            'rtl' => $locale === 'ar',
            'order' => $purchaseOrder,
            'priced' => $priced,
            'total' => $total,
            // Total and amount in words only when every line has a price.
            'words' => $priced ? AmountInWords::format($total, $locale) : null,
            'method' => $purchaseOrder->payment_mode ? UiLang::get(self::METHOD_KEYS[$purchaseOrder->payment_mode] ?? 'other') : '—',
            'signatories' => BoardSignatories::names(),
        ])->render();

        ActivityRecorder::record($request->user(), ActivityAction::PURCHASE_ORDER_PRINTED, $purchaseOrder, [
            'reference' => $purchaseOrder->reference,
        ]);

        return $this->pdf->stream($html, 'bon-de-commande-'.str_replace('/', '-', $purchaseOrder->reference).'.pdf', $locale === 'ar');
    }
}
```

- [ ] **Step 4: The template**

Create `resources/views/pdf/purchase-order.blade.php`:

```blade
@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ').' '.$club['currency'];
    $supplier = $order->supplier;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; }
        .title { font-size: 20px; font-weight: bold; text-align: center; margin: 6px 0 2px; }
        .ref { text-align: center; font-size: 13px; margin-bottom: 14px; }
        .draft { border: 2px solid #b45309; color: #b45309; font-size: 16px; font-weight: bold; text-align: center; padding: 6px; margin-bottom: 12px; }
        table.info { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.info td { padding: 7px 6px; border: 1px solid #cbd5e1; vertical-align: top; }
        table.info td.label { background: #f8fafc; color: #475569; width: 35%; font-weight: bold; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.lines th { background: #f1f5f9; color: #334155; padding: 6px; border: 1px solid #cbd5e1; font-size: 11px; }
        table.lines td { padding: 6px; border: 1px solid #cbd5e1; vertical-align: top; }
        table.lines td.num { text-align: center; white-space: nowrap; }
        table.lines td.total-label { font-weight: bold; background: #f8fafc; }
        table.lines td.total { font-weight: bold; font-size: 14px; text-align: center; white-space: nowrap; }
        .words { margin: 6px 0 12px; }
    </style>
</head>
<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    @include('pdf.partials.header')

    @if ($order->status === 'draft')
        <div class="draft">{{ $L('po.pdf.draft') }}</div>
    @endif

    <div class="title">{{ $L('pur.pdf.title') }}</div>
    <div class="ref">{{ $L('po.number') }} <bdo dir="ltr">{{ $order->reference }}</bdo> — <bdo dir="ltr">{{ $order->order_date->format('d/m/Y') }}</bdo></div>

    <table class="info">
        <tr><td class="label">{{ $L('pur.supplier') }}</td><td>{{ $supplier?->name ?? '—' }}</td></tr>
        @if ($supplier?->address)
            <tr><td class="label">{{ $L('supplier.field.address') }}</td><td>{{ $supplier->address }}</td></tr>
        @endif
        @if ($supplier?->phone)
            <tr><td class="label">{{ $L('supplier.field.phone') }}</td><td><bdo dir="ltr">{{ $supplier->phone }}</bdo></td></tr>
        @endif
        @if ($order->requested_by)
            <tr><td class="label">{{ $L('pur.requested_by') }}</td><td>{{ $order->requested_by }}</td></tr>
        @endif
        @if ($order->delivery_mode)
            <tr><td class="label">{{ $L('pur.delivery_mode') }}</td><td>{{ $order->delivery_mode }}</td></tr>
        @endif
        <tr><td class="label">{{ $L('pur.payment_mode') }}</td><td>{{ $method }}</td></tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th style="width:6%">#</th>
                <th>{{ $L('designation') }}</th>
                <th style="width:12%">{{ $L('quantity') }}</th>
                <th style="width:20%">{{ $L('unit_price') }}</th>
                <th style="width:20%">{{ $L('amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->lines as $i => $line)
                <tr>
                    <td class="num"><bdo dir="ltr">{{ $i + 1 }}</bdo></td>
                    <td>{{ $line->designation }}</td>
                    <td class="num"><bdo dir="ltr">{{ $line->quantity }}</bdo></td>
                    <td class="num">@if ($line->unit_price !== null)<bdo dir="ltr">{{ $money($line->unit_price) }}</bdo>@endif</td>
                    <td class="num">@if ($line->amount !== null)<bdo dir="ltr">{{ $money($line->amount) }}</bdo>@endif</td>
                </tr>
            @endforeach
            @if ($priced)
                <tr>
                    <td colspan="4" class="total-label">{{ $L('total') }}</td>
                    <td class="total"><bdo dir="ltr">{{ $money($total) }}</bdo></td>
                </tr>
            @endif
        </tbody>
    </table>

    @if ($priced)
        <div class="words"><b>{{ $L('po.pdf.amount_words') }} :</b> {{ $words }}</div>
    @endif

    @if ($order->observation)
        <table class="info">
            <tr><td class="label">{{ $L('po.observation') }}</td><td>{!! nl2br(e($order->observation)) !!}</td></tr>
        </table>
    @endif

    @include('pdf.partials.signatures')
</body>
</html>
```

- [ ] **Step 5: Route**

In `routes/web.php` replace

```php
use App\Http\Controllers\PurchaseOrderController;
```

with

```php
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseOrderPrintController;
```

and replace

```php
    Route::post('/purchase-orders/{purchaseOrder}/payment-order', [PurchaseOrderController::class, 'paymentOrder'])->name('purchase-orders.payment-order.store');
```

with

```php
    Route::post('/purchase-orders/{purchaseOrder}/payment-order', [PurchaseOrderController::class, 'paymentOrder'])->name('purchase-orders.payment-order.store');
    Route::get('/purchase-orders/{purchaseOrder}/print', [PurchaseOrderPrintController::class, 'show'])->name('purchase-orders.print');
```

- [ ] **Step 6: PDF labels**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p2-t6-keys.json`:

```json
{
    "pur.pdf.title": { "ar": "سند طلب", "fr": "BON DE COMMANDE", "en": "PURCHASE ORDER" },
    "pur.supplier": { "ar": "المورد", "fr": "Fournisseur", "en": "Supplier" },
    "pur.requested_by": { "ar": "الجهة الطالبة", "fr": "Demandé par", "en": "Requested by" },
    "pur.delivery_mode": { "ar": "طريقة التسليم", "fr": "Mode de livraison", "en": "Delivery" },
    "pur.payment_mode": { "ar": "طريقة الدفع", "fr": "Mode de paiement", "en": "Payment mode" }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p2-t6-keys.json`
Expected: `✓ 5 key(s) written to ar/en/fr`

- [ ] **Step 7: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderPrintTest.php`
Expected: PASS (6 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PaymentOrderPrintTest.php`
Expected: PASS (shared partials unchanged).
Run: `npm run i18n:check`
Expected: PASS.

(The visual check of the rendered Arabic and French PDFs — mPDF RTL — is Task 9, Step 4, once a migrated database copy exists.)

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/PurchaseOrderPrintController.php resources/views/pdf/purchase-order.blade.php routes/web.php tests/Feature/PurchaseOrderPrintTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(purchase-orders): printed purchase order (ar/fr/en, draft banner, signatures)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 7: Pages, menu and the payment-order form link

**Files:**
- Create: `resources/js/Pages/PurchaseOrders/Index.vue`
- Create: `resources/js/Pages/PurchaseOrders/Create.vue`
- Create: `resources/js/Pages/PurchaseOrders/Edit.vue`
- Create: `resources/js/Pages/PurchaseOrders/Show.vue`
- Create: `resources/js/Pages/PurchaseOrders/Partials/PurchaseOrderForm.vue`
- Modify: `resources/js/Composables/useNavigation.js:38` (Finance section)
- Modify: `resources/js/lib/statusLabels.js:11` (`STATUS_KEYS`)
- Modify: `resources/js/Pages/PaymentOrders/Partials/PaymentOrderForm.vue`, `Create.vue`, `Edit.vue`, `Show.vue`
- Modify: `resources/js/i18n/*.json` (via script)
- Test: `tests/Feature/PurchaseOrderPagesTest.php`

**Interfaces:**
- Consumes: the Inertia props listed in Task 5 and Task 3 (`purchaseOrders`, `order.purchase_order`); routes from Tasks 5–6; `STATUS_KEYS.payment_method` and `payment_order`; components `IconButton`, `Badge`, `Modal`, `SearchableSelect`, `TextInput`, `InputLabel`, `InputError`, `Checkbox`, `PrimaryButton`, `SecondaryButton`, `DangerButton`; composables `useCan`, `useFormatMoney`, `useFormatDate`, `useStatusLabel`; `localToday` from `@/lib/localDate`.
- Produces: `STATUS_KEYS.purchase_order` (`draft|sent|received|cancelled` → `pur.status.*`); sidebar entry Finance → Purchase orders (module `purchase_orders`, icon `clipboard`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PurchaseOrderPagesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchaseOrderPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Supplier $supplier;

    private EquipmentCatalog $balls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->admin = User::factory()->admin()->create();
        $this->supplier = Supplier::create(['name' => 'Test Sport Shop', 'rib' => '00100000000000001234']);
        $this->balls = EquipmentCatalog::create(['name' => 'Ballon taille 5', 'category' => 'Balls']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order()
    {
        return app(PurchaseOrderService::class)->create([
            'order_date' => '2026-11-25', 'supplier_id' => $this->supplier->id, 'payment_mode' => 'bank',
            'lines' => [
                ['equipment_catalog_id' => $this->balls->id, 'designation' => 'Ballon taille 5', 'quantity' => 10, 'unit_price' => 1500],
                ['equipment_catalog_id' => null, 'designation' => 'Gonfleur', 'quantity' => 2, 'unit_price' => 750],
            ],
        ], $this->admin);
    }

    #[Test]
    public function the_register_and_the_create_page_render(): void
    {
        $this->order();

        $this->actingAs($this->admin)->get(route('purchase-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('PurchaseOrders/Index')->has('orders.data', 1));
        $this->get(route('purchase-orders.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('PurchaseOrders/Create')
                ->has('suppliers', 1)->has('catalogs', 1)
                ->where('catalogs.0.name', 'Ballon taille 5'));
    }

    #[Test]
    public function a_draft_opens_the_full_form_and_a_sent_order_the_price_form(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)->get(route('purchase-orders.edit', $order))
            ->assertInertia(fn (Assert $page) => $page->component('PurchaseOrders/Edit')
                ->where('pricesOnly', false)->has('order.lines', 2));

        app(PurchaseOrderService::class)->send($order, $this->admin);
        $this->get(route('purchase-orders.edit', $order))
            ->assertInertia(fn (Assert $page) => $page->component('PurchaseOrders/Edit')->where('pricesOnly', true));
    }

    #[Test]
    public function the_show_page_lists_lines_total_and_its_payment_order(): void
    {
        $service = app(PurchaseOrderService::class);
        $order = $service->receive($service->send($this->order(), $this->admin), $this->admin, '2026-11-27');
        $service->createPaymentOrder($order, $this->admin);

        $this->actingAs($this->admin)->get(route('purchase-orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->component('PurchaseOrders/Show')
                ->has('order.lines', 2)
                ->where('order.total', fn ($total) => (float) $total === 16500.0)
                ->where('order.payment_orders.0.reference', '01/2026')
                ->where('order.payment_orders.0.status', 'draft')
                ->where('can.create_payment_order', false)
                ->where('can.edit', false));
    }

    #[Test]
    public function the_payment_order_form_offers_the_received_orders(): void
    {
        $service = app(PurchaseOrderService::class);
        $order = $service->receive($service->send($this->order(), $this->admin), $this->admin, '2026-11-27');

        $this->actingAs($this->admin)->get(route('payment-orders.create'))
            ->assertInertia(fn (Assert $page) => $page->component('PaymentOrders/Create')
                ->where('purchaseOrders.0.id', $order->id)
                ->where('purchaseOrders.0.supplier_id', $this->supplier->id));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderPagesTest.php`
Expected: FAIL (`Inertia page component file [PurchaseOrders/Index] does not exist`).

- [ ] **Step 3: Status labels and menu entry**

In `resources/js/lib/statusLabels.js` replace

```js
    payment_order: { draft: 'po.status.draft', approved: 'po.status.approved', paid: 'po.status.paid', cancelled: 'po.status.cancelled' },
```

with

```js
    payment_order: { draft: 'po.status.draft', approved: 'po.status.approved', paid: 'po.status.paid', cancelled: 'po.status.cancelled' },
    purchase_order: { draft: 'pur.status.draft', sent: 'pur.status.sent', received: 'pur.status.received', cancelled: 'pur.status.cancelled' },
```

In `resources/js/Composables/useNavigation.js` replace

```js
                { label: t('payment_orders'), href: '/payment-orders', icon: 'document', prefix: '/payment-orders', module: 'payment_orders' },
```

with

```js
                { label: t('payment_orders'), href: '/payment-orders', icon: 'document', prefix: '/payment-orders', module: 'payment_orders' },
                { label: t('purchase_orders'), href: '/purchase-orders', icon: 'clipboard', prefix: '/purchase-orders', module: 'purchase_orders' },
```

- [ ] **Step 4: Register page**

Create `resources/js/Pages/PurchaseOrders/Index.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Badge from '@/Components/Badge.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFormatDate } from '@/Composables/useFormatDate';
import { useStatusLabel } from '@/Composables/useStatusLabel';

const { t } = useI18n();
const { can } = useCan();
const { formatMoney } = useFormatMoney();
const { formatDate } = useFormatDate();
const { statusLabel } = useStatusLabel();

const props = defineProps({ orders: Object, filters: Object, years: Array, suppliers: Array });

const filters = reactive({
    year: props.filters.year ?? '', status: props.filters.status ?? '',
    supplier: props.filters.supplier ?? '', q: props.filters.q ?? '',
});
let timer = null;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('purchase-orders.index'),
        Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')), { preserveState: true, replace: true }), 300);
});

const STATUS_COLOR = { draft: 'slate', sent: 'blue', received: 'emerald', cancelled: 'rose' };
</script>

<template>
    <Head :title="t('purchase_orders')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('purchase_orders') }}</h1>
                <IconButton v-if="can('purchase_orders', 'add')" :href="route('purchase-orders.create')" icon="plus" :label="t('pur.new')" variant="primary" />
            </div>
        </template>

        <div class="space-y-4">
            <div class="grid grid-cols-1 gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:grid-cols-2 lg:grid-cols-5 dark:bg-slate-900 dark:ring-slate-800">
                <TextInput v-model="filters.q" class="sm:col-span-2" :placeholder="t('pur.find_placeholder')" />
                <select v-model="filters.year" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <option value="">{{ t('po.all_years') }}</option>
                    <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                </select>
                <select v-model="filters.status" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <option value="">{{ t('po.all_statuses') }}</option>
                    <option v-for="s in ['draft', 'sent', 'received', 'cancelled']" :key="s" :value="s">{{ statusLabel('purchase_order', s) }}</option>
                </select>
                <select v-model="filters.supplier" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <option value="">{{ t('po.all_suppliers') }}</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3 text-start">{{ t('po.number') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('date') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('pur.supplier') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('pur.requested_by') }}</th>
                            <th class="px-4 py-3 text-end">{{ t('total') }}</th>
                            <th class="px-4 py-3 text-center">{{ t('status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="o in orders.data" :key="o.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-4 py-3 font-mono">
                                <Link :href="route('purchase-orders.show', o.id)" class="text-primary-600 hover:underline dark:text-primary-300"><bdi dir="ltr">{{ o.reference }}</bdi></Link>
                                <span v-if="o.is_legacy" class="ms-1 rounded bg-slate-100 px-1 font-sans text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('po.legacy') }}</span>
                            </td>
                            <td class="px-4 py-3"><bdi dir="ltr">{{ formatDate(o.order_date) }}</bdi></td>
                            <td class="px-4 py-3 text-slate-900 dark:text-slate-100">{{ o.supplier || '—' }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ o.requested_by }}</td>
                            <td class="px-4 py-3 text-end tabular-nums">
                                <bdi v-if="o.total !== null" dir="ltr">{{ formatMoney(o.total) }}</bdi>
                                <span v-else class="text-xs text-slate-400 dark:text-slate-500">{{ t('pur.unpriced') }}</span>
                            </td>
                            <td class="px-4 py-3 text-center"><Badge :label="statusLabel('purchase_order', o.status)" :color="STATUS_COLOR[o.status]" /></td>
                        </tr>
                        <tr v-if="!orders.data.length"><td colspan="6" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td></tr>
                    </tbody>
                </table>
            </div>

            <div v-if="orders.last_page > 1" class="flex flex-wrap justify-center gap-1">
                <Link v-for="link in orders.links" :key="link.label" :href="link.url || ''" v-html="link.label" preserve-state
                    :class="['rounded px-3 py-1 text-sm', link.active ? 'bg-primary-600 text-white' : 'text-slate-600 dark:text-slate-300', !link.url && 'pointer-events-none opacity-40']" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 5: Form partial (draft form and price-only form)**

Create `resources/js/Pages/PurchaseOrders/Partials/PurchaseOrderForm.vue`:

```vue
<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import IconButton from '@/Components/IconButton.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useStatusLabel } from '@/Composables/useStatusLabel';
import { localToday } from '@/lib/localDate';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();
const { statusLabel } = useStatusLabel();
const props = defineProps({
    order: { type: Object, default: null },
    // Sent or received: supplier, designations and quantities are locked; only unit prices change.
    pricesOnly: { type: Boolean, default: false },
    suppliers: { type: Array, default: () => [] },
    catalogs: { type: Array, default: () => [] },
});
const isEdit = !!props.order;
const day = (value) => (value ? String(value).slice(0, 10) : '');
const METHODS = ['bank', 'ccp', 'cheque', 'cash', 'baridimob', 'other'];

let nextKey = 0;
const makeLine = (l = {}) => ({
    key: nextKey++,
    id: l.id ?? null,
    equipment_catalog_id: l.equipment_catalog_id ?? '',
    designation: l.designation ?? '',
    quantity: l.quantity ?? 1,
    unit_price: l.unit_price ?? '',
});

const form = useForm({
    order_date: day(props.order?.order_date) || localToday(),
    supplier_id: props.order?.supplier_id ?? '',
    requested_by: props.order?.requested_by ?? '',
    delivery_mode: props.order?.delivery_mode ?? '',
    payment_mode: props.order?.payment_mode ?? '',
    observation: props.order?.observation ?? '',
    lines: (props.order?.lines?.length ? props.order.lines : [{}]).map(makeLine),
});

const supplierOptions = computed(() => props.suppliers.map((s) => ({
    value: s.id, label: s.name, description: [s.address, s.phone].filter(Boolean).join(' · '),
})));
const catalogOptions = computed(() => props.catalogs.map((c) => ({ value: c.id, label: c.name })));
const catalogName = (id) => props.catalogs.find((c) => String(c.id) === String(id))?.name ?? '';

// Picking a catalog item fills the designation, unless the user typed their own.
function pickCatalog(row, id) {
    const previous = catalogName(row.equipment_catalog_id);
    row.equipment_catalog_id = id;
    if (id && (row.designation === '' || row.designation === previous)) row.designation = catalogName(id);
}
const addLine = () => form.lines.push(makeLine());
const removeLine = (index) => form.lines.splice(index, 1);
const amount = (row) => (row.unit_price === '' || row.unit_price === null ? null : Number(row.quantity || 0) * Number(row.unit_price));
const total = computed(() => (form.lines.every((r) => amount(r) !== null) ? form.lines.reduce((sum, r) => sum + amount(r), 0) : null));
const lineError = (index, field) => form.errors[`lines.${index}.${field}`];
const blank = (v) => (v === '' ? null : v);

function submit() {
    if (props.pricesOnly) {
        form.transform((data) => ({ lines: data.lines.map((r) => ({ id: r.id, unit_price: blank(r.unit_price) })) }));
    } else {
        form.transform((data) => ({
            order_date: data.order_date,
            supplier_id: blank(data.supplier_id),
            requested_by: blank(data.requested_by),
            delivery_mode: blank(data.delivery_mode),
            payment_mode: blank(data.payment_mode),
            observation: blank(data.observation),
            lines: data.lines.map((r) => ({
                equipment_catalog_id: blank(r.equipment_catalog_id),
                designation: r.designation,
                quantity: r.quantity,
                unit_price: blank(r.unit_price),
            })),
        }));
    }
    isEdit ? form.put(route('purchase-orders.update', props.order.id)) : form.post(route('purchase-orders.store'));
}
</script>

<template>
    <form @submit.prevent="submit" class="mx-auto max-w-4xl space-y-6">
        <p v-if="pricesOnly" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ t('pur.prices_only_hint') }}</p>

        <div class="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <InputLabel :value="t('pur.supplier')" />
                    <SearchableSelect v-model="form.supplier_id" :options="supplierOptions" :placeholder="t('pur.choose_supplier')" :disabled="pricesOnly" class="mt-1" />
                    <InputError :message="form.errors.supplier_id" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('date')" />
                    <TextInput v-model="form.order_date" type="date" required :disabled="pricesOnly" class="mt-1 w-full" />
                    <InputError :message="form.errors.order_date" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('pur.requested_by')" />
                    <TextInput v-model="form.requested_by" maxlength="200" :disabled="pricesOnly" class="mt-1 w-full" />
                    <InputError :message="form.errors.requested_by" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('pur.delivery_mode')" />
                    <TextInput v-model="form.delivery_mode" maxlength="200" :disabled="pricesOnly" class="mt-1 w-full" />
                    <InputError :message="form.errors.delivery_mode" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('pur.payment_mode')" />
                    <select v-model="form.payment_mode" :disabled="pricesOnly" class="mt-1 w-full rounded-lg border-slate-300 shadow-sm disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900">
                        <option value="">—</option>
                        <option v-for="m in METHODS" :key="m" :value="m">{{ statusLabel('payment_method', m) }}</option>
                    </select>
                    <InputError :message="form.errors.payment_mode" class="mt-1" />
                </div>
                <div class="sm:col-span-3">
                    <InputLabel :value="t('po.observation')" />
                    <textarea v-model="form.observation" rows="2" maxlength="2000" :disabled="pricesOnly"
                        class="mt-1 w-full rounded-lg border-slate-300 shadow-sm disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900"></textarea>
                </div>
            </div>
        </div>

        <div class="space-y-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-6 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('pur.lines') }}</h2>
            <InputError :message="form.errors.lines" />
            <div v-for="(row, index) in form.lines" :key="row.key"
                class="grid grid-cols-1 gap-2 rounded-xl border border-slate-200 p-3 sm:grid-cols-12 sm:items-end dark:border-slate-700">
                <div class="sm:col-span-4">
                    <InputLabel :value="t('pur.catalog_item')" />
                    <SearchableSelect :model-value="row.equipment_catalog_id" :options="catalogOptions" :placeholder="t('pur.catalog_item')"
                        :disabled="pricesOnly" class="mt-1" @update:model-value="pickCatalog(row, $event)" />
                </div>
                <div class="sm:col-span-4">
                    <InputLabel :value="t('designation')" />
                    <TextInput v-model="row.designation" maxlength="255" required :disabled="pricesOnly" class="mt-1 w-full" />
                    <InputError :message="lineError(index, 'designation')" class="mt-1" />
                </div>
                <div class="sm:col-span-1">
                    <InputLabel :value="t('quantity')" />
                    <TextInput v-model="row.quantity" type="number" min="1" step="1" required :disabled="pricesOnly" class="mt-1 w-full" />
                    <InputError :message="lineError(index, 'quantity')" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <InputLabel :value="t('unit_price')" />
                    <TextInput v-model="row.unit_price" type="number" min="0" step="0.01" class="mt-1 w-full" />
                    <InputError :message="lineError(index, 'unit_price')" class="mt-1" />
                </div>
                <div class="flex items-center justify-between gap-2 sm:col-span-1 sm:justify-end">
                    <span class="text-sm tabular-nums text-slate-600 sm:hidden dark:text-slate-300">
                        <bdi v-if="amount(row) !== null" dir="ltr">{{ formatMoney(amount(row)) }}</bdi>
                    </span>
                    <IconButton v-if="!pricesOnly && form.lines.length > 1" icon="trash" :label="t('remove')" variant="danger" size="sm" @click="removeLine(index)" />
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <IconButton v-if="!pricesOnly" icon="plus" :label="t('pur.add_line')" @click="addLine" />
                <p class="ms-auto text-sm font-semibold text-slate-900 dark:text-slate-100">
                    {{ t('total') }}:
                    <bdi v-if="total !== null" dir="ltr" class="tabular-nums">{{ formatMoney(total) }}</bdi>
                    <span v-else class="text-xs font-normal text-slate-500 dark:text-slate-400">{{ t('pur.unpriced') }}</span>
                </p>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="isEdit ? route('purchase-orders.show', order.id) : route('purchase-orders.index')"><SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton></Link>
            <PrimaryButton :disabled="form.processing">{{ isEdit ? t('save_changes') : t('po.save_draft') }}</PrimaryButton>
        </div>
    </form>
</template>
```

- [ ] **Step 6: Create and Edit pages**

Create `resources/js/Pages/PurchaseOrders/Create.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PurchaseOrderForm from './Partials/PurchaseOrderForm.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({ suppliers: Array, catalogs: Array });
</script>

<template>
    <Head :title="t('pur.new')" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('pur.new') }}</h1></template>
        <PurchaseOrderForm :suppliers="suppliers" :catalogs="catalogs" />
    </AuthenticatedLayout>
</template>
```

Create `resources/js/Pages/PurchaseOrders/Edit.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PurchaseOrderForm from './Partials/PurchaseOrderForm.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({ order: Object, pricesOnly: Boolean, suppliers: Array, catalogs: Array });
</script>

<template>
    <Head :title="t('pur.title') + ' ' + order.reference" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('pur.title') }} <bdi dir="ltr">{{ order.reference }}</bdi></h1></template>
        <PurchaseOrderForm :order="order" :prices-only="pricesOnly" :suppliers="suppliers" :catalogs="catalogs" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 7: Show page (actions, receive dialog, links)**

Create `resources/js/Pages/PurchaseOrders/Show.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFormatDate } from '@/Composables/useFormatDate';
import { useStatusLabel } from '@/Composables/useStatusLabel';
import { localToday } from '@/lib/localDate';

const { t } = useI18n();
const { can: userCan } = useCan();
const { formatMoney } = useFormatMoney();
const { formatDate } = useFormatDate();
const { statusLabel } = useStatusLabel();
const props = defineProps({ order: Object, can: Object });

const STATUS_COLOR = { draft: 'slate', sent: 'blue', received: 'emerald', cancelled: 'rose' };
const PAYMENT_COLOR = { draft: 'slate', approved: 'amber', paid: 'emerald', cancelled: 'rose' };
const receiveOpen = ref(false);
const cancelOpen = ref(false);
const receiveForm = useForm({ received_date: localToday(), to_stock: false, payment_order: false });

const rows = computed(() => [
    { label: t('date'), value: formatDate(props.order.order_date), ltr: true },
    { label: t('pur.supplier'), value: props.order.supplier?.name },
    { label: t('supplier.field.address'), value: props.order.supplier?.address },
    { label: t('supplier.field.phone'), value: props.order.supplier?.phone, ltr: true },
    { label: t('pur.requested_by'), value: props.order.requested_by },
    { label: t('pur.delivery_mode'), value: props.order.delivery_mode },
    { label: t('pur.payment_mode'), value: props.order.payment_mode ? statusLabel('payment_method', props.order.payment_mode) : null },
    { label: t('pur.received_date'), value: props.order.received_at ? formatDate(props.order.received_at) : null, ltr: true },
    { label: t('po.observation'), value: props.order.observation, multiline: true },
].filter((row) => row.value));

const send = () => router.post(route('purchase-orders.send', props.order.id), {}, { preserveScroll: true });
const receive = () => receiveForm.post(route('purchase-orders.receive', props.order.id), {
    preserveScroll: true, onSuccess: () => { receiveOpen.value = false; },
});
const cancel = () => router.post(route('purchase-orders.cancel', props.order.id), {}, {
    preserveScroll: true, onFinish: () => { cancelOpen.value = false; },
});
const createPaymentOrder = () => router.post(route('purchase-orders.payment-order.store', props.order.id));
</script>

<template>
    <Head :title="t('pur.title') + ' ' + order.reference" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('pur.title') }} <bdi dir="ltr">{{ order.reference }}</bdi></h1>
                    <Badge :label="statusLabel('purchase_order', order.status)" :color="STATUS_COLOR[order.status]" />
                    <Badge v-if="order.is_legacy" :label="t('po.legacy')" color="slate" />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <IconButton v-if="can.print" :href="route('purchase-orders.print', order.id)" external target="_blank" icon="print" :label="t('pur.print')" />
                    <IconButton v-if="can.edit" :href="route('purchase-orders.edit', order.id)" icon="pencil" :label="t('edit')" />
                    <IconButton v-if="can.send" icon="send" :label="t('pur.send')" variant="primary" @click="send" />
                    <IconButton v-if="can.receive" icon="check" :label="t('pur.receive')" variant="primary" @click="receiveOpen = true" />
                    <IconButton v-if="can.create_payment_order" icon="money" :label="t('pur.create_payment_order')" variant="primary" @click="createPaymentOrder" />
                    <IconButton v-if="can.cancel" icon="xcircle" :label="t('pur.cancel_order')" variant="danger" @click="cancelOpen = true" />
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-6">
            <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div v-for="row in rows" :key="row.label" :class="row.multiline && 'sm:col-span-2'">
                        <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ row.label }}</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100" :class="row.multiline && 'whitespace-pre-line'"><bdi :dir="row.ltr ? 'ltr' : undefined">{{ row.value }}</bdi></dd>
                    </div>
                </dl>
                <p v-if="order.status === 'draft'" class="mt-5 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ t('pur.draft_hint') }}</p>
                <p v-else-if="can.edit" class="mt-5 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ t('pur.prices_only_hint') }}</p>
            </div>

            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3 text-start">{{ t('designation') }}</th>
                            <th class="px-4 py-3 text-center">{{ t('quantity') }}</th>
                            <th class="px-4 py-3 text-end">{{ t('unit_price') }}</th>
                            <th class="px-4 py-3 text-end">{{ t('amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="line in order.lines" :key="line.id">
                            <td class="px-4 py-3 text-slate-900 dark:text-slate-100">
                                {{ line.designation }}
                                <span v-if="line.catalog" class="ms-1 rounded bg-primary-50 px-1 text-xs text-primary-700 dark:bg-primary-500/15 dark:text-primary-300">{{ line.catalog.name }}</span>
                            </td>
                            <td class="px-4 py-3 text-center tabular-nums"><bdi dir="ltr">{{ line.quantity }}</bdi></td>
                            <td class="px-4 py-3 text-end tabular-nums">
                                <bdi v-if="line.unit_price !== null" dir="ltr">{{ formatMoney(line.unit_price) }}</bdi>
                                <span v-else class="text-xs text-slate-400 dark:text-slate-500">{{ t('pur.unpriced') }}</span>
                            </td>
                            <td class="px-4 py-3 text-end tabular-nums"><bdi v-if="line.amount !== null" dir="ltr">{{ formatMoney(line.amount) }}</bdi></td>
                        </tr>
                    </tbody>
                    <tfoot v-if="order.fully_priced" class="bg-slate-50 text-sm font-semibold dark:bg-slate-950">
                        <tr><td colspan="3" class="px-4 py-3">{{ t('total') }}</td><td class="px-4 py-3 text-end tabular-nums"><bdi dir="ltr">{{ formatMoney(order.total) }}</bdi></td></tr>
                    </tfoot>
                </table>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                    <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('payment_orders') }}</h2>
                    <ul v-if="order.payment_orders.length" class="mt-3 space-y-2 text-sm">
                        <li v-for="p in order.payment_orders" :key="p.id" class="flex flex-wrap items-center gap-2">
                            <Link v-if="userCan('payment_orders', 'view')" :href="route('payment-orders.show', p.id)" class="font-semibold text-primary-600 hover:underline dark:text-primary-300"><bdi dir="ltr">{{ p.reference }}</bdi></Link>
                            <bdi v-else dir="ltr" class="font-semibold">{{ p.reference }}</bdi>
                            <span class="tabular-nums text-slate-600 dark:text-slate-300"><bdi dir="ltr">{{ formatMoney(p.amount) }}</bdi></span>
                            <Badge :label="statusLabel('payment_order', p.status)" :color="PAYMENT_COLOR[p.status]" />
                        </li>
                    </ul>
                    <p v-else class="mt-3 text-sm text-slate-500 dark:text-slate-400">—</p>
                    <p v-if="order.status === 'received' && !order.fully_priced && !order.is_legacy" class="mt-3 text-xs text-amber-700 dark:text-amber-300">{{ t('pur.needs_prices') }}</p>
                </div>
                <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                    <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('pur.equipment_units') }}</h2>
                    <p class="mt-3 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100"><bdi dir="ltr">{{ order.equipment_units }}</bdi></p>
                </div>
            </div>
        </div>

        <Modal :show="receiveOpen" @close="receiveOpen = false" max-width="md">
            <form @submit.prevent="receive" class="space-y-4 p-6">
                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('pur.receive') }}</h3>
                <div>
                    <InputLabel :value="t('pur.received_date')" />
                    <TextInput v-model="receiveForm.received_date" type="date" required class="mt-1 w-full" />
                    <InputError :message="receiveForm.errors.received_date" class="mt-1" />
                </div>
                <label v-if="can.receive_stock" class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-200">
                    <Checkbox v-model:checked="receiveForm.to_stock" class="mt-0.5" />
                    <span>{{ t('pur.to_stock') }}<span class="block text-xs text-slate-500 dark:text-slate-400">{{ t('pur.to_stock_hint') }}</span></span>
                </label>
                <label v-if="can.payment_order_on_receive" class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-200">
                    <Checkbox v-model:checked="receiveForm.payment_order" class="mt-0.5" />
                    <span>{{ t('pur.with_payment_order') }}</span>
                </label>
                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" @click="receiveOpen = false">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="receiveForm.processing">{{ t('pur.receive') }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <Modal :show="cancelOpen" @close="cancelOpen = false" max-width="md">
            <div class="space-y-4 p-6">
                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('pur.cancel_order') }}</h3>
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('pur.cancel_confirm') }}</p>
                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" @click="cancelOpen = false">{{ t('back') }}</SecondaryButton>
                    <DangerButton type="button" @click="cancel">{{ t('pur.cancel_order') }}</DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 8: Payment-order form — pick a received purchase order**

In `resources/js/Pages/PaymentOrders/Partials/PaymentOrderForm.vue`:

Replace

```js
import { useFinanceAccountLabel } from '@/Composables/useFinanceAccountLabel';
```

with

```js
import { useFinanceAccountLabel } from '@/Composables/useFinanceAccountLabel';
import { useFormatMoney } from '@/Composables/useFormatMoney';
```

Replace

```js
    boardMeetings: { type: Array, default: () => [] },
});
const { accountLabel } = useFinanceAccountLabel();
```

with

```js
    boardMeetings: { type: Array, default: () => [] },
    // Received purchase orders without an active payment order: [{ id, reference, supplier_id, total }].
    purchaseOrders: { type: Array, default: () => [] },
});
const { accountLabel } = useFinanceAccountLabel();
const { formatMoney } = useFormatMoney();
```

Replace

```js
    observation: props.order?.observation || '',
});
```

with

```js
    observation: props.order?.observation || '',
    purchase_order_id: props.order?.purchase_order_id || '',
});
```

Replace

```js
watch(() => form.beneficiary_type, () => {
    form.beneficiary_id = '';
    form.beneficiary_bank = '';
    form.beneficiary_rib = '';
});
```

with

```js
watch(() => form.beneficiary_type, () => {
    form.beneficiary_id = '';
    form.beneficiary_bank = '';
    form.beneficiary_rib = '';
});

// The chosen supplier's received purchase orders that have no payment order yet.
const purchaseOrderOptions = computed(() => (form.beneficiary_type === 'supplier' && form.beneficiary_id
    ? props.purchaseOrders.filter((p) => String(p.supplier_id) === String(form.beneficiary_id))
    : []));
// Another supplier (or another kind of beneficiary): the previous one's purchase order no longer applies.
watch(() => [form.beneficiary_type, form.beneficiary_id], () => {
    if (form.purchase_order_id && !purchaseOrderOptions.value.some((p) => String(p.id) === String(form.purchase_order_id))) {
        form.purchase_order_id = '';
    }
});
```

Replace

```vue
                <div class="sm:col-span-2"><InputLabel :value="t('supplier.field.rib')" /><TextInput v-model="form.beneficiary_rib" dir="ltr" class="mt-1 w-full font-mono" /><InputError :message="form.errors.beneficiary_rib" class="mt-1" /></div>
```

with

```vue
                <div class="sm:col-span-2"><InputLabel :value="t('supplier.field.rib')" /><TextInput v-model="form.beneficiary_rib" dir="ltr" class="mt-1 w-full font-mono" /><InputError :message="form.errors.beneficiary_rib" class="mt-1" /></div>
                <div v-if="purchaseOrderOptions.length || form.errors.purchase_order_id" class="sm:col-span-3">
                    <InputLabel :value="t('pur.purchase_order_field')" />
                    <select v-model="form.purchase_order_id" class="mt-1 w-full rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <option value="">—</option>
                        <option v-for="p in purchaseOrderOptions" :key="p.id" :value="p.id">{{ p.reference }} · {{ formatMoney(p.total) }}</option>
                    </select>
                    <InputError :message="form.errors.purchase_order_id" class="mt-1" />
                </div>
```

In `resources/js/Pages/PaymentOrders/Create.vue` replace

```js
    users: Array, boardMembers: Array, boardMeetings: Array,
});
```

with

```js
    users: Array, boardMembers: Array, boardMeetings: Array, purchaseOrders: Array,
});
```

and replace

```vue
            :users="users" :board-members="boardMembers" :board-meetings="boardMeetings" />
```

with

```vue
            :users="users" :board-members="boardMembers" :board-meetings="boardMeetings" :purchase-orders="purchaseOrders" />
```

In `resources/js/Pages/PaymentOrders/Edit.vue` replace

```js
    users: Array, boardMembers: Array, boardMeetings: Array,
});
```

with

```js
    users: Array, boardMembers: Array, boardMeetings: Array, purchaseOrders: Array,
});
```

and replace

```vue
            :users="users" :board-members="boardMembers" :board-meetings="boardMeetings" />
```

with

```vue
            :users="users" :board-members="boardMembers" :board-meetings="boardMeetings" :purchase-orders="purchaseOrders" />
```

In `resources/js/Pages/PaymentOrders/Show.vue` replace

```vue
                <p v-if="order.transaction" class="mt-5 text-sm">
```

with

```vue
                <p v-if="order.purchase_order" class="mt-5 text-sm">
                    {{ t('pur.linked_purchase_order') }}
                    <Link :href="route('purchase-orders.show', order.purchase_order.id)" class="font-semibold text-primary-600 hover:underline dark:text-primary-300"><bdi dir="ltr">{{ order.purchase_order.reference }}</bdi></Link>
                </p>
                <p v-if="order.transaction" class="mt-5 text-sm">
```

- [ ] **Step 9: Page translations**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p2-t7-keys.json`:

```json
{
    "pur.new": { "ar": "سند طلب جديد", "fr": "Nouveau bon de commande", "en": "New purchase order" },
    "pur.find_placeholder": { "ar": "الرقم أو المورد أو المادة", "fr": "N°, fournisseur ou article", "en": "No., supplier or item" },
    "pur.choose_supplier": { "ar": "اختر المورد", "fr": "Choisir le fournisseur", "en": "Choose the supplier" },
    "pur.lines": { "ar": "المواد المطلوبة", "fr": "Articles commandés", "en": "Items ordered" },
    "pur.catalog_item": { "ar": "مادة من الكتالوج (اختياري)", "fr": "Article du catalogue (facultatif)", "en": "Catalog item (optional)" },
    "pur.add_line": { "ar": "إضافة سطر", "fr": "Ajouter une ligne", "en": "Add a line" },
    "pur.unpriced": { "ar": "غير مسعّر", "fr": "Non chiffré", "en": "Not priced" },
    "pur.prices_only_hint": { "ar": "الطلب مُرسَل: يمكن تعديل أسعار الوحدة فقط، إلى أن يُربط به أمر دفع.", "fr": "Commande envoyée : seuls les prix unitaires peuvent changer, jusqu'à ce qu'un ordre de paiement soit lié.", "en": "Order sent: only unit prices can change, until a payment order is linked." },
    "pur.draft_hint": { "ar": "مسودة: كل شيء قابل للتعديل. بعد الإرسال يُقفل المورد والمواد والكميات.", "fr": "Brouillon : tout est modifiable. Une fois envoyé, fournisseur, articles et quantités sont verrouillés.", "en": "Draft: everything can be edited. Once sent, the supplier, items and quantities are locked." },
    "pur.needs_prices": { "ar": "أدخل سعر كل سطر لإنشاء أمر الدفع.", "fr": "Saisissez le prix de chaque ligne pour créer l'ordre de paiement.", "en": "Enter every line's price to create the payment order." },
    "pur.send": { "ar": "تسجيل الإرسال", "fr": "Marquer envoyé", "en": "Mark as sent" },
    "pur.receive": { "ar": "تسجيل الاستلام", "fr": "Marquer reçu", "en": "Mark as received" },
    "pur.received_date": { "ar": "تاريخ الاستلام", "fr": "Date de réception", "en": "Received on" },
    "pur.to_stock": { "ar": "إدخال مواد الكتالوج إلى المخزون", "fr": "Mettre les articles du catalogue en stock", "en": "Put catalog items into stock" },
    "pur.to_stock_hint": { "ar": "سطور الكتالوج فقط، دون تسجيل مصروف: يُسجَّل المبلغ عند دفع أمر الدفع.", "fr": "Lignes du catalogue uniquement, sans dépense : l'argent est enregistré au paiement de l'ordre de paiement.", "en": "Catalog lines only, with no expense: the money is recorded when the payment order is paid." },
    "pur.with_payment_order": { "ar": "إنشاء أمر دفع (مسودة)", "fr": "Créer l'ordre de paiement (brouillon)", "en": "Create the payment order (draft)" },
    "pur.create_payment_order": { "ar": "إنشاء أمر دفع", "fr": "Créer l'ordre de paiement", "en": "Create payment order" },
    "pur.cancel_order": { "ar": "إلغاء السند", "fr": "Annuler le bon", "en": "Cancel order" },
    "pur.cancel_confirm": { "ar": "إلغاء سند الطلب هذا؟ يحتفظ برقمه.", "fr": "Annuler ce bon de commande ? Il garde son numéro.", "en": "Cancel this purchase order? It keeps its number." },
    "pur.print": { "ar": "طباعة سند الطلب", "fr": "Imprimer le bon de commande", "en": "Print purchase order" },
    "pur.equipment_units": { "ar": "وحدات أُدخلت إلى المخزون", "fr": "Unités entrées en stock", "en": "Units put into stock" },
    "pur.linked_purchase_order": { "ar": "سند الطلب:", "fr": "Bon de commande :", "en": "Purchase order:" },
    "pur.purchase_order_field": { "ar": "سند طلب مستلَم (اختياري)", "fr": "Bon de commande reçu (facultatif)", "en": "Received purchase order (optional)" },
    "pur.status.draft": { "ar": "مسودة", "fr": "Brouillon", "en": "Draft" },
    "pur.status.sent": { "ar": "مُرسَل", "fr": "Envoyé", "en": "Sent" },
    "pur.status.received": { "ar": "مُستلَم", "fr": "Reçu", "en": "Received" },
    "pur.status.cancelled": { "ar": "ملغى", "fr": "Annulé", "en": "Cancelled" }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p2-t7-keys.json`
Expected: `✓ 27 key(s) written to ar/en/fr`

- [ ] **Step 10: Build, check and run the tests**

Run: `npm run build`
Expected: build succeeds (no missing import).
Run: `npm run i18n:check`
Expected: PASS (every template key, the new `purchase_order` status domain and all flash keys resolve).
Run: `npm run test:js`
Expected: PASS (`statusLabels`, `navI18n`, `navigation` tests still green).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderPagesTest.php`
Expected: PASS (4 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PaymentOrderPagesTest.php`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add resources/js/Pages/PurchaseOrders/Index.vue resources/js/Pages/PurchaseOrders/Create.vue resources/js/Pages/PurchaseOrders/Edit.vue resources/js/Pages/PurchaseOrders/Show.vue resources/js/Pages/PurchaseOrders/Partials/PurchaseOrderForm.vue resources/js/Composables/useNavigation.js resources/js/lib/statusLabels.js resources/js/Pages/PaymentOrders/Partials/PaymentOrderForm.vue resources/js/Pages/PaymentOrders/Create.vue resources/js/Pages/PaymentOrders/Edit.vue resources/js/Pages/PaymentOrders/Show.vue tests/Feature/PurchaseOrderPagesTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(purchase-orders): register, form, order page, menu entry and payment-order link

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

(`public/build` is not committed — check `git status` shows no other staged path.)

---

### Task 8: Import of the old Access purchase order

**Files:**
- Modify: `scripts/legacy/export-access.ps1` (keep its UTF-8 BOM — PowerShell 5.1 needs it for the accented column names)
- Modify: `app/Services/Legacy/LegacyAccessImporter.php`
- Modify: `tests/Fixtures/legacy-access.json`
- Modify: `resources/js/i18n/*.json` (via script)
- Test: `tests/Feature/LegacyAccessImportTest.php` (add tests)

**Interfaces:**
- Consumes: `PurchaseOrder`, `PurchaseOrderLine::amountFor()` (Task 2); importer helpers `plain()`, `date()`, `text()` (P1).
- Produces: optional JSON section `purchase_orders` (format id stays `access-legacy-v1`): list of `{reference, date, supplier, address, requested_by, delivery_mode, payment_mode, order_number, observation, lines: [{designation, reference, quantity, unit_price}]}`; import result key `purchase_orders` `{created, skipped}`; `LEGACY_DATA_IMPORTED` property `purchase_orders` (created count); legacy rows `legacy_source = 'purchase'`, `status = received`, `number = null`; i18n key `legacy_import.section.purchase_orders`.
- Not in this plan: running the real export against the `.accdb` (done separately).

- [ ] **Step 1: Fixture section**

In `tests/Fixtures/legacy-access.json` replace

```json
        { "source": "cash", "reference": "03", "date": "2023-12-23", "subject": "Cotisation des membres", "amount": 20000, "beneficiary": "Test Trésorier", "bank": "", "rib": "", "payment_reference": "01/2023", "nature": "" }
    ]
}
```

with

```json
        { "source": "cash", "reference": "03", "date": "2023-12-23", "subject": "Cotisation des membres", "amount": 20000, "beneficiary": "Test Trésorier", "bank": "", "rib": "", "payment_reference": "01/2023", "nature": "" }
    ],
    "purchase_orders": [
        { "reference": "01/2021", "date": "2021-03-14", "supplier": "", "address": "", "requested_by": "Section football", "delivery_mode": "Sur place", "payment_mode": "Chèque", "order_number": "12", "observation": "", "lines": [
            { "designation": "Caméra de surveillance", "reference": "", "quantity": 1, "unit_price": null }
        ] }
    ]
}
```

- [ ] **Step 2: Write the failing tests**

In `tests/Feature/LegacyAccessImportTest.php` replace

```php
use App\Models\PaymentOrder;
use App\Models\Supplier;
```

with

```php
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Documents\YearlySequence;
use Illuminate\Support\Facades\DB;
```

and replace

```php
    #[Test]
    public function only_a_superadmin_uploads_the_file(): void
```

with

```php
    #[Test]
    public function the_old_purchase_order_is_imported_as_received_history(): void
    {
        $result = app(LegacyAccessImporter::class)->import($this->data(), User::factory()->admin()->create());

        $this->assertSame(['created' => 1, 'skipped' => 0], $result['purchase_orders']);
        $order = PurchaseOrder::firstOrFail();
        $this->assertTrue($order->is_legacy);
        $this->assertSame('purchase', $order->legacy_source);
        $this->assertNull($order->number);
        $this->assertSame(2021, $order->year);
        $this->assertSame('01/2021', $order->reference);
        $this->assertSame(PurchaseOrder::RECEIVED, $order->status);
        $this->assertNull($order->supplier_id);
        $this->assertSame('Section football', $order->requested_by);
        $this->assertSame('cheque', $order->payment_mode);
        $this->assertStringContainsString('N° Ordre : 12', $order->observation);
        $this->assertCount(1, $order->lines);
        $this->assertSame('Caméra de surveillance', $order->lines[0]->designation);
        $this->assertSame(1, $order->lines[0]->quantity);
        $this->assertNull($order->lines[0]->unit_price);
        // Not linked to the old payment order 01/2021: that one is another expense.
        $this->assertSame(0, PaymentOrder::whereNotNull('purchase_order_id')->count());
        $this->assertFalse($order->isEditable());
        $this->assertFalse($order->canEditPrices());
        $this->assertDatabaseHas('activity_logs', ['action' => 'legacy_data_imported']);
    }

    #[Test]
    public function importing_the_purchase_order_twice_changes_nothing(): void
    {
        $importer = app(LegacyAccessImporter::class);
        $admin = User::factory()->admin()->create();
        $importer->import($this->data(), $admin);

        $second = $importer->import($this->data(), $admin);

        $this->assertSame(['created' => 0, 'skipped' => 1], $second['purchase_orders']);
        $this->assertSame(1, PurchaseOrder::count());
        $this->assertSame(1, DB::transaction(fn () => YearlySequence::next('purchase_orders', 2021)));
    }

    #[Test]
    public function a_file_without_purchase_orders_still_imports(): void
    {
        $data = $this->data();
        unset($data['purchase_orders']);

        $result = app(LegacyAccessImporter::class)->import($data, User::factory()->admin()->create());

        $this->assertSame(['created' => 0, 'skipped' => 0], $result['purchase_orders']);
        $this->assertSame(['created' => 3, 'skipped' => 0, 'merged' => 1], $result['payment_orders']);
        $this->assertSame(0, PurchaseOrder::count());
    }

    #[Test]
    public function a_known_supplier_is_linked_and_bad_purchase_rows_are_skipped(): void
    {
        $result = app(LegacyAccessImporter::class)->import([
            'format' => 'access-legacy-v1',
            'suppliers' => [['name' => 'Test Journal']],
            'purchase_orders' => [
                ['reference' => '02/2021', 'date' => '2021-06-01', 'supplier' => 'TEST JOURNAL', 'payment_mode' => 'Virement',
                    'lines' => [['designation' => 'Affiches', 'quantity' => '3', 'unit_price' => '2500']]],
                ['reference' => '03/2021', 'date' => '', 'lines' => [['designation' => 'Sans date', 'quantity' => 1]]],
                ['reference' => '04/2021', 'date' => '2021-06-03', 'lines' => []],
                ['reference' => '05/2021', 'date' => '2021-06-04', 'supplier' => 'Unknown Shop', 'address' => '1 rue Test',
                    'lines' => [['designation' => '', 'reference' => 'REF-9', 'quantity' => 0]]],
                'oops',
            ],
        ], User::factory()->admin()->create());

        $this->assertSame(['created' => 2, 'skipped' => 3], $result['purchase_orders']);
        $linked = PurchaseOrder::where('legacy_reference', '02/2021')->firstOrFail();
        $this->assertSame(Supplier::where('name', 'Test Journal')->value('id'), $linked->supplier_id);
        $this->assertSame('bank', $linked->payment_mode);
        $this->assertSame(3, $linked->lines[0]->quantity);
        $this->assertSame('2500.00', $linked->lines[0]->unit_price);
        $this->assertSame('7500.00', $linked->lines[0]->amount);
        // An unknown supplier stays readable in the observation; a line named only by its reference keeps it.
        $unknown = PurchaseOrder::where('legacy_reference', '05/2021')->firstOrFail();
        $this->assertNull($unknown->supplier_id);
        $this->assertStringContainsString('Fournisseur : Unknown Shop', $unknown->observation);
        $this->assertStringContainsString('Adresse : 1 rue Test', $unknown->observation);
        $this->assertSame('REF-9', $unknown->lines[0]->designation);
        $this->assertSame(1, $unknown->lines[0]->quantity);
    }

    #[Test]
    public function only_a_superadmin_uploads_the_file(): void
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/LegacyAccessImportTest.php`
Expected: FAIL (`Undefined array key "purchase_orders"`); the seven P1 tests still pass.

- [ ] **Step 4: Importer section**

In `app/Services/Legacy/LegacyAccessImporter.php`:

Replace

```php
use App\Models\PaymentOrder;
use App\Models\Supplier;
```

with

```php
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
```

Replace

```php
            $result = [
                'suppliers' => $this->suppliers(is_array($data['suppliers'] ?? null) ? $data['suppliers'] : []),
                'payment_orders' => $this->paymentOrders(is_array($data['payment_orders'] ?? null) ? $data['payment_orders'] : []),
            ];
            ActivityRecorder::record($by, ActivityAction::LEGACY_DATA_IMPORTED, null, [
                'suppliers' => $result['suppliers']['created'],
                'payment_orders' => $result['payment_orders']['created'],
            ]);
```

with

```php
            $result = [
                'suppliers' => $this->suppliers(is_array($data['suppliers'] ?? null) ? $data['suppliers'] : []),
                'payment_orders' => $this->paymentOrders(is_array($data['payment_orders'] ?? null) ? $data['payment_orders'] : []),
                // Optional section (P2): an older export without it still imports.
                'purchase_orders' => $this->purchaseOrders(is_array($data['purchase_orders'] ?? null) ? $data['purchase_orders'] : []),
            ];
            ActivityRecorder::record($by, ActivityAction::LEGACY_DATA_IMPORTED, null, [
                'suppliers' => $result['suppliers']['created'],
                'payment_orders' => $result['payment_orders']['created'],
                'purchase_orders' => $result['purchase_orders']['created'],
            ]);
```

Replace

```php
    /** "01/2021" stays; a bare "01" (Arabic orders) gets its year: from the cash reference or the date. */
```

with

```php
    /**
     * Old `bon de commande` rows → legacy purchase orders: received, read-only,
     * no number. A supplier is linked only when its name matches a known one;
     * otherwise the old name and address stay readable in the observation, with
     * the old "N° Ordre" (never linked to a payment order).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function purchaseOrders(array $rows): array
    {
        $created = $skipped = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;

                continue;
            }
            $reference = Str::limit(trim(preg_replace('/\s+/', ' ', self::plain($row['reference'] ?? null))), 50, '');
            $date = self::date($row['date'] ?? null);
            $lines = array_values(array_filter(array_map(
                fn ($line) => self::purchaseLine($line),
                is_array($row['lines'] ?? null) ? $row['lines'] : [],
            )));
            if ($reference === '' || $date === null || $lines === []) {
                $skipped++;

                continue;
            }
            if (PurchaseOrder::where('is_legacy', true)->where('legacy_source', 'purchase')
                ->where('legacy_reference', $reference)->whereDate('order_date', $date)->exists()) {
                $skipped++;

                continue;
            }

            $supplierName = self::text($row['supplier'] ?? null, 200);
            $supplierId = $supplierName === null ? null
                : Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower($supplierName)])->value('id');
            $orderNumber = self::text($row['order_number'] ?? null, 50);
            $address = self::text($row['address'] ?? null, 255);
            $observation = implode("\n", array_filter([
                self::text($row['observation'] ?? null, 2000),
                $orderNumber !== null ? 'N° Ordre : '.$orderNumber : null,
                $supplierName !== null && $supplierId === null ? 'Fournisseur : '.$supplierName : null,
                $address !== null && $supplierId === null ? 'Adresse : '.$address : null,
            ]));

            $order = PurchaseOrder::create([
                'year' => (int) substr($date, 0, 4),
                'number' => null,
                'legacy_reference' => $reference,
                'is_legacy' => true,
                'legacy_source' => 'purchase',
                'status' => PurchaseOrder::RECEIVED,
                'order_date' => $date,
                'supplier_id' => $supplierId,
                'requested_by' => self::text($row['requested_by'] ?? null, 200),
                'delivery_mode' => self::text($row['delivery_mode'] ?? null, 200),
                'payment_mode' => self::paymentMode(self::plain($row['payment_mode'] ?? null)),
                'observation' => $observation === '' ? null : $observation,
                'received_at' => $date,
            ]);
            foreach ($lines as $index => $line) {
                PurchaseOrderLine::create([...$line, 'purchase_order_id' => $order->id, 'sort_order' => $index + 1]);
            }
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * One old order line: a designation (or its article reference), a quantity
     * of at least 1 and a price when one was typed.
     *
     * @return array{designation: string, quantity: int, unit_price: ?float, amount: ?float}|null
     */
    private static function purchaseLine(mixed $line): ?array
    {
        if (! is_array($line)) {
            return null;
        }
        $designation = self::text($line['designation'] ?? null, 255) ?? self::text($line['reference'] ?? null, 255);
        if ($designation === null) {
            return null;
        }
        $quantity = is_numeric($line['quantity'] ?? null) ? max(1, (int) round((float) $line['quantity'])) : 1;
        $price = is_numeric($line['unit_price'] ?? null) && (float) $line['unit_price'] > 0 ? round((float) $line['unit_price'], 2) : null;

        return [
            'designation' => $designation,
            'quantity' => $quantity,
            'unit_price' => $price,
            'amount' => PurchaseOrderLine::amountFor($quantity, $price),
        ];
    }

    /** The old free-text payment mode as one of PaymentOrder::METHODS, or null when unclear. */
    private static function paymentMode(string $text): ?string
    {
        $text = mb_strtolower(trim($text));

        return match (true) {
            $text === '' => null,
            str_contains($text, 'vir') || str_contains($text, 'تحويل') => 'bank',
            str_contains($text, 'ccp') => 'ccp',
            str_contains($text, 'chè') || str_contains($text, 'cheq') || str_contains($text, 'chq') || str_contains($text, 'شيك') => 'cheque',
            str_contains($text, 'esp') || str_contains($text, 'cash') || str_contains($text, 'نقد') => 'cash',
            default => null,
        };
    }

    /** "01/2021" stays; a bare "01" (Arabic orders) gets its year: from the cash reference or the date. */
```

Also update the class docblock: replace

```php
 * orders are paid, read-only and create no transaction, so finance totals do
 * not move. Running it again creates nothing (every row is matched first).
```

with

```php
 * orders are paid, the imported purchase order is received; both are
 * read-only and create no transaction, so finance totals do not move.
 * Running it again creates nothing (every row is matched first).
```

- [ ] **Step 5: Export script section**

In `scripts/legacy/export-access.ps1` replace

```powershell
$connection.Close()
```

with

```powershell
# Optional: the old purchase orders ("bon de commande"). One row per ordered
# item, grouped by the order reference N°1 ("01/2021"). A database without the
# table still exports (the importer treats the section as optional).
$purchaseOrders = [ordered]@{}
try {
    $purchaseRows = (Read-Table 'SELECT * FROM [bon de commande]').Rows
} catch {
    $purchaseRows = @()
    Write-Warning 'No [bon de commande] table: purchase orders skipped'
}
foreach ($row in $purchaseRows) {
    $reference = Get-Text $row['N°1']
    if ($reference -eq '') { $reference = Get-Text $row['N°'] }
    if ($reference -eq '') { continue }
    if (-not $purchaseOrders.Contains($reference)) {
        $purchaseOrders[$reference] = [ordered]@{
            reference = $reference; date = (Get-Day $row['Date'])
            supplier = (Get-Text $row['Fournisseur']); address = (Get-Text $row['Adresse'])
            requested_by = (Get-Text $row['service']); delivery_mode = (Get-Text $row['Mode de livraison'])
            payment_mode = (Get-Text $row['mode de paiement']); order_number = (Get-Text $row['N° Ordre'])
            observation = (Get-Text $row['Observation'])
            lines = (New-Object System.Collections.ArrayList)
        }
    }
    $price = Get-Amount $row['PU']
    [void]$purchaseOrders[$reference].lines.Add([ordered]@{
        designation = (Get-Text $row['Désignation']); reference = (Get-Text $row['Ref'])
        quantity = (Get-Amount $row['Quantité'])
        unit_price = $(if ($price -gt 0) { $price } else { $null })
    })
}

$connection.Close()
```

Replace

```powershell
    payment_orders = @($orders)
}
$json = $document | ConvertTo-Json -Depth 5
[System.IO.File]::WriteAllText($Out, $json, (New-Object System.Text.UTF8Encoding($false)))
Write-Output ("Wrote {0} suppliers, {1} payment orders to {2}" -f $suppliers.Count, $orders.Count, $Out)
```

with

```powershell
    payment_orders = @($orders)
    purchase_orders = @($purchaseOrders.Values)
}
$json = $document | ConvertTo-Json -Depth 6
[System.IO.File]::WriteAllText($Out, $json, (New-Object System.Text.UTF8Encoding($false)))
Write-Output ("Wrote {0} suppliers, {1} payment orders, {2} purchase orders to {3}" -f $suppliers.Count, $orders.Count, $purchaseOrders.Count, $Out)
```

Check the script still parses (no database needed):

Run (from Git Bash — the single quotes keep `$` away from bash):

```bash
powershell -NoProfile -Command '$errors = $null; [void][System.Management.Automation.Language.Parser]::ParseFile((Resolve-Path "scripts/legacy/export-access.ps1"), [ref]$null, [ref]$errors); if ($errors) { $errors; exit 1 } else { "parse ok" }'
```

Expected: `parse ok`

- [ ] **Step 6: Result label**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p2-t8-keys.json`:

```json
{
    "legacy_import.section.purchase_orders": { "ar": "سندات الطلب", "fr": "Bons de commande", "en": "Purchase orders" }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p2-t8-keys.json`
Expected: `✓ 1 key(s) written to ar/en/fr`

(`resources/js/Pages/LegacyImport/Index.vue` already loops over every result section with `t('legacy_import.section.' + section)` — no page change.)

- [ ] **Step 7: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/LegacyAccessImportTest.php`
Expected: PASS (11 tests: 7 from P1 + 4 new).
Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add scripts/legacy/export-access.ps1 app/Services/Legacy/LegacyAccessImporter.php tests/Fixtures/legacy-access.json tests/Feature/LegacyAccessImportTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(legacy): import the old Access purchase order (optional section)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 9: Full verification

**Files:** none changed unless a check fails (fix in the task the failure belongs to, with its own test and commit).

- [ ] **Step 1: Front-end checks**

Run: `npm run i18n:check`
Expected: PASS.
Run: `npm run test:js`
Expected: PASS.
Run: `npm run build`
Expected: build succeeds.

- [ ] **Step 2: Full suite**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit`
Expected: all tests pass (no other test process running at the same time).

- [ ] **Step 3: Migrations on a copy of the dev database (SQLite rebuild guard)**

The link migration rebuilds `payment_orders` and adds a column to `equipment_items`; nothing may cascade away. Work on a copy, never on `D:\irnb-laravel\database\database.sqlite` itself (stop `php artisan serve` first if it holds the file):

```bash
COPY="C:/Users/MUSTAPHA/AppData/Local/Temp/p2-dev-copy.sqlite"
cp D:/irnb-laravel/database/database.sqlite "$COPY"
COUNT='$p = new PDO("sqlite:".getenv("COPY")); foreach (["payment_orders", "equipment_items", "equipment_histories", "branch_equipment_item", "transactions"] as $t) { echo $t, " ", $p->query("select count(*) from $t")->fetchColumn(), PHP_EOL; }'
COPY="$COPY" php -r "$COUNT"
DB_CONNECTION=sqlite DB_DATABASE="$COPY" php artisan migrate --force
COPY="$COPY" php -r "$COUNT"
```

Expected: `migrate` runs the three `2026_11_25_2000*` migrations without error; the five counts are identical before and after. Then:

```bash
DB_CONNECTION=sqlite DB_DATABASE="$COPY" php artisan migrate --force
```

Expected: `Nothing to migrate.`

- [ ] **Step 4: Look at the printed purchase order (mPDF RTL)**

Render one Arabic and one French PDF from an in-memory order against the migrated copy:

```bash
DB_CONNECTION=sqlite DB_DATABASE="C:/Users/MUSTAPHA/AppData/Local/Temp/p2-dev-copy.sqlite" php artisan tinker --execute '
foreach (["ar", "fr"] as $locale) {
    app()->setLocale($locale);
    $o = new App\Models\PurchaseOrder(["year" => 2026, "number" => 3, "status" => "draft", "order_date" => "2026-11-25", "requested_by" => "Section football", "delivery_mode" => "Livraison au club", "payment_mode" => "cheque", "observation" => "Ligne 1\nLigne 2"]);
    $o->setRelation("supplier", new App\Models\Supplier(["name" => "Test Sport Shop", "address" => "12 rue des Sports", "phone" => "0550000000"]));
    $o->setRelation("lines", collect([
        new App\Models\PurchaseOrderLine(["designation" => "Ballon taille 5", "quantity" => 10, "unit_price" => 1500, "amount" => 15000]),
        new App\Models\PurchaseOrderLine(["designation" => "Gonfleur", "quantity" => 2, "unit_price" => 750, "amount" => 1500]),
    ]));
    $html = view("pdf.purchase-order", [
        "club" => App\Services\Pdf\ClubHeader::data(), "rtl" => $locale === "ar", "order" => $o,
        "priced" => true, "total" => 16500.0, "words" => App\Support\AmountInWords::format(16500.0, $locale),
        "method" => App\Support\UiLang::get("cheque"), "signatories" => App\Services\Pdf\BoardSignatories::names(),
    ])->render();
    $file = sys_get_temp_dir()."/p2-purchase-order-".$locale.".pdf";
    file_put_contents($file, app(App\Services\Pdf\PdfService::class)->render($html, $locale === "ar"));
    echo $file, PHP_EOL;
}'
```

Expected: two file paths printed. Open both: the Arabic one reads right-to-left with the reference, date, phone, quantities and money left-to-right; the "DRAFT" banner is at the top; the lines table has its total row and the amount in words; the observation keeps its line break; both signature columns show. Fix any defect in `resources/views/pdf/purchase-order.blade.php` (commit `fix(purchase-orders): …`) and re-run Step 2.

- [ ] **Step 5: Review the branch before handing over**

Run: `git log --oneline main..feat/purchase-orders`
Expected: the 8 task commits (plus any fix commits), nothing else.
Run: `git status --short`
Expected: clean (no unstaged or untracked project file left behind).

Hand-over notes for the owner (no server deploy — the app runs locally and on the desktop): run `php artisan migrate` on the web PC's database; the desktop build picks up the migrations on its next version; re-export `D:\BACKUPS\irnb-access-legacy.json` with the updated script, then import it again from Settings → Old software import (existing rows are skipped, the purchase order is added).

---

## Spec coverage (P2)

| Spec rule | Task |
|---|---|
| `PurchaseOrder` model, `purchase_order_lines`, service with atomic claims, exception flash pattern | 2, 4 |
| Own `NN/YYYY` sequence via `YearlySequence`, unique `(year, number)`, cancelled keeps number | 2, 4 |
| Legacy fields; legacy read-only, no number | 2, 4, 8 |
| Statuses draft / sent / received / cancelled; supplier required (null only legacy); requested by / delivery mode ≤ 200; payment mode = `PaymentOrder::METHODS`; audit columns | 2, 5 |
| Lines: catalog item or free text, designation required (prefilled from catalog), quantity ≥ 1, optional price, server-computed amount, sort order, at least one line | 2, 4, 5, 7 |
| FK `payment_orders.purchase_order_id` nullOnDelete; `equipment_items.purchase_order_id` nullOnDelete | 2 |
| Draft fully editable, print with DRAFT banner | 4, 6, 7 |
| Send locks supplier / designations / catalog links / quantities; prices editable (sent or received) until an active payment order | 4, 5, 7 |
| Cancel from draft or sent; received cannot | 4 |
| Receive whole order, date default today; stock tick (catalog lines, stock right) with quantity, price, designation, New, purchase, no expense, purchase date, `purchase_order_id`; free text never stocked | 4, 5, 7 |
| Payment-order tick (all priced + `payment_orders` add): draft, supplier beneficiary with bank/RIB, total, subject "Purchase order NN/YYYY", method = payment mode, link; same transaction | 4, 5, 7 |
| Received order without active payment order → "Create payment order" button; at most one active | 4, 5, 7 |
| Payment order form links a received order of the same supplier with no active payment order; supplier change clears the link | 3, 7 |
| `markPaid` sets `purchase_transaction_id` on the order's items where null | 3 |
| Order page: lines, total, linked payment order with status, received equipment count; payment order page links back | 3, 5, 7 |
| Finance reset leaves purchase orders untouched | 2 |
| Print: UI language, letterhead, number, date, supplier name/address/phone, requested by, delivery, payment mode, lines (blank unpriced cells), total + words only when all priced, observation, President + Treasurer | 6 |
| Module `purchase_orders` (finance, no approve), print → view, send/receive/cancel/payment order → edit, grant migration | 1 |
| Menu under Finance next to payment orders | 7 |
| Activity codes `PURCHASE_ORDER_*` (ids, references, totals only) | 4, 6 |
| Import: optional export section, legacy received order, no supplier, free-text line, "01/2021", N° Ordre in observation, not linked, idempotent, file without section still imports | 8 |
