# Social Activities (P1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the club record its social activities (volunteer campaigns, awareness sessions, summer camps…) with participants, a written report, a photo gallery and attached files, so P2 can print them in the literary report.

**Architecture:** One migration creates `activity_types`, `activities`, three pivots, `activity_photos` and `activity_files`, and grants the two new rights to system roles only. A resource controller + two small nested controllers (photos, files) behind the existing route-name-derived `permission` middleware. Inertia + Vue 3 pages: Index (filters + stat tiles), Form (create/edit), Show (gallery, files, participants). Photos go to the public disk as `/media` paths (two sizes via `FileStorageService::storeImage`); files go to the private disk via `PrivateFileStorage`.

**Tech Stack:** Laravel 13, PHPUnit feature tests (`#[Test]`, `RefreshDatabase`), Inertia + Vue 3 `<script setup>`, Tailwind, vue-i18n flat JSON catalogs (ar/fr/en), intervention/image 3 (GD).

**Spec:** `docs/superpowers/specs/2026-11-01-social-activities-literary-report-design.md` (P1 section).

## Global Constraints

- Work in a git worktree on branch `feat/social-activities` (see Task 0). Stage explicit files only — never `git add -A` / `git add .`.
- Tests are PHPUnit classes in `tests/Feature` with `#[Test]` and `RefreshDatabase`. Only `UserFactory` and `RoleFactory` exist: create other models with `Model::create([...])`.
- Permission actions are `view`, `add`, `edit`, `delete` (not "create").
- New rights (`activities`, `activity_types`) start empty for every non-system role; system roles get all four actions in the migration.
- Every flash key emitted by a controller must exist in `resources/js/i18n/{ar,fr,en}.json`; check with `npm run i18n:check`. Use `t()` directly in Vue, never `te()` for flash keys.
- Offline only: no CDN, no remote assets.
- Player names always through `Player::fullname`; narrowed player queries spread `Player::NAME_COLUMNS`.
- Uploaded public images are referenced as host-relative `/media/<path>`.
- Activity types are user data in Arabic, not translated.
- Photos: jpeg/png/webp, max 8 MB each (`max:8192`), up to 20 per upload; stored 1600px and 400px wide.
- At most 4 photos per activity flagged `in_report`, enforced server-side.
- Files: pdf/doc/docx/jpg/jpeg/png/webp, max 20 MB (`max:20480`), private disk.
- `cost` is informational only: nothing in finance reads it.
- Action buttons use `IconButton` (icon-only, label on hover).

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_11_01_100001_create_activities_tables.php` | All P1 tables, seeded types, system-role rights |
| `app/Models/ActivityType.php` | Type list item |
| `app/Models/Activity.php` | Activity + relations, `participants_count` accessor, file cleanup on delete |
| `app/Models/ActivityPhoto.php` | Photo row, `url` / `thumb_url` accessors |
| `app/Models/ActivityFile.php` | Private file row |
| `app/Http/Controllers/ActivityTypeController.php` | App Configuration list CRUD |
| `app/Http/Controllers/ActivityController.php` | Activities index/create/store/show/edit/update/destroy |
| `app/Http/Requests/ActivityRequest.php` | Activity validation |
| `app/Http/Controllers/ActivityPhotoController.php` | Upload, caption/in-report toggle, delete |
| `app/Http/Controllers/ActivityFileController.php` | Upload, serve, delete |
| `app/Models/Role.php` | Add modules + `social` group |
| `config/permissions.php` | Route prefixes + overrides |
| `config/activity.php` | Activity-log route map |
| `app/Services/Activity/ActivityAction.php` | 3 new codes, new `social` area |
| `app/Services/Activity/ActivitySubjectLink.php` | Label + link for an Activity subject |
| `routes/web.php` | Routes |
| `resources/js/Pages/Settings/ActivityTypes.vue` | Type list page |
| `resources/js/Pages/Activities/Index.vue` | List page |
| `resources/js/Pages/Activities/Form.vue` | Create / edit page |
| `resources/js/Pages/Activities/Show.vue` | Detail page (gallery, files, participants) |
| `resources/js/Components/Activities/ParticipantPicker.vue` | Multi-pick on top of `SearchableSelect` |
| `resources/js/Components/Icon.vue` | `heart` glyph |
| `resources/js/Composables/useNavigation.js` | `social` section + type list item |
| `resources/js/Components/PermissionGrid.vue` | `social` section label |
| `resources/js/i18n/{ar,fr,en}.json` | All new keys |
| `tests/Feature/Activities/ActivitySchemaTest.php` | Migration, rights, permission map |
| `tests/Feature/Activities/ActivityTypesTest.php` | Type list |
| `tests/Feature/Activities/ActivityCrudTest.php` | Activities CRUD, filters, stats, activity log |
| `tests/Feature/Activities/ActivityMediaTest.php` | Photos + files |

---

### Task 0: Worktree

- [ ] **Step 1: Create the worktree and branch**

Run (PowerShell, from `D:\irnb-laravel`):

```powershell
git worktree add D:\irnb-activities -b feat/social-activities main
Copy-Item -Recurse D:\irnb-laravel\vendor D:\irnb-activities\vendor
Copy-Item -Recurse D:\irnb-laravel\node_modules D:\irnb-activities\node_modules
Copy-Item D:\irnb-laravel\.env D:\irnb-activities\.env
```

(Copies, not junctions.) Then copy the spec and this plan into the worktree (they are untracked in the main checkout):

```powershell
Copy-Item D:\irnb-laravel\docs\superpowers\specs\2026-11-01-social-activities-literary-report-design.md D:\irnb-activities\docs\superpowers\specs\
Copy-Item D:\irnb-laravel\docs\superpowers\plans\2026-11-01-social-activities-p1.md D:\irnb-activities\docs\superpowers\plans\
```

- [ ] **Step 2: Baseline tests**

Run in `D:\irnb-activities`: `php artisan test --compact`
Expected: all green (note the count; it must not drop later).

- [ ] **Step 3: Commit the docs**

```bash
git add docs/superpowers/specs/2026-11-01-social-activities-literary-report-design.md docs/superpowers/plans/2026-11-01-social-activities-p1.md
git commit -m "docs: social activities + literary report spec, P1 plan"
```

---

### Task 1: Schema, models, permissions

**Files:**
- Create: `database/migrations/2026_11_01_100001_create_activities_tables.php`
- Create: `app/Models/ActivityType.php`, `app/Models/Activity.php`, `app/Models/ActivityPhoto.php`, `app/Models/ActivityFile.php`
- Modify: `app/Models/Role.php` (`MODULE_GROUPS`, `MODULES`)
- Modify: `config/permissions.php` (`modules`, `overrides`)
- Test: `tests/Feature/Activities/ActivitySchemaTest.php`

**Interfaces:**
- Produces: models `ActivityType`, `Activity`, `ActivityPhoto`, `ActivityFile`; `Activity` relations `type()`, `branch()`, `responsible()`, `categories()`, `players()`, `boardMembers()`, `photos()`, `files()`; `Activity::participants_count` (int accessor, reads `players_count` / `board_members_count` when loaded via `withCount`, else counts relations); `Activity::PHOTO_DIR` / `FILE_DIR` path helpers `photoDirectory()` / `fileDirectory()`; `ActivityPhoto::REPORT_LIMIT = 4`; `ActivityPhoto` appended `url`, `thumb_url`.
- Permission modules: `activities`, `activity_types`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Activities/ActivitySchemaTest.php`:

```php
<?php

namespace Tests\Feature\Activities;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Role;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivitySchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function default_activity_types_are_seeded(): void
    {
        $this->assertSame(
            ['حملة تطوعية', 'جلسة توعوية', 'مخيم صيفي', 'نشاط خيري', 'زيارة', 'احتفال / مناسبة'],
            ActivityType::orderBy('sort_order')->pluck('name')->all(),
        );
    }

    #[Test]
    public function both_modules_are_permission_controlled(): void
    {
        $this->assertContains('activities', Role::MODULES);
        $this->assertContains('activity_types', Role::MODULES);
        $this->assertSame(['activities'], Role::MODULE_GROUPS['social']);
        $this->assertContains('activity_types', Role::MODULE_GROUPS['config']);
    }

    #[Test]
    public function routes_resolve_to_the_new_modules(): void
    {
        $this->assertSame(['activities', 'view'], PermissionMap::resolve('activities.index'));
        $this->assertSame(['activities', 'add'], PermissionMap::resolve('activities.store'));
        $this->assertSame(['activities', 'edit'], PermissionMap::resolve('activities.update'));
        $this->assertSame(['activities', 'delete'], PermissionMap::resolve('activities.destroy'));
        $this->assertSame(['activities', 'edit'], PermissionMap::resolve('activities.photos.store'));
        $this->assertSame(['activities', 'edit'], PermissionMap::resolve('activities.photos.destroy'));
        $this->assertSame(['activities', 'edit'], PermissionMap::resolve('activities.files.store'));
        $this->assertSame(['activities', 'edit'], PermissionMap::resolve('activities.files.destroy'));
        $this->assertSame(['activities', 'view'], PermissionMap::resolve('activities.files.show'));
        $this->assertSame(['activity_types', 'view'], PermissionMap::resolve('activity-types.index'));
    }

    #[Test]
    public function the_migration_grants_the_new_rights_to_system_roles_only(): void
    {
        $system = Role::factory()->create(['key' => 'sys', 'is_system' => true, 'permissions' => ['players' => ['view']]]);
        $custom = Role::factory()->create(['key' => 'custom', 'is_system' => false, 'permissions' => ['players' => ['view']]]);

        $migration = require database_path('migrations/2026_11_01_100001_create_activities_tables.php');
        $migration->down();
        $migration->up();

        $all = ['view', 'add', 'edit', 'delete'];
        $this->assertSame($all, $system->fresh()->permissions['activities']);
        $this->assertSame($all, $system->fresh()->permissions['activity_types']);
        $this->assertArrayNotHasKey('activities', $custom->fresh()->permissions);
        $this->assertArrayNotHasKey('activity_types', $custom->fresh()->permissions);
    }

    #[Test]
    public function participants_count_adds_players_board_members_and_outside_volunteers(): void
    {
        $activity = Activity::create([
            'title' => 'Ramadan basket', 'activity_type_id' => ActivityType::first()->id,
            'starts_on' => '2026-03-10', 'external_volunteers_count' => 5,
        ]);

        $this->assertSame(5, $activity->participants_count);
        $this->assertSame('2026-03-10', $activity->toArray()['starts_on']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Activities/ActivitySchemaTest.php`
Expected: FAIL (class `App\Models\ActivityType` not found).

- [ ] **Step 3: Write the migration**

`database/migrations/2026_11_01_100001_create_activities_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Social activities (2026-11-01): volunteer campaigns, awareness sessions,
 * summer camps… recorded after the fact with participants, a written report,
 * photos and attached files, for the year-end literary report.
 *
 * Two new rights: `activities` (sidebar section) and `activity_types` (App
 * Configuration list). As with every new right, they start empty except on
 * the system roles.
 */
return new class extends Migration
{
    private const TYPES = ['حملة تطوعية', 'جلسة توعوية', 'مخيم صيفي', 'نشاط خيري', 'زيارة', 'احتفال / مناسبة'];

    private const MODULES = ['activities', 'activity_types'];

    public function up(): void
    {
        Schema::create('activity_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('activity_types')->insert(array_map(
            fn (string $name, int $i) => ['name' => $name, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now],
            self::TYPES,
            array_keys(self::TYPES),
        ));

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('activity_type_id')->constrained('activity_types')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('location')->nullable();
            $table->text('partners')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('responsible_board_member_id')->nullable()->constrained('board_members')->nullOnDelete();
            $table->decimal('cost', 12, 2)->nullable();
            $table->unsignedInteger('beneficiaries_count')->default(0);
            $table->unsignedInteger('external_volunteers_count')->default(0);
            $table->text('description')->nullable();
            $table->longText('report')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('starts_on');
        });

        Schema::create('activity_category', function (Blueprint $table) {
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['activity_id', 'category_id']);
        });

        Schema::create('activity_player', function (Blueprint $table) {
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->primary(['activity_id', 'player_id']);
        });

        Schema::create('activity_board_member', function (Blueprint $table) {
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_member_id')->constrained()->cascadeOnDelete();
            $table->primary(['activity_id', 'board_member_id']);
        });

        Schema::create('activity_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('thumb_path');
            $table->string('caption')->nullable();
            $table->boolean('in_report')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('activity_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 150);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        $all = ['view', 'add', 'edit', 'delete'];
        DB::table('roles')->where('is_system', true)->orderBy('id')->get(['id', 'permissions'])
            ->each(function ($role) use ($all) {
                $permissions = json_decode((string) $role->permissions, true) ?: [];
                foreach (self::MODULES as $module) {
                    $permissions[$module] = $all;
                }

                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            });
    }

    public function down(): void
    {
        DB::table('roles')->orderBy('id')->get(['id', 'permissions'])->each(function ($role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            foreach (self::MODULES as $module) {
                unset($permissions[$module]);
            }

            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        });

        Schema::dropIfExists('activity_files');
        Schema::dropIfExists('activity_photos');
        Schema::dropIfExists('activity_board_member');
        Schema::dropIfExists('activity_player');
        Schema::dropIfExists('activity_category');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('activity_types');
    }
};
```

- [ ] **Step 4: Write the models**

`app/Models/ActivityType.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A kind of social activity (volunteer campaign, summer camp…). User data, not translated. */
class ActivityType extends Model
{
    use SerializesClubDates;

    protected $fillable = ['name', 'sort_order'];

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }
}
```

`app/Models/Activity.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use App\Services\Storage\FileStorageService;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A social activity, recorded after it took place. `ends_on` null means a
 * single day. `cost` is informational only: finance never reads it.
 * Participants = linked players + linked board members + outside volunteers.
 */
class Activity extends Model
{
    use SerializesClubDates;

    protected $fillable = [
        'title', 'activity_type_id', 'starts_on', 'ends_on', 'location', 'partners',
        'branch_id', 'responsible_board_member_id', 'cost', 'beneficiaries_count',
        'external_volunteers_count', 'description', 'report', 'created_by_user_id',
    ];

    protected $appends = ['participants_count'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'cost' => 'decimal:2',
            'beneficiaries_count' => 'integer',
            'external_volunteers_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Rows cascade in the database; the files on disk have to go here.
        static::deleting(function (Activity $activity) {
            $public = app(FileStorageService::class);
            foreach ($activity->photos()->get() as $photo) {
                $public->delete($photo->path);
                $public->delete($photo->thumb_path);
            }

            $private = app(PrivateFileStorage::class);
            foreach ($activity->files()->get() as $file) {
                $private->delete($file->path);
            }
        });
    }

    public function photoDirectory(): string
    {
        return 'activities/'.$this->id;
    }

    public function fileDirectory(): string
    {
        return 'activities/'.$this->id;
    }

    protected function participantsCount(): Attribute
    {
        return Attribute::make(get: fn () => (int) ($this->attributes['players_count'] ?? $this->players()->count())
            + (int) ($this->attributes['board_members_count'] ?? $this->boardMembers()->count())
            + (int) ($this->attributes['external_volunteers_count'] ?? 0));
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class, 'activity_type_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class, 'responsible_board_member_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(Player::class);
    }

    public function boardMembers(): BelongsToMany
    {
        return $this->belongsToMany(BoardMember::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ActivityPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ActivityFile::class)->orderBy('id');
    }
}
```

`app/Models/ActivityPhoto.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A gallery photo: 1600px `path` + 400px `thumb_path` on the public disk. */
class ActivityPhoto extends Model
{
    use SerializesClubDates;

    /** Photos per activity printed in the literary report. */
    public const REPORT_LIMIT = 4;

    protected $fillable = ['activity_id', 'path', 'thumb_path', 'caption', 'in_report', 'sort_order'];

    protected $appends = ['url', 'thumb_url'];

    protected $hidden = ['path', 'thumb_path'];

    protected function casts(): array
    {
        return ['in_report' => 'boolean'];
    }

    protected function url(): Attribute
    {
        return Attribute::make(get: fn () => '/media/'.ltrim((string) $this->path, '/'));
    }

    protected function thumbUrl(): Attribute
    {
        return Attribute::make(get: fn () => '/media/'.ltrim((string) $this->thumb_path, '/'));
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
```

`app/Models/ActivityFile.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An attached document on the private disk; reached only through ActivityFileController::show. */
class ActivityFile extends Model
{
    use SerializesClubDates;

    protected $fillable = ['activity_id', 'path', 'original_name', 'mime', 'size'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
```

- [ ] **Step 5: Register the modules**

`app/Models/Role.php` — in `MODULE_GROUPS` add the `social` group after `board` and the type list in `config`:

```php
        'board' => ['board'],
        'social' => ['activities'],
        'config' => [
            'categories', 'branches', 'positions', 'player_statuses', 'document_types', 'jobs',
            'board_roles', 'board_committees', 'equipment_categories', 'storage_locations',
            'game_formats', 'opponents', 'venues', 'activity_types',
        ],
```

and in `MODULES`:

```php
        'board',
        'activities',
        'categories', 'branches', 'positions', 'player_statuses', 'document_types', 'jobs',
        'board_roles', 'board_committees', 'equipment_categories', 'storage_locations',
        'game_formats', 'opponents', 'venues', 'activity_types',
        'users', 'settings',
```

`config/permissions.php` — in `modules`, after `'board-committees' => 'board_committees',`:

```php
        'activities' => 'activities',
```

and after `'venues' => 'venues',`:

```php
        'activity-types' => 'activity_types',
```

In `overrides`, after the `players.documents.files.download` entry:

```php
        // Photos and files are part of editing an activity: "store" / "destroy"
        // would otherwise be gated as add / delete (deleting a photo is not
        // deleting the activity).
        'activities.photos.store' => ['activities', 'edit'],
        'activities.photos.destroy' => ['activities', 'edit'],
        'activities.files.store' => ['activities', 'edit'],
        'activities.files.destroy' => ['activities', 'edit'],
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact tests/Feature/Activities/ActivitySchemaTest.php`
Expected: PASS (5 tests).

Run: `php artisan test --compact --filter="Permission|Role|Dashboard"`
Expected: PASS (the new modules don't break the role editor or dashboard tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_11_01_100001_create_activities_tables.php app/Models/ActivityType.php app/Models/Activity.php app/Models/ActivityPhoto.php app/Models/ActivityFile.php app/Models/Role.php config/permissions.php tests/Feature/Activities/ActivitySchemaTest.php
git commit -m "feat(activities): schema, models and permission modules"
```

---

### Task 2: Activity types list (App Configuration)

**Files:**
- Create: `app/Http/Controllers/ActivityTypeController.php`
- Create: `resources/js/Pages/Settings/ActivityTypes.vue`
- Modify: `routes/web.php` (lookup tables block, after `venues`)
- Modify: `config/activity.php` (`$settingLists`)
- Modify: `resources/js/Composables/useNavigation.js` (config section)
- Modify: `resources/js/i18n/{ar,fr,en}.json`
- Test: `tests/Feature/Activities/ActivityTypesTest.php`

**Interfaces:**
- Consumes: `ActivityType`, `Activity` (Task 1).
- Produces: routes `activity-types.index|store|update|destroy`; page prop `types: [{id, name, sort_order, activities_count}]`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Activities/ActivityTypesTest.php`:

```php
<?php

namespace Tests\Feature\Activities;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityTypesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    #[Test]
    public function the_list_shows_types_in_order_with_usage(): void
    {
        $this->actingAs($this->admin())->get(route('activity-types.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/ActivityTypes')
                ->has('types', 6)
                ->where('types.0.name', 'حملة تطوعية')
                ->where('types.0.activities_count', 0));
    }

    #[Test]
    public function a_type_can_be_added_renamed_and_deleted(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('activity-types.store'), ['name' => 'يوم مفتوح'])
            ->assertSessionHas('success', 'flash.activity_type_created');
        $type = ActivityType::where('name', 'يوم مفتوح')->firstOrFail();
        $this->assertSame(7, $type->sort_order);

        $this->put(route('activity-types.update', $type), ['name' => 'أبواب مفتوحة', 'sort_order' => 2])
            ->assertSessionHas('success', 'flash.activity_type_updated');
        $this->assertSame('أبواب مفتوحة', $type->fresh()->name);

        $this->delete(route('activity-types.destroy', $type))
            ->assertSessionHas('success', 'flash.activity_type_deleted');
        $this->assertModelMissing($type);
    }

    #[Test]
    public function names_are_unique(): void
    {
        $this->actingAs($this->admin())
            ->post(route('activity-types.store'), ['name' => 'مخيم صيفي'])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function a_type_in_use_cannot_be_deleted(): void
    {
        $type = ActivityType::first();
        Activity::create(['title' => 'Camp', 'activity_type_id' => $type->id, 'starts_on' => '2026-07-01']);

        $this->actingAs($this->admin())->delete(route('activity-types.destroy', $type))
            ->assertSessionHas('error', 'flash.activity_type_in_use');
        $this->assertModelExists($type);
    }

    #[Test]
    public function the_list_needs_its_own_right(): void
    {
        $board = User::factory()->create([
            'role_id' => Role::factory()->create(['permissions' => ['activities' => ['view', 'add', 'edit', 'delete']]])->id,
        ]);
        $this->actingAs($board)->get(route('activity-types.index'))->assertForbidden();

        $config = User::factory()->create([
            'role_id' => Role::factory()->create(['permissions' => ['activity_types' => ['view']]])->id,
        ]);
        $this->actingAs($config)->get(route('activity-types.index'))->assertOk();
        $this->actingAs($config)->post(route('activity-types.store'), ['name' => 'X'])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Activities/ActivityTypesTest.php`
Expected: FAIL (`Route [activity-types.index] not defined`).

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/ActivityTypeController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\ActivityType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ActivityTypeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/ActivityTypes', [
            'types' => ActivityType::query()->withCount('activities')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['sort_order'] ??= (int) ActivityType::max('sort_order') + 1;
        ActivityType::create($data);

        return back()->with('success', 'flash.activity_type_created');
    }

    public function update(Request $request, ActivityType $activityType): RedirectResponse
    {
        $activityType->update($this->validated($request, $activityType));

        return back()->with('success', 'flash.activity_type_updated');
    }

    /** Activities keep their history: a type still in use is refused, never cascaded. */
    public function destroy(ActivityType $activityType): RedirectResponse
    {
        if ($activityType->activities()->exists()) {
            return back()->with('error', 'flash.activity_type_in_use');
        }

        $activityType->delete();

        return back()->with('success', 'flash.activity_type_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ActivityType $type = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('activity_types', 'name')->ignore($type)],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
        ]);
    }
}
```

Note: `validate()` drops absent keys, so `$data['sort_order'] ??=` only fills it when the form didn't send one; on update an absent `sort_order` leaves the stored value.

- [ ] **Step 4: Route, activity log, navigation**

`routes/web.php` — add the import with the other controller imports (alphabetical):

```php
use App\Http\Controllers\ActivityTypeController;
```

and after the `venues` resource line (`Route::resource('venues', VenueController::class)->except(['show', 'create', 'edit']);`):

```php
        Route::resource('activity-types', ActivityTypeController::class)->except(['show', 'create', 'edit']);
```

`config/activity.php` — in `$settingLists`, after `'venues' => 'venues',`:

```php
    'activity-types' => 'activity_types',
```

`resources/js/Composables/useNavigation.js` — in the `config` section, after the `venues` item:

```js
                { label: t('activity_types'), href: '/activity-types', icon: 'heart', prefix: '/activity-types', module: 'activity_types' },
```

(`heart` is added to `Icon.vue` in Task 5; until then the icon renders empty, which is harmless.)

- [ ] **Step 5: Write the page**

`resources/js/Pages/Settings/ActivityTypes.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import { useCan } from '@/Composables/useCan';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t } = useI18n();
const { can } = useCan();

defineProps({ types: Array });

const editingId = ref(null);
const deleteId = ref(null);
const form = useForm({ name: '' });
const editForm = useForm({ name: '', sort_order: 0 });

function add() {
    form.post(route('activity-types.store'), { onSuccess: () => form.reset() });
}

function startEdit(type) {
    editingId.value = type.id;
    editForm.name = type.name;
    editForm.sort_order = type.sort_order;
}

function saveEdit(id) {
    editForm.put(route('activity-types.update', id), { onSuccess: () => { editingId.value = null; } });
}

function destroy() {
    router.delete(route('activity-types.destroy', deleteId.value), { onFinish: () => { deleteId.value = null; } });
}
</script>

<template>
    <Head :title="t('activity_types')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('activity_types') }}</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <form v-if="can('activity_types', 'add')" @submit.prevent="add" class="flex flex-wrap items-end gap-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="min-w-48 flex-1">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }}</label>
                    <TextInput v-model="form.name" class="mt-1 w-full" required />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <PrimaryButton :disabled="form.processing">{{ t('add') }}</PrimaryButton>
            </form>

            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="w-20 px-4 py-3 text-center text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">#</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('activities') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="type in types" :key="type.id">
                            <td class="px-4 py-3 text-center text-sm text-slate-500">
                                <TextInput v-if="editingId === type.id" v-model="editForm.sort_order" type="number" min="0" class="w-16" />
                                <span v-else>{{ type.sort_order }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div v-if="editingId === type.id" class="space-y-1">
                                    <TextInput v-model="editForm.name" class="w-full" />
                                    <InputError :message="editForm.errors.name" />
                                </div>
                                <span v-else class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ type.name }}</span>
                            </td>
                            <td class="px-4 py-3 text-center text-sm text-slate-600 dark:text-slate-300">{{ type.activities_count }}</td>
                            <td class="px-4 py-3 text-end">
                                <div v-if="editingId === type.id" class="flex justify-end gap-2">
                                    <button @click="saveEdit(type.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('save') }}</button>
                                    <button @click="editingId = null" class="text-sm text-slate-500 dark:text-slate-400">{{ t('cancel') }}</button>
                                </div>
                                <div v-else class="flex justify-end gap-2">
                                    <IconButton v-if="can('activity_types', 'edit')" icon="pencil" :label="t('edit')" plain size="sm" @click="startEdit(type)" />
                                    <IconButton v-if="can('activity_types', 'delete')" icon="trash" :label="t('delete')" variant="danger" plain size="sm" :disabled="type.activities_count > 0" @click="deleteId = type.id" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!types?.length">
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
```

Before writing it, open `resources/js/Composables/useCan.js` (or wherever `useCan` lives: `grep -rn "export function useCan" resources/js`) and match its import path and return shape; adjust the two `useCan` lines if they differ.

- [ ] **Step 6: i18n keys**

Add to each catalog, keeping the file's alphabetical key order (insert each key where it sorts):

| Key | ar | fr | en |
|---|---|---|---|
| `activities` | الأنشطة | Activités | Activities |
| `activity_types` | أنواع الأنشطة | Types d'activités | Activity types |
| `activity.kind.activity_types` | أنواع الأنشطة | Types d'activités | Activity types |
| `flash.activity_type_created` | تمت إضافة النوع | Type ajouté | Type added |
| `flash.activity_type_updated` | تم تعديل النوع | Type modifié | Type updated |
| `flash.activity_type_deleted` | تم حذف النوع | Type supprimé | Type deleted |
| `flash.activity_type_in_use` | لا يمكن حذف نوع مستعمل في أنشطة | Ce type est utilisé par des activités | This type is used by activities |

- [ ] **Step 7: Run the tests**

Run: `php artisan test --compact tests/Feature/Activities/ActivityTypesTest.php`
Expected: PASS (5 tests).

Run: `php artisan test --compact --filter="Flash|Activity"`
Expected: PASS (flash catalog test sees the new keys; activity coverage still green).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/ActivityTypeController.php resources/js/Pages/Settings/ActivityTypes.vue routes/web.php config/activity.php resources/js/Composables/useNavigation.js resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Activities/ActivityTypesTest.php
git commit -m "feat(activities): activity types configuration list"
```

---

### Task 3: Activities CRUD backend + activity log

**Files:**
- Create: `app/Http/Requests/ActivityRequest.php`
- Create: `app/Http/Controllers/ActivityController.php`
- Modify: `routes/web.php`
- Modify: `app/Services/Activity/ActivityAction.php`
- Modify: `app/Services/Activity/ActivitySubjectLink.php`
- Modify: `config/activity.php`
- Modify: `tests/Unit/ActivityRecorderTest.php` (count 110 → 113)
- Modify: `resources/js/i18n/{ar,fr,en}.json` (flash + activity-log keys)
- Test: `tests/Feature/Activities/ActivityCrudTest.php`

**Interfaces:**
- Consumes: Task 1 models and modules.
- Produces:
  - Routes: `activities.index|create|store|show|edit|update|destroy` (`Route::resource`).
  - `index` props: `activities` (paginator of `{id, title, starts_on, ends_on, location, beneficiaries_count, external_volunteers_count, players_count, board_members_count, participants_count, type: {id,name}, branch: {id,localized_name}|null, cover: {thumb_url}|null}`), `stats: {count, beneficiaries, participants}`, `filters: {types: string[], branch: string|null, from, to, search}`, `types`, `branches`.
  - `create` / `edit` props (`Activities/Form`): `activity` (null on create; on edit includes `category_ids`, `player_ids`, `board_member_ids`), `types`, `branches`, `categories`, `players: [{value,label,description}]`, `boardMembers: [{value,label}]`.
  - `show` props (`Activities/Show`): `activity` with `type`, `branch`, `responsible:{id,name}`, `categories`, `players:[{id,name}]`, `board_members:[{id,name}]`, `photos`, `files`, `participants_count`; `reportLimit` (int 4).
  - Action codes `ActivityAction::ACTIVITY_CREATED|ACTIVITY_UPDATED|ACTIVITY_DELETED` in area `social`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Activities/ActivityCrudTest.php`:

```php
<?php

namespace Tests\Feature\Activities;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\ActivityType;
use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(string $first = 'Sami'): Player
    {
        static $n = 0;
        $n++;

        return Player::create(['membership_id' => "A{$n}", 'firstname' => $first, 'lastname' => 'Zidane']);
    }

    private function member(): BoardMember
    {
        $term = BoardTerm::firstOrCreate(['name' => '2024–2028'], ['is_current' => true]);

        return BoardMember::create(['name' => 'Karim', 'role' => 'member', 'board_term_id' => $term->id, 'status' => 'active']);
    }

    private function activity(array $attributes = []): Activity
    {
        return Activity::create($attributes + [
            'title' => 'Blood drive', 'activity_type_id' => ActivityType::first()->id, 'starts_on' => '2026-02-14',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Summer camp 2026',
            'activity_type_id' => ActivityType::where('name', 'مخيم صيفي')->value('id'),
            'starts_on' => '2026-07-05',
            'ends_on' => '2026-07-15',
            'location' => 'Tikjda',
            'partners' => 'Youth directorate',
            'beneficiaries_count' => 40,
            'external_volunteers_count' => 3,
            'cost' => '120000.50',
            'description' => 'Ten days in the mountains.',
            'report' => 'All went well.',
        ];
    }

    #[Test]
    public function an_activity_is_created_with_its_participants_and_categories(): void
    {
        $category = Category::create(['name' => 'U15', 'code' => 'U15']);
        $branch = Branch::create(['name' => 'Football']);
        $player = $this->player();
        $member = $this->member();

        $response = $this->actingAs($this->admin())->post(route('activities.store'), $this->payload([
            'branch_id' => $branch->id,
            'responsible_board_member_id' => $member->id,
            'category_ids' => [$category->id],
            'player_ids' => [$player->id],
            'board_member_ids' => [$member->id],
        ]))->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.activity_created');

        $activity = Activity::sole();
        $response->assertRedirect(route('activities.show', $activity));
        $this->assertNotNull($activity->created_by_user_id);
        $this->assertSame('2026-07-15', $activity->ends_on->format('Y-m-d'));
        $this->assertSame([$category->id], $activity->categories->pluck('id')->all());
        $this->assertSame([$player->id], $activity->players->pluck('id')->all());
        $this->assertSame([$member->id], $activity->boardMembers->pluck('id')->all());
        $this->assertSame(1 + 1 + 3, $activity->participants_count);
        $this->assertSame(1, ActivityLog::where('action', ActivityAction::ACTIVITY_CREATED)->count());
    }

    #[Test]
    public function validation_rules(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('activities.store'), $this->payload(['title' => '']))->assertSessionHasErrors('title');
        $this->post(route('activities.store'), $this->payload(['activity_type_id' => null]))->assertSessionHasErrors('activity_type_id');
        $this->post(route('activities.store'), $this->payload(['ends_on' => '2026-07-01']))->assertSessionHasErrors('ends_on');
        $this->post(route('activities.store'), $this->payload(['beneficiaries_count' => -1]))->assertSessionHasErrors('beneficiaries_count');
        $this->assertSame(0, Activity::count());
    }

    #[Test]
    public function an_activity_is_updated_and_its_lists_resynced(): void
    {
        $old = $this->player('Old');
        $new = $this->player('New');
        $activity = $this->activity();
        $activity->players()->attach($old);

        $this->actingAs($this->admin())->put(route('activities.update', $activity), $this->payload([
            'title' => 'Renamed', 'player_ids' => [$new->id],
        ]))->assertSessionHas('success', 'flash.activity_updated');

        $activity->refresh();
        $this->assertSame('Renamed', $activity->title);
        $this->assertSame([$new->id], $activity->players->pluck('id')->all());
        $this->assertSame(1, ActivityLog::where('action', ActivityAction::ACTIVITY_UPDATED)->count());
    }

    #[Test]
    public function an_activity_is_deleted(): void
    {
        $activity = $this->activity();

        $this->actingAs($this->admin())->delete(route('activities.destroy', $activity))
            ->assertRedirect(route('activities.index'))
            ->assertSessionHas('success', 'flash.activity_deleted');

        $this->assertModelMissing($activity);
        $this->assertSame(1, ActivityLog::where('action', ActivityAction::ACTIVITY_DELETED)->count());
    }

    #[Test]
    public function the_index_filters_and_totals_the_filtered_set(): void
    {
        $camp = ActivityType::where('name', 'مخيم صيفي')->value('id');
        $a = $this->activity(['title' => 'Camp Tikjda', 'activity_type_id' => $camp, 'starts_on' => '2026-07-05', 'beneficiaries_count' => 40, 'external_volunteers_count' => 2]);
        $a->players()->attach($this->player());
        $this->activity(['title' => 'Blood drive', 'starts_on' => '2026-02-14', 'beneficiaries_count' => 100]);
        $this->activity(['title' => 'Old camp', 'activity_type_id' => $camp, 'starts_on' => '2025-07-05', 'beneficiaries_count' => 30]);

        $this->actingAs($this->admin())
            ->get(route('activities.index', ['types' => [$camp], 'from' => '2026-01-01', 'to' => '2026-12-31']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Activities/Index')
                ->has('activities.data', 1)
                ->where('activities.data.0.title', 'Camp Tikjda')
                ->where('activities.data.0.participants_count', 3)
                ->where('stats.count', 1)
                ->where('stats.beneficiaries', 40)
                ->where('stats.participants', 3));

        $this->get(route('activities.index', ['search' => 'blood']))
            ->assertInertia(fn (Assert $page) => $page->has('activities.data', 1)->where('stats.beneficiaries', 100));
    }

    #[Test]
    public function the_show_page_carries_participants_by_full_name(): void
    {
        $activity = $this->activity();
        $activity->players()->attach($this->player('Amine'));

        $this->actingAs($this->admin())->get(route('activities.show', $activity))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Activities/Show')
                ->where('activity.title', 'Blood drive')
                ->where('activity.players.0.name', Player::first()->fullname)
                ->where('reportLimit', 4));
    }

    #[Test]
    public function the_create_and_edit_forms_render(): void
    {
        $this->player();
        $activity = $this->activity();

        $this->actingAs($this->admin())->get(route('activities.create'))
            ->assertInertia(fn (Assert $page) => $page->component('Activities/Form')->where('activity', null)->has('types', 6)->has('players', 1));

        $this->get(route('activities.edit', $activity))
            ->assertInertia(fn (Assert $page) => $page->component('Activities/Form')->where('activity.id', $activity->id)->where('activity.player_ids', []));
    }

    #[Test]
    public function each_action_needs_its_right(): void
    {
        $activity = $this->activity();
        $viewer = User::factory()->create([
            'role_id' => Role::factory()->create(['permissions' => ['activities' => ['view']]])->id,
        ]);

        $this->actingAs($viewer)->get(route('activities.index'))->assertOk();
        $this->actingAs($viewer)->get(route('activities.show', $activity))->assertOk();
        $this->actingAs($viewer)->get(route('activities.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('activities.store'), $this->payload())->assertForbidden();
        $this->actingAs($viewer)->put(route('activities.update', $activity), $this->payload())->assertForbidden();
        $this->actingAs($viewer)->delete(route('activities.destroy', $activity))->assertForbidden();

        $nobody = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => []])->id]);
        $this->actingAs($nobody)->get(route('activities.index'))->assertForbidden();
    }
}
```

Before running: check `Category::create` and `Branch::create` required columns in their migrations (`grep -n "table->" database/migrations/*create_categories* database/migrations/*branches*`) and add any NOT NULL column without default to those two `create()` calls. Check `BoardMember` required columns the same way.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Activities/ActivityCrudTest.php`
Expected: FAIL (`Route [activities.store] not defined`).

- [ ] **Step 3: Write the form request**

`app/Http/Requests/ActivityRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `permission` middleware already decided
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'activity_type_id' => ['required', 'integer', 'exists:activity_types,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'location' => ['nullable', 'string', 'max:255'],
            'partners' => ['nullable', 'string', 'max:2000'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'responsible_board_member_id' => ['nullable', 'integer', 'exists:board_members,id'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'beneficiaries_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'external_volunteers_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'description' => ['nullable', 'string', 'max:10000'],
            'report' => ['nullable', 'string', 'max:100000'],
            'category_ids' => ['nullable', 'array', 'max:200'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'player_ids' => ['nullable', 'array', 'max:2000'],
            'player_ids.*' => ['integer', 'exists:players,id'],
            'board_member_ids' => ['nullable', 'array', 'max:200'],
            'board_member_ids.*' => ['integer', 'exists:board_members,id'],
        ];
    }

    /** The activity's own columns, counts defaulted to 0. @return array<string, mixed> */
    public function activityData(): array
    {
        $data = collect($this->validated())->except(['category_ids', 'player_ids', 'board_member_ids'])->all();
        $data['beneficiaries_count'] = (int) ($data['beneficiaries_count'] ?? 0);
        $data['external_volunteers_count'] = (int) ($data['external_volunteers_count'] ?? 0);

        return $data;
    }

    /** @return array{categories: int[], players: int[], boardMembers: int[]} */
    public function links(): array
    {
        return [
            'categories' => array_map('intval', $this->validated('category_ids') ?? []),
            'players' => array_map('intval', $this->validated('player_ids') ?? []),
            'boardMembers' => array_map('intval', $this->validated('board_member_ids') ?? []),
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

`app/Http/Controllers/ActivityController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActivityRequest;
use App\Models\Activity;
use App\Models\ActivityPhoto;
use App\Models\ActivityType;
use App\Models\BoardMember;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'types' => array_values(array_filter(array_map('strval', (array) $request->query('types', [])), 'ctype_digit')),
            'branch' => ctype_digit((string) $request->query('branch')) ? (string) $request->query('branch') : null,
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
            'search' => trim((string) $request->query('search', '')),
        ];

        $query = Activity::query()
            ->when($filters['types'], fn (Builder $q, $types) => $q->whereIn('activity_type_id', $types))
            ->when($filters['branch'], fn (Builder $q, $branch) => $q->where('branch_id', $branch))
            // An activity counts in a period when any of its days falls inside it.
            ->when($filters['from'], fn (Builder $q, $from) => $q->whereRaw('coalesce(ends_on, starts_on) >= ?', [$from]))
            ->when($filters['to'], fn (Builder $q, $to) => $q->where('starts_on', '<=', $to))
            ->when($filters['search'] !== '', function (Builder $q) use ($filters) {
                $like = '%'.$filters['search'].'%';
                $q->where(fn (Builder $w) => $w->where('title', 'like', $like)
                    ->orWhere('location', 'like', $like)
                    ->orWhere('partners', 'like', $like));
            });

        $ids = (clone $query)->pluck('id');

        $activities = $query
            ->with(['type:id,name', 'branch', 'photos' => fn ($q) => $q->select(['id', 'activity_id', 'thumb_path', 'path', 'sort_order'])])
            ->withCount(['players', 'boardMembers'])
            ->orderByDesc('starts_on')->orderByDesc('id')
            ->paginate(20)->withQueryString()
            ->through(function (Activity $activity) {
                $cover = $activity->photos->first();
                $activity->setRelation('photos', collect());
                $activity->setAttribute('cover', $cover ? ['thumb_url' => $cover->thumb_url] : null);

                return $activity;
            });

        return Inertia::render('Activities/Index', [
            'activities' => $activities,
            'stats' => [
                'count' => $ids->count(),
                'beneficiaries' => (int) Activity::whereIn('id', $ids)->sum('beneficiaries_count'),
                'participants' => (int) Activity::whereIn('id', $ids)->sum('external_volunteers_count')
                    + DB::table('activity_player')->whereIn('activity_id', $ids)->count()
                    + DB::table('activity_board_member')->whereIn('activity_id', $ids)->count(),
            ],
            'filters' => $filters,
            'types' => ActivityType::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Activities/Form', ['activity' => null] + $this->formOptions());
    }

    public function store(ActivityRequest $request): RedirectResponse
    {
        $activity = DB::transaction(function () use ($request) {
            $activity = Activity::create($request->activityData() + ['created_by_user_id' => $request->user()->id]);
            $this->syncLinks($activity, $request->links());

            return $activity;
        });

        ActivityRecorder::record($request->user(), ActivityAction::ACTIVITY_CREATED, $activity);

        return redirect()->route('activities.show', $activity)->with('success', 'flash.activity_created');
    }

    public function show(Activity $activity): Response
    {
        $activity->load([
            'type:id,name',
            'branch',
            'responsible' => fn ($q) => $q->withPlayer(),
            'categories',
            'players' => fn ($q) => $q->select(['players.id', ...Player::NAME_COLUMNS, 'membership_id']),
            'boardMembers' => fn ($q) => $q->withPlayer(),
            'photos',
            'files',
        ]);

        $data = $activity->toArray();
        $data['responsible'] = $activity->responsible ? ['id' => $activity->responsible->id, 'name' => $activity->responsible->name] : null;
        $data['players'] = $activity->players->map(fn (Player $p) => ['id' => $p->id, 'name' => $p->fullname, 'membership_id' => $p->membership_id])->values();
        $data['board_members'] = $activity->boardMembers->map(fn (BoardMember $m) => ['id' => $m->id, 'name' => $m->name])->values();

        return Inertia::render('Activities/Show', [
            'activity' => $data,
            'reportLimit' => ActivityPhoto::REPORT_LIMIT,
        ]);
    }

    public function edit(Activity $activity): Response
    {
        $data = $activity->toArray();
        $data['category_ids'] = $activity->categories()->pluck('categories.id')->all();
        $data['player_ids'] = $activity->players()->pluck('players.id')->all();
        $data['board_member_ids'] = $activity->boardMembers()->pluck('board_members.id')->all();

        return Inertia::render('Activities/Form', ['activity' => $data] + $this->formOptions($activity));
    }

    public function update(ActivityRequest $request, Activity $activity): RedirectResponse
    {
        DB::transaction(function () use ($request, $activity) {
            $activity->update($request->activityData());
            $this->syncLinks($activity, $request->links());
        });

        return redirect()->route('activities.show', $activity)->with('success', 'flash.activity_updated');
    }

    public function destroy(Activity $activity): RedirectResponse
    {
        $activity->delete();

        return redirect()->route('activities.index')->with('success', 'flash.activity_deleted');
    }

    /** @param  array{categories: int[], players: int[], boardMembers: int[]}  $links */
    private function syncLinks(Activity $activity, array $links): void
    {
        $activity->categories()->sync($links['categories']);
        $activity->players()->sync($links['players']);
        $activity->boardMembers()->sync($links['boardMembers']);
    }

    /**
     * Pickers for the form. Players already linked stay pickable on edit even
     * once archived, so saving the form never drops them silently.
     *
     * @return array<string, mixed>
     */
    private function formOptions(?Activity $activity = null): array
    {
        $linked = $activity?->players()->pluck('players.id')->all() ?? [];

        return [
            'types' => ActivityType::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::orderBy('name')->get(),
            'categories' => Category::orderBy('name')->get(),
            'players' => Player::query()
                ->where(fn (Builder $q) => $q->where('archived', false)->orWhereIn('id', $linked))
                ->orderBy('lastname')->orderBy('firstname')
                ->get(['id', ...Player::NAME_COLUMNS, 'membership_id'])
                ->map(fn (Player $p) => ['value' => $p->id, 'label' => $p->fullname, 'description' => $p->membership_id])
                ->values(),
            'boardMembers' => BoardMember::withPlayer()->where('status', 'active')->orderBy('sort_order')->get()
                ->map(fn (BoardMember $m) => ['value' => $m->id, 'label' => $m->name])
                ->values(),
        ];
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
```

Note for the implementer: `Player` has an `archived` column (used the same way in `BoardController::members`).

- [ ] **Step 5: Routes**

`routes/web.php` — import:

```php
use App\Http\Controllers\ActivityController;
```

Inside the `['auth', 'approved', 'permission']` group, after the board meetings block (after the `board.meetings.destroy` line):

```php
        // Social activities (P1 of the literary report work).
        Route::resource('activities', ActivityController::class);
```

- [ ] **Step 6: Activity log codes**

`app/Services/Activity/ActivityAction.php`:

1. After the board constants block (after `const MEETING_DELETED = 'meeting_deleted';`), add:

```php
    // social activities
    const ACTIVITY_CREATED = 'activity_created';

    const ACTIVITY_UPDATED = 'activity_updated';

    const ACTIVITY_DELETED = 'activity_deleted';
```

2. Append `self::ACTIVITY_CREATED, self::ACTIVITY_UPDATED, self::ACTIVITY_DELETED,` at the end of the `ALL` array.
3. In `AREAS`, after the `'board' => [...]` entry, add:

```php
        'social' => [
            self::ACTIVITY_CREATED,
            self::ACTIVITY_UPDATED,
            self::ACTIVITY_DELETED,
        ],
```

4. `ACTIVITY_DELETED` cannot be undone, so it does not go in `QUALITY` (read the docblock above `QUALITY` to confirm it lists only codes that can later be cancelled/archived).

`tests/Unit/ActivityRecorderTest.php`: change `$this->assertCount(110, ActivityAction::ALL);` to `$this->assertCount(113, ActivityAction::ALL);`.

`config/activity.php` — in `$routes`, after the `// board` group:

```php
    // social activities (creation is recorded in ActivityController::store)
    'activities.update' => ActivityAction::ACTIVITY_UPDATED,
    'activities.destroy' => ActivityAction::ACTIVITY_DELETED,
    'activities.photos.store' => ActivityAction::ACTIVITY_UPDATED,
    'activities.photos.update' => ActivityAction::ACTIVITY_UPDATED,
    'activities.photos.destroy' => ActivityAction::ACTIVITY_UPDATED,
    'activities.files.store' => ActivityAction::ACTIVITY_UPDATED,
    'activities.files.destroy' => ActivityAction::ACTIVITY_UPDATED,
```

`app/Services/Activity/ActivitySubjectLink.php`:
- import `use App\Models\Activity;`
- in `MODULES` add `Activity::class => ['activities'],`
- in `describe()` add before the `default` arm:

```php
            $subject instanceof Activity => [(string) $subject->title, route('activities.show', $subject)],
```

Check how the activity pages label areas and whether any JS map lists areas (`grep -rn "activity.area" resources/js`); if a JS map of area → colour/icon exists, add `social` to it.

- [ ] **Step 7: i18n keys**

| Key | ar | fr | en |
|---|---|---|---|
| `flash.activity_created` | تم تسجيل النشاط | Activité enregistrée | Activity recorded |
| `flash.activity_updated` | تم تعديل النشاط | Activité modifiée | Activity updated |
| `flash.activity_deleted` | تم حذف النشاط | Activité supprimée | Activity deleted |
| `activity.area.social` | النشاط الاجتماعي | Activités sociales | Social activities |
| `activity.action.activity_created` | أنشطة مسجلة | Activités enregistrées | Activities recorded |
| `activity.action.activity_updated` | أنشطة معدلة | Activités modifiées | Activities edited |
| `activity.action.activity_deleted` | أنشطة محذوفة | Activités supprimées | Activities deleted |

- [ ] **Step 8: Run the tests**

Run: `php artisan test --compact tests/Feature/Activities/ActivityCrudTest.php`
Expected: PASS (8 tests).

Run: `php artisan test --compact tests/Unit/ActivityRecorderTest.php tests/Unit/ActivityCoverageTest.php tests/Feature/UsersBatchTest.php tests/Feature/UserActivityPagesTest.php`
Expected: PASS. `ActivityCoverageTest` will fail if any of the three codes is not referenced — `ACTIVITY_CREATED` is in the controller, the other two in `config/activity.php`.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/ActivityRequest.php app/Http/Controllers/ActivityController.php routes/web.php app/Services/Activity/ActivityAction.php app/Services/Activity/ActivitySubjectLink.php config/activity.php tests/Unit/ActivityRecorderTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Activities/ActivityCrudTest.php
git commit -m "feat(activities): activities CRUD with participants and activity log"
```

(The `Activities/*` Vue pages don't exist yet; Inertia tests only assert the component name, so they pass. Task 5 adds the pages.)

---

### Task 4: Photos and files

**Files:**
- Create: `app/Http/Controllers/ActivityPhotoController.php`
- Create: `app/Http/Controllers/ActivityFileController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/i18n/{ar,fr,en}.json`
- Test: `tests/Feature/Activities/ActivityMediaTest.php`

**Interfaces:**
- Consumes: `Activity::photoDirectory()`, `Activity::fileDirectory()`, `ActivityPhoto::REPORT_LIMIT`, `FileStorageService::storeImage(UploadedFile, string $dir, int $maxWidth): array{path, filename, url}`, `PrivateFileStorage::store(UploadedFile, string $dir): array{path, original_name, mime, size}`, `PrivateFileStorage::serve(string $path, string $name)`, `PrivateFileStorage::delete(?string)`.
- Produces routes:
  - `POST /activities/{activity}/photos` → `activities.photos.store` (input `photos[]`)
  - `PUT /activities/{activity}/photos/{photo}` → `activities.photos.update` (input `caption`, `in_report`)
  - `DELETE /activities/{activity}/photos/{photo}` → `activities.photos.destroy`
  - `POST /activities/{activity}/files` → `activities.files.store` (input `file`)
  - `GET /activities/{activity}/files/{file}` → `activities.files.show`
  - `DELETE /activities/{activity}/files/{file}` → `activities.files.destroy`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Activities/ActivityMediaTest.php`:

```php
<?php

namespace Tests\Feature\Activities;

use App\Models\Activity;
use App\Models\ActivityPhoto;
use App\Models\ActivityType;
use App\Models\Role;
use App\Models\User;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityMediaTest extends TestCase
{
    use RefreshDatabase;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake(PrivateFileStorage::DISK);
        $this->activity = Activity::create(['title' => 'Camp', 'activity_type_id' => ActivityType::first()->id, 'starts_on' => '2026-07-05']);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function uploadPhotos(int $count = 1)
    {
        $photos = array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 2400, 1600), range(1, $count));

        return $this->post(route('activities.photos.store', $this->activity), ['photos' => $photos]);
    }

    #[Test]
    public function photos_are_stored_in_two_sizes(): void
    {
        $this->actingAs($this->admin());
        $this->uploadPhotos(2)->assertSessionHas('success', 'flash.activity_photos_added');

        $photos = $this->activity->photos()->get();
        $this->assertCount(2, $photos);
        foreach ($photos as $photo) {
            Storage::disk('public')->assertExists($photo->path);
            Storage::disk('public')->assertExists($photo->thumb_path);
            $this->assertStringStartsWith('/media/activities/'.$this->activity->id.'/', $photo->url);
            [$width] = getimagesizefromstring(Storage::disk('public')->get($photo->thumb_path));
            $this->assertSame(400, $width);
        }
        $this->assertSame([1, 2], $photos->pluck('sort_order')->all());
    }

    #[Test]
    public function only_images_are_accepted(): void
    {
        $this->actingAs($this->admin())
            ->post(route('activities.photos.store', $this->activity), ['photos' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]])
            ->assertSessionHasErrors('photos.0');
    }

    #[Test]
    public function at_most_four_photos_go_in_the_report(): void
    {
        $this->actingAs($this->admin());
        $this->uploadPhotos(5);
        $photos = $this->activity->photos()->get();

        foreach ($photos->take(4) as $photo) {
            $this->put(route('activities.photos.update', [$this->activity, $photo]), ['in_report' => true, 'caption' => 'Day '.$photo->id])
                ->assertSessionHas('success', 'flash.activity_photo_updated');
        }
        $this->put(route('activities.photos.update', [$this->activity, $photos[4]]), ['in_report' => true])
            ->assertSessionHas('error', 'flash.activity_report_photos_limit');

        $this->assertSame(4, $this->activity->photos()->where('in_report', true)->count());
        // Re-saving a photo already in the report is not blocked by the cap.
        $this->put(route('activities.photos.update', [$this->activity, $photos[0]]), ['in_report' => true, 'caption' => 'Opening'])
            ->assertSessionHas('success', 'flash.activity_photo_updated');
        $this->assertSame('Opening', $photos[0]->fresh()->caption);
    }

    #[Test]
    public function deleting_a_photo_removes_its_files(): void
    {
        $this->actingAs($this->admin());
        $this->uploadPhotos();
        $photo = $this->activity->photos()->first();

        $this->delete(route('activities.photos.destroy', [$this->activity, $photo]))
            ->assertSessionHas('success', 'flash.activity_photo_deleted');

        $this->assertModelMissing($photo);
        Storage::disk('public')->assertMissing($photo->path);
        Storage::disk('public')->assertMissing($photo->thumb_path);
    }

    #[Test]
    public function a_photo_of_another_activity_is_not_reachable(): void
    {
        $this->actingAs($this->admin());
        $this->uploadPhotos();
        $photo = $this->activity->photos()->first();
        $other = Activity::create(['title' => 'Other', 'activity_type_id' => ActivityType::first()->id, 'starts_on' => '2026-01-01']);

        $this->delete(route('activities.photos.destroy', [$other, $photo]))->assertNotFound();
    }

    #[Test]
    public function files_are_private_and_served_to_viewers_only(): void
    {
        $this->actingAs($this->admin())
            ->post(route('activities.files.store', $this->activity), ['file' => UploadedFile::fake()->create('report.pdf', 200, 'application/pdf')])
            ->assertSessionHas('success', 'flash.activity_file_uploaded');

        $file = $this->activity->files()->sole();
        $this->assertSame('report.pdf', $file->original_name);
        Storage::disk(PrivateFileStorage::DISK)->assertExists($file->path);
        Storage::disk('public')->assertMissing($file->path);

        $this->get(route('activities.files.show', [$this->activity, $file]))->assertOk();

        $nobody = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => []])->id]);
        $this->actingAs($nobody)->get(route('activities.files.show', [$this->activity, $file]))->assertForbidden();
    }

    #[Test]
    public function deleting_a_file_removes_it_from_disk(): void
    {
        $this->actingAs($this->admin())
            ->post(route('activities.files.store', $this->activity), ['file' => UploadedFile::fake()->create('a.docx', 50)]);
        $file = $this->activity->files()->sole();

        $this->delete(route('activities.files.destroy', [$this->activity, $file]))
            ->assertSessionHas('success', 'flash.activity_file_deleted');

        $this->assertModelMissing($file);
        Storage::disk(PrivateFileStorage::DISK)->assertMissing($file->path);
    }

    #[Test]
    public function deleting_the_activity_removes_photos_and_files(): void
    {
        $this->actingAs($this->admin());
        $this->uploadPhotos();
        $this->post(route('activities.files.store', $this->activity), ['file' => UploadedFile::fake()->create('a.pdf', 50, 'application/pdf')]);
        $photo = $this->activity->photos()->first();
        $file = $this->activity->files()->first();

        $this->delete(route('activities.destroy', $this->activity));

        Storage::disk('public')->assertMissing($photo->path);
        Storage::disk('public')->assertMissing($photo->thumb_path);
        Storage::disk(PrivateFileStorage::DISK)->assertMissing($file->path);
    }

    #[Test]
    public function a_viewer_cannot_change_photos_or_files(): void
    {
        $viewer = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => ['activities' => ['view']]])->id]);

        $this->actingAs($viewer)
            ->post(route('activities.photos.store', $this->activity), ['photos' => [UploadedFile::fake()->image('p.jpg')]])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('activities.files.store', $this->activity), ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Activities/ActivityMediaTest.php`
Expected: FAIL (`Route [activities.photos.store] not defined`).

- [ ] **Step 3: Write the photo controller**

`app/Http/Controllers/ActivityPhotoController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityPhoto;
use App\Services\Storage\FileStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ActivityPhotoController extends Controller
{
    public function store(Request $request, Activity $activity, FileStorageService $storage): RedirectResponse
    {
        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:20'],
            'photos.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $order = (int) $activity->photos()->max('sort_order');
        foreach ($request->file('photos') as $upload) {
            $large = $storage->storeImage($upload, $activity->photoDirectory(), 1600);
            $thumb = $storage->storeImage($upload, $activity->photoDirectory().'/thumbs', 400);

            $activity->photos()->create([
                'path' => $large['path'],
                'thumb_path' => $thumb['path'],
                'sort_order' => ++$order,
            ]);
        }

        return back()->with('success', 'flash.activity_photos_added');
    }

    public function update(Request $request, Activity $activity, ActivityPhoto $photo): RedirectResponse
    {
        $data = $request->validate([
            'caption' => ['nullable', 'string', 'max:255'],
            'in_report' => ['sometimes', 'boolean'],
        ]);

        $inReport = $request->has('in_report') ? $request->boolean('in_report') : $photo->in_report;
        if ($inReport && ! $photo->in_report
            && $activity->photos()->where('in_report', true)->count() >= ActivityPhoto::REPORT_LIMIT) {
            return back()->with('error', 'flash.activity_report_photos_limit');
        }

        $photo->update([
            'caption' => array_key_exists('caption', $data) ? $data['caption'] : $photo->caption,
            'in_report' => $inReport,
        ]);

        return back()->with('success', 'flash.activity_photo_updated');
    }

    public function destroy(Activity $activity, ActivityPhoto $photo, FileStorageService $storage): RedirectResponse
    {
        $storage->delete($photo->path);
        $storage->delete($photo->thumb_path);
        $photo->delete();

        return back()->with('success', 'flash.activity_photo_deleted');
    }
}
```

Note: the 400px thumb from a 2400px fake image is exactly 400 wide because `storeImage` scales down to `$maxWidth`.

- [ ] **Step 4: Write the file controller**

`app/Http/Controllers/ActivityFileController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityFile;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Attached documents (minutes, agreements, certificates): private disk, archive only. */
class ActivityFileController extends Controller
{
    public function store(Request $request, Activity $activity, PrivateFileStorage $storage): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp', 'max:20480'],
        ]);

        $activity->files()->create($storage->store($request->file('file'), $activity->fileDirectory()));

        return back()->with('success', 'flash.activity_file_uploaded');
    }

    public function show(Activity $activity, ActivityFile $file, PrivateFileStorage $storage): StreamedResponse
    {
        return $storage->serve($file->path, $file->original_name);
    }

    public function destroy(Activity $activity, ActivityFile $file, PrivateFileStorage $storage): RedirectResponse
    {
        $storage->delete($file->path);
        $file->delete();

        return back()->with('success', 'flash.activity_file_deleted');
    }
}
```

- [ ] **Step 5: Routes**

`routes/web.php` — imports:

```php
use App\Http\Controllers\ActivityFileController;
use App\Http\Controllers\ActivityPhotoController;
```

Directly after `Route::resource('activities', ActivityController::class);`:

```php
        Route::scopeBindings()->group(function () {
            Route::post('/activities/{activity}/photos', [ActivityPhotoController::class, 'store'])->name('activities.photos.store');
            Route::put('/activities/{activity}/photos/{photo}', [ActivityPhotoController::class, 'update'])->name('activities.photos.update');
            Route::delete('/activities/{activity}/photos/{photo}', [ActivityPhotoController::class, 'destroy'])->name('activities.photos.destroy');
            Route::post('/activities/{activity}/files', [ActivityFileController::class, 'store'])->name('activities.files.store');
            Route::get('/activities/{activity}/files/{file}', [ActivityFileController::class, 'show'])->name('activities.files.show');
            Route::delete('/activities/{activity}/files/{file}', [ActivityFileController::class, 'destroy'])->name('activities.files.destroy');
        });
```

Scoped binding resolves `{photo}` through `$activity->photos()` and `{file}` through `$activity->files()` (relation names match the parameter names in plural), so a photo of another activity returns 404.

- [ ] **Step 6: i18n keys**

| Key | ar | fr | en |
|---|---|---|---|
| `flash.activity_photos_added` | تمت إضافة الصور | Photos ajoutées | Photos added |
| `flash.activity_photo_updated` | تم تعديل الصورة | Photo modifiée | Photo updated |
| `flash.activity_photo_deleted` | تم حذف الصورة | Photo supprimée | Photo deleted |
| `flash.activity_report_photos_limit` | 4 صور كحد أقصى في التقرير | 4 photos au maximum dans le rapport | At most 4 photos in the report |
| `flash.activity_file_uploaded` | تم رفع الملف | Fichier ajouté | File uploaded |
| `flash.activity_file_deleted` | تم حذف الملف | Fichier supprimé | File deleted |

- [ ] **Step 7: Run the tests**

Run: `php artisan test --compact tests/Feature/Activities`
Expected: PASS (all four test classes).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/ActivityPhotoController.php app/Http/Controllers/ActivityFileController.php routes/web.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Activities/ActivityMediaTest.php
git commit -m "feat(activities): photo gallery and private attached files"
```

---

### Task 5: Vue pages, navigation, i18n

**Files:**
- Create: `resources/js/Components/Activities/ParticipantPicker.vue`
- Create: `resources/js/Pages/Activities/Index.vue`, `Form.vue`, `Show.vue`
- Modify: `resources/js/Components/Icon.vue` (add `heart`)
- Modify: `resources/js/Composables/useNavigation.js` (`social` section)
- Modify: `resources/js/Components/PermissionGrid.vue` (`SECTION_LABELS.social`)
- Modify: `resources/js/i18n/{ar,fr,en}.json`

**Interfaces:**
- Consumes: the props listed in Task 3 / routes in Task 4; `SearchableSelect` (`modelValue`, `options [{value,label,description}]`, `placeholder`), `MultiSelectFilter` (`modelValue: string[]`, `options [{value,label}]`, `label`, `placeholder`), `Pagination` (`links`), `IconButton` (`icon`, `label`, `plain`, `size`, `variant`), `ConfirmModal`, `useCan().can(module, action)`.
- Produces: `ParticipantPicker` — props `modelValue: number[]`, `options: [{value,label,description?}]`, `placeholder: string`; emits `update:modelValue`.

Before writing, open these and copy their exact usage (imports, prop names, classes): `resources/js/Pages/Board/Meetings.vue` (list page layout), `resources/js/Pages/Board/Meeting.vue` (detail page + file upload with `useForm` + `forceFormData`), any page using `MultiSelectFilter` with `router.get(..., { preserveState: true, replace: true })` (`grep -rln "MultiSelectFilter" resources/js/Pages`), and `resources/js/Components/Dashboard/StatTile.vue` (props). Where a snippet below differs from those files, follow the files.

- [ ] **Step 1: Icon, navigation, permission grid**

`resources/js/Components/Icon.vue` — add to the glyph map (next to `board`):

```js
    heart: '<path d="M12 20s-7-4.35-7-10a4 4 0 0 1 7-2.65A4 4 0 0 1 19 10c0 5.65-7 10-7 10z"/>',
```

`resources/js/Composables/useNavigation.js` — after the `board` section object:

```js
            { key: 'social', label: t('nav.social'), icon: 'heart', items: [
                { label: t('activities'), href: '/activities', icon: 'heart', prefix: '/activities', module: 'activities' },
            ] },
```

`resources/js/Components/PermissionGrid.vue` — in `SECTION_LABELS` after `board`:

```js
    social: 'nav.social',
```

- [ ] **Step 2: ParticipantPicker**

`resources/js/Components/Activities/ParticipantPicker.vue`:

```vue
<script setup>
import { computed, ref, watch } from 'vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import IconButton from '@/Components/IconButton.vue';
import { useI18n } from 'vue-i18n';

/** Several people from a long list: pick one at a time, chips below. */
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const pick = ref('');
const chosen = computed(() => new Set(props.modelValue.map(Number)));
const remaining = computed(() => props.options.filter((o) => !chosen.value.has(Number(o.value))));
const selected = computed(() => props.options.filter((o) => chosen.value.has(Number(o.value))));

watch(pick, (value) => {
    if (value === '' || value === null) return;
    emit('update:modelValue', [...props.modelValue.map(Number), Number(value)]);
    pick.value = '';
});

function remove(value) {
    emit('update:modelValue', props.modelValue.map(Number).filter((v) => v !== Number(value)));
}
</script>

<template>
    <div class="space-y-2">
        <SearchableSelect v-model="pick" :options="remaining" :placeholder="placeholder" />
        <ul v-if="selected.length" class="flex flex-wrap gap-2">
            <li v-for="o in selected" :key="o.value" class="flex items-center gap-1 rounded-full bg-slate-100 py-0.5 ps-3 pe-1 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                {{ o.label }}
                <IconButton icon="close" :label="t('remove')" plain size="sm" @click="remove(o.value)" />
            </li>
        </ul>
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('activities.selected_count', { n: selected.length }) }}</p>
    </div>
</template>
```

(If the catalogs have no `remove` key, use the existing `delete` key instead: `grep -n '"remove"' resources/js/i18n/en.json`.)

- [ ] **Step 3: Index page**

`resources/js/Pages/Activities/Index.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MultiSelectFilter from '@/Components/MultiSelectFilter.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Pagination from '@/Components/Pagination.vue';
import StatTile from '@/Components/Dashboard/StatTile.vue';
import Icon from '@/Components/Icon.vue';
import { useCan } from '@/Composables/useCan';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { reactive, watch } from 'vue';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({
    activities: Object,
    stats: Object,
    filters: Object,
    types: Array,
    branches: Array,
});

const state = reactive({
    types: props.filters.types ?? [],
    branch: props.filters.branch ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    search: props.filters.search ?? '',
});

let timer = null;
watch(state, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('activities.index'), Object.fromEntries(Object.entries(state).filter(([, v]) => v !== '' && !(Array.isArray(v) && !v.length))), {
            preserveState: true, preserveScroll: true, replace: true,
        });
    }, 300);
});

const dates = (a) => (a.ends_on && a.ends_on !== a.starts_on ? `${a.starts_on} → ${a.ends_on}` : a.starts_on);
</script>

<template>
    <Head :title="t('activities')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('activities') }}</h1>
                <Link v-if="can('activities', 'add')" :href="route('activities.create')" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    <Icon name="plus" class="h-4 w-4" /> {{ t('activities.add') }}
                </Link>
            </div>
        </template>

        <div class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-3">
                <StatTile :label="t('activities.stats.count')" :value="stats.count" icon="heart" />
                <StatTile :label="t('activities.stats.beneficiaries')" :value="stats.beneficiaries" icon="players" />
                <StatTile :label="t('activities.stats.participants')" :value="stats.participants" icon="members" />
            </div>

            <div class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <SearchInput v-model="state.search" :placeholder="t('activities.search')" class="min-w-56 flex-1" />
                <MultiSelectFilter v-model="state.types" :options="types.map((ty) => ({ value: String(ty.id), label: ty.name }))" :label="t('activities.type')" :placeholder="t('activities.all_types')" collapse-all />
                <select v-model="state.branch" class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900">
                    <option value="">{{ t('activities.all_branches') }}</option>
                    <option v-for="b in branches" :key="b.id" :value="String(b.id)">{{ b.localized_name }}</option>
                </select>
                <label class="text-sm text-slate-600 dark:text-slate-300">{{ t('activities.from') }}
                    <input v-model="state.from" type="date" class="ms-1 rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900" />
                </label>
                <label class="text-sm text-slate-600 dark:text-slate-300">{{ t('activities.to') }}
                    <input v-model="state.to" type="date" class="ms-1 rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900" />
                </label>
            </div>

            <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="a in activities.data" :key="a.id">
                    <Link :href="route('activities.show', a.id)" class="flex h-full flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 transition hover:ring-primary-300 dark:bg-slate-900 dark:ring-slate-800">
                        <img v-if="a.cover" :src="a.cover.thumb_url" alt="" loading="lazy" class="h-40 w-full object-cover" />
                        <div v-else class="flex h-40 items-center justify-center bg-slate-50 text-slate-300 dark:bg-slate-950 dark:text-slate-700"><Icon name="heart" class="h-10 w-10" /></div>
                        <div class="flex flex-1 flex-col gap-1 p-4">
                            <span class="w-fit rounded bg-primary-50 px-2 py-0.5 text-xs text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">{{ a.type?.name }}</span>
                            <h2 class="font-semibold text-slate-900 dark:text-slate-100">{{ a.title }}</h2>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ dates(a) }}<span v-if="a.location"> · {{ a.location }}</span></p>
                            <p class="mt-auto pt-2 text-xs text-slate-500 dark:text-slate-400">
                                {{ t('activities.beneficiaries') }}: {{ a.beneficiaries_count }} · {{ t('activities.participants') }}: {{ a.participants_count }}
                            </p>
                        </div>
                    </Link>
                </li>
            </ul>
            <p v-if="!activities.data.length" class="rounded-2xl bg-white p-8 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-400 dark:ring-slate-800">{{ t('activities.empty') }}</p>

            <Pagination :links="activities.links" />
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 4: Form page**

`resources/js/Pages/Activities/Form.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import ParticipantPicker from '@/Components/Activities/ParticipantPicker.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    activity: { type: Object, default: null },
    types: Array,
    branches: Array,
    categories: Array,
    players: Array,
    boardMembers: Array,
});

const a = props.activity ?? {};
const form = useForm({
    title: a.title ?? '',
    activity_type_id: a.activity_type_id ?? '',
    starts_on: a.starts_on ?? '',
    ends_on: a.ends_on ?? '',
    location: a.location ?? '',
    partners: a.partners ?? '',
    branch_id: a.branch_id ?? '',
    responsible_board_member_id: a.responsible_board_member_id ?? '',
    cost: a.cost ?? '',
    beneficiaries_count: a.beneficiaries_count ?? 0,
    external_volunteers_count: a.external_volunteers_count ?? 0,
    description: a.description ?? '',
    report: a.report ?? '',
    category_ids: a.category_ids ?? [],
    player_ids: a.player_ids ?? [],
    board_member_ids: a.board_member_ids ?? [],
});

function submit() {
    const clean = (d) => ({ ...d, ends_on: d.ends_on || null, branch_id: d.branch_id || null, responsible_board_member_id: d.responsible_board_member_id || null, cost: d.cost === '' ? null : d.cost });
    if (props.activity) form.transform(clean).put(route('activities.update', props.activity.id));
    else form.transform(clean).post(route('activities.store'));
}

const field = 'mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
const label = 'text-sm font-medium text-slate-700 dark:text-slate-200';
const card = 'space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
</script>

<template>
    <Head :title="activity ? t('activities.edit') : t('activities.add')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ activity ? t('activities.edit') : t('activities.add') }}</h1>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-6">
            <section :class="card">
                <div>
                    <label :class="label">{{ t('activities.title') }}</label>
                    <TextInput v-model="form.title" class="mt-1 w-full" required />
                    <InputError :message="form.errors.title" class="mt-1" />
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label :class="label">{{ t('activities.type') }}</label>
                        <select v-model="form.activity_type_id" :class="field" required>
                            <option value="" disabled>—</option>
                            <option v-for="ty in types" :key="ty.id" :value="ty.id">{{ ty.name }}</option>
                        </select>
                        <InputError :message="form.errors.activity_type_id" class="mt-1" />
                    </div>
                    <div>
                        <label :class="label">{{ t('activities.starts_on') }}</label>
                        <input v-model="form.starts_on" type="date" :class="field" required />
                        <InputError :message="form.errors.starts_on" class="mt-1" />
                    </div>
                    <div>
                        <label :class="label">{{ t('activities.ends_on') }}</label>
                        <input v-model="form.ends_on" type="date" :min="form.starts_on" :class="field" />
                        <p class="mt-1 text-xs text-slate-500">{{ t('activities.single_day_hint') }}</p>
                        <InputError :message="form.errors.ends_on" class="mt-1" />
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label :class="label">{{ t('activities.location') }}</label>
                        <TextInput v-model="form.location" class="mt-1 w-full" />
                    </div>
                    <div>
                        <label :class="label">{{ t('activities.partners') }}</label>
                        <TextInput v-model="form.partners" class="mt-1 w-full" />
                    </div>
                    <div>
                        <label :class="label">{{ t('activities.branch') }}</label>
                        <select v-model="form.branch_id" :class="field">
                            <option value="">—</option>
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.localized_name }}</option>
                        </select>
                    </div>
                    <div>
                        <label :class="label">{{ t('activities.responsible') }}</label>
                        <SearchableSelect v-model="form.responsible_board_member_id" :options="boardMembers" class="mt-1" />
                    </div>
                </div>
                <div>
                    <span :class="label">{{ t('activities.categories') }}</span>
                    <div class="mt-2 flex flex-wrap gap-3">
                        <label v-for="c in categories" :key="c.id" class="flex items-center gap-1.5 text-sm text-slate-700 dark:text-slate-200">
                            <input v-model="form.category_ids" type="checkbox" :value="c.id" class="rounded border-slate-300 dark:border-slate-700" /> {{ c.localized_name }}
                        </label>
                    </div>
                </div>
            </section>

            <section :class="card">
                <h2 class="font-semibold text-slate-900 dark:text-slate-100">{{ t('activities.participants') }}</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label :class="label">{{ t('activities.beneficiaries') }}</label>
                        <TextInput v-model="form.beneficiaries_count" type="number" min="0" class="mt-1 w-full" />
                        <InputError :message="form.errors.beneficiaries_count" class="mt-1" />
                    </div>
                    <div>
                        <label :class="label">{{ t('activities.external_volunteers') }}</label>
                        <TextInput v-model="form.external_volunteers_count" type="number" min="0" class="mt-1 w-full" />
                    </div>
                    <div>
                        <label :class="label">{{ t('activities.cost') }}</label>
                        <TextInput v-model="form.cost" type="number" min="0" step="0.01" class="mt-1 w-full" />
                        <p class="mt-1 text-xs text-slate-500">{{ t('activities.cost_hint') }}</p>
                    </div>
                </div>
                <div>
                    <label :class="label">{{ t('activities.players') }}</label>
                    <ParticipantPicker v-model="form.player_ids" :options="players" :placeholder="t('activities.pick_player')" class="mt-1" />
                </div>
                <div>
                    <label :class="label">{{ t('activities.board_members') }}</label>
                    <ParticipantPicker v-model="form.board_member_ids" :options="boardMembers" :placeholder="t('activities.pick_member')" class="mt-1" />
                </div>
            </section>

            <section :class="card">
                <div>
                    <label :class="label">{{ t('activities.description') }}</label>
                    <textarea v-model="form.description" rows="3" :class="field" />
                </div>
                <div>
                    <label :class="label">{{ t('activities.report') }}</label>
                    <textarea v-model="form.report" rows="8" :class="field" />
                    <p class="mt-1 text-xs text-slate-500">{{ t('activities.report_hint') }}</p>
                    <InputError :message="form.errors.report" class="mt-1" />
                </div>
            </section>

            <div class="flex justify-end gap-3">
                <Link :href="activity ? route('activities.show', activity.id) : route('activities.index')" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">{{ t('cancel') }}</Link>
                <PrimaryButton :disabled="form.processing">{{ t('save') }}</PrimaryButton>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 5: Show page**

`resources/js/Pages/Activities/Show.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Modal from '@/Components/Modal.vue';
import TextInput from '@/Components/TextInput.vue';
import Icon from '@/Components/Icon.vue';
import { useCan } from '@/Composables/useCan';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({ activity: Object, reportLimit: Number });

const canEdit = computed(() => can('activities', 'edit'));
const inReportCount = computed(() => props.activity.photos.filter((p) => p.in_report).length);
const confirm = ref(null); // { kind: 'activity' | 'photo' | 'file', id }
const lightbox = ref(null);
const captionEdit = ref(null);
const captionText = ref('');

const photoForm = useForm({ photos: [] });
const fileForm = useForm({ file: null });

function uploadPhotos(event) {
    photoForm.photos = Array.from(event.target.files);
    photoForm.post(route('activities.photos.store', props.activity.id), { forceFormData: true, preserveScroll: true, onFinish: () => { event.target.value = ''; photoForm.reset(); } });
}

function uploadFile(event) {
    fileForm.file = event.target.files[0];
    fileForm.post(route('activities.files.store', props.activity.id), { forceFormData: true, preserveScroll: true, onFinish: () => { event.target.value = ''; fileForm.reset(); } });
}

function toggleReport(photo) {
    router.put(route('activities.photos.update', [props.activity.id, photo.id]), { in_report: !photo.in_report }, { preserveScroll: true });
}

function saveCaption(photo) {
    router.put(route('activities.photos.update', [props.activity.id, photo.id]), { caption: captionText.value || null }, { preserveScroll: true, onSuccess: () => { captionEdit.value = null; } });
}

function doDelete() {
    const c = confirm.value;
    const done = { preserveScroll: true, onFinish: () => { confirm.value = null; } };
    if (c.kind === 'activity') router.delete(route('activities.destroy', props.activity.id), done);
    if (c.kind === 'photo') router.delete(route('activities.photos.destroy', [props.activity.id, c.id]), done);
    if (c.kind === 'file') router.delete(route('activities.files.destroy', [props.activity.id, c.id]), done);
}

const a = computed(() => props.activity);
const dates = computed(() => (a.value.ends_on && a.value.ends_on !== a.value.starts_on ? `${a.value.starts_on} → ${a.value.ends_on}` : a.value.starts_on));
const size = (n) => (n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.ceil(n / 1024)} KB`);
const card = 'rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
</script>

<template>
    <Head :title="a.title" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link :href="route('activities.index')" class="text-sm text-slate-500 hover:underline">{{ t('activities') }}</Link>
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ a.title }}</h1>
                </div>
                <div class="flex gap-2">
                    <Link v-if="canEdit" :href="route('activities.edit', a.id)"><IconButton icon="pencil" :label="t('edit')" /></Link>
                    <IconButton v-if="can('activities', 'delete')" icon="trash" :label="t('delete')" variant="danger" @click="confirm = { kind: 'activity' }" />
                </div>
            </div>
        </template>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <section :class="card">
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-slate-500">{{ t('activities.type') }}</dt><dd class="font-medium">{{ a.type?.name }}</dd></div>
                        <div><dt class="text-slate-500">{{ t('activities.dates') }}</dt><dd class="font-medium">{{ dates }}</dd></div>
                        <div v-if="a.location"><dt class="text-slate-500">{{ t('activities.location') }}</dt><dd class="font-medium">{{ a.location }}</dd></div>
                        <div v-if="a.partners"><dt class="text-slate-500">{{ t('activities.partners') }}</dt><dd class="font-medium">{{ a.partners }}</dd></div>
                        <div v-if="a.branch"><dt class="text-slate-500">{{ t('activities.branch') }}</dt><dd class="font-medium">{{ a.branch.localized_name }}</dd></div>
                        <div v-if="a.categories.length"><dt class="text-slate-500">{{ t('activities.categories') }}</dt><dd class="font-medium">{{ a.categories.map((c) => c.localized_name).join('، ') }}</dd></div>
                        <div v-if="a.responsible"><dt class="text-slate-500">{{ t('activities.responsible') }}</dt><dd class="font-medium">{{ a.responsible.name }}</dd></div>
                        <div v-if="a.cost !== null"><dt class="text-slate-500">{{ t('activities.cost') }}</dt><dd class="font-medium">{{ a.cost }}</dd></div>
                    </dl>
                    <p v-if="a.description" class="mt-4 whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ a.description }}</p>
                </section>

                <section :class="card">
                    <h2 class="mb-2 font-semibold">{{ t('activities.report') }}</h2>
                    <p v-if="a.report" class="whitespace-pre-line text-sm leading-relaxed text-slate-700 dark:text-slate-300">{{ a.report }}</p>
                    <p v-else class="text-sm text-slate-400">{{ t('activities.no_report') }}</p>
                </section>

                <section :class="card">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2 class="font-semibold">{{ t('activities.photos') }} <span class="text-sm font-normal text-slate-500">· {{ t('activities.in_report_count', { n: inReportCount, max: reportLimit }) }}</span></h2>
                        <label v-if="canEdit" class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-700">
                            <Icon name="upload" class="h-4 w-4" /> {{ t('activities.add_photos') }}
                            <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" :disabled="photoForm.processing" @change="uploadPhotos" />
                        </label>
                    </div>
                    <p v-if="photoForm.errors.photos || Object.keys(photoForm.errors).length" class="mb-2 text-sm text-red-600">{{ Object.values(photoForm.errors)[0] }}</p>
                    <ul v-if="a.photos.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <li v-for="p in a.photos" :key="p.id" class="group relative overflow-hidden rounded-xl ring-1 ring-slate-200 dark:ring-slate-800" :class="p.in_report ? 'ring-2 ring-primary-500' : ''">
                            <button type="button" class="block w-full" @click="lightbox = p"><img :src="p.thumb_url" :alt="p.caption || ''" loading="lazy" class="h-32 w-full object-cover" /></button>
                            <div class="space-y-1 p-2 text-xs">
                                <div v-if="captionEdit === p.id" class="flex gap-1">
                                    <TextInput v-model="captionText" class="w-full text-xs" @keyup.enter="saveCaption(p)" />
                                    <IconButton icon="save" :label="t('save')" plain size="sm" @click="saveCaption(p)" />
                                </div>
                                <p v-else class="truncate text-slate-600 dark:text-slate-300">{{ p.caption || '—' }}</p>
                                <div v-if="canEdit" class="flex items-center justify-between">
                                    <label class="flex items-center gap-1" :title="t('activities.in_report_hint', { max: reportLimit })">
                                        <input type="checkbox" :checked="p.in_report" :disabled="!p.in_report && inReportCount >= reportLimit" class="rounded border-slate-300" @change="toggleReport(p)" />
                                        {{ t('activities.in_report') }}
                                    </label>
                                    <span class="flex">
                                        <IconButton icon="pencil" :label="t('activities.caption')" plain size="sm" @click="captionEdit = p.id; captionText = p.caption || ''" />
                                        <IconButton icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="confirm = { kind: 'photo', id: p.id }" />
                                    </span>
                                </div>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-400">{{ t('activities.no_photos') }}</p>
                </section>
            </div>

            <div class="space-y-6">
                <section :class="card">
                    <h2 class="mb-3 font-semibold">{{ t('activities.participants') }} · {{ a.participants_count }}</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('activities.beneficiaries') }}: <strong>{{ a.beneficiaries_count }}</strong></p>
                    <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('activities.external_volunteers') }}: <strong>{{ a.external_volunteers_count }}</strong></p>
                    <div v-if="a.board_members.length" class="mt-3">
                        <h3 class="text-xs font-semibold uppercase text-slate-500">{{ t('activities.board_members') }}</h3>
                        <ul class="mt-1 space-y-0.5 text-sm"><li v-for="m in a.board_members" :key="m.id">{{ m.name }}</li></ul>
                    </div>
                    <div v-if="a.players.length" class="mt-3">
                        <h3 class="text-xs font-semibold uppercase text-slate-500">{{ t('activities.players') }}</h3>
                        <ul class="mt-1 space-y-0.5 text-sm">
                            <li v-for="p in a.players" :key="p.id">
                                <Link v-if="can('players', 'view')" :href="route('players.show', p.id)" class="hover:underline">{{ p.name }}</Link>
                                <span v-else>{{ p.name }}</span>
                            </li>
                        </ul>
                    </div>
                </section>

                <section :class="card">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="font-semibold">{{ t('activities.files') }}</h2>
                        <label v-if="canEdit" class="cursor-pointer">
                            <IconButton icon="upload" :label="t('activities.add_file')" plain tag="span" />
                            <input type="file" accept=".pdf,.doc,.docx,image/jpeg,image/png,image/webp" class="hidden" :disabled="fileForm.processing" @change="uploadFile" />
                        </label>
                    </div>
                    <p v-if="fileForm.errors.file" class="mb-2 text-sm text-red-600">{{ fileForm.errors.file }}</p>
                    <ul v-if="a.files.length" class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        <li v-for="f in a.files" :key="f.id" class="flex items-center justify-between gap-2 py-2">
                            <a :href="route('activities.files.show', [a.id, f.id])" target="_blank" class="truncate hover:underline">{{ f.original_name }}</a>
                            <span class="flex shrink-0 items-center gap-1 text-xs text-slate-500">
                                {{ size(f.size) }}
                                <IconButton v-if="canEdit" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="confirm = { kind: 'file', id: f.id }" />
                            </span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-400">{{ t('activities.no_files') }}</p>
                </section>
            </div>
        </div>

        <Modal :show="!!lightbox" max-width="4xl" @close="lightbox = null">
            <figure v-if="lightbox" class="bg-black">
                <img :src="lightbox.url" :alt="lightbox.caption || ''" class="mx-auto max-h-[80vh] object-contain" />
                <figcaption v-if="lightbox.caption" class="p-3 text-center text-sm text-white">{{ lightbox.caption }}</figcaption>
            </figure>
        </Modal>

        <ConfirmModal :show="!!confirm" :message="t('are_you_sure')" @confirm="doDelete" @cancel="confirm = null" />
    </AuthenticatedLayout>
</template>
```

Check `IconButton` props before using `tag="span"`: if it has no `tag` prop, replace the file-upload `IconButton` with a plain `<span class="...">` holding `<Icon name="upload" />` and the label in `title`. Check `Modal` accepts `max-width="4xl"`; use the nearest supported value otherwise.

- [ ] **Step 6: i18n keys (pages)**

| Key | ar | fr | en |
|---|---|---|---|
| `nav.social` | النشاط الاجتماعي | Activités sociales | Social activities |
| `activities.add` | نشاط جديد | Nouvelle activité | New activity |
| `activities.edit` | تعديل النشاط | Modifier l'activité | Edit activity |
| `activities.title` | العنوان | Titre | Title |
| `activities.type` | النوع | Type | Type |
| `activities.all_types` | كل الأنواع | Tous les types | All types |
| `activities.all_branches` | كل الفروع | Toutes les sections | All branches |
| `activities.starts_on` | تاريخ البداية | Date de début | Start date |
| `activities.ends_on` | تاريخ النهاية | Date de fin | End date |
| `activities.single_day_hint` | اتركه فارغاً لنشاط يوم واحد | Vide pour une activité d'un jour | Leave empty for a one-day activity |
| `activities.dates` | التاريخ | Dates | Dates |
| `activities.from` | من | Du | From |
| `activities.to` | إلى | Au | To |
| `activities.location` | المكان | Lieu | Location |
| `activities.partners` | الشركاء | Partenaires | Partners |
| `activities.branch` | الفرع | Section | Branch |
| `activities.categories` | الفئات المعنية | Catégories concernées | Categories concerned |
| `activities.responsible` | المسؤول | Responsable | Person in charge |
| `activities.cost` | التكلفة | Coût | Cost |
| `activities.cost_hint` | للعلم فقط، لا تُسجَّل في المالية | Indicatif, non comptabilisé | For information only, not recorded in finance |
| `activities.beneficiaries` | المستفيدون | Bénéficiaires | Beneficiaries |
| `activities.external_volunteers` | متطوعون من خارج النادي | Bénévoles externes | Outside volunteers |
| `activities.participants` | المشاركون | Participants | Participants |
| `activities.players` | اللاعبون المشاركون | Joueurs participants | Participating players |
| `activities.board_members` | أعضاء المكتب المشاركون | Membres du bureau participants | Participating board members |
| `activities.pick_player` | أضف لاعباً… | Ajouter un joueur… | Add a player… |
| `activities.pick_member` | أضف عضواً… | Ajouter un membre… | Add a member… |
| `activities.selected_count` | {n} مختار | {n} sélectionné(s) | {n} selected |
| `activities.description` | الوصف | Description | Description |
| `activities.report` | تقرير النشاط | Rapport de l'activité | Activity report |
| `activities.report_hint` | يُطبع هذا النص في التقرير الأدبي | Ce texte est imprimé dans le rapport moral | This text is printed in the literary report |
| `activities.no_report` | لم يُكتب التقرير بعد | Rapport pas encore rédigé | No report written yet |
| `activities.photos` | الصور | Photos | Photos |
| `activities.add_photos` | إضافة صور | Ajouter des photos | Add photos |
| `activities.no_photos` | لا توجد صور | Aucune photo | No photos |
| `activities.caption` | التعليق | Légende | Caption |
| `activities.in_report` | في التقرير | Dans le rapport | In report |
| `activities.in_report_hint` | تُطبع حتى {max} صور في التقرير الأدبي | Jusqu'à {max} photos imprimées dans le rapport moral | Up to {max} photos are printed in the literary report |
| `activities.in_report_count` | {n}/{max} في التقرير | {n}/{max} dans le rapport | {n}/{max} in report |
| `activities.files` | الملفات المرفقة | Pièces jointes | Attached files |
| `activities.add_file` | إرفاق ملف | Joindre un fichier | Attach a file |
| `activities.no_files` | لا توجد ملفات | Aucun fichier | No files |
| `activities.search` | بحث في العنوان أو المكان أو الشركاء | Rechercher titre, lieu, partenaires | Search title, location, partners |
| `activities.empty` | لا توجد أنشطة | Aucune activité | No activities |
| `activities.stats.count` | الأنشطة | Activités | Activities |
| `activities.stats.beneficiaries` | المستفيدون | Bénéficiaires | Beneficiaries |
| `activities.stats.participants` | المشاركون | Participants | Participants |

The French catalog's word for the literary report: check how the existing finance report is named in `fr.json` and keep "rapport moral" consistent with it (Algerian clubs use "rapport moral" for التقرير الأدبي).

- [ ] **Step 7: Build and check**

Run: `npm run i18n:check`
Expected: exit 0.

Run: `npm run build`
Expected: build succeeds, no unresolved imports.

Run: `php artisan test --compact tests/Feature/Activities`
Expected: PASS.

- [ ] **Step 8: Manual check in the browser**

Run the app (`php artisan serve --port=2026` in the worktree with `npm run dev`, or the project's usual way) and as superadmin:
1. Sidebar shows «النشاط الاجتماعي» → «الأنشطة»; App Configuration shows «أنواع الأنشطة».
2. Create an activity with players, board members, categories; it opens on its page.
3. Upload 5 photos; tick 4 "في التقرير"; the 5th checkbox is disabled; edit a caption; open the lightbox.
4. Attach a PDF; it opens in a new tab; delete it.
5. Index: filter by type and dates; tiles change; search by location.
6. Switch UI to fr and en: no raw keys.
7. Roles page: «النشاط الاجتماعي» group with «الأنشطة»; «أنواع الأنشطة» under App Configuration.

- [ ] **Step 9: Commit**

```bash
git add resources/js/Components/Activities/ParticipantPicker.vue resources/js/Pages/Activities/Index.vue resources/js/Pages/Activities/Form.vue resources/js/Pages/Activities/Show.vue resources/js/Components/Icon.vue resources/js/Composables/useNavigation.js resources/js/Components/PermissionGrid.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(activities): list, form and detail pages with gallery"
```

---

### Task 6: Full verification

- [ ] **Step 1: Format**

Run: `vendor/bin/pint --dirty`
Expected: files fixed or "PASS". If it changed files, stage those explicit files and commit `style: pint`.

- [ ] **Step 2: Full suite**

Run: `php artisan test --compact`
Expected: all green; count = baseline (Task 0) + new tests.

- [ ] **Step 3: i18n + build**

Run: `npm run i18n:check && npm run build`
Expected: both succeed.

- [ ] **Step 4: Hand off**

Report to the owner: branch `feat/social-activities` ready for review; deploy needs `php artisan migrate` (new tables, seeded types, rights on system roles) and a desktop build. Do not merge or push without the owner's go-ahead (see push-only-verified-tip rule: check `main` tip and `origin/main..main` first).
