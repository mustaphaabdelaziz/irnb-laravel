# User Permissions (Roles + Per-Module Access) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the coarse all-or-nothing `admin` gate with role-based, per-module (view/add/edit/delete) access control that a superadmin manages.

**Architecture:** A `Role` holds a JSON permission matrix `{module:[actions]}`. Each user references one role (`role_id`) plus optional per-user `permission_overrides` (`{grant, revoke}`). Effective permissions resolve live from role ∪ grant − revoke; users whose legacy `privileges` contain `admin`/`superadmin` short-circuit to full access (god), preserving current behavior and existing tests. A single `permission` middleware maps the current route name → `[module, action]` via `config/permissions.php` and enforces it. The effective matrix is shared to Inertia so the Vue sidebar and buttons hide what the user lacks.

**Tech Stack:** Laravel 13, Inertia v2 + Vue 3, PHPUnit 12 (attribute style), Tailwind, vue-i18n.

## Global Constraints

- Modules (11), verbatim keys: `players`, `subscriptions`, `transactions`, `finance`, `reports`, `equipment`, `inventory`, `board`, `users`, `categories`, `settings`.
- Actions (4), verbatim keys: `view`, `add`, `edit`, `delete`.
- Deny-by-default: no role + no grants ⇒ empty permissions (profile/dashboard only).
- Live resolution: editing a role updates all holders immediately (no snapshot copy).
- Superadmin (privileges contains `superadmin`) manages roles + assigns them; nobody else.
- Legacy god short-circuit: `privileges` ∩ `['admin','superadmin']` ⇒ full access. Do NOT remove `privileges` handling.
- Role manager route `/roles*` is guarded by the `superadmin` middleware, NOT the module map.
- Follow existing patterns: `match()` style validation, `back()->with('success'/'error')`, Inertia `->through()` mapping, PHPUnit `#[Test]` attributes + `RefreshDatabase`.

---

### Task 1: `Role` model + `roles` migration + factory

**Files:**
- Create: `database/migrations/2026_07_07_100001_create_roles_table.php`
- Create: `app/Models/Role.php`
- Create: `database/factories/RoleFactory.php`
- Test: `tests/Unit/RoleModelTest.php`

**Interfaces:**
- Produces: `Role` with fillable `key,name,permissions,is_system`; casts `name`→array, `permissions`→array, `is_system`→bool; consts `Role::MODULES` (11 strings), `Role::ACTIONS` (4 strings); static `Role::allPermissions(): array` → `{module:[all 4 actions]}`; relation `users(): HasMany`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoleModelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_casts_json_columns_and_stores_a_matrix(): void
    {
        $role = Role::create([
            'key' => 'accountant',
            'name' => ['en' => 'Accountant', 'fr' => 'Comptable', 'ar' => 'محاسب'],
            'permissions' => ['finance' => ['view', 'edit'], 'transactions' => ['view']],
        ]);

        $fresh = $role->fresh();
        $this->assertSame(['en' => 'Accountant', 'fr' => 'Comptable', 'ar' => 'محاسب'], $fresh->name);
        $this->assertSame(['view', 'edit'], $fresh->permissions['finance']);
        $this->assertFalse($fresh->is_system);
    }

    #[Test]
    public function all_permissions_covers_every_module_and_action(): void
    {
        $all = Role::allPermissions();
        $this->assertCount(11, $all);
        $this->assertSame(['view', 'add', 'edit', 'delete'], $all['finance']);
        $this->assertArrayHasKey('settings', $all);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/RoleModelTest.php`
Expected: FAIL — `Class "App\Models\Role" not found`.

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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('name');
            $table->json('permissions')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
```

- [ ] **Step 4: Write the `Role` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'name', 'permissions', 'is_system'];

    /** Permission-controlled modules. */
    public const MODULES = [
        'players', 'subscriptions', 'transactions', 'finance', 'reports',
        'equipment', 'inventory', 'board', 'users', 'categories', 'settings',
    ];

    /** Canonical actions per module. */
    public const ACTIONS = ['view', 'add', 'edit', 'delete'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'permissions' => 'array',
            'is_system' => 'boolean',
        ];
    }

    /** Full matrix: every module granted every action. */
    public static function allPermissions(): array
    {
        return array_fill_keys(self::MODULES, self::ACTIONS);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->slug(2),
            'name' => ['en' => $this->faker->jobTitle(), 'fr' => 'Rôle', 'ar' => 'دور'],
            'permissions' => ['players' => ['view']],
            'is_system' => false,
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Unit/RoleModelTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_07_100001_create_roles_table.php app/Models/Role.php database/factories/RoleFactory.php tests/Unit/RoleModelTest.php
git commit -m "feat: add Role model, roles table and factory"
```

---

### Task 2: User columns + permission-resolution methods

**Files:**
- Create: `database/migrations/2026_07_07_100002_add_role_to_users_table.php`
- Modify: `app/Models/User.php` (add relation, casts, fillable, methods)
- Test: `tests/Unit/UserPermissionResolutionTest.php`

**Interfaces:**
- Consumes: `Role`, `Role::allPermissions()`.
- Produces on `User`: `role(): BelongsTo`; `isSuperadmin(): bool`; `isGodAdmin(): bool`; `effectivePermissions(): array` (`{module:[actions]}`); `hasPermission(string $module, string $action): bool`. New columns `role_id` (nullable FK), `permission_overrides` (json cast array).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserPermissionResolutionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function no_role_means_no_permissions(): void
    {
        $user = User::factory()->create(['privileges' => ['user']]);
        $this->assertSame([], $user->effectivePermissions());
        $this->assertFalse($user->hasPermission('players', 'view'));
    }

    #[Test]
    public function role_matrix_is_returned(): void
    {
        $role = Role::factory()->create(['permissions' => ['finance' => ['view', 'edit']]]);
        $user = User::factory()->create(['role_id' => $role->id, 'privileges' => ['user']]);

        $this->assertTrue($user->hasPermission('finance', 'edit'));
        $this->assertFalse($user->hasPermission('finance', 'delete'));
    }

    #[Test]
    public function overrides_grant_and_revoke(): void
    {
        $role = Role::factory()->create(['permissions' => ['finance' => ['view', 'edit']]]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'privileges' => ['user'],
            'permission_overrides' => [
                'grant' => ['players' => ['view']],
                'revoke' => ['finance' => ['edit']],
            ],
        ]);

        $this->assertTrue($user->hasPermission('players', 'view'));   // granted
        $this->assertTrue($user->hasPermission('finance', 'view'));   // kept
        $this->assertFalse($user->hasPermission('finance', 'edit'));  // revoked
    }

    #[Test]
    public function god_admin_has_everything(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin']]);
        $super = User::factory()->create(['privileges' => ['superadmin']]);

        $this->assertTrue($admin->hasPermission('settings', 'delete'));
        $this->assertTrue($super->hasPermission('board', 'add'));
        $this->assertTrue($super->isSuperadmin());
        $this->assertFalse($admin->isSuperadmin());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/UserPermissionResolutionTest.php`
Expected: FAIL — `Call to undefined method App\Models\User::effectivePermissions()`.

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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('privileges')->constrained('roles')->nullOnDelete();
            $table->json('permission_overrides')->nullable()->after('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn('permission_overrides');
        });
    }
};
```

- [ ] **Step 4: Modify `User` — fillable, casts, relation, methods**

Add `'role_id'` and `'permission_overrides'` to `$fillable`. In `casts()` add `'permission_overrides' => 'array'`. Add `use Illuminate\Database\Eloquent\Relations\BelongsTo;` (already imported). Add these members:

```php
public function role(): BelongsTo
{
    return $this->belongsTo(Role::class);
}

public function isSuperadmin(): bool
{
    return in_array('superadmin', $this->privileges ?? [], true);
}

/** Legacy admin/superadmin privilege grants full access during transition. */
public function isGodAdmin(): bool
{
    return (bool) array_intersect(['admin', 'superadmin'], $this->privileges ?? []);
}

/** Resolve the effective permission matrix: role ∪ grant − revoke (god ⇒ all). */
public function effectivePermissions(): array
{
    if ($this->isGodAdmin()) {
        return Role::allPermissions();
    }

    $perms = $this->role?->permissions ?? [];
    $overrides = $this->permission_overrides ?? [];

    foreach (($overrides['grant'] ?? []) as $module => $actions) {
        $perms[$module] = array_values(array_unique([...($perms[$module] ?? []), ...$actions]));
    }

    foreach (($overrides['revoke'] ?? []) as $module => $actions) {
        if (isset($perms[$module])) {
            $perms[$module] = array_values(array_diff($perms[$module], $actions));
            if ($perms[$module] === []) {
                unset($perms[$module]);
            }
        }
    }

    return $perms;
}

public function hasPermission(string $module, string $action): bool
{
    if ($this->isGodAdmin()) {
        return true;
    }

    return in_array($action, $this->effectivePermissions()[$module] ?? [], true);
}
```

Add `use App\Models\Role;` is unnecessary (same namespace). Ensure `Role` referenced as `Role::` works (same `App\Models` namespace — it does).

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Unit/UserPermissionResolutionTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_07_100002_add_role_to_users_table.php app/Models/User.php tests/Unit/UserPermissionResolutionTest.php
git commit -m "feat: add role_id + permission_overrides and effective-permission resolution to User"
```

---

### Task 3: Permission map config + resolver

**Files:**
- Create: `config/permissions.php`
- Create: `app/Support/PermissionMap.php`
- Test: `tests/Unit/PermissionMapTest.php`

**Interfaces:**
- Produces: `PermissionMap::resolve(?string $routeName): ?array` — returns `[module, action]` for a mapped route, or `null` for unguarded/unmapped routes.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Support\PermissionMap;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PermissionMapTest extends TestCase
{
    #[Test]
    public function it_derives_module_and_action_from_route_names(): void
    {
        $this->assertSame(['players', 'view'], PermissionMap::resolve('players.index'));
        $this->assertSame(['players', 'add'], PermissionMap::resolve('players.store'));
        $this->assertSame(['players', 'edit'], PermissionMap::resolve('players.update'));
        $this->assertSame(['players', 'delete'], PermissionMap::resolve('players.destroy'));
        $this->assertSame(['finance', 'edit'], PermissionMap::resolve('finance.years.close'));
        $this->assertSame(['categories', 'add'], PermissionMap::resolve('jobs.store'));
        $this->assertSame(['equipment', 'view'], PermissionMap::resolve('equipment.inventory'));
        $this->assertSame(['board', 'delete'], PermissionMap::resolve('board.meetings.destroy'));
        $this->assertSame(['inventory', 'edit'], PermissionMap::resolve('inventory.participants'));
    }

    #[Test]
    public function overrides_win_and_unguarded_returns_null(): void
    {
        $this->assertSame(['players', 'add'], PermissionMap::resolve('players.transactions.store'));
        $this->assertSame(['reports', 'view'], PermissionMap::resolve('reports.financial'));
        $this->assertNull(PermissionMap::resolve('dashboard'));
        $this->assertNull(PermissionMap::resolve('profile.edit'));
        $this->assertNull(PermissionMap::resolve(null));
        $this->assertNull(PermissionMap::resolve('login')); // unmapped auth route
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/PermissionMapTest.php`
Expected: FAIL — `Class "App\Support\PermissionMap" not found`.

- [ ] **Step 3: Write `config/permissions.php`**

```php
<?php

return [
    // Route-name prefix => module key. Longest matching prefix wins.
    // A route name matches a prefix when it equals it or starts with "prefix.".
    'modules' => [
        'players' => 'players',
        'subscriptions' => 'subscriptions',
        'transactions' => 'transactions',
        'finance' => 'finance',
        'reports' => 'reports',
        'equipment' => 'equipment',
        'inventory' => 'inventory',
        'board' => 'board',
        'board-roles' => 'board',
        'users' => 'users',
        'categories' => 'categories',
        'jobs' => 'categories',
        'positions' => 'categories',
        'equipment-categories' => 'categories',
        'storage-locations' => 'categories',
        'settings' => 'settings',
    ],

    // Exact route name => [module, action]. Wins over prefix derivation.
    'overrides' => [
        'players.transactions.store' => ['players', 'add'],
        'players.transactions.update' => ['players', 'edit'],
        'players.transactions.destroy' => ['players', 'delete'],
        'players.card' => ['players', 'view'],
        'transactions.receipt' => ['transactions', 'view'],
        'reports.financial' => ['reports', 'view'],
        'finance.index' => ['finance', 'view'],
        'finance.settings' => ['finance', 'edit'],
        'inventory.report' => ['inventory', 'view'],
        'board.meetings.minutes' => ['board', 'view'],
    ],

    // Route names that need no permission once authenticated.
    'unguarded' => [
        'dashboard', 'profile.edit', 'profile.update', 'profile.destroy',
        'lang.switch', 'account.pending',
    ],
];
```

- [ ] **Step 4: Write `PermissionMap`**

```php
<?php

namespace App\Support;

class PermissionMap
{
    /** @return array{0:string,1:string}|null [module, action] or null when unguarded. */
    public static function resolve(?string $routeName): ?array
    {
        if ($routeName === null) {
            return null;
        }

        $config = config('permissions');

        if (in_array($routeName, $config['unguarded'], true)) {
            return null;
        }

        if (isset($config['overrides'][$routeName])) {
            return $config['overrides'][$routeName];
        }

        $module = self::matchModule($routeName, $config['modules']);
        if ($module === null) {
            return null; // unmapped => not permission-controlled
        }

        return [$module, self::deriveAction($routeName)];
    }

    private static function matchModule(string $routeName, array $modules): ?string
    {
        $best = null;
        $bestLen = -1;

        foreach ($modules as $prefix => $module) {
            if (($routeName === $prefix || str_starts_with($routeName, $prefix.'.')) && strlen($prefix) > $bestLen) {
                $best = $module;
                $bestLen = strlen($prefix);
            }
        }

        return $best;
    }

    private static function deriveAction(string $routeName): string
    {
        $last = str_contains($routeName, '.') ? substr(strrchr($routeName, '.'), 1) : $routeName;

        return match ($last) {
            'destroy', 'delete' => 'delete',
            'store', 'create' => 'add',
            'index', 'show', 'export', 'template', 'card', 'history',
            'report', 'receipt', 'minutes', 'calendar', 'inventory',
            'preview-serial' => 'view',
            default => 'edit', // update, edit, approve, assign, rent, return, close, counts, participants, …
        };
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Unit/PermissionMapTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add config/permissions.php app/Support/PermissionMap.php tests/Unit/PermissionMapTest.php
git commit -m "feat: add route->permission map config and resolver"
```

---

### Task 4: Middleware (`permission` + `superadmin`) + register aliases

**Files:**
- Create: `app/Http/Middleware/EnsurePermission.php`
- Create: `app/Http/Middleware/EnsureUserIsSuperadmin.php`
- Modify: `bootstrap/app.php` (register aliases; append `permission` to the web group is done at route level in Task 5)
- Test: `tests/Feature/PermissionMiddlewareTest.php`

**Interfaces:**
- Consumes: `PermissionMap::resolve`, `User::hasPermission`, `User::isSuperadmin`.
- Produces: alias `permission` → `EnsurePermission`, alias `superadmin` → `EnsureUserIsSuperadmin`.

- [ ] **Step 1: Write the failing test** (uses a temporary in-test route so it is independent of Task 5)

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth', 'permission'])->group(function () {
            Route::get('/_t/finance', fn () => 'ok')->name('finance.index');
            Route::delete('/_t/finance/{id}', fn () => 'ok')->name('finance.years.close');
        });
    }

    #[Test]
    public function it_blocks_without_permission_and_allows_with(): void
    {
        $viewer = User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::factory()->create(['permissions' => ['finance' => ['view']]])->id,
        ]);

        $this->actingAs($viewer)->get('/_t/finance')->assertOk();               // has finance.view
        $this->actingAs($viewer)->delete('/_t/finance/1')->assertForbidden();   // lacks finance.edit
    }

    #[Test]
    public function god_admin_passes_everything(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin']]);
        $this->actingAs($admin)->delete('/_t/finance/1')->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/PermissionMiddlewareTest.php`
Expected: FAIL — middleware alias `permission` not registered (`Target class [permission] does not exist`).

- [ ] **Step 3: Write `EnsurePermission`**

```php
<?php

namespace App\Http\Middleware;

use App\Support\PermissionMap;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $resolved = PermissionMap::resolve($request->route()?->getName());

        if ($resolved === null) {
            return $next($request); // unguarded / unmapped route
        }

        [$module, $action] = $resolved;

        if (! $request->user()?->hasPermission($module, $action)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Write `EnsureUserIsSuperadmin`**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isSuperadmin()) {
            abort(403, 'Unauthorized: super administrator access required.');
        }

        return $next($request);
    }
}
```

- [ ] **Step 5: Register aliases in `bootstrap/app.php`**

Add the imports and extend the alias array:

```php
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureUserIsSuperadmin;
```

```php
$middleware->alias([
    'admin' => EnsureUserIsAdmin::class,
    'approved' => EnsureAccountApproved::class,
    'permission' => EnsurePermission::class,
    'superadmin' => EnsureUserIsSuperadmin::class,
]);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/PermissionMiddlewareTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/EnsurePermission.php app/Http/Middleware/EnsureUserIsSuperadmin.php bootstrap/app.php tests/Feature/PermissionMiddlewareTest.php
git commit -m "feat: add permission + superadmin middleware and aliases"
```

---

### Task 5: Rewire `routes/web.php` — enforce `permission`, add `/roles`

**Files:**
- Modify: `routes/web.php`
- Test: `tests/Feature/ModuleAccessTest.php`

**Interfaces:**
- Consumes: `permission`, `superadmin` aliases; `RoleController` (created next task — reference the class import now; routes can point at it before its methods exist only if the test in this task does not hit them, so DEFER the roles routes to Task 6). This task ONLY adds `permission` enforcement.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_without_role_is_denied_module_pages(): void
    {
        $user = User::factory()->create(['privileges' => ['user'], 'approved' => true, 'email_verified_at' => now()]);

        $this->actingAs($user)->get(route('players.index'))->assertForbidden();
        $this->actingAs($user)->get(route('finance.index'))->assertForbidden();
    }

    #[Test]
    public function user_with_role_sees_only_granted_modules(): void
    {
        $role = Role::factory()->create(['permissions' => ['players' => ['view']]]);
        $user = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);

        $this->actingAs($user)->get(route('players.index'))->assertOk();
        $this->actingAs($user)->get(route('finance.index'))->assertForbidden();
    }

    #[Test]
    public function dashboard_and_profile_stay_open(): void
    {
        $user = User::factory()->create(['privileges' => ['user'], 'approved' => true, 'email_verified_at' => now()]);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ModuleAccessTest.php`
Expected: FAIL — module pages return 200 (no enforcement yet).

- [ ] **Step 3: Apply `permission` middleware to the authenticated group**

In `routes/web.php`, change the main group opener:

```php
Route::middleware(['auth', 'verified', 'approved'])->group(function () {
```

to:

```php
Route::middleware(['auth', 'verified', 'approved', 'permission'])->group(function () {
```

Then REMOVE the inner `Route::middleware('admin')->group(function () {` wrapper (line ~128) and its matching closing `});` — de-indent its body so those routes live directly in the parent group. They are now governed by the module map (users, categories, jobs, positions, equipment-categories, storage-locations, finance settings, board, settings all resolve to modules). Do not delete any route — only remove the `admin` group wrapper lines.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/ModuleAccessTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Run the full suite to catch regressions from de-gating**

Run: `php artisan test`
Expected: The two plain-`user()` suites fail (`EquipmentItemManagementTest`, `InventoryParticipantsTest`) — they are fixed in Task 11. Everything else PASSES (god short-circuit keeps `privileges=>['admin']` tests green). Note the failures; do not fix yet.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php tests/Feature/ModuleAccessTest.php
git commit -m "feat: enforce per-module permission middleware on authenticated routes"
```

---

### Task 6: `RoleController` + `/roles` routes (superadmin only)

**Files:**
- Create: `app/Http/Controllers/RoleController.php`
- Modify: `routes/web.php` (add roles routes)
- Test: `tests/Feature/RoleManagementTest.php`

**Interfaces:**
- Consumes: `Role`, `Role::MODULES`, `Role::ACTIONS`, `superadmin` middleware.
- Produces: routes `roles.index`, `roles.store`, `roles.update`, `roles.destroy`; Inertia page `Roles/Index`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::factory()->create(['privileges' => ['superadmin'], 'approved' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function superadmin_can_create_a_role(): void
    {
        $this->actingAs($this->superadmin())
            ->post(route('roles.store'), [
                'name' => ['en' => 'Coach', 'fr' => 'Coach', 'ar' => 'مدرب'],
                'permissions' => ['players' => ['view', 'edit']],
            ])->assertRedirect();

        $this->assertDatabaseHas('roles', ['key' => 'coach']);
        $this->assertSame(['view', 'edit'], Role::where('key', 'coach')->first()->permissions['players']);
    }

    #[Test]
    public function invalid_module_or_action_is_rejected(): void
    {
        $this->actingAs($this->superadmin())
            ->post(route('roles.store'), [
                'name' => ['en' => 'Bad'],
                'permissions' => ['nope' => ['fly']],
            ])->assertSessionHasErrors('permissions');
    }

    #[Test]
    public function system_roles_cannot_be_deleted(): void
    {
        $role = Role::factory()->create(['is_system' => true, 'key' => 'administrator']);
        $this->actingAs($this->superadmin())->delete(route('roles.destroy', $role))->assertRedirect();
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    #[Test]
    public function non_superadmin_cannot_manage_roles(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'approved' => true, 'email_verified_at' => now()]);
        $this->actingAs($admin)->get(route('roles.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('roles.store'), ['name' => ['en' => 'X']])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/RoleManagementTest.php`
Expected: FAIL — `route('roles.store')` not defined.

- [ ] **Step 3: Write `RoleController`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Roles/Index', [
            'roles' => Role::query()->withCount('users')->orderByDesc('is_system')->orderBy('key')->get(),
            'modules' => Role::MODULES,
            'actions' => Role::ACTIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRole($request);
        $validated['key'] = $this->uniqueKey($validated['name']);
        $validated['permissions'] = $this->sanitisePermissions($validated['permissions'] ?? []);

        Role::create($validated);

        return back()->with('success', 'Role created successfully.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $validated = $this->validateRole($request);
        $validated['permissions'] = $this->sanitisePermissions($validated['permissions'] ?? []);

        // The superadmin system role always keeps full access.
        if ($role->key === 'superadmin') {
            $validated['permissions'] = Role::allPermissions();
        }
        unset($validated['key']); // key is immutable after creation

        $role->update($validated);

        return back()->with('success', 'Role updated successfully.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->with('error', 'System roles cannot be deleted.');
        }

        // Detaching happens via nullOnDelete on users.role_id.
        $role->delete();

        return back()->with('success', 'Role deleted successfully.');
    }

    private function validateRole(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'array'],
            'name.en' => ['nullable', 'string', 'max:255'],
            'name.fr' => ['nullable', 'string', 'max:255'],
            'name.ar' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array', function ($attr, $value, $fail) {
                $unknownModules = array_diff(array_keys((array) $value), Role::MODULES);
                if ($unknownModules !== []) {
                    $fail('Unknown module in permissions: '.implode(', ', $unknownModules));
                }
                foreach ((array) $value as $actions) {
                    if (array_diff((array) $actions, Role::ACTIONS) !== []) {
                        $fail('Unknown action in permissions.');
                    }
                }
            }],
        ]);
    }

    private function sanitisePermissions(array $permissions): array
    {
        $clean = [];
        foreach ($permissions as $module => $actions) {
            if (! in_array($module, Role::MODULES, true)) {
                continue;
            }
            $actions = array_values(array_intersect(Role::ACTIONS, (array) $actions));
            if ($actions !== []) {
                $clean[$module] = $actions;
            }
        }

        return $clean;
    }

    private function uniqueKey(array $name): string
    {
        $base = Str::slug($name['en'] ?? $name['fr'] ?? $name['ar'] ?? 'role') ?: 'role';
        $key = $base;
        $i = 1;
        while (Role::where('key', $key)->exists()) {
            $key = $base.'-'.(++$i);
        }

        return $key;
    }
}
```

- [ ] **Step 4: Add roles routes in `routes/web.php`** (inside the authenticated group, superadmin-guarded — the `permission` middleware ignores unmapped `roles.*`):

```php
Route::middleware('superadmin')->group(function () {
    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
});
```

Add `use App\Http\Controllers\RoleController;` at the top of `routes/web.php`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/RoleManagementTest.php`
Expected: PASS (4 tests). (The `Roles/Index` page component is added in Task 10; Inertia render of a missing page does not fail these HTTP-assertion tests, but if a test asserts the component, defer that assertion. The above tests assert redirects/errors only, so they pass.)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/RoleController.php routes/web.php tests/Feature/RoleManagementTest.php
git commit -m "feat: add superadmin-only role CRUD"
```

---

### Task 7: Share effective permissions to Inertia + `useCan` composable

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Create: `resources/js/Composables/useCan.js`
- Test: `tests/Feature/SharedPermissionsTest.php`

**Interfaces:**
- Produces shared props: `auth.permissions` (`{module:[actions]}`), `auth.isSuperadmin` (bool). Redefines `auth.isAdmin` = `isSuperadmin || hasPermission('users','view')`.
- Produces `useCan()` → `{ can(module, action), isSuperadmin, permissions }`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SharedPermissionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function effective_permissions_are_shared(): void
    {
        $role = Role::factory()->create(['permissions' => ['players' => ['view', 'edit']]]);
        $user = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.permissions.players', ['view', 'edit'])
                ->where('auth.isSuperadmin', false)
                ->where('auth.isAdmin', false));
    }

    #[Test]
    public function superadmin_flag_and_admin_derived_true(): void
    {
        $user = User::factory()->create(['privileges' => ['superadmin'], 'approved' => true, 'email_verified_at' => now()]);
        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.isSuperadmin', true)
                ->where('auth.isAdmin', true));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/SharedPermissionsTest.php`
Expected: FAIL — `auth.permissions` missing.

- [ ] **Step 3: Modify `HandleInertiaRequests::share`**

Replace the `$isAdmin` line and the `auth` block:

```php
$user = $request->user();
$permissions = $user ? $user->effectivePermissions() : [];
$isSuperadmin = $user?->isSuperadmin() ?? false;
$isAdmin = $isSuperadmin || ($user && $user->hasPermission('users', 'view'));

return [
    ...parent::share($request),
    'auth' => [
        'user' => $user,
        'isAdmin' => $isAdmin,
        'isSuperadmin' => $isSuperadmin,
        'permissions' => $permissions,
    ],
    // …rest unchanged…
];
```

Keep the existing `pendingApprovals` block but base it on `$isAdmin` (unchanged variable name).

- [ ] **Step 4: Write `useCan` composable**

```js
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export function useCan() {
    const page = usePage();

    const isSuperadmin = computed(() => page.props.auth?.isSuperadmin ?? false);
    const permissions = computed(() => page.props.auth?.permissions ?? {});

    function can(module, action) {
        if (isSuperadmin.value) return true;
        return (permissions.value[module] ?? []).includes(action);
    }

    return { can, isSuperadmin, permissions };
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/SharedPermissionsTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/HandleInertiaRequests.php resources/js/Composables/useCan.js tests/Feature/SharedPermissionsTest.php
git commit -m "feat: share effective permissions to Inertia + useCan composable"
```

---

### Task 8: Sidebar filters by permission

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`

**Interfaces:**
- Consumes: `useCan().can`, `useCan().isSuperadmin`.

- [ ] **Step 1: Tag every nav item with a `module` and filter sections**

Import the composable near the other imports:

```js
import { useCan } from '@/Composables/useCan.js';
```

Inside `<script setup>` add:

```js
const { can, isSuperadmin } = useCan();
```

Rewrite the `sections` computed so each item carries a `module`, the full list is always declared, and items/sections are filtered by `can(module,'view')`. Superadmin-only entries (Roles) use `isSuperadmin`:

```js
const sections = computed(() => {
    const raw = [
        { label: t('nav_overview'), items: [
            { label: t('dashboard'), href: '/dashboard', icon: 'dashboard', prefix: '/dashboard', always: true },
        ] },
        { label: t('nav_members'), items: [
            { label: t('players'), href: '/players', icon: 'players', prefix: '/players', module: 'players' },
            { label: t('subscriptions'), href: '/subscriptions', icon: 'subscriptions', prefix: '/subscriptions', module: 'subscriptions' },
        ] },
        { label: t('nav_finance'), items: [
            { label: t('transactions'), href: '/transactions', icon: 'transactions', prefix: '/transactions', module: 'transactions' },
            { label: t('finance'), href: '/finance', icon: 'money', prefix: '/finance', module: 'finance' },
        ] },
        { label: t('nav_equipment'), items: [
            { label: t('equipments'), href: '/equipment/catalogs', icon: 'equipment', prefix: '/equipment/catalogs', module: 'equipment' },
            { label: t('inventory'), href: '/equipment/stocktake', icon: 'clipboard', prefix: '/equipment/stocktake', module: 'inventory' },
            { label: t('equipment_categories'), href: '/equipment-categories', icon: 'equipment', prefix: '/equipment-categories', module: 'categories' },
            { label: t('storage_locations'), href: '/storage-locations', icon: 'equipment', prefix: '/storage-locations', module: 'categories' },
        ] },
        { label: t('nav_governance'), items: [
            { label: t('board'), href: '/board', icon: 'board', prefix: '/board', exact: true, module: 'board' },
            { label: t('calendar'), href: '/board/calendar', icon: 'calendar', prefix: '/board/calendar', module: 'board' },
            { label: t('meetings'), href: '/board/meetings', icon: 'clipboard', prefix: '/board/meetings', module: 'board' },
            { label: t('tasks'), href: '/board/tasks', icon: 'task', prefix: '/board/tasks', module: 'board' },
        ] },
        { label: t('administration'), items: [
            { label: t('members'), href: '/users', icon: 'members', prefix: '/users', badge: pendingApprovals.value, module: 'users' },
            { label: t('categories'), href: '/categories', icon: 'categories', prefix: '/categories', module: 'categories' },
            { label: t('board_roles'), href: '/board-roles', icon: 'board', prefix: '/board-roles', module: 'board' },
            { label: t('jobs'), href: '/jobs', icon: 'jobs', prefix: '/jobs', module: 'categories' },
            { label: t('positions'), href: '/positions', icon: 'positions', prefix: '/positions', module: 'categories' },
            { label: t('roles'), href: '/roles', icon: 'members', prefix: '/roles', superadminOnly: true },
            { label: t('settings'), href: '/settings', icon: 'settings', prefix: '/settings', module: 'settings' },
        ] },
    ];

    return raw
        .map((section) => ({
            ...section,
            items: section.items.filter((i) =>
                i.always || (i.superadminOnly ? isSuperadmin.value : can(i.module, 'view'))),
        }))
        .filter((section) => section.items.length > 0);
});
```

Remove the now-unused `isAdmin` gating inside `sections` (the `isAdmin` computed may remain for `userRole`/header display).

- [ ] **Step 2: Build assets to verify no compile error**

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 3: Manual verification**

Run `php artisan test tests/Feature/SharedPermissionsTest.php` (still green). Then, if a dev server is available, log in as a role-scoped user and confirm only permitted menus render; log in as superadmin and confirm `Roles` appears. (Covered end-to-end in Task 12.)

- [ ] **Step 4: Commit**

```bash
git add resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "feat: filter sidebar menus by effective permissions"
```

---

### Task 9: User edit — role dropdown + advanced overrides

**Files:**
- Modify: `app/Http/Controllers/UserController.php` (edit + update)
- Modify: `app/Http/Requests/User/UpdateUserRequest.php`
- Modify: `resources/js/Pages/Users/Edit.vue`
- Test: `tests/Feature/UserRoleAssignmentTest.php`

**Interfaces:**
- Consumes: `Role`, `Role::MODULES`, `Role::ACTIONS`, `User::isSuperadmin`.
- Produces: `users.update` accepts `role_id` (nullable, exists) and `permission_overrides` (nullable array) — applied only when the actor is superadmin.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserRoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::factory()->create(['privileges' => ['superadmin'], 'approved' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function superadmin_assigns_a_role_to_a_user(): void
    {
        $role = Role::factory()->create(['permissions' => ['players' => ['view']]]);
        $member = User::factory()->create(['privileges' => ['user'], 'email_verified_at' => now()]);

        $this->actingAs($this->superadmin())
            ->put(route('users.update', $member), [
                'name' => $member->name,
                'role_id' => $role->id,
                'permission_overrides' => ['grant' => ['finance' => ['view']]],
            ])->assertRedirect(route('users.index'));

        $member->refresh();
        $this->assertSame($role->id, $member->role_id);
        $this->assertTrue($member->hasPermission('players', 'view'));
        $this->assertTrue($member->hasPermission('finance', 'view'));
    }

    #[Test]
    public function non_superadmin_cannot_change_role(): void
    {
        $role = Role::factory()->create();
        $admin = User::factory()->create(['privileges' => ['admin'], 'email_verified_at' => now()]);
        $member = User::factory()->create(['privileges' => ['user'], 'email_verified_at' => now()]);

        $this->actingAs($admin)
            ->put(route('users.update', $member), [
                'name' => 'Renamed',
                'role_id' => $role->id,
            ])->assertRedirect();

        $member->refresh();
        $this->assertNull($member->role_id);          // role change ignored
        $this->assertSame('Renamed', $member->name);  // profile edit still works
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/UserRoleAssignmentTest.php`
Expected: FAIL — `role_id` not persisted.

- [ ] **Step 3: Extend `UpdateUserRequest` rules**

Add to the returned rules array:

```php
'role_id' => ['nullable', 'integer', 'exists:roles,id'],
'permission_overrides' => ['nullable', 'array'],
'permission_overrides.grant' => ['nullable', 'array'],
'permission_overrides.revoke' => ['nullable', 'array'],
```

- [ ] **Step 4: Apply role fields only for superadmin in `UserController::update`**

After `$validated = $request->validated();` and `unset($validated['picture']);`, and before `$user->update($validated);`, insert:

```php
// Only a superadmin may (re)assign roles and overrides.
if (! $request->user()->isSuperadmin()) {
    unset($validated['role_id'], $validated['permission_overrides']);
}
```

- [ ] **Step 5: Pass roles + current assignment to the edit page**

In `UserController::edit`, add to the `'user'` payload:

```php
'role_id' => $user->role_id,
'permission_overrides' => $user->permission_overrides ?? ['grant' => [], 'revoke' => []],
```

and add sibling props to the `Inertia::render('Users/Edit', [...])` array:

```php
'roles' => \App\Models\Role::orderBy('key')->get(['id', 'key', 'name', 'permissions']),
'modules' => \App\Models\Role::MODULES,
'actions' => \App\Models\Role::ACTIONS,
'canManageAccess' => $request->user()->isSuperadmin(),
```

Change the signature to `public function edit(Request $request, User $user): Response` and `use Illuminate\Http\Request;` (already imported).

- [ ] **Step 6: Update `Users/Edit.vue` — replace privilege checkboxes with Role select + overrides**

Add props `roles`, `modules`, `actions`, `canManageAccess`. Replace the `privileges` form field with `role_id` and `permission_overrides`:

```js
const props = defineProps({
    user: Object,
    roles: { type: Array, default: () => [] },
    modules: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
    canManageAccess: { type: Boolean, default: false },
});

const form = useForm({
    // …existing identity fields…
    role_id: props.user.role_id ?? null,
    permission_overrides: {
        grant: props.user.permission_overrides?.grant ?? {},
        revoke: props.user.permission_overrides?.revoke ?? {},
    },
    approved: props.user.approved ?? false,
    is_active: props.user.is_active ?? true,
    preferred_lng: props.user.preferred_lng || 'ar',
    picture: null,
});

const showAdvanced = ref(false);

function toggleOverride(bucket, module, action) {
    const set = form.permission_overrides[bucket];
    const list = new Set(set[module] ?? []);
    list.has(action) ? list.delete(action) : list.add(action);
    if (list.size) set[module] = [...list];
    else delete set[module];
}
```

Add `import { ref } from 'vue';`. In the template, replace the role checkbox block (the `<div>` containing the `user`/`administrator` checkboxes) with a role dropdown shown only when `canManageAccess`, plus a collapsible advanced grid:

```html
<div v-if="canManageAccess">
    <InputLabel :value="t('role')" />
    <select v-model="form.role_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
        <option :value="null">{{ t('no_role') }}</option>
        <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name?.[preferred_lng] || r.name?.en || r.key }}</option>
    </select>
    <button type="button" class="mt-2 text-sm text-primary-600" @click="showAdvanced = !showAdvanced">
        {{ showAdvanced ? t('hide_advanced') : t('advanced_overrides') }}
    </button>

    <div v-if="showAdvanced" class="mt-3 overflow-x-auto rounded-lg ring-1 ring-slate-200 dark:ring-slate-800">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-slate-500">
                    <th class="p-2">{{ t('module') }}</th>
                    <th v-for="a in actions" :key="a" class="p-2 text-center">{{ t(a) }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="m in modules" :key="m" class="border-t border-slate-100 dark:border-slate-800">
                    <td class="p-2 font-medium">{{ t(m) }}</td>
                    <td v-for="a in actions" :key="a" class="p-2 text-center">
                        <input type="checkbox"
                            :checked="(form.permission_overrides.grant[m] ?? []).includes(a)"
                            @change="toggleOverride('grant', m, a)"
                            class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="p-2 text-xs text-slate-400">{{ t('overrides_hint') }}</p>
    </div>
</div>
```

Use `const preferred_lng = props.user.preferred_lng || 'ar';` for the role label. Update `submit()`’s `transform` to keep `role_id` and `permission_overrides` (they already ride along in `data`); drop the old `privileges` reference. Since `submit()` uses `forceFormData: true`, ensure nested `permission_overrides` serialises — send it JSON-encoded to be safe:

```js
function submit() {
    form.transform((data) => ({
        ...data,
        phones: data.phone ? [data.phone] : [],
        permission_overrides: JSON.stringify(data.permission_overrides),
        _method: 'put',
    })).post(route('users.update', props.user.id), { forceFormData: true });
}
```

And in `UpdateUserRequest`, decode the JSON before validation by adding a `prepareForValidation`:

```php
protected function prepareForValidation(): void
{
    if (is_string($this->permission_overrides)) {
        $this->merge(['permission_overrides' => json_decode($this->permission_overrides, true) ?: []]);
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Feature/UserRoleAssignmentTest.php`
Expected: PASS (2 tests).

- [ ] **Step 8: Build assets**

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/UserController.php app/Http/Requests/User/UpdateUserRequest.php resources/js/Pages/Users/Edit.vue tests/Feature/UserRoleAssignmentTest.php
git commit -m "feat: assign roles + per-user overrides on user edit (superadmin only)"
```

---

### Task 10: Roles admin page (`Roles/Index.vue`) + permission matrix editor

**Files:**
- Create: `resources/js/Pages/Roles/Index.vue`

**Interfaces:**
- Consumes props from `RoleController::index`: `roles` (with `users_count`), `modules`, `actions`.

- [ ] **Step 1: Build the page** (list + create/edit modal with a modules×actions grid)

```html
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t, locale } = useI18n();
const props = defineProps({ roles: Array, modules: Array, actions: Array });

const editing = ref(null);
const form = useForm({ name: { en: '', fr: '', ar: '' }, permissions: {} });

function openCreate() {
    editing.value = 'new';
    form.reset();
    form.permissions = {};
}
function openEdit(role) {
    editing.value = role;
    form.name = { en: role.name?.en ?? '', fr: role.name?.fr ?? '', ar: role.name?.ar ?? '' };
    form.permissions = JSON.parse(JSON.stringify(role.permissions ?? {}));
}
function toggle(module, action) {
    const list = new Set(form.permissions[module] ?? []);
    list.has(action) ? list.delete(action) : list.add(action);
    if (list.size) form.permissions[module] = [...list];
    else delete form.permissions[module];
}
function has(module, action) {
    return (form.permissions[module] ?? []).includes(action);
}
function save() {
    if (editing.value === 'new') {
        form.post(route('roles.store'), { onSuccess: () => (editing.value = null) });
    } else {
        form.put(route('roles.update', editing.value.id), { onSuccess: () => (editing.value = null) });
    }
}
function destroy(role) {
    if (confirm(t('confirm_delete'))) router.delete(route('roles.destroy', role.id));
}
function roleName(role) {
    return role.name?.[locale.value] || role.name?.en || role.key;
}
</script>

<template>
    <Head :title="t('roles')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('roles') }}</h1>
                <PrimaryButton @click="openCreate">{{ t('add') }}</PrimaryButton>
            </div>
        </template>

        <div class="space-y-3">
            <div v-for="role in roles" :key="role.id"
                class="flex items-center justify-between rounded-2xl bg-white dark:bg-slate-900 p-4 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div>
                    <p class="font-semibold text-slate-900 dark:text-slate-100">
                        {{ roleName(role) }}
                        <span v-if="role.is_system" class="ms-2 rounded bg-primary-50 px-2 py-0.5 text-xs text-primary-700">{{ t('system') }}</span>
                    </p>
                    <p class="text-xs text-slate-400">{{ role.users_count }} {{ t('members') }}</p>
                </div>
                <div class="flex gap-2">
                    <SecondaryButton @click="openEdit(role)">{{ t('edit') }}</SecondaryButton>
                    <SecondaryButton v-if="!role.is_system" @click="destroy(role)">{{ t('delete') }}</SecondaryButton>
                </div>
            </div>
        </div>

        <!-- Editor modal -->
        <div v-if="editing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="editing = null">
            <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                <h2 class="mb-4 text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('role') }}</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    <input v-model="form.name.en" placeholder="English" class="rounded-lg border-slate-300 dark:border-slate-700" />
                    <input v-model="form.name.fr" placeholder="Français" class="rounded-lg border-slate-300 dark:border-slate-700" />
                    <input v-model="form.name.ar" placeholder="العربية" class="rounded-lg border-slate-300 dark:border-slate-700" />
                </div>

                <div class="mt-4 overflow-x-auto rounded-lg ring-1 ring-slate-200 dark:ring-slate-800">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500">
                                <th class="p-2">{{ t('module') }}</th>
                                <th v-for="a in actions" :key="a" class="p-2 text-center">{{ t(a) }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in modules" :key="m" class="border-t border-slate-100 dark:border-slate-800">
                                <td class="p-2 font-medium">{{ t(m) }}</td>
                                <td v-for="a in actions" :key="a" class="p-2 text-center">
                                    <input type="checkbox" :checked="has(m, a)" @change="toggle(m, a)"
                                        class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <SecondaryButton @click="editing = null">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="save">{{ t('save_changes') }}</PrimaryButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Build assets**

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 3: Manual smoke (optional here; full run in Task 12)**

Log in as superadmin, open `/roles`, create a role with a matrix, edit it, confirm persistence.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Roles/Index.vue
git commit -m "feat: roles admin page with modules x actions matrix editor"
```

---

### Task 11: Seeder, data migration, adapt existing tests

**Files:**
- Create: `database/seeders/RoleSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php` (call RoleSeeder)
- Create: `database/migrations/2026_07_07_100003_seed_roles_and_map_admins.php`
- Modify: `tests/Feature/EquipmentItemManagementTest.php` (`user()` helper)
- Modify: `tests/Feature/InventoryParticipantsTest.php` (`user()` helper)

**Interfaces:**
- Consumes: `Role`, `Role::allPermissions()`.
- Produces: seeded `superadmin` + `administrator` system roles (+ optional presets); legacy `admin` users linked to the Administrator role.

- [ ] **Step 1: Write `RoleSeeder`**

```php
<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::updateOrCreate(['key' => 'superadmin'], [
            'name' => ['en' => 'Super Admin', 'fr' => 'Super Admin', 'ar' => 'مدير عام'],
            'permissions' => Role::allPermissions(),
            'is_system' => true,
        ]);

        Role::updateOrCreate(['key' => 'administrator'], [
            'name' => ['en' => 'Administrator', 'fr' => 'Administrateur', 'ar' => 'مدير'],
            'permissions' => Role::allPermissions(),
            'is_system' => true,
        ]);

        // Optional starter presets (editable/deletable).
        Role::updateOrCreate(['key' => 'accountant'], [
            'name' => ['en' => 'Accountant', 'fr' => 'Comptable', 'ar' => 'محاسب'],
            'permissions' => [
                'finance' => ['view', 'add', 'edit', 'delete'],
                'transactions' => ['view', 'add', 'edit'],
                'subscriptions' => ['view'],
                'reports' => ['view'],
            ],
            'is_system' => false,
        ]);

        Role::updateOrCreate(['key' => 'coach'], [
            'name' => ['en' => 'Coach', 'fr' => 'Entraîneur', 'ar' => 'مدرب'],
            'permissions' => [
                'players' => ['view', 'add', 'edit'],
                'equipment' => ['view'],
                'inventory' => ['view'],
            ],
            'is_system' => false,
        ]);
    }
}
```

- [ ] **Step 2: Call it from `DatabaseSeeder`**

In `DatabaseSeeder::run`, add near the top: `$this->call(RoleSeeder::class);` (add `use Database\Seeders\RoleSeeder;` if the file uses imports; otherwise reference the class fully-qualified).

- [ ] **Step 3: Write the data migration** (seeds roles for existing installs and links legacy admins)

```php
<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new \Database\Seeders\RoleSeeder())->run();

        $adminRoleId = Role::where('key', 'administrator')->value('id');
        if ($adminRoleId === null) {
            return;
        }

        // Users with a legacy admin/superadmin privilege get the Administrator role.
        User::query()
            ->whereNull('role_id')
            ->get()
            ->each(function (User $user) use ($adminRoleId) {
                if (array_intersect(['admin', 'superadmin'], $user->privileges ?? [])) {
                    $user->update(['role_id' => $adminRoleId]);
                }
            });
    }

    public function down(): void
    {
        // Non-destructive: leave roles + assignments in place.
    }
};
```

- [ ] **Step 4: Adapt the two plain-`user()` test helpers**

The `permission` middleware now blocks a bare approved user. These suites are about equipment/inventory behavior, not permissions, so give the helper a full-access role. In BOTH `tests/Feature/EquipmentItemManagementTest.php` and `tests/Feature/InventoryParticipantsTest.php`, replace the `user()` helper body:

```php
private function user(): User
{
    $role = \App\Models\Role::firstOrCreate(
        ['key' => 'test-access'],
        ['name' => ['en' => 'Test'], 'permissions' => \App\Models\Role::allPermissions(), 'is_system' => false],
    );

    return User::factory()->create(['email_verified_at' => now(), 'role_id' => $role->id]);
}
```

(Keep the `assertForbidden` test in `InventoryParticipantsTest` — it asserts a *completed* session rejects participant edits at the controller level, not a permission failure, so full access does not change its outcome. Verify this specific test still passes; if it relied on lack of permission, adjust its acting user to lack `inventory` edit instead.)

- [ ] **Step 5: Run the affected suites**

Run: `php artisan test tests/Feature/EquipmentItemManagementTest.php tests/Feature/InventoryParticipantsTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/seeders/RoleSeeder.php database/seeders/DatabaseSeeder.php database/migrations/2026_07_07_100003_seed_roles_and_map_admins.php tests/Feature/EquipmentItemManagementTest.php tests/Feature/InventoryParticipantsTest.php
git commit -m "feat: seed system roles, migrate legacy admins, adapt tests"
```

---

### Task 12: i18n keys, full-suite verification, build

**Files:**
- Modify: `resources/js/i18n/en.json`, `fr.json`, `ar.json`

**Interfaces:** none (finalization).

- [ ] **Step 1: Add i18n keys** to all three locale files (values shown for `en`; translate for `fr`/`ar`).

Keys to add (skip any that already exist): `roles`, `role`, `no_role`, `system`, `module`, `view`, `add`, `edit`, `delete`, `advanced_overrides`, `hide_advanced`, `overrides_hint`, `players`, `subscriptions`, `transactions`, `finance`, `reports`, `equipment`, `inventory`, `board`, `users`, `categories`, `settings`, `confirm_delete`.

English values:

```json
{
  "roles": "Roles",
  "role": "Role",
  "no_role": "No role",
  "system": "System",
  "module": "Module",
  "view": "View",
  "add": "Add",
  "edit": "Edit",
  "delete": "Delete",
  "advanced_overrides": "Advanced overrides",
  "hide_advanced": "Hide advanced",
  "overrides_hint": "Checked cells are granted on top of the role.",
  "confirm_delete": "Are you sure you want to delete this?"
}
```

For the module label keys (`players`, `finance`, …), reuse existing translations if present; only add the ones missing. French/Arabic: translate equivalently (e.g. `role` → `Rôle` / `الدور`; `module` → `Module` / `الوحدة`; `view` → `Voir` / `عرض`; `add` → `Ajouter` / `إضافة`; `edit` → `Modifier` / `تعديل`; `delete` → `Supprimer` / `حذف`).

- [ ] **Step 2: Build assets**

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 3: Run the entire test suite**

Run: `php artisan test`
Expected: ALL PASS.

- [ ] **Step 4: Manual end-to-end verification** (per verification-before-completion)

- Fresh migrate + seed: `php artisan migrate:fresh --seed`.
- Log in as a superadmin: `/roles` reachable; create "Receptionist" (players: view/add/edit; subscriptions: view/add). Assign it to a member on `/users/{id}/edit`.
- Log in as that member: sidebar shows only Members (players/subscriptions); Finance/Board/Settings hidden; visiting `/finance` returns 403; Add button on players visible, Delete hidden.
- Log in as a no-role member: only Dashboard + Profile visible.

- [ ] **Step 5: Commit**

```bash
git add resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json
git commit -m "feat: i18n keys for roles + permissions UI"
```

---

## Self-Review

**Spec coverage:**
- Roles + matrix → Tasks 1, 6, 10. ✔
- role_id + overrides + live resolution → Task 2. ✔
- 4 actions / specials-fold-into-edit + route map → Task 3. ✔
- Deny-by-default + enforcement → Tasks 4, 5. ✔
- Superadmin-only role admin → Tasks 6 (backend guard), 9 (user assign guard), 8/10 (UI gating). ✔
- Frontend share + sidebar + buttons → Tasks 7, 8, 9. ✔
- Seed + migrate legacy admins → Task 11. ✔
- Tests (unit resolution + feature enforcement/UI/escalation/migration) → Tasks 1–11. ✔
- i18n → Task 12. ✔

**Placeholder scan:** No TBD/TODO; every code step shows concrete code. Frontend steps that lack unit tests use `npm run build` + explicit manual verification (Task 12) since the repo has no JS test harness.

**Type consistency:** `effectivePermissions()`, `hasPermission()`, `isSuperadmin()`, `isGodAdmin()`, `Role::MODULES`, `Role::ACTIONS`, `Role::allPermissions()`, `PermissionMap::resolve()` used identically across tasks. Shared prop names `auth.permissions` / `auth.isSuperadmin` consistent between Task 7 (producer) and Tasks 8–10 (consumers). Override shape `{grant, revoke}` consistent across Tasks 2, 9.

**Validation:** `validateRole` fails on unknown module keys AND unknown actions via one closure on `permissions` (satisfies the `invalid_module_or_action_is_rejected` test, which asserts a `permissions` error for module `nope`/action `fly`). `sanitisePermissions` additionally strips anything stray before persistence.
