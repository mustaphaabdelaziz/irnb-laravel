# Round 2 · P3 Player Documents — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the P3 package of `docs/superpowers/specs/2026-09-22-post-testing-round-2-design.md`, as amended by its "Owner decisions (2026-09-23, before planning)":
- #13 manageable document types (Settings → Document types), seeded defaults, age limit instead of category limit
- #11 per-player documents: mark received / renew / exempt, several files per document, phone camera, private storage
- #12 the checklist on the player page (with "Expires soon"), and a missing-documents column + filters on the players list
- security found while mapping: board-meeting minutes move behind login onto the private disk
- carried from P2: a "Sans wilaya" option in the players-list wilaya filter

**Architecture:**
- **One definition of "missing", in two forms.** `App\Services\Player\DocumentChecklist` computes each document's state in PHP for the player page, and builds the SQL twin (a sum of `CASE WHEN … NOT EXISTS (…)` terms, one per required active type) for the players list. A dedicated test runs both over the same fixture players and asserts they agree.
- **Half-open date comparisons everywhere.** SQLite stores Eloquent `date` columns as `Y-m-d 00:00:00` text. Every SQL comparison against a date is written as `col >= 'Y-m-d'` or `col < 'Y-m-d'` (never `<=` / `>`), which is correct for both that text form and MySQL `date` columns. The PHP side uses the same boundaries (`lt($today)`, `lt($today + 31 days)`, `gte($earliestBirthdate)`).
- **Private files never touch `/media`.** A small `App\Services\Storage\PrivateFileStorage` wraps the `local` disk (`storage/app/private`). Player documents and board minutes are stored there and served only through authenticated, permission-checked routes.
- **Reference data lives in migrations** (desktop runs `migrate` on boot, never seeders): the seven default document types and the `documents` permission for the admin roles.
- **Backups carry named private roots** (`player-documents`, `minutes`), each staged, checked and swapped with `rename()` exactly like the media tree.

**Tech Stack:** PHP 8.x, Laravel 13, Inertia v2 + Vue 3 `<script setup>`, vue-i18n (flat keys), Tailwind, PHPUnit 12, SQLite (web also MySQL), NativePHP desktop.

## Global Constraints

**Branch**
- Work on `feat/round2-p3-player-documents` (already created from `main` @ `548172e`, spec commit `49cfcf8` on top). Do not create another branch.

**Committing**
- Stage **explicit file paths only**. Never stage a directory, never `git add -A` / `git add .` (the working tree holds unrelated uncommitted work, e.g. `.claude/`). After each commit run `git show --stat HEAD` and check the file list.

**Spec rules that apply to every task (from "Cross-cutting rules")**
- **Identifiers, never names.** Relations use ids. A display name is never used to find a record. Document types are recognised by `code` (the Photo rule keys off `code = 'photo'`), records point at `document_type_id`.
- **Stable codes, translated labels.** The database stores `received` / `exempt`, `none` / `season` / `date`, and the checklist states; screens translate them.
- **Destructive actions** use `ConfirmModal` (not `window.confirm`).
- **Tests:** PHPUnit class style with `#[Test]` and `RefreshDatabase`. Every feature covers its normal flow and its edge cases.

**Behaviour changes that break existing tests on purpose**
- `tests/Unit/RoleModelTest.php::all_permissions_covers_every_module_and_action` asserts **11** modules. Task 1 adds `documents` → it becomes **12** (edited in Task 1, not deleted).
- `tests/Feature/BoardCalendarTest.php::admin_can_upload_and_remove_a_minutes_file` asserts minutes land on the public disk with a `/media/minutes/` URL. Task 12 moves them to the private disk → that test is **rewritten** in Task 12.

**Tests that constrain the design — keep them green**
- `tests/Feature/ListFilterPartialReloadTest.php:63-82` pins the partial-reload `only` list for `players.index` on both sides (Vue `useListFilters(..., { only: [...] })` and the controller). The list stays exactly `['players', 'filters', 'categoryStats', 'statusStats', 'positionStats', 'ageStats']`. New filter values travel inside `filters`; the new lookup prop `documentTypes` is a closure and must stay **out** of reloads (Task 9 adds `->has('documentTypes')` / `->missing('documentTypes')` assertions to that test).
- `tests/Feature/FlashTranslationTest.php` scans every controller for `'flash.*'` strings and requires each key in all three catalogs. Every task that adds a flash key to a controller adds the key to the catalogs **in the same task**.
- `tests/Unit/Services/BackupServiceTest.php::create_throws_and_leaves_no_backup_when_a_media_file_cannot_be_added` overrides the protected `mediaFiles()`; keep that method protected and overridable.

**Permissions (RBAC)**
- Route names map to modules by longest prefix in `config/permissions.php`; the action comes from `PermissionMap::deriveAction()` (`store`/`create` → add, `index`/`show`/`export`… → view, `destroy`/`delete` → delete, anything else → edit). Exact-name `overrides` win.
- New module `documents` (view / add / edit / delete) guards every `players.documents.*` route. The players list's missing column and filters need `players/view` only. Document-type settings stay on the `categories` module, like every other lookup page.
- God admins (legacy `admin`/`superadmin` privilege) short-circuit to `Role::allPermissions()`, so adding the module to `Role::MODULES` covers them. The `superadmin` and `administrator` roles get it through a data migration (Task 1); other roles get nothing until an admin grants it.
- Server-side data is gated too: the player page's `documents` prop is `null` unless the viewer has `documents/view`.

**Desktop**
- Reference data goes in migrations, never seeders.
- No enum columns and no changes to existing enum columns (SQLite rebuilds the table). Codes are `string` columns.
- **Laravel does not wrap SQLite migrations in a transaction.** Every schema migration must be re-runnable after a partial failure: guard `Schema::create` with `Schema::hasTable`, column adds with `Schema::hasColumn`, and seed rows with "insert if the code does not exist" (see `database/migrations/2026_09_23_100006_add_locale_names_to_member_jobs.php`). Data/file migrations must be idempotent.
- New migrations are named `database/migrations/2026_09_24_1000NN_*.php` (none exist yet — check with `ls database/migrations | grep 2026_09_24`).
- `NativeAppServiceProvider::firstRunSetup()` copies only `storage/app/public` from the bundle; the private disk starts empty on a new install and needs no seeding. Task 3 excludes `storage/app/private` from the NativePHP bundle so a developer's test documents can never ship in an installer.
- After any schema change, `storage/app/seed/database.sqlite` is refreshed before the next desktop build (final task). The seed file is git-ignored — it is a local build step, never committed.

**UI text**
- Flat keys in `resources/js/i18n/{ar,en,fr}.json`, called with `t('key')`, **never `te()`** (vue-i18n's `te()` falsely reports flat dotted keys such as `flash.*` as missing).
- Keys are added ONLY with `node scripts/i18n-add.mjs <file>`; save the scratch file as `i18n-keys.tmp.json` in the repo root, run the script, then **delete the file — it is never staged**. The script **overwrites** a key that already exists, so every key below is new (all new UI keys use the `doc_` prefix, plus `documents`, `document_types`, `no_wilaya`); check with `grep -c '"<key>"' resources/js/i18n/en.json` → `0` before adding.
- vue-i18n treats `{ } @ $ |` as syntax: values may carry `{name}`-style placeholders and none of the others.
- Server-rendered text uses `App\Support\UiLang` or `__()` with entries in `lang/{ar,fr}.json` in alphabetical order. (This package adds no server-rendered text.)
- Flash messages are keys (`'flash.document_received'`), translated by the page.

**Files with a byte-order mark** — targeted `Edit`s only, never rewrite the whole file; verify afterwards with `head -c 3 <file> | od -An -tx1` → `ef bb bf`:
- `resources/js/Pages/Players/Show.vue`, `resources/js/Pages/Players/Index.vue` (touched here)
- also BOM (not touched here): `Equipment/Catalog/{Create,Edit,Show}.vue`, `Settings/General.vue`, `Subscriptions/Show.vue`, `Users/Edit.vue`
- New files (`PlayerDocumentsCard.vue`, `Settings/DocumentTypes.vue`) are written **without** a BOM.

**Tests and tooling**
- Always `php artisan config:clear` before running tests (`composer test` does it for you).
- Actor: `User::factory()->admin()->create(['email_verified_at' => now()])` (god short-circuit). For permission tests build a role: `User::factory()->create(['privileges' => ['user'], 'role_id' => Role::create([...])->id])` (the factory defaults to approved + active).
- No Player factory — build players with `Player::create([...])` including a unique `membership_id` (`file_number` stays null, which is fine; `RegisterPlayerService` is not needed for these tests).
- Read Inertia props with `->viewData('page')['props']`.
- Uploads: `Storage::fake('local')` (private disk) and `Storage::fake('public')`; `UploadedFile::fake()->create('scan.pdf', 200, 'application/pdf')`, `UploadedFile::fake()->image('photo.jpg')`.
- Freeze time with `Carbon::setTestNow('2026-10-05')` and reset it in `tearDown()`; the club season defaults to a September start (`App\Support\Season`), so a document received on 2026-10-05 with `season` validity is valid until `2027-08-31`.
- `php vendor/bin/pint <changed php files>` before each commit.
- Frontend verification is `npm run build` + `node scripts/i18n-check.mjs` (there is no JS test runner) plus the manual list in the final task.
- The suite is **722 passing** at the start of this plan.

**Composer**
- No composer change in this plan. (A `composer install`/`update` would revert the NativePHP `afterPack` vendor patch in `vendor/nativephp/desktop/resources/electron/electron-builder.mjs` that bundles the VC++ runtime DLLs — the final task still checks it is present before building.)

**Laravel facts found in passing**
- `FormData` drops empty arrays: never rely on sending `files: []`; the server treats a missing `files` as "no files".
- A multipart form cannot travel as `PUT` in PHP: post it with `_method: 'put'`.

---

## File structure

**New**

| File | Responsibility |
|---|---|
| `app/Models/DocumentType.php` | A document type: localized names, required, validity, age limit, active, order; `validUntilFor()`, `earliestApplicableBirthdate()`, `appliesTo()` |
| `app/Models/PlayerDocument.php` | One row per (player, type): `received` / `exempt`, dates, reason, notes |
| `app/Models/PlayerDocumentFile.php` | A scanned file of a document, on the private disk |
| `app/Services/Storage/PrivateFileStorage.php` | Store / delete / stream files on the private `local` disk |
| `app/Services/Player/DocumentChecklist.php` | The checklist states in PHP **and** their SQL twin for the list |
| `app/Services/Player/PlayerDocumentService.php` | Mark received, renew, exempt, undo, attach/remove files, purge a player |
| `app/Http/Controllers/DocumentTypeController.php` | Settings → Document types CRUD with in-use delete guard |
| `app/Http/Controllers/PlayerDocumentController.php` | The per-player document actions and the authenticated file routes |
| `resources/js/Pages/Settings/DocumentTypes.vue` | The settings page |
| `resources/js/Components/PlayerDocumentsCard.vue` | The Documents card on the player page |
| migrations `2026_09_24_100001`–`100004` | admin roles get `documents`; `document_types` + defaults; `player_documents` + `player_document_files`; minutes to the private disk |
| tests | `DocumentsPermissionTest`, `DocumentTypeModelTest`, `PlayerDocumentModelTest`, `DocumentTypeSettingsTest`, `DocumentChecklistTest`, `PlayerDocumentActionsTest`, `PlayerDocumentFiltersTest`, `PlayerDocumentDeletionTest`, `BoardMinutesPrivateTest`, `Unit/Services/BackupPrivateRootsTest` |

**Modified**

| Area | Files |
|---|---|
| RBAC | `app/Models/Role.php`, `config/permissions.php`, `tests/Unit/RoleModelTest.php` |
| Players | `app/Models/Player.php`, `app/Http/Controllers/PlayerController.php`, `resources/js/Pages/Players/Index.vue` (BOM), `resources/js/Pages/Players/Show.vue` (BOM) |
| Board | `app/Http/Controllers/BoardMeetingController.php`, `app/Models/BoardMeeting.php`, `tests/Feature/BoardCalendarTest.php` |
| Backup | `app/Services/Backup/BackupService.php`, `app/Providers/AppServiceProvider.php` |
| Shared | `routes/web.php`, `resources/js/Layouts/AuthenticatedLayout.vue`, `config/nativephp.php`, `resources/js/i18n/*.json`, `tests/Feature/ListFilterPartialReloadTest.php` |

---

### Task 1: The `documents` permission module

**Why:** Owner decision — documents are sensitive (medical certificates, ID copies), so they get their own module instead of riding on players/view and players/edit. Admins get it by default; everyone else only when an admin grants it.

**Files:**
- Modify: `app/Models/Role.php`
- Modify: `config/permissions.php`
- Create: `database/migrations/2026_09_24_100001_grant_documents_permission_to_admin_roles.php`
- Modify: `tests/Unit/RoleModelTest.php`
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script)
- Test: `tests/Feature/DocumentsPermissionTest.php`

**Interfaces:**
- `Role::MODULES` gains `'documents'` (right after `'players'`, so the roles matrix shows it next to Players).
- `config('permissions.modules')` gains `'players.documents' => 'documents'` and `'document-types' => 'categories'`.
- `config('permissions.overrides')` gains `'players.documents.files.download' => ['documents', 'view']`.
- Route names later tasks register, and the permission they resolve to:

| Route name | Resolves to |
|---|---|
| `players.documents.store` | documents / add |
| `players.documents.update` | documents / edit |
| `players.documents.exempt` | documents / edit |
| `players.documents.unexempt` | documents / edit |
| `players.documents.files.store` | documents / add |
| `players.documents.files.show` | documents / view |
| `players.documents.files.download` | documents / view (override) |
| `players.documents.files.destroy` | documents / delete |
| `document-types.index` / `.store` / `.update` / `.destroy` | categories / view, add, edit, delete |
| `board.meetings.attachment.show` (Task 12) | board / view (derived from `show`, no config change) |

- i18n key `documents` (the module label in the roles matrix uses `t(module)`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DocumentsPermissionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentsPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_24_100001_grant_documents_permission_to_admin_roles.php';

    #[Test]
    public function documents_is_a_permission_module_of_its_own(): void
    {
        $this->assertContains('documents', Role::MODULES);
        $this->assertSame(Role::ACTIONS, Role::allPermissions()['documents']);
    }

    #[Test]
    public function every_document_route_is_gated_by_the_documents_module(): void
    {
        $expected = [
            'players.documents.store' => ['documents', 'add'],
            'players.documents.update' => ['documents', 'edit'],
            'players.documents.exempt' => ['documents', 'edit'],
            'players.documents.unexempt' => ['documents', 'edit'],
            'players.documents.files.store' => ['documents', 'add'],
            'players.documents.files.show' => ['documents', 'view'],
            'players.documents.files.download' => ['documents', 'view'],
            'players.documents.files.destroy' => ['documents', 'delete'],
        ];

        foreach ($expected as $route => $permission) {
            $this->assertSame($permission, PermissionMap::resolve($route), $route);
        }
    }

    #[Test]
    public function the_player_routes_keep_their_own_module(): void
    {
        $this->assertSame(['players', 'view'], PermissionMap::resolve('players.show'));
        $this->assertSame(['players', 'view'], PermissionMap::resolve('players.index'));
        $this->assertSame(['players', 'add'], PermissionMap::resolve('players.transactions.store'));
    }

    #[Test]
    public function document_type_settings_ride_on_the_lookup_module(): void
    {
        $this->assertSame(['categories', 'view'], PermissionMap::resolve('document-types.index'));
        $this->assertSame(['categories', 'add'], PermissionMap::resolve('document-types.store'));
        $this->assertSame(['categories', 'edit'], PermissionMap::resolve('document-types.update'));
        $this->assertSame(['categories', 'delete'], PermissionMap::resolve('document-types.destroy'));
    }

    #[Test]
    public function a_god_admin_has_the_documents_module(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

        $this->assertTrue($admin->hasPermission('documents', 'delete'));
        $this->assertSame(Role::ACTIONS, $admin->effectivePermissions()['documents']);
    }

    #[Test]
    public function the_migration_grants_documents_to_the_admin_roles_only(): void
    {
        $administrator = Role::create([
            'key' => 'administrator',
            'name' => ['en' => 'Administrator'],
            'permissions' => ['players' => Role::ACTIONS],
            'is_system' => true,
        ]);
        $superadmin = Role::create([
            'key' => 'superadmin',
            'name' => ['en' => 'Super Admin'],
            'permissions' => ['players' => Role::ACTIONS],
            'is_system' => true,
        ]);
        $coach = Role::create([
            'key' => 'coach',
            'name' => ['en' => 'Coach'],
            'permissions' => ['players' => ['view', 'add', 'edit']],
        ]);

        $migration = require database_path(self::MIGRATION);
        $migration->up();
        // Desktop re-runs a migration whose previous run died half-way: it must be harmless.
        $migration->up();

        $this->assertSame(Role::ACTIONS, $administrator->fresh()->permissions['documents']);
        $this->assertSame(Role::ACTIONS, $superadmin->fresh()->permissions['documents']);
        $this->assertSame(Role::ACTIONS, $administrator->fresh()->permissions['players'], 'other modules are untouched');
        $this->assertArrayNotHasKey('documents', $coach->fresh()->permissions, 'non-admin roles are not granted anything');
    }

    #[Test]
    public function player_rights_do_not_include_documents(): void
    {
        $user = User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::create([
                'key' => 'coach',
                'name' => ['en' => 'Coach'],
                'permissions' => ['players' => Role::ACTIONS],
            ])->id,
        ]);

        $this->assertFalse($user->hasPermission('documents', 'view'));
        $this->assertTrue($user->hasPermission('players', 'edit'));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan config:clear; php artisan test --filter=DocumentsPermissionTest`

Expected: FAIL — `documents_is_a_permission_module_of_its_own` (`'documents'` not in the array), the route mapping tests (`players.documents.*` resolves to the players module) and the migration test (`Failed opening required …2026_09_24_100001…`).

- [ ] **Step 3: Declare the module**

In `app/Models/Role.php`, replace:

```php
    public const MODULES = [
        'players', 'subscriptions', 'transactions', 'finance', 'reports',
        'equipment', 'inventory', 'board', 'users', 'categories', 'settings',
    ];
```

with:

```php
    public const MODULES = [
        'players', 'documents', 'subscriptions', 'transactions', 'finance', 'reports',
        'equipment', 'inventory', 'board', 'users', 'categories', 'settings',
    ];
```

In `config/permissions.php`, in `'modules'`, replace:

```php
        'players' => 'players',
```

with:

```php
        'players' => 'players',
        // Longest prefix wins, so every players.documents.* route lands here and
        // never on the players module: documents are more sensitive than the
        // player record (medical certificates, ID copies).
        'players.documents' => 'documents',
```

and replace:

```php
        'player-statuses' => 'categories',
```

with:

```php
        'player-statuses' => 'categories',
        'document-types' => 'categories',
```

In `'overrides'`, replace:

```php
        'board.meetings.minutes' => ['board', 'view'],
```

with:

```php
        'board.meetings.minutes' => ['board', 'view'],
        // "download" is not a view verb: without this, fetching a file the user
        // may already open inline would need edit rights.
        'players.documents.files.download' => ['documents', 'view'],
```

- [ ] **Step 4: Grant it to the admin roles**

Create `database/migrations/2026_09_24_100001_grant_documents_permission_to_admin_roles.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The new `documents` module (player documents: view / add / edit /
     * delete). The two system admin roles get it in full, as they have every
     * other module; every other role gets nothing until an admin grants it —
     * documents carry medical and identity papers.
     *
     * God admins (legacy admin/superadmin privilege) need nothing here: they
     * short-circuit to Role::allPermissions(), which reads Role::MODULES. A
     * fresh install's RoleSeeder also uses allPermissions().
     *
     * A migration, not the seeder, because the desktop build runs `migrate` on
     * every boot and never runs seeders. Idempotent: it only ever sets the one
     * key, so re-running it after a partial failure is harmless.
     */
    private const ADMIN_ROLES = ['superadmin', 'administrator'];

    private const ACTIONS = ['view', 'add', 'edit', 'delete'];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->whereIn('key', self::ADMIN_ROLES)->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            $permissions['documents'] = self::ACTIONS;

            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->whereIn('key', self::ADMIN_ROLES)->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            unset($permissions['documents']);

            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
```

In `tests/Unit/RoleModelTest.php`, replace:

```php
        $this->assertCount(11, $all);
        $this->assertSame(['view', 'add', 'edit', 'delete'], $all['finance']);
        $this->assertArrayHasKey('settings', $all);
```

with:

```php
        $this->assertCount(12, $all);
        $this->assertSame(['view', 'add', 'edit', 'delete'], $all['finance']);
        $this->assertArrayHasKey('settings', $all);
        $this->assertArrayHasKey('documents', $all);
```

- [ ] **Step 5: The module label**

Check it is new: `grep -c '"documents"' resources/js/i18n/en.json` → `0`. Save as `i18n-keys.tmp.json`:

```json
{
    "documents": { "en": "Documents", "fr": "Documents", "ar": "الوثائق" }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete `i18n-keys.tmp.json`.

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `php artisan test --filter="DocumentsPermissionTest|RoleModelTest|RoleManagementTest|UserRoleAssignmentTest|SharedPermissionsTest|PermissionMiddlewareTest|DashboardPageTest|ModuleAccessTest"`

Expected: PASS (DocumentsPermissionTest: 7 tests; the others unchanged apart from the 12-module count).

- [ ] **Step 7: Commit**

```bash
php vendor/bin/pint app/Models/Role.php config/permissions.php database/migrations/2026_09_24_100001_grant_documents_permission_to_admin_roles.php tests/Feature/DocumentsPermissionTest.php tests/Unit/RoleModelTest.php
node scripts/i18n-check.mjs
git add app/Models/Role.php config/permissions.php database/migrations/2026_09_24_100001_grant_documents_permission_to_admin_roles.php tests/Feature/DocumentsPermissionTest.php tests/Unit/RoleModelTest.php resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(rbac): add a documents permission module, granted to the admin roles"
git show --stat HEAD
```

---

### Task 2: Document types — table, defaults and model

**Why:** The types are reference data the club edits later, so the seven defaults are seeded **in a migration** (desktop). Owner decision: an age limit (`max_age`) replaces the category pivot, and Parental authorization is required up to 17.

**Files:**
- Create: `database/migrations/2026_09_24_100002_create_document_types.php`
- Create: `app/Models/DocumentType.php`
- Test: `tests/Feature/DocumentTypeModelTest.php`

**Interfaces:**
- Table `document_types`: `id`, `code` string(64) unique, `name`, `name_ar`/`name_fr`/`name_en` nullable, `is_required` bool default false, `validity` string(16) default `none`, `max_age` unsigned tinyint nullable, `is_active` bool default true, `sort_order` unsigned int default 0, timestamps. **No** `document_type_category` pivot.
- Seeded codes, in order (`sort_order` 10…70): `birth_certificate` (required, none), `photo` (required, none), `medical_certificate` (required, season), `parental_authorization` (required, season, `max_age = 17`), `id_card_copy` (optional, date), `residence_certificate` (optional, none), `school_certificate` (optional, season). `name` holds the French name (the fallback `HasLocalizedName` reads).
- `App\Models\DocumentType`:
  - constants `PHOTO = 'photo'`, `VALIDITY_NONE`, `VALIDITY_SEASON`, `VALIDITY_DATE`, `VALIDITIES`
  - `playerDocuments(): HasMany`, `scopeOrdered(Builder)` (sort_order, then name), `isPhoto(): bool`
  - `earliestApplicableBirthdate(?CarbonInterface $today = null): ?CarbonImmutable` — the earliest birth date the type still applies to (`today − (max_age + 1) years + 1 day`), `null` when there is no age limit
  - `appliesTo(Player $player, ?CarbonInterface $today = null): bool` — true when no limit, no birth date, or `birthdate >= earliestApplicableBirthdate`
  - `validUntilFor(CarbonInterface|string $receivedAt, ?string $entered = null): ?string` — `Y-m-d` or null (season → end of the season containing `receivedAt`; date → the entered date; none → null)
  - `$appends = ['localized_name']`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DocumentTypeModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentTypeModelTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_24_100002_create_document_types.php';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    #[Test]
    public function the_seven_default_types_are_seeded_in_order(): void
    {
        $this->assertSame([
            'birth_certificate', 'photo', 'medical_certificate', 'parental_authorization',
            'id_card_copy', 'residence_certificate', 'school_certificate',
        ], DocumentType::ordered()->pluck('code')->all());
    }

    #[Test]
    public function the_defaults_carry_the_spec_rules(): void
    {
        $rules = DocumentType::ordered()->get()
            ->mapWithKeys(fn (DocumentType $t) => [$t->code => [$t->is_required, $t->validity, $t->max_age, $t->is_active]])
            ->all();

        $this->assertSame([
            'birth_certificate' => [true, 'none', null, true],
            'photo' => [true, 'none', null, true],
            'medical_certificate' => [true, 'season', null, true],
            'parental_authorization' => [true, 'season', 17, true],
            'id_card_copy' => [false, 'date', null, true],
            'residence_certificate' => [false, 'none', null, true],
            'school_certificate' => [false, 'season', null, true],
        ], $rules);
    }

    #[Test]
    public function a_type_is_named_in_the_page_language(): void
    {
        $type = $this->type('medical_certificate');

        app()->setLocale('fr');
        $this->assertSame('Certificat médical', $type->localized_name);

        app()->setLocale('ar');
        $this->assertSame('شهادة طبية', $type->localized_name);

        app()->setLocale('en');
        $this->assertSame('Medical certificate', $type->localized_name);
    }

    #[Test]
    public function only_the_photo_type_is_the_photo(): void
    {
        $this->assertTrue($this->type('photo')->isPhoto());
        $this->assertFalse($this->type('birth_certificate')->isPhoto());
    }

    #[Test]
    public function a_season_document_is_valid_until_the_end_of_its_season(): void
    {
        $type = $this->type('medical_certificate');

        // Seasons start in September unless the club says otherwise.
        $this->assertSame('2027-08-31', $type->validUntilFor('2026-10-05'));
        $this->assertSame('2026-08-31', $type->validUntilFor('2026-08-31'));
        $this->assertSame('2027-08-31', $type->validUntilFor('2026-09-01'));
    }

    #[Test]
    public function a_date_document_is_valid_until_the_entered_date_and_a_plain_one_never_expires(): void
    {
        $this->assertSame('2030-01-31', $this->type('id_card_copy')->validUntilFor('2026-10-05', '2030-01-31'));
        $this->assertNull($this->type('id_card_copy')->validUntilFor('2026-10-05'));
        $this->assertNull($this->type('birth_certificate')->validUntilFor('2026-10-05', '2030-01-31'));
    }

    #[Test]
    public function an_age_limited_type_applies_up_to_and_including_its_age(): void
    {
        Carbon::setTestNow('2026-09-23');
        $type = $this->type('parental_authorization'); // max_age 17

        $this->assertSame('2008-09-24', $type->earliestApplicableBirthdate()->toDateString());

        // 17 today (turns 18 tomorrow): still asked.
        $this->assertTrue($type->appliesTo(new Player(['birthdate' => '2008-09-24'])));
        // 18 today: no longer asked.
        $this->assertFalse($type->appliesTo(new Player(['birthdate' => '2008-09-23'])));
        // A young child.
        $this->assertTrue($type->appliesTo(new Player(['birthdate' => '2016-01-01'])));
        // Owner decision: no birth date means "not limited".
        $this->assertTrue($type->appliesTo(new Player(['birthdate' => null])));
    }

    #[Test]
    public function a_type_without_an_age_limit_applies_to_everyone(): void
    {
        $type = $this->type('medical_certificate');

        $this->assertNull($type->earliestApplicableBirthdate());
        $this->assertTrue($type->appliesTo(new Player(['birthdate' => '1950-01-01'])));
    }

    #[Test]
    public function the_migration_can_run_again_after_a_partial_failure(): void
    {
        $migration = require database_path(self::MIGRATION);

        // Desktop: SQLite DDL is not rolled back, so a re-run meets its own table
        // and its own rows. Neither may throw or duplicate.
        $migration->up();

        $this->assertSame(7, DocumentType::count());
    }

    #[Test]
    public function the_seed_never_overwrites_an_edited_type(): void
    {
        $this->type('school_certificate')->update(['is_required' => true, 'name_fr' => 'Scolarité']);

        (require database_path(self::MIGRATION))->up();

        $type = $this->type('school_certificate');
        $this->assertTrue($type->is_required);
        $this->assertSame('Scolarité', $type->name_fr);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan config:clear; php artisan test --filter=DocumentTypeModelTest`

Expected: FAIL with `Class "App\Models\DocumentType" not found`.

- [ ] **Step 3: The table and its defaults**

Create `database/migrations/2026_09_24_100002_create_document_types.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The documents a player's file should hold, managed in Settings.
     *
     * `code` is how the app recognises a type (the Photo rule keys off
     * `photo`); records point at the id, so renaming a type or changing its
     * rules never breaks them. `max_age` replaces the category limit of the
     * first draft (owner decision): 17 means "asked while the player is 17 or
     * younger", null means every age.
     *
     * The seven defaults are seeded here, not in a seeder, because the desktop
     * build runs `migrate` on boot and never runs seeders.
     *
     * Re-runnable: SQLite DDL is not rolled back when a later statement fails,
     * so a second run meets its own table (guarded) and its own rows (inserted
     * only when the code is absent — an edited default is never overwritten).
     */
    public function up(): void
    {
        if (! Schema::hasTable('document_types')) {
            Schema::create('document_types', function (Blueprint $table) {
                $table->id();
                $table->string('code', 64)->unique();
                $table->string('name');
                $table->string('name_ar')->nullable();
                $table->string('name_fr')->nullable();
                $table->string('name_en')->nullable();
                $table->boolean('is_required')->default(false);
                $table->string('validity', 16)->default('none');
                $table->unsignedTinyInteger('max_age')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        $now = now();

        foreach ($this->defaults() as $order => $type) {
            if (DB::table('document_types')->where('code', $type['code'])->exists()) {
                continue;
            }

            DB::table('document_types')->insert([
                ...$type,
                'name' => $type['name_fr'],
                'is_active' => true,
                'sort_order' => ($order + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }

    /** @return list<array<string, mixed>> */
    private function defaults(): array
    {
        return [
            ['code' => 'birth_certificate', 'name_fr' => 'Acte de naissance', 'name_ar' => 'شهادة الميلاد', 'name_en' => 'Birth certificate', 'is_required' => true, 'validity' => 'none', 'max_age' => null],
            // Owner decision: received whenever the player has a profile picture.
            ['code' => 'photo', 'name_fr' => 'Photo', 'name_ar' => 'صورة شمسية', 'name_en' => 'Photo', 'is_required' => true, 'validity' => 'none', 'max_age' => null],
            ['code' => 'medical_certificate', 'name_fr' => 'Certificat médical', 'name_ar' => 'شهادة طبية', 'name_en' => 'Medical certificate', 'is_required' => true, 'validity' => 'season', 'max_age' => null],
            // Owner decision: required, but only while the player is 17 or younger.
            ['code' => 'parental_authorization', 'name_fr' => 'Autorisation parentale', 'name_ar' => 'ترخيص أبوي', 'name_en' => 'Parental authorization', 'is_required' => true, 'validity' => 'season', 'max_age' => 17],
            ['code' => 'id_card_copy', 'name_fr' => "Copie de la carte d'identité", 'name_ar' => 'نسخة من بطاقة التعريف', 'name_en' => 'ID card copy', 'is_required' => false, 'validity' => 'date', 'max_age' => null],
            ['code' => 'residence_certificate', 'name_fr' => 'Certificat de résidence', 'name_ar' => 'شهادة الإقامة', 'name_en' => 'Residence certificate', 'is_required' => false, 'validity' => 'none', 'max_age' => null],
            ['code' => 'school_certificate', 'name_fr' => 'Certificat de scolarité', 'name_ar' => 'شهادة مدرسية', 'name_en' => 'School certificate', 'is_required' => false, 'validity' => 'season', 'max_age' => null],
        ];
    }
};
```

- [ ] **Step 4: The model**

Create `app/Models/DocumentType.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of document a player's file should hold (birth certificate, medical
 * certificate, ...). Managed in Settings → Document types.
 */
class DocumentType extends Model
{
    use HasLocalizedName;

    /** The seeded Photo type: satisfied by the player's profile picture, never uploaded. */
    public const PHOTO = 'photo';

    public const VALIDITY_NONE = 'none';

    public const VALIDITY_SEASON = 'season';

    public const VALIDITY_DATE = 'date';

    public const VALIDITIES = [self::VALIDITY_NONE, self::VALIDITY_SEASON, self::VALIDITY_DATE];

    protected $fillable = [
        'code',
        'name',
        'name_ar',
        'name_fr',
        'name_en',
        'is_required',
        'validity',
        'max_age',
        'is_active',
        'sort_order',
    ];

    protected $appends = [
        'localized_name',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'max_age' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function playerDocuments(): HasMany
    {
        return $this->hasMany(PlayerDocument::class);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    public function isPhoto(): bool
    {
        return $this->code === self::PHOTO;
    }

    /**
     * The earliest birth date this type still applies to, or null when it has
     * no age limit. A player applies while their birthdate is ON OR AFTER it:
     * with max_age 17 on 2026-09-23 that is 2008-09-24 — 17 today, 18 tomorrow.
     *
     * This single boundary is what both the PHP checklist and its SQL twin
     * compare against (DocumentChecklist), so they can never disagree about
     * a birthday.
     */
    public function earliestApplicableBirthdate(?CarbonInterface $today = null): ?CarbonImmutable
    {
        if ($this->max_age === null) {
            return null;
        }

        return CarbonImmutable::parse($today ?? CarbonImmutable::today())
            ->startOfDay()
            ->subYearsNoOverflow($this->max_age + 1)
            ->addDay();
    }

    /** Owner decision: a player with no birth date is treated as not limited. */
    public function appliesTo(Player $player, ?CarbonInterface $today = null): bool
    {
        $earliest = $this->earliestApplicableBirthdate($today);

        return $earliest === null
            || $player->birthdate === null
            || $player->birthdate->gte($earliest);
    }

    /**
     * When a document received on $receivedAt stops being valid, as Y-m-d, or
     * null for "never". `date` types take the date the user entered.
     */
    public function validUntilFor(CarbonInterface|string $receivedAt, ?string $entered = null): ?string
    {
        return match ($this->validity) {
            self::VALIDITY_SEASON => Season::forDate($receivedAt)->end()->toDateString(),
            self::VALIDITY_DATE => $entered ? CarbonImmutable::parse($entered)->toDateString() : null,
            default => null,
        };
    }
}
```

(`PlayerDocument` is created in Task 3; `playerDocuments()` is not called before then.)

- [ ] **Step 5: Run the test and confirm it passes**

Run: `php artisan test --filter=DocumentTypeModelTest`

Expected: PASS (10 tests).

- [ ] **Step 6: Commit**

```bash
php vendor/bin/pint app/Models/DocumentType.php database/migrations/2026_09_24_100002_create_document_types.php tests/Feature/DocumentTypeModelTest.php
git add app/Models/DocumentType.php database/migrations/2026_09_24_100002_create_document_types.php tests/Feature/DocumentTypeModelTest.php
git commit -m "feat(documents): document types with seeded defaults and an age limit"
git show --stat HEAD
```

---

### Task 3: Player documents and files — tables, models, private storage

**Why:** One row per (player, type) holds the state and dates; several files hang off it. Files live on the **private** disk and are never reachable through the public `/media` route.

**Files:**
- Create: `database/migrations/2026_09_24_100003_create_player_documents.php`
- Create: `app/Models/PlayerDocument.php`
- Create: `app/Models/PlayerDocumentFile.php`
- Create: `app/Services/Storage/PrivateFileStorage.php`
- Modify: `app/Models/Player.php`
- Modify: `config/nativephp.php`
- Test: `tests/Feature/PlayerDocumentModelTest.php`

**Interfaces:**
- Table `player_documents`: `id`, `player_id` FK cascade, `document_type_id` FK **restrict**, `state` string(16) (`received` / `exempt`), `received_at` date nullable, `valid_until` date nullable, `exempt_reason` string nullable, `notes` text nullable, `recorded_by_user_id` FK users nullOnDelete, timestamps, **unique** (`player_id`, `document_type_id`), index (`document_type_id`, `state`).
- Table `player_document_files`: `id`, `player_document_id` FK cascade, `path`, `original_name`, `mime` string(100), `size` unsigned big int, `uploaded_by_user_id` FK users nullOnDelete, `created_at` (no `updated_at`).
- `App\Models\PlayerDocument`: constants `RECEIVED = 'received'`, `EXEMPT = 'exempt'`, `ROOT = 'player-documents'`; `static directoryFor(int $playerId): string` → `player-documents/{id}`; relations `player()`, `type()` (BelongsTo `DocumentType`, `document_type_id`), `files()` (HasMany ordered by id), `recordedBy()`; casts `received_at`/`valid_until` → `date`.
- `App\Models\PlayerDocumentFile`: `UPDATED_AT = null`; relations `document()`, `uploadedBy()`; `path` is `$hidden` (never sent to the browser).
- `Player::documents(): HasMany`.
- `App\Services\Storage\PrivateFileStorage` (disk `local` = `storage/app/private`):
  - `store(UploadedFile $file, string $directory): array{path:string, original_name:string, mime:string, size:int}` — random file name, original name kept
  - `exists(?string $path): bool`, `delete(?string $path): void`, `deleteDirectory(string $directory): void` — all refuse `..`, absolute and drive-letter paths
  - `inline(string $path, string $name, ?string $mime = null): StreamedResponse` (Content-Disposition inline, `nosniff`, `private, no-store`), `download(string $path, string $name): StreamedResponse` (attachment); both 404 when the file is gone
- `config('nativephp.cleanup_exclude_files')` gains `'storage/app/private'`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PlayerDocumentModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDocumentFile;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PlayerDocumentModelTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_24_100003_create_player_documents.php';

    private function player(): Player
    {
        return Player::create(['membership_id' => '202600501', 'firstname' => 'Amine', 'lastname' => 'Saadi']);
    }

    private function type(string $code = 'birth_certificate'): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    private function document(Player $player, ?DocumentType $type = null): PlayerDocument
    {
        return PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => ($type ?? $this->type())->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => '2026-09-01',
        ]);
    }

    #[Test]
    public function a_player_has_at_most_one_row_per_type(): void
    {
        $player = $this->player();
        $this->document($player);

        $this->expectException(QueryException::class);
        $this->document($player);
    }

    #[Test]
    public function a_document_knows_its_player_type_and_files(): void
    {
        $player = $this->player();
        $document = $this->document($player);
        $document->files()->create(['path' => 'player-documents/1/a.pdf', 'original_name' => 'acte.pdf', 'mime' => 'application/pdf', 'size' => 10]);

        $this->assertTrue($document->player->is($player));
        $this->assertSame('birth_certificate', $document->type->code);
        $this->assertSame(['acte.pdf'], $document->files->pluck('original_name')->all());
        $this->assertSame([$document->id], $player->documents()->pluck('id')->all());
        $this->assertSame('2026-09-01', $document->fresh()->received_at->toDateString());
    }

    #[Test]
    public function a_file_never_sends_its_disk_path_to_the_browser(): void
    {
        $document = $this->document($this->player());
        $file = $document->files()->create(['path' => 'player-documents/1/secret.pdf', 'original_name' => 'acte.pdf', 'mime' => 'application/pdf', 'size' => 10]);

        $this->assertArrayNotHasKey('path', $file->toArray());
        $this->assertNotNull($file->created_at);
    }

    #[Test]
    public function removing_a_player_row_removes_its_documents_and_files(): void
    {
        $player = $this->player();
        $document = $this->document($player);
        $document->files()->create(['path' => 'player-documents/1/a.pdf', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 10]);

        $player->delete();

        $this->assertSame(0, PlayerDocument::count());
        $this->assertSame(0, PlayerDocumentFile::count());
    }

    #[Test]
    public function a_type_with_records_cannot_be_deleted_underneath_them(): void
    {
        $type = $this->type();
        $this->document($this->player(), $type);

        $this->expectException(QueryException::class);
        $type->delete();
    }

    #[Test]
    public function each_player_gets_a_folder_of_their_own(): void
    {
        $this->assertSame('player-documents/42', PlayerDocument::directoryFor(42));
    }

    #[Test]
    public function the_migration_can_run_again_after_a_partial_failure(): void
    {
        (require database_path(self::MIGRATION))->up();

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('player_documents'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('player_document_files'));
    }

    #[Test]
    public function a_stored_file_lands_on_the_private_disk_under_a_random_name(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $stored = app(PrivateFileStorage::class)->store(
            UploadedFile::fake()->create('Acte de naissance.pdf', 120, 'application/pdf'),
            'player-documents/7'
        );

        $this->assertStringStartsWith('player-documents/7/', $stored['path']);
        $this->assertStringNotContainsString('Acte', $stored['path']);
        $this->assertSame('Acte de naissance.pdf', $stored['original_name']);
        $this->assertSame('application/pdf', $stored['mime']);
        $this->assertSame(120 * 1024, $stored['size']);
        Storage::disk('local')->assertExists($stored['path']);
        Storage::disk('public')->assertMissing($stored['path']);
    }

    #[Test]
    public function private_storage_refuses_paths_that_climb_out_of_the_disk(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('player-documents/7/a.pdf', 'PDF');
        $storage = app(PrivateFileStorage::class);

        $this->assertTrue($storage->exists('player-documents/7/a.pdf'));
        $this->assertFalse($storage->exists('../.env'));
        $this->assertFalse($storage->exists('/etc/passwd'));
        $this->assertFalse($storage->exists('C:\\Windows\\win.ini'));
        $this->assertFalse($storage->exists(null));

        $storage->delete('player-documents/7/../7/a.pdf');
        Storage::disk('local')->assertExists('player-documents/7/a.pdf');

        $storage->delete('player-documents/7/a.pdf');
        Storage::disk('local')->assertMissing('player-documents/7/a.pdf');
    }

    #[Test]
    public function a_private_file_is_streamed_inline_or_as_a_download(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('player-documents/7/a.pdf', 'PDF-BYTES');
        $storage = app(PrivateFileStorage::class);

        $inline = $storage->inline('player-documents/7/a.pdf', 'شهادة.pdf', 'application/pdf');
        $this->assertStringStartsWith('inline', $inline->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $inline->headers->get('X-Content-Type-Options'));
        $this->assertSame('application/pdf', $inline->headers->get('Content-Type'));

        $download = $storage->download('player-documents/7/a.pdf', 'acte.pdf');
        $this->assertStringStartsWith('attachment', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('acte.pdf', $download->headers->get('Content-Disposition'));
    }

    #[Test]
    public function a_missing_private_file_is_a_404(): void
    {
        Storage::fake('local');

        $this->expectException(NotFoundHttpException::class);
        app(PrivateFileStorage::class)->inline('player-documents/7/gone.pdf', 'gone.pdf');
    }

    #[Test]
    public function the_desktop_installer_never_bundles_private_files(): void
    {
        $this->assertContains('storage/app/private', config('nativephp.cleanup_exclude_files'));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan config:clear; php artisan test --filter=PlayerDocumentModelTest`

Expected: FAIL with `Class "App\Models\PlayerDocument" not found`.

- [ ] **Step 3: The tables**

Create `database/migrations/2026_09_24_100003_create_player_documents.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A player's documents: one row per (player, type) — received or exempt,
     * with its dates — and any number of scanned files per row.
     *
     * The type FK restricts deletion (Settings refuses to delete a type in
     * use; this is the database backing that up). The player FK cascades,
     * although permanent deletion also removes the rows and the files itself.
     *
     * Re-runnable: each table is created only when absent, because SQLite
     * does not roll back DDL and the desktop build re-runs an unrecorded
     * migration on the next boot.
     */
    public function up(): void
    {
        if (! Schema::hasTable('player_documents')) {
            Schema::create('player_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('player_id')->constrained()->cascadeOnDelete();
                $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
                $table->string('state', 16);
                $table->date('received_at')->nullable();
                $table->date('valid_until')->nullable();
                $table->string('exempt_reason')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['player_id', 'document_type_id']);
                $table->index(['document_type_id', 'state']);
            });
        }

        if (! Schema::hasTable('player_document_files')) {
            Schema::create('player_document_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('player_document_id')->constrained()->cascadeOnDelete();
                $table->string('path');
                $table->string('original_name');
                $table->string('mime', 100);
                $table->unsignedBigInteger('size');
                $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('player_document_files');
        Schema::dropIfExists('player_documents');
    }
};
```

- [ ] **Step 4: The models**

Create `app/Models/PlayerDocument.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One document of one player: received (with its dates) or exempt (with a
 * reason). Renewing moves the dates forward on this same row; the files
 * uploaded over time stay attached as history.
 */
class PlayerDocument extends Model
{
    public const RECEIVED = 'received';

    public const EXEMPT = 'exempt';

    /** Folder on the private disk that holds every player's files. */
    public const ROOT = 'player-documents';

    protected $fillable = [
        'player_id',
        'document_type_id',
        'state',
        'received_at',
        'valid_until',
        'exempt_reason',
        'notes',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'valid_until' => 'date',
        ];
    }

    /** Where a player's files live on the private disk. */
    public static function directoryFor(int $playerId): string
    {
        return self::ROOT.'/'.$playerId;
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(PlayerDocumentFile::class)->orderBy('id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
```

Create `app/Models/PlayerDocumentFile.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scanned page or photo of a player document, on the private disk. The
 * browser only ever sees its id and original name; the file itself is served
 * by PlayerDocumentController behind the documents permission.
 */
class PlayerDocumentFile extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'player_document_id',
        'path',
        'original_name',
        'mime',
        'size',
        'uploaded_by_user_id',
    ];

    /** The disk path is an implementation detail and must never reach the page. */
    protected $hidden = [
        'path',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PlayerDocument::class, 'player_document_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
```

In `app/Models/Player.php`, replace:

```php
    public function equipmentRentals(): MorphMany
```

with:

```php
    public function documents(): HasMany
    {
        return $this->hasMany(PlayerDocument::class);
    }

    public function equipmentRentals(): MorphMany
```

- [ ] **Step 5: Private storage**

Create `app/Services/Storage/PrivateFileStorage.php`:

```php
<?php

namespace App\Services\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files that must never be public — player documents, board minutes — on the
 * private `local` disk (storage/app/private).
 *
 * Nothing here produces a URL: the public `/media/{path}` route only reads
 * the public disk, and the framework's own local-disk route needs a signed
 * URL this app never issues. A file is reached only through a controller
 * action that has already checked who is asking.
 *
 * Files are stored unmodified (scans must stay legible) under a random name;
 * the caller keeps the original name for downloads.
 */
class PrivateFileStorage
{
    public const DISK = 'local';

    /**
     * @return array{path: string, original_name: string, mime: string, size: int}
     */
    public function store(UploadedFile $file, string $directory): array
    {
        $path = $file->store(trim($directory, '/'), self::DISK);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The file could not be stored.');
        }

        return [
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName() ?: basename($path), 0, 255),
            'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'size' => (int) $file->getSize(),
        ];
    }

    public function exists(?string $path): bool
    {
        return $this->isSafe($path) && Storage::disk(self::DISK)->exists($path);
    }

    public function delete(?string $path): void
    {
        if ($this->isSafe($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function deleteDirectory(string $directory): void
    {
        if ($this->isSafe($directory)) {
            Storage::disk(self::DISK)->deleteDirectory($directory);
        }
    }

    /** Shown in the browser tab (PDF viewer, image). */
    public function inline(string $path, string $name, ?string $mime = null): StreamedResponse
    {
        abort_unless($this->exists($path), 404);

        return Storage::disk(self::DISK)->response($path, $name, array_filter([
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]));
    }

    /** Saved to disk under its original name. */
    public function download(string $path, string $name): StreamedResponse
    {
        abort_unless($this->exists($path), 404);

        return Storage::disk(self::DISK)->download($path, $name, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Only plain relative paths inside the disk: no climbing out, no absolute paths. */
    private function isSafe(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        $path = str_replace('\\', '/', $path);

        return ! str_starts_with($path, '/')
            && ! preg_match('#^[A-Za-z]:#', $path)
            && ! in_array('..', explode('/', $path), true);
    }
}
```

- [ ] **Step 6: Keep private files out of the installer**

In `config/nativephp.php`, replace:

```php
        'storage/app/mpdf',
```

with:

```php
        'storage/app/mpdf',
        // Player documents and board minutes of THIS machine: never ship them to
        // another club's installer. A new install starts with an empty private disk.
        'storage/app/private',
```

- [ ] **Step 7: Run the test and confirm it passes**

Run: `php artisan test --filter=PlayerDocumentModelTest`

Expected: PASS (12 tests).

- [ ] **Step 8: Commit**

```bash
php vendor/bin/pint app/Models/PlayerDocument.php app/Models/PlayerDocumentFile.php app/Models/Player.php app/Services/Storage/PrivateFileStorage.php config/nativephp.php database/migrations/2026_09_24_100003_create_player_documents.php tests/Feature/PlayerDocumentModelTest.php
git add app/Models/PlayerDocument.php app/Models/PlayerDocumentFile.php app/Models/Player.php app/Services/Storage/PrivateFileStorage.php config/nativephp.php database/migrations/2026_09_24_100003_create_player_documents.php tests/Feature/PlayerDocumentModelTest.php
git commit -m "feat(documents): player documents and files on the private disk"
git show --stat HEAD
```

---

### Task 4: Settings → Document types (backend)

**Why:** Spec — add and edit names, required, validity, age limit and order; activate / deactivate; delete only while unused; renaming or changing rules never breaks existing records (they reference the id). Same shape as `PlayerStatusController`.

**Files:**
- Create: `app/Http/Controllers/DocumentTypeController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script — the flash keys)
- Test: `tests/Feature/DocumentTypeSettingsTest.php`

**Interfaces:**
- Routes (inside the management group, next to `player-statuses`): `Route::resource('document-types', DocumentTypeController::class)->except(['show', 'create', 'edit'])` → `document-types.index|store|update|destroy`, parameter `{document_type}`.
- `index` renders `Settings/DocumentTypes` with prop `documentTypes` = every type (active or not) `withCount('playerDocuments')`, ordered.
- Request fields for store/update: `name` (required, unique), `name_ar`, `name_fr`, `name_en`, `is_required` (bool), `validity` (`none|season|date`), `max_age` (nullable 1–99), `is_active` (bool), `sort_order` (nullable, ≥ 0). `code` is generated on create (slug of `name_en`, else `name_fr`, else `name`, else `document`, suffixed `-2`, `-3`… if taken) and is never editable.
- Flash keys: `flash.document_type_created`, `flash.document_type_updated`, `flash.document_type_deleted`, `flash.document_type_in_use`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DocumentTypeSettingsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentTypeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Licence fédérale',
            'name_ar' => 'الإجازة الفدرالية',
            'name_fr' => 'Licence fédérale',
            'name_en' => 'Federation licence',
            'is_required' => true,
            'validity' => 'season',
            'max_age' => '',
            'is_active' => true,
            'sort_order' => 80,
            ...$overrides,
        ];
    }

    private function recordFor(DocumentType $type, array $attributes = []): PlayerDocument
    {
        $player = Player::create(['membership_id' => '202600601', 'firstname' => 'Ali', 'lastname' => 'Kaci']);

        return PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => $type->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => '2026-09-01',
            ...$attributes,
        ]);
    }

    #[Test]
    public function the_page_lists_every_type_with_its_usage(): void
    {
        $this->recordFor($this->type('birth_certificate'));

        $page = $this->actingAs($this->admin())->get(route('document-types.index'))
            ->assertOk()->viewData('page');
        $props = $page['props'];

        $this->assertSame('Settings/DocumentTypes', $page['component']);
        $this->assertCount(7, $props['documentTypes']);
        $this->assertSame('birth_certificate', $props['documentTypes'][0]['code']);
        $this->assertSame(1, $props['documentTypes'][0]['player_documents_count']);
        $this->assertArrayHasKey('localized_name', $props['documentTypes'][0]);
    }

    #[Test]
    public function a_type_can_be_created_with_its_rules_and_gets_a_stable_code(): void
    {
        $this->actingAs($this->admin())->post(route('document-types.store'), $this->payload(['max_age' => 15]))
            ->assertRedirect()
            ->assertSessionHas('success', 'flash.document_type_created');

        $type = DocumentType::where('name', 'Licence fédérale')->firstOrFail();
        $this->assertSame('federation-licence', $type->code);
        $this->assertTrue($type->is_required);
        $this->assertSame('season', $type->validity);
        $this->assertSame(15, $type->max_age);
        $this->assertSame(80, $type->sort_order);

        app()->setLocale('ar');
        $this->assertSame('الإجازة الفدرالية', $type->localized_name);
    }

    #[Test]
    public function a_second_type_with_the_same_slug_gets_a_suffixed_code(): void
    {
        $this->actingAs($this->admin())->post(route('document-types.store'), $this->payload());
        $this->actingAs($this->admin())->post(route('document-types.store'), $this->payload(['name' => 'Licence fédérale (bis)']));

        $this->assertSame(['federation-licence', 'federation-licence-2'], DocumentType::where('code', 'like', 'federation-licence%')->orderBy('id')->pluck('code')->all());
    }

    #[Test]
    public function bad_rules_are_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('document-types.store'), $this->payload(['validity' => 'forever']))
            ->assertSessionHasErrors('validity');

        $this->actingAs($this->admin())->post(route('document-types.store'), $this->payload(['max_age' => 0]))
            ->assertSessionHasErrors('max_age');

        $this->actingAs($this->admin())->post(route('document-types.store'), $this->payload(['name' => 'Photo']))
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function renaming_or_changing_rules_never_touches_existing_records_or_the_code(): void
    {
        $type = $this->type('medical_certificate');
        $record = $this->recordFor($type, ['valid_until' => '2027-08-31']);

        $this->actingAs($this->admin())->put(route('document-types.update', $type), $this->payload([
            'name' => 'Certificat médical annuel',
            'validity' => 'date',
            'max_age' => 30,
            'code' => 'hacked',
        ]))->assertRedirect()->assertSessionHas('success', 'flash.document_type_updated');

        $type->refresh();
        $this->assertSame('medical_certificate', $type->code);
        $this->assertSame('Certificat médical annuel', $type->name);
        $this->assertSame(30, $type->max_age);
        $this->assertSame('2027-08-31', $record->fresh()->valid_until->toDateString());
        $this->assertSame($type->id, $record->fresh()->document_type_id);
    }

    #[Test]
    public function clearing_the_age_limit_makes_a_type_apply_to_every_age(): void
    {
        $type = $this->type('parental_authorization');

        $this->actingAs($this->admin())->put(route('document-types.update', $type), $this->payload([
            'name' => $type->name,
            'max_age' => '',
        ]));

        $this->assertNull($type->fresh()->max_age);
    }

    #[Test]
    public function a_type_can_be_deactivated_and_reactivated(): void
    {
        $type = $this->type('school_certificate');

        $this->actingAs($this->admin())->put(route('document-types.update', $type), $this->payload(['name' => $type->name, 'is_active' => false]));
        $this->assertFalse($type->fresh()->is_active);

        $this->actingAs($this->admin())->put(route('document-types.update', $type), $this->payload(['name' => $type->name, 'is_active' => true]));
        $this->assertTrue($type->fresh()->is_active);
    }

    #[Test]
    public function an_unused_type_can_be_deleted(): void
    {
        $type = $this->type('residence_certificate');

        $this->actingAs($this->admin())->delete(route('document-types.destroy', $type))
            ->assertRedirect()
            ->assertSessionHas('success', 'flash.document_type_deleted');

        $this->assertNull($type->fresh());
    }

    #[Test]
    public function a_type_in_use_cannot_be_deleted(): void
    {
        $type = $this->type('birth_certificate');
        $this->recordFor($type);

        $this->actingAs($this->admin())->delete(route('document-types.destroy', $type))
            ->assertRedirect()
            ->assertSessionHas('error', 'flash.document_type_in_use');

        $this->assertNotNull($type->fresh());
    }

    #[Test]
    public function the_page_needs_the_lookup_module(): void
    {
        $coach = User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::create(['key' => 'coach', 'name' => ['en' => 'Coach'], 'permissions' => ['players' => Role::ACTIONS, 'documents' => Role::ACTIONS]])->id,
        ]);

        $this->actingAs($coach)->get(route('document-types.index'))->assertForbidden();
        $this->actingAs($coach)->post(route('document-types.store'), $this->payload())->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan config:clear; php artisan test --filter=DocumentTypeSettingsTest`

Expected: FAIL with `Route [document-types.index] not defined.`

- [ ] **Step 3: The controller**

Create `app/Http/Controllers/DocumentTypeController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Document types. Same shape as the other lookup pages (player
 * statuses, jobs): localized names, active flag, order, and a delete that is
 * refused while any player has a record of the type.
 */
class DocumentTypeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/DocumentTypes', [
            // withCount so the page can hide "delete" on a type in use.
            'documentTypes' => DocumentType::withCount('playerDocuments')->ordered()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DocumentType::create([...$data, 'code' => $this->uniqueCode($data)]);

        return back()->with('success', 'flash.document_type_created');
    }

    public function update(Request $request, DocumentType $documentType): RedirectResponse
    {
        // The code is deliberately not editable: it is how the app recognises a
        // type (the Photo rule keys off it). Records point at the id, so a
        // rename or a rule change never breaks them.
        $documentType->update($this->validated($request, $documentType));

        return back()->with('success', 'flash.document_type_updated');
    }

    public function destroy(DocumentType $documentType): RedirectResponse
    {
        if ($documentType->playerDocuments()->exists()) {
            return back()->with('error', 'flash.document_type_in_use');
        }

        $documentType->delete();

        return back()->with('success', 'flash.document_type_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?DocumentType $existing = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('document_types', 'name')->ignore($existing?->id)],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_fr' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'is_required' => ['boolean'],
            'validity' => ['required', Rule::in(DocumentType::VALIDITIES)],
            'max_age' => ['nullable', 'integer', 'min:1', 'max:99'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        // An emptied age box means "every age", so it must be written as null
        // rather than left out of the update.
        $data['max_age'] = $data['max_age'] ?? null;
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    /** A readable, unique slug, fixed at creation. */
    private function uniqueCode(array $data): string
    {
        $base = Str::slug((string) ($data['name_en'] ?? ''))
            ?: Str::slug((string) ($data['name_fr'] ?? ''))
            ?: Str::slug((string) $data['name'])
            ?: 'document';
        $base = Str::limit($base, 56, '');

        $code = $base;
        $suffix = 2;

        while (DocumentType::where('code', $code)->exists()) {
            $code = $base.'-'.$suffix++;
        }

        return $code;
    }
}
```

- [ ] **Step 4: The routes**

In `routes/web.php`, add the import next to the other controller imports (alphabetical order):

```php
use App\Http\Controllers\DocumentTypeController;
```

and replace:

```php
        Route::resource('player-statuses', PlayerStatusController::class)->except(['show', 'create', 'edit']);
```

with:

```php
        Route::resource('player-statuses', PlayerStatusController::class)->except(['show', 'create', 'edit']);
        Route::resource('document-types', DocumentTypeController::class)->except(['show', 'create', 'edit']);
```

- [ ] **Step 5: The flash keys**

`FlashTranslationTest` requires every controller flash key in the catalogs. Save as `i18n-keys.tmp.json`:

```json
{
    "flash.document_type_created": { "en": "Document type created.", "fr": "Type de document créé.", "ar": "تم إنشاء نوع الوثيقة." },
    "flash.document_type_updated": { "en": "Document type updated.", "fr": "Type de document mis à jour.", "ar": "تم تحديث نوع الوثيقة." },
    "flash.document_type_deleted": { "en": "Document type deleted.", "fr": "Type de document supprimé.", "ar": "تم حذف نوع الوثيقة." },
    "flash.document_type_in_use": { "en": "Cannot delete: players already have records of this type. Deactivate it instead.", "fr": "Suppression impossible : des joueurs ont déjà des enregistrements de ce type. Désactivez-le plutôt.", "ar": "لا يمكن الحذف: لدى لاعبين سجلات من هذا النوع. قم بتعطيله بدلاً من ذلك." }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete the file.

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `php artisan test --filter="DocumentTypeSettingsTest|FlashTranslationTest"`

Expected: PASS (DocumentTypeSettingsTest: 10 tests). The Inertia page component does not exist yet; the tests read props from `viewData`, so they do not need it.

- [ ] **Step 7: Commit**

```bash
php vendor/bin/pint app/Http/Controllers/DocumentTypeController.php routes/web.php tests/Feature/DocumentTypeSettingsTest.php
node scripts/i18n-check.mjs
git add app/Http/Controllers/DocumentTypeController.php routes/web.php tests/Feature/DocumentTypeSettingsTest.php resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(documents): manage document types in settings, delete only while unused"
git show --stat HEAD
```

---

### Task 5: Settings → Document types (page and menu)

**Files:**
- Create: `resources/js/Pages/Settings/DocumentTypes.vue` (no BOM)
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script)

**Interfaces:**
- Consumes the `documentTypes` prop of Task 4 and the routes `document-types.store|update|destroy`.
- Produces i18n keys used again in Task 8: `document_types`, `doc_add_type`, `doc_edit_type`, `doc_required`, `doc_optional`, `doc_validity`, `doc_validity_none`, `doc_validity_season`, `doc_validity_date`, `doc_max_age`, `doc_max_age_hint`, `doc_all_ages`, `doc_up_to_age`, `doc_records`, `doc_activate`, `doc_deactivate`.

- [ ] **Step 1: The keys**

Check each is new: `grep -c '"doc_' resources/js/i18n/en.json` → `0` and `grep -c '"document_types"' resources/js/i18n/en.json` → `0`. Save as `i18n-keys.tmp.json`:

```json
{
    "document_types": { "en": "Document types", "fr": "Types de documents", "ar": "أنواع الوثائق" },
    "doc_add_type": { "en": "New document type", "fr": "Nouveau type de document", "ar": "نوع وثيقة جديد" },
    "doc_edit_type": { "en": "Edit document type", "fr": "Modifier le type de document", "ar": "تعديل نوع الوثيقة" },
    "doc_required": { "en": "Required", "fr": "Obligatoire", "ar": "إلزامي" },
    "doc_optional": { "en": "Optional", "fr": "Facultatif", "ar": "اختياري" },
    "doc_validity": { "en": "Validity", "fr": "Validité", "ar": "الصلاحية" },
    "doc_validity_none": { "en": "No expiry", "fr": "Sans expiration", "ar": "بدون انتهاء" },
    "doc_validity_season": { "en": "One season", "fr": "Une saison", "ar": "موسم واحد" },
    "doc_validity_date": { "en": "Until a date", "fr": "Jusqu'à une date", "ar": "إلى تاريخ محدد" },
    "doc_max_age": { "en": "Age limit", "fr": "Limite d'âge", "ar": "حد السن" },
    "doc_max_age_hint": { "en": "Leave empty for every age. 17 means the document is asked while the player is 17 or younger.", "fr": "Laisser vide pour tous les âges. 17 signifie que le document est demandé tant que le joueur a 17 ans ou moins.", "ar": "اتركه فارغاً لكل الأعمار. 17 تعني أن الوثيقة مطلوبة ما دام عمر اللاعب 17 سنة أو أقل." },
    "doc_all_ages": { "en": "All ages", "fr": "Tous âges", "ar": "كل الأعمار" },
    "doc_up_to_age": { "en": "Up to {age} years", "fr": "Jusqu'à {age} ans", "ar": "حتى {age} سنة" },
    "doc_records": { "en": "Records", "fr": "Enregistrements", "ar": "السجلات" },
    "doc_activate": { "en": "Activate", "fr": "Activer", "ar": "تفعيل" },
    "doc_deactivate": { "en": "Deactivate", "fr": "Désactiver", "ar": "تعطيل" }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete the file.

- [ ] **Step 2: The page**

Create `resources/js/Pages/Settings/DocumentTypes.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    documentTypes: { type: Array, default: () => [] },
});

// Every field the server accepts, with the value a new type starts from.
const FIELDS = {
    name: '',
    name_ar: '',
    name_fr: '',
    name_en: '',
    is_required: false,
    validity: 'none',
    max_age: '',
    sort_order: 0,
    is_active: true,
};

const form = useForm({ ...FIELDS });
// null = modal closed, 0 = adding, otherwise the id being edited.
const editing = ref(null);
const deleteId = ref(null);

const validityLabels = computed(() => ({
    none: t('doc_validity_none'),
    season: t('doc_validity_season'),
    date: t('doc_validity_date'),
}));

const inputClass = 'mt-1 w-full rounded-lg border-slate-300 text-slate-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-500/40 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100';

// The full payload for a type (or for a blank one): the server expects every
// field on update, and an empty age box means "every age".
function payloadOf(type) {
    return Object.fromEntries(Object.keys(FIELDS).map((key) => [
        key,
        key === 'max_age' ? (type.max_age ?? '') : (type[key] ?? FIELDS[key]),
    ]));
}

function openCreate() {
    Object.assign(form, payloadOf(FIELDS));
    form.clearErrors();
    editing.value = 0;
}

function openEdit(type) {
    Object.assign(form, payloadOf(type));
    form.clearErrors();
    editing.value = type.id;
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => { editing.value = null; } };

    if (editing.value) {
        form.put(route('document-types.update', editing.value), options);
    } else {
        form.post(route('document-types.store'), options);
    }
}

function toggleActive(type) {
    router.put(route('document-types.update', type.id), { ...payloadOf(type), is_active: !type.is_active }, { preserveScroll: true });
}

function destroy() {
    const id = deleteId.value;
    deleteId.value = null;
    router.delete(route('document-types.destroy', id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('document_types')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('document_types') }}</h1>
                <PrimaryButton type="button" @click="openCreate">
                    <Icon name="plus" class="me-1" /> {{ t('doc_add_type') }}
                </PrimaryButton>
            </div>
        </template>

        <div class="mx-auto max-w-5xl">
            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('doc_required') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('doc_validity') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('doc_max_age') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('doc_records') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="type in documentTypes" :key="type.id" :class="{ 'opacity-60': !type.is_active }">
                            <td class="px-4 py-3">
                                <p class="text-sm font-medium text-slate-900 dark:text-slate-100">
                                    {{ type.localized_name || type.name }}
                                    <span v-if="!type.is_active" class="ms-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500 dark:bg-slate-800">{{ t('inactive') }}</span>
                                </p>
                                <p class="font-mono text-xs text-slate-400">{{ type.code }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <Badge v-if="type.is_required" :label="t('doc_required')" color="primary" />
                                <Badge v-else :label="t('doc_optional')" color="slate" />
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ validityLabels[type.validity] || type.validity }}</td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                {{ type.max_age ? t('doc_up_to_age', { age: type.max_age }) : t('doc_all_ages') }}
                            </td>
                            <td class="px-4 py-3 text-end text-sm text-slate-600 dark:text-slate-300">{{ type.player_documents_count }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-end">
                                <button type="button" class="text-sm text-slate-500 hover:text-slate-700 dark:hover:text-slate-200" @click="openEdit(type)">{{ t('edit') }}</button>
                                <button type="button" class="ms-3 text-sm text-slate-500 hover:text-slate-700 dark:hover:text-slate-200" @click="toggleActive(type)">
                                    {{ type.is_active ? t('doc_deactivate') : t('doc_activate') }}
                                </button>
                                <!-- Refused server-side too while any player has a record of this type. -->
                                <button v-if="type.player_documents_count === 0" type="button" class="ms-3 text-sm text-rose-500 hover:text-rose-700" @click="deleteId = type.id">{{ t('delete') }}</button>
                            </td>
                        </tr>
                        <tr v-if="!documentTypes.length">
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Modal :show="editing !== null" max-width="lg" @close="editing = null">
            <form class="space-y-4 p-6" @submit.prevent="save">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ editing ? t('doc_edit_type') : t('doc_add_type') }}</h3>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <InputLabel :value="t('name')" />
                        <TextInput v-model="form.name" class="mt-1 w-full" required />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="العربية" />
                        <TextInput v-model="form.name_ar" class="mt-1 w-full" dir="rtl" />
                    </div>
                    <div>
                        <InputLabel value="Français" />
                        <TextInput v-model="form.name_fr" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel value="English" />
                        <TextInput v-model="form.name_en" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel :value="t('doc_validity')" />
                        <select v-model="form.validity" :class="inputClass">
                            <option v-for="(label, value) in validityLabels" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <InputError :message="form.errors.validity" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('doc_max_age')" />
                        <input v-model="form.max_age" type="number" min="1" max="99" :class="inputClass" />
                        <InputError :message="form.errors.max_age" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('sort_order')" />
                        <input v-model="form.sort_order" type="number" min="0" :class="inputClass" />
                        <InputError :message="form.errors.sort_order" class="mt-1" />
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 sm:col-span-2">{{ t('doc_max_age_hint') }}</p>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input v-model="form.is_required" type="checkbox" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                        {{ t('doc_required') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                        {{ t('active') }}
                    </label>
                </div>

                <div class="flex justify-end gap-3">
                    <SecondaryButton type="button" @click="editing = null">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">{{ t('save') }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 3: The menu entry**

In `resources/js/Layouts/AuthenticatedLayout.vue`, replace:

```js
            { label: t('player_statuses'), href: '/player-statuses', icon: 'positions', prefix: '/player-statuses', module: 'categories' },
```

with:

```js
            { label: t('player_statuses'), href: '/player-statuses', icon: 'positions', prefix: '/player-statuses', module: 'categories' },
            { label: t('document_types'), href: '/document-types', icon: 'document', prefix: '/document-types', module: 'categories' },
```

- [ ] **Step 4: Verify**

Run: `npm run build` (succeeds), `node scripts/i18n-check.mjs` (`✓ …`), `php artisan test --filter=DocumentTypeSettingsTest` (PASS).

Manual: open `/document-types` — the seven defaults are listed; add a type, edit it, deactivate it (row greys out), delete an unused one (ConfirmModal); switch to Arabic, the layout is right-to-left and the names read in Arabic.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Settings/DocumentTypes.vue resources/js/Layouts/AuthenticatedLayout.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(documents): document types settings page"
git show --stat HEAD
```

---

### Task 6: The checklist — PHP states and their SQL twin

**Why:** The player page needs each document's state; the players list needs "how many are missing" for 25 rows at a time and as a filter over all players, **in SQL** (spec). Both must say exactly the same thing, so both live in one class and one test compares them.

**Files:**
- Create: `app/Services/Player/DocumentChecklist.php`
- Test: `tests/Feature/DocumentChecklistTest.php`

**Interfaces:**
- State constants on `App\Services\Player\DocumentChecklist`: `RECEIVED_SCANNED = 'received_scanned'`, `RECEIVED_PAPER = 'received_paper'`, `EXPIRES_SOON = 'expires_soon'`, `EXPIRED = 'expired'`, `MISSING = 'missing'`, `NOT_REQUIRED = 'not_required'`; `EXPIRES_SOON_DAYS = 30`.
- `DocumentChecklist::for(Player $player, ?CarbonInterface $today = null): array` →
  `['items' => list<item>, 'missing_count' => int, 'expiring_count' => int]`, where each item is
  `['type' => ['id','code','name','is_required','validity','max_age','is_active','is_photo'], 'state' => string, 'reason' => 'exempt'|'optional'|'age'|null, 'counts_missing' => bool, 'document' => null|['id','state','received_at','valid_until','exempt_reason','notes','recorded_by','files' => list<['id','original_name','mime','size','uploaded_at','uploaded_by']>]]`.
  Items: every **active** type, plus every **inactive** type the player has a row for (shown greyed), ordered like `DocumentType::ordered()`.
- `DocumentChecklist::evaluate(DocumentType $type, ?PlayerDocument $document, Player $player, ?CarbonInterface $today = null): array{state, reason, counts_missing}`.
- `DocumentChecklist::missingCountSql(?CarbonInterface $today = null, ?int $onlyTypeId = null): array{0: string, 1: array}` — an SQL expression (with bindings) usable wherever the query's `FROM` is `players`; `['0', []]` when no type qualifies. With `$onlyTypeId` it is 1/0 for that single type (0 for a type that is not active and required).
- `DocumentChecklist::expiringSoonSql(?CarbonInterface $today = null): array{0: string, 1: array}` — an `EXISTS (…)` condition.

**The exact rules (the contract both forms implement)** — `today` is the current date; `soon` is `today + 31 days` (so "within 30 days" is `today <= valid_until < soon`):

| Situation (checked in this order) | state | reason | counts as missing |
|---|---|---|---|
| Photo type, player has a non-empty `picture_url` | `received_scanned` | – | no |
| Photo type, exempt row | `not_required` | `exempt` | no |
| Photo type, otherwise | as "no row" below (any received row is ignored) | | |
| Exempt row | `not_required` | `exempt` | no |
| Received row, `valid_until < today` | `expired` | – | **yes** if the type is active, required and applies |
| Received row, `valid_until < soon` | `expires_soon` | – | no |
| Received row with ≥ 1 file | `received_scanned` | – | no |
| Received row, no file | `received_paper` | – | no |
| No row, type active + required + applies | `missing` | – | **yes** |
| No row, type does not apply (age) | `not_required` | `age` | no |
| No row, type optional (or inactive) | `not_required` | `optional` | no |

"Applies" = `DocumentType::appliesTo()`: no age limit, or no birth date, or `birthdate >= earliestApplicableBirthdate()`.

**SQL twin.** For each type that is active **and** required, one term (types are loaded in PHP, so the age cut-off is a PHP-computed date, identical to the one `appliesTo()` uses):

```sql
(CASE WHEN [applies] AND [missing] THEN 1 ELSE 0 END)
-- [applies] = (players.birthdate IS NULL OR players.birthdate >= :earliest)   -- only when max_age is set
-- photo:     [missing] = (players.picture_url IS NULL OR players.picture_url = '')
--                        AND NOT EXISTS (SELECT 1 FROM player_documents pd WHERE pd.player_id = players.id
--                                        AND pd.document_type_id = :type AND pd.state = 'exempt')
-- other:     [missing] = NOT EXISTS (SELECT 1 FROM player_documents pd WHERE pd.player_id = players.id
--                                    AND pd.document_type_id = :type AND (pd.state = 'exempt'
--                                    OR (pd.state = 'received' AND (pd.valid_until IS NULL OR pd.valid_until >= :today))))
```

The missing count is the sum of the terms. "Expiring soon" is `EXISTS (… pd JOIN document_types dt … WHERE dt.is_active = 1 AND dt.code <> 'photo' AND pd.state = 'received' AND pd.valid_until >= :today AND pd.valid_until < :soon)`, which matches the PHP `expiring_count` (items in `expires_soon` whose type is active).

Every date comparison is half-open (`>=` / `<` against a `Y-m-d` string): SQLite holds these columns as `Y-m-d 00:00:00` text, and `'2026-10-05 00:00:00' >= '2026-10-05'` is true while `'2026-10-05 00:00:00' <= '2026-10-05'` is false.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DocumentChecklistTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Services\Player\DocumentChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentChecklistTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function player(array $attributes = []): Player
    {
        $this->sequence++;

        return Player::create([
            'membership_id' => '2026'.str_pad((string) $this->sequence, 5, '0', STR_PAD_LEFT),
            'firstname' => 'Player'.$this->sequence,
            'lastname' => 'Test',
            'birthdate' => '1990-01-01',
            ...$attributes,
        ]);
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    private function received(Player $player, string $code, ?string $validUntil = null, int $files = 0): PlayerDocument
    {
        $document = PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => $this->type($code)->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => '2026-09-01',
            'valid_until' => $validUntil,
        ]);

        for ($i = 1; $i <= $files; $i++) {
            $document->files()->create([
                'path' => "player-documents/{$player->id}/scan-{$i}.pdf",
                'original_name' => "scan-{$i}.pdf",
                'mime' => 'application/pdf',
                'size' => 2048,
            ]);
        }

        return $document;
    }

    private function exempt(Player $player, string $code): PlayerDocument
    {
        return PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => $this->type($code)->id,
            'state' => PlayerDocument::EXEMPT,
            'exempt_reason' => 'Handled by the federation',
        ]);
    }

    /** @return array<string, string> code => state */
    private function states(Player $player): array
    {
        return collect(DocumentChecklist::for($player->fresh())['items'])
            ->mapWithKeys(fn (array $item) => [$item['type']['code'] => $item['state']])
            ->all();
    }

    private function item(Player $player, string $code): ?array
    {
        return collect(DocumentChecklist::for($player->fresh())['items'])
            ->first(fn (array $item) => $item['type']['code'] === $code);
    }

    private function missing(Player $player): int
    {
        return DocumentChecklist::for($player->fresh())['missing_count'];
    }

    #[Test]
    public function an_adult_with_nothing_is_missing_the_three_documents_every_player_needs(): void
    {
        $player = $this->player();

        $this->assertSame([
            'birth_certificate' => 'missing',
            'photo' => 'missing',
            'medical_certificate' => 'missing',
            'parental_authorization' => 'not_required',
            'id_card_copy' => 'not_required',
            'residence_certificate' => 'not_required',
            'school_certificate' => 'not_required',
        ], $this->states($player));
        $this->assertSame(3, $this->missing($player));
        $this->assertSame('age', $this->item($player, 'parental_authorization')['reason']);
        $this->assertSame('optional', $this->item($player, 'id_card_copy')['reason']);
    }

    #[Test]
    public function a_minor_also_needs_the_parental_authorization(): void
    {
        $player = $this->player(['birthdate' => '2012-03-01']);

        $this->assertSame('missing', $this->states($player)['parental_authorization']);
        $this->assertSame(4, $this->missing($player));
    }

    #[Test]
    public function a_player_without_a_birth_date_is_not_age_limited(): void
    {
        $player = $this->player(['birthdate' => null]);

        $this->assertSame('missing', $this->states($player)['parental_authorization']);
        $this->assertSame(4, $this->missing($player));
    }

    #[Test]
    public function the_age_limit_ends_on_the_eighteenth_birthday(): void
    {
        // Today is 2026-10-05.
        $seventeen = $this->player(['birthdate' => '2008-10-06']); // 18 tomorrow
        $eighteen = $this->player(['birthdate' => '2008-10-05']);  // 18 today

        $this->assertSame('missing', $this->states($seventeen)['parental_authorization']);
        $this->assertSame('not_required', $this->states($eighteen)['parental_authorization']);
    }

    #[Test]
    public function the_photo_is_the_profile_picture(): void
    {
        $player = $this->player(['picture_url' => '/media/players/amine.jpg']);

        $this->assertSame('received_scanned', $this->states($player)['photo']);
        $this->assertTrue($this->item($player, 'photo')['type']['is_photo']);
        $this->assertSame(2, $this->missing($player));
    }

    #[Test]
    public function a_received_document_is_scanned_or_on_paper(): void
    {
        $player = $this->player();
        $this->received($player, 'birth_certificate', null, 2);
        $this->received($player, 'residence_certificate');

        $this->assertSame('received_scanned', $this->states($player)['birth_certificate']);
        $this->assertSame('received_paper', $this->states($player)['residence_certificate']);

        $document = $this->item($player, 'birth_certificate')['document'];
        $this->assertSame('2026-09-01', $document['received_at']);
        $this->assertSame(['scan-1.pdf', 'scan-2.pdf'], array_column($document['files'], 'original_name'));
        $this->assertArrayNotHasKey('path', $document['files'][0]);
    }

    #[Test]
    public function a_document_expiring_within_thirty_days_is_flagged_but_not_missing(): void
    {
        $soon = $this->player();
        $this->received($soon, 'medical_certificate', '2026-11-04'); // today + 30

        $later = $this->player();
        $this->received($later, 'medical_certificate', '2026-11-05'); // today + 31

        $this->assertSame('expires_soon', $this->states($soon)['medical_certificate']);
        $this->assertSame(2, $this->missing($soon));
        $this->assertSame(1, DocumentChecklist::for($soon->fresh())['expiring_count']);

        $this->assertSame('received_paper', $this->states($later)['medical_certificate']);
        $this->assertSame(0, DocumentChecklist::for($later->fresh())['expiring_count']);
    }

    #[Test]
    public function a_document_valid_until_today_is_still_valid(): void
    {
        $player = $this->player();
        $this->received($player, 'medical_certificate', '2026-10-05');

        $this->assertSame('expires_soon', $this->states($player)['medical_certificate']);
    }

    #[Test]
    public function an_expired_required_document_counts_as_missing(): void
    {
        $player = $this->player();
        $this->received($player, 'medical_certificate', '2026-10-04');

        $item = $this->item($player, 'medical_certificate');
        $this->assertSame('expired', $item['state']);
        $this->assertTrue($item['counts_missing']);
        $this->assertSame(3, $this->missing($player));
    }

    #[Test]
    public function an_expired_optional_document_is_shown_but_not_counted(): void
    {
        $player = $this->player();
        $this->received($player, 'school_certificate', '2026-08-31');

        $item = $this->item($player, 'school_certificate');
        $this->assertSame('expired', $item['state']);
        $this->assertFalse($item['counts_missing']);
        $this->assertSame(3, $this->missing($player));
    }

    #[Test]
    public function an_exempted_document_is_not_required(): void
    {
        $player = $this->player();
        $this->exempt($player, 'birth_certificate');
        $this->exempt($player, 'photo');

        $this->assertSame('not_required', $this->states($player)['birth_certificate']);
        $this->assertSame('exempt', $this->item($player, 'birth_certificate')['reason']);
        $this->assertSame('Handled by the federation', $this->item($player, 'birth_certificate')['document']['exempt_reason']);
        $this->assertSame('not_required', $this->states($player)['photo']);
        $this->assertSame(1, $this->missing($player));
    }

    #[Test]
    public function an_inactive_type_is_listed_only_where_a_record_exists_and_never_counts(): void
    {
        $without = $this->player();
        $with = $this->player();
        $this->received($with, 'medical_certificate', '2026-10-01');
        $this->type('medical_certificate')->update(['is_active' => false]);

        $this->assertArrayNotHasKey('medical_certificate', $this->states($without));
        $this->assertSame(2, $this->missing($without));

        $item = $this->item($with, 'medical_certificate');
        $this->assertFalse($item['type']['is_active']);
        $this->assertSame('expired', $item['state']);
        $this->assertFalse($item['counts_missing']);
        $this->assertSame(2, $this->missing($with));
    }

    #[Test]
    public function an_optional_or_inactive_type_never_matches_the_missing_type_filter(): void
    {
        $this->assertSame(['0', []], DocumentChecklist::missingCountSql(null, $this->type('school_certificate')->id));

        $this->type('medical_certificate')->update(['is_active' => false]);
        $this->assertSame(['0', []], DocumentChecklist::missingCountSql(null, $this->type('medical_certificate')->id));
    }

    #[Test]
    public function the_sql_count_agrees_with_the_checklist_for_every_situation(): void
    {
        $players = $this->fixtures();

        $this->assertAgreement($players);

        // Rules change under existing records: a type is switched off, another
        // loses its age limit. Both forms must follow.
        $this->type('medical_certificate')->update(['is_active' => false]);
        $this->type('parental_authorization')->update(['max_age' => null]);

        $this->assertAgreement($players);
    }

    /** @return array<string, Player> label => player */
    private function fixtures(): array
    {
        $players = [
            'adult, nothing' => $this->player(),
            'minor, nothing' => $this->player(['birthdate' => '2012-03-01']),
            'no birth date' => $this->player(['birthdate' => null]),
            '18 tomorrow' => $this->player(['birthdate' => '2008-10-06']),
            '18 today' => $this->player(['birthdate' => '2008-10-05']),
            'picture' => $this->player(['picture_url' => '/media/players/a.jpg']),
            'empty picture string' => $this->player(['picture_url' => '']),
            'scanned' => $this->player(),
            'expires soon' => $this->player(),
            'valid today' => $this->player(),
            'expired yesterday' => $this->player(),
            'expired optional' => $this->player(),
            'exempt' => $this->player(),
            'exempt photo' => $this->player(),
            'complete adult' => $this->player(['picture_url' => '/media/players/b.jpg']),
            'complete minor' => $this->player(['birthdate' => '2013-05-05', 'picture_url' => '/media/players/c.jpg']),
            'minor, expired parental' => $this->player(['birthdate' => '2011-01-01']),
        ];

        $this->received($players['scanned'], 'birth_certificate', null, 1);
        $this->received($players['expires soon'], 'medical_certificate', '2026-11-04');
        $this->received($players['valid today'], 'medical_certificate', '2026-10-05');
        $this->received($players['expired yesterday'], 'medical_certificate', '2026-10-04');
        $this->received($players['expired optional'], 'school_certificate', '2026-08-31');
        $this->exempt($players['exempt'], 'medical_certificate');
        $this->exempt($players['exempt photo'], 'photo');

        foreach (['complete adult', 'complete minor'] as $label) {
            $this->received($players[$label], 'birth_certificate', null, 1);
            $this->received($players[$label], 'medical_certificate', '2027-08-31');
        }
        $this->received($players['complete minor'], 'parental_authorization', '2027-08-31');
        $this->received($players['minor, expired parental'], 'parental_authorization', '2026-08-31');

        return $players;
    }

    /** @param array<string, Player> $players */
    private function assertAgreement(array $players): void
    {
        [$sql, $bindings] = DocumentChecklist::missingCountSql();
        $sqlCounts = Player::query()
            ->select('players.id')
            ->selectRaw("{$sql} as missing_documents_count", $bindings)
            ->pluck('missing_documents_count', 'id')
            ->map(fn ($count) => (int) $count);

        $checklists = [];
        foreach ($players as $label => $player) {
            $checklists[$label] = DocumentChecklist::for($player->fresh());
            $this->assertSame($checklists[$label]['missing_count'], $sqlCounts[$player->id], "missing count: {$label}");
        }

        // Per type: the "missing type X" filter selects exactly the players whose
        // checklist counts that type as missing.
        $required = DocumentType::where('is_active', true)->where('is_required', true)->get();
        foreach ($required as $type) {
            [$typeSql, $typeBindings] = DocumentChecklist::missingCountSql(null, $type->id);
            $fromSql = Player::query()->whereRaw("{$typeSql} > 0", $typeBindings)->orderBy('id')->pluck('id')->all();

            $fromPhp = collect($players)
                ->filter(fn (Player $player, string $label) => collect($checklists[$label]['items'])
                    ->contains(fn (array $item) => $item['type']['id'] === $type->id && $item['counts_missing']))
                ->map(fn (Player $player) => $player->id)
                ->sort()->values()->all();

            $this->assertSame($fromPhp, $fromSql, "missing {$type->code}");
        }

        // Expiring soon.
        [$expiringSql, $expiringBindings] = DocumentChecklist::expiringSoonSql();
        $fromSql = Player::query()->whereRaw($expiringSql, $expiringBindings)->orderBy('id')->pluck('id')->all();
        $fromPhp = collect($players)
            ->filter(fn (Player $player, string $label) => $checklists[$label]['expiring_count'] > 0)
            ->map(fn (Player $player) => $player->id)
            ->sort()->values()->all();

        $this->assertSame($fromPhp, $fromSql, 'expiring soon');
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan config:clear; php artisan test --filter=DocumentChecklistTest`

Expected: FAIL with `Class "App\Services\Player\DocumentChecklist" not found`.

- [ ] **Step 3: The checklist**

Create `app/Services/Player/DocumentChecklist.php`:

```php
<?php

namespace App\Services\Player;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDocumentFile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Which documents a player has, lacks, or no longer needs.
 *
 * Two forms of ONE rule set:
 *  - for() / evaluate(): the per-document states the player page shows;
 *  - missingCountSql() / expiringSoonSql(): the same rules as SQL, so the
 *    players list can count and filter over every player without loading
 *    their documents into PHP.
 *
 * DocumentChecklistTest runs both over the same fixtures and asserts they
 * agree. Change one, change the other, and keep that test green.
 *
 * Dates: every SQL comparison is half-open against a Y-m-d string (>= / <),
 * because SQLite keeps these columns as "Y-m-d 00:00:00" text and a closed
 * comparison (<=) would miss the boundary day. The PHP side uses the same
 * boundaries: expired = valid_until < today; expires soon = valid_until <
 * today + 31 days; applies = birthdate >= DocumentType::earliestApplicableBirthdate().
 */
final class DocumentChecklist
{
    public const RECEIVED_SCANNED = 'received_scanned';

    public const RECEIVED_PAPER = 'received_paper';

    public const EXPIRES_SOON = 'expires_soon';

    public const EXPIRED = 'expired';

    public const MISSING = 'missing';

    public const NOT_REQUIRED = 'not_required';

    public const EXPIRES_SOON_DAYS = 30;

    /**
     * The player page's checklist: every active type, plus any inactive type
     * the player still has a record for (shown greyed, never counted).
     *
     * @return array{items: list<array<string, mixed>>, missing_count: int, expiring_count: int}
     */
    public static function for(Player $player, ?CarbonInterface $today = null): array
    {
        $today = self::today($today);

        $documents = $player->documents()
            ->with(['files.uploadedBy:id,name', 'recordedBy:id,name'])
            ->get()
            ->keyBy('document_type_id');

        $types = DocumentType::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $documents->keys()->all()))
            ->ordered()
            ->get();

        $items = $types
            ->map(fn (DocumentType $type) => self::item($type, $documents->get($type->id), $player, $today))
            ->values()
            ->all();

        return [
            'items' => $items,
            'missing_count' => count(array_filter($items, fn (array $item) => $item['counts_missing'])),
            'expiring_count' => count(array_filter(
                $items,
                fn (array $item) => $item['state'] === self::EXPIRES_SOON && $item['type']['is_active']
            )),
        ];
    }

    /**
     * The state of one type for one player. See the rule table in the P3 plan
     * (Task 6); the order of the checks below IS that table.
     *
     * @return array{state: string, reason: ?string, counts_missing: bool}
     */
    public static function evaluate(DocumentType $type, ?PlayerDocument $document, Player $player, ?CarbonInterface $today = null): array
    {
        $today = self::today($today);
        $applies = $type->appliesTo($player, $today);
        $expected = $type->is_active && $type->is_required && $applies;

        // Owner decision: the Photo is the profile picture. A received row for it
        // is ignored — the picture is the only thing that makes it "received".
        if ($type->isPhoto()) {
            $picture = $player->getAttributes()['picture_url'] ?? null;

            if ($picture !== null && $picture !== '') {
                return self::result(self::RECEIVED_SCANNED);
            }

            if ($document?->state === PlayerDocument::EXEMPT) {
                return self::result(self::NOT_REQUIRED, 'exempt');
            }

            return self::absent($applies, $expected);
        }

        if ($document === null) {
            return self::absent($applies, $expected);
        }

        if ($document->state === PlayerDocument::EXEMPT) {
            return self::result(self::NOT_REQUIRED, 'exempt');
        }

        $validUntil = $document->valid_until;

        if ($validUntil !== null && $validUntil->lt($today)) {
            return self::result(self::EXPIRED, null, $expected);
        }

        if ($validUntil !== null && $validUntil->lt($today->addDays(self::EXPIRES_SOON_DAYS + 1))) {
            return self::result(self::EXPIRES_SOON);
        }

        return self::result($document->files->isNotEmpty() ? self::RECEIVED_SCANNED : self::RECEIVED_PAPER);
    }

    /**
     * How many documents a player is missing, as an SQL expression over
     * `players` — the twin of for()['missing_count']. With $onlyTypeId it is
     * 1/0 for that single type (the "missing type X" filter).
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function missingCountSql(?CarbonInterface $today = null, ?int $onlyTypeId = null): array
    {
        $today = self::today($today);

        $types = DocumentType::query()
            ->where('is_active', true)
            ->where('is_required', true)
            ->when($onlyTypeId !== null, fn ($query) => $query->whereKey($onlyTypeId))
            ->orderBy('id')
            ->get();

        if ($types->isEmpty()) {
            return ['0', []];
        }

        $terms = [];
        $bindings = [];

        foreach ($types as $type) {
            [$term, $termBindings] = self::missingTermSql($type, $today);
            $terms[] = $term;
            array_push($bindings, ...$termBindings);
        }

        return ['('.implode(' + ', $terms).')', $bindings];
    }

    /**
     * "Has an active document that expires within 30 days", as an SQL
     * condition over `players` — the twin of for()['expiring_count'] > 0.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function expiringSoonSql(?CarbonInterface $today = null): array
    {
        $today = self::today($today);

        return [
            'EXISTS (SELECT 1 FROM player_documents pd'
                .' INNER JOIN document_types dt ON dt.id = pd.document_type_id'
                .' WHERE pd.player_id = players.id AND dt.is_active = 1 AND dt.code <> ?'
                ." AND pd.state = 'received' AND pd.valid_until >= ? AND pd.valid_until < ?)",
            [
                DocumentType::PHOTO,
                $today->toDateString(),
                $today->addDays(self::EXPIRES_SOON_DAYS + 1)->toDateString(),
            ],
        ];
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private static function missingTermSql(DocumentType $type, CarbonImmutable $today): array
    {
        $conditions = [];
        $bindings = [];

        $earliest = $type->earliestApplicableBirthdate($today);
        if ($earliest !== null) {
            $conditions[] = '(players.birthdate IS NULL OR players.birthdate >= ?)';
            $bindings[] = $earliest->toDateString();
        }

        if ($type->isPhoto()) {
            $conditions[] = "(players.picture_url IS NULL OR players.picture_url = '')";
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM player_documents pd'
                .' WHERE pd.player_id = players.id AND pd.document_type_id = ?'
                ." AND pd.state = 'exempt')";
            $bindings[] = $type->id;
        } else {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM player_documents pd'
                .' WHERE pd.player_id = players.id AND pd.document_type_id = ?'
                ." AND (pd.state = 'exempt' OR (pd.state = 'received'"
                .' AND (pd.valid_until IS NULL OR pd.valid_until >= ?))))';
            $bindings[] = $type->id;
            $bindings[] = $today->toDateString();
        }

        return ['(CASE WHEN '.implode(' AND ', $conditions).' THEN 1 ELSE 0 END)', $bindings];
    }

    /** @return array<string, mixed> */
    private static function item(DocumentType $type, ?PlayerDocument $document, Player $player, CarbonImmutable $today): array
    {
        return [
            'type' => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->localized_name,
                'is_required' => $type->is_required,
                'validity' => $type->validity,
                'max_age' => $type->max_age,
                'is_active' => $type->is_active,
                'is_photo' => $type->isPhoto(),
            ],
            ...self::evaluate($type, $document, $player, $today),
            'document' => $document === null ? null : [
                'id' => $document->id,
                'state' => $document->state,
                'received_at' => $document->received_at?->toDateString(),
                'valid_until' => $document->valid_until?->toDateString(),
                'exempt_reason' => $document->exempt_reason,
                'notes' => $document->notes,
                'recorded_by' => $document->recordedBy?->name,
                'files' => $document->files->map(fn (PlayerDocumentFile $file) => [
                    'id' => $file->id,
                    'original_name' => $file->original_name,
                    'mime' => $file->mime,
                    'size' => $file->size,
                    'uploaded_at' => $file->created_at?->toDateString(),
                    'uploaded_by' => $file->uploadedBy?->name,
                ])->values()->all(),
            ],
        ];
    }

    /** No row (or a Photo without picture): missing if expected, otherwise why not. */
    private static function absent(bool $applies, bool $expected): array
    {
        if ($expected) {
            return self::result(self::MISSING, null, true);
        }

        return self::result(self::NOT_REQUIRED, $applies ? 'optional' : 'age');
    }

    /** @return array{state: string, reason: ?string, counts_missing: bool} */
    private static function result(string $state, ?string $reason = null, bool $countsMissing = false): array
    {
        return ['state' => $state, 'reason' => $reason, 'counts_missing' => $countsMissing];
    }

    private static function today(?CarbonInterface $today): CarbonImmutable
    {
        return CarbonImmutable::parse($today ?? CarbonImmutable::today())->startOfDay();
    }
}
```

- [ ] **Step 4: Run the test and confirm it passes**

Run: `php artisan test --filter=DocumentChecklistTest`

Expected: PASS (14 tests).

- [ ] **Step 5: Commit**

```bash
php vendor/bin/pint app/Services/Player/DocumentChecklist.php tests/Feature/DocumentChecklistTest.php
git add app/Services/Player/DocumentChecklist.php tests/Feature/DocumentChecklistTest.php
git commit -m "feat(documents): checklist states and their SQL twin, proven to agree"
git show --stat HEAD
```

---

### Task 7: Player document actions (backend)

**Why:** Spec "Player page" actions — mark received (date, and expiry for `date` types), upload one or more files, view, download, remove a file, renew, exempt with a reason, undo the exemption — each behind the `documents` permission (owner decision), with files on the private disk.

**Files:**
- Create: `app/Services/Player/PlayerDocumentService.php`
- Create: `app/Http/Controllers/PlayerDocumentController.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/PlayerController.php` (`show()` gains the `documents` prop)
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script — flash keys)
- Test: `tests/Feature/PlayerDocumentActionsTest.php`

**Interfaces:**
- Routes (in the authenticated group, after the player subscription routes):

| Method + URI | Name | Action |
|---|---|---|
| `POST /players/{player}/documents` | `players.documents.store` | mark received (fields `document_type_id`, `received_at`, `valid_until`, `notes`, `files[]`) |
| `POST /players/{player}/documents/exempt` | `players.documents.exempt` | exempt (`document_type_id`, `reason`) |
| `PUT /players/{player}/documents/{document}` | `players.documents.update` | renew (`received_at`, `valid_until`, `notes`, `files[]`; multipart sent as POST + `_method=put`) |
| `DELETE /players/{player}/documents/{document}/exempt` | `players.documents.unexempt` | undo exemption |
| `POST /players/{player}/documents/{document}/files` | `players.documents.files.store` | add files (`files[]`) |
| `GET /players/{player}/documents/files/{file}` | `players.documents.files.show` | view inline |
| `GET /players/{player}/documents/files/{file}/download` | `players.documents.files.download` | download |
| `DELETE /players/{player}/documents/files/{file}` | `players.documents.files.destroy` | remove a file |

- Upload rules: each file `mimes:pdf,jpg,jpeg,png,webp`, `max:10240` (10 MB); at most 10 files per request.
- `App\Services\Player\PlayerDocumentService` (constructor-injected `PrivateFileStorage`):
  - `markReceived(Player, DocumentType, array $receipt, array $files, ?User $by): PlayerDocument`
  - `renew(PlayerDocument, array $receipt, array $files, ?User $by): void`
  - `exempt(Player, DocumentType, string $reason, ?User $by): PlayerDocument`
  - `unexempt(PlayerDocument): void`
  - `attach(PlayerDocument, array $files, ?User $by): void`
  - `removeFile(PlayerDocumentFile): void`
  - `purgePlayerRecords(Player): void` and `purgePlayerFiles(int $playerId): void` (used by Task 11)
  - `$receipt` = `['received_at' => 'Y-m-d', 'valid_until' => ?'Y-m-d', 'notes' => ?string]`
- `players.show` gains the prop `documents`: `DocumentChecklist::for($player)` when the viewer has `documents/view`, otherwise `null`.
- Flash keys: `flash.document_received`, `flash.document_renewed`, `flash.document_exempted`, `flash.document_exemption_removed`, `flash.document_files_uploaded`, `flash.document_file_removed`, `flash.document_already_recorded`, `flash.document_not_received`, `flash.document_not_exempt`, `flash.document_photo_is_profile_picture`.

**Decisions this task implements (edge cases):**
- The Photo type cannot be marked received nor receive files (its only source is the profile picture); it can be exempted.
- "Mark received" on a type that already has a row is refused (`document_already_recorded`) — the user renews instead.
- Renew keeps the row and every earlier file (history), moves `received_at` / `valid_until`, replaces `notes`. Only a `received` row can be renewed or receive files.
- Exempt works with or without an existing row; on a received row it keeps the dates and files. Undo turns a row that had been received back into `received`, and deletes a row that never was.
- Changing a type's validity later does not recompute existing `valid_until` values.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PlayerDocumentActionsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDocumentFile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerDocumentActionsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05');
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    /** A non-god user whose role grants exactly $permissions. */
    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::create([
                'key' => 'role-'.uniqid(),
                'name' => ['en' => 'Role'],
                'permissions' => $permissions,
            ])->id,
        ]);
    }

    private function player(): Player
    {
        $this->sequence++;

        return Player::create([
            'membership_id' => '20269'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'firstname' => 'Amine',
            'lastname' => 'Saadi',
            'birthdate' => '1995-04-04',
        ]);
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    private function pdf(string $name = 'scan.pdf', int $kilobytes = 200): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
    }

    private function markReceived(Player $player, string $code, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin())->post(route('players.documents.store', $player), [
            'document_type_id' => $this->type($code)->id,
            'received_at' => '2026-10-05',
            ...$extra,
        ]);
    }

    #[Test]
    public function a_season_document_is_valid_until_the_end_of_the_season(): void
    {
        $player = $this->player();

        $this->markReceived($player, 'medical_certificate')
            ->assertRedirect()
            ->assertSessionHas('success', 'flash.document_received');

        $document = $player->documents()->firstOrFail();
        $this->assertSame(PlayerDocument::RECEIVED, $document->state);
        $this->assertSame('2026-10-05', $document->received_at->toDateString());
        $this->assertSame('2027-08-31', $document->valid_until->toDateString());
    }

    #[Test]
    public function a_date_document_needs_its_expiry_date(): void
    {
        $player = $this->player();

        $this->markReceived($player, 'id_card_copy')->assertSessionHasErrors('valid_until');
        $this->markReceived($player, 'id_card_copy', ['valid_until' => '2026-01-01'])->assertSessionHasErrors('valid_until');

        $this->markReceived($player, 'id_card_copy', ['valid_until' => '2031-06-30'])->assertSessionHasNoErrors();
        $this->assertSame('2031-06-30', $player->documents()->firstOrFail()->valid_until->toDateString());
    }

    #[Test]
    public function a_plain_document_never_expires_and_cannot_be_received_in_the_future(): void
    {
        $player = $this->player();

        $this->markReceived($player, 'birth_certificate', ['received_at' => '2026-10-06'])->assertSessionHasErrors('received_at');

        $this->markReceived($player, 'birth_certificate', ['notes' => 'Original seen'])->assertSessionHasNoErrors();
        $document = $player->documents()->firstOrFail();
        $this->assertNull($document->valid_until);
        $this->assertSame('Original seen', $document->notes);
    }

    #[Test]
    public function several_files_go_to_the_private_disk_with_their_names(): void
    {
        $player = $this->player();

        $this->markReceived($player, 'birth_certificate', [
            'files' => [$this->pdf('page 1.pdf'), UploadedFile::fake()->image('page-2.jpg')],
        ])->assertSessionHasNoErrors();

        $files = PlayerDocumentFile::orderBy('id')->get();
        $this->assertSame(['page 1.pdf', 'page-2.jpg'], $files->pluck('original_name')->all());

        foreach ($files as $file) {
            $this->assertStringStartsWith("player-documents/{$player->id}/", $file->path);
            Storage::disk('local')->assertExists($file->path);
            Storage::disk('public')->assertMissing($file->path);
        }
    }

    #[Test]
    public function only_scans_and_photos_up_to_ten_megabytes_are_accepted(): void
    {
        $player = $this->player();

        $this->markReceived($player, 'birth_certificate', [
            'files' => [UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')],
        ])->assertSessionHasErrors('files.0');

        $this->markReceived($player, 'birth_certificate', [
            'files' => [$this->pdf('huge.pdf', 10241)],
        ])->assertSessionHasErrors('files.0');

        $this->assertSame(0, PlayerDocument::count());
    }

    #[Test]
    public function the_photo_comes_from_the_profile_picture_only(): void
    {
        $player = $this->player();

        $this->markReceived($player, 'photo')
            ->assertRedirect()
            ->assertSessionHas('error', 'flash.document_photo_is_profile_picture');

        $this->assertSame(0, PlayerDocument::count());
    }

    #[Test]
    public function a_document_cannot_be_marked_received_twice(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate');

        $this->markReceived($player, 'birth_certificate')
            ->assertSessionHas('error', 'flash.document_already_recorded');

        $this->assertSame(1, PlayerDocument::count());
    }

    #[Test]
    public function an_inactive_type_cannot_be_recorded(): void
    {
        $player = $this->player();
        $this->type('school_certificate')->update(['is_active' => false]);

        $this->markReceived($player, 'school_certificate')->assertSessionHasErrors('document_type_id');
    }

    #[Test]
    public function renewing_moves_the_dates_and_keeps_the_earlier_files(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'medical_certificate', ['received_at' => '2025-10-01', 'files' => [$this->pdf('2025.pdf')]]);
        $document = $player->documents()->firstOrFail();
        $this->assertSame('2026-08-31', $document->valid_until->toDateString());

        $this->actingAs($this->admin())->put(route('players.documents.update', [$player, $document]), [
            'received_at' => '2026-10-05',
            'notes' => 'Renewed',
            'files' => [$this->pdf('2026.pdf')],
        ])->assertRedirect()->assertSessionHas('success', 'flash.document_renewed');

        $document->refresh();
        $this->assertSame('2026-10-05', $document->received_at->toDateString());
        $this->assertSame('2027-08-31', $document->valid_until->toDateString());
        $this->assertSame('Renewed', $document->notes);
        $this->assertSame(['2025.pdf', '2026.pdf'], $document->files()->pluck('original_name')->all());
    }

    #[Test]
    public function a_document_can_be_exempted_with_a_reason_and_the_exemption_undone(): void
    {
        $player = $this->player();

        $this->actingAs($this->admin())->post(route('players.documents.exempt', $player), [
            'document_type_id' => $this->type('medical_certificate')->id,
            'reason' => 'x',
        ])->assertSessionHasErrors('reason');

        $this->actingAs($this->admin())->post(route('players.documents.exempt', $player), [
            'document_type_id' => $this->type('medical_certificate')->id,
            'reason' => 'Checked by the federation doctor',
        ])->assertSessionHas('success', 'flash.document_exempted');

        $document = $player->documents()->firstOrFail();
        $this->assertSame(PlayerDocument::EXEMPT, $document->state);
        $this->assertSame('Checked by the federation doctor', $document->exempt_reason);

        $this->actingAs($this->admin())->delete(route('players.documents.unexempt', [$player, $document]))
            ->assertSessionHas('success', 'flash.document_exemption_removed');

        // Never received: undoing the exemption leaves nothing behind.
        $this->assertSame(0, PlayerDocument::count());
    }

    #[Test]
    public function undoing_the_exemption_of_a_received_document_brings_it_back(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate', ['files' => [$this->pdf()]]);
        $document = $player->documents()->firstOrFail();

        $this->actingAs($this->admin())->post(route('players.documents.exempt', $player), [
            'document_type_id' => $this->type('birth_certificate')->id,
            'reason' => 'Not needed for this competition',
        ]);
        $this->assertSame(PlayerDocument::EXEMPT, $document->fresh()->state);
        $this->assertSame(1, $document->files()->count(), 'exempting keeps the files');

        $this->actingAs($this->admin())->delete(route('players.documents.unexempt', [$player, $document]));

        $document->refresh();
        $this->assertSame(PlayerDocument::RECEIVED, $document->state);
        $this->assertNull($document->exempt_reason);
        $this->assertSame('2026-10-05', $document->received_at->toDateString());
    }

    #[Test]
    public function the_photo_can_be_exempted(): void
    {
        $player = $this->player();

        $this->actingAs($this->admin())->post(route('players.documents.exempt', $player), [
            'document_type_id' => $this->type('photo')->id,
            'reason' => 'Refuses to be photographed',
        ])->assertSessionHas('success', 'flash.document_exempted');
    }

    #[Test]
    public function files_can_be_added_to_a_received_document_only(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate');
        $document = $player->documents()->firstOrFail();

        $this->actingAs($this->admin())->post(route('players.documents.files.store', [$player, $document]), [])
            ->assertSessionHasErrors('files');

        $this->actingAs($this->admin())->post(route('players.documents.files.store', [$player, $document]), [
            'files' => [$this->pdf('a.pdf'), $this->pdf('b.pdf')],
        ])->assertSessionHas('success', 'flash.document_files_uploaded');
        $this->assertSame(2, $document->files()->count());

        $document->update(['state' => PlayerDocument::EXEMPT]);
        $this->actingAs($this->admin())->post(route('players.documents.files.store', [$player, $document]), [
            'files' => [$this->pdf('c.pdf')],
        ])->assertSessionHas('error', 'flash.document_not_received');
    }

    #[Test]
    public function a_file_is_viewed_inline_and_downloaded_under_its_original_name(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate', ['files' => [$this->pdf('Acte de naissance.pdf')]]);
        $file = PlayerDocumentFile::firstOrFail();

        $inline = $this->actingAs($this->admin())->get(route('players.documents.files.show', [$player, $file]));
        $inline->assertOk();
        $this->assertStringStartsWith('inline', $inline->headers->get('Content-Disposition'));
        $inline->assertHeader('X-Content-Type-Options', 'nosniff');
        $inline->assertHeader('Content-Type', 'application/pdf');

        $download = $this->actingAs($this->admin())->get(route('players.documents.files.download', [$player, $file]));
        $download->assertOk();
        $this->assertStringStartsWith('attachment', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Acte de naissance.pdf', $download->headers->get('Content-Disposition'));
    }

    #[Test]
    public function a_file_cannot_be_reached_through_another_player(): void
    {
        $owner = $this->player();
        $other = $this->player();
        $this->markReceived($owner, 'birth_certificate', ['files' => [$this->pdf()]]);
        $file = PlayerDocumentFile::firstOrFail();

        $this->actingAs($this->admin())->get(route('players.documents.files.show', [$other, $file]))->assertNotFound();
        $this->actingAs($this->admin())->delete(route('players.documents.files.destroy', [$other, $file]))->assertNotFound();
        $this->assertNotNull($file->fresh());
    }

    #[Test]
    public function the_public_media_route_never_serves_a_document(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate', ['files' => [$this->pdf()]]);
        $file = PlayerDocumentFile::firstOrFail();

        $this->get('/media/'.$file->path)->assertNotFound();
    }

    #[Test]
    public function removing_a_file_deletes_it_from_the_disk_and_keeps_the_document(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate', ['files' => [$this->pdf()]]);
        $file = PlayerDocumentFile::firstOrFail();
        $path = $file->path;

        $this->actingAs($this->admin())->delete(route('players.documents.files.destroy', [$player, $file]))
            ->assertSessionHas('success', 'flash.document_file_removed');

        $this->assertNull($file->fresh());
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(PlayerDocument::RECEIVED, $player->documents()->firstOrFail()->state);
    }

    #[Test]
    public function every_document_action_needs_the_documents_module(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate', ['files' => [$this->pdf()]]);
        $document = $player->documents()->firstOrFail();
        $file = PlayerDocumentFile::firstOrFail();

        // Full player rights, no documents rights.
        $coach = $this->userWith(['players' => Role::ACTIONS]);

        $this->actingAs($coach)->get(route('players.documents.files.show', [$player, $file]))->assertForbidden();
        $this->actingAs($coach)->get(route('players.documents.files.download', [$player, $file]))->assertForbidden();
        $this->markReceived($player, 'medical_certificate', [], $coach)->assertForbidden();
        $this->actingAs($coach)->post(route('players.documents.exempt', $player), ['document_type_id' => $this->type('medical_certificate')->id, 'reason' => 'Because'])->assertForbidden();
        $this->actingAs($coach)->delete(route('players.documents.files.destroy', [$player, $file]))->assertForbidden();

        // View only: can open and download, cannot change anything.
        $viewer = $this->userWith(['players' => ['view'], 'documents' => ['view']]);

        $this->actingAs($viewer)->get(route('players.documents.files.show', [$player, $file]))->assertOk();
        $this->actingAs($viewer)->get(route('players.documents.files.download', [$player, $file]))->assertOk();
        $this->markReceived($player, 'medical_certificate', [], $viewer)->assertForbidden();
        $this->actingAs($viewer)->put(route('players.documents.update', [$player, $document]), ['received_at' => '2026-10-05'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('players.documents.files.destroy', [$player, $file]))->assertForbidden();
    }

    #[Test]
    public function the_player_page_carries_the_checklist_only_for_document_viewers(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate');

        $props = $this->actingAs($this->admin())->get(route('players.show', $player))->assertOk()->viewData('page')['props'];
        $this->assertSame(2, $props['documents']['missing_count']);
        $this->assertSame('birth_certificate', $props['documents']['items'][0]['type']['code']);

        $coach = $this->userWith(['players' => Role::ACTIONS]);
        $props = $this->actingAs($coach)->get(route('players.show', $player))->assertOk()->viewData('page')['props'];
        $this->assertNull($props['documents']);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan config:clear; php artisan test --filter=PlayerDocumentActionsTest`

Expected: FAIL with `Route [players.documents.store] not defined.`

- [ ] **Step 3: The service**

Create `app/Services/Player/PlayerDocumentService.php`:

```php
<?php

namespace App\Services\Player;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDocumentFile;
use App\Models\User;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Everything that changes a player's documents. The controller validates and
 * checks permissions; this class keeps rows and files in step.
 */
class PlayerDocumentService
{
    public function __construct(private PrivateFileStorage $storage) {}

    /**
     * @param  array{received_at: string, valid_until: ?string, notes: ?string}  $receipt
     * @param  list<UploadedFile>  $files
     */
    public function markReceived(Player $player, DocumentType $type, array $receipt, array $files, ?User $by): PlayerDocument
    {
        $document = PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => $type->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => $receipt['received_at'],
            'valid_until' => $type->validUntilFor($receipt['received_at'], $receipt['valid_until']),
            'notes' => $receipt['notes'],
            'recorded_by_user_id' => $by?->id,
        ]);

        $this->attach($document, $files, $by);

        return $document;
    }

    /**
     * Renewal moves the dates forward on the same row. Earlier files stay
     * attached, with their upload dates, as the document's history.
     *
     * @param  array{received_at: string, valid_until: ?string, notes: ?string}  $receipt
     * @param  list<UploadedFile>  $files
     */
    public function renew(PlayerDocument $document, array $receipt, array $files, ?User $by): void
    {
        $document->update([
            'received_at' => $receipt['received_at'],
            'valid_until' => $document->type->validUntilFor($receipt['received_at'], $receipt['valid_until']),
            'notes' => $receipt['notes'],
            'recorded_by_user_id' => $by?->id,
        ]);

        $this->attach($document, $files, $by);
    }

    /** With or without an existing row; a received row keeps its dates and files. */
    public function exempt(Player $player, DocumentType $type, string $reason, ?User $by): PlayerDocument
    {
        $document = PlayerDocument::firstOrNew([
            'player_id' => $player->id,
            'document_type_id' => $type->id,
        ]);

        $document->fill([
            'state' => PlayerDocument::EXEMPT,
            'exempt_reason' => $reason,
            'recorded_by_user_id' => $by?->id,
        ])->save();

        return $document;
    }

    /** Back to "received" when it had been received; otherwise the row goes. */
    public function unexempt(PlayerDocument $document): void
    {
        if ($document->received_at !== null) {
            $document->update(['state' => PlayerDocument::RECEIVED, 'exempt_reason' => null]);

            return;
        }

        $paths = $document->files()->pluck('path');
        $document->files()->delete();
        $document->delete();
        $paths->each(fn (string $path) => $this->storage->delete($path));
    }

    /** @param  list<UploadedFile>  $files */
    public function attach(PlayerDocument $document, array $files, ?User $by): void
    {
        foreach ($files as $file) {
            $stored = $this->storage->store($file, PlayerDocument::directoryFor($document->player_id));

            try {
                $document->files()->create([
                    ...$stored,
                    'uploaded_by_user_id' => $by?->id,
                ]);
            } catch (Throwable $e) {
                // Never leave a file on disk that no row points at.
                $this->storage->delete($stored['path']);

                throw $e;
            }
        }
    }

    public function removeFile(PlayerDocumentFile $file): void
    {
        $path = $file->path;

        $file->delete();
        $this->storage->delete($path);
    }

    /** Rows only — call inside the permanent-deletion transaction. */
    public function purgePlayerRecords(Player $player): void
    {
        PlayerDocumentFile::query()
            ->whereIn('player_document_id', $player->documents()->select('id'))
            ->delete();

        $player->documents()->delete();
    }

    /** The player's whole folder — call after the transaction has committed. */
    public function purgePlayerFiles(int $playerId): void
    {
        $this->storage->deleteDirectory(PlayerDocument::directoryFor($playerId));
    }
}
```

- [ ] **Step 4: The controller**

Create `app/Http/Controllers/PlayerDocumentController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDocumentFile;
use App\Services\Player\PlayerDocumentService;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A player's documents. Every route here is players.documents.*, which
 * config/permissions.php maps to the `documents` module (owner decision), so
 * the `permission` middleware has already checked view/add/edit/delete.
 */
class PlayerDocumentController extends Controller
{
    /** Scans and phone photos, nothing executable; 10 MB like the meeting minutes. */
    private const FILE_RULES = ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'];

    private const MAX_FILES = 10;

    public function __construct(private PlayerDocumentService $documents) {}

    public function store(Request $request, Player $player): RedirectResponse
    {
        $type = $this->activeType($request);

        if ($type->isPhoto()) {
            return back()->with('error', 'flash.document_photo_is_profile_picture');
        }

        if ($player->documents()->where('document_type_id', $type->id)->exists()) {
            return back()->with('error', 'flash.document_already_recorded');
        }

        $this->documents->markReceived($player, $type, $this->receipt($request, $type), $this->files($request), $request->user());

        return back()->with('success', 'flash.document_received');
    }

    public function update(Request $request, Player $player, PlayerDocument $document): RedirectResponse
    {
        $this->ensureBelongs($player, $document);

        if ($document->state !== PlayerDocument::RECEIVED) {
            return back()->with('error', 'flash.document_not_received');
        }

        if ($document->type->isPhoto()) {
            return back()->with('error', 'flash.document_photo_is_profile_picture');
        }

        $this->documents->renew($document, $this->receipt($request, $document->type), $this->files($request), $request->user());

        return back()->with('success', 'flash.document_renewed');
    }

    public function exempt(Request $request, Player $player): RedirectResponse
    {
        $type = $this->activeType($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $this->documents->exempt($player, $type, $data['reason'], $request->user());

        return back()->with('success', 'flash.document_exempted');
    }

    public function unexempt(Player $player, PlayerDocument $document): RedirectResponse
    {
        $this->ensureBelongs($player, $document);

        if ($document->state !== PlayerDocument::EXEMPT) {
            return back()->with('error', 'flash.document_not_exempt');
        }

        $this->documents->unexempt($document);

        return back()->with('success', 'flash.document_exemption_removed');
    }

    public function storeFiles(Request $request, Player $player, PlayerDocument $document): RedirectResponse
    {
        $this->ensureBelongs($player, $document);

        if ($document->type->isPhoto()) {
            return back()->with('error', 'flash.document_photo_is_profile_picture');
        }

        if ($document->state !== PlayerDocument::RECEIVED) {
            return back()->with('error', 'flash.document_not_received');
        }

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*' => self::FILE_RULES,
        ]);

        $this->documents->attach($document, $this->files($request), $request->user());

        return back()->with('success', 'flash.document_files_uploaded');
    }

    public function showFile(Player $player, PlayerDocumentFile $file, PrivateFileStorage $storage): StreamedResponse
    {
        $this->ensureFileBelongs($player, $file);

        return $storage->inline($file->path, $file->original_name, $file->mime);
    }

    public function downloadFile(Player $player, PlayerDocumentFile $file, PrivateFileStorage $storage): StreamedResponse
    {
        $this->ensureFileBelongs($player, $file);

        return $storage->download($file->path, $file->original_name);
    }

    public function destroyFile(Player $player, PlayerDocumentFile $file): RedirectResponse
    {
        $this->ensureFileBelongs($player, $file);

        $this->documents->removeFile($file);

        return back()->with('success', 'flash.document_file_removed');
    }

    private function activeType(Request $request): DocumentType
    {
        $data = $request->validate([
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('is_active', true)],
        ]);

        return DocumentType::findOrFail($data['document_type_id']);
    }

    /** @return array{received_at: string, valid_until: ?string, notes: ?string} */
    private function receipt(Request $request, DocumentType $type): array
    {
        $data = $request->validate([
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            'valid_until' => [
                Rule::requiredIf($type->validity === DocumentType::VALIDITY_DATE),
                'nullable',
                'date',
                'after_or_equal:received_at',
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            'files' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'files.*' => self::FILE_RULES,
        ]);

        return [
            'received_at' => $data['received_at'],
            'valid_until' => $data['valid_until'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /** @return list<UploadedFile> */
    private function files(Request $request): array
    {
        return array_values(array_filter(
            (array) $request->file('files', []),
            fn ($file) => $file instanceof UploadedFile
        ));
    }

    private function ensureBelongs(Player $player, PlayerDocument $document): void
    {
        abort_unless((int) $document->player_id === (int) $player->id, 404);
    }

    private function ensureFileBelongs(Player $player, PlayerDocumentFile $file): void
    {
        abort_unless((int) $file->document?->player_id === (int) $player->id, 404);
    }
}
```

- [ ] **Step 5: The routes**

In `routes/web.php`, add the import (alphabetical order):

```php
use App\Http\Controllers\PlayerDocumentController;
```

and replace:

```php
    Route::delete('/players/{player}/subscriptions/{playerSubscription}', [PlayerSubscriptionController::class, 'destroy'])->name('players.subscriptions.destroy');
```

with:

```php
    Route::delete('/players/{player}/subscriptions/{playerSubscription}', [PlayerSubscriptionController::class, 'destroy'])->name('players.subscriptions.destroy');

    // Player documents — every name is players.documents.*, gated by the `documents`
    // module (config/permissions.php). Files are served from the private disk only.
    Route::post('/players/{player}/documents', [PlayerDocumentController::class, 'store'])->name('players.documents.store');
    Route::post('/players/{player}/documents/exempt', [PlayerDocumentController::class, 'exempt'])->name('players.documents.exempt');
    Route::put('/players/{player}/documents/{document}', [PlayerDocumentController::class, 'update'])->name('players.documents.update');
    Route::delete('/players/{player}/documents/{document}/exempt', [PlayerDocumentController::class, 'unexempt'])->name('players.documents.unexempt');
    Route::post('/players/{player}/documents/{document}/files', [PlayerDocumentController::class, 'storeFiles'])->name('players.documents.files.store');
    Route::get('/players/{player}/documents/files/{file}', [PlayerDocumentController::class, 'showFile'])->name('players.documents.files.show');
    Route::get('/players/{player}/documents/files/{file}/download', [PlayerDocumentController::class, 'downloadFile'])->name('players.documents.files.download');
    Route::delete('/players/{player}/documents/files/{file}', [PlayerDocumentController::class, 'destroyFile'])->name('players.documents.files.destroy');
```

(`{document}` binds `PlayerDocument` and `{file}` binds `PlayerDocumentFile` by the controller's type hints; the controller checks both belong to `{player}`.)

- [ ] **Step 6: The checklist on the player page**

In `app/Http/Controllers/PlayerController.php`, add the import:

```php
use App\Services\Player\DocumentChecklist;
```

Replace:

```php
    public function show(Player $player): Response
    {
```

with:

```php
    public function show(Request $request, Player $player): Response
    {
```

and replace:

```php
            'fileDrawerSize' => FileNumber::drawerSize(),
        ]);
    }
```

with:

```php
            'fileDrawerSize' => FileNumber::drawerSize(),
            // Owner decision: documents have their own permission. Without
            // documents/view the checklist is not even sent to the page.
            'documents' => $request->user()?->hasPermission('documents', 'view')
                ? DocumentChecklist::for($player)
                : null,
        ]);
    }
```

- [ ] **Step 7: The flash keys**

Save as `i18n-keys.tmp.json`:

```json
{
    "flash.document_received": { "en": "Document marked as received.", "fr": "Document marqué comme reçu.", "ar": "تم تسجيل استلام الوثيقة." },
    "flash.document_renewed": { "en": "Document renewed.", "fr": "Document renouvelé.", "ar": "تم تجديد الوثيقة." },
    "flash.document_exempted": { "en": "Document marked as not required.", "fr": "Document marqué comme non requis.", "ar": "تم تعليم الوثيقة كغير مطلوبة." },
    "flash.document_exemption_removed": { "en": "Exemption removed.", "fr": "Dispense annulée.", "ar": "تم إلغاء الإعفاء." },
    "flash.document_files_uploaded": { "en": "Files added.", "fr": "Fichiers ajoutés.", "ar": "تمت إضافة الملفات." },
    "flash.document_file_removed": { "en": "File removed.", "fr": "Fichier supprimé.", "ar": "تم حذف الملف." },
    "flash.document_already_recorded": { "en": "This document is already recorded. Renew it instead.", "fr": "Ce document est déjà enregistré. Renouvelez-le plutôt.", "ar": "هذه الوثيقة مسجلة مسبقاً. قم بتجديدها بدلاً من ذلك." },
    "flash.document_not_received": { "en": "This document has not been received.", "fr": "Ce document n'a pas été reçu.", "ar": "لم يتم استلام هذه الوثيقة." },
    "flash.document_not_exempt": { "en": "This document is not exempted.", "fr": "Ce document n'est pas dispensé.", "ar": "هذه الوثيقة غير معفاة." },
    "flash.document_photo_is_profile_picture": { "en": "The photo is the player's profile picture. Change it from the player form.", "fr": "La photo est celle du profil du joueur. Modifiez-la depuis la fiche du joueur.", "ar": "الصورة هي صورة الملف الشخصي للاعب. غيّرها من استمارة اللاعب." }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete the file.

- [ ] **Step 8: Run the tests and confirm they pass**

Run: `php artisan test --filter="PlayerDocumentActionsTest|FlashTranslationTest|PlayerFileNumberTest|PlayerWilayaTest"`

Expected: PASS (PlayerDocumentActionsTest: 19 tests; the player-page tests are unchanged).

- [ ] **Step 9: Commit**

```bash
php vendor/bin/pint app/Services/Player/PlayerDocumentService.php app/Http/Controllers/PlayerDocumentController.php app/Http/Controllers/PlayerController.php routes/web.php tests/Feature/PlayerDocumentActionsTest.php
node scripts/i18n-check.mjs
git add app/Services/Player/PlayerDocumentService.php app/Http/Controllers/PlayerDocumentController.php app/Http/Controllers/PlayerController.php routes/web.php tests/Feature/PlayerDocumentActionsTest.php resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(documents): mark received, renew, exempt and private file handling per player"
git show --stat HEAD
```

---

### Task 8: The Documents card on the player page

**Why:** Spec "Player page" — a Documents card with the checklist chips, the per-type actions and "3 missing" in the header. Owner decisions — "Expires soon" in orange; several files per document; the upload offers the phone camera (image types in `accept`, no forced `capture`); the Photo row explains it is the profile picture.

**Files:**
- Create: `resources/js/Components/PlayerDocumentsCard.vue` (no BOM)
- Modify: `resources/js/Pages/Players/Show.vue` (**BOM — targeted edits only**)
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script)

**Interfaces:**
- `<PlayerDocumentsCard :player-id="Number" :checklist="Object" />` — `checklist` is the `documents` prop of Task 7 (`{ items, missing_count, expiring_count }`).
- Action buttons follow `useCan()`: mark received / add files → `documents.add`; renew / exempt / undo → `documents.edit`; remove a file → `documents.delete`; view / download links → the card is only rendered with `documents.view`.
- Chip colours: received + scanned `emerald`, paper only `blue`, expires soon `amber` (orange), expired `rose`, missing `rose`, not required `slate`. Inactive types are greyed (`opacity-60`).

- [ ] **Step 1: The keys**

Check they are new: `grep -c '"doc_state_\|"doc_reason_\|"doc_mark_received"\|"doc_files"' resources/js/i18n/en.json` → `0`. Save as `i18n-keys.tmp.json`:

```json
{
    "doc_state_received_scanned": { "en": "Received + scanned", "fr": "Reçu + scanné", "ar": "مستلم + ممسوح ضوئياً" },
    "doc_state_received_paper": { "en": "Received (paper only)", "fr": "Reçu (papier seulement)", "ar": "مستلم (ورقي فقط)" },
    "doc_state_expires_soon": { "en": "Expires soon", "fr": "Expire bientôt", "ar": "ينتهي قريباً" },
    "doc_state_expired": { "en": "Expired", "fr": "Expiré", "ar": "منتهي الصلاحية" },
    "doc_state_missing": { "en": "Missing", "fr": "Manquant", "ar": "ناقص" },
    "doc_state_not_required": { "en": "Not required", "fr": "Non requis", "ar": "غير مطلوب" },
    "doc_reason_exempt": { "en": "Exempted", "fr": "Dispensé", "ar": "معفى" },
    "doc_reason_age": { "en": "Not needed at this age", "fr": "Non requis à cet âge", "ar": "غير مطلوب في هذا السن" },
    "doc_missing_count": { "en": "{count} missing", "fr": "{count} manquant(s)", "ar": "{count} ناقصة" },
    "doc_expiring_count": { "en": "{count} expiring soon", "fr": "{count} expire(nt) bientôt", "ar": "{count} تنتهي قريباً" },
    "doc_complete": { "en": "Complete", "fr": "Complet", "ar": "مكتمل" },
    "doc_mark_received": { "en": "Mark received", "fr": "Marquer reçu", "ar": "تسجيل الاستلام" },
    "doc_renew": { "en": "Renew", "fr": "Renouveler", "ar": "تجديد" },
    "doc_upload_files": { "en": "Add files", "fr": "Ajouter des fichiers", "ar": "إضافة ملفات" },
    "doc_exempt": { "en": "Mark not required", "fr": "Marquer non requis", "ar": "تعليم كغير مطلوب" },
    "doc_unexempt": { "en": "Undo exemption", "fr": "Annuler la dispense", "ar": "إلغاء الإعفاء" },
    "doc_exempt_reason": { "en": "Reason", "fr": "Motif", "ar": "السبب" },
    "doc_received_on": { "en": "Received on", "fr": "Reçu le", "ar": "تاريخ الاستلام" },
    "doc_valid_until": { "en": "Valid until", "fr": "Valable jusqu'au", "ar": "صالح إلى غاية" },
    "doc_validity_season_hint": { "en": "Valid until the end of the season.", "fr": "Valable jusqu'à la fin de la saison.", "ar": "صالح حتى نهاية الموسم." },
    "doc_files": { "en": "Files", "fr": "Fichiers", "ar": "الملفات" },
    "doc_files_hint": { "en": "PDF or photo, up to 10 MB each. On a phone you can take a picture.", "fr": "PDF ou photo, 10 Mo maximum chacun. Sur un téléphone, vous pouvez prendre une photo.", "ar": "PDF أو صورة، 10 ميغابايت كحد أقصى لكل ملف. على الهاتف يمكنك التقاط صورة." },
    "doc_photo_hint": { "en": "Taken from the profile picture.", "fr": "Reprise de la photo de profil.", "ar": "مأخوذة من صورة الملف الشخصي." },
    "doc_confirm_remove_file": { "en": "Remove this file? It cannot be recovered.", "fr": "Supprimer ce fichier ? Il ne pourra pas être récupéré.", "ar": "حذف هذا الملف؟ لا يمكن استرجاعه." },
    "doc_confirm_unexempt": { "en": "Undo the exemption for this document?", "fr": "Annuler la dispense pour ce document ?", "ar": "إلغاء الإعفاء لهذه الوثيقة؟" },
    "doc_download": { "en": "Download", "fr": "Télécharger", "ar": "تحميل" },
    "doc_inactive_type": { "en": "No longer requested", "fr": "Plus demandé", "ar": "لم تعد مطلوبة" }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete the file.

- [ ] **Step 2: The card**

Create `resources/js/Components/PlayerDocumentsCard.vue`:

```vue
<script setup>
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useCan } from '@/Composables/useCan';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    playerId: { type: Number, required: true },
    // DocumentChecklist::for(): { items, missing_count, expiring_count }
    checklist: { type: Object, required: true },
});

const { t } = useI18n();
const { can } = useCan();

// Image types make phone browsers offer the camera. No `capture` attribute, so
// the gallery and the file picker stay available too.
const ACCEPT = 'application/pdf,image/jpeg,image/png,image/webp,.pdf,.jpg,.jpeg,.png,.webp';

const STATE_COLORS = {
    received_scanned: 'emerald',
    received_paper: 'blue',
    expires_soon: 'amber',
    expired: 'rose',
    missing: 'rose',
    not_required: 'slate',
};

const stateLabels = computed(() => ({
    received_scanned: t('doc_state_received_scanned'),
    received_paper: t('doc_state_received_paper'),
    expires_soon: t('doc_state_expires_soon'),
    expired: t('doc_state_expired'),
    missing: t('doc_state_missing'),
    not_required: t('doc_state_not_required'),
}));

const reasonLabels = computed(() => ({
    exempt: t('doc_reason_exempt'),
    optional: t('doc_optional'),
    age: t('doc_reason_age'),
}));

const items = computed(() => props.checklist?.items ?? []);

const buttonPrimary = 'inline-flex items-center gap-1 rounded-md bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700';
const buttonOutline = 'inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-300 hover:bg-primary-50 dark:text-primary-300 dark:ring-primary-700 dark:hover:bg-primary-900/30';
const buttonQuiet = 'inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800';
const fileInputClass = 'mt-1 block w-full text-xs text-slate-500 file:me-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-primary-700 hover:file:bg-primary-100 dark:text-slate-400 dark:file:bg-primary-500/10 dark:file:text-primary-300';

function formatDate(value) {
    return value ? new Date(`${value}T00:00:00`).toLocaleDateString() : '—';
}

function formatSize(bytes) {
    return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

// Local date, not UTC: toISOString() alone would say "yesterday" before 1 a.m. in Algiers.
function todayIso() {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    return now.toISOString().slice(0, 10);
}

// Laravel reports array uploads as files.0, files.1, ...
function fileErrors(form) {
    return Object.entries(form.errors)
        .filter(([key]) => key === 'files' || key.startsWith('files.'))
        .map(([, message]) => message);
}

const canReceive = (item) => !item.type.is_photo && !item.document && item.type.is_active && can('documents', 'add');
const canRenew = (item) => !item.type.is_photo && item.document?.state === 'received' && can('documents', 'edit');
const canUpload = (item) => !item.type.is_photo && item.document?.state === 'received' && can('documents', 'add');
const canExempt = (item) => item.type.is_active
    && item.document?.state !== 'exempt'
    && !(item.type.is_photo && item.state === 'received_scanned')
    && can('documents', 'edit');
const canUnexempt = (item) => item.document?.state === 'exempt' && can('documents', 'edit');

const fileUrl = (file) => route('players.documents.files.show', { player: props.playerId, file: file.id });
const downloadUrl = (file) => route('players.documents.files.download', { player: props.playerId, file: file.id });

// --- Mark received / renew (same form) ---
const receiving = ref(null);
const receiveForm = useForm({ document_type_id: null, received_at: '', valid_until: '', notes: '', files: [] });

function openReceive(item) {
    receiveForm.clearErrors();
    receiveForm.document_type_id = item.type.id;
    receiveForm.received_at = todayIso();
    receiveForm.valid_until = '';
    receiveForm.notes = item.document?.notes ?? '';
    receiveForm.files = [];
    receiving.value = item;
}

function submitReceive() {
    const item = receiving.value;
    const options = {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => { receiving.value = null; },
    };

    if (item.document) {
        // PHP cannot read a multipart PUT: send it as POST with the method spoofed.
        receiveForm
            .transform((data) => ({ ...data, _method: 'put' }))
            .post(route('players.documents.update', { player: props.playerId, document: item.document.id }), options);
    } else {
        receiveForm
            .transform((data) => data)
            .post(route('players.documents.store', props.playerId), options);
    }
}

// --- Add files to a received document ---
const uploading = ref(null);
const uploadForm = useForm({ files: [] });

function openUpload(item) {
    uploadForm.clearErrors();
    uploadForm.files = [];
    uploading.value = item;
}

function submitUpload() {
    uploadForm.post(route('players.documents.files.store', { player: props.playerId, document: uploading.value.document.id }), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => { uploading.value = null; },
    });
}

// --- Exempt / undo ---
const exempting = ref(null);
const exemptForm = useForm({ document_type_id: null, reason: '' });

function openExempt(item) {
    exemptForm.clearErrors();
    exemptForm.document_type_id = item.type.id;
    exemptForm.reason = '';
    exempting.value = item;
}

function submitExempt() {
    exemptForm.post(route('players.documents.exempt', props.playerId), {
        preserveScroll: true,
        onSuccess: () => { exempting.value = null; },
    });
}

const unexempting = ref(null);

function confirmUnexempt() {
    const item = unexempting.value;
    unexempting.value = null;
    router.delete(route('players.documents.unexempt', { player: props.playerId, document: item.document.id }), { preserveScroll: true });
}

// --- Remove a file ---
const removingFile = ref(null);

function confirmRemoveFile() {
    const file = removingFile.value;
    removingFile.value = null;
    router.delete(route('players.documents.files.destroy', { player: props.playerId, file: file.id }), { preserveScroll: true });
}
</script>

<template>
    <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <h3 class="flex items-center gap-2 text-base font-semibold text-slate-900 dark:text-slate-100">
                <Icon name="folder" class="text-slate-400" /> {{ t('documents') }}
            </h3>
            <div class="flex flex-wrap items-center gap-2">
                <Badge v-if="checklist.missing_count > 0" :label="t('doc_missing_count', { count: checklist.missing_count })" color="rose" />
                <Badge v-else :label="t('doc_complete')" color="emerald" />
                <Badge v-if="checklist.expiring_count > 0" :label="t('doc_expiring_count', { count: checklist.expiring_count })" color="amber" />
            </div>
        </div>

        <div v-if="!items.length" class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_data') }}</div>

        <ul v-else class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="item in items" :key="item.type.id" class="px-5 py-4" :class="{ 'opacity-60': !item.type.is_active }">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-900 dark:text-slate-100">
                            {{ item.type.name }}
                            <span v-if="item.type.is_required" class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('doc_required') }}</span>
                            <span v-if="item.type.max_age" class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('doc_up_to_age', { age: item.type.max_age }) }}</span>
                            <span v-if="!item.type.is_active" class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('doc_inactive_type') }}</span>
                        </p>
                        <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                            <span v-if="item.type.is_photo">{{ t('doc_photo_hint') }}</span>
                            <span v-if="item.document?.received_at">{{ t('doc_received_on') }} {{ formatDate(item.document.received_at) }}</span>
                            <span v-if="item.document?.valid_until">{{ t('doc_valid_until') }} {{ formatDate(item.document.valid_until) }}</span>
                            <span v-if="item.document?.state === 'exempt' && item.document.exempt_reason">{{ t('doc_exempt_reason') }}: {{ item.document.exempt_reason }}</span>
                            <span v-if="item.document?.notes" class="italic">{{ item.document.notes }}</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge :label="stateLabels[item.state]" :color="STATE_COLORS[item.state]" dot />
                        <span v-if="item.state === 'not_required' && item.reason" class="text-xs text-slate-400">{{ reasonLabels[item.reason] }}</span>
                    </div>
                </div>

                <ul v-if="item.document?.files?.length" class="mt-2 space-y-1">
                    <li v-for="file in item.document.files" :key="file.id" class="flex flex-wrap items-center gap-2 text-xs">
                        <Icon name="document" class="text-primary-500" />
                        <a :href="fileUrl(file)" target="_blank" rel="noopener" class="min-w-0 max-w-xs truncate font-medium text-primary-600 hover:underline dark:text-primary-300">{{ file.original_name }}</a>
                        <span class="text-slate-400">{{ formatSize(file.size) }} · {{ formatDate(file.uploaded_at) }}</span>
                        <a :href="downloadUrl(file)" class="text-slate-500 hover:text-slate-700 dark:hover:text-slate-200" :title="t('doc_download')" :aria-label="t('doc_download')"><Icon name="download" /></a>
                        <button v-if="can('documents', 'delete')" type="button" class="text-slate-300 hover:text-rose-500" :title="t('remove')" :aria-label="t('remove')" @click="removingFile = file"><Icon name="xcircle" /></button>
                    </li>
                </ul>

                <div class="mt-3 flex flex-wrap gap-2">
                    <button v-if="canReceive(item)" type="button" :class="buttonPrimary" @click="openReceive(item)"><Icon name="check" /> {{ t('doc_mark_received') }}</button>
                    <button v-if="canRenew(item)" type="button" :class="buttonOutline" @click="openReceive(item)"><Icon name="refresh" /> {{ t('doc_renew') }}</button>
                    <button v-if="canUpload(item)" type="button" :class="buttonOutline" @click="openUpload(item)"><Icon name="upload" /> {{ t('doc_upload_files') }}</button>
                    <button v-if="canExempt(item)" type="button" :class="buttonQuiet" @click="openExempt(item)">{{ t('doc_exempt') }}</button>
                    <button v-if="canUnexempt(item)" type="button" :class="buttonQuiet" @click="unexempting = item">{{ t('doc_unexempt') }}</button>
                </div>
            </li>
        </ul>

        <!-- Mark received / renew -->
        <Modal :show="!!receiving" max-width="md" @close="receiving = null">
            <form v-if="receiving" class="space-y-4 p-6" @submit.prevent="submitReceive">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                    {{ receiving.document ? t('doc_renew') : t('doc_mark_received') }} · {{ receiving.type.name }}
                </h3>
                <div>
                    <InputLabel :value="t('doc_received_on')" />
                    <TextInput v-model="receiveForm.received_at" type="date" class="mt-1 w-full" :max="todayIso()" required />
                    <InputError :message="receiveForm.errors.received_at" class="mt-1" />
                </div>
                <div v-if="receiving.type.validity === 'date'">
                    <InputLabel :value="t('doc_valid_until')" />
                    <TextInput v-model="receiveForm.valid_until" type="date" class="mt-1 w-full" :min="receiveForm.received_at" required />
                    <InputError :message="receiveForm.errors.valid_until" class="mt-1" />
                </div>
                <p v-else-if="receiving.type.validity === 'season'" class="text-xs text-slate-500 dark:text-slate-400">{{ t('doc_validity_season_hint') }}</p>
                <div>
                    <InputLabel :value="t('notes')" />
                    <TextInput v-model="receiveForm.notes" class="mt-1 w-full" />
                    <InputError :message="receiveForm.errors.notes" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('doc_files')" />
                    <input type="file" multiple :accept="ACCEPT" :class="fileInputClass" @change="receiveForm.files = Array.from($event.target.files || [])" />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('doc_files_hint') }}</p>
                    <InputError v-for="(message, index) in fileErrors(receiveForm)" :key="index" :message="message" class="mt-1" />
                </div>
                <div class="flex justify-end gap-3">
                    <SecondaryButton type="button" @click="receiving = null">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="receiveForm.processing">{{ t('save') }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <!-- Add files -->
        <Modal :show="!!uploading" max-width="md" @close="uploading = null">
            <form v-if="uploading" class="space-y-4 p-6" @submit.prevent="submitUpload">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('doc_upload_files') }} · {{ uploading.type.name }}</h3>
                <div>
                    <input type="file" multiple :accept="ACCEPT" :class="fileInputClass" required @change="uploadForm.files = Array.from($event.target.files || [])" />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('doc_files_hint') }}</p>
                    <InputError v-for="(message, index) in fileErrors(uploadForm)" :key="index" :message="message" class="mt-1" />
                </div>
                <div class="flex justify-end gap-3">
                    <SecondaryButton type="button" @click="uploading = null">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="uploadForm.processing || !uploadForm.files.length">{{ t('upload') }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <!-- Exempt -->
        <Modal :show="!!exempting" max-width="md" @close="exempting = null">
            <form v-if="exempting" class="space-y-4 p-6" @submit.prevent="submitExempt">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('doc_exempt') }} · {{ exempting.type.name }}</h3>
                <div>
                    <InputLabel :value="t('doc_exempt_reason')" />
                    <TextInput v-model="exemptForm.reason" class="mt-1 w-full" required minlength="3" maxlength="255" />
                    <InputError :message="exemptForm.errors.reason" class="mt-1" />
                </div>
                <div class="flex justify-end gap-3">
                    <SecondaryButton type="button" @click="exempting = null">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="exemptForm.processing">{{ t('save') }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal :show="!!unexempting" :message="t('doc_confirm_unexempt')" @confirm="confirmUnexempt" @cancel="unexempting = null" />
        <ConfirmModal :show="!!removingFile" :message="t('doc_confirm_remove_file')" @confirm="confirmRemoveFile" @cancel="removingFile = null" />
    </div>
</template>
```

- [ ] **Step 3: Put the card on the page (BOM file — targeted edits)**

In `resources/js/Pages/Players/Show.vue`:

Replace:

```js
import PlayerFieldRow from '@/Components/PlayerFieldRow.vue';
```

with:

```js
import PlayerFieldRow from '@/Components/PlayerFieldRow.vue';
import PlayerDocumentsCard from '@/Components/PlayerDocumentsCard.vue';
```

Replace:

```js
    fileDrawerSize: { type: Number, default: 100 },
```

with:

```js
    fileDrawerSize: { type: Number, default: 100 },
    // null when the viewer lacks documents/view (the server does not send it).
    documents: { type: Object, default: null },
```

Replace:

```html
            <!-- Subscriptions -->
```

with:

```html
            <!-- Documents: checklist + actions (absent without the documents permission) -->
            <PlayerDocumentsCard v-if="documents" :player-id="player.id" :checklist="documents" />

            <!-- Subscriptions -->
```

- [ ] **Step 4: Verify**

Run:
- `head -c 3 resources/js/Pages/Players/Show.vue | od -An -tx1` → `ef bb bf`
- `npm run build` → success
- `node scripts/i18n-check.mjs` → `✓ …`
- `php artisan test --filter=PlayerDocumentActionsTest` → PASS

Manual (FR, then AR): open a player → the Documents card shows "3 manquant(s)" for an adult with nothing; mark the medical certificate received (a season date appears), attach two files from the same dialog, open one (new tab, inline PDF) and download the other; renew it; mark the birth certificate not required with a reason and undo it; remove a file (ConfirmModal). On a phone browser the file button offers the camera.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/PlayerDocumentsCard.vue resources/js/Pages/Players/Show.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(documents): documents card on the player page"
git show --stat HEAD
```

---

### Task 9: Players list — missing column and filters (backend), plus "Sans wilaya"

**Why:** Spec — a missing-documents count column and filters for "has missing documents" and "missing type X", computed in SQL. Owner decisions — an "expiring soon" filter; the wilaya filter gains "no wilaya" (`wilaya_id=none`). All of it needs players/view only.

**Files:**
- Modify: `app/Http/Controllers/PlayerController.php`
- Modify: `tests/Feature/ListFilterPartialReloadTest.php`
- Test: `tests/Feature/PlayerDocumentFiltersTest.php`

**Interfaces:**
- Each row of the `players` prop gains `missing_documents_count` (int), from `DocumentChecklist::missingCountSql()`.
- Query parameter `documents`: `missing` (count > 0) | `expiring` (`expiringSoonSql()`) | `missing-{typeId}` (that type's term > 0). Anything else is ignored. It goes through `applyPlayerFilters()`, so the stats charts and the export follow it.
- Query parameter `wilaya_id=none` → `whereNull('wilaya_id')`; a numeric id works as before.
- `filters` prop list gains `documents`.
- New lookup prop `documentTypes` (closure — never part of a filter reload): the active **required** types, ordered, as `id, code, name, name_ar, name_fr, name_en` (+ appended `localized_name`).
- The partial-reload `only` list is **unchanged**.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PlayerDocumentFiltersTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\CountryState;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerDocumentFiltersTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(string $lastname, array $attributes = []): Player
    {
        $this->sequence++;

        return Player::create([
            'membership_id' => '20268'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'firstname' => 'Test',
            'lastname' => $lastname,
            'birthdate' => '1990-01-01',
            ...$attributes,
        ]);
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    private function receive(Player $player, string $code, ?string $validUntil = null): void
    {
        PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => $this->type($code)->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => '2026-09-01',
            'valid_until' => $validUntil,
        ]);
    }

    /** Picture + birth certificate + a medical certificate valid all season. */
    private function complete(Player $player): Player
    {
        $player->update(['picture_url' => '/media/players/'.$player->id.'.jpg']);
        $this->receive($player, 'birth_certificate');
        $this->receive($player, 'medical_certificate', '2027-08-31');

        return $player;
    }

    private function props(array $query = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->admin())
            ->get(route('players.index', $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    /** @return list<string> */
    private function names(array $props): array
    {
        return collect($props['players']['data'])->pluck('lastname')->sort()->values()->all();
    }

    #[Test]
    public function each_row_carries_its_missing_documents_count(): void
    {
        $this->complete($this->player('Complete'));
        $this->player('Adult');
        $this->player('Minor', ['birthdate' => '2012-01-01']);

        $counts = collect($this->props()['players']['data'])
            ->mapWithKeys(fn (array $row) => [$row['lastname'] => (int) $row['missing_documents_count']])
            ->all();

        $this->assertSame(['Adult' => 3, 'Complete' => 0, 'Minor' => 4], collect($counts)->sortKeys()->all());
    }

    #[Test]
    public function the_missing_filter_keeps_only_players_with_something_missing(): void
    {
        $this->complete($this->player('Complete'));
        $this->player('Adult');

        $props = $this->props(['documents' => 'missing']);

        $this->assertSame(['Adult'], $this->names($props));
        $this->assertSame('missing', $props['filters']['documents']);
    }

    #[Test]
    public function the_missing_type_filter_keeps_only_players_missing_that_type(): void
    {
        $hasMedical = $this->player('HasMedical');
        $this->receive($hasMedical, 'medical_certificate', '2027-08-31');
        $expired = $this->player('ExpiredMedical');
        $this->receive($expired, 'medical_certificate', '2026-08-31');
        $this->player('NoMedical');

        $props = $this->props(['documents' => 'missing-'.$this->type('medical_certificate')->id]);

        $this->assertSame(['ExpiredMedical', 'NoMedical'], $this->names($props));
    }

    #[Test]
    public function the_missing_type_filter_on_the_photo_finds_players_without_a_picture(): void
    {
        $this->player('WithPicture', ['picture_url' => '/media/players/x.jpg']);
        $this->player('WithoutPicture');

        $props = $this->props(['documents' => 'missing-'.$this->type('photo')->id]);

        $this->assertSame(['WithoutPicture'], $this->names($props));
    }

    #[Test]
    public function an_optional_type_or_a_malformed_value_filters_nothing_in(): void
    {
        $this->player('Adult');

        $this->assertSame([], $this->names($this->props(['documents' => 'missing-'.$this->type('school_certificate')->id])));
        $this->assertSame(['Adult'], $this->names($this->props(['documents' => 'nonsense'])));
    }

    #[Test]
    public function the_expiring_filter_keeps_players_with_a_document_expiring_within_thirty_days(): void
    {
        $soon = $this->player('Soon');
        $this->receive($soon, 'medical_certificate', '2026-10-20');
        $later = $this->player('Later');
        $this->receive($later, 'medical_certificate', '2027-08-31');
        $this->player('Nothing');

        $this->assertSame(['Soon'], $this->names($this->props(['documents' => 'expiring'])));
    }

    #[Test]
    public function the_stats_follow_the_documents_filter(): void
    {
        $this->complete($this->player('Complete'));
        $this->player('Adult');
        $this->player('Other');

        $props = $this->props(['documents' => 'missing']);

        $this->assertSame(2, collect($props['categoryStats'])->sum('count'));
        $this->assertSame(2, collect($props['ageStats'])->sum('count'));
    }

    #[Test]
    public function the_no_wilaya_option_finds_players_without_one(): void
    {
        $wilaya = CountryState::query()->whereNotNull('code')->orderBy('code')->firstOrFail();
        $this->player('Placed', ['wilaya_id' => $wilaya->id]);
        $this->player('Unplaced');

        $this->assertSame(['Unplaced'], $this->names($this->props(['wilaya_id' => 'none'])));
        $this->assertSame(['Placed'], $this->names($this->props(['wilaya_id' => $wilaya->id])));
        $this->assertSame('none', $this->props(['wilaya_id' => 'none'])['filters']['wilaya_id']);
    }

    #[Test]
    public function the_filter_offers_the_required_active_types(): void
    {
        $this->type('parental_authorization')->update(['is_active' => false]);

        $codes = collect($this->props()['documentTypes'])->pluck('code')->all();

        $this->assertSame(['birth_certificate', 'photo', 'medical_certificate'], $codes);
    }

    #[Test]
    public function the_column_and_filters_need_player_rights_only(): void
    {
        $viewer = User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::create(['key' => 'viewer', 'name' => ['en' => 'Viewer'], 'permissions' => ['players' => ['view']]])->id,
        ]);
        $this->player('Adult');

        $props = $this->props(['documents' => 'missing'], $viewer);

        $this->assertSame(['Adult'], $this->names($props));
        $this->assertSame(3, (int) $props['players']['data'][0]['missing_documents_count']);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan config:clear; php artisan test --filter=PlayerDocumentFiltersTest`

Expected: FAIL — `Undefined array key "missing_documents_count"`, the filters return everyone, `documentTypes` is missing.

- [ ] **Step 3: The column**

In `app/Http/Controllers/PlayerController.php`, add the import:

```php
use App\Models\DocumentType;
```

(`DocumentChecklist` was imported in Task 7.) In `index()`, replace:

```php
        $query = Player::query()
            ->select('players.*')
            ->selectRaw('players.outstanding_debt as total_debt')
            ->with(['category', 'position', 'otherPositions', 'memberJob', 'status', 'wilaya']);
```

with:

```php
        // Missing documents per row, computed in SQL — the twin of the player
        // page's checklist (DocumentChecklist; the two are tested to agree).
        [$missingSql, $missingBindings] = DocumentChecklist::missingCountSql();

        $query = Player::query()
            ->select('players.*')
            ->selectRaw('players.outstanding_debt as total_debt')
            ->selectRaw("{$missingSql} as missing_documents_count", $missingBindings)
            ->with(['category', 'position', 'otherPositions', 'memberJob', 'status', 'wilaya']);
```

- [ ] **Step 4: The lookup and the echoed filter**

Replace:

```php
            'categoryStats' => $categoryStats,
```

with:

```php
            // The "missing type X" options: only types that can be missing.
            'documentTypes' => fn () => DocumentType::query()
                ->where('is_active', true)
                ->where('is_required', true)
                ->ordered()
                ->get(['id', 'code', 'name', 'name_ar', 'name_fr', 'name_en']),
            'categoryStats' => $categoryStats,
```

and replace:

```php
            'filters' => $request->only(['search', 'category_id', 'status', 'position_id', 'branch_id', 'age', 'archived', 'wilaya_id']),
```

with:

```php
            'filters' => $request->only(['search', 'category_id', 'status', 'position_id', 'branch_id', 'age', 'archived', 'wilaya_id', 'documents']),
```

- [ ] **Step 5: The filters**

In `applyPlayerFilters()`, replace:

```php
        if ($request->filled('wilaya_id')) {
            $query->where('wilaya_id', $request->input('wilaya_id'));
        }
```

with:

```php
        if ($request->filled('wilaya_id')) {
            // "none" finds the players whose wilaya was never set or was "Unknown"
            // before the P2 migration (the list's filter drops empty values, so
            // "no wilaya" needs a value of its own).
            $request->input('wilaya_id') === 'none'
                ? $query->whereNull('wilaya_id')
                : $query->where('wilaya_id', $request->input('wilaya_id'));
        }

        if ($request->filled('documents')) {
            $this->applyDocumentsFilter($query, (string) $request->input('documents'));
        }
```

and add this private method directly after `applyPlayerFilters()`:

```php
    /**
     * missing | expiring | missing-{typeId}. Uses the SQL twin of the
     * checklist, so the list, its charts and the export agree with the
     * player page. An unknown value filters nothing.
     */
    private function applyDocumentsFilter($query, string $filter): void
    {
        if ($filter === 'missing') {
            [$sql, $bindings] = DocumentChecklist::missingCountSql();
            $query->whereRaw("{$sql} > 0", $bindings);

            return;
        }

        if ($filter === 'expiring') {
            [$sql, $bindings] = DocumentChecklist::expiringSoonSql();
            $query->whereRaw($sql, $bindings);

            return;
        }

        if (preg_match('/^missing-(\d+)$/', $filter, $match)) {
            [$sql, $bindings] = DocumentChecklist::missingCountSql(null, (int) $match[1]);
            $query->whereRaw("{$sql} > 0", $bindings);
        }
    }
```

- [ ] **Step 6: Keep the partial reload pinned**

In `tests/Feature/ListFilterPartialReloadTest.php`, replace:

```php
                ->has('playerStatuses')
                ->has('players.data', 2));
```

with:

```php
                ->has('playerStatuses')
                ->has('documentTypes')
                ->has('players.data', 2));
```

and replace:

```php
                    ->missing('positions')
                    ->missing('playerStatuses')));
```

with:

```php
                    ->missing('positions')
                    ->missing('playerStatuses')
                    ->missing('documentTypes')));
```

(Both anchors are unique in that file: lines ~59 and ~81.)

- [ ] **Step 7: Run the tests and confirm they pass**

Run: `php artisan test --filter="PlayerDocumentFiltersTest|ListFilterPartialReloadTest|PlayerStatsFilterTest|PlayerSearchTest|PlayerIndexDebtTest|PlayerWilayaTest|AuditFixesTest|PlayerListActionsTest|PlayerPositionsTest"`

Expected: PASS (PlayerDocumentFiltersTest: 10 tests; the others unchanged).

- [ ] **Step 8: Commit**

```bash
php vendor/bin/pint app/Http/Controllers/PlayerController.php tests/Feature/PlayerDocumentFiltersTest.php tests/Feature/ListFilterPartialReloadTest.php
git add app/Http/Controllers/PlayerController.php tests/Feature/PlayerDocumentFiltersTest.php tests/Feature/ListFilterPartialReloadTest.php
git commit -m "feat(players): missing-documents count and filters in SQL, plus a no-wilaya filter"
git show --stat HEAD
```

---

### Task 10: Players list — the column and the filters on screen

**Files:**
- Modify: `resources/js/Pages/Players/Index.vue` (**BOM — targeted edits only**)
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script)

**Interfaces:** consumes `documentTypes`, `filters.documents`, `filters.wilaya_id = 'none'` and `players.data[].missing_documents_count` from Task 9. The `useListFilters` `only` list stays `['players', 'filters', 'categoryStats', 'statusStats', 'positionStats', 'ageStats']`.

- [ ] **Step 1: The keys**

Check they are new (`grep -c '"doc_filter_\|"no_wilaya"' resources/js/i18n/en.json` → `0`). Save as `i18n-keys.tmp.json`:

```json
{
    "doc_filter_all": { "en": "All documents", "fr": "Tous les documents", "ar": "كل الوثائق" },
    "doc_filter_missing": { "en": "Missing documents", "fr": "Documents manquants", "ar": "وثائق ناقصة" },
    "doc_filter_expiring": { "en": "Expiring soon", "fr": "Expirant bientôt", "ar": "تنتهي قريباً" },
    "doc_filter_missing_type": { "en": "Missing a specific document", "fr": "Un document précis manquant", "ar": "وثيقة محددة ناقصة" },
    "no_wilaya": { "en": "No wilaya", "fr": "Sans wilaya", "ar": "بدون ولاية" }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete the file.

- [ ] **Step 2: Props and filter state**

In `resources/js/Pages/Players/Index.vue`, replace:

```js
    ageStats: { type: Array, default: () => [] },
    filters: Object,
```

with:

```js
    ageStats: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
    filters: Object,
```

Replace:

```js
const wilayaFilter = ref(props.filters?.wilaya_id || '');
```

with:

```js
const wilayaFilter = ref(props.filters?.wilaya_id || '');
// missing | expiring | missing-<typeId> — one select, one query parameter.
const documentsFilter = ref(props.filters?.documents || '');
```

Replace:

```js
    wilaya_id: wilayaFilter.value,
```

with:

```js
    wilaya_id: wilayaFilter.value,
    documents: documentsFilter.value,
```

(Do **not** touch the `only: [...]` list on the next lines.)

- [ ] **Step 3: The two selects**

Replace:

```html
                    <option value="">{{ t('all_wilayas') }}</option>
                    <option v-for="w in wilayas" :key="w.id" :value="w.id">{{ w.code }} · {{ w.localized_name || w.name }}</option>
                </select>
```

with:

```html
                    <option value="">{{ t('all_wilayas') }}</option>
                    <!-- A value of its own: useListFilters drops empty values. -->
                    <option value="none">{{ t('no_wilaya') }}</option>
                    <option v-for="w in wilayas" :key="w.id" :value="w.id">{{ w.code }} · {{ w.localized_name || w.name }}</option>
                </select>
                <select
                    v-model="documentsFilter"
                    class="min-w-0 flex-1 rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:max-w-xs sm:flex-none"
                >
                    <option value="">{{ t('doc_filter_all') }}</option>
                    <option value="missing">{{ t('doc_filter_missing') }}</option>
                    <option value="expiring">{{ t('doc_filter_expiring') }}</option>
                    <optgroup v-if="documentTypes.length" :label="t('doc_filter_missing_type')">
                        <option v-for="dt in documentTypes" :key="dt.id" :value="`missing-${dt.id}`">{{ dt.localized_name || dt.name }}</option>
                    </optgroup>
                </select>
```

- [ ] **Step 4: The column**

Replace:

```html
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('status') }}</th>
```

with:

```html
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('status') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('documents') }}</th>
```

Replace:

```html
                                    <span v-else class="text-sm text-slate-400">-</span>
                                </td>
```

with:

```html
                                    <span v-else class="text-sm text-slate-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <Badge v-if="Number(player.missing_documents_count) > 0" :label="t('doc_missing_count', { count: Number(player.missing_documents_count) })" color="rose" />
                                    <Icon v-else name="check" class="text-emerald-500" :title="t('doc_complete')" />
                                </td>
```

Replace:

```html
                                <td colspan="9" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
```

with:

```html
                                <td colspan="10" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
```

- [ ] **Step 5: Verify**

Run:
- `head -c 3 resources/js/Pages/Players/Index.vue | od -An -tx1` → `ef bb bf`
- `grep -n "only: \['players', 'filters', 'categoryStats', 'statusStats', 'positionStats', 'ageStats'\]" resources/js/Pages/Players/Index.vue` → one match (unchanged)
- `npm run build` → success; `node scripts/i18n-check.mjs` → `✓ …`
- `php artisan test --filter="ListFilterPartialReloadTest|PlayerDocumentFiltersTest"` → PASS

Manual: the Documents column shows "3 missing" / a green tick; each documents option narrows the list and the doughnuts; "Sans wilaya" lists the players without a wilaya; the choice survives a refresh (it is in the URL).

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Players/Index.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(players): documents column, documents and no-wilaya filters on the list"
git show --stat HEAD
```

---

### Task 11: Permanent deletion removes the documents

**Why:** Spec — "Permanently deleting a player removes their document files from disk." Archiving (the normal delete) keeps everything.

**Files:**
- Modify: `app/Http/Controllers/PlayerController.php` (`permanentlyDelete()`)
- Test: `tests/Feature/PlayerDocumentDeletionTest.php`

**Interfaces:** uses `PlayerDocumentService::purgePlayerRecords()` (inside the transaction) and `purgePlayerFiles()` (after it) from Task 7. `forceDelete()` and `bulkForceDelete()` both go through `permanentlyDelete()`, so both are covered.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PlayerDocumentDeletionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDocumentFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerDocumentDeletionTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    /** A player with a received birth certificate and one scanned file on the private disk. */
    private function playerWithScan(): array
    {
        $this->sequence++;
        $player = Player::create([
            'membership_id' => '20267'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'firstname' => 'Test',
            'lastname' => 'Player'.$this->sequence,
            'archived' => true,
        ]);

        $this->actingAs($this->admin())->post(route('players.documents.store', $player), [
            'document_type_id' => DocumentType::where('code', 'birth_certificate')->value('id'),
            'received_at' => now()->toDateString(),
            'files' => [UploadedFile::fake()->create('acte.pdf', 50, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $path = PlayerDocumentFile::query()
            ->whereIn('player_document_id', $player->documents()->select('id'))
            ->value('path');
        Storage::disk('local')->assertExists($path);

        return [$player, $path];
    }

    #[Test]
    public function permanently_deleting_a_player_removes_their_documents_and_files(): void
    {
        [$player, $path] = $this->playerWithScan();
        [$other, $otherPath] = $this->playerWithScan();

        $this->actingAs($this->admin())->delete(route('players.forceDelete', $player))->assertRedirect();

        $this->assertNull(Player::find($player->id));
        $this->assertSame(0, PlayerDocument::where('player_id', $player->id)->count());
        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertMissing(PlayerDocument::directoryFor($player->id));

        // Someone else's file is untouched.
        Storage::disk('local')->assertExists($otherPath);
        $this->assertSame(1, PlayerDocument::where('player_id', $other->id)->count());
    }

    #[Test]
    public function bulk_permanent_deletion_removes_every_players_files(): void
    {
        [$first, $firstPath] = $this->playerWithScan();
        [$second, $secondPath] = $this->playerWithScan();

        $this->actingAs($this->admin())->post(route('players.bulkForceDelete'), ['ids' => [$first->id, $second->id]])
            ->assertRedirect();

        $this->assertSame(0, PlayerDocument::count());
        $this->assertSame(0, PlayerDocumentFile::count());
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertMissing($secondPath);
    }

    #[Test]
    public function archiving_a_player_keeps_their_documents(): void
    {
        [$player, $path] = $this->playerWithScan();
        $player->update(['archived' => false]);

        $this->actingAs($this->admin())->delete(route('players.destroy', $player))->assertRedirect();

        $this->assertTrue($player->fresh()->archived);
        $this->assertSame(1, PlayerDocument::where('player_id', $player->id)->count());
        Storage::disk('local')->assertExists($path);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan config:clear; php artisan test --filter=PlayerDocumentDeletionTest`

Expected: FAIL — the rows go (database cascade) but `Storage::disk('local')->assertMissing($path)` fails: the scanned file is still on disk.

- [ ] **Step 3: Remove rows in the transaction, files after it**

In `app/Http/Controllers/PlayerController.php`, add the import:

```php
use App\Services\Player\PlayerDocumentService;
```

and replace:

```php
    private function permanentlyDelete(Player $player, FileStorageService $files): void
    {
        DB::transaction(function () use ($player) {
            Transaction::where('related_entity_type', 'Player')
                ->where('related_entity_id', $player->id)
                ->update(['archived' => true]);

            $player->emergencyContacts()->delete();
            $player->achievements()->delete();
            $player->playerSubscriptions()->delete();
            $player->equipmentRentals()->delete();
            $player->delete();
        });

        $files->delete($player->picture_filename);
    }
```

with:

```php
    private function permanentlyDelete(Player $player, FileStorageService $files): void
    {
        $documents = app(PlayerDocumentService::class);

        DB::transaction(function () use ($player, $documents) {
            Transaction::where('related_entity_type', 'Player')
                ->where('related_entity_id', $player->id)
                ->update(['archived' => true]);

            $player->emergencyContacts()->delete();
            $player->achievements()->delete();
            $player->playerSubscriptions()->delete();
            $player->equipmentRentals()->delete();
            $documents->purgePlayerRecords($player);
            $player->delete();
        });

        $files->delete($player->picture_filename);
        // After the commit, so a deletion that rolled back never loses the scans.
        $documents->purgePlayerFiles($player->id);
    }
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `php artisan test --filter="PlayerDocumentDeletionTest|PlayerListActionsTest|AuditFixesTest"`

Expected: PASS (PlayerDocumentDeletionTest: 3 tests).

- [ ] **Step 5: Commit**

```bash
php vendor/bin/pint app/Http/Controllers/PlayerController.php tests/Feature/PlayerDocumentDeletionTest.php
git add app/Http/Controllers/PlayerController.php tests/Feature/PlayerDocumentDeletionTest.php
git commit -m "feat(documents): permanent player deletion removes their document files"
git show --stat HEAD
```

---

### Task 12: Board minutes move behind login onto the private disk

**Why:** Owner decision (security, found while mapping) — minutes and meeting attachments are uploaded to the public disk and served by the unauthenticated `GET /media/{path}` route (and, on the web, by the `public/storage` symlink). They move to the private `local` disk, are served by an authenticated route behind `board/view`, and the existing files are migrated. Club logos, branding, player/user/board-member photos and equipment pictures stay public (PDFs and pages need them).

**Files:**
- Modify: `app/Http/Controllers/BoardMeetingController.php`
- Modify: `app/Models/BoardMeeting.php`
- Modify: `routes/web.php`
- Create: `database/migrations/2026_09_24_100004_move_meeting_minutes_to_private_disk.php`
- Modify: `tests/Feature/BoardCalendarTest.php` (rewrite `admin_can_upload_and_remove_a_minutes_file`)
- Test: `tests/Feature/BoardMinutesPrivateTest.php`
- No change: `resources/js/Pages/Board/Meeting.vue` — it links `meeting.attachment_url`, which now resolves to the authenticated route (it is the only Vue file that renders the attachment: `grep -rn attachment_url resources/js` → `Board/Meeting.vue` only).

**Interfaces:**
- Route `GET /board/meetings/{meeting}/attachment` → `BoardMeetingController@showAttachment`, name `board.meetings.attachment.show` (derived `show` → `board / view`; no config change).
- Storage: `PrivateFileStorage` (Task 3), folder `minutes/` on the private disk (same relative path as before).
- `BoardMeeting::attachment_url` (accessor): the host-relative authenticated route (`/board/meetings/{id}/attachment`) whenever `attachment_filename` is set; otherwise the stored value through `Media::path()` (legacy external links).
- The column `attachment_url` is written `null` for private files; `attachment_filename` holds the private path.
- The data migration is idempotent: copy public → private (again, if a previous copy is incomplete), delete the public copy only when the private one has the same size, then point the row at the private path. A row whose file is on neither disk is left untouched.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BoardMinutesPrivateTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BoardMeeting;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardMinutesPrivateTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_24_100004_move_meeting_minutes_to_private_disk.php';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function meeting(array $attributes = []): BoardMeeting
    {
        return BoardMeeting::create([
            'title' => 'AGM',
            'type' => 'general_assembly',
            'meeting_date' => now(),
            'status' => 'held',
            ...$attributes,
        ]);
    }

    private function upload(BoardMeeting $meeting, string $name = 'minutes.pdf'): void
    {
        $this->actingAs($this->admin())->post(route('board.meetings.attachment', $meeting), [
            'attachment' => UploadedFile::fake()->create($name, 200, 'application/pdf'),
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    #[Test]
    public function uploaded_minutes_go_to_the_private_disk(): void
    {
        $meeting = $this->meeting();
        $this->upload($meeting);

        $meeting->refresh();
        $this->assertStringStartsWith('minutes/', $meeting->attachment_filename);
        Storage::disk('local')->assertExists($meeting->attachment_filename);
        Storage::disk('public')->assertMissing($meeting->attachment_filename);
        $this->assertNull($meeting->getAttributes()['attachment_url']);
        $this->assertSame(route('board.meetings.attachment.show', $meeting, false), $meeting->attachment_url);
    }

    #[Test]
    public function the_minutes_open_inline_for_a_board_viewer(): void
    {
        $meeting = $this->meeting();
        $this->upload($meeting);

        $response = $this->actingAs($this->admin())->get(route('board.meetings.attachment.show', $meeting));

        $response->assertOk();
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    #[Test]
    public function the_minutes_need_a_login_and_board_rights(): void
    {
        $meeting = $this->meeting();
        $this->upload($meeting);

        // Back to a guest.
        auth()->forgetGuards();
        $this->get(route('board.meetings.attachment.show', $meeting))->assertRedirect(route('login'));

        $coach = User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::create(['key' => 'coach', 'name' => ['en' => 'Coach'], 'permissions' => ['players' => Role::ACTIONS]])->id,
        ]);
        $this->actingAs($coach)->get(route('board.meetings.attachment.show', $meeting))->assertForbidden();

        $this->assertSame(['board', 'view'], PermissionMap::resolve('board.meetings.attachment.show'));
    }

    #[Test]
    public function the_public_media_route_no_longer_serves_minutes(): void
    {
        $meeting = $this->meeting();
        $this->upload($meeting);

        $this->get('/media/'.$meeting->fresh()->attachment_filename)->assertNotFound();
    }

    #[Test]
    public function a_meeting_without_minutes_has_nothing_to_show(): void
    {
        $this->actingAs($this->admin())
            ->get(route('board.meetings.attachment.show', $this->meeting()))
            ->assertNotFound();
    }

    #[Test]
    public function replacing_the_minutes_removes_the_previous_file(): void
    {
        $meeting = $this->meeting();
        $this->upload($meeting, 'first.pdf');
        $first = $meeting->fresh()->attachment_filename;

        $this->upload($meeting, 'second.pdf');

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($meeting->fresh()->attachment_filename);
    }

    #[Test]
    public function the_migration_moves_existing_minutes_off_the_public_disk(): void
    {
        Storage::disk('public')->put('minutes/old.pdf', 'OLD-MINUTES');
        Storage::disk('public')->put('minutes/legacy.pdf', 'LEGACY-MINUTES');

        $current = $this->meeting(['attachment_url' => '/media/minutes/old.pdf', 'attachment_filename' => 'minutes/old.pdf']);
        // An old row that only kept an absolute URL.
        $legacy = $this->meeting(['attachment_url' => 'http://localhost:8000/storage/minutes/legacy.pdf', 'attachment_filename' => null]);
        // A row whose file is already gone from every disk.
        $gone = $this->meeting(['attachment_url' => '/media/minutes/gone.pdf', 'attachment_filename' => 'minutes/gone.pdf']);

        $migration = require database_path(self::MIGRATION);
        $migration->up();
        // Desktop: a re-run after a partial failure must be harmless.
        $migration->up();

        $this->assertSame('OLD-MINUTES', Storage::disk('local')->get('minutes/old.pdf'));
        $this->assertSame('LEGACY-MINUTES', Storage::disk('local')->get('minutes/legacy.pdf'));
        Storage::disk('public')->assertMissing('minutes/old.pdf');
        Storage::disk('public')->assertMissing('minutes/legacy.pdf');

        $rows = DB::table('board_meetings')->get()->keyBy('id');
        $this->assertSame('minutes/old.pdf', $rows[$current->id]->attachment_filename);
        $this->assertNull($rows[$current->id]->attachment_url);
        $this->assertSame('minutes/legacy.pdf', $rows[$legacy->id]->attachment_filename);
        $this->assertNull($rows[$legacy->id]->attachment_url);
        $this->assertSame('minutes/gone.pdf', $rows[$gone->id]->attachment_filename);
        $this->assertSame('/media/minutes/gone.pdf', $rows[$gone->id]->attachment_url);
    }

    #[Test]
    public function the_migration_finishes_a_copy_that_an_earlier_run_left_half_done(): void
    {
        Storage::disk('public')->put('minutes/old.pdf', 'OLD-MINUTES');
        Storage::disk('local')->put('minutes/old.pdf', 'OLD-'); // interrupted copy
        $meeting = $this->meeting(['attachment_url' => '/media/minutes/old.pdf', 'attachment_filename' => 'minutes/old.pdf']);

        (require database_path(self::MIGRATION))->up();

        $this->assertSame('OLD-MINUTES', Storage::disk('local')->get('minutes/old.pdf'));
        Storage::disk('public')->assertMissing('minutes/old.pdf');
        $this->assertNull(DB::table('board_meetings')->where('id', $meeting->id)->value('attachment_url'));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan config:clear; php artisan test --filter=BoardMinutesPrivateTest`

Expected: FAIL — `Route [board.meetings.attachment.show] not defined.` and the migration file is missing.

- [ ] **Step 3: Store and serve privately**

In `app/Http/Controllers/BoardMeetingController.php`, add the imports:

```php
use App\Services\Storage\PrivateFileStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;
```

Replace the whole `attachment()` method and its docblock:

```php
    /**
     * Attach the signed minutes document to a meeting (PDF, Word, or a photo).
     * Stored on the public disk with a host-relative /media URL so it downloads
     * on both the web app and the desktop app. Replaces any previous file.
     */
    public function attachment(Request $request, BoardMeeting $meeting, FileStorageService $storage): RedirectResponse
    {
        if ($meeting->isCancelled()) {
            return back()->with('error', 'flash.meeting_is_cancelled');
        }

        $request->validate([
            'attachment' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $storage->delete($meeting->attachment_filename);

        $stored = $storage->storeFile($request->file('attachment'), 'minutes');
        $meeting->update([
            'attachment_url' => $stored['url'],
            'attachment_filename' => $stored['filename'],
        ]);

        return back()->with('success', 'flash.minutes_file_uploaded');
    }

    public function deleteAttachment(BoardMeeting $meeting, FileStorageService $storage): RedirectResponse
    {
        if ($meeting->isCancelled()) {
            return back()->with('error', 'flash.meeting_is_cancelled');
        }

        $storage->delete($meeting->attachment_filename);
        $meeting->update(['attachment_url' => null, 'attachment_filename' => null]);

        return back()->with('success', 'flash.minutes_file_removed');
    }
```

with:

```php
    /**
     * Attach the signed minutes document to a meeting (PDF, Word, or a photo).
     *
     * Minutes are private: they go on the private disk and are served only by
     * showAttachment(), behind login and board/view — never by the public
     * /media route. Replaces any previous file.
     */
    public function attachment(Request $request, BoardMeeting $meeting, PrivateFileStorage $storage, FileStorageService $legacy): RedirectResponse
    {
        if ($meeting->isCancelled()) {
            return back()->with('error', 'flash.meeting_is_cancelled');
        }

        $request->validate([
            'attachment' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $this->forgetStoredAttachment($meeting, $storage, $legacy);

        $stored = $storage->store($request->file('attachment'), 'minutes');
        $meeting->update([
            'attachment_url' => null,
            'attachment_filename' => $stored['path'],
        ]);

        return back()->with('success', 'flash.minutes_file_uploaded');
    }

    public function showAttachment(BoardMeeting $meeting, PrivateFileStorage $storage): StreamedResponse
    {
        $path = $meeting->attachment_filename;

        abort_unless($path && $storage->exists($path), 404);

        return $storage->inline($path, basename($path));
    }

    public function deleteAttachment(BoardMeeting $meeting, PrivateFileStorage $storage, FileStorageService $legacy): RedirectResponse
    {
        if ($meeting->isCancelled()) {
            return back()->with('error', 'flash.meeting_is_cancelled');
        }

        $this->forgetStoredAttachment($meeting, $storage, $legacy);
        $meeting->update(['attachment_url' => null, 'attachment_filename' => null]);

        return back()->with('success', 'flash.minutes_file_removed');
    }

    /**
     * Remove the current file wherever it lives: the private disk, or — for a
     * row the minutes migration could not move — the public one.
     */
    private function forgetStoredAttachment(BoardMeeting $meeting, PrivateFileStorage $storage, FileStorageService $legacy): void
    {
        $storage->delete($meeting->attachment_filename);
        $legacy->delete($meeting->attachment_filename);
    }
```

In `app/Models/BoardMeeting.php`, replace:

```php
    /** Normalise the stored attachment URL to a host-relative /media path (web + desktop). */
    protected function attachmentUrl(): Attribute
    {
        return Attribute::make(get: fn ($value) => Media::path($value));
    }
```

with:

```php
    /**
     * Where the page links the minutes file. A stored file is private and only
     * reachable through the authenticated route (host-relative, so it works on
     * the web and in the desktop window). A legacy external link is kept as is.
     */
    protected function attachmentUrl(): Attribute
    {
        return Attribute::make(get: function ($value, array $attributes) {
            if (! empty($attributes['attachment_filename']) && ! empty($attributes['id'])) {
                return route('board.meetings.attachment.show', $attributes['id'], false);
            }

            return Media::path($value);
        });
    }
```

In `routes/web.php`, replace:

```php
        Route::post('/board/meetings/{meeting}/attachment', [BoardMeetingController::class, 'attachment'])->name('board.meetings.attachment');
```

with:

```php
        Route::post('/board/meetings/{meeting}/attachment', [BoardMeetingController::class, 'attachment'])->name('board.meetings.attachment');
        // Minutes are private: this (auth + board/view) is the only way to read them.
        Route::get('/board/meetings/{meeting}/attachment', [BoardMeetingController::class, 'showAttachment'])->name('board.meetings.attachment.show');
```

- [ ] **Step 4: Move the existing files**

Create `database/migrations/2026_09_24_100004_move_meeting_minutes_to_private_disk.php`:

```php
<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Board minutes used to sit on the public disk, readable by anyone through
     * /media (and the web server's public/storage symlink). Move every one of
     * them to the private disk, where only the authenticated
     * board.meetings.attachment.show route can read them.
     *
     * Idempotent, because the desktop build re-runs an unrecorded migration on
     * the next boot: a file is (re)copied while the private copy is missing or
     * differs in size, the public copy is deleted only once the private one
     * matches it, and a row is re-pointed only when its private file exists.
     * A row whose file is on neither disk is left exactly as it was.
     */
    public function up(): void
    {
        if (! Schema::hasTable('board_meetings')) {
            return;
        }

        $public = Storage::disk('public');
        $private = Storage::disk('local');

        $rows = DB::table('board_meetings')
            ->where(fn ($query) => $query->whereNotNull('attachment_filename')->orWhereNotNull('attachment_url'))
            ->orderBy('id')
            ->get(['id', 'attachment_url', 'attachment_filename']);

        foreach ($rows as $row) {
            $path = $this->relativePath($row);

            if ($path === null) {
                continue;
            }

            if ($public->exists($path) && (! $private->exists($path) || $private->size($path) !== $public->size($path))) {
                $this->copy($public, $private, $path);
            }

            if (! $private->exists($path)) {
                continue;
            }

            if ($public->exists($path) && $public->size($path) === $private->size($path)) {
                $public->delete($path);
            }

            DB::table('board_meetings')->where('id', $row->id)->update([
                'attachment_filename' => $path,
                'attachment_url' => null,
            ]);
        }
    }

    /** Put the files back on the public disk with their /media URL. */
    public function down(): void
    {
        if (! Schema::hasTable('board_meetings')) {
            return;
        }

        $public = Storage::disk('public');
        $private = Storage::disk('local');

        foreach (DB::table('board_meetings')->whereNotNull('attachment_filename')->get(['id', 'attachment_filename']) as $row) {
            $path = $row->attachment_filename;

            if (! str_starts_with($path, 'minutes/') || ! $private->exists($path)) {
                continue;
            }

            $this->copy($private, $public, $path);
            $private->delete($path);

            DB::table('board_meetings')->where('id', $row->id)->update(['attachment_url' => '/media/'.$path]);
        }
    }

    /** The file's path inside a disk, from the filename or from an old URL; null if not a minutes file. */
    private function relativePath(object $row): ?string
    {
        $path = $row->attachment_filename;

        if (! $path && $row->attachment_url
            && preg_match('#(?:^|/)(?:media|storage)/(minutes/.+)$#i', (string) $row->attachment_url, $match)) {
            $path = $match[1];
        }

        if (! $path) {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', (string) $path), '/');

        if (! str_starts_with($path, 'minutes/') || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return $path;
    }

    private function copy(Filesystem $from, Filesystem $to, string $path): void
    {
        $stream = $from->readStream($path);

        try {
            $to->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
};
```

- [ ] **Step 5: The existing test follows the new rule**

In `tests/Feature/BoardCalendarTest.php`, replace the whole method `admin_can_upload_and_remove_a_minutes_file()`:

```php
    #[Test]
    public function admin_can_upload_and_remove_a_minutes_file(): void
    {
        Storage::fake('public');
        $meeting = BoardMeeting::create([
            'title' => 'AGM', 'type' => 'general_assembly',
            'meeting_date' => now(), 'status' => 'held',
        ]);

        $this->actingAs($this->admin())
            ->post(route('board.meetings.attachment', $meeting), [
                'attachment' => UploadedFile::fake()->create('minutes.pdf', 200, 'application/pdf'),
            ])->assertRedirect();

        $meeting->refresh();
        $this->assertStringStartsWith('minutes/', (string) $meeting->attachment_filename);
        $this->assertStringStartsWith('/media/minutes/', (string) $meeting->attachment_url);
        Storage::disk('public')->assertExists($meeting->attachment_filename);

        $stored = $meeting->attachment_filename;

        $this->actingAs($this->admin())
            ->delete(route('board.meetings.attachment.delete', $meeting))
            ->assertRedirect();

        $meeting->refresh();
        $this->assertNull($meeting->attachment_url);
        Storage::disk('public')->assertMissing($stored);
    }
```

with:

```php
    #[Test]
    public function admin_can_upload_and_remove_a_minutes_file(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $meeting = BoardMeeting::create([
            'title' => 'AGM', 'type' => 'general_assembly',
            'meeting_date' => now(), 'status' => 'held',
        ]);

        $this->actingAs($this->admin())
            ->post(route('board.meetings.attachment', $meeting), [
                'attachment' => UploadedFile::fake()->create('minutes.pdf', 200, 'application/pdf'),
            ])->assertRedirect();

        $meeting->refresh();
        $this->assertStringStartsWith('minutes/', (string) $meeting->attachment_filename);
        // Minutes are private (P3): an authenticated route, never a /media URL.
        $this->assertSame(route('board.meetings.attachment.show', $meeting, false), $meeting->attachment_url);
        Storage::disk('local')->assertExists($meeting->attachment_filename);
        Storage::disk('public')->assertMissing($meeting->attachment_filename);

        $stored = $meeting->attachment_filename;

        $this->actingAs($this->admin())
            ->delete(route('board.meetings.attachment.delete', $meeting))
            ->assertRedirect();

        $meeting->refresh();
        $this->assertNull($meeting->attachment_url);
        Storage::disk('local')->assertMissing($stored);
    }
```

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `php artisan test --filter="BoardMinutesPrivateTest|BoardCalendarTest|BoardMeetingCancellationTest|ReportPdfTest"`

Expected: PASS (BoardMinutesPrivateTest: 8 tests).

Manual: open a held meeting → upload a PDF → the link opens it in a new tab (web and desktop window); copy the link into a private browser window → the login page; `/media/minutes/<file>` → 404.

- [ ] **Step 7: Commit**

```bash
php vendor/bin/pint app/Http/Controllers/BoardMeetingController.php app/Models/BoardMeeting.php routes/web.php database/migrations/2026_09_24_100004_move_meeting_minutes_to_private_disk.php tests/Feature/BoardMinutesPrivateTest.php tests/Feature/BoardCalendarTest.php
git add app/Http/Controllers/BoardMeetingController.php app/Models/BoardMeeting.php routes/web.php database/migrations/2026_09_24_100004_move_meeting_minutes_to_private_disk.php tests/Feature/BoardMinutesPrivateTest.php tests/Feature/BoardCalendarTest.php
git commit -m "fix(board): keep meeting minutes private behind login and move existing files"
git show --stat HEAD
```

---

### Task 13: Backups carry the private files

**Why:** Spec — `BackupService` includes only `storage/app/public`; it must include the player documents, both directions, with a test. The minutes now live on the private disk too (Task 12), so both private folders are carried.

**Read first:** `app/Services/Backup/BackupService.php` in full, and the "database-backup-restore" section of `.superpowers/sdd/progress.md`. The restore is subtle on Windows: the database swap is a single `rename()` after the WAL is checkpointed; the media swap is `rename()` only — **never** a copy fallback (it would merge trees) — with the originals put back on failure; everything that can be checked is checked in staging **before** the pre-restore snapshot, so a bad archive changes nothing. The private folders must follow exactly the same pattern.

**Design (minimal):**
- `BackupService` takes an optional fourth constructor argument `array $privateRoots` = archive name ⇒ live folder. Default `[]`, so every existing test and caller is unchanged. Names must match `^[a-z0-9][a-z0-9-]*$`.
- **Backup:** after the media, each root's files go into the zip under `private/<name>/<relative path>`; a failed `addFile()` fails the backup (as for media). The manifest gains `private_roots: { "<name>": <file count> }`.
- **Restore, in staging (before the snapshot):** for each configured root, `private/<name>` must exist in the archive when the manifest counts files for it (otherwise "That backup is incomplete…", nothing changed); when it counts none — or the manifest predates this feature and has no `private_roots` at all — an empty folder is staged, so the restore leaves the root **empty**, "exactly as it was" (before this release nothing was private: the minutes were in the media tree, which the restore puts back, and the next boot's migrate moves them again).
- **Restore, past the point of no return (after the media swap):** for each root: create the parent if needed, `rename(live → live.old-<ts>)`, `rename(staged → live)`; on failure put the original back (or report where it is stranded) and throw. Retired folders are deleted after success; any that cannot be deleted are returned (joined with ` ; `) exactly like the leftover media folder today.
- `AppServiceProvider` passes `['player-documents' => storage_path('app/private/player-documents'), 'minutes' => storage_path('app/private/minutes')]`. Not the whole private disk: only these two folders are app data.

**Files:**
- Modify: `app/Services/Backup/BackupService.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/Services/BackupPrivateRootsTest.php`

**Interfaces:**
- `new BackupService(BackupSettings $settings, string $databasePath, string $mediaPath, array $privateRoots = [])`
- protected `privateFiles(string $root): iterable` (absolute ⇒ relative), beside the existing protected `mediaFiles()`
- `restore(string $zipPath): ?string` — unchanged contract; a leftover may now also be a retired private folder.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/BackupPrivateRootsTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use InvalidArgumentException;
use Native\Desktop\Facades\Settings;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeNativeSettings;
use Tests\TestCase;
use ZipArchive;

class BackupPrivateRootsTest extends TestCase
{
    private string $root;

    private string $dbPath;

    private string $mediaPath;

    private string $documentsPath;

    private string $minutesPath;

    private string $destination;

    private BackupSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::swap(new FakeNativeSettings);

        // Under storage_path(), like BackupRestoreTest: the restore moves folders with
        // a plain rename(), which only works on the volume the staging area lives on.
        $this->root = storage_path('app').DIRECTORY_SEPARATOR.'backup-private-test-'.uniqid();
        $this->dbPath = $this->root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'database.sqlite';
        $this->mediaPath = $this->root.DIRECTORY_SEPARATOR.'public';
        $this->documentsPath = $this->root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'player-documents';
        $this->minutesPath = $this->root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'minutes';
        $this->destination = $this->root.DIRECTORY_SEPARATOR.'dest';

        mkdir(dirname($this->dbPath), 0777, true);
        mkdir($this->mediaPath, 0777, true);
        mkdir($this->documentsPath.DIRECTORY_SEPARATOR.'7', 0777, true);
        mkdir($this->minutesPath, 0777, true);
        mkdir($this->destination, 0777, true);

        // WAL, as the packaged app always is (see BackupRestoreTest).
        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT)');
        $pdo->exec('CREATE TABLE players (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO players (name) VALUES ('Ali')");
        $pdo = null;

        file_put_contents($this->mediaPath.DIRECTORY_SEPARATOR.'photo.jpg', 'PHOTO');
        file_put_contents($this->documentsPath.DIRECTORY_SEPARATOR.'7'.DIRECTORY_SEPARATOR.'scan.pdf', 'SCAN');
        file_put_contents($this->minutesPath.DIRECTORY_SEPARATOR.'agm.pdf', 'MINUTES');

        $this->settings = new BackupSettings;
        $this->settings->put(['destination' => $this->destination]);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        foreach (glob(storage_path('app/backup-tmp').DIRECTORY_SEPARATOR.'restore-*') ?: [] as $leftover) {
            $this->deleteTree($leftover);
        }

        parent::tearDown();
    }

    private function service(): BackupService
    {
        return new BackupService($this->settings, $this->dbPath, $this->mediaPath, [
            'player-documents' => $this->documentsPath,
            'minutes' => $this->minutesPath,
        ]);
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /** relative path (forward slashes) => contents */
    private function tree(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $tree = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                $tree[$relative] = file_get_contents($file->getPathname());
            }
        }

        ksort($tree);

        return $tree;
    }

    private function players(): array
    {
        return (new \PDO('sqlite:'.$this->dbPath))->query('SELECT name FROM players ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** A copy of $backup without its private/ entries, with $manifest written in. */
    private function rewrite(string $backup, array $manifest): string
    {
        $source = new ZipArchive;
        $source->open($backup);

        $copy = $this->destination.DIRECTORY_SEPARATOR.BackupService::PREFIX.'2026-01-05_000000.zip';
        $target = new ZipArchive;
        $target->open($copy, ZipArchive::CREATE);

        for ($i = 0; $i < $source->numFiles; $i++) {
            $name = $source->getNameIndex($i);

            if ($name === 'manifest.json' || str_starts_with($name, 'private/')) {
                continue;
            }

            $target->addFromString($name, $source->getFromIndex($i));
        }

        $target->addFromString('manifest.json', json_encode($manifest));
        $target->close();
        $source->close();

        return $copy;
    }

    private function manifestOf(string $backup): array
    {
        $zip = new ZipArchive;
        $zip->open($backup);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        return $manifest;
    }

    #[Test]
    public function a_backup_carries_each_private_root_and_counts_it(): void
    {
        $backup = $this->service()->create();

        $zip = new ZipArchive;
        $zip->open($backup);
        $this->assertSame('SCAN', $zip->getFromName('private/player-documents/7/scan.pdf'));
        $this->assertSame('MINUTES', $zip->getFromName('private/minutes/agm.pdf'));
        $this->assertSame('PHOTO', $zip->getFromName('media/photo.jpg'));
        $zip->close();

        $manifest = $this->manifestOf($backup);
        $this->assertSame(['player-documents' => 1, 'minutes' => 1], $manifest['private_roots']);
        $this->assertSame(1, $manifest['media_files'], 'private files are not counted as media');
    }

    #[Test]
    public function restoring_puts_the_private_roots_back_exactly_as_they_were(): void
    {
        $backup = $this->service()->create();

        file_put_contents($this->documentsPath.DIRECTORY_SEPARATOR.'7'.DIRECTORY_SEPARATOR.'scan.pdf', 'CHANGED');
        file_put_contents($this->documentsPath.DIRECTORY_SEPARATOR.'7'.DIRECTORY_SEPARATOR.'later.pdf', 'ADDED-AFTER');
        unlink($this->minutesPath.DIRECTORY_SEPARATOR.'agm.pdf');

        $this->assertNull($this->service()->restore($backup));

        $this->assertSame(['7/scan.pdf' => 'SCAN'], $this->tree($this->documentsPath));
        $this->assertSame(['agm.pdf' => 'MINUTES'], $this->tree($this->minutesPath));
        $this->assertSame(['photo.jpg' => 'PHOTO'], $this->tree($this->mediaPath));
        $this->assertSame([], glob($this->root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'*.old-*') ?: [], 'retired folders are cleaned up');
    }

    #[Test]
    public function a_root_that_did_not_exist_yet_is_created_by_the_restore(): void
    {
        $backup = $this->service()->create();
        $this->deleteTree($this->root.DIRECTORY_SEPARATOR.'private');

        $this->service()->restore($backup);

        $this->assertSame(['7/scan.pdf' => 'SCAN'], $this->tree($this->documentsPath));
        $this->assertSame(['agm.pdf' => 'MINUTES'], $this->tree($this->minutesPath));
    }

    #[Test]
    public function a_backup_from_before_private_storage_restores_empty_private_roots(): void
    {
        $manifest = $this->manifestOf($backup = $this->service()->create());
        unset($manifest['private_roots']);
        $legacy = $this->rewrite($backup, $manifest);

        $this->service()->restore($legacy);

        $this->assertDirectoryExists($this->documentsPath);
        $this->assertSame([], $this->tree($this->documentsPath));
        $this->assertSame([], $this->tree($this->minutesPath));
        $this->assertSame(['Ali'], $this->players());
    }

    #[Test]
    public function it_rejects_a_backup_whose_manifest_promises_private_files_it_does_not_hold(): void
    {
        $manifest = $this->manifestOf($backup = $this->service()->create());
        $stripped = $this->rewrite($backup, $manifest); // private/ removed, counts kept

        $caught = null;
        try {
            $this->service()->restore($stripped);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'the restore should have been refused');
        $this->assertStringContainsString('incomplete', $caught->getMessage());

        // Caught in staging, before the snapshot: nothing changed.
        $this->assertSame(['7/scan.pdf' => 'SCAN'], $this->tree($this->documentsPath));
        $this->assertSame(['agm.pdf' => 'MINUTES'], $this->tree($this->minutesPath));
        $this->assertSame([], glob($this->destination.DIRECTORY_SEPARATOR.BackupService::SNAPSHOT_DIR.DIRECTORY_SEPARATOR.'*.zip') ?: []);
    }

    #[Test]
    public function a_service_without_private_roots_behaves_as_before(): void
    {
        $backup = (new BackupService($this->settings, $this->dbPath, $this->mediaPath))->create();

        $zip = new ZipArchive;
        $zip->open($backup);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertStringStartsNotWith('private/', $zip->getNameIndex($i));
        }
        $zip->close();

        $this->assertSame([], $this->manifestOf($backup)['private_roots']);
    }

    #[Test]
    public function a_private_root_name_must_be_a_plain_folder_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BackupService($this->settings, $this->dbPath, $this->mediaPath, ['../escape' => $this->documentsPath]);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan config:clear; php artisan test --filter=BackupPrivateRootsTest`

Expected: FAIL — `a_private_root_name_must_be_a_plain_folder_name` (no exception) and the archive has no `private/…` entries (`assertSame('SCAN', false)`), `Undefined array key "private_roots"`.

- [ ] **Step 3: Constructor**

In `app/Services/Backup/BackupService.php`, replace:

```php
    public function __construct(
        private BackupSettings $settings,
        private string $databasePath,
        private string $mediaPath,
    ) {}
```

with:

```php
    /**
     * @param  array<string, string>  $privateRoots  archive name => live folder. Files that must
     *                                               never be public (player documents, board minutes) live outside the
     *                                               media tree; each root is carried, checked and swapped exactly like
     *                                               the media tree.
     */
    public function __construct(
        private BackupSettings $settings,
        private string $databasePath,
        private string $mediaPath,
        private array $privateRoots = [],
    ) {
        foreach (array_keys($privateRoots) as $name) {
            if (! is_string($name) || ! preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
                throw new \InvalidArgumentException("Not a usable private backup root name: {$name}");
            }
        }
    }
```

- [ ] **Step 4: Backup side**

Replace:

```php
                    $mediaFiles++;
                }
```

with:

```php
                    $mediaFiles++;
                }

                $privateFiles = [];

                foreach ($this->privateRoots as $name => $root) {
                    $privateFiles[$name] = 0;

                    foreach ($this->privateFiles($root) as $absolute => $relative) {
                        // Same rule as the media: a file that cannot be added fails the
                        // whole backup rather than producing a silently incomplete one.
                        if (! @$zip->addFile($absolute, 'private/'.$name.'/'.$relative)) {
                            throw new RuntimeException("Could not add a file to the backup: {$absolute}");
                        }

                        $privateFiles[$name]++;
                    }
                }
```

Replace:

```php
                    'media_files' => $mediaFiles,
```

with:

```php
                    'media_files' => $mediaFiles,
                    // An object even when empty, so "no private files" ({}) is told apart
                    // from a backup made before private roots existed (no key at all).
                    'private_roots' => (object) $privateFiles,
```

Replace the whole `mediaFiles()` method (its docblock stays):

```php
    protected function mediaFiles(): iterable
    {
        if (! is_dir($this->mediaPath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->mediaPath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->mediaPath) + 1));

            yield $file->getPathname() => $relative;
        }
    }
```

with:

```php
    protected function mediaFiles(): iterable
    {
        yield from $this->filesUnder($this->mediaPath);
    }

    /**
     * @return iterable<string, string> absolute path => path relative to $root
     *
     * Protected for the same reason as mediaFiles(): a test can stand in for a
     * file that vanishes between being listed and being archived.
     */
    protected function privateFiles(string $root): iterable
    {
        yield from $this->filesUnder($root);
    }

    /** @return iterable<string, string> absolute path => path relative to $root */
    private function filesUnder(string $root): iterable
    {
        if (! is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            yield $file->getPathname() => $relative;
        }
    }
```

- [ ] **Step 5: Restore side — staging checks**

Replace:

```php
            // 3. Undo point. Still non-destructive.
            $snapshot = $this->snapshot();
```

with:

```php
            // The private roots get the same two checks as the media tree, here in
            // staging and before the snapshot, so a truncated archive is still refused
            // with nothing changed.
            $stagedPrivate = $this->stagePrivateRoots($staging, is_array($manifest) ? $manifest : []);

            // 3. Undo point. Still non-destructive.
            $snapshot = $this->snapshot();
```

Add this method directly after `assertHealthyDatabase()`:

```php
    /**
     * For every configured private root, the staged folder the swap will move into
     * place — checked the way the media tree is checked, before anything is touched.
     *
     * A root the manifest counts files for must be in the archive, or the backup is
     * incomplete. A root it counts none for — or every root, for a backup made before
     * private roots existed (no `private_roots` key) — is staged EMPTY, so the restore
     * leaves it exactly as it was at backup time rather than keeping today's files
     * beside a database that knows nothing about them.
     *
     * @return array<string, string> name => staged folder
     */
    private function stagePrivateRoots(string $staging, array $manifest): array
    {
        $counts = is_array($manifest['private_roots'] ?? null) ? $manifest['private_roots'] : [];
        $staged = [];

        foreach (array_keys($this->privateRoots) as $name) {
            $dir = $staging.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.$name;
            $expected = (int) ($counts[$name] ?? 0);

            if ($expected > 0 && ! is_dir($dir)) {
                throw new RuntimeException(
                    "That backup is incomplete: it says it holds {$expected} file(s) in {$name}, "
                    .'but there are none inside the archive. Nothing was changed.'
                );
            }

            if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
                throw new RuntimeException("Could not prepare the restored {$name} folder: {$dir}");
            }

            $staged[$name] = $dir;
        }

        return $staged;
    }
```

- [ ] **Step 6: Restore side — the swap**

Replace:

```php
            $strandedMedia = null;
            $retiredMedia = null;
```

with:

```php
            $strandedMedia = null;
            $strandedPrivate = null;
            $retiredMedia = null;
            $retiredPrivate = [];
```

Replace:

```php
                    $retiredMedia = $retired;
                }
            } catch (\Throwable $e) {
```

with:

```php
                    $retiredMedia = $retired;
                }

                // The private roots, one by one, exactly like the media tree above:
                // rename() only, the originals put back on failure, never a copy that
                // could merge the backup's files into today's.
                foreach ($stagedPrivate as $name => $stagedDir) {
                    $live = $this->privateRoots[$name];
                    $retired = $live.'.old-'.$this->timestamp();
                    $parent = dirname($live);

                    if (! is_dir($parent) && ! @mkdir($parent, 0777, true) && ! is_dir($parent)) {
                        throw new RuntimeException("Could not prepare the folder that holds {$name}: {$parent}");
                    }

                    if (is_dir($live) && ! @rename($live, $retired)) {
                        throw new RuntimeException("Could not move the current {$name} folder aside.");
                    }

                    if (! @rename($stagedDir, $live)) {
                        if (is_dir($retired) && ! @rename($retired, $live)) {
                            $strandedPrivate = $retired;
                        }

                        throw new RuntimeException("Could not write the restored {$name} folder into place.");
                    }

                    if (is_dir($retired)) {
                        $retiredPrivate[] = $retired;
                    }
                }
            } catch (\Throwable $e) {
```

Replace:

```php
                    if ($strandedMedia !== null) {
                        $message .= ' Your media files could not be put back automatically — they are in: '.$strandedMedia;
                    }
```

with:

```php
                    if ($strandedMedia !== null) {
                        $message .= ' Your media files could not be put back automatically — they are in: '.$strandedMedia;
                    }

                    if ($strandedPrivate !== null) {
                        $message .= ' Some private files could not be put back automatically — they are in: '.$strandedPrivate;
                    }
```

Replace:

```php
            if ($retiredMedia !== null && ! $this->deleteDirectory($retiredMedia)) {
                Log::warning('The restore succeeded, but the superseded media folder could not be deleted.', [
                    'leftover' => $retiredMedia,
                    'snapshot' => $snapshot,
                ]);

                return $retiredMedia;
            }

            return null;
```

with:

```php
            $leftovers = [];

            foreach (array_filter([$retiredMedia, ...$retiredPrivate]) as $retired) {
                if (! $this->deleteDirectory($retired)) {
                    $leftovers[] = $retired;
                }
            }

            if ($leftovers !== []) {
                Log::warning('The restore succeeded, but a superseded folder could not be deleted.', [
                    'leftover' => $leftovers,
                    'snapshot' => $snapshot,
                ]);

                // One folder (the usual case) comes back exactly as before; several are
                // listed together so the user hears about every one of them.
                return implode(' ; ', $leftovers);
            }

            return null;
```

- [ ] **Step 7: Wire the two roots**

In `app/Providers/AppServiceProvider.php`, replace:

```php
            config('nativephp-internal.database_path') ?: database_path('database.sqlite'),
            storage_path('app/public'),
        ));
```

with:

```php
            config('nativephp-internal.database_path') ?: database_path('database.sqlite'),
            storage_path('app/public'),
            [
                // Private (P3): served only through authenticated routes, so they live
                // on the private disk — and must still come back with a restore.
                'player-documents' => storage_path('app/private/player-documents'),
                'minutes' => storage_path('app/private/minutes'),
            ],
        ));
```

- [ ] **Step 8: Run the tests and confirm they pass**

Run: `php artisan test --filter="BackupPrivateRootsTest|BackupServiceTest|BackupRestoreTest|BackupPageTest|ClubIdentityTest"`

Expected: PASS (BackupPrivateRootsTest: 7 tests; every existing backup test unchanged — in particular `a_superseded_media_folder_that_cannot_be_deleted_is_returned_not_thrown` still receives the single media folder path).

- [ ] **Step 9: Commit**

```bash
php vendor/bin/pint app/Services/Backup/BackupService.php app/Providers/AppServiceProvider.php tests/Unit/Services/BackupPrivateRootsTest.php
git add app/Services/Backup/BackupService.php app/Providers/AppServiceProvider.php tests/Unit/Services/BackupPrivateRootsTest.php
git commit -m "feat(backup): back up and restore player documents and minutes"
git show --stat HEAD
```

---

### Task 14: Full verification, deploy notes and manual check

- [ ] **Step 1: Everything green**

| Command | Expected |
|---|---|
| `composer test` | all pass: **822** (722 before this plan + 100 new: 7 + 10 + 12 + 10 + 14 + 19 + 10 + 3 + 8 + 7); record the exact count |
| `node scripts/i18n-check.mjs` | `✓ …` |
| `npm run build` | success |
| `php vendor/bin/pint --test app tests database routes config` | only files this branch never touched |
| `git status --short` | only `?? .claude/`; no `i18n-keys.tmp.json` |
| `head -c 3 resources/js/Pages/Players/Show.vue \| od -An -tx1` and the same for `Players/Index.vue` | `ef bb bf` for both |
| `head -c 3 resources/js/Components/PlayerDocumentsCard.vue \| od -An -tx1` and `Settings/DocumentTypes.vue` | `3c 73 63` (`<sc`, no BOM) |
| `grep -nE "[^a-zA-Z_]te\(" resources/js/Components/PlayerDocumentsCard.vue resources/js/Pages/Settings/DocumentTypes.vue` | nothing |
| `ls database/migrations \| grep 2026_09_24` | exactly `100001`–`100004` |

- [ ] **Step 2: Migrate the dev database and check the data**

```bash
php artisan migrate
php -r '$p=new PDO("sqlite:database/database.sqlite");
echo "document types=",$p->query("select count(*) from document_types")->fetchColumn(),"\n";
echo "parental max_age=",$p->query("select max_age from document_types where code=\"parental_authorization\"")->fetchColumn(),"\n";
foreach($p->query("select key, permissions from roles") as $r){ $perm=json_decode($r["permissions"],true); echo $r["key"],": documents=",json_encode($perm["documents"]??null),"\n"; }
echo "minutes still public=",$p->query("select count(*) from board_meetings where attachment_url like \"%/minutes/%\"")->fetchColumn(),"\n";'
```

Expected: 7 document types; parental `max_age` 17; `superadmin` and `administrator` have `["view","add","edit","delete"]`, other roles `null`; 0 minutes still public.

Run `php artisan migrate:rollback --step=4` then `php artisan migrate` once more: both succeed (down()s are safe, up()s re-run cleanly).

- [ ] **Step 3: Manual check in the browser, FR then AR**

1. **Settings → Document types:** seven defaults; Parental authorization shows "Jusqu'à 17 ans" / "حتى 17 سنة"; add a type, edit it, deactivate it (greyed), delete an unused one (ConfirmModal); a type with records has no delete button.
2. **Roles:** the matrix has a "Documents" row; Administrator has all four boxes ticked; Coach has none.
3. **A player (adult, no picture):** Documents card says "3 manquant(s)"; Photo row says "Reprise de la photo de profil"; Parental authorization is "Non requis · Non requis à cet âge".
4. **A minor:** 4 missing, Parental authorization required.
5. **Mark received** the medical certificate with two files (one PDF, one phone photo); it shows "Reçu + scanné" and "Valable jusqu'au 31/08/…" (end of season). Open a file (inline, new tab) and download the other (original name).
6. **ID card copy:** the dialog asks for the expiry date; an expiry within 30 days shows "Expire bientôt" in orange and the card header shows the expiring count.
7. **Renew** the medical certificate: dates move, the earlier files are still listed.
8. **Not required** on the birth certificate with a reason; undo it (ConfirmModal).
9. **Remove a file** (ConfirmModal); it is gone after reload.
10. **Players list:** the Documents column; "Documents manquants", "Expirant bientôt" and "Un document précis manquant → Certificat médical" each narrow the list and the charts; "Sans wilaya" lists players without a wilaya; the filter survives a page refresh.
11. **Permissions:** log in as a user whose role has players but not documents → no Documents card; the list column and filters still work; opening a copied file URL → 403.
12. **Board minutes:** upload minutes to a held meeting; the link opens them; the same link in a private window → login page; `/media/minutes/<file>` → 404.
13. **Phone browser** (web): the file button on the player page offers the camera.
14. Switch to Arabic and repeat 3, 5 and 10: every label in Arabic, layout right-to-left, dates readable.
15. **Desktop (after the build):** make a backup, add a document file, restore the backup → the file added afterwards is gone and the earlier one is back; minutes open in the desktop window.

- [ ] **Step 4: Desktop build (PowerShell)**

1. Refresh the seed from the migrated dev database (the seed is git-ignored — never committed):
   ```bash
   php -r '@unlink("storage/app/seed/database.sqlite"); $p=new PDO("sqlite:database/database.sqlite"); $p->exec("VACUUM INTO \"storage/app/seed/database.sqlite\"");'
   php -r '$p=new PDO("sqlite:storage/app/seed/database.sqlite"); echo $p->query("select count(*) from document_types")->fetchColumn(),"\n";'
   ```
   Expected: `7`. Keep a copy of the previous seed in `database/` (e.g. `database/seed-backup-2026-09-24-pre-1.8.0.sqlite`), never in `storage/app/seed/`.
2. Set `NATIVEPHP_APP_VERSION=1.8.0` in `.env` (git-ignored), then `php artisan config:clear`.
3. Confirm the NativePHP `afterPack` patch (VC++ DLLs) is still in `vendor/nativephp/desktop/resources/electron/electron-builder.mjs` (`grep -n afterPack …`). This plan ran no composer command, so it should be intact.
4. Confirm `storage/app/private` is in `cleanup_exclude_files` (`grep -n "storage/app/private" config/nativephp.php`) and clear `storage/app/mpdf`.
5. `php artisan native:build win x64 --no-interaction`; copy the installer from `nativephp/electron/dist/` to `D:\SPORT_CLUB-installers` (never inside the project).

- [ ] **Step 5: Deploy notes for the PR description**

- Run `php artisan migrate` (four migrations `2026_09_24_100001`–`100004`): `documents` permission for the admin roles; `document_types` + 7 defaults; `player_documents` + `player_document_files`; board minutes moved from the public to the private disk.
- **Web deploy:** the minutes migration moves files between `storage/app/public/minutes` and `storage/app/private/minutes` — make sure the PHP user can write `storage/app/private`. After it, `/storage/minutes/…` and `/media/minutes/…` return 404 by design.
- **Other roles:** only Super Admin and Administrator get documents. Give it to other roles (e.g. a secretary) in Roles.
- **Refresh `storage/app/seed/database.sqlite`** before building the desktop app (done in Step 4), or new installs miss the new tables.
- **Version 1.8.0.** Upgrades migrate on first launch (the migration signature changed). A fresh install starts with an empty private disk.
- **Backups made with 1.8.0** carry `private/player-documents` and `private/minutes`. Restoring an older backup empties both folders (nothing was private before 1.8.0) and the next launch moves that backup's minutes again.
- **Found in passing, not changed:** transaction receipts (`receipts/` on the public disk, linked from the transaction form) are also financial documents served by the public `/media` route. They were outside the owner's decision; flag them for a follow-up.

---

## Spec coverage check

| Spec item (P3) | Task |
|---|---|
| Owner decision: own `documents` module (view / add / edit / delete), admins by default, every document action checks it | 1, 7, 8 |
| Owner decision: list column and filters need players/view only | 9 |
| Owner decision: Photo = profile picture, no second upload | 2 (seed `photo`), 6 (rule), 7 (refused), 8 (hint) |
| Owner decision: age limit `max_age` instead of the category pivot; no birth date = not limited; Parental authorization required, max 17 | 2, 6 |
| Owner decision: "Expires soon" (30 days, orange), not missing; list filter | 6, 8, 9, 10 |
| Owner decision: several files per document; `pdf,jpg,jpeg,png,webp`; camera via `accept`, no forced `capture` | 7, 8 |
| Owner decision: minutes/attachments private — authenticated route, `board/view`, private disk, existing files migrated; logos/branding/player pictures stay public | 12 |
| Owner decision: "Sans wilaya" filter option (`wilaya_id=none`) | 9, 10 |
| Data model `document_types` (code, localized names, required, validity, active, order) | 2 |
| Data model `player_documents` (unique per player+type, state, dates, reason, notes, recorded by) | 3 |
| Data model `player_document_files` (path, original name, mime, size, uploaded by, created_at) | 3 |
| Validity rules: season end / entered date (required) / none; renewal on the same row, earlier files kept | 2, 7 |
| Checklist states (received + scanned, paper, expired, missing, not required) + inactive types greyed | 6, 8 |
| Per-player exemption with reason, undo | 7, 8 |
| Players list: missing count column, "has missing", "missing type X", computed in SQL (subquery), agreeing with the checklist | 6, 9, 10 |
| Uploads: allowed types, 10 MB, random names, original name kept, images unmodified | 3, 7 |
| Storage and access: private disk `player-documents/{player_id}/`, never `/media`, `files.show` / `download` routes behind auth | 3, 7 |
| Backup and restore include the private documents (both directions, with a test) — plus the moved minutes | 13 |
| Permanent deletion removes the document files | 11 |
| Settings → Document types: add/edit names, required, validity, age limit, order; activate/deactivate; delete only while unused; rename never breaks records | 4, 5 |
| Default types seeded in a migration (desktop) | 2 |
| Player page Documents card: mark received (date, expiry for date types), upload, view, download, remove (confirm), renew, exempt, undo; "N missing" header | 7, 8 |
| Cross-cutting: stable codes + translated labels, `t()` only, i18n via script, ConfirmModal, PHPUnit `#[Test]` + RefreshDatabase, reference data in migrations, no enum changes, seed refresh | all; 14 |
