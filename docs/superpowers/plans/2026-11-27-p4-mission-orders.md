# P4 — Mission Orders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add mission orders («أمر بمهمة» / ordre de mission) under **Secretariat** (الأمانة): one or more travelers per order (user, board member, player or a typed name, plus "Add a team"), one A4 sheet per traveler signed by the President and the Secretary General, no number of its own — the first print takes the order's outgoing mail-register reference (`2026/NN`), which every reprint and every sheet shows — editable after print, cancel-never-delete (cancelling also cancels its mail entry, the reference stays), and the import of the 2 old Access orders.

**Architecture:** New `mission_orders` + `mission_order_travelers` tables and models. `App\Services\Secretariat\MissionOrders` owns every write (create / update / cancel / register-the-reference) and the reference lookup, each write one DB transaction with an atomic conditional claim (`lock_version` bump). The reference is the P3 mail entry with source `mission_order` / order id / key `order`: `MailRegister::register()` takes it, `MailRegister::find()` reads it, and a new `MailRegister::cancelSource()` (no number, no file — safe to nest) cancels it inside the mission's cancel transaction so both cancels are atomic. `App\Services\Secretariat\MissionTravelers` builds the form's pickers (default functions) and the "Add a team" rows. Thin controllers: `MissionOrderController` (pages, cancel, `register` JSON, `teamMembers` JSON), `MissionOrderPrintController` (all sheets / one traveler). `MailSources` learns the new source type (label + link in the mail register). Vue: `Pages/Missions/*` and a small `MissionPrintButton` (no dialog). Role module `missions` joins the `secretariat` group.

**Tech Stack:** Laravel 13, Inertia + Vue 3 (`<script setup>`), mPDF (`App\Services\Pdf\PdfService`), PHPUnit feature tests (`#[Test]`, `RefreshDatabase`), SQLite (dev/desktop) + MySQL compatible SQL.

Spec: `docs/superpowers/specs/2026-11-25-access-legacy-documents-design.md` — sections "Owner decisions" (every Mission row, owner-approved 2026-11-26), "Shared rules", "P4 — Mission orders" (the source of truth), "P3 — Mail register" (P4 builds on it), "Import of old Access data", "Testing". P3 code on `main` (`MailEntry`, `MailRegister`, `MailSources`, `MailEntryPrintController`, `pdf/mail-letter.blade.php`, `BoardSignatories`, `MailPrintButton.vue`, `Pages/Mail/*`, `LegacyAccessImporter`) is the pattern to mirror and the code to reuse.

## Global Constraints

- **Worktree:** all work happens in `D:\irnb-mission-orders` on branch `feat/mission-orders`, created from `main`: `git worktree add -b feat/mission-orders D:/irnb-mission-orders main`. Copy `vendor` (a real copy, no junctions): `cp -r D:/irnb-laravel/vendor D:/irnb-mission-orders/vendor`; copy `node_modules` the same way: `cp -r D:/irnb-laravel/node_modules D:/irnb-mission-orders/node_modules` (no `npm ci`: the app works offline); copy `.env`: `cp D:/irnb-laravel/.env D:/irnb-mission-orders/.env`; then once in the worktree: `npm run build`. Never run tests in `D:\irnb-laravel` (its cached config makes tests refuse to run). If `bootstrap/cache/config.php` exists in the worktree, run `php artisan config:clear` first.
- **Tests:** single file `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/X.php` (from the worktree root); full suite `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit`. Never run two test processes at once. Every new test class calls `$this->withoutVite()` in `setUp()` (new page components are not in the Vite manifest until a rebuild). Tasks 3–6 never call `->component()` in Inertia assertions (the page files arrive in Task 7, and the testing config checks they exist).
- **Staging:** stage explicit paths only (`git add <paths>`), never `git add -A` / `git add .` — the repo root holds untracked private files (an `.accdb`, PDFs, notes).
- **Commits:** conventional message, body ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **SQL:** portable SQLite + MySQL — no `strftime`, no `||` concatenation, no `YEAR()`, no partial / filtered indexes, no SQLite-only functions. The year filter is a date range (`order_date >= 'Y-01-01' and order_date < 'Y+1-01-01'`, which also works when SQLite stores `Y-m-d 00:00:00`); years for the filter are computed in PHP (`substr($date, 0, 4)`).
- **Migrations:** timestamps `2026_11_27_100000` (grant) and `2026_11_27_100001` (tables). `2026_11_26_100001`, `100010`, `100011`, `300000`, `300001` already exist on `main`: never reuse them.
- **State changes:** every write is an atomic conditional claim — `MissionOrder::whereKey($id)->where('status', 'active')->where('is_legacy', false)->update([...])`, refuse when it returns 0 — inside `DB::transaction` owned by the service. Every claim bumps `lock_version` (an edit that changes no value still changes a row: MySQL counts changed rows only).
- **Transactions and the mail register:** controllers never wrap a `MissionOrders` or `MailRegister` call in their own `DB::transaction` — the services own them. `MissionOrders::register()` calls `MailRegister::register()` outside any transaction of its own (that method retries a unique-index race and must own its transaction). `MissionOrders::cancel()` is the one place a mail-register write runs inside another transaction: it calls `MailRegister::cancelSource()`, which takes no number (nothing to retry) and touches no file (nothing to clean up outside the transaction), so it nests as a savepoint and the order + its mail entry are cancelled together or not at all.
- **Mission orders are never deleted** (no destroy route, no delete method). Cancel keeps the order; its active mail entry is cancelled with it and keeps its number; the cancelled order still shows that reference ("cancel keeps it"). Imported orders (`is_legacy`) are read-only: no edit, no cancel, no registration; they show `legacy_reference`.
- **Reference rule:** no number of its own. Active order → its active mail entry (`MailRegister::find('mission_order', id, 'order')`), none before the first print. Cancelled order → the mail entry cancelled with it (latest entry of that source). Imported order → `legacy_reference`. Only `POST missions.register` (right `missions` → `edit`) takes a reference; the PDF GET routes (`missions.print`, `missions.print-traveler`) only look it up. Cancelled and imported orders print without registering.
- **Mail entry defaults** (when the first print registers): correspondent = travelers' names, the first three joined with `, ` (`، ` in Arabic) then ` +N` for the rest; subject = `UiLang::get('mis.title').' — '.$destination` (fr «Ordre de mission — Testville», ar «أمر بمهمة — …»). Source type `MailEntry::SOURCE_MISSION = 'mission_order'`, key `MailSources::MISSION_KEY = 'order'`. Missions do **not** go through the P3 print tick box (`mail.sources.*`, `MailSources::URL_TYPES` / `PRINT_MODULES` stay as they are): their own register route needs `missions` → `edit`, not `mail` → `add`.
- **Travelers:** at least one; each row `traveler_type` (`user` / `board_member` / `player` / null = typed), `traveler_id`, `name` and `function` (snapshots, editable), `sort_order`. Function defaults: user → `mis.function.coach` («مدرب»), board member → their board role label (the role's typed label first, else the built-in translation), player → `mis.function.player` («لاعب — {category}», or `player` without a category). The same (type, id) is kept once (client and server). "Add a team" = the team's coaches of the current season (`Season::current()->startYear`) then its players of that season (`TeamMembers`), players who left the team's sport left out; the form lists them ticked, the user unticks who stays home.
- **Picker rights:** the players picker needs `players` → `view`; "Add a team" (picker and JSON route) needs `teams` → `view` and `players` → `view` (plus `missions` → `add` or `edit`). Users and board members are listed to every form user (payment-order precedent).
- **i18n:** ar / fr / en, flat dotted keys, added only through `node scripts/i18n-add.mjs <keys.json>` (insert-only: never re-run it on an existing key, never re-sort catalogs). Keys file: any scratch path, e.g. `C:\Users\MUSTAPHA\AppData\Local\Temp\p4-tN-keys.json`. The module label is the message `missions` (the role editor prints `t(module)`), so **no key may start with `missions.`**: mission keys use the prefix `mis.` (verified free: no `mis` / `mis.*` key exists on `main`). A key that is a message is never also a prefix (the form's "Function" label is `mis.function_label`, not `mis.function`, because `mis.function.coach` exists). No key segment after a message may be a String method name (`link`, `search`, `at`, `replace`, `trim`, `concat`, `split`…). Vue uses `t()` directly, never `te()`. `npm run i18n:check` must pass after every task that adds keys (it also requires every literal `t('…')` in Vue to exist in all three catalogs, so page keys land in the page task).
- **Activity log properties:** ids, references and counts only — never names, functions, destinations or reasons. The four `MISSION_ORDER_*` codes live in the existing `board` area next to P3's `MAIL_ENTRY_*` (a new area would change every area chart and `ActivityReportTest`).
- **Permissions:** route-name derived (`config/permissions.php`). Module `missions`, group `secretariat` (after `mail`), canonical actions (`view`, `add`, `edit`, `delete`). Overrides: `missions.cancel` → `delete`; `missions.print`, `missions.print-traveler`, `missions.team-members` → `view` (the team route's controller also requires `missions` add or edit + `teams` view + `players` view); `missions.register` → `edit`. Grant migration: system roles full, others none, idempotent.
- **PDFs:** mPDF via `PdfService::stream($html, $filename, $rtl)`; `<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">`, RTL when `app()->getLocale() === 'ar'`; `<bdo dir="ltr">` around references and dates (no `<br>` inside a `bdo`); layout with tables only; free text through `App\Support\PdfText::ltrIfLatin()` (Latin lines left-to-right in Arabic pages, line breaks as `<br />` between bdo's); labels via `UiLang::get()`; `<pagebreak />` between sheets; signatures President + Secretary General from `BoardSignatories::names()` (blank line when nobody holds the role). Dates printed `d/m/Y`, dates only.
- **UI:** action buttons are `IconButton`; dark mode on every colored class (`dark:text-primary-300`, never `-400`); layouts work at 320 px (`grid-cols-1` base, tables in `overflow-x-auto`); no CDN / remote resources; never name an Inertia `useForm` field `progress` (or another reserved name). The print button opens the PDF tab synchronously inside the click (`window.open('', '_blank')`), points it at the PDF after the POST, falls back to `window.open(href)` when the tab is null, and is disabled while busy. No Modal per table row (desktop DOM cost): the print button has no dialog at all, and the Show page mounts its one cancel Modal on demand. Any parameter a PDF uses travels in its URL (here the order and traveler ids are in the path; no query).
- **Test data:** fake names only ("Testville", "Entraîneur Test", "Président Test"); the import fixture holds **fake** rows only — never a value read from the `.accdb`. Do not open the `.accdb` or `D:\BACKUPS` while implementing; the Access column names in Task 8 are all the export needs.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Models/Role.php` (modify) | `missions` in `MODULES` and in `MODULE_GROUPS['secretariat']` |
| `config/permissions.php` (modify) | `missions` prefix + overrides |
| `database/migrations/2026_11_27_100000_grant_mission_permissions.php` | system roles get the module |
| `database/migrations/2026_11_27_100001_create_mission_orders_tables.php` | `mission_orders`, `mission_order_travelers` |
| `app/Models/MissionOrder.php`, `app/Models/MissionOrderTraveler.php` | constants, casts, travelers relation, editable rule |
| `app/Models/MailEntry.php` (modify) | `SOURCE_MISSION` |
| `app/Services/Secretariat/MailRegister.php` (modify) | `cancelSource()` (+ shared cancel claim) |
| `app/Exceptions/MissionOrderException.php` | refusals: flash error, or 422 JSON for the register call |
| `app/Services/Secretariat/MissionOrders.php` | create / update / cancel / register, references |
| `app/Services/Activity/ActivityAction.php`, `ActivitySubjectLink.php` (modify) | four `MISSION_ORDER_*` codes, subject link |
| `app/Services/Secretariat/MissionTravelers.php` | form pickers with default functions, "Add a team" rows |
| `app/Http/Requests/Mission/SaveMissionOrderRequest.php` | order fields + travelers, ids that exist |
| `app/Http/Controllers/MissionOrderController.php` | pages, cancel, register (JSON), team members (JSON) |
| `app/Services/Secretariat/MailSources.php` (modify) | `MISSION_KEY`, link + label of a mission source |
| `app/Http/Controllers/MissionOrderPrintController.php`, `resources/views/pdf/mission-order.blade.php` | the sheets |
| `resources/js/Pages/Missions/{Index,Create,Edit,Show}.vue`, `Partials/MissionOrderForm.vue` | mission UI |
| `resources/js/Components/MissionPrintButton.vue` | print button that takes the reference (no dialog) |
| `resources/js/Composables/useNavigation.js` (modify) | Secretariat → Mission orders |
| `app/Services/Legacy/LegacyAccessImporter.php`, `scripts/legacy/export-access.ps1`, `tests/Fixtures/legacy-access.json` (modify) | optional `missions` section |

**Why a sibling `MissionPrintButton` and not `MailPrintButton`:** the P3 button exists to *ask* (tick box, lookup call, `mail` → `add`, `mail.sources.*` routes). A mission print never asks — printing always takes the reference (owner 2026-11-26) — and uses its own route and right. Folding that into `MailPrintButton` would add a second endpoint, a second right and a no-dialog branch to a component five pages depend on; a 50-line sibling with the same tab-opening rules is simpler and needs no Modal at all.

---

### Task 1: `missions` right in the `secretariat` group, grant migration

**Files:**
- Modify: `app/Models/Role.php:28-30, 46-48`
- Modify: `config/permissions.php` (modules list line 30-31, overrides line 188-192)
- Create: `database/migrations/2026_11_27_100000_grant_mission_permissions.php`
- Modify: `resources/js/i18n/ar.json`, `fr.json`, `en.json` (via script)
- Modify (test): `tests/Unit/RoleModelTest.php:33-34`, `tests/Feature/MailRightsTest.php:25`, `tests/js/navI18n.test.mjs:14`
- Test: `tests/Feature/MissionRightsTest.php`

**Interfaces:**
- Produces: module key `missions` (group `secretariat` = `['mail', 'missions']`, actions `['view','add','edit','delete']`); route prefix `missions` → `missions`; exact overrides `missions.cancel` → `delete`, `missions.print` / `missions.print-traveler` / `missions.team-members` → `view`, `missions.register` → `edit`; i18n key `missions` (module label, also the sidebar label in Task 7). `PermissionGrid.vue` already labels the `secretariat` section (P3) — no change there.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MissionRightsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MissionRightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[Test]
    public function missions_is_a_secretariat_module_with_the_canonical_actions_only(): void
    {
        $this->assertContains('missions', Role::MODULES);
        $this->assertSame(['mail', 'missions'], Role::MODULE_GROUPS['secretariat']);
        $this->assertSame(['view', 'add', 'edit', 'delete'], Role::allPermissions()['missions']);
        $this->assertArrayNotHasKey('missions', Role::EXTRA_ACTIONS);
    }

    #[Test]
    public function routes_map_to_the_rights_the_spec_names(): void
    {
        foreach (['index', 'show', 'print', 'print-traveler', 'team-members'] as $route) {
            $this->assertSame(['missions', 'view'], PermissionMap::resolve('missions.'.$route), $route);
        }
        foreach (['create', 'store'] as $route) {
            $this->assertSame(['missions', 'add'], PermissionMap::resolve('missions.'.$route), $route);
        }
        // Printing takes the order's mail-register reference: an edit right.
        foreach (['edit', 'update', 'register'] as $route) {
            $this->assertSame(['missions', 'edit'], PermissionMap::resolve('missions.'.$route), $route);
        }
        // Orders are never deleted: the delete right is cancelling.
        $this->assertSame(['missions', 'delete'], PermissionMap::resolve('missions.cancel'));
    }

    #[Test]
    public function the_grant_migration_gives_system_roles_the_module_and_runs_twice_safely(): void
    {
        $system = Role::factory()->create(['is_system' => true, 'permissions' => ['mail' => ['view']]]);
        $trimmed = Role::factory()->create(['is_system' => true, 'permissions' => ['missions' => ['view']]]);
        $other = Role::factory()->create(['is_system' => false, 'permissions' => ['mail' => ['view']]]);
        $migration = require database_path('migrations/2026_11_27_100000_grant_mission_permissions.php');

        $migration->up();
        $migration->up();

        $this->assertSame(['view', 'add', 'edit', 'delete'], $system->fresh()->permissions['missions']);
        $this->assertSame(['view'], $system->fresh()->permissions['mail']);
        // A system role someone already trimmed keeps its choice.
        $this->assertSame(['view'], $trimmed->fresh()->permissions['missions']);
        $this->assertArrayNotHasKey('missions', $other->fresh()->permissions);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MissionRightsTest.php`
Expected: FAIL (`missions` not in `Role::MODULES`, `PermissionMap::resolve('missions.index')` returns null, migration file missing).

- [ ] **Step 3: Add the module to `Role`**

In `app/Models/Role.php`, in `MODULE_GROUPS` replace

```php
        // The board's secretariat (الأمانة): the mail register now, mission orders in P4.
        'secretariat' => ['mail'],
```

with

```php
        // The board's secretariat (الأمانة): the mail register and mission orders.
        'secretariat' => ['mail', 'missions'],
```

and in `MODULES` replace

```php
        'mail',
        'activities', 'literary_reports',
```

with

```php
        'mail', 'missions',
        'activities', 'literary_reports',
```

- [ ] **Step 4: Map the routes in `config/permissions.php`**

In `config/permissions.php`, in `modules`, replace

```php
        // Secretariat: the mail register (outgoing and incoming letters).
        'mail' => 'mail',
```

with

```php
        // Secretariat: the mail register (outgoing and incoming letters).
        'mail' => 'mail',
        // Secretariat: mission orders (أمر بمهمة).
        'missions' => 'missions',
```

In `config/permissions.php`, in `overrides`, replace

```php
        'mail.sources.show' => ['mail', 'add'],
        'mail.sources.store' => ['mail', 'add'],
    ],
```

with

```php
        'mail.sources.show' => ['mail', 'add'],
        'mail.sources.store' => ['mail', 'add'],
        // Mission orders are never deleted, so the delete right is cancelling one.
        // Printing (all sheets or one traveler) only reads; taking the order's
        // mail-register reference at the first print is an edit. "Add a team"
        // reads a team for the form: the controller also asks for missions
        // add or edit, teams view and players view.
        'missions.cancel' => ['missions', 'delete'],
        'missions.print' => ['missions', 'view'],
        'missions.print-traveler' => ['missions', 'view'],
        'missions.team-members' => ['missions', 'view'],
        'missions.register' => ['missions', 'edit'],
    ],
```

- [ ] **Step 5: Grant migration**

Create `database/migrations/2026_11_27_100000_grant_mission_permissions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mission orders. Owner's rule for new rights: they start empty except the
 * system roles (full access). A migration because the desktop build never
 * seeds. Idempotent: a module a role already has is left alone.
 */
return new class extends Migration
{
    private const GRANT = [
        'missions' => ['view', 'add', 'edit', 'delete'],
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

- [ ] **Step 6: Module label**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p4-t1-keys.json`:

```json
{
    "missions": { "ar": "أوامر المهمة", "fr": "Ordres de mission", "en": "Mission orders" }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p4-t1-keys.json`
Expected: `✓ 1 key(s) written to ar/en/fr`

- [ ] **Step 7: Update the tests that pin the module list**

In `tests/Unit/RoleModelTest.php` replace

```php
        $this->assertCount(35, $all);
        $this->assertArrayHasKey('mail', $all);
```

with

```php
        $this->assertCount(36, $all);
        $this->assertArrayHasKey('mail', $all);
        $this->assertArrayHasKey('missions', $all);
```

In `tests/Feature/MailRightsTest.php` replace

```php
        $this->assertSame(['mail'], Role::MODULE_GROUPS['secretariat']);
```

with

```php
        $this->assertSame(['mail', 'missions'], Role::MODULE_GROUPS['secretariat']);
```

In `tests/js/navI18n.test.mjs` replace

```js
    'nav.secretariat', 'mail',
];
```

with

```js
    'nav.secretariat', 'mail', 'missions',
];
```

- [ ] **Step 8: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MissionRightsTest.php`
Expected: PASS (3 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailRightsTest.php`
Expected: PASS (3 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Unit/RoleModelTest.php`
Expected: PASS (36 modules).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PerListPermissionsTest.php`
Expected: PASS (MODULES and MODULE_GROUPS still list the same modules).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/RoleExtraActionsTest.php`
Expected: PASS.
Run: `npm run test:js`
Expected: PASS.
Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Models/Role.php config/permissions.php database/migrations/2026_11_27_100000_grant_mission_permissions.php tests/Feature/MissionRightsTest.php tests/Unit/RoleModelTest.php tests/Feature/MailRightsTest.php tests/js/navI18n.test.mjs resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(roles): mission orders module in the secretariat group

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 2: `mission_orders` + `mission_order_travelers` tables, models, mail source type

**Files:**
- Create: `database/migrations/2026_11_27_100001_create_mission_orders_tables.php`
- Create: `app/Models/MissionOrder.php`
- Create: `app/Models/MissionOrderTraveler.php`
- Modify: `app/Models/MailEntry.php:40` (new constant after `SOURCE_PURCHASE_ORDER`)
- Test: `tests/Feature/MissionOrderModelTest.php`

**Interfaces:**
- Produces:
  - `MissionOrder` constants `ACTIVE = 'active'`, `CANCELLED = 'cancelled'`, `STATUSES`, `LEGACY_SOURCE = 'ordre_de_mission'`, `FIELDS = ['order_date', 'destination', 'reason', 'departure_date', 'return_date', 'transport']`; casts the three dates `date:Y-m-d`, `is_legacy` bool, `cancelled_at` datetime; hidden `lock_version`; `isEditable(): bool` (active and not imported); `travelers(): HasMany` ordered by `sort_order`, `id`; `createdBy()`, `cancelledBy()`.
  - `MissionOrderTraveler` constants `USER = 'user'`, `BOARD_MEMBER = 'board_member'`, `PLAYER = 'player'`, `TYPES`; fillable `mission_order_id`, `traveler_type`, `traveler_id`, `name`, `function`, `sort_order`; integer casts; `missionOrder(): BelongsTo`.
  - `MailEntry::SOURCE_MISSION = 'mission_order'`.

- [ ] **Step 1: Write the failing model test**

Create `tests/Feature/MissionOrderModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\MissionOrderTraveler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MissionOrderModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function order(array $extra = []): MissionOrder
    {
        return MissionOrder::create(array_merge([
            'order_date' => '2026-11-26', 'destination' => 'Testville', 'reason' => 'Stage de formation',
            'departure_date' => '2026-12-01', 'return_date' => '2026-12-03', 'transport' => 'Bus du club',
            'status' => MissionOrder::ACTIVE,
        ], $extra));
    }

    #[Test]
    public function an_order_reads_its_dates_as_days_and_hides_its_lock_version(): void
    {
        $order = $this->order()->fresh();

        $this->assertSame('2026-11-26', $order->order_date->toDateString());
        $this->assertSame('2026-12-01', $order->departure_date->toDateString());
        $this->assertSame('2026-12-03', $order->return_date->toDateString());
        $this->assertFalse($order->is_legacy);
        $this->assertSame(0, (int) DB::table('mission_orders')->where('id', $order->id)->value('lock_version'));

        $array = $order->toArray();
        $this->assertSame('2026-12-01', $array['departure_date']);
        $this->assertArrayNotHasKey('lock_version', $array);
    }

    #[Test]
    public function only_an_active_order_typed_in_this_app_is_editable(): void
    {
        $this->assertTrue($this->order()->isEditable());
        $this->assertFalse($this->order(['status' => MissionOrder::CANCELLED])->isEditable());
        $this->assertFalse($this->order(['is_legacy' => true, 'legacy_reference' => '59', 'legacy_source' => MissionOrder::LEGACY_SOURCE])->isEditable());
    }

    #[Test]
    public function travelers_come_in_their_order_and_go_with_their_order(): void
    {
        $order = $this->order();
        $order->travelers()->create(['name' => 'Second Test', 'function' => 'Joueur', 'sort_order' => 2]);
        $order->travelers()->create(['traveler_type' => MissionOrderTraveler::USER, 'traveler_id' => 7, 'name' => 'Premier Test', 'function' => 'Entraîneur', 'sort_order' => 1]);

        $this->assertSame(['Premier Test', 'Second Test'], $order->travelers()->pluck('name')->all());
        $first = $order->travelers()->first();
        $this->assertSame('user', $first->traveler_type);
        $this->assertSame(7, $first->traveler_id);
        $this->assertSame($order->id, $first->missionOrder->id);

        // Orders are never deleted in the app; the table still cascades.
        $order->delete();
        $this->assertSame(0, MissionOrderTraveler::count());
    }

    #[Test]
    public function the_traveler_kinds_and_the_mail_source_type_are_named_once(): void
    {
        $this->assertSame(['user', 'board_member', 'player'], MissionOrderTraveler::TYPES);
        $this->assertSame('mission_order', MailEntry::SOURCE_MISSION);
        $this->assertSame(['active', 'cancelled'], MissionOrder::STATUSES);
        $this->assertSame('ordre_de_mission', MissionOrder::LEGACY_SOURCE);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MissionOrderModelTest.php`
Expected: FAIL (`Class "App\Models\MissionOrder" not found`).

- [ ] **Step 3: Tables migration**

Create `database/migrations/2026_11_27_100001_create_mission_orders_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission orders (أمر بمهمة): who travels where, why, when and how, one
 * printed sheet per traveler. No number of their own — the reference is the
 * mail-register entry taken at the first print (source mission_order / id /
 * "order"), so there is no FK here. Never deleted: cancelled instead. The
 * two old Access orders are imported read-only with their text reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_orders', function (Blueprint $table) {
            $table->id();
            // Required by the form; null only on an imported order whose old date could not be read.
            $table->date('order_date')->nullable();
            $table->string('destination', 200)->nullable();
            $table->text('reason')->nullable();
            $table->date('departure_date')->nullable();
            $table->date('return_date')->nullable();
            $table->string('transport', 100)->nullable();
            $table->string('status', 20)->default('active');
            $table->boolean('is_legacy')->default(false);
            $table->string('legacy_reference', 50)->nullable();
            $table->string('legacy_source', 20)->nullable();
            // Bumped by every claim so the claim always changes a row (MySQL counts changed rows only).
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('order_date');
            $table->index(['legacy_source', 'legacy_reference']);
        });

        Schema::create('mission_order_travelers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_order_id')->constrained('mission_orders')->cascadeOnDelete();
            // user / board_member / player, or null for a typed name.
            $table->string('traveler_type', 20)->nullable();
            $table->unsignedBigInteger('traveler_id')->nullable();
            // Snapshots, editable: the sheet prints what the order says.
            $table->string('name', 200);
            $table->string('function', 200)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['traveler_type', 'traveler_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_order_travelers');
        Schema::dropIfExists('mission_orders');
    }
};
```

- [ ] **Step 4: The order model**

Create `app/Models/MissionOrder.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A mission order (أمر بمهمة / ordre de mission): destination, reason,
 * departure and return days, transport, and one or more travelers, each
 * printed on their own sheet. Its reference is the mail-register entry the
 * first print takes (MissionOrders::reference()); imported orders keep their
 * old text in legacy_reference. Cancelled, never deleted.
 */
class MissionOrder extends Model
{
    use SerializesClubDates;

    public const ACTIVE = 'active';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::ACTIVE, self::CANCELLED];

    /** The Access table the imported orders come from. */
    public const LEGACY_SOURCE = 'ordre_de_mission';

    /** What the form sets; status, legacy and audit columns belong to the service. */
    public const FIELDS = ['order_date', 'destination', 'reason', 'departure_date', 'return_date', 'transport'];

    protected $fillable = [
        'order_date', 'destination', 'reason', 'departure_date', 'return_date', 'transport',
        'status', 'is_legacy', 'legacy_reference', 'legacy_source', 'created_by', 'cancelled_by', 'cancelled_at',
    ];

    protected $hidden = ['lock_version'];

    protected function casts(): array
    {
        return [
            'order_date' => 'date:Y-m-d',
            'departure_date' => 'date:Y-m-d',
            'return_date' => 'date:Y-m-d',
            'is_legacy' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Active and typed in this app: editable, cancellable, and its first print takes a reference. */
    public function isEditable(): bool
    {
        return $this->status === self::ACTIVE && ! $this->is_legacy;
    }

    public function travelers(): HasMany
    {
        return $this->hasMany(MissionOrderTraveler::class)->orderBy('sort_order')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
```

- [ ] **Step 5: The traveler model**

Create `app/Models/MissionOrderTraveler.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person on a mission order: an app user (a coach), a board member, a
 * player, or a typed name (type null). Name and function are copies taken
 * when the order was written and stay editable — the sheet prints them.
 */
class MissionOrderTraveler extends Model
{
    use SerializesClubDates;

    public const USER = 'user';

    public const BOARD_MEMBER = 'board_member';

    public const PLAYER = 'player';

    public const TYPES = [self::USER, self::BOARD_MEMBER, self::PLAYER];

    protected $fillable = ['mission_order_id', 'traveler_type', 'traveler_id', 'name', 'function', 'sort_order'];

    protected function casts(): array
    {
        return [
            'mission_order_id' => 'integer',
            'traveler_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function missionOrder(): BelongsTo
    {
        return $this->belongsTo(MissionOrder::class);
    }
}
```

- [ ] **Step 6: The mail source type**

In `app/Models/MailEntry.php` replace

```php
    public const SOURCE_PURCHASE_ORDER = 'purchase_order';
```

with

```php
    public const SOURCE_PURCHASE_ORDER = 'purchase_order';

    /** A mission order's reference: taken at its first print (P4), not through the tick box. */
    public const SOURCE_MISSION = 'mission_order';
```

- [ ] **Step 7: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MissionOrderModelTest.php`
Expected: PASS (4 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailEntryModelTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_11_27_100001_create_mission_orders_tables.php app/Models/MissionOrder.php app/Models/MissionOrderTraveler.php app/Models/MailEntry.php tests/Feature/MissionOrderModelTest.php
git commit -F - <<'EOF'
feat(missions): mission orders and travelers tables and models

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 3: `MissionOrders` service (create, update, cancel with its mail entry, register the reference)

**Files:**
- Create: `app/Exceptions/MissionOrderException.php`
- Create: `app/Services/Secretariat/MissionOrders.php`
- Modify: `app/Services/Secretariat/MailRegister.php` (the `cancel()` method, lines 116-136 → `cancel()` + `cancelSource()` + private `claimCancel()`)
- Modify: `app/Services/Secretariat/MailSources.php:31-32` (constant `MISSION_KEY` only; links and labels come in Task 5)
- Modify: `app/Services/Activity/ActivityAction.php` (constants after `MAIL_ENTRY_REGISTERED`, `ALL`, `AREAS['board']`)
- Modify: `app/Services/Activity/ActivitySubjectLink.php` (import, `MODULES`, `describe()`)
- Modify (test): `tests/Unit/ActivityRecorderTest.php:61`
- Modify: `resources/js/i18n/*.json` (via script)
- Test: `tests/Feature/MissionOrdersTest.php`

**Interfaces:**
- Consumes: `MissionOrder`, `MissionOrderTraveler`, `MailEntry::SOURCE_MISSION` (Task 2); `MailRegister::register(string $type, int $id, string $key, array $defaults, User $by): MailEntry` (returns the existing active entry with `wasRecentlyCreated === false`), `MailRegister::find(string, int, string): ?MailEntry`, `MailEntry::lockFor()`, `reference` accessor (P3); `ActivityRecorder::record(?User, string, ?Model, array)` (drops null properties).
- Produces:
  - `MissionOrderException(string $flashKey)` with public `$flashKey`; renders `back()->with('error', key)`, or `422 {"message": key}` when the request expects JSON.
  - `MailRegister::cancelSource(string $type, int $id, string $key, User $by): ?MailEntry` — cancels the source's active entry (number kept, `source_lock` cleared, `MAIL_ENTRY_CANCELLED` logged), null when there is none; nests safely in a caller's transaction.
  - `MailSources::MISSION_KEY = 'order'`.
  - `MissionOrders` (container-resolved, `final`): `create(array $data, User $by): MissionOrder`; `update(MissionOrder $order, array $data, User $by): MissionOrder`; `cancel(MissionOrder $order, User $by): MissionOrder`; `register(MissionOrder $order, User $by): MailEntry`; `reference(MissionOrder $order): ?string`; `references(iterable $orders): array<int, ?string>` (one query); `static defaults(MissionOrder $order): array{correspondent: string, address: null, subject: string}`. `$data` keys: `MissionOrder::FIELDS` + `travelers` (list of `{traveler_type: ?string, traveler_id: ?int, name: string, function: ?string}`); the returned order has `travelers` loaded.
  - Activity codes `ActivityAction::MISSION_ORDER_CREATED` (`mission_order_created`, props `travelers` count), `MISSION_ORDER_UPDATED` (`reference`, `travelers`), `MISSION_ORDER_CANCELLED` (`reference`), `MISSION_ORDER_PRINTED` (`reference`, `traveler_id` — recorded by Task 6).
  - Flash keys `flash.mission_locked`, `flash.mission_cannot_cancel`, `flash.mission_cannot_register`, `flash.mission_no_traveler`; label key `mis.title`.
  - `ActivitySubjectLink` labels a `MissionOrder` subject `«mis.title» · {destination}` linked to `route('missions.show', $order)` (the route arrives in Task 5), visible to `missions` → `view`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MissionOrdersTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Exceptions\MissionOrderException;
use App\Models\ActivityLog;
use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Secretariat\MailRegister;
use App\Services\Secretariat\MissionOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class MissionOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-26 10:00:00');
        app()->setLocale('fr');
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function missions(): MissionOrders
    {
        return app(MissionOrders::class);
    }

    private function typed(string $name, ?string $function = null): array
    {
        return ['traveler_type' => null, 'traveler_id' => null, 'name' => $name, 'function' => $function];
    }

    private function order(array $extra = []): MissionOrder
    {
        return $this->missions()->create(array_merge([
            'order_date' => '2026-11-26', 'destination' => 'Testville', 'reason' => 'Stage de formation',
            'departure_date' => '2026-12-01', 'return_date' => '2026-12-03', 'transport' => 'Bus du club',
            'travelers' => [$this->typed('Entraîneur Test', 'Entraîneur'), $this->typed('Joueur Test', 'Joueur — U15')],
        ], $extra), $this->admin);
    }

    private function legacy(): MissionOrder
    {
        $order = MissionOrder::create([
            'order_date' => '2018-05-12', 'destination' => 'Vieilleville', 'status' => MissionOrder::ACTIVE,
            'is_legacy' => true, 'legacy_reference' => '59', 'legacy_source' => MissionOrder::LEGACY_SOURCE,
        ]);
        $order->travelers()->create(['name' => 'Ancien Test', 'function' => 'Entraîneur', 'sort_order' => 1]);

        return $order;
    }

    private function refusal(callable $step): string
    {
        try {
            $step();
        } catch (MissionOrderException $e) {
            return $e->flashKey;
        }
        $this->fail('The step was not refused.');
    }

    #[Test]
    public function an_order_keeps_its_fields_and_a_copy_of_each_traveler(): void
    {
        $coach = User::factory()->create();
        $order = $this->order(['travelers' => [
            ['traveler_type' => 'user', 'traveler_id' => $coach->id, 'name' => 'Coach Test', 'function' => 'Entraîneur'],
            $this->typed('Chauffeur Test'),
        ]]);

        $this->assertSame(MissionOrder::ACTIVE, $order->status);
        $this->assertSame($this->admin->id, $order->created_by);
        $this->assertSame('Testville', $order->destination);
        $this->assertSame('2026-12-01', $order->departure_date->toDateString());
        $this->assertSame(['Coach Test', 'Chauffeur Test'], $order->travelers->pluck('name')->all());
        $this->assertSame([1, 2], $order->travelers->pluck('sort_order')->all());
        $this->assertSame(['user', null], $order->travelers->pluck('traveler_type')->all());
        $this->assertSame($coach->id, $order->travelers[0]->traveler_id);
        $this->assertNull($order->travelers[1]->function);
        $this->assertNull($this->missions()->reference($order));
    }

    #[Test]
    public function the_same_person_is_listed_once_and_typed_names_always_stay(): void
    {
        $coach = User::factory()->create();
        $row = ['traveler_type' => 'user', 'traveler_id' => $coach->id, 'name' => 'Coach Test', 'function' => 'Entraîneur'];

        $order = $this->order(['travelers' => [$row, $this->typed('Homonyme'), $row, $this->typed('Homonyme')]]);

        $this->assertSame(['Coach Test', 'Homonyme', 'Homonyme'], $order->travelers->pluck('name')->all());
    }

    #[Test]
    public function an_order_needs_a_traveler(): void
    {
        $this->assertSame('flash.mission_no_traveler', $this->refusal(fn () => $this->order(['travelers' => []])));
        $this->assertSame(0, MissionOrder::count());
    }

    #[Test]
    public function an_edit_rewrites_fields_and_travelers_and_bumps_the_lock_version(): void
    {
        $order = $this->missions()->update($this->order(), [
            'order_date' => '2026-11-27', 'destination' => 'Nouvelleville', 'reason' => null,
            'departure_date' => '2026-12-02', 'return_date' => null, 'transport' => 'Train',
            'travelers' => [$this->typed('Seul Test', 'Arbitre')],
        ], $this->admin);

        $this->assertSame('Nouvelleville', $order->destination);
        $this->assertNull($order->return_date);
        $this->assertSame(['Seul Test'], $order->travelers->pluck('name')->all());
        $this->assertSame(1, (int) DB::table('mission_orders')->where('id', $order->id)->value('lock_version'));
    }

    #[Test]
    public function cancelled_and_imported_orders_are_read_only(): void
    {
        $data = ['destination' => 'X', 'travelers' => [$this->typed('X')]];
        $cancelled = $this->missions()->cancel($this->order(), $this->admin);

        $this->assertSame('flash.mission_locked', $this->refusal(fn () => $this->missions()->update($cancelled, $data, $this->admin)));
        $this->assertSame('flash.mission_locked', $this->refusal(fn () => $this->missions()->update($this->legacy(), $data, $this->admin)));
        $this->assertSame('Testville', $cancelled->fresh()->destination);
    }

    #[Test]
    public function the_first_print_takes_one_outgoing_reference_and_reprints_keep_it(): void
    {
        $order = $this->order();

        $first = $this->missions()->register($order, $this->admin);
        $again = $this->missions()->register($order->fresh(), $this->admin);

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($again->wasRecentlyCreated);
        $this->assertSame($first->id, $again->id);
        $this->assertSame('2026/01', $first->reference);
        $this->assertSame(MailEntry::OUT, $first->direction);
        $this->assertSame([MailEntry::SOURCE_MISSION, $order->id, 'order'], [$first->source_type, $first->source_id, $first->source_key]);
        $this->assertSame('Entraîneur Test, Joueur Test', $first->correspondent);
        $this->assertSame('Ordre de mission — Testville', $first->subject);
        $this->assertSame('2026/01', $this->missions()->reference($order));

        // Still editable after printing; the reference stays.
        $this->missions()->update($order, ['destination' => 'Autreville', 'travelers' => [$this->typed('Seul Test')]], $this->admin);
        $this->assertSame('2026/01', $this->missions()->reference($order->fresh()));
        $this->assertSame(1, MailEntry::count());
    }

    #[Test]
    public function the_correspondent_names_three_travelers_then_counts_the_rest(): void
    {
        $five = array_map(fn ($n) => $this->typed($n), ['Un Test', 'Deux Test', 'Trois Test', 'Quatre Test', 'Cinq Test']);

        $fr = $this->missions()->register($this->order(['travelers' => $five]), $this->admin);
        app()->setLocale('ar');
        $ar = $this->missions()->register($this->order(['travelers' => $five]), $this->admin);

        $this->assertSame('Un Test, Deux Test, Trois Test +2', $fr->correspondent);
        $this->assertSame('Un Test، Deux Test، Trois Test +2', $ar->correspondent);
        $this->assertSame('أمر بمهمة — Testville', $ar->subject);
    }

    #[Test]
    public function cancelled_and_imported_orders_never_take_a_reference(): void
    {
        $cancelled = $this->missions()->cancel($this->order(), $this->admin);
        $legacy = $this->legacy();

        $this->assertSame('flash.mission_cannot_register', $this->refusal(fn () => $this->missions()->register($cancelled, $this->admin)));
        $this->assertSame('flash.mission_cannot_register', $this->refusal(fn () => $this->missions()->register($legacy, $this->admin)));
        $this->assertSame(0, MailEntry::count());
        $this->assertSame('59', $this->missions()->reference($legacy));
    }

    #[Test]
    public function cancelling_cancels_the_mail_entry_and_the_order_keeps_its_reference(): void
    {
        $order = $this->order();
        $this->missions()->register($order, $this->admin);

        $cancelled = $this->missions()->cancel($order, $this->admin);

        $this->assertSame(MissionOrder::CANCELLED, $cancelled->status);
        $this->assertSame($this->admin->id, $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
        $entry = MailEntry::sole();
        $this->assertSame(MailEntry::CANCELLED, $entry->status);
        $this->assertSame(1, $entry->number);
        $this->assertSame('2026/01', $this->missions()->reference($cancelled));
        // The number stays taken.
        $this->assertSame('2026/02', $this->missions()->register($this->order(), $this->admin)->reference);

        $this->assertSame('flash.mission_cannot_cancel', $this->refusal(fn () => $this->missions()->cancel($cancelled, $this->admin)));
        $this->assertSame('flash.mission_cannot_cancel', $this->refusal(fn () => $this->missions()->cancel($this->legacy(), $this->admin)));
    }

    #[Test]
    public function an_order_never_printed_is_cancelled_alone(): void
    {
        $cancelled = $this->missions()->cancel($this->order(), $this->admin);

        $this->assertSame(MissionOrder::CANCELLED, $cancelled->status);
        $this->assertSame(0, MailEntry::count());
        $this->assertNull($this->missions()->reference($cancelled));
    }

    #[Test]
    public function the_order_and_its_mail_entry_are_cancelled_together_or_not_at_all(): void
    {
        $order = $this->order();
        $entry = $this->missions()->register($order, $this->admin);
        // The mail entry's cancel fails after the order's claim ran.
        DB::listen(function ($query) {
            $sql = strtolower(ltrim($query->sql));
            if (str_starts_with($sql, 'update') && str_contains($sql, 'mail_entries')) {
                throw new RuntimeException('mail register unavailable');
            }
        });

        try {
            $this->missions()->cancel($order, $this->admin);
            $this->fail('The cancel went through.');
        } catch (RuntimeException $e) {
            $this->assertSame('mail register unavailable', $e->getMessage());
        }

        $this->assertSame(MissionOrder::ACTIVE, $order->fresh()->status);
        $this->assertSame(MailEntry::ACTIVE, $entry->fresh()->status);
        $this->assertSame(0, ActivityLog::where('action', ActivityAction::MISSION_ORDER_CANCELLED)->count());
    }

    #[Test]
    public function an_entry_cancelled_in_the_register_lets_the_next_print_take_a_new_reference(): void
    {
        $order = $this->order();
        $entry = $this->missions()->register($order, $this->admin);
        app(MailRegister::class)->cancel($entry, $this->admin);

        $this->assertNull($this->missions()->reference($order));
        $this->assertSame('2026/02', $this->missions()->register($order, $this->admin)->reference);
        $this->assertSame('2026/02', $this->missions()->reference($order));
    }

    #[Test]
    public function cancel_source_cancels_only_an_active_entry(): void
    {
        $register = app(MailRegister::class);
        $this->assertNull($register->cancelSource(MailEntry::SOURCE_MISSION, 99, 'order', $this->admin));

        $entry = $register->register(MailEntry::SOURCE_MISSION, 99, 'order', ['correspondent' => 'X', 'subject' => 'Y'], $this->admin);
        $cancelled = $register->cancelSource(MailEntry::SOURCE_MISSION, 99, 'order', $this->admin);

        $this->assertSame($entry->id, $cancelled?->id);
        $this->assertSame(MailEntry::CANCELLED, $cancelled->status);
        $this->assertNull($register->find(MailEntry::SOURCE_MISSION, 99, 'order'));
        $this->assertNull($register->cancelSource(MailEntry::SOURCE_MISSION, 99, 'order', $this->admin));
    }

    #[Test]
    public function a_page_of_references_costs_one_query(): void
    {
        $printed = $this->order();
        $this->missions()->register($printed, $this->admin);              // 2026/01
        $cancelled = $this->order();
        $this->missions()->register($cancelled, $this->admin);            // 2026/02
        $cancelled = $this->missions()->cancel($cancelled, $this->admin);
        $draft = $this->order();
        $legacy = $this->legacy();
        $orders = [$printed->fresh(), $cancelled, $draft, $legacy];

        DB::enableQueryLog();
        $references = $this->missions()->references($orders);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries);
        $this->assertSame([$printed->id => '2026/01', $cancelled->id => '2026/02', $draft->id => null, $legacy->id => '59'], $references);
    }

    #[Test]
    public function the_activity_log_keeps_ids_references_and_counts_never_names_or_places(): void
    {
        $order = $this->order();
        $this->missions()->register($order, $this->admin);
        $this->missions()->update($order, ['destination' => 'Autreville', 'travelers' => [$this->typed('Seul Test', 'Arbitre')]], $this->admin);
        $this->missions()->cancel($order->fresh(), $this->admin);

        $created = ActivityLog::where('action', ActivityAction::MISSION_ORDER_CREATED)->sole();
        $this->assertSame($order->id, (int) $created->subject_id);
        $this->assertSame(['travelers' => 2], $created->properties);
        $this->assertSame(['reference' => '2026/01', 'travelers' => 1], ActivityLog::where('action', ActivityAction::MISSION_ORDER_UPDATED)->sole()->properties);
        $this->assertSame(['reference' => '2026/01'], ActivityLog::where('action', ActivityAction::MISSION_ORDER_CANCELLED)->sole()->properties);
        foreach (ActivityLog::all() as $log) {
            foreach (['Testville', 'Autreville', 'Entraîneur Test', 'Seul Test', 'Arbitre', 'Stage'] as $text) {
                $this->assertStringNotContainsString($text, (string) json_encode($log->properties, JSON_UNESCAPED_UNICODE));
            }
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MissionOrdersTest.php`
Expected: FAIL (`Class "App\Services\Secretariat\MissionOrders" not found`).

- [ ] **Step 3: Exception**

Create `app/Exceptions/MissionOrderException.php`:

```php
<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A mission-order step the order's state refuses (editing or cancelling a
 * cancelled or imported order, taking a reference for one…). An expected
 * refusal, never logged: the flash error it names on a page, or — for the
 * Print buttons' JSON call — a 422 whose message is that flash key.
 */
class MissionOrderException extends RuntimeException
{
    public function __construct(public readonly string $flashKey)
    {
        parent::__construct($flashKey);
    }

    public function report(): void {}

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->flashKey], 422);
        }

        return back()->withInput()->with('error', $this->flashKey);
    }
}
```

- [ ] **Step 4: `MailRegister::cancelSource()`**

In `app/Services/Secretariat/MailRegister.php` replace

```php
    /** Active, not imported; keeps its number and frees its printed document for a new entry. */
    public function cancel(MailEntry $entry, User $by): MailEntry
    {
        return DB::transaction(function () use ($entry, $by) {
            $claimed = MailEntry::whereKey($entry->id)
                ->where('status', MailEntry::ACTIVE)->where('is_legacy', false)
                ->update([
                    'status' => MailEntry::CANCELLED,
                    'cancelled_by' => $by->id,
                    'cancelled_at' => now(),
                    'source_lock' => null,
                    'updated_at' => now(),
                ]);
            if ($claimed === 0) {
                throw new MailEntryException('flash.mail_cannot_cancel');
            }
            ActivityRecorder::record($by, ActivityAction::MAIL_ENTRY_CANCELLED, $entry, self::properties($entry));

            return $entry->fresh();
        });
    }
```

with

```php
    /** Active, not imported; keeps its number and frees its printed document for a new entry. */
    public function cancel(MailEntry $entry, User $by): MailEntry
    {
        return DB::transaction(function () use ($entry, $by) {
            if (! $this->claimCancel($entry, $by)) {
                throw new MailEntryException('flash.mail_cannot_cancel');
            }

            return $entry->fresh();
        });
    }

    /**
     * Cancels a printed document's active entry — null when it has none (or
     * someone cancelled it first) — for a caller cancelling the document itself
     * in its own transaction (a mission order). Safe inside that transaction:
     * it nests as a savepoint, and unlike register() it takes no number
     * (nothing to retry) and unlike update() it touches no file (nothing to
     * clean up outside the transaction).
     */
    public function cancelSource(string $type, int $id, string $key, User $by): ?MailEntry
    {
        return DB::transaction(function () use ($type, $id, $key, $by) {
            $entry = $this->find($type, $id, $key);

            return $entry !== null && $this->claimCancel($entry, $by) ? $entry->fresh() : null;
        });
    }

    /** The cancel claim: active and not imported → cancelled, number kept, document freed. */
    private function claimCancel(MailEntry $entry, User $by): bool
    {
        $claimed = MailEntry::whereKey($entry->id)
            ->where('status', MailEntry::ACTIVE)->where('is_legacy', false)
            ->update([
                'status' => MailEntry::CANCELLED,
                'cancelled_by' => $by->id,
                'cancelled_at' => now(),
                'source_lock' => null,
                'updated_at' => now(),
            ]);
        if ($claimed === 0) {
            return false;
        }
        ActivityRecorder::record($by, ActivityAction::MAIL_ENTRY_CANCELLED, $entry, self::properties($entry));

        return true;
    }
```

- [ ] **Step 5: The mission source key**

In `app/Services/Secretariat/MailSources.php` replace

```php
    /** One registration per purchase order. */
    public const PURCHASE_ORDER_KEY = 'order';
```

with

```php
    /** One registration per purchase order. */
    public const PURCHASE_ORDER_KEY = 'order';

    /** One reference per mission order, taken at its first print; all its sheets share it. */
    public const MISSION_KEY = 'order';
```

- [ ] **Step 6: Activity codes**

In `app/Services/Activity/ActivityAction.php` replace

```php
    const MAIL_ENTRY_REGISTERED = 'mail_entry_registered';
```

with

```php
    const MAIL_ENTRY_REGISTERED = 'mail_entry_registered';

    const MISSION_ORDER_CREATED = 'mission_order_created';

    const MISSION_ORDER_UPDATED = 'mission_order_updated';

    const MISSION_ORDER_CANCELLED = 'mission_order_cancelled';

    const MISSION_ORDER_PRINTED = 'mission_order_printed';
```

In `ALL` (8-space indent) replace

```php
        self::MAIL_ENTRY_REGISTERED,
        self::DOCUMENT_UPDATED,
```

with

```php
        self::MAIL_ENTRY_REGISTERED,
        self::MISSION_ORDER_CREATED,
        self::MISSION_ORDER_UPDATED,
        self::MISSION_ORDER_CANCELLED,
        self::MISSION_ORDER_PRINTED,
        self::DOCUMENT_UPDATED,
```

In `AREAS['board']` (12-space indent) replace

```php
            self::MAIL_ENTRY_REGISTERED,
        ],
        'social' => [
```

with

```php
            self::MAIL_ENTRY_REGISTERED,
            // The secretariat's mission orders.
            self::MISSION_ORDER_CREATED,
            self::MISSION_ORDER_UPDATED,
            self::MISSION_ORDER_CANCELLED,
            self::MISSION_ORDER_PRINTED,
        ],
        'social' => [
```

In `tests/Unit/ActivityRecorderTest.php` replace

```php
        $this->assertCount(136, ActivityAction::ALL);
```

with

```php
        $this->assertCount(140, ActivityAction::ALL);
```

- [ ] **Step 7: Subject link (activity pages)**

In `app/Services/Activity/ActivitySubjectLink.php` replace

```php
use App\Models\MemberJob;
use App\Models\PaymentOrder;
```

with

```php
use App\Models\MemberJob;
use App\Models\MissionOrder;
use App\Models\PaymentOrder;
```

replace

```php
        MailEntry::class => ['mail'],
```

with

```php
        MailEntry::class => ['mail'],
        MissionOrder::class => ['missions'],
```

and replace

```php
            $subject instanceof MailEntry => [
                UiLang::get('mreg.title').' '.$subject->reference,
                route('mail.show', $subject),
            ],
```

with

```php
            $subject instanceof MailEntry => [
                UiLang::get('mreg.title').' '.$subject->reference,
                route('mail.show', $subject),
            ],
            // The route comes with the controller (Task 5); a link is only built when a log is displayed.
            $subject instanceof MissionOrder => [
                UiLang::get('mis.title').' · '.$subject->destination,
                route('missions.show', $subject),
            ],
```

- [ ] **Step 8: The service**

Create `app/Services/Secretariat/MissionOrders.php`:

```php
<?php

namespace App\Services\Secretariat;

use App\Exceptions\MissionOrderException;
use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\MissionOrderTraveler;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Support\UiLang;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every write to a mission order, one DB transaction each, each guarded by an
 * atomic conditional claim (lock_version bumped): create, update (fields and
 * travelers, also after printing), cancel (the order and its mail entry
 * together) and register() — the first print taking the order's outgoing
 * mail-register reference. Orders have no number of their own and are never
 * deleted.
 */
final class MissionOrders
{
    /** How many travelers the mail entry's correspondent names before "+N". */
    private const NAMED = 3;

    public function __construct(private readonly MailRegister $mail) {}

    /** @param array<string, mixed> $data validated fields (MissionOrder::FIELDS) and `travelers` */
    public function create(array $data, User $by): MissionOrder
    {
        return DB::transaction(function () use ($data, $by) {
            $order = MissionOrder::create([
                ...Arr::only($data, MissionOrder::FIELDS),
                'status' => MissionOrder::ACTIVE,
                'created_by' => $by->id,
            ]);
            $count = $this->writeTravelers($order, $data['travelers'] ?? []);
            ActivityRecorder::record($by, ActivityAction::MISSION_ORDER_CREATED, $order, ['travelers' => $count]);

            return $order->fresh('travelers');
        });
    }

    /**
     * Fields and travelers, also after printing — the reference stays and
     * reprints show it. Cancelled and imported orders are refused.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(MissionOrder $order, array $data, User $by): MissionOrder
    {
        return DB::transaction(function () use ($order, $data, $by) {
            $claimed = MissionOrder::whereKey($order->id)
                ->where('status', MissionOrder::ACTIVE)->where('is_legacy', false)
                ->update([
                    ...Arr::only($data, MissionOrder::FIELDS),
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_at' => now(),
                ]);
            if ($claimed === 0) {
                throw new MissionOrderException('flash.mission_locked');
            }
            $count = $this->writeTravelers($order, $data['travelers'] ?? []);
            ActivityRecorder::record($by, ActivityAction::MISSION_ORDER_UPDATED, $order, [
                'reference' => $this->reference($order),
                'travelers' => $count,
            ]);

            return $order->fresh('travelers');
        });
    }

    /**
     * Active orders typed here. The order and its active mail entry are
     * cancelled in this one transaction — MailRegister::cancelSource() nests
     * as a savepoint (no number, no file) — so both are cancelled or neither.
     * The entry keeps its number; the order keeps showing that reference.
     */
    public function cancel(MissionOrder $order, User $by): MissionOrder
    {
        return DB::transaction(function () use ($order, $by) {
            $claimed = MissionOrder::whereKey($order->id)
                ->where('status', MissionOrder::ACTIVE)->where('is_legacy', false)
                ->update([
                    'status' => MissionOrder::CANCELLED,
                    'cancelled_by' => $by->id,
                    'cancelled_at' => now(),
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_at' => now(),
                ]);
            if ($claimed === 0) {
                throw new MissionOrderException('flash.mission_cannot_cancel');
            }
            $entry = $this->mail->cancelSource(MailEntry::SOURCE_MISSION, $order->id, MailSources::MISSION_KEY, $by);
            ActivityRecorder::record($by, ActivityAction::MISSION_ORDER_CANCELLED, $order, ['reference' => $entry?->reference]);

            return $order->fresh('travelers');
        });
    }

    /**
     * The Print buttons: the order's active mail entry, or a new outgoing one
     * (today, next number) named after its travelers and destination. Reprints
     * get the same entry. Cancelled and imported orders print without one, so
     * they are refused here.
     */
    public function register(MissionOrder $order, User $by): MailEntry
    {
        if (! $order->isEditable()) {
            throw new MissionOrderException('flash.mission_cannot_register');
        }
        $order->loadMissing('travelers');
        // MailRegister owns this transaction (it retries a unique-index race): never wrapped in another one.
        $entry = $this->mail->register(MailEntry::SOURCE_MISSION, $order->id, MailSources::MISSION_KEY, self::defaults($order), $by);

        // Cancelled in another tab between the check and the new number: that number must not stay active.
        if ($entry->wasRecentlyCreated && ! MissionOrder::whereKey($order->id)->where('status', MissionOrder::ACTIVE)->exists()) {
            $this->mail->cancelSource(MailEntry::SOURCE_MISSION, $order->id, MailSources::MISSION_KEY, $by);

            throw new MissionOrderException('flash.mission_cannot_register');
        }

        return $entry;
    }

    public function reference(MissionOrder $order): ?string
    {
        return $this->references([$order])[$order->id] ?? null;
    }

    /**
     * Each order's reference: an imported order its old text; an active order
     * its active mail entry (none before the first print); a cancelled order
     * the entry cancelled with it (cancel keeps the reference). One query for
     * the whole page. Lookup only — never takes a number.
     *
     * @param  iterable<MissionOrder>  $orders
     * @return array<int, ?string> order id → reference
     */
    public function references(iterable $orders): array
    {
        $orders = collect($orders);
        $ids = $orders->reject(fn (MissionOrder $order) => $order->is_legacy)->pluck('id');
        $entries = $ids->isEmpty() ? collect() : MailEntry::query()
            ->where('source_type', MailEntry::SOURCE_MISSION)->where('source_key', MailSources::MISSION_KEY)
            ->whereIn('source_id', $ids)->orderBy('id')
            ->get(['id', 'direction', 'year', 'number', 'status', 'is_legacy', 'legacy_reference', 'source_id'])
            ->groupBy('source_id');

        return $orders->mapWithKeys(function (MissionOrder $order) use ($entries) {
            if ($order->is_legacy) {
                return [$order->id => $order->legacy_reference];
            }
            $mine = $entries->get($order->id, collect());
            $entry = $order->status === MissionOrder::ACTIVE
                ? $mine->firstWhere('status', MailEntry::ACTIVE)
                : $mine->last();

            return [$order->id => $entry?->reference];
        })->all();
    }

    /**
     * A new mail entry's correspondent (the first three travelers, then "+N")
     * and subject («أمر بمهمة — destination», in the app language).
     *
     * @return array{correspondent: string, address: null, subject: string}
     */
    public static function defaults(MissionOrder $order): array
    {
        $names = $order->travelers->pluck('name')->filter()->values();
        $named = $names->take(self::NAMED)->implode(app()->getLocale() === 'ar' ? '، ' : ', ');
        $rest = $names->count() - self::NAMED;

        return [
            'correspondent' => $rest > 0 ? $named.' +'.$rest : $named,
            'address' => null,
            'subject' => UiLang::get('mis.title').' — '.$order->destination,
        ];
    }

    /**
     * Replaces the order's travelers with $rows in their order. A person
     * (same type + id) is kept once; typed names (no type) always stay; an id
     * without a type, or a type without an id, is a typed name. At least one.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeTravelers(MissionOrder $order, array $rows): int
    {
        MissionOrderTraveler::where('mission_order_id', $order->id)->delete();
        $seen = [];
        $sort = 0;
        foreach ($rows as $row) {
            $name = is_array($row) ? self::text($row['name'] ?? null, 200) : null;
            if ($name === null) {
                continue;
            }
            $type = in_array($row['traveler_type'] ?? null, MissionOrderTraveler::TYPES, true) ? $row['traveler_type'] : null;
            $id = $type !== null && is_numeric($row['traveler_id'] ?? null) ? (int) $row['traveler_id'] : null;
            if ($id === null) {
                $type = null;
            } elseif (isset($seen[$type.'|'.$id])) {
                continue;
            } else {
                $seen[$type.'|'.$id] = true;
            }
            MissionOrderTraveler::create([
                'mission_order_id' => $order->id,
                'traveler_type' => $type,
                'traveler_id' => $id,
                'name' => $name,
                'function' => self::text($row['function'] ?? null, 200),
                'sort_order' => ++$sort,
            ]);
        }
        if ($sort === 0) {
            throw new MissionOrderException('flash.mission_no_traveler');
        }

        return $sort;
    }

    private static function text(mixed $value, int $limit): ?string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
```

- [ ] **Step 9: Translations (title, refusals, activity labels)**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p4-t3-keys.json`:

```json
{
    "mis.title": { "ar": "أمر بمهمة", "fr": "Ordre de mission", "en": "Mission order" },
    "flash.mission_locked": { "ar": "لا يمكن تعديل أمر المهمة هذا.", "fr": "Cet ordre de mission ne peut plus être modifié.", "en": "This mission order can no longer be changed." },
    "flash.mission_cannot_cancel": { "ar": "لا يمكن إلغاء أمر المهمة هذا.", "fr": "Cet ordre de mission ne peut pas être annulé.", "en": "This mission order cannot be cancelled." },
    "flash.mission_cannot_register": { "ar": "أمر ملغى أو مستورد: يُطبع دون أخذ مرجع.", "fr": "Ordre annulé ou importé : il s'imprime sans prendre de référence.", "en": "Cancelled or imported order: it prints without taking a reference." },
    "flash.mission_no_traveler": { "ar": "أضف مسافرًا واحدًا على الأقل.", "fr": "Ajoutez au moins un voyageur.", "en": "Add at least one traveler." },
    "activity.action.mission_order_created": { "ar": "إنشاء أمر بمهمة", "fr": "Ordre de mission créé", "en": "Mission order created" },
    "activity.action.mission_order_updated": { "ar": "تعديل أمر بمهمة", "fr": "Ordre de mission modifié", "en": "Mission order edited" },
    "activity.action.mission_order_cancelled": { "ar": "إلغاء أمر بمهمة", "fr": "Ordre de mission annulé", "en": "Mission order cancelled" },
    "activity.action.mission_order_printed": { "ar": "طباعة أمر بمهمة", "fr": "Ordre de mission imprimé", "en": "Mission order printed" }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p4-t3-keys.json`
Expected: `✓ 9 key(s) written to ar/en/fr`

- [ ] **Step 10: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MissionOrdersTest.php`
Expected: PASS (15 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailRegisterTest.php`
Expected: PASS (the cancel refactor keeps P3's behaviour).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Unit/ActivityRecorderTest.php`
Expected: PASS (140 codes, each in exactly one area).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/ActivityReportTest.php`
Expected: PASS (no new area).
Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add app/Exceptions/MissionOrderException.php app/Services/Secretariat/MissionOrders.php app/Services/Secretariat/MailRegister.php app/Services/Secretariat/MailSources.php app/Services/Activity/ActivityAction.php app/Services/Activity/ActivitySubjectLink.php tests/Unit/ActivityRecorderTest.php tests/Feature/MissionOrdersTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(missions): MissionOrders service — create, edit, cancel with its mail entry, reference at print

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 4: `MissionTravelers` — form pickers with default functions, "Add a team" rows

**Files:**
- Create: `app/Services/Secretariat/MissionTravelers.php`
- Modify: `resources/js/i18n/*.json` (via script)
- Test: `tests/Feature/MissionTravelersTest.php`

**Interfaces:**
- Consumes: `MissionOrderTraveler::USER` / `PLAYER` (Task 2); `User::fullname`; `BoardMember::withPlayer()`, `inCurrentTerm()`, `name`; `BoardRole` (`name`, `label`); `Player::NAME_COLUMNS`, `fullname`, `notLeft()`, `category()`; `Team::coachesFor(int)`, `rosterFor(int)`, `labelWith(CategoryLabels)`; `App\Services\Competitions\TeamMembers::of(Team)`, `RELATIONS`, `hasLeft(Player)`, `category(Player)`; `App\Support\Season::current()->startYear`; `CategoryLabels::make()`.
- Produces (container-resolved, `final`):
  - `options(User $viewer): array{users: list<array{value: int, label: string, function: string}>, boardMembers: list<array{value: int, label: string, description: string, function: string}>, players: ?list<array{value: int, label: string, description: string, function: string}>, teams: ?list<array{value: int, label: string}>}` — `players` null without `players` → `view`; `teams` null without `teams` → `view` and `players` → `view`. These are the Inertia props of `Missions/Create` and `Missions/Edit` (Task 5).
  - `team(Team $team): list<array{traveler_type: string, traveler_id: int, name: string, function: string}>` — coaches of the current season, then players (A–Z) of that season who have not left the team's sport; each person once.
  - `static coachFunction(): string`, `static playerFunction(?string $category): string`.
  - Label keys `mis.function.coach`, `mis.function.player` (with `{category}`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MissionTravelersTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\BoardTerm;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\Secretariat\MissionTravelers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MissionTravelersTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-26 10:00:00');
        app()->setLocale('fr');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function travelers(): MissionTravelers
    {
        return app(MissionTravelers::class);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
        ]);
    }

    #[Test]
    public function each_picker_carries_the_persons_default_function(): void
    {
        $coach = User::factory()->create(['firstname' => 'Karim', 'lastname' => 'Coach']);
        User::factory()->create(['firstname' => 'Ancien', 'lastname' => 'Compte', 'is_active' => false]);
        BoardRole::create(['name' => 'media_test', 'label' => 'Chargé de communication test', 'level' => 'bureau']);
        $president = BoardMember::create(['board_term_id' => BoardTerm::current()?->id, 'name' => 'Président Test', 'role' => 'president', 'status' => 'active']);
        $media = BoardMember::create(['board_term_id' => BoardTerm::current()?->id, 'name' => 'Média Test', 'role' => 'media_test', 'status' => 'active']);
        BoardMember::create(['board_term_id' => BoardTerm::current()?->id, 'name' => 'Ancien Membre', 'role' => 'treasurer', 'status' => 'former']);
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        $archived = $this->player($u15, ['archived' => true]);
        $gone = $this->player($u15, ['status_id' => Player::leftStatusId()]);

        $options = $this->travelers()->options(User::factory()->admin()->create());

        $users = collect($options['users']);
        $this->assertSame(['value' => $coach->id, 'label' => 'Karim Coach', 'function' => 'Entraîneur'], $users->firstWhere('value', $coach->id));
        $this->assertNull($users->firstWhere('label', 'Ancien Compte'));

        $members = collect($options['boardMembers']);
        // The built-in role name is translated; a role with a typed label shows that label.
        $this->assertSame('Président', $members->firstWhere('value', $president->id)['function']);
        $this->assertSame('Chargé de communication test', $members->firstWhere('value', $media->id)['function']);
        $this->assertSame('Chargé de communication test', $members->firstWhere('value', $media->id)['description']);
        $this->assertNull($members->firstWhere('label', 'Ancien Membre'));

        $players = collect($options['players']);
        $row = $players->firstWhere('value', $player->id);
        $this->assertSame($player->fullname, $row['label']);
        $this->assertSame('Joueur — U15', $row['function']);
        $this->assertStringContainsString($player->membership_id, $row['description']);
        $this->assertNull($players->firstWhere('value', $archived->id));
        $this->assertNull($players->firstWhere('value', $gone->id));
    }

    #[Test]
    public function players_and_teams_are_offered_only_to_who_may_view_them(): void
    {
        // A category name no seeded row repeats, so the label carries no sport suffix.
        $this->team($this->category('Cadets Test'), ['name' => 'Cadets A']);

        $plain = $this->travelers()->options($this->userWith(['missions' => ['view', 'add']]));
        $this->assertNull($plain['players']);
        $this->assertNull($plain['teams']);

        $players = $this->travelers()->options($this->userWith(['missions' => ['view', 'add'], 'players' => ['view']]));
        $this->assertIsArray($players['players']);
        $this->assertNull($players['teams']);

        $both = $this->travelers()->options($this->userWith(['missions' => ['view', 'add'], 'players' => ['view'], 'teams' => ['view']]));
        $this->assertSame('Cadets A · Cadets Test', $both['teams'][0]['label']);
    }

    #[Test]
    public function adding_a_team_brings_its_coaches_then_its_players_of_this_season(): void
    {
        $u15 = $this->category('U15');
        $team = $this->team($u15, ['name' => 'Cadets A']);
        $first = $this->player($u15);
        $second = $this->player($u15);
        $gone = $this->player($u15);
        $lastSeason = $this->player($u15);
        $this->onRoster($team, 2026, [$second, $first, $gone]);
        $this->onRoster($team, 2025, [$lastSeason]);
        $gone->update(['status_id' => Player::leftStatusId()]);
        $coach = User::factory()->create(['firstname' => 'Karim', 'lastname' => 'Coach']);
        $this->assignCoach($team, $coach, 2026);
        $this->assignCoach($team, User::factory()->create(), 2025);

        $rows = $this->travelers()->team($team);

        $this->assertSame([
            ['traveler_type' => 'user', 'traveler_id' => $coach->id, 'name' => 'Karim Coach', 'function' => 'Entraîneur'],
            ['traveler_type' => 'player', 'traveler_id' => $first->id, 'name' => $first->fullname, 'function' => 'Joueur — U15'],
            ['traveler_type' => 'player', 'traveler_id' => $second->id, 'name' => $second->fullname, 'function' => 'Joueur — U15'],
        ], $rows);
    }

    #[Test]
    public function a_player_without_a_category_is_simply_a_player(): void
    {
        $this->assertSame('Joueur', MissionTravelers::playerFunction(null));
        $this->assertSame('Joueur — U17', MissionTravelers::playerFunction('U17'));
        app()->setLocale('ar');
        $this->assertSame('مدرب', MissionTravelers::coachFunction());
        $this->assertSame('لاعب — U17', MissionTravelers::playerFunction('U17'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MissionTravelersTest.php`
Expected: FAIL (`Class "App\Services\Secretariat\MissionTravelers" not found`).

- [ ] **Step 3: The service**

Create `app/Services/Secretariat/MissionTravelers.php`:

```php
<?php

namespace App\Services\Secretariat;

use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\MissionOrderTraveler;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Services\Competitions\TeamMembers;
use App\Support\CategoryLabels;
use App\Support\Season;
use App\Support\UiLang;

/**
 * Who can travel on a mission order, as the form offers them: users (coach by
 * default), current board members (their board role), players (player — their
 * category) and, through "Add a team", a team's coaches and players of the
 * current season. Names and functions are defaults only: the order keeps its
 * own editable copy.
 */
final class MissionTravelers
{
    /**
     * The form's pickers. Players need players → view; teams need teams →
     * view and players → view (a team brings its players). Null hides a picker.
     *
     * @return array{users: list<array<string, mixed>>, boardMembers: list<array<string, mixed>>, players: ?list<array<string, mixed>>, teams: ?list<array<string, mixed>>}
     */
    public function options(User $viewer): array
    {
        $seesPlayers = $viewer->hasPermission('players', 'view');

        return [
            'users' => User::query()->where('is_active', true)
                ->orderBy('lastname')->orderBy('firstname')->orderBy('name')
                ->get(['id', 'name', 'firstname', 'lastname'])
                ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->fullname, 'function' => self::coachFunction()])
                ->values()->all(),
            'boardMembers' => $this->boardMembers(),
            'players' => $seesPlayers ? $this->players() : null,
            'teams' => $seesPlayers && $viewer->hasPermission('teams', 'view') ? $this->teams() : null,
        ];
    }

    /**
     * "Add a team": its coaches, then its players (A–Z) of the current season,
     * as rows the form lists ticked. Players who left the team's sport stay on
     * the roster, marked, but do not travel: they are left out. Each person once.
     *
     * @return list<array{traveler_type: string, traveler_id: int, name: string, function: string}>
     */
    public function team(Team $team): array
    {
        $season = Season::current()->startYear;
        $members = TeamMembers::of($team);

        $coaches = $team->coachesFor($season)->orderBy('users.lastname')->orderBy('users.firstname')->get()
            ->map(fn (User $user) => self::row(MissionOrderTraveler::USER, $user->id, $user->fullname, self::coachFunction()));
        $players = $team->rosterFor($season)->with(TeamMembers::RELATIONS)->get()
            ->reject(fn (Player $player) => $members->hasLeft($player))
            ->map(fn (Player $player) => self::row(
                MissionOrderTraveler::PLAYER, $player->id, $player->fullname,
                self::playerFunction($members->category($player)?->localized_name),
            ))
            ->sortBy('name');

        return $coaches->concat($players)
            ->unique(fn (array $row) => $row['traveler_type'].'|'.$row['traveler_id'])
            ->values()->all();
    }

    public static function coachFunction(): string
    {
        return UiLang::get('mis.function.coach');
    }

    /** «لاعب — U15», or «لاعب» alone when the player has no category. */
    public static function playerFunction(?string $category): string
    {
        return $category === null || $category === ''
            ? UiLang::get('player')
            : strtr(UiLang::get('mis.function.player'), ['{category}' => $category]);
    }

    /** @return list<array<string, mixed>> active members of the current term, their role as the function */
    private function boardMembers(): array
    {
        // A role's typed label first (custom roles), else its built-in translation — never the raw name.
        $labels = BoardRole::query()->whereNotNull('label')->where('label', '!=', '')->pluck('label', 'name');

        return BoardMember::query()->withPlayer()->inCurrentTerm()->where('status', 'active')
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(function (BoardMember $member) use ($labels) {
                $role = (string) $member->role;
                $function = $role === '' ? '' : ($labels[$role] ?? UiLang::get($role, $role));

                return ['value' => $member->id, 'label' => $member->name, 'description' => $function, 'function' => $function];
            })
            ->values()->all();
    }

    /** @return list<array<string, mixed>> players still in the club (not archived, not left), A–Z */
    private function players(): array
    {
        return Player::query()->where('archived', false)->notLeft()
            ->with('category')
            ->orderBy('lastname')->orderBy('firstname')
            ->get(['id', 'category_id', 'membership_id', ...Player::NAME_COLUMNS])
            ->map(function (Player $player) {
                $category = $player->category?->localized_name;

                return [
                    'value' => $player->id,
                    'label' => $player->fullname,
                    'description' => implode(' · ', array_filter([$player->membership_id, $category])),
                    'function' => self::playerFunction($category),
                ];
            })
            ->values()->all();
    }

    /** @return list<array{value: int, label: string}> */
    private function teams(): array
    {
        $labels = CategoryLabels::make();

        return Team::query()->where('archived', false)->orderBy('name')->get(['id', 'name', 'category_id'])
            ->map(fn (Team $team) => ['value' => $team->id, 'label' => $team->labelWith($labels)])
            ->values()->all();
    }

    /** @return array{traveler_type: string, traveler_id: int, name: string, function: string} */
    private static function row(string $type, int $id, string $name, string $function): array
    {
        return ['traveler_type' => $type, 'traveler_id' => $id, 'name' => $name, 'function' => $function];
    }
}
```

- [ ] **Step 4: Function labels**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p4-t4-keys.json`:

```json
{
    "mis.function.coach": { "ar": "مدرب", "fr": "Entraîneur", "en": "Coach" },
    "mis.function.player": { "ar": "لاعب — {category}", "fr": "Joueur — {category}", "en": "Player — {category}" }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p4-t4-keys.json`
Expected: `✓ 2 key(s) written to ar/en/fr`

(`player` — «لاعب» / «Joueur» / «Player» — and the board role names `president`, `secretary`… already exist.)

- [ ] **Step 5: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MissionTravelersTest.php`
Expected: PASS (4 tests).
Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Secretariat/MissionTravelers.php tests/Feature/MissionTravelersTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(missions): traveler pickers with default functions and the add-a-team rows

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 5: Controller, form request, routes, register + team JSON, mission source in the mail register

**Files:**
- Create: `app/Http/Requests/Mission/SaveMissionOrderRequest.php`
- Create: `app/Http/Controllers/MissionOrderController.php`
- Modify: `routes/web.php` (imports after `MembershipIdController`, line 65; routes after `mail.print`, line 328)
- Modify: `app/Services/Secretariat/MailSources.php` (import, `LINK_MODULES`, `url()`, `describe()`, new `missionLabel()`)
- Modify: `resources/js/i18n/*.json` (via script)
- Test: `tests/Feature/MissionOrderHttpTest.php`

**Interfaces:**
- Consumes: `MissionOrders` (Task 3), `MissionTravelers` (Task 4), `MissionOrder::FIELDS`, `isEditable()`, `MissionOrderTraveler::TYPES` (Task 2), `MailEntry::SOURCE_MISSION`, `MailSources::MISSION_KEY`.
- Produces routes: `missions.index` GET `/missions` (query `year`, `status` = `active`|`cancelled`, `q`), `missions.create` GET `/missions/create`, `missions.store` POST `/missions`, `missions.show` GET `/missions/{missionOrder}`, `missions.edit` GET `/missions/{missionOrder}/edit`, `missions.update` PUT `/missions/{missionOrder}`, `missions.cancel` POST `/missions/{missionOrder}/cancel`, `missions.register` POST `/missions/{missionOrder}/register` → JSON `{reference: string, created: bool}` (422 `{message: flashKey}` when refused), `missions.team-members` GET `/missions/team-members/{team}` → JSON `{travelers: list<{traveler_type, traveler_id, name, function}>}`. No destroy.
- Inertia props (Task 7 builds the pages on them):
  - `Missions/Index`: `orders` (paginator of `{id, reference, order_date, destination, travelers (first three names joined), travelers_count, departure_date, return_date, status, is_legacy}`), `filters` (`{year?, status?, q?}`), `years` (list of int, newest first), `can` `{create, register}`.
  - `Missions/Create`: `users`, `boardMembers`, `players` (or null), `teams` (or null) — `MissionTravelers::options()`.
  - `Missions/Edit`: `order` (model array with `travelers`), plus the four option lists.
  - `Missions/Show`: `order` (model array with `travelers`: `{id, traveler_type, traveler_id, name, function, sort_order}`), `reference` (string or null), `can` `{edit, cancel, register}` (each false unless the order is active and not imported).
- Flash keys `flash.mission_created`, `_updated`, `_cancelled`; label key `mreg.source.mission_order` (the mail register's source column and page).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MissionOrderHttpTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivitySubjectLink;
use App\Services\Secretariat\MissionOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MissionOrderHttpTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-26 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'], 'preferred_lng' => 'fr',
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
        ]);
    }

    private function typed(string $name, ?string $function = null): array
    {
        return ['traveler_type' => null, 'traveler_id' => null, 'name' => $name, 'function' => $function];
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'order_date' => '2026-11-26', 'destination' => 'Testville', 'reason' => 'Stage de formation',
            'departure_date' => '2026-12-01', 'return_date' => '2026-12-03', 'transport' => 'Bus du club',
            'travelers' => [$this->typed('Entraîneur Test', 'Entraîneur')],
        ], $extra);
    }

    private function order(array $extra = []): MissionOrder
    {
        return app(MissionOrders::class)->create($this->payload($extra), User::factory()->admin()->create());
    }

    private function legacy(): MissionOrder
    {
        $order = MissionOrder::create([
            'order_date' => '2018-05-12', 'destination' => 'Vieilleville', 'status' => MissionOrder::ACTIVE,
            'is_legacy' => true, 'legacy_reference' => '59', 'legacy_source' => MissionOrder::LEGACY_SOURCE,
        ]);
        $order->travelers()->create(['name' => 'Ancien Test', 'function' => 'Entraîneur', 'sort_order' => 1]);

        return $order;
    }

    #[Test]
    public function an_editor_writes_an_order_with_a_coach_and_a_typed_traveler(): void
    {
        $coach = User::factory()->create(['firstname' => 'Karim', 'lastname' => 'Coach']);
        $user = $this->userWith(['missions' => ['view', 'add']]);

        $response = $this->actingAs($user)->post(route('missions.store'), $this->payload(['travelers' => [
            ['traveler_type' => 'user', 'traveler_id' => $coach->id, 'name' => 'Karim Coach', 'function' => 'Entraîneur'],
            $this->typed('Chauffeur Test', 'Chauffeur'),
        ]]));

        $order = MissionOrder::sole();
        $response->assertRedirect(route('missions.show', $order))->assertSessionHas('success', 'flash.mission_created');
        $this->assertSame('Testville', $order->destination);
        $this->assertSame(['Karim Coach', 'Chauffeur Test'], $order->travelers()->pluck('name')->all());
        $this->assertSame($user->id, $order->created_by);
        // Writing an order takes no reference: only a print does.
        $this->assertSame(0, MailEntry::count());
    }

    #[Test]
    public function the_form_is_validated(): void
    {
        $user = $this->userWith(['missions' => ['view', 'add']]);

        $this->actingAs($user)->post(route('missions.store'), $this->payload([
            'destination' => '', 'departure_date' => '2026-12-05', 'return_date' => '2026-12-01', 'travelers' => [],
        ]))->assertSessionHasErrors(['destination', 'return_date', 'travelers']);

        $this->post(route('missions.store'), $this->payload(['travelers' => [
            ['traveler_type' => 'robot', 'traveler_id' => 1, 'name' => 'X', 'function' => null],
            $this->typed(''),
        ]]))->assertSessionHasErrors(['travelers.0.traveler_type', 'travelers.1.name']);

        // The existence check runs once the rows are well formed.
        $this->post(route('missions.store'), $this->payload(['travelers' => [
            $this->typed('Connu'),
            ['traveler_type' => 'player', 'traveler_id' => 99999, 'name' => 'Inconnu', 'function' => null],
        ]]))->assertSessionHasErrors(['travelers.1.traveler_id']);

        $this->assertSame(0, MissionOrder::count());
    }

    #[Test]
    public function each_action_needs_its_right(): void
    {
        $order = $this->order();

        $this->actingAs($this->userWith(['missions' => ['view']]));
        $this->get(route('missions.index'))->assertOk();
        $this->get(route('missions.show', $order))->assertOk();
        $this->get(route('missions.create'))->assertForbidden();
        $this->post(route('missions.store'), $this->payload())->assertForbidden();
        $this->get(route('missions.edit', $order))->assertForbidden();
        $this->put(route('missions.update', $order), $this->payload())->assertForbidden();
        $this->post(route('missions.cancel', $order))->assertForbidden();
        $this->postJson(route('missions.register', $order))->assertForbidden();

        $this->actingAs($this->userWith(['mail' => ['view']]))->get(route('missions.index'))->assertForbidden();
        // Orders are never deleted: there is no destroy route.
        $this->assertFalse(Route::has('missions.destroy'));
    }

    #[Test]
    public function an_update_rewrites_the_order_and_its_travelers(): void
    {
        $order = $this->order();

        $this->actingAs($this->userWith(['missions' => ['view', 'edit']]))
            ->put(route('missions.update', $order), $this->payload(['destination' => 'Autreville', 'travelers' => [$this->typed('Arbitre Test', 'Arbitre')]]))
            ->assertRedirect(route('missions.show', $order))->assertSessionHas('success', 'flash.mission_updated');

        $order->refresh();
        $this->assertSame('Autreville', $order->destination);
        $this->assertSame(['Arbitre Test'], $order->travelers()->pluck('name')->all());
    }

    #[Test]
    public function cancelled_and_imported_orders_have_no_edit_page(): void
    {
        $admin = User::factory()->admin()->create();
        $cancelled = app(MissionOrders::class)->cancel($this->order(), $admin);
        $legacy = $this->legacy();

        $this->actingAs($admin)->get(route('missions.edit', $cancelled))
            ->assertRedirect(route('missions.show', $cancelled))->assertSessionHas('error', 'flash.mission_locked');
        $this->get(route('missions.edit', $legacy))
            ->assertRedirect(route('missions.show', $legacy))->assertSessionHas('error', 'flash.mission_locked');
        $this->put(route('missions.update', $legacy), $this->payload())->assertSessionHas('error', 'flash.mission_locked');
    }

    #[Test]
    public function cancelling_needs_the_delete_right_and_cancels_the_mail_entry_too(): void
    {
        $order = $this->order();
        app(MissionOrders::class)->register($order, User::factory()->admin()->create());

        $this->actingAs($this->userWith(['missions' => ['view', 'edit']]))->post(route('missions.cancel', $order))->assertForbidden();

        $this->actingAs($this->userWith(['missions' => ['view', 'delete']]))->from(route('missions.show', $order))
            ->post(route('missions.cancel', $order))
            ->assertRedirect(route('missions.show', $order))->assertSessionHas('success', 'flash.mission_cancelled');

        $this->assertSame(MissionOrder::CANCELLED, $order->fresh()->status);
        $this->assertSame(MailEntry::CANCELLED, MailEntry::sole()->status);
        $this->assertSame('2026/01', app(MissionOrders::class)->reference($order->fresh()));
        $this->post(route('missions.cancel', $order))->assertSessionHas('error', 'flash.mission_cannot_cancel');
    }

    #[Test]
    public function the_print_buttons_take_the_reference_once_with_the_edit_right(): void
    {
        $order = $this->order(['travelers' => [$this->typed('Entraîneur Test'), $this->typed('Joueur Test')]]);

        $this->actingAs($this->userWith(['missions' => ['view', 'add']]))->postJson(route('missions.register', $order))->assertForbidden();

        $this->actingAs($this->userWith(['missions' => ['view', 'edit']]));
        $this->postJson(route('missions.register', $order))->assertOk()
            ->assertJson(['reference' => '2026/01', 'created' => true]);
        $this->postJson(route('missions.register', $order))->assertOk()
            ->assertJson(['reference' => '2026/01', 'created' => false]);

        $entry = MailEntry::sole();
        $this->assertSame('Entraîneur Test, Joueur Test', $entry->correspondent);
        $this->assertSame('Ordre de mission — Testville', $entry->subject);

        app(MissionOrders::class)->cancel($order, User::factory()->admin()->create());
        $this->postJson(route('missions.register', $order))->assertStatus(422)
            ->assertJsonPath('message', 'flash.mission_cannot_register');
        $this->postJson(route('missions.register', $this->legacy()))->assertStatus(422);
    }

    #[Test]
    public function the_list_filters_by_year_and_status_and_finds_a_place_a_traveler_or_a_reference(): void
    {
        $admin = User::factory()->admin()->create(['preferred_lng' => 'fr']);
        $missions = app(MissionOrders::class);
        $printed = $missions->create($this->payload(['destination' => 'Testville']), $admin);
        $missions->create($this->payload([
            'destination' => 'Autreville', 'order_date' => '2025-06-01',
            'travelers' => [$this->typed('Voyageur Unique'), $this->typed('B'), $this->typed('C'), $this->typed('D')],
        ]), $admin);
        $cancelled = $missions->create($this->payload(['destination' => 'Troisville']), $admin);
        $missions->cancel($cancelled, $admin);
        $missions->register($printed, $admin);                                                     // 2026/01
        $this->legacy();

        $this->actingAs($admin)->get(route('missions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 4)
                ->where('orders.data.0.destination', 'Troisville')
                ->where('orders.data.1.reference', '2026/01')
                ->where('orders.data.2.travelers', 'Voyageur Unique, B, C')
                ->where('orders.data.2.travelers_count', 4)
                ->where('orders.data.3.reference', '59')
                ->where('years', [2026, 2025, 2018])
                ->where('can.create', true)->where('can.register', true));
        $this->get(route('missions.index', ['year' => 2025]))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.destination', 'Autreville'));
        $this->get(route('missions.index', ['status' => 'cancelled']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.destination', 'Troisville'));
        $this->get(route('missions.index', ['q' => 'Autre']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1));
        $this->get(route('missions.index', ['q' => 'Voyageur Unique']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.destination', 'Autreville'));
        // "2026/1" is mail entry 1 of 2026: the order printed under it.
        $this->get(route('missions.index', ['q' => '2026/1']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.destination', 'Testville'));
        $this->get(route('missions.index', ['q' => '59']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.destination', 'Vieilleville'));
    }

    #[Test]
    public function the_order_page_shows_its_reference_and_what_the_viewer_may_do(): void
    {
        $order = $this->order();
        app(MissionOrders::class)->register($order, User::factory()->admin()->create());

        $this->actingAs(User::factory()->admin()->create())->get(route('missions.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.id', $order->id)
                ->has('order.travelers', 1)
                ->where('order.travelers.0.name', 'Entraîneur Test')
                ->where('reference', '2026/01')
                ->where('can.edit', true)->where('can.cancel', true)->where('can.register', true));

        $this->actingAs($this->userWith(['missions' => ['view']]))->get(route('missions.show', $this->legacy()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reference', '59')
                ->where('can.edit', false)->where('can.cancel', false)->where('can.register', false));
    }

    #[Test]
    public function the_form_pages_carry_the_pickers(): void
    {
        $order = $this->order();

        $this->actingAs(User::factory()->admin()->create())->get(route('missions.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('users')->has('boardMembers')->has('players')->has('teams'));
        $this->get(route('missions.edit', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('order.id', $order->id)->has('order.travelers', 1)->has('users'));

        $this->actingAs($this->userWith(['missions' => ['view', 'add']]))->get(route('missions.create'))
            ->assertInertia(fn (Assert $page) => $page->where('players', null)->where('teams', null));
    }

    #[Test]
    public function adding_a_team_needs_the_team_and_its_players(): void
    {
        $u15 = $this->category('U15');
        $team = $this->team($u15);
        $player = $this->player($u15);
        $this->onRoster($team, 2026, [$player]);
        $coach = User::factory()->create(['firstname' => 'Karim', 'lastname' => 'Coach']);
        $this->assignCoach($team, $coach, 2026);

        $this->actingAs($this->userWith(['missions' => ['view', 'add']]))->getJson(route('missions.team-members', $team))->assertForbidden();
        $this->actingAs($this->userWith(['missions' => ['view'], 'teams' => ['view'], 'players' => ['view']]))
            ->getJson(route('missions.team-members', $team))->assertForbidden();

        $this->actingAs($this->userWith(['missions' => ['view', 'edit'], 'teams' => ['view'], 'players' => ['view']]))
            ->getJson(route('missions.team-members', $team))
            ->assertOk()
            ->assertJsonCount(2, 'travelers')
            ->assertJsonPath('travelers.0.traveler_id', $coach->id)
            ->assertJsonPath('travelers.1.traveler_type', 'player')
            ->assertJsonPath('travelers.1.traveler_id', $player->id);
    }

    #[Test]
    public function the_mail_register_names_and_links_a_mission_order_for_who_may_view_it(): void
    {
        $order = $this->order();
        app(MissionOrders::class)->register($order, User::factory()->admin()->create());
        $entry = MailEntry::sole();

        $this->actingAs(User::factory()->admin()->create())->get(route('mail.show', $entry))
            ->assertInertia(fn (Assert $page) => $page
                ->where('source.type', 'mission_order')
                ->where('source.label', 'Testville · 26/11/2026')
                ->where('source.url', route('missions.show', $order)));
        $this->get(route('mail.index'))
            ->assertInertia(fn (Assert $page) => $page->where('entries.data.0.source_url', route('missions.show', $order)));

        $this->actingAs($this->userWith(['mail' => ['view']]))->get(route('mail.show', $entry))
            ->assertInertia(fn (Assert $page) => $page->where('source.label', '#'.$order->id)->where('source.url', null));
    }

    #[Test]
    public function the_activity_pages_link_an_order_for_mission_viewers_only(): void
    {
        $order = $this->order();
        $log = ActivityLog::where('action', ActivityAction::MISSION_ORDER_CREATED)->sole();

        $this->assertSame(route('missions.show', $order), ActivitySubjectLink::for($log, User::factory()->admin()->create())['url']);

        $neutral = ActivitySubjectLink::for($log, $this->userWith(['players' => ['view']]));
        $this->assertNull($neutral['url']);
        $this->assertTrue($neutral['neutral']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MissionOrderHttpTest.php`
Expected: FAIL (`Route [missions.store] not defined`).

- [ ] **Step 3: Form request**

Create `app/Http/Requests/Mission/SaveMissionOrderRequest.php`:

```php
<?php

namespace App\Http\Requests\Mission;

use App\Models\BoardMember;
use App\Models\MissionOrderTraveler;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A mission order and its travelers (create, or edit an active order — the
 * service refuses the others). A traveler is a typed name (no type) or a
 * user / board member / player that exists; name and function are the
 * sheet's own copy.
 */
class SaveMissionOrderRequest extends FormRequest
{
    /** Traveler type → the model its id must exist in. */
    private const MODELS = [
        MissionOrderTraveler::USER => User::class,
        MissionOrderTraveler::BOARD_MEMBER => BoardMember::class,
        MissionOrderTraveler::PLAYER => Player::class,
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_date' => ['required', 'date'],
            'destination' => ['required', 'string', 'max:200'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'departure_date' => ['required', 'date'],
            'return_date' => ['nullable', 'date', 'after_or_equal:departure_date'],
            'transport' => ['nullable', 'string', 'max:100'],
            'travelers' => ['required', 'array', 'min:1', 'max:100'],
            'travelers.*.traveler_type' => ['nullable', Rule::in(MissionOrderTraveler::TYPES)],
            'travelers.*.traveler_id' => ['nullable', 'required_with:travelers.*.traveler_type', 'integer'],
            'travelers.*.name' => ['required', 'string', 'max:200'],
            'travelers.*.function' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** Every picked person still exists (one query per type). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $rows = collect($this->input('travelers', []));
            foreach (self::MODELS as $type => $model) {
                $mine = $rows->filter(fn ($row) => is_array($row) && ($row['traveler_type'] ?? null) === $type);
                if ($mine->isEmpty()) {
                    continue;
                }
                $found = $model::query()->whereIn('id', $mine->pluck('traveler_id')->map(fn ($id) => (int) $id)->unique()->values())
                    ->pluck('id')->map(fn ($id) => (int) $id)->all();
                foreach ($mine as $index => $row) {
                    if (! in_array((int) $row['traveler_id'], $found, true)) {
                        $validator->errors()->add("travelers.{$index}.traveler_id", __('validation.exists', ['attribute' => '#'.($index + 1)]));
                    }
                }
            }
        });
    }
}
```

- [ ] **Step 4: Source link and label in the mail register**

In `app/Services/Secretariat/MailSources.php` replace

```php
use App\Models\MailEntry;
use App\Models\Player;
```

with

```php
use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\Player;
```

replace

```php
        MailEntry::SOURCE_PURCHASE_ORDER => 'purchase_orders',
    ];

    /** The print dialog's URL segment → stored source type. */
```

with

```php
        MailEntry::SOURCE_PURCHASE_ORDER => 'purchase_orders',
        MailEntry::SOURCE_MISSION => 'missions',
    ];

    /** The print dialog's URL segment → stored source type. */
```

(`URL_TYPES` and `PRINT_MODULES` stay without the mission type: a mission order takes its reference through `missions.register`, never through the tick box.)

replace

```php
            MailEntry::SOURCE_PURCHASE_ORDER => route('purchase-orders.show', $id),
        };
```

with

```php
            MailEntry::SOURCE_PURCHASE_ORDER => route('purchase-orders.show', $id),
            MailEntry::SOURCE_MISSION => route('missions.show', $id),
        };
```

replace

```php
            MailEntry::SOURCE_PURCHASE_ORDER => PurchaseOrder::find($id)?->reference,
```

with

```php
            MailEntry::SOURCE_PURCHASE_ORDER => PurchaseOrder::find($id)?->reference,
            MailEntry::SOURCE_MISSION => self::missionLabel($id),
```

and replace

```php
    /** 'Y-m-d' → 'dd/mm/yyyy', as the prints show dates. */
```

with

```php
    /** A mission order: its destination and order date. */
    private static function missionLabel(int $orderId): ?string
    {
        $order = MissionOrder::find($orderId, ['id', 'destination', 'order_date']);
        if ($order === null) {
            return null;
        }

        return ($order->destination ?? '—').($order->order_date ? ' · '.$order->order_date->format('d/m/Y') : '');
    }

    /** 'Y-m-d' → 'dd/mm/yyyy', as the prints show dates. */
```

- [ ] **Step 5: Controller**

Create `app/Http/Controllers/MissionOrderController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Mission\SaveMissionOrderRequest;
use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\Team;
use App\Services\Secretariat\MissionOrders;
use App\Services\Secretariat\MissionTravelers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Secretariat → Mission orders (أوامر المهمة): list, form, cancel, the Print
 * buttons' reference call and "Add a team". MissionOrders owns every write
 * and its transaction; nothing here wraps one.
 */
class MissionOrderController extends Controller
{
    public function __construct(
        private readonly MissionOrders $missions,
        private readonly MissionTravelers $travelers,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'status' => ['nullable', Rule::in(MissionOrder::STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();

        $orders = MissionOrder::query()
            ->with('travelers:id,mission_order_id,name,sort_order')
            // A date range, not YEAR(): portable, and it also matches dates stored with a time.
            ->when($filters['year'] ?? null, fn ($q, $year) => $q
                ->where('order_date', '>=', sprintf('%04d-01-01', $year))
                ->where('order_date', '<', sprintf('%04d-01-01', $year + 1)))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(function ($q) use ($text) {
                $like = '%'.$text.'%';
                $q->where('destination', 'like', $like)
                    ->orWhere('legacy_reference', 'like', $like)
                    ->orWhereHas('travelers', fn ($t) => $t->where('name', 'like', $like));
                // "2026/3" finds the order printed under mail entry 3 of 2026 (active or cancelled with it).
                if (preg_match('#(\d{4})\s*/\s*0*(\d+)\s*$#u', $text, $m)) {
                    $q->orWhereIn('mission_orders.id', MailEntry::query()->select('source_id')
                        ->where('source_type', MailEntry::SOURCE_MISSION)->where('direction', MailEntry::OUT)
                        ->where('year', (int) $m[1])->where('number', (int) $m[2]));
                }
            }))
            ->orderByDesc('order_date')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        $references = $this->missions->references($orders->getCollection());
        $orders->through(fn (MissionOrder $order) => [
            'id' => $order->id,
            'reference' => $references[$order->id] ?? null,
            'order_date' => $order->order_date?->format('Y-m-d'),
            'destination' => $order->destination,
            'travelers' => $order->travelers->pluck('name')->take(3)->implode(', '),
            'travelers_count' => $order->travelers->count(),
            'departure_date' => $order->departure_date?->format('Y-m-d'),
            'return_date' => $order->return_date?->format('Y-m-d'),
            'status' => $order->status,
            'is_legacy' => $order->is_legacy,
        ]);

        return Inertia::render('Missions/Index', [
            'orders' => $orders,
            'filters' => $filters,
            'years' => MissionOrder::query()->whereNotNull('order_date')->toBase()->pluck('order_date')
                ->map(fn ($date) => (int) substr((string) $date, 0, 4))
                ->unique()->sortDesc()->values(),
            'can' => [
                'create' => $user->hasPermission('missions', 'add'),
                'register' => $user->hasPermission('missions', 'edit'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Missions/Create', $this->travelers->options($request->user()));
    }

    public function store(SaveMissionOrderRequest $request): RedirectResponse
    {
        $order = $this->missions->create($request->validated(), $request->user());

        return redirect()->route('missions.show', $order)->with('success', 'flash.mission_created');
    }

    public function show(Request $request, MissionOrder $missionOrder): Response
    {
        $user = $request->user();
        $editable = $missionOrder->isEditable();

        return Inertia::render('Missions/Show', [
            'order' => $missionOrder->load('travelers'),
            'reference' => $this->missions->reference($missionOrder),
            'can' => [
                'edit' => $editable && $user->hasPermission('missions', 'edit'),
                'cancel' => $editable && $user->hasPermission('missions', 'delete'),
                // The first print takes the reference; cancelled and imported orders print without one.
                'register' => $editable && $user->hasPermission('missions', 'edit'),
            ],
        ]);
    }

    public function edit(Request $request, MissionOrder $missionOrder): Response|RedirectResponse
    {
        if (! $missionOrder->isEditable()) {
            return redirect()->route('missions.show', $missionOrder)->with('error', 'flash.mission_locked');
        }

        return Inertia::render('Missions/Edit', [
            'order' => $missionOrder->load('travelers'),
            ...$this->travelers->options($request->user()),
        ]);
    }

    public function update(SaveMissionOrderRequest $request, MissionOrder $missionOrder): RedirectResponse
    {
        $this->missions->update($missionOrder, $request->validated(), $request->user());

        return redirect()->route('missions.show', $missionOrder)->with('success', 'flash.mission_updated');
    }

    public function cancel(Request $request, MissionOrder $missionOrder): RedirectResponse
    {
        $this->missions->cancel($missionOrder, $request->user());

        return back()->with('success', 'flash.mission_cancelled');
    }

    /** The Print buttons: take (or get back) the order's reference, then the page opens the PDF. */
    public function register(Request $request, MissionOrder $missionOrder): JsonResponse
    {
        $entry = $this->missions->register($missionOrder, $request->user());

        return response()->json(['reference' => $entry->reference, 'created' => $entry->wasRecentlyCreated]);
    }

    /** "Add a team": the form writes orders (add or edit) and the team's page and players are readable. */
    public function teamMembers(Request $request, Team $team): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            ($user->hasPermission('missions', 'add') || $user->hasPermission('missions', 'edit'))
                && $user->hasPermission('teams', 'view') && $user->hasPermission('players', 'view'),
            403,
        );

        return response()->json(['travelers' => $this->travelers->team($team)]);
    }
}
```

- [ ] **Step 6: Routes**

In `routes/web.php` replace

```php
use App\Http\Controllers\MembershipIdController;
```

with

```php
use App\Http\Controllers\MembershipIdController;
use App\Http\Controllers\MissionOrderController;
```

and replace

```php
    // An outgoing entry with a text, printed as a letter on the letterhead.
    Route::get('/mail/{mailEntry}/print', MailEntryPrintController::class)->name('mail.print');
```

with

```php
    // An outgoing entry with a text, printed as a letter on the letterhead.
    Route::get('/mail/{mailEntry}/print', MailEntryPrintController::class)->name('mail.print');

    // Secretariat → mission orders (أوامر المهمة): never deleted — cancelled instead, so the routes have no destroy.
    // "Add a team": a team's coaches and players of the season, as rows for the form.
    Route::get('/missions/team-members/{team}', [MissionOrderController::class, 'teamMembers'])->name('missions.team-members');
    Route::resource('missions', MissionOrderController::class)->except(['destroy'])->parameters(['missions' => 'missionOrder']);
    Route::post('/missions/{missionOrder}/cancel', [MissionOrderController::class, 'cancel'])->name('missions.cancel');
    // The Print buttons take the order's mail-register reference here; the PDF routes only look it up.
    Route::post('/missions/{missionOrder}/register', [MissionOrderController::class, 'register'])->name('missions.register');
```

- [ ] **Step 7: Translations**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p4-t5-keys.json`:

```json
{
    "flash.mission_created": { "ar": "تم إنشاء أمر المهمة.", "fr": "Ordre de mission créé.", "en": "Mission order created." },
    "flash.mission_updated": { "ar": "تم تعديل أمر المهمة.", "fr": "Ordre de mission modifié.", "en": "Mission order updated." },
    "flash.mission_cancelled": { "ar": "تم إلغاء أمر المهمة ومراسلته في سجل البريد.", "fr": "Ordre de mission annulé, ainsi que son courrier au registre.", "en": "Mission order cancelled, with its mail-register entry." },
    "mreg.source.mission_order": { "ar": "أمر بمهمة", "fr": "Ordre de mission", "en": "Mission order" }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p4-t5-keys.json`
Expected: `✓ 4 key(s) written to ar/en/fr`

- [ ] **Step 8: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MissionOrderHttpTest.php`
Expected: PASS (13 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailEntryHttpTest.php`
Expected: PASS (P3 sources unchanged).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailSourceTest.php`
Expected: PASS.
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/FlashTranslationTest.php`
Expected: PASS.
Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/Mission/SaveMissionOrderRequest.php app/Http/Controllers/MissionOrderController.php app/Services/Secretariat/MailSources.php routes/web.php tests/Feature/MissionOrderHttpTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(missions): mission order routes, form request, reference and team calls, mail register source

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 6: The printed sheets (one per traveler, President + Secretary General)

**Files:**
- Create: `app/Http/Controllers/MissionOrderPrintController.php`
- Create: `resources/views/pdf/mission-order.blade.php`
- Modify: `routes/web.php` (import after `MissionOrderController`; routes after `missions.register`)
- Modify: `resources/js/i18n/*.json` (via script)
- Test: `tests/Feature/MissionOrderPrintTest.php`

**Interfaces:**
- Consumes: `MissionOrders::reference()` (Task 3 — lookup only), `MissionOrder::travelers()`, `CANCELLED` (Task 2), `BoardSignatories::names()` (P3: `president`, `secretary`), `ClubHeader::data()`, `PdfService::stream()`, `PdfText::ltrIfLatin()`, partials `pdf.partials.header`, `pdf.partials.sign-line`, labels `mreg.pdf.ref`, `mreg.pdf.date`, `mreg.pdf.secretary`, `po.pdf.president` (existing).
- Produces:
  - Route `missions.print` GET `/missions/{missionOrder}/print` — every traveler, one sheet each, `<pagebreak />` between; `missions.print-traveler` GET `/missions/{missionOrder}/print/{traveler}` — one sheet (404 when the traveler belongs to another order). Both work for active, cancelled (with a «CANCELLED» banner) and imported orders, never write a mail entry, and log `MISSION_ORDER_PRINTED` (`reference`, `traveler_id`).
  - File names `ordre-de-mission-{reference with non-alphanumerics as "-" | order id}[-{traveler sort_order}].pdf` (e.g. `ordre-de-mission-2026-01.pdf`, `ordre-de-mission-2026-01-2.pdf`, `ordre-de-mission-59.pdf`).
  - Label keys `mis.pdf.title`, `mis.pdf.name`, `mis.pdf.function`, `mis.pdf.destination`, `mis.pdf.reason`, `mis.pdf.departure`, `mis.pdf.return`, `mis.pdf.transport`, `mis.pdf.cancelled`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MissionOrderPrintTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Pdf\PdfService;
use App\Services\Secretariat\MissionOrders;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MissionOrderPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-26 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function typed(string $name, ?string $function = null): array
    {
        return ['traveler_type' => null, 'traveler_id' => null, 'name' => $name, 'function' => $function];
    }

    private function order(array $extra = []): MissionOrder
    {
        return app(MissionOrders::class)->create(array_merge([
            'order_date' => '2026-11-26', 'destination' => 'Testville', 'reason' => "Stage de formation\nNiveau 1",
            'departure_date' => '2026-12-01', 'return_date' => '2026-12-03', 'transport' => 'Bus du club',
            'travelers' => [
                $this->typed('Entraîneur Test', 'Entraîneur principal'),
                $this->typed('Adjoint Test', 'Entraîneur adjoint'),
                $this->typed('Joueur Test', 'Joueur — U15'),
            ],
        ], $extra), User::factory()->admin()->create());
    }

    private function user(string $locale = 'fr'): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function member(string $name, string $role): void
    {
        BoardMember::create(['board_term_id' => BoardTerm::current()?->id, 'name' => $name, 'role' => $role, 'status' => 'active']);
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
    public function the_sheets_are_a_real_pdf_in_arabic(): void
    {
        $response = $this->actingAs($this->user('ar'))->get(route('missions.print', $this->order()))->assertOk();

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function one_traveler_is_a_real_pdf_in_french(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->user('fr'))->get(route('missions.print-traveler', [$order, $order->travelers[1]]))->assertOk();

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    #[Test]
    public function each_traveler_gets_a_sheet_with_every_field_and_both_signatures(): void
    {
        $this->member('Président Test', 'president');
        $this->member('Secrétaire Test', 'secretary');
        $this->member('Trésorier Test', 'treasurer');
        $order = $this->order();
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('missions.print', $order))->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertSame('ordre-de-mission-'.$order->id.'.pdf', $seen['filename']);
        $this->assertSame(2, substr_count($seen['html'], '<pagebreak />'));
        $this->assertSame(3, substr_count($seen['html'], UiLang::get('mis.pdf.title', null, 'fr')));
        foreach ([
            'Entraîneur Test', 'Adjoint Test', 'Joueur Test', 'Entraîneur principal', 'Joueur — U15',
            UiLang::get('mis.pdf.name', null, 'fr'), UiLang::get('mis.pdf.function', null, 'fr'),
            UiLang::get('mis.pdf.destination', null, 'fr'), 'Testville',
            UiLang::get('mis.pdf.reason', null, 'fr'), 'Stage de formation</bdo><br /><bdo dir="ltr">Niveau 1',
            UiLang::get('mis.pdf.departure', null, 'fr'), '<bdo dir="ltr">01/12/2026</bdo>',
            UiLang::get('mis.pdf.return', null, 'fr'), '<bdo dir="ltr">03/12/2026</bdo>',
            UiLang::get('mis.pdf.transport', null, 'fr'), 'Bus du club',
            '<bdo dir="ltr">26/11/2026</bdo>',
            UiLang::get('mreg.pdf.secretary', null, 'fr'), UiLang::get('po.pdf.president', null, 'fr'),
            'Président Test', 'Secrétaire Test',
        ] as $text) {
            $this->assertStringContainsString($text, $seen['html'], $text);
        }
        // The treasurer does not sign mission orders; no banner on an active order.
        $this->assertStringNotContainsString('Trésorier Test', $seen['html']);
        $this->assertStringNotContainsString(UiLang::get('mis.pdf.cancelled', null, 'fr'), $seen['html']);
        // Opening the PDF never takes a reference.
        $this->assertSame(0, MailEntry::count());
    }

    #[Test]
    public function the_reference_taken_at_the_first_print_shows_on_every_sheet(): void
    {
        $order = $this->order();
        app(MissionOrders::class)->register($order, User::factory()->admin()->create());
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('missions.print', $order))->assertOk();

        $this->assertSame(3, substr_count($seen['html'], '<bdo dir="ltr">2026/01</bdo>'));
        $this->assertSame('ordre-de-mission-2026-01.pdf', $seen['filename']);
        $this->assertSame(1, MailEntry::count());
    }

    #[Test]
    public function one_traveler_prints_alone(): void
    {
        $order = $this->order();
        app(MissionOrders::class)->register($order, User::factory()->admin()->create());
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('missions.print-traveler', [$order, $order->travelers[1]]))->assertOk();

        $this->assertStringContainsString('Adjoint Test', $seen['html']);
        $this->assertStringNotContainsString('Entraîneur Test', $seen['html']);
        $this->assertStringNotContainsString('Joueur Test', $seen['html']);
        $this->assertStringNotContainsString('<pagebreak />', $seen['html']);
        $this->assertStringContainsString('<bdo dir="ltr">2026/01</bdo>', $seen['html']);
        $this->assertSame('ordre-de-mission-2026-01-2.pdf', $seen['filename']);
    }

    #[Test]
    public function a_traveler_of_another_order_is_not_found(): void
    {
        $order = $this->order();
        $other = $this->order();

        $this->actingAs($this->user('fr'))->get(route('missions.print-traveler', [$order, $other->travelers[0]]))->assertNotFound();
    }

    #[Test]
    public function the_arabic_sheet_reads_right_to_left_and_keeps_latin_lines_left_to_right(): void
    {
        $seen = $this->spyPdf();

        $this->actingAs($this->user('ar'))->get(route('missions.print', $this->order([
            'destination' => 'وهران', 'reason' => "تربص تكويني\nStage FAF 2026",
            'travelers' => [$this->typed('مدرب تجريبي', 'مدرب')],
        ])))->assertOk();

        $this->assertTrue($seen['rtl']);
        $this->assertStringContainsString('<body dir="rtl">', $seen['html']);
        $this->assertStringContainsString('تربص تكويني<br /><bdo dir="ltr">Stage FAF 2026</bdo>', $seen['html']);
        $this->assertStringContainsString('وهران', $seen['html']);
        $this->assertStringContainsString('مدرب تجريبي', $seen['html']);
        $this->assertStringContainsString(UiLang::get('mis.pdf.title', null, 'ar'), $seen['html']);
    }

    #[Test]
    public function a_cancelled_order_prints_with_a_banner_and_keeps_its_reference(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->order();
        app(MissionOrders::class)->register($order, $admin);
        app(MissionOrders::class)->cancel($order, $admin);
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('missions.print', $order))->assertOk();

        $this->assertStringContainsString(UiLang::get('mis.pdf.cancelled', null, 'fr'), $seen['html']);
        $this->assertStringContainsString('<bdo dir="ltr">2026/01</bdo>', $seen['html']);
        $this->assertSame(1, MailEntry::count());
    }

    #[Test]
    public function an_imported_order_prints_its_old_reference(): void
    {
        $legacy = MissionOrder::create([
            'order_date' => '2018-05-12', 'destination' => 'Vieilleville', 'status' => MissionOrder::ACTIVE,
            'is_legacy' => true, 'legacy_reference' => '59', 'legacy_source' => MissionOrder::LEGACY_SOURCE,
        ]);
        $legacy->travelers()->create(['name' => 'Ancien Test', 'function' => 'Entraîneur', 'sort_order' => 1]);
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('missions.print', $legacy))->assertOk();

        $this->assertStringContainsString('<bdo dir="ltr">59</bdo>', $seen['html']);
        $this->assertStringContainsString('Ancien Test', $seen['html']);
        $this->assertSame('ordre-de-mission-59.pdf', $seen['filename']);
        $this->assertSame(0, MailEntry::count());
    }

    #[Test]
    public function a_view_only_user_prints_and_others_may_not(): void
    {
        $order = $this->order();
        $role = fn (array $permissions) => User::factory()->create([
            'privileges' => ['user'], 'preferred_lng' => 'fr',
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
        ]);
        $this->spyPdf();

        $this->actingAs($role(['missions' => ['view']]))->get(route('missions.print', $order))->assertOk();
        $this->actingAs($role(['mail' => ['view']]))->get(route('missions.print-traveler', [$order, $order->travelers[0]]))->assertForbidden();
    }

    #[Test]
    public function each_print_is_logged_with_references_and_ids_only(): void
    {
        $order = $this->order();
        app(MissionOrders::class)->register($order, User::factory()->admin()->create());
        $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('missions.print-traveler', [$order, $order->travelers[2]]))->assertOk();

        $log = ActivityLog::where('action', ActivityAction::MISSION_ORDER_PRINTED)->sole();
        $this->assertSame($order->id, (int) $log->subject_id);
        $this->assertSame(['reference' => '2026/01', 'traveler_id' => $order->travelers[2]->id], $log->properties);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MissionOrderPrintTest.php`
Expected: FAIL (`Route [missions.print] not defined`).

- [ ] **Step 3: Print controller**

Create `app/Http/Controllers/MissionOrderPrintController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\MissionOrder;
use App\Models\MissionOrderTraveler;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Pdf\BoardSignatories;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Services\Secretariat\MissionOrders;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * «أمر بمهمة» / Ordre de mission on A4, in the app language: one sheet per
 * traveler, every traveler in one PDF or one traveler's sheet. The order's
 * reference is looked up, never taken (that is missions.register). Cancelled
 * orders (with a banner) and imported orders print too.
 */
class MissionOrderPrintController extends Controller
{
    public function __construct(
        private readonly PdfService $pdf,
        private readonly MissionOrders $missions,
    ) {}

    public function all(Request $request, MissionOrder $missionOrder): Response
    {
        return $this->sheets($request, $missionOrder, $missionOrder->travelers()->get());
    }

    public function traveler(Request $request, MissionOrder $missionOrder, MissionOrderTraveler $traveler): Response
    {
        abort_unless($traveler->mission_order_id === $missionOrder->id, 404);

        return $this->sheets($request, $missionOrder, collect([$traveler]), $traveler);
    }

    /** @param Collection<int, MissionOrderTraveler> $travelers */
    private function sheets(Request $request, MissionOrder $order, Collection $travelers, ?MissionOrderTraveler $one = null): Response
    {
        abort_if($travelers->isEmpty(), 404);
        // Lookup only: opening, refreshing or reprinting never takes a number.
        $reference = $this->missions->reference($order);
        $locale = app()->getLocale();

        $html = view('pdf.mission-order', [
            'club' => ClubHeader::data(),
            'rtl' => $locale === 'ar',
            'order' => $order,
            'travelers' => $travelers->values(),
            'reference' => $reference,
            'signatories' => BoardSignatories::names(),
        ])->render();

        ActivityRecorder::record($request->user(), ActivityAction::MISSION_ORDER_PRINTED, $order, [
            'reference' => $reference,
            'traveler_id' => $one?->id,
        ]);

        $slug = trim((string) preg_replace('/[^0-9A-Za-z]+/', '-', (string) $reference), '-');
        $name = 'ordre-de-mission-'.($slug !== '' ? $slug : $order->id).($one !== null ? '-'.$one->sort_order : '');

        return $this->pdf->stream($html, $name.'.pdf', $locale === 'ar');
    }
}
```

- [ ] **Step 4: The template**

Create `resources/views/pdf/mission-order.blade.php`:

```blade
@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    // Free text: Latin lines keep their own left-to-right order inside the RTL page; line breaks become <br /> between bdo's.
    $T = fn (?string $v) => \App\Support\PdfText::ltrIfLatin($v === null || trim($v) === '' ? '—' : $v);
    // References and dates read left to right; a missing one is a dash.
    $ltr = fn (?string $v) => $v === null || $v === '' ? '—' : new \Illuminate\Support\HtmlString('<bdo dir="ltr">'.e($v).'</bdo>');
    $cancelled = $order->status === \App\Models\MissionOrder::CANCELLED;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; line-height: 1.5; }
        .banner { text-align: center; font-weight: bold; color: #b91c1c; border: 1px solid #b91c1c; padding: 4px; margin-bottom: 10px; }
        .title { font-size: 20px; font-weight: bold; text-align: center; margin: 4px 0 14px; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.meta td { padding: 2px 0; vertical-align: top; }
        table.fields { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        table.fields td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        table.fields td.label { width: 32%; color: #64748b; }
        .label { color: #64748b; }
    </style>
</head>
<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    @foreach ($travelers as $i => $traveler)
        @if ($i > 0)
            <pagebreak />
        @endif

        @include('pdf.partials.header')

        @if ($cancelled)
            <div class="banner">{{ $L('mis.pdf.cancelled') }}</div>
        @endif

        <div class="title">{{ $L('mis.pdf.title') }}</div>

        <table class="meta">
            <tr>
                <td><span class="label">{{ $L('mreg.pdf.ref') }}</span> {{ $ltr($reference) }}</td>
                <td style="text-align: {{ $rtl ? 'left' : 'right' }};"><span class="label">{{ $L('mreg.pdf.date') }}</span> {{ $ltr($order->order_date?->format('d/m/Y')) }}</td>
            </tr>
        </table>

        <table class="fields">
            <tr><td class="label">{{ $L('mis.pdf.name') }}</td><td>{{ $T($traveler->name) }}</td></tr>
            <tr><td class="label">{{ $L('mis.pdf.function') }}</td><td>{{ $T($traveler->function) }}</td></tr>
            <tr><td class="label">{{ $L('mis.pdf.destination') }}</td><td>{{ $T($order->destination) }}</td></tr>
            <tr><td class="label">{{ $L('mis.pdf.reason') }}</td><td>{{ $T($order->reason) }}</td></tr>
            <tr><td class="label">{{ $L('mis.pdf.departure') }}</td><td>{{ $ltr($order->departure_date?->format('d/m/Y')) }}</td></tr>
            <tr><td class="label">{{ $L('mis.pdf.return') }}</td><td>{{ $ltr($order->return_date?->format('d/m/Y')) }}</td></tr>
            <tr><td class="label">{{ $L('mis.pdf.transport') }}</td><td>{{ $T($order->transport) }}</td></tr>
        </table>

        {{-- President + Secretary General (blank line when nobody holds the role). --}}
        <table style="width:100%; margin-top:30px; text-align:center; font-size:12px; page-break-inside: avoid;">
            <tr>
                <td style="width:50%; vertical-align:top;">
                    <div style="font-weight:bold;">{{ $L('mreg.pdf.secretary') }}</div>
                    <div style="margin-top:4px;">{{ $signatories['secretary'] ?? '' }}</div>
                    @include('pdf.partials.sign-line')
                </td>
                <td style="width:50%; vertical-align:top;">
                    <div style="font-weight:bold;">{{ $L('po.pdf.president') }}</div>
                    <div style="margin-top:4px;">{{ $signatories['president'] ?? '' }}</div>
                    @include('pdf.partials.sign-line')
                </td>
            </tr>
        </table>
    @endforeach
</body>
</html>
```

- [ ] **Step 5: Routes**

In `routes/web.php` replace

```php
use App\Http\Controllers\MissionOrderController;
```

with

```php
use App\Http\Controllers\MissionOrderController;
use App\Http\Controllers\MissionOrderPrintController;
```

and replace

```php
    Route::post('/missions/{missionOrder}/register', [MissionOrderController::class, 'register'])->name('missions.register');
```

with

```php
    Route::post('/missions/{missionOrder}/register', [MissionOrderController::class, 'register'])->name('missions.register');
    // One sheet per traveler: every traveler in one PDF, or one traveler's sheet.
    Route::get('/missions/{missionOrder}/print', [MissionOrderPrintController::class, 'all'])->name('missions.print');
    Route::get('/missions/{missionOrder}/print/{traveler}', [MissionOrderPrintController::class, 'traveler'])
        ->whereNumber('traveler')->name('missions.print-traveler');
```

- [ ] **Step 6: PDF labels**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p4-t6-keys.json`:

```json
{
    "mis.pdf.title": { "ar": "أمر بمهمة", "fr": "Ordre de mission", "en": "Mission order" },
    "mis.pdf.name": { "ar": "الاسم واللقب:", "fr": "Nom et prénom :", "en": "Name:" },
    "mis.pdf.function": { "ar": "الوظيفة:", "fr": "Fonction :", "en": "Function:" },
    "mis.pdf.destination": { "ar": "الوجهة:", "fr": "Destination :", "en": "Destination:" },
    "mis.pdf.reason": { "ar": "سبب المهمة:", "fr": "Motif :", "en": "Purpose:" },
    "mis.pdf.departure": { "ar": "تاريخ الذهاب:", "fr": "Date de départ :", "en": "Departure date:" },
    "mis.pdf.return": { "ar": "تاريخ العودة:", "fr": "Date de retour :", "en": "Return date:" },
    "mis.pdf.transport": { "ar": "وسيلة النقل:", "fr": "Moyen de transport :", "en": "Means of transport:" },
    "mis.pdf.cancelled": { "ar": "أمر ملغى", "fr": "ORDRE ANNULÉ", "en": "CANCELLED ORDER" }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p4-t6-keys.json`
Expected: `✓ 9 key(s) written to ar/en/fr`

- [ ] **Step 7: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MissionOrderPrintTest.php`
Expected: PASS (11 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailLetterPrintTest.php`
Expected: PASS (`BoardSignatories` unchanged).
Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/MissionOrderPrintController.php resources/views/pdf/mission-order.blade.php routes/web.php tests/Feature/MissionOrderPrintTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(missions): mission order sheets, one per traveler, signed by the president and the secretary general

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 7: Pages, Secretariat menu entry, the print button

**Files:**
- Create: `resources/js/Pages/Missions/Index.vue`, `Create.vue`, `Edit.vue`, `Show.vue`, `Partials/MissionOrderForm.vue`
- Create: `resources/js/Components/MissionPrintButton.vue`
- Modify: `resources/js/Composables/useNavigation.js:57-60` (Secretariat section)
- Modify: `resources/js/i18n/*.json` (via script)
- Test: `tests/Feature/MissionPagesTest.php`

**Interfaces:**
- Consumes: the Inertia props of Task 5 (`Missions/Index`, `Missions/Create`, `Missions/Edit`, `Missions/Show`), routes `missions.*` (Tasks 5-6), JSON of `missions.register` (`{reference, created}`) and `missions.team-members` (`{travelers}`), `useCan()`, `window.axios` (sends the XSRF cookie), `IconButton`, `Modal` (`show`, `max-width`, `closeable`), `Checkbox` (`v-model:checked`), `Badge`, `TextInput` (`v-model`, String), `SearchableSelect` (`options` of `{value, label, description?}`, `v-model`, `@update:model-value`), `localToday()`, `useFormatDate().formatDate`.
- Produces: `MissionPrintButton` props `href` (PDF URL, required), `registerUrl` (`missions.register` URL, or null = plain link), `icon`, `label`, `variant`, `size`, `plain`; emits `registered(reference: string)`; other attributes go to the button.

Before editing, check no source-text guard test pins the files this task touches:

Run: `grep -rln "useNavigation\|Pages/Missions\|MissionPrintButton" tests`
Expected: no output (`tests/js/navigation.test.mjs` tests `resources/js/lib/navigation.js`, not the composable).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MissionPagesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\MissionOrder;
use App\Models\User;
use App\Services\Secretariat\MissionOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MissionPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-26 10:00:00');
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(): MissionOrder
    {
        return app(MissionOrders::class)->create([
            'order_date' => '2026-11-26', 'destination' => 'Testville', 'departure_date' => '2026-12-01',
            'travelers' => [['traveler_type' => null, 'traveler_id' => null, 'name' => 'Entraîneur Test', 'function' => 'Entraîneur']],
        ], $this->admin);
    }

    #[Test]
    public function the_list_and_the_create_page_render(): void
    {
        $this->order();

        $this->actingAs($this->admin)->get(route('missions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Missions/Index')->has('orders.data', 1));
        $this->get(route('missions.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Missions/Create')->has('users')->has('boardMembers'));
    }

    #[Test]
    public function the_edit_and_show_pages_render(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)->get(route('missions.edit', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Missions/Edit')->where('order.id', $order->id));
        $this->get(route('missions.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Missions/Show')
                ->where('reference', null)->where('can.register', true)->has('order.travelers', 1));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MissionPagesTest.php`
Expected: FAIL (Inertia page component file `Missions/Index` does not exist).

- [ ] **Step 3: Secretariat menu**

In `resources/js/Composables/useNavigation.js` replace

```js
            // The board's secretariat (الأمانة): mail register now, mission orders in P4.
            { key: 'secretariat', label: t('nav.secretariat'), icon: 'mail', items: [
                { label: t('mail'), href: '/mail', icon: 'mail', prefix: '/mail', module: 'mail' },
            ] },
```

with

```js
            // The board's secretariat (الأمانة): mail register and mission orders.
            { key: 'secretariat', label: t('nav.secretariat'), icon: 'mail', items: [
                { label: t('mail'), href: '/mail', icon: 'mail', prefix: '/mail', module: 'mail' },
                { label: t('missions'), href: '/missions', icon: 'send', prefix: '/missions', module: 'missions' },
            ] },
```

- [ ] **Step 4: The print button**

Create `resources/js/Components/MissionPrintButton.vue`:

```vue
<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import IconButton from '@/Components/IconButton.vue';

/**
 * Print button of a mission order (every sheet, or one traveler's). With a
 * `registerUrl` — an active order typed here, with no reference yet, and the
 * missions edit right — the click takes the order's mail-register reference
 * (POST), then the PDF opens; the PDF route only looks the reference up, so
 * reprints keep it. Without one it is a plain link. No dialog: printing a
 * mission order always takes its reference (owner 2026-11-26), so there is
 * nothing to ask — and no Modal per table row.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    href: { type: String, required: true },
    // missions.register URL, or null: print straight away.
    registerUrl: { type: String, default: null },
    icon: { type: String, default: 'print' },
    label: { type: String, required: true },
    variant: { type: String, default: 'neutral' },
    size: { type: String, default: 'md' },
    plain: { type: Boolean, default: false },
});
const emit = defineEmits(['registered']);

const { t } = useI18n();
const busy = ref(false);
const failed = ref(false);

async function print() {
    if (busy.value) return;
    // Opened now, while the click still counts as the user's: a tab opened after the request is blocked as a pop-up.
    const tab = window.open('', '_blank');
    busy.value = true;
    failed.value = false;
    try {
        const { data } = await window.axios.post(props.registerUrl);
        emit('registered', data.reference);
        if (tab) tab.location.href = props.href;
        else window.open(props.href, '_blank');
    } catch {
        tab?.close();
        failed.value = true;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <IconButton v-if="!registerUrl" v-bind="$attrs" :href="href" external target="_blank" :icon="icon" :label="label" :variant="variant" :size="size" :plain="plain" />
    <span v-else class="inline-flex items-center gap-2">
        <IconButton v-bind="$attrs" :icon="icon" :label="label" :variant="variant" :size="size" :plain="plain" :disabled="busy" @click="print" />
        <span v-if="failed" role="alert" class="text-xs text-rose-600 dark:text-rose-300">{{ t('mis.register_failed') }}</span>
    </span>
</template>
```

- [ ] **Step 5: List page**

Create `resources/js/Pages/Missions/Index.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Badge from '@/Components/Badge.vue';
import TextInput from '@/Components/TextInput.vue';
import MissionPrintButton from '@/Components/MissionPrintButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormatDate } from '@/Composables/useFormatDate';

const { t } = useI18n();
const { formatDate } = useFormatDate();

const props = defineProps({ orders: Object, filters: Object, years: Array, can: Object });

const STATUSES = ['active', 'cancelled'];
const STATUS_COLOR = { active: 'emerald', cancelled: 'rose' };

// References a print on this page took, until the next reload.
const taken = reactive({});
const referenceOf = (order) => taken[order.id] ?? order.reference;
const registerUrl = (order) => (props.can.register && order.status === 'active' && !order.is_legacy && !referenceOf(order)
    ? route('missions.register', order.id) : null);

const filters = reactive({ year: props.filters.year ?? '', status: props.filters.status ?? '', q: props.filters.q ?? '' });
let timer = null;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('missions.index'),
        Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')),
        { preserveState: true, replace: true }), 300);
});
</script>

<template>
    <Head :title="t('missions')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('missions') }}</h1>
                <IconButton v-if="can.create" :href="route('missions.create')" icon="plus" :label="t('mis.new')" variant="primary" />
            </div>
        </template>

        <div class="space-y-4">
            <div class="grid grid-cols-1 gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:grid-cols-4 dark:bg-slate-900 dark:ring-slate-800">
                <TextInput v-model="filters.q" class="sm:col-span-2" :placeholder="t('mis.find_placeholder')" />
                <select v-model="filters.year" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <option value="">{{ t('po.all_years') }}</option>
                    <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                </select>
                <select v-model="filters.status" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <option value="">{{ t('mis.all_statuses') }}</option>
                    <option v-for="s in STATUSES" :key="s" :value="s">{{ t('mis.status.' + s) }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3 text-start">{{ t('reference') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('mis.order_date') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('mis.destination') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('mis.travelers') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('mis.trip') }}</th>
                            <th class="px-4 py-3 text-center">{{ t('status') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="o in orders.data" :key="o.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="whitespace-nowrap px-4 py-3 font-mono">
                                <Link :href="route('missions.show', o.id)" class="text-primary-600 hover:underline dark:text-primary-300"><bdi>{{ referenceOf(o) || '—' }}</bdi></Link>
                                <span v-if="o.is_legacy" class="ms-1 rounded bg-slate-100 px-1 font-sans text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('po.legacy') }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3"><bdi v-if="o.order_date" dir="ltr">{{ formatDate(o.order_date) }}</bdi><span v-else class="text-slate-400 dark:text-slate-500">—</span></td>
                            <td class="px-4 py-3 text-slate-900 dark:text-slate-100">
                                <Link :href="route('missions.show', o.id)" class="hover:underline">{{ o.destination || '—' }}</Link>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                                {{ o.travelers }}<span v-if="o.travelers_count > 3" class="text-slate-400 dark:text-slate-500"> +{{ o.travelers_count - 3 }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-300">
                                <bdi v-if="o.departure_date" dir="ltr">{{ formatDate(o.departure_date) }}<template v-if="o.return_date"> – {{ formatDate(o.return_date) }}</template></bdi>
                            </td>
                            <td class="px-4 py-3 text-center"><Badge :label="t('mis.status.' + o.status)" :color="STATUS_COLOR[o.status]" /></td>
                            <td class="px-4 py-3 text-end">
                                <MissionPrintButton :href="route('missions.print', o.id)" :register-url="registerUrl(o)" :label="t('mis.print_all')" size="sm" plain
                                    @registered="(reference) => { taken[o.id] = reference; }" />
                            </td>
                        </tr>
                        <tr v-if="!orders.data.length"><td colspan="7" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td></tr>
                    </tbody>
                </table>
            </div>

            <div v-if="orders.last_page > 1" class="flex flex-wrap justify-center gap-1">
                <Link v-for="(link, i) in orders.links" :key="i" :href="link.url || ''" v-html="link.label" preserve-state
                    :class="['rounded px-3 py-1 text-sm', link.active ? 'bg-primary-600 text-white' : 'text-slate-600 dark:text-slate-300', !link.url && 'pointer-events-none opacity-40']" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 6: Form partial**

Create `resources/js/Pages/Missions/Partials/MissionOrderForm.vue`:

```vue
<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import IconButton from '@/Components/IconButton.vue';
import Checkbox from '@/Components/Checkbox.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { localToday } from '@/lib/localDate';

const { t } = useI18n();
const props = defineProps({
    order: { type: Object, default: null },
    // MissionTravelers::options(): { value, label, description?, function } — function is the default.
    users: { type: Array, default: () => [] },
    boardMembers: { type: Array, default: () => [] },
    // null: the viewer may not read players (or teams); that picker is hidden.
    players: { type: Array, default: null },
    teams: { type: Array, default: null },
});
const isEdit = !!props.order;
const day = (value) => (value ? String(value).slice(0, 10) : '');

const form = useForm({
    order_date: isEdit ? day(props.order.order_date) : localToday(),
    destination: props.order?.destination ?? '',
    reason: props.order?.reason ?? '',
    departure_date: day(props.order?.departure_date),
    return_date: day(props.order?.return_date),
    transport: props.order?.transport ?? '',
    travelers: (props.order?.travelers ?? []).map((row) => ({
        traveler_type: row.traveler_type ?? null,
        traveler_id: row.traveler_id ?? null,
        name: row.name ?? '',
        function: row.function ?? '',
    })),
});

// Adding someone: a kind, then the person — or a typed name.
const kinds = computed(() => ['user', 'board_member', ...(props.players ? ['player'] : []), 'typed']);
const kind = ref('user');
const pick = ref('');
const lists = computed(() => ({ user: props.users, board_member: props.boardMembers, player: props.players ?? [] }));
// The same person (kind + id) is listed once.
const listed = (type, id) => form.travelers.some((row) => row.traveler_type === type && Number(row.traveler_id) === Number(id));
const pickable = computed(() => (lists.value[kind.value] ?? []).filter((option) => !listed(kind.value, option.value)));
watch(kind, () => { pick.value = ''; });

function addPicked(id) {
    const option = (lists.value[kind.value] ?? []).find((o) => String(o.value) === String(id));
    if (option && !listed(kind.value, option.value)) {
        form.travelers.push({ traveler_type: kind.value, traveler_id: option.value, name: option.label, function: option.function ?? '' });
    }
    pick.value = '';
}
function addTyped() {
    form.travelers.push({ traveler_type: null, traveler_id: null, name: '', function: '' });
}
function removeAt(index) {
    form.travelers.splice(index, 1);
}

// "Add a team": its coaches and players of the season, all ticked; untick who stays home, then add.
const team = ref('');
const teamRows = ref(null);
const teamBusy = ref(false);
const teamFailed = ref(false);
async function loadTeam() {
    if (!team.value || teamBusy.value) return;
    teamBusy.value = true;
    teamFailed.value = false;
    try {
        const { data } = await window.axios.get(route('missions.team-members', team.value));
        teamRows.value = data.travelers
            .filter((row) => !listed(row.traveler_type, row.traveler_id))
            .map((row) => ({ ...row, keep: true }));
    } catch {
        teamRows.value = null;
        teamFailed.value = true;
    } finally {
        teamBusy.value = false;
    }
}
function closeTeam() {
    teamRows.value = null;
    team.value = '';
}
function addTeam() {
    for (const row of teamRows.value.filter((r) => r.keep)) {
        if (!listed(row.traveler_type, row.traveler_id)) {
            form.travelers.push({ traveler_type: row.traveler_type, traveler_id: row.traveler_id, name: row.name, function: row.function ?? '' });
        }
    }
    closeTeam();
}

const kindLabel = (type) => t('mis.kind.' + (type ?? 'typed'));
const rowError = (index, key) => form.errors[`travelers.${index}.${key}`];
const field = 'mt-1 w-full rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900';

function submit() {
    if (isEdit) form.put(route('missions.update', props.order.id));
    else form.post(route('missions.store'));
}
</script>

<template>
    <form @submit.prevent="submit" class="mx-auto max-w-4xl space-y-6">
        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <InputLabel :value="t('mis.order_date')" required />
                    <TextInput v-model="form.order_date" type="date" required class="mt-1 w-full" />
                    <InputError :message="form.errors.order_date" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <InputLabel :value="t('mis.destination')" required />
                    <TextInput v-model="form.destination" maxlength="200" required class="mt-1 w-full" />
                    <InputError :message="form.errors.destination" class="mt-1" />
                </div>
                <div class="sm:col-span-3">
                    <InputLabel :value="t('mis.reason')" />
                    <textarea v-model="form.reason" rows="2" maxlength="1000" :class="field"></textarea>
                    <InputError :message="form.errors.reason" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('mis.departure_date')" required />
                    <TextInput v-model="form.departure_date" type="date" required class="mt-1 w-full" />
                    <InputError :message="form.errors.departure_date" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('mis.return_date')" />
                    <TextInput v-model="form.return_date" type="date" :min="form.departure_date || undefined" class="mt-1 w-full" />
                    <InputError :message="form.errors.return_date" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('mis.transport')" />
                    <TextInput v-model="form.transport" maxlength="100" class="mt-1 w-full" />
                    <InputError :message="form.errors.transport" class="mt-1" />
                </div>
            </div>
        </div>

        <div class="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('mis.travelers') }}</h2>
            <InputError :message="form.errors.travelers" />
            <p v-if="!form.travelers.length" class="text-sm text-slate-500 dark:text-slate-400">{{ t('mis.no_travelers') }}</p>

            <div v-for="(row, index) in form.travelers" :key="index"
                class="grid grid-cols-1 items-start gap-2 rounded-xl bg-slate-50 p-3 sm:grid-cols-12 dark:bg-slate-800/60">
                <span class="self-center rounded bg-primary-50 px-1.5 py-0.5 text-center text-xs font-medium text-primary-700 sm:col-span-2 dark:bg-primary-500/15 dark:text-primary-300">{{ kindLabel(row.traveler_type) }}</span>
                <div class="sm:col-span-5">
                    <TextInput v-model="row.name" maxlength="200" required :aria-label="t('name')" :placeholder="t('name')" class="w-full" />
                    <InputError :message="rowError(index, 'name') || rowError(index, 'traveler_id') || rowError(index, 'traveler_type')" class="mt-1" />
                </div>
                <div class="sm:col-span-4">
                    <TextInput v-model="row.function" maxlength="200" :aria-label="t('mis.function_label')" :placeholder="t('mis.function_label')" class="w-full" />
                    <InputError :message="rowError(index, 'function')" class="mt-1" />
                </div>
                <div class="flex justify-end sm:col-span-1">
                    <IconButton icon="trash" :label="t('remove')" variant="danger" size="sm" plain @click="removeAt(index)" />
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 border-t border-slate-100 pt-4 sm:grid-cols-3 dark:border-slate-800">
                <div>
                    <InputLabel :value="t('mis.add_person')" />
                    <select v-model="kind" :class="field">
                        <option v-for="k in kinds" :key="k" :value="k">{{ kindLabel(k) }}</option>
                    </select>
                </div>
                <div v-if="kind === 'typed'" class="flex items-end sm:col-span-2">
                    <SecondaryButton type="button" @click="addTyped">{{ t('mis.add_typed') }}</SecondaryButton>
                </div>
                <div v-else class="sm:col-span-2">
                    <InputLabel :value="t('mis.choose_person')" />
                    <SearchableSelect v-model="pick" :options="pickable" :placeholder="t('mis.choose_person')" @update:model-value="addPicked" />
                </div>
            </div>

            <div v-if="teams" class="space-y-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <InputLabel :value="t('mis.add_team')" />
                        <SearchableSelect v-model="team" :options="teams" :placeholder="t('mis.choose_team')" :disabled="teamBusy" />
                    </div>
                    <div class="flex items-end">
                        <SecondaryButton type="button" :disabled="!team || teamBusy" @click="loadTeam">{{ t('mis.add_team') }}</SecondaryButton>
                    </div>
                </div>
                <p v-if="teamFailed" class="text-xs text-rose-600 dark:text-rose-300">{{ t('mis.team_failed') }}</p>
                <div v-if="teamRows" class="space-y-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                    <p class="text-xs text-slate-600 dark:text-slate-300">{{ t('mis.team_hint') }}</p>
                    <p v-if="!teamRows.length" class="text-sm text-slate-500 dark:text-slate-400">{{ t('mis.team_empty') }}</p>
                    <label v-for="row in teamRows" :key="row.traveler_type + '-' + row.traveler_id" class="flex flex-wrap items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <Checkbox v-model:checked="row.keep" />
                        <span class="font-medium">{{ row.name }}</span>
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ row.function }}</span>
                    </label>
                    <div class="flex justify-end gap-2">
                        <SecondaryButton type="button" @click="closeTeam">{{ t('cancel') }}</SecondaryButton>
                        <PrimaryButton type="button" :disabled="!teamRows.some((r) => r.keep)" @click="addTeam">{{ t('mis.add_ticked') }}</PrimaryButton>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="isEdit ? route('missions.show', order.id) : route('missions.index')"><SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton></Link>
            <PrimaryButton :disabled="form.processing">{{ isEdit ? t('save_changes') : t('save') }}</PrimaryButton>
        </div>
    </form>
</template>
```

- [ ] **Step 7: Create and Edit pages**

Create `resources/js/Pages/Missions/Create.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MissionOrderForm from './Partials/MissionOrderForm.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({ users: Array, boardMembers: Array, players: Array, teams: Array });
</script>

<template>
    <Head :title="t('mis.new')" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('mis.new') }}</h1></template>
        <MissionOrderForm :users="users" :board-members="boardMembers" :players="players" :teams="teams" />
    </AuthenticatedLayout>
</template>
```

Create `resources/js/Pages/Missions/Edit.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MissionOrderForm from './Partials/MissionOrderForm.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({ order: Object, users: Array, boardMembers: Array, players: Array, teams: Array });
</script>

<template>
    <Head :title="t('mis.title') + ' — ' + (order.destination || '')" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('mis.title') }} — {{ order.destination }}</h1></template>
        <MissionOrderForm :order="order" :users="users" :board-members="boardMembers" :players="players" :teams="teams" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 8: Show page**

Create `resources/js/Pages/Missions/Show.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import MissionPrintButton from '@/Components/MissionPrintButton.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormatDate } from '@/Composables/useFormatDate';

const { t } = useI18n();
const { formatDate } = useFormatDate();
const props = defineProps({ order: Object, reference: String, can: Object });

const STATUS_COLOR = { active: 'emerald', cancelled: 'rose' };

// The reference a print on this page took, until the next reload. Once it exists, every button prints straight away.
const currentReference = ref(props.reference);
const registerUrl = computed(() => (props.can.register && !currentReference.value ? route('missions.register', props.order.id) : null));
const taken = (reference) => { currentReference.value = reference; };

const rows = computed(() => [
    { label: t('reference'), value: currentReference.value, ltr: true },
    { label: t('mis.order_date'), value: props.order.order_date ? formatDate(props.order.order_date) : null, ltr: true },
    { label: t('mis.destination'), value: props.order.destination },
    { label: t('mis.transport'), value: props.order.transport },
    { label: t('mis.departure_date'), value: props.order.departure_date ? formatDate(props.order.departure_date) : null, ltr: true },
    { label: t('mis.return_date'), value: props.order.return_date ? formatDate(props.order.return_date) : null, ltr: true },
    { label: t('mis.reason'), value: props.order.reason, wide: true, multiline: true },
].filter((row) => row.value));

const hint = computed(() => {
    if (props.order.status === 'cancelled') return { text: t('mis.cancelled_hint'), tone: 'rose' };
    if (props.order.is_legacy) return { text: t('mis.legacy_hint'), tone: 'amber' };
    if (!currentReference.value) return { text: t('mis.reference_hint'), tone: 'slate' };
    return null;
});
const HINT_CLASS = {
    rose: 'bg-rose-50 text-rose-800 dark:bg-rose-500/10 dark:text-rose-200',
    amber: 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200',
    slate: 'bg-slate-50 text-slate-700 dark:bg-slate-800/60 dark:text-slate-300',
};
const kindLabel = (type) => t('mis.kind.' + (type ?? 'typed'));

// The confirmation mounts on the first click; Modal opens from a watch on `show`, so show flips a tick later.
const cancelMounted = ref(false);
const cancelOpen = ref(false);
async function askCancel() {
    cancelMounted.value = true;
    await nextTick();
    cancelOpen.value = true;
}
const cancel = () => router.post(route('missions.cancel', props.order.id), {}, {
    preserveScroll: true, onFinish: () => { cancelOpen.value = false; },
});
</script>

<template>
    <Head :title="t('mis.title') + (currentReference ? ' ' + currentReference : '')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('mis.title') }} <bdi v-if="currentReference">{{ currentReference }}</bdi></h1>
                    <Badge :label="t('mis.status.' + order.status)" :color="STATUS_COLOR[order.status]" />
                    <Badge v-if="order.is_legacy" :label="t('po.legacy')" color="slate" />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <MissionPrintButton :href="route('missions.print', order.id)" :register-url="registerUrl" :label="t('mis.print_all')" @registered="taken" />
                    <IconButton v-if="can.edit" :href="route('missions.edit', order.id)" icon="pencil" :label="t('edit')" />
                    <IconButton v-if="can.cancel" icon="xcircle" :label="t('mis.cancel_order')" variant="danger" @click="askCancel" />
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-6">
            <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div v-for="row in rows" :key="row.label" :class="row.wide && 'sm:col-span-2'">
                        <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ row.label }}</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100" :class="row.multiline && 'whitespace-pre-line'"><bdi :dir="row.ltr ? 'ltr' : undefined">{{ row.value }}</bdi></dd>
                    </div>
                </dl>
                <p v-if="hint" class="mt-5 rounded-lg p-3 text-xs" :class="HINT_CLASS[hint.tone]">{{ hint.text }}</p>
            </div>

            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="px-6 pt-5 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('mis.travelers') }}</h2>
                <table class="mt-3 min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3 text-start">#</th>
                            <th class="px-4 py-3 text-start">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('mis.function_label') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="(traveler, i) in order.travelers" :key="traveler.id">
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ i + 1 }}</td>
                            <td class="px-4 py-3 text-slate-900 dark:text-slate-100">
                                {{ traveler.name }}
                                <span class="ms-1 rounded bg-slate-100 px-1 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ kindLabel(traveler.traveler_type) }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ traveler.function || '—' }}</td>
                            <td class="px-4 py-3 text-end">
                                <MissionPrintButton :href="route('missions.print-traveler', [order.id, traveler.id])" :register-url="registerUrl"
                                    :label="t('mis.print_one')" size="sm" plain @registered="taken" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Modal v-if="cancelMounted" :show="cancelOpen" max-width="md" @close="cancelOpen = false">
            <div class="space-y-4 p-6">
                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('mis.cancel_order') }}</h3>
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('mis.cancel_confirm') }}</p>
                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" @click="cancelOpen = false">{{ t('back') }}</SecondaryButton>
                    <DangerButton type="button" @click="cancel">{{ t('mis.cancel_order') }}</DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 9: Page translations**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p4-t7-keys.json`:

```json
{
    "mis.new": { "ar": "أمر بمهمة جديد", "fr": "Nouvel ordre de mission", "en": "New mission order" },
    "mis.find_placeholder": { "ar": "بحث: الوجهة، المسافر، المرجع…", "fr": "Rechercher : destination, voyageur, référence…", "en": "Search: destination, traveler, reference…" },
    "mis.all_statuses": { "ar": "كل الحالات", "fr": "Tous les statuts", "en": "All statuses" },
    "mis.status.active": { "ar": "ساري", "fr": "Actif", "en": "Active" },
    "mis.status.cancelled": { "ar": "ملغى", "fr": "Annulé", "en": "Cancelled" },
    "mis.order_date": { "ar": "تاريخ الأمر", "fr": "Date de l'ordre", "en": "Order date" },
    "mis.destination": { "ar": "الوجهة", "fr": "Destination", "en": "Destination" },
    "mis.reason": { "ar": "سبب المهمة", "fr": "Motif", "en": "Purpose" },
    "mis.departure_date": { "ar": "تاريخ الذهاب", "fr": "Date de départ", "en": "Departure date" },
    "mis.return_date": { "ar": "تاريخ العودة", "fr": "Date de retour", "en": "Return date" },
    "mis.transport": { "ar": "وسيلة النقل", "fr": "Moyen de transport", "en": "Means of transport" },
    "mis.trip": { "ar": "الذهاب – العودة", "fr": "Aller – retour", "en": "Out – back" },
    "mis.travelers": { "ar": "المسافرون", "fr": "Voyageurs", "en": "Travelers" },
    "mis.function_label": { "ar": "الوظيفة", "fr": "Fonction", "en": "Function" },
    "mis.kind.user": { "ar": "مستخدم", "fr": "Utilisateur", "en": "User" },
    "mis.kind.board_member": { "ar": "عضو في المجلس", "fr": "Membre du bureau", "en": "Board member" },
    "mis.kind.player": { "ar": "لاعب", "fr": "Joueur", "en": "Player" },
    "mis.kind.typed": { "ar": "اسم يُكتب", "fr": "Nom saisi", "en": "Typed name" },
    "mis.add_person": { "ar": "إضافة مسافر", "fr": "Ajouter un voyageur", "en": "Add a traveler" },
    "mis.choose_person": { "ar": "اختر شخصًا…", "fr": "Choisir une personne…", "en": "Choose a person…" },
    "mis.add_typed": { "ar": "إضافة اسم", "fr": "Ajouter un nom", "en": "Add a name" },
    "mis.add_team": { "ar": "إضافة فريق", "fr": "Ajouter une équipe", "en": "Add a team" },
    "mis.choose_team": { "ar": "اختر فريقًا…", "fr": "Choisir une équipe…", "en": "Choose a team…" },
    "mis.team_hint": { "ar": "لاعبو الفريق ومدربوه لهذا الموسم: أزل العلامة عمن لا يسافر.", "fr": "Joueurs et entraîneurs de l'équipe cette saison : décochez ceux qui ne partent pas.", "en": "The team's players and coaches this season: untick who stays home." },
    "mis.add_ticked": { "ar": "إضافة المحددين", "fr": "Ajouter les personnes cochées", "en": "Add the ticked people" },
    "mis.team_empty": { "ar": "لا أحد لإضافته.", "fr": "Personne à ajouter.", "en": "Nobody to add." },
    "mis.team_failed": { "ar": "تعذّر تحميل الفريق.", "fr": "L'équipe n'a pas pu être chargée.", "en": "The team could not be loaded." },
    "mis.no_travelers": { "ar": "أضف مسافرًا واحدًا على الأقل.", "fr": "Ajoutez au moins un voyageur.", "en": "Add at least one traveler." },
    "mis.print_all": { "ar": "طباعة كل الأوامر", "fr": "Imprimer tous les ordres", "en": "Print every sheet" },
    "mis.print_one": { "ar": "طباعة أمر هذا المسافر", "fr": "Imprimer son ordre", "en": "Print this traveler's sheet" },
    "mis.register_failed": { "ar": "تعذّر أخذ المرجع من سجل البريد.", "fr": "La référence n'a pas pu être prise au registre du courrier.", "en": "The mail-register reference could not be taken." },
    "mis.reference_hint": { "ar": "يأخذ الأمر مرجعه من سجل البريد عند أول طباعة.", "fr": "L'ordre prend sa référence au registre du courrier à la première impression.", "en": "The order takes its mail-register reference at its first print." },
    "mis.cancel_order": { "ar": "إلغاء الأمر", "fr": "Annuler l'ordre", "en": "Cancel the order" },
    "mis.cancel_confirm": { "ar": "يبقى الأمر ومرجعه في السجل ولا يُحذف شيء. هل تريد الإلغاء؟", "fr": "L'ordre et sa référence restent au registre, rien n'est supprimé. L'annuler ?", "en": "The order and its reference stay in the register; nothing is deleted. Cancel it?" },
    "mis.cancelled_hint": { "ar": "أمر ملغى: للاطلاع فقط.", "fr": "Ordre annulé : lecture seule.", "en": "Cancelled order: read-only." },
    "mis.legacy_hint": { "ar": "مستورد من البرنامج القديم: للاطلاع فقط.", "fr": "Importé de l'ancien logiciel : lecture seule.", "en": "Imported from the old software: read-only." }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p4-t7-keys.json`
Expected: `✓ 36 key(s) written to ar/en/fr`

- [ ] **Step 10: Build, check and run the tests**

Run: `npm run build`
Expected: build succeeds (the new pages are in the manifest).
Run: `npm run i18n:check`
Expected: PASS (every literal `t('…')` of the new files exists in ar, fr and en; `mis.kind.*` and `mis.status.*` are listed as built at runtime).
Run: `npm run test:js`
Expected: PASS.
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MissionPagesTest.php`
Expected: PASS (2 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailPagesTest.php`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add resources/js/Pages/Missions/Index.vue resources/js/Pages/Missions/Create.vue resources/js/Pages/Missions/Edit.vue resources/js/Pages/Missions/Show.vue resources/js/Pages/Missions/Partials/MissionOrderForm.vue resources/js/Components/MissionPrintButton.vue resources/js/Composables/useNavigation.js tests/Feature/MissionPagesTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(missions): Secretariat → Mission orders pages, add-a-team, print button that takes the reference

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 8: Import of the old Access mission orders

**Files:**
- Modify: `tests/Fixtures/legacy-access.json` (end of file: new `missions` section — **fake rows only**)
- Modify: `app/Services/Legacy/LegacyAccessImporter.php` (imports, `import()`, new `missions()` + `textDay()`)
- Modify: `scripts/legacy/export-access.ps1` (helper `Get-DayText`, optional `missions` section; keep the UTF-8 BOM)
- Modify (test): `tests/Feature/LegacyAccessImportTest.php` (import line, four tests at the end)
- Modify: `resources/js/i18n/*.json` (via script)

**Interfaces:**
- Consumes: `MissionOrder` (`LEGACY_SOURCE`, `ACTIVE`, `isEditable()`), `MissionOrderTraveler` (Task 2), `MissionOrders::reference()` (Task 3), the importer's private helpers `plain()`, `text()` (P1).
- Produces: export section `missions` = list of `{reference, date, lastname, firstname, function, destination, reason, departure_date, return_date, transport}` (Access table `Ordre de Mission`: columns `Réf`, `Date`, `Nom`, `Prénom`, `Fonction`, `Départ Vers`, `السبب`, `Date Départ`, `Date Retour`, `Moyen Transport`; `N°` is the Access row id and is not exported; every date column is text in Access and is exported as typed — `yyyy-MM-dd` if a cell ever is a real date); import result key `missions` = `{created, skipped}`; legacy orders `status = active`, `is_legacy = true`, `legacy_source = 'ordre_de_mission'`, `legacy_reference` = `Réf`, one typed traveler (`Nom Prénom`, or `—`; `Fonction`); **no mail entry**; activity property `missions` (created count); label key `legacy_import.section.missions`.

- [ ] **Step 1: Fixture section**

In `tests/Fixtures/legacy-access.json` replace

```json
        { "reference": "", "correspondent": "", "address": "", "date": null }
    ]
}
```

with

```json
        { "reference": "", "correspondent": "", "address": "", "date": null }
    ],
    "missions": [
        { "reference": "59", "date": "12/05/2018", "lastname": "Testeur", "firstname": "Karim", "function": "Entraîneur", "destination": "Testville", "reason": "Stage de formation", "departure_date": "13/05/2018", "return_date": "15/05/2018", "transport": "Bus" },
        { "reference": "60", "date": "2018-06-02", "lastname": "Exemple", "firstname": "Samir", "function": "Entraîneur adjoint", "destination": "Autreville", "reason": "Séminaire", "departure_date": "pas une date", "return_date": "", "transport": "Voiture" },
        { "reference": "", "date": "", "lastname": "", "firstname": "", "function": "", "destination": "", "reason": "", "departure_date": "", "return_date": "", "transport": "" }
    ]
}
```

(All three rows are invented. Never copy a row of the real `.accdb` into the fixture.)

- [ ] **Step 2: Write the failing tests**

In `tests/Feature/LegacyAccessImportTest.php` replace

```php
use App\Models\MailEntry;
use App\Models\PaymentOrder;
```

with

```php
use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\PaymentOrder;
use App\Services\Secretariat\MissionOrders;
```

and replace the end of the file

```php
        $this->assertNull($row->entry_date);
        $this->assertNull($row->correspondent);
    }
}
```

with

```php
        $this->assertNull($row->entry_date);
        $this->assertNull($row->correspondent);
    }

    #[Test]
    public function the_old_mission_orders_are_imported_as_read_only_history(): void
    {
        $result = app(LegacyAccessImporter::class)->import($this->data(), User::factory()->admin()->create());

        $this->assertSame(['created' => 2, 'skipped' => 1], $result['missions']);

        $first = MissionOrder::where('legacy_reference', '59')->sole();
        $this->assertTrue($first->is_legacy);
        $this->assertSame(MissionOrder::LEGACY_SOURCE, $first->legacy_source);
        $this->assertSame(MissionOrder::ACTIVE, $first->status);
        $this->assertFalse($first->isEditable());
        // Access kept the dates as text, day first.
        $this->assertSame('2018-05-12', $first->order_date->toDateString());
        $this->assertSame('2018-05-13', $first->departure_date->toDateString());
        $this->assertSame('2018-05-15', $first->return_date->toDateString());
        $this->assertSame('Testville', $first->destination);
        $this->assertSame('Stage de formation', $first->reason);
        $this->assertSame('Bus', $first->transport);
        $traveler = $first->travelers()->sole();
        $this->assertNull($traveler->traveler_type);
        $this->assertSame('Testeur Karim', $traveler->name);
        $this->assertSame('Entraîneur', $traveler->function);
        $this->assertSame('59', app(MissionOrders::class)->reference($first));

        $second = MissionOrder::where('legacy_reference', '60')->sole();
        $this->assertSame('2018-06-02', $second->order_date->toDateString());
        $this->assertNull($second->departure_date);
        $this->assertNull($second->return_date);

        // The old letters are already in the imported mail register: no entry for the missions.
        $this->assertSame(0, MailEntry::where('source_type', MailEntry::SOURCE_MISSION)->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'legacy_data_imported']);
    }

    #[Test]
    public function importing_the_mission_orders_twice_changes_nothing(): void
    {
        $importer = app(LegacyAccessImporter::class);
        $admin = User::factory()->admin()->create();
        $importer->import($this->data(), $admin);

        $second = $importer->import($this->data(), $admin);

        $this->assertSame(['created' => 0, 'skipped' => 3], $second['missions']);
        $this->assertSame(2, MissionOrder::count());
    }

    #[Test]
    public function a_file_without_mission_orders_still_imports(): void
    {
        $data = $this->data();
        unset($data['missions']);

        $result = app(LegacyAccessImporter::class)->import($data, User::factory()->admin()->create());

        $this->assertSame(['created' => 0, 'skipped' => 0], $result['missions']);
        $this->assertSame(['created' => 3, 'skipped' => 1], $result['mail']);
        $this->assertSame(0, MissionOrder::count());
    }

    #[Test]
    public function odd_mission_rows_are_read_defensively(): void
    {
        $result = app(LegacyAccessImporter::class)->import([
            'format' => 'access-legacy-v1',
            'missions' => [
                'oops',
                // A reference alone still imports; an impossible date stays empty; a nameless traveler is a dash.
                ['reference' => ' 61 ', 'date' => '31/02/2018'],
                // Two-digit year and dots.
                ['reference' => '62', 'date' => '05.07.18', 'lastname' => 'Test', 'destination' => 'Testville', 'departure_date' => '2018-07-06 00:00:00'],
                ['reference' => '', 'date' => '', 'lastname' => '', 'firstname' => '', 'destination' => ''],
            ],
        ], User::factory()->admin()->create());

        $this->assertSame(['created' => 2, 'skipped' => 2], $result['missions']);
        $alone = MissionOrder::where('legacy_reference', '61')->sole();
        $this->assertNull($alone->order_date);
        $this->assertSame('—', $alone->travelers()->sole()->name);
        $dotted = MissionOrder::where('legacy_reference', '62')->sole();
        $this->assertSame('2018-07-05', $dotted->order_date->toDateString());
        $this->assertSame('2018-07-06', $dotted->departure_date->toDateString());
        $this->assertSame('Test', $dotted->travelers()->sole()->name);
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/LegacyAccessImportTest.php`
Expected: FAIL (`Undefined array key "missions"`); the 15 earlier tests still pass.

- [ ] **Step 4: Importer section**

In `app/Services/Legacy/LegacyAccessImporter.php` replace

```php
use App\Models\MailEntry;
use App\Models\PaymentOrder;
```

with

```php
use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\MissionOrderTraveler;
use App\Models\PaymentOrder;
```

replace

```php
                // Optional section (P3): the old outgoing letters.
                'mail' => $this->mail(is_array($data['mail'] ?? null) ? $data['mail'] : []),
            ];
            ActivityRecorder::record($by, ActivityAction::LEGACY_DATA_IMPORTED, null, [
                'suppliers' => $result['suppliers']['created'],
                'payment_orders' => $result['payment_orders']['created'],
                'purchase_orders' => $result['purchase_orders']['created'],
                'mail' => $result['mail']['created'],
            ]);
```

with

```php
                // Optional section (P3): the old outgoing letters.
                'mail' => $this->mail(is_array($data['mail'] ?? null) ? $data['mail'] : []),
                // Optional section (P4): the old mission orders.
                'missions' => $this->missions(is_array($data['missions'] ?? null) ? $data['missions'] : []),
            ];
            ActivityRecorder::record($by, ActivityAction::LEGACY_DATA_IMPORTED, null, [
                'suppliers' => $result['suppliers']['created'],
                'payment_orders' => $result['payment_orders']['created'],
                'purchase_orders' => $result['purchase_orders']['created'],
                'mail' => $result['mail']['created'],
                'missions' => $result['missions']['created'],
            ]);
```

and replace

```php
    /** "2019/07" → 2019; anything else → null. */
```

with

```php
    /**
     * Old `Ordre de Mission` rows → legacy mission orders: read-only, the old
     * reference text kept, one typed traveler (Nom + Prénom, Fonction). No
     * mail entry: the old letters are already in the imported mail register.
     * Access kept every date as text: read day first (or Y-m-d); an
     * unreadable date stays empty. A row is matched on (source, reference,
     * order date), so importing again creates nothing.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function missions(array $rows): array
    {
        $created = $skipped = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;

                continue;
            }
            $reference = self::text(preg_replace('/\s+/u', ' ', self::plain($row['reference'] ?? null)), 50);
            $date = self::textDay($row['date'] ?? null);
            $name = self::text(preg_replace('/\s+/u', ' ', self::plain($row['lastname'] ?? null).' '.self::plain($row['firstname'] ?? null)), 200);
            $destination = self::text($row['destination'] ?? null, 200);
            if ($reference === null && $name === null && $destination === null) {
                $skipped++;

                continue;
            }

            $exists = MissionOrder::where('is_legacy', true)->where('legacy_source', MissionOrder::LEGACY_SOURCE)
                ->when($reference !== null, fn ($q) => $q->where('legacy_reference', $reference), fn ($q) => $q->whereNull('legacy_reference'))
                ->when($date !== null, fn ($q) => $q->whereDate('order_date', $date), fn ($q) => $q->whereNull('order_date'))
                ->exists();
            if ($exists) {
                $skipped++;

                continue;
            }

            $order = MissionOrder::create([
                'order_date' => $date,
                'destination' => $destination,
                'reason' => self::text($row['reason'] ?? null, 1000),
                'departure_date' => self::textDay($row['departure_date'] ?? null),
                'return_date' => self::textDay($row['return_date'] ?? null),
                'transport' => self::text($row['transport'] ?? null, 100),
                'status' => MissionOrder::ACTIVE,
                'is_legacy' => true,
                'legacy_reference' => $reference,
                'legacy_source' => MissionOrder::LEGACY_SOURCE,
            ]);
            MissionOrderTraveler::create([
                'mission_order_id' => $order->id,
                'traveler_type' => null,
                'traveler_id' => null,
                'name' => $name ?? '—',
                'function' => self::text($row['function'] ?? null, 200),
                'sort_order' => 1,
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * A day typed as text in Access: "15/03/2018", "15-03-2018", "15.03.18"
     * (day first, as the club wrote them) or "2018-03-15" (with or without a
     * time). An impossible or unreadable date → null. Carbon::parse() is not
     * used: it reads "05/07/2018" month first.
     */
    private static function textDay(mixed $value): ?string
    {
        $text = trim(self::plain($value));
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)/', $text, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4}|\d{2})(?!\d)#', $text, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $year += $year < 100 ? 2000 : 0;
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /** "2019/07" → 2019; anything else → null. */
```

- [ ] **Step 5: Export script section**

`scripts/legacy/export-access.ps1` starts with a UTF-8 BOM (PowerShell 5.1 needs it to read the accented and Arabic column names) — edit it in place and keep it. In `scripts/legacy/export-access.ps1` replace

```powershell
function Get-Amount($value) { if ($value -is [DBNull] -or $null -eq $value) { 0 } else { [double]$value } }
```

with

```powershell
function Get-Amount($value) { if ($value -is [DBNull] -or $null -eq $value) { 0 } else { [double]$value } }
# Dates the old app kept as text: exported as typed (the importer reads them day first); a real date as yyyy-MM-dd.
function Get-DayText($value) { if ($value -is [DateTime]) { $value.ToString('yyyy-MM-dd') } else { Get-Text $value } }
```

replace

```powershell
$connection.Close()
```

with

```powershell
# Optional: the old mission orders (Ordre de Mission), one traveler per row.
# Every column but N° is text in Access, dates included. The N° column is the
# Access row id and is not exported. A database without the table still exports.
$missions = New-Object System.Collections.ArrayList
try {
    $missionRows = (Read-Table 'SELECT * FROM [Ordre de Mission]').Rows
} catch {
    $missionRows = @()
    Write-Warning 'No [Ordre de Mission] table: missions skipped'
}
foreach ($row in $missionRows) {
    [void]$missions.Add([ordered]@{
        'reference' = (Get-Text $row['Réf']); 'date' = (Get-DayText $row['Date'])
        'lastname' = (Get-Text $row['Nom']); 'firstname' = (Get-Text $row['Prénom']); 'function' = (Get-Text $row['Fonction'])
        'destination' = (Get-Text $row['Départ Vers']); 'reason' = (Get-Text $row['السبب'])
        'departure_date' = (Get-DayText $row['Date Départ']); 'return_date' = (Get-DayText $row['Date Retour'])
        'transport' = (Get-Text $row['Moyen Transport'])
    })
}

$connection.Close()
```

(The keys are quoted because `function` is a PowerShell keyword.)

and replace

```powershell
    mail = @($mail)
}
$json = $document | ConvertTo-Json -Depth 6
[System.IO.File]::WriteAllText($Out, $json, (New-Object System.Text.UTF8Encoding($false)))
Write-Output ("Wrote {0} suppliers, {1} payment orders, {2} purchase orders, {3} letters to {4}" -f $suppliers.Count, $orders.Count, $purchaseOrders.Count, $mail.Count, $Out)
```

with

```powershell
    mail = @($mail)
    missions = @($missions)
}
$json = $document | ConvertTo-Json -Depth 6
[System.IO.File]::WriteAllText($Out, $json, (New-Object System.Text.UTF8Encoding($false)))
Write-Output ("Wrote {0} suppliers, {1} payment orders, {2} purchase orders, {3} letters, {4} mission orders to {5}" -f $suppliers.Count, $orders.Count, $purchaseOrders.Count, $mail.Count, $missions.Count, $Out)
```

Check the BOM is still there and the script still parses (no database needed):

Run: `head -c 3 scripts/legacy/export-access.ps1 | od -An -tx1`
Expected: ` ef bb bf`

Run (from Git Bash — the single quotes keep `$` away from bash):

```bash
powershell -NoProfile -Command '$errors = $null; [void][System.Management.Automation.Language.Parser]::ParseFile((Resolve-Path "scripts/legacy/export-access.ps1"), [ref]$null, [ref]$errors); if ($errors) { $errors; exit 1 } else { "parse ok" }'
```

Expected: `parse ok`

- [ ] **Step 6: Result label**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p4-t8-keys.json`:

```json
{
    "legacy_import.section.missions": { "ar": "أوامر المهمة", "fr": "Ordres de mission", "en": "Mission orders" }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p4-t8-keys.json`
Expected: `✓ 1 key(s) written to ar/en/fr`

(`resources/js/Pages/LegacyImport/Index.vue` already loops over every result section with `t('legacy_import.section.' + section)` — no page change.)

- [ ] **Step 7: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/LegacyAccessImportTest.php`
Expected: PASS (19 tests: 15 from P1–P3 + 4 new).
Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add scripts/legacy/export-access.ps1 app/Services/Legacy/LegacyAccessImporter.php tests/Fixtures/legacy-access.json tests/Feature/LegacyAccessImportTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(legacy): import the old Access mission orders (optional section)

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

- [ ] **Step 3: Migrations and import on a copy of the dev database**

The table migration only creates `mission_orders` and `mission_order_travelers` (no rebuild of an existing table). Work on a copy, never on `D:\irnb-laravel\database\database.sqlite` itself (stop `php artisan serve` first if it holds the file):

```bash
COPY="C:/Users/MUSTAPHA/AppData/Local/Temp/p4-dev-copy.sqlite"
cp D:/irnb-laravel/database/database.sqlite "$COPY"
DB_CONNECTION=sqlite DB_DATABASE="$COPY" php artisan migrate --force
COPY="$COPY" php -r '$p = new PDO("sqlite:".getenv("COPY")); echo $p->query("select count(*) from mission_orders")->fetchColumn(), PHP_EOL; foreach ($p->query("select permissions from roles where is_system = 1") as $r) { echo (str_contains($r[0], "\"missions\"") ? "missions granted" : "MISSING"), PHP_EOL; }'
DB_CONNECTION=sqlite DB_DATABASE="$COPY" php artisan migrate --force
```

Expected: the first `migrate` runs `2026_11_27_100000_grant_mission_permissions` and `2026_11_27_100001_create_mission_orders_tables` without error; the count prints `0`; every system role prints `missions granted`; the second `migrate` prints `Nothing to migrate.`

Then import the fake fixture twice into the copy (the real export holds names and is imported by the owner from Settings, see hand-over):

```bash
DB_CONNECTION=sqlite DB_DATABASE="C:/Users/MUSTAPHA/AppData/Local/Temp/p4-dev-copy.sqlite" php artisan tinker --execute '
$admin = App\Models\User::query()->where("is_active", true)->orderBy("id")->firstOrFail();
$data = json_decode(file_get_contents(base_path("tests/Fixtures/legacy-access.json")), true);
$importer = app(App\Services\Legacy\LegacyAccessImporter::class);
echo json_encode($importer->import($data, $admin)["missions"]), PHP_EOL;
echo json_encode($importer->import($data, $admin)["missions"]), PHP_EOL;'
```

Expected: `{"created":2,"skipped":1}` then `{"created":0,"skipped":3}`.

- [ ] **Step 4: Look at the prints (mPDF RTL, page breaks)**

Render an Arabic and a French three-traveler PDF against the migrated copy:

```bash
DB_CONNECTION=sqlite DB_DATABASE="C:/Users/MUSTAPHA/AppData/Local/Temp/p4-dev-copy.sqlite" php artisan tinker --execute '
foreach (["ar", "fr"] as $locale) {
    app()->setLocale($locale);
    $order = new App\Models\MissionOrder(["order_date" => "2026-11-26", "status" => "active",
        "destination" => $locale === "ar" ? "وهران" : "Oran", "transport" => $locale === "ar" ? "حافلة النادي" : "Bus du club",
        "reason" => $locale === "ar" ? "تربص تكويني للمدربين\nStage FAF niveau 1" : "Stage de formation des entraîneurs\nNiveau 1",
        "departure_date" => "2026-12-01", "return_date" => "2026-12-03"]);
    $travelers = collect([["مدرب تجريبي", "مدرب"], ["Coach Test", "Entraîneur adjoint"], ["لاعب تجريبي", "لاعب — U17"]])
        ->map(fn ($t, $i) => new App\Models\MissionOrderTraveler(["name" => $t[0], "function" => $t[1], "sort_order" => $i + 1]));
    $html = view("pdf.mission-order", ["club" => App\Services\Pdf\ClubHeader::data(), "rtl" => $locale === "ar", "order" => $order,
        "travelers" => $travelers, "reference" => "2026/07", "signatories" => App\Services\Pdf\BoardSignatories::names()])->render();
    $file = sys_get_temp_dir()."/p4-mission-".$locale.".pdf";
    file_put_contents($file, app(App\Services\Pdf\PdfService::class)->render($html, $locale === "ar"));
    echo $file, PHP_EOL;
}'
```

Expected: two file paths printed. Open both: **three pages each**, one traveler per page, each page with the letterhead, the title («أمر بمهمة» / «Ordre de mission»), `2026/07` and `26/11/2026` reading left-to-right, the seven fields, the dates `01/12/2026` / `03/12/2026` left-to-right, the Latin line `Stage FAF niveau 1` left-to-right on its own line in the Arabic reason, the Latin name `Coach Test` left-to-right, and the «الكاتب العام / Secrétaire général» and «الرئيس / Président» columns with a line to sign at the bottom of every page (never split across pages). Then in the browser (Step 5) print a cancelled order (red «أمر ملغى» banner) and one traveler's sheet. Fix any defect in `resources/views/pdf/mission-order.blade.php` (commit `fix(missions): …`) and re-run Step 2.

- [ ] **Step 5: Browser QA (owner or Claude with the dev server)**

Serve the worktree against the copy (`DB_CONNECTION=sqlite DB_DATABASE="C:/Users/MUSTAPHA/AppData/Local/Temp/p4-dev-copy.sqlite" php artisan serve --no-reload --port=2027`) and check, in Arabic and French, at full width and at 320 px, light and dark:
- Sidebar shows **الأمانة / Secrétariat → أوامر المهمة / Ordres de mission** under the mail register; Roles shows the mission orders row in the Secretariat group.
- New order: add a user (function «مدرب» / «Entraîneur»), a board member (their role), a player («لاعب — U15»), a typed name; the same person cannot be added twice; "Add a team" lists the coaches then the players of this season, all ticked — untick one, add, check the list; edit names and functions; save without travelers is refused.
- Show page: «print every sheet» opens one tab (no pop-up warning) with N pages and takes `2026/NN` — the header and the mail register now show it; a traveler's print button gives one page with the same reference; reprints never take a new number (check the mail register: one entry, source «أمر بمهمة» linking back to the order). Edit after printing keeps the reference. Cancel: the order turns read-only with its reference, the mail entry is cancelled with its number kept. A view-only user prints directly (no number taken when the order has none).
- List: year and status filters, search by destination, by traveler name and by `2026/NN`; an imported order shows `59` with the «قديم / Ancien» tag and no edit / cancel.

- [ ] **Step 6: Review the branch before handing over**

Run: `git log --oneline main..feat/mission-orders`
Expected: the 8 task commits (plus any fix commits), nothing else.
Run: `git status --short`
Expected: clean (no unstaged or untracked project file left behind).

Hand-over notes for the owner (no server deploy — the app runs locally and on the desktop): run `php artisan migrate` on the web PC's database; the desktop build picks up the migrations on its next version; Claude re-exports the old data with the updated script (`powershell -NoProfile -ExecutionPolicy Bypass -File scripts/legacy/export-access.ps1 -Database "D:\irnb-laravel\Sauvegarde de Sauvegarde de IRNB 2021 FOOT BALL.accdb" -Out "D:\BACKUPS\irnb-access-legacy.json"` — the output holds names: never commit it), then the owner imports it again from Settings → Old software import (existing rows are skipped, the 2 mission orders are added). Help Center articles for mission orders are not part of this plan.

---

## Spec coverage (P4)

| Spec rule | Task |
|---|---|
| `mission_orders`: order_date, destination, reason, departure / return (dates only), transport, status active / cancelled + cancelled_by / at, is_legacy, legacy_reference, legacy_source, created_by, lock_version | 2 |
| `mission_order_travelers`: mission_order_id (cascade), traveler_type user / board_member / player / null, traveler_id, name + function snapshots (editable), sort_order | 2, 3 |
| No number of its own: reference = active mail entry source `mission_order` / id / key `order` via `MailRegister::find`; legacy shows `legacy_reference` | 2, 3 |
| Create / edit active non-legacy orders, also after printing; reprint keeps the reference | 3, 5, 7 |
| Cancel keeps the order and cancels its active mail entry in the same transaction (number stays); never deleted (no destroy route); legacy read-only | 3, 5, 7 |
| Atomic claims with `lock_version` bump (MySQL changed-rows rule) | 3 |
| Travelers ≥ 1; user / board member / player / typed; defaults user → coach label, board member → role label, player → «لاعب — {category}»; editable | 3, 4, 5, 7 |
| "Add a team": players of the current season (`TeamMembers`) + its coaches, untick before saving, no duplicate (type + id); rights checked | 4, 5, 7 |
| Print takes the reference: Print buttons (all sheets, one traveler) POST `missions.register` (missions edit) → `MailRegister::register('mission_order', id, 'order', defaults)`, correspondent = first three names then «+N», subject = «أمر بمهمة — {destination}» localized; then the PDF opens | 3, 5, 7 |
| PDF GET routes only look up; view-only users print directly (reference shown when one exists); cancelled and legacy orders print without registering | 3, 6, 7 |
| Mail register shows the mission order as an entry's source (link with missions view); `MailSources` gains the type | 2, 3, 5 |
| One A4 sheet per traveler, all in one PDF (`missions.print`) or one traveler (`missions.print-traveler`); letterhead, title, reference, date, name, function, destination, reason, departure and return dates, transport; President + Secretary General (`BoardSignatories`); page break between sheets; mPDF rules (RTL, `PdfText`, tables, bdo) | 6, 9 |
| Secretariat → Mission orders: list (year of order date, search destination / traveler / reference, status), create / edit / show, cancel, print buttons | 5, 7 |
| Module `missions` in `secretariat`, view / add / edit / delete (delete = cancel); print → view; register → edit; grant migration system roles only, idempotent | 1 |
| No money link; no extra fields | 2 (no such columns) |
| Activity log: create / update / cancel / print codes, ids and references only | 3, 6 |
| Import: `Ordre de Mission` → legacy orders (refs 59, 60), one typed traveler (Nom + Prénom, Fonction); no mail entries; optional export section (format id unchanged); idempotent by (legacy_source, legacy_reference, order date); text dates parsed defensively | 8 |
| Tests: reference reused on reprint, cancel keeps the number, permission grants, status transitions and forbidden ones, PDF smoke tests ar + fr, import twice → same counts; i18n check, build, full suite | 1-9 |

## Decisions this plan made where the spec was silent

- **Required fields:** order date, destination, departure date and ≥ 1 traveler; reason, return date (≥ departure) and transport optional. Imported orders may lack any of them.
- **Print title:** in the app language only (`mis.pdf.title`), like every other print (owner rule "print language follows the app language"); the spec's «أمر بمهمة / Ordre de mission» names the ar and fr labels.
- **Cancelled sheet:** carries a red «أمر ملغى / ORDRE ANNULÉ» banner (same idea as P1's DRAFT banner), so a cancelled order printed for the archive is never mistaken for a valid one.
- **Cancelled order's reference:** the mail entry cancelled with it (owner: "cancel keeps it"); an active order whose entry was cancelled from the mail register shows none and its next print takes a new number (P3 rule).
- **"Add a team":** current season = `Season::current()` (spec "current season"); players who left the team's sport are left out; needs `teams` view **and** `players` view.
- **Separate print button component** (`MissionPrintButton`, no dialog) rather than a mode of `MailPrintButton` — see File Structure.
- **`mail.sources.*` untouched:** missions never use the tick box, so their type is not added to `URL_TYPES` / `PRINT_MODULES` / the route's `whereIn`; it is added where the register *shows* sources (`LINK_MODULES`, `url()`, `describe()`, `mreg.source.mission_order`).
