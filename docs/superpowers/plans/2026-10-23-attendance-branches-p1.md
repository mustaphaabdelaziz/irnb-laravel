# Attendance branches P1 (data, generator, roster, calendar, settings) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every attendance session and weekly schedule belongs to a branch (sport); a session's roster lists only that branch's members; the calendar and settings work per branch.

**Architecture:** Nullable `branch_id` (null = whole club) on `training_sessions`, `training_schedules`, `deleted_session_slots`, `preseason_targets`, plus a virtual `branch_key = coalesce(branch_id, 0)` used in the unique keys. Generator copies each schedule's branch onto its sessions and compares branch in its slot guards; generation marks stay keyed by category. Roster filters by `branch_player`. Calendar gets a branch switch remembered in the server session.

**Tech Stack:** Laravel 13, PHPUnit feature tests (sqlite :memory:), Inertia + Vue 3 `<script setup>`, Tailwind, vue-i18n flat keys.

**Spec:** `docs/superpowers/specs/2026-10-23-attendance-branches-design.md` (package 1).

## Global Constraints

- Work only in worktree `D:/irnb-branches`, branch `feat/attendance-branches`. Stage explicit file paths only.
- Null `branch_id` = whole club (no branch condition). Forms require a branch whenever `Branch::exists()`.
- No DB foreign-key constraint on the new `branch_id` columns (adding one rebuilds sqlite tables; `training_sessions` has cascading children). Never rebuild an sqlite table in a migration.
- Never run `php artisan migrate --env=testing` (no .env.testing → hits dev DB). Tests use `RefreshDatabase` on sqlite :memory:.
- Before running tests in the worktree: `php artisan config:clear`. If Inertia tests fail with ViteManifestNotFound, run `npm run build` once.
- New UI strings go through `node scripts/i18n-add.mjs keys.json` (ar/fr/en together); never overwrite existing keys. Then `npm run i18n:check`.
- Offline only: no CDN/remote resources.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Run the full suite in the background (`php artisan test --parallel` if available, else `php artisan test`); it takes minutes.

---

### Task 1: Migration, backfill, models, branch delete guard

**Files:**
- Create: `database/migrations/2026_10_23_100001_add_branch_to_attendance_tables.php`
- Modify: `app/Models/TrainingSession.php` (fillable, `branch()` relation)
- Modify: `app/Models/TrainingSchedule.php` (fillable, `branch()` relation)
- Modify: `app/Models/PreseasonTarget.php` (fillable)
- Modify: `app/Http/Controllers/BranchController.php:78-87` (`destroy` guard)
- Test: `tests/Feature/AttendanceBranchMigrationTest.php`

**Interfaces:**
- Produces: columns `branch_id` (nullable unsigned bigint, indexed) on the 4 tables; `branch_key` virtual on `training_sessions`, `deleted_session_slots`, `preseason_targets`; uniques `(branch_key, category_id, date, start_time)` on sessions and deleted slots, `(branch_key, category_id, season_start_year)` on targets. `TrainingSession::branch(): BelongsTo`, `TrainingSchedule::branch(): BelongsTo`. Migration object has public `backfill(): void`. Flash key `flash.branch_has_sessions`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\PreseasonTarget;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceBranchMigrationTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_23_100001_add_branch_to_attendance_tables.php');
    }

    private function session(int $categoryId, ?int $branchId, string $start = '18:00'): TrainingSession
    {
        return TrainingSession::create([
            'branch_id' => $branchId, 'category_id' => $categoryId, 'date' => '2026-10-05', 'start_time' => $start,
            'end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    #[Test]
    public function backfill_assigns_the_branch_with_the_most_players(): void
    {
        $u15 = $this->category();
        $swim = Branch::create(['name' => 'Swim']);
        $foot = Branch::create(['name' => 'Foot']);
        $foot->players()->attach([$this->player($u15)->id, $this->player($u15)->id]);
        $swim->players()->attach([$this->player($u15)->id]);

        $session = $this->session($u15->id, null);
        $schedule = TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01']);
        $target = PreseasonTarget::create(['category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 10]);
        DB::table('deleted_session_slots')->insert(['category_id' => $u15->id, 'date' => '2026-10-12', 'start_time' => '18:00', 'created_at' => now()]);

        $this->migration()->backfill();

        $this->assertSame($foot->id, $session->fresh()->branch_id);
        $this->assertSame($foot->id, $schedule->fresh()->branch_id);
        $this->assertSame($foot->id, $target->fresh()->branch_id);
        $this->assertSame($foot->id, (int) DB::table('deleted_session_slots')->value('branch_id'));
    }

    #[Test]
    public function backfill_leaves_rows_club_wide_when_there_is_no_branch(): void
    {
        $session = $this->session($this->category()->id, null);

        $this->migration()->backfill();

        $this->assertNull($session->fresh()->branch_id);
    }

    #[Test]
    public function two_branches_may_share_a_category_slot_but_one_branch_may_not(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);

        $this->session($u15->id, $foot->id);
        $this->session($u15->id, $swim->id);
        $this->assertSame(2, TrainingSession::count());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->session($u15->id, $foot->id);
    }

    #[Test]
    public function club_wide_sessions_still_collide_on_the_same_slot(): void
    {
        $u15 = $this->category();
        $this->session($u15->id, null);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->session($u15->id, null);
    }

    #[Test]
    public function a_branch_with_sessions_or_schedules_cannot_be_deleted(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $this->session($u15->id, $foot->id);

        $this->actingAs($this->admin())->delete(route('branches.destroy', $foot))
            ->assertSessionHas('error', 'flash.branch_has_sessions');
        $this->assertNotNull($foot->fresh());

        TrainingSession::query()->delete();
        TrainingSchedule::create(['branch_id' => $foot->id, 'category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01']);
        $this->actingAs($this->admin())->delete(route('branches.destroy', $foot))
            ->assertSessionHas('error', 'flash.branch_has_sessions');
    }

    #[Test]
    public function deleting_an_unused_branch_drops_its_targets_and_tombstones(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        PreseasonTarget::create(['branch_id' => $foot->id, 'category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 10]);
        DB::table('deleted_session_slots')->insert(['branch_id' => $foot->id, 'category_id' => $u15->id, 'date' => '2026-10-12', 'start_time' => '18:00', 'created_at' => now()]);

        $this->actingAs($this->admin())->delete(route('branches.destroy', $foot))->assertSessionHas('success');

        $this->assertSame(0, PreseasonTarget::count());
        $this->assertSame(0, DB::table('deleted_session_slots')->count());
    }
}
```

Check the branch destroy route name with `php artisan route:list --name=branches` before running; adjust `branches.destroy` if it differs.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan config:clear && php artisan test --filter=AttendanceBranchMigrationTest`
Expected: FAIL (migration file missing / no `branch_id` column).

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance per branch (sport). `branch_id` null = the whole club, so a
 * club without branches keeps working. No foreign-key constraint: adding
 * one rebuilds the sqlite table, and training_sessions has cascading
 * children; BranchController::destroy() refuses a branch still in use.
 *
 * NULLs never collide in a unique index, and the generator relies on
 * insertOrIgnore against the slot key, so the keys use the virtual
 * `branch_key` (0 = whole club) instead of `branch_id`.
 */
return new class extends Migration
{
    /** Each table's old unique key, which gains the branch (null: no unique key). */
    private const KEYS = [
        'training_sessions' => ['category_id', 'date', 'start_time'],
        'training_schedules' => null,
        'deleted_session_slots' => ['category_id', 'date', 'start_time'],
        'preseason_targets' => ['category_id', 'season_start_year'],
    ];

    public function up(): void
    {
        foreach (self::KEYS as $table => $key) {
            if (! Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('branch_id')->nullable()->index());
            }
            if ($key === null || Schema::hasColumn($table, 'branch_key')) {
                continue;
            }
            Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('branch_key')->virtualAs('coalesce(branch_id, 0)'));
            Schema::table($table, function (Blueprint $t) use ($key) {
                // MySQL needs an index starting with category_id for its foreign key once the old unique is gone.
                $t->index('category_id');
                $t->unique(['branch_key', ...$key]);
                $t->dropUnique($key);
            });
        }

        $this->backfill();
    }

    /** Every row made before branches mattered goes to the branch with the most players (ties: lowest id). */
    public function backfill(): void
    {
        $branchId = DB::table('branches')
            ->leftJoin('branch_player', 'branch_player.branch_id', '=', 'branches.id')
            ->groupBy('branches.id')
            ->orderByRaw('count(branch_player.player_id) desc')
            ->orderBy('branches.id')
            ->value('branches.id');
        if ($branchId === null) {
            return;
        }

        foreach (array_keys(self::KEYS) as $table) {
            DB::table($table)->whereNull('branch_id')->update(['branch_id' => $branchId]);
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as $table => $key) {
            if ($key !== null) {
                Schema::table($table, function (Blueprint $t) use ($key) {
                    $t->unique($key);
                    $t->dropUnique(['branch_key', ...$key]);
                    $t->dropIndex(['category_id']);
                });
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('branch_key'));
            }
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['branch_id']);
                $t->dropColumn('branch_id');
            });
        }
    }
};
```

- [ ] **Step 4: Models**

`app/Models/TrainingSession.php`: add `'branch_id'` first in `$fillable`; add after `category()`:

```php
    /** The branch (sport) whose members make the roster; null = the whole club. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
```

Update the class docblock's first sentence to: "One training of one branch on one date." and the `booted()` comment stays.

`app/Models/TrainingSchedule.php`: `$fillable = ['branch_id', 'category_id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_to'];` plus the same `branch()` relation (docblock: "The branch whose sessions this slot generates; null = the whole club."). Class docblock: "One weekly training slot of a branch and category, valid over a date range."

`app/Models/PreseasonTarget.php`: add `'branch_id'` to `$fillable`.

- [ ] **Step 5: Branch delete guard**

In `BranchController::destroy`, after the teams check:

```php
        // Attendance rows have no foreign key on branch_id (see the add_branch_to_attendance_tables migration).
        if (TrainingSession::where('branch_id', $branch->id)->exists() || TrainingSchedule::where('branch_id', $branch->id)->exists()) {
            return back()->with('error', 'flash.branch_has_sessions');
        }
        $files->delete($branch->logo_filename);
        DB::transaction(function () use ($branch) {
            PreseasonTarget::where('branch_id', $branch->id)->delete();
            DB::table('deleted_session_slots')->where('branch_id', $branch->id)->delete();
            $branch->delete();
        });
```

(replacing the existing `$files->delete(...)` and `$branch->delete();` lines; add the `use` imports for `TrainingSession`, `TrainingSchedule`, `PreseasonTarget`, `Illuminate\Support\Facades\DB`).

Add the i18n key (file `C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/f5180bdf-51af-4cd4-a47b-5d8543b2a1bb/scratchpad/keys-p1-t1.json`):

```json
{
    "flash.branch_has_sessions": {
        "ar": "لهذا الفرع حصص تدريب أو برنامج أسبوعي ولا يمكن حذفه.",
        "fr": "Cette section a des séances ou un planning d'entraînement et ne peut pas être supprimée.",
        "en": "This branch has training sessions or a weekly schedule and cannot be deleted."
    }
}
```

Run: `node scripts/i18n-add.mjs <that path> && npm run i18n:check`

- [ ] **Step 6: Run tests**

Run: `php artisan test --filter=AttendanceBranchMigrationTest`
Expected: PASS (6 tests). Also run `php artisan test --filter=BranchFeatureTest` → PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_10_23_100001_add_branch_to_attendance_tables.php app/Models/TrainingSession.php app/Models/TrainingSchedule.php app/Models/PreseasonTarget.php app/Http/Controllers/BranchController.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/AttendanceBranchMigrationTest.php
git commit -m "feat(attendance): sessions, schedules and targets belong to a branch"
```

---

### Task 2: Generator per branch

**Files:**
- Modify: `app/Services/Attendance/SessionGenerator.php` (`generate()`, class docblock)
- Test: `tests/Feature/SessionGeneratorBranchTest.php`

**Interfaces:**
- Consumes: Task 1 columns.
- Produces: generated rows carry `branch_id` = their schedule's. Public API unchanged (`forMonth(int, int, int): int`, `forRange(string, string): void`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Attendance\GenerationMarks;
use App\Services\Attendance\SessionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class SessionGeneratorBranchTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function schedule(int $categoryId, ?int $branchId): TrainingSchedule
    {
        return TrainingSchedule::create([
            'branch_id' => $branchId, 'category_id' => $categoryId, 'weekday' => 1,
            'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01',
        ]);
    }

    /** @return list<string> "branchId date" of every session, sorted */
    private function slots(): array
    {
        return TrainingSession::orderBy('date')->orderBy('branch_id')->get()
            ->map(fn ($s) => ($s->branch_id ?? 0).' '.$s->date)->all();
    }

    #[Test]
    public function each_branch_gets_its_own_sessions_at_the_same_slot(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $this->schedule($u15->id, $foot->id);
        $this->schedule($u15->id, $swim->id);

        // October 2026 Mondays: 5, 12, 19, 26.
        $this->assertSame(8, app(SessionGenerator::class)->forMonth($u15->id, 2026, 10));
        $this->assertSame(4, TrainingSession::where('branch_id', $foot->id)->count());
        $this->assertSame(4, TrainingSession::where('branch_id', $swim->id)->count());
    }

    #[Test]
    public function a_deleted_or_moved_slot_of_one_branch_leaves_the_other_branch_alone(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $this->schedule($u15->id, $foot->id);
        $this->schedule($u15->id, $swim->id);
        DB::table('deleted_session_slots')->insert(['branch_id' => $foot->id, 'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'created_at' => now()]);
        TrainingSession::create([
            'branch_id' => $swim->id, 'category_id' => $u15->id, 'date' => '2026-10-13', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned, 'moved_from' => '2026-10-12',
        ]);

        app(SessionGenerator::class)->forMonth($u15->id, 2026, 10);

        $this->assertSame(0, TrainingSession::where('branch_id', $foot->id)->where('date', '2026-10-05')->count());
        $this->assertSame(1, TrainingSession::where('branch_id', $swim->id)->where('date', '2026-10-05')->count());
        $this->assertSame(1, TrainingSession::where('branch_id', $foot->id)->where('date', '2026-10-12')->count());
        $this->assertSame(0, TrainingSession::where('branch_id', $swim->id)->where('date', '2026-10-12')->count());
    }

    #[Test]
    public function club_wide_schedules_stay_idempotent_after_marks_are_forgotten(): void
    {
        $u15 = $this->category();
        $this->schedule($u15->id, null);
        $generator = app(SessionGenerator::class);

        $generator->forMonth($u15->id, 2026, 10);
        GenerationMarks::forgetAll();
        $this->assertSame(0, $generator->forMonth($u15->id, 2026, 10));
        $this->assertSame(['0 2026-10-05', '0 2026-10-12', '0 2026-10-19', '0 2026-10-26'], $this->slots());
    }

    #[Test]
    public function for_range_copies_the_schedule_branch(): void
    {
        $u15 = $this->category();
        $swim = Branch::create(['name' => 'Swim']);
        $this->schedule($u15->id, $swim->id);

        app(SessionGenerator::class)->forRange('2026-10-01', '2026-10-31');

        $this->assertSame([$swim->id], TrainingSession::distinct()->pluck('branch_id')->all());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SessionGeneratorBranchTest`
Expected: FAIL (`branch_id` null on generated rows; deleted/moved guards ignore branch).

- [ ] **Step 3: Implement**

In `SessionGenerator::generate()` replace the `$moved`, `$joint`, `$deleted` lookups and the inner loop guard/row:

```php
        $moved = TrainingSession::where('category_id', $categoryId)
            ->whereNotNull('moved_from')
            ->whereBetween('moved_from', [$from, $to])
            ->get(['branch_id', 'moved_from'])
            ->mapWithKeys(fn (TrainingSession $s) => [self::slotKey($s->branch_id, $s->moved_from) => true]);

        $joint = DB::table('training_sessions')
            ->join('training_session_category', 'training_session_category.training_session_id', '=', 'training_sessions.id')
            ->where('training_session_category.category_id', $categoryId)
            ->where('training_sessions.category_id', '!=', $categoryId)
            ->whereBetween('training_sessions.date', [$from, $to])
            ->get(['training_sessions.branch_id', 'training_sessions.date', 'training_sessions.start_time'])
            ->mapWithKeys(fn ($s) => [self::slotKey($s->branch_id, "{$s->date} {$s->start_time}") => true]);

        $deleted = DB::table('deleted_session_slots')
            ->where('category_id', $categoryId)
            ->whereBetween('date', [$from, $to])
            ->get(['branch_id', 'date', 'start_time'])
            ->mapWithKeys(fn ($s) => [self::slotKey($s->branch_id, "{$s->date} {$s->start_time}") => true]);
```

Inner loop:

```php
            foreach ($schedules as $schedule) {
                $slot = "{$date} {$schedule->start_time}";
                if ($schedule->weekday !== $day->dayOfWeekIso
                    || $schedule->valid_from > $date
                    || ($schedule->valid_to !== null && $schedule->valid_to < $date)
                    || isset($moved[self::slotKey($schedule->branch_id, $date)])
                    || isset($joint[self::slotKey($schedule->branch_id, $slot)])
                    || isset($deleted[self::slotKey($schedule->branch_id, $slot)])) {
                    continue;
                }

                $rows[] = [
                    'branch_id' => $schedule->branch_id,
                    'category_id' => $categoryId,
                    // … rest unchanged
```

Add at the end of the class:

```php
    /** A slot of one branch (0 = the whole club), the way the unique key's branch_key sees it. */
    private static function slotKey(int|string|null $branchId, string $slot): string
    {
        return ((int) $branchId).'|'.$slot;
    }
```

Class docblock: replace "the unique (category, date, start) key" with "the unique (branch, category, date, start) key", and add after the first sentence: "Each session takes its schedule's branch; moved, joint and deleted slots only block the same branch."

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter="SessionGenerator|SessionGenerationMarks|AttendanceJointCalendar|PreseasonMultiCategory"`
Expected: PASS (new and existing).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/SessionGenerator.php tests/Feature/SessionGeneratorBranchTest.php
git commit -m "feat(attendance): generate each schedule's sessions in its branch"
```

---

### Task 3: Hand-added sessions, slot check, delete tombstone

**Files:**
- Modify: `app/Http/Controllers/TrainingSessionController.php` (`store`, `updateCategories`, `destroy`, `move`, `assertSlotFree`)
- Test: `tests/Feature/AttendanceSessionBranchTest.php`

**Interfaces:**
- Consumes: Task 1 columns.
- Produces: `POST attendance.sessions.store` accepts `branch_id` (required when a branch exists). `assertSlotFree(?int $branchId, array $categoryIds, string $date, string $startTime, ?int $ignoreId = null, string $errorKey = 'start_time'): void`. Destroy redirect carries `branch_id`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceSessionBranchTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function payload(int $categoryId, array $extra = []): array
    {
        return $extra + ['category_id' => $categoryId, 'kind' => 'extra', 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30'];
    }

    #[Test]
    public function a_hand_added_session_needs_a_branch_once_branches_exist(): void
    {
        $u15 = $this->category();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->payload($u15->id))->assertSessionHasErrors('branch_id');

        $foot = Branch::create(['name' => 'Foot']);
        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->payload($u15->id, ['branch_id' => $foot->id]))->assertSessionHasNoErrors();
        $this->assertSame($foot->id, TrainingSession::sole()->branch_id);
    }

    #[Test]
    public function without_branches_a_session_is_club_wide(): void
    {
        $u15 = $this->category();
        $this->actingAs($this->admin())->post(route('attendance.sessions.store'), $this->payload($u15->id))->assertSessionHasNoErrors();
        $this->assertNull(TrainingSession::sole()->branch_id);
    }

    #[Test]
    public function the_slot_check_compares_branches(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->payload($u15->id, ['branch_id' => $foot->id]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->payload($u15->id, ['branch_id' => $swim->id]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->payload($u15->id, ['branch_id' => $foot->id]))
            ->assertSessionHasErrors(['start_time' => 'att.error.duplicate']);
    }

    #[Test]
    public function deleting_a_regular_session_tombstones_its_branch_slot(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $session = TrainingSession::create([
            'branch_id' => $foot->id, 'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);

        $this->actingAs($this->admin())->delete(route('attendance.sessions.destroy', $session))
            ->assertRedirect(route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-10', 'branch_id' => $foot->id]));

        $this->assertSame($foot->id, (int) DB::table('deleted_session_slots')->value('branch_id'));
    }
}
```


- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AttendanceSessionBranchTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

`store()` validation, add first:

```php
            // Required once the club has branches; null = the whole club.
            'branch_id' => [Rule::requiredIf(fn () => Branch::exists()), 'nullable', 'integer', 'exists:branches,id'],
```

After the validate call: `$branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;` and call `$this->assertSlotFree($branchId, $categoryIds, $data['date'], $data['start_time']);`. `TrainingSession::create(Arr::except($data, 'category_ids') + ...)` already carries `branch_id`.

`updateCategories()`: `$this->assertSlotFree($session->branch_id, $ids, $session->date, $session->start_time, $session->id, 'category_ids');`

`move()`: `$this->assertSlotFree($session->branch_id, $session->categoryIds(), $data['date'], $data['start_time'], $session->id);`

`destroy()`: `$back = ['category_id' => $session->category_id, 'month' => substr($session->date, 0, 7), 'branch_id' => $session->branch_id];` and the tombstone rows gain `'branch_id' => $session->branch_id,` (first key).

`assertSlotFree()`:

```php
    /**
     * No other session of the same branch that any of these categories takes
     * part in may start at the same date and time (other branches may). The
     * pivot holds every category, primary or not, so this also covers the
     * unique (branch, category, date, start) key.
     *
     * @param  array<int, int>  $categoryIds
     */
    private function assertSlotFree(?int $branchId, array $categoryIds, string $date, string $startTime, ?int $ignoreId = null, string $errorKey = 'start_time'): void
    {
        $taken = TrainingSession::where('branch_id', $branchId) // null → whereNull
            ->where('date', $date)->where('start_time', $startTime)
            ->whereIn('id', DB::table('training_session_category')->select('training_session_id')->whereIn('category_id', $categoryIds))
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
```

(rest unchanged). Import `App\Models\Branch`. Update the class/`store()` docblock: "An extra or pre-season session added by hand for one branch (regular ones come from the schedule)."

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter="AttendanceSessionBranch|AttendanceSessionDelete|AttendanceSession|TrainingSessionTitle|PreseasonMultiCategory|AttendanceRosterStatus"`
Expected: PASS. Existing tests posting without `branch_id` keep passing because they create no branch; if one fails because a seeder creates a branch, add `'branch_id' => Branch::value('id')` to that test's payload.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/TrainingSessionController.php tests/Feature/AttendanceSessionBranchTest.php
git commit -m "feat(attendance): hand-added sessions pick a branch; slots clash per branch"
```

---

### Task 4: Roster, grid and month sheet per branch; session page

**Files:**
- Modify: `app/Services/Attendance/Roster.php` (`expected()`, `forSession()`, docblock)
- Modify: `app/Http/Controllers/AttendanceGridController.php:83`
- Modify: `app/Services/Attendance/MonthSheet.php:56-59`
- Modify: `app/Http/Controllers/AttendanceController.php` (`show()`: branch, candidates order/tag, lastCoach)
- Modify: `resources/js/Pages/Attendance/Session.vue` (header branch, back link, candidate tag)
- Test: `tests/Feature/AttendanceRosterBranchTest.php`

**Interfaces:**
- Consumes: Task 1 `TrainingSession::branch()`.
- Produces: `Roster::expected(int|array $categoryIds, string $date, ?array $statusIds = null, ?int $branchId = null): Collection`. Session page props: `session.branch` (`{id, name, logo_url}|null`), candidates gain `branches` (string) and `in_branch` (bool).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\TrainingSession;
use App\Services\Attendance\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceRosterBranchTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function session(int $categoryId, ?int $branchId): TrainingSession
    {
        return TrainingSession::create([
            'branch_id' => $branchId, 'category_id' => $categoryId, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    #[Test]
    public function the_roster_lists_only_members_of_the_session_branch(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $footballer = $this->player($u15);
        $swimmer = $this->player($u15);
        $both = $this->player($u15);
        $foot->players()->attach([$footballer->id, $both->id]);
        $swim->players()->attach([$swimmer->id, $both->id]);

        $roster = app(Roster::class);
        $this->assertSame([$footballer->id, $both->id], $roster->forSession($this->session($u15->id, $foot->id))->pluck('id')->all());
        $this->assertSame([$swimmer->id, $both->id], $roster->forSession($this->session($u15->id, $swim->id))->pluck('id')->all());
    }

    #[Test]
    public function a_club_wide_session_lists_every_member_of_the_category(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $b = $this->player($u15);

        $this->assertSame([$a->id, $b->id], app(Roster::class)->forSession($this->session($u15->id, null))->pluck('id')->all());
    }

    #[Test]
    public function the_session_page_shows_the_branch_and_lists_its_players_first_among_candidates(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $swimmer = $this->player($u17);   // Test001: alphabetically first
        $footballer = $this->player($u17); // Test002
        $swim->players()->attach($swimmer->id);
        $foot->players()->attach($footballer->id);
        $session = $this->session($u15->id, $foot->id);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertInertia(fn (Assert $page) => $page
                ->where('session.branch.id', $foot->id)
                ->where('candidates.0.id', $footballer->id)
                ->where('candidates.0.in_branch', true)
                ->where('candidates.1.id', $swimmer->id)
                ->where('candidates.1.in_branch', false)
                ->where('candidates.1.branches', 'Swim'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AttendanceRosterBranchTest`
Expected: FAIL.

- [ ] **Step 3: Roster**

```php
    /**
     * A player has one category, so several categories never list anyone twice.
     *
     * @param  int|array<int, int>  $categoryIds
     * @param  list<int>|null  $statusIds  the roster set; null = the settings' default
     * @param  int|null  $branchId  only members of this branch; null = the whole club
     * @return Collection<int, Player>
     */
    public function expected(int|array $categoryIds, string $date, ?array $statusIds = null, ?int $branchId = null): Collection
    {
        $statusIds ??= $this->defaultStatusIds();

        return Player::whereIn('category_id', (array) $categoryIds)
            ->when($branchId !== null, fn ($q) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId)))
            ->where('archived', false)
            // … rest unchanged
```

`forSession()`: `return $this->expected($session->categoryIds(), $session->date, $this->statusIdsFor($session), $session->branch_id);`

Class docblock first sentence becomes: "Who is expected at a session. Before the first save it is the members of the session's branch (any branch for a club-wide session) in every category of the session on that date whose player status is in the session's roster set; after, it is exactly the saved marks."

- [ ] **Step 4: Grid and month sheet**

`AttendanceGridController.php:83`: `: $roster->expected($session->categoryIds(), $session->date, $roster->statusIdsFor($session), $session->branch_id)->modelKeys();`

`MonthSheet.php`:

```php
            $key = $session->branch_id.'|'.$session->date.'|'.implode(',', $categoryIds).'|'.implode(',', $statusIds);
            $expected[$key] ??= $this->roster->expected($categoryIds, $session->date, $statusIds, $session->branch_id)->modelKeys();
```

- [ ] **Step 5: Session page controller**

In `AttendanceController::show()`, `'session' => [` gains:

```php
                'branch_id' => $session->branch_id,
                'branch' => $session->branch ? ['id' => $session->branch->id, 'name' => $session->branch->localized_name, 'logo_url' => $session->branch->logo_url] : null,
```

Candidates: change `Player::with(['status', 'category'])` to `Player::with(['status', 'category', 'branches'])`, and replace the `->map(...)` at the end with:

```php
                // The session's branch members first (a stable sort keeps the name order); others are guests.
                ->sortBy(fn (Player $p) => $session->branch_id !== null && ! $p->branches->contains('id', $session->branch_id))
                ->map(fn (Player $p) => [
                    'id' => $p->id,
                    'name' => $p->fullname,
                    'picture' => $p->picture_url,
                    'status' => $p->status?->localized_name,
                    'category' => $p->category?->localized_name,
                    'branches' => $p->branches->map(fn ($b) => $b->localized_name)->implode(', '),
                    'in_branch' => $session->branch_id === null || $p->branches->contains('id', $session->branch_id),
                ])
                ->values(),
```

Update the candidates comment: "Anyone to add by hand, whatever their status, category or branch: …; the session's branch members first."

`lastCoach`: add `->where('branch_id', $session->branch_id)` after `TrainingSession::where('category_id', $session->category_id)`.

- [ ] **Step 6: Session.vue**

Header `<h1>`: prefix the branch:

```vue
                        <h1 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-slate-100">
                            <img v-if="session.branch?.logo_url" :src="session.branch.logo_url" alt="" class="h-6 w-6 rounded object-contain" />
                            <span>{{ [session.branch?.name, session.category].filter(Boolean).join(' · ') }} · {{ t(`att.kind.${session.kind}`) }}</span>
                        </h1>
```

Back link: `route('attendance.index', { category_id: session.category_id, month: session.date.slice(0, 7), branch_id: session.branch_id ?? undefined })`.

Candidate row second span:

```vue
                                <span class="text-xs text-slate-500">{{ [c.in_branch ? null : c.branches, c.category, c.status].filter(Boolean).join(' · ') }}</span>
```

- [ ] **Step 7: Run tests**

Run: `php artisan test --filter="AttendanceRosterBranch|AttendanceRosterStatus|AttendanceGrid|AttendanceMonthSheet|AttendanceSession|AttendanceFullNames"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Attendance/Roster.php app/Http/Controllers/AttendanceGridController.php app/Services/Attendance/MonthSheet.php app/Http/Controllers/AttendanceController.php resources/js/Pages/Attendance/Session.vue tests/Feature/AttendanceRosterBranchTest.php
git commit -m "feat(attendance): a session's roster is its branch's members"
```

---

### Task 5: Calendar branch switch, feed, pre-season progress, add dialog

**Files:**
- Modify: `app/Http/Controllers/AttendanceCalendarController.php`
- Modify: `app/Services/Attendance/CalendarFeed.php` (`sessions()`, `timeline()`)
- Modify: `app/Services/Attendance/PreseasonProgress.php` (`forCategory()`, `milestones()`, `held()`)
- Modify: `resources/js/Pages/Attendance/Index.vue`
- Modify: `resources/js/Pages/Attendance/Partials/AddSessionModal.vue`
- Test: `tests/Feature/AttendanceCalendarBranchTest.php`

**Interfaces:**
- Consumes: Task 3 `branch_id` on store.
- Produces: `CalendarFeed::sessions(string $from, string $to, ?int $categoryId = null, ?string $kind = null, ?int $branchId = null)`, `CalendarFeed::timeline(string $from, string $to, ?int $categoryId = null, ?string $kind = null, ?int $branchId = null)`, `PreseasonProgress::forCategory(int $categoryId, DateTimeInterface|string $date, ?int $branchId = null)`, `PreseasonProgress::milestones(string $from, string $to, ?int $categoryId = null, ?int $branchId = null)`. Inertia props `branches` (`[{id, name, logo_url}]`) and `branchId` (int|null) on `Attendance/Index`; session key `attendance.branch_id`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\PreseasonTarget;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceCalendarBranchTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function session(int $categoryId, int $branchId, array $extra = []): TrainingSession
    {
        return TrainingSession::create($extra + [
            'branch_id' => $branchId, 'category_id' => $categoryId, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Extra, 'state' => SessionState::Planned,
        ]);
    }

    #[Test]
    public function every_view_shows_the_chosen_branch_only(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $f = $this->session($u15->id, $foot->id);
        $s = $this->session($u15->id, $swim->id);
        $admin = $this->admin();

        foreach (['month', 'week', 'agenda'] as $view) {
            $this->actingAs($admin)->get(route('attendance.index', ['view' => $view, 'category_id' => $u15->id, 'month' => '2026-10', 'date' => '2026-10-05', 'branch_id' => $swim->id]))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('branchId', $swim->id)
                    ->has('branches', 2)
                    ->where('sessions', fn ($sessions) => collect($sessions)->pluck('id')->all() === [$s->id]));
        }
        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'timeline', 'month' => '2026-10', 'branch_id' => $foot->id]))
            ->assertInertia(fn (Assert $page) => $page->where('events', fn ($events) => collect($events)->where('type', 'session')->pluck('id')->all() === [$f->id]));
    }

    #[Test]
    public function the_branch_defaults_to_the_first_then_is_remembered(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.index', ['category_id' => $u15->id]))
            ->assertInertia(fn (Assert $page) => $page->where('branchId', $foot->id));
        $this->actingAs($admin)->get(route('attendance.index', ['category_id' => $u15->id, 'branch_id' => $swim->id]));
        $this->actingAs($admin)->get(route('attendance.index', ['category_id' => $u15->id]))
            ->assertInertia(fn (Assert $page) => $page->where('branchId', $swim->id));
    }

    #[Test]
    public function without_branches_the_calendar_shows_everything(): void
    {
        $u15 = $this->category();
        TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Extra, 'state' => SessionState::Planned,
        ]);

        $this->actingAs($this->admin())->get(route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page->where('branchId', null)->has('sessions', 1));
    }

    #[Test]
    public function pre_season_progress_counts_the_branch_only(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        PreseasonTarget::create(['branch_id' => $foot->id, 'category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 10]);
        PreseasonTarget::create(['branch_id' => $swim->id, 'category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 4]);
        $this->session($u15->id, $foot->id, ['kind' => SessionKind::Preseason, 'state' => SessionState::Held, 'date' => '2026-09-07']);

        $this->actingAs($this->admin())->get(route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-09', 'branch_id' => $swim->id]))
            ->assertInertia(fn (Assert $page) => $page->where('preseason.done', 0)->where('preseason.target', 4));
    }
}
```


- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AttendanceCalendarBranchTest`
Expected: FAIL (no `branchId` prop).

- [ ] **Step 3: PreseasonProgress**

```php
    /**
     * @param  int|null  $branchId  null = sessions of every branch, and the highest target any branch set
     * @return array{season: string, done: int, target: ?int}
     */
    public function forCategory(int $categoryId, DateTimeInterface|string $date, ?int $branchId = null): array
    {
        $season = Season::forDate($date);
        $target = PreseasonTarget::where('category_id', $categoryId)
            ->where('season_start_year', $season->startYear)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->max('target_count');

        return [
            'season' => $season->label(),
            'done' => $this->held($categoryId, $season, $branchId)->count(),
            'target' => $target === null ? null : (int) $target,
        ];
    }
```

`held()`:

```php
    private function held(int $categoryId, Season $season, ?int $branchId = null): Builder
    {
        return TrainingSession::query()
            ->includingCategory($categoryId)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            // … rest unchanged
```

`milestones(string $from, string $to, ?int $categoryId = null, ?int $branchId = null)`: targets query becomes

```php
            $targets = PreseasonTarget::where('season_start_year', $season->startYear)
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                ->selectRaw('category_id, max(target_count) as target_count')
                ->groupBy('category_id')
                ->pluck('target_count', 'category_id');
```

and `$heldByCategory` gains `->when($branchId !== null, fn ($q) => $q->where('training_sessions.branch_id', $branchId))` after the category `when`. Class docblock: "Pre-season progress per branch, category and season."

- [ ] **Step 4: CalendarFeed**

`sessions(string $from, string $to, ?int $categoryId = null, ?string $kind = null, ?int $branchId = null)`: add `->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))` after the kind `when`.

`timeline(string $from, string $to, ?int $categoryId = null, ?string $kind = null, ?int $branchId = null)`: pass `$branchId` to `$this->sessions(...)` and to `$this->preseason->milestones($from, $to, $categoryId, $branchId)`.

- [ ] **Step 5: Calendar controller**

- `VIEW_PROPS`: add `'branchId'` to every view's list.
- `index(Request $request)`: validate `'branch_id' => ['nullable', 'integer', 'exists:branches,id']`; then `$data['branch_id'] = $this->branchId($request, $data);` before `$view = …`.
- Render props gain:

```php
            // The branch switch; lazy like `categories`.
            'branches' => fn () => Branch::orderBy('id')->get()
                ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->localized_name, 'logo_url' => $b->logo_url])->values(),
```

- Each view method returns `'branchId' => $data['branch_id']` and passes it on:
  - `month()`: `$this->feed->sessions(..., $categoryId, null, $data['branch_id'])`, `$this->preseason->forCategory($categoryId, $anchor, $data['branch_id'])`, `'hasSchedule' => TrainingSchedule::where('category_id', $categoryId)->where('branch_id', $data['branch_id'])->exists()`; the early-return `$props` array includes `'branchId' => $data['branch_id']`.
  - `week()`: `$this->feed->sessions($start->toDateString(), $end->toDateString(), branchId: $data['branch_id'])`.
  - `agenda()`: `$this->feed->sessions($from, $to, $categoryId, branchId: $data['branch_id'])`.
  - `timeline()`: `$this->feed->timeline($fromDate, $toDate, $categoryId, $kind, $data['branch_id'])`.
- New method:

```php
    /**
     * The branch on screen: the one asked for (remembered in the session),
     * else the remembered one if it still exists, else the first; null when
     * the club has no branch (every session then shows).
     */
    private function branchId(Request $request, array $data): ?int
    {
        if (isset($data['branch_id'])) {
            $request->session()->put('attendance.branch_id', (int) $data['branch_id']);

            return (int) $data['branch_id'];
        }
        $remembered = $request->session()->get('attendance.branch_id');
        if ($remembered !== null && Branch::whereKey($remembered)->exists()) {
            return (int) $remembered;
        }

        $first = Branch::orderBy('id')->value('id');

        return $first === null ? null : (int) $first;
    }
```

Import `App\Models\Branch`. Class docblock: add "Every view shows one branch (see branchId())."

- [ ] **Step 6: Index.vue**

- Props: `branches: { type: Array, default: () => [] }, branchId: { type: Number, default: null },`.
- `VIEW_PROPS`: add `'branchId'` to each list.
- `navigate()`: `clean({ view: props.view, category_id: props.categoryId, branch_id: props.branchId, ...params })`.
- New: `const switchBranch = (id) => id !== props.branchId && navigate({ branch_id: id, month: props.month, date: props.week?.start });`
- Toolbar (inside the `flex flex-wrap items-center justify-between gap-3` div, before `<ViewSwitcher>`), wrap left side:

```vue
                <div class="flex flex-wrap items-center gap-2">
                    <div v-if="branches.length > 1" role="tablist" :aria-label="t('branch')" class="inline-flex rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800">
                        <button
                            v-for="b in branches"
                            :key="b.id"
                            type="button"
                            role="tab"
                            :aria-selected="branchId === b.id"
                            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-sm font-semibold"
                            :class="branchId === b.id ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-slate-100' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'"
                            @click="switchBranch(b.id)"
                        >
                            <img v-if="b.logo_url" :src="b.logo_url" alt="" class="h-4 w-4 rounded object-contain" />
                            {{ b.name }}
                        </button>
                    </div>
                    <ViewSwitcher :view="view" @switch="switchView" />
                </div>
```

- `<AddSessionModal>` gains `:branches="branches" :branch-id="branchId"`.

- [ ] **Step 7: AddSessionModal.vue**

- Props: `branches: { type: Array, default: () => [] }, branchId: { type: Number, default: null }, // pre-selected (the calendar's switch)`.
- `useForm({ branch_id: null, category_id: null, … })`; in the `watch` `Object.assign`: `branch_id: props.branchId ?? props.branches[0]?.id ?? null,`.
- Before the category `<label>`:

```vue
            <label v-if="branches.length" class="block text-sm">{{ t('branch') }}
                <select v-model="form.branch_id" :class="[input, 'mt-1 block w-full']">
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </label>
```

- [ ] **Step 8: Run tests**

Run: `php artisan test --filter="AttendanceCalendar|AttendanceWeekView|AttendanceAgendaView|AttendanceTimelineView|AttendanceJointCalendar|AttendancePlayerCard|PreseasonMultiCategory"`
Expected: PASS. `AttendanceCalendarPerformanceTest` counts queries: if it fails only because of the one extra `branches` lookup, raise its budget by exactly the new queries and say so in the commit message.

Then `npm run build` → succeeds.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/AttendanceCalendarController.php app/Services/Attendance/CalendarFeed.php app/Services/Attendance/PreseasonProgress.php resources/js/Pages/Attendance/Index.vue resources/js/Pages/Attendance/Partials/AddSessionModal.vue tests/Feature/AttendanceCalendarBranchTest.php
git commit -m "feat(attendance): branch switch on the calendar and in the add-session dialog"
```

---

### Task 6: Settings — schedules and pre-season targets per branch

**Files:**
- Modify: `app/Http/Controllers/AttendanceSettingsController.php` (`index`, `storeTarget`, `validateSchedule`)
- Modify: `resources/js/Pages/Attendance/Settings.vue` (schedule form + table, target form)
- Test: `tests/Feature/AttendanceSettingsBranchTest.php`

**Interfaces:**
- Consumes: Task 1 fillables.
- Produces: settings props `branches` (`[{id, name}]`), `schedules[].branch_id`, `targets[].branch_id`; schedule and target writes accept `branch_id` (required when a branch exists).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PreseasonTarget;
use App\Models\TrainingSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceSettingsBranchTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function schedulePayload(int $categoryId, array $extra = []): array
    {
        return $extra + ['category_id' => $categoryId, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01'];
    }

    #[Test]
    public function a_schedule_needs_a_branch_once_branches_exist(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.schedules.store'), $this->schedulePayload($u15->id))->assertSessionHasErrors('branch_id');
        $this->actingAs($admin)->post(route('attendance.schedules.store'), $this->schedulePayload($u15->id, ['branch_id' => $foot->id]))->assertSessionHasNoErrors();
        $this->assertSame($foot->id, TrainingSchedule::sole()->branch_id);
    }

    #[Test]
    public function targets_are_kept_per_branch(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $admin = $this->admin();

        foreach ([[$foot->id, 10], [$swim->id, 4], [$foot->id, 12]] as [$branchId, $count]) {
            $this->actingAs($admin)->post(route('attendance.preseason-targets.store'), [
                'branch_id' => $branchId, 'category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => $count,
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame([$foot->id => 12, $swim->id => 4], PreseasonTarget::orderBy('branch_id')->pluck('target_count', 'branch_id')->all());
    }

    #[Test]
    public function the_page_lists_branches_and_each_schedule_branch(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        TrainingSchedule::create($this->schedulePayload($u15->id, ['branch_id' => $foot->id]));

        $this->actingAs($this->admin())->get(route('attendance.settings'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('branches.0.id', $foot->id)
                ->where('schedules.0.branch_id', $foot->id));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AttendanceSettingsBranchTest`
Expected: FAIL.

- [ ] **Step 3: Controller**

`index()` props:

```php
            'branches' => Branch::orderBy('id')->get()->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->localized_name])->values(),
            'schedules' => TrainingSchedule::orderBy('branch_id')->orderBy('category_id')->orderBy('weekday')->orderBy('start_time')
                ->get(['id', 'branch_id', 'category_id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_to']),
            …
            'targets' => PreseasonTarget::get(['branch_id', 'category_id', 'season_start_year', 'target_count']),
```

`validateSchedule()` and `storeTarget()` rules gain first:

```php
            'branch_id' => [Rule::requiredIf(fn () => Branch::exists()), 'nullable', 'integer', 'exists:branches,id'],
```

`storeTarget()` upsert key: `['branch_id' => $data['branch_id'] ?? null, 'category_id' => $data['category_id'], 'season_start_year' => $data['season_start_year']]` (Eloquent turns `branch_id => null` into `whereNull`).

Import `App\Models\Branch`.

- [ ] **Step 4: Settings.vue**

- Prop `branches: { type: Array, default: () => [] },`; helper `const branchName = (id) => props.branches.find((b) => b.id === id)?.name ?? '';`.
- `scheduleForm = useForm({ branch_id: props.branches[0]?.id ?? null, category_id: …` ; `editSchedule(s)` assigns `branch_id: s.branch_id`.
- Schedule form, before the category `<label>`:

```vue
                    <label v-if="branches.length" class="text-xs text-slate-500">{{ t('branch') }}
                        <select v-model="scheduleForm.branch_id" :class="[input, 'block']"><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                    </label>
```

- Schedule table: first cell becomes `<td class="py-2 font-medium">{{ [branchName(s.branch_id), categoryName(s.category_id)].filter(Boolean).join(' · ') }}</td>`.
- `targetForm = useForm({ branch_id: props.branches[0]?.id ?? null, category_id: …` ; watch:

```js
watch(() => [targetForm.branch_id, targetForm.category_id, targetForm.season_start_year], ([b, c, y]) => {
    targetForm.target_count = props.targets.find((x) => (x.branch_id ?? null) === b && x.category_id === c && x.season_start_year === y)?.target_count ?? 0;
```

(keep the watch's existing options/closing lines).
- Target form: same branch `<select v-model="targetForm.branch_id">` label before the category select.

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter="AttendanceSettings"` → PASS. `npm run build` → succeeds.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AttendanceSettingsController.php resources/js/Pages/Attendance/Settings.vue tests/Feature/AttendanceSettingsBranchTest.php
git commit -m "feat(attendance): weekly schedules and pre-season targets per branch"
```

---

### Task 7: Full verification

- [ ] **Step 1:** `npm run i18n:check` → no missing keys.
- [ ] **Step 2:** `npm run build` → succeeds.
- [ ] **Step 3:** Full suite in the background: `php artisan test` → all green. Fix any test that posts sessions/schedules/targets without `branch_id` while a branch exists (add `'branch_id' => Branch::value('id')`), never by loosening the rule.
- [ ] **Step 4:** Migration on a copy of the dev DB (never the real one): copy `D:/irnb-laravel/database/database.sqlite` to the scratchpad, run `DB_DATABASE=<copy path> php artisan migrate --force` from the worktree, then check with tinker: every `training_sessions.branch_id` = the football branch id, `select count(*) from training_sessions group by branch_key, category_id, date, start_time having count(*) > 1` returns nothing.
- [ ] **Step 5:** `git log --stat` review: each commit holds only its task's files.
