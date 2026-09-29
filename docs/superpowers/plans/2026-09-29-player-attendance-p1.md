# Player Attendance — P1 Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Track every player at every category training session (regular, extra, pre-season) with six statuses, from weekly schedules that generate sessions automatically, with a session screen and a month grid for fast entry.

**Architecture:** Five new tables (schedules, closures, pre-season targets, sessions, attendances). Small services under `app/Services/Attendance` (session generator, roster, mark recorder, code parser) called by four thin controllers. Four Inertia/Vue pages under `resources/js/Pages/Attendance`. A new RBAC module `attendance`.

**Tech Stack:** Laravel 13, Inertia v2 + Vue 3 (`<script setup>`, JS), Tailwind 4, vue-i18n (flat dotted keys), PHPUnit feature tests on sqlite.

Spec: `docs/superpowers/specs/2026-09-29-player-attendance-design.md`.

## Global Constraints

- Work in a git worktree on branch `feat/attendance-p1` created from `main`. The main working tree holds large uncommitted work (including `resources/js/i18n/*.json`); never stage or commit it. Copy `vendor/` and `node_modules/` into the worktree (plain copy, **no junctions/symlinks**), and copy `.env`.
- Stage explicit file paths only (`git add <file>...`). Never `git add -A` / `git add .`.
- Commit messages end with: `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`
- New tables store dates as `'Y-m-d'` strings and times as `'H:i'` strings. **No `date`/`datetime` casts** on those columns: sqlite would store `Y-m-d H:i:s` and break the unique key and string comparisons.
- Offline only: no CDN, remote fonts or remote scripts (the desktop app runs offline).
- i18n: flat dotted keys in `resources/js/i18n/{ar,fr,en}.json`. In Vue use `t()` directly, never `te()`. Every `flash.*` key a controller emits must exist in all three files (`npm run i18n:check`).
- Activity: record with `ActivityRecorder::record()` at the call site, inside the same `DB::transaction`. No free text or names in properties.
- Tests: PHPUnit classes with `#[Test]`, `use RefreshDatabase;`, in `tests/Feature` or `tests/Unit`. Run with `php artisan test --filter=<Class>`.
- Permissions come from route names via `config/permissions.php`. Last-segment verbs `index`/`show` map to view, `store` to add, `destroy` to delete, anything else to edit.
- Deviations from the spec, owner-visible (report them when P1 is done):
  1. There is no "generate month" button. Opening a month on the calendar or grid always generates it, which is idempotent.
  2. The grid accepts Latin codes only (`P R<n> D<n> B AE AN`); the legend is localised. An `AE` typed in the grid keeps an existing reason, otherwise uses `other`; the reason is edited on the session screen.
  3. Saving marks needs `attendance.edit`.

---

## File map

```
database/migrations/2026_09_29_100001_create_attendance_tables.php   5 tables
database/migrations/2026_09_29_100002_grant_attendance_permission.php
app/Enums/AttendanceStatus.php  AbsenceReason.php  SessionKind.php  SessionState.php
app/Models/TrainingSchedule.php ClubClosure.php PreseasonTarget.php TrainingSession.php Attendance.php
app/Support/AttendanceSettings.php         points/rules/alerts in WebsiteConfig.settings['attendance']
app/Services/Attendance/AttendanceCode.php grid code <-> status
app/Services/Attendance/SessionGenerator.php
app/Services/Attendance/Roster.php
app/Services/Attendance/MarkRecorder.php
app/Http/Requests/SaveAttendanceMarksRequest.php
app/Http/Controllers/AttendanceController.php          index (calendar), show, saveMarks
app/Http/Controllers/TrainingSessionController.php     store, cancel, move
app/Http/Controllers/AttendanceGridController.php      show, save
app/Http/Controllers/AttendanceSettingsController.php
resources/js/Pages/Attendance/Index.vue Session.vue Grid.vue Settings.vue
modify: app/Models/Role.php, config/permissions.php, app/Services/Activity/ActivityAction.php,
        routes/web.php, resources/js/Layouts/AuthenticatedLayout.vue, resources/js/i18n/*.json
tests/Support/AttendanceFixtures.php  + tests listed per task
```

---

### Task 0: Worktree

- [ ] **Step 1: Create the worktree and copy dependencies**

```bash
cd /d/irnb-laravel
git worktree add ../irnb-attendance -b feat/attendance-p1 main
cp .env ../irnb-attendance/.env
cp -r vendor ../irnb-attendance/vendor
cp -r node_modules ../irnb-attendance/node_modules
cd ../irnb-attendance && php artisan test --filter=ActivityRecorderTest
```
Expected: the tests pass. All later paths are relative to `D:/irnb-attendance`.

---

### Task 1: Enums, tables and models

**Files:**
- Create: `app/Enums/AttendanceStatus.php`, `app/Enums/AbsenceReason.php`, `app/Enums/SessionKind.php`, `app/Enums/SessionState.php`
- Create: `database/migrations/2026_09_29_100001_create_attendance_tables.php`
- Create: `app/Models/TrainingSchedule.php`, `app/Models/ClubClosure.php`, `app/Models/PreseasonTarget.php`, `app/Models/TrainingSession.php`, `app/Models/Attendance.php`
- Create: `tests/Support/AttendanceFixtures.php`
- Test: `tests/Unit/AttendanceStatusTest.php`, `tests/Feature/AttendanceModelTest.php`

**Interfaces:**
- Produces: `AttendanceStatus` with `takesMinutes(): bool`, `takesReason(): bool`, `requiresReason(): bool` and `values(): array`. The same `values()` exists on `AbsenceReason`, `SessionKind` and `SessionState`.
- Produces: `TrainingSession` relations `category()`, `schedule()`, `attendances()` and scope `unmarkedPlanned()`. `Attendance` relations `session()`, `player()`.
- Produces: test trait `Tests\Support\AttendanceFixtures` with `admin(): User`, `category(string $name = 'U15'): Category`, `player(Category $c, array $extra = []): Player`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/AttendanceStatusTest.php`:
```php
<?php

namespace Tests\Unit;

use App\Enums\AttendanceStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AttendanceStatusTest extends TestCase
{
    #[Test]
    public function only_late_and_left_early_take_minutes(): void
    {
        $withMinutes = array_filter(AttendanceStatus::cases(), fn ($s) => $s->takesMinutes());

        $this->assertSame([AttendanceStatus::Late, AttendanceStatus::LeftEarly], array_values($withMinutes));
    }

    #[Test]
    public function excused_absence_requires_a_reason_and_not_training_allows_one(): void
    {
        $this->assertTrue(AttendanceStatus::AbsentExcused->requiresReason());
        $this->assertTrue(AttendanceStatus::NotTraining->takesReason());
        $this->assertFalse(AttendanceStatus::NotTraining->requiresReason());
        $this->assertFalse(AttendanceStatus::AbsentUnexcused->takesReason());
        $this->assertSame(
            ['present', 'late', 'left_early', 'not_training', 'absent_excused', 'absent_unexcused'],
            AttendanceStatus::values(),
        );
    }
}
```

`tests/Support/AttendanceFixtures.php`:
```php
<?php

namespace Tests\Support;

use App\Models\Category;
use App\Models\Player;
use App\Models\User;

trait AttendanceFixtures
{
    private int $playerSeq = 0;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function category(string $name = 'U15'): Category
    {
        return Category::create(['name' => $name]);
    }

    private function player(Category $category, array $extra = []): Player
    {
        $n = ++$this->playerSeq;

        return Player::create([
            'membership_id' => '8'.str_pad((string) $n, 9, '0', STR_PAD_LEFT),
            'firstname' => 'P'.$n,
            'lastname' => 'Test'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'category_id' => $category->id,
            'outstanding_debt' => 0,
        ] + $extra);
    }
}
```

`tests/Feature/AttendanceModelTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\TrainingSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceModelTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function a_session_keeps_plain_date_and_time_strings_and_its_marks(): void
    {
        $u15 = $this->category();
        $player = $this->player($u15);
        $session = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
        Attendance::create([
            'training_session_id' => $session->id, 'player_id' => $player->id,
            'status' => AttendanceStatus::Late, 'minutes' => 15,
        ]);

        $fresh = TrainingSession::first();
        $this->assertSame('2026-10-05', $fresh->date);
        $this->assertSame('18:00', $fresh->start_time);
        $this->assertSame(SessionKind::Regular, $fresh->kind);
        $this->assertSame(AttendanceStatus::Late, $fresh->attendances->first()->status);
        $this->assertSame(15, $fresh->attendances->first()->minutes);
        $this->assertTrue($fresh->category->is($u15));
    }

    #[Test]
    public function the_same_category_slot_cannot_exist_twice(): void
    {
        $u15 = $this->category();
        $row = ['category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned];
        TrainingSession::create($row);

        $this->expectException(QueryException::class);
        TrainingSession::create($row);
    }

    #[Test]
    public function unmarked_planned_scope_skips_held_and_marked_sessions(): void
    {
        $u15 = $this->category();
        $base = ['category_id' => $u15->id, 'end_time' => '19:30', 'kind' => SessionKind::Regular];
        $planned = TrainingSession::create($base + ['date' => '2026-10-05', 'start_time' => '18:00', 'state' => SessionState::Planned]);
        TrainingSession::create($base + ['date' => '2026-10-06', 'start_time' => '18:00', 'state' => SessionState::Held]);
        $marked = TrainingSession::create($base + ['date' => '2026-10-07', 'start_time' => '18:00', 'state' => SessionState::Planned]);
        Attendance::create(['training_session_id' => $marked->id, 'player_id' => $this->player($u15)->id, 'status' => AttendanceStatus::Present]);

        $this->assertSame([$planned->id], TrainingSession::unmarkedPlanned()->pluck('id')->all());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="AttendanceStatusTest|AttendanceModelTest"`
Expected: FAIL, `Class "App\Enums\AttendanceStatus" not found`.

- [ ] **Step 3: Write the enums**

`app/Enums/AttendanceStatus.php`:
```php
<?php

namespace App\Enums;

/**
 * How a player took part in one training session. Every status is counted on
 * its own in reports; none is folded into another.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case LeftEarly = 'left_early';
    case NotTraining = 'not_training';   // came, did not train (injured, sick)
    case AbsentExcused = 'absent_excused';
    case AbsentUnexcused = 'absent_unexcused';

    /** Late and left-early marks carry how many minutes. */
    public function takesMinutes(): bool
    {
        return $this === self::Late || $this === self::LeftEarly;
    }

    public function takesReason(): bool
    {
        return $this === self::AbsentExcused || $this === self::NotTraining;
    }

    public function requiresReason(): bool
    {
        return $this === self::AbsentExcused;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

`app/Enums/AbsenceReason.php`:
```php
<?php

namespace App\Enums;

/** Why an excused (or not-training) player missed the session. */
enum AbsenceReason: string
{
    case Injury = 'injury';
    case Illness = 'illness';
    case School = 'school';
    case Family = 'family';
    case Travel = 'travel';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

`app/Enums/SessionKind.php`:
```php
<?php

namespace App\Enums;

/** Regular sessions come from the weekly schedule; the others are added by hand. */
enum SessionKind: string
{
    case Regular = 'regular';
    case Preseason = 'preseason';
    case Extra = 'extra';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

`app/Enums/SessionState.php`:
```php
<?php

namespace App\Enums;

/** Only held sessions count in reports. Saving marks makes a session held. */
enum SessionState: string
{
    case Planned = 'planned';
    case Held = 'held';
    case Cancelled = 'cancelled';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

- [ ] **Step 4: Write the migration**

`database/migrations/2026_09_29_100001_create_attendance_tables.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Player attendance. Dates are 'Y-m-d' and times 'H:i' strings so the
     * unique slot key and string comparisons behave the same on sqlite
     * (desktop) and MySQL (web).
     */
    public function up(): void
    {
        Schema::create('training_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // ISO: 1 = Monday … 7 = Sunday
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->string('valid_from', 10);
            $table->string('valid_to', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('club_closures', function (Blueprint $table) {
            $table->id();
            $table->string('start_date', 10);
            $table->string('end_date', 10);
            $table->string('reason', 100);
            $table->timestamps();
        });

        Schema::create('preseason_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('season_start_year');
            $table->unsignedSmallInteger('target_count');
            $table->timestamps();
            $table->unique(['category_id', 'season_start_year']);
        });

        Schema::create('training_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('training_schedules')->nullOnDelete();
            $table->string('date', 10);
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->string('kind', 16);
            $table->string('state', 16);
            $table->string('cancel_reason', 255)->nullable();
            $table->string('moved_from', 10)->nullable();
            $table->string('coach', 100)->nullable();
            $table->string('theme', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['category_id', 'date', 'start_time']);
            $table->index(['category_id', 'state', 'date']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->string('reason', 16)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['training_session_id', 'player_id']);
            $table->index(['player_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('training_sessions');
        Schema::dropIfExists('preseason_targets');
        Schema::dropIfExists('club_closures');
        Schema::dropIfExists('training_schedules');
    }
};
```

- [ ] **Step 5: Write the models**

`app/Models/TrainingSchedule.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One weekly training slot of a category, valid over a date range. */
class TrainingSchedule extends Model
{
    protected $fillable = ['category_id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_to'];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
```

`app/Models/ClubClosure.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A period (holidays, closed stadium) in which no regular session is generated. */
class ClubClosure extends Model
{
    protected $fillable = ['start_date', 'end_date', 'reason'];
}
```

`app/Models/PreseasonTarget.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How many pre-season (préparation physique) sessions a category plans for a season. */
class PreseasonTarget extends Model
{
    protected $fillable = ['category_id', 'season_start_year', 'target_count'];

    protected function casts(): array
    {
        return ['season_start_year' => 'integer', 'target_count' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
```

`app/Models/TrainingSession.php`:
```php
<?php

namespace App\Models;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One training of one category on one date. `date`/`moved_from` are 'Y-m-d'
 * strings and times 'H:i' strings (see the create_attendance_tables migration).
 */
class TrainingSession extends Model
{
    protected $fillable = [
        'category_id', 'schedule_id', 'date', 'start_time', 'end_time', 'kind', 'state',
        'cancel_reason', 'moved_from', 'coach', 'theme', 'notes',
    ];

    protected function casts(): array
    {
        return ['kind' => SessionKind::class, 'state' => SessionState::class];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TrainingSchedule::class, 'schedule_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /** Planned sessions nobody has marked yet: safe to delete and regenerate. */
    public function scopeUnmarkedPlanned(Builder $query): void
    {
        $query->where('state', SessionState::Planned->value)->whereDoesntHave('attendances');
    }
}
```

`app/Models/Attendance.php`:
```php
<?php

namespace App\Models;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One player's mark at one training session. */
class Attendance extends Model
{
    protected $fillable = ['training_session_id', 'player_id', 'status', 'minutes', 'reason', 'note', 'recorded_by'];

    protected function casts(): array
    {
        return ['status' => AttendanceStatus::class, 'reason' => AbsenceReason::class, 'minutes' => 'integer'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter="AttendanceStatusTest|AttendanceModelTest"`
Expected: PASS (5 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Enums/AttendanceStatus.php app/Enums/AbsenceReason.php app/Enums/SessionKind.php app/Enums/SessionState.php database/migrations/2026_09_29_100001_create_attendance_tables.php app/Models/TrainingSchedule.php app/Models/ClubClosure.php app/Models/PreseasonTarget.php app/Models/TrainingSession.php app/Models/Attendance.php tests/Support/AttendanceFixtures.php tests/Unit/AttendanceStatusTest.php tests/Feature/AttendanceModelTest.php
git commit -m "feat(attendance): schedules, sessions and marks tables

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Permission module, activity codes, translations

**Files:**
- Modify: `app/Models/Role.php:16-19` (MODULES), `config/permissions.php` (modules + overrides), `app/Services/Activity/ActivityAction.php` (constants, ALL, AREAS)
- Create: `database/migrations/2026_09_29_100002_grant_attendance_permission.php`
- Modify: `resources/js/i18n/en.json`, `fr.json`, `ar.json` (append keys)
- Test: `tests/Feature/AttendancePermissionTest.php`

**Interfaces:**
- Produces: module `attendance` and activity constants `ActivityAction::ATTENDANCE_MARKED`, `TRAINING_SESSION_CREATED`, `TRAINING_SESSION_CANCELLED` and `TRAINING_SESSION_MOVED`.
- Produces every `att.*`, `flash.*` and `activity.*` key used by Tasks 5–9.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendancePermissionTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Services\Activity\ActivityAction;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendancePermissionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function attendance_is_a_module_and_its_routes_map_to_it(): void
    {
        $this->assertContains('attendance', Role::MODULES);
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.index'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.sessions.show'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.grid'));
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.grid.save'));
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.sessions.marks'));
        $this->assertSame(['attendance', 'add'], PermissionMap::resolve('attendance.sessions.store'));
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.settings'));
    }

    #[Test]
    public function the_migration_mirrors_players_rights_and_gives_admin_roles_everything(): void
    {
        $coach = Role::factory()->create(['permissions' => ['players' => ['view', 'edit']]]);
        $cashier = Role::factory()->create(['permissions' => ['transactions' => ['view']]]);
        $admin = Role::factory()->create(['key' => 'administrator', 'permissions' => ['players' => ['view']]]);

        (require database_path('migrations/2026_09_29_100002_grant_attendance_permission.php'))->up();

        $this->assertSame(['view', 'edit'], $coach->fresh()->permissions['attendance']);
        $this->assertArrayNotHasKey('attendance', $cashier->fresh()->permissions);
        $this->assertSame(['view', 'add', 'edit', 'delete'], $admin->fresh()->permissions['attendance']);
    }

    #[Test]
    public function attendance_activity_codes_form_their_own_area(): void
    {
        $this->assertSame([
            ActivityAction::ATTENDANCE_MARKED,
            ActivityAction::TRAINING_SESSION_CREATED,
            ActivityAction::TRAINING_SESSION_CANCELLED,
            ActivityAction::TRAINING_SESSION_MOVED,
        ], ActivityAction::AREAS['attendance']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=AttendancePermissionTest`
Expected: FAIL, `'attendance'` is not in the MODULES array.

- [ ] **Step 3: Register the module**

`app/Models/Role.php`, replace the MODULES constant:
```php
    public const MODULES = [
        'players', 'documents', 'attendance', 'subscriptions', 'transactions', 'finance', 'reports',
        'equipment', 'inventory', 'board', 'users', 'categories', 'settings',
    ];
```

`config/permissions.php`: in `'modules'` add after `'players.documents' => 'documents',`:
```php
        'attendance' => 'attendance',
```
In `'overrides'` add after `'players.academic-results' => ['players', 'view'],`:
```php
        // "grid" is not a view verb: opening the month grid must not need edit rights.
        'attendance.grid' => ['attendance', 'view'],
```

- [ ] **Step 4: Add the activity codes**

`app/Services/Activity/ActivityAction.php`, after the `DOCUMENT_FILE_UPLOADED` constant:
```php
    // attendance
    const ATTENDANCE_MARKED = 'attendance_marked';

    const TRAINING_SESSION_CREATED = 'training_session_created';

    const TRAINING_SESSION_CANCELLED = 'training_session_cancelled';

    const TRAINING_SESSION_MOVED = 'training_session_moved';
```
Append these four constants to the end of the `ALL` array, in the same order. In `AREAS`, after the `'documents' => [...]` entry, add:
```php
        'attendance' => [
            self::ATTENDANCE_MARKED,
            self::TRAINING_SESSION_CREATED,
            self::TRAINING_SESSION_CANCELLED,
            self::TRAINING_SESSION_MOVED,
        ],
```

- [ ] **Step 5: Write the grant migration**

`database/migrations/2026_09_29_100002_grant_attendance_permission.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The new `attendance` module. Whoever could work on players keeps working:
     * each role gets the same actions on attendance as it has on players, and
     * the two system admin roles get everything. God admins need nothing (they
     * read Role::MODULES). A migration, not a seeder, because the desktop build
     * runs `migrate` on boot and never seeds. Idempotent: an existing
     * `attendance` key is left alone.
     */
    private const ADMIN_ROLES = ['superadmin', 'administrator'];

    private const ACTIONS = ['view', 'add', 'edit', 'delete'];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->get(['id', 'key', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            if (array_key_exists('attendance', $permissions)) {
                continue;
            }

            $actions = in_array($role->key, self::ADMIN_ROLES, true) ? self::ACTIONS : ($permissions['players'] ?? []);
            if ($actions === []) {
                continue;
            }

            $permissions['attendance'] = array_values($actions);
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
            unset($permissions['attendance']);
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
```

- [ ] **Step 6: Run the test**

Run: `php artisan test --filter="AttendancePermissionTest|ActivityRecorderTest|PermissionMiddlewareTest|DocumentsPermissionTest"`
Expected: PASS.

- [ ] **Step 7: Append the translations**

Create `att-i18n.mjs` in the repo root. It is temporary: run it, then delete it. It appends keys without reordering existing ones; the committed files round-trip through `JSON.stringify(o, null, 4)`.

```js
import { readFileSync, writeFileSync } from 'node:fs';

const keys = {
    'activity.action.attendance_marked': ['Attendance taken', 'Présences saisies', 'تسجيل الحضور'],
    'activity.action.training_session_created': ['Sessions added', 'Séances ajoutées', 'حصص مضافة'],
    'activity.action.training_session_cancelled': ['Sessions cancelled', 'Séances annulées', 'حصص ملغاة'],
    'activity.action.training_session_moved': ['Sessions moved', 'Séances déplacées', 'حصص مؤجلة'],
    'activity.area.attendance': ['Attendance', 'Présences', 'الحضور'],
    'flash.attendance_saved': ['Attendance saved.', 'Présences enregistrées.', 'تم حفظ الحضور.'],
    'flash.training_session_created': ['Session added.', 'Séance ajoutée.', 'تمت إضافة الحصة.'],
    'flash.training_session_cancelled': ['Session cancelled.', 'Séance annulée.', 'تم إلغاء الحصة.'],
    'flash.training_session_moved': ['Session moved.', 'Séance déplacée.', 'تم نقل الحصة.'],
    'flash.attendance_settings_saved': ['Attendance settings saved.', 'Réglages des présences enregistrés.', 'تم حفظ إعدادات الحضور.'],
    'flash.training_schedule_saved': ['Schedule saved.', 'Horaire enregistré.', 'تم حفظ التوقيت.'],
    'flash.training_schedule_deleted': ['Schedule deleted.', 'Horaire supprimé.', 'تم حذف التوقيت.'],
    'flash.club_closure_saved': ['Closure saved.', 'Fermeture enregistrée.', 'تم حفظ فترة الإغلاق.'],
    'flash.club_closure_deleted': ['Closure deleted.', 'Fermeture supprimée.', 'تم حذف فترة الإغلاق.'],
    'flash.preseason_target_saved': ['Pre-season target saved.', 'Objectif de préparation enregistré.', 'تم حفظ عدد حصص التحضير.'],
    'att.category': ['Category', 'Catégorie', 'الفئة'],
    'att.no_category': ['Create a category first.', "Créez d'abord une catégorie.", 'أنشئ فئة أولاً.'],
    'att.no_schedule': ['No weekly schedule for this category yet. Add one in the attendance settings.', "Pas encore d'horaire hebdomadaire pour cette catégorie. Ajoutez-en un dans les réglages des présences.", 'لا يوجد توقيت أسبوعي لهذه الفئة بعد. أضفه من إعدادات الحضور.'],
    'att.settings': ['Attendance settings', 'Réglages des présences', 'إعدادات الحضور'],
    'att.grid': ['Month grid', 'Grille du mois', 'جدول الشهر'],
    'att.add_extra': ['Extra session', 'Séance supplémentaire', 'حصة إضافية'],
    'att.add_preseason': ['Pre-season session', 'Séance de préparation', 'حصة تحضير بدني'],
    'att.preseason_progress': ['Pre-season {season}: {done}/{target}', 'Préparation {season} : {done}/{target}', 'التحضير البدني {season}: {done}/{target}'],
    'att.preseason_no_target': ['Pre-season {season}: {done} sessions', 'Préparation {season} : {done} séances', 'التحضير البدني {season}: {done} حصص'],
    'att.kind.regular': ['Training', 'Entraînement', 'تدريب'],
    'att.kind.preseason': ['Pre-season', 'Préparation physique', 'تحضير بدني'],
    'att.kind.extra': ['Extra', 'Supplémentaire', 'إضافية'],
    'att.state.planned': ['Planned', 'Prévue', 'مبرمجة'],
    'att.state.held': ['Held', 'Effectuée', 'منجزة'],
    'att.state.cancelled': ['Cancelled', 'Annulée', 'ملغاة'],
    'att.status.present': ['Present', 'Présent', 'حاضر'],
    'att.status.late': ['Late', 'En retard', 'متأخر'],
    'att.status.left_early': ['Left early', 'Parti tôt', 'غادر مبكراً'],
    'att.status.not_training': ['Present, not training', 'Présent, sans entraînement', 'حاضر دون تدريب'],
    'att.status.absent_excused': ['Absent (excused)', 'Absent (justifié)', 'غائب بعذر'],
    'att.status.absent_unexcused': ['Absent (unexcused)', 'Absent (non justifié)', 'غائب بدون عذر'],
    'att.reason.injury': ['Injury', 'Blessure', 'إصابة'],
    'att.reason.illness': ['Illness', 'Maladie', 'مرض'],
    'att.reason.school': ['School', 'École', 'دراسة'],
    'att.reason.family': ['Family', 'Famille', 'عائلة'],
    'att.reason.travel': ['Travel', 'Voyage', 'سفر'],
    'att.reason.other': ['Other', 'Autre', 'أخرى'],
    'att.date': ['Date', 'Date', 'التاريخ'],
    'att.start': ['Start', 'Début', 'البداية'],
    'att.end': ['End', 'Fin', 'النهاية'],
    'att.coach': ['Coach', 'Entraîneur', 'المدرب'],
    'att.theme': ['Theme', 'Thème', 'المحور'],
    'att.notes': ['Notes', 'Notes', 'ملاحظات'],
    'att.note': ['Note', 'Note', 'ملاحظة'],
    'att.minutes': ['Minutes', 'Minutes', 'الدقائق'],
    'att.reason': ['Reason', 'Motif', 'السبب'],
    'att.player': ['Player', 'Joueur', 'اللاعب'],
    'att.cancel': ['Cancel session', 'Annuler la séance', 'إلغاء الحصة'],
    'att.cancel_reason': ['Reason for cancelling', "Motif d'annulation", 'سبب الإلغاء'],
    'att.cancelled_because': ['Cancelled: {reason}', 'Annulée : {reason}', 'ملغاة: {reason}'],
    'att.move': ['Move session', 'Déplacer la séance', 'نقل الحصة'],
    'att.moved_from': ['Moved from {date}', 'Déplacée du {date}', 'منقولة من {date}'],
    'att.save': ['Save', 'Enregistrer', 'حفظ'],
    'att.close': ['Close', 'Fermer', 'إغلاق'],
    'att.all_present': ['All present', 'Tous présents', 'الكل حاضر'],
    'att.add_player': ['Add a player', 'Ajouter un joueur', 'إضافة لاعب'],
    'att.remove': ['Remove', 'Retirer', 'إزالة'],
    'att.not_saved': ['Not recorded yet: everyone is shown as present until you save.', "Pas encore saisie : tout le monde est présent jusqu'à l'enregistrement.", 'لم يسجل بعد: الجميع حاضر حتى الحفظ.'],
    'att.marked': ['{n} marked', '{n} saisis', '{n} مسجل'],
    'att.grid_help': ['Codes: P present · R15 late 15 min · D10 left early 10 min · B present, not training · AE absent excused · AN absent unexcused. An empty cell counts as present when the column is saved.', 'Codes : P présent · R15 retard 15 min · D10 parti 10 min plus tôt · B présent sans entraînement · AE absent justifié · AN absent non justifié. Une case vide compte comme présent à l’enregistrement de la colonne.', 'الرموز: P حاضر · R15 متأخر 15 دقيقة · D10 غادر مبكراً 10 دقائق · B حاضر دون تدريب · AE غائب بعذر · AN غائب بدون عذر. الخانة الفارغة تُحسب حضوراً عند حفظ العمود.'],
    'att.no_sessions': ['No sessions this month.', 'Aucune séance ce mois-ci.', 'لا توجد حصص هذا الشهر.'],
    'att.schedules': ['Weekly schedule', 'Horaire hebdomadaire', 'التوقيت الأسبوعي'],
    'att.weekday': ['Day', 'Jour', 'اليوم'],
    'att.valid_from': ['From', 'Du', 'من'],
    'att.valid_to': ['Until', 'Au', 'إلى'],
    'att.add': ['Add', 'Ajouter', 'إضافة'],
    'att.edit': ['Edit', 'Modifier', 'تعديل'],
    'att.delete': ['Delete', 'Supprimer', 'حذف'],
    'att.confirm_delete': ['Delete this item?', 'Supprimer cet élément ?', 'حذف هذا العنصر؟'],
    'att.closures': ['Club closures', 'Fermetures du club', 'فترات إغلاق النادي'],
    'att.start_date': ['Start date', 'Date de début', 'تاريخ البداية'],
    'att.end_date': ['End date', 'Date de fin', 'تاريخ النهاية'],
    'att.targets': ['Pre-season targets', 'Objectifs de préparation', 'أهداف التحضير البدني'],
    'att.season': ['Season', 'Saison', 'الموسم'],
    'att.target_count': ['Sessions planned', 'Séances prévues', 'الحصص المبرمجة'],
    'att.points': ['Points per status', 'Points par statut', 'النقاط حسب الحالة'],
    'att.rules': ['Discipline rules', 'Règles de discipline', 'قواعد الانضباط'],
    'att.lates_per_unexcused': ['Lates counted as one unexcused absence (0 = off)', 'Retards comptés comme une absence non justifiée (0 = désactivé)', 'عدد التأخرات التي تعادل غياباً بدون عذر (0 = معطل)'],
    'att.late_minutes_as_absent': ['Late beyond this many minutes counts as absent (0 = off)', 'Retard au-delà de ces minutes compté comme absence (0 = désactivé)', 'التأخر بعد هذه الدقائق يعتبر غياباً (0 = معطل)'],
    'att.alerts': ['Alerts', 'Alertes', 'التنبيهات'],
    'att.min_score_pct': ['Alert below this score (%)', 'Alerte sous ce score (%)', 'تنبيه تحت هذه النسبة (%)'],
    'att.unexcused_streak': ['Alert after this many unexcused absences in a row', "Alerte après ce nombre d'absences non justifiées d'affilée", 'تنبيه بعد هذا العدد من الغيابات المتتالية بدون عذر'],
    'att.error.invalid_code': ['Unknown code', 'Code inconnu', 'رمز غير معروف'],
    'att.error.cancelled': ['This session is cancelled.', 'Cette séance est annulée.', 'هذه الحصة ملغاة.'],
    'att.error.duplicate': ['This category already has a session at that date and time.', 'Cette catégorie a déjà une séance à cette date et heure.', 'لهذه الفئة حصة في نفس التاريخ والوقت.'],
};

['en', 'fr', 'ar'].forEach((locale, i) => {
    const file = `resources/js/i18n/${locale}.json`;
    const data = JSON.parse(readFileSync(file, 'utf8'));
    for (const [key, values] of Object.entries(keys)) {
        if (key in data) throw new Error(`${locale}: ${key} already exists`);
        data[key] = values[i];
    }
    writeFileSync(file, JSON.stringify(data, null, 4) + '\n');
});
```

Run and clean up:
```bash
node att-i18n.mjs && rm att-i18n.mjs
git diff --stat resources/js/i18n
```
Expected: only insertions in the three files, 101 lines each (100 keys, plus the previous last line gaining a comma).

- [ ] **Step 8: Verify translations resolve**

Run: `npm run i18n:check && php artisan test --filter=FlashTranslationTest`
Expected: both pass. The new flash keys are not emitted yet, so this confirms nothing broke.

- [ ] **Step 9: Commit**

```bash
git add app/Models/Role.php config/permissions.php app/Services/Activity/ActivityAction.php database/migrations/2026_09_29_100002_grant_attendance_permission.php resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json tests/Feature/AttendancePermissionTest.php
git commit -m "feat(attendance): attendance permission module, activity codes, translations

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Settings store and grid code parser

**Files:**
- Create: `app/Support/AttendanceSettings.php`, `app/Services/Attendance/AttendanceCode.php`
- Test: `tests/Feature/AttendanceSettingsTest.php`, `tests/Unit/AttendanceCodeTest.php`

**Interfaces:**
- Produces: `AttendanceSettings::DEFAULTS`, `AttendanceSettings::get(): array`, `AttendanceSettings::save(array $values): void`.
- Produces: `AttendanceCode::parse(?string $code): ?array{status: AttendanceStatus, minutes: ?int}`, where `null` means invalid and an empty string or `null` means present.
- Produces: `AttendanceCode::format(AttendanceStatus $status, ?int $minutes): string`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/AttendanceCodeTest.php`:
```php
<?php

namespace Tests\Unit;

use App\Enums\AttendanceStatus;
use App\Services\Attendance\AttendanceCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AttendanceCodeTest extends TestCase
{
    public static function valid(): array
    {
        return [
            'empty is present' => ['', AttendanceStatus::Present, null],
            'null is present' => [null, AttendanceStatus::Present, null],
            'P' => ['p', AttendanceStatus::Present, null],
            'late' => ['R15', AttendanceStatus::Late, 15],
            'late spaced' => [' r 5 ', AttendanceStatus::Late, 5],
            'left early' => ['D10', AttendanceStatus::LeftEarly, 10],
            'not training' => ['B', AttendanceStatus::NotTraining, null],
            'excused' => ['ae', AttendanceStatus::AbsentExcused, null],
            'unexcused' => ['AN', AttendanceStatus::AbsentUnexcused, null],
        ];
    }

    #[Test]
    #[DataProvider('valid')]
    public function it_parses_codes(?string $code, AttendanceStatus $status, ?int $minutes): void
    {
        $this->assertSame(['status' => $status, 'minutes' => $minutes], AttendanceCode::parse($code));
    }

    #[Test]
    public function it_rejects_unknown_codes_and_late_without_minutes(): void
    {
        foreach (['X', 'R', 'R0', 'D', 'R1000', 'A'] as $code) {
            $this->assertNull(AttendanceCode::parse($code), $code);
        }
    }

    #[Test]
    public function format_round_trips(): void
    {
        foreach (['P', 'R15', 'D10', 'B', 'AE', 'AN'] as $code) {
            $parsed = AttendanceCode::parse($code);
            $this->assertSame($code, AttendanceCode::format($parsed['status'], $parsed['minutes']));
        }
    }
}
```

`tests/Feature/AttendanceSettingsTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\WebsiteConfig;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendanceSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function defaults_apply_until_saved_and_other_settings_survive(): void
    {
        $this->assertSame(AttendanceSettings::DEFAULTS, AttendanceSettings::get());

        $config = WebsiteConfig::singleton();
        $config->settings = ['seasonStartMonth' => 9] + ($config->settings ?? []);
        $config->save();

        AttendanceSettings::save(['points' => ['late' => 0.5], 'alerts' => ['min_score_pct' => 70]]);

        $settings = AttendanceSettings::get();
        $this->assertSame(0.5, $settings['points']['late']);
        $this->assertSame(1, $settings['points']['present']);
        $this->assertSame(70, $settings['alerts']['min_score_pct']);
        $this->assertSame(9, WebsiteConfig::singleton()->settings['seasonStartMonth']);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter="AttendanceCodeTest|AttendanceSettingsTest"`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

`app/Services/Attendance/AttendanceCode.php`:
```php
<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;

/**
 * The short codes written on the paper sheet and typed into the month grid:
 * P, R<min>, D<min>, B, AE, AN. An empty cell means present.
 */
final class AttendanceCode
{
    private const SIMPLE = [
        'P' => AttendanceStatus::Present,
        'B' => AttendanceStatus::NotTraining,
        'AE' => AttendanceStatus::AbsentExcused,
        'AN' => AttendanceStatus::AbsentUnexcused,
    ];

    /** @return array{status: AttendanceStatus, minutes: ?int}|null null when the code is not valid */
    public static function parse(?string $code): ?array
    {
        $code = strtoupper(str_replace(' ', '', (string) $code));

        if ($code === '') {
            return ['status' => AttendanceStatus::Present, 'minutes' => null];
        }

        if (isset(self::SIMPLE[$code])) {
            return ['status' => self::SIMPLE[$code], 'minutes' => null];
        }

        if (preg_match('/^([RD])(\d{1,3})$/', $code, $m) && (int) $m[2] > 0) {
            return [
                'status' => $m[1] === 'R' ? AttendanceStatus::Late : AttendanceStatus::LeftEarly,
                'minutes' => (int) $m[2],
            ];
        }

        return null;
    }

    public static function format(AttendanceStatus $status, ?int $minutes): string
    {
        return match ($status) {
            AttendanceStatus::Late => 'R'.$minutes,
            AttendanceStatus::LeftEarly => 'D'.$minutes,
            default => array_search($status, self::SIMPLE, true),
        };
    }
}
```

`app/Support/AttendanceSettings.php`:
```php
<?php

namespace App\Support;

use App\Models\WebsiteConfig;

/**
 * Attendance scoring settings, kept in WebsiteConfig.settings['attendance'].
 * Points and rules only change the score used for ranking and alerts; the
 * status counts shown in reports are always the raw marks.
 */
final class AttendanceSettings
{
    public const DEFAULTS = [
        'points' => [
            'present' => 1, 'late' => 0.75, 'left_early' => 0.75, 'not_training' => 0.5,
            'absent_excused' => 0, 'absent_unexcused' => -1,
        ],
        'rules' => ['lates_per_unexcused' => 3, 'late_minutes_as_absent' => 30],
        'alerts' => ['min_score_pct' => 60, 'unexcused_streak' => 3],
    ];

    public static function get(): array
    {
        $stored = (WebsiteConfig::singleton()->settings ?? [])['attendance'] ?? [];

        return array_replace_recursive(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    public static function save(array $values): void
    {
        $config = WebsiteConfig::singleton();
        $settings = $config->settings ?? [];
        $settings['attendance'] = array_replace_recursive(self::get(), $values);
        $config->settings = $settings;
        $config->save();
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter="AttendanceCodeTest|AttendanceSettingsTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/AttendanceSettings.php app/Services/Attendance/AttendanceCode.php tests/Unit/AttendanceCodeTest.php tests/Feature/AttendanceSettingsTest.php
git commit -m "feat(attendance): scoring settings and grid code parser

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Session generator

**Files:**
- Create: `app/Services/Attendance/SessionGenerator.php`
- Test: `tests/Feature/SessionGeneratorTest.php`

**Interfaces:**
- Consumes: `TrainingSchedule`, `ClubClosure`, `TrainingSession` (Task 1).
- Produces: `SessionGenerator::forMonth(int $categoryId, int $year, int $month): int`, which returns the number of sessions created.

- [ ] **Step 1: Write the failing test**

`tests/Feature/SessionGeneratorTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ClubClosure;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Attendance\SessionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class SessionGeneratorTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function schedule(int $categoryId, int $weekday, array $extra = []): TrainingSchedule
    {
        return TrainingSchedule::create($extra + [
            'category_id' => $categoryId, 'weekday' => $weekday, 'start_time' => '18:00', 'end_time' => '19:30',
            'valid_from' => '2026-01-01',
        ]);
    }

    private function dates(int $categoryId): array
    {
        return TrainingSession::where('category_id', $categoryId)->orderBy('date')->pluck('date')->all();
    }

    #[Test]
    public function it_creates_one_planned_session_per_scheduled_weekday_and_is_idempotent(): void
    {
        $u15 = $this->category();
        $this->schedule($u15->id, 1); // Mondays
        $this->schedule($u15->id, 3); // Wednesdays

        // October 2026: Mondays 5,12,19,26 and Wednesdays 7,14,21,28.
        $this->assertSame(8, app(SessionGenerator::class)->forMonth($u15->id, 2026, 10));
        $this->assertSame(0, app(SessionGenerator::class)->forMonth($u15->id, 2026, 10));

        $this->assertSame(
            ['2026-10-05', '2026-10-07', '2026-10-12', '2026-10-14', '2026-10-19', '2026-10-21', '2026-10-26', '2026-10-28'],
            $this->dates($u15->id),
        );
        $first = TrainingSession::orderBy('date')->first();
        $this->assertSame(SessionKind::Regular, $first->kind);
        $this->assertSame(SessionState::Planned, $first->state);
        $this->assertSame('18:00', $first->start_time);
        $this->assertNotNull($first->schedule_id);
    }

    #[Test]
    public function closures_and_validity_ranges_are_respected(): void
    {
        $u15 = $this->category();
        $this->schedule($u15->id, 1, ['valid_to' => '2026-10-20']);
        $this->schedule($u15->id, 1, ['valid_from' => '2026-10-21', 'start_time' => '17:00']);
        ClubClosure::create(['start_date' => '2026-10-10', 'end_date' => '2026-10-13', 'reason' => 'Holiday']);

        app(SessionGenerator::class)->forMonth($u15->id, 2026, 10);

        $slots = TrainingSession::orderBy('date')->get()->map(fn ($s) => "$s->date $s->start_time")->all();
        $this->assertSame(['2026-10-05 18:00', '2026-10-19 18:00', '2026-10-26 17:00'], $slots);
    }

    #[Test]
    public function cancelled_and_moved_sessions_are_not_recreated(): void
    {
        $u15 = $this->category();
        $this->schedule($u15->id, 1);
        $generator = app(SessionGenerator::class);
        $generator->forMonth($u15->id, 2026, 10);

        TrainingSession::where('date', '2026-10-05')->update(['state' => SessionState::Cancelled->value, 'cancel_reason' => 'Rain']);
        TrainingSession::where('date', '2026-10-12')->update(['date' => '2026-10-13', 'moved_from' => '2026-10-12']);

        $this->assertSame(0, $generator->forMonth($u15->id, 2026, 10));
        $this->assertSame(['2026-10-05', '2026-10-13', '2026-10-19', '2026-10-26'], $this->dates($u15->id));
    }

    #[Test]
    public function another_category_is_untouched(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $this->schedule($u17->id, 1);

        $this->assertSame(0, app(SessionGenerator::class)->forMonth($u15->id, 2026, 10));
        $this->assertSame(0, TrainingSession::count());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=SessionGeneratorTest`
Expected: FAIL, class `SessionGenerator` not found.

- [ ] **Step 3: Implement**

`app/Services/Attendance/SessionGenerator.php`:
```php
<?php

namespace App\Services\Attendance;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ClubClosure;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns a category's weekly schedule into planned sessions, one month at a
 * time. Called whenever a month is opened (the desktop app has no reliable
 * scheduler), so it must be idempotent: the unique (category, date, start)
 * key ignores slots that already exist, including cancelled ones, and a slot
 * whose session was moved elsewhere (`moved_from`) is skipped.
 */
final class SessionGenerator
{
    public function forMonth(int $categoryId, int $year, int $month): int
    {
        $first = CarbonImmutable::create($year, $month, 1);
        $from = $first->toDateString();
        $to = $first->endOfMonth()->toDateString();

        $schedules = TrainingSchedule::where('category_id', $categoryId)
            ->where('valid_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from))
            ->get();

        if ($schedules->isEmpty()) {
            return 0;
        }

        $closures = ClubClosure::where('start_date', '<=', $to)->where('end_date', '>=', $from)->get(['start_date', 'end_date']);

        $moved = TrainingSession::whereIn('schedule_id', $schedules->modelKeys())
            ->whereNotNull('moved_from')
            ->get(['schedule_id', 'moved_from'])
            ->mapWithKeys(fn (TrainingSession $s) => ["{$s->schedule_id}|{$s->moved_from}" => true]);

        $now = now();
        $rows = [];

        for ($day = $first; $day->toDateString() <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();

            if ($closures->contains(fn (ClubClosure $c) => $c->start_date <= $date && $c->end_date >= $date)) {
                continue;
            }

            foreach ($schedules as $schedule) {
                if ($schedule->weekday !== $day->dayOfWeekIso
                    || $schedule->valid_from > $date
                    || ($schedule->valid_to !== null && $schedule->valid_to < $date)
                    || isset($moved["{$schedule->id}|{$date}"])) {
                    continue;
                }

                $rows[] = [
                    'category_id' => $categoryId,
                    'schedule_id' => $schedule->id,
                    'date' => $date,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'kind' => SessionKind::Regular->value,
                    'state' => SessionState::Planned->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $rows === [] ? 0 : DB::table('training_sessions')->insertOrIgnore($rows);
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php artisan test --filter=SessionGeneratorTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/SessionGenerator.php tests/Feature/SessionGeneratorTest.php
git commit -m "feat(attendance): generate planned sessions from weekly schedules

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Roster and mark recorder

**Files:**
- Create: `app/Services/Attendance/Roster.php`, `app/Services/Attendance/MarkRecorder.php`
- Test: `tests/Feature/MarkRecorderTest.php`

**Interfaces:**
- Consumes: models and enums (Task 1), `ActivityAction::ATTENDANCE_MARKED` (Task 2).
- Produces: `Roster::expected(int $categoryId, string $date): Collection<Player>` and `Roster::forSession(TrainingSession $session): Collection<Player>`. Both are ordered by lastname then firstname and select `id, firstname, lastname, category_id`.
- Produces: `MarkRecorder::save(TrainingSession $session, array $marks, ?User $user, ?array $log = null): int`. Each mark is `['player_id' => int, 'status' => string, 'minutes' => ?int, 'reason' => ?string, 'note' => ?string]`. `$log` holds the keys `coach`, `theme` and `notes`; `null` keeps the current log. It throws `ValidationException` (key `session`, message `att.error.cancelled`) on a cancelled session. It returns the number of marks.

- [ ] **Step 1: Write the failing test**

`tests/Feature/MarkRecorderTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\TrainingSession;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class MarkRecorderTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function session(Category $category, string $date = '2026-10-05'): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    #[Test]
    public function the_expected_roster_is_the_category_on_that_date(): void
    {
        $u15 = $this->category();
        $stays = $this->player($u15);
        $this->player($u15, ['archived' => true]);
        // Player::booted() clears left_at unless the status is "left" (seeded by migrations).
        $left = \App\Models\Player::leftStatusId();
        $this->player($u15, ['status_id' => $left, 'left_at' => '2026-10-01']);
        $leavesLater = $this->player($u15, ['status_id' => $left, 'left_at' => '2026-10-20']);
        $this->player($this->category('U17'));

        $ids = app(Roster::class)->expected($u15->id, '2026-10-05')->pluck('id')->all();

        $this->assertSame([$stays->id, $leavesLater->id], $ids);
    }

    #[Test]
    public function saving_stores_marks_normalises_fields_and_holds_the_session(): void
    {
        $u15 = $this->category();
        [$a, $b, $c] = [$this->player($u15), $this->player($u15), $this->player($u15)];
        $session = $this->session($u15);
        $admin = $this->admin();

        $count = app(MarkRecorder::class)->save($session, [
            ['player_id' => $a->id, 'status' => 'late', 'minutes' => 12, 'reason' => 'injury'],
            ['player_id' => $b->id, 'status' => 'absent_excused', 'minutes' => 5, 'reason' => 'school', 'note' => 'Exam'],
            ['player_id' => $c->id, 'status' => 'present', 'note' => ''],
        ], $admin, ['coach' => 'Karim', 'theme' => 'Endurance', 'notes' => null]);

        $this->assertSame(3, $count);
        $marks = $session->attendances()->get()->keyBy('player_id');
        $this->assertSame(12, $marks[$a->id]->minutes);
        $this->assertNull($marks[$a->id]->reason);
        $this->assertNull($marks[$b->id]->minutes);
        $this->assertSame(AbsenceReason::School, $marks[$b->id]->reason);
        $this->assertSame('Exam', $marks[$b->id]->note);
        $this->assertNull($marks[$c->id]->note);
        $this->assertSame($admin->id, $marks[$c->id]->recorded_by);

        $session->refresh();
        $this->assertSame(SessionState::Held, $session->state);
        $this->assertSame('Karim', $session->coach);
        $this->assertSame(1, ActivityLog::where('action', 'attendance_marked')->count());
    }

    #[Test]
    public function saving_again_updates_marks_and_drops_removed_players(): void
    {
        $u15 = $this->category();
        [$a, $b] = [$this->player($u15), $this->player($u15)];
        $session = $this->session($u15);
        $recorder = app(MarkRecorder::class);

        $recorder->save($session, [
            ['player_id' => $a->id, 'status' => 'present'],
            ['player_id' => $b->id, 'status' => 'present'],
        ], null, ['coach' => 'Karim']);
        $recorder->save($session, [['player_id' => $a->id, 'status' => 'absent_unexcused']], null);

        $this->assertSame([$a->id], $session->attendances()->pluck('player_id')->all());
        $this->assertSame(AttendanceStatus::AbsentUnexcused, $session->attendances()->first()->status);
        $this->assertSame('Karim', $session->fresh()->coach); // null log keeps it
    }

    #[Test]
    public function once_marked_the_session_roster_is_frozen(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $session = $this->session($u15);
        app(MarkRecorder::class)->save($session, [['player_id' => $a->id, 'status' => 'present']], null);

        $newcomer = $this->player($u15);
        $a->update(['category_id' => $this->category('U17')->id]);

        $this->assertSame([$a->id], app(Roster::class)->forSession($session)->pluck('id')->all());
        $this->assertSame([$newcomer->id], app(Roster::class)->forSession($this->session($u15, '2026-10-06'))->pluck('id')->all());
    }

    #[Test]
    public function a_cancelled_session_cannot_be_marked(): void
    {
        $u15 = $this->category();
        $session = $this->session($u15);
        $session->update(['state' => SessionState::Cancelled, 'cancel_reason' => 'Rain']);

        $this->expectException(ValidationException::class);
        app(MarkRecorder::class)->save($session, [['player_id' => $this->player($u15)->id, 'status' => 'present']], null);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=MarkRecorderTest`
Expected: FAIL, class `Roster` not found.

- [ ] **Step 3: Implement**

`app/Services/Attendance/Roster.php`:
```php
<?php

namespace App\Services\Attendance;

use App\Models\Player;
use App\Models\TrainingSession;
use Illuminate\Support\Collection;

/**
 * Who is expected at a session. Before the first save it is the category's
 * members on that date; after, it is exactly the saved marks. Freezing it
 * keeps history fair: later category changes, departures and newcomers never
 * rewrite a past session.
 */
final class Roster
{
    private const COLUMNS = ['id', 'firstname', 'lastname', 'category_id'];

    /** @return Collection<int, Player> */
    public function expected(int $categoryId, string $date): Collection
    {
        return Player::where('category_id', $categoryId)
            ->where('archived', false)
            ->where(fn ($q) => $q->whereNull('left_at')->orWhereDate('left_at', '>', $date))
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::COLUMNS);
    }

    /** @return Collection<int, Player> */
    public function forSession(TrainingSession $session): Collection
    {
        if (! $session->attendances()->exists()) {
            return $this->expected($session->category_id, $session->date);
        }

        return Player::whereIn('id', $session->attendances()->select('player_id'))
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::COLUMNS);
    }
}
```

`app/Services/Attendance/MarkRecorder.php`:
```php
<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a session's full set of marks: the given players are the roster
 * (anyone missing is removed), fields a status does not take are cleared,
 * and the session becomes held.
 */
final class MarkRecorder
{
    private const LOG_FIELDS = ['coach', 'theme', 'notes'];

    public function save(TrainingSession $session, array $marks, ?User $user, ?array $log = null): int
    {
        if ($session->state === SessionState::Cancelled) {
            throw ValidationException::withMessages(['session' => 'att.error.cancelled']);
        }

        return DB::transaction(function () use ($session, $marks, $user, $log) {
            $playerIds = [];

            foreach ($marks as $mark) {
                $status = AttendanceStatus::from($mark['status']);
                $playerIds[] = (int) $mark['player_id'];

                Attendance::updateOrCreate(
                    ['training_session_id' => $session->id, 'player_id' => (int) $mark['player_id']],
                    [
                        'status' => $status,
                        'minutes' => $status->takesMinutes() ? (int) $mark['minutes'] : null,
                        'reason' => $status->takesReason() ? ($mark['reason'] ?? null) : null,
                        'note' => ($mark['note'] ?? null) ?: null,
                        'recorded_by' => $user?->id,
                    ],
                );
            }

            $session->attendances()->whereNotIn('player_id', $playerIds)->delete();

            $session->state = SessionState::Held;
            if ($log !== null) {
                $session->fill(array_intersect_key($log, array_flip(self::LOG_FIELDS)));
            }
            $session->save();

            ActivityRecorder::record($user, ActivityAction::ATTENDANCE_MARKED, $session, ['count' => count($playerIds)]);

            return count($playerIds);
        });
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php artisan test --filter=MarkRecorderTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/Roster.php app/Services/Attendance/MarkRecorder.php tests/Feature/MarkRecorderTest.php
git commit -m "feat(attendance): roster snapshot and mark recorder

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Attendance settings screen

**Files:**
- Create: `app/Http/Controllers/AttendanceSettingsController.php`, `resources/js/Pages/Attendance/Settings.vue`
- Modify: `routes/web.php` (inside the `permission` middleware group, after the board routes block near line 284)
- Test: `tests/Feature/AttendanceSettingsPageTest.php`

**Interfaces:**
- Consumes: `AttendanceSettings`, `TrainingSession::unmarkedPlanned()`, `Season`.
- Produces the routes `attendance.settings` (GET), `attendance.settings.update` (PUT), `attendance.schedules.store|update|destroy`, `attendance.closures.store|destroy` and `attendance.preseason-targets.store`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceSettingsPageTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ClubClosure;
use App\Models\PreseasonTarget;
use App\Models\Role;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceSettingsPageTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function the_page_lists_everything(): void
    {
        $u15 = $this->category();
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 2, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);

        $this->actingAs($this->admin())->get(route('attendance.settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Settings')
                ->has('schedules', 1)->has('categories', 1)->has('seasons', 2)
                ->where('settings.points.present', 1));
    }

    #[Test]
    public function schedules_are_validated_created_and_edits_regenerate_future_planned_sessions(): void
    {
        Carbon::setTestNow('2026-10-10');
        $u15 = $this->category();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.schedules.store'), [
            'category_id' => $u15->id, 'weekday' => 1, 'start_time' => '19:00', 'end_time' => '18:00', 'valid_from' => '2026-09-01',
        ])->assertSessionHasErrors('end_time');

        $this->actingAs($admin)->post(route('attendance.schedules.store'), [
            'category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01',
        ])->assertSessionHas('success', 'flash.training_schedule_saved');
        $schedule = TrainingSchedule::sole();

        $base = ['category_id' => $u15->id, 'schedule_id' => $schedule->id, 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => SessionKind::Regular];
        $past = TrainingSession::create($base + ['date' => '2026-10-05', 'state' => SessionState::Planned]);
        $future = TrainingSession::create($base + ['date' => '2026-10-12', 'state' => SessionState::Planned]);
        $held = TrainingSession::create($base + ['date' => '2026-10-19', 'state' => SessionState::Held]);

        $this->actingAs($admin)->put(route('attendance.schedules.update', $schedule), [
            'category_id' => $u15->id, 'weekday' => 1, 'start_time' => '17:00', 'end_time' => '18:30', 'valid_from' => '2026-09-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame('17:00', $schedule->fresh()->start_time);
        $this->assertModelExists($past);
        $this->assertModelMissing($future);
        $this->assertModelExists($held);
    }

    #[Test]
    public function a_closure_removes_unmarked_planned_sessions_inside_it(): void
    {
        $u15 = $this->category();
        $base = ['category_id' => $u15->id, 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned];
        $inside = TrainingSession::create($base + ['date' => '2026-12-22']);
        $outside = TrainingSession::create($base + ['date' => '2027-01-05']);

        $this->actingAs($this->admin())->post(route('attendance.closures.store'), [
            'start_date' => '2026-12-20', 'end_date' => '2027-01-03', 'reason' => 'Winter break',
        ])->assertSessionHas('success', 'flash.club_closure_saved');

        $this->assertModelMissing($inside);
        $this->assertModelExists($outside);

        $this->actingAs($this->admin())->delete(route('attendance.closures.destroy', ClubClosure::sole()))
            ->assertSessionHas('success', 'flash.club_closure_deleted');
        $this->assertSame(0, ClubClosure::count());
    }

    #[Test]
    public function preseason_target_and_scoring_settings_are_saved(): void
    {
        $u15 = $this->category();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.preseason-targets.store'), ['category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 12]);
        $this->actingAs($admin)->post(route('attendance.preseason-targets.store'), ['category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 14])
            ->assertSessionHas('success', 'flash.preseason_target_saved');
        $this->assertSame(14, PreseasonTarget::sole()->target_count);

        $payload = AttendanceSettings::DEFAULTS;
        $payload['points']['late'] = 0.5;
        $payload['alerts']['min_score_pct'] = 75;
        $this->actingAs($admin)->put(route('attendance.settings.update'), $payload)
            ->assertSessionHas('success', 'flash.attendance_settings_saved');
        $this->assertEquals(0.5, AttendanceSettings::get()['points']['late']);
        $this->assertSame(75, AttendanceSettings::get()['alerts']['min_score_pct']);
    }

    #[Test]
    public function view_only_users_cannot_open_settings(): void
    {
        $role = Role::factory()->create(['permissions' => ['attendance' => ['view']]]);
        $viewer = User::factory()->create(['privileges' => ['user'], 'role_id' => $role->id]);

        $this->actingAs($viewer)->get(route('attendance.settings'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=AttendanceSettingsPageTest`
Expected: FAIL, `Route [attendance.settings] not defined`.

- [ ] **Step 3: Add the routes**

`routes/web.php`: add the `use` lines at the top with the other controller imports:
```php
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceGridController;
use App\Http\Controllers\AttendanceSettingsController;
use App\Http\Controllers\TrainingSessionController;
```
Inside the `Route::middleware(['auth', 'verified', 'approved', 'permission'])->group(...)`, right after the board routes block, add the settings routes now. Tasks 7–9 add the other routes to this same block.
```php
        // Player attendance at category trainings (module `attendance`).
        Route::get('/attendance/settings', [AttendanceSettingsController::class, 'index'])->name('attendance.settings');
        Route::put('/attendance/settings', [AttendanceSettingsController::class, 'update'])->name('attendance.settings.update');
        Route::post('/attendance/schedules', [AttendanceSettingsController::class, 'storeSchedule'])->name('attendance.schedules.store');
        Route::put('/attendance/schedules/{schedule}', [AttendanceSettingsController::class, 'updateSchedule'])->name('attendance.schedules.update');
        Route::delete('/attendance/schedules/{schedule}', [AttendanceSettingsController::class, 'destroySchedule'])->name('attendance.schedules.destroy');
        Route::post('/attendance/closures', [AttendanceSettingsController::class, 'storeClosure'])->name('attendance.closures.store');
        Route::delete('/attendance/closures/{closure}', [AttendanceSettingsController::class, 'destroyClosure'])->name('attendance.closures.destroy');
        Route::post('/attendance/preseason-targets', [AttendanceSettingsController::class, 'storeTarget'])->name('attendance.preseason-targets.store');
```
Check where the board routes sit (inside a nested group or not) with `grep -n "board.calendar" routes/web.php`, and put the block at the same nesting level.

- [ ] **Step 4: Write the controller**

`app/Http/Controllers/AttendanceSettingsController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Models\Category;
use App\Models\ClubClosure;
use App\Models\PreseasonTarget;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Support\AttendanceSettings;
use App\Support\Season;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceSettingsController extends Controller
{
    public function index(): Response
    {
        $current = Season::current();

        return Inertia::render('Attendance/Settings', [
            'categories' => Category::orderBy('id')->get()
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])->values(),
            'schedules' => TrainingSchedule::orderBy('category_id')->orderBy('weekday')->orderBy('start_time')
                ->get(['id', 'category_id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_to']),
            'closures' => ClubClosure::orderByDesc('start_date')->get(['id', 'start_date', 'end_date', 'reason']),
            'targets' => PreseasonTarget::get(['category_id', 'season_start_year', 'target_count']),
            'seasons' => collect([$current, Season::forStartYear($current->startYear + 1)])
                ->map(fn (Season $s) => ['start_year' => $s->startYear, 'label' => $s->label()])->values(),
            'settings' => AttendanceSettings::get(),
            'statuses' => AttendanceStatus::values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [
            'rules.lates_per_unexcused' => ['required', 'integer', 'between:0,20'],
            'rules.late_minutes_as_absent' => ['required', 'integer', 'between:0,240'],
            'alerts.min_score_pct' => ['required', 'integer', 'between:0,100'],
            'alerts.unexcused_streak' => ['required', 'integer', 'between:0,20'],
        ];
        foreach (AttendanceStatus::values() as $status) {
            $rules["points.$status"] = ['required', 'numeric', 'between:-5,5'];
        }
        $data = $request->validate($rules);

        AttendanceSettings::save([
            'points' => array_map('floatval', $data['points']),
            'rules' => array_map('intval', $data['rules']),
            'alerts' => array_map('intval', $data['alerts']),
        ]);

        return back()->with('success', 'flash.attendance_settings_saved');
    }

    public function storeSchedule(Request $request): RedirectResponse
    {
        TrainingSchedule::create($this->validateSchedule($request));

        return back()->with('success', 'flash.training_schedule_saved');
    }

    public function updateSchedule(Request $request, TrainingSchedule $schedule): RedirectResponse
    {
        $schedule->update($this->validateSchedule($request));
        $this->purgeFuturePlanned($schedule);

        return back()->with('success', 'flash.training_schedule_saved');
    }

    public function destroySchedule(TrainingSchedule $schedule): RedirectResponse
    {
        $this->purgeFuturePlanned($schedule);
        $schedule->delete();

        return back()->with('success', 'flash.training_schedule_deleted');
    }

    public function storeClosure(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:100'],
        ]);

        ClubClosure::create($data);
        TrainingSession::unmarkedPlanned()
            ->where('kind', SessionKind::Regular->value)
            ->whereBetween('date', [$data['start_date'], $data['end_date']])
            ->delete();

        return back()->with('success', 'flash.club_closure_saved');
    }

    public function destroyClosure(ClubClosure $closure): RedirectResponse
    {
        $closure->delete(); // the next month view regenerates the freed dates

        return back()->with('success', 'flash.club_closure_deleted');
    }

    public function storeTarget(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'season_start_year' => ['required', 'integer', 'between:2000,2100'],
            'target_count' => ['required', 'integer', 'between:0,200'],
        ]);

        PreseasonTarget::updateOrCreate(
            ['category_id' => $data['category_id'], 'season_start_year' => $data['season_start_year']],
            ['target_count' => $data['target_count']],
        );

        return back()->with('success', 'flash.preseason_target_saved');
    }

    private function validateSchedule(Request $request): array
    {
        return $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ]);
    }

    /** Upcoming generated sessions nobody marked are rebuilt from the new schedule on the next month view. */
    private function purgeFuturePlanned(TrainingSchedule $schedule): void
    {
        TrainingSession::unmarkedPlanned()
            ->where('schedule_id', $schedule->id)
            ->where('date', '>=', now()->toDateString())
            ->delete();
    }
}
```

- [ ] **Step 5: Write the page**

`resources/js/Pages/Attendance/Settings.vue`:
```vue
<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    schedules: { type: Array, default: () => [] },
    closures: { type: Array, default: () => [] },
    targets: { type: Array, default: () => [] },
    seasons: { type: Array, default: () => [] },
    settings: { type: Object, required: true },
    statuses: { type: Array, default: () => [] },
});
const { t, locale } = useI18n();
const lang = computed(() => (locale.value === 'ar' ? 'ar' : locale.value));
const today = new Date().toISOString().slice(0, 10);

// ISO weekday 1..7 -> localized name (2024-01-01 is a Monday).
const weekdayName = (n) => new Date(Date.UTC(2024, 0, n)).toLocaleDateString(lang.value, { weekday: 'long', timeZone: 'UTC' });
const categoryName = (id) => props.categories.find((c) => c.id === id)?.name ?? '';

const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
const card = 'rounded-xl bg-white p-4 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';

// ---- Weekly schedule ----
const editingId = ref(null);
const scheduleForm = useForm({ category_id: props.categories[0]?.id ?? null, weekday: 1, start_time: '18:00', end_time: '19:30', valid_from: today, valid_to: '' });
function editSchedule(s) {
    editingId.value = s.id;
    Object.assign(scheduleForm, { category_id: s.category_id, weekday: s.weekday, start_time: s.start_time, end_time: s.end_time, valid_from: s.valid_from, valid_to: s.valid_to ?? '' });
}
function resetSchedule() {
    editingId.value = null;
    scheduleForm.reset();
    scheduleForm.clearErrors();
}
function submitSchedule() {
    const opts = { preserveScroll: true, onSuccess: resetSchedule };
    scheduleForm.transform((d) => ({ ...d, valid_to: d.valid_to || null }));
    editingId.value ? scheduleForm.put(route('attendance.schedules.update', editingId.value), opts) : scheduleForm.post(route('attendance.schedules.store'), opts);
}
function destroy(name, id) {
    if (window.confirm(t('att.confirm_delete'))) router.delete(route(name, id), { preserveScroll: true });
}

// ---- Closures ----
const closureForm = useForm({ start_date: today, end_date: today, reason: '' });
const submitClosure = () => closureForm.post(route('attendance.closures.store'), { preserveScroll: true, onSuccess: () => closureForm.reset() });

// ---- Pre-season targets ----
const targetForm = useForm({ category_id: props.categories[0]?.id ?? null, season_start_year: props.seasons[0]?.start_year, target_count: 0 });
watch(() => [targetForm.category_id, targetForm.season_start_year], ([c, y]) => {
    targetForm.target_count = props.targets.find((x) => x.category_id === c && x.season_start_year === y)?.target_count ?? 0;
}, { immediate: true });
const submitTarget = () => targetForm.post(route('attendance.preseason-targets.store'), { preserveScroll: true });

// ---- Points, rules, alerts ----
const settingsForm = useForm(JSON.parse(JSON.stringify(props.settings)));
const submitSettings = () => settingsForm.put(route('attendance.settings.update'), { preserveScroll: true });
</script>

<template>
    <Head :title="t('att.settings')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('attendance.index')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 rtl:rotate-180"><Icon name="back" /></Link>
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('att.settings') }}</h1>
            </div>
        </template>

        <div class="grid gap-4 lg:grid-cols-2">
            <!-- Weekly schedule -->
            <section :class="[card, 'lg:col-span-2']">
                <h2 class="mb-3 font-bold text-slate-900 dark:text-slate-100">{{ t('att.schedules') }}</h2>
                <form class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="submitSchedule">
                    <label class="text-xs text-slate-500">{{ t('att.category') }}
                        <select v-model="scheduleForm.category_id" :class="[input, 'block']"><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                    </label>
                    <label class="text-xs text-slate-500">{{ t('att.weekday') }}
                        <select v-model="scheduleForm.weekday" :class="[input, 'block']"><option v-for="n in 7" :key="n" :value="n">{{ weekdayName(n) }}</option></select>
                    </label>
                    <label class="text-xs text-slate-500">{{ t('att.start') }}<input v-model="scheduleForm.start_time" type="time" :class="[input, 'block']" /></label>
                    <label class="text-xs text-slate-500">{{ t('att.end') }}<input v-model="scheduleForm.end_time" type="time" :class="[input, 'block']" /></label>
                    <label class="text-xs text-slate-500">{{ t('att.valid_from') }}<input v-model="scheduleForm.valid_from" type="date" :class="[input, 'block']" /></label>
                    <label class="text-xs text-slate-500">{{ t('att.valid_to') }}<input v-model="scheduleForm.valid_to" type="date" :class="[input, 'block']" /></label>
                    <button type="submit" :disabled="scheduleForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ editingId ? t('att.save') : t('att.add') }}</button>
                    <button v-if="editingId" type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="resetSchedule">{{ t('att.close') }}</button>
                    <div class="w-full"><InputError v-for="(e, k) in scheduleForm.errors" :key="k" :message="e" /></div>
                </form>
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="s in schedules" :key="s.id" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="py-2 font-medium">{{ categoryName(s.category_id) }}</td>
                            <td>{{ weekdayName(s.weekday) }}</td>
                            <td dir="ltr" class="text-start">{{ s.start_time }}–{{ s.end_time }}</td>
                            <td class="text-slate-500">{{ s.valid_from }} → {{ s.valid_to ?? '…' }}</td>
                            <td class="text-end">
                                <button class="p-1 text-slate-400 hover:text-primary-600" :title="t('att.edit')" @click="editSchedule(s)"><Icon name="pencil" /></button>
                                <button class="p-1 text-slate-400 hover:text-rose-600" :title="t('att.delete')" @click="destroy('attendance.schedules.destroy', s.id)"><Icon name="trash" /></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- Closures -->
            <section :class="card">
                <h2 class="mb-3 font-bold text-slate-900 dark:text-slate-100">{{ t('att.closures') }}</h2>
                <form class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="submitClosure">
                    <label class="text-xs text-slate-500">{{ t('att.start_date') }}<input v-model="closureForm.start_date" type="date" :class="[input, 'block']" /></label>
                    <label class="text-xs text-slate-500">{{ t('att.end_date') }}<input v-model="closureForm.end_date" type="date" :class="[input, 'block']" /></label>
                    <label class="flex-1 text-xs text-slate-500">{{ t('att.reason') }}<input v-model="closureForm.reason" type="text" maxlength="100" :class="[input, 'block w-full']" /></label>
                    <button type="submit" :disabled="closureForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.add') }}</button>
                    <div class="w-full"><InputError v-for="(e, k) in closureForm.errors" :key="k" :message="e" /></div>
                </form>
                <ul class="space-y-1 text-sm">
                    <li v-for="c in closures" :key="c.id" class="flex items-center justify-between border-t border-slate-100 pt-1 dark:border-slate-800">
                        <span><b>{{ c.reason }}</b> · {{ c.start_date }} → {{ c.end_date }}</span>
                        <button class="p-1 text-slate-400 hover:text-rose-600" :title="t('att.delete')" @click="destroy('attendance.closures.destroy', c.id)"><Icon name="trash" /></button>
                    </li>
                </ul>
            </section>

            <!-- Pre-season targets -->
            <section :class="card">
                <h2 class="mb-3 font-bold text-slate-900 dark:text-slate-100">{{ t('att.targets') }}</h2>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="submitTarget">
                    <label class="text-xs text-slate-500">{{ t('att.category') }}
                        <select v-model="targetForm.category_id" :class="[input, 'block']"><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                    </label>
                    <label class="text-xs text-slate-500">{{ t('att.season') }}
                        <select v-model="targetForm.season_start_year" :class="[input, 'block']"><option v-for="s in seasons" :key="s.start_year" :value="s.start_year">{{ s.label }}</option></select>
                    </label>
                    <label class="text-xs text-slate-500">{{ t('att.target_count') }}<input v-model.number="targetForm.target_count" type="number" min="0" max="200" :class="[input, 'block w-24']" /></label>
                    <button type="submit" :disabled="targetForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
                    <div class="w-full"><InputError v-for="(e, k) in targetForm.errors" :key="k" :message="e" /></div>
                </form>
            </section>

            <!-- Points, rules, alerts -->
            <section :class="[card, 'lg:col-span-2']">
                <form class="grid gap-4 md:grid-cols-3" @submit.prevent="submitSettings">
                    <div>
                        <h2 class="mb-2 font-bold text-slate-900 dark:text-slate-100">{{ t('att.points') }}</h2>
                        <label v-for="s in statuses" :key="s" class="mb-1 flex items-center justify-between gap-2 text-sm">
                            {{ t(`att.status.${s}`) }}
                            <input v-model.number="settingsForm.points[s]" type="number" step="0.25" min="-5" max="5" :class="[input, 'w-24']" />
                        </label>
                    </div>
                    <div>
                        <h2 class="mb-2 font-bold text-slate-900 dark:text-slate-100">{{ t('att.rules') }}</h2>
                        <label class="mb-2 block text-sm">{{ t('att.lates_per_unexcused') }}<input v-model.number="settingsForm.rules.lates_per_unexcused" type="number" min="0" max="20" :class="[input, 'mt-1 block w-24']" /></label>
                        <label class="block text-sm">{{ t('att.late_minutes_as_absent') }}<input v-model.number="settingsForm.rules.late_minutes_as_absent" type="number" min="0" max="240" :class="[input, 'mt-1 block w-24']" /></label>
                    </div>
                    <div>
                        <h2 class="mb-2 font-bold text-slate-900 dark:text-slate-100">{{ t('att.alerts') }}</h2>
                        <label class="mb-2 block text-sm">{{ t('att.min_score_pct') }}<input v-model.number="settingsForm.alerts.min_score_pct" type="number" min="0" max="100" :class="[input, 'mt-1 block w-24']" /></label>
                        <label class="block text-sm">{{ t('att.unexcused_streak') }}<input v-model.number="settingsForm.alerts.unexcused_streak" type="number" min="0" max="20" :class="[input, 'mt-1 block w-24']" /></label>
                    </div>
                    <div class="md:col-span-3">
                        <InputError v-for="(e, k) in settingsForm.errors" :key="k" :message="e" />
                        <button type="submit" :disabled="settingsForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
                    </div>
                </form>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 6: Run the test**

Run: `php artisan test --filter=AttendanceSettingsPageTest`
Expected: PASS (5 tests). If `assertInertia` complains that the page file is missing, check the path `resources/js/Pages/Attendance/Settings.vue`.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/AttendanceSettingsController.php resources/js/Pages/Attendance/Settings.vue routes/web.php tests/Feature/AttendanceSettingsPageTest.php
git commit -m "feat(attendance): settings screen for schedules, closures, targets and scoring

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Calendar page, session create/cancel/move, sidebar entry

**Files:**
- Create: `app/Http/Controllers/AttendanceController.php` (the `index` method only in this task), `app/Http/Controllers/TrainingSessionController.php`, `resources/js/Pages/Attendance/Index.vue`
- Modify: `routes/web.php` (attendance block), `resources/js/Layouts/AuthenticatedLayout.vue` (nav_members items)
- Test: `tests/Feature/AttendanceCalendarTest.php`

**Interfaces:**
- Consumes: `SessionGenerator::forMonth`, `Season`, `PreseasonTarget`.
- Produces the routes `attendance.index` (GET, params `category_id` and `month=YYYY-MM`), `attendance.sessions.store` (POST), `attendance.sessions.cancel` (POST `{reason}`) and `attendance.sessions.move` (POST `{date,start_time,end_time}`). Store redirects to `attendance.sessions.show`, which Task 8 creates; the routes line for show is added here so the redirect resolves.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceCalendarTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ActivityLog;
use App\Models\PreseasonTarget;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceCalendarTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function opening_a_month_generates_and_lists_its_sessions_with_preseason_progress(): void
    {
        $u15 = $this->category();
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);
        PreseasonTarget::create(['category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 12]);
        TrainingSession::create(['category_id' => $u15->id, 'date' => '2026-09-02', 'start_time' => '09:00', 'end_time' => '10:30',
            'kind' => SessionKind::Preseason, 'state' => SessionState::Held]);

        $this->actingAs($this->admin())
            ->get(route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Index')
                ->where('categoryId', $u15->id)
                ->where('month', '2026-10')
                ->has('sessions', 4)
                ->where('sessions.0.date', '2026-10-05')
                ->where('preseason.done', 1)
                ->where('preseason.target', 12)
                ->where('preseason.season', '2026/27')
                ->where('hasSchedule', true));
    }

    #[Test]
    public function an_extra_session_is_created_and_a_duplicate_slot_is_rejected(): void
    {
        $u15 = $this->category();
        $admin = $this->admin();
        $payload = ['category_id' => $u15->id, 'kind' => 'preseason', 'date' => '2026-09-03', 'start_time' => '09:00', 'end_time' => '10:30'];

        $response = $this->actingAs($admin)->post(route('attendance.sessions.store'), $payload);
        $session = TrainingSession::sole();
        $response->assertRedirect(route('attendance.sessions.show', $session))->assertSessionHas('success', 'flash.training_session_created');
        $this->assertSame(SessionKind::Preseason, $session->kind);
        $this->assertSame(1, ActivityLog::where('action', 'training_session_created')->count());

        $this->actingAs($admin)->post(route('attendance.sessions.store'), $payload)->assertSessionHasErrors('start_time');
        $this->actingAs($admin)->post(route('attendance.sessions.store'), ['kind' => 'regular'] + $payload)->assertSessionHasErrors('kind');
    }

    #[Test]
    public function a_session_is_cancelled_with_a_reason_and_moved_keeping_its_original_date(): void
    {
        $u15 = $this->category();
        $admin = $this->admin();
        $session = TrainingSession::create(['category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned]);

        $this->actingAs($admin)->post(route('attendance.sessions.move', $session), ['date' => '2026-10-06', 'start_time' => '17:00', 'end_time' => '18:30'])
            ->assertSessionHas('success', 'flash.training_session_moved');
        $this->actingAs($admin)->post(route('attendance.sessions.move', $session), ['date' => '2026-10-07', 'start_time' => '17:00', 'end_time' => '18:30']);
        $session->refresh();
        $this->assertSame('2026-10-07', $session->date);
        $this->assertSame('2026-10-05', $session->moved_from);

        $this->actingAs($admin)->post(route('attendance.sessions.cancel', $session), [])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('attendance.sessions.cancel', $session), ['reason' => 'Rain'])
            ->assertSessionHas('success', 'flash.training_session_cancelled');
        $this->assertSame(SessionState::Cancelled, $session->fresh()->state);
        $this->assertSame('Rain', $session->fresh()->cancel_reason);

        $this->actingAs($admin)->post(route('attendance.sessions.move', $session), ['date' => '2026-10-08', 'start_time' => '17:00', 'end_time' => '18:30'])
            ->assertSessionHasErrors('date');
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=AttendanceCalendarTest`
Expected: FAIL, `Route [attendance.index] not defined`.

- [ ] **Step 3: Add the routes**

`routes/web.php`, in the attendance block (Task 6), add these lines **above** the settings lines. `/attendance/sessions/{session}` never clashes with `/attendance/settings`, but keep the static paths first anyway.
```php
        Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
        Route::post('/attendance/sessions', [TrainingSessionController::class, 'store'])->name('attendance.sessions.store');
        Route::get('/attendance/sessions/{session}', [AttendanceController::class, 'show'])->name('attendance.sessions.show');
        Route::post('/attendance/sessions/{session}/cancel', [TrainingSessionController::class, 'cancel'])->name('attendance.sessions.cancel');
        Route::post('/attendance/sessions/{session}/move', [TrainingSessionController::class, 'move'])->name('attendance.sessions.move');
```

- [ ] **Step 4: Write the controllers**

`app/Http/Controllers/AttendanceController.php` (Task 8 adds `show` and `saveMarks`):
```php
<?php

namespace App\Http\Controllers;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Category;
use App\Models\PreseasonTarget;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Attendance\SessionGenerator;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    /** Month calendar of one category. Opening a month generates its planned sessions. */
    public function index(Request $request, SessionGenerator $generator): Response
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $categories = Category::orderBy('id')->get()
            ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])->values();
        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : data_get($categories->first(), 'id');
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month'] ?? now()->format('Y-m'));

        $sessions = collect();
        $preseason = null;
        $hasSchedule = false;

        if ($categoryId !== null) {
            $generator->forMonth($categoryId, $anchor->year, $anchor->month);

            $sessions = TrainingSession::where('category_id', $categoryId)
                ->whereBetween('date', [$anchor->startOfMonth()->toDateString(), $anchor->endOfMonth()->toDateString()])
                ->withCount('attendances')
                ->orderBy('date')->orderBy('start_time')
                ->get()
                ->map(fn (TrainingSession $s) => [
                    'id' => $s->id, 'date' => $s->date, 'start_time' => $s->start_time, 'end_time' => $s->end_time,
                    'kind' => $s->kind->value, 'state' => $s->state->value, 'theme' => $s->theme,
                    'marked' => $s->attendances_count,
                ]);

            $season = Season::forDate($anchor);
            $preseason = [
                'season' => $season->label(),
                'done' => TrainingSession::where('category_id', $categoryId)
                    ->where('kind', SessionKind::Preseason->value)
                    ->where('state', SessionState::Held->value)
                    ->whereBetween('date', [$season->start()->toDateString(), $season->end()->toDateString()])
                    ->count(),
                'target' => PreseasonTarget::where('category_id', $categoryId)
                    ->where('season_start_year', $season->startYear)->value('target_count'),
            ];
            $hasSchedule = TrainingSchedule::where('category_id', $categoryId)->exists();
        }

        return Inertia::render('Attendance/Index', [
            'categories' => $categories,
            'categoryId' => $categoryId,
            'month' => $anchor->format('Y-m'),
            'sessions' => $sessions,
            'preseason' => $preseason,
            'hasSchedule' => $hasSchedule,
        ]);
    }
}
```

`app/Http/Controllers/TrainingSessionController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\TrainingSession;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TrainingSessionController extends Controller
{
    /** An extra or pre-season session added by hand (regular ones come from the schedule). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'kind' => ['required', Rule::in([SessionKind::Extra->value, SessionKind::Preseason->value])],
            ...$this->slotRules(),
        ]);
        $this->assertSlotFree((int) $data['category_id'], $data['date'], $data['start_time']);

        $session = DB::transaction(function () use ($data, $request) {
            $session = TrainingSession::create($data + ['state' => SessionState::Planned]);
            ActivityRecorder::record($request->user(), ActivityAction::TRAINING_SESSION_CREATED, $session, ['kind' => $data['kind']]);

            return $session;
        });

        return redirect()->route('attendance.sessions.show', $session)->with('success', 'flash.training_session_created');
    }

    public function cancel(Request $request, TrainingSession $session): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        DB::transaction(function () use ($session, $data, $request) {
            $session->update(['state' => SessionState::Cancelled, 'cancel_reason' => $data['reason']]);
            ActivityRecorder::record($request->user(), ActivityAction::TRAINING_SESSION_CANCELLED, $session);
        });

        return back()->with('success', 'flash.training_session_cancelled');
    }

    /** `moved_from` keeps the first original date so the generator never recreates that slot. */
    public function move(Request $request, TrainingSession $session): RedirectResponse
    {
        if ($session->state === SessionState::Cancelled) {
            throw ValidationException::withMessages(['date' => 'att.error.cancelled']);
        }
        $data = $request->validate($this->slotRules());
        $this->assertSlotFree($session->category_id, $data['date'], $data['start_time'], $session->id);

        DB::transaction(function () use ($session, $data, $request) {
            $session->update($data + ['moved_from' => $session->moved_from ?? $session->date]);
            ActivityRecorder::record($request->user(), ActivityAction::TRAINING_SESSION_MOVED, $session);
        });

        return back()->with('success', 'flash.training_session_moved');
    }

    private function slotRules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ];
    }

    private function assertSlotFree(int $categoryId, string $date, string $startTime, ?int $ignoreId = null): void
    {
        $taken = TrainingSession::where('category_id', $categoryId)->where('date', $date)->where('start_time', $startTime)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['start_time' => 'att.error.duplicate']);
        }
    }
}
```

- [ ] **Step 5: Write the calendar page**

`resources/js/Pages/Attendance/Index.vue`:
```vue
<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { useCan } from '@/Composables/useCan';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    categoryId: { type: Number, default: null },
    month: { type: String, required: true }, // YYYY-MM
    sessions: { type: Array, default: () => [] },
    preseason: { type: Object, default: null },
    hasSchedule: { type: Boolean, default: false },
});
const { t, locale } = useI18n();
const { can } = useCan();
const lang = computed(() => (locale.value === 'ar' ? 'ar' : locale.value));

const key = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const todayKey = key(new Date());
const anchor = computed(() => new Date(`${props.month}-01T00:00:00`));
const monthLabel = computed(() => anchor.value.toLocaleDateString(lang.value, { month: 'long', year: 'numeric' }));
const weekdays = computed(() => Array.from({ length: 7 }, (_, i) =>
    new Date(Date.UTC(2024, 0, 1 + i)).toLocaleDateString(lang.value, { weekday: 'short', timeZone: 'UTC' })));

const byDate = computed(() => props.sessions.reduce((acc, s) => ((acc[s.date] ??= []).push(s), acc), {}));

// 6-week grid starting on the Monday on/before the 1st.
const cells = computed(() => {
    const first = anchor.value;
    const start = new Date(first);
    start.setDate(first.getDate() - ((first.getDay() + 6) % 7));
    return Array.from({ length: 42 }, (_, i) => {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        const k = key(d);
        return { key: k, day: d.getDate(), inMonth: d.getMonth() === first.getMonth(), sessions: byDate.value[k] ?? [] };
    });
});

function visit(params) {
    router.get(route('attendance.index'), { category_id: props.categoryId, month: props.month, ...params }, { preserveScroll: true });
}
function shift(delta) {
    const d = new Date(anchor.value);
    d.setMonth(d.getMonth() + delta);
    visit({ month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` });
}

const chip = {
    planned: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    held: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
    cancelled: 'bg-slate-200 text-slate-500 line-through dark:bg-slate-700 dark:text-slate-400',
};
const kindDot = { regular: 'bg-primary-500', preseason: 'bg-amber-500', extra: 'bg-violet-500' };

const preseasonLabel = computed(() => {
    if (!props.preseason) return '';
    const { season, done, target } = props.preseason;
    return target === null ? t('att.preseason_no_target', { season, done }) : t('att.preseason_progress', { season, done, target });
});

// ---- Add an extra / pre-season session ----
const showForm = ref(false);
const form = useForm({ category_id: null, kind: 'extra', date: '', start_time: '18:00', end_time: '19:30' });
function openCreate(kind, date = todayKey) {
    form.reset();
    form.clearErrors();
    Object.assign(form, { category_id: props.categoryId, kind, date });
    showForm.value = true;
}
const submit = () => form.post(route('attendance.sessions.store'), { onSuccess: () => (showForm.value = false) });
const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
</script>

<template>
    <Head :title="t('attendance')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('attendance') }}</h1>
                <div class="flex gap-2">
                    <Link v-if="categoryId" :href="route('attendance.grid', { category_id: categoryId, month })" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800">{{ t('att.grid') }}</Link>
                    <Link v-if="can('attendance', 'edit')" :href="route('attendance.settings')" class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800"><Icon name="settings" />{{ t('att.settings') }}</Link>
                </div>
            </div>
        </template>

        <p v-if="!categories.length" class="text-sm text-slate-500">{{ t('att.no_category') }}</p>

        <div v-else class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <select :value="categoryId" :class="input" :aria-label="t('att.category')" @change="visit({ category_id: Number($event.target.value) })">
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rtl:rotate-180" @click="shift(-1)"><Icon name="back" /></button>
                    <span class="min-w-[9rem] text-center text-sm font-bold capitalize text-slate-900 dark:text-slate-100">{{ monthLabel }}</span>
                    <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 ltr:rotate-180" @click="shift(1)"><Icon name="back" /></button>
                    <span v-if="preseason" class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">{{ preseasonLabel }}</span>
                </div>
                <div v-if="can('attendance', 'add')" class="flex gap-2">
                    <button class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-700" @click="openCreate('extra')">+ {{ t('att.add_extra') }}</button>
                    <button class="rounded-lg bg-amber-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-600" @click="openCreate('preseason')">+ {{ t('att.add_preseason') }}</button>
                </div>
            </div>

            <p v-if="!hasSchedule" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ t('att.no_schedule') }}</p>

            <div class="grid grid-cols-7 gap-px overflow-hidden rounded-xl bg-slate-200 ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-800">
                <div v-for="w in weekdays" :key="w" class="bg-slate-50 p-2 text-center text-xs font-semibold text-slate-500 dark:bg-slate-900">{{ w }}</div>
                <div v-for="cell in cells" :key="cell.key" class="min-h-[5.5rem] bg-white p-1.5 dark:bg-slate-900" :class="{ 'opacity-40': !cell.inMonth }">
                    <div class="mb-1 text-xs font-semibold" :class="cell.key === todayKey ? 'text-primary-600' : 'text-slate-400'">{{ cell.day }}</div>
                    <Link v-for="s in cell.sessions" :key="s.id" :href="route('attendance.sessions.show', s.id)" class="mb-1 flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium" :class="chip[s.state]" :title="`${t(`att.kind.${s.kind}`)} · ${t(`att.state.${s.state}`)}`">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="kindDot[s.kind]"></span>
                        <span dir="ltr">{{ s.start_time }}</span>
                        <span v-if="s.state === 'held'" class="ms-auto">✓</span>
                    </Link>
                </div>
            </div>

            <div class="flex flex-wrap gap-3 text-xs text-slate-500">
                <span v-for="(cls, kind) in kindDot" :key="kind" class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" :class="cls"></span>{{ t(`att.kind.${kind}`) }}</span>
            </div>
        </div>

        <Modal :show="showForm" max-width="md" @close="showForm = false">
            <form class="space-y-3 p-5" @submit.prevent="submit">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ form.kind === 'preseason' ? t('att.add_preseason') : t('att.add_extra') }}</h2>
                <label class="block text-sm">{{ t('att.date') }}<input v-model="form.date" type="date" :class="[input, 'mt-1 block w-full']" /></label>
                <div class="flex gap-2">
                    <label class="flex-1 text-sm">{{ t('att.start') }}<input v-model="form.start_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                    <label class="flex-1 text-sm">{{ t('att.end') }}<input v-model="form.end_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                </div>
                <InputError v-for="(e, k) in form.errors" :key="k" :message="e.startsWith('att.') ? t(e) : e" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="showForm = false">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="form.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 6: Add the sidebar entry**

`resources/js/Layouts/AuthenticatedLayout.vue`, in the `nav_members` items, after the `subscriptions` line:
```js
            { label: t('attendance'), href: '/attendance', icon: 'calendar', prefix: '/attendance', module: 'attendance' },
```

Note: the `attendance.sessions.show` route points at `AttendanceController@show`, which Task 8 adds. The store test only checks the redirect URL, so it passes before `show` exists.

- [ ] **Step 7: Run the test**

Run: `php artisan test --filter=AttendanceCalendarTest`
Expected: PASS (3 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/AttendanceController.php app/Http/Controllers/TrainingSessionController.php resources/js/Pages/Attendance/Index.vue resources/js/Layouts/AuthenticatedLayout.vue routes/web.php tests/Feature/AttendanceCalendarTest.php
git commit -m "feat(attendance): month calendar, extra/pre-season sessions, cancel and move

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Session marking screen

**Files:**
- Create: `app/Http/Requests/SaveAttendanceMarksRequest.php`, `resources/js/Pages/Attendance/Session.vue`
- Modify: `app/Http/Controllers/AttendanceController.php` (add `show` and `saveMarks`), `routes/web.php` (add the marks route)
- Test: `tests/Feature/AttendanceSessionTest.php`

**Interfaces:**
- Consumes: `Roster::forSession`, `MarkRecorder::save`, `AttendanceStatus`, `AbsenceReason`.
- Produces the route `attendance.sessions.marks` (PUT). Its body is `{coach, theme, notes, marks: [{player_id, status, minutes, reason, note}]}`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceSessionTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Category;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceSessionTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function session(Category $category, array $extra = []): TrainingSession
    {
        return TrainingSession::create($extra + [
            'category_id' => $category->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    #[Test]
    public function an_unsaved_session_shows_the_expected_roster_as_present_with_the_last_coach(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $this->session($u15, ['date' => '2026-09-28', 'coach' => 'Karim', 'state' => SessionState::Held]);
        $session = $this->session($u15);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Session')
                ->where('saved', false)
                ->where('lastCoach', 'Karim')
                ->has('rows', 1)
                ->where('rows.0.player_id', $a->id)
                ->where('rows.0.status', 'present')
                ->has('candidates', 0));
    }

    #[Test]
    public function marks_are_saved_and_validated(): void
    {
        $u15 = $this->category();
        [$a, $b] = [$this->player($u15), $this->player($u15)];
        $session = $this->session($u15);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('attendance.sessions.marks', $session), [
            'marks' => [['player_id' => $a->id, 'status' => 'late'], ['player_id' => $b->id, 'status' => 'absent_excused']],
        ])->assertSessionHasErrors(['marks.0.minutes', 'marks.1.reason']);

        $this->actingAs($admin)->put(route('attendance.sessions.marks', $session), [
            'coach' => 'Karim', 'theme' => 'Endurance',
            'marks' => [
                ['player_id' => $a->id, 'status' => 'late', 'minutes' => 10],
                ['player_id' => $b->id, 'status' => 'absent_excused', 'reason' => 'illness', 'note' => 'Flu'],
            ],
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.attendance_saved');

        $this->assertSame(SessionState::Held, $session->fresh()->state);
        $this->assertSame('Endurance', $session->fresh()->theme);
        $this->assertSame(AttendanceStatus::Late, $session->attendances()->where('player_id', $a->id)->value('status'));
    }

    #[Test]
    public function marking_needs_attendance_edit(): void
    {
        $u15 = $this->category();
        $session = $this->session($u15);
        $role = Role::factory()->create(['permissions' => ['attendance' => ['view']]]);
        $viewer = User::factory()->create(['privileges' => ['user'], 'role_id' => $role->id]);

        $this->actingAs($viewer)->get(route('attendance.sessions.show', $session))->assertOk();
        $this->actingAs($viewer)->put(route('attendance.sessions.marks', $session), [
            'marks' => [['player_id' => $this->player($u15)->id, 'status' => 'present']],
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=AttendanceSessionTest`
Expected: FAIL. `show` does not exist, and `attendance.sessions.marks` is not defined.

- [ ] **Step 3: Add the route**

`routes/web.php`, attendance block, after the `attendance.sessions.show` line:
```php
        Route::put('/attendance/sessions/{session}/marks', [AttendanceController::class, 'saveMarks'])->name('attendance.sessions.marks');
```

- [ ] **Step 4: Write the form request**

`app/Http/Requests/SaveAttendanceMarksRequest.php`:
```php
<?php

namespace App\Http\Requests;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The full set of marks of one session, plus its log. Access is checked by the `permission` middleware. */
class SaveAttendanceMarksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'coach' => ['nullable', 'string', 'max:100'],
            'theme' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'marks' => ['required', 'array', 'min:1'],
            'marks.*.player_id' => ['required', 'integer', 'distinct', 'exists:players,id'],
            'marks.*.status' => ['required', Rule::enum(AttendanceStatus::class)],
            'marks.*.minutes' => ['nullable', 'integer', 'between:1,600', 'required_if:marks.*.status,late,left_early'],
            'marks.*.reason' => ['nullable', Rule::enum(AbsenceReason::class), 'required_if:marks.*.status,absent_excused'],
            'marks.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 5: Add `show` and `saveMarks` to AttendanceController**

Add these imports to `app/Http/Controllers/AttendanceController.php`:
```php
use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use App\Http\Requests\SaveAttendanceMarksRequest;
use App\Models\Player;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\Roster;
use Illuminate\Http\RedirectResponse;
```
Add these methods:
```php
    /** One session: its roster with marks (everyone present until first saved) and its log. */
    public function show(TrainingSession $session, Roster $roster): Response
    {
        $session->load('category');
        $marks = $session->attendances()->get()->keyBy('player_id');
        $players = $roster->forSession($session);

        return Inertia::render('Attendance/Session', [
            'session' => [
                'id' => $session->id, 'date' => $session->date, 'start_time' => $session->start_time, 'end_time' => $session->end_time,
                'kind' => $session->kind->value, 'state' => $session->state->value, 'cancel_reason' => $session->cancel_reason,
                'moved_from' => $session->moved_from, 'coach' => $session->coach, 'theme' => $session->theme, 'notes' => $session->notes,
                'category' => $session->category?->localized_name,
                'category_id' => $session->category_id,
            ],
            'saved' => $marks->isNotEmpty(),
            'rows' => $players->map(function (Player $p) use ($marks) {
                $mark = $marks->get($p->id);

                return [
                    'player_id' => $p->id,
                    'name' => trim("{$p->lastname} {$p->firstname}"),
                    'status' => $mark?->status->value ?? AttendanceStatus::Present->value,
                    'minutes' => $mark?->minutes,
                    'reason' => $mark?->reason?->value,
                    'note' => $mark?->note,
                ];
            })->values(),
            'candidates' => Player::where('archived', false)->whereNull('left_at')
                ->whereNotIn('id', $players->modelKeys())
                ->orderBy('lastname')->orderBy('firstname')
                ->get(['id', 'firstname', 'lastname'])
                ->map(fn (Player $p) => ['id' => $p->id, 'name' => trim("{$p->lastname} {$p->firstname}")]),
            'lastCoach' => TrainingSession::where('category_id', $session->category_id)
                ->whereKeyNot($session->id)->whereNotNull('coach')
                ->orderByDesc('date')->value('coach'),
            'statuses' => AttendanceStatus::values(),
            'reasons' => AbsenceReason::values(),
        ]);
    }

    public function saveMarks(SaveAttendanceMarksRequest $request, TrainingSession $session, MarkRecorder $recorder): RedirectResponse
    {
        $data = $request->validated();
        $recorder->save($session, $data['marks'], $request->user(), [
            'coach' => $data['coach'] ?? null, 'theme' => $data['theme'] ?? null, 'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'flash.attendance_saved');
    }
```

- [ ] **Step 6: Write the session page**

`resources/js/Pages/Attendance/Session.vue`:
```vue
<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { useCan } from '@/Composables/useCan';

const props = defineProps({
    session: { type: Object, required: true },
    saved: { type: Boolean, default: false },
    rows: { type: Array, default: () => [] },
    candidates: { type: Array, default: () => [] },
    lastCoach: { type: String, default: null },
    statuses: { type: Array, default: () => [] },
    reasons: { type: Array, default: () => [] },
});
const { t, locale } = useI18n();
const { can } = useCan();
const page = usePage();
const errors = computed(() => page.props.errors ?? {});

const cancelled = computed(() => props.session.state === 'cancelled');
const editable = computed(() => can('attendance', 'edit') && !cancelled.value);
const dateLabel = computed(() => new Date(`${props.session.date}T00:00:00`).toLocaleDateString(locale.value === 'ar' ? 'ar' : locale.value, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));

const rows = ref(props.rows.map((r) => ({ ...r, note: r.note ?? '' })));
const log = reactive({ coach: props.session.coach ?? props.lastCoach ?? '', theme: props.session.theme ?? '', notes: props.session.notes ?? '' });

const takesMinutes = (s) => s === 'late' || s === 'left_early';
const takesReason = (s) => s === 'absent_excused' || s === 'not_training';
function setStatus(row, status) {
    row.status = status;
    if (!takesMinutes(status)) row.minutes = null;
    if (!takesReason(status)) row.reason = null;
    if (status === 'absent_excused' && !row.reason) row.reason = 'other';
}
const allPresent = () => rows.value.forEach((r) => setStatus(r, 'present'));

const statusStyle = {
    present: 'bg-emerald-600 text-white', late: 'bg-amber-500 text-white', left_early: 'bg-orange-500 text-white',
    not_training: 'bg-sky-600 text-white', absent_excused: 'bg-slate-500 text-white', absent_unexcused: 'bg-rose-600 text-white',
};
const counts = computed(() => rows.value.reduce((acc, r) => ((acc[r.status] = (acc[r.status] ?? 0) + 1), acc), {}));

const pick = ref('');
function addPlayer() {
    const p = props.candidates.find((c) => c.id === Number(pick.value));
    if (p && !rows.value.some((r) => r.player_id === p.id)) rows.value.push({ player_id: p.id, name: p.name, status: 'present', minutes: null, reason: null, note: '' });
    pick.value = '';
}
const removeRow = (row) => (rows.value = rows.value.filter((r) => r !== row));

const saving = ref(false);
function save() {
    router.put(route('attendance.sessions.marks', props.session.id), {
        ...log,
        marks: rows.value.map(({ name, ...mark }) => mark),
    }, { preserveScroll: true, preserveState: 'errors', onStart: () => (saving.value = true), onFinish: () => (saving.value = false) });
}
const rowError = (i) => ['minutes', 'reason', 'note', 'status'].map((f) => errors.value[`marks.${i}.${f}`]).find(Boolean);

// ---- Cancel / move ----
const showCancel = ref(false);
const cancelForm = useForm({ reason: '' });
const submitCancel = () => cancelForm.post(route('attendance.sessions.cancel', props.session.id), { onSuccess: () => (showCancel.value = false) });
const showMove = ref(false);
const moveForm = useForm({ date: props.session.date, start_time: props.session.start_time, end_time: props.session.end_time });
const submitMove = () => moveForm.post(route('attendance.sessions.move', props.session.id), { onSuccess: () => (showMove.value = false) });

const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
const tr = (e) => (typeof e === 'string' && e.startsWith('att.') ? t(e) : e);
</script>

<template>
    <Head :title="t('attendance')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <Link :href="route('attendance.index', { category_id: session.category_id, month: session.date.slice(0, 7) })" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 rtl:rotate-180"><Icon name="back" /></Link>
                    <div>
                        <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ session.category }} · {{ t(`att.kind.${session.kind}`) }}</h1>
                        <p class="text-sm capitalize text-slate-500">{{ dateLabel }} · <span dir="ltr">{{ session.start_time }}–{{ session.end_time }}</span> · {{ t(`att.state.${session.state}`) }}</p>
                        <p v-if="session.moved_from" class="text-xs text-slate-400">{{ t('att.moved_from', { date: session.moved_from }) }}</p>
                    </div>
                </div>
                <div v-if="editable" class="flex gap-2">
                    <button class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700" @click="showMove = true">{{ t('att.move') }}</button>
                    <button class="rounded-lg px-3 py-1.5 text-sm font-semibold text-rose-600 ring-1 ring-rose-200 hover:bg-rose-50 dark:ring-rose-900" @click="showCancel = true">{{ t('att.cancel') }}</button>
                </div>
            </div>
        </template>

        <p v-if="cancelled" class="mb-4 rounded-lg bg-slate-100 p-3 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('att.cancelled_because', { reason: session.cancel_reason }) }}</p>
        <p v-else-if="!saved" class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ t('att.not_saved') }}</p>
        <InputError :message="tr(errors.session)" class="mb-2" />

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800 lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 p-3 dark:border-slate-800">
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span v-for="s in statuses" :key="s" v-show="counts[s]" class="rounded-full px-2 py-0.5" :class="statusStyle[s]">{{ t(`att.status.${s}`) }}: {{ counts[s] }}</span>
                    </div>
                    <button v-if="editable" class="rounded-lg px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200 hover:bg-emerald-50 dark:text-emerald-300 dark:ring-emerald-900" @click="allPresent">{{ t('att.all_present') }}</button>
                </div>
                <ul>
                    <li v-for="(row, i) in rows" :key="row.player_id" class="border-b border-slate-100 p-3 last:border-0 dark:border-slate-800">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="min-w-[10rem] flex-1 font-medium text-slate-900 dark:text-slate-100">{{ row.name }}</span>
                            <button v-for="s in statuses" :key="s" type="button" :disabled="!editable" class="rounded-lg px-2 py-1 text-xs font-semibold" :class="row.status === s ? statusStyle[s] : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400'" @click="setStatus(row, s)">{{ t(`att.status.${s}`) }}</button>
                            <button v-if="editable" type="button" class="p-1 text-slate-300 hover:text-rose-600" :title="t('att.remove')" @click="removeRow(row)"><Icon name="trash" /></button>
                        </div>
                        <div v-if="takesMinutes(row.status) || takesReason(row.status) || row.note" class="mt-2 flex flex-wrap items-center gap-2">
                            <label v-if="takesMinutes(row.status)" class="text-xs text-slate-500">{{ t('att.minutes') }}
                                <input v-model.number="row.minutes" type="number" min="1" max="600" :disabled="!editable" :class="[input, 'ms-1 w-20']" />
                            </label>
                            <label v-if="takesReason(row.status)" class="text-xs text-slate-500">{{ t('att.reason') }}
                                <select v-model="row.reason" :disabled="!editable" :class="[input, 'ms-1']">
                                    <option :value="null">—</option>
                                    <option v-for="r in reasons" :key="r" :value="r">{{ t(`att.reason.${r}`) }}</option>
                                </select>
                            </label>
                            <input v-model="row.note" type="text" maxlength="255" :placeholder="t('att.note')" :disabled="!editable" :class="[input, 'min-w-[12rem] flex-1']" />
                        </div>
                        <InputError :message="rowError(i)" />
                    </li>
                </ul>
                <div v-if="editable && candidates.length" class="flex gap-2 border-t border-slate-100 p-3 dark:border-slate-800">
                    <select v-model="pick" :class="[input, 'flex-1']" :aria-label="t('att.add_player')">
                        <option value="">{{ t('att.add_player') }}…</option>
                        <option v-for="c in candidates" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <button type="button" class="rounded-lg px-3 text-sm font-semibold text-primary-600 ring-1 ring-primary-200 dark:ring-primary-900" :disabled="!pick" @click="addPlayer">{{ t('att.add') }}</button>
                </div>
            </section>

            <section class="h-fit space-y-3 rounded-xl bg-white p-4 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <label class="block text-sm">{{ t('att.coach') }}<input v-model="log.coach" type="text" maxlength="100" :disabled="!editable" :class="[input, 'mt-1 block w-full']" /></label>
                <label class="block text-sm">{{ t('att.theme') }}<input v-model="log.theme" type="text" maxlength="100" :disabled="!editable" :class="[input, 'mt-1 block w-full']" /></label>
                <label class="block text-sm">{{ t('att.notes') }}<textarea v-model="log.notes" rows="4" maxlength="2000" :disabled="!editable" :class="[input, 'mt-1 block w-full']"></textarea></label>
                <InputError :message="errors.marks" />
                <button v-if="editable" type="button" :disabled="saving || !rows.length" class="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50" @click="save">{{ t('att.save') }}</button>
            </section>
        </div>

        <Modal :show="showCancel" max-width="md" @close="showCancel = false">
            <form class="space-y-3 p-5" @submit.prevent="submitCancel">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.cancel') }}</h2>
                <label class="block text-sm">{{ t('att.cancel_reason') }}<input v-model="cancelForm.reason" type="text" maxlength="255" :class="[input, 'mt-1 block w-full']" /></label>
                <InputError :message="cancelForm.errors.reason" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="showCancel = false">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="cancelForm.processing" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">{{ t('att.cancel') }}</button>
                </div>
            </form>
        </Modal>

        <Modal :show="showMove" max-width="md" @close="showMove = false">
            <form class="space-y-3 p-5" @submit.prevent="submitMove">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.move') }}</h2>
                <label class="block text-sm">{{ t('att.date') }}<input v-model="moveForm.date" type="date" :class="[input, 'mt-1 block w-full']" /></label>
                <div class="flex gap-2">
                    <label class="flex-1 text-sm">{{ t('att.start') }}<input v-model="moveForm.start_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                    <label class="flex-1 text-sm">{{ t('att.end') }}<input v-model="moveForm.end_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                </div>
                <InputError v-for="(e, k) in moveForm.errors" :key="k" :message="tr(e)" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="showMove = false">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="moveForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter="AttendanceSessionTest|AttendanceCalendarTest"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/SaveAttendanceMarksRequest.php app/Http/Controllers/AttendanceController.php resources/js/Pages/Attendance/Session.vue routes/web.php tests/Feature/AttendanceSessionTest.php
git commit -m "feat(attendance): session screen to mark players and log the session

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Month grid entry

**Files:**
- Create: `app/Http/Controllers/AttendanceGridController.php`, `resources/js/Pages/Attendance/Grid.vue`
- Modify: `routes/web.php`
- Test: `tests/Feature/AttendanceGridTest.php`

**Interfaces:**
- Consumes: `SessionGenerator`, `Roster`, `MarkRecorder`, `AttendanceCode`.
- Produces the route `attendance.grid` (GET `category_id`, `month`) and `attendance.grid.save` (POST `{category_id, month, columns: [{session_id, codes: {player_id: code}}]}`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceGridTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceGridTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function the_grid_lists_non_cancelled_sessions_with_roster_cells(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);
        $this->actingAs($this->admin())->get(route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-10']));
        $sessions = TrainingSession::orderBy('date')->get();
        $sessions[0]->update(['state' => SessionState::Cancelled, 'cancel_reason' => 'Rain']);
        Attendance::create(['training_session_id' => $sessions[1]->id, 'player_id' => $a->id, 'status' => AttendanceStatus::Late, 'minutes' => 15]);
        $sessions[1]->update(['state' => SessionState::Held]);

        $this->actingAs($this->admin())->get(route('attendance.grid', ['category_id' => $u15->id, 'month' => '2026-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Grid')
                ->has('sessions', 3)
                ->has('rows', 1)
                ->where("cells.{$a->id}.{$sessions[1]->id}", 'R15')
                ->where("cells.{$a->id}.{$sessions[2]->id}", ''));
    }

    #[Test]
    public function saving_parses_codes_keeps_known_reasons_and_rejects_bad_codes(): void
    {
        $u15 = $this->category();
        [$a, $b] = [$this->player($u15), $this->player($u15)];
        $session = TrainingSession::create(['category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => 'regular', 'state' => 'held']);
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $b->id, 'status' => AttendanceStatus::AbsentExcused, 'reason' => AbsenceReason::Injury]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $session->id, 'codes' => [$a->id => 'X', $b->id => 'AE']]],
        ])->assertSessionHasErrors("columns.0.codes.{$a->id}");

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $session->id, 'codes' => [$a->id => 'r20', $b->id => 'AE']]],
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.attendance_saved');

        $marks = $session->attendances()->get()->keyBy('player_id');
        $this->assertSame(AttendanceStatus::Late, $marks[$a->id]->status);
        $this->assertSame(20, $marks[$a->id]->minutes);
        $this->assertSame(AbsenceReason::Injury, $marks[$b->id]->reason);
    }

    #[Test]
    public function an_empty_cell_saves_as_present_and_new_excuses_default_to_other(): void
    {
        $u15 = $this->category();
        [$a, $b] = [$this->player($u15), $this->player($u15)];
        $session = TrainingSession::create(['category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => 'regular', 'state' => 'planned']);

        $this->actingAs($this->admin())->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $session->id, 'codes' => [$a->id => '', $b->id => 'ae']]],
        ])->assertSessionHasNoErrors();

        $marks = $session->attendances()->get()->keyBy('player_id');
        $this->assertSame(AttendanceStatus::Present, $marks[$a->id]->status);
        $this->assertSame(AbsenceReason::Other, $marks[$b->id]->reason);
        $this->assertSame(SessionState::Held, $session->fresh()->state);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=AttendanceGridTest`
Expected: FAIL, `Route [attendance.grid] not defined`.

- [ ] **Step 3: Add the routes**

`routes/web.php`, attendance block, after the `attendance.index` line:
```php
        Route::get('/attendance/grid', [AttendanceGridController::class, 'show'])->name('attendance.grid');
        Route::post('/attendance/grid', [AttendanceGridController::class, 'save'])->name('attendance.grid.save');
```

- [ ] **Step 4: Write the controller**

`app/Http/Controllers/AttendanceGridController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceCode;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\Roster;
use App\Services\Attendance\SessionGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Month grid: players × sessions, filled with the paper-sheet codes. A cell
 * exists only where the player is on that session's roster (frozen marks,
 * or the expected roster before the first save).
 */
class AttendanceGridController extends Controller
{
    public function show(Request $request, SessionGenerator $generator, Roster $roster): Response
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'month' => ['required', 'date_format:Y-m'],
        ]);
        $category = Category::findOrFail($data['category_id']);
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month']);
        $generator->forMonth($category->id, $anchor->year, $anchor->month);

        $sessions = TrainingSession::where('category_id', $category->id)
            ->where('state', '!=', SessionState::Cancelled->value)
            ->whereBetween('date', [$anchor->startOfMonth()->toDateString(), $anchor->endOfMonth()->toDateString()])
            ->with('attendances')
            ->orderBy('date')->orderBy('start_time')
            ->get();

        $cells = [];
        $expectedByDate = [];
        foreach ($sessions as $session) {
            if ($session->attendances->isNotEmpty()) {
                foreach ($session->attendances as $mark) {
                    $cells[$mark->player_id][$session->id] = AttendanceCode::format($mark->status, $mark->minutes);
                }

                continue;
            }
            $expectedByDate[$session->date] ??= $roster->expected($category->id, $session->date)->modelKeys();
            foreach ($expectedByDate[$session->date] as $playerId) {
                $cells[$playerId][$session->id] = '';
            }
        }

        $rows = Player::whereIn('id', array_keys($cells))->orderBy('lastname')->orderBy('firstname')
            ->get(['id', 'firstname', 'lastname'])
            ->map(fn (Player $p) => ['id' => $p->id, 'name' => trim("{$p->lastname} {$p->firstname}")]);

        return Inertia::render('Attendance/Grid', [
            'category' => ['id' => $category->id, 'name' => $category->localized_name],
            'month' => $anchor->format('Y-m'),
            'sessions' => $sessions->map(fn (TrainingSession $s) => [
                'id' => $s->id, 'date' => $s->date, 'start_time' => $s->start_time, 'kind' => $s->kind->value, 'state' => $s->state->value,
            ])->values(),
            'rows' => $rows,
            'cells' => (object) $cells,
        ]);
    }

    public function save(Request $request, MarkRecorder $recorder): RedirectResponse
    {
        $data = $request->validate([
            'columns' => ['required', 'array', 'min:1'],
            'columns.*.session_id' => ['required', 'integer', 'distinct', 'exists:training_sessions,id'],
            'columns.*.codes' => ['required', 'array', 'min:1'],
            'columns.*.codes.*' => ['nullable', 'string', 'max:6'],
        ]);

        $plan = [];
        $errors = [];
        foreach ($data['columns'] as $i => $column) {
            $session = TrainingSession::with('attendances')->findOrFail($column['session_id']);
            $existing = $session->attendances->keyBy('player_id');
            $marks = [];

            foreach ($column['codes'] as $playerId => $code) {
                $parsed = AttendanceCode::parse($code);
                if ($parsed === null) {
                    $errors["columns.$i.codes.$playerId"] = 'att.error.invalid_code';

                    continue;
                }
                $previous = $existing->get((int) $playerId);
                $keepDetails = $previous?->status === $parsed['status'];
                $reason = $keepDetails ? $previous->reason?->value : null;
                if ($parsed['status'] === AttendanceStatus::AbsentExcused && $reason === null) {
                    $reason = AbsenceReason::Other->value;
                }

                $marks[] = [
                    'player_id' => (int) $playerId,
                    'status' => $parsed['status']->value,
                    'minutes' => $parsed['minutes'],
                    'reason' => $reason,
                    'note' => $keepDetails ? $previous->note : null,
                ];
            }
            $plan[] = [$session, $marks];
        }

        $unknown = array_diff(
            collect($plan)->flatMap(fn ($p) => array_column($p[1], 'player_id'))->unique()->all(),
            Player::whereIn('id', collect($plan)->flatMap(fn ($p) => array_column($p[1], 'player_id'))->all())->pluck('id')->all(),
        );
        if ($unknown !== []) {
            $errors['columns'] = 'att.error.invalid_code';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($plan, $recorder, $request) {
            foreach ($plan as [$session, $marks]) {
                $recorder->save($session, $marks, $request->user());
            }
        });

        return back()->with('success', 'flash.attendance_saved');
    }
}
```

- [ ] **Step 5: Write the grid page**

`resources/js/Pages/Attendance/Grid.vue`:
```vue
<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import { useCan } from '@/Composables/useCan';

const props = defineProps({
    category: { type: Object, required: true },
    month: { type: String, required: true },
    sessions: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    cells: { type: Object, default: () => ({}) }, // { playerId: { sessionId: code } }
});
const { t, locale } = useI18n();
const { can } = useCan();
const page = usePage();
const editable = computed(() => can('attendance', 'edit'));
const lang = computed(() => (locale.value === 'ar' ? 'ar' : locale.value));

const values = reactive(JSON.parse(JSON.stringify(props.cells)));
const dirty = reactive(new Set());
const sent = ref([]); // session ids in the order last posted, to map `columns.{i}` errors back

const inRoster = (pid, sid) => values[pid] !== undefined && sid in values[pid];
const dayLabel = (s) => new Date(`${s.date}T00:00:00`).toLocaleDateString(lang.value, { weekday: 'short', day: 'numeric' });
const monthLabel = computed(() => new Date(`${props.month}-01T00:00:00`).toLocaleDateString(lang.value, { month: 'long', year: 'numeric' }));

function onInput(pid, sid, event) {
    values[pid][sid] = event.target.value.toUpperCase();
    dirty.add(sid);
}
function cellError(pid, sid) {
    const i = sent.value.indexOf(sid);
    const e = i === -1 ? null : page.props.errors?.[`columns.${i}.codes.${pid}`];
    return e ? t(e) : null;
}
const codeStyle = (code) => ({
    'bg-amber-50 dark:bg-amber-500/10': /^R/.test(code),
    'bg-orange-50 dark:bg-orange-500/10': /^D/.test(code),
    'bg-sky-50 dark:bg-sky-500/10': code === 'B',
    'bg-slate-100 dark:bg-slate-800': code === 'AE',
    'bg-rose-50 dark:bg-rose-500/10': code === 'AN',
});

// Spreadsheet-style navigation: arrows / Enter move between cells (mirrored in RTL).
function onKey(event, r, c) {
    const rtl = document.documentElement.dir === 'rtl';
    const moves = { ArrowUp: [-1, 0], ArrowDown: [1, 0], Enter: [1, 0], ArrowLeft: [0, rtl ? 1 : -1], ArrowRight: [0, rtl ? -1 : 1] };
    const move = moves[event.key];
    if (!move) return;
    event.preventDefault();
    let [nr, nc] = [r + move[0], c + move[1]];
    while (nr >= 0 && nr < props.rows.length && nc >= 0 && nc < props.sessions.length) {
        const el = document.querySelector(`[data-cell="${nr}-${nc}"]`);
        if (el) return el.focus();
        [nr, nc] = [nr + move[0], nc + move[1]];
    }
}

function save() {
    const columns = props.sessions
        .filter((s) => dirty.has(s.id))
        .map((s) => ({ session_id: s.id, codes: Object.fromEntries(props.rows.filter((r) => inRoster(r.id, s.id)).map((r) => [r.id, values[r.id][s.id] ?? ''])) }))
        .filter((c) => Object.keys(c.codes).length);
    if (!columns.length) return;
    sent.value = columns.map((c) => c.session_id);
    router.post(route('attendance.grid.save'), { columns }, { preserveScroll: true, preserveState: 'errors', onSuccess: () => dirty.clear() });
}
</script>

<template>
    <Head :title="t('att.grid')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <Link :href="route('attendance.index', { category_id: category.id, month })" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 rtl:rotate-180"><Icon name="back" /></Link>
                    <h1 class="text-lg font-bold capitalize text-slate-900 dark:text-slate-100">{{ t('att.grid') }} · {{ category.name }} · {{ monthLabel }}</h1>
                </div>
                <button v-if="editable" :disabled="!dirty.size" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50" @click="save">{{ t('att.save') }}</button>
            </div>
        </template>

        <p class="mb-3 text-xs text-slate-500">{{ t('att.grid_help') }}</p>
        <InputError :message="page.props.errors?.columns ? t(page.props.errors.columns) : null" />

        <p v-if="!sessions.length" class="text-sm text-slate-500">{{ t('att.no_sessions') }}</p>
        <div v-else class="overflow-x-auto rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <table class="text-sm">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/50">
                        <th class="sticky start-0 z-10 bg-slate-50 p-2 text-start dark:bg-slate-800">{{ t('att.player') }}</th>
                        <th v-for="s in sessions" :key="s.id" class="min-w-[3.5rem] p-1 text-center text-xs font-semibold" :class="dirty.has(s.id) ? 'text-primary-600' : 'text-slate-500'">
                            <Link :href="route('attendance.sessions.show', s.id)" class="hover:underline">{{ dayLabel(s) }}</Link>
                            <div class="font-normal" :class="s.state === 'held' ? 'text-emerald-600' : 'text-slate-400'">{{ s.state === 'held' ? '✓' : '·' }}</div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, r) in rows" :key="row.id" class="border-t border-slate-100 dark:border-slate-800">
                        <td class="sticky start-0 z-10 whitespace-nowrap bg-white p-2 font-medium dark:bg-slate-900">{{ row.name }}</td>
                        <td v-for="(s, c) in sessions" :key="s.id" class="p-0.5 text-center">
                            <template v-if="inRoster(row.id, s.id)">
                                <input
                                    :data-cell="`${r}-${c}`"
                                    :value="values[row.id][s.id]"
                                    :disabled="!editable"
                                    maxlength="6"
                                    dir="ltr"
                                    class="w-14 rounded border-slate-200 p-1 text-center font-mono text-xs uppercase dark:border-slate-700 dark:bg-slate-900"
                                    :class="[codeStyle(values[row.id][s.id]), cellError(row.id, s.id) ? 'border-rose-500 ring-1 ring-rose-500' : '']"
                                    :title="cellError(row.id, s.id) ?? ''"
                                    @input="onInput(row.id, s.id, $event)"
                                    @keydown="onKey($event, r, c)"
                                />
                            </template>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter="AttendanceGridTest|AttendancePermissionTest"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/AttendanceGridController.php resources/js/Pages/Attendance/Grid.vue routes/web.php tests/Feature/AttendanceGridTest.php
git commit -m "feat(attendance): month grid entry with paper-sheet codes

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: Full verification

- [ ] **Step 1: Full test suite**

Run: `php artisan test`
Expected: all green. If a dashboard or roles test enumerates `Role::MODULES` or `ActivityAction::AREAS` with a fixed count, update its expectation to include `attendance` and commit that test change on its own.

- [ ] **Step 2: Style**

Run: `vendor/bin/pint --dirty`, then `git diff --stat`.
Commit any formatting fixes: `git add <files>`, then `git commit -m "style(attendance): pint"` with the Co-Authored-By trailer.

- [ ] **Step 3: Frontend build and translations**

Run: `npm run build && npm run i18n:check`
Expected: the build succeeds with no missing-import errors, and the i18n check prints no `does not resolve` lines.

- [ ] **Step 4: Manual run**

Serve the worktree (`php artisan migrate && php artisan serve --port=2027`) and check in the browser, in **ar** and **fr**:
1. As superadmin, Attendance appears in the sidebar under Members.
2. In Settings, add a Monday and Wednesday schedule for one category, a closure, and a pre-season target of 12.
3. On the calendar, the month shows the sessions, the closure days are empty, and the badge shows `0/12`.
4. Add a pre-season session. It opens on the session screen with everyone present. Set one player late (15 min) and one absent excused (illness), then save. The badge on the calendar shows `1/12`.
5. On the month grid, type `R10`, `AN` and `X`. Saving `X` highlights the cell with "Unknown code"; fix it and save. The column shows ✓.
6. Cancel a session with a reason. It shows struck through on the calendar and disappears from the grid.
7. Arrow keys move the right way in Arabic (RTL).

- [ ] **Step 5: Hand-off**

Report to the owner: what shipped, the three spec deviations listed under Global Constraints, and the merge note. The main tree has uncommitted edits to `resources/js/i18n/*.json`, so those must be committed or stashed before merging `feat/attendance-p1`. The deploy needs `php artisan migrate`; the desktop app runs it on boot. Next step: plan P2 (paper sheets).
