# Branch registrations, Release 2, Part 7: Category split, branch page, pickers

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each branch its own categories: split the stored data once, enforce that a category belongs to its branch, manage categories on a per-branch tab, and offer only the right categories in every picker.

**Architecture:** One idempotent migration adds `categories.branch_id` and `is_active`, gives every category to the main branch, and copies to each other branch only what it uses, repointing that branch's rows and prices. `CategoryOwnership` holds the "belongs to its branch" check for every writer. `categoryPicker.js` and `sportLabels.js` (client) and `CategoryLabels` (server) hold the picker and naming rules.

**Tech Stack:** Laravel 13, Eloquent, PHPUnit attribute tests, Vue 3 with Inertia, Tailwind, `node --test`. Spec: `docs/superpowers/specs/2026-11-05-branch-registrations-r2-design.md`, section 11 and the category and price lines of section 14.

## Global Constraints

The main plan `2026-11-05-branch-registrations-r2.md` applies in full. Parts 1 to 6 are done before this part starts, so every reader already uses the registration. Line numbers below are from `dd839a2`; earlier parts may have moved them, so find the quoted code.

Part-specific constraints:

- **A foreign key on `categories.branch_id` on every database, `ON DELETE RESTRICT`** (owner, 2026-11-05: "protect it"). Laravel's `foreign()` on SQLite rebuilds the table, and `categories` has cascading children, so a rebuild would delete them. So never use it there: SQLite gets the key from raw `ALTER TABLE ... ADD COLUMN ... REFERENCES`, which rewrites nothing. Checked on SQLite 3.40 with foreign keys on: bad IDs and deleting a branch that still owns a category are refused, children kept, native `DROP COLUMN` works. `BranchController::destroy` deletes a branch's unused categories first and refuses a used one. A test that deletes a branch owning categories, or names a branch that doesn't exist, now fails on SQLite as it would on PostgreSQL: fix the test, not the key.
- **A category with no owner is valid only where no branch exists.** Production gets there only with no branches at all, because the first branch adopts every unowned category and `registrations:verify` fails on any left over once a branch exists. `CategoryOwnership` and the pickers accept an unowned category on any branch, so test fixtures that don't care about ownership keep working.
- **`sort_order` is not added.** Lists keep sorting by name, or by ID where they do today.
- **A stored value that no longer fits stays until edited**, as positions do. Every ownership check exempts the value already saved.
- **Attendance reports and filters list inactive categories; pickers for new choices don't.** Deactivated means hidden from new choices, kept where stored, still in reports.
- Not repointed: `users.category_id` (no reader). It stays on the main branch's row.

**How to repair existing tests in this part.** Many tests create one category and use it on two branches. After Task P7.3 and Task P7.6 they fail with `validation.category_other_branch`, a 422, or an empty calendar. Apply one of these rules, whichever keeps what the test is about. Never weaken an assertion.

| The test | Fix |
|---|---|
| Is about something other than ownership, and uses one category across branches | After the branches exist, add `$category->update(['branch_id' => null]);` so it stays unowned |
| Creates a category before its first branch, then uses it on the second | Same fix |
| Is about a two-branch player or per-branch behaviour | Create one category per branch: `Category::create(['name' => 'U15', 'branch_id' => $swim->id])` |

Likely files: `AttendanceCalendarBranchTest`, `AttendanceSessionBranchTest`, `AttendanceSettingsBranchTest`, `AttendanceBranchStatsTest`, `AttendanceBranchStatsPageTest`, `AttendanceBranchAlertsTest`, `AttendanceBranchRankingTest`, `AttendanceBranchInjuriesTest`, `CashRegisterManagementTest`, `CaisseRulesTest`, `Competitions/TeamsTest`, `SubscriptionBranchEligibilityTest`, `SubscriptionOutsideCategoryTest`, `Registrations/PlayerRegistrationCardsTest`, `Registrations/RegistrationFormFixesTest`, `Registrations/RegistrationWritersTest`, and the tests added by Parts 2 to 6.

---

### Task P7.1: Schema and the data split

**Files:**
- Create: `database/migrations/2026_11_06_100001_split_categories_by_branch.php`
- Create: `tests/Feature/Registrations/SplitCategoriesMigrationTest.php`
- Modify: `app/Models/Category.php`
- Modify: `tests/Support/RegistrationFixtures.php`

**Interfaces:**
- Produces: columns `categories.branch_id` (nullable), `categories.is_active` (default true), unique `categories_branch_name_unique` on (`branch_id`, `name`).
- Produces: `Category::branch(): BelongsTo`, `Category::registrations(): HasMany`, `Category::scopeForBranch(Builder, ?int $branchId)` (that branch's plus unowned; null means all), `Category::isInUse(): bool`.
- Produces for tests: `RegistrationFixtures::twoSportClub()` now gives `$senior` to football and `$u15` to swimming.

**Every table that holds a category, from reading the migrations:**

| Table | How the split treats it |
|---|---|
| `branch_player`, `training_sessions`, `training_schedules`, `deleted_session_slots`, `preseason_targets`, `teams`, `finance_accounts` | Each row names its branch: repointed directly |
| `training_session_category` | Through its session's branch |
| `activity_category` | Through its activity's branch. An activity with no branch stays on the main row |
| `attendances` | Through its session's branch. Not counted as "use": a mark never causes a copy |
| `session_generation_marks` | Copied when the branch has a schedule on the copy |
| `category_subscription` | Moved or copied by the branches ticked |
| `players` | The mirror, refreshed for players whose registration moved |
| `users` | Not repointed (spec section 2) |

- [ ] **Step 1: Add ownership to the `Category` model**

In `app/Models/Category.php`, add to the imports:

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
```

Add `'branch_id'` and `'is_active'` to `$fillable`, and add after `$appends`:

```php
    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Everywhere a category can be stored. A row here means deleting it would lose something. */
    private const USED_IN = [
        'branch_player', 'teams', 'training_sessions', 'training_session_category', 'training_schedules',
        'finance_accounts', 'category_subscription', 'activity_category', 'attendances', 'players', 'users',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(BranchRegistration::class);
    }

    /** That branch's categories, plus any with no owner. Null means every category. */
    public function scopeForBranch(Builder $query, ?int $branchId): void
    {
        $query->when($branchId !== null, fn (Builder $q) => $q->where(
            fn (Builder $w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId)
        ));
    }

    public function isInUse(): bool
    {
        foreach (self::USED_IN as $table) {
            if (DB::table($table)->where('category_id', $this->id)->exists()) {
                return true;
            }
        }

        return false;
    }
```

- [ ] **Step 2: Give the fixtures' categories their branches**

In `tests/Support/RegistrationFixtures.php`, replace the two category lines in `twoSportClub()`:

```php
        $this->senior = Category::create(['name' => 'Senior', 'branch_id' => $this->foot->id]);
        $this->u15 = Category::create(['name' => 'U15', 'branch_id' => $this->swim->id]);
```

- [ ] **Step 3: Write the failing test**

`tests/Feature/Registrations/SplitCategoriesMigrationTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\Subscription;
use App\Services\Player\RegistrationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class SplitCategoriesMigrationTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_06_100001_split_categories_by_branch.php');
    }

    private function football(): Branch
    {
        return Branch::create(['name' => 'Football', 'code' => 'FB']);
    }

    private function swimming(): Branch
    {
        return Branch::create(['name' => 'Swimming', 'code' => 'SW']);
    }

    /** A shared category as it stands before the split. Branches created first, so nothing adopts it. */
    private function shared(string $name = 'Senior', array $extra = []): Category
    {
        return Category::create(['name' => $name, ...$extra]);
    }

    private function member(array $cards, array $player = []): Player
    {
        $model = Player::create(['firstname' => 'A', 'lastname' => 'B', 'join_year' => 2020, ...$player]);
        foreach ($cards as $card) {
            app(RegistrationService::class)->register($model, $card);
        }

        return $model->refresh();
    }

    private function copyOf(Category $category, Branch $branch): ?object
    {
        return DB::table('categories')->where('name', $category->name)->where('branch_id', $branch->id)->first();
    }

    #[Test]
    public function the_main_branch_keeps_the_row_and_another_branch_gets_a_full_copy(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior', ['name_ar' => 'أكابر', 'name_fr' => 'Séniors', 'name_en' => 'Seniors', 'description' => 'Grown ups']);
        $player = $this->member([
            ['branch_id' => $foot->id, 'category_id' => $senior->id],
            ['branch_id' => $swim->id, 'category_id' => $senior->id],
        ]);

        $this->migration()->up();

        $this->assertSame($foot->id, (int) DB::table('categories')->where('id', $senior->id)->value('branch_id'));
        $copy = $this->copyOf($senior, $swim);
        $this->assertNotEquals($senior->id, $copy->id);
        $this->assertSame('أكابر', $copy->name_ar);
        $this->assertSame('Séniors', $copy->name_fr);
        $this->assertSame('Seniors', $copy->name_en);
        $this->assertSame('Grown ups', $copy->description);
        $this->assertEquals($senior->id, DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $foot->id)->value('category_id'));
        $this->assertEquals($copy->id, DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $swim->id)->value('category_id'));
        $this->assertEquals($senior->id, DB::table('players')->where('id', $player->id)->value('category_id'));
    }

    #[Test]
    public function another_branch_gets_only_what_it_uses(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior');
        $this->shared('Ecole');
        $this->member([['branch_id' => $swim->id, 'category_id' => $senior->id]]);

        $this->migration()->up();

        $this->assertSame(1, DB::table('categories')->where('branch_id', $swim->id)->count());
        $this->assertSame(2, DB::table('categories')->where('branch_id', $foot->id)->count());
    }

    #[Test]
    public function every_row_of_that_branch_moves_to_the_copy_and_nothing_of_the_main_branch_does(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior');
        $rows = $this->rowsOn($senior, [$foot, $swim]);

        $this->migration()->up();

        $copy = $this->copyOf($senior, $swim);
        foreach (['training_sessions', 'training_schedules', 'deleted_session_slots', 'preseason_targets', 'teams', 'finance_accounts'] as $table) {
            $this->assertEquals($senior->id, DB::table($table)->where('branch_id', $foot->id)->whereNotNull('category_id')->value('category_id'), $table.' on football');
            $this->assertEquals($copy->id, DB::table($table)->where('branch_id', $swim->id)->whereNotNull('category_id')->value('category_id'), $table.' on swimming');
        }
        $this->assertEquals([$senior->id], DB::table('training_session_category')->where('training_session_id', $rows['football_session'])->pluck('category_id')->all());
        $this->assertEquals([$copy->id], DB::table('training_session_category')->where('training_session_id', $rows['swimming_session'])->pluck('category_id')->all());
        $this->assertEquals($senior->id, DB::table('attendances')->where('training_session_id', $rows['football_session'])->value('category_id'));
        $this->assertEquals($copy->id, DB::table('attendances')->where('training_session_id', $rows['swimming_session'])->value('category_id'));
        $this->assertEquals([$copy->id], DB::table('activity_category')->where('activity_id', $rows['swimming_activity'])->pluck('category_id')->all());
        $this->assertEquals([$senior->id], DB::table('activity_category')->where('activity_id', $rows['club_activity'])->pluck('category_id')->all());
    }

    #[Test]
    public function a_mark_alone_causes_no_copy_and_stays_on_its_category(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $ecole = $this->shared('Ecole');
        $senior = $this->shared('Senior');
        $session = $this->session($swim, $senior);
        $player = $this->member([['branch_id' => $foot->id, 'category_id' => $ecole->id]]);
        DB::table('attendances')->insert(['training_session_id' => $session, 'player_id' => $player->id, 'category_id' => $ecole->id, 'status' => 'present']);

        $this->migration()->up();

        $this->assertNull($this->copyOf($ecole, $swim));
        $this->assertEquals($ecole->id, DB::table('attendances')->where('training_session_id', $session)->value('category_id'));
    }

    #[Test]
    public function a_price_on_a_subscription_ticked_only_for_that_branch_brings_its_category(): void
    {
        [, $swim] = [$this->football(), $this->swimming()];
        $cadets = $this->shared('Cadets');
        $piscine = $this->priced('Piscine 2026', $cadets, [$swim]);

        $this->migration()->up();

        $copy = $this->copyOf($cadets, $swim);
        $this->assertNotNull($copy);
        $row = DB::table('category_subscription')->where('subscription_id', $piscine->id)->sole();
        $this->assertEquals($copy->id, $row->category_id);
        $this->assertEquals(1500, $row->amount_student);
        $this->assertEquals(2500, $row->amount_worker);
    }

    #[Test]
    public function a_price_on_a_shared_or_unticked_subscription_alone_causes_no_copy(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $cadets = $this->shared('Cadets');
        $this->priced('Both', $cadets, [$foot, $swim]);
        $this->priced('Everyone', $cadets, []);

        $this->migration()->up();

        $this->assertNull($this->copyOf($cadets, $swim));
        $this->assertSame(2, DB::table('category_subscription')->where('category_id', $cadets->id)->count());
    }

    #[Test]
    public function the_branch_link_is_a_real_foreign_key_and_its_children_survive(): void
    {
        $foot = $this->football();
        $senior = $this->shared('Senior');
        $this->member([['branch_id' => $foot->id, 'category_id' => $senior->id]]);
        $registrations = DB::table('branch_player')->count();

        $this->migration()->up();

        $this->assertSame($registrations, DB::table('branch_player')->count(), 'adding the key must not cascade');
        $this->assertTrue(collect(\Illuminate\Support\Facades\Schema::getForeignKeys('categories'))->contains(fn (array $key) => $key['columns'] === ['branch_id']));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('categories')->where('id', $senior->id)->update(['branch_id' => 999999]);
    }

    #[Test]
    public function generation_marks_follow_a_repointed_schedule_so_no_month_generates_twice(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior');
        $ecole = $this->shared('Ecole');
        DB::table('training_schedules')->insert([
            'branch_id' => $swim->id, 'category_id' => $senior->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00', 'valid_from' => '2026-01-01',
        ]);
        $this->member([['branch_id' => $swim->id, 'category_id' => $ecole->id]]);
        DB::table('session_generation_marks')->insert([
            ['category_id' => $senior->id, 'month' => '2026-03', 'created_at' => now()],
            ['category_id' => $ecole->id, 'month' => '2026-03', 'created_at' => now()],
        ]);

        $this->migration()->up();

        $this->assertTrue(DB::table('session_generation_marks')->where('category_id', $this->copyOf($senior, $swim)->id)->where('month', '2026-03')->exists());
        $this->assertTrue(DB::table('session_generation_marks')->where('category_id', $senior->id)->where('month', '2026-03')->exists(), 'football keeps its mark');
        $this->assertFalse(DB::table('session_generation_marks')->where('category_id', $this->copyOf($ecole, $swim)->id)->exists(), 'no schedule, no mark');
    }

    #[Test]
    public function prices_follow_the_branches_a_subscription_is_ticked_for(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior');
        $this->member([['branch_id' => $swim->id, 'category_id' => $senior->id]]);

        $only = $this->priced('Only swimming', $senior, [$swim]);
        $both = $this->priced('Both', $senior, [$foot, $swim]);
        $none = $this->priced('None', $senior, []);
        $other = $this->priced('Only football', $senior, [$foot]);

        $this->migration()->up();

        $copy = $this->copyOf($senior, $swim);
        $price = fn (Subscription $s, int $category) => DB::table('category_subscription')->where('subscription_id', $s->id)->where('category_id', $category)->first();

        $this->assertNull($price($only, $senior->id), 'moved');
        $this->assertEquals(1500, $price($only, $copy->id)->amount_student);
        $this->assertEquals(2500, $price($only, $copy->id)->amount_worker);
        foreach ([$both, $none] as $subscription) {
            $this->assertEquals(1500, $price($subscription, $senior->id)->amount_student);
            $this->assertEquals(1500, $price($subscription, $copy->id)->amount_student);
            $this->assertEquals(2500, $price($subscription, $copy->id)->amount_worker);
        }
        $this->assertNotNull($price($other, $senior->id));
        $this->assertNull($price($other, $copy->id), 'swimming is never charged this one');
    }

    #[Test]
    public function the_mirror_follows_a_player_whose_main_registration_moved(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior');
        $swimmer = $this->member([['branch_id' => $swim->id, 'category_id' => $senior->id]]);
        $leftFootball = $this->member([
            ['branch_id' => $foot->id, 'category_id' => $senior->id],
            ['branch_id' => $swim->id, 'category_id' => $senior->id],
        ]);
        app(RegistrationService::class)->leave($leftFootball, [$foot->id], '2026-03-15');

        $this->migration()->up();

        $copy = $this->copyOf($senior, $swim);
        $this->assertEquals($copy->id, DB::table('players')->where('id', $swimmer->id)->value('category_id'));
        $this->assertEquals($copy->id, DB::table('players')->where('id', $leftFootball->id)->value('category_id'));
        $this->artisan('registrations:verify')->assertExitCode(0);
    }

    #[Test]
    public function a_player_with_no_registration_keeps_their_category(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior');
        $this->member([['branch_id' => $swim->id, 'category_id' => $senior->id]]);
        $flat = Player::create(['firstname' => 'Flat', 'category_id' => $senior->id]);

        $this->migration()->up();

        $this->assertEquals($senior->id, DB::table('players')->where('id', $flat->id)->value('category_id'));
        $this->assertSame($foot->id, (int) DB::table('categories')->where('id', $senior->id)->value('branch_id'));
    }

    #[Test]
    public function one_branch_owns_everything_and_copies_nothing(): void
    {
        $foot = $this->football();
        $senior = $this->shared('Senior');
        $this->shared('Ecole');
        $player = $this->member([['branch_id' => $foot->id, 'category_id' => $senior->id]]);

        $this->migration()->up();

        $this->assertSame(2, DB::table('categories')->where('branch_id', $foot->id)->count());
        $this->assertSame(2, DB::table('categories')->count());
        $this->assertEquals($senior->id, DB::table('players')->where('id', $player->id)->value('category_id'));
    }

    #[Test]
    public function no_branches_leaves_every_category_with_no_owner(): void
    {
        $this->shared('Senior');

        $this->migration()->up();

        $this->assertSame(1, DB::table('categories')->whereNull('branch_id')->count());
    }

    #[Test]
    public function running_it_twice_changes_nothing(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior');
        $this->rowsOn($senior, [$foot, $swim]);
        $this->priced('Both', $senior, [$foot, $swim]);
        DB::table('session_generation_marks')->insert(['category_id' => $senior->id, 'month' => '2026-03', 'created_at' => '2026-03-01 00:00:00']);

        $this->migration()->up();
        $first = $this->snapshot();
        $this->migration()->up();

        $this->assertEquals($first, $this->snapshot());
    }

    #[Test]
    public function two_branches_may_share_a_name_and_one_branch_may_not(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        Category::create(['name' => 'Senior', 'branch_id' => $foot->id]);
        Category::create(['name' => 'Senior', 'branch_id' => $swim->id]);

        $this->expectException(QueryException::class);
        Category::create(['name' => 'Senior', 'branch_id' => $foot->id]);
    }

    #[Test]
    public function down_merges_copies_back_by_name_onto_the_main_row(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior');
        $player = $this->member([
            ['branch_id' => $foot->id, 'category_id' => $senior->id],
            ['branch_id' => $swim->id, 'category_id' => $senior->id],
        ]);
        $rows = $this->rowsOn($senior, [$foot, $swim]);
        $both = $this->priced('Both', $senior, [$foot, $swim]);
        $only = $this->priced('Only swimming', $senior, [$swim]);
        DB::table('session_generation_marks')->insert(['category_id' => $senior->id, 'month' => '2026-03', 'created_at' => now()]);
        $this->migration()->up();
        // Added after the split, so no main-branch row holds its name.
        $new = Category::create(['name' => 'Poussin', 'branch_id' => $swim->id]);

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('categories', 'branch_id'));
        $this->assertFalse(Schema::hasColumn('categories', 'is_active'));
        $this->assertEqualsCanonicalizing(['Senior', 'Poussin'], DB::table('categories')->pluck('name')->all());
        $this->assertTrue(DB::table('categories')->where('id', $new->id)->exists(), 'a name nobody else holds survives');
        $this->assertEquals([$senior->id], DB::table('branch_player')->where('player_id', $player->id)->distinct()->pluck('category_id')->all());
        foreach (['training_sessions', 'training_schedules', 'deleted_session_slots', 'preseason_targets', 'teams', 'finance_accounts'] as $table) {
            $this->assertEquals($senior->id, DB::table($table)->where('branch_id', $swim->id)->whereNotNull('category_id')->value('category_id'), $table);
        }
        $this->assertEquals([$senior->id], DB::table('training_session_category')->where('training_session_id', $rows['swimming_session'])->pluck('category_id')->all());
        $this->assertSame(1, DB::table('category_subscription')->where('subscription_id', $both->id)->count());
        $this->assertEquals($senior->id, DB::table('category_subscription')->where('subscription_id', $only->id)->value('category_id'));
        $this->assertSame(1, DB::table('session_generation_marks')->count());

        $this->expectException(QueryException::class);
        DB::table('categories')->insert(['name' => 'Senior']);
    }

    /** Sessions, schedules, slots, targets, teams, caisses and activities on the category, one set per branch. */
    private function rowsOn(Category $category, array $branches): array
    {
        $ids = [];
        $type = DB::table('activity_types')->value('id');
        foreach ($branches as $branch) {
            $key = strtolower($branch->name);
            $session = $this->session($branch, $category);
            $ids[$key.'_session'] = $session;
            $player = $this->member([['branch_id' => $branch->id, 'category_id' => $category->id]]);
            DB::table('attendances')->insert(['training_session_id' => $session, 'player_id' => $player->id, 'category_id' => $category->id, 'status' => 'present']);
            DB::table('training_schedules')->insert([
                'branch_id' => $branch->id, 'category_id' => $category->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00', 'valid_from' => '2026-01-01',
            ]);
            DB::table('deleted_session_slots')->insert(['branch_id' => $branch->id, 'category_id' => $category->id, 'date' => '2026-03-02', 'start_time' => '18:00']);
            DB::table('preseason_targets')->insert(['branch_id' => $branch->id, 'category_id' => $category->id, 'season_start_year' => 2026, 'target_count' => 10]);
            $this->team($category, ['branch_id' => $branch->id]);
            DB::table('finance_accounts')->insert(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'Caisse '.$key, 'type' => 'cash', 'currency' => 'DZD', 'is_active' => true]);
            $activity = DB::table('activities')->insertGetId(['title' => $key, 'activity_type_id' => $type, 'starts_on' => '2026-03-01', 'branch_id' => $branch->id]);
            DB::table('activity_category')->insert(['activity_id' => $activity, 'category_id' => $category->id]);
            $ids[$key.'_activity'] = $activity;
        }
        $club = DB::table('activities')->insertGetId(['title' => 'club', 'activity_type_id' => $type, 'starts_on' => '2026-03-01']);
        DB::table('activity_category')->insert(['activity_id' => $club, 'category_id' => $category->id]);
        $ids['club_activity'] = $club;

        return $ids;
    }

    private function session(Branch $branch, Category $category): int
    {
        $id = DB::table('training_sessions')->insertGetId([
            'branch_id' => $branch->id, 'category_id' => $category->id, 'date' => '2026-03-02', 'start_time' => '18:00', 'end_time' => '19:00',
            'kind' => SessionKind::Regular->value, 'state' => SessionState::Held->value,
        ]);
        DB::table('training_session_category')->insert(['training_session_id' => $id, 'category_id' => $category->id]);

        return $id;
    }

    private function priced(string $name, Category $category, array $branches): Subscription
    {
        $subscription = Subscription::create(['name' => $name, 'year' => 2026, 'amount_student' => 1000, 'amount_worker' => 2000]);
        $subscription->categories()->attach($category->id, ['amount_student' => 1500, 'amount_worker' => 2500]);
        $subscription->branches()->attach(array_map(fn (Branch $b) => $b->id, $branches));

        return $subscription;
    }

    private function snapshot(): array
    {
        $tables = ['categories', 'branch_player', 'training_sessions', 'training_session_category', 'training_schedules', 'deleted_session_slots', 'preseason_targets', 'teams', 'finance_accounts', 'activity_category', 'attendances', 'session_generation_marks', 'category_subscription'];

        return collect($tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->get()->map(fn ($row) => (array) $row)->all()])->all();
    }
}
```

- [ ] **Step 4: Run it to verify it fails**

Run: `php artisan config:clear && php artisan test tests/Feature/Registrations/SplitCategoriesMigrationTest.php`
Expected: FAIL. The test database has no `categories.branch_id` until the migration exists, so `require` fails with `Failed opening required`.

- [ ] **Step 5: Write the migration**

`database/migrations/2026_11_06_100001_split_categories_by_branch.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each branch gets its own categories. The main branch keeps every existing
 * row and its ID. Another branch gets a copy of only what it uses, and that
 * branch's rows move to the copy. No amount changes.
 *
 * Every schema step checks its own work and the data runs in one transaction,
 * so a failed run finishes on the next one and a second run changes nothing.
 *
 * On SQLite the foreign key is declared with the column: foreign() would rebuild
 * the table, and categories has cascading children. BranchController::destroy()
 * deletes unused categories first and refuses a category still used.
 */
return new class extends Migration
{
    /** Tables whose rows name both their branch and their category. */
    private const BY_BRANCH = [
        'branch_player', 'training_sessions', 'training_schedules', 'deleted_session_slots',
        'preseason_targets', 'teams', 'finance_accounts',
    ];

    private const UNIQUE = 'categories_branch_name_unique';

    private const FOREIGN = 'categories_branch_id_foreign';

    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'branch_id')) {
            if ($this->isSqlite()) {
                DB::statement('ALTER TABLE categories ADD COLUMN branch_id INTEGER REFERENCES branches(id) ON DELETE RESTRICT');
            } else {
                Schema::table('categories', fn (Blueprint $t) => $t->unsignedBigInteger('branch_id')->nullable());
            }
        }
        if (! Schema::hasColumn('categories', 'is_active')) {
            Schema::table('categories', fn (Blueprint $t) => $t->boolean('is_active')->default(true));
        }
        if (! Schema::hasIndex('categories', self::UNIQUE)) {
            Schema::table('categories', fn (Blueprint $t) => $t->unique(['branch_id', 'name'], self::UNIQUE));
        }
        if (Schema::hasIndex('categories', 'categories_name_unique')) {
            Schema::table('categories', fn (Blueprint $t) => $t->dropUnique('categories_name_unique'));
        }
        if (! $this->isSqlite() && ! $this->hasForeignKey()) {
            Schema::table('categories', fn (Blueprint $t) => $t->foreign('branch_id', self::FOREIGN)->references('id')->on('branches')->restrictOnDelete());
        }

        DB::transaction(fn () => $this->split());
    }

    private function split(): void
    {
        $branchIds = DB::table('branches')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id);
        if ($branchIds->isEmpty()) {
            return;
        }
        $mainId = $this->mainId($branchIds);

        DB::table('categories')->whereNull('branch_id')->update(['branch_id' => $mainId]);

        $moved = collect();
        foreach ($branchIds->reject(fn (int $id) => $id === $mainId) as $branchId) {
            foreach ($this->usedFromElsewhere($branchId) as $categoryId) {
                $copyId = $this->copyFor((int) $categoryId, $branchId);
                $moved = $moved->merge(DB::table('branch_player')->where('branch_id', $branchId)->where('category_id', $categoryId)->pluck('player_id'));
                $this->repoint((int) $categoryId, $copyId, $branchId);
                $this->copyMarks((int) $categoryId, $copyId, $branchId);
                $this->movePrices((int) $categoryId, $copyId, $branchId);
            }
        }

        $this->refreshMirror($moved->unique()->values(), $mainId);
    }

    private function mainId(Collection $branchIds): int
    {
        return (int) (DB::table('website_configs')->value('main_branch_id') ?? $branchIds->first());
    }

    /** @return Collection<int, int> the categories this branch uses, or prices on a subscription ticked only for it, but does not own */
    private function usedFromElsewhere(int $branchId): Collection
    {
        $used = collect(self::BY_BRANCH)
            ->flatMap(fn (string $table) => DB::table($table)->where('branch_id', $branchId)->whereNotNull('category_id')->distinct()->pluck('category_id'))
            ->merge(DB::table('training_session_category as link')
                ->join('training_sessions as session', 'session.id', '=', 'link.training_session_id')
                ->where('session.branch_id', $branchId)->distinct()->pluck('link.category_id'))
            ->merge(DB::table('activity_category as link')
                ->join('activities as activity', 'activity.id', '=', 'link.activity_id')
                ->where('activity.branch_id', $branchId)->distinct()->pluck('link.category_id'))
            ->merge(DB::table('category_subscription as price')
                ->join('branch_subscription as ticked', 'ticked.subscription_id', '=', 'price.subscription_id')
                ->where('ticked.branch_id', $branchId)
                ->whereNotExists(fn ($other) => $other->from('branch_subscription as other')
                    ->whereColumn('other.subscription_id', 'price.subscription_id')
                    ->where('other.branch_id', '!=', $branchId))
                ->distinct()->pluck('price.category_id'))
            ->unique()->values();

        return DB::table('categories')->whereIn('id', $used)->where('branch_id', '!=', $branchId)->orderBy('id')->pluck('id');
    }

    private function copyFor(int $categoryId, int $branchId): int
    {
        $original = (array) DB::table('categories')->where('id', $categoryId)->first();
        $existing = DB::table('categories')->where('branch_id', $branchId)->where('name', $original['name'])->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        unset($original['id']);

        return (int) DB::table('categories')->insertGetId(['branch_id' => $branchId] + $original);
    }

    private function repoint(int $from, int $to, int $branchId): void
    {
        foreach (self::BY_BRANCH as $table) {
            DB::table($table)->where('branch_id', $branchId)->where('category_id', $from)->update(['category_id' => $to]);
        }

        $sessions = DB::table('training_sessions')->where('branch_id', $branchId)->select('id');
        DB::table('attendances')->where('category_id', $from)->whereIn('training_session_id', $sessions)->update(['category_id' => $to]);
        $this->movePivot('training_session_category', 'training_session_id', $sessions, $from, $to);
        $this->movePivot('activity_category', 'activity_id', DB::table('activities')->where('branch_id', $branchId)->select('id'), $from, $to);
    }

    private function movePivot(string $table, string $key, mixed $owners, int $from, int $to): void
    {
        $ids = DB::table($table)->where('category_id', $from)->whereIn($key, $owners)->pluck($key);
        foreach ($ids->chunk(200) as $chunk) {
            DB::table($table)->insertOrIgnore($chunk->map(fn ($id) => [$key => $id, 'category_id' => $to])->values()->all());
            DB::table($table)->where('category_id', $from)->whereIn($key, $chunk->values()->all())->delete();
        }
    }

    /** A month already generated from a schedule that moved stays generated for the copy. */
    private function copyMarks(int $from, int $to, int $branchId): void
    {
        if (! DB::table('training_schedules')->where('branch_id', $branchId)->where('category_id', $to)->exists()) {
            return;
        }
        foreach (DB::table('session_generation_marks')->where('category_id', $from)->get() as $mark) {
            DB::table('session_generation_marks')->insertOrIgnore(['category_id' => $to, 'month' => $mark->month, 'created_at' => $mark->created_at]);
        }
    }

    /**
     * Ticked only for this branch: the row moves. Ticked for several or none: the
     * copy gets the same amounts. Ticked only for others: never charged here.
     */
    private function movePrices(int $from, int $to, int $branchId): void
    {
        foreach (DB::table('category_subscription')->where('category_id', $from)->get() as $row) {
            $ticked = DB::table('branch_subscription')->where('subscription_id', $row->subscription_id)->pluck('branch_id')->map(fn ($id) => (int) $id);

            if ($ticked->count() === 1 && $ticked->first() === $branchId) {
                DB::table('category_subscription')->where('category_id', $from)->where('subscription_id', $row->subscription_id)->update(['category_id' => $to]);
            } elseif ($ticked->isEmpty() || $ticked->contains($branchId)) {
                DB::table('category_subscription')->insertOrIgnore(['category_id' => $to] + (array) $row);
            }
        }
    }

    /**
     * The player copy holds the main registration's category. This repeats
     * RegistrationService::pickPrimary's rule, so the migration keeps its meaning
     * if that code changes later.
     *
     * @param  Collection<int, int>  $playerIds
     */
    private function refreshMirror(Collection $playerIds, int $mainId): void
    {
        $left = DB::table('player_statuses')->where('code', 'left')->value('id');

        foreach ($playerIds->chunk(200) as $chunk) {
            $rows = DB::table('branch_player')->whereIn('player_id', $chunk->values()->all())->orderBy('id')->get()->groupBy('player_id');
            foreach ($rows as $playerId => $registrations) {
                $active = $registrations->reject(fn (object $r) => $left !== null && (int) $r->status_id === (int) $left);
                $primary = $active->first(fn (object $r) => (int) $r->branch_id === $mainId)
                    ?? $active->sortBy(fn (object $r) => [$this->joinSortKey($r), $r->id])->first()
                    ?? $registrations->first();
                DB::table('players')->where('id', $playerId)->update(['category_id' => $primary->category_id]);
            }
        }
    }

    private function joinSortKey(object $registration): string
    {
        if ($registration->join_date !== null) {
            return substr((string) $registration->join_date, 0, 10);
        }

        return $registration->legacy_join_year !== null ? $registration->legacy_join_year.'-12-31' : '9999-12-31';
    }

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }

    private function hasForeignKey(): bool
    {
        return collect(Schema::getForeignKeys('categories'))->contains(fn (array $key) => $key['columns'] === ['branch_id']);
    }

    /**
     * Keeps one row per name: the main branch's, else the lowest ID, so the old
     * unique name fits again. A stale row on the same slot as its copy fails loudly
     * here, inside the transaction, and nothing changes.
     */
    public function down(): void
    {
        DB::transaction(function () {
            $mainId = DB::table('website_configs')->value('main_branch_id');
            foreach (DB::table('categories')->orderBy('id')->get()->groupBy('name') as $group) {
                $keeper = $group->first(fn (object $c) => $mainId !== null && (int) $c->branch_id === (int) $mainId) ?? $group->first();
                foreach ($group->reject(fn (object $c) => $c->id === $keeper->id) as $copy) {
                    $this->mergeInto((int) $copy->id, (int) $keeper->id);
                }
            }
        });

        if (! $this->isSqlite() && $this->hasForeignKey()) {
            Schema::table('categories', fn (Blueprint $t) => $t->dropForeign(self::FOREIGN));
        }
        if (! Schema::hasIndex('categories', 'categories_name_unique')) {
            Schema::table('categories', fn (Blueprint $t) => $t->unique('name', 'categories_name_unique'));
        }
        if (Schema::hasIndex('categories', self::UNIQUE)) {
            Schema::table('categories', fn (Blueprint $t) => $t->dropUnique(self::UNIQUE));
        }
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn(['branch_id', 'is_active']));
    }

    private function mergeInto(int $from, int $to): void
    {
        foreach ([...self::BY_BRANCH, 'attendances', 'players', 'users'] as $table) {
            DB::table($table)->where('category_id', $from)->update(['category_id' => $to]);
        }
        foreach (['training_session_category', 'activity_category', 'category_subscription', 'session_generation_marks'] as $table) {
            foreach (DB::table($table)->where('category_id', $from)->get() as $row) {
                DB::table($table)->insertOrIgnore(['category_id' => $to] + (array) $row);
            }
            DB::table($table)->where('category_id', $from)->delete();
        }
        DB::table('categories')->where('id', $from)->delete();
    }
};
```

- [ ] **Step 6: Run the test**

Run: `php artisan test tests/Feature/Registrations/SplitCategoriesMigrationTest.php`
Expected: all PASS. If `training_schedules` inserts fail on a NOT NULL column you can't see here, read `2026_09_29_100001_create_attendance_tables.php` and add it to the insert. Don't loosen the migration to suit a fixture.

- [ ] **Step 7: Run the whole suite**

Run: `php artisan config:clear && php artisan test 2>&1 | tail -15`
Expected: as at the end of Part 6. Nothing enforces ownership yet. A test asserting a duplicate category name fails on the database is the one thing that may change here, because the unique is now per branch: move it to assert through `categories.store` in Task P7.5.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_11_06_100001_split_categories_by_branch.php app/Models/Category.php tests/Support/RegistrationFixtures.php tests/Feature/Registrations/SplitCategoriesMigrationTest.php
git commit -m "feat(categories): give each branch its own categories

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P7.2: New clubs: adoption and the seeder

**Files:**
- Modify: `app/Observers/BranchObserver.php`
- Modify: `database/seeders/CategorySeeder.php`
- Create: `tests/Feature/Registrations/CategoryAdoptionTest.php`

**Interfaces:**
- Consumes: `categories.branch_id`.
- Produces: the first branch created owns every unowned category. `CategorySeeder` gives new rows to the main branch when one exists.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/CategoryAdoptionTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Category;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CategoryAdoptionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_first_branch_takes_every_unowned_category_and_the_second_takes_none(): void
    {
        $senior = Category::create(['name' => 'Senior']);

        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);

        $this->assertSame($foot->id, $senior->refresh()->branch_id);
        $this->assertSame(0, Category::where('branch_id', $swim->id)->count());
    }

    #[Test]
    public function with_no_branches_categories_have_no_owner(): void
    {
        (new CategorySeeder)->run();

        $this->assertSame(5, Category::whereNull('branch_id')->count());
    }

    #[Test]
    public function seeding_after_a_branch_exists_gives_new_rows_to_the_main_branch(): void
    {
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);

        (new CategorySeeder)->run();

        $this->assertSame(5, Category::where('branch_id', $foot->id)->count());
    }

    #[Test]
    public function seeding_twice_adds_nothing(): void
    {
        Branch::create(['name' => 'Football', 'code' => 'FB']);

        (new CategorySeeder)->run();
        (new CategorySeeder)->run();

        $this->assertSame(5, Category::count());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/CategoryAdoptionTest.php`
Expected: FAIL on the first and third tests, where `branch_id` stays null.

- [ ] **Step 3: Make the first branch adopt them**

In `app/Observers/BranchObserver.php`, change the comment and table list in `created()`:

```php
        // The club's first branch adopts every club-wide row, as the migrations'
        // backfills do (2026_10_23_100001_add_branch_to_attendance_tables,
        // 2026_10_23_300001_add_branch_to_injury_notes), and every unowned category
        // (2026_11_06_100001_split_categories_by_branch).
        if (Branch::count() === 1) {
            WebsiteConfig::singleton()->update(['main_branch_id' => $branch->id]);
            foreach (['training_sessions', 'training_schedules', 'deleted_session_slots', 'preseason_targets', 'injury_notes', 'categories'] as $table) {
                DB::table($table)->whereNull('branch_id')->update(['branch_id' => $branch->id]);
            }
        }
```

- [ ] **Step 4: Update the seeder**

In `database/seeders/CategorySeeder.php`, add `use App\Models\Branch;` and replace the loop:

```php
        // With no branch yet they wait, and the first branch adopts them.
        $mainId = Branch::mainId();

        foreach ($categories as $category) {
            Category::firstOrCreate(['name' => $category['name']], $category + ['branch_id' => $mainId]);
        }
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/Registrations/CategoryAdoptionTest.php`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Observers/BranchObserver.php database/seeders/CategorySeeder.php tests/Feature/Registrations/CategoryAdoptionTest.php
git commit -m "feat(categories): the first branch adopts categories with no owner

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P7.3: A category must belong to its branch

**Files:**
- Create: `app/Support/CategoryOwnership.php`
- Create: `tests/Feature/Registrations/CategoryOwnershipTest.php`
- Modify: `lang/ar/validation.php`, `lang/en/validation.php`, `lang/fr/validation.php`
- Modify: `app/Http/Requests/Player/ValidatesRegistrations.php`
- Modify: `app/Services/Player/RegistrationService.php`
- Modify: `app/Http/Controllers/TrainingSessionController.php`
- Modify: `app/Http/Controllers/AttendanceSettingsController.php`
- Modify: `app/Http/Controllers/TeamController.php`
- Modify: `app/Http/Controllers/FinanceAccountController.php`
- Modify: `app/Http/Requests/Subscription/StoreSubscriptionRequest.php`
- Modify: `app/Http/Requests/ActivityRequest.php`
- Modify: `tests/Feature/Registrations/RegistrationServiceTest.php` only if its `setField` test needs a branch, and the test files in the repair table above

**Interfaces:**
- Produces: `CategoryOwnership::outside(iterable|int|null $ids, iterable $branchIds, iterable|int|null $stored = null): bool` (no branches given means no check); `CategoryOwnership::assertIn(string $field, iterable|int|null $ids, ?int $branchId, iterable|int|null $stored = null): void` (throws `ValidationException`).
- Produces: `RegistrationService::setField` sends `category_id` to the registration in the category's branch. A player with no registration there is left alone. An unowned category or an empty value goes to the main registration.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/CategoryOwnershipTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Category;
use App\Models\FinanceAccount;
use App\Models\Subscription;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Player\RegistrationService;
use App\Support\CategoryOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class CategoryOwnershipTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
    }

    private function slot(array $extra = []): array
    {
        return ['kind' => SessionKind::Extra->value, 'date' => '2026-03-02', 'start_time' => '18:00', 'end_time' => '19:00'] + $extra;
    }

    #[Test]
    public function the_helper_checks_owner_stored_value_and_unowned(): void
    {
        $unowned = Category::create(['name' => 'Open']);

        $this->assertTrue(CategoryOwnership::outside($this->senior->id, [$this->swim->id]));
        $this->assertFalse(CategoryOwnership::outside($this->u15->id, [$this->swim->id]));
        $this->assertFalse(CategoryOwnership::outside($unowned->id, [$this->swim->id]));
        $this->assertFalse(CategoryOwnership::outside($this->senior->id, [$this->swim->id], $this->senior->id));
        $this->assertFalse(CategoryOwnership::outside($this->senior->id, []));
        $this->assertFalse(CategoryOwnership::outside(null, [$this->swim->id]));
        $this->assertTrue(CategoryOwnership::outside([$this->u15->id, $this->senior->id], [$this->swim->id]));
    }

    #[Test]
    public function a_session_refuses_a_category_of_another_branch(): void
    {
        $this->post(route('attendance.sessions.store'), $this->slot(['branch_id' => $this->swim->id, 'category_id' => $this->senior->id]))
            ->assertSessionHasErrors('category_id');
        $this->assertSame(0, TrainingSession::count());

        $this->post(route('attendance.sessions.store'), $this->slot(['branch_id' => $this->swim->id, 'category_id' => $this->u15->id]))
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function every_category_of_a_joint_session_must_belong_to_its_branch(): void
    {
        $this->post(route('attendance.sessions.store'), $this->slot([
            'kind' => SessionKind::Preseason->value, 'branch_id' => $this->swim->id, 'category_id' => $this->u15->id, 'category_ids' => [$this->u15->id, $this->senior->id],
        ]))->assertSessionHasErrors('category_id');
    }

    #[Test]
    public function a_session_with_no_branch_takes_any_category(): void
    {
        $session = TrainingSession::create(['category_id' => $this->senior->id, 'date' => '2026-03-03', 'start_time' => '18:00', 'end_time' => '19:00', 'kind' => SessionKind::Extra, 'state' => SessionState::Planned]);

        $this->put(route('attendance.sessions.update', $session), ['title' => 'x'])->assertSessionHasNoErrors();
    }

    #[Test]
    public function moving_a_session_to_another_branch_checks_its_categories(): void
    {
        $session = TrainingSession::create(['branch_id' => $this->foot->id, 'category_id' => $this->senior->id, 'date' => '2026-03-03', 'start_time' => '18:00', 'end_time' => '19:00', 'kind' => SessionKind::Extra, 'state' => SessionState::Planned]);
        $session->categories()->syncWithoutDetaching([$this->senior->id]);

        $this->put(route('attendance.sessions.update', $session), ['branch_id' => $this->swim->id])->assertSessionHasErrors('category_ids');
    }

    #[Test]
    public function a_session_that_no_longer_fits_can_still_be_edited(): void
    {
        $session = TrainingSession::create(['branch_id' => $this->swim->id, 'category_id' => $this->senior->id, 'date' => '2026-03-03', 'start_time' => '18:00', 'end_time' => '19:00', 'kind' => SessionKind::Extra, 'state' => SessionState::Planned]);
        $session->categories()->syncWithoutDetaching([$this->senior->id]);

        $this->put(route('attendance.sessions.update', $session), ['title' => 'x'])->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_schedule_refuses_another_branchs_category_and_keeps_a_stored_one(): void
    {
        $body = ['branch_id' => $this->swim->id, 'category_id' => $this->senior->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00', 'valid_from' => '2026-01-01'];

        $this->post(route('attendance.settings.schedules.store'), $body)->assertSessionHasErrors('category_id');

        $stale = TrainingSchedule::create($body);
        $this->put(route('attendance.settings.schedules.update', $stale), $body)->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_target_refuses_another_branchs_category(): void
    {
        $this->post(route('attendance.settings.targets.store'), [
            'branch_id' => $this->swim->id, 'category_id' => $this->senior->id, 'season_start_year' => 2026, 'target_count' => 5,
        ])->assertSessionHasErrors('category_id');
    }

    #[Test]
    public function a_team_refuses_another_branchs_category(): void
    {
        $format = $this->format($this->swim);

        $this->post(route('teams.store'), ['branch_id' => $this->swim->id, 'category_id' => $this->senior->id, 'name' => 'A', 'default_game_format_id' => $format->id])
            ->assertSessionHasErrors('category_id');
        $this->post(route('teams.store'), ['branch_id' => $this->swim->id, 'category_id' => $this->u15->id, 'name' => 'A', 'default_game_format_id' => $format->id])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_caisse_refuses_another_branchs_category(): void
    {
        $this->post(route('finance.accounts.store'), ['name' => 'C', 'type' => 'cash', 'branch_id' => $this->swim->id, 'category_id' => $this->senior->id])
            ->assertSessionHasErrors('category_id');
        $this->assertSame(0, FinanceAccount::where('name', 'C')->count());
    }

    #[Test]
    public function a_subscription_category_must_belong_to_a_ticked_branch_if_any(): void
    {
        $body = ['kind' => 'annual', 'year' => 2026, 'amount_student' => 100, 'amount_worker' => 100];

        $this->post(route('subscriptions.store'), $body + ['name' => 'A', 'branch_ids' => [$this->swim->id], 'category_ids' => [$this->senior->id]])
            ->assertSessionHasErrors('category_ids');
        $this->post(route('subscriptions.store'), $body + ['name' => 'B', 'branch_ids' => [$this->swim->id, $this->foot->id], 'category_ids' => [$this->senior->id, $this->u15->id]])
            ->assertSessionHasNoErrors();
        $this->post(route('subscriptions.store'), $body + ['name' => 'C', 'category_ids' => [$this->senior->id, $this->u15->id]])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_subscription_keeps_a_stored_category_that_no_longer_fits(): void
    {
        $subscription = Subscription::create(['name' => 'S', 'year' => 2026, 'amount_student' => 100, 'amount_worker' => 100]);
        $subscription->categories()->attach($this->senior->id);
        $subscription->branches()->attach($this->swim->id);

        $this->put(route('subscriptions.update', $subscription), [
            'kind' => 'annual', 'name' => 'S', 'year' => 2026, 'amount_student' => 100, 'amount_worker' => 100,
            'branch_ids' => [$this->swim->id], 'category_ids' => [$this->senior->id],
        ])->assertSessionHasNoErrors();
    }

    #[Test]
    public function an_activity_category_must_belong_to_its_chosen_branch_if_any(): void
    {
        $body = ['title' => 'A', 'activity_type_id' => DB::table('activity_types')->value('id'), 'starts_on' => '2026-03-01'];

        $this->post(route('activities.store'), $body + ['branch_id' => $this->swim->id, 'category_ids' => [$this->senior->id]])
            ->assertSessionHasErrors('category_ids');
        $this->post(route('activities.store'), $body + ['category_ids' => [$this->senior->id, $this->u15->id]])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_registration_card_refuses_another_branchs_category_and_keeps_a_stored_one(): void
    {
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['category_id' => $this->senior->id]);
        $card = fn (int $category) => ['branch_id' => $this->swim->id, 'category_id' => $category];
        $base = ['firstname' => 'Both', 'lastname' => 'Test', 'gender' => 'Male', 'registrations' => [
            ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id],
        ]];

        $this->put(route('players.update', $player), ['registrations' => [...$base['registrations'], $card($this->senior->id)]] + $base)
            ->assertSessionDoesntHaveErrors('registrations.1.category_id');

        $other = Category::create(['name' => 'Cadet', 'branch_id' => $this->foot->id]);
        $this->put(route('players.update', $player), ['registrations' => [...$base['registrations'], $card($other->id)]] + $base)
            ->assertSessionHasErrors('registrations.1.category_id');
    }

    #[Test]
    public function bulk_category_goes_to_the_registration_in_its_branch(): void
    {
        $both = $this->bothActive();
        $service = app(RegistrationService::class);
        $swimmer = \App\Models\Player::create(['firstname' => 'Swimmer', 'join_year' => 2020]);
        $service->register($swimmer, ['branch_id' => $this->swim->id, 'category_id' => $this->u15->id]);
        $cadet = Category::create(['name' => 'Cadet', 'branch_id' => $this->foot->id]);

        $service->setField($both, 'category_id', $cadet->id);
        $service->setField($swimmer, 'category_id', $cadet->id);

        $this->assertSame($cadet->id, $both->registrationIn($this->foot->id)->category_id);
        $this->assertSame($this->u15->id, $both->refresh()->registrationIn($this->swim->id)->category_id);
        $this->assertSame($this->u15->id, $swimmer->refresh()->registrationIn($this->swim->id)->category_id, 'left alone');
    }
}
```

Before running, read `routes/web.php` for the exact names of the schedule, target and caisse routes used above (`grep -n "storeSchedule\|updateSchedule\|storeTarget\|FinanceAccountController" routes/web.php`) and use those names. The test's assertions don't change.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/CategoryOwnershipTest.php`
Expected: FAIL, `Class "App\Support\CategoryOwnership" not found`.

- [ ] **Step 3: Write the helper and the message**

`app/Support/CategoryOwnership.php`:

```php
<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Validation\ValidationException;

/**
 * The one rule: a category must belong to its branch. A value already saved stays
 * until edited, as positions do, and a category with no owner fits anywhere.
 */
final class CategoryOwnership
{
    /** Whether any new value belongs to a branch not listed. No branches listed means no check. */
    public static function outside(iterable|int|null $ids, iterable $branchIds, iterable|int|null $stored = null): bool
    {
        $branches = collect($branchIds)->map(fn ($id) => (int) $id)->all();
        $new = collect($ids ?? [])->filter()->map(fn ($id) => (int) $id)
            ->diff(collect($stored ?? [])->map(fn ($id) => (int) $id))->values()->all();

        if ($branches === [] || $new === []) {
            return false;
        }

        return Category::whereIn('id', $new)->whereNotNull('branch_id')->whereNotIn('branch_id', $branches)->exists();
    }

    /**
     * A null branch takes any category.
     *
     * @throws ValidationException
     */
    public static function assertIn(string $field, iterable|int|null $ids, ?int $branchId, iterable|int|null $stored = null): void
    {
        if ($branchId !== null && self::outside($ids, [$branchId], $stored)) {
            throw ValidationException::withMessages([$field => __('validation.category_other_branch')]);
        }
    }
}
```

Add after `position_other_branch` in each language file:

```php
// lang/en/validation.php
    'category_other_branch' => 'This category belongs to another branch.',
// lang/fr/validation.php
    'category_other_branch' => 'Cette catégorie appartient à une autre section.',
// lang/ar/validation.php
    'category_other_branch' => 'هذه الفئة تابعة لفرع آخر.',
```

- [ ] **Step 4: Check registration cards**

In `app/Http/Requests/Player/ValidatesRegistrations.php`, add `use App\Models\Category;`. In `validateRegistrations`, add below `$branchOf`:

```php
            $categoryOwner = Category::query()->pluck('branch_id', 'id');
```

Add inside the loop, before `$main = $card['position_id'] ?? null;`:

```php
                $category = $card['category_id'] ?? null;
                $keepsCategory = $saved !== null && (int) $saved->category_id === (int) $category;
                $categoryBranch = $categoryOwner[$category] ?? null;
                if ($category && ! $keepsCategory && $categoryBranch !== null && (int) $categoryBranch !== $branchId) {
                    $validator->errors()->add("registrations.$i.category_id", __('validation.category_other_branch'));
                }
```

- [ ] **Step 5: Send bulk category to the owning registration**

In `app/Services/Player/RegistrationService.php`, add `use App\Models\Category;`, change the docblock of `setField` to:

```php
    /**
     * One field from bulk edit: status on the registration of $branchId only,
     * position and category on the registration in their branch.
     */
```

Change the `match` to:

```php
            $targets = match ($field) {
                'status_id' => $registrations->where('branch_id', $branchId),
                'position_id' => collect([$this->positionOwner($registrations, $player, $value)])->filter(),
                'category_id' => collect([$this->categoryOwner($registrations, $player, $value)])->filter(),
                default => collect([$this->primary($player)]),
            };
```

Add after `positionOwner`:

```php
    /** @param  Collection<int, BranchRegistration>  $registrations */
    private function categoryOwner(Collection $registrations, Player $player, mixed $categoryId): ?BranchRegistration
    {
        if (! $categoryId) {
            return $this->primary($player);
        }
        $branchId = Category::whereKey($categoryId)->value('branch_id');

        return $branchId === null ? $this->primary($player) : $registrations->firstWhere('branch_id', $branchId);
    }
```

Part 6 made `setField` return whether it changed anything, and `bulkUpdate` counts that. A player with no registration in the category's branch has no target, so it is counted as skipped with no change in `PlayerController`.

- [ ] **Step 6: Check sessions**

In `app/Http/Controllers/TrainingSessionController.php`, add `use App\Support\CategoryOwnership;`.

In `store`, immediately after `$branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;`:

```php
        CategoryOwnership::assertIn('category_id', $categoryIds, $branchId);
```

In `update`, immediately after the `$sortedCategoryIds` lines, and before `$errors = [];`:

```php
        // A session keeps a category that no longer fits, unless it changes branch.
        CategoryOwnership::assertIn('category_ids', $categoryIds, $branchId, $branchId === $session->branch_id ? $currentIds : null);
```

- [ ] **Step 7: Check schedules and targets**

In `app/Http/Controllers/AttendanceSettingsController.php`, add `use App\Support\CategoryOwnership;`.

Change `updateSchedule`'s call to `$this->validateSchedule($request, $schedule);`, and change the method:

```php
    private function validateSchedule(Request $request, ?TrainingSchedule $schedule = null): array
    {
        $data = $request->validate([
            'branch_id' => [Rule::requiredIf(fn () => Branch::exists()), 'nullable', 'integer', 'exists:branches,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ]);
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        CategoryOwnership::assertIn('category_id', $data['category_id'], $branchId, $schedule !== null && $schedule->branch_id === $branchId ? $schedule->category_id : null);

        return $data;
    }
```

In `storeTarget`, right after the `validate` call:

```php
        CategoryOwnership::assertIn('category_id', $data['category_id'], isset($data['branch_id']) ? (int) $data['branch_id'] : null);
```

- [ ] **Step 8: Check teams and caisses**

In `app/Http/Controllers/TeamController.php`, add `use App\Support\CategoryOwnership;`, and in `validated()`, right after `$branchId = $team?->branch_id ?? (int) $data['branch_id'];`:

```php
        CategoryOwnership::assertIn('category_id', $data['category_id'], $branchId, $team?->category_id);
```

In `app/Http/Controllers/FinanceAccountController.php`, add `use App\Support\CategoryOwnership;`, and in `validateData`, inside `if (! empty($categoryId) && ! empty($data['branch_id'])) {` before `$taken`:

```php
            CategoryOwnership::assertIn('category_id', $categoryId, (int) $data['branch_id'],
                $account !== null && (int) $account->branch_id === (int) $data['branch_id'] ? $account->category_id : null);
```

- [ ] **Step 9: Check subscriptions and activities**

In `app/Http/Requests/Subscription/StoreSubscriptionRequest.php`, add `use App\Support\CategoryOwnership;` and add after `rules()`:

```php
    /** A category must belong to a ticked branch, if any. One already saved stays until edited. */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $stored = $this->route('subscription')?->categories()->pluck('categories.id');
            if (CategoryOwnership::outside($this->input('category_ids'), (array) $this->input('branch_ids', []), $stored)) {
                $validator->errors()->add('category_ids', __('validation.category_other_branch'));
            }
        });
    }
```

In `app/Http/Requests/ActivityRequest.php`, add `use App\Support\CategoryOwnership;` and add after `rules()`:

```php
    /** A category must belong to the chosen branch, if any. One already saved stays until edited. */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $stored = $this->route('activity')?->categories()->pluck('categories.id');
            if (CategoryOwnership::outside($this->input('category_ids'), array_filter([$this->input('branch_id')]), $stored)) {
                $validator->errors()->add('category_ids', __('validation.category_other_branch'));
            }
        });
    }
```

- [ ] **Step 10: Run the new test, then the suite**

```bash
php artisan test tests/Feature/Registrations/CategoryOwnershipTest.php
php artisan config:clear && php artisan test 2>&1 | tail -30
```

Expected: the new test passes. Existing failures are `validation.category_other_branch` or 422s in the files listed at the top. Fix each with the repair table, and list what you changed in `.superpowers/r2/progress.md`.

- [ ] **Step 11: Commit**

```bash
git add app/Support/CategoryOwnership.php lang/ar/validation.php lang/en/validation.php lang/fr/validation.php app/Http/Requests/Player/ValidatesRegistrations.php app/Services/Player/RegistrationService.php app/Http/Controllers/TrainingSessionController.php app/Http/Controllers/AttendanceSettingsController.php app/Http/Controllers/TeamController.php app/Http/Controllers/FinanceAccountController.php app/Http/Requests/Subscription/StoreSubscriptionRequest.php app/Http/Requests/ActivityRequest.php tests/Feature/Registrations/CategoryOwnershipTest.php
git commit -m "feat(categories): a category must belong to its branch

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Before committing, also `git add` each test file you repaired, by its path, as listed in `progress.md`.

### Task P7.4: The naming and picker helpers

**Files:**
- Create: `resources/js/lib/sportLabels.js`
- Create: `resources/js/lib/categoryPicker.js`
- Create: `tests/js/sportLabels.test.mjs`
- Create: `tests/js/categoryPicker.test.mjs`
- Delete: `resources/js/lib/positionLabels.js`, `tests/js/positionLabels.test.mjs`
- Modify: `resources/js/Pages/Players/Index.vue`, `resources/js/Pages/Players/Partials/PlayerForm.vue`
- Create: `app/Support/CategoryLabels.php`
- Create: `tests/Feature/Registrations/CategoryLabelsTest.php`

**Interfaces:**
- Produces: `sportLabeller(items, base = (x) => x.name)`, returning `(item) => label`. `categoriesForBranch(categories, branchId, stored = [])` and `categoriesForBranches(categories, branchIds, stored = [])`, each returning the active categories owned by those branches or unowned, plus any in `stored`. No branch given means every active category plus `stored`.
- Produces: `CategoryLabels::make(): self`, `->of(int|string|null $id): ?string`, `->options(): list<array{value: int, label: string}>`. Build one per request.

- [ ] **Step 1: Write the failing JS tests**

`tests/js/sportLabels.test.mjs`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { sportLabeller } from '../../resources/js/lib/sportLabels.js';

const positions = [
    { id: 1, name: 'Goalkeeper', abbreviation: 'GK', branch: { name: 'Football', localized_name: 'Football' } },
    { id: 2, name: 'Goalkeeper', abbreviation: 'GK', branch: { name: 'Handball', localized_name: 'Hand' } },
    { id: 3, name: 'Striker', abbreviation: 'ST', branch: { name: 'Football', localized_name: 'Football' } },
    { id: 4, name: 'Pivot', abbreviation: 'PV', branch: null },
];

test('the sport is added only to names shared by several sports', () => {
    const label = sportLabeller(positions);
    assert.equal(label(positions[0]), 'Goalkeeper · Football');
    assert.equal(label(positions[1]), 'Goalkeeper · Hand');
    assert.equal(label(positions[2]), 'Striker');
    assert.equal(label(positions[3]), 'Pivot');
});

test('a custom base label keeps the sport suffix rule', () => {
    const label = sportLabeller(positions, (p) => `${p.abbreviation} - ${p.name}`);
    assert.equal(label(positions[1]), 'GK - Goalkeeper · Hand');
    assert.equal(label(positions[2]), 'ST - Striker');
});

test('categories use the same rule on their localized names', () => {
    const categories = [
        { id: 1, name: 'Senior', localized_name: 'Séniors', branch: { localized_name: 'Football' } },
        { id: 2, name: 'Senior', localized_name: 'Séniors', branch: { localized_name: 'Natation' } },
        { id: 3, name: 'Ecole', localized_name: 'École', branch: { localized_name: 'Football' } },
    ];
    const label = sportLabeller(categories, (c) => c.localized_name || c.name);
    assert.equal(label(categories[1]), 'Séniors · Natation');
    assert.equal(label(categories[2]), 'École');
});
```

`tests/js/categoryPicker.test.mjs`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { categoriesForBranch, categoriesForBranches } from '../../resources/js/lib/categoryPicker.js';

const list = [
    { id: 1, name: 'Senior', branch_id: 10, is_active: true },
    { id: 2, name: 'U15', branch_id: 20, is_active: true },
    { id: 3, name: 'Old', branch_id: 10, is_active: false },
    { id: 4, name: 'Open', branch_id: null, is_active: true },
];
const ids = (rows) => rows.map((c) => c.id);

test('a branch lists its own active categories and unowned ones', () => {
    assert.deepEqual(ids(categoriesForBranch(list, 10)), [1, 4]);
    assert.deepEqual(ids(categoriesForBranch(list, 20)), [2, 4]);
});

test('a stored value stays listed, even inactive or from another branch', () => {
    assert.deepEqual(ids(categoriesForBranch(list, 10, [3])), [1, 3, 4]);
    assert.deepEqual(ids(categoriesForBranch(list, 20, [1])), [1, 2, 4]);
    assert.deepEqual(ids(categoriesForBranch(list, 20, ['1', '', null])), [1, 2, 4]);
});

test('no branch lists every active category', () => {
    assert.deepEqual(ids(categoriesForBranch(list, null)), [1, 2, 4]);
    assert.deepEqual(ids(categoriesForBranch(list, '')), [1, 2, 4]);
});

test('several ticked branches list all of theirs, and none ticked lists all', () => {
    assert.deepEqual(ids(categoriesForBranches(list, [10, 20])), [1, 2, 4]);
    assert.deepEqual(ids(categoriesForBranches(list, [20])), [2, 4]);
    assert.deepEqual(ids(categoriesForBranches(list, [])), [1, 2, 4]);
});

test('a branch id arriving as a string still matches', () => {
    assert.deepEqual(ids(categoriesForBranch(list, '20')), [2, 4]);
});

test('a missing is_active counts as active', () => {
    assert.deepEqual(ids(categoriesForBranch([{ id: 9, branch_id: 10 }], 10)), [9]);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `node --test tests/js/sportLabels.test.mjs tests/js/categoryPicker.test.mjs`
Expected: FAIL, `Cannot find module`.

- [ ] **Step 3: Write the JS helpers and move the old labeller**

```bash
git mv resources/js/lib/positionLabels.js resources/js/lib/sportLabels.js
git rm tests/js/positionLabels.test.mjs
```

Replace the content of `resources/js/lib/sportLabels.js`:

```js
/**
 * Positions and categories are per sport, so two can share a name ("Goalkeeper"
 * in football and in handball, "Senior" in football and in swimming). Returns a
 * labeller that appends " · Sport" only to those shared names, leaving every
 * other option as before.
 */
export function sportLabeller(items, base = (item) => item.name) {
    const counts = new Map();
    for (const item of items) counts.set(item.name, (counts.get(item.name) || 0) + 1);

    return (item) => {
        const sport = item.branch?.localized_name || item.branch?.name;
        return counts.get(item.name) > 1 && sport ? `${base(item)} · ${sport}` : base(item);
    };
}
```

`resources/js/lib/categoryPicker.js`:

```js
/**
 * Which categories a picker offers. New choices: active ones of the given
 * branches, plus any with no owner. A value already stored stays listed until it
 * is changed, even if inactive or from another branch. The server checks too
 * (App\Support\CategoryOwnership).
 */
export function categoriesForBranches(categories, branchIds, stored = []) {
    const branches = new Set((branchIds ?? []).filter(Boolean).map(Number));
    const kept = new Set((stored ?? []).filter(Boolean).map(Number));

    return categories.filter((category) => {
        if (kept.has(Number(category.id))) return true;
        if (category.is_active === false) return false;
        return branches.size === 0 || category.branch_id == null || branches.has(Number(category.branch_id));
    });
}

export function categoriesForBranch(categories, branchId, stored = []) {
    return categoriesForBranches(categories, branchId ? [branchId] : [], stored);
}
```

In `resources/js/Pages/Players/Index.vue` and `resources/js/Pages/Players/Partials/PlayerForm.vue`, change the import to `import { sportLabeller } from '@/lib/sportLabels';` and each `positionLabeller(` call to `sportLabeller(`.

- [ ] **Step 4: Run the JS tests**

Run: `npm run test:js 2>&1 | tail -5`
Expected: all PASS.

- [ ] **Step 5: Write the failing PHP test**

`tests/Feature/Registrations/CategoryLabelsTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Category;
use App\Support\CategoryLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class CategoryLabelsTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    #[Test]
    public function the_sport_is_added_only_when_the_name_repeats(): void
    {
        $swimSenior = Category::create(['name' => 'Senior', 'branch_id' => $this->swim->id]);

        $labels = CategoryLabels::make();

        $this->assertSame('Senior · Football', $labels->of($this->senior->id));
        $this->assertSame('Senior · Swimming', $labels->of($swimSenior->id));
        $this->assertSame('U15', $labels->of($this->u15->id));
        $this->assertNull($labels->of(null));
        $this->assertNull($labels->of(999));
    }

    #[Test]
    public function it_uses_the_current_locale(): void
    {
        $this->foot->update(['name_fr' => 'Foot']);
        $this->senior->update(['name_fr' => 'Séniors']);
        Category::create(['name' => 'Senior', 'branch_id' => $this->swim->id]);
        app()->setLocale('fr');

        $this->assertSame('Séniors · Foot', CategoryLabels::make()->of($this->senior->id));
    }

    #[Test]
    public function an_unowned_repeat_has_no_sport_to_add(): void
    {
        $open = Category::create(['name' => 'Senior']);

        $this->assertSame('Senior', CategoryLabels::make()->of($open->id));
    }

    #[Test]
    public function options_are_ordered_by_name_for_a_filter(): void
    {
        $options = CategoryLabels::make()->options();

        $this->assertSame(['Senior', 'U15'], array_column($options, 'label'));
        $this->assertSame($this->senior->id, $options[0]['value']);
    }
}
```

- [ ] **Step 6: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/CategoryLabelsTest.php`
Expected: FAIL, `Class "App\Support\CategoryLabels" not found`.

- [ ] **Step 7: Write `CategoryLabels`**

`app/Support/CategoryLabels.php`:

```php
<?php

namespace App\Support;

use App\Models\Category;

/**
 * Category names where no single branch applies: filters, charts, team labels.
 * The sport is added only when the name repeats ("Senior · Swimming"). The server
 * twin of sportLabeller() in resources/js/lib/sportLabels.js. Build one per request.
 */
final class CategoryLabels
{
    /** @param  array<int, string>  $labels  in name order */
    private function __construct(private readonly array $labels) {}

    public static function make(): self
    {
        $categories = Category::query()
            ->with('branch:id,name,name_ar,name_fr,name_en')
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'name_ar', 'name_fr', 'name_en', 'branch_id']);
        $counts = $categories->countBy('name');

        return new self($categories->mapWithKeys(fn (Category $category) => [
            $category->id => $counts[$category->name] > 1 && $category->branch !== null
                ? $category->localized_name.' · '.$category->branch->localized_name
                : $category->localized_name,
        ])->all());
    }

    public function of(int|string|null $id): ?string
    {
        return $id === null ? null : ($this->labels[(int) $id] ?? null);
    }

    /** @return list<array{value: int, label: string}> */
    public function options(): array
    {
        return array_map(
            fn (int $id, string $label) => ['value' => $id, 'label' => $label],
            array_keys($this->labels),
            array_values($this->labels),
        );
    }
}
```

- [ ] **Step 8: Run the test**

Run: `php artisan test tests/Feature/Registrations/CategoryLabelsTest.php`
Expected: all PASS.

- [ ] **Step 9: Commit**

```bash
git add resources/js/lib/sportLabels.js resources/js/lib/categoryPicker.js tests/js/sportLabels.test.mjs tests/js/categoryPicker.test.mjs resources/js/Pages/Players/Index.vue resources/js/Pages/Players/Partials/PlayerForm.vue app/Support/CategoryLabels.php tests/Feature/Registrations/CategoryLabelsTest.php
git commit -m "feat(categories): name and pick categories per sport

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

`git mv` and `git rm` already staged the removals.

### Task P7.5: Branch page with General and Categories tabs

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/BranchController.php`
- Modify: `app/Http/Controllers/CategoryController.php`
- Create: `resources/js/Pages/Settings/BranchDetail.vue`
- Create: `resources/js/Pages/Settings/Partials/BranchGeneralForm.vue`
- Create: `resources/js/Pages/Settings/Partials/MemberCategoriesPanel.vue`
- Modify: `resources/js/Pages/Settings/Branches.vue`
- Modify: `resources/js/Pages/Settings/Categories.vue`
- Create: `tests/Feature/Registrations/BranchPageTest.php`
- Modify: `tests/Feature/PerListPermissionsTest.php`
- i18n: `resources/js/i18n/{ar,en,fr}.json` through `scripts/i18n-add.mjs`

**Interfaces:**
- Consumes: `Category::forBranch`, `Category::isInUse`, `Category::registrations`.
- Produces: routes `branches.show` (`GET /branches/{branch}`, right `branches.view`), `categories.show` (`GET /branches/{branch}/categories`, right `categories.view`), `categories.toggle-active` (`POST /categories/{category}/active`, right `categories.edit`). `categories.store` takes `branch_id`, defaulting to the main branch.
- Produces: page `Settings/BranchDetail` with props `branch`, `tab` (`general` or `categories`), `categories`. Custom fields and documents tabs arrive in a later release by adding to its `tabs` list.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/BranchPageTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class BranchPageTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
    }

    #[Test]
    public function the_rights_stay_on_categories(): void
    {
        $this->assertSame(['categories', 'view'], PermissionMap::resolve('categories.show'));
        $this->assertSame(['categories', 'edit'], PermissionMap::resolve('categories.toggle-active'));
        $this->assertSame(['branches', 'view'], PermissionMap::resolve('branches.show'));
    }

    #[Test]
    public function the_old_page_sends_people_to_the_main_branchs_tab(): void
    {
        $this->twoSportClub();

        $this->get(route('categories.index'))->assertRedirect(route('categories.show', $this->foot));
    }

    #[Test]
    public function with_no_branches_the_old_page_stays(): void
    {
        Category::create(['name' => 'Senior']);

        $this->get(route('categories.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/Categories')->has('categories', 1));
    }

    #[Test]
    public function the_tab_lists_only_that_branchs_categories_with_their_counts(): void
    {
        $this->twoSportClub();
        $this->bothActive();

        $this->get(route('categories.show', $this->swim))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/BranchDetail')
                ->where('tab', 'categories')
                ->where('branch.id', $this->swim->id)
                ->has('categories', 1)
                ->where('categories.0.name', 'U15')
                ->where('categories.0.registrations_count', 1));
    }

    #[Test]
    public function the_general_tab_holds_the_branch(): void
    {
        $this->twoSportClub();

        $this->get(route('branches.show', $this->swim))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/BranchDetail')
                ->where('tab', 'general')
                ->where('branch.code', 'SW'));
    }

    #[Test]
    public function adding_goes_to_the_named_branch_and_a_name_is_unique_within_it(): void
    {
        $this->twoSportClub();

        $this->post(route('categories.store'), ['name' => 'Senior', 'branch_id' => $this->swim->id])->assertSessionHasNoErrors();
        $this->assertSame(1, Category::where('branch_id', $this->swim->id)->where('name', 'Senior')->count());

        $this->post(route('categories.store'), ['name' => 'Senior', 'branch_id' => $this->swim->id])->assertSessionHasErrors('name');
        $this->post(route('categories.store'), ['name' => 'Senior'])->assertSessionHasErrors('name');
    }

    #[Test]
    public function with_no_branches_a_name_stays_unique_across_the_club(): void
    {
        $this->post(route('categories.store'), ['name' => 'Senior'])->assertSessionHasNoErrors();
        $this->post(route('categories.store'), ['name' => 'Senior'])->assertSessionHasErrors('name');
        $this->assertNull(Category::first()->branch_id);
    }

    #[Test]
    public function adding_without_a_branch_uses_the_main_one(): void
    {
        $this->twoSportClub();

        $this->post(route('categories.store'), ['name' => 'Cadet'])->assertSessionHasNoErrors();

        $this->assertSame($this->foot->id, Category::where('name', 'Cadet')->value('branch_id'));
    }

    #[Test]
    public function a_category_cannot_move_to_another_branch(): void
    {
        $this->twoSportClub();

        $this->put(route('categories.update', $this->u15), ['name' => 'U15', 'branch_id' => $this->foot->id])->assertSessionHasNoErrors();

        $this->assertSame($this->swim->id, $this->u15->refresh()->branch_id);
    }

    #[Test]
    public function renaming_is_checked_within_the_categorys_own_branch(): void
    {
        $this->twoSportClub();
        Category::create(['name' => 'Cadet', 'branch_id' => $this->swim->id]);

        $this->put(route('categories.update', $this->u15), ['name' => 'Cadet'])->assertSessionHasErrors('name');
        $this->put(route('categories.update', $this->u15), ['name' => 'Senior'])->assertSessionHasNoErrors();
    }

    #[Test]
    public function deactivating_keeps_it_stored_and_listed_on_the_tab(): void
    {
        $this->twoSportClub();
        $player = $this->bothActive();

        $this->post(route('categories.toggle-active', $this->u15))->assertSessionHas('success', 'flash.category_deactivated');

        $this->assertFalse($this->u15->refresh()->is_active);
        $this->assertSame($this->u15->id, $player->registrationIn($this->swim->id)->category_id);
        $this->get(route('categories.show', $this->swim))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('categories.0.is_active', false));

        $this->post(route('categories.toggle-active', $this->u15))->assertSessionHas('success', 'flash.category_activated');
        $this->assertTrue($this->u15->refresh()->is_active);
    }

    #[Test]
    public function delete_keeps_todays_refusals(): void
    {
        $this->twoSportClub();
        $this->bothActive();

        $this->delete(route('categories.destroy', $this->u15))->assertSessionHas('error', 'flash.category_has_registrations');
        $this->assertTrue(Category::whereKey($this->u15->id)->exists());
    }

    #[Test]
    public function deleting_a_branch_takes_its_unused_categories_with_it(): void
    {
        $this->twoSportClub();
        $extra = Branch::create(['name' => 'Tennis', 'code' => 'TN']);
        $unused = Category::create(['name' => 'Loisir', 'branch_id' => $extra->id]);

        $this->delete(route('branches.destroy', $extra))->assertSessionHas('success', 'flash.branch_deleted');

        $this->assertFalse(Branch::whereKey($extra->id)->exists());
        $this->assertFalse(Category::whereKey($unused->id)->exists());
    }

    #[Test]
    public function a_used_category_refuses_the_branch_delete(): void
    {
        $this->twoSportClub();
        $extra = Branch::create(['name' => 'Tennis', 'code' => 'TN']);
        $used = Category::create(['name' => 'Loisir', 'branch_id' => $extra->id]);
        $subscription = Subscription::create(['name' => 'S', 'year' => 2026, 'amount_student' => 1, 'amount_worker' => 1]);
        $subscription->categories()->attach($used->id);

        $this->delete(route('branches.destroy', $extra))->assertSessionHas('error', 'flash.branch_has_used_categories');

        $this->assertTrue(Branch::whereKey($extra->id)->exists());
        $this->assertTrue(Category::whereKey($used->id)->exists());
    }

    #[Test]
    public function a_flat_player_using_a_category_also_counts_as_use(): void
    {
        $this->twoSportClub();
        $extra = Branch::create(['name' => 'Tennis', 'code' => 'TN']);
        $used = Category::create(['name' => 'Loisir', 'branch_id' => $extra->id]);
        Player::create(['firstname' => 'Flat', 'category_id' => $used->id]);

        $this->delete(route('branches.destroy', $extra))->assertSessionHas('error', 'flash.branch_has_used_categories');
    }

    #[Test]
    public function the_branches_list_links_to_the_page(): void
    {
        $this->twoSportClub();

        $this->get(route('branches.index'))->assertOk();
        $this->get(route('branches.show', $this->foot))->assertOk();
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/BranchPageTest.php`
Expected: FAIL, `Route [categories.show] not defined`.

- [ ] **Step 3: Add the routes**

In `routes/web.php`, replace the `categories` and `branches` resource lines:

```php
        Route::resource('categories', CategoryController::class)->except(['show', 'create', 'edit']);
        Route::post('/categories/{category}/active', [CategoryController::class, 'toggleActive'])->name('categories.toggle-active');
        Route::get('/branches/{branch}/categories', [CategoryController::class, 'show'])->name('categories.show');
        Route::resource('branches', BranchController::class)->except(['create', 'edit']);
```

- [ ] **Step 4: Rewrite `CategoryController`'s index, store, update; add show and toggle**

In `app/Http/Controllers/CategoryController.php`, add imports `App\Models\Branch` and `Illuminate\Validation\Rule`. Replace `index`, `store` and `update`:

```php
    /** Categories live on their branch's page. A club with no branches keeps this page. */
    public function index(): Response|RedirectResponse
    {
        $main = Branch::mainId() ?? Branch::query()->orderBy('id')->value('id');
        if ($main !== null) {
            return redirect()->route('categories.show', $main);
        }

        return Inertia::render('Settings/Categories', [
            'categories' => $this->listed(null),
        ]);
    }

    /** The Categories tab of a branch's page. */
    public function show(Branch $branch): Response
    {
        return Inertia::render('Settings/BranchDetail', [
            'branch' => $branch,
            'tab' => 'categories',
            'categories' => $this->listed($branch->id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['branch_id' => ['nullable', 'integer', 'exists:branches,id']]);
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : Branch::mainId();

        Category::create($this->validateNames($request, $branchId) + ['branch_id' => $branchId]);

        return back()->with('success', 'flash.category_created');
    }

    /** A category can't move to another branch: its rows and prices stay with it. */
    public function update(Request $request, Category $category): RedirectResponse
    {
        $category->update($this->validateNames($request, $category->branch_id, $category->id));

        return back()->with('success', 'flash.category_updated');
    }

    /** Hidden from pickers for new choices, kept where stored, still in reports. */
    public function toggleActive(Category $category): RedirectResponse
    {
        $category->update(['is_active' => ! $category->is_active]);

        return back()->with('success', $category->is_active ? 'flash.category_activated' : 'flash.category_deactivated');
    }

    /** @return Collection<int, Category> */
    private function listed(?int $branchId): Collection
    {
        return Category::query()
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->withCount('registrations')
            ->orderBy('name')
            ->get();
    }

    /** @return array<string, mixed> */
    private function validateNames(Request $request, ?int $branchId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'name')->ignore($ignoreId)
                    ->where(fn ($q) => $branchId === null ? $q->whereNull('branch_id') : $q->where('branch_id', $branchId)),
            ],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_fr' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);
    }
```

Add `use Illuminate\Database\Eloquent\Collection;`. `destroy` stays as it is.

- [ ] **Step 5: Add `show` and the delete rule to `BranchController`**

In `app/Http/Controllers/BranchController.php`, add `use App\Models\Category;`, and add after `index`:

```php
    /** The General tab of a branch's page. */
    public function show(Branch $branch): Response
    {
        return Inertia::render('Settings/BranchDetail', [
            'branch' => $branch,
            'tab' => 'general',
            'categories' => [],
        ]);
    }
```

In `destroy`, add before `$files->delete($branch->logo_filename);`:

```php
        // A branch's unused categories go with it; one still used refuses the delete.
        $categories = Category::where('branch_id', $branch->id)->get();
        if ($categories->contains(fn (Category $category) => $category->isInUse())) {
            return back()->with('error', 'flash.branch_has_used_categories');
        }
```

And inside the transaction, before `$branch->delete();`:

```php
            $categories->each->delete();
```

- [ ] **Step 6: Run the test**

Run: `php artisan test tests/Feature/Registrations/BranchPageTest.php`
Expected: PHP assertions pass. Tests that render fail with a missing Vue page only if the Inertia page check is on, so if `assertInertia` complains that `Settings/BranchDetail` doesn't exist, go on to Step 7 and rerun.

- [ ] **Step 7: Add the i18n keys**

Write `C:\Users\MUSTAPHA\AppData\Local\Temp\claude\d--irnb-laravel\11a6f482-f907-4a93-aab7-376002fc2f9d\scratchpad\p7-keys.json`:

```json
{
    "branch_page.general": { "ar": "عام", "fr": "Général", "en": "General" },
    "branch_page.categories": { "ar": "الفئات", "fr": "Catégories", "en": "Categories" },
    "branch_page.back": { "ar": "العودة إلى الفروع", "fr": "Retour aux sections", "en": "Back to branches" },
    "category_tab.activate": { "ar": "تفعيل", "fr": "Activer", "en": "Activate" },
    "category_tab.deactivate": { "ar": "إيقاف", "fr": "Désactiver", "en": "Deactivate" },
    "category_tab.inactive": { "ar": "موقوفة", "fr": "Inactive", "en": "Inactive" },
    "category_tab.inactive_hint": { "ar": "الفئة الموقوفة لا تظهر عند الاختيار الجديد، وتبقى حيث هي محفوظة وفي التقارير.", "fr": "Une catégorie inactive n'est plus proposée pour un nouveau choix. Elle reste là où elle est enregistrée et dans les rapports.", "en": "An inactive category is no longer offered for new choices. It stays where it is stored and in reports." },
    "flash.category_activated": { "ar": "تم تفعيل الفئة.", "fr": "Catégorie activée.", "en": "Category activated." },
    "flash.category_deactivated": { "ar": "تم إيقاف الفئة.", "fr": "Catégorie désactivée.", "en": "Category deactivated." },
    "flash.branch_has_used_categories": { "ar": "لا يمكن الحذف: فئة من فئات هذا الفرع مستعملة.", "fr": "Suppression impossible : une catégorie de cette section est utilisée.", "en": "Can't delete: one of this branch's categories is in use." }
}
```

Run:

```bash
node scripts/i18n-add.mjs "C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/11a6f482-f907-4a93-aab7-376002fc2f9d/scratchpad/p7-keys.json"
npm run i18n:check
```

Expected: `i18n:check` passes.

- [ ] **Step 8: Write the shared categories panel**

`resources/js/Pages/Settings/Partials/MemberCategoriesPanel.vue`, used by the tab and by the no-branches page:

```vue
<script setup>
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import { useCan } from '@/Composables/useCan';
import { useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({
    categories: { type: Array, default: () => [] },
    branchId: { type: Number, default: null }, // null: a club with no branches
});

const editingId = ref(null);
const deleteId = ref(null);

const form = useForm({ branch_id: props.branchId, name: '', name_ar: '', name_fr: '', name_en: '', description: '' });
const editForm = useForm({ name: '', name_ar: '', name_fr: '', name_en: '', description: '' });

function addCategory() {
    form.post(route('categories.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('name', 'name_ar', 'name_fr', 'name_en', 'description'),
    });
}

function startEdit(cat) {
    editingId.value = cat.id;
    editForm.clearErrors();
    editForm.name = cat.name;
    editForm.name_ar = cat.name_ar || '';
    editForm.name_fr = cat.name_fr || '';
    editForm.name_en = cat.name_en || '';
    editForm.description = cat.description || '';
}

function saveEdit(id) {
    editForm.put(route('categories.update', id), {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
    });
}

function toggle(cat) {
    router.post(route('categories.toggle-active', cat.id), {}, { preserveScroll: true });
}

function destroy() {
    router.delete(route('categories.destroy', deleteId.value), {
        preserveScroll: true,
        onSuccess: () => { deleteId.value = null; },
    });
}
</script>

<template>
    <div class="space-y-6">
        <!-- Add form -->
        <form v-if="can('categories', 'add')" @submit.prevent="addCategory" class="space-y-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }} <span class="text-xs text-slate-400">({{ t('default') }})</span></label>
                    <TextInput v-model="form.name" class="mt-1 w-full" :placeholder="t('name')" required />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('description') }}</label>
                    <TextInput v-model="form.description" class="mt-1 w-full" :placeholder="t('description')" />
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }} (العربية)</label>
                    <TextInput v-model="form.name_ar" class="mt-1 w-full" placeholder="الاسم" />
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }} (Français)</label>
                    <TextInput v-model="form.name_fr" class="mt-1 w-full" placeholder="Nom" />
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }} (English)</label>
                    <TextInput v-model="form.name_en" class="mt-1 w-full" placeholder="Name" />
                </div>
            </div>
            <div class="flex justify-end">
                <PrimaryButton :disabled="form.processing">{{ t('add') }}</PrimaryButton>
            </div>
        </form>

        <!-- List -->
        <div class="overflow-x-auto rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
            <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                <thead class="bg-slate-50 dark:bg-slate-950">
                    <tr>
                        <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                        <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('description') }}</th>
                        <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('members') }}</th>
                        <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="cat in categories" :key="cat.id">
                        <td class="px-4 py-3">
                            <div v-if="editingId === cat.id" class="space-y-1.5">
                                <TextInput v-model="editForm.name" class="w-full" :placeholder="t('name')" />
                                <TextInput v-model="editForm.name_ar" class="w-full" placeholder="العربية" />
                                <TextInput v-model="editForm.name_fr" class="w-full" placeholder="Français" />
                                <TextInput v-model="editForm.name_en" class="w-full" placeholder="English" />
                                <InputError :message="editForm.errors.name" class="mt-1" />
                            </div>
                            <template v-else>
                                <span class="text-sm font-medium text-slate-900 dark:text-slate-100" :class="{ 'opacity-60': cat.is_active === false }">{{ cat.localized_name || cat.name }}</span>
                                <span v-if="cat.is_active === false" class="ms-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('category_tab.inactive') }}</span>
                            </template>
                        </td>
                        <td class="px-4 py-3">
                            <TextInput v-if="editingId === cat.id" v-model="editForm.description" class="w-full" />
                            <span v-else class="text-sm text-slate-600 dark:text-slate-300">{{ cat.description || '-' }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ cat.registrations_count }}</td>
                        <td class="px-4 py-3 text-end">
                            <div v-if="editingId === cat.id" class="flex justify-end gap-2">
                                <button @click="saveEdit(cat.id)" class="text-sm text-primary-600 hover:text-primary-800 dark:text-primary-300 dark:hover:text-primary-200">{{ t('save') }}</button>
                                <button @click="editingId = null" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('cancel') }}</button>
                            </div>
                            <div v-else class="flex justify-end gap-2">
                                <IconButton v-if="can('categories', 'edit')" :icon="cat.is_active === false ? 'check' : 'close'" :label="cat.is_active === false ? t('category_tab.activate') : t('category_tab.deactivate')" plain size="sm" @click="toggle(cat)" />
                                <IconButton v-if="can('categories', 'edit')" icon="pencil" :label="t('edit')" plain size="sm" @click="startEdit(cat)" />
                                <IconButton v-if="can('categories', 'delete')" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = cat.id" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!categories?.length">
                        <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('category_tab.inactive_hint') }}</p>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </div>
</template>
```

Check `resources/js/Components/IconButton.vue` for the `check` icon name before keeping it. If the icon set has no `check`, use the name it has for a tick; `close` is confirmed by `CategoryManager.vue`.

Replace `resources/js/Pages/Settings/Categories.vue` with:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MemberCategoriesPanel from './Partials/MemberCategoriesPanel.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// Only a club with no branches lands here: otherwise /categories goes to a branch's tab.
defineProps({ categories: Array });
</script>

<template>
    <Head :title="t('categories')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('categories') }}</h1>
        </template>

        <div class="mx-auto max-w-2xl">
            <MemberCategoriesPanel :categories="categories" />
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 9: Write the General form and the page**

`resources/js/Pages/Settings/Partials/BranchGeneralForm.vue`:

```vue
<script setup>
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useCan } from '@/Composables/useCan';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({ branch: { type: Object, required: true } });

// Files only travel in multipart POSTs, so the update is a POST spoofing PUT.
const form = useForm({
    _method: 'put',
    name: props.branch.name,
    code: props.branch.code || '',
    name_ar: props.branch.name_ar || '',
    name_fr: props.branch.name_fr || '',
    name_en: props.branch.name_en || '',
    description: props.branch.description || '',
    logo: null,
    remove_logo: false,
});

function save() {
    form.post(route('branches.update', props.branch.id), { forceFormData: true, preserveScroll: true });
}
</script>

<template>
    <form @submit.prevent="save" class="space-y-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }} <span class="text-xs text-slate-400">({{ t('default') }})</span></label>
                <TextInput v-model="form.name" class="mt-1 w-full" :placeholder="t('name')" required />
                <InputError :message="form.errors.name" class="mt-1" />
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('branch_code') }}</label>
                <TextInput v-model="form.code" class="mt-1 w-full uppercase" maxlength="6" dir="ltr" />
                <InputError :message="form.errors.code" class="mt-1" />
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }} (العربية)</label>
                <TextInput v-model="form.name_ar" class="mt-1 w-full" placeholder="الاسم" />
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }} (Français)</label>
                <TextInput v-model="form.name_fr" class="mt-1 w-full" placeholder="Nom" />
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }} (English)</label>
                <TextInput v-model="form.name_en" class="mt-1 w-full" placeholder="Name" />
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('description') }}</label>
                <TextInput v-model="form.description" class="mt-1 w-full" :placeholder="t('description')" />
            </div>
            <div class="sm:col-span-2">
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('logo') }}</label>
                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <img v-if="branch.logo_url && !form.remove_logo && !form.logo" :src="branch.logo_url" alt="" class="h-8 w-8 rounded object-contain" />
                    <input type="file" accept="image/*" class="block min-w-0 flex-1 text-xs" @change="form.logo = $event.target.files[0] || null; form.remove_logo = false" />
                    <label v-if="branch.logo_url && !form.logo" class="inline-flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
                        <input v-model="form.remove_logo" type="checkbox" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500 dark:border-slate-700" />
                        {{ t('remove') }}
                    </label>
                </div>
                <InputError :message="form.errors.logo" class="mt-1" />
            </div>
        </div>
        <div v-if="can('branches', 'edit')" class="flex justify-end">
            <PrimaryButton :disabled="form.processing">{{ t('save') }}</PrimaryButton>
        </div>
    </form>
</template>
```

`resources/js/Pages/Settings/BranchDetail.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BranchGeneralForm from './Partials/BranchGeneralForm.vue';
import MemberCategoriesPanel from './Partials/MemberCategoriesPanel.vue';
import { useCan } from '@/Composables/useCan';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({
    branch: { type: Object, required: true },
    tab: { type: String, required: true }, // general | categories
    categories: { type: Array, default: () => [] },
});

// Later releases add custom fields and documents here. Each tab has its own right.
const tabs = computed(() => [
    { key: 'general', label: t('branch_page.general'), href: route('branches.show', props.branch.id), shown: can('branches', 'view') },
    { key: 'categories', label: t('branch_page.categories'), href: route('categories.show', props.branch.id), shown: can('categories', 'view') },
].filter((tab) => tab.shown));

const name = computed(() => props.branch.localized_name || props.branch.name);
</script>

<template>
    <Head :title="name" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="flex items-center gap-2 text-xl font-bold text-slate-900 dark:text-slate-100">
                <img v-if="branch.logo_url" :src="branch.logo_url" alt="" class="h-7 w-7 rounded object-contain" />
                {{ name }}
            </h1>
        </template>

        <div class="mx-auto max-w-2xl space-y-6">
            <Link v-if="can('branches', 'view')" :href="route('branches.index')" class="inline-block text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">{{ t('branch_page.back') }}</Link>

            <div role="tablist" :aria-label="name" class="flex flex-wrap gap-0.5 rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800 sm:inline-flex">
                <Link
                    v-for="item in tabs"
                    :key="item.key"
                    :href="item.href"
                    role="tab"
                    :aria-selected="tab === item.key"
                    class="rounded-md px-3 py-1 text-sm font-semibold"
                    :class="tab === item.key ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-slate-100' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300'"
                >{{ item.label }}</Link>
            </div>

            <BranchGeneralForm v-if="tab === 'general'" :branch="branch" />
            <MemberCategoriesPanel v-else :categories="categories" :branch-id="branch.id" />
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 10: Make the branches page a list that links to it**

In `resources/js/Pages/Settings/Branches.vue`:

Delete `editingId`, `editForm`, `startEdit` and `saveEdit` from the script.

In the table, replace the name cell's two `div`s (the `v-if="editingId === branch.id"` block and its `v-else`) with:

```vue
                                <div class="flex items-center gap-2">
                                    <img v-if="branch.logo_url" :src="branch.logo_url" alt="" class="h-8 w-8 shrink-0 rounded object-contain" />
                                    <span v-else class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ (branch.localized_name || branch.name || '?').charAt(0) }}</span>
                                    <Link :href="route('branches.show', branch.id)" class="text-sm font-medium text-slate-900 hover:underline dark:text-slate-100">{{ branch.localized_name || branch.name }}</Link>
                                    <span v-if="branch.id === mainBranchId" class="ms-2 rounded bg-primary-50 px-1.5 py-0.5 text-xs text-primary-700 dark:bg-primary-900/30 dark:text-primary-200">{{ t('main_branch') }}</span>
                                    <span class="ms-2 font-mono text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ branch.code }}</span>
                                </div>
```

Replace the actions cell's two `div`s with:

```vue
                                <div class="flex justify-end gap-2">
                                    <IconButton v-if="branch.id !== mainBranchId" icon="flag" :label="t('set_main_branch')" plain size="sm" @click="router.post(route('branches.main', branch.id), {}, { preserveScroll: true })" />
                                    <IconButton icon="players" :label="t('members')" variant="primary" plain size="sm" @click="openMembers(branch)" />
                                    <IconButton icon="pencil" :label="t('edit')" plain size="sm" :href="route('branches.show', branch.id)" />
                                    <IconButton icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = branch.id" />
                                </div>
```

- [ ] **Step 11: Pin the page's permission in the existing test, then run everything**

In `tests/Feature/PerListPermissionsTest.php`, add after the `categories.index` assertion on line 23:

```php
        $this->assertSame(['categories', 'view'], PermissionMap::resolve('categories.show'));
```

Run:

```bash
php artisan test tests/Feature/Registrations/BranchPageTest.php tests/Feature/PerListPermissionsTest.php tests/Feature/BranchFeatureTest.php tests/Feature/CategoryDeletionTest.php tests/Feature/CategoryLocalizationTest.php tests/Feature/UsersBatchTest.php
npm run build 2>&1 | tail -3
npm run i18n:check
```

Expected: all PASS. If `CategoryLocalizationTest` or `UsersBatchTest` post a `code` field to `categories.store`, it is ignored as today.

- [ ] **Step 12: Commit**

```bash
git add routes/web.php app/Http/Controllers/BranchController.php app/Http/Controllers/CategoryController.php resources/js/Pages/Settings/BranchDetail.vue resources/js/Pages/Settings/Partials/BranchGeneralForm.vue resources/js/Pages/Settings/Partials/MemberCategoriesPanel.vue resources/js/Pages/Settings/Branches.vue resources/js/Pages/Settings/Categories.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Registrations/BranchPageTest.php tests/Feature/PerListPermissionsTest.php
git commit -m "feat(branches): a page per branch with General and Categories tabs

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P7.6: Pickers where a branch is known

**Files:**
- Modify: `resources/js/Pages/Players/Partials/RegistrationCard.vue`
- Modify: `app/Http/Controllers/AttendanceCalendarController.php`
- Modify: `resources/js/Pages/Attendance/Index.vue`, `resources/js/Pages/Attendance/Partials/AddSessionModal.vue`
- Modify: `app/Http/Controllers/AttendanceController.php`, `resources/js/Pages/Attendance/Session.vue`
- Modify: `app/Http/Controllers/AttendanceSettingsController.php`, `resources/js/Pages/Attendance/Settings.vue`
- Modify: `app/Http/Controllers/AttendanceAlertsController.php`, `AttendanceInjuryController.php`, `AttendanceRankingController.php`, `AttendanceStatsController.php`
- Modify: `app/Services/Attendance/AttendanceStats.php`, `app/Services/Attendance/PreseasonProgress.php`
- Modify: `app/Http/Controllers/TeamController.php`, `resources/js/Pages/Teams/Index.vue`, `resources/js/Pages/Teams/Show.vue`
- Modify: `app/Http/Controllers/CashRegisterController.php`, `resources/js/Components/Finance/CaisseFormModal.vue`
- Modify: `app/Http/Controllers/SubscriptionController.php`, `resources/js/Pages/Subscriptions/Partials/SubscriptionForm.vue`
- Modify: `app/Http/Controllers/ActivityController.php`, `resources/js/Pages/Activities/Form.vue`
- Create: `tests/Feature/Registrations/CategoryPickersTest.php`

**Interfaces:**
- Consumes: `Category::forBranch`, `CategoryOwnership::outside`, `categoriesForBranch`, `categoriesForBranches`, `sportLabeller`.
- Produces: `categories` props of the attendance pages carry `{ id, name, branch_id, is_active }` (calendar, session, settings) or only the branch's categories (alerts, injuries, ranking, stats). Team, subscription and activity props gain `branch` on each category.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/CategoryPickersTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class CategoryPickersTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
    }

    #[Test]
    public function the_calendar_defaults_to_the_first_active_category_of_the_branch(): void
    {
        $this->u15->update(['is_active' => false]);
        $next = Category::create(['name' => 'Next', 'branch_id' => $this->swim->id]);

        $this->get(route('attendance.index', ['branch_id' => $this->swim->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('categoryId', $next->id)->where('branchId', $this->swim->id));
    }

    #[Test]
    public function a_category_of_another_branch_in_the_url_falls_back_to_the_branchs_own(): void
    {
        $this->get(route('attendance.index', ['branch_id' => $this->swim->id, 'category_id' => $this->senior->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('categoryId', $this->u15->id));
    }

    #[Test]
    public function a_branch_with_no_categories_has_no_default(): void
    {
        $this->senior->delete();

        $this->get(route('attendance.index', ['branch_id' => $this->foot->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('categoryId', null));
    }

    #[Test]
    public function the_calendar_sends_the_owner_with_each_category(): void
    {
        $this->get(route('attendance.index', ['branch_id' => $this->swim->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categories', fn ($list) => collect($list)->firstWhere('id', $this->senior->id)['branch_id'] === $this->foot->id));
    }

    #[Test]
    public function report_pages_list_only_the_branchs_categories_inactive_included(): void
    {
        $old = Category::create(['name' => 'Old', 'branch_id' => $this->swim->id, 'is_active' => false]);

        foreach (['attendance.alerts', 'attendance.injuries', 'attendance.ranking', 'attendance.stats'] as $name) {
            $this->get(route($name, ['branch_id' => $this->swim->id]))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('categories', fn ($list) => collect($list)->pluck('id')->sort()->values()->all() === collect([$this->u15->id, $old->id])->sort()->values()->all()));
        }
    }

    #[Test]
    public function a_report_page_ignores_a_category_of_another_branch(): void
    {
        $this->get(route('attendance.stats', ['branch_id' => $this->swim->id, 'category_id' => $this->senior->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('categoryId', null));
    }

    #[Test]
    public function the_stats_table_shows_only_the_branchs_categories(): void
    {
        $this->get(route('attendance.stats', ['branch_id' => $this->swim->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categoryRows', fn ($rows) => collect($rows)->pluck('category_id')->all() === [$this->u15->id]));
    }

    #[Test]
    public function the_settings_page_sends_the_owner_with_each_category(): void
    {
        $this->get(route('attendance.settings'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categories', fn ($list) => collect($list)->firstWhere('id', $this->u15->id)['branch_id'] === $this->swim->id));
    }

    #[Test]
    public function the_team_form_lists_its_branchs_categories_plus_the_stored_one(): void
    {
        $team = \App\Models\Team::create([
            'branch_id' => $this->swim->id, 'category_id' => $this->senior->id, 'name' => 'A',
            'default_game_format_id' => \App\Models\GameFormat::create([
                'branch_id' => $this->swim->id, 'sport' => 'swimming', 'name' => 'F', 'players_on_field' => 1, 'max_bench' => 0, 'max_called_up' => 1,
                'periods_count' => 1, 'period_minutes' => 1, 'period_label' => 'half', 'scoring' => 'goals', 'sanction_types' => [],
            ])->id,
        ]);

        $this->get(route('teams.show', $team))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categories', fn ($list) => collect($list)->pluck('id')->sort()->values()->all() === collect([$this->senior->id, $this->u15->id])->sort()->values()->all()));
    }

    #[Test]
    public function the_subscription_form_gets_each_categorys_sport_to_label_it(): void
    {
        $this->get(route('subscriptions.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categories', fn ($list) => collect($list)->firstWhere('id', $this->u15->id)['branch']['name'] === 'Swimming'));
    }

    #[Test]
    public function the_caisse_page_sends_the_owner_with_each_category(): void
    {
        $this->get(route('finance.registers'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categories', fn ($list) => collect($list)->firstWhere('id', $this->u15->id)['branch_id'] === $this->swim->id));
    }
}
```

Before running, confirm the route names used above with `php artisan route:list --name=attendance` and `--name=finance`, and the prop name of the stats table in `AttendanceStatsController::data`, and use what exists. The assertions don't change.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/CategoryPickersTest.php`
Expected: FAIL on the default category, the foreign URL category, the report lists and the owner fields.

- [ ] **Step 3: Registration card**

In `resources/js/Pages/Players/Partials/RegistrationCard.vue`, add the import `import { categoriesForBranch } from '@/lib/categoryPicker';` and add above `branchPositions`:

```js
// Only this branch's categories, plus the stored one until it is changed.
const branchCategories = computed(() => categoriesForBranch(props.categories, props.branch.id, [props.card.category_id]));
```

Replace the category block with:

```vue
            <div v-if="branchCategories.length">
                <InputLabel :value="t('category')" />
                <select v-model="card.category_id" :class="selectClass">
                    <option value="">{{ t('select_category') }}</option>
                    <option v-for="category in branchCategories" :key="category.id" :value="category.id">{{ category.localized_name || category.name }}</option>
                </select>
                <InputError :message="fieldError('category_id')" class="mt-1" />
            </div>
```

The server requires no category on a card, so a hidden field needs no other change.

- [ ] **Step 4: Calendar, server side**

In `app/Http/Controllers/AttendanceCalendarController.php`, add `use App\Support\CategoryOwnership;`.

In `index`, after `$data['branch_id'] = BranchChoice::resolve(...)`:

```php
        // A category left in the URL from another branch would show an empty month.
        if (CategoryOwnership::outside($data['category_id'] ?? null, array_filter([$data['branch_id']]))) {
            unset($data['category_id']);
        }
```

Replace the `$categories` closure's body:

```php
            return $list ??= Category::orderBy('id')->get()
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name, 'branch_id' => $c->branch_id, 'is_active' => $c->is_active])->values();
```

In `month`, replace the `$categoryId` line:

```php
        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : $this->firstOfBranch($categories(), $data['branch_id']);
```

Add above `monthAnchor`:

```php
    /** The default: the branch's first active category. A club with no branches takes the first of all. */
    private function firstOfBranch(Collection $categories, ?int $branchId): ?int
    {
        return $categories->first(fn (array $c) => $c['is_active']
            && ($branchId === null || $c['branch_id'] === null || (int) $c['branch_id'] === $branchId))['id'] ?? null;
    }
```

- [ ] **Step 5: Calendar, client side**

In `resources/js/Pages/Attendance/Index.vue`, add `import { categoriesForBranch } from '@/lib/categoryPicker';`, and in the script after the props:

```js
// The views choose among the switched branch's categories. The add-session dialog
// has its own branch field, so it gets them all and filters on that.
const branchCategories = computed(() => categoriesForBranch(props.categories, props.branchId, [props.categoryId]));
```

Change `v-if="!categories.length"` to `v-if="!branchCategories.length"`, and pass `:categories="branchCategories"` to `MonthView`, `AgendaView` and `TimelineView`. Leave `AddSessionModal` with `:categories="categories"`.

In `resources/js/Pages/Attendance/Partials/AddSessionModal.vue`, add `import { categoriesForBranch } from '@/lib/categoryPicker';`. Change the opening `category_id` line and `others`, and add a watch:

```js
const offered = computed(() => categoriesForBranch(props.categories, form.branch_id));

watch(() => props.show, (open) => {
    if (!open) return;
    form.reset();
    form.clearErrors();
    const branchId = props.branchId ?? props.branches[0]?.id ?? null;
    const own = categoriesForBranch(props.categories, branchId);
    Object.assign(form, {
        branch_id: branchId,
        category_id: own.some((c) => c.id === props.categoryId) ? props.categoryId : (own[0]?.id ?? null),
        category_ids: [],
        kind: props.kind,
        date: props.date,
        roster_status_ids: [...props.rosterStatusIds],
    });
});

// A different branch offers different categories: keep the choice only if it still fits.
watch(() => form.branch_id, () => {
    if (!offered.value.some((c) => c.id === form.category_id)) form.category_id = offered.value[0]?.id ?? null;
    form.category_ids = form.category_ids.filter((id) => offered.value.some((c) => c.id === id));
});

// Only a pre-season session can be shared; the primary category is always part of it.
const others = computed(() => offered.value.filter((c) => c.id !== form.category_id));
```

`offered` reads `form`, so declare it after `const form = useForm(...)`. Change the template's `v-for="c in categories"` in the category `select` to `v-for="c in offered"`. Show the form's category error under the select:

```vue
            <InputError :message="form.errors.category_id" class="mt-1" />
```

- [ ] **Step 6: Session edit dialog**

In `app/Http/Controllers/AttendanceController.php`, change `allCategories`:

```php
            'allCategories' => Category::orderBy('id')->get()->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name, 'branch_id' => $c->branch_id, 'is_active' => $c->is_active])->values(),
```

In `resources/js/Pages/Attendance/Session.vue`, add `import { categoriesForBranch } from '@/lib/categoryPicker';` and after `editForm`:

```js
// The session's own categories stay listed until they are changed.
const editCategories = computed(() => categoriesForBranch(props.allCategories, editForm.branch_id, props.session.categories.map((c) => c.id)));
```

Change `v-for="c in allCategories"` to `v-for="c in editCategories"`. Add `computed` to the `vue` import if it isn't there.

- [ ] **Step 7: Schedules and targets**

In `app/Http/Controllers/AttendanceSettingsController.php`, change the `categories` prop:

```php
            'categories' => Category::orderBy('id')->get()
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name, 'branch_id' => $c->branch_id, 'is_active' => $c->is_active])->values(),
```

In `resources/js/Pages/Attendance/Settings.vue`, add `import { categoriesForBranch } from '@/lib/categoryPicker';`. Change the forms' initial category, and add below them:

```js
const firstOf = (branchId) => categoriesForBranch(props.categories, branchId)[0]?.id ?? null;
```

Declare `firstOf` above `scheduleForm`, and in both `useForm` calls replace `category_id: props.categories[0]?.id ?? null` with `category_id: firstOf(props.branches[0]?.id ?? null)`. After each form add:

```js
const scheduleCategories = computed(() => categoriesForBranch(props.categories, scheduleForm.branch_id, [scheduleForm.category_id]));
watch(() => scheduleForm.branch_id, (branchId) => {
    if (!categoriesForBranch(props.categories, branchId).some((c) => c.id === scheduleForm.category_id)) scheduleForm.category_id = firstOf(branchId);
});
```

```js
const targetCategories = computed(() => categoriesForBranch(props.categories, targetForm.branch_id, [targetForm.category_id]));
watch(() => targetForm.branch_id, (branchId) => {
    if (!categoriesForBranch(props.categories, branchId).some((c) => c.id === targetForm.category_id)) targetForm.category_id = firstOf(branchId);
});
```

Editing an old schedule assigns branch and category together with `Object.assign`, so the watch runs after both are set and keeps a stored value only if it fits. To keep a stale one selectable, run the edit's `Object.assign` as it is and set `scheduleForm.category_id = s.category_id` again on the next tick:

```js
nextTick(() => { scheduleForm.category_id = s.category_id; });
```

Add it at the end of the function that holds that `Object.assign`, and add `nextTick` to the `vue` import. Add `computed` to the `vue` import if it is missing. Then change the two `select`s' `v-for`: the schedule one to `c in scheduleCategories`, the target one to `c in targetCategories`.

- [ ] **Step 8: Attendance report pages**

These list the viewed branch's categories, inactive ones included, because they read history. Use this in all four. In `app/Http/Controllers/AttendanceAlertsController.php`, `AttendanceInjuryController.php` and `AttendanceStatsController.php`, add `use App\Support\CategoryOwnership;`, then right after `$branchId = BranchChoice::fromRequest($request);`:

```php
        // A filter left over from another branch would show an empty report.
        if (CategoryOwnership::outside($categoryId, array_filter([$branchId]))) {
            $categoryId = null;
        }
```

And change their `categories` prop:

```php
            'categories' => Category::forBranch($branchId)->orderBy('id')->get()
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->localized_name])
                ->values()->all(),
```

In `AttendanceRankingController::index`, resolve the branch first, because the default category depends on it:

```php
        $request->validate(['category_id' => ['nullable', 'integer', 'exists:categories,id']]);
        $branchId = BranchChoice::fromRequest($request);
        $categories = Category::forBranch($branchId)->orderBy('id')->get()
            ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->localized_name])
            ->values()->all();
        $asked = $request->filled('category_id') ? (int) $request->query('category_id') : null;
        $categoryId = $asked !== null && ! CategoryOwnership::outside($asked, array_filter([$branchId])) ? $asked : ($categories[0]['id'] ?? null);
```

Remove the now-duplicate `$branchId` line below and add the `CategoryOwnership` import.

In `app/Services/Attendance/AttendanceStats.php`, in the method with `return Category::orderBy('id')->get()`, change to `return Category::forBranch($branchId)->orderBy('id')->get()`.

In `app/Services/Attendance/PreseasonProgress.php`, change `forCategories`' `Category::orderBy('id')->pluck('id')` to `Category::forBranch($branchId)->orderBy('id')->pluck('id')`, and `milestones`' query to:

```php
        $categories = Category::forBranch($branchId)->when($categoryId !== null, fn ($q) => $q->whereKey($categoryId))->orderBy('id')->get();
```

- [ ] **Step 9: Teams**

In `app/Http/Controllers/TeamController.php`, `index` keeps `Category::query()->orderBy('name')->get()`, which now carries `branch_id` and `is_active`. In `show`, change the `categories` prop:

```php
            'categories' => Category::forBranch($team->branch_id)->orWhereKey($team->category_id)->orderBy('name')->get(),
```

In `resources/js/Pages/Teams/Index.vue`, add `import { categoriesForBranch } from '@/lib/categoryPicker';`, and below `formatsOfBranch`:

```js
const categoriesOfBranch = computed(() => (form.branch_id ? categoriesForBranch(props.categories, Number(form.branch_id)) : []));
```

Change the sport select's `@change` to reset both, and the category `v-for`:

```vue
                    <select v-model="form.branch_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" required @change="form.default_game_format_id = ''; form.category_id = ''">
```

```vue
                        <option v-for="c in categoriesOfBranch" :key="c.id" :value="c.id">{{ c.localized_name || c.name }}</option>
```

Show its error under the select with `<InputError :message="form.errors.category_id" class="mt-1" />`, importing `InputError` if the page doesn't have it.

In `resources/js/Pages/Teams/Show.vue`, the server already narrowed the list. Hide inactive ones except the stored one by changing the `v-for`:

```vue
                            <option v-for="c in categories.filter((x) => x.is_active !== false || x.id === team.category_id)" :key="c.id" :value="c.id">{{ c.localized_name || c.name }}</option>
```

- [ ] **Step 10: Caisses**

In `app/Http/Controllers/CashRegisterController.php`, change the `categories` prop:

```php
            'categories' => Category::query()->orderBy('name')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en', 'branch_id', 'is_active']),
```

In `resources/js/Components/Finance/CaisseFormModal.vue`, add `import { categoriesForBranch } from '@/lib/categoryPicker';`, and after `takenCategoryIds`:

```js
// The chosen branch's categories, plus this caisse's own while it stays in its branch.
const branchCategories = computed(() => categoriesForBranch(
    props.categories,
    form.branch_id,
    String(props.account?.branch_id ?? '') === String(form.branch_id) ? [props.account?.category_id] : [],
));
```

Replace the `form.branch_id` watch:

```js
watch(() => form.branch_id, (branch) => {
    if (!branch || !branchCategories.value.some((c) => c.id === form.category_id)) form.category_id = '';
});
```

Change the option's `v-for="c in categories"` to `v-for="c in branchCategories"`.

- [ ] **Step 11: Subscription form**

In `app/Http/Controllers/SubscriptionController.php`, change all three `'categories' => Category::orderBy('name')->get(),` (lines 176, 267, 302) to:

```php
            'categories' => Category::with('branch:id,name,name_ar,name_fr,name_en')->orderBy('name')->get(),
```

In `resources/js/Pages/Subscriptions/Partials/SubscriptionForm.vue`, add:

```js
import { categoriesForBranches } from '@/lib/categoryPicker';
import { sportLabeller } from '@/lib/sportLabels';
```

After `form` and `chosenCategories`:

```js
// Those of the ticked branches, or all if none is ticked. Saved ones stay until changed.
const savedCategoryIds = sub.categories?.map((c) => c.id) || [];
const offeredCategories = computed(() => categoriesForBranches(props.categories, form.branch_ids, savedCategoryIds));
const categoryLabel = computed(() => sportLabeller(offeredCategories.value, (c) => c.localized_name || c.name));

// Unticking a branch takes back its categories: the server would refuse them anyway.
watch(() => form.branch_ids, () => {
    form.category_ids = form.category_ids.filter((id) => offeredCategories.value.some((c) => c.id === id));
}, { deep: true });
```

Add `watch` to the `vue` import. Change the checkbox list and the price row name:

```vue
                        <label v-for="cat in offeredCategories" :key="cat.id" class="flex items-center gap-2">
                            <input type="checkbox" :value="cat.id" v-model="form.category_ids"
                                class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500" />
                            <span class="text-sm">{{ categoryLabel(cat) }}</span>
                        </label>
```

In the `chosenCategories` price rows, show `{{ categoryLabel(cat) }}` wherever the row shows the category's name. `chosenCategories` already filters `props.categories` by `form.category_ids`, and `categoryPrices` is built from all of `props.categories`, so prices need no change.

- [ ] **Step 12: Activity form**

In `app/Http/Controllers/ActivityController.php`, change `formOptions`' `categories`:

```php
            'categories' => Category::with('branch:id,name,name_ar,name_fr,name_en')->orderBy('name')->get(),
```

In `resources/js/Pages/Activities/Form.vue`, add:

```js
import { categoriesForBranch } from '@/lib/categoryPicker';
import { sportLabeller } from '@/lib/sportLabels';
```

After `form`:

```js
// The chosen branch's categories, or all if none is chosen. Saved ones stay until changed.
const savedCategoryIds = props.activity?.category_ids ?? [];
const offeredCategories = computed(() => categoriesForBranch(props.categories, form.branch_id, savedCategoryIds));
const categoryLabel = computed(() => sportLabeller(offeredCategories.value, (c) => c.localized_name || c.name));

watch(() => form.branch_id, () => {
    form.category_ids = form.category_ids.filter((id) => offeredCategories.value.some((c) => c.id === id));
});
```

Add `computed` and `watch` to its `vue` import. Change the checkbox `v-for` to `c in offeredCategories` and the label text to `{{ categoryLabel(c) }}`.

- [ ] **Step 13: Run the tests and build**

```bash
php artisan test tests/Feature/Registrations/CategoryPickersTest.php
php artisan config:clear && php artisan test 2>&1 | tail -30
npm run test:js 2>&1 | tail -3
npm run build 2>&1 | tail -3
```

Expected: the new test passes. Any remaining failures are in the files listed at the top, on a calendar or a report that now hides another branch's category. Fix with the repair table.

- [ ] **Step 14: Commit**

```bash
git add resources/js/Pages/Players/Partials/RegistrationCard.vue app/Http/Controllers/AttendanceCalendarController.php resources/js/Pages/Attendance/Index.vue resources/js/Pages/Attendance/Partials/AddSessionModal.vue app/Http/Controllers/AttendanceController.php resources/js/Pages/Attendance/Session.vue app/Http/Controllers/AttendanceSettingsController.php resources/js/Pages/Attendance/Settings.vue app/Http/Controllers/AttendanceAlertsController.php app/Http/Controllers/AttendanceInjuryController.php app/Http/Controllers/AttendanceRankingController.php app/Http/Controllers/AttendanceStatsController.php app/Services/Attendance/AttendanceStats.php app/Services/Attendance/PreseasonProgress.php app/Http/Controllers/TeamController.php resources/js/Pages/Teams/Index.vue resources/js/Pages/Teams/Show.vue app/Http/Controllers/CashRegisterController.php resources/js/Components/Finance/CaisseFormModal.vue app/Http/Controllers/SubscriptionController.php resources/js/Pages/Subscriptions/Partials/SubscriptionForm.vue app/Http/Controllers/ActivityController.php resources/js/Pages/Activities/Form.vue tests/Feature/Registrations/CategoryPickersTest.php
git commit -m "feat(categories): pickers offer only the branch's categories

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Before committing, also `git add` each test file you repaired, by its path.

### Task P7.7: Names where no single branch applies

**Files:**
- Modify: `app/Http/Controllers/PlayerController.php`, `resources/js/Pages/Players/Index.vue`
- Modify: `app/Http/Controllers/JoinDateController.php`, `resources/js/Pages/Players/JoinDates.vue`
- Modify: `app/Reports/Concerns/FiltersPlayers.php`, `app/Reports/Attendance/SessionsOverview.php`, `app/Reports/Players/PlayerDirectory.php`, `app/Reports/Players/PlayerDemographics.php`
- Modify: `app/Services/Player/RegistrationCounts.php` (from Part 6), `app/Services/Player/DocumentStats.php`
- Modify: `app/Models/Team.php`, `app/Http/Controllers/CompetitionController.php`, `app/Http/Controllers/GameController.php`, `app/Http/Controllers/GameStatsController.php`, `app/Http/Controllers/GameCallUpController.php`, `app/Services/Competitions/MatchSheets.php`, `app/Reports/Concerns/FiltersGames.php`
- Create: `tests/Feature/Registrations/RepeatedCategoryNamesTest.php`

**Interfaces:**
- Consumes: `CategoryLabels`, `sportLabeller`.
- Produces: `Team::labelWith(CategoryLabels $labels): string`.

Parts 2 to 6 changed some of these queries. Keep what they did. Only the name lookup changes here.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/RepeatedCategoryNamesTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Category;
use App\Models\GameFormat;
use App\Models\Team;
use App\Models\User;
use App\Reports\Players\PlayerDirectory;
use App\Services\Player\RegistrationService;
use App\Support\CategoryLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class RepeatedCategoryNamesTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private Category $swimSenior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->swimSenior = Category::create(['name' => 'Senior', 'branch_id' => $this->swim->id]);
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
    }

    private function team(Category $category): Team
    {
        $format = GameFormat::create([
            'branch_id' => $category->branch_id, 'sport' => 'x', 'name' => 'F'.$category->id, 'players_on_field' => 1, 'max_bench' => 0, 'max_called_up' => 1,
            'periods_count' => 1, 'period_minutes' => 1, 'period_label' => 'half', 'scoring' => 'goals', 'sanction_types' => [],
        ]);

        return Team::create(['branch_id' => $category->branch_id, 'category_id' => $category->id, 'name' => 'First', 'default_game_format_id' => $format->id]);
    }

    #[Test]
    public function the_players_page_gets_each_categorys_sport(): void
    {
        $this->get(route('players.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categories', fn ($list) => collect($list)->firstWhere('id', $this->swimSenior->id)['branch']['name'] === 'Swimming'));
    }

    #[Test]
    public function report_filters_name_the_sport_when_the_name_repeats(): void
    {
        $filters = collect(app(PlayerDirectory::class)->filters());
        $category = $filters->firstWhere('key', 'category_id');

        $labels = collect($category->options ?? $category['options'])->pluck('label');

        $this->assertContains('Senior · Swimming', $labels);
        $this->assertContains('Senior · Football', $labels);
        $this->assertContains('U15', $labels);
    }

    #[Test]
    public function a_team_label_names_the_sport_only_when_the_category_repeats(): void
    {
        $labels = CategoryLabels::make();

        $this->assertSame('First · Senior · Swimming', $this->team($this->swimSenior)->labelWith($labels));
        $this->assertSame('First · U15', $this->team($this->u15)->labelWith($labels));
    }

    #[Test]
    public function a_team_with_a_name_ending_in_an_arabic_letter_is_not_cut(): void
    {
        $team = $this->team($this->u15);
        $team->name = 'نشاط';

        $this->assertSame('نشاط · U15', $team->labelWith(CategoryLabels::make()));
    }

    #[Test]
    public function category_counts_keep_same_named_categories_apart(): void
    {
        $service = app(RegistrationService::class);
        $service->register(\App\Models\Player::create(['firstname' => 'A', 'join_year' => 2020]), ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);
        $service->register(\App\Models\Player::create(['firstname' => 'B', 'join_year' => 2020]), ['branch_id' => $this->swim->id, 'category_id' => $this->swimSenior->id]);

        $names = \App\Services\Player\RegistrationCounts::byCategory(\App\Models\Player::query())->pluck('name');

        $this->assertContains('Senior · Football', $names);
        $this->assertContains('Senior · Swimming', $names);
    }
}
```

Before running, read the `Filter` class for how an option list is read (`grep -rl "class Filter" app/Reports`). The assertions on labels don't change.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/RepeatedCategoryNamesTest.php`
Expected: FAIL. `labelWith` doesn't exist, and labels lack the sport.

- [ ] **Step 3: Players list and queue**

In `app/Http/Controllers/PlayerController.php`, change the index's lazy prop:

```php
            'categories' => fn () => Category::with('branch:id,name,name_ar,name_fr,name_en')->orderBy('name')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en', 'branch_id']),
```

In `resources/js/Pages/Players/Index.vue`, add below `positionLabel`:

```js
const categoryLabel = computed(() => sportLabeller(props.categories, (c) => c.localized_name || c.name));
```

`positionLabel` is declared after `categoryOptions`, so move `categoryOptions` below this line. Change:

```js
const categoryOptions = computed(() => props.categories.map((c) => ({ value: c.id, label: categoryLabel.value(c) })));
```

The bulk edit options:

```js
        options: props.categories.map((c) => ({ value: c.id, label: categoryLabel.value(c) })),
```

The chips, following the position chips' pattern:

```js
const categoryChips = computed(() => props.categoryStats.map((s) => {
    const category = props.categories.find((c) => c.id === s.category_id);
    return { key: s.category_id ?? '', label: category ? categoryLabel.value(category) : (s.name || t('uncategorized')), count: s.count };
}));
```

Keep any other field Part 6 added to a chip.

In `app/Http/Controllers/JoinDateController.php`:

```php
            'categories' => Category::with('branch:id,name,name_ar,name_fr,name_en')->orderBy('name')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en', 'branch_id']),
```

In `resources/js/Pages/Players/JoinDates.vue`, add `import { sportLabeller } from '@/lib/sportLabels';`, then:

```js
const categoryLabel = computed(() => sportLabeller(props.categories, (c) => c.localized_name || c.name));
```

Add `computed` to its `vue` import if missing, and change the option text to `{{ categoryLabel(category) }}`.

- [ ] **Step 4: Report filters and charts**

Add `use App\Support\CategoryLabels;` to each file below and remove `use App\Models\Category;` if it is no longer used.

`app/Reports/Concerns/FiltersPlayers.php`:

```php
    protected function categoryFilter(): Filter
    {
        return Filter::multi('category_id', 'col.category', CategoryLabels::make()->options());
    }
```

`app/Reports/Attendance/SessionsOverview.php` and `app/Reports/Players/PlayerDirectory.php`, the filter line:

```php
            Filter::multi('category_id', 'col.category', CategoryLabels::make()->options()),
```

Directory's chart reads `RegistrationCounts::byCategory` since Part 6, so Step 5 labels it.

`app/Reports/Players/PlayerDemographics.php`, in `rows` as Part 6 left it, replace `$names = Category::all()->keyBy('id');` with `$labels = CategoryLabels::make();` and:

```php
            'category' => $labels->of($categoryId) ?? UiLang::get('reports.none'),
```

- [ ] **Step 5: Dashboard charts**

Since Part 6, the Players chips' server rows, Directory's chart, and the dashboard's `byCategory` and `leftByCategory` all read `RegistrationCounts::byCategory`, so one change labels them. In `app/Services/Player/RegistrationCounts.php` (Part 6), add `use App\Support\CategoryLabels;` and change the method:

```php
    /** @return Collection<int, object{key: int|null, name: string|null, total: int}> */
    public static function byCategory(EloquentBuilder|QueryBuilder $players, ?array $branches = null, bool $includeLeft = false): Collection
    {
        $labels = CategoryLabels::make();

        return self::grouped(self::rows($players, $branches, $includeLeft), 'categories', 'category_id')
            ->map(function (object $row) use ($labels) {
                $row->name = $labels->of($row->key) ?? $row->name;

                return $row;
            });
    }
```

Grouping is by category ID, so same-named categories are already separate rows. If `leftByCategory` in `MemberStats` still builds its own names, read them from `$labels->of(...)` the same way.

`app/Services/Player/DocumentStats.php`, in the `byCategory` map:

```php
            'name' => $labels->of($row->category_id) ?? ($row->category_name ?: null),
```

with `$labels = CategoryLabels::make();` above the query.

- [ ] **Step 6: Team labels**

In `app/Models/Team.php`, add `use App\Support\CategoryLabels;` and:

```php
    /** "Team · Category", with the sport added when another sport repeats the category's name. */
    public function labelWith(CategoryLabels $labels): string
    {
        $category = $labels->of($this->category_id);

        return $category === null ? $this->name : $this->name.' · '.$category;
    }
```

This also replaces the byte-wise `trim(..., ' ·')` in `FiltersGames`, which could cut an Arabic name ending in `ط` because `·` and `ط` share the byte `B7`.

Replace each site, with `$labels = CategoryLabels::make();` once above the statement that builds the list:

| File | Old | New |
|---|---|---|
| `app/Http/Controllers/CompetitionController.php:51` | `$c->team->name.' · '.$c->team->category?->localized_name` | `$c->team->labelWith($labels)` |
| `app/Http/Controllers/CompetitionController.php:56` | `$t->name.' · '.$t->category?->localized_name` | `$t->labelWith($labels)` |
| `app/Http/Controllers/GameController.php:76` | `$t->name.' · '.$t->category?->localized_name` | `$t->labelWith($labels)` |
| `app/Http/Controllers/GameStatsController.php:34` | `$t->name.' · '.$t->category?->localized_name` | `$t->labelWith($labels)` |
| `app/Http/Controllers/GameCallUpController.php:167` | `$game->team->name.' · '.$game->team->category?->localized_name` | `$game->team->labelWith(CategoryLabels::make())` |
| `app/Services/Competitions/MatchSheets.php:105` | `$game->team->name.' · '.$game->team->category?->localized_name` | `$game->team->labelWith(CategoryLabels::make())` |
| `app/Reports/Concerns/FiltersGames.php:21` | `trim($t->name.' · '.$t->category?->localized_name, ' ·')` | `$t->labelWith($labels)` |

Add `use App\Support\CategoryLabels;` to each. In closures, add `$labels` to the `use` list or use `fn`, which captures it. `Team::query()->with('category')` in those queries may stay; nothing breaks. Remove it where it was only for the label.

- [ ] **Step 7: Run the tests**

```bash
php artisan test tests/Feature/Registrations/RepeatedCategoryNamesTest.php
php artisan config:clear && php artisan test 2>&1 | tail -15
npm run build 2>&1 | tail -3
```

Expected: all PASS. Tests with one category of a name see no change in label.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/PlayerController.php resources/js/Pages/Players/Index.vue app/Http/Controllers/JoinDateController.php resources/js/Pages/Players/JoinDates.vue app/Reports/Concerns/FiltersPlayers.php app/Reports/Attendance/SessionsOverview.php app/Reports/Players/PlayerDirectory.php app/Reports/Players/PlayerDemographics.php app/Services/Player/RegistrationCounts.php app/Services/Player/DocumentStats.php app/Models/Team.php app/Http/Controllers/CompetitionController.php app/Http/Controllers/GameController.php app/Http/Controllers/GameStatsController.php app/Http/Controllers/GameCallUpController.php app/Services/Competitions/MatchSheets.php app/Reports/Concerns/FiltersGames.php tests/Feature/Registrations/RepeatedCategoryNamesTest.php
git commit -m "feat(categories): name the sport when a category name repeats

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P7.8: Excel import looks up a category within the row's branch

**Files:**
- Modify: `app/Http/Controllers/PlayerImportController.php`
- Modify: `lang/ar/validation.php`, `lang/en/validation.php`, `lang/fr/validation.php`
- Modify: `tests/Feature/Registrations/PlayerImportRegistrationTest.php`

**Interfaces:**
- Consumes: `categories.branch_id`.
- Produces: `validation.category_not_in_branch` with `:name`. A row's category name is looked up within its branch. A row with no branch uses the main branch. A name that branch lacks is a row error, and no category is ever created.

- [ ] **Step 1: Write the failing tests**

Read the top of `tests/Feature/Registrations/PlayerImportRegistrationTest.php` for how it builds a file and posts it, then add these with the same helper:

```php
    #[Test]
    public function a_category_name_is_looked_up_within_the_rows_branch(): void
    {
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        Category::create(['name' => 'Senior', 'branch_id' => $foot->id]);
        $swimSenior = Category::create(['name' => 'Senior', 'branch_id' => $swim->id]);

        $this->importRows([['firstname' => 'Ali', 'lastname' => 'B', 'category' => 'senior', 'branch' => 'Swimming', 'join_year' => '2020']]);

        $this->assertSame($swimSenior->id, Player::where('firstname', 'Ali')->firstOrFail()->registrationIn($swim->id)->category_id);
    }

    #[Test]
    public function a_row_with_no_branch_uses_the_main_branchs_category(): void
    {
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        $senior = Category::create(['name' => 'Senior', 'branch_id' => $foot->id]);
        Category::create(['name' => 'Senior', 'branch_id' => $swim->id]);

        $this->importRows([['firstname' => 'Ali', 'lastname' => 'B', 'category' => 'Senior', 'join_year' => '2020']]);

        $this->assertSame($senior->id, Player::where('firstname', 'Ali')->firstOrFail()->registrationIn($foot->id)->category_id);
    }

    #[Test]
    public function a_name_the_branch_does_not_have_is_a_row_error_and_nothing_is_created(): void
    {
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        Category::create(['name' => 'Ecole', 'branch_id' => $foot->id]);

        $response = $this->importRows([['firstname' => 'Ali', 'lastname' => 'B', 'category' => 'Ecole', 'branch' => 'Swimming', 'join_year' => '2020']]);

        $this->assertSame(0, Player::where('firstname', 'Ali')->count());
        $this->assertSame(1, Category::where('name', 'Ecole')->count());
        $this->assertStringContainsString('Ecole', json_encode(session()->all(), JSON_UNESCAPED_UNICODE));
        $response->assertRedirect();
    }

    #[Test]
    public function an_empty_category_cell_is_fine(): void
    {
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);

        $this->importRows([['firstname' => 'Ali', 'lastname' => 'B', 'category' => '', 'join_year' => '2020']]);

        $this->assertNull(Player::where('firstname', 'Ali')->firstOrFail()->registrationIn($foot->id)->category_id);
    }

    #[Test]
    public function with_no_branches_any_category_name_still_matches_and_an_unknown_one_stays_blank(): void
    {
        $senior = Category::create(['name' => 'Senior']);

        $this->importRows([
            ['firstname' => 'Ali', 'lastname' => 'B', 'category' => 'Senior', 'join_year' => '2020'],
            ['firstname' => 'Omar', 'lastname' => 'B', 'category' => 'Nothing', 'join_year' => '2020'],
        ]);

        $this->assertSame($senior->id, Player::where('firstname', 'Ali')->value('category_id'));
        $this->assertNull(Player::where('firstname', 'Omar')->value('category_id'));
    }
```

If the test file's helper has another name or shape than `importRows`, use it and keep the assertions.

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test tests/Feature/Registrations/PlayerImportRegistrationTest.php`
Expected: the new tests on branch lookup and the row error FAIL.

- [ ] **Step 3: Add the message**

```php
// lang/en/validation.php
    'category_not_in_branch' => 'The category ":name" does not exist in this branch.',
// lang/fr/validation.php
    'category_not_in_branch' => 'La catégorie « :name » n\'existe pas dans cette section.',
// lang/ar/validation.php
    'category_not_in_branch' => 'الفئة «:name» غير موجودة في هذا الفرع.',
```

- [ ] **Step 4: Change the import**

In `app/Http/Controllers/PlayerImportController.php`, replace the `$categories` build:

```php
        // Match a category by any of its names (base + per-locale), case-insensitively, within
        // its branch. Key 0 holds categories with no owner: a club with no branches.
        $categories = [];
        foreach (Category::all(['id', 'name', 'name_ar', 'name_fr', 'name_en', 'branch_id']) as $category) {
            foreach ([$category->name, $category->name_ar, $category->name_fr, $category->name_en] as $variant) {
                if ($variant !== null && $variant !== '') {
                    $categories[(int) $category->branch_id][mb_strtolower(trim($variant))] = $category->id;
                }
            }
        }
```

In the row's `$attributes`, change `category_id` to:

```php
                    'category_id' => null, // resolved below, once the card's branch is known
```

Right after `$attributes['position_id'] = $this->resolvePosition(...)`:

```php
                $attributes['category_id'] = $this->resolveCategory($categories, $data['category'] ?? null, $branchId);
```

Add above `resolvePosition`'s docblock:

```php
    /**
     * With branches, a name is read within the row's branch, and one that branch
     * lacks is a row error: nothing is ever created silently. With no branches an
     * unknown name stays blank, as it always has.
     *
     * @param  array<int, array<string, int>>  $categories  branch id (0: no owner) => name => category id
     *
     * @throws RuntimeException when the branch has no such category
     */
    private function resolveCategory(array $categories, ?string $cell, ?int $branchId): ?int
    {
        $name = mb_strtolower(trim((string) $cell));
        if ($name === '') {
            return null;
        }

        $id = ($branchId === null ? null : ($categories[$branchId][$name] ?? null)) ?? ($categories[0][$name] ?? null);
        if ($id === null && $branchId !== null) {
            throw new RuntimeException(__('validation.category_not_in_branch', ['name' => trim((string) $cell)]));
        }

        return $id;
    }
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/Registrations/PlayerImportRegistrationTest.php tests/Feature/PlayerImportTest.php`
Expected: all PASS. If `PlayerImportTest` doesn't exist, run `php artisan test --filter=Import`. An existing test that imports a category name its branch lacks should create that category with `branch_id` first. Fix it that way, because the new row error is the intended behaviour.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/PlayerImportController.php lang/ar/validation.php lang/en/validation.php lang/fr/validation.php tests/Feature/Registrations/PlayerImportRegistrationTest.php
git commit -m "feat(import): read a category name within the row's branch

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P7.9: `registrations:verify` checks categories and prices

**Files:**
- Modify: `app/Console/Commands/VerifyRegistrations.php`
- Modify: `tests/Feature/Registrations/VerifyRegistrationsTest.php`

**Interfaces:**
- Produces: failures labelled `category with no branch`, `category of another branch`, `same-named categories priced differently`, `same-named category with no price row`.

- [ ] **Step 1: Write the failing tests**

Add `use App\Models\Branch; use App\Models\Category; use App\Models\Subscription; use App\Models\TrainingSession;` to `tests/Feature/Registrations/VerifyRegistrationsTest.php` where missing, and add:

```php
    private function club(): array
    {
        $foot = Branch::firstOrCreate(['name' => 'Football'], ['code' => 'FB']);
        $swim = Branch::firstOrCreate(['name' => 'Swimming'], ['code' => 'SW']);

        return [$foot, $swim, Category::create(['name' => 'Senior', 'branch_id' => $foot->id]), Category::create(['name' => 'Senior', 'branch_id' => $swim->id])];
    }

    #[Test]
    public function an_unowned_category_fails_once_a_branch_exists(): void
    {
        $this->club();
        Category::create(['name' => 'Orphan']);

        $this->artisan('registrations:verify')->expectsOutputToContain('category with no branch')->assertExitCode(1);
    }

    #[Test]
    public function an_unowned_category_is_fine_with_no_branches(): void
    {
        Category::create(['name' => 'Senior']);

        $this->artisan('registrations:verify')->assertExitCode(0);
    }

    #[Test]
    public function a_registrations_category_of_another_branch_fails(): void
    {
        [$foot, , , $swimSenior] = $this->club();
        $player = Player::create(['firstname' => 'A', 'join_year' => 2020]);
        app(\App\Services\Player\RegistrationService::class)->register($player, ['branch_id' => $foot->id, 'category_id' => $swimSenior->id]);

        $this->artisan('registrations:verify')->expectsOutputToContain('category of another branch')->assertExitCode(1);
    }

    #[Test]
    public function a_session_pivot_category_of_another_branch_fails(): void
    {
        [$foot, , $senior, $swimSenior] = $this->club();
        $session = TrainingSession::create(['branch_id' => $foot->id, 'category_id' => $senior->id, 'date' => '2026-03-02', 'start_time' => '18:00', 'end_time' => '19:00', 'kind' => 'preseason', 'state' => 'held']);
        $session->categories()->syncWithoutDetaching([$senior->id, $swimSenior->id]);

        $this->artisan('registrations:verify')->expectsOutputToContain('category of another branch')->assertExitCode(1);
    }

    #[Test]
    public function same_named_categories_priced_differently_fail(): void
    {
        [, , $senior, $swimSenior] = $this->club();
        $subscription = Subscription::create(['name' => 'S', 'year' => 2026, 'amount_student' => 1, 'amount_worker' => 1]);
        $subscription->categories()->attach($senior->id, ['amount_student' => 100, 'amount_worker' => 200]);
        $subscription->categories()->attach($swimSenior->id, ['amount_student' => 150, 'amount_worker' => 200]);

        $this->artisan('registrations:verify')->expectsOutputToContain('same-named categories priced differently')->assertExitCode(1);
    }

    #[Test]
    public function a_copy_with_no_price_row_fails_where_the_subscription_applies(): void
    {
        [$foot, $swim, $senior] = $this->club();
        $subscription = Subscription::create(['name' => 'S', 'year' => 2026, 'amount_student' => 1, 'amount_worker' => 1]);
        $subscription->categories()->attach($senior->id, ['amount_student' => 100, 'amount_worker' => 200]);
        $subscription->branches()->attach([$foot->id, $swim->id]);

        $this->artisan('registrations:verify')->expectsOutputToContain('same-named category with no price row')->assertExitCode(1);
    }

    #[Test]
    public function a_copy_with_no_price_row_is_fine_where_the_subscription_does_not_apply(): void
    {
        [$foot, , $senior] = $this->club();
        $subscription = Subscription::create(['name' => 'S', 'year' => 2026, 'amount_student' => 1, 'amount_worker' => 1]);
        $subscription->categories()->attach($senior->id, ['amount_student' => 100, 'amount_worker' => 200]);
        $subscription->branches()->attach([$foot->id]);

        $this->artisan('registrations:verify')->assertExitCode(0);
    }

    #[Test]
    public function matching_prices_pass(): void
    {
        [$foot, $swim, $senior, $swimSenior] = $this->club();
        $subscription = Subscription::create(['name' => 'S', 'year' => 2026, 'amount_student' => 1, 'amount_worker' => 1]);
        $subscription->categories()->attach($senior->id, ['amount_student' => 100, 'amount_worker' => 200]);
        $subscription->categories()->attach($swimSenior->id, ['amount_student' => 100, 'amount_worker' => 200]);
        $subscription->branches()->attach([$foot->id, $swim->id]);

        $this->artisan('registrations:verify')->assertExitCode(0);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test tests/Feature/Registrations/VerifyRegistrationsTest.php`
Expected: the new failure tests FAIL because the exit code is 0.

- [ ] **Step 3: Add the checks**

In `app/Console/Commands/VerifyRegistrations.php`, add `use App\Models\Category;`. Add before the final `$this->line($failures === 0 ...`:

```php
        $failures += $this->report('category with no branch', $this->unownedCategories());
        $failures += $this->report('category of another branch', $this->misplacedCategories());
        [$priced, $unpriced] = $this->priceProblems();
        $failures += $this->report('same-named categories priced differently (expected only after someone sets a price per branch)', $priced);
        $failures += $this->report('same-named category with no price row (expected only after someone removes one)', $unpriced);
```

Add before `report`:

```php
    /** @return list<string> */
    private function unownedCategories(): array
    {
        if (! DB::table('branches')->exists()) {
            return [];
        }

        return Category::whereNull('branch_id')->pluck('name')->all();
    }

    /** @return list<string> */
    private function misplacedCategories(): array
    {
        $problems = [];
        foreach (['branch_player' => 'registration', 'training_sessions' => 'session', 'training_schedules' => 'schedule', 'teams' => 'team', 'finance_accounts' => 'caisse'] as $table => $label) {
            $ids = DB::table($table)->join('categories', 'categories.id', '=', "$table.category_id")
                ->whereNotNull("$table.branch_id")->whereNotNull('categories.branch_id')
                ->whereColumn('categories.branch_id', '!=', "$table.branch_id")
                ->pluck("$table.id");
            foreach ($ids as $id) {
                $problems[] = "$label $id";
            }
        }

        $joint = DB::table('training_session_category')
            ->join('training_sessions', 'training_sessions.id', '=', 'training_session_category.training_session_id')
            ->join('categories', 'categories.id', '=', 'training_session_category.category_id')
            ->whereNotNull('training_sessions.branch_id')->whereNotNull('categories.branch_id')
            ->whereColumn('categories.branch_id', '!=', 'training_sessions.branch_id')
            ->pluck('training_session_category.training_session_id');
        foreach ($joint as $id) {
            $problems[] = "session $id (joint)";
        }

        return $problems;
    }

    /**
     * After the split, same-named categories of a subscription hold the same amounts,
     * and each has a row in every branch the subscription applies to, so no amount changed.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function priceProblems(): array
    {
        $rows = DB::table('category_subscription')
            ->join('categories', 'categories.id', '=', 'category_subscription.category_id')
            ->get(['category_subscription.subscription_id', 'category_subscription.category_id', 'category_subscription.amount_student', 'category_subscription.amount_worker', 'categories.name']);
        $sameName = Category::query()->get(['id', 'name', 'branch_id'])->groupBy('name')->filter(fn ($group) => $group->count() > 1);
        $ticked = DB::table('branch_subscription')->get()->groupBy('subscription_id');

        $priced = [];
        $unpriced = [];
        foreach ($rows->groupBy(fn ($row) => $row->subscription_id.'|'.$row->name) as $group) {
            $first = $group->first();
            if ($group->unique(fn ($row) => (float) $row->amount_student.'|'.(float) $row->amount_worker)->count() > 1) {
                $priced[] = "subscription {$first->subscription_id}: {$first->name}";
            }

            $branches = collect($ticked->get($first->subscription_id, []))->pluck('branch_id')->map(fn ($id) => (int) $id);
            $missing = collect($sameName->get($first->name, []))
                ->reject(fn (Category $category) => $group->contains('category_id', $category->id))
                ->filter(fn (Category $category) => $branches->isEmpty() || $branches->contains((int) $category->branch_id));
            foreach ($missing as $category) {
                $unpriced[] = "subscription {$first->subscription_id}: {$first->name}, category {$category->id}";
            }
        }

        return [$priced, $unpriced];
    }
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test tests/Feature/Registrations/VerifyRegistrationsTest.php tests/Feature/Registrations/SplitCategoriesMigrationTest.php`
Expected: all PASS, and the split migration leaves verify at exit 0.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/VerifyRegistrations.php tests/Feature/Registrations/VerifyRegistrationsTest.php
git commit -m "feat(registrations): verify checks category owners and prices

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P7.10: Closing check of Part 7

**Files:**
- Create: `.superpowers/r2/diff-split.php`
- Modify: `.superpowers/r2/progress.md`

- [ ] **Step 1: Run every check**

```bash
php artisan config:clear && php artisan test 2>&1 | tail -10
npm run test:js 2>&1 | tail -3
npm run i18n:check
npm run build 2>&1 | tail -3
```

Expected: only `ManualSuspensionsTest` may fail.

- [ ] **Step 2: Write a comparison that reads category names, not IDs**

The split moves swimming's IDs on purpose, including those of one-branch swimmers, whose mirror follows. `diff-figures` compares IDs and reports those as `UNEXPECTED`. This script proves the names didn't change. `.superpowers/r2/diff-split.php`:

```php
<?php

// Compares two SQLite copies, player by player, on category NAME per branch.
$open = fn (string $file) => new PDO('sqlite:'.__DIR__.'/data/'.$file, null, null, [
    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$read = function (PDO $db) {
    $rows = $db->query('SELECT r.player_id, r.branch_id, c.name FROM branch_player r LEFT JOIN categories c ON c.id = r.category_id ORDER BY r.player_id, r.branch_id')->fetchAll();
    $mirror = $db->query('SELECT p.id, c.name FROM players p LEFT JOIN categories c ON c.id = p.category_id ORDER BY p.id')->fetchAll();
    $prices = $db->query('SELECT s.subscription_id, c.name, s.amount_student, s.amount_worker FROM category_subscription s JOIN categories c ON c.id = s.category_id GROUP BY s.subscription_id, c.name, s.amount_student, s.amount_worker ORDER BY s.subscription_id, c.name')->fetchAll();

    return compact('rows', 'mirror', 'prices');
};
[$a, $b] = [$read($open($argv[1])), $read($open($argv[2]))];
$bad = 0;
foreach ($a as $what => $rows) {
    if ($rows !== $b[$what]) {
        echo "DIFFERS: $what\n";
        foreach (array_udiff($rows, $b[$what], fn ($x, $y) => $x <=> $y) as $row) {
            echo '  before: '.json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
        }
        foreach (array_udiff($b[$what], $rows, fn ($x, $y) => $x <=> $y) as $row) {
            echo '  after:  '.json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
        }
        $bad++;
    } else {
        echo "same: $what\n";
    }
}
foreach ($open($argv[2])->query('SELECT b.name AS branch, c.name, COUNT(r.id) AS registrations FROM categories c LEFT JOIN branches b ON b.id = c.branch_id LEFT JOIN branch_player r ON r.category_id = c.id GROUP BY b.name, c.name ORDER BY b.name, c.name')->fetchAll() as $row) {
    echo str_pad((string) $row['branch'], 20).str_pad((string) $row['name'], 20).$row['registrations']."\n";
}
exit($bad > 0 ? 1 : 0);
```

- [ ] **Step 3: Run the migration on the real-data copy, twice**

```bash
cd /d/irnb-branch-reg-r2
cp .superpowers/r2/data/real.sqlite .superpowers/r2/data/before-split.sqlite
( . .superpowers/r2/env-sqlite.sh
  php artisan config:show database.connections.sqlite.database
  php artisan migrate --force
  php artisan registrations:verify
  php artisan migrate:rollback --force --step=1
  php artisan migrate --force
  php artisan registrations:verify
  php .superpowers/r2/figures.php p7
  php .superpowers/r2/sweep.php p7 | tail -5 )
php .superpowers/r2/diff-split.php before-split.sqlite real.sqlite
php .superpowers/r2/diff-figures.php p6 p7
php .superpowers/r2/diff-sweep.php p6 p7 > .superpowers/r2/data/sweep-p7.txt
```

Expected, using the test-copy figures from spec section 2:

- `config:show` prints the `.superpowers/r2/data/real.sqlite` path. **Stop if it prints anything else.** `real.sqlite` must already hold Part 6's state, and `figures-p6.json` and `sweep-p6.json` must exist from Part 6's closing step. If Part 6 used another label, use that.
- Both `registrations:verify` runs exit 0.
- `diff-split`: `same: rows`, `same: mirror`, `same: prices`. The category table shows swimming with exactly Senior 18 and Junior 1, football with Senior 93, Ecole 37, Minime 32, Cadet 29, Junior 14. Re-check against the copy's actual numbers, because the owner's test copy may have moved since 2026-11-05.
- `diff-figures`: **`player_subscriptions` and `transactions` identical. Any line on them is a stop.** `eligible` and `rosters` lists identical for every subscription and session. `player_mirror` lines are expected only on `category_id`, for players whose main registration is in swimming, and each must match a `same: mirror` above. `diff-figures` calls these `UNEXPECTED` for one-branch swimmers and exits 1, so read every line rather than the exit code.
- `sweep`: no 5xx. `diff-sweep` differences are category IDs on swimming rows, and category labels gaining " · Sport" for the two repeated names: Senior and Junior.
- The one football team stays on football's Minime; the football Senior caisse stays; the swimming Senior caisse now points at swimming's Senior copy. No caisse was created: compare `SELECT COUNT(*) FROM finance_accounts` before and after.

Record the output in `.superpowers/r2/progress.md`. Part 8 repeats this on the desktop database, the backup, MariaDB and PostgreSQL.

- [ ] **Step 4: Self-review against spec section 11**

Tick each against the code, and fix anything missing before moving to Part 8:

- [ ] Schema: `branch_id`, `is_active`, unique (`branch_id`, `name`); no `sort_order`.
- [ ] Migration steps 1 to 5, can run twice, `down` merges by name.
- [ ] Ownership enforced on registrations, sessions (all categories of a joint session), schedules, targets, teams, caisses, subscriptions, activities. Stored value stays. No-branch session takes any.
- [ ] `Settings/Branches.vue` is a list linking to the page; tabs General and Categories; tab shows only with `categories.view`.
- [ ] `/categories` redirects; with no branches it stays. Add, edit, deactivate, delete on the tab; delete keeps its refusals; a category can't move.
- [ ] Deleting a branch: refused with registrations, unused categories go, used ones refuse.
- [ ] Pickers: registration card, attendance, sessions, schedules, teams, caisses list only that branch's plus the stored value; calendar default is the first of the branch; subscription form follows ticked branches; a branch with no categories hides the field.
- [ ] Repeated names: Players filter and bulk edit, report filters, chips, charts, team labels. `positionLabels.js` is now `sportLabels.js`, used for both.
- [ ] Excel: lookup within the row's branch, main branch for no branch, row error for a missing name.
- [ ] New clubs: `CategorySeeder` seeds with no owner, and the first branch takes them all.
- [ ] `registrations:verify`: every category has a branch once any exists; registrations, sessions, schedules, teams, caisses fit; price rows give the same amounts.
- [ ] Everything runs on SQLite here; MariaDB and PostgreSQL are run by Part 8.
