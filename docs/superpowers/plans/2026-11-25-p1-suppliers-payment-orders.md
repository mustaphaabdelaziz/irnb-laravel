# P1 — Suppliers + Payment Orders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Suppliers list and a Payment Orders register (draft → approved → paid, cancel) whose "mark paid" creates the expense, with three printable A4 forms (payment order, bank transfer order, cash order) and a one-time import of suppliers and old orders from the club's old Access app.

**Architecture:** New `suppliers` lookup list (App Configuration pattern of `VenueController`). New `payment_orders` table + `PaymentOrderService` (all state changes, one DB transaction each) + thin `PaymentOrderController`. Numbering through a small `YearlySequence` helper (max + 1 per year, unique index, one retry). Expenses gain supplier / invoice / cheque date / board meeting fields; board meetings gain a PV number. A new role action `approve` exists only on the `payment_orders` module (`Role::EXTRA_ACTIONS`). PDFs through `PdfService` + `ClubHeader`, language = UI locale.

**Tech Stack:** Laravel 13, Inertia + Vue 3 (`<script setup>`), mPDF (`App\Services\Pdf\PdfService`), PHPUnit feature tests (`#[Test]`, `RefreshDatabase`), SQLite (dev/desktop) + MySQL compatible SQL.

Spec: `docs/superpowers/specs/2026-11-25-access-legacy-documents-design.md` (section P1 + Shared rules + Import).

## Global Constraints

- Work in a git worktree on branch `feat/payment-orders` (see `superpowers:using-git-worktrees`); copy `vendor` (no junctions) and run `npm ci && npm run build` once in the worktree before feature tests (Inertia pages need the Vite manifest).
- Stage explicit files only (`git add <paths>`), never `git add -A` — the main working tree holds unrelated uncommitted files.
- Run tests with `php artisan config:clear` first; single test: `php artisan test --filter=ClassName`; full suite: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit`. No parallel test runs.
- SQL must run on SQLite **and** MySQL: no `strftime`, no `||` concatenation, no SQLite-only functions; compute years in PHP.
- UI text: ar / fr / en, flat dotted keys added only through `node scripts/i18n-add.mjs <keys.json>`; never re-sort catalogs; never make a key segment that follows an existing message a String method name (`link`, `search`, `at`, `replace`, `trim`, `concat`, `split`…). `npm run i18n:check` must pass.
- Every `flash.*` key a controller emits exists in all three catalogs.
- PDFs: `<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">`, RTL when `app()->getLocale() === 'ar'`; wrap RIBs, numbers, references and dates in `<bdo dir="ltr">`; text blocks with line breaks use `nl2br(e(...))` (mPDF ignores `pre-wrap`).
- Activity log properties: ids, amounts and references only — never names, RIBs or free text.
- Orders are never deleted. Numbers: payment order `NN/YYYY` (calendar year of `order_date`, 2-digit pad).
- New rights start empty except system roles (granted by an idempotent migration, desktop never seeds).
- Commit after each task with a conventional message ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Models/Role.php` (modify) | `payment_orders` + `suppliers` modules, `EXTRA_ACTIONS`, `actionsFor()` |
| `app/Http/Controllers/RoleController.php`, `UserController.php` (modify) | validate / pass extra actions |
| `resources/js/Components/PermissionGrid.vue` (modify) | extra-action column |
| `database/migrations/2026_11_25_100000_grant_supplier_and_payment_order_permissions.php` | system roles get the new modules |
| `database/migrations/2026_11_25_100001_create_suppliers_table.php` | suppliers |
| `app/Models/Supplier.php`, `app/Http/Controllers/SupplierController.php`, `resources/js/Pages/Settings/Suppliers.vue` | suppliers list |
| `database/migrations/2026_11_25_100002_add_document_fields_to_transactions_and_meetings.php` | transaction supplier / invoice / cheque / meeting fields, meeting PV number |
| `app/Services/Documents/YearlySequence.php` | per-year numbering |
| `database/migrations/2026_11_25_100003_create_payment_orders_table.php`, `app/Models/PaymentOrder.php` | payment orders |
| `app/Exceptions/PaymentOrderException.php` | refusals rendered as flash errors |
| `app/Services/Finance/PaymentOrderService.php` | create / update / approve / markPaid / cancel / fromExpense |
| `app/Http/Requests/PaymentOrder/SavePaymentOrderRequest.php` | form validation |
| `app/Http/Controllers/PaymentOrderController.php` | register pages + actions |
| `resources/js/Pages/PaymentOrders/{Index,Create,Edit,Show}.vue`, `Partials/PaymentOrderForm.vue` | UI |
| `app/Support/AmountInWords.php` | amount in words ar/fr/en (intl `NumberFormatter`, bundled on desktop) |
| `app/Services/Pdf/BoardSignatories.php`, `resources/views/pdf/partials/signatures.blade.php` | president + treasurer block |
| `app/Http/Controllers/PaymentOrderPrintController.php`, `resources/views/pdf/payment-order{,-transfer,-cash}.blade.php` | 3 prints |
| `app/Services/Legacy/LegacyAccessImporter.php`, `app/Http/Controllers/LegacyImportController.php`, `resources/js/Pages/LegacyImport/Index.vue` | import of the Access export |
| `scripts/legacy/export-access.ps1` | Access → JSON export (no data committed) |

---

### Task 1: `approve` role action + new modules + grant migration

**Files:**
- Modify: `app/Models/Role.php`
- Modify: `app/Http/Controllers/RoleController.php`
- Modify: `app/Http/Controllers/UserController.php:189`
- Modify: `resources/js/Components/PermissionGrid.vue`
- Modify: `resources/js/Pages/Roles/Index.vue`, `resources/js/Pages/Users/Edit.vue`
- Create: `database/migrations/2026_11_25_100000_grant_supplier_and_payment_order_permissions.php`
- Test: `tests/Feature/RoleExtraActionsTest.php`

**Interfaces:**
- Produces: `Role::EXTRA_ACTIONS = ['payment_orders' => ['approve']]`; `Role::actionsFor(string $module): array`; modules `payment_orders` (group finance) and `suppliers` (group config); Inertia prop `extraActions` on `Roles/Index` and `Users/Edit`; `PermissionGrid` prop `extraActions: Object`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoleExtraActionsTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::factory()->create(['privileges' => ['superadmin']]);
    }

    #[Test]
    public function approve_exists_only_on_payment_orders(): void
    {
        $all = Role::allPermissions();

        $this->assertSame(['view', 'add', 'edit', 'delete', 'approve'], $all['payment_orders']);
        $this->assertSame(['view', 'add', 'edit', 'delete'], $all['suppliers']);
        $this->assertSame(['view', 'add', 'edit', 'delete'], $all['players']);
        $this->assertContains('payment_orders', Role::MODULE_GROUPS['finance']);
        $this->assertContains('suppliers', Role::MODULE_GROUPS['config']);
    }

    #[Test]
    public function the_role_editor_saves_approve_on_payment_orders(): void
    {
        $role = Role::factory()->create(['permissions' => []]);

        $this->actingAs($this->superadmin())->put(route('roles.update', $role), [
            'name' => ['en' => 'Treasurer'],
            'permissions' => ['payment_orders' => ['view', 'approve']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['view', 'approve'], $role->fresh()->permissions['payment_orders']);
    }

    #[Test]
    public function approve_on_another_module_is_refused(): void
    {
        $role = Role::factory()->create(['permissions' => []]);

        $this->actingAs($this->superadmin())->put(route('roles.update', $role), [
            'name' => ['en' => 'Coach'],
            'permissions' => ['players' => ['view', 'approve']],
        ])->assertSessionHasErrors('permissions');
    }

    #[Test]
    public function a_user_with_approve_has_the_permission(): void
    {
        $role = Role::factory()->create(['permissions' => ['payment_orders' => ['view', 'approve']]]);
        $user = User::factory()->create(['privileges' => ['user'], 'role_id' => $role->id]);

        $this->assertTrue($user->hasPermission('payment_orders', 'approve'));
        $this->assertFalse($user->hasPermission('payment_orders', 'edit'));
    }

    #[Test]
    public function the_grant_migration_gives_system_roles_the_new_modules_only(): void
    {
        $system = Role::factory()->create(['is_system' => true, 'permissions' => ['players' => ['view']]]);
        $other = Role::factory()->create(['is_system' => false, 'permissions' => ['players' => ['view']]]);

        (require database_path('migrations/2026_11_25_100000_grant_supplier_and_payment_order_permissions.php'))->up();

        $this->assertSame(['view', 'add', 'edit', 'delete', 'approve'], $system->fresh()->permissions['payment_orders']);
        $this->assertSame(['view', 'add', 'edit', 'delete'], $system->fresh()->permissions['suppliers']);
        $this->assertArrayNotHasKey('payment_orders', $other->fresh()->permissions);
        $this->assertArrayNotHasKey('suppliers', $other->fresh()->permissions);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan config:clear && php artisan test --filter=RoleExtraActionsTest`
Expected: FAIL (undefined index `payment_orders`, migration file missing).

- [ ] **Step 3: Implement `Role` changes**

In `app/Models/Role.php`:

```php
    public const MODULE_GROUPS = [
        'members' => ['players', 'documents', 'attendance', 'subscriptions'],
        'competitions' => ['teams', 'competitions', 'games'],
        'finance' => ['transactions', 'payment_orders', 'finance', 'reports'],
        'equipment' => ['equipment', 'inventory'],
        'board' => ['board'],
        'social' => ['activities', 'literary_reports'],
        'config' => [
            'categories', 'branches', 'positions', 'player_statuses', 'document_types', 'jobs',
            'board_roles', 'board_committees', 'equipment_categories', 'storage_locations',
            'game_formats', 'opponents', 'venues', 'activity_types', 'suppliers',
        ],
        'system' => ['users', 'settings'],
    ];

    /** Permission-controlled modules. */
    public const MODULES = [
        'players', 'documents', 'attendance', 'subscriptions',
        'teams', 'competitions', 'games',
        'transactions', 'payment_orders', 'finance', 'reports',
        'equipment', 'inventory',
        'board',
        'activities', 'literary_reports',
        'categories', 'branches', 'positions', 'player_statuses', 'document_types', 'jobs',
        'board_roles', 'board_committees', 'equipment_categories', 'storage_locations',
        'game_formats', 'opponents', 'venues', 'activity_types', 'suppliers',
        'users', 'settings',
    ];

    /** Canonical actions per module. */
    public const ACTIONS = ['view', 'add', 'edit', 'delete'];

    /**
     * Actions only some modules have, after the canonical four. Approving a
     * payment order is a separate right from editing it (owner, 2026-11-25):
     * the accountant prepares, the president or treasurer approves.
     */
    public const EXTRA_ACTIONS = [
        'payment_orders' => ['approve'],
    ];

    /** @return list<string> The actions a module can be granted. */
    public static function actionsFor(string $module): array
    {
        return [...self::ACTIONS, ...(self::EXTRA_ACTIONS[$module] ?? [])];
    }
```

and replace `allPermissions()`:

```php
    /** Full matrix: every module granted every action it has. */
    public static function allPermissions(): array
    {
        $all = [];
        foreach (self::MODULES as $module) {
            $all[$module] = self::actionsFor($module);
        }

        return $all;
    }
```

- [ ] **Step 4: Implement `RoleController` + `UserController` changes**

In `RoleController::index()` add the prop after `'actions' => Role::ACTIONS,`:

```php
            'extraActions' => Role::EXTRA_ACTIONS,
```

In `validateRole()` replace the per-module action check:

```php
                foreach ((array) $value as $module => $actions) {
                    if (array_diff((array) $actions, Role::actionsFor((string) $module)) !== []) {
                        $fail('Unknown action in permissions.');
                    }
                }
```

In `sanitisePermissions()` replace the intersect line:

```php
            $actions = array_values(array_intersect(Role::actionsFor($module), (array) $actions));
```

In `UserController` (edit page props, next to `'actions' => Role::ACTIONS,` at line ~189) add:

```php
            'extraActions' => Role::EXTRA_ACTIONS,
```

- [ ] **Step 5: Grant migration**

Create `database/migrations/2026_11_25_100000_grant_supplier_and_payment_order_permissions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers and payment orders. Owner's rule for new rights: they start
 * empty except the system roles (full access, approve included). A migration
 * because the desktop build never seeds. Idempotent: a module a role already
 * has is left alone.
 */
return new class extends Migration
{
    private const GRANT = [
        'suppliers' => ['view', 'add', 'edit', 'delete'],
        'payment_orders' => ['view', 'add', 'edit', 'delete', 'approve'],
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

- [ ] **Step 6: PermissionGrid extra column**

In `resources/js/Components/PermissionGrid.vue` add the prop and a computed, and render the extra column:

```js
const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
    groups: { type: Object, default: () => ({}) },
    actions: { type: Array, default: () => [] },
    // Role::EXTRA_ACTIONS: { module: ['approve'] } — rights only some modules have.
    extraActions: { type: Object, default: () => ({}) },
});
```

```js
import { computed } from 'vue';
// …
const hasExtras = computed(() => Object.keys(props.extraActions).length > 0);
const columnCount = computed(() => props.actions.length + 1 + (hasExtras.value ? 1 : 0));
```

Template changes: header gets `<th v-if="hasExtras" class="p-2 text-center">{{ t('perm_other') }}</th>` after the action headers; the section row uses `:colspan="columnCount"`; each module row gets, after the action cells:

```vue
<td v-if="hasExtras" class="p-2 text-center">
    <label v-for="a in (extraActions[m] ?? [])" :key="a" class="inline-flex items-center gap-1 text-xs">
        <input type="checkbox" :checked="has(m, a)" :aria-label="`${t(m)} — ${t('perm_' + a)}`" @change="toggle(m, a)"
            class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
        {{ t('perm_' + a) }}
    </label>
</td>
```

In `Roles/Index.vue` add prop `extraActions: { type: Object, default: () => ({}) }` and pass `:extra-actions="extraActions"` to `<PermissionGrid>`; same in `Users/Edit.vue`.

- [ ] **Step 7: Translations**

Write `C:\Users\MUSTAPHA\AppData\Local\Temp\p1-t1-keys.json` (any scratch path) and run `node scripts/i18n-add.mjs <path>`:

```json
{
    "payment_orders": { "ar": "أوامر الدفع", "fr": "Ordres de paiement", "en": "Payment orders" },
    "suppliers": { "ar": "الموردون", "fr": "Fournisseurs", "en": "Suppliers" },
    "perm_other": { "ar": "حقوق أخرى", "fr": "Autres droits", "en": "Other rights" },
    "perm_approve": { "ar": "المصادقة", "fr": "Approuver", "en": "Approve" }
}
```

- [ ] **Step 8: Run tests**

Run: `php artisan test --filter="RoleExtraActionsTest|RoleManagementTest"`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Models/Role.php app/Http/Controllers/RoleController.php app/Http/Controllers/UserController.php resources/js/Components/PermissionGrid.vue resources/js/Pages/Roles/Index.vue resources/js/Pages/Users/Edit.vue database/migrations/2026_11_25_100000_grant_supplier_and_payment_order_permissions.php tests/Feature/RoleExtraActionsTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(roles): approve right on payment orders; suppliers and payment orders modules"
```

---

### Task 2: Suppliers list (App Configuration)

**Files:**
- Create: `database/migrations/2026_11_25_100001_create_suppliers_table.php`
- Create: `app/Models/Supplier.php`
- Create: `app/Http/Controllers/SupplierController.php`
- Create: `resources/js/Pages/Settings/Suppliers.vue`
- Modify: `routes/web.php` (settings lookup group, after `venues`), `config/permissions.php` (modules), `config/activity.php` (`$settingLists`), `resources/js/Composables/useNavigation.js` (config section)
- Test: `tests/Feature/SupplierListTest.php`

**Interfaces:**
- Consumes: module `suppliers` (Task 1).
- Produces: `App\Models\Supplier` (`name, activity, address, phone, bank_name, rib, notes, is_active`), scope `active()`; routes `suppliers.index|store|update|destroy`; method `Supplier::isInUse(): bool` (extended in Tasks 3 and 6).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplierListTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    #[Test]
    public function the_list_shows_suppliers_alphabetically(): void
    {
        Supplier::create(['name' => 'Zeta Sport']);
        Supplier::create(['name' => 'Alpha Print']);

        $this->actingAs($this->admin())->get(route('suppliers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/Suppliers')
                ->where('suppliers.0.name', 'Alpha Print')
                ->where('suppliers.1.name', 'Zeta Sport'));
    }

    #[Test]
    public function a_supplier_is_created_with_bank_details(): void
    {
        $this->actingAs($this->admin())->post(route('suppliers.store'), [
            'name' => 'Journal El Massa', 'activity' => 'Press', 'bank_name' => 'BNA 611',
            'rib' => '00100000000000001234', 'is_active' => true,
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.supplier_created');

        $this->assertDatabaseHas('suppliers', ['name' => 'Journal El Massa', 'rib' => '00100000000000001234', 'is_active' => true]);
    }

    #[Test]
    public function names_are_unique_ignoring_case(): void
    {
        Supplier::create(['name' => 'Journal El Massa']);

        $this->actingAs($this->admin())->post(route('suppliers.store'), ['name' => 'JOURNAL el massa'])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function a_rib_is_digits_only(): void
    {
        $this->actingAs($this->admin())->post(route('suppliers.store'), ['name' => 'X', 'rib' => '0010 abc'])
            ->assertSessionHasErrors('rib');
    }

    #[Test]
    public function an_unused_supplier_can_be_deleted(): void
    {
        $supplier = Supplier::create(['name' => 'Old']);

        $this->actingAs($this->admin())->delete(route('suppliers.destroy', $supplier))
            ->assertSessionHas('success', 'flash.supplier_deleted');

        $this->assertModelMissing($supplier);
    }

    #[Test]
    public function the_list_needs_the_suppliers_right(): void
    {
        $role = Role::factory()->create(['permissions' => ['venues' => ['view']]]);
        $user = User::factory()->create(['privileges' => ['user'], 'role_id' => $role->id]);

        $this->actingAs($user)->get(route('suppliers.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SupplierListTest`
Expected: FAIL (route `suppliers.index` not defined).

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200)->unique();
            $table->string('activity', 200)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('bank_name', 150)->nullable();
            // Algerian RIB: 20 digits; stored as typed digits, no spaces.
            $table->string('rib', 30)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
```

- [ ] **Step 4: Model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Who the club pays: shops, printers, leagues, coaches under contract.
 * Picked on payment orders and expenses so the name and RIB are typed once.
 */
class Supplier extends Model
{
    use SerializesClubDates;

    protected $fillable = ['name', 'activity', 'address', 'phone', 'bank_name', 'rib', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** A supplier something points at stays (deactivate it instead). */
    public function isInUse(): bool
    {
        return false;
    }
}
```

- [ ] **Step 5: Controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Suppliers', [
            'suppliers' => Supplier::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Supplier::create($this->validated($request));

        return back()->with('success', 'flash.supplier_created');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request, $supplier));

        return back()->with('success', 'flash.supplier_updated');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        if ($supplier->isInUse()) {
            return back()->with('error', 'flash.supplier_in_use');
        }
        $supplier->delete();

        return back()->with('success', 'flash.supplier_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $request->merge(['rib' => $request->filled('rib') ? preg_replace('/\s+/', '', (string) $request->input('rib')) : null]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200', function ($attribute, $value, $fail) use ($supplier) {
                $taken = Supplier::query()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])
                    ->when($supplier, fn ($q) => $q->whereKeyNot($supplier->id))
                    ->exists();
                if ($taken) {
                    $fail(__('validation.unique', ['attribute' => __('validation.attributes.name')]));
                }
            }],
            'activity' => ['nullable', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'rib' => ['nullable', 'string', 'regex:/^\d{10,30}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['name'] = trim($data['name']);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
```

- [ ] **Step 6: Routes, permissions, activity, nav**

`routes/web.php` — import `use App\Http\Controllers\SupplierController;` and add after the `venues` resource line:

```php
        Route::resource('suppliers', SupplierController::class)->except(['show', 'create', 'edit']);
```

`config/permissions.php` `modules`, after `'activity-types' => 'activity_types',`:

```php
        'suppliers' => 'suppliers',
```

`config/activity.php` `$settingLists`, after `'venues' => 'venues',` (the line is at ~41):

```php
    'suppliers' => 'suppliers',
```

`useNavigation.js` config section, after the `activity_types` item:

```js
                { label: t('suppliers'), href: '/suppliers', icon: 'transactions', prefix: '/suppliers', module: 'suppliers' },
```

- [ ] **Step 7: Page `resources/js/Pages/Settings/Suppliers.vue`**

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan.js';
import { computed, ref } from 'vue';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({ suppliers: Array });

const FIELDS = ['name', 'activity', 'address', 'phone', 'bank_name', 'rib', 'notes'];
const blank = () => ({ name: '', activity: '', address: '', phone: '', bank_name: '', rib: '', notes: '', is_active: true });

const query = ref('');
const editingId = ref(null);
const deleteId = ref(null);
const form = useForm(blank());
const editForm = useForm(blank());

const shown = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return props.suppliers;
    return props.suppliers.filter((s) => [s.name, s.activity, s.rib].some((v) => (v || '').toLowerCase().includes(q)));
});

function add() {
    form.post(route('suppliers.store'), { preserveScroll: true, onSuccess: () => form.reset() });
}

function startEdit(supplier) {
    editingId.value = supplier.id;
    FIELDS.forEach((f) => { editForm[f] = supplier[f] || ''; });
    editForm.is_active = supplier.is_active;
}

function saveEdit(id) {
    editForm.put(route('suppliers.update', id), { preserveScroll: true, onSuccess: () => { editingId.value = null; } });
}

function destroy() {
    router.delete(route('suppliers.destroy', deleteId.value), { preserveScroll: true, onFinish: () => { deleteId.value = null; } });
}
</script>

<template>
    <Head :title="t('suppliers')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('suppliers') }}</h1>
        </template>

        <div class="mx-auto max-w-5xl space-y-6">
            <form v-if="can('suppliers', 'add')" @submit.prevent="add" class="grid grid-cols-1 gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:grid-cols-3 dark:bg-slate-900 dark:ring-slate-800">
                <div v-for="f in ['name', 'activity', 'phone', 'bank_name', 'rib', 'address']" :key="f">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('supplier.field.' + f) }}</label>
                    <TextInput v-model="form[f]" class="mt-1 w-full" :required="f === 'name'" :dir="f === 'rib' || f === 'phone' ? 'ltr' : undefined" />
                    <InputError :message="form.errors[f]" class="mt-1" />
                </div>
                <div class="sm:col-span-3 flex justify-end">
                    <PrimaryButton :disabled="form.processing">{{ t('add') }}</PrimaryButton>
                </div>
            </form>

            <TextInput v-model="query" class="w-full sm:w-72" :placeholder="t('supplier.filter_placeholder')" />

            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">
                            <th class="px-4 py-3 text-start">{{ t('supplier.field.name') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('supplier.field.activity') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('supplier.field.bank_name') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('supplier.field.rib') }}</th>
                            <th class="px-4 py-3 text-end">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="s in shown" :key="s.id" :class="{ 'opacity-60': !s.is_active }">
                            <template v-if="editingId === s.id">
                                <td colspan="4" class="px-4 py-3">
                                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                        <div v-for="f in ['name', 'activity', 'phone', 'bank_name', 'rib', 'address']" :key="f">
                                            <TextInput v-model="editForm[f]" class="w-full" :placeholder="t('supplier.field.' + f)" :dir="f === 'rib' || f === 'phone' ? 'ltr' : undefined" />
                                            <InputError :message="editForm.errors[f]" />
                                        </div>
                                        <label class="flex items-center gap-2 text-xs"><input v-model="editForm.is_active" type="checkbox" class="rounded" /> {{ t('active') }}</label>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-end">
                                    <div class="flex justify-end gap-2">
                                        <button @click="saveEdit(s.id)" class="text-sm text-primary-600 dark:text-primary-300">{{ t('save') }}</button>
                                        <button @click="editingId = null" class="text-sm text-slate-500 dark:text-slate-400">{{ t('cancel') }}</button>
                                    </div>
                                </td>
                            </template>
                            <template v-else>
                                <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">{{ s.name }}</td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ s.activity }}</td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ s.bank_name }}</td>
                                <td class="px-4 py-3 font-mono text-slate-600 dark:text-slate-300"><bdi dir="ltr">{{ s.rib }}</bdi></td>
                                <td class="px-4 py-3 text-end">
                                    <div class="flex justify-end gap-2">
                                        <IconButton v-if="can('suppliers', 'edit')" icon="pencil" :label="t('edit')" plain size="sm" @click="startEdit(s)" />
                                        <IconButton v-if="can('suppliers', 'delete')" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = s.id" />
                                    </div>
                                </td>
                            </template>
                        </tr>
                        <tr v-if="!shown.length">
                            <td colspan="5" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 8: Translations** (`node scripts/i18n-add.mjs <file>`)

```json
{
    "supplier.field.name": { "ar": "الاسم", "fr": "Nom", "en": "Name" },
    "supplier.field.activity": { "ar": "النشاط", "fr": "Activité", "en": "Activity" },
    "supplier.field.address": { "ar": "العنوان", "fr": "Adresse", "en": "Address" },
    "supplier.field.phone": { "ar": "الهاتف", "fr": "Téléphone", "en": "Phone" },
    "supplier.field.bank_name": { "ar": "البنك", "fr": "Banque", "en": "Bank" },
    "supplier.field.rib": { "ar": "رقم الحساب البنكي (RIB)", "fr": "RIB", "en": "Bank account (RIB)" },
    "supplier.field.notes": { "ar": "ملاحظات", "fr": "Notes", "en": "Notes" },
    "supplier.filter_placeholder": { "ar": "ابحث بالاسم أو النشاط أو RIB", "fr": "Filtrer par nom, activité ou RIB", "en": "Filter by name, activity or RIB" },
    "flash.supplier_created": { "ar": "تمت إضافة المورد.", "fr": "Fournisseur ajouté.", "en": "Supplier added." },
    "flash.supplier_updated": { "ar": "تم تحديث المورد.", "fr": "Fournisseur mis à jour.", "en": "Supplier updated." },
    "flash.supplier_deleted": { "ar": "تم حذف المورد.", "fr": "Fournisseur supprimé.", "en": "Supplier deleted." },
    "flash.supplier_in_use": { "ar": "هذا المورد مستعمل في عمليات أو أوامر دفع: عطّله بدل حذفه.", "fr": "Ce fournisseur est utilisé par des opérations ou des ordres de paiement : désactivez-le plutôt.", "en": "This supplier is used by transactions or payment orders: deactivate it instead." },
    "activity.kind.suppliers": { "ar": "الموردون", "fr": "Fournisseurs", "en": "Suppliers" }
}
```

Before adding `activity.kind.suppliers`, open `resources/js/i18n/en.json` and confirm the settings-list kind keys follow `activity.kind.<kind>` (e.g. `activity.kind.venues`); if the prefix differs, use that prefix instead.

- [ ] **Step 9: Run tests**

Run: `php artisan test --filter="SupplierListTest|UsersBatchTest"`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_11_25_100001_create_suppliers_table.php app/Models/Supplier.php app/Http/Controllers/SupplierController.php resources/js/Pages/Settings/Suppliers.vue routes/web.php config/permissions.php config/activity.php resources/js/Composables/useNavigation.js tests/Feature/SupplierListTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(suppliers): suppliers list in App Configuration"
```

---

### Task 3: Expense supplier / invoice / cheque / meeting fields + meeting PV number

**Files:**
- Create: `database/migrations/2026_11_25_100002_add_document_fields_to_transactions_and_meetings.php`
- Modify: `app/Models/Transaction.php` (fillable, casts, relations)
- Modify: `app/Models/Supplier.php` (`transactions()`, `isInUse()`)
- Modify: `app/Models/BoardMeeting.php` (fillable)
- Modify: `app/Http/Controllers/BoardMeetingController.php` (`validateCore`)
- Modify: `resources/js/Pages/Board/Meeting.vue` (form field)
- Modify: `app/Http/Requests/Transaction/StoreTransactionRequest.php`
- Modify: `app/Http/Controllers/TransactionController.php` (`formOptions`, `show` load)
- Modify: `app/Services/Finance/TransactionChanges.php` (FIELDS + labels)
- Modify: `app/Http/Controllers/TransactionImportController.php:53` and `resources/js/lib/statusLabels.js:11` (cheque)
- Modify: `resources/js/Pages/Transactions/Partials/TransactionForm.vue`, `resources/js/Pages/Transactions/Show.vue`, `resources/js/Pages/Transactions/Partials/TransactionHistory.vue`
- Test: `tests/Feature/TransactionDocumentFieldsTest.php`

**Interfaces:**
- Consumes: `Supplier` (Task 2).
- Produces: `transactions.supplier_id|invoice_number|invoice_date|cheque_date|board_meeting_id`; `Transaction::supplier()`, `Transaction::boardMeeting()`; `board_meetings.pv_number`; payment method value `cheque`; `formOptions` props `suppliers` (`id, name, bank_name, rib`) and `boardMeetings` (`id, title, meeting_date, pv_number`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\BoardMeeting;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionDocumentFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function meeting(): BoardMeeting
    {
        return BoardMeeting::create([
            'title' => 'Bureau', 'type' => 'ordinary', 'meeting_date' => '2026-11-10 18:00:00', 'status' => 'held',
        ]);
    }

    private function payload(array $extra = []): array
    {
        $category = FinanceCategory::where('type', 'expense')->first()
            ?? FinanceCategory::create(['name' => 'Equipment', 'type' => 'expense', 'is_active' => true]);
        $account = FinanceAccount::selectable()->first();

        return array_merge([
            'title' => 'Ballons', 'amount' => 16050, 'transaction_date' => '2026-11-20',
            'transaction_type' => 'expense', 'finance_category_id' => $category->id,
            'finance_account_id' => $account?->id, 'payment_method' => 'cheque',
            'payment_reference' => '1234567', 'status' => 'Paid',
        ], $extra);
    }

    #[Test]
    public function an_expense_keeps_its_supplier_invoice_cheque_and_meeting(): void
    {
        $supplier = Supplier::create(['name' => 'Sport Shop']);
        $meeting = $this->meeting();

        $this->actingAs($this->admin())->post(route('transactions.store'), $this->payload([
            'supplier_id' => $supplier->id, 'invoice_number' => 'FA-14/2026', 'invoice_date' => '2026-11-18',
            'cheque_date' => '2026-11-20', 'board_meeting_id' => $meeting->id,
        ]))->assertSessionHasNoErrors();

        $transaction = Transaction::latest('id')->first();
        $this->assertSame($supplier->id, $transaction->supplier_id);
        $this->assertSame('FA-14/2026', $transaction->invoice_number);
        $this->assertSame('2026-11-18', $transaction->invoice_date->format('Y-m-d'));
        $this->assertSame('2026-11-20', $transaction->cheque_date->format('Y-m-d'));
        $this->assertSame($meeting->id, $transaction->board_meeting_id);
        $this->assertSame('cheque', $transaction->payment_method);
    }

    #[Test]
    public function a_used_supplier_cannot_be_deleted(): void
    {
        $supplier = Supplier::create(['name' => 'Sport Shop']);
        $this->actingAs($this->admin())->post(route('transactions.store'), $this->payload(['supplier_id' => $supplier->id]));

        $this->delete(route('suppliers.destroy', $supplier))->assertSessionHas('error', 'flash.supplier_in_use');
        $this->assertModelExists($supplier);
    }

    #[Test]
    public function the_form_offers_active_suppliers_and_meetings(): void
    {
        Supplier::create(['name' => 'Active One', 'rib' => '00100000000000001234']);
        Supplier::create(['name' => 'Gone', 'is_active' => false]);
        $this->meeting();

        $this->actingAs($this->admin())->get(route('transactions.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('suppliers', 1)
                ->where('suppliers.0.name', 'Active One')
                ->where('suppliers.0.rib', '00100000000000001234')
                ->has('boardMeetings', 1));
    }

    #[Test]
    public function editing_the_supplier_is_kept_in_the_history(): void
    {
        $a = Supplier::create(['name' => 'Shop A']);
        $b = Supplier::create(['name' => 'Shop B']);
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('transactions.store'), $this->payload(['supplier_id' => $a->id]));
        $transaction = Transaction::latest('id')->first();

        $this->put(route('transactions.update', $transaction), $this->payload(['supplier_id' => $b->id]))->assertSessionHasNoErrors();

        $diff = $transaction->revisions()->latest('id')->first()->diff;
        $this->assertSame('Shop A', $diff['supplier_id']['old_label']);
        $this->assertSame('Shop B', $diff['supplier_id']['new_label']);
    }

    #[Test]
    public function a_meeting_keeps_its_pv_number(): void
    {
        $meeting = $this->meeting();

        $this->actingAs($this->admin())->put(route('board.meetings.update', $meeting), [
            'title' => 'Bureau', 'type' => 'ordinary', 'meeting_date' => '2026-11-10 18:00:00',
            'status' => 'held', 'pv_number' => '07/2026',
        ])->assertSessionHasNoErrors();

        $this->assertSame('07/2026', $meeting->fresh()->pv_number);
    }
}
```

Before running: check `app/Models/Transaction.php` for the revisions relation name (`TransactionRevision` has `transaction_id`); if the model has no `revisions()` relation, add `public function revisions(): HasMany { return $this->hasMany(TransactionRevision::class); }` in Step 4. Also check how `FinanceCategory` and `FinanceAccount` rows exist in a fresh test DB (look at `tests/Feature/TransactionTitleFlowTest.php` setup) and copy that setup into `payload()` if `FinanceAccount::selectable()->first()` returns null.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TransactionDocumentFieldsTest`
Expected: FAIL (unknown column `supplier_id`).

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a paper expense file carries (old Access app, owner 2026-11-25): the
 * supplier, their invoice, the cheque date (number stays in payment_reference)
 * and the board meeting that approved it — whose minutes get a PV number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('payment_reference')->constrained('suppliers')->nullOnDelete();
            $table->string('invoice_number', 100)->nullable()->after('supplier_id');
            $table->date('invoice_date')->nullable()->after('invoice_number');
            $table->date('cheque_date')->nullable()->after('invoice_date');
            $table->foreignId('board_meeting_id')->nullable()->after('cheque_date')->constrained('board_meetings')->nullOnDelete();
        });

        Schema::table('board_meetings', function (Blueprint $table) {
            $table->string('pv_number', 50)->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('board_meeting_id');
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['invoice_number', 'invoice_date', 'cheque_date']);
        });

        Schema::table('board_meetings', function (Blueprint $table) {
            $table->dropColumn('pv_number');
        });
    }
};
```

- [ ] **Step 4: Models**

`Transaction.php`: add `'supplier_id', 'invoice_number', 'invoice_date', 'cheque_date', 'board_meeting_id',` to `$fillable`; add to `casts()`:

```php
            'invoice_date' => 'date:Y-m-d',
            'cheque_date' => 'date:Y-m-d',
```

and relations:

```php
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function boardMeeting(): BelongsTo
    {
        return $this->belongsTo(BoardMeeting::class);
    }
```

`Supplier.php`:

```php
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** A supplier something points at stays (deactivate it instead). */
    public function isInUse(): bool
    {
        return $this->transactions()->exists();
    }
```

(import `Illuminate\Database\Eloquent\Relations\HasMany`).

`BoardMeeting.php`: add `'pv_number'` to `$fillable`.

- [ ] **Step 5: Meeting PV number**

`BoardMeetingController::validateCore()` add:

```php
            'pv_number' => ['nullable', 'string', 'max:50'],
```

`resources/js/Pages/Board/Meeting.vue`: add `pv_number: props.meeting.pv_number || '',` to the `useForm` object, and after the location `<label>` block (line ~176) add:

```vue
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('meeting.pv_number') }}</span>
                            <input v-model="form.pv_number" dir="ltr" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
```

If the meeting create form (in `Board/Meetings.vue`) posts through `validateCore` too, nothing else is needed there (the field is optional).

- [ ] **Step 6: Request + controller**

`StoreTransactionRequest::rules()` — change `payment_method` and add fields:

```php
            'payment_method' => ['nullable', 'in:cash,bank,ccp,baridimob,cheque,other'],
            // …existing payment_* rules unchanged…
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'invoice_date' => ['nullable', 'date'],
            'cheque_date' => ['nullable', 'date'],
            'board_meeting_id' => ['nullable', 'integer', Rule::exists('board_meetings', 'id')],
```

Before narrowing `payment_method` to a list, grep existing data paths: `grep -rn "payment_method' =>" app database/seeders` — every value written must be in the list (cash, bank, ccp, baridimob, other are the known ones). If another value appears, add it to the list.

`TransactionController::formOptions()` — add to the returned array:

```php
            // Active suppliers, plus the entry's own one if it was deactivated since.
            'suppliers' => Supplier::query()
                ->where(fn (Builder $q) => $q->where('is_active', true)
                    ->when($transaction?->supplier_id, fn (Builder $q, $id) => $q->orWhere('id', $id)))
                ->orderBy('name')
                ->get(['id', 'name', 'bank_name', 'rib']),
            'boardMeetings' => BoardMeeting::query()
                ->whereNull('cancelled_at')
                ->orderByDesc('meeting_date')
                ->limit(200)
                ->get(['id', 'title', 'meeting_date', 'pv_number']),
```

`TransactionController::show()` — add `'supplier:id,name', 'boardMeeting:id,title,meeting_date,pv_number',` to the `load([...])` list.

- [ ] **Step 7: History tracking**

`TransactionChanges::FIELDS` — after `'payment_reference' => 'text',` add:

```php
        'supplier_id' => 'id',
        'invoice_number' => 'text',
        'invoice_date' => 'date',
        'cheque_date' => 'date',
        'board_meeting_id' => 'id',
```

In `withLabels()` add before `return $changes;`:

```php
        if (isset($changes['supplier_id'])) {
            $names = Supplier::whereIn('id', self::ids($changes['supplier_id']))->pluck('name', 'id');
            $changes['supplier_id'] = self::labelled($changes['supplier_id'], $names->all());
        }

        if (isset($changes['board_meeting_id'])) {
            $names = BoardMeeting::whereIn('id', self::ids($changes['board_meeting_id']))->get(['id', 'title', 'pv_number'])
                ->mapWithKeys(fn (BoardMeeting $m) => [$m->id => trim(($m->pv_number ? $m->pv_number.' · ' : '').$m->title)]);
            $changes['board_meeting_id'] = self::labelled($changes['board_meeting_id'], $names->all());
        }
```

`TransactionHistory.vue` `fieldLabel` map — add:

```js
    supplier_id: t('tx.supplier'),
    invoice_number: t('tx.invoice_number'),
    invoice_date: t('tx.invoice_date'),
    cheque_date: t('tx.cheque_date'),
    board_meeting_id: t('tx.board_meeting'),
```

and in `shown()` add `case 'invoice_date': case 'cheque_date': return formatDate(value);`. Check how id fields with labels are rendered (finance_category_id uses `old_label`/`new_label`) and make sure `supplier_id` and `board_meeting_id` take the same branch — search the template for `finance_category_id` or `_label` and add the two fields to that condition if it lists fields by name.

- [ ] **Step 8: Cheque method everywhere**

`resources/js/lib/statusLabels.js` line 11:

```js
    payment_method: { cash: 'cash', bank: 'bank_transfer', ccp: 'ccp', baridimob: 'baridimob', cheque: 'cheque', other: 'other' },
```

`TransactionImportController::PAYMENT_METHODS`:

```php
    private const PAYMENT_METHODS = ['cash' => 'cash', 'bank' => 'bank_transfer', 'ccp' => 'ccp', 'baridimob' => 'baridimob', 'cheque' => 'cheque', 'other' => 'other'];
```

- [ ] **Step 9: Expense form + show page**

`TransactionForm.vue` props add `suppliers: { type: Array, default: () => [] }, boardMeetings: { type: Array, default: () => [] },`; `useForm` add:

```js
    supplier_id: props.transaction?.supplier_id || '',
    invoice_number: props.transaction?.invoice_number || '',
    invoice_date: props.transaction?.invoice_date ? String(props.transaction.invoice_date).slice(0, 10) : '',
    cheque_date: props.transaction?.cheque_date ? String(props.transaction.cheque_date).slice(0, 10) : '',
    board_meeting_id: props.transaction?.board_meeting_id || '',
```

script additions:

```js
const supplierOptions = computed(() => props.suppliers.map((s) => ({ value: s.id, label: s.name, description: s.rib || '' })));
const meetingLabel = (m) => [m.pv_number, m.title, String(m.meeting_date).slice(0, 10)].filter(Boolean).join(' · ');
```

In `submit()`'s transform, add `supplier_id: data.supplier_id || null, board_meeting_id: data.board_meeting_id || null,`.

Template: add `<option value="cheque">{{ t('cheque') }}</option>` before `other` in the payment method select; after the bank details block add:

```vue
            <!-- Cheque details: the number is the payment reference -->
            <div v-if="form.payment_method === 'cheque'" class="rounded-xl bg-slate-50 dark:bg-slate-950 p-4">
                <p class="mb-3 text-sm font-semibold text-slate-600 dark:text-slate-300">{{ t('tx.cheque_details') }}</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div><InputLabel :value="t('tx.cheque_number')" /><TextInput v-model="form.payment_reference" dir="ltr" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="t('tx.cheque_date')" /><TextInput v-model="form.cheque_date" type="date" class="mt-1 w-full" /><InputError :message="form.errors.cheque_date" class="mt-1" /></div>
                </div>
            </div>

            <!-- Expense paperwork: who was paid, their invoice, the meeting that approved it -->
            <div v-if="form.transaction_type === 'expense'" class="grid grid-cols-1 gap-4 sm:grid-cols-2" data-help="txf-paperwork">
                <div class="sm:col-span-2">
                    <InputLabel :value="t('tx.supplier')" />
                    <SearchableSelect v-model="form.supplier_id" :options="supplierOptions" :placeholder="t('tx.supplier_placeholder')" />
                    <InputError :message="form.errors.supplier_id" class="mt-1" />
                </div>
                <div><InputLabel :value="t('tx.invoice_number')" /><TextInput v-model="form.invoice_number" dir="ltr" class="mt-1 w-full" /><InputError :message="form.errors.invoice_number" class="mt-1" /></div>
                <div><InputLabel :value="t('tx.invoice_date')" /><TextInput v-model="form.invoice_date" type="date" class="mt-1 w-full" /><InputError :message="form.errors.invoice_date" class="mt-1" /></div>
                <div class="sm:col-span-2">
                    <InputLabel :value="t('tx.board_meeting')" />
                    <select v-model="form.board_meeting_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm">
                        <option value="">—</option>
                        <option v-for="m in boardMeetings" :key="m.id" :value="m.id">{{ meetingLabel(m) }}</option>
                    </select>
                    <InputError :message="form.errors.board_meeting_id" class="mt-1" />
                </div>
            </div>
```

Pass the new props from `Transactions/Create.vue` and `Transactions/Edit.vue` to `<TransactionForm>` (`:suppliers="suppliers" :board-meetings="boardMeetings"`, declaring both props there).

`Show.vue` `paymentDetails` computed — add rows (they filter out empties):

```js
    { label: t('tx.supplier'), value: props.transaction.supplier?.name },
    { label: t('tx.cheque_date'), value: props.transaction.cheque_date ? formatDate(props.transaction.cheque_date) : null },
    { label: t('tx.invoice_number'), value: props.transaction.invoice_number },
    { label: t('tx.invoice_date'), value: props.transaction.invoice_date ? formatDate(props.transaction.invoice_date) : null },
    { label: t('tx.board_meeting'), value: props.transaction.board_meeting ? [props.transaction.board_meeting.pv_number, props.transaction.board_meeting.title].filter(Boolean).join(' · ') : null },
```

(`paymentDetails` is declared after `useFormatDate()`; keep that order.)

- [ ] **Step 10: Translations**

```json
{
    "cheque": { "ar": "صك", "fr": "Chèque", "en": "Cheque" },
    "tx.cheque_details": { "ar": "تفاصيل الصك", "fr": "Détails du chèque", "en": "Cheque details" },
    "tx.cheque_number": { "ar": "رقم الصك", "fr": "N° du chèque", "en": "Cheque number" },
    "tx.cheque_date": { "ar": "تاريخ الصك", "fr": "Date du chèque", "en": "Cheque date" },
    "tx.supplier": { "ar": "المورد", "fr": "Fournisseur", "en": "Supplier" },
    "tx.supplier_placeholder": { "ar": "اختر موردًا", "fr": "Choisir un fournisseur", "en": "Choose a supplier" },
    "tx.invoice_number": { "ar": "رقم الفاتورة أو الوصل", "fr": "N° facture / reçu", "en": "Invoice / receipt no." },
    "tx.invoice_date": { "ar": "تاريخ الفاتورة", "fr": "Date de la facture", "en": "Invoice date" },
    "tx.board_meeting": { "ar": "اجتماع المكتب (المصادقة)", "fr": "Réunion du bureau (approbation)", "en": "Board meeting (approval)" },
    "meeting.pv_number": { "ar": "رقم المحضر", "fr": "N° du PV", "en": "Minutes (PV) no." }
}
```

Check first that `tx` and `meeting` are not themselves message keys whose next segment would collide (`grep -n '"tx":\|"meeting":' resources/js/i18n/en.json` — `"meeting"` as a message is fine because `pv_number` is not a String method).

- [ ] **Step 11: Run tests**

Run: `php artisan test --filter="TransactionDocumentFieldsTest|TransactionEditHistoryTest|TransactionChangesTest|TransactionFormOptionsTest|TransactionImportRoundTripTest|BoardMeeting"`
Expected: PASS.

- [ ] **Step 12: Commit**

```bash
git add database/migrations/2026_11_25_100002_add_document_fields_to_transactions_and_meetings.php app/Models/Transaction.php app/Models/Supplier.php app/Models/BoardMeeting.php app/Http/Controllers/BoardMeetingController.php resources/js/Pages/Board/Meeting.vue app/Http/Requests/Transaction/StoreTransactionRequest.php app/Http/Controllers/TransactionController.php app/Services/Finance/TransactionChanges.php app/Http/Controllers/TransactionImportController.php resources/js/lib/statusLabels.js resources/js/Pages/Transactions tests/Feature/TransactionDocumentFieldsTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(transactions): supplier, invoice, cheque and approving meeting on expenses; meeting PV number"
```

---

### Task 4: Yearly numbering + payment orders table and model

**Files:**
- Create: `app/Services/Documents/YearlySequence.php`
- Create: `database/migrations/2026_11_25_100003_create_payment_orders_table.php`
- Create: `app/Models/PaymentOrder.php`
- Modify: `app/Models/Supplier.php` (`paymentOrders()`, `isInUse()`)
- Test: `tests/Feature/PaymentOrderModelTest.php`

**Interfaces:**
- Produces:
  - `YearlySequence::next(string $table, int $year): int` — call inside a DB transaction.
  - `YearlySequence::retrying(callable $attempt): mixed` — runs `$attempt`, runs it once more on `UniqueConstraintViolationException`.
  - `PaymentOrder` constants `DRAFT|APPROVED|PAID|CANCELLED`, `STATUSES`, `BENEFICIARY_TYPES = ['supplier','user','board_member']`, `METHODS = ['cash','bank','ccp','baridimob','cheque','other']`; appended attribute `reference` (`NN/YYYY` or `legacy_reference`); `liveBeneficiaryName(): ?string`; `isEditable(): bool`; relations `financeCategory, financeAccount, boardMeeting, transaction, createdBy, approvedBy, cancelledBy, supplier` (`supplier` = belongsTo via `beneficiary_id` — only meaningful when type is supplier).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\BoardMember;
use App\Models\PaymentOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Documents\YearlySequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentOrderModelTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $attributes = []): PaymentOrder
    {
        return PaymentOrder::create(array_merge([
            'year' => 2026, 'number' => 1, 'status' => PaymentOrder::DRAFT,
            'order_date' => '2026-11-25', 'subject' => 'Ballons', 'amount' => 16050,
        ], $attributes));
    }

    #[Test]
    public function the_sequence_restarts_each_year_and_ignores_legacy_rows(): void
    {
        $this->order(['year' => 2026, 'number' => 1]);
        $this->order(['year' => 2026, 'number' => 2]);
        $this->order(['year' => 2025, 'number' => 9]);
        $this->order(['year' => 2026, 'number' => null, 'is_legacy' => true, 'legacy_reference' => '07/2026']);

        $this->assertSame(3, DB::transaction(fn () => YearlySequence::next('payment_orders', 2026)));
        $this->assertSame(10, DB::transaction(fn () => YearlySequence::next('payment_orders', 2025)));
        $this->assertSame(1, DB::transaction(fn () => YearlySequence::next('payment_orders', 2027)));
    }

    #[Test]
    public function a_number_cannot_be_used_twice_in_a_year(): void
    {
        $this->order(['number' => 4]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->order(['number' => 4]);
    }

    #[Test]
    public function retrying_runs_the_attempt_again_once_after_a_clash(): void
    {
        $calls = 0;
        $result = YearlySequence::retrying(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                $this->order(['number' => 1]);
                $this->order(['number' => 1]);   // clash
            }

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(2, $calls);
    }

    #[Test]
    public function the_reference_is_number_slash_year_or_the_legacy_text(): void
    {
        $this->assertSame('03/2026', $this->order(['number' => 3])->reference);
        $this->assertSame('114/2026', $this->order(['number' => 114])->reference);
        $this->assertSame('01/2022', $this->order(['number' => null, 'is_legacy' => true, 'legacy_reference' => '01/2022', 'year' => 2022])->reference);
    }

    #[Test]
    public function the_live_beneficiary_name_follows_its_type(): void
    {
        $supplier = Supplier::create(['name' => 'Sport Shop']);
        $user = User::factory()->create(['name' => 'Treasurer User']);
        $member = BoardMember::create(['name' => 'Board Person', 'role' => 'treasurer', 'status' => 'active']);

        $this->assertSame('Sport Shop', $this->order(['number' => 1, 'beneficiary_type' => 'supplier', 'beneficiary_id' => $supplier->id])->liveBeneficiaryName());
        $this->assertSame($user->name, $this->order(['number' => 2, 'beneficiary_type' => 'user', 'beneficiary_id' => $user->id])->liveBeneficiaryName());
        $this->assertSame('Board Person', $this->order(['number' => 3, 'beneficiary_type' => 'board_member', 'beneficiary_id' => $member->id])->liveBeneficiaryName());
        $this->assertNull($this->order(['number' => 4])->liveBeneficiaryName());
    }

    #[Test]
    public function a_supplier_with_a_payment_order_is_in_use(): void
    {
        $supplier = Supplier::create(['name' => 'Sport Shop']);
        $this->assertFalse($supplier->isInUse());

        $this->order(['beneficiary_type' => 'supplier', 'beneficiary_id' => $supplier->id]);

        $this->assertTrue($supplier->fresh()->isInUse());
    }
}
```

Note: `User` `name` may be computed from firstname/lastname (users batch Oct). If `User::factory()->create(['name' => …])` does not keep the name, the assertion uses `$user->name` already — keep it that way.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PaymentOrderModelTest`
Expected: FAIL (class `PaymentOrder` not found).

- [ ] **Step 3: `YearlySequence`**

```php
<?php

namespace App\Services\Documents;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Per-year document numbers (payment orders now; purchase orders and the
 * mail register later): the year's highest number plus one. Gaps stay —
 * cancelled documents keep their number. Each numbered table carries a unique
 * index on (year, number), so two people saving at the same instant cannot
 * share a number: the second insert fails and retrying() runs it again.
 */
final class YearlySequence
{
    /** The next number for $year. Call inside the DB transaction that inserts it. */
    public static function next(string $table, int $year): int
    {
        return (int) DB::table($table)->where('year', $year)->max('number') + 1;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $attempt
     * @return T
     */
    public static function retrying(callable $attempt): mixed
    {
        try {
            return $attempt();
        } catch (UniqueConstraintViolationException) {
            return $attempt();
        }
    }
}
```

- [ ] **Step 4: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment orders (الأمر بالدفع): draft → approved → paid (the expense is then
 * created and linked) or cancelled. Numbered NN/YYYY per calendar year; legacy
 * rows imported from the old Access app keep their text reference and no
 * number. Orders are never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('number')->nullable();
            $table->string('legacy_reference', 50)->nullable();
            $table->boolean('is_legacy')->default(false);
            $table->string('legacy_source', 20)->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('order_date');
            $table->string('subject', 150);
            $table->decimal('amount', 12, 2);
            $table->foreignId('finance_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finance_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_method', 20)->nullable();
            $table->string('beneficiary_type', 20)->nullable();
            $table->unsignedBigInteger('beneficiary_id')->nullable();
            $table->string('beneficiary_name', 200)->nullable();
            $table->string('beneficiary_bank', 150)->nullable();
            $table->string('beneficiary_rib', 30)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->date('payment_date')->nullable();
            $table->string('invoice_number', 100)->nullable();
            $table->date('invoice_date')->nullable();
            $table->foreignId('board_meeting_id')->nullable()->constrained()->nullOnDelete();
            // FK added with purchase orders (P2).
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->text('observation')->nullable();
            $table->foreignId('transaction_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['year', 'number']);
            $table->index(['beneficiary_type', 'beneficiary_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_orders');
    }
};
```

- [ ] **Step 5: Model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentOrder extends Model
{
    use SerializesClubDates;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::APPROVED, self::PAID, self::CANCELLED];

    public const BENEFICIARY_TYPES = ['supplier', 'user', 'board_member'];

    public const METHODS = ['cash', 'bank', 'ccp', 'baridimob', 'cheque', 'other'];

    protected $fillable = [
        'year', 'number', 'legacy_reference', 'is_legacy', 'legacy_source', 'status', 'order_date',
        'subject', 'amount', 'finance_category_id', 'finance_account_id', 'payment_method',
        'beneficiary_type', 'beneficiary_id', 'beneficiary_name', 'beneficiary_bank', 'beneficiary_rib',
        'payment_reference', 'payment_date', 'invoice_number', 'invoice_date', 'board_meeting_id',
        'purchase_order_id', 'observation', 'transaction_id', 'created_by', 'approved_by', 'approved_at',
        'paid_at', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $appends = ['reference'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'number' => 'integer',
            'is_legacy' => 'boolean',
            'amount' => 'decimal:2',
            'order_date' => 'date:Y-m-d',
            'payment_date' => 'date:Y-m-d',
            'invoice_date' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** NN/YYYY, or the old Access reference for imported rows. */
    protected function reference(): Attribute
    {
        return Attribute::make(get: fn () => $this->is_legacy || $this->number === null
            ? (string) $this->legacy_reference
            : sprintf('%02d/%d', $this->number, $this->year));
    }

    /** Only a draft is changed; approved, paid, cancelled and imported orders are locked. */
    public function isEditable(): bool
    {
        return $this->status === self::DRAFT && ! $this->is_legacy;
    }

    /** The beneficiary's name as its record says it now (drafts show this; approval freezes it). */
    public function liveBeneficiaryName(): ?string
    {
        if ($this->beneficiary_id === null) {
            return null;
        }

        return match ($this->beneficiary_type) {
            'supplier' => Supplier::whereKey($this->beneficiary_id)->value('name'),
            'user' => User::find($this->beneficiary_id)?->name,
            'board_member' => BoardMember::withPlayer()->find($this->beneficiary_id)?->name,
            default => null,
        };
    }

    /** The name printed and listed: frozen once approved, live while draft. */
    public function beneficiaryLabel(): ?string
    {
        return $this->status === self::DRAFT ? ($this->liveBeneficiaryName() ?? $this->beneficiary_name) : $this->beneficiary_name;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'beneficiary_id');
    }

    public function financeCategory(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class);
    }

    public function financeAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class);
    }

    public function boardMeeting(): BelongsTo
    {
        return $this->belongsTo(BoardMeeting::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
```

- [ ] **Step 6: Supplier in-use covers payment orders**

`Supplier.php`:

```php
    public function paymentOrders(): HasMany
    {
        return $this->hasMany(PaymentOrder::class, 'beneficiary_id')->where('beneficiary_type', 'supplier');
    }

    /** A supplier something points at stays (deactivate it instead). */
    public function isInUse(): bool
    {
        return $this->transactions()->exists() || $this->paymentOrders()->exists();
    }
```

- [ ] **Step 7: Run tests**

Run: `php artisan test --filter="PaymentOrderModelTest|SupplierListTest"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Documents/YearlySequence.php database/migrations/2026_11_25_100003_create_payment_orders_table.php app/Models/PaymentOrder.php app/Models/Supplier.php tests/Feature/PaymentOrderModelTest.php
git commit -m "feat(payment-orders): payment orders table, model and yearly numbering"
```

---

### Task 5: `PaymentOrderService` (create, update, approve, mark paid, cancel, from expense)

**Files:**
- Create: `app/Exceptions/PaymentOrderException.php`
- Create: `app/Services/Finance/PaymentOrderService.php`
- Modify: `app/Services/Activity/ActivityAction.php` (codes + ALL + AREAS['money'])
- Modify: `app/Services/Activity/ActivitySubjectLink.php` (MODULES + describe)
- Test: `tests/Feature/PaymentOrderServiceTest.php`

**Interfaces:**
- Consumes: `PaymentOrder`, `YearlySequence` (Task 4), transaction fields (Task 3).
- Produces (all return the fresh `PaymentOrder`, throw `PaymentOrderException`):
  - `create(array $data, User $by): PaymentOrder` — `$data` keys = validated form fields (see Task 6 request).
  - `update(PaymentOrder $order, array $data, User $by): PaymentOrder`
  - `approve(PaymentOrder $order, User $by): PaymentOrder`
  - `markPaid(PaymentOrder $order, User $by, ?string $paymentDate = null): PaymentOrder`
  - `cancel(PaymentOrder $order, User $by, string $reason): PaymentOrder`
  - `fromExpense(Transaction $transaction, User $by): PaymentOrder`
  - Activity codes `ActivityAction::PAYMENT_ORDER_CREATED|UPDATED|APPROVED|PAID|CANCELLED|PRINTED`.
  - `PaymentOrderException::$flashKey` (`flash.payment_order_locked`, `flash.payment_order_year_locked`, `flash.payment_order_not_draft`, `flash.payment_order_no_beneficiary`, `flash.payment_order_not_approved`, `flash.payment_order_missing_finance`, `flash.payment_order_cannot_cancel`, `flash.payment_order_not_expense`, `flash.payment_order_exists`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Exceptions\PaymentOrderException;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\PaymentOrder;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Finance\PaymentOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentOrderService $service;

    private User $user;

    private FinanceCategory $category;

    private FinanceAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->service = app(PaymentOrderService::class);
        $this->user = User::factory()->admin()->create();
        $this->category = FinanceCategory::where('type', 'expense')->first()
            ?? FinanceCategory::create(['name' => 'Equipment', 'type' => 'expense', 'is_active' => true]);
        $this->account = FinanceAccount::selectable()->first()
            ?? FinanceAccount::create(['name' => 'Caisse', 'type' => 'cash', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function data(array $extra = []): array
    {
        $supplier = Supplier::firstOrCreate(['name' => 'Sport Shop'], ['bank_name' => 'BNA', 'rib' => '00100000000000001234']);

        return array_merge([
            'order_date' => '2026-11-25', 'subject' => 'Ballons U15', 'amount' => 16050,
            'finance_category_id' => $this->category->id, 'finance_account_id' => $this->account->id,
            'payment_method' => 'bank', 'beneficiary_type' => 'supplier', 'beneficiary_id' => $supplier->id,
        ], $extra);
    }

    #[Test]
    public function create_numbers_the_order_as_a_draft(): void
    {
        $first = $this->service->create($this->data(), $this->user);
        $second = $this->service->create($this->data(), $this->user);

        $this->assertSame(PaymentOrder::DRAFT, $first->status);
        $this->assertSame('01/2026', $first->reference);
        $this->assertSame('02/2026', $second->reference);
        $this->assertSame($this->user->id, $first->created_by);
        $this->assertDatabaseHas('activity_logs', ['action' => ActivityAction::PAYMENT_ORDER_CREATED, 'subject_id' => $first->id]);
    }

    #[Test]
    public function the_year_comes_from_the_order_date(): void
    {
        $order = $this->service->create($this->data(['order_date' => '2025-12-30']), $this->user);

        $this->assertSame('01/2025', $order->reference);
    }

    #[Test]
    public function a_draft_can_be_edited_but_not_moved_to_another_year(): void
    {
        $order = $this->service->create($this->data(), $this->user);

        $this->service->update($order, $this->data(['subject' => 'Ballons U17']), $this->user);
        $this->assertSame('Ballons U17', $order->fresh()->subject);

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_year_locked'));
        $this->service->update($order->fresh(), $this->data(['order_date' => '2027-01-02']), $this->user);
    }

    #[Test]
    public function approval_freezes_the_beneficiary_and_locks_the_order(): void
    {
        $order = $this->service->create($this->data(['beneficiary_bank' => null, 'beneficiary_rib' => null]), $this->user);

        $approved = $this->service->approve($order, $this->user);

        $this->assertSame(PaymentOrder::APPROVED, $approved->status);
        $this->assertSame('Sport Shop', $approved->beneficiary_name);
        $this->assertSame('BNA', $approved->beneficiary_bank);
        $this->assertSame('00100000000000001234', $approved->beneficiary_rib);
        $this->assertNotNull($approved->approved_at);

        Supplier::first()->update(['name' => 'Renamed Shop']);
        $this->assertSame('Sport Shop', $approved->fresh()->beneficiaryLabel());

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_locked'));
        $this->service->update($approved, $this->data(['subject' => 'Changed']), $this->user);
    }

    #[Test]
    public function an_order_without_beneficiary_cannot_be_approved(): void
    {
        $order = $this->service->create($this->data(['beneficiary_type' => null, 'beneficiary_id' => null]), $this->user);

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_no_beneficiary'));
        $this->service->approve($order, $this->user);
    }

    #[Test]
    public function marking_paid_creates_exactly_one_linked_expense(): void
    {
        $order = $this->service->approve($this->service->create($this->data([
            'invoice_number' => 'FA-9', 'invoice_date' => '2026-11-20', 'payment_reference' => 'VIR-77',
        ]), $this->user), $this->user);

        $paid = $this->service->markPaid($order, $this->user, '2026-11-26');

        $this->assertSame(PaymentOrder::PAID, $paid->status);
        $transaction = Transaction::findOrFail($paid->transaction_id);
        $this->assertSame('expense', $transaction->transaction_type);
        $this->assertSame('Ballons U15', $transaction->title);
        $this->assertSame('16050.00', $transaction->amount);
        $this->assertSame('2026-11-26', $transaction->transaction_date->format('Y-m-d'));
        $this->assertSame(Supplier::first()->id, $transaction->supplier_id);
        $this->assertSame('FA-9', $transaction->invoice_number);
        $this->assertSame('VIR-77', $transaction->payment_reference);
        $this->assertSame('00100000000000001234', $transaction->payment_account);
        $this->assertSame('Sport Shop', $transaction->payment_holder);
        $this->assertSame('Paid', $transaction->status);

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_not_approved'));
        $this->service->markPaid($paid, $this->user);
    }

    #[Test]
    public function a_draft_cannot_be_paid(): void
    {
        $order = $this->service->create($this->data(), $this->user);

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_not_approved'));
        $this->service->markPaid($order, $this->user);
    }

    #[Test]
    public function paying_needs_a_category_and_a_register(): void
    {
        $order = $this->service->approve($this->service->create($this->data(['finance_account_id' => null]), $this->user), $this->user);

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_missing_finance'));
        $this->service->markPaid($order, $this->user);
    }

    #[Test]
    public function draft_and_approved_orders_can_be_cancelled_but_not_paid_ones(): void
    {
        $draft = $this->service->cancel($this->service->create($this->data(), $this->user), $this->user, 'Doublon');
        $this->assertSame(PaymentOrder::CANCELLED, $draft->status);
        $this->assertSame('Doublon', $draft->cancel_reason);
        $this->assertSame('01/2026', $draft->reference);   // keeps its number

        $paid = $this->service->markPaid($this->service->approve($this->service->create($this->data(), $this->user), $this->user), $this->user);

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_cannot_cancel'));
        $this->service->cancel($paid, $this->user, 'Trop tard');
    }

    #[Test]
    public function an_existing_expense_gets_a_paid_order_once(): void
    {
        $supplier = Supplier::create(['name' => 'Printer', 'rib' => '00700000000000005678']);
        $transaction = Transaction::create([
            'title' => 'Impression maillots', 'amount' => 12300, 'transaction_date' => '2026-10-12',
            'transaction_type' => 'expense', 'finance_category_id' => $this->category->id, 'category' => 'equipment',
            'finance_account_id' => $this->account->id, 'payment_method' => 'bank', 'payment_account' => '00700000000000005678',
            'payment_holder' => 'Printer', 'supplier_id' => $supplier->id, 'status' => 'Paid',
        ]);

        $order = $this->service->fromExpense($transaction, $this->user);

        $this->assertSame(PaymentOrder::PAID, $order->status);
        $this->assertSame($transaction->id, $order->transaction_id);
        $this->assertSame('01/2026', $order->reference);
        $this->assertSame('Printer', $order->beneficiary_name);
        $this->assertSame('supplier', $order->beneficiary_type);
        $this->assertSame(1, Transaction::count());

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_exists'));
        $this->service->fromExpense($transaction, $this->user);
    }

    #[Test]
    public function an_income_never_gets_a_payment_order(): void
    {
        $transaction = Transaction::create([
            'title' => 'Cotisation', 'amount' => 1500, 'transaction_date' => '2026-10-12',
            'transaction_type' => 'income', 'category' => 'subscription', 'status' => 'Paid',
        ]);

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_not_expense'));
        $this->service->fromExpense($transaction, $this->user);
    }
}
```

Before running: open `tests/Feature/TransactionTitleFlowTest.php` (or any transaction store test) to see how a fresh test DB gets its finance categories / accounts / fiscal year (seeded by migrations or created in setUp) and adjust `setUp()` so `$this->category` and `$this->account` are valid rows; `FinanceAccount::create([...])` above uses guessed columns — copy the real ones from that test. The `TransactionObserver` needs an open fiscal year for 2026-11 and 2026-10.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PaymentOrderServiceTest`
Expected: FAIL (class `PaymentOrderService` not found).

- [ ] **Step 3: Exception**

```php
<?php

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * A payment order step the order's state refuses (editing an approved order,
 * paying a draft…). An expected refusal: rendered as the flash error it names,
 * never logged.
 */
class PaymentOrderException extends RuntimeException
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

In `ActivityAction.php`, after `const BUDGET_UPDATED = 'budget_updated';` add:

```php
    const PAYMENT_ORDER_CREATED = 'payment_order_created';

    const PAYMENT_ORDER_UPDATED = 'payment_order_updated';

    const PAYMENT_ORDER_APPROVED = 'payment_order_approved';

    const PAYMENT_ORDER_PAID = 'payment_order_paid';

    const PAYMENT_ORDER_CANCELLED = 'payment_order_cancelled';

    const PAYMENT_ORDER_PRINTED = 'payment_order_printed';

    const LEGACY_DATA_IMPORTED = 'legacy_data_imported';
```

Append the seven constants to `ALL` (after `self::BUDGET_UPDATED,`) and to `AREAS['money']` (after `self::FINANCE_RESET,`).

`ActivitySubjectLink.php`: add `use App\Models\PaymentOrder;`, `PaymentOrder::class => ['payment_orders'],` to `MODULES`, and in the private `describe()` method add an arm in the same style as the `Transaction` arm returning label `$subject->reference.' · '.$subject->subject` and url `route('payment-orders.show', $subject)`. (The route is added in Task 6; until then `route()` is only called when a log with a PaymentOrder subject is displayed.)

- [ ] **Step 5: Service**

```php
<?php

namespace App\Services\Finance;

use App\Exceptions\PaymentOrderException;
use App\Models\FinanceCategory;
use App\Models\PaymentOrder;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Documents\YearlySequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every payment order state change, one DB transaction each:
 * draft (numbered) → approved (beneficiary frozen) → paid (expense created) or cancelled.
 */
final class PaymentOrderService
{
    /** @param array<string, mixed> $data validated form fields */
    public function create(array $data, User $by): PaymentOrder
    {
        return YearlySequence::retrying(fn () => DB::transaction(function () use ($data, $by) {
            $year = Carbon::parse($data['order_date'])->year;
            $order = PaymentOrder::create([
                ...$data,
                'year' => $year,
                'number' => YearlySequence::next('payment_orders', $year),
                'status' => PaymentOrder::DRAFT,
                'created_by' => $by->id,
            ]);
            ActivityRecorder::record($by, ActivityAction::PAYMENT_ORDER_CREATED, $order, [
                'amount' => (float) $order->amount, 'reference' => $order->reference,
            ]);

            return $order->fresh();
        }));
    }

    /** @param array<string, mixed> $data */
    public function update(PaymentOrder $order, array $data, User $by): PaymentOrder
    {
        if (! $order->isEditable()) {
            throw new PaymentOrderException('flash.payment_order_locked');
        }
        if (Carbon::parse($data['order_date'])->year !== $order->year) {
            throw new PaymentOrderException('flash.payment_order_year_locked');
        }

        return DB::transaction(function () use ($order, $data, $by) {
            $order->update($data);
            ActivityRecorder::record($by, ActivityAction::PAYMENT_ORDER_UPDATED, $order, ['reference' => $order->reference]);

            return $order->fresh();
        });
    }

    public function approve(PaymentOrder $order, User $by): PaymentOrder
    {
        if ($order->status !== PaymentOrder::DRAFT || $order->is_legacy) {
            throw new PaymentOrderException('flash.payment_order_not_draft');
        }
        if ($order->beneficiary_type === null || $order->beneficiary_id === null) {
            throw new PaymentOrderException('flash.payment_order_no_beneficiary');
        }

        return DB::transaction(function () use ($order, $by) {
            $supplier = $order->beneficiary_type === 'supplier' ? Supplier::find($order->beneficiary_id) : null;
            $order->update([
                'status' => PaymentOrder::APPROVED,
                'beneficiary_name' => $order->liveBeneficiaryName() ?? $order->beneficiary_name,
                'beneficiary_bank' => $order->beneficiary_bank ?: $supplier?->bank_name,
                'beneficiary_rib' => $order->beneficiary_rib ?: $supplier?->rib,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ]);
            ActivityRecorder::record($by, ActivityAction::PAYMENT_ORDER_APPROVED, $order, [
                'amount' => (float) $order->amount, 'reference' => $order->reference,
            ]);

            return $order->fresh();
        });
    }

    public function markPaid(PaymentOrder $order, User $by, ?string $paymentDate = null): PaymentOrder
    {
        if ($order->status !== PaymentOrder::APPROVED || $order->transaction_id !== null) {
            throw new PaymentOrderException('flash.payment_order_not_approved');
        }
        $category = $order->finance_category_id ? FinanceCategory::find($order->finance_category_id) : null;
        if ($category === null || $category->type !== 'expense' || $order->finance_account_id === null) {
            throw new PaymentOrderException('flash.payment_order_missing_finance');
        }

        return DB::transaction(function () use ($order, $by, $paymentDate, $category) {
            $date = $paymentDate ?? ($order->payment_date?->format('Y-m-d')) ?? now()->format('Y-m-d');
            $transaction = Transaction::create([
                'title' => Str::limit($order->subject, 150, ''),
                'amount' => $order->amount,
                'transaction_date' => $date,
                'transaction_type' => 'expense',
                'finance_category_id' => $category->id,
                'category' => Str::slug($category->name, '_'),
                'finance_account_id' => $order->finance_account_id,
                'payment_method' => $order->payment_method ?? 'cash',
                'payment_bank_name' => $order->beneficiary_bank,
                'payment_account' => $order->beneficiary_rib,
                'payment_holder' => $order->beneficiary_name,
                'payment_reference' => $order->payment_reference,
                'cheque_date' => $order->payment_method === 'cheque' ? $date : null,
                'supplier_id' => $order->beneficiary_type === 'supplier' ? $order->beneficiary_id : null,
                'invoice_number' => $order->invoice_number,
                'invoice_date' => $order->invoice_date,
                'board_meeting_id' => $order->board_meeting_id,
                'description' => $order->observation,
                'status' => 'Paid',
                'recorded_by_user_id' => $by->id,
            ]);
            ActivityRecorder::record($by, ActivityAction::TRANSACTION_RECORDED, $transaction, [
                'amount' => (float) $transaction->amount, 'type' => 'expense',
            ]);

            $order->update([
                'status' => PaymentOrder::PAID,
                'transaction_id' => $transaction->id,
                'payment_date' => $date,
                'paid_at' => now(),
            ]);
            ActivityRecorder::record($by, ActivityAction::PAYMENT_ORDER_PAID, $order, [
                'amount' => (float) $order->amount, 'reference' => $order->reference,
            ]);

            return $order->fresh();
        });
    }

    public function cancel(PaymentOrder $order, User $by, string $reason): PaymentOrder
    {
        if (! in_array($order->status, [PaymentOrder::DRAFT, PaymentOrder::APPROVED], true) || $order->is_legacy) {
            throw new PaymentOrderException('flash.payment_order_cannot_cancel');
        }

        return DB::transaction(function () use ($order, $by, $reason) {
            $order->update([
                'status' => PaymentOrder::CANCELLED,
                'cancelled_by' => $by->id,
                'cancelled_at' => now(),
                'cancel_reason' => Str::limit(trim($reason), 500, ''),
            ]);
            ActivityRecorder::record($by, ActivityAction::PAYMENT_ORDER_CANCELLED, $order, ['reference' => $order->reference]);

            return $order->fresh();
        });
    }

    /** A paid order for an expense recorded without one (its number comes from the expense's year). */
    public function fromExpense(Transaction $transaction, User $by): PaymentOrder
    {
        if ($transaction->transaction_type !== 'expense') {
            throw new PaymentOrderException('flash.payment_order_not_expense');
        }
        if (PaymentOrder::where('transaction_id', $transaction->id)->exists()) {
            throw new PaymentOrderException('flash.payment_order_exists');
        }

        return YearlySequence::retrying(fn () => DB::transaction(function () use ($transaction, $by) {
            $date = $transaction->transaction_date ?? now();
            $year = $date->year;
            $supplierId = $transaction->supplier_id;
            $order = PaymentOrder::create([
                'year' => $year,
                'number' => YearlySequence::next('payment_orders', $year),
                'status' => PaymentOrder::PAID,
                'order_date' => $date->format('Y-m-d'),
                'subject' => Str::limit($transaction->title ?: ($transaction->description ?: '#'.$transaction->id), 150, ''),
                'amount' => $transaction->amount,
                'finance_category_id' => $transaction->finance_category_id,
                'finance_account_id' => $transaction->finance_account_id,
                'payment_method' => $transaction->payment_method,
                'beneficiary_type' => $supplierId ? 'supplier' : null,
                'beneficiary_id' => $supplierId,
                'beneficiary_name' => $supplierId ? Supplier::whereKey($supplierId)->value('name') : $transaction->payment_holder,
                'beneficiary_bank' => $transaction->payment_bank_name,
                'beneficiary_rib' => $transaction->payment_account,
                'payment_reference' => $transaction->payment_reference,
                'payment_date' => $date->format('Y-m-d'),
                'invoice_number' => $transaction->invoice_number,
                'invoice_date' => $transaction->invoice_date,
                'board_meeting_id' => $transaction->board_meeting_id,
                'transaction_id' => $transaction->id,
                'created_by' => $by->id,
                'approved_by' => $by->id,
                'approved_at' => now(),
                'paid_at' => now(),
            ]);
            ActivityRecorder::record($by, ActivityAction::PAYMENT_ORDER_CREATED, $order, [
                'amount' => (float) $order->amount, 'reference' => $order->reference,
            ]);

            return $order->fresh();
        }));
    }
}
```

- [ ] **Step 6: Translations** (flash + activity labels)

```json
{
    "flash.payment_order_created": { "ar": "تم إنشاء أمر الدفع.", "fr": "Ordre de paiement créé.", "en": "Payment order created." },
    "flash.payment_order_updated": { "ar": "تم تحديث أمر الدفع.", "fr": "Ordre de paiement mis à jour.", "en": "Payment order updated." },
    "flash.payment_order_approved": { "ar": "تمت المصادقة على أمر الدفع.", "fr": "Ordre de paiement approuvé.", "en": "Payment order approved." },
    "flash.payment_order_paid": { "ar": "تم تسجيل الدفع وإنشاء النفقة.", "fr": "Paiement enregistré, dépense créée.", "en": "Payment recorded, expense created." },
    "flash.payment_order_cancelled": { "ar": "تم إلغاء أمر الدفع.", "fr": "Ordre de paiement annulé.", "en": "Payment order cancelled." },
    "flash.payment_order_locked": { "ar": "لا يمكن تعديل أمر دفع بعد المصادقة عليه.", "fr": "Un ordre approuvé ne se modifie plus.", "en": "An approved order can no longer be edited." },
    "flash.payment_order_year_locked": { "ar": "يبقى تاريخ الأمر في سنة ترقيمه.", "fr": "La date reste dans l'année de numérotation de l'ordre.", "en": "The date must stay in the order's numbering year." },
    "flash.payment_order_not_draft": { "ar": "المصادقة ممكنة على المسودات فقط.", "fr": "Seul un brouillon peut être approuvé.", "en": "Only a draft can be approved." },
    "flash.payment_order_no_beneficiary": { "ar": "اختر المستفيد قبل المصادقة.", "fr": "Choisissez le bénéficiaire avant d'approuver.", "en": "Choose the beneficiary before approving." },
    "flash.payment_order_not_approved": { "ar": "الدفع ممكن فقط لأمر مصادق عليه ولم يُدفع بعد.", "fr": "Seul un ordre approuvé et non payé peut être payé.", "en": "Only an approved, unpaid order can be paid." },
    "flash.payment_order_missing_finance": { "ar": "حدد فئة النفقة والصندوق قبل الدفع.", "fr": "Indiquez la catégorie de dépense et la caisse avant de payer.", "en": "Set the expense category and register before paying." },
    "flash.payment_order_cannot_cancel": { "ar": "لا يمكن إلغاء أمر مدفوع: صحّح النفقة.", "fr": "Un ordre payé ne s'annule pas : corrigez la dépense.", "en": "A paid order cannot be cancelled: correct the expense." },
    "flash.payment_order_not_expense": { "ar": "أوامر الدفع للنفقات فقط.", "fr": "Les ordres de paiement concernent les dépenses.", "en": "Payment orders are for expenses only." },
    "flash.payment_order_exists": { "ar": "لهذه النفقة أمر دفع من قبل.", "fr": "Cette dépense a déjà un ordre de paiement.", "en": "This expense already has a payment order." },
    "activity.action.payment_order_created": { "ar": "إنشاء أمر دفع", "fr": "Ordre de paiement créé", "en": "Payment order created" },
    "activity.action.payment_order_updated": { "ar": "تعديل أمر دفع", "fr": "Ordre de paiement modifié", "en": "Payment order edited" },
    "activity.action.payment_order_approved": { "ar": "المصادقة على أمر دفع", "fr": "Ordre de paiement approuvé", "en": "Payment order approved" },
    "activity.action.payment_order_paid": { "ar": "دفع أمر دفع", "fr": "Ordre de paiement payé", "en": "Payment order paid" },
    "activity.action.payment_order_cancelled": { "ar": "إلغاء أمر دفع", "fr": "Ordre de paiement annulé", "en": "Payment order cancelled" },
    "activity.action.payment_order_printed": { "ar": "طباعة أمر دفع", "fr": "Ordre de paiement imprimé", "en": "Payment order printed" },
    "activity.action.legacy_data_imported": { "ar": "استيراد بيانات البرنامج القديم", "fr": "Import des données de l'ancien logiciel", "en": "Old software data imported" }
}
```

- [ ] **Step 7: Run tests**

Run: `php artisan test --filter="PaymentOrderServiceTest|UsersBatchTest|UserActivityPagesTest|ActivityReportTest"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Exceptions/PaymentOrderException.php app/Services/Finance/PaymentOrderService.php app/Services/Activity/ActivityAction.php app/Services/Activity/ActivitySubjectLink.php tests/Feature/PaymentOrderServiceTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(payment-orders): service for create, approve, pay, cancel and orders from expenses"
```

---

### Task 6: Controller, request, routes, permissions

**Files:**
- Create: `app/Http/Requests/PaymentOrder/SavePaymentOrderRequest.php`
- Create: `app/Http/Controllers/PaymentOrderController.php`
- Modify: `routes/web.php` (after the transactions block), `config/permissions.php` (modules + overrides)
- Test: `tests/Feature/PaymentOrderHttpTest.php`

**Interfaces:**
- Consumes: `PaymentOrderService` (Task 5).
- Produces routes: `payment-orders.index` GET `/payment-orders`, `payment-orders.create` GET `/payment-orders/create`, `payment-orders.store` POST, `payment-orders.show` GET `/payment-orders/{paymentOrder}`, `payment-orders.edit` GET `/payment-orders/{paymentOrder}/edit`, `payment-orders.update` PUT, `payment-orders.approve` POST `/payment-orders/{paymentOrder}/approve`, `payment-orders.pay` POST `/payment-orders/{paymentOrder}/pay`, `payment-orders.cancel` POST `/payment-orders/{paymentOrder}/cancel`, `transactions.payment-order.store` POST `/transactions/{transaction}/payment-order`. Inertia components `PaymentOrders/Index|Create|Edit|Show` (built in Task 7) with props listed in Step 4.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\PaymentOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\PaymentOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentOrderHttpTest extends TestCase
{
    use RefreshDatabase;

    private FinanceCategory $category;

    private FinanceAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-25 10:00:00');
        // Same finance setup as PaymentOrderServiceTest (copy its adjusted setUp lines).
        $this->category = FinanceCategory::where('type', 'expense')->first()
            ?? FinanceCategory::create(['name' => 'Equipment', 'type' => 'expense', 'is_active' => true]);
        $this->account = FinanceAccount::selectable()->first()
            ?? FinanceAccount::create(['name' => 'Caisse', 'type' => 'cash', 'is_active' => true]);
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
        $supplier = Supplier::firstOrCreate(['name' => 'Sport Shop'], ['rib' => '00100000000000001234']);

        return array_merge([
            'order_date' => '2026-11-25', 'subject' => 'Ballons', 'amount' => 16050,
            'finance_category_id' => $this->category->id, 'finance_account_id' => $this->account->id,
            'payment_method' => 'bank', 'beneficiary_type' => 'supplier', 'beneficiary_id' => $supplier->id,
        ], $extra);
    }

    #[Test]
    public function an_accountant_creates_a_draft_and_lands_on_it(): void
    {
        $user = $this->userWith(['payment_orders' => ['view', 'add', 'edit']]);

        $response = $this->actingAs($user)->post(route('payment-orders.store'), $this->payload());

        $order = PaymentOrder::firstOrFail();
        $response->assertRedirect(route('payment-orders.show', $order))->assertSessionHas('success', 'flash.payment_order_created');
        $this->assertSame('01/2026', $order->reference);
    }

    #[Test]
    public function validation_rejects_a_beneficiary_that_does_not_exist(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('payment-orders.store'), $this->payload(['beneficiary_type' => 'user', 'beneficiary_id' => 999999]))
            ->assertSessionHasErrors('beneficiary_id');
    }

    #[Test]
    public function approving_needs_the_approve_right(): void
    {
        $order = app(PaymentOrderService::class)->create($this->payload(), User::factory()->admin()->create());
        $editor = $this->userWith(['payment_orders' => ['view', 'add', 'edit']]);
        $approver = $this->userWith(['payment_orders' => ['view', 'approve']]);

        $this->actingAs($editor)->post(route('payment-orders.approve', $order))->assertForbidden();
        $this->actingAs($approver)->post(route('payment-orders.approve', $order))->assertSessionHas('success', 'flash.payment_order_approved');
        $this->assertSame(PaymentOrder::APPROVED, $order->fresh()->status);
    }

    #[Test]
    public function paying_creates_the_expense_and_a_refusal_comes_back_as_a_flash(): void
    {
        $admin = User::factory()->admin()->create();
        $service = app(PaymentOrderService::class);
        $order = $service->approve($service->create($this->payload(), $admin), $admin);

        $this->actingAs($admin)->post(route('payment-orders.pay', $order), ['payment_date' => '2026-11-26'])
            ->assertSessionHas('success', 'flash.payment_order_paid');
        $this->assertNotNull($order->fresh()->transaction_id);

        $this->post(route('payment-orders.pay', $order))->assertSessionHas('error', 'flash.payment_order_not_approved');
        $this->assertSame(1, Transaction::count());
    }

    #[Test]
    public function cancelling_needs_a_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $order = app(PaymentOrderService::class)->create($this->payload(), $admin);

        $this->actingAs($admin)->post(route('payment-orders.cancel', $order), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('payment-orders.cancel', $order), ['reason' => 'Doublon'])->assertSessionHas('success', 'flash.payment_order_cancelled');
    }

    #[Test]
    public function the_register_lists_and_filters_orders(): void
    {
        $admin = User::factory()->admin()->create();
        $service = app(PaymentOrderService::class);
        $service->create($this->payload(['subject' => 'Ballons']), $admin);
        $service->approve($service->create($this->payload(['subject' => 'Maillots']), $admin), $admin);

        $this->actingAs($admin)->get(route('payment-orders.index', ['status' => 'approved']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('PaymentOrders/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.subject', 'Maillots')
                ->where('orders.data.0.reference', '02/2026')
                ->has('years'));
    }

    #[Test]
    public function the_show_page_tells_what_the_viewer_may_do(): void
    {
        $order = app(PaymentOrderService::class)->create($this->payload(), User::factory()->admin()->create());
        $viewer = $this->userWith(['payment_orders' => ['view']]);

        $this->actingAs($viewer)->get(route('payment-orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->component('PaymentOrders/Show')
                ->where('order.reference', '01/2026')
                ->where('order.beneficiary_label', 'Sport Shop')
                ->where('prints', ['payment', 'transfer']));
    }

    #[Test]
    public function an_expense_gets_its_order_from_the_expense_page(): void
    {
        $admin = User::factory()->admin()->create();
        $transaction = Transaction::create([
            'title' => 'Impression', 'amount' => 12300, 'transaction_date' => '2026-10-12', 'transaction_type' => 'expense',
            'finance_category_id' => $this->category->id, 'category' => 'equipment', 'status' => 'Paid',
        ]);

        $this->actingAs($admin)->post(route('transactions.payment-order.store', $transaction))
            ->assertRedirect(route('payment-orders.show', PaymentOrder::firstOrFail()));
    }

    #[Test]
    public function there_is_no_delete_route(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('payment-orders.destroy'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PaymentOrderHttpTest`
Expected: FAIL (route not defined).

- [ ] **Step 3: Request**

```php
<?php

namespace App\Http\Requests\PaymentOrder;

use App\Models\PaymentOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePaymentOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'beneficiary_rib' => $this->filled('beneficiary_rib') ? preg_replace('/\s+/', '', (string) $this->input('beneficiary_rib')) : null,
        ]);
    }

    public function rules(): array
    {
        $table = match ($this->input('beneficiary_type')) {
            'supplier' => 'suppliers',
            'user' => 'users',
            'board_member' => 'board_members',
            default => null,
        };

        return [
            'order_date' => ['required', 'date'],
            'subject' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'finance_category_id' => ['nullable', 'integer', Rule::exists('finance_categories', 'id')->where('type', 'expense')],
            'finance_account_id' => ['nullable', 'integer', Rule::exists('finance_accounts', 'id')],
            'payment_method' => ['nullable', Rule::in(PaymentOrder::METHODS)],
            'beneficiary_type' => ['nullable', Rule::in(PaymentOrder::BENEFICIARY_TYPES)],
            'beneficiary_id' => ['nullable', 'required_with:beneficiary_type', 'integer', ...($table ? [Rule::exists($table, 'id')] : [])],
            'beneficiary_bank' => ['nullable', 'string', 'max:150'],
            'beneficiary_rib' => ['nullable', 'string', 'regex:/^\d{10,30}$/'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'payment_date' => ['nullable', 'date'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'invoice_date' => ['nullable', 'date'],
            'board_meeting_id' => ['nullable', 'integer', Rule::exists('board_meetings', 'id')],
            'observation' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

- [ ] **Step 4: Controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentOrder\SavePaymentOrderRequest;
use App\Models\BoardMeeting;
use App\Models\BoardMember;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\PaymentOrder;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\PaymentOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentOrderController extends Controller
{
    public function __construct(private readonly PaymentOrderService $orders) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'year' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(PaymentOrder::STATUSES)],
            'method' => ['nullable', Rule::in(PaymentOrder::METHODS)],
            'supplier' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = PaymentOrder::query()
            ->when($filters['year'] ?? null, fn ($q, $year) => $q->where('year', $year))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['method'] ?? null, fn ($q, $method) => $q->where('payment_method', $method))
            ->when($filters['supplier'] ?? null, fn ($q, $id) => $q->where('beneficiary_type', 'supplier')->where('beneficiary_id', $id))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(function ($q) use ($text) {
                $q->where('subject', 'like', "%{$text}%")
                    ->orWhere('beneficiary_name', 'like', "%{$text}%")
                    ->orWhere('legacy_reference', 'like', "%{$text}%");
                // "7" or "07/2026" finds order number 7 (of 2026).
                if (preg_match('/^0*(\d+)(?:\/(\d{4}))?$/', trim($text), $m)) {
                    $q->orWhere(fn ($q) => $q->where('number', (int) $m[1])
                        ->when($m[2] ?? null, fn ($q, $year) => $q->where('year', (int) $year)));
                }
            }));

        $total = (float) (clone $query)->where('status', '!=', PaymentOrder::CANCELLED)->sum('amount');

        $orders = $query->orderByDesc('year')->orderByDesc('number')->orderByDesc('order_date')->orderByDesc('id')
            ->paginate(50)->withQueryString()
            ->through(fn (PaymentOrder $order) => [
                'id' => $order->id,
                'reference' => $order->reference,
                'order_date' => $order->order_date?->format('Y-m-d'),
                'subject' => $order->subject,
                'amount' => (float) $order->amount,
                'status' => $order->status,
                'payment_method' => $order->payment_method,
                'is_legacy' => $order->is_legacy,
                'beneficiary_label' => $order->beneficiaryLabel(),
            ]);

        return Inertia::render('PaymentOrders/Index', [
            'orders' => $orders,
            'filters' => $filters,
            'total' => $total,
            'years' => PaymentOrder::query()->distinct()->orderByDesc('year')->pluck('year'),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PaymentOrders/Create', $this->formOptions());
    }

    public function store(SavePaymentOrderRequest $request): RedirectResponse
    {
        $order = $this->orders->create($request->validated(), $request->user());

        return redirect()->route('payment-orders.show', $order)->with('success', 'flash.payment_order_created');
    }

    public function show(Request $request, PaymentOrder $paymentOrder): Response
    {
        $paymentOrder->load([
            'financeCategory', 'financeAccount', 'boardMeeting:id,title,meeting_date,pv_number', 'transaction:id,amount,transaction_date',
            'createdBy:id,name,firstname,lastname', 'approvedBy:id,name,firstname,lastname', 'cancelledBy:id,name,firstname,lastname',
        ]);
        $user = $request->user();

        return Inertia::render('PaymentOrders/Show', [
            'order' => [...$paymentOrder->toArray(), 'beneficiary_label' => $paymentOrder->beneficiaryLabel()],
            'prints' => self::printsFor($paymentOrder),
            'can' => [
                'edit' => $paymentOrder->isEditable() && $user->hasPermission('payment_orders', 'edit'),
                'approve' => $paymentOrder->status === PaymentOrder::DRAFT && ! $paymentOrder->is_legacy && $user->hasPermission('payment_orders', 'approve'),
                'pay' => $paymentOrder->status === PaymentOrder::APPROVED && $user->hasPermission('payment_orders', 'edit'),
                'cancel' => in_array($paymentOrder->status, [PaymentOrder::DRAFT, PaymentOrder::APPROVED], true) && ! $paymentOrder->is_legacy && $user->hasPermission('payment_orders', 'edit'),
            ],
        ]);
    }

    public function edit(PaymentOrder $paymentOrder): Response|RedirectResponse
    {
        if (! $paymentOrder->isEditable()) {
            return redirect()->route('payment-orders.show', $paymentOrder)->with('error', 'flash.payment_order_locked');
        }

        return Inertia::render('PaymentOrders/Edit', ['order' => $paymentOrder, ...$this->formOptions()]);
    }

    public function update(SavePaymentOrderRequest $request, PaymentOrder $paymentOrder): RedirectResponse
    {
        $this->orders->update($paymentOrder, $request->validated(), $request->user());

        return redirect()->route('payment-orders.show', $paymentOrder)->with('success', 'flash.payment_order_updated');
    }

    public function approve(Request $request, PaymentOrder $paymentOrder): RedirectResponse
    {
        $this->orders->approve($paymentOrder, $request->user());

        return back()->with('success', 'flash.payment_order_approved');
    }

    public function pay(Request $request, PaymentOrder $paymentOrder): RedirectResponse
    {
        $data = $request->validate(['payment_date' => ['nullable', 'date']]);
        $this->orders->markPaid($paymentOrder, $request->user(), $data['payment_date'] ?? null);

        return back()->with('success', 'flash.payment_order_paid');
    }

    public function cancel(Request $request, PaymentOrder $paymentOrder): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->orders->cancel($paymentOrder, $request->user(), $data['reason']);

        return back()->with('success', 'flash.payment_order_cancelled');
    }

    /** "Create payment order" on an expense recorded without one. */
    public function fromExpense(Request $request, Transaction $transaction): RedirectResponse
    {
        $order = $this->orders->fromExpense($transaction, $request->user());

        return redirect()->route('payment-orders.show', $order)->with('success', 'flash.payment_order_created');
    }

    /** @return list<string> Which of the three forms this order prints. */
    public static function printsFor(PaymentOrder $order): array
    {
        if ($order->status === PaymentOrder::CANCELLED) {
            return [];
        }

        return array_values(array_filter([
            'payment',
            in_array($order->payment_method, ['bank', 'ccp'], true) ? 'transfer' : null,
            $order->payment_method === 'cash' ? 'cash' : null,
        ]));
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'financeCategories' => FinanceCategory::query()->where('type', 'expense')->where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'financeAccounts' => FinanceAccount::selectable()->get(),
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name', 'bank_name', 'rib']),
            'users' => User::query()->where('is_active', true)->orderBy('lastname')->orderBy('firstname')->get(['id', 'name', 'firstname', 'lastname'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]),
            'boardMembers' => BoardMember::query()->withPlayer()->inCurrentTerm()->orderBy('sort_order')->get()
                ->map(fn (BoardMember $m) => ['id' => $m->id, 'name' => $m->name, 'role' => $m->role]),
            'boardMeetings' => BoardMeeting::query()->whereNull('cancelled_at')->orderByDesc('meeting_date')->limit(200)
                ->get(['id', 'title', 'meeting_date', 'pv_number']),
        ];
    }
}
```

Check `FinanceAccount::selectable()` returns models that serialize the fields `useFinanceAccountLabel` needs (same as `TransactionController::formOptions`, which passes `FinanceAccount::selectable()->get()` unchanged). Check `User` has `lastname`/`firstname` columns (computed names) — if `name` is a stored column only, order by `name`.

- [ ] **Step 5: Routes + permissions**

`routes/web.php`, import `use App\Http\Controllers\PaymentOrderController;` and after the transactions receipt-file route add:

```php
    // Payment orders (الأمر بالدفع): never deleted — cancelled instead, so the routes have no destroy.
    Route::resource('payment-orders', PaymentOrderController::class)->except(['destroy'])->parameters(['payment-orders' => 'paymentOrder']);
    Route::post('/payment-orders/{paymentOrder}/approve', [PaymentOrderController::class, 'approve'])->name('payment-orders.approve');
    Route::post('/payment-orders/{paymentOrder}/pay', [PaymentOrderController::class, 'pay'])->name('payment-orders.pay');
    Route::post('/payment-orders/{paymentOrder}/cancel', [PaymentOrderController::class, 'cancel'])->name('payment-orders.cancel');
    Route::post('/transactions/{transaction}/payment-order', [PaymentOrderController::class, 'fromExpense'])->name('transactions.payment-order.store');
```

`config/permissions.php` `modules` add (after `'transactions' => 'transactions',`):

```php
        'payment-orders' => 'payment_orders',
```

`overrides` add:

```php
        // Approving is its own right (Role::EXTRA_ACTIONS); paying and cancelling are edits.
        'payment-orders.approve' => ['payment_orders', 'approve'],
        'payment-orders.pay' => ['payment_orders', 'edit'],
        'payment-orders.cancel' => ['payment_orders', 'edit'],
        // Made from the expense page, but it creates a payment order.
        'transactions.payment-order.store' => ['payment_orders', 'add'],
```

- [ ] **Step 6: Placeholder Inertia pages so the HTTP tests render**

Task 7 writes the real pages. For this task create the four files with a minimal body so `assertInertia` finds the components (Inertia testing checks the page file exists):

`resources/js/Pages/PaymentOrders/Index.vue`, `Create.vue`, `Edit.vue`, `Show.vue`, each:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
</script>

<template>
    <AuthenticatedLayout />
</template>
```

- [ ] **Step 7: Run tests**

Run: `php artisan test --filter="PaymentOrderHttpTest|PaymentOrderServiceTest|UsersBatchTest"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/PaymentOrder/SavePaymentOrderRequest.php app/Http/Controllers/PaymentOrderController.php routes/web.php config/permissions.php resources/js/Pages/PaymentOrders tests/Feature/PaymentOrderHttpTest.php
git commit -m "feat(payment-orders): register routes, form validation and approve/pay/cancel actions"
```

---

### Task 7: Payment order pages, menu, expense page link

**Files:**
- Modify (replace placeholders): `resources/js/Pages/PaymentOrders/Index.vue`, `Create.vue`, `Edit.vue`, `Show.vue`
- Create: `resources/js/Pages/PaymentOrders/Partials/PaymentOrderForm.vue`
- Modify: `resources/js/Composables/useNavigation.js` (finance section), `resources/js/lib/statusLabels.js` (payment order statuses), `resources/js/Pages/Transactions/Show.vue` (order link / create button), `app/Http/Controllers/TransactionController.php` (`show` passes `paymentOrder`)
- Test: `tests/Feature/PaymentOrderPagesTest.php`

**Interfaces:**
- Consumes: props from Task 6 (`orders, filters, total, years, suppliers` on Index; `financeCategories, financeAccounts, suppliers, users, boardMembers, boardMeetings` (+ `order`) on Create/Edit; `order, prints, can` on Show).
- Produces: `Transactions/Show` prop `paymentOrder: { id, reference } | null`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\PaymentOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\PaymentOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentOrderPagesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_expense_page_links_its_payment_order(): void
    {
        $admin = User::factory()->admin()->create();
        $category = FinanceCategory::where('type', 'expense')->first()
            ?? FinanceCategory::create(['name' => 'Equipment', 'type' => 'expense', 'is_active' => true]);
        $transaction = Transaction::create([
            'title' => 'Impression', 'amount' => 12300, 'transaction_date' => now()->format('Y-m-d'), 'transaction_type' => 'expense',
            'finance_category_id' => $category->id, 'category' => 'equipment', 'status' => 'Paid',
        ]);

        $this->actingAs($admin)->get(route('transactions.show', $transaction))
            ->assertInertia(fn (Assert $page) => $page->where('paymentOrder', null));

        $order = app(PaymentOrderService::class)->fromExpense($transaction, $admin);

        $this->get(route('transactions.show', $transaction))
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentOrder.id', $order->id)
                ->where('paymentOrder.reference', $order->reference));
    }

    #[Test]
    public function the_create_page_has_its_pickers(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('payment-orders.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('PaymentOrders/Create')
                ->has('financeCategories')->has('financeAccounts')->has('suppliers')
                ->has('users')->has('boardMembers')->has('boardMeetings'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PaymentOrderPagesTest`
Expected: FAIL on `paymentOrder` prop missing.

- [ ] **Step 3: Transaction show prop**

In `TransactionController::show()` add to the rendered props:

```php
            // Its payment order (الأمر بالدفع), if one was made.
            'paymentOrder' => ($order = PaymentOrder::where('transaction_id', $transaction->id)->first(['id', 'year', 'number', 'is_legacy', 'legacy_reference']))
                ? ['id' => $order->id, 'reference' => $order->reference]
                : null,
```

(import `App\Models\PaymentOrder`).

In `Transactions/Show.vue` add prop `paymentOrder: { type: Object, default: null }` and, in the header button group before the edit button:

```vue
                    <IconButton v-if="paymentOrder" :href="route('payment-orders.show', paymentOrder.id)" icon="document" :label="t('po.title') + ' ' + paymentOrder.reference" data-help="tx-payment-order" />
                    <IconButton v-else-if="transaction.transaction_type === 'expense' && can('payment_orders', 'add')" icon="plus" :label="t('po.create_from_expense')"
                        @click="router.post(route('transactions.payment-order.store', transaction.id))" data-help="tx-payment-order-create" />
```

(import `router` from `@inertiajs/vue3` if not already imported).

- [ ] **Step 4: Status labels + menu**

`resources/js/lib/statusLabels.js` — add a map next to `payment_method`:

```js
    payment_order: { draft: 'po.status.draft', approved: 'po.status.approved', paid: 'po.status.paid', cancelled: 'po.status.cancelled' },
```

`useNavigation.js` finance section, after the transactions item:

```js
                { label: t('payment_orders'), href: '/payment-orders', icon: 'document', prefix: '/payment-orders', module: 'payment_orders' },
```

- [ ] **Step 5: Form partial `PaymentOrders/Partials/PaymentOrderForm.vue`**

```vue
<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFinanceAccountLabel } from '@/Composables/useFinanceAccountLabel';
import { localToday } from '@/lib/localDate';

const { t, locale } = useI18n();
const props = defineProps({
    order: { type: Object, default: null },
    financeCategories: { type: Array, default: () => [] },
    financeAccounts: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    boardMembers: { type: Array, default: () => [] },
    boardMeetings: { type: Array, default: () => [] },
});
const { accountLabel } = useFinanceAccountLabel();
const isEdit = !!props.order;
const day = (value) => (value ? String(value).slice(0, 10) : '');

const form = useForm({
    order_date: day(props.order?.order_date) || localToday(),
    subject: props.order?.subject || '',
    amount: props.order?.amount || '',
    finance_category_id: props.order?.finance_category_id || '',
    finance_account_id: props.order?.finance_account_id || '',
    payment_method: props.order?.payment_method || 'bank',
    beneficiary_type: props.order?.beneficiary_type || 'supplier',
    beneficiary_id: props.order?.beneficiary_id || '',
    beneficiary_bank: props.order?.beneficiary_bank || '',
    beneficiary_rib: props.order?.beneficiary_rib || '',
    payment_reference: props.order?.payment_reference || '',
    payment_date: day(props.order?.payment_date),
    invoice_number: props.order?.invoice_number || '',
    invoice_date: day(props.order?.invoice_date),
    board_meeting_id: props.order?.board_meeting_id || '',
    observation: props.order?.observation || '',
});

const categoryName = (c) => c[`name_${locale.value}`] || c.name;
const beneficiaryOptions = computed(() => {
    const list = { supplier: props.suppliers, user: props.users, board_member: props.boardMembers }[form.beneficiary_type] || [];
    return list.map((b) => ({ value: b.id, label: b.name, description: b.rib || (b.role ? t(b.role) : '') }));
});
const meetingLabel = (m) => [m.pv_number, m.title, day(m.meeting_date)].filter(Boolean).join(' · ');

// Picking a supplier fills the bank details once; they stay editable.
watch(() => form.beneficiary_id, (id) => {
    if (form.beneficiary_type !== 'supplier') return;
    const supplier = props.suppliers.find((s) => String(s.id) === String(id));
    if (supplier) {
        form.beneficiary_bank = supplier.bank_name || '';
        form.beneficiary_rib = supplier.rib || '';
    }
});
watch(() => form.beneficiary_type, () => { form.beneficiary_id = ''; });

function submit() {
    form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));
    isEdit ? form.put(route('payment-orders.update', props.order.id)) : form.post(route('payment-orders.store'));
}
</script>

<template>
    <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-6">
        <div class="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2"><InputLabel :value="t('po.subject')" /><TextInput v-model="form.subject" maxlength="150" required class="mt-1 w-full" /><InputError :message="form.errors.subject" class="mt-1" /></div>
                <div><InputLabel :value="t('date')" /><TextInput v-model="form.order_date" type="date" required class="mt-1 w-full" /><InputError :message="form.errors.order_date" class="mt-1" /></div>
                <div><InputLabel :value="t('amount')" /><TextInput v-model="form.amount" type="number" step="0.01" min="0.01" required class="mt-1 w-full" /><InputError :message="form.errors.amount" class="mt-1" /></div>
                <div>
                    <InputLabel :value="t('category')" />
                    <select v-model="form.finance_category_id" class="mt-1 w-full rounded-lg border-slate-300 shadow-sm dark:border-slate-700">
                        <option value="">—</option>
                        <option v-for="c in financeCategories" :key="c.id" :value="c.id">{{ categoryName(c) }}</option>
                    </select>
                    <InputError :message="form.errors.finance_category_id" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('source_register')" />
                    <select v-model="form.finance_account_id" class="mt-1 w-full rounded-lg border-slate-300 shadow-sm dark:border-slate-700">
                        <option value="">—</option>
                        <option v-for="a in financeAccounts" :key="a.id" :value="a.id">{{ accountLabel(a) }}</option>
                    </select>
                    <InputError :message="form.errors.finance_account_id" class="mt-1" />
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('po.finance_hint') }}</p>
        </div>

        <div class="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('po.beneficiary') }}</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <select v-model="form.beneficiary_type" class="w-full rounded-lg border-slate-300 shadow-sm dark:border-slate-700">
                        <option value="supplier">{{ t('po.beneficiary_type.supplier') }}</option>
                        <option value="user">{{ t('po.beneficiary_type.user') }}</option>
                        <option value="board_member">{{ t('po.beneficiary_type.board_member') }}</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <SearchableSelect v-model="form.beneficiary_id" :options="beneficiaryOptions" :placeholder="t('po.choose_beneficiary')" />
                    <InputError :message="form.errors.beneficiary_id" class="mt-1" />
                </div>
                <div><InputLabel :value="t('supplier.field.bank_name')" /><TextInput v-model="form.beneficiary_bank" class="mt-1 w-full" /></div>
                <div class="sm:col-span-2"><InputLabel :value="t('supplier.field.rib')" /><TextInput v-model="form.beneficiary_rib" dir="ltr" class="mt-1 w-full font-mono" /><InputError :message="form.errors.beneficiary_rib" class="mt-1" /></div>
            </div>
        </div>

        <div class="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('po.payment') }}</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <InputLabel :value="t('payment_method')" />
                    <select v-model="form.payment_method" class="mt-1 w-full rounded-lg border-slate-300 shadow-sm dark:border-slate-700">
                        <option value="bank">{{ t('bank_transfer') }}</option>
                        <option value="ccp">CCP</option>
                        <option value="cheque">{{ t('cheque') }}</option>
                        <option value="cash">{{ t('cash') }}</option>
                        <option value="baridimob">BaridiMob</option>
                        <option value="other">{{ t('other') }}</option>
                    </select>
                </div>
                <div><InputLabel :value="form.payment_method === 'cheque' ? t('tx.cheque_number') : t('po.payment_reference')" /><TextInput v-model="form.payment_reference" dir="ltr" class="mt-1 w-full" /></div>
                <div><InputLabel :value="t('po.payment_date')" /><TextInput v-model="form.payment_date" type="date" class="mt-1 w-full" /></div>
                <div><InputLabel :value="t('tx.invoice_number')" /><TextInput v-model="form.invoice_number" dir="ltr" class="mt-1 w-full" /></div>
                <div><InputLabel :value="t('tx.invoice_date')" /><TextInput v-model="form.invoice_date" type="date" class="mt-1 w-full" /></div>
                <div>
                    <InputLabel :value="t('tx.board_meeting')" />
                    <select v-model="form.board_meeting_id" class="mt-1 w-full rounded-lg border-slate-300 shadow-sm dark:border-slate-700">
                        <option value="">—</option>
                        <option v-for="m in boardMeetings" :key="m.id" :value="m.id">{{ meetingLabel(m) }}</option>
                    </select>
                </div>
                <div class="sm:col-span-3">
                    <InputLabel :value="t('po.observation')" />
                    <textarea v-model="form.observation" rows="2" class="mt-1 w-full rounded-lg border-slate-300 shadow-sm dark:border-slate-700"></textarea>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="isEdit ? route('payment-orders.show', order.id) : route('payment-orders.index')"><SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton></Link>
            <PrimaryButton :disabled="form.processing">{{ isEdit ? t('save_changes') : t('po.save_draft') }}</PrimaryButton>
        </div>
    </form>
</template>
```

Check `t(b.role)` labels board roles (board roles may be `BoardRole.label`); if role names are not i18n keys, show `b.role` as is.

- [ ] **Step 6: Create / Edit pages**

`Create.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PaymentOrderForm from './Partials/PaymentOrderForm.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({
    financeCategories: Array, financeAccounts: Array, suppliers: Array,
    users: Array, boardMembers: Array, boardMeetings: Array,
});
</script>

<template>
    <Head :title="t('po.new')" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('po.new') }}</h1></template>
        <PaymentOrderForm :finance-categories="financeCategories" :finance-accounts="financeAccounts" :suppliers="suppliers"
            :users="users" :board-members="boardMembers" :board-meetings="boardMeetings" />
    </AuthenticatedLayout>
</template>
```

`Edit.vue`: same with prop `order: Object`, title `t('po.title') + ' ' + order.reference`, and `:order="order"` passed to the form.

- [ ] **Step 7: Index page**

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Badge from '@/Components/Badge.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan.js';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFormatDate } from '@/Composables/useFormatDate';
import { statusLabel } from '@/lib/statusLabels';

const { t } = useI18n();
const { can } = useCan();
const { formatMoney } = useFormatMoney();
const { formatDate } = useFormatDate();

const props = defineProps({ orders: Object, filters: Object, total: Number, years: Array, suppliers: Array });

const filters = reactive({
    year: props.filters.year ?? '', status: props.filters.status ?? '', method: props.filters.method ?? '',
    supplier: props.filters.supplier ?? '', q: props.filters.q ?? '',
});
let timer = null;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('payment-orders.index'),
        Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')), { preserveState: true, replace: true }), 300);
});

const STATUS_COLOR = { draft: 'slate', approved: 'amber', paid: 'emerald', cancelled: 'rose' };
</script>

<template>
    <Head :title="t('payment_orders')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('payment_orders') }}</h1>
                <IconButton v-if="can('payment_orders', 'add')" :href="route('payment-orders.create')" icon="plus" :label="t('po.new')" variant="primary" />
            </div>
        </template>

        <div class="space-y-4">
            <div class="grid grid-cols-1 gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:grid-cols-3 lg:grid-cols-6 dark:bg-slate-900 dark:ring-slate-800">
                <TextInput v-model="filters.q" class="sm:col-span-3 lg:col-span-2" :placeholder="t('po.find_placeholder')" />
                <select v-model="filters.method" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700">
                    <option value="">{{ t('po.all_methods') }}</option>
                    <option v-for="m in ['bank', 'ccp', 'cheque', 'cash', 'baridimob', 'other']" :key="m" :value="m">{{ statusLabel('payment_method', m) }}</option>
                </select>
                <select v-model="filters.year" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700">
                    <option value="">{{ t('po.all_years') }}</option>
                    <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                </select>
                <select v-model="filters.status" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700">
                    <option value="">{{ t('po.all_statuses') }}</option>
                    <option v-for="s in ['draft', 'approved', 'paid', 'cancelled']" :key="s" :value="s">{{ statusLabel('payment_order', s) }}</option>
                </select>
                <select v-model="filters.supplier" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700">
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
                            <th class="px-4 py-3 text-start">{{ t('po.subject') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('po.beneficiary') }}</th>
                            <th class="px-4 py-3 text-end">{{ t('amount') }}</th>
                            <th class="px-4 py-3 text-center">{{ t('status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="o in orders.data" :key="o.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-4 py-3 font-mono"><Link :href="route('payment-orders.show', o.id)" class="text-primary-600 hover:underline dark:text-primary-300"><bdi dir="ltr">{{ o.reference }}</bdi></Link></td>
                            <td class="px-4 py-3">{{ formatDate(o.order_date) }}</td>
                            <td class="px-4 py-3 text-slate-900 dark:text-slate-100">{{ o.subject }} <span v-if="o.is_legacy" class="ms-1 rounded bg-slate-100 px-1 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('po.legacy') }}</span></td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ o.beneficiary_label }}</td>
                            <td class="px-4 py-3 text-end tabular-nums"><bdi dir="ltr">{{ formatMoney(o.amount) }}</bdi></td>
                            <td class="px-4 py-3 text-center"><Badge :label="statusLabel('payment_order', o.status)" :color="STATUS_COLOR[o.status]" /></td>
                        </tr>
                        <tr v-if="!orders.data.length"><td colspan="6" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td></tr>
                    </tbody>
                    <tfoot v-if="orders.data.length" class="bg-slate-50 text-sm font-semibold dark:bg-slate-950">
                        <tr><td colspan="4" class="px-4 py-3">{{ t('po.total_not_cancelled') }}</td><td class="px-4 py-3 text-end tabular-nums"><bdi dir="ltr">{{ formatMoney(total) }}</bdi></td><td></td></tr>
                    </tfoot>
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

Check `Badge` component path/props and `useFormatDate` export names by opening `resources/js/Pages/Transactions/Show.vue` imports; copy those import lines exactly.

- [ ] **Step 8: Show page**

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFormatDate } from '@/Composables/useFormatDate';
import { statusLabel } from '@/lib/statusLabels';
import { localToday } from '@/lib/localDate';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();
const { formatDate } = useFormatDate();
const props = defineProps({ order: Object, prints: Array, can: Object });

const STATUS_COLOR = { draft: 'slate', approved: 'amber', paid: 'emerald', cancelled: 'rose' };
const payOpen = ref(false);
const cancelOpen = ref(false);
const payForm = useForm({ payment_date: props.order.payment_date ? String(props.order.payment_date).slice(0, 10) : localToday() });
const cancelForm = useForm({ reason: '' });

const rows = computed(() => [
    { label: t('date'), value: formatDate(props.order.order_date) },
    { label: t('po.beneficiary'), value: props.order.beneficiary_label },
    { label: t('supplier.field.bank_name'), value: props.order.beneficiary_bank },
    { label: t('supplier.field.rib'), value: props.order.beneficiary_rib, ltr: true },
    { label: t('payment_method'), value: props.order.payment_method ? statusLabel('payment_method', props.order.payment_method) : null },
    { label: t('po.payment_reference'), value: props.order.payment_reference, ltr: true },
    { label: t('po.payment_date'), value: props.order.payment_date ? formatDate(props.order.payment_date) : null },
    { label: t('tx.invoice_number'), value: props.order.invoice_number, ltr: true },
    { label: t('tx.invoice_date'), value: props.order.invoice_date ? formatDate(props.order.invoice_date) : null },
    { label: t('category'), value: props.order.finance_category?.localized_name || props.order.finance_category?.name },
    { label: t('tx.board_meeting'), value: props.order.board_meeting ? [props.order.board_meeting.pv_number, props.order.board_meeting.title].filter(Boolean).join(' · ') : null },
    { label: t('po.observation'), value: props.order.observation },
    { label: t('po.cancel_reason'), value: props.order.cancel_reason },
].filter((row) => row.value));

const approve = () => router.post(route('payment-orders.approve', props.order.id), {}, { preserveScroll: true });
const pay = () => payForm.post(route('payment-orders.pay', props.order.id), { preserveScroll: true, onSuccess: () => { payOpen.value = false; } });
const cancel = () => cancelForm.post(route('payment-orders.cancel', props.order.id), { preserveScroll: true, onSuccess: () => { cancelOpen.value = false; } });
</script>

<template>
    <Head :title="t('po.title') + ' ' + order.reference" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-3">
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('po.title') }} <bdi dir="ltr">{{ order.reference }}</bdi></h1>
                    <Badge :label="statusLabel('payment_order', order.status)" :color="STATUS_COLOR[order.status]" />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <IconButton v-for="p in prints" :key="p" :href="route('payment-orders.print', { paymentOrder: order.id, form: p })" external target="_blank" icon="print" :label="t('po.print.' + p)" />
                    <IconButton v-if="can.edit" :href="route('payment-orders.edit', order.id)" icon="pencil" :label="t('edit')" />
                    <IconButton v-if="can.approve" icon="check" :label="t('perm_approve')" variant="primary" @click="approve" />
                    <IconButton v-if="can.pay" icon="money" :label="t('po.mark_paid')" variant="primary" @click="payOpen = true" />
                    <IconButton v-if="can.cancel" icon="x" :label="t('po.cancel_order')" variant="danger" @click="cancelOpen = true" />
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('po.subject') }}</p>
                <h2 class="mt-1 text-lg font-bold text-slate-900 dark:text-slate-100">{{ order.subject }}</h2>
                <p class="mt-2 text-2xl font-bold tabular-nums text-rose-700 dark:text-rose-400"><bdi dir="ltr">{{ formatMoney(order.amount) }}</bdi></p>
                <dl class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div v-for="row in rows" :key="row.label">
                        <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ row.label }}</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100"><bdi :dir="row.ltr ? 'ltr' : undefined">{{ row.value }}</bdi></dd>
                    </div>
                </dl>
                <p v-if="order.transaction" class="mt-5 text-sm">
                    {{ t('po.expense_link') }}
                    <Link :href="route('transactions.show', order.transaction.id)" class="font-semibold text-primary-600 hover:underline dark:text-primary-300" dir="ltr">#{{ order.transaction.id }}</Link>
                </p>
                <p v-if="order.status === 'draft'" class="mt-5 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ t('po.draft_hint') }}</p>
            </div>
        </div>

        <Modal :show="payOpen" @close="payOpen = false" max-width="md">
            <form @submit.prevent="pay" class="space-y-4 p-6">
                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('po.mark_paid') }}</h3>
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('po.mark_paid_hint') }}</p>
                <TextInput v-model="payForm.payment_date" type="date" required class="w-full" />
                <InputError :message="payForm.errors.payment_date" />
                <div class="flex justify-end gap-2"><SecondaryButton type="button" @click="payOpen = false">{{ t('cancel') }}</SecondaryButton><PrimaryButton :disabled="payForm.processing">{{ t('po.mark_paid') }}</PrimaryButton></div>
            </form>
        </Modal>

        <Modal :show="cancelOpen" @close="cancelOpen = false" max-width="md">
            <form @submit.prevent="cancel" class="space-y-4 p-6">
                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('po.cancel_order') }}</h3>
                <TextInput v-model="cancelForm.reason" required maxlength="500" class="w-full" :placeholder="t('po.cancel_reason')" />
                <InputError :message="cancelForm.errors.reason" />
                <div class="flex justify-end gap-2"><SecondaryButton type="button" @click="cancelOpen = false">{{ t('back') }}</SecondaryButton><PrimaryButton :disabled="cancelForm.processing">{{ t('po.cancel_order') }}</PrimaryButton></div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
```

The print route `payment-orders.print` is added in Task 8; until then the print buttons would throw in Ziggy — so in this task render the print buttons only when `route().has('payment-orders.print')`: change `v-for` element to `<template v-if="route().has('payment-orders.print')"><IconButton v-for=…/></template>`, and remove that guard in Task 8. Check `Modal.vue` props (`show`, `maxWidth`) and IconButton icon names (`check`, `money`, `x`, `plus`) against `resources/js/Components/Icon.vue`; use existing icon names.

- [ ] **Step 9: Translations**

```json
{
    "po.title": { "ar": "أمر بالدفع", "fr": "Ordre de paiement", "en": "Payment order" },
    "po.new": { "ar": "أمر دفع جديد", "fr": "Nouvel ordre de paiement", "en": "New payment order" },
    "po.number": { "ar": "الرقم", "fr": "N°", "en": "No." },
    "po.subject": { "ar": "موضوع النفقة", "fr": "Objet de la dépense", "en": "Expense subject" },
    "po.beneficiary": { "ar": "المستفيد", "fr": "Bénéficiaire", "en": "Beneficiary" },
    "po.beneficiary_type.supplier": { "ar": "مورد", "fr": "Fournisseur", "en": "Supplier" },
    "po.beneficiary_type.user": { "ar": "مستخدم", "fr": "Utilisateur", "en": "User" },
    "po.beneficiary_type.board_member": { "ar": "عضو المكتب", "fr": "Membre du bureau", "en": "Board member" },
    "po.choose_beneficiary": { "ar": "اختر المستفيد", "fr": "Choisir le bénéficiaire", "en": "Choose the beneficiary" },
    "po.payment": { "ar": "الدفع", "fr": "Paiement", "en": "Payment" },
    "po.payment_reference": { "ar": "رقم التحويل / الدفع", "fr": "N° de virement / versement", "en": "Transfer / payment no." },
    "po.payment_date": { "ar": "تاريخ الدفع", "fr": "Date de paiement", "en": "Payment date" },
    "po.observation": { "ar": "ملاحظات", "fr": "Observation", "en": "Observation" },
    "po.finance_hint": { "ar": "الفئة والصندوق ضروريان عند الدفع فقط.", "fr": "Catégorie et caisse ne sont exigées qu'au paiement.", "en": "Category and register are only required when paying." },
    "po.save_draft": { "ar": "حفظ كمسودة", "fr": "Enregistrer le brouillon", "en": "Save draft" },
    "po.status.draft": { "ar": "مسودة", "fr": "Brouillon", "en": "Draft" },
    "po.status.approved": { "ar": "مصادق عليه", "fr": "Approuvé", "en": "Approved" },
    "po.status.paid": { "ar": "مدفوع", "fr": "Payé", "en": "Paid" },
    "po.status.cancelled": { "ar": "ملغى", "fr": "Annulé", "en": "Cancelled" },
    "po.find_placeholder": { "ar": "رقم، موضوع أو مستفيد", "fr": "N°, objet ou bénéficiaire", "en": "No., subject or beneficiary" },
    "po.all_years": { "ar": "كل السنوات", "fr": "Toutes les années", "en": "All years" },
    "po.all_statuses": { "ar": "كل الحالات", "fr": "Tous les statuts", "en": "All statuses" },
    "po.all_suppliers": { "ar": "كل الموردين", "fr": "Tous les fournisseurs", "en": "All suppliers" },
    "po.all_methods": { "ar": "كل طرق الدفع", "fr": "Tous les modes", "en": "All methods" },
    "po.legacy": { "ar": "قديم", "fr": "Ancien", "en": "Old" },
    "po.total_not_cancelled": { "ar": "المجموع (دون الملغاة)", "fr": "Total (hors annulés)", "en": "Total (excluding cancelled)" },
    "po.mark_paid": { "ar": "تسجيل الدفع", "fr": "Marquer payé", "en": "Mark paid" },
    "po.mark_paid_hint": { "ar": "ستُنشأ النفقة في الصندوق المختار بهذا التاريخ.", "fr": "La dépense sera créée sur la caisse choisie à cette date.", "en": "The expense will be recorded on the chosen register at this date." },
    "po.cancel_order": { "ar": "إلغاء الأمر", "fr": "Annuler l'ordre", "en": "Cancel order" },
    "po.cancel_reason": { "ar": "سبب الإلغاء", "fr": "Motif d'annulation", "en": "Reason for cancelling" },
    "po.expense_link": { "ar": "النفقة المسجلة:", "fr": "Dépense enregistrée :", "en": "Recorded expense:" },
    "po.draft_hint": { "ar": "مسودة: يمكن تعديلها. بعد المصادقة يُقفل الأمر.", "fr": "Brouillon : modifiable. Une fois approuvé, l'ordre est verrouillé.", "en": "Draft: can be edited. Once approved, the order is locked." },
    "po.create_from_expense": { "ar": "إنشاء أمر بالدفع", "fr": "Créer l'ordre de paiement", "en": "Create payment order" },
    "po.print.payment": { "ar": "طباعة أمر الدفع", "fr": "Imprimer l'ordre de paiement", "en": "Print payment order" },
    "po.print.transfer": { "ar": "طباعة أمر التحويل", "fr": "Imprimer l'ordre de virement", "en": "Print transfer order" },
    "po.print.cash": { "ar": "طباعة أمر الدفع نقدًا", "fr": "Imprimer l'ordre de versement", "en": "Print cash order" }
}
```

`po.print` is not itself a message (only `po.print.*` are), and none of the segments after `po.` is a String method name.

- [ ] **Step 10: Build + tests**

Run: `npm run build && php artisan test --filter="PaymentOrderPagesTest|PaymentOrderHttpTest|Transaction" && npm run i18n:check`
Expected: build succeeds, tests PASS, i18n check clean.

- [ ] **Step 11: Commit**

```bash
git add resources/js/Pages/PaymentOrders resources/js/Composables/useNavigation.js resources/js/lib/statusLabels.js resources/js/Pages/Transactions/Show.vue app/Http/Controllers/TransactionController.php tests/Feature/PaymentOrderPagesTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(payment-orders): register, form and order pages; link from the expense page"
```

---

### Task 8: The three printed forms

**Files:**
- Create: `app/Support/AmountInWords.php`
- Create: `app/Services/Pdf/BoardSignatories.php`
- Create: `resources/views/pdf/partials/signatures.blade.php`
- Create: `resources/views/pdf/payment-order.blade.php`, `resources/views/pdf/payment-order-transfer.blade.php`, `resources/views/pdf/payment-order-cash.blade.php`
- Create: `app/Http/Controllers/PaymentOrderPrintController.php`
- Modify: `routes/web.php`, `config/permissions.php` (override), `resources/js/Pages/PaymentOrders/Show.vue` (remove the `route().has` guard)
- Test: `tests/Feature/PaymentOrderPrintTest.php`, `tests/Unit/AmountInWordsTest.php`

**Interfaces:**
- Consumes: `PaymentOrderController::printsFor()` (Task 6), `ClubHeader::data()`, `PdfService::stream(string $html, string $filename, bool $rtl = true, bool $landscape = false)`.
- Produces: route `payment-orders.print` GET `/payment-orders/{paymentOrder}/print/{form}` (`form` ∈ payment|transfer|cash); `AmountInWords::format(float $amount, string $locale): string`; `BoardSignatories::names(): array{president: ?string, treasurer: ?string}`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/AmountInWordsTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\AmountInWords;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AmountInWordsTest extends TestCase
{
    #[Test]
    public function french_and_english_spell_dinars_and_centimes(): void
    {
        $this->assertSame('quarante-cinq mille quatre cent soixante-dix dinars', AmountInWords::format(45470, 'fr'));
        $this->assertSame('one thousand two hundred fifty dinars and fifty centimes', AmountInWords::format(1250.5, 'en'));
    }

    #[Test]
    public function arabic_is_spelled_in_arabic(): void
    {
        $words = AmountInWords::format(45470, 'ar');

        $this->assertStringContainsString('ألف', $words);
        $this->assertStringContainsString('دينار', $words);
    }
}
```

`tests/Feature/PaymentOrderPrintTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BoardMember;
use App\Models\PaymentOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Pdf\PdfService;
use App\Services\Activity\ActivityAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentOrderPrintTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $extra = []): PaymentOrder
    {
        $supplier = Supplier::create(['name' => 'Journal El Massa', 'bank_name' => 'BNA 611', 'rib' => '00100000000000001234']);

        return PaymentOrder::create(array_merge([
            'year' => 2026, 'number' => 7, 'status' => PaymentOrder::APPROVED, 'order_date' => '2026-11-25',
            'subject' => 'Publication renouvellement du bureau', 'amount' => 45470, 'payment_method' => 'bank',
            'beneficiary_type' => 'supplier', 'beneficiary_id' => $supplier->id, 'beneficiary_name' => 'Journal El Massa',
            'beneficiary_bank' => 'BNA 611', 'beneficiary_rib' => '00100000000000001234', 'payment_reference' => 'VIR-07',
        ], $extra));
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
    public function the_payment_order_is_a_real_pdf_in_arabic(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->user('ar'))->get(route('payment-orders.print', ['paymentOrder' => $order, 'form' => 'payment']))->assertOk();

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    #[Test]
    public function the_payment_order_shows_number_subject_amount_words_beneficiary_and_signatures(): void
    {
        BoardMember::create(['name' => 'Président Test', 'role' => 'president', 'status' => 'active']);
        BoardMember::create(['name' => 'Trésorier Test', 'role' => 'treasurer', 'status' => 'active']);
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('payment-orders.print', ['paymentOrder' => $this->order(), 'form' => 'payment']))->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertSame('ordre-de-paiement-07-2026.pdf', $seen['filename']);
        foreach (['07/2026', 'Publication renouvellement du bureau', 'quarante-cinq mille quatre cent soixante-dix dinars',
            'Journal El Massa', '00100000000000001234', 'Président Test', 'Trésorier Test'] as $text) {
            $this->assertStringContainsString($text, $seen['html'], $text);
        }
    }

    #[Test]
    public function the_transfer_order_needs_a_bank_or_ccp_method(): void
    {
        $this->actingAs($this->user())->get(route('payment-orders.print', ['paymentOrder' => $this->order(['payment_method' => 'cash']), 'form' => 'transfer']))
            ->assertNotFound();
    }

    #[Test]
    public function the_cash_order_prints_for_a_cash_order(): void
    {
        $seen = $this->spyPdf();

        $this->actingAs($this->user())->get(route('payment-orders.print', ['paymentOrder' => $this->order(['payment_method' => 'cash']), 'form' => 'cash']))->assertOk();

        $this->assertStringContainsString('Journal El Massa', $seen['html']);
    }

    #[Test]
    public function a_draft_prints_with_a_watermark_and_a_cancelled_order_does_not_print(): void
    {
        $seen = $this->spyPdf();
        $this->actingAs($this->user())->get(route('payment-orders.print', ['paymentOrder' => $this->order(['status' => PaymentOrder::DRAFT]), 'form' => 'payment']))->assertOk();
        $this->assertStringContainsString('BROUILLON', $seen['html']);

        $cancelled = PaymentOrder::create([
            'year' => 2026, 'number' => 8, 'status' => PaymentOrder::CANCELLED, 'order_date' => '2026-11-25', 'subject' => 'X', 'amount' => 1,
        ]);
        $this->get(route('payment-orders.print', ['paymentOrder' => $cancelled, 'form' => 'payment']))->assertNotFound();
    }

    #[Test]
    public function printing_needs_only_the_view_right_and_is_logged(): void
    {
        $this->spyPdf();
        $role = Role::factory()->create(['permissions' => ['payment_orders' => ['view']]]);
        $viewer = User::factory()->create(['privileges' => ['user'], 'role_id' => $role->id]);
        $order = $this->order();

        $this->actingAs($viewer)->get(route('payment-orders.print', ['paymentOrder' => $order, 'form' => 'payment']))->assertOk();

        $this->assertDatabaseHas('activity_logs', ['action' => ActivityAction::PAYMENT_ORDER_PRINTED, 'subject_id' => $order->id]);
    }
}
```

Check: the user locale column is `preferred_lng` (used in `AttendanceLetterPdfTest`), and the app sets the locale from it per request — the attendance PDF test relies on that, so it holds.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="AmountInWordsTest|PaymentOrderPrintTest"`
Expected: FAIL (class / route missing).

- [ ] **Step 3: `AmountInWords`**

```php
<?php

namespace App\Support;

use NumberFormatter;

/**
 * An amount written out, as payment and transfer orders require
 * ("quarante-cinq mille … dinars"). intl's spell-out rules per locale; the
 * desktop's bundled PHP ships intl too.
 */
final class AmountInWords
{
    public static function format(float $amount, string $locale): string
    {
        $amount = round($amount, 2);
        $whole = (int) floor($amount);
        $cents = (int) round(($amount - $whole) * 100);
        $speller = new NumberFormatter($locale, NumberFormatter::SPELLOUT);

        $text = $speller->format($whole).' '.UiLang::get('po.words.dinars', null, $locale);
        if ($cents > 0) {
            $text .= ' '.UiLang::get('po.words.and', null, $locale).' '.$speller->format($cents).' '.UiLang::get('po.words.centimes', null, $locale);
        }

        return $text;
    }
}
```

- [ ] **Step 4: `BoardSignatories` + partial**

```php
<?php

namespace App\Services\Pdf;

use App\Models\BoardMember;

/** The president's and treasurer's names for the signature block (current term, active members). */
final class BoardSignatories
{
    /** @return array{president: ?string, treasurer: ?string} */
    public static function names(): array
    {
        $members = BoardMember::query()->withPlayer()->inCurrentTerm()
            ->where('status', 'active')
            ->whereIn('role', ['president', 'treasurer'])
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->groupBy('role');

        return [
            'president' => $members->get('president')?->first()?->name,
            'treasurer' => $members->get('treasurer')?->first()?->name,
        ];
    }
}
```

`resources/views/pdf/partials/signatures.blade.php` (expects `$L` and `$signatories` in scope):

```blade
<table style="width:100%; margin-top:40px; text-align:center; font-size:12px;">
    <tr>
        <td style="width:50%;">
            <div style="font-weight:bold;">{{ $L('po.pdf.treasurer') }}</div>
            <div style="margin-top:4px;">{{ $signatories['treasurer'] ?? '' }}</div>
            <div style="margin-top:50px; border-top:1px solid #94a3b8; width:70%; margin-left:auto; margin-right:auto;"></div>
        </td>
        <td style="width:50%;">
            <div style="font-weight:bold;">{{ $L('po.pdf.president') }}</div>
            <div style="margin-top:4px;">{{ $signatories['president'] ?? '' }}</div>
            <div style="margin-top:50px; border-top:1px solid #94a3b8; width:70%; margin-left:auto; margin-right:auto;"></div>
        </td>
    </tr>
</table>
```

- [ ] **Step 5: The three views**

`resources/views/pdf/payment-order.blade.php`:

```blade
@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ').' '.$club['currency'];
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
        table.info { width: 100%; border-collapse: collapse; }
        table.info td { padding: 7px 6px; border: 1px solid #cbd5e1; vertical-align: top; }
        table.info td.label { background: #f8fafc; color: #475569; width: 35%; font-weight: bold; }
        .amount { font-size: 16px; font-weight: bold; }
    </style>
</head>
<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    @include('pdf.partials.header')

    @if ($order->status === 'draft')
        <div class="draft">{{ $L('po.pdf.draft') }}</div>
    @endif

    <div class="title">{{ $L('po.pdf.payment_title') }}</div>
    <div class="ref">{{ $L('po.number') }} <bdo dir="ltr">{{ $order->reference }}</bdo> — <bdo dir="ltr">{{ $order->order_date->format('d/m/Y') }}</bdo></div>

    <table class="info">
        <tr><td class="label">{{ $L('po.subject') }}</td><td>{{ $order->subject }}</td></tr>
        <tr><td class="label">{{ $L('amount') }}</td><td class="amount"><bdo dir="ltr">{{ $money($order->amount) }}</bdo></td></tr>
        <tr><td class="label">{{ $L('po.pdf.amount_words') }}</td><td>{{ $words }}</td></tr>
        <tr><td class="label">{{ $L('po.beneficiary') }}</td><td>{{ $beneficiary }}</td></tr>
        @if ($order->beneficiary_bank)
            <tr><td class="label">{{ $L('supplier.field.bank_name') }}</td><td>{{ $order->beneficiary_bank }}</td></tr>
        @endif
        @if ($order->beneficiary_rib)
            <tr><td class="label">{{ $L('supplier.field.rib') }}</td><td><bdo dir="ltr">{{ $order->beneficiary_rib }}</bdo></td></tr>
        @endif
        <tr><td class="label">{{ $L('payment_method') }}</td><td>{{ $method }}</td></tr>
        @if ($order->payment_reference)
            <tr><td class="label">{{ $order->payment_method === 'cheque' ? $L('tx.cheque_number') : $L('po.payment_reference') }}</td><td><bdo dir="ltr">{{ $order->payment_reference }}</bdo></td></tr>
        @endif
        @if ($order->invoice_number)
            <tr><td class="label">{{ $L('tx.invoice_number') }}</td><td><bdo dir="ltr">{{ $order->invoice_number }}</bdo>@if ($order->invoice_date) — <bdo dir="ltr">{{ $order->invoice_date->format('d/m/Y') }}</bdo>@endif</td></tr>
        @endif
        @if ($order->boardMeeting)
            <tr><td class="label">{{ $L('po.pdf.meeting') }}</td><td>@if ($order->boardMeeting->pv_number)<bdo dir="ltr">{{ $order->boardMeeting->pv_number }}</bdo> — @endif<bdo dir="ltr">{{ $order->boardMeeting->meeting_date?->format('d/m/Y') }}</bdo></td></tr>
        @endif
        @if ($order->observation)
            <tr><td class="label">{{ $L('po.observation') }}</td><td>{!! nl2br(e($order->observation)) !!}</td></tr>
        @endif
    </table>

    @include('pdf.partials.signatures')
</body>
</html>
```

`resources/views/pdf/payment-order-transfer.blade.php` (to the bank: debit the club account, credit the beneficiary):

```blade
@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ').' '.$club['currency'];
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
        table.info td { padding: 7px 6px; border: 1px solid #cbd5e1; }
        table.info td.label { background: #f8fafc; color: #475569; width: 35%; font-weight: bold; }
        .section { font-weight: bold; margin: 10px 0 4px; }
    </style>
</head>
<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    @include('pdf.partials.header')

    @if ($order->status === 'draft')
        <div class="draft">{{ $L('po.pdf.draft') }}</div>
    @endif

    <div class="title">{{ $L('po.pdf.transfer_title') }}</div>
    <div class="ref">{{ $L('po.number') }} <bdo dir="ltr">{{ $order->reference }}</bdo> — <bdo dir="ltr">{{ $order->order_date->format('d/m/Y') }}</bdo></div>

    <p>{{ $L('po.pdf.transfer_intro') }}</p>

    <div class="section">{{ $L('po.pdf.debit') }}</div>
    <table class="info">
        <tr><td class="label">{{ $L('po.pdf.account_holder') }}</td><td>{{ $club['name'] }}</td></tr>
        <tr><td class="label">{{ $L('supplier.field.rib') }}</td><td><bdo dir="ltr">{{ $clubRib ?? '' }}</bdo></td></tr>
    </table>

    <div class="section">{{ $L('po.pdf.credit') }}</div>
    <table class="info">
        <tr><td class="label">{{ $L('po.beneficiary') }}</td><td>{{ $beneficiary }}</td></tr>
        <tr><td class="label">{{ $L('supplier.field.bank_name') }}</td><td>{{ $order->beneficiary_bank }}</td></tr>
        <tr><td class="label">{{ $L('supplier.field.rib') }}</td><td><bdo dir="ltr">{{ $order->beneficiary_rib }}</bdo></td></tr>
    </table>

    <table class="info">
        <tr><td class="label">{{ $L('amount') }}</td><td><bdo dir="ltr">{{ $money($order->amount) }}</bdo></td></tr>
        <tr><td class="label">{{ $L('po.pdf.amount_words') }}</td><td>{{ $words }}</td></tr>
        <tr><td class="label">{{ $L('po.subject') }}</td><td>{{ $order->subject }}</td></tr>
        @if ($order->payment_reference)
            <tr><td class="label">{{ $L('po.payment_reference') }}</td><td><bdo dir="ltr">{{ $order->payment_reference }}</bdo></td></tr>
        @endif
    </table>

    @include('pdf.partials.signatures')
</body>
</html>
```

`resources/views/pdf/payment-order-cash.blade.php`:

```blade
@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ').' '.$club['currency'];
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
        table.info { width: 100%; border-collapse: collapse; }
        table.info td { padding: 7px 6px; border: 1px solid #cbd5e1; }
        table.info td.label { background: #f8fafc; color: #475569; width: 35%; font-weight: bold; }
        .received { margin-top: 24px; }
    </style>
</head>
<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    @include('pdf.partials.header')

    @if ($order->status === 'draft')
        <div class="draft">{{ $L('po.pdf.draft') }}</div>
    @endif

    <div class="title">{{ $L('po.pdf.cash_title') }}</div>
    <div class="ref">{{ $L('po.number') }} <bdo dir="ltr">{{ $order->reference }}</bdo> — <bdo dir="ltr">{{ $order->order_date->format('d/m/Y') }}</bdo></div>

    <table class="info">
        <tr><td class="label">{{ $L('po.pdf.paid_to') }}</td><td>{{ $beneficiary }}</td></tr>
        <tr><td class="label">{{ $L('amount') }}</td><td><bdo dir="ltr">{{ $money($order->amount) }}</bdo></td></tr>
        <tr><td class="label">{{ $L('po.pdf.amount_words') }}</td><td>{{ $words }}</td></tr>
        <tr><td class="label">{{ $L('po.subject') }}</td><td>{{ $order->subject }}</td></tr>
        @if ($order->payment_reference)
            <tr><td class="label">{{ $L('po.payment_reference') }}</td><td><bdo dir="ltr">{{ $order->payment_reference }}</bdo></td></tr>
        @endif
    </table>

    <table style="width:100%; margin-top:30px; text-align:center;">
        <tr><td style="width:50%;"></td><td style="width:50%;"><b>{{ $L('po.pdf.received_by') }}</b><div style="margin-top:50px; border-top:1px solid #94a3b8; width:70%; margin-left:auto; margin-right:auto;"></div></td></tr>
    </table>

    @include('pdf.partials.signatures')
</body>
</html>
```

- [ ] **Step 6: Print controller, route, override**

```php
<?php

namespace App\Http\Controllers;

use App\Models\PaymentOrder;
use App\Models\WebsiteConfig;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Pdf\BoardSignatories;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\AmountInWords;
use App\Support\UiLang;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The three A4 forms of a payment order, in the app language. */
class PaymentOrderPrintController extends Controller
{
    private const VIEWS = [
        'payment' => ['pdf.payment-order', 'ordre-de-paiement'],
        'transfer' => ['pdf.payment-order-transfer', 'ordre-de-virement'],
        'cash' => ['pdf.payment-order-cash', 'ordre-de-versement'],
    ];

    public function __construct(private readonly PdfService $pdf) {}

    public function show(Request $request, PaymentOrder $paymentOrder, string $form): Response
    {
        abort_unless(in_array($form, PaymentOrderController::printsFor($paymentOrder), true), 404);

        $paymentOrder->load('boardMeeting:id,meeting_date,pv_number');
        $locale = app()->getLocale();
        [$view, $slug] = self::VIEWS[$form];

        $html = view($view, [
            'club' => ClubHeader::data(),
            'rtl' => $locale === 'ar',
            'order' => $paymentOrder,
            'beneficiary' => $paymentOrder->beneficiaryLabel(),
            'words' => AmountInWords::format((float) $paymentOrder->amount, $locale),
            'method' => $paymentOrder->payment_method ? UiLang::get(self::methodKey($paymentOrder->payment_method)) : '—',
            'clubRib' => WebsiteConfig::singleton()->banking_info['rib'] ?? null,
            'signatories' => BoardSignatories::names(),
        ])->render();

        ActivityRecorder::record($request->user(), ActivityAction::PAYMENT_ORDER_PRINTED, $paymentOrder, [
            'reference' => $paymentOrder->reference, 'form' => $form,
        ]);

        return $this->pdf->stream($html, $slug.'-'.str_replace('/', '-', $paymentOrder->reference).'.pdf', $locale === 'ar');
    }

    /** The UI label key of a payment method (same map as resources/js/lib/statusLabels.js). */
    private static function methodKey(string $method): string
    {
        return ['bank' => 'bank_transfer', 'cash' => 'cash', 'ccp' => 'ccp', 'baridimob' => 'baridimob', 'cheque' => 'cheque'][$method] ?? 'other';
    }
}
```

Check `WebsiteConfig::singleton()->banking_info` shape (`app/Models/WebsiteConfig.php:36,173`) — the club RIB key may be `banking_info['rib']` or nested; use the actual key. Also confirm `ActivityRecorder::record` properties accept a `form` string (it keeps small context values; a fixed enum value is not free text).

Route (after the payment-orders routes):

```php
    Route::get('/payment-orders/{paymentOrder}/print/{form}', [PaymentOrderPrintController::class, 'show'])
        ->whereIn('form', ['payment', 'transfer', 'cash'])->name('payment-orders.print');
```

Override in `config/permissions.php`:

```php
        // Printing an order only reads it.
        'payment-orders.print' => ['payment_orders', 'view'],
```

In `PaymentOrders/Show.vue` remove the `route().has('payment-orders.print')` guard added in Task 7.

- [ ] **Step 7: Translations**

```json
{
    "po.words.dinars": { "ar": "دينار جزائري", "fr": "dinars", "en": "dinars" },
    "po.words.centimes": { "ar": "سنتيم", "fr": "centimes", "en": "centimes" },
    "po.words.and": { "ar": "و", "fr": "et", "en": "and" },
    "po.pdf.payment_title": { "ar": "أمر بالدفع", "fr": "ORDRE DE PAIEMENT", "en": "PAYMENT ORDER" },
    "po.pdf.transfer_title": { "ar": "أمر بالتحويل", "fr": "ORDRE DE VIREMENT", "en": "BANK TRANSFER ORDER" },
    "po.pdf.cash_title": { "ar": "أمر بالدفع نقدًا", "fr": "ORDRE DE VERSEMENT", "en": "CASH PAYMENT ORDER" },
    "po.pdf.draft": { "ar": "مسودة — غير صالحة للدفع", "fr": "BROUILLON — NON VALABLE POUR PAIEMENT", "en": "DRAFT — NOT VALID FOR PAYMENT" },
    "po.pdf.amount_words": { "ar": "المبلغ بالحروف", "fr": "Montant en lettres", "en": "Amount in words" },
    "po.pdf.meeting": { "ar": "محضر الجلسة", "fr": "PV de réunion", "en": "Meeting minutes" },
    "po.pdf.president": { "ar": "الرئيس", "fr": "Le président", "en": "The president" },
    "po.pdf.treasurer": { "ar": "أمين المال", "fr": "Le trésorier", "en": "The treasurer" },
    "po.pdf.transfer_intro": { "ar": "يرجى تحويل المبلغ المذكور أدناه من حساب النادي إلى حساب المستفيد.", "fr": "Veuillez virer le montant ci-dessous du compte du club vers le compte du bénéficiaire.", "en": "Please transfer the amount below from the club's account to the beneficiary's account." },
    "po.pdf.debit": { "ar": "الحساب المدين", "fr": "Compte à débiter", "en": "Account to debit" },
    "po.pdf.credit": { "ar": "الحساب الدائن", "fr": "Compte à créditer", "en": "Account to credit" },
    "po.pdf.account_holder": { "ar": "صاحب الحساب", "fr": "Titulaire", "en": "Account holder" },
    "po.pdf.paid_to": { "ar": "يدفع إلى", "fr": "Payé à", "en": "Paid to" },
    "po.pdf.received_by": { "ar": "استلمت المبلغ (الإمضاء)", "fr": "Reçu (signature)", "en": "Received (signature)" }
}
```

Check `po.words` / `po.pdf` are not messages themselves (only their children) — they are new, so fine.

- [ ] **Step 8: Run tests**

Run: `php artisan test --filter="AmountInWordsTest|PaymentOrderPrintTest|PaymentOrderHttpTest"`
Expected: PASS. If the ICU version on this PC spells French or English slightly differently (e.g. "cinquante" vs "cinquante-et-un"), copy the actual output of `php -r 'echo (new NumberFormatter("fr", NumberFormatter::SPELLOUT))->format(45470);'` into the test — the behaviour under test is "spelled out + unit", not ICU's grammar.

- [ ] **Step 9: Visual check**

Start the app (`php artisan serve --port=2026 --no-reload`), create an order in Arabic and in French, print the three forms, open the PDFs and check: RTL layout in Arabic, RIB and dates not reversed, signature names, watermark on a draft. (Downloads may be captured by IDM with a stale same-named file — open the fresh tab, not the Downloads copy.)

- [ ] **Step 10: Commit**

```bash
git add app/Support/AmountInWords.php app/Services/Pdf/BoardSignatories.php resources/views/pdf/partials/signatures.blade.php resources/views/pdf/payment-order.blade.php resources/views/pdf/payment-order-transfer.blade.php resources/views/pdf/payment-order-cash.blade.php app/Http/Controllers/PaymentOrderPrintController.php routes/web.php config/permissions.php resources/js/Pages/PaymentOrders/Show.vue tests/Unit/AmountInWordsTest.php tests/Feature/PaymentOrderPrintTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(payment-orders): print payment, bank transfer and cash orders"
```

---

### Task 9: Import of the old Access data (suppliers + payment orders)

**Files:**
- Create: `scripts/legacy/export-access.ps1`
- Create: `app/Services/Legacy/LegacyAccessImporter.php`
- Create: `app/Http/Controllers/LegacyImportController.php`
- Create: `resources/js/Pages/LegacyImport/Index.vue`
- Create: `tests/Fixtures/legacy-access.json`
- Modify: `routes/web.php` (superadmin group), `resources/js/Composables/useNavigation.js` (system section)
- Test: `tests/Feature/LegacyAccessImportTest.php`

**Interfaces:**
- Consumes: `Supplier`, `PaymentOrder`.
- Produces:
  - JSON format `irnb-access-v1`: `{ "format": "irnb-access-v1", "suppliers": [{name, bank, rib}], "payment_orders": [{source: "transfer"|"cash"|"payment", reference, date: "Y-m-d"|null, subject, amount, beneficiary, bank, rib, payment_reference, nature}] }` — later packages add `mail`, `missions`, `purchase_orders` keys.
  - `LegacyAccessImporter::import(array $data, User $by): array{suppliers: array{created:int, skipped:int}, payment_orders: array{created:int, skipped:int, merged:int}}`.
  - Routes `legacy-import.index` GET `/legacy-import`, `legacy-import.store` POST `/legacy-import` (superadmin middleware).

- [ ] **Step 1: Fixture `tests/Fixtures/legacy-access.json`** (fake data)

```json
{
    "format": "irnb-access-v1",
    "suppliers": [
        { "name": "Test Journal", "bank": "BNA 611", "rib": "00100000000000001234" },
        { "name": "TEST JOURNAL", "bank": "", "rib": "" },
        { "name": "Test Ligue", "bank": "", "rib": "00700000000000005678" }
    ],
    "payment_orders": [
        { "source": "transfer", "reference": "01/2021", "date": "2021-05-09", "subject": "PUBLICATION", "amount": 2000, "beneficiary": "Test Journal", "bank": "BNA 611", "rib": "00100000000000001234", "payment_reference": "01/2021", "nature": "PUBLICATION DU BUREAU" },
        { "source": "payment", "reference": "01", "date": "2022-10-17", "subject": "Matériel natation", "amount": 45470, "beneficiary": "", "bank": "", "rib": "", "payment_reference": "", "nature": "" },
        { "source": "cash", "reference": "01", "date": "2022-10-17", "subject": "Matériel natation", "amount": 45470, "beneficiary": "Test Trésorier", "bank": "", "rib": "", "payment_reference": "01/2022", "nature": "" },
        { "source": "cash", "reference": "03", "date": "2023-12-23", "subject": "Cotisation des membres", "amount": 20000, "beneficiary": "Test Trésorier", "bank": "", "rib": "", "payment_reference": "01/2023", "nature": "" }
    ]
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\PaymentOrder;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Legacy\LegacyAccessImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LegacyAccessImportTest extends TestCase
{
    use RefreshDatabase;

    private function data(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/legacy-access.json')), true);
    }

    #[Test]
    public function suppliers_and_orders_are_imported_as_history(): void
    {
        $result = app(LegacyAccessImporter::class)->import($this->data(), User::factory()->admin()->create());

        $this->assertSame(['created' => 2, 'skipped' => 1], $result['suppliers']);
        $this->assertSame(['created' => 3, 'skipped' => 0, 'merged' => 1], $result['payment_orders']);
        $this->assertSame('00100000000000001234', Supplier::where('name', 'Test Journal')->value('rib'));

        $order = PaymentOrder::where('legacy_reference', '01/2021')->firstOrFail();
        $this->assertTrue($order->is_legacy);
        $this->assertNull($order->number);
        $this->assertSame(2021, $order->year);
        $this->assertSame(PaymentOrder::PAID, $order->status);
        $this->assertSame('bank', $order->payment_method);
        $this->assertSame('PUBLICATION DU BUREAU', $order->observation);
        $this->assertSame('01/2021', $order->reference);

        // The Arabic payment order and the cash order of the same expense are one order.
        $merged = PaymentOrder::whereDate('order_date', '2022-10-17')->get();
        $this->assertCount(1, $merged);
        $this->assertSame('01/2022', $merged->first()->reference);
        $this->assertSame('Test Trésorier', $merged->first()->beneficiary_name);

        // History only: no money moves.
        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function importing_twice_changes_nothing(): void
    {
        $importer = app(LegacyAccessImporter::class);
        $admin = User::factory()->admin()->create();
        $importer->import($this->data(), $admin);

        $second = $importer->import($this->data(), $admin);

        $this->assertSame(['created' => 0, 'skipped' => 3], $second['suppliers']);
        $this->assertSame(0, $second['payment_orders']['created']);
        $this->assertSame(2, Supplier::count());
        $this->assertSame(3, PaymentOrder::count());
    }

    #[Test]
    public function legacy_orders_never_take_a_sequence_number(): void
    {
        app(LegacyAccessImporter::class)->import($this->data(), User::factory()->admin()->create());

        $this->assertSame(0, PaymentOrder::whereNotNull('number')->count());
    }

    #[Test]
    public function only_a_superadmin_uploads_the_file(): void
    {
        $file = UploadedFile::fake()->createWithContent('legacy.json', file_get_contents(base_path('tests/Fixtures/legacy-access.json')));

        $this->actingAs(User::factory()->create(['privileges' => ['user']]))
            ->post(route('legacy-import.store'), ['file' => $file])->assertForbidden();

        $this->actingAs(User::factory()->create(['privileges' => ['superadmin']]))
            ->post(route('legacy-import.store'), ['file' => $file])
            ->assertSessionHas('legacyResult');
        $this->assertSame(3, PaymentOrder::count());
    }

    #[Test]
    public function a_file_of_another_format_is_refused(): void
    {
        $file = UploadedFile::fake()->createWithContent('other.json', '{"format":"something-else"}');

        $this->actingAs(User::factory()->create(['privileges' => ['superadmin']]))
            ->post(route('legacy-import.store'), ['file' => $file])
            ->assertSessionHasErrors('file');
    }
}
```

Check what the `superadmin` middleware returns for a non-superadmin (403 vs redirect) in `bootstrap/app.php` / its middleware class, and adjust `assertForbidden()` accordingly.

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=LegacyAccessImportTest`
Expected: FAIL (class `LegacyAccessImporter` not found).

- [ ] **Step 4: Importer**

```php
<?php

namespace App\Services\Legacy;

use App\Models\PaymentOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports the export of the club's old MS Access app (format irnb-access-v1,
 * made by scripts/legacy/export-access.ps1). History only: imported payment
 * orders are paid, read-only and create no transaction, so finance totals do
 * not move. Running it again creates nothing (every row is matched first).
 */
final class LegacyAccessImporter
{
    public const FORMAT = 'irnb-access-v1';

    private const METHOD_BY_SOURCE = ['transfer' => 'bank', 'cash' => 'cash', 'payment' => null];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, int>>
     */
    public function import(array $data, User $by): array
    {
        return DB::transaction(function () use ($data, $by) {
            $result = [
                'suppliers' => $this->suppliers($data['suppliers'] ?? []),
                'payment_orders' => $this->paymentOrders($data['payment_orders'] ?? []),
            ];
            ActivityRecorder::record($by, ActivityAction::LEGACY_DATA_IMPORTED, null, [
                'suppliers' => $result['suppliers']['created'],
                'payment_orders' => $result['payment_orders']['created'],
            ]);

            return $result;
        });
    }

    /** @param list<array<string, mixed>> $rows */
    private function suppliers(array $rows): array
    {
        $created = $skipped = 0;
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $existing = Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            if ($existing) {
                // Fill a missing bank detail from a later row, never overwrite one.
                $existing->update(array_filter([
                    'bank_name' => $existing->bank_name ?: self::text($row['bank'] ?? null),
                    'rib' => $existing->rib ?: self::rib($row['rib'] ?? null),
                ]));
                $skipped++;

                continue;
            }
            Supplier::create([
                'name' => Str::limit($name, 200, ''),
                'bank_name' => self::text($row['bank'] ?? null),
                'rib' => self::rib($row['rib'] ?? null),
                'notes' => 'Ancien logiciel (Access)',
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** @param list<array<string, mixed>> $rows */
    private function paymentOrders(array $rows): array
    {
        $created = $skipped = $merged = 0;
        // The Arabic payment order first: its cash-order twin merges into it.
        usort($rows, fn ($a, $b) => (($a['source'] ?? '') === 'payment' ? 0 : 1) <=> (($b['source'] ?? '') === 'payment' ? 0 : 1));

        foreach ($rows as $row) {
            $source = (string) ($row['source'] ?? '');
            if (! array_key_exists($source, self::METHOD_BY_SOURCE)) {
                continue;
            }
            $date = ! empty($row['date']) ? Carbon::parse($row['date'])->format('Y-m-d') : null;
            $amount = round((float) ($row['amount'] ?? 0), 2);
            $reference = self::reference((string) ($row['reference'] ?? ''), $date, (string) ($row['payment_reference'] ?? ''));
            if ($date === null) {
                $skipped++;

                continue;
            }

            if (PaymentOrder::where('is_legacy', true)->where('legacy_source', $source)
                ->where('legacy_reference', $reference)->whereDate('order_date', $date)->where('amount', $amount)->exists()) {
                $skipped++;

                continue;
            }

            // A cash order whose Arabic payment order was imported: same expense.
            if ($source === 'cash') {
                $twin = PaymentOrder::where('is_legacy', true)->where('legacy_source', 'payment')
                    ->whereDate('order_date', $date)->where('amount', $amount)->first();
                if ($twin) {
                    $twin->update([
                        'payment_method' => $twin->payment_method ?? 'cash',
                        'beneficiary_name' => $twin->beneficiary_name ?: self::text($row['beneficiary'] ?? null),
                        'payment_reference' => $twin->payment_reference ?: self::text($row['payment_reference'] ?? null),
                    ]);
                    $merged++;

                    continue;
                }
            }

            PaymentOrder::create([
                'year' => (int) substr($date, 0, 4),
                'number' => null,
                'legacy_reference' => $reference,
                'is_legacy' => true,
                'legacy_source' => $source,
                'status' => PaymentOrder::PAID,
                'order_date' => $date,
                'subject' => Str::limit(trim((string) ($row['subject'] ?? '')) ?: '—', 150, ''),
                'amount' => $amount,
                'payment_method' => self::METHOD_BY_SOURCE[$source],
                'beneficiary_name' => self::text($row['beneficiary'] ?? null),
                'beneficiary_bank' => self::text($row['bank'] ?? null),
                'beneficiary_rib' => self::rib($row['rib'] ?? null),
                'payment_reference' => self::text($row['payment_reference'] ?? null),
                'payment_date' => $date,
                'observation' => self::text($row['nature'] ?? null),
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'merged' => $merged];
    }

    /** "01/2021" stays; a bare "01" (Arabic orders) gets its year: from the cash reference or the date. */
    private static function reference(string $reference, ?string $date, string $paymentReference): string
    {
        $reference = trim(preg_replace('/\s+/', ' ', $reference));
        if (str_contains($reference, '/')) {
            return $reference;
        }
        $year = $date ? substr($date, 0, 4) : (str_contains($paymentReference, '/') ? explode('/', $paymentReference)[1] : '');

        return $year !== '' ? sprintf('%s/%s', str_pad($reference, 2, '0', STR_PAD_LEFT), $year) : $reference;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function rib(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits === '' ? null : substr($digits, 0, 30);
    }
}
```

Check `ActivityRecorder::record()` accepts a null subject (look at its signature); if it requires a model, pass `$by` as the subject.

- [ ] **Step 5: Controller + page + routes + nav**

```php
<?php

namespace App\Http\Controllers;

use App\Services\Legacy\LegacyAccessImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Superadmin: import the export of the old Access app (works on the desktop too, no command line). */
class LegacyImportController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('LegacyImport/Index', ['result' => $request->session()->get('legacyResult')]);
    }

    public function store(Request $request, LegacyAccessImporter $importer): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480']]);
        $data = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);

        if (! is_array($data) || ($data['format'] ?? null) !== LegacyAccessImporter::FORMAT) {
            throw ValidationException::withMessages(['file' => __('validation.mimes', ['attribute' => 'file', 'values' => LegacyAccessImporter::FORMAT])]);
        }

        $result = $importer->import($data, $request->user());

        return back()->with('legacyResult', $result)->with('success', 'flash.legacy_imported');
    }
}
```

Routes inside the existing `Route::middleware('superadmin')->group(...)`:

```php
        // One-time import of the old Access app's export (suppliers, payment orders; mail and missions later).
        Route::get('/legacy-import', [LegacyImportController::class, 'index'])->name('legacy-import.index');
        Route::post('/legacy-import', [LegacyImportController::class, 'store'])->name('legacy-import.store');
```

(import the controller). Check that `legacy-import.*` route names map to no module in `config/permissions.php` (they do not, so `permission` passes them through and `superadmin` gates them).

Nav, system section after roles:

```js
                { label: t('legacy_import.title'), href: '/legacy-import', icon: 'archive', prefix: '/legacy-import', superadminOnly: true },
```

`resources/js/Pages/LegacyImport/Index.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({ result: { type: Object, default: null } });
const form = useForm({ file: null });
const submit = () => form.post(route('legacy-import.store'), { forceFormData: true, onSuccess: () => form.reset() });
</script>

<template>
    <Head :title="t('legacy_import.title')" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('legacy_import.title') }}</h1></template>
        <div class="mx-auto max-w-2xl space-y-6">
            <form @submit.prevent="submit" class="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('legacy_import.hint') }}</p>
                <input type="file" accept="application/json,.json" @change="form.file = $event.target.files[0]" class="w-full text-sm" />
                <InputError :message="form.errors.file" />
                <PrimaryButton :disabled="form.processing || !form.file">{{ t('legacy_import.run') }}</PrimaryButton>
            </form>
            <div v-if="result" class="rounded-2xl bg-white p-6 text-sm shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="mb-3 font-bold text-slate-900 dark:text-slate-100">{{ t('legacy_import.result') }}</h2>
                <ul class="space-y-1 text-slate-700 dark:text-slate-200">
                    <li v-for="(counts, section) in result" :key="section">
                        <b>{{ t('legacy_import.section.' + section) }}</b> —
                        {{ t('legacy_import.created') }} {{ counts.created }} · {{ t('legacy_import.skipped') }} {{ counts.skipped }}<template v-if="counts.merged !== undefined"> · {{ t('legacy_import.merged') }} {{ counts.merged }}</template>
                    </li>
                </ul>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 6: Translations**

```json
{
    "legacy_import.title": { "ar": "استيراد البرنامج القديم", "fr": "Import de l'ancien logiciel", "en": "Old software import" },
    "legacy_import.hint": { "ar": "اختر ملف JSON المستخرج من قاعدة Access القديمة. إعادة الاستيراد لا تكرر شيئًا.", "fr": "Choisissez le fichier JSON exporté de l'ancienne base Access. Réimporter ne crée aucun doublon.", "en": "Choose the JSON file exported from the old Access database. Importing again creates no duplicates." },
    "legacy_import.run": { "ar": "استيراد", "fr": "Importer", "en": "Import" },
    "legacy_import.result": { "ar": "النتيجة", "fr": "Résultat", "en": "Result" },
    "legacy_import.created": { "ar": "أضيف", "fr": "créés", "en": "created" },
    "legacy_import.skipped": { "ar": "موجود من قبل", "fr": "déjà présents", "en": "already there" },
    "legacy_import.merged": { "ar": "مدمج", "fr": "fusionnés", "en": "merged" },
    "legacy_import.section.suppliers": { "ar": "الموردون", "fr": "Fournisseurs", "en": "Suppliers" },
    "legacy_import.section.payment_orders": { "ar": "أوامر الدفع", "fr": "Ordres de paiement", "en": "Payment orders" },
    "flash.legacy_imported": { "ar": "تم الاستيراد.", "fr": "Import terminé.", "en": "Import done." }
}
```

- [ ] **Step 7: Export script `scripts/legacy/export-access.ps1`** (committed; holds no data)

```powershell
<#
  Exports the old MS Access app's tables to the irnb-access-v1 JSON that
  Settings → Old software import reads. Needs the Microsoft ACE OLEDB 16.0
  provider (64-bit). The output holds names and bank numbers: keep it out of git.

  Usage: powershell -File scripts/legacy/export-access.ps1 -Database "<path>.accdb" -Out "D:\BACKUPS\irnb-access-legacy.json"
#>
param(
    [Parameter(Mandatory = $true)][string]$Database,
    [Parameter(Mandatory = $true)][string]$Out
)

$ErrorActionPreference = 'Stop'
$connection = New-Object System.Data.OleDb.OleDbConnection("Provider=Microsoft.ACE.OLEDB.16.0;Data Source=$Database;Mode=Read")
$connection.Open()

function Read-Table([string]$sql) {
    $command = $connection.CreateCommand()
    $command.CommandText = $sql
    $adapter = New-Object System.Data.OleDb.OleDbDataAdapter($command)
    $table = New-Object System.Data.DataTable
    [void]$adapter.Fill($table)
    return ,$table
}
function Get-Text($value) { if ($value -is [DBNull] -or $null -eq $value) { '' } else { ("$value").Trim() } }
function Get-Day($value) { if ($value -is [DateTime]) { $value.ToString('yyyy-MM-dd') } else { $null } }
function Get-Amount($value) { if ($value -is [DBNull] -or $null -eq $value) { 0 } else { [double]$value } }

$suppliers = @{}
$orders = New-Object System.Collections.ArrayList

foreach ($row in (Read-Table 'SELECT * FROM [Fonctionnement]').Rows) {
    $name = Get-Text $row['Fournisseur']
    if ($name -ne '' -and -not $suppliers.ContainsKey($name.ToLower())) {
        $suppliers[$name.ToLower()] = [ordered]@{ name = $name; bank = (Get-Text $row['BANQUE F']); rib = (Get-Text $row['N° COMPTE (RIB) F']) }
    }
    [void]$orders.Add([ordered]@{
        source = 'transfer'; reference = (Get-Text $row['N°1']); date = (Get-Day $row['Date'])
        subject = (Get-Text $row['Objet de La Dèpense:']); amount = (Get-Amount $row['MONTANT'])
        beneficiary = $name; bank = (Get-Text $row['BANQUE F']); rib = (Get-Text $row['N° COMPTE (RIB) F'])
        payment_reference = (Get-Text $row['N° virement']); nature = (Get-Text $row['Nature3'])
    })
}

foreach ($row in (Read-Table 'SELECT * FROM [order de versement]').Rows) {
    $name = Get-Text $row['Fournisseur']
    [void]$orders.Add([ordered]@{
        source = 'cash'; reference = (Get-Text $row['N°1']); date = (Get-Day $row['Date'])
        subject = (Get-Text $row['Objet de La Dèpense:']); amount = (Get-Amount $row['MONTANT'])
        beneficiary = $name; bank = ''; rib = ''
        payment_reference = (Get-Text $row['N° versement']); nature = (Get-Text $row['Nature3'])
    })
}

foreach ($row in (Read-Table 'SELECT * FROM [الامر بالدفع]').Rows) {
    [void]$orders.Add([ordered]@{
        source = 'payment'; reference = (Get-Text $row['رقم الأمر بالدفع']); date = (Get-Day $row['تاريخ'])
        subject = (Get-Text $row['موضوع النفقة']); amount = (Get-Amount $row['مبلغ النفقة'])
        beneficiary = (Get-Text $row['المورد']); bank = (Get-Text $row['البنك']); rib = (Get-Text $row['رقم  البنك البنكي'])
        payment_reference = (Get-Text $row['رقم الشيك']); nature = (Get-Text $row['ملاحظات'])
    })
}

$connection.Close()

$document = [ordered]@{
    format = 'irnb-access-v1'
    exported_at = (Get-Date).ToString('yyyy-MM-ddTHH:mm:ss')
    suppliers = @($suppliers.Values)
    payment_orders = @($orders)
}
$json = $document | ConvertTo-Json -Depth 5
[System.IO.File]::WriteAllText($Out, $json, (New-Object System.Text.UTF8Encoding($false)))
Write-Output ("Wrote {0} suppliers, {1} payment orders to {2}" -f $suppliers.Count, $orders.Count, $Out)
```

The column names above are the ones read from the real `.accdb` on 2026-11-25 (`Fonctionnement`, `order de versement`, `الامر بالدفع`). Save the script as UTF-8 **with BOM** so Windows PowerShell 5.1 reads the Arabic/accented column names correctly.

- [ ] **Step 8: Run tests**

Run: `php artisan test --filter=LegacyAccessImportTest && npm run build && npm run i18n:check`
Expected: PASS / clean.

- [ ] **Step 9: Run the real export (do not commit the output)**

```powershell
powershell -File scripts/legacy/export-access.ps1 -Database "D:\irnb-laravel\Sauvegarde de Sauvegarde de IRNB 2021 FOOT BALL.accdb" -Out "D:\BACKUPS\irnb-access-legacy.json"
```

Expected: `Wrote 1x suppliers, 61 payment orders to D:\BACKUPS\irnb-access-legacy.json` (18 + 24 + 19 rows). Then import it on a **copy** of the dev DB (see memory "DB swap gotchas": use `APP_CONFIG_CACHE` / `--no-reload`) and check: suppliers ≈ 15–20, payment orders ≈ 40–45 after merging, `transactions` count unchanged. Report the counts to the owner; the real import on the web and desktop DBs is the owner's click.

- [ ] **Step 10: Commit**

```bash
git add scripts/legacy/export-access.ps1 app/Services/Legacy/LegacyAccessImporter.php app/Http/Controllers/LegacyImportController.php resources/js/Pages/LegacyImport/Index.vue tests/Fixtures/legacy-access.json tests/Feature/LegacyAccessImportTest.php routes/web.php resources/js/Composables/useNavigation.js resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(legacy): import suppliers and old payment orders from the Access export"
```

---

### Task 10: Full verification

**Files:** none new.

- [ ] **Step 1: Migrations on a fresh DB and on a copy of the dev DB**

Run: `php artisan migrate:fresh --env=testing` is covered by the suite; additionally copy `database/database.sqlite` to a scratch file, point `DB_DATABASE` at it (see memory db-swap-gotchas), run `php artisan migrate`, and confirm `php artisan migrate:rollback --step=4` then `migrate` again works.

- [ ] **Step 2: Checks**

Run: `php artisan config:clear && npm run build && npm run i18n:check && php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit`
Expected: build OK, i18n clean, full suite green (report the count).

- [ ] **Step 3: Browser walk-through (127.0.0.1:2026)**

As an admin: add a supplier → new payment order (supplier picks its RIB) → approve → mark paid → expense page shows the order → print the payment and transfer forms (ar + fr) → cancel another draft → register filters by status/year/supplier. As a role with `payment_orders` view+add+edit but not approve: approve button absent, POST refused. Dark mode and 320 px width on the register and order pages (bare grids need `grid-cols-1`).

- [ ] **Step 4: Hand back**

Do not merge or push: report to the owner with the test count, the export/import counts from Task 9 Step 9, and screenshots of the PDFs. Merge to main and the desktop build happen after owner QA.
