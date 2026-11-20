# Training Groups P1 (Groups + Roster) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A category can be split into named training groups; a weekly schedule line picks a group; a group session lists only the players who are members of that group on the session date.

**Architecture:** Two new tables (`training_groups`, `training_group_members` with from/until dates) and a nullable `group_id` on `training_schedules` and `training_sessions` (null = whole category, today's behaviour). One service, `GroupMembership`, is the only writer of memberships. `Roster::expected()` gains a group condition. The generator copies the line's group to its sessions. Attendance settings gets a Groups card (create, rename, delete, tick list of players, waiting count).

**Tech Stack:** Laravel 13, PHPUnit class tests (`#[Test]`, `RefreshDatabase`), Inertia + Vue 3 `<script setup>`, vue-i18n flat dotted keys, `node --test` for JS helpers, SQLite (dev/desktop) and MySQL/MariaDB.

**Spec:** `docs/superpowers/specs/2026-11-20-training-groups-design.md` (phase 1 of 3)

## Global Constraints

- Work in a git worktree at `D:/irnb-groups` on branch `feat/training-groups` (superpowers:using-git-worktrees). Copy `vendor` and `node_modules` into the worktree; never junction them. Run `npm run build` once in the fresh worktree (Inertia tests need the Vite manifest).
- The spec and this plan are untracked files in `D:/irnb-laravel`. Copy both into the worktree at the same paths and commit them first: `docs(attendance): training groups spec and P1 plan`.
- The main folder holds the owner's untracked files (`.claude/`, `HANDOFF.md`, `_bmad/`, `brag-output/`, `ASIRAM.png`, a PDF, `ASIREM-summary-for-NotebookLM.md`). Never stage them. Stage explicit file paths only, never a directory.
- Do not push. The owner decides when `main` is pushed.
- Tests are PHPUnit classes in `tests/Feature`, not Pest functions. Run one class with `php artisan test --filter=<ClassName>`. Never run two test commands at the same time.
- `group_id` null always means "the whole category": football and every category without groups must behave exactly as before. No backfill.
- No SQLite-only SQL: the suite also runs on MariaDB.
- Migrations only add tables, columns and indexes. Never rebuild `training_sessions` (it has cascading children): no foreign-key constraint on the two `group_id` columns.
- Dates in `training_group_members` are plain `'Y-m-d'` strings (no Eloquent date cast), like `training_sessions.date`.
- Do not name an Eloquent scope `on`: `Model::on()` already picks a connection. The scope is `activeOn`.
- Player names always come from `Player::fullname` (query with `Roster::COLUMNS`).
- i18n: flat dotted keys in `resources/js/i18n/{ar,fr,en}.json`. The files are sorted by key: insert each new key at its alphabetical place without reformatting the file. Call with `t()` directly, never `te()`. No key segment named after a String method (`link`, `search`, `replace`, `at`, `sub`, `big`, `small`, `fixed`, `anchor`, `length`). Never use `att.group.<something>` (it would nest under the flat key `att.group`).
- No CDN or remote resource. The desktop app runs offline.
- Interface names: Arabic «الفوج» / «الأفواج», French « Groupe », English "Group".
- Help Center: an Arabic article change needs its `fr` and `en` twins in the same commit; `php artisan help:check` and `php artisan test --filter=Help` must pass.
- Commit messages are conventional (`feat(attendance): …`) and end with:
  `Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>`

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_11_20_200001_create_training_groups.php` | Two new tables, `group_id` on schedules and sessions |
| `app/Models/TrainingGroup.php` | A group of one branch + category |
| `app/Models/TrainingGroupMember.php` | One dated membership; the "member on a date" rule |
| `app/Models/TrainingSchedule.php`, `app/Models/TrainingSession.php` | `group_id` fillable + cast, `group()` |
| `app/Models/Category.php` | `training_groups` counts as "in use" |
| `app/Models/BranchRegistration.php` | Category change closes the membership |
| `app/Http/Controllers/BranchController.php` | Branch delete removes its unused groups |
| `app/Services/Attendance/GroupMembership.php` | Only writer of memberships (add, remove, close, current) |
| `app/Services/Attendance/Roster.php` | Group condition |
| `app/Services/Attendance/MonthSheet.php`, `app/Http/Controllers/AttendanceGridController.php` | Pass the session's group to the roster |
| `app/Services/Attendance/SessionGenerator.php` | Copies the line's group |
| `app/Http/Controllers/AttendanceSettingsController.php` | Group on schedule lines; page props |
| `app/Http/Controllers/AttendanceGroupController.php` | Create, rename, delete a group; save its tick list |
| `app/Services/Attendance/GroupBoard.php` | Groups + rosters as the settings page shows them |
| `routes/web.php`, `config/permissions.php`, `config/activity.php` | Four routes, edit right, activity kinds |
| `resources/js/lib/groupMembers.js` | Tick-list diff and sections (pure functions) |
| `resources/js/Pages/Attendance/Partials/GroupsCard.vue` | Groups card and members dialog |
| `resources/js/Pages/Attendance/Settings.vue` | Mounts the card; Group select on the schedule form |
| `app/Services/Attendance/CalendarFeed.php`, `app/Http/Controllers/AttendanceController.php` | Group name in the calendar feed and session page |
| `resources/js/Pages/Attendance/Partials/{MonthView,WeekView,AgendaView,TimelineView}.vue`, `resources/js/Pages/Attendance/Session.vue` | Show the group name |
| `resources/js/i18n/{ar,fr,en}.json` | 23 new keys each |
| `resources/help/{ar,fr,en}/attendance/timetable.md` | One paragraph about groups |

---

### Task 1: Schema and models

**Files:**
- Create: `database/migrations/2026_11_20_200001_create_training_groups.php`
- Create: `app/Models/TrainingGroup.php`
- Create: `app/Models/TrainingGroupMember.php`
- Modify: `app/Models/TrainingSchedule.php` (fillable, casts, relation)
- Modify: `app/Models/TrainingSession.php` (fillable, casts, relation)
- Modify: `app/Models/Category.php:47-51` (`USED_IN`)
- Modify: `app/Http/Controllers/BranchController.php` (`destroy`, inside the transaction)
- Test: `tests/Feature/TrainingGroupSchemaTest.php`

**Interfaces:**
- Produces: tables `training_groups(id, branch_id, category_id, name, timestamps)` and `training_group_members(id, training_group_id, player_id, from_date, to_date, timestamps)`; columns `training_schedules.group_id`, `training_sessions.group_id`.
- Produces: `TrainingGroup` (fillable `branch_id`, `category_id`, `name`; `members(): HasMany`, `branch()`, `category()`, `isInUse(): bool`); `TrainingGroupMember` (fillable `training_group_id`, `player_id`, `from_date`, `to_date`; `scopeActiveOn(Builder, string $date)`; `static constrainActiveOn(mixed $query, string $date): mixed`); `TrainingSchedule::group(): BelongsTo`, `TrainingSession::group(): BelongsTo`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TrainingGroupSchemaTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMember;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class TrainingGroupSchemaTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function a_group_holds_dated_members_and_links_schedules_and_sessions(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $player = $this->player($minime);
        $group = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G1']);
        $group->members()->create(['player_id' => $player->id, 'from_date' => '2026-11-01', 'to_date' => null]);

        $this->assertFalse($group->isInUse());

        $schedule = TrainingSchedule::create([
            'branch_id' => $swim->id, 'category_id' => $minime->id, 'group_id' => $group->id, 'weekday' => 6,
            'start_time' => '09:30', 'end_time' => '11:00', 'valid_from' => '2026-11-01',
        ]);
        $session = TrainingSession::create([
            'branch_id' => $swim->id, 'category_id' => $minime->id, 'group_id' => $group->id, 'date' => '2026-11-21',
            'start_time' => '09:30', 'end_time' => '11:00', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);

        $this->assertTrue($schedule->group->is($group));
        $this->assertTrue($session->fresh()->group->is($group));
        $this->assertSame($group->id, $session->fresh()->group_id);
        $this->assertTrue($group->isInUse());
        $this->assertSame([$player->id], TrainingGroupMember::activeOn('2026-11-21')->pluck('player_id')->all());
        $this->assertSame([], TrainingGroupMember::activeOn('2026-10-31')->pluck('player_id')->all());
        $this->assertSame('2026-11-01', TrainingGroupMember::sole()->from_date);
    }

    #[Test]
    public function a_category_with_a_group_is_in_use_and_deleting_a_group_removes_its_members(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $group = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G1']);
        $group->members()->create(['player_id' => $this->player($minime)->id]);

        $this->assertTrue($minime->isInUse());

        $group->delete();

        $this->assertSame(0, TrainingGroupMember::count());
    }

    #[Test]
    public function deleting_a_branch_removes_its_unused_groups(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $group = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G1']);

        $this->actingAs($this->admin())->delete(route('branches.destroy', $swim))
            ->assertSessionHas('success', 'flash.branch_deleted');

        $this->assertModelMissing($group);
        $this->assertModelMissing($minime);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=TrainingGroupSchemaTest`
Expected: FAIL — `Class "App\Models\TrainingGroup" not found`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_11_20_200001_create_training_groups.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training groups inside a branch + category. `group_id` null on a schedule
 * or a session = the whole category, so nothing changes for a category
 * without groups. No foreign key on the two `group_id` columns: adding one
 * rebuilds the sqlite table, and training_sessions has cascading children;
 * AttendanceGroupController::destroy() refuses a group still in use.
 *
 * Every step checks its own work, so a run that failed half way (MySQL DDL
 * is not transactional) finishes on the next run.
 */
return new class extends Migration
{
    private const TAGGED = ['training_schedules', 'training_sessions'];

    public function up(): void
    {
        if (! Schema::hasTable('training_groups')) {
            Schema::create('training_groups', function (Blueprint $t) {
                $t->id();
                // null = the whole club, as on the other attendance tables.
                $t->unsignedBigInteger('branch_id')->nullable()->index();
                $t->foreignId('category_id')->constrained();
                $t->string('name', 60);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('training_group_members')) {
            Schema::create('training_group_members', function (Blueprint $t) {
                $t->id();
                $t->foreignId('training_group_id')->constrained()->cascadeOnDelete();
                $t->foreignId('player_id')->constrained()->cascadeOnDelete();
                // 'Y-m-d'. from_date null = since always; to_date null = still a member.
                $t->date('from_date')->nullable();
                $t->date('to_date')->nullable();
                $t->timestamps();
                $t->index(['player_id', 'training_group_id'], 'training_group_members_player_group_index');
            });
        }

        foreach (self::TAGGED as $table) {
            if (! Schema::hasColumn($table, 'group_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('group_id')->nullable());
            }
            if (! Schema::hasIndex($table, "{$table}_group_id_index")) {
                Schema::table($table, fn (Blueprint $t) => $t->index('group_id', "{$table}_group_id_index"));
            }
        }
    }

    public function down(): void
    {
        foreach (self::TAGGED as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex("{$table}_group_id_index");
                $t->dropColumn('group_id');
            });
        }
        Schema::dropIfExists('training_group_members');
        Schema::dropIfExists('training_groups');
    }
};
```

- [ ] **Step 4: Write the two models**

Create `app/Models/TrainingGroup.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named group of players inside one branch + category (e.g. Swimming ·
 * Minime · «الفوج 1»). A weekly schedule line and a session may point at one;
 * their roster is then the group's members on the session date (see Roster).
 * Memberships are written only by App\Services\Attendance\GroupMembership.
 */
class TrainingGroup extends Model
{
    protected $fillable = ['branch_id', 'category_id', 'name'];

    protected function casts(): array
    {
        return ['branch_id' => 'integer', 'category_id' => 'integer'];
    }

    protected static function booted(): void
    {
        // Explicit, so it also holds where the database does not enforce foreign keys.
        static::deleting(fn (TrainingGroup $group) => $group->members()->delete());
    }

    /** The branch (sport) of the group; null = the whole club. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(TrainingGroupMember::class);
    }

    /** A schedule line or a session points at it: deleting it would change their rosters. */
    public function isInUse(): bool
    {
        return TrainingSchedule::where('group_id', $this->id)->exists()
            || TrainingSession::where('group_id', $this->id)->exists();
    }
}
```

Create `app/Models/TrainingGroupMember.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stretch of a player's membership of a training group. `from_date` and
 * `to_date` are 'Y-m-d' strings; null from = since always, null to = still a
 * member. Written only by App\Services\Attendance\GroupMembership.
 */
class TrainingGroupMember extends Model
{
    protected $fillable = ['training_group_id', 'player_id', 'from_date', 'to_date'];

    protected function casts(): array
    {
        return ['training_group_id' => 'integer', 'player_id' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class, 'training_group_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** Memberships that cover the day. (Not named `on`: Model::on() picks a connection.) */
    public function scopeActiveOn(Builder $query, string $date): void
    {
        static::constrainActiveOn($query, $date);
    }

    /** The same rule for any query that reads training_group_members (Roster's subquery). */
    public static function constrainActiveOn(mixed $query, string $date): mixed
    {
        $day = substr($date, 0, 10);

        return $query
            ->where(fn ($w) => $w->whereNull('training_group_members.from_date')->orWhere('training_group_members.from_date', '<=', $day))
            ->where(fn ($w) => $w->whereNull('training_group_members.to_date')->orWhere('training_group_members.to_date', '>=', $day));
    }
}
```

- [ ] **Step 5: Add `group_id` to schedules and sessions**

In `app/Models/TrainingSchedule.php`, replace the `$fillable` line and the `casts()` body, and add the relation after `branch()`:

```php
    protected $fillable = ['branch_id', 'category_id', 'group_id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_to'];

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'group_id' => 'integer'];
    }
```

```php
    /** The training group this slot is for; null = the whole category. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class, 'group_id');
    }
```

In `app/Models/TrainingSession.php`, add `'group_id'` to `$fillable` right after `'category_id'`, add the cast, and add the relation after `branch()`:

```php
    protected $fillable = [
        'branch_id', 'category_id', 'group_id', 'schedule_id', 'date', 'start_time', 'end_time', 'kind', 'state',
        'cancel_reason', 'moved_from', 'coach', 'title', 'notes', 'roster_status_ids',
    ];
```

```php
        return ['kind' => SessionKind::class, 'state' => SessionState::class, 'roster_status_ids' => 'array', 'group_id' => 'integer'];
```

```php
    /** The training group whose members make the roster; null = the whole category. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class, 'group_id');
    }
```

- [ ] **Step 6: Category in use, branch delete**

In `app/Models/Category.php`, add `'training_groups'` to `USED_IN`:

```php
    private const USED_IN = [
        'branch_player', 'teams', 'training_sessions', 'training_session_category', 'training_schedules',
        'finance_accounts', 'category_subscription', 'activity_category', 'attendances', 'players', 'users',
        'preseason_targets', 'deleted_session_slots', 'session_generation_marks', 'training_groups',
    ];
```

In `app/Http/Controllers/BranchController.php`, inside `destroy()`'s transaction, right after the `injury_notes` delete line and before `$categories = Category::where(...)`, add (and `use App\Models\TrainingGroup;` at the top):

```php
                // Only unused groups can remain (a branch with sessions or schedules is refused above);
                // each() runs the model's deleting event, which removes the memberships.
                TrainingGroup::where('branch_id', $branch->id)->get()->each->delete();
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --filter=TrainingGroupSchemaTest`
Expected: PASS (3 tests).

Run: `php artisan test --filter=Branch`
Expected: PASS (no branch test broken by the delete change).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_11_20_200001_create_training_groups.php app/Models/TrainingGroup.php app/Models/TrainingGroupMember.php app/Models/TrainingSchedule.php app/Models/TrainingSession.php app/Models/Category.php app/Http/Controllers/BranchController.php tests/Feature/TrainingGroupSchemaTest.php
git commit -m "feat(attendance): training groups tables and models"
```

---

### Task 2: `GroupMembership` service

**Files:**
- Create: `app/Services/Attendance/GroupMembership.php`
- Modify: `app/Models/BranchRegistration.php` (`booted()`, one more hook)
- Test: `tests/Feature/GroupMembershipTest.php`

**Interfaces:**
- Consumes: `TrainingGroup`, `TrainingGroupMember` (Task 1).
- Produces, all on `App\Services\Attendance\GroupMembership`:
  - `add(TrainingGroup $group, int $playerId, ?string $from): void` — `$from` is `'Y-m-d'` or null ("from the beginning").
  - `remove(TrainingGroup $group, int $playerId, ?string $from): void`
  - `closeInBranch(int $playerId, ?int $branchId, string $from): void`
  - `currentGroupIds(array $groupIds, string $date): array` — player id => group id, for members on `$date`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/GroupMembershipTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Category;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMember;
use App\Services\Attendance\GroupMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class GroupMembershipTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private Branch $swim;

    private Category $minime;

    private TrainingGroup $g1;

    private TrainingGroup $g2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swim = Branch::create(['name' => 'Swim']);
        $this->minime = Category::create(['name' => 'Minime', 'branch_id' => $this->swim->id]);
        $this->g1 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G1']);
        $this->g2 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G2']);
    }

    /** @return list<array{int, ?string, ?string}> [group id, from, to] of the player's rows, oldest first */
    private function rows(int $playerId): array
    {
        return TrainingGroupMember::where('player_id', $playerId)->orderBy('id')->get()
            ->map(fn (TrainingGroupMember $m) => [$m->training_group_id, $m->from_date, $m->to_date])->all();
    }

    #[Test]
    public function a_first_assignment_has_no_start_date(): void
    {
        $p = $this->player($this->minime);

        app(GroupMembership::class)->add($this->g1, $p->id, null);

        $this->assertSame([[$this->g1->id, null, null]], $this->rows($p->id));
    }

    #[Test]
    public function a_move_from_a_date_closes_the_old_group_the_day_before(): void
    {
        $p = $this->player($this->minime);
        $membership = app(GroupMembership::class);

        $membership->add($this->g1, $p->id, null);
        $membership->add($this->g2, $p->id, '2027-01-10');

        $this->assertSame([[$this->g1->id, null, '2027-01-09'], [$this->g2->id, '2027-01-10', null]], $this->rows($p->id));
    }

    #[Test]
    public function from_the_beginning_replaces_the_history_in_the_branch(): void
    {
        $p = $this->player($this->minime);
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $p->id, null);
        $membership->add($this->g2, $p->id, '2027-01-10');

        $membership->add($this->g2, $p->id, null);

        $this->assertSame([[$this->g2->id, null, null]], $this->rows($p->id));
    }

    #[Test]
    public function adding_to_the_group_the_player_is_already_in_changes_nothing(): void
    {
        $p = $this->player($this->minime);
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $p->id, null);

        $membership->add($this->g1, $p->id, '2027-01-10');

        $this->assertSame([[$this->g1->id, null, null]], $this->rows($p->id));
    }

    #[Test]
    public function a_membership_that_would_end_before_it_starts_is_deleted(): void
    {
        $p = $this->player($this->minime);
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $p->id, '2027-01-10');

        $membership->add($this->g2, $p->id, '2027-01-05');

        $this->assertSame([[$this->g2->id, '2027-01-05', null]], $this->rows($p->id));
    }

    #[Test]
    public function removing_from_a_date_closes_and_from_the_beginning_deletes(): void
    {
        $a = $this->player($this->minime);
        $b = $this->player($this->minime);
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $a->id, null);
        $membership->add($this->g1, $b->id, null);

        $membership->remove($this->g1, $a->id, '2027-02-01');
        $membership->remove($this->g1, $b->id, null);

        $this->assertSame([[$this->g1->id, null, '2027-01-31']], $this->rows($a->id));
        $this->assertSame([], $this->rows($b->id));
    }

    #[Test]
    public function a_group_of_another_branch_is_left_alone(): void
    {
        $foot = Branch::create(['name' => 'Foot']);
        $u13 = Category::create(['name' => 'U13', 'branch_id' => $foot->id]);
        $footGroup = TrainingGroup::create(['branch_id' => $foot->id, 'category_id' => $u13->id, 'name' => 'A']);
        $p = $this->player($this->minime);
        $membership = app(GroupMembership::class);
        $membership->add($footGroup, $p->id, null);

        $membership->add($this->g1, $p->id, null);

        $this->assertSame([[$footGroup->id, null, null], [$this->g1->id, null, null]], $this->rows($p->id));
    }

    #[Test]
    public function current_group_ids_reads_the_members_on_a_date(): void
    {
        $a = $this->player($this->minime);
        $b = $this->player($this->minime);
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $a->id, null);
        $membership->add($this->g2, $a->id, '2027-01-10');
        $membership->add($this->g1, $b->id, null);
        $ids = [$this->g1->id, $this->g2->id];

        $this->assertSame([$a->id => $this->g1->id, $b->id => $this->g1->id], $membership->currentGroupIds($ids, '2027-01-09'));
        $this->assertSame([$a->id => $this->g2->id, $b->id => $this->g1->id], $membership->currentGroupIds($ids, '2027-01-10'));
        $this->assertSame([], $membership->currentGroupIds([], '2027-01-10'));
    }

    #[Test]
    public function a_category_change_closes_the_membership_the_day_before(): void
    {
        Carbon::setTestNow('2026-12-01');
        $cadet = Category::create(['name' => 'Cadet', 'branch_id' => $this->swim->id]);
        $p = $this->player($this->minime);
        $this->swim->players()->attach($p->id);
        app(GroupMembership::class)->add($this->g1, $p->id, null);

        $registration = BranchRegistration::where('player_id', $p->id)->where('branch_id', $this->swim->id)->sole();
        $registration->category_id = $cadet->id;
        $registration->save();

        $this->assertSame([[$this->g1->id, null, '2026-11-30']], $this->rows($p->id));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=GroupMembershipTest`
Expected: FAIL — `Class "App\Services\Attendance\GroupMembership" not found`.

- [ ] **Step 3: Write the service**

Create `app/Services/Attendance/GroupMembership.php`:

```php
<?php

namespace App\Services\Attendance;

use App\Models\TrainingGroup;
use App\Models\TrainingGroupMember;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of training_group_members. A player has at most one
 * membership open on any date inside one branch.
 *
 * Every change takes the day it applies from ('Y-m-d'), or null = "from the
 * beginning": the first setup, and the way to fix a mistake. A change from a
 * date keeps the history before it, so unsaved sessions before that date
 * still list the player in the old group. Saved sessions are never touched:
 * their roster is their marks.
 */
final class GroupMembership
{
    /** Puts the player in the group from $from on, taking them out of any other group of the same branch. */
    public function add(TrainingGroup $group, int $playerId, ?string $from): void
    {
        DB::transaction(function () use ($group, $playerId, $from) {
            $inBranch = TrainingGroupMember::where('player_id', $playerId)
                ->whereIn('training_group_id', $this->branchGroupIds($group->branch_id));

            if ($from === null) {
                $inBranch->delete();
                $this->open($group, $playerId, null);

                return;
            }

            $rows = $inBranch->get();
            $already = $rows->contains(fn (TrainingGroupMember $m) => $m->training_group_id === $group->id
                && $m->to_date === null
                && ($m->from_date === null || $m->from_date <= $from));
            if ($already) {
                return;
            }

            $this->close($rows, $from);
            $this->open($group, $playerId, $from);
        });
    }

    /** Takes the player out of the group from $from on; null deletes the membership altogether. */
    public function remove(TrainingGroup $group, int $playerId, ?string $from): void
    {
        DB::transaction(function () use ($group, $playerId, $from) {
            $own = TrainingGroupMember::where('player_id', $playerId)->where('training_group_id', $group->id);

            $from === null ? $own->delete() : $this->close($own->get(), $from);
        });
    }

    /** Ends the player's membership in every group of the branch from $from on (their category changed). */
    public function closeInBranch(int $playerId, ?int $branchId, string $from): void
    {
        DB::transaction(fn () => $this->close(
            TrainingGroupMember::where('player_id', $playerId)->whereIn('training_group_id', $this->branchGroupIds($branchId))->get(),
            $from,
        ));
    }

    /**
     * @param  array<int, int>  $groupIds
     * @return array<int, int> player id => group id, for the members of these groups on $date
     */
    public function currentGroupIds(array $groupIds, string $date): array
    {
        if ($groupIds === []) {
            return [];
        }

        return TrainingGroupMember::whereIn('training_group_id', $groupIds)
            ->activeOn($date)
            ->orderBy('id')
            ->get(['player_id', 'training_group_id'])
            ->mapWithKeys(fn (TrainingGroupMember $m) => [$m->player_id => $m->training_group_id])
            ->all();
    }

    private function open(TrainingGroup $group, int $playerId, ?string $from): void
    {
        TrainingGroupMember::create(['training_group_id' => $group->id, 'player_id' => $playerId, 'from_date' => $from, 'to_date' => null]);
    }

    /**
     * Ends each row the day before $from. A row that ended earlier stays; one
     * that starts on $from or later would end before it starts, so it goes.
     *
     * @param  iterable<TrainingGroupMember>  $rows
     */
    private function close(iterable $rows, string $from): void
    {
        $before = CarbonImmutable::createFromFormat('!Y-m-d', $from)->subDay()->toDateString();

        foreach ($rows as $row) {
            if ($row->to_date !== null && $row->to_date < $from) {
                continue;
            }
            if ($row->from_date !== null && $row->from_date >= $from) {
                $row->delete();

                continue;
            }
            $row->update(['to_date' => $before]);
        }
    }

    /** @return array<int, int> every group of the branch (null = the club-wide groups) */
    private function branchGroupIds(?int $branchId): array
    {
        return TrainingGroup::where('branch_id', $branchId)->pluck('id')->all(); // null → whereNull
    }
}
```

- [ ] **Step 4: Close the membership when a registration's category changes**

In `app/Models/BranchRegistration.php`, add `use App\Services\Attendance\GroupMembership;` at the top, and at the end of `booted()` (after the `static::saving(...)` block):

```php
        // A training group belongs to one category: leaving the category ends the membership
        // the day before. One place for every writer (player form, bulk edit).
        static::updated(function (BranchRegistration $registration) {
            if ($registration->wasChanged('category_id')) {
                app(GroupMembership::class)->closeInBranch((int) $registration->player_id, (int) $registration->branch_id, now()->toDateString());
            }
        });
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=GroupMembershipTest`
Expected: PASS (9 tests).

Run: `php artisan test --filter=Registration`
Expected: PASS (the new hook breaks no registration test).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/GroupMembership.php app/Models/BranchRegistration.php tests/Feature/GroupMembershipTest.php
git commit -m "feat(attendance): dated group memberships"
```

---

### Task 3: Roster by group

**Files:**
- Modify: `app/Services/Attendance/Roster.php` (`expected`, `forSession`, class comment)
- Modify: `app/Services/Attendance/MonthSheet.php:61-62`
- Modify: `app/Http/Controllers/AttendanceGridController.php:97`
- Test: `tests/Feature/AttendanceRosterGroupTest.php`

**Interfaces:**
- Consumes: `TrainingGroupMember::constrainActiveOn($query, string $date)` (Task 1), `GroupMembership::add()` (Task 2).
- Produces: `Roster::expected(int|array $categoryIds, string $date, ?array $statusIds = null, ?int $branchId = null, ?int $groupId = null): Collection` — the fifth argument is new; null = no group condition.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttendanceRosterGroupTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Services\Attendance\GroupMembership;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\MonthSheet;
use App\Services\Attendance\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceRosterGroupTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private Branch $swim;

    private Category $minime;

    private TrainingGroup $g1;

    private TrainingGroup $g2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swim = Branch::create(['name' => 'Swim']);
        $this->minime = Category::create(['name' => 'Minime', 'branch_id' => $this->swim->id]);
        $this->g1 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G1']);
        $this->g2 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G2']);
    }

    private function swimmer(): \App\Models\Player
    {
        $player = $this->player($this->minime);
        $this->swim->players()->attach($player->id);

        return $player;
    }

    private function session(?TrainingGroup $group, string $date, string $start): TrainingSession
    {
        return TrainingSession::create([
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'group_id' => $group?->id, 'date' => $date,
            'start_time' => $start, 'end_time' => '23:00', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    #[Test]
    public function a_group_session_lists_only_its_members_and_a_plain_session_lists_the_category(): void
    {
        [$a, $b, $waiting] = [$this->swimmer(), $this->swimmer(), $this->swimmer()];
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $a->id, null);
        $membership->add($this->g2, $b->id, null);
        $roster = app(Roster::class);

        $this->assertSame([$a->id], $roster->forSession($this->session($this->g1, '2026-11-21', '09:30'))->pluck('id')->all());
        $this->assertSame([$b->id], $roster->forSession($this->session($this->g2, '2026-11-21', '11:00'))->pluck('id')->all());
        $this->assertSame([$a->id, $b->id, $waiting->id], $roster->forSession($this->session(null, '2026-11-21', '14:00'))->pluck('id')->all());
    }

    #[Test]
    public function a_moved_player_is_listed_in_the_old_group_before_the_date_and_the_new_one_from_it(): void
    {
        $a = $this->swimmer();
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $a->id, null);
        $membership->add($this->g2, $a->id, '2027-01-10');
        $roster = app(Roster::class);

        $this->assertSame([$a->id], $roster->forSession($this->session($this->g1, '2027-01-09', '09:30'))->pluck('id')->all());
        $this->assertSame([], $roster->forSession($this->session($this->g2, '2027-01-09', '11:00'))->pluck('id')->all());
        $this->assertSame([], $roster->forSession($this->session($this->g1, '2027-01-10', '09:30'))->pluck('id')->all());
        $this->assertSame([$a->id], $roster->forSession($this->session($this->g2, '2027-01-10', '11:00'))->pluck('id')->all());
    }

    #[Test]
    public function a_saved_session_keeps_its_list_when_the_group_changes(): void
    {
        $a = $this->swimmer();
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $a->id, null);
        $session = $this->session($this->g1, '2026-11-21', '09:30');
        app(MarkRecorder::class)->save($session, [['player_id' => $a->id, 'status' => 'present']], $this->admin());

        $membership->add($this->g2, $a->id, null);

        $this->assertSame([$a->id], app(Roster::class)->forSession($session->fresh())->pluck('id')->all());
    }

    #[Test]
    public function the_month_sheet_gives_a_cell_only_to_the_members_of_each_session_group(): void
    {
        [$a, $b] = [$this->swimmer(), $this->swimmer()];
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $a->id, null);
        $membership->add($this->g2, $b->id, null);
        $first = $this->session($this->g1, '2026-11-21', '09:30');
        $second = $this->session($this->g2, '2026-11-21', '11:00');

        $cells = app(MonthSheet::class)->build($this->minime->id, 2026, 11, $this->swim->id)['cells'];

        $this->assertSame([$first->id], array_keys($cells[$a->id]));
        $this->assertSame([$second->id], array_keys($cells[$b->id]));
    }

    #[Test]
    public function the_grid_refuses_a_code_for_a_player_outside_the_session_group(): void
    {
        [$a, $b] = [$this->swimmer(), $this->swimmer()];
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $a->id, null);
        $membership->add($this->g2, $b->id, null);
        $session = $this->session($this->g1, '2026-11-21', '09:30');

        $this->actingAs($this->admin())->post(route('attendance.grid.save'), [
            'branch_id' => $this->swim->id,
            'columns' => [['session_id' => $session->id, 'codes' => [$b->id => 'P']]],
        ])->assertSessionHasErrors(["columns.0.codes.{$b->id}"]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AttendanceRosterGroupTest`
Expected: FAIL — the first test lists all three players in the G1 session (no group condition yet).

- [ ] **Step 3: Add the group condition to `Roster`**

In `app/Services/Attendance/Roster.php`, add `use App\Models\TrainingGroupMember;` to the imports. Append this paragraph to the class doc comment, before the closing `*/`:

```php
 *
 * A session of a training group (`group_id`) adds one condition: the player
 * is a member of that group on the session date. A player of the category
 * with no group is in no group session.
```

Replace `expected()` with:

```php
    /**
     * @param  int|array<int, int>  $categoryIds
     * @param  list<int>|null  $statusIds  the roster set; null = the settings' default
     * @param  int|null  $branchId  only this branch's registrations; null = any registration
     * @param  int|null  $groupId  only members of this training group on the date; null = the whole category
     * @return Collection<int, Player>
     */
    public function expected(int|array $categoryIds, string $date, ?array $statusIds = null, ?int $branchId = null, ?int $groupId = null): Collection
    {
        $statusIds ??= $this->defaultStatusIds();

        return Player::registeredWhere(
            branches: $branchId,
            categories: (array) $categoryIds,
            also: fn ($row, string $status, string $leftAt) => $this->onRoster($row, $status, $leftAt, $date, $statusIds),
        )
            ->where('archived', false)
            ->when($groupId !== null, fn ($players) => $players->whereExists(
                fn ($members) => TrainingGroupMember::constrainActiveOn(
                    $members->selectRaw('1')->from('training_group_members')
                        ->whereColumn('training_group_members.player_id', 'players.id')
                        ->where('training_group_members.training_group_id', $groupId),
                    $date,
                )
            ))
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::COLUMNS);
    }
```

In `forSession()`, replace the `return $this->expected(...)` line with:

```php
            return $this->expected($session->categoryIds(), $session->date, $this->statusIdsFor($session), $session->branch_id, $session->group_id);
```

- [ ] **Step 4: Pass the group from the month sheet and the grid save**

In `app/Services/Attendance/MonthSheet.php`, replace the two lines that build `$key` and fill `$expected[$key]`:

```php
            $key = $session->branch_id.'|'.$session->group_id.'|'.$session->date.'|'.implode(',', $categoryIds).'|'.implode(',', $statusIds);
            $expected[$key] ??= $this->roster->expected($categoryIds, $session->date, $statusIds, $session->branch_id, $session->group_id)->modelKeys();
```

In `app/Http/Controllers/AttendanceGridController.php`, replace the `: $roster->expected(...)` line in `save()`:

```php
                : $roster->expected($session->categoryIds(), $session->date, $roster->statusIdsFor($session), $session->branch_id, $session->group_id)->modelKeys();
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=AttendanceRosterGroupTest`
Expected: PASS (5 tests).

Run: `php artisan test --filter=Roster`
Expected: PASS (existing roster tests unchanged).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/Roster.php app/Services/Attendance/MonthSheet.php app/Http/Controllers/AttendanceGridController.php tests/Feature/AttendanceRosterGroupTest.php
git commit -m "feat(attendance): a group session lists only its members"
```

---

### Task 4: Group on schedule lines and in the generator

**Files:**
- Modify: `app/Services/Attendance/SessionGenerator.php` (`generate()`, the `$rows[]` array; class comment)
- Modify: `app/Http/Controllers/AttendanceSettingsController.php` (`index` schedules columns, `updateSchedule`, `validateSchedule`)
- Test: `tests/Feature/AttendanceScheduleGroupTest.php`

**Interfaces:**
- Consumes: `TrainingGroup`, `training_schedules.group_id`, `training_sessions.group_id` (Task 1).
- Produces: `POST attendance.schedules.store` and `PUT attendance.schedules.update` accept `group_id` (nullable integer); error key `group_id` with message `att.error.group_other_category`; the settings page's `schedules.*.group_id`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttendanceScheduleGroupTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\TrainingGroup;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\SessionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceScheduleGroupTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private Branch $swim;

    private Category $minime;

    private TrainingGroup $g1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swim = Branch::create(['name' => 'Swim']);
        $this->minime = Category::create(['name' => 'Minime', 'branch_id' => $this->swim->id]);
        $this->g1 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G1']);
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'weekday' => 1,
            'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01',
        ];
    }

    #[Test]
    public function a_line_stores_its_group_and_the_page_lists_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.schedules.store'), $this->payload(['group_id' => $this->g1->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->g1->id, TrainingSchedule::sole()->group_id);
        $this->actingAs($admin)->get(route('attendance.settings'))
            ->assertInertia(fn (Assert $page) => $page->where('schedules.0.group_id', $this->g1->id));
    }

    #[Test]
    public function a_group_of_another_category_or_branch_is_refused(): void
    {
        $cadet = Category::create(['name' => 'Cadet', 'branch_id' => $this->swim->id]);
        $other = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $cadet->id, 'name' => 'C1']);
        $foot = Branch::create(['name' => 'Foot']);
        $unowned = Category::create(['name' => 'Open']);
        $unowned->update(['branch_id' => null]);
        $footGroup = TrainingGroup::create(['branch_id' => $foot->id, 'category_id' => $unowned->id, 'name' => 'F1']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.schedules.store'), $this->payload(['group_id' => $other->id]))
            ->assertSessionHasErrors(['group_id' => 'att.error.group_other_category']);
        $this->actingAs($admin)->post(route('attendance.schedules.store'), $this->payload(['category_id' => $unowned->id, 'group_id' => $footGroup->id]))
            ->assertSessionHasErrors(['group_id' => 'att.error.group_other_category']);
        $this->assertSame(0, TrainingSchedule::count());
    }

    #[Test]
    public function the_generator_copies_the_line_group(): void
    {
        TrainingSchedule::create($this->payload(['group_id' => $this->g1->id]));
        TrainingSchedule::create($this->payload(['weekday' => 3]));

        // October 2026: Mondays 5, 12, 19, 26; Wednesdays 7, 14, 21, 28.
        app(SessionGenerator::class)->forRange('2026-10-01', '2026-10-31');

        $this->assertSame(4, TrainingSession::where('group_id', $this->g1->id)->count());
        $this->assertSame(4, TrainingSession::whereNull('group_id')->count());
        $this->assertSame(['2026-10-05'], TrainingSession::where('group_id', $this->g1->id)->orderBy('date')->limit(1)->pluck('date')->all());
    }

    #[Test]
    public function giving_a_line_a_group_retags_unsaved_sessions_and_leaves_saved_ones(): void
    {
        Carbon::setTestNow('2026-10-10');
        $admin = $this->admin();
        $schedule = TrainingSchedule::create($this->payload());
        $player = $this->player($this->minime);
        $this->swim->players()->attach($player->id);

        $base = [
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'schedule_id' => $schedule->id,
            'start_time' => '18:00', 'end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ];
        $saved = TrainingSession::create($base + ['date' => '2026-09-28']);
        app(MarkRecorder::class)->save($saved, [['player_id' => $player->id, 'status' => 'present']], $admin);
        $past = TrainingSession::create($base + ['date' => '2026-10-05']);
        $future = TrainingSession::create($base + ['date' => '2026-10-12']);
        $moved = TrainingSession::create($base + ['date' => '2026-10-20', 'moved_from' => '2026-10-19']);

        $this->actingAs($admin)->put(route('attendance.schedules.update', $schedule), $this->payload(['group_id' => $this->g1->id]))
            ->assertSessionHasNoErrors();

        $this->assertNull($saved->fresh()->group_id);
        $this->assertSame($this->g1->id, $past->fresh()->group_id);
        $this->assertSame($this->g1->id, $moved->fresh()->group_id);
        $this->assertModelMissing($future); // regenerated with the group on the next month view

        app(SessionGenerator::class)->forRange('2026-10-01', '2026-10-31');
        $this->assertSame($this->g1->id, TrainingSession::where('date', '2026-10-12')->sole()->group_id);
    }

    #[Test]
    public function clearing_the_group_of_a_line_brings_unsaved_sessions_back_to_the_whole_category(): void
    {
        Carbon::setTestNow('2026-10-10');
        $schedule = TrainingSchedule::create($this->payload(['group_id' => $this->g1->id]));
        $past = TrainingSession::create([
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'schedule_id' => $schedule->id, 'group_id' => $this->g1->id,
            'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);

        $this->actingAs($this->admin())->put(route('attendance.schedules.update', $schedule), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertNull($schedule->fresh()->group_id);
        $this->assertNull($past->fresh()->group_id);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AttendanceScheduleGroupTest`
Expected: FAIL — the first test: `group_id` is null on the stored schedule (the request ignores it).

- [ ] **Step 3: Generator copies the group**

In `app/Services/Attendance/SessionGenerator.php`, in `generate()`, add one line to the `$rows[] = [...]` array, right after `'category_id' => $categoryId,`:

```php
                    'group_id' => $schedule->group_id,
```

In the class doc comment, replace the sentence `Each session takes its schedule's branch;` with:

```php
 * time. Each session takes its schedule's branch and training group; moved, joint and deleted
```

(The comment's first two lines then read: "Turns a category's weekly schedule into planned sessions, one month at a" / "time. Each session takes its schedule's branch and training group; moved, joint and deleted" / "slots only block the same branch. …" — re-wrap the paragraph so no line passes 100 characters.)

- [ ] **Step 4: Schedule lines accept and apply a group**

In `app/Http/Controllers/AttendanceSettingsController.php`:

Add `use App\Models\TrainingGroup;` to the imports.

In `index()`, add `'group_id'` to the schedules column list:

```php
                ->get(['id', 'branch_id', 'category_id', 'group_id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_to']),
```

Replace `updateSchedule()`:

```php
    public function updateSchedule(Request $request, TrainingSchedule $schedule): RedirectResponse
    {
        $data = $this->validateSchedule($request, $schedule);
        // One transaction: see purgeFuturePlanned().
        DB::transaction(function () use ($schedule, $data) {
            $schedule->update($data);
            // Sessions of this line nobody marked follow its group: past ones, and moved ones
            // the purge leaves alone. Saved sessions keep their group; their list is their marks.
            TrainingSession::where('schedule_id', $schedule->id)
                ->where('category_id', $schedule->category_id)
                ->whereDoesntHave('attendances')
                ->update(['group_id' => $schedule->group_id]);
            $this->purgeFuturePlanned($schedule);
        });

        return back()->with('success', 'flash.training_schedule_saved');
    }
```

Replace `validateSchedule()`:

```php
    private function validateSchedule(Request $request, ?TrainingSchedule $schedule = null): array
    {
        $data = $request->validate([
            'branch_id' => [Rule::requiredIf(fn () => Branch::exists()), 'nullable', 'integer', 'exists:branches,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'group_id' => ['nullable', 'integer'],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ]);
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        CategoryOwnership::assertIn('category_id', $data['category_id'], $branchId, $schedule !== null && $schedule->branch_id === $branchId ? $schedule->category_id : null);

        // Always present, so an edit that drops the group clears it. A group belongs to one branch + category.
        $data['group_id'] = isset($data['group_id']) ? (int) $data['group_id'] : null;
        if ($data['group_id'] !== null && ! TrainingGroup::whereKey($data['group_id'])
            ->where('branch_id', $branchId) // null → whereNull
            ->where('category_id', $data['category_id'])
            ->exists()) {
            throw ValidationException::withMessages(['group_id' => 'att.error.group_other_category']);
        }

        return $data;
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=AttendanceScheduleGroupTest`
Expected: PASS (5 tests).

Run: `php artisan test --filter=AttendanceSettings`
Expected: PASS.

Run: `php artisan test --filter=SessionGenerator`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/SessionGenerator.php app/Http/Controllers/AttendanceSettingsController.php tests/Feature/AttendanceScheduleGroupTest.php
git commit -m "feat(attendance): a weekly schedule line can belong to a group"
```

---

### Task 5: Group endpoints and settings page data

**Files:**
- Create: `app/Http/Controllers/AttendanceGroupController.php`
- Create: `app/Services/Attendance/GroupBoard.php`
- Modify: `routes/web.php` (after the `attendance.schedules.destroy` route)
- Modify: `config/permissions.php` (next to the `attendance.schedules.*` entries)
- Modify: `config/activity.php` (`$settingLists` and the attendance block)
- Modify: `app/Http/Controllers/AttendanceSettingsController.php` (`index`)
- Test: `tests/Feature/AttendanceGroupsTest.php`

**Interfaces:**
- Consumes: `GroupMembership::add/remove/currentGroupIds` (Task 2), `Roster::expected` (Task 3), `TrainingGroup::isInUse()` (Task 1).
- Produces routes: `POST attendance.groups.store` `{branch_id, category_id, name}`; `PUT attendance.groups.update` `{name}`; `DELETE attendance.groups.destroy`; `PUT attendance.groups.members.update` `{from: 'Y-m-d'|null, add: int[], remove: int[]}`. Route parameter name: `group`.
- Produces flash keys: `flash.training_group_saved`, `flash.training_group_deleted`, `flash.training_group_in_use` (error), `flash.training_group_members_saved`. Error messages: `att.error.group_name_taken` (key `name`), `att.error.group_wrong_player` (key `add`).
- Produces settings page props: `groups: [{ id, branch_id, category_id, name, key, members, in_use }]` and `groupRosters: { [key]: [{ id, name, group_id }] }`, where `key` is `"<branch id or 0>:<category id>"`, `members` counts roster players in the group today, and `group_id` is null for a player waiting for a group.
- Produces: `GroupBoard::build(string $date): array{groups: list<array>, rosters: array<string, list<array>>}` and `GroupBoard::key(?int $branchId, int $categoryId): string`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttendanceGroupsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Role;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMember;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\Attendance\GroupMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceGroupsTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private Branch $swim;

    private Category $minime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swim = Branch::create(['name' => 'Swim']);
        $this->minime = Category::create(['name' => 'Minime', 'branch_id' => $this->swim->id]);
    }

    private function group(string $name = 'G1'): TrainingGroup
    {
        return TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => $name]);
    }

    private function swimmer(): \App\Models\Player
    {
        $player = $this->player($this->minime);
        $this->swim->players()->attach($player->id);

        return $player;
    }

    #[Test]
    public function a_group_is_created_renamed_and_its_name_is_unique_in_the_category(): void
    {
        $admin = $this->admin();
        $payload = ['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => ' G1 '];

        $this->actingAs($admin)->post(route('attendance.groups.store'), $payload)
            ->assertSessionHas('success', 'flash.training_group_saved');
        $group = TrainingGroup::sole();
        $this->assertSame('G1', $group->name);

        $this->actingAs($admin)->post(route('attendance.groups.store'), $payload)
            ->assertSessionHasErrors(['name' => 'att.error.group_name_taken']);

        $this->actingAs($admin)->put(route('attendance.groups.update', $group), ['name' => 'Morning'])
            ->assertSessionHas('success', 'flash.training_group_saved');
        $this->assertSame('Morning', $group->fresh()->name);
    }

    #[Test]
    public function a_category_of_another_branch_is_refused(): void
    {
        $foot = Branch::create(['name' => 'Foot']);

        $this->actingAs($this->admin())->post(route('attendance.groups.store'), [
            'branch_id' => $foot->id, 'category_id' => $this->minime->id, 'name' => 'G1',
        ])->assertSessionHasErrors('category_id');

        $this->assertSame(0, TrainingGroup::count());
    }

    #[Test]
    public function a_group_in_use_cannot_be_deleted_and_an_unused_one_goes_with_its_members(): void
    {
        $admin = $this->admin();
        $used = $this->group('G1');
        $free = $this->group('G2');
        TrainingSchedule::create([
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'group_id' => $used->id, 'weekday' => 6,
            'start_time' => '09:30', 'end_time' => '11:00', 'valid_from' => '2026-11-01',
        ]);
        app(GroupMembership::class)->add($free, $this->swimmer()->id, null);

        $this->actingAs($admin)->delete(route('attendance.groups.destroy', $used))
            ->assertSessionHas('error', 'flash.training_group_in_use');
        $this->assertModelExists($used);

        $this->actingAs($admin)->delete(route('attendance.groups.destroy', $free))
            ->assertSessionHas('success', 'flash.training_group_deleted');
        $this->assertModelMissing($free);
        $this->assertSame(0, TrainingGroupMember::count());
    }

    #[Test]
    public function the_tick_list_adds_moves_and_removes_players(): void
    {
        $admin = $this->admin();
        [$g1, $g2] = [$this->group('G1'), $this->group('G2')];
        [$a, $b] = [$this->swimmer(), $this->swimmer()];

        $this->actingAs($admin)->put(route('attendance.groups.members.update', $g1), ['from' => null, 'add' => [$a->id, $b->id], 'remove' => []])
            ->assertSessionHas('success', 'flash.training_group_members_saved');
        $this->actingAs($admin)->put(route('attendance.groups.members.update', $g2), ['from' => '2027-01-10', 'add' => [$a->id], 'remove' => []])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('attendance.groups.members.update', $g1), ['from' => '2027-02-01', 'add' => [], 'remove' => [$b->id]])
            ->assertSessionHasNoErrors();

        $rows = TrainingGroupMember::orderBy('id')->get()->map(fn ($m) => [$m->player_id, $m->training_group_id, $m->from_date, $m->to_date])->all();
        $this->assertSame([
            [$a->id, $g1->id, null, '2027-01-09'],
            [$b->id, $g1->id, null, '2027-01-31'],
            [$a->id, $g2->id, '2027-01-10', null],
        ], $rows);
    }

    #[Test]
    public function a_player_of_another_category_cannot_be_ticked(): void
    {
        $cadet = Category::create(['name' => 'Cadet', 'branch_id' => $this->swim->id]);
        $outsider = $this->player($cadet);
        $this->swim->players()->attach($outsider->id);

        $this->actingAs($this->admin())->put(route('attendance.groups.members.update', $this->group()), ['from' => null, 'add' => [$outsider->id], 'remove' => []])
            ->assertSessionHasErrors(['add' => 'att.error.group_wrong_player']);

        $this->assertSame(0, TrainingGroupMember::count());
    }

    #[Test]
    public function the_settings_page_lists_groups_their_rosters_and_who_is_waiting(): void
    {
        Carbon::setTestNow('2026-11-20');
        [$g1, $g2] = [$this->group('G1'), $this->group('G2')];
        [$a, $b, $waiting] = [$this->swimmer(), $this->swimmer(), $this->swimmer()];
        app(GroupMembership::class)->add($g1, $a->id, null);
        app(GroupMembership::class)->add($g2, $b->id, null);
        TrainingSchedule::create([
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'group_id' => $g1->id, 'weekday' => 6,
            'start_time' => '09:30', 'end_time' => '11:00', 'valid_from' => '2026-11-01',
        ]);
        $key = "{$this->swim->id}:{$this->minime->id}";

        $this->actingAs($this->admin())->get(route('attendance.settings'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('groups', 2)
                ->where('groups.0.id', $g1->id)->where('groups.0.key', $key)->where('groups.0.members', 1)->where('groups.0.in_use', true)
                ->where('groups.1.id', $g2->id)->where('groups.1.members', 1)->where('groups.1.in_use', false)
                ->has("groupRosters.{$key}", 3)
                ->where("groupRosters.{$key}.0", ['id' => $a->id, 'name' => $a->fullname, 'group_id' => $g1->id])
                ->where("groupRosters.{$key}.1.group_id", $g2->id)
                ->where("groupRosters.{$key}.2", ['id' => $waiting->id, 'name' => $waiting->fullname, 'group_id' => null]));
    }

    #[Test]
    public function without_groups_the_page_sends_empty_lists(): void
    {
        $this->swimmer();

        $this->actingAs($this->admin())->get(route('attendance.settings'))
            ->assertInertia(fn (Assert $page) => $page->has('groups', 0)->has('groupRosters', 0));
    }

    #[Test]
    public function every_group_write_needs_the_attendance_edit_right(): void
    {
        $group = $this->group();
        $role = Role::factory()->create(['permissions' => ['attendance' => ['view', 'add', 'delete']]]);
        $user = User::factory()->create(['privileges' => ['user'], 'role_id' => $role->id]);

        $this->actingAs($user)->post(route('attendance.groups.store'), ['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'X'])->assertForbidden();
        $this->actingAs($user)->put(route('attendance.groups.update', $group), ['name' => 'X'])->assertForbidden();
        $this->actingAs($user)->put(route('attendance.groups.members.update', $group), ['from' => null, 'add' => [], 'remove' => []])->assertForbidden();
        $this->actingAs($user)->delete(route('attendance.groups.destroy', $group))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AttendanceGroupsTest`
Expected: FAIL — `Route [attendance.groups.store] not defined`.

- [ ] **Step 3: Routes, permissions, activity**

In `routes/web.php`, add `use App\Http\Controllers\AttendanceGroupController;` to the imports (alphabetical place, before `AttendanceInjuryController`), and after the `attendance.schedules.destroy` route add:

```php
        // Training groups inside a category; every write needs attendance/edit (config/permissions.php).
        Route::post('/attendance/groups', [AttendanceGroupController::class, 'store'])->name('attendance.groups.store');
        Route::put('/attendance/groups/{group}', [AttendanceGroupController::class, 'update'])->name('attendance.groups.update');
        Route::delete('/attendance/groups/{group}', [AttendanceGroupController::class, 'destroy'])->name('attendance.groups.destroy');
        Route::put('/attendance/groups/{group}/members', [AttendanceGroupController::class, 'members'])->name('attendance.groups.members.update');
```

In `config/permissions.php`, right after the `'attendance.preseason-targets.store' => ['attendance', 'edit'],` line add:

```php
        // Training groups decide who each session lists: settings, like the schedule lines.
        'attendance.groups.store' => ['attendance', 'edit'],
        'attendance.groups.destroy' => ['attendance', 'edit'],
        'attendance.groups.members.update' => ['attendance', 'edit'],
```

In `config/activity.php`, add to `$settingLists` right after the `'attendance.custom-statuses' => 'attendance_codes',` line:

```php
    'attendance.groups' => 'training_groups',
```

and in the `// attendance` block of `$routes`, after the `attendance.closures.destroy` line:

```php
    'attendance.groups.members.update' => [ActivityAction::SETTINGS_UPDATED, 'kind' => 'training_group_members'],
```

- [ ] **Step 4: Write `GroupBoard`**

Create `app/Services/Attendance/GroupBoard.php`:

```php
<?php

namespace App\Services\Attendance;

use App\Models\Player;
use App\Models\TrainingGroup;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;

/**
 * The training groups as the settings page shows them: every group with how
 * many roster players it holds on the day, and, for each branch + category
 * that has groups, its roster with each player's group. A player with no
 * group there is waiting: no group session lists them. Read-only.
 */
final class GroupBoard
{
    public function __construct(
        private readonly Roster $roster,
        private readonly GroupMembership $membership,
    ) {}

    /** @return array{groups: list<array<string, mixed>>, rosters: array<string, list<array<string, mixed>>>} */
    public function build(string $date): array
    {
        $groups = TrainingGroup::orderBy('branch_id')->orderBy('category_id')->orderBy('name')->orderBy('id')->get();
        if ($groups->isEmpty()) {
            return ['groups' => [], 'rosters' => []];
        }

        $used = array_flip([
            ...TrainingSchedule::whereNotNull('group_id')->distinct()->pluck('group_id')->all(),
            ...TrainingSession::whereNotNull('group_id')->distinct()->pluck('group_id')->all(),
        ]);
        $current = $this->membership->currentGroupIds($groups->modelKeys(), $date);

        $rosters = [];
        $counts = [];
        foreach ($groups->groupBy(fn (TrainingGroup $g) => self::key($g->branch_id, $g->category_id)) as $key => $siblings) {
            $first = $siblings->first();
            $own = array_flip($siblings->modelKeys());

            $rosters[$key] = $this->roster->expected($first->category_id, $date, null, $first->branch_id)
                ->map(function (Player $player) use ($current, $own, &$counts) {
                    // A membership left over from another category does not count here.
                    $groupId = isset($current[$player->id], $own[$current[$player->id]]) ? $current[$player->id] : null;
                    if ($groupId !== null) {
                        $counts[$groupId] = ($counts[$groupId] ?? 0) + 1;
                    }

                    return ['id' => $player->id, 'name' => $player->fullname, 'group_id' => $groupId];
                })
                ->values()->all();
        }

        return [
            'groups' => $groups->map(fn (TrainingGroup $g) => [
                'id' => $g->id,
                'branch_id' => $g->branch_id,
                'category_id' => $g->category_id,
                'name' => $g->name,
                'key' => self::key($g->branch_id, $g->category_id),
                'members' => $counts[$g->id] ?? 0,
                'in_use' => isset($used[$g->id]),
            ])->values()->all(),
            'rosters' => $rosters,
        ];
    }

    /** One branch + category, as the page keys its rosters (0 = the whole club). */
    public static function key(?int $branchId, int $categoryId): string
    {
        return ((int) $branchId).':'.$categoryId;
    }
}
```

- [ ] **Step 5: Write the controller**

Create `app/Http/Controllers/AttendanceGroupController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Player;
use App\Models\TrainingGroup;
use App\Services\Attendance\GroupMembership;
use App\Support\CategoryOwnership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Training groups inside a branch + category, managed from the attendance settings page. */
class AttendanceGroupController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => [Rule::requiredIf(fn () => Branch::exists()), 'nullable', 'integer', 'exists:branches,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:60'],
        ]);
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        $categoryId = (int) $data['category_id'];
        CategoryOwnership::assertIn('category_id', $categoryId, $branchId);

        TrainingGroup::create([
            'branch_id' => $branchId,
            'category_id' => $categoryId,
            'name' => $this->freeName($data['name'], $branchId, $categoryId),
        ]);

        return back()->with('success', 'flash.training_group_saved');
    }

    /** Only the name changes: a group never moves to another branch or category. */
    public function update(Request $request, TrainingGroup $group): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $group->update(['name' => $this->freeName($data['name'], $group->branch_id, $group->category_id, $group->id)]);

        return back()->with('success', 'flash.training_group_saved');
    }

    public function destroy(TrainingGroup $group): RedirectResponse
    {
        // The two group_id columns have no foreign key (see the create_training_groups migration).
        if ($group->isInUse()) {
            return back()->with('error', 'flash.training_group_in_use');
        }

        $group->delete();

        return back()->with('success', 'flash.training_group_deleted');
    }

    /**
     * The tick list: who was ticked (`add`) and unticked (`remove`), applied
     * from `from` on; no date = from the beginning (see GroupMembership).
     */
    public function members(Request $request, TrainingGroup $group, GroupMembership $membership): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'add' => ['nullable', 'array'],
            'add.*' => ['integer', 'distinct'],
            'remove' => ['nullable', 'array'],
            'remove.*' => ['integer', 'distinct'],
        ]);
        $add = array_map('intval', $data['add'] ?? []);
        $remove = array_map('intval', $data['remove'] ?? []);
        $from = $data['from'] ?? null;

        // Only players of the group's own branch + category can join it.
        $fitting = Player::registeredWhere(branches: $group->branch_id, categories: $group->category_id)->whereIn('id', $add)->count();
        if ($fitting !== count($add)) {
            throw ValidationException::withMessages(['add' => 'att.error.group_wrong_player']);
        }

        DB::transaction(function () use ($membership, $group, $add, $remove, $from) {
            foreach ($remove as $playerId) {
                $membership->remove($group, $playerId, $from);
            }
            foreach ($add as $playerId) {
                $membership->add($group, $playerId, $from);
            }
        });

        return back()->with('success', 'flash.training_group_members_saved');
    }

    /** The trimmed name, refused when another group of the same branch + category already has it. */
    private function freeName(string $name, ?int $branchId, int $categoryId, ?int $ignoreId = null): string
    {
        $name = trim($name);
        $taken = TrainingGroup::where('branch_id', $branchId) // null → whereNull
            ->where('category_id', $categoryId)
            ->where('name', $name)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'att.error.group_name_taken']);
        }

        return $name;
    }
}
```

- [ ] **Step 6: Send the board to the settings page**

In `app/Http/Controllers/AttendanceSettingsController.php`, add `use App\Services\Attendance\GroupBoard;` to the imports and change `index()`'s signature and first lines, then add two props right after the `'schedules' => …` entry:

```php
    public function index(GroupBoard $board): Response
    {
        $current = Season::current();
        $groups = $board->build(now()->toDateString());
```

```php
            'groups' => $groups['groups'],
            // Keyed by GroupBoard::key(). Empty, it reaches the page as [] (the Vue prop accepts both).
            'groupRosters' => $groups['rosters'],
```

Do not cast `groupRosters` to an object: the Inertia test helpers read props with `Arr::get`, which cannot walk into a `stdClass`.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=AttendanceGroupsTest`
Expected: PASS (8 tests).

Run: `php artisan test --filter=Permission`
Expected: PASS.

Run: `php artisan test --filter=Activity`
Expected: PASS. If a test lists every write route and reports `attendance.groups.*` as unmapped, the fix is the two `config/activity.php` entries of Step 3; do not add an exemption.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/AttendanceGroupController.php app/Services/Attendance/GroupBoard.php app/Http/Controllers/AttendanceSettingsController.php routes/web.php config/permissions.php config/activity.php tests/Feature/AttendanceGroupsTest.php
git commit -m "feat(attendance): manage training groups and their players"
```

---

### Task 6: Settings page — Groups card and Group select

**Files:**
- Create: `resources/js/lib/groupMembers.js`
- Create: `tests/js/groupMembers.test.mjs`
- Create: `resources/js/Pages/Attendance/Partials/GroupsCard.vue`
- Modify: `resources/js/Pages/Attendance/Settings.vue`
- Modify: `resources/js/i18n/ar.json`, `resources/js/i18n/fr.json`, `resources/js/i18n/en.json`

**Interfaces:**
- Consumes: props `groups`, `groupRosters` and the four `attendance.groups.*` routes (Task 5); `schedules.*.group_id` and the `group_id` field of the schedule routes (Task 4).
- Produces: `memberChanges(before: number[], ticked: number[]): { add: number[], remove: number[] }` and `groupSections(groups, rosters): [{ key, branch_id, category_id, groups, waiting }]` in `resources/js/lib/groupMembers.js`; `data-help` anchors `set-groups`, `set-group-form`, `set-group-add`, `set-group-players`.

- [ ] **Step 1: Write the failing JS test**

Create `tests/js/groupMembers.test.mjs`:

```js
import test from 'node:test';
import assert from 'node:assert/strict';
import { groupSections, memberChanges } from '../../resources/js/lib/groupMembers.js';

test('memberChanges reports who was ticked and who was unticked', () => {
    assert.deepEqual(memberChanges([1, 2, 3], [2, 3, 4]), { add: [4], remove: [1] });
});

test('memberChanges reports nothing when the ticks did not change', () => {
    assert.deepEqual(memberChanges([1, 2], [2, 1]), { add: [], remove: [] });
});

test('memberChanges compares ids as numbers', () => {
    assert.deepEqual(memberChanges(['1'], [1, '2']), { add: [2], remove: [] });
});

test('groupSections gathers the groups of one branch + category with the players waiting', () => {
    const groups = [
        { id: 1, key: '1:11', branch_id: 1, category_id: 11, name: 'G1' },
        { id: 2, key: '1:11', branch_id: 1, category_id: 11, name: 'G2' },
        { id: 3, key: '2:7', branch_id: 2, category_id: 7, name: 'A' },
    ];
    const rosters = {
        '1:11': [{ id: 5, name: 'a', group_id: 1 }, { id: 6, name: 'b', group_id: null }],
    };

    const sections = groupSections(groups, rosters);

    assert.deepEqual(sections.map((s) => [s.key, s.groups.map((g) => g.id), s.waiting.map((p) => p.id)]), [
        ['1:11', [1, 2], [6]],
        ['2:7', [3], []],
    ]);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:js`
Expected: FAIL — `Cannot find module … resources/js/lib/groupMembers.js`.

- [ ] **Step 3: Write the helper**

Create `resources/js/lib/groupMembers.js`:

```js
/**
 * What saving a group's tick list must send: the players ticked since the
 * dialog opened and the ones unticked. Only changes go to the server, so a
 * player nobody touched keeps their dates.
 */
export function memberChanges(before, ticked) {
    const was = new Set(before.map(Number));
    const now = new Set(ticked.map(Number));

    return {
        add: [...now].filter((id) => !was.has(id)),
        remove: [...was].filter((id) => !now.has(id)),
    };
}

/**
 * The groups gathered by branch + category (their `key`), in the order given,
 * each with the roster players who have no group yet.
 */
export function groupSections(groups, rosters) {
    const sections = new Map();

    for (const group of groups) {
        if (!sections.has(group.key)) {
            sections.set(group.key, {
                key: group.key,
                branch_id: group.branch_id,
                category_id: group.category_id,
                groups: [],
                waiting: (rosters[group.key] ?? []).filter((player) => player.group_id == null),
            });
        }
        sections.get(group.key).groups.push(group);
    }

    return [...sections.values()];
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `npm run test:js`
Expected: PASS (all files, 4 new tests).

- [ ] **Step 5: Add the i18n keys**

Insert each key at its alphabetical place in the three files (they are sorted by key; do not reformat anything else).

`resources/js/i18n/ar.json`:

```json
    "activity.kind.training_group_members": "لاعبو الأفواج",
    "activity.kind.training_groups": "أفواج التدريب",
    "att.error.group_name_taken": "في هذه الفئة فوج بهذا الاسم.",
    "att.error.group_other_category": "هذا الفوج تابع لفرع آخر أو لفئة أخرى.",
    "att.error.group_wrong_player": "أحد اللاعبين المختارين ليس في فئة هذا الفوج.",
    "att.group": "الفوج",
    "att.group_count": "اللاعبون: {n}",
    "att.group_find": "ابحث عن لاعب",
    "att.group_from": "تُطبَّق التغييرات من",
    "att.group_from_help": "اتركه فارغًا لتُطبَّق من البداية: عند أول ضبط، أو لتصحيح خطأ.",
    "att.group_in_other": "في {group}",
    "att.group_name": "اسم الفوج",
    "att.group_no_players": "لا يوجد لاعبون في هذه الفئة.",
    "att.group_none": "لا توجد أفواج بعد.",
    "att.group_players": "لاعبو الفوج",
    "att.group_waiting": "بدون فوج: {n}",
    "att.group_whole": "كل الفئة",
    "att.groups": "الأفواج",
    "att.groups_help": "قسّم لاعبي الفئة إلى أفواج، ثم اختر الفوج في سطر التوقيت الأسبوعي. حصة الفوج تعرض لاعبيه فقط.",
    "flash.training_group_deleted": "تم حذف الفوج.",
    "flash.training_group_in_use": "لا يمكن حذف هذا الفوج: يستعمله سطر في التوقيت الأسبوعي أو حصة.",
    "flash.training_group_members_saved": "تم حفظ لاعبي الفوج.",
    "flash.training_group_saved": "تم حفظ الفوج.",
```

`resources/js/i18n/fr.json`:

```json
    "activity.kind.training_group_members": "Joueurs des groupes",
    "activity.kind.training_groups": "Groupes d'entraînement",
    "att.error.group_name_taken": "Cette catégorie a déjà un groupe de ce nom.",
    "att.error.group_other_category": "Ce groupe appartient à une autre section ou à une autre catégorie.",
    "att.error.group_wrong_player": "Un des joueurs choisis n'est pas dans la catégorie de ce groupe.",
    "att.group": "Groupe",
    "att.group_count": "Joueurs : {n}",
    "att.group_find": "Chercher un joueur",
    "att.group_from": "Changements appliqués à partir du",
    "att.group_from_help": "Laissez vide pour appliquer depuis le début : au premier réglage, ou pour corriger une erreur.",
    "att.group_in_other": "Dans {group}",
    "att.group_name": "Nom du groupe",
    "att.group_no_players": "Aucun joueur dans cette catégorie.",
    "att.group_none": "Aucun groupe pour l'instant.",
    "att.group_players": "Joueurs du groupe",
    "att.group_waiting": "Sans groupe : {n}",
    "att.group_whole": "Toute la catégorie",
    "att.groups": "Groupes",
    "att.groups_help": "Répartissez les joueurs de la catégorie en groupes, puis choisissez le groupe sur la ligne de l'horaire hebdomadaire. La séance d'un groupe n'affiche que ses joueurs.",
    "flash.training_group_deleted": "Groupe supprimé.",
    "flash.training_group_in_use": "Ce groupe ne peut pas être supprimé : une ligne de l'horaire hebdomadaire ou une séance l'utilise.",
    "flash.training_group_members_saved": "Joueurs du groupe enregistrés.",
    "flash.training_group_saved": "Groupe enregistré.",
```

`resources/js/i18n/en.json`:

```json
    "activity.kind.training_group_members": "Group players",
    "activity.kind.training_groups": "Training groups",
    "att.error.group_name_taken": "This category already has a group with this name.",
    "att.error.group_other_category": "This group belongs to another branch or category.",
    "att.error.group_wrong_player": "One of the chosen players is not in this group's category.",
    "att.group": "Group",
    "att.group_count": "Players: {n}",
    "att.group_find": "Find a player",
    "att.group_from": "Changes apply from",
    "att.group_from_help": "Leave empty to apply from the beginning: at the first setup, or to fix a mistake.",
    "att.group_in_other": "In {group}",
    "att.group_name": "Group name",
    "att.group_no_players": "No players in this category.",
    "att.group_none": "No groups yet.",
    "att.group_players": "Group players",
    "att.group_waiting": "Without group: {n}",
    "att.group_whole": "Whole category",
    "att.groups": "Groups",
    "att.groups_help": "Split the category's players into groups, then choose the group on the weekly schedule line. A group's session lists only its players.",
    "flash.training_group_deleted": "Group deleted.",
    "flash.training_group_in_use": "This group cannot be deleted: a weekly schedule line or a session uses it.",
    "flash.training_group_members_saved": "Group players saved.",
    "flash.training_group_saved": "Group saved.",
```

Run: `npm run i18n:check`
Expected: passes with no missing key and no compile error.

- [ ] **Step 6: Write the Groups card**

Create `resources/js/Pages/Attendance/Partials/GroupsCard.vue`:

```vue
<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import IconButton from '@/Components/IconButton.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { categoriesForBranch } from '@/lib/categoryPicker';
import { groupSections, memberChanges } from '@/lib/groupMembers';
import { localToday } from '@/lib/localDate';

const props = defineProps({
    branches: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    groups: { type: Array, default: () => [] }, // [{ id, branch_id, category_id, name, key, members, in_use }]
    rosters: { type: [Object, Array], default: () => ({}) }, // key => [{ id, name, group_id }]; [] when there is no group
});
const { t } = useI18n();
const today = localToday();

const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
const card = 'rounded-xl bg-white p-4 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
// Only att.* values are translation keys; Laravel's own messages show as they are.
const tr = (e) => (typeof e === 'string' && e.startsWith('att.') ? t(e) : e);
const branchName = (id) => props.branches.find((b) => b.id === id)?.name ?? '';
const categoryName = (id) => props.categories.find((c) => c.id === id)?.name ?? '';
const groupName = (id) => props.groups.find((g) => g.id === id)?.name ?? '';

// ---- Create or rename ----
const editingId = ref(null);
const firstOf = (branchId) => categoriesForBranch(props.categories, branchId)[0]?.id ?? null;
const form = useForm({ branch_id: props.branches[0]?.id ?? null, category_id: firstOf(props.branches[0]?.id ?? null), name: '' });
const formCategories = computed(() => categoriesForBranch(props.categories, form.branch_id, [form.category_id]));
watch(() => form.branch_id, (branchId) => {
    if (!categoriesForBranch(props.categories, branchId).some((c) => c.id === form.category_id)) form.category_id = firstOf(branchId);
});
function edit(group) {
    editingId.value = group.id;
    form.clearErrors();
    Object.assign(form, { branch_id: group.branch_id, category_id: group.category_id, name: group.name });
    // The branch watch runs after both are set; keep a stored category selectable.
    nextTick(() => { form.category_id = group.category_id; });
}
function resetForm() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
}
function submit() {
    if (editingId.value) {
        form.put(route('attendance.groups.update', editingId.value), { preserveScroll: true, onSuccess: resetForm });
    } else {
        // Branch and category stay chosen: the next group of the same category is one name away.
        form.post(route('attendance.groups.store'), { preserveScroll: true, onSuccess: () => form.reset('name') });
    }
}
function destroy(group) {
    if (window.confirm(t('att.confirm_delete'))) router.delete(route('attendance.groups.destroy', group.id), { preserveScroll: true });
}

// ---- List: one block per branch + category, with who is waiting for a group ----
const sections = computed(() => groupSections(props.groups, props.rosters));
const waitingOpen = ref(null); // the section key whose waiting names are shown

// ---- Players of one group: a tick list, saved as changes from a date ----
const open = ref(null); // the group being edited
const ticked = ref([]);
const from = ref('');
const query = ref('');
const saving = ref(false);
const memberError = ref('');
const roster = computed(() => (open.value ? props.rosters[open.value.key] ?? [] : []));
const shown = computed(() => {
    const q = query.value.trim().toLowerCase();
    return q ? roster.value.filter((p) => p.name.toLowerCase().includes(q)) : roster.value;
});
const membersBefore = () => roster.value.filter((p) => p.group_id === open.value.id).map((p) => p.id);
function openPlayers(group) {
    open.value = group;
    query.value = '';
    memberError.value = '';
    ticked.value = (props.rosters[group.key] ?? []).filter((p) => p.group_id === group.id).map((p) => p.id);
    // An empty group is a first setup: from the beginning. Later changes default to today.
    from.value = group.members ? today : '';
}
function savePlayers() {
    const { add, remove } = memberChanges(membersBefore(), ticked.value);
    if (!add.length && !remove.length) {
        open.value = null;
        return;
    }
    router.put(route('attendance.groups.members.update', open.value.id), { from: from.value || null, add, remove }, {
        preserveScroll: true,
        onStart: () => { saving.value = true; memberError.value = ''; },
        onFinish: () => { saving.value = false; },
        onSuccess: () => { open.value = null; },
        onError: (errors) => { memberError.value = tr(Object.values(errors)[0]); },
    });
}
</script>

<template>
    <section data-help="set-groups" :class="card">
        <h2 class="mb-1 font-bold text-slate-900 dark:text-slate-100">{{ t('att.groups') }}</h2>
        <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">{{ t('att.groups_help') }}</p>

        <form data-help="set-group-form" class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="submit">
            <label v-if="branches.length" class="text-xs text-slate-500 dark:text-slate-400">{{ t('branch') }}
                <select v-model="form.branch_id" :disabled="!!editingId" :class="[input, 'block']"><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
            </label>
            <label class="text-xs text-slate-500 dark:text-slate-400">{{ t('att.category') }}
                <select v-model="form.category_id" :disabled="!!editingId" :class="[input, 'block']"><option v-for="c in formCategories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
            </label>
            <label class="text-xs text-slate-500 dark:text-slate-400">{{ t('att.group_name') }}
                <input v-model="form.name" type="text" maxlength="60" dir="auto" :class="[input, 'block w-44']" />
            </label>
            <button data-help="set-group-add" type="submit" :disabled="form.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ editingId ? t('att.save') : t('att.add') }}</button>
            <button v-if="editingId" type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:text-slate-400 dark:ring-slate-700" @click="resetForm">{{ t('att.close') }}</button>
            <div class="w-full"><InputError v-for="(e, k) in form.errors" :key="k" :message="tr(e)" /></div>
        </form>

        <p v-if="!groups.length" class="text-sm text-slate-500 dark:text-slate-400">{{ t('att.group_none') }}</p>

        <div v-for="s in sections" :key="s.key" class="border-t border-slate-100 py-2 dark:border-slate-800">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ [branchName(s.branch_id), categoryName(s.category_id)].filter(Boolean).join(' · ') }}</h3>
                <button
                    v-if="s.waiting.length"
                    type="button"
                    class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800 hover:bg-amber-200 dark:bg-amber-500/20 dark:text-amber-300 dark:hover:bg-amber-500/30"
                    :aria-expanded="waitingOpen === s.key"
                    @click="waitingOpen = waitingOpen === s.key ? null : s.key"
                >{{ t('att.group_waiting', { n: s.waiting.length }) }}</button>
            </div>
            <ul v-if="waitingOpen === s.key" class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-amber-800 dark:text-amber-300">
                <li v-for="p in s.waiting" :key="p.id">{{ p.name }}</li>
            </ul>
            <table class="mt-1 w-full text-sm">
                <tbody>
                    <tr v-for="g in s.groups" :key="g.id">
                        <td class="py-1.5 font-medium">{{ g.name }}</td>
                        <td class="text-slate-500 dark:text-slate-400">{{ t('att.group_count', { n: g.members }) }}</td>
                        <td class="whitespace-nowrap text-end">
                            <IconButton data-help="set-group-players" icon="users" :label="t('att.group_players')" plain size="sm" @click="openPlayers(g)" />
                            <IconButton icon="pencil" :label="t('att.edit')" plain size="sm" @click="edit(g)" />
                            <IconButton icon="trash" :label="t('att.delete')" variant="danger" plain size="sm" :disabled="g.in_use" :title="g.in_use ? t('flash.training_group_in_use') : t('att.delete')" @click="destroy(g)" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :show="!!open" max-width="md" @close="open = null">
            <form v-if="open" class="space-y-3 p-5" @submit.prevent="savePlayers">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.group_players') }} · {{ open.name }}</h2>
                <label class="block text-sm">{{ t('att.group_from') }}
                    <input v-model="from" type="date" :class="[input, 'mt-1 block']" />
                    <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ t('att.group_from_help') }}</span>
                </label>
                <input v-model="query" type="text" :placeholder="t('att.group_find')" :aria-label="t('att.group_find')" :class="[input, 'block w-full']" />
                <p v-if="!roster.length" class="text-sm text-slate-500 dark:text-slate-400">{{ t('att.group_no_players') }}</p>
                <ul class="max-h-80 space-y-1 overflow-y-auto text-sm">
                    <li v-for="p in shown" :key="p.id">
                        <label class="flex items-center gap-2">
                            <input v-model="ticked" type="checkbox" :value="p.id" class="rounded border-slate-300 text-primary-600 dark:border-slate-700 dark:bg-slate-900" />
                            <span class="min-w-0 flex-1 truncate">{{ p.name }}</span>
                            <span v-if="p.group_id && p.group_id !== open.id" class="shrink-0 rounded bg-slate-100 px-1.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('att.group_in_other', { group: groupName(p.group_id) }) }}</span>
                        </label>
                    </li>
                </ul>
                <InputError :message="memberError" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:text-slate-400 dark:ring-slate-700" @click="open = null">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="saving" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
                </div>
            </form>
        </Modal>
    </section>
</template>
```

Check the icon name before moving on: open `resources/js/Components/Icon.vue` and confirm it has an icon named `users`. If it does not, use the name it gives to its "people" icon (the one the sidebar uses for players) in the `IconButton` above. Do not add a new icon.

- [ ] **Step 7: Mount the card and add the Group select to the schedule form**

In `resources/js/Pages/Attendance/Settings.vue`:

1. Imports — add after the `InputError` import:

```js
import GroupsCard from '@/Pages/Attendance/Partials/GroupsCard.vue';
```

2. Props — add after the `schedules` prop:

```js
    groups: { type: Array, default: () => [] }, // [{ id, branch_id, category_id, name, key, members, in_use }]
    groupRosters: { type: [Object, Array], default: () => ({}) }, // [] when there is no group
```

3. Add a name lookup after `categoryName`:

```js
const groupName = (id) => props.groups.find((g) => g.id === id)?.name ?? '';
```

4. Replace the weekly-schedule script block from `const scheduleForm = useForm(...)` down to the end of `submitSchedule()` with:

```js
const scheduleForm = useForm({ branch_id: props.branches[0]?.id ?? null, category_id: firstOf(props.branches[0]?.id ?? null), group_id: null, weekday: 1, start_time: '18:00', end_time: '19:30', valid_from: today, valid_to: '' });
const scheduleCategories = computed(() => categoriesForBranch(props.categories, scheduleForm.branch_id, [scheduleForm.category_id]));
// The groups of the chosen branch + category; none = the select is not shown.
const scheduleGroups = computed(() => props.groups.filter((g) => (g.branch_id ?? null) === (scheduleForm.branch_id ?? null) && g.category_id === scheduleForm.category_id));
watch(() => scheduleForm.branch_id, (branchId) => {
    if (!categoriesForBranch(props.categories, branchId).some((c) => c.id === scheduleForm.category_id)) scheduleForm.category_id = firstOf(branchId);
});
// A group belongs to one branch + category: another choice drops it.
watch(() => [scheduleForm.branch_id, scheduleForm.category_id], () => {
    if (!scheduleGroups.value.some((g) => g.id === scheduleForm.group_id)) scheduleForm.group_id = null;
});
function editSchedule(s) {
    editingId.value = s.id;
    Object.assign(scheduleForm, { branch_id: s.branch_id, category_id: s.category_id, weekday: s.weekday, start_time: s.start_time, end_time: s.end_time, valid_from: s.valid_from, valid_to: s.valid_to ?? '' });
    // The watches run after the fields are set; keep a stored category selectable, then its group.
    nextTick(() => {
        scheduleForm.category_id = s.category_id;
        nextTick(() => { scheduleForm.group_id = s.group_id ?? null; });
    });
}
function resetSchedule() {
    editingId.value = null;
    scheduleForm.reset();
    scheduleForm.clearErrors();
}
function submitSchedule() {
    const opts = { preserveScroll: true, onSuccess: resetSchedule };
    scheduleForm.transform((d) => ({ ...d, group_id: d.group_id || null, valid_to: d.valid_to || null }));
    editingId.value ? scheduleForm.put(route('attendance.schedules.update', editingId.value), opts) : scheduleForm.post(route('attendance.schedules.store'), opts);
}
```

5. Template — right after `<div class="grid grid-cols-1 gap-4 lg:grid-cols-2">` and before the `<!-- Weekly schedule -->` comment, add:

```vue
            <!-- Training groups -->
            <GroupsCard class="lg:col-span-2" :branches="branches" :categories="categories" :groups="groups" :rosters="groupRosters" />
```

6. Template — in the schedule form, right after the category `<label>…</label>` and before the weekday label, add:

```vue
                    <label v-if="scheduleGroups.length" class="text-xs text-slate-500 dark:text-slate-400">{{ t('att.group') }}
                        <select v-model="scheduleForm.group_id" :class="[input, 'block']">
                            <option :value="null">{{ t('att.group_whole') }}</option>
                            <option v-for="g in scheduleGroups" :key="g.id" :value="g.id">{{ g.name }}</option>
                        </select>
                    </label>
```

7. Template — the schedule form's error line becomes (errors of `group_id` are `att.*` keys):

```vue
                    <div class="w-full"><InputError v-for="(e, k) in scheduleForm.errors" :key="k" :message="tr(e)" /></div>
```

8. Template — the first cell of each schedule row becomes:

```vue
                            <td class="py-2 font-medium">{{ [branchName(s.branch_id), categoryName(s.category_id), groupName(s.group_id)].filter(Boolean).join(' · ') }}</td>
```

The Group select only appears when the chosen branch + category has groups, so the schedule form of a category without groups is unchanged (the Help Center shots of that form pick its selects by position).

- [ ] **Step 8: Build and run the checks**

Run: `npm run build`
Expected: builds with no error.

Run: `npm run i18n:check`
Expected: PASS.

Run: `npm run test:js`
Expected: PASS.

Run: `php artisan test --filter=AttendanceSettings`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add resources/js/lib/groupMembers.js tests/js/groupMembers.test.mjs resources/js/Pages/Attendance/Partials/GroupsCard.vue resources/js/Pages/Attendance/Settings.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(attendance): groups card and group choice on schedule lines"
```

---

### Task 7: Group name on the calendar and the session page

**Files:**
- Modify: `app/Services/Attendance/CalendarFeed.php` (`sessions()`)
- Modify: `app/Http/Controllers/AttendanceController.php` (`show()`, the `session` array)
- Modify: `resources/js/Pages/Attendance/Partials/MonthView.vue`
- Modify: `resources/js/Pages/Attendance/Partials/WeekView.vue`
- Modify: `resources/js/Pages/Attendance/Partials/AgendaView.vue`
- Modify: `resources/js/Pages/Attendance/Partials/TimelineView.vue`
- Modify: `resources/js/Pages/Attendance/Session.vue`
- Test: `tests/Feature/AttendanceGroupNameTest.php`

**Interfaces:**
- Consumes: `TrainingSession::group()` (Task 1).
- Produces: `group` (the group's name or null) on every session of `CalendarFeed::sessions()` and on the session page's `session` prop.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttendanceGroupNameTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Services\Attendance\CalendarFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceGroupNameTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function the_calendar_feed_and_the_session_page_name_the_group(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $group = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G1']);
        $base = [
            'branch_id' => $swim->id, 'category_id' => $minime->id, 'date' => '2026-11-21', 'end_time' => '23:00',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ];
        $grouped = TrainingSession::create($base + ['group_id' => $group->id, 'start_time' => '09:30']);
        $plain = TrainingSession::create($base + ['start_time' => '11:00']);

        $feed = app(CalendarFeed::class)->sessions('2026-11-01', '2026-11-30');
        $this->assertSame(['G1', null], $feed->pluck('group')->all());

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('attendance.sessions.show', $grouped))
            ->assertInertia(fn (Assert $page) => $page->where('session.group', 'G1'));
        $this->actingAs($admin)->get(route('attendance.sessions.show', $plain))
            ->assertInertia(fn (Assert $page) => $page->where('session.group', null));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AttendanceGroupNameTest`
Expected: FAIL — the feed rows have no `group` key (`[null, null]` instead of `['G1', null]`).

- [ ] **Step 3: Send the group name**

In `app/Services/Attendance/CalendarFeed.php`, in `sessions()`, change the eager load and add one key after `'branch'`:

```php
            ->with(['categories', 'branch', 'group'])
```

```php
            'branch' => $s->branch?->localized_name,
            'group' => $s->group?->name,
```

In `app/Http/Controllers/AttendanceController.php`, in `show()`, add one key to the `session` array right after `'category' => $categories->implode(' · '),`:

```php
                // The training group whose members make the list; null = the whole category.
                'group' => $session->group?->name,
```

- [ ] **Step 4: Show it**

`resources/js/Pages/Attendance/Partials/MonthView.vue` — replace `chipTitle` and add the group to the chip, right before the `<span v-if="s.title" …>` line:

```js
const chipTitle = (s) => [t(`att.kind.${s.kind}`), t(`att.state.${s.state}`), s.categories.map((c) => c.name).join(', '), s.group, s.title, summaryText(s.summary)]
    .filter(Boolean).join(' · ');
```

```vue
                        <span v-if="s.group" class="min-w-0 truncate">{{ s.group }}</span>
```

`resources/js/Pages/Attendance/Partials/WeekView.vue` — replace `blockTitle` and the categories line of the block:

```js
const blockTitle = (s) => [`${s.start_time}–${s.end_time}`, t(`att.kind.${s.kind}`), t(`att.state.${s.state}`), s.categories.map((c) => c.name).join(', '), s.group, s.title]
    .filter(Boolean).join(' · ');
```

```vue
                        <div class="truncate">{{ [b.session.categories.map((c) => c.name).join(' · '), b.session.group].filter(Boolean).join(' · ') }}</div>
```

`resources/js/Pages/Attendance/Partials/AgendaView.vue` — the categories cell becomes:

```vue
                            <td class="p-2">{{ [s.categories.map((c) => c.name).join(' · '), s.group].filter(Boolean).join(' · ') }}</td>
```

`resources/js/Pages/Attendance/Partials/TimelineView.vue` — the categories line becomes:

```vue
                                <div class="mt-1 font-semibold text-slate-900 dark:text-slate-100" :class="{ 'line-through': e.state === 'cancelled' }">{{ [e.categories.map((c) => c.name).join(' · '), e.group].filter(Boolean).join(' · ') }}</div>
```

`resources/js/Pages/Attendance/Session.vue` — the header title span becomes:

```vue
                            <span>{{ [session.branch?.name, session.category, session.group].filter(Boolean).join(' · ') }} · {{ t(`att.kind.${session.kind}`) }}</span>
```

- [ ] **Step 5: Run the tests and build**

Run: `php artisan test --filter=AttendanceGroupNameTest`
Expected: PASS.

Run: `php artisan test --filter=AttendanceCalendar`
Expected: PASS (this includes `AttendanceCalendarPerformanceTest`).

Run: `php artisan test --filter=SessionRosterQueryBudgetTest`
Expected: PASS. A session with no group must cost no extra query: `with('group')` skips its query when every `group_id` is null, and `$session->group` does not query for a null key. If a budget fails, find the extra query; do not raise the budget.

Run: `npm run build`
Expected: builds with no error.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/CalendarFeed.php app/Http/Controllers/AttendanceController.php resources/js/Pages/Attendance/Partials/MonthView.vue resources/js/Pages/Attendance/Partials/WeekView.vue resources/js/Pages/Attendance/Partials/AgendaView.vue resources/js/Pages/Attendance/Partials/TimelineView.vue resources/js/Pages/Attendance/Session.vue tests/Feature/AttendanceGroupNameTest.php
git commit -m "feat(attendance): show the group on the calendar and the session page"
```

---

### Task 8: Help Center

The settings page gained a card, and the weekly schedule can now carry a group. Phase 1 adds one paragraph to the timetable article in the three languages and retakes the screenshots of the settings page. The illustrated step-by-step article about groups comes with phase 2, when the player card also sets the group.

**Files:**
- Modify: `resources/help/ar/attendance/timetable.md`
- Modify: `resources/help/fr/attendance/timetable.md`
- Modify: `resources/help/en/attendance/timetable.md`
- Replace: the images under `public/help-shots/{ar,fr,en}/` of every shot taken on `/attendance/settings`

**Interfaces:**
- Consumes: the labels of Task 6 (`att.groups`, `att.group_players`, `att.group`, `att.schedules`).

- [ ] **Step 1: Read the authoring rules**

Read `resources/help/README.md` in full (sections "Rules", "Screenshots", "Translations (French, English)", "Check before committing"). Follow it wherever it is stricter than this task.

- [ ] **Step 2: Add the paragraph in Arabic**

In `resources/help/ar/attendance/timetable.md`:

In the front matter, add `الأفواج` and `فوج` to `keywords` (before `إعدادات الحضور`).

Right after the paragraph that starts with `في آخر كل سطر من «التوقيت الأسبوعي» زر قلم`, add:

```markdown
فئة تتدرّب في أكثر من فوج؟ في بطاقة «الأفواج»، في الصفحة نفسها، أضف فوجًا لكل مجموعة. ثم اضغط زر «لاعبو الفوج» في سطر الفوج، وضع علامة على لاعبيه. بعدها يظهر اختيار «الفوج» في بطاقة «التوقيت الأسبوعي»: اختر الفوج لكل سطر. حصة الفوج تعرض لاعبيه فقط. اللاعب الذي ليس في أي فوج لا يظهر في حصص الأفواج، والبطاقة تذكّرك بعدد اللاعبين الذين بلا فوج.
```

- [ ] **Step 3: Add the twin paragraphs**

In `resources/help/fr/attendance/timetable.md`, add `groupes` and `groupe` to `keywords` (before `réglages des présences`), and add at the same place as in the Arabic file (after the paragraph about the pencil and bin buttons of each line):

```markdown
Une catégorie s'entraîne en plusieurs groupes ? Dans la carte « Groupes », sur la même page, ajoutez un groupe pour chacun. Puis appuyez sur le bouton « Joueurs du groupe » de sa ligne et cochez ses joueurs. Le choix « Groupe » apparaît alors dans la carte « Horaire hebdomadaire » : choisissez le groupe de chaque ligne. La séance d'un groupe n'affiche que ses joueurs. Un joueur qui n'est dans aucun groupe n'apparaît pas dans les séances des groupes ; la carte vous rappelle combien de joueurs sont sans groupe.
```

In `resources/help/en/attendance/timetable.md`, add `groups` and `group` to `keywords` (before the last keyword), and add at the same place:

```markdown
Does a category train in more than one group? In the "Groups" card, on the same page, add a group for each one. Then press the "Group players" button on its line and tick its players. The "Group" choice then appears in the "Weekly schedule" card: choose the group of each line. A group's session lists only its players. A player who is in no group does not appear in group sessions; the card reminds you how many players are without a group.
```

Before saving, open the French and English files and match the quote style and the surrounding paragraph breaks they already use.

- [ ] **Step 4: Retake the settings page screenshots**

List the shots taken on the settings page:

```bash
grep -n -B3 "url: '/attendance/settings'" resources/help/shots.js | grep "name:"
```

Take each of them again in the three languages, following the "Screenshots" section of `resources/help/README.md` for the demo database and the server (replace `<names>` with the shot names printed above, separated by spaces):

```bash
npm run help:shots -- <names>
npm run help:shots -- --locale=fr <names>
npm run help:shots -- --locale=en <names>
```

Expected: each run ends without "target missing". Open one `set-schedule` image per language (light and dark): the «الأفواج» card shows above the weekly schedule with «لا توجد أفواج بعد.», and the ringed schedule form is fully visible.

- [ ] **Step 5: Check**

Run: `php artisan help:check`
Expected: no error.

Run: `php artisan test --filter=Help`
Expected: PASS.

- [ ] **Step 6: Commit**

Stage the three article files and each image the runs wrote (`git status --short public/help-shots` lists them), by path:

```bash
git add resources/help/ar/attendance/timetable.md resources/help/fr/attendance/timetable.md resources/help/en/attendance/timetable.md
git add <each public/help-shots/{ar,fr,en}/ file listed by git status>
git commit -m "docs(help): training groups in the timetable article"
```

---

### Task 9: Whole-branch verification

**Files:** none (verification only; fixes go into the task they belong to, as new commits).

- [ ] **Step 1: Run the whole PHP suite in the background**

Run (background, one run at a time): `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit`
Expected: all tests pass. Any failure in a test this plan did not write means a shared rule changed: read it, fix the cause, run the whole suite again.

- [ ] **Step 2: Run the front-end checks**

Run: `npm run build` — Expected: no error.
Run: `npm run i18n:check` — Expected: PASS.
Run: `npm run test:js` — Expected: PASS.
Run: `php artisan help:check` — Expected: no error.

- [ ] **Step 3: Migrate a copy of the dev database twice**

Copy `D:/irnb-laravel/database/database.sqlite` to the worktree as `database/groups-check.sqlite` (never open the live file from the worktree). Then:

```bash
DB_DATABASE="$(pwd)/database/groups-check.sqlite" APP_CONFIG_CACHE="$(pwd)/bootstrap/cache/config-groups-check.php" php artisan migrate --force
DB_DATABASE="$(pwd)/database/groups-check.sqlite" APP_CONFIG_CACHE="$(pwd)/bootstrap/cache/config-groups-check.php" php artisan migrate --force
```

Expected: the first run applies `2026_11_20_200001_create_training_groups`; the second says "Nothing to migrate". Then check nothing moved:

```bash
DB_DATABASE="$(pwd)/database/groups-check.sqlite" APP_CONFIG_CACHE="$(pwd)/bootstrap/cache/config-groups-check.php" php artisan tinker --execute="echo json_encode([DB::table('training_sessions')->count(), DB::table('training_sessions')->whereNotNull('group_id')->count(), DB::table('attendances')->count()]);"
```

Expected: the first and third numbers equal the live database's counts; the second is `0`.

- [ ] **Step 4: Click through the owner's case on that copy**

Serve the copy (`php artisan serve --port=2027 --no-reload` with the same two environment variables) and, in the browser at `http://127.0.0.1:2027`:

1. Attendance → settings. In «الأفواج», add «الفوج 1» and «الفوج 2» for Swimming · Minime.
2. Open «لاعبو الفوج» of «الفوج 1»: the date is empty; tick half of the players; save. The count beside the group changes and «بدون فوج» shows the rest.
3. Do the same for «الفوج 2» with the other players: «بدون فوج» disappears.
4. Edit the Saturday 09:30 line: the «الفوج» select is there; choose «الفوج 1»; save. Edit the 11:00 line: choose «الفوج 2».
5. Open the calendar on Swimming · Minime: each Saturday shows two sessions named with their group. Open each: it lists only its group's players, and anyone else can still be added by hand.
6. Open a football category month and one football session: nothing changed, no group shown.
7. Switch the interface to French, then English, then dark mode, then a 320 px wide window: the Groups card and the players dialog stay readable.
8. Open the activity page: the group changes are listed with a readable label.

Stop the server and delete `database/groups-check.sqlite` and `bootstrap/cache/config-groups-check.php`.

- [ ] **Step 5: Finish the branch**

Use superpowers:finishing-a-development-branch. Do not push: the owner decides. Report the suite totals, the two migrate runs, and each of the eight browser checks as passed or failed.

---

## Self-review notes

- Spec coverage (phase 1 only): data + migration → Task 1; membership rules incl. category change → Task 2; roster, grid save, month sheet cells → Task 3; schedule line group, generator, retag on save → Task 4; Groups card data, create/rename/delete guard, tick list, waiting count, edit right → Tasks 5–6; group name on calendar and session page → Task 7; Help Center → Task 8. Phase 2 (player card, per-group grid and printed sheet, add-session and Edit dialogs, session paper sheet) and phase 3 (report filter) have their own plans.
- Names used across tasks: `TrainingGroup`, `TrainingGroupMember::activeOn` / `constrainActiveOn`, `GroupMembership::add/remove/closeInBranch/currentGroupIds`, `Roster::expected(..., ?int $groupId)`, `GroupBoard::build/key`, route parameter `group`, props `groups` / `groupRosters`, JS `memberChanges` / `groupSections`.
