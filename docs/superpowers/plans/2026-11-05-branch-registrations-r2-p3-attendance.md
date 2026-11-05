# Branch registrations, Release 2, Part 3: Attendance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make rosters, new marks, category tags, the at-risk list, injuries, ranking, certificates and the player's attendance card read the registration in the branch being viewed. Saved marks stay frozen.

**Architecture:** `Roster::expected` moves to `Player::registeredWhere`, with one small addition to that scope so the roster's status-set rule sits on the same registration row. `PlayerNames::attach` takes the branch being viewed and reads its registration through a join, so it costs no extra query. Everything that shows a category or decides "active" flows from those two, plus `RegistrationDisplay` for model-based pages.

**Tech Stack:** Laravel 13, Eloquent, PHPUnit attribute tests, Blade PDF views. No Vue or i18n change. Spec: `docs/superpowers/specs/2026-11-05-branch-registrations-r2-design.md`, section 6.

## Global Constraints

See the main plan `2026-11-05-branch-registrations-r2.md`, which apply in full. Parts 0 to 2 are done. Part-specific:

- **Leave day counts.** A player is listed on unmarked sessions up to and including the leave date, as `activeOn` defines it and the owner chose ("rosters until the leave date"). Today's code stops the day before. This changes one existing test and can change one kind of figure; see Task P3.1 and Task P3.7.
- Saved marks never change. `Roster::forSession` keeps preferring them, and it keeps its name because `figures.php` calls it.
- No per-row query. `PlayerNames` reads plain rows on purpose (see its class doc), and its two-query cost is pinned by tests that must keep passing unchanged: `AttendanceBranchAlertsTest::any_branch_costs_a_fixed_number_of_queries`, `AttendanceAtRiskTest`'s six queries, `AttendanceInjurySpellsTest`'s five queries, `AttendanceBranchInjuriesTest::the_branch_adds_no_query_to_the_club_list`.
- **Checked, no change needed:** `AttendanceStats`, `PreseasonProgress`, `CalendarFeed`, `SessionsOverview`, `AttendanceCard`, `TrainingCanceller`, `SessionGenerator`, `GenerationMarks`. Their `category_id` is the session's or the mark's, never a player's. `AttendanceGridController` and `AttendanceAlertsController` and `AttendanceInjuryController` change through `Roster`, `AtRisk` and `InjurySpells` only.
- The Part 6 item "`attendance-player` PDF follows the branch being viewed" is done here, in Task P3.5, because this controller already knows the tab.

---

### Task P3.1: The roster reads one registration

**Files:**
- Create: `tests/Support/RegistrationSessions.php`
- Create: `tests/Feature/Registrations/RosterRegistrationTest.php`
- Modify: `app/Models/Player.php` (`scopeRegisteredWhere`)
- Modify: `app/Services/Attendance/Roster.php`
- Modify: `tests/Feature/MarkRecorderTest.php` (one test, its rule changed)

**Interfaces:**
- Consumes: `Player::scopeRegisteredWhere`, `BranchRegistration` scopes, `RegistrationFixtures` (Part 1).
- Produces: a new last argument on the scope, `?Closure $also = null`, called as `$also($query, string $statusColumn, string $leftAtColumn)` on both the registration row and a no-registration player's own columns. Existing callers are unaffected.
- Produces for tests: `RegistrationSessions::sessionIn(?Branch $branch, array $categories, string $date = '2026-03-10', array $extra = []): TrainingSession` and `markOn(TrainingSession $session, Player $player, AttendanceStatus $status, ?string $reason = null): void`.

- [ ] **Step 1: Write the test helper**

`tests/Support/RegistrationSessions.php`:

```php
<?php

namespace Tests\Support;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;

/** Sessions and marks for the attendance tests that use RegistrationFixtures. */
trait RegistrationSessions
{
    /** @param  list<Category>  $categories  the first is the primary; more make a joint session */
    private function sessionIn(?Branch $branch, array $categories, string $date = '2026-03-10', array $extra = []): TrainingSession
    {
        $session = TrainingSession::create($extra + [
            'branch_id' => $branch?->id, 'category_id' => $categories[0]->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => count($categories) > 1 ? SessionKind::Preseason : SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
        if (count($categories) > 1) {
            $session->categories()->syncWithoutDetaching(array_map(fn (Category $c) => $c->id, $categories));
        }

        return $session;
    }

    private function markOn(TrainingSession $session, Player $player, AttendanceStatus $status, ?string $reason = null): void
    {
        Attendance::create([
            'training_session_id' => $session->id, 'player_id' => $player->id, 'category_id' => $session->category_id,
            'status' => $status, 'reason' => $reason,
        ]);
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/Registrations/RosterRegistrationTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Enums\AttendanceStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Services\Attendance\Roster;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\Support\RegistrationSessions;
use Tests\TestCase;

class RosterRegistrationTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures, RegistrationSessions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    /** @return list<int> */
    private function expected(?Branch $branch, Category|array $categories, string $date = '2026-03-10', ?array $statusIds = null): array
    {
        $ids = array_map(fn (Category $c) => $c->id, is_array($categories) ? $categories : [$categories]);

        return app(Roster::class)->expected($ids, $date, $statusIds, $branch?->id)->modelKeys();
    }

    private function statusId(string $code): int
    {
        return (int) PlayerStatus::where('code', $code)->value('id');
    }

    #[Test]
    public function a_branch_session_reads_that_branchs_category(): void
    {
        $player = $this->bothActive();

        $this->assertSame([$player->id], $this->expected($this->foot, $this->senior));
        $this->assertSame([], $this->expected($this->foot, $this->u15));
        $this->assertSame([$player->id], $this->expected($this->swim, $this->u15));
        $this->assertSame([], $this->expected($this->swim, $this->senior));
    }

    #[Test]
    public function a_session_with_no_branch_matches_any_registration_and_lists_a_player_once(): void
    {
        $player = $this->bothActive();

        $this->assertSame([$player->id], $this->expected(null, $this->senior));
        $this->assertSame([$player->id], $this->expected(null, $this->u15));
        $this->assertSame([$player->id], $this->expected(null, [$this->senior, $this->u15]));
    }

    #[Test]
    public function a_left_branch_lists_the_player_up_to_the_leave_date_and_not_after(): void
    {
        $player = $this->leftFootball();

        $this->assertSame([$player->id], $this->expected($this->foot, $this->senior, '2026-03-14'));
        $this->assertSame([$player->id], $this->expected($this->foot, $this->senior, $this->leaveDate));
        $this->assertSame([], $this->expected($this->foot, $this->senior, '2026-03-16'));
        // The other sport goes on.
        $this->assertSame([$player->id], $this->expected($this->swim, $this->u15, '2026-03-16'));
    }

    #[Test]
    public function left_everywhere_lists_them_nowhere_after_the_date(): void
    {
        $player = $this->leftBoth();

        $this->assertSame([$player->id], $this->expected($this->foot, $this->senior, $this->leaveDate));
        $this->assertSame([$player->id], $this->expected($this->swim, $this->u15, $this->leaveDate));
        $this->assertSame([], $this->expected($this->foot, $this->senior, '2026-03-16'));
        $this->assertSame([], $this->expected($this->swim, $this->u15, '2026-03-16'));
        $this->assertSame([], $this->expected(null, $this->senior, '2026-03-16'));
    }

    #[Test]
    public function no_registration_uses_the_players_columns_and_has_no_branch(): void
    {
        $flat = $this->noRegistration();

        $this->assertSame([$flat->id], $this->expected(null, $this->senior));
        $this->assertSame([], $this->expected(null, $this->u15));
        $this->assertSame([], $this->expected($this->foot, $this->senior));
    }

    #[Test]
    public function the_status_set_applies_to_the_row_in_that_branch(): void
    {
        $player = $this->bothActive();
        $paused = $this->statusId('paused');
        app(RegistrationService::class)->setField($player, 'status_id', $paused, $this->foot->id);

        // Default set: registered.
        $this->assertSame([], $this->expected($this->foot, $this->senior));
        $this->assertSame([$player->id], $this->expected($this->swim, $this->u15));
        // A session's own set.
        $this->assertSame([$player->id], $this->expected($this->foot, $this->senior, '2026-03-10', [$paused]));
        $this->assertSame([], $this->expected($this->swim, $this->u15, '2026-03-10', [$paused]));
    }

    #[Test]
    public function a_row_with_no_status_counts_as_registered(): void
    {
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->foot->id)->update(['status_id' => null]);

        $this->assertSame([$player->id], $this->expected($this->foot, $this->senior));
    }

    #[Test]
    public function a_legacy_left_row_with_no_date_never_counts(): void
    {
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->foot->id)
            ->update(['status_id' => $this->statusId('left'), 'left_at' => null]);

        $this->assertSame([], $this->expected($this->foot, $this->senior));
        $this->assertSame([$player->id], $this->expected($this->swim, $this->u15));
    }

    #[Test]
    public function an_archived_player_is_not_expected(): void
    {
        $player = $this->bothActive();
        $player->update(['archived' => true]);

        $this->assertSame([], $this->expected($this->foot, $this->senior));
    }

    #[Test]
    public function the_mirror_decides_nothing_for_a_registered_player(): void
    {
        $player = $this->bothActive();
        DB::table('players')->where('id', $player->id)->update(['category_id' => $this->u15->id]);

        $this->assertSame([$player->id], $this->expected($this->foot, $this->senior));
        $this->assertSame([], $this->expected($this->foot, $this->u15));
    }

    #[Test]
    public function a_marked_session_stays_frozen_after_leaving(): void
    {
        $player = $this->bothActive();
        $session = $this->sessionIn($this->foot, [$this->senior], '2026-03-20');
        $this->markOn($session, $player, AttendanceStatus::Present);

        app(RegistrationService::class)->leave($player, [$this->foot->id], $this->leaveDate);

        $this->assertSame([$player->id], app(Roster::class)->forSession($session->fresh())->modelKeys());
    }

    #[Test]
    public function an_unmarked_session_after_leaving_drops_them(): void
    {
        $player = $this->leftFootball();
        $stays = Player::create(['firstname' => 'Stays', 'lastname' => 'Test', 'join_year' => 2020]);
        app(RegistrationService::class)->register($stays, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);
        $session = $this->sessionIn($this->foot, [$this->senior], '2026-03-20');

        $ids = app(Roster::class)->forSession($session)->modelKeys();

        $this->assertSame([$stays->id], $ids);
        $this->assertNotContains($player->id, $ids);
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan config:clear && php artisan test tests/Feature/Registrations/RosterRegistrationTest.php`
Expected: FAIL. `a_branch_session_reads_that_branchs_category` fails on swimming + U15, because today's roster reads the mirror's Senior.

- [ ] **Step 4: Let the shared query carry an extra condition on the same row**

In `app/Models/Player.php`, add `use Closure;` to the imports. Change the scope's signature and body:

```php
    /**
     * The one answer to "is this player in this branch, with this category and
     * status, on this date": all conditions hold on the same registration. A
     * player with no registration at all is judged on their own columns, and has
     * no branch. Null means no condition; an empty list matches nothing, so a
     * computed list can never silently widen to everyone.
     *
     * $also adds a rule of the caller's to that same row, or to a player's own
     * columns: called with the query and the status and leave-date column names.
     */
    public function scopeRegisteredWhere(
        Builder $query,
        iterable|int|null $branches = null,
        iterable|int|null $categories = null,
        iterable|int|null $statuses = null,
        CarbonInterface|string|null $activeOn = null,
        bool $active = false,
        ?Closure $also = null,
    ): void {
        $playerKey = $query->getModel()->qualifyColumn('id');

        $rows = BranchRegistration::query()
            ->selectRaw('1')
            ->whereColumn('branch_player.player_id', $playerKey)
            ->when($branches !== null, fn ($q) => $q->inBranches($branches))
            ->when($categories !== null, fn ($q) => $q->inCategories($categories))
            ->when($statuses !== null, fn ($q) => $q->withStatuses($statuses))
            ->when($activeOn !== null, fn ($q) => $q->activeOn($activeOn))
            ->when($active, fn ($q) => $q->active())
            ->when($also !== null, fn ($q) => $also($q, 'branch_player.status_id', 'branch_player.left_at'));

        $query->where(function (Builder $either) use ($rows, $playerKey, $branches, $categories, $statuses, $activeOn, $active, $also) {
            $either->whereExists($rows);

            if ($branches !== null) {
                return;
            }

            $either->orWhere(function (Builder $flat) use ($playerKey, $categories, $statuses, $activeOn, $active, $also) {
                $column = $flat->getModel()->qualifyColumn(...);
                $flat->whereNotExists(fn ($q) => $q->selectRaw('1')->from('branch_player')->whereColumn('branch_player.player_id', $playerKey))
                    ->when($categories !== null, fn ($q) => $q->whereIn($column('category_id'), collect($categories)->all()))
                    ->when($statuses !== null, fn ($q) => $q->whereIn($column('status_id'), collect($statuses)->all()))
                    ->when($activeOn !== null, fn ($q) => BranchRegistration::constrainActiveOn($q, $column('status_id'), $column('left_at'), $activeOn))
                    ->when($active, fn ($q) => $q->notLeft())
                    ->when($also !== null, fn ($q) => $also($q, $column('status_id'), $column('left_at')));
            });
        });
    }
```

- [ ] **Step 5: Move `Roster` to it**

In `app/Services/Attendance/Roster.php`, replace the class doc's first paragraph:

```php
/**
 * Who is expected at a session. Before the first save it is the players with one
 * registration in the session's branch (any registration for a session with no
 * branch), in a category of the session, on the roster on that date, up to and
 * including their leave date; a player with no registration is judged on their
 * own columns. After the first save it is exactly the saved marks. Freezing it
 * keeps history fair: later category or status changes, departures and
 * newcomers never rewrite a past session.
```

The second paragraph of the doc stays. Replace the docblock and body of `expected()`:

```php
    /**
     * @param  int|array<int, int>  $categoryIds
     * @param  list<int>|null  $statusIds  the roster set; null = the settings' default
     * @param  int|null  $branchId  only this branch's registrations; null = any registration
     * @return Collection<int, Player>
     */
    public function expected(int|array $categoryIds, string $date, ?array $statusIds = null, ?int $branchId = null): Collection
    {
        $statusIds ??= $this->defaultStatusIds();

        return Player::registeredWhere(
            branches: $branchId,
            categories: (array) $categoryIds,
            also: fn ($row, string $status, string $leftAt) => $this->onRoster($row, $status, $leftAt, $date, $statusIds),
        )
            ->where('archived', false)
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::COLUMNS);
    }

    /**
     * Not gone before the date, with a status in the roster set. When the set holds
     * registered: no status at all (older records) and left later (members on that
     * date) count too. A legacy "left" with no date never does.
     *
     * @param  list<int>  $statusIds
     */
    private function onRoster(mixed $row, string $status, string $leftAt, string $date, array $statusIds): void
    {
        // SQLite may hold a date with a time: compare on the day alone.
        $row->where(fn ($q) => $q->whereNull($leftAt)->orWhere($leftAt, '>=', substr($date, 0, 10)));

        if ($statusIds === []) {
            return;
        }

        $row->where(function ($q) use ($status, $leftAt, $statusIds) {
            $q->whereIn($status, $statusIds);
            ['registered' => $registered, 'left' => $left] = $this->codedStatuses();
            if ($registered !== null && in_array($registered, $statusIds, true)) {
                $q->orWhereNull($status);
                if ($left !== null) {
                    $q->orWhere(fn ($q) => $q->where($status, $left)->whereNotNull($leftAt));
                }
            }
        });
    }
```

- [ ] **Step 6: Change the one test whose rule changed**

In `tests/Feature/MarkRecorderTest.php`, replace the test `a_player_who_left_exactly_on_the_session_date_is_not_expected` with:

```php
    #[Test]
    public function a_player_who_left_exactly_on_the_session_date_is_still_expected(): void
    {
        $u15 = $this->category();
        // Player::booted() clears left_at unless the status is "left" (seeded by migrations).
        $left = Player::leftStatusId();
        $player = $this->player($u15, ['status_id' => $left, 'left_at' => '2026-10-05']);

        $ids = app(Roster::class)->expected($u15->id, '2026-10-05')->pluck('id')->all();

        $this->assertSame([$player->id], $ids);
    }
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test tests/Feature/Registrations/RosterRegistrationTest.php tests/Feature/Registrations/SharedQueryTest.php tests/Feature/MarkRecorderTest.php tests/Feature/AttendanceRosterStatusTest.php tests/Feature/AttendanceRosterBranchTest.php tests/Feature/AttendanceMonthSheetTest.php tests/Feature/AttendanceGridTest.php`
Expected: all PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Models/Player.php app/Services/Attendance/Roster.php tests/Support/RegistrationSessions.php tests/Feature/Registrations/RosterRegistrationTest.php tests/Feature/MarkRecorderTest.php
git commit -m "feat(attendance): rosters read the registration in the session's branch

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P3.2: A new mark stores the registration's category

**Files:**
- Create: `tests/Feature/Registrations/MarkCategoryRegistrationTest.php`
- Modify: `app/Services/Attendance/MarkRecorder.php`

**Interfaces:**
- Consumes: `RegistrationDisplay::make()`, `->values(Player, ?int)`, `RegistrationSessions`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/MarkCategoryRegistrationTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Attendance;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\MarkRecorder;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\Support\RegistrationSessions;
use Tests\TestCase;

class MarkCategoryRegistrationTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures, RegistrationSessions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    private function storedCategory(TrainingSession $session, Player $player): ?int
    {
        app(MarkRecorder::class)->save($session, [['player_id' => $player->id, 'status' => 'present']], null);

        return Attendance::where('training_session_id', $session->id)->where('player_id', $player->id)->value('category_id');
    }

    #[Test]
    public function a_new_mark_stores_the_category_of_the_sessions_branch(): void
    {
        $player = $this->bothActive();

        $this->assertSame($this->u15->id, $this->storedCategory($this->sessionIn($this->swim, [$this->u15]), $player));
        $this->assertSame($this->senior->id, $this->storedCategory($this->sessionIn($this->foot, [$this->senior]), $player));
    }

    #[Test]
    public function a_left_branch_still_stores_its_own_category(): void
    {
        $player = $this->leftFootball();

        $this->assertSame($this->senior->id, $this->storedCategory($this->sessionIn($this->foot, [$this->senior]), $player));
    }

    #[Test]
    public function a_player_added_by_hand_with_no_row_there_stores_the_main_category(): void
    {
        $player = Player::create(['firstname' => 'Solo', 'lastname' => 'Test', 'join_year' => 2020]);
        app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);

        $this->assertSame($this->senior->id, $this->storedCategory($this->sessionIn($this->swim, [$this->u15]), $player));
    }

    #[Test]
    public function a_session_with_no_branch_stores_the_main_category(): void
    {
        $player = $this->bothActive();

        $this->assertSame($this->senior->id, $this->storedCategory($this->sessionIn(null, [$this->u15]), $player));
    }

    #[Test]
    public function no_registration_stores_the_players_own_category(): void
    {
        $flat = $this->noRegistration();

        $this->assertSame($this->senior->id, $this->storedCategory($this->sessionIn(null, [$this->senior]), $flat));
    }

    #[Test]
    public function an_existing_mark_never_changes_its_category(): void
    {
        $player = $this->bothActive();
        $session = $this->sessionIn($this->swim, [$this->u15]);
        $this->storedCategory($session, $player);

        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['category_id' => $this->senior->id]);

        $this->assertSame($this->u15->id, $this->storedCategory($session, $player));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/MarkCategoryRegistrationTest.php`
Expected: FAIL. `a_new_mark_stores_the_category_of_the_sessions_branch` gets Senior for the swimming session.

- [ ] **Step 3: Change `MarkRecorder`**

In `app/Services/Attendance/MarkRecorder.php`, add `use App\Services\Player\RegistrationDisplay;` to the imports. Replace:

```php
            // One query for the roster's current category: a new mark takes
            // it, but an existing mark keeps whatever category_id it already
            // has (see the loop below) — later roster moves never rewrite it.
            $categoryByPlayer = Player::whereIn('id', $playerIds)->pluck('category_id', 'id');
            $existingPlayerIds = $session->attendances()->whereIn('player_id', $playerIds)->pluck('player_id')
                ->map(fn ($id) => (int) $id)->all();
```

with:

```php
            $existingPlayerIds = $session->attendances()->whereIn('player_id', $playerIds)->pluck('player_id')
                ->map(fn ($id) => (int) $id)->all();
            // A new mark takes the player's category in the session's branch, but an
            // existing mark keeps whatever category_id it already has (see the loop
            // below) — later roster moves never rewrite it.
            $categoryByPlayer = $this->newMarkCategories($session, array_diff($playerIds, $existingPlayerIds));
```

Add after `save()`:

```php
    /**
     * Each player's category in the session's branch; a player added by hand
     * with no registration there, or a session with no branch, takes the main one.
     *
     * @param  array<int, int>  $playerIds
     * @return array<int, int|null> by player id
     */
    private function newMarkCategories(TrainingSession $session, array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }
        $display = RegistrationDisplay::make();

        return Player::with('registrations')->whereIn('id', $playerIds)->get(['id', 'category_id'])
            ->mapWithKeys(fn (Player $player) => [$player->id => $display->values($player, $session->branch_id)['category_id']])
            ->all();
    }
```

The loop below it (`$values['category_id'] = $categoryByPlayer[$playerId] ?? null;`) stays.

- [ ] **Step 4: Run the tests**

Run: `php artisan test tests/Feature/Registrations/MarkCategoryRegistrationTest.php tests/Feature/MarkRecorderTest.php tests/Feature/AttendanceSessionTest.php tests/Feature/AttendanceGridTest.php`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/MarkRecorder.php tests/Feature/Registrations/MarkCategoryRegistrationTest.php
git commit -m "feat(attendance): a new mark stores the category of the session's branch

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P3.3: The session page, its candidates and the sheets

**Files:**
- Create: `tests/Feature/Registrations/SessionPageRegistrationTest.php`
- Modify: `app/Services/Attendance/Roster.php` (new `categoryIdsIn`)
- Modify: `app/Http/Controllers/AttendanceController.php`
- Modify: `app/Http/Controllers/AttendanceSheetController.php`

**Interfaces:**
- Consumes: `RegistrationDisplay`, `Player::registeredWhere(activeOn:)`, `RegistrationSessions`.
- Produces: `Roster::categoryIdsIn(Illuminate\Database\Eloquent\Collection $players, ?int $branchId): array<int, int|null>`, by player id. Loads `registrations` once.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/SessionPageRegistrationTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Enums\AttendanceStatus;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Services\Attendance\Roster;
use App\Services\Pdf\PdfService;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\Support\RegistrationSessions;
use Tests\TestCase;

class SessionPageRegistrationTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase, RegistrationFixtures, RegistrationSessions;

    private Category $u17;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->u17 = Category::create(['name' => 'U17']);
    }

    #[Test]
    public function a_joint_sessions_tag_is_the_category_in_its_branch(): void
    {
        $player = $this->bothActive();
        $session = $this->sessionIn($this->swim, [$this->u15, $this->senior]);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.player_id', $player->id)
                ->where('rows.0.category', 'U15'));
    }

    #[Test]
    public function a_marks_own_category_still_wins(): void
    {
        $player = $this->bothActive();
        $session = $this->sessionIn($this->swim, [$this->u15, $this->senior]);
        $this->markOn($session, $player, AttendanceStatus::Present);
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['category_id' => $this->senior->id]);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertInertia(fn (Assert $page) => $page->where('rows.0.category', 'U15'));
    }

    #[Test]
    public function candidates_follow_active_registrations(): void
    {
        $both = $this->bothActive();
        $leftFoot = $this->leftFootball();
        $leftBoth = $this->leftBoth();
        $flat = $this->noRegistration();
        $registered = PlayerStatus::where('code', 'registered')->firstOrFail()->localized_name;
        // Nobody is on this session's roster: everyone left to add is a candidate.
        $session = $this->sessionIn($this->foot, [$this->u17], '2026-04-01');

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates', 3)
                ->where('candidates.0.id', $both->id)
                ->where('candidates', function (Collection $candidates) use ($both, $leftFoot, $leftBoth, $flat, $registered) {
                    $row = fn (Player $p) => collect($candidates->firstWhere('id', $p->id));

                    $this->assertTrue($row($both)['in_branch']);
                    $this->assertSame('Senior', $row($both)['category']);
                    $this->assertSame($registered, $row($both)['status']);
                    $this->assertSame('Football, Swimming', $row($both)['branches']);

                    // Left football: a guest here, shown as the swimmer they still are.
                    $this->assertFalse($row($leftFoot)['in_branch']);
                    $this->assertSame('U15', $row($leftFoot)['category']);
                    $this->assertSame($registered, $row($leftFoot)['status']);
                    $this->assertSame('Swimming', $row($leftFoot)['branches']);

                    $this->assertNull($candidates->firstWhere('id', $leftBoth->id));

                    $this->assertFalse($row($flat)['in_branch']);
                    $this->assertSame('Senior', $row($flat)['category']);
                    $this->assertSame('', $row($flat)['branches']);

                    return true;
                }));
    }

    #[Test]
    public function a_session_with_no_branch_has_everyone_in_branch(): void
    {
        $flat = $this->noRegistration();
        $session = $this->sessionIn(null, [$this->u17], '2026-04-01');

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.0.id', $flat->id)
                ->where('candidates.0.in_branch', true));
    }

    #[Test]
    public function left_everywhere_is_a_candidate_only_up_to_the_leave_date(): void
    {
        $leftBoth = $this->leftBoth();
        $session = $this->sessionIn($this->foot, [$this->u17], $this->leaveDate);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertInertia(fn (Assert $page) => $page->where('candidates.0.id', $leftBoth->id));
    }

    #[Test]
    public function category_ids_in_reads_each_case_with_one_load(): void
    {
        $both = $this->bothActive();
        $flat = $this->noRegistration('Flat', $this->u17->id);
        $solo = Player::create(['firstname' => 'Solo', 'lastname' => 'Test', 'join_year' => 2020]);
        app(RegistrationService::class)->register($solo, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);
        $players = Player::whereIn('id', [$both->id, $flat->id, $solo->id])->get();

        $ids = app(Roster::class)->categoryIdsIn($players, $this->swim->id);

        $this->assertSame($this->u15->id, $ids[$both->id]);
        $this->assertSame($this->u17->id, $ids[$flat->id]);
        $this->assertSame($this->senior->id, $ids[$solo->id]);
    }

    #[Test]
    public function the_month_sheet_labels_another_category_from_the_branchs_row(): void
    {
        $this->bothActive();
        $this->sessionIn($this->swim, [$this->u15], '2026-10-05');
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $html) use ($seen) {
                $seen['html'] = $html;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        $this->actingAs($this->admin())->get(route('attendance.sheets.month', [
            'category_id' => $this->u15->id, 'month' => '2026-10', 'branch_id' => $this->swim->id,
        ]))->assertOk();

        // The mirror says Senior; in swimming this player is U15, so no label.
        $this->assertStringNotContainsString('(Senior)', $seen['html']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/SessionPageRegistrationTest.php`
Expected: FAIL. `a_joint_sessions_tag_is_the_category_in_its_branch` gets Senior, and `categoryIdsIn` is undefined.

- [ ] **Step 3: Add `categoryIdsIn` to `Roster`**

In `app/Services/Attendance/Roster.php`, add these imports:

```php
use App\Services\Player\RegistrationDisplay;
use Illuminate\Database\Eloquent\Collection as PlayerRows;
```

Add after `forSession()`:

```php
    /**
     * The category to tag each listed player with: their registration's in the
     * branch, else their main one, else their own. One load for any number of players.
     *
     * @param  PlayerRows<int, Player>  $players
     * @return array<int, int|null> by player id
     */
    public function categoryIdsIn(PlayerRows $players, ?int $branchId): array
    {
        $players->loadMissing('registrations');
        $display = RegistrationDisplay::make();

        return $players->mapWithKeys(fn (Player $player) => [$player->id => $display->values($player, $branchId)['category_id']])->all();
    }
```

- [ ] **Step 4: Change the session page**

In `app/Http/Controllers/AttendanceController.php`, add these imports:

```php
use App\Services\Player\RegistrationDisplay;
use Illuminate\Database\Eloquent\Collection;
```

In `show()`, after `$joint = $categories->count() > 1;`, add:

```php
        $rowCategories = $joint ? $roster->categoryIdsIn($players, $session->branch_id) : [];
```

Change the rows closure to:

```php
            'rows' => $players->map(function (Player $p) use ($marks, $categories, $joint, $rowCategories) {
                $mark = $marks->get($p->id);

                return [
                    'player_id' => $p->id,
                    'name' => $p->fullname,
                    'picture' => $p->picture_url,
                    // Where a player comes from only matters when several categories share the session.
                    // Once marked, the mark's own category_id (fixed at marking time) wins over the
                    // player's current one in this branch, so a later change never retags a frozen session.
                    'category' => $joint ? $categories->get($mark?->category_id ?? $rowCategories[$p->id] ?? null) : null,
                    'status' => $mark?->status ?? AttendanceStatus::Present->value,
                    'minutes' => $mark?->minutes,
                    'reason' => $mark?->reason?->value,
                    'note' => $mark?->note,
                ];
            })->values(),
```

Replace the whole `'candidates' => Player::with(...)...->values(),` entry, with its comment, with:

```php
            'candidates' => $this->candidates($session, $players),
```

Add after `saveMarks()`:

```php
    /**
     * Anyone to add by hand, whatever their status, category or branch: not archived,
     * still a member somewhere on the session date, not already listed; searched by
     * full name. The session's active members come first, with that branch's values.
     * Everyone else is a guest, with their main values and the branches they're still in.
     *
     * @param  Collection<int, Player>  $listed
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function candidates(TrainingSession $session, Collection $listed): \Illuminate\Support\Collection
    {
        $display = RegistrationDisplay::make();
        $branchNames = Branch::all()->mapWithKeys(fn (Branch $b) => [$b->id => $b->localized_name]);
        $categoryNames = Category::all()->mapWithKeys(fn (Category $c) => [$c->id => $c->localized_name]);
        $statusNames = PlayerStatus::all()->mapWithKeys(fn (PlayerStatus $s) => [$s->id => $s->localized_name]);
        $branchId = $session->branch_id;
        $inBranch = fn (Player $p) => $branchId === null || $display->isActiveIn($p, $branchId);

        return Player::with('registrations')
            ->registeredWhere(activeOn: $session->date)
            ->where('archived', false)
            ->whereNotIn('id', $listed->modelKeys())
            ->orderBy('lastname')->orderBy('firstname')
            ->get([...Roster::COLUMNS, 'status_id', 'left_at'])
            // The session's branch members first (a stable sort keeps the name order); others are guests.
            ->sortBy(fn (Player $p) => ! $inBranch($p))
            ->map(function (Player $p) use ($display, $branchId, $inBranch, $branchNames, $categoryNames, $statusNames) {
                $values = $display->values($p, $inBranch($p) ? $branchId : null);

                return [
                    'id' => $p->id,
                    'name' => $p->fullname,
                    'picture' => $p->picture_url,
                    'status' => $statusNames->get($values['status_id']),
                    'category' => $categoryNames->get($values['category_id']),
                    'branches' => $p->registrations
                        ->filter(fn ($r) => $display->isActiveIn($p, (int) $r->branch_id))
                        ->sortBy('branch_id')
                        ->map(fn ($r) => $branchNames->get($r->branch_id))
                        ->implode(', '),
                    'in_branch' => $inBranch($p),
                ];
            })
            ->values();
    }
```

`Roster::COLUMNS` already holds `category_id` and `id`, which `registrations` needs. `Branch::orderBy('id')` and `Category::orderBy('id')` further up in `show()` stay as they are.

- [ ] **Step 5: Change the sheets**

In `app/Http/Controllers/AttendanceSheetController.php`, change the signature of `month()`:

```php
    public function month(Request $request, MonthSheet $sheet, AttendanceStatusCatalog $catalog, Roster $roster): Response
```

Replace the `$otherIds` block and its comment with:

```php
        // Players from another category (a joint session's roster, a guest) carry its name.
        // Deliberately the player's CURRENT category in this branch, not any mark's frozen one:
        // unlike the session sheet/screen, the month grid is a forward-looking blank sheet the coach
        // fills in on the pitch, so it lists people as they are today (see rows_follow_the_roster_rules()).
        $categoryOf = $roster->categoryIdsIn($players, $branch?->id);
        $otherIds = collect($categoryOf)->filter()->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === $category->id)->unique()->values()->all();
```

Replace the `'rows'` entry of the month view with:

```php
            'rows' => $players->map(fn (Player $p) => [
                'id' => $p->id,
                'name' => self::name($p),
                'category' => $others->get((int) ($categoryOf[$p->id] ?? 0)),
            ])->values()->all(),
```

In `session()`, after `$joint = $categories->count() > 1;`, add:

```php
        $rowCategories = $joint ? $roster->categoryIdsIn($players, $session->branch_id) : [];
```

Change its rows closure to:

```php
            'rows' => $players->map(function (Player $p) use ($marks, $categories, $joint, $filled, $rowCategories) {
                $mark = $marks->get($p->id);

                return [
                    'name' => self::name($p),
                    // Where a player comes from only matters when several categories share the session.
                    // Once marked, the mark's own category_id (fixed at marking time) wins over the
                    // player's current one in this branch, so a later change never retags a frozen session.
                    'category' => $joint ? $categories->get($mark?->category_id ?? $rowCategories[$p->id] ?? null) : null,
                    'status' => $filled ? $mark?->status : null,
                    'minutes' => $filled ? $mark?->minutes : null,
                    'reason' => $filled ? $mark?->reason?->value : null,
                    'note' => $filled ? $mark?->note : null,
                ];
            })->values()->all(),
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/Registrations/SessionPageRegistrationTest.php tests/Feature/AttendanceSessionTest.php tests/Feature/AttendanceRosterBranchTest.php tests/Feature/AttendanceRosterStatusTest.php tests/Feature/AttendanceMonthSheetPdfTest.php tests/Feature/AttendanceSessionSheetPdfTest.php tests/Feature/AttendanceFullNamesTest.php tests/Feature/AttendanceCategoryPivotTest.php`
Expected: all PASS. `the_session_page_offers_any_active_player_by_full_name_with_their_status` and `the_session_page_shows_the_branch_and_lists_its_players_first_among_candidates` pass unchanged, because they rely on behaviour this keeps.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Attendance/Roster.php app/Http/Controllers/AttendanceController.php app/Http/Controllers/AttendanceSheetController.php tests/Feature/Registrations/SessionPageRegistrationTest.php
git commit -m "feat(attendance): session page and sheets tag players from the branch's registration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P3.4: Names, at-risk, injuries, ranking and certificates

**Files:**
- Create: `tests/Feature/Registrations/AttendanceNamesRegistrationTest.php`
- Modify: `app/Services/Attendance/PlayerNames.php`
- Modify: `app/Services/Attendance/AtRisk.php`
- Modify: `app/Services/Attendance/InjurySpells.php`
- Modify: `app/Http/Controllers/AttendanceRankingController.php`
- Modify: `app/Http/Controllers/AttendanceStatsController.php`

**Interfaces:**
- Consumes: `Player::registeredWhere`, `RegistrationSessions`.
- Produces: `PlayerNames::attach(array $rows, ?int $branchId = null): array`. A row's own `branch_id` key beats `$branchId`. `category_id`, `category` and `membership_id` come from the registration in that branch when there is one, otherwise the player's columns. `active` is false for a registration that is left.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/AttendanceNamesRegistrationTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Services\Attendance\AtRisk;
use App\Services\Attendance\InjurySpells;
use App\Services\Attendance\PlayerNames;
use App\Services\Pdf\PdfService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\Support\RegistrationSessions;
use Tests\TestCase;

class AttendanceNamesRegistrationTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase, RegistrationFixtures, RegistrationSessions;

    private const FROM = '2026-09-01';

    private const TO = '2027-08-31';

    private const DATES = ['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26', '2026-11-02'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->leaveDate = '2026-11-10';
        $this->twoSportClub();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function marks(Player $player, Branch $branch, Category $category, AttendanceStatus $status = AttendanceStatus::AbsentUnexcused): void
    {
        foreach (self::DATES as $date) {
            $this->markOn($this->sessionIn($branch, [$category], $date, ['state' => SessionState::Held]), $player, $status);
        }
    }

    private function names(array $rows, ?int $branchId = null): array
    {
        return app(PlayerNames::class)->attach($rows, $branchId);
    }

    #[Test]
    public function names_read_the_branchs_row(): void
    {
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => 'SW-2020-00001']);

        $main = $this->names([['player_id' => $player->id]])[0];
        $this->assertSame($this->senior->id, $main['category_id']);
        $this->assertSame('Senior', $main['category']);
        $this->assertTrue($main['active']);

        $swim = $this->names([['player_id' => $player->id]], $this->swim->id)[0];
        $this->assertSame($this->u15->id, $swim['category_id']);
        $this->assertSame('U15', $swim['category']);
        $this->assertSame('SW-2020-00001', $swim['membership_id']);
        $this->assertTrue($swim['active']);
    }

    #[Test]
    public function a_second_row_with_no_id_of_its_own_shows_the_players(): void
    {
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => null]);

        $this->assertSame($player->fresh()->membership_id, $this->names([['player_id' => $player->id]], $this->swim->id)[0]['membership_id']);
    }

    #[Test]
    public function left_a_branch_is_not_active_there(): void
    {
        $player = $this->leftFootball();

        $this->assertFalse($this->names([['player_id' => $player->id]], $this->foot->id)[0]['active']);
        $this->assertTrue($this->names([['player_id' => $player->id]], $this->swim->id)[0]['active']);
        $this->assertTrue($this->names([['player_id' => $player->id]])[0]['active']);
    }

    #[Test]
    public function left_everywhere_is_active_nowhere(): void
    {
        $player = $this->leftBoth();

        $this->assertFalse($this->names([['player_id' => $player->id]], $this->foot->id)[0]['active']);
        $this->assertFalse($this->names([['player_id' => $player->id]], $this->swim->id)[0]['active']);
        $this->assertFalse($this->names([['player_id' => $player->id]])[0]['active']);
    }

    #[Test]
    public function no_registration_uses_the_players_columns(): void
    {
        $flat = $this->noRegistration();

        $row = $this->names([['player_id' => $flat->id]], $this->foot->id)[0];

        $this->assertSame($this->senior->id, $row['category_id']);
        $this->assertTrue($row['active']);
    }

    #[Test]
    public function a_rows_own_branch_beats_the_argument(): void
    {
        $player = $this->bothActive();

        $rows = $this->names([
            ['player_id' => $player->id, 'branch_id' => $this->swim->id],
            ['player_id' => $player->id, 'branch_id' => $this->foot->id],
        ], $this->foot->id);

        $this->assertSame('U15', $rows[0]['category']);
        $this->assertSame('Senior', $rows[1]['category']);
    }

    #[Test]
    public function a_branch_adds_no_query(): void
    {
        $player = $this->bothActive();
        $count = function (?int $branchId) use ($player): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->names([['player_id' => $player->id]], $branchId);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->assertSame(2, $count(null));
        $this->assertSame(2, $count($this->swim->id));
    }

    #[Test]
    public function at_risk_filters_and_shows_the_branchs_category(): void
    {
        $player = $this->bothActive();
        $this->marks($player, $this->swim, $this->u15);
        $risk = app(AtRisk::class);

        $rows = $risk->list(self::FROM, self::TO, null, $this->swim->id);
        $this->assertSame([$player->id], array_column($rows, 'player_id'));
        $this->assertSame('U15', $rows[0]['category']);
        $this->assertCount(1, $risk->list(self::FROM, self::TO, $this->u15->id, $this->swim->id));
        $this->assertCount(0, $risk->list(self::FROM, self::TO, $this->senior->id, $this->swim->id));
    }

    #[Test]
    public function at_risk_drops_a_player_who_left_that_branch(): void
    {
        $player = $this->leftFootball();
        $this->marks($player, $this->foot, $this->senior);
        $this->marks($player, $this->swim, $this->u15);
        $risk = app(AtRisk::class);

        $this->assertSame([], $risk->list(self::FROM, self::TO, null, $this->foot->id));
        $this->assertCount(1, $risk->list(self::FROM, self::TO, null, $this->swim->id));

        $any = $risk->anyBranch(self::FROM, self::TO);
        $this->assertCount(1, $any);
        $this->assertSame($this->swim->id, $any[0]['branch_id']);
        $this->assertSame('U15', $any[0]['category']);
    }

    #[Test]
    public function at_risk_keeps_a_guest_with_no_row_and_no_registration(): void
    {
        $flat = $this->noRegistration();
        $this->marks($flat, $this->foot, $this->senior);

        $rows = app(AtRisk::class)->list(self::FROM, self::TO, null, $this->foot->id);

        $this->assertSame([$flat->id], array_column($rows, 'player_id'));
        $this->assertSame('Senior', $rows[0]['category']);
    }

    #[Test]
    public function injuries_follow_the_branch(): void
    {
        $player = $this->bothActive();
        $left = $this->leftFootball();
        $this->markOn($this->sessionIn($this->swim, [$this->u15], '2026-10-05', ['state' => SessionState::Held]), $player, AttendanceStatus::NotTraining);
        $this->markOn($this->sessionIn($this->foot, [$this->senior], '2026-10-05', ['state' => SessionState::Held]), $left, AttendanceStatus::NotTraining);
        $spells = app(InjurySpells::class);

        $swim = $spells->club(self::FROM, self::TO, null, $this->swim->id);
        $this->assertSame('U15', $swim['current'][0]['category']);
        $this->assertCount(1, $spells->club(self::FROM, self::TO, $this->u15->id, $this->swim->id)['current']);
        $this->assertCount(0, $spells->club(self::FROM, self::TO, $this->senior->id, $this->swim->id)['current']);
        $this->assertSame([], $spells->club(self::FROM, self::TO, null, $this->foot->id)['current']);
    }

    #[Test]
    public function the_ranking_leaves_out_a_player_who_left_that_branch(): void
    {
        $stays = $this->bothActive();
        $left = $this->leftFootball();
        foreach (self::DATES as $date) {
            $session = $this->sessionIn($this->foot, [$this->senior], $date, ['state' => SessionState::Held]);
            $this->markOn($session, $stays, AttendanceStatus::Present);
            $this->markOn($session, $left, AttendanceStatus::Present);
        }

        $this->actingAs($this->admin())->get(route('attendance.ranking', [
            'type' => 'season', 'season' => 2026, 'category_id' => $this->senior->id, 'branch_id' => $this->foot->id,
        ]))->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.player_id', $stays->id)
            ->where('rows.0.category', 'Senior')
            ->where('unranked', 0));
    }

    #[Test]
    public function the_statistics_table_shows_the_branchs_category(): void
    {
        $player = $this->bothActive();
        $this->marks($player, $this->swim, $this->u15, AttendanceStatus::Present);

        $this->actingAs($this->admin())->get(route('attendance.stats', ['branch_id' => $this->swim->id, 'period' => 'custom', 'from' => self::FROM, 'to' => self::TO]))
            ->assertInertia(fn (Assert $page) => $page->where('players', function (Collection $players) use ($player) {
                $this->assertSame('U15', collect($players->firstWhere('player_id', $player->id))['category']);

                return true;
            }));
    }

    #[Test]
    public function a_certificate_needs_the_category_in_that_branch(): void
    {
        $this->mock(PdfService::class, function (MockInterface $mock) {
            $mock->shouldReceive('stream')->andReturnUsing(fn () => response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']));
        });
        $both = $this->bothActive();
        $left = $this->leftFootball();
        $flat = $this->noRegistration();
        $admin = $this->admin();
        $get = fn (Player $p, Branch $b, Category $c) => $this->actingAs($admin)->get(route('attendance.certificates', [
            'category_id' => $c->id, 'branch_id' => $b->id, 'player_id' => $p->id,
        ]));

        $get($both, $this->swim, $this->u15)->assertOk();
        $get($both, $this->foot, $this->senior)->assertOk();
        $get($both, $this->swim, $this->senior)->assertNotFound();
        $get($both, $this->foot, $this->u15)->assertNotFound();
        $get($left, $this->foot, $this->senior)->assertNotFound();
        $get($left, $this->swim, $this->u15)->assertOk();
        $get($flat, $this->foot, $this->senior)->assertNotFound();
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/AttendanceNamesRegistrationTest.php`
Expected: FAIL. `names_read_the_branchs_row` gets Senior for swimming, because `attach` has no branch yet and ignores the argument.

- [ ] **Step 3: Rewrite `PlayerNames`**

Replace the whole of `app/Services/Attendance/PlayerNames.php`:

```php
<?php

namespace App\Services\Attendance;

use App\Models\Category;
use App\Models\Player;
use Illuminate\Support\Facades\DB;

/**
 * Who each attendance row is about, in the branch being viewed: the player's
 * name (Player::fullname), and from their registration there the membership id,
 * current category and whether they are still active (not archived, not left
 * that branch). A player with no registration there, or no branch being viewed,
 * shows their own columns, which hold their main registration. Two queries for
 * any number of rows (the players with their registrations, then the
 * categories), none for no rows. Shared by the statistics page, the at-risk
 * list, the ranking and the injuries list.
 *
 * The players are read as plain rows: with a full club, hydrating a model per
 * player took most of the statistics page's time.
 */
final class PlayerNames
{
    /**
     * @param  array<array-key, array{player_id: int, branch_id?: int|null}>  $rows  a row's own branch_id beats $branchId
     * @return list<array<string, mixed>> the rows, in their order, with name, membership_id, category_id, category, active
     */
    public function attach(array $rows, ?int $branchId = null): array
    {
        if ($rows === []) {
            return [];
        }

        $branchOf = fn (array $row): ?int => $row['branch_id'] ?? $branchId;
        $branchIds = collect($rows)->map($branchOf)->filter()->unique()->values()->all();
        $columns = array_map(fn (string $column) => 'players.'.$column, ['id', ...Player::NAME_COLUMNS, 'membership_id', 'category_id', 'archived', 'left_at']);

        // One row per player and branch asked about, in the same query as the player.
        $players = DB::table('players')
            ->whereIn('players.id', array_unique(array_column($rows, 'player_id')))
            ->select($columns)
            ->when($branchIds !== [], fn ($q) => $q
                ->leftJoin('branch_player as r', fn ($join) => $join->on('r.player_id', '=', 'players.id')->whereIn('r.branch_id', $branchIds))
                ->leftJoin('player_statuses as rs', 'rs.id', '=', 'r.status_id')
                ->addSelect(['r.branch_id as reg_branch_id', 'r.category_id as reg_category_id', 'r.membership_id as reg_membership_id', 'rs.code as reg_status_code']))
            ->get()
            ->groupBy('id');

        $categories = Category::whereIn('id', $players->flatten(1)
            ->flatMap(fn ($player) => [$player->category_id, $player->reg_category_id ?? null])
            ->filter()->unique()->values())
            ->get()
            ->mapWithKeys(fn (Category $category) => [$category->id => $category->localized_name]);

        return array_values(array_map(function (array $row) use ($players, $categories, $branchOf): array {
            $found = $players->get($row['player_id']);
            $player = $found?->first();
            $branch = $branchOf($row);
            $registration = $branch === null ? null : $found?->firstWhere('reg_branch_id', $branch);
            $categoryId = $registration !== null ? $registration->reg_category_id : $player?->category_id;
            $hasLeft = $registration !== null ? $registration->reg_status_code === 'left' : $player?->left_at !== null;

            return [
                ...$row,
                'name' => $player === null ? '#'.$row['player_id']
                    : Player::fullnameOf($player->lastname, $player->firstname, $player->nickname, $player->father, $player->grandfather, $player->gender),
                'membership_id' => $registration?->reg_membership_id ?? $player?->membership_id,
                'category_id' => $categoryId === null ? null : (int) $categoryId,
                'category' => $categoryId === null ? null : $categories->get((int) $categoryId),
                'active' => $player !== null && ! $player->archived && ! $hasLeft,
            ];
        }, $rows));
    }
}
```

- [ ] **Step 4: Pass the branch through `AtRisk` and `InjurySpells`**

In `app/Services/Attendance/AtRisk.php`, change `list()`'s docblock sentence and body:

```php
    /**
     * Every active player (not archived, not left this branch) at risk over the
     * period in one branch, worst first: lowest score % (none last), then longest
     * streak, then name. $categoryId keeps the players now in that category in the
     * branch; their numbers still cover all their marks in the branch, since the
     * risk is the person's. Six queries whatever the roster (the custom
     * codes: 1, players(): 2, the streak scan: 1, names: 2), four when
     * nobody is at risk.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $from, string $to, ?int $categoryId = null, ?int $branchId = null): array
    {
        return $this->named($this->flagged($from, $to, $branchId), $categoryId, $branchId);
    }
```

Change the call in `anyBranch()` to `$this->named($flagged, null, null)`. Each of those rows carries its own `branch_id`, which `PlayerNames` reads.

Replace `named()`:

```php
    /**
     * Names attached (PlayerNames) from the branch's registration, active players
     * only, optionally those now in $categoryId, worst first (usort is stable:
     * equal rows keep their order).
     *
     * @return list<array<string, mixed>>
     */
    private function named(array $flagged, ?int $categoryId, ?int $branchId): array
    {
        $rows = array_values(array_filter(
            $this->names->attach($flagged, $branchId),
            fn (array $row) => $row['active'] && ($categoryId === null || $row['category_id'] === $categoryId),
        ));
        usort($rows, fn (array $a, array $b) => [$a['score_pct'] ?? 101, $b['longest_streak'], $a['name']]
            <=> [$b['score_pct'] ?? 101, $a['longest_streak'], $b['name']]);

        return $rows;
    }
```

In `app/Services/Attendance/InjurySpells.php`, change the filter in `club()`:

```php
        $rows = array_values(array_filter(
            $this->names->attach($rows, $branchId),
            fn (array $row) => $row['active'] && ($categoryId === null || $row['category_id'] === $categoryId),
        ));
```

In the docblock of `club()`, change "Active players only (not archived, not left); $categoryId keeps those now in that category." to "Active players only (not archived, not left this branch); $categoryId keeps those now in that category in the branch."

- [ ] **Step 5: Change the ranking and the statistics page**

In `app/Http/Controllers/AttendanceRankingController.php`, remove the import `use Illuminate\Support\Facades\DB;`. Replace the block in `certificates()`:

```php
                $own = $this->stats->players($period['from'], $period['to'], $category->id, $playerId, $branchId)[$playerId] ?? null;
                // Neither expected in the branch's category over the period nor in it now: no certificate "for" it.
                $inItNow = Player::whereKey($playerId)
                    ->registeredWhere(branches: $branchId, categories: [$category->id], active: true)
                    ->exists();
                abort_if(($own['expected'] ?? 0) === 0 && ! $inItNow, 404);
                $row = $this->names->attach([$own ?? $this->stats->emptyRow($playerId)], $branchId)[0];
                // Departed or archived: no certificate for them either, even outside the ranking.
                abort_if(! $row['active'], 404);
```

In `ranking()`, change the line to:

```php
        $active = array_values(array_filter($this->names->attach($rows, $branchId), fn (array $row) => $row['active']));
```

In `app/Http/Controllers/AttendanceStatsController.php`, change the call in `data()` to `$players = $this->withNames($rows, $branchId);`, and replace `withNames`:

```php
    /** Adds each player's name, membership id and current category in the branch (PlayerNames); sorted by name. */
    private function withNames(array $rows, ?int $branchId): array
    {
        return collect($this->names->attach($rows, $branchId))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/Registrations/AttendanceNamesRegistrationTest.php tests/Feature/AttendanceAtRiskTest.php tests/Feature/AttendanceAlertsPageTest.php tests/Feature/AttendanceBranchAlertsTest.php tests/Feature/AttendanceBranchInjuriesTest.php tests/Feature/AttendanceInjurySpellsTest.php tests/Feature/AttendanceInjuriesPageTest.php tests/Feature/AttendanceRankingPageTest.php tests/Feature/AttendanceBranchRankingTest.php tests/Feature/AttendanceCertificatesPdfTest.php tests/Feature/AttendanceStatsPageTest.php tests/Feature/AttendanceBranchStatsPageTest.php tests/Feature/AttendanceStatsExportTest.php tests/Feature/Dashboard/DashboardAttendanceCardTest.php`
Expected: all PASS, with the pinned query counts unchanged.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Attendance/PlayerNames.php app/Services/Attendance/AtRisk.php app/Services/Attendance/InjurySpells.php app/Http/Controllers/AttendanceRankingController.php app/Http/Controllers/AttendanceStatsController.php tests/Feature/Registrations/AttendanceNamesRegistrationTest.php
git commit -m "feat(attendance): names, at-risk, injuries and ranking read the branch being viewed

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P3.5: The player's attendance card and its PDFs

**Files:**
- Create: `tests/Feature/Registrations/PlayerAttendanceCardRegistrationTest.php`
- Modify: `app/Http/Controllers/AttendancePlayerController.php`
- Modify: `resources/views/pdf/attendance-player.blade.php:42-43`

**Interfaces:**
- Consumes: `Player::registrationIn`, `RegistrationDisplay::make()`, `->values()`.

`branchTabs()` already lists every registration plus any branch with a mark, so a left branch stays reachable. It doesn't change. A test pins it.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/PlayerAttendanceCardRegistrationTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\Player;
use App\Models\PreseasonTarget;
use App\Services\Pdf\PdfService;
use App\Services\Player\RegistrationService;
use App\Support\AttendanceSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\Support\RegistrationSessions;
use Tests\TestCase;

class PlayerAttendanceCardRegistrationTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase, RegistrationFixtures, RegistrationSessions;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');   // season 2026/27
        $this->twoSportClub();
        PreseasonTarget::create(['branch_id' => $this->foot->id, 'category_id' => $this->senior->id, 'season_start_year' => 2026, 'target_count' => 10]);
        PreseasonTarget::create(['branch_id' => $this->swim->id, 'category_id' => $this->u15->id, 'season_start_year' => 2026, 'target_count' => 4]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function spyPdf(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->andReturnUsing(function (string $html, string $filename) use ($seen) {
                $seen['html'] = $html;
                $seen['filename'] = $filename;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        return $seen;
    }

    private function swimmingId(Player $player): string
    {
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => 'SW-2020-00001']);

        return 'SW-2020-00001';
    }

    #[Test]
    public function each_tab_uses_its_own_category_for_the_target(): void
    {
        $player = $this->bothActive();

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', ['player' => $player, 'branch_id' => $this->foot->id]))
            ->assertJsonPath('preseason.target', 10);
        $this->actingAs($this->admin())->getJson(route('attendance.players.show', ['player' => $player, 'branch_id' => $this->swim->id]))
            ->assertJsonPath('preseason.target', 4);
    }

    #[Test]
    public function a_left_branch_keeps_its_tab_and_its_target(): void
    {
        $player = $this->leftFootball();

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', ['player' => $player, 'branch_id' => $this->foot->id]))
            ->assertJsonCount(2, 'branches')
            ->assertJsonPath('branchId', $this->foot->id)
            ->assertJsonPath('preseason.target', 10);
    }

    #[Test]
    public function left_everywhere_keeps_every_tab(): void
    {
        $player = $this->leftBoth();

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', ['player' => $player, 'branch_id' => $this->swim->id]))
            ->assertJsonCount(2, 'branches')
            ->assertJsonPath('preseason.target', 4);
    }

    #[Test]
    public function a_tab_with_marks_but_no_registration_has_no_target(): void
    {
        $player = Player::create(['firstname' => 'Solo', 'lastname' => 'Test', 'join_year' => 2020]);
        app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);
        $this->markOn($this->sessionIn($this->swim, [$this->u15], '2026-10-05', ['state' => SessionState::Held]), $player, AttendanceStatus::Present);

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', ['player' => $player, 'branch_id' => $this->swim->id]))
            ->assertJsonCount(2, 'branches')
            ->assertJsonPath('preseason', null);
    }

    #[Test]
    public function no_registration_in_a_club_with_branches_has_no_target(): void
    {
        $this->actingAs($this->admin())->getJson(route('attendance.players.show', $this->noRegistration()))
            ->assertJsonPath('branchId', null)
            ->assertJsonPath('preseason', null);
    }

    #[Test]
    public function the_report_prints_the_tabs_id_and_category(): void
    {
        $player = $this->bothActive();
        $id = $this->swimmingId($player);
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.players.report', ['player' => $player, 'branch_id' => $this->swim->id]))->assertOk();

        $this->assertStringContainsString($id, $seen['html']);
        $this->assertStringContainsString('U15', $seen['html']);
        $this->assertStringNotContainsString('Senior', $seen['html']);
        $this->assertStringContainsString("attendance-{$id}-", $seen['filename']);
    }

    #[Test]
    public function the_report_of_a_tab_with_no_registration_prints_the_main_values(): void
    {
        $player = Player::create(['firstname' => 'Solo', 'lastname' => 'Test', 'join_year' => 2020]);
        app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);
        $this->markOn($this->sessionIn($this->swim, [$this->u15], '2026-10-05', ['state' => SessionState::Held]), $player, AttendanceStatus::Present);
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.players.report', ['player' => $player, 'branch_id' => $this->swim->id]))->assertOk();

        $this->assertStringContainsString('Senior', $seen['html']);
    }

    #[Test]
    public function the_letter_names_the_tabs_category_and_id(): void
    {
        $player = $this->bothActive();
        $id = $this->swimmingId($player);
        $body = 'CAT={category}';
        AttendanceSettings::save(['letter' => ['body' => ['ar' => $body, 'fr' => $body, 'en' => $body]]]);
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.players.letter', ['player' => $player, 'branch_id' => $this->swim->id]))->assertOk();

        $this->assertStringContainsString('CAT=U15', $seen['html']);
        $this->assertStringContainsString("attendance-letter-{$id}-", $seen['filename']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/PlayerAttendanceCardRegistrationTest.php`
Expected: FAIL. `each_tab_uses_its_own_category_for_the_target` gets no target on the swimming tab, because it looks up Senior there.

- [ ] **Step 3: Change the controller**

In `app/Http/Controllers/AttendancePlayerController.php`, add these imports:

```php
use App\Models\Category;
use App\Services\Player\RegistrationDisplay;
```

In `report()`, remove `$player->loadMissing('category');`, then replace the rest from `$data = ...` to the end of the method with:

```php
        $data = $this->data($request, $player);
        $shown = $this->shown($player, $data['branchId']);
        ['from' => $from, 'to' => $to] = $data['period'];

        $html = view('pdf.attendance-player', [
            ...$data,
            'club' => ClubHeader::data(),
            'player' => $player,
            'shown' => $shown,
            'photo' => Media::localFile($player->picture_url),
            // A hidden custom code only shows while the player has marks for it in the period.
            'labels' => $this->catalog->labels(null, $this->catalog->withMarks($data['summary']['counts'])),
            'codes' => $this->catalog->codes(),
        ])->render();

        return $this->pdf->stream(
            $html,
            "attendance-{$shown['membership_id']}-{$from}-{$to}".self::branchSuffix($data['branchId']).'.pdf',
            app()->getLocale() === 'ar',
        );
```

In `letter()`, remove `$player->loadMissing('category');`. After `[$tabs, $branchId] = $this->branchTabs($request, $player);`, add:

```php
        $shown = $this->shown($player, $branchId);
```

Change the letter's category line to:

```php
            'category' => $shown['category'] ?? '—',
```

Change the filename line to:

```php
            "attendance-letter-{$shown['membership_id']}-{$from}-{$to}".self::branchSuffix($branchId).'.pdf',
```

Add after `branchName()`:

```php
    /**
     * The ID and category a printed page shows for the tab: that registration's,
     * or the main one's when the tab has none (marks as a guest).
     *
     * @return array{membership_id: ?string, category: ?string}
     */
    private function shown(Player $player, ?int $branchId): array
    {
        $values = RegistrationDisplay::make()->values($player, $branchId);

        return [
            'membership_id' => $values['membership_id'],
            'category' => $values['category_id'] === null ? null : Category::find($values['category_id'])?->localized_name,
        ];
    }

    /**
     * The category a tab's pre-season target belongs to: that registration's. A tab with
     * marks but no registration has none, nor does a player in no branch of a club with branches.
     */
    private function tabCategoryId(Player $player, ?int $branchId): ?int
    {
        if ($branchId !== null) {
            return $player->registrationIn($branchId)?->category_id;
        }

        return Branch::exists() ? null : $player->category_id;
    }
```

In `data()`, add this before `return`:

```php
        $categoryId = $this->tabCategoryId($player, $branchId);
```

Replace the `preseason` entry and its comment with:

```php
            'preseason' => $categoryId ? $this->preseason->forCategory((int) $categoryId, $to, $branchId) : null,
```

- [ ] **Step 4: Change the PDF view**

In `resources/views/pdf/attendance-player.blade.php`, replace lines 42 and 43:

```blade
                <div style="font-family:monospace;">{{ $shown['membership_id'] }}</div>
                <div class="muted">{{ $shown['category'] }}</div>
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/Registrations/PlayerAttendanceCardRegistrationTest.php tests/Feature/AttendancePlayerCardTest.php tests/Feature/AttendanceBranchPlayerCardTest.php tests/Feature/AttendancePlayerReportTest.php tests/Feature/AttendanceLetterPdfTest.php`
Expected: all PASS. `AttendanceBranchPlayerCardTest::injuries_and_preseason_follow_the_tab` passes unchanged, because `attach()` copies the player's category to each row.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AttendancePlayerController.php resources/views/pdf/attendance-player.blade.php tests/Feature/Registrations/PlayerAttendanceCardRegistrationTest.php
git commit -m "feat(attendance): the player's card and PDFs follow the tab's registration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P3.6: Query budget for the session roster

This is the session-roster half of the spec's budget test (section 4). Part 6 adds the Players list and the dashboard. The class name holds `QueryBudget`, so the main plan's `--filter=QueryBudget` finds it.

**Files:**
- Create: `tests/Feature/Registrations/SessionRosterQueryBudgetTest.php`

- [ ] **Step 1: Write the test**

`tests/Feature/Registrations/SessionRosterQueryBudgetTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\TrainingSession;
use App\Services\Attendance\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\Support\RegistrationSessions;
use Tests\TestCase;

/** A club of 1,920 players costs the session screen no more queries than a club of three. */
class SessionRosterQueryBudgetTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase, RegistrationFixtures, RegistrationSessions;

    private const CLUB_SIZE = 1920;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    /** Every tenth member also swims. Rows go straight in: a model per player would take minutes. */
    private function addMembers(int $count): void
    {
        $registered = PlayerStatus::where('code', 'registered')->value('id');
        $after = (int) Player::max('id');

        foreach (array_chunk(range(1, $count), 100) as $chunk) {
            DB::table('players')->insert(array_map(fn (int $n) => [
                'firstname' => 'P'.$n, 'lastname' => 'Budget', 'category_id' => $this->senior->id, 'status_id' => $registered,
            ], $chunk));
        }

        $rows = [];
        foreach (Player::where('id', '>', $after)->orderBy('id')->pluck('id') as $i => $id) {
            $row = ['player_id' => $id, 'status_id' => $registered];
            $rows[] = $row + ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id];
            if ($i % 10 === 0) {
                $rows[] = $row + ['branch_id' => $this->swim->id, 'category_id' => $this->u15->id];
            }
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('branch_player')->insert($chunk);
        }
    }

    private function queriesFor(TrainingSession $session): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('attendance.sessions.show', $session))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    #[Test]
    public function the_session_screen_costs_the_same_at_any_club_size(): void
    {
        $this->addMembers(3);
        // Joint, so everyone is on the roster and the category tags are read.
        $joint = $this->sessionIn($this->foot, [$this->senior, $this->u15]);
        // A category nobody is in, so everyone is a candidate instead.
        $empty = $this->sessionIn($this->foot, [Category::create(['name' => 'U17'])]);
        $this->actingAs($this->admin());
        $this->get(route('attendance.sessions.show', $joint))->assertOk();   // settle what is read once
        $this->get(route('attendance.sessions.show', $empty))->assertOk();

        $fewOnRoster = $this->queriesFor($joint);
        $fewCandidates = $this->queriesFor($empty);
        $this->addMembers(self::CLUB_SIZE - 3);

        $this->assertSame($fewOnRoster, $this->queriesFor($joint));
        $this->assertSame($fewCandidates, $this->queriesFor($empty));
    }

    #[Test]
    public function the_expected_roster_is_one_query_at_any_club_size(): void
    {
        $this->addMembers(self::CLUB_SIZE);
        $roster = app(Roster::class);
        $roster->defaultStatusIds();
        $roster->expected($this->senior->id, '2026-03-10', null, $this->foot->id);   // loads the statuses once

        DB::flushQueryLog();
        DB::enableQueryLog();
        $players = $roster->expected($this->senior->id, '2026-03-10', null, $this->foot->id);
        DB::disableQueryLog();

        $this->assertCount(self::CLUB_SIZE, $players);
        $this->assertCount(1, DB::getQueryLog());
    }

    #[Test]
    public function the_tags_cost_one_load_at_any_club_size(): void
    {
        $this->addMembers(self::CLUB_SIZE);
        $roster = app(Roster::class);
        $players = $roster->expected($this->senior->id, '2026-03-10', null, $this->foot->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $roster->categoryIdsIn($players, $this->swim->id);
        DB::disableQueryLog();

        // The registrations, the main branch, the left status.
        $this->assertCount(3, DB::getQueryLog());
    }
}
```

- [ ] **Step 2: Run it**

Run: `php artisan test tests/Feature/Registrations/SessionRosterQueryBudgetTest.php`
Expected: PASS. If it fails, a per-row query exists: print `DB::getQueryLog()` to find it and fix the code, not the test. Don't lower `CLUB_SIZE`.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Registrations/SessionRosterQueryBudgetTest.php
git commit -m "test(attendance): the session roster costs no query per player

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P3.7: Close the part

**Files:** none, unless a check finds something.

- [ ] **Step 1: Run the whole suite**

```bash
php artisan config:clear && php artisan test 2>&1 | tail -15
```

Expected: only `ManualSuspensionsTest` may fail, as at the baseline. No frontend file changed in this part, so `npm run i18n:check` and `npm run build` have nothing new to catch. Run them once anyway, as the main plan requires:

```bash
npm run i18n:check 2>&1 | tail -3
npm run build 2>&1 | tail -3
```

A failure in a test that attaches a branch and then changes `players.category_id` or `status_id` directly means the fixture speaks to the mirror. Change the fixture to go through `RegistrationService`, or update the registration too. Don't weaken the code. The tests read while planning that change those columns have no branches, so none is expected.

- [ ] **Step 2: Run the new SQL on the other databases**

Using the private instances from the main plan's Task 7:

```bash
FILES="tests/Feature/Registrations/RosterRegistrationTest.php tests/Feature/Registrations/MarkCategoryRegistrationTest.php tests/Feature/Registrations/SessionPageRegistrationTest.php tests/Feature/Registrations/AttendanceNamesRegistrationTest.php tests/Feature/Registrations/PlayerAttendanceCardRegistrationTest.php tests/Feature/Registrations/SessionRosterQueryBudgetTest.php"
( . .superpowers/r2/env-pgsql.sh; export DB_DATABASE=sportclub_r2_test DB_URL=; php artisan test $FILES 2>&1 | tail -15 )
( export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3399 DB_DATABASE=sportclub_r2_test DB_USERNAME=root DB_PASSWORD= DB_URL= APP_CONFIG_CACHE="D:/irnb-branch-reg-r2/.superpowers/r2/no-config-cache.php"
  php artisan test $FILES 2>&1 | tail -15 )
```

Expected: all PASS on both. Fix a failure in the new SQL here, before Part 4 builds on it. `left_at >= ?` with a text binding and the `leftJoin` with `whereIn` are the two things to watch on PostgreSQL.

- [ ] **Step 3: Check the pinned figures on the real-data copy**

```bash
( . .superpowers/r2/env-sqlite.sh; php artisan config:show database.connections.sqlite.database; php .superpowers/r2/figures.php p3 )
php .superpowers/r2/diff-figures.php baseline p3
```

Expected: the path printed is `.superpowers/r2/data/real.sqlite`. Differences:

- `player_subscriptions`, `transactions`, `attendances`, `player_mirror`: **none from this part**. Compare with the Part 2 run too, using `diff-figures.php p2 p3`, to separate this part's differences from Part 2's.
- `rosters`: all 12 real sessions are football Senior, so a two-branch player's football row decides. Expect none, or a two-branch line traceable to spec section 6.
- **One allowed `UNEXPECTED` kind:** `rosters N gained player M` where the session is unmarked and the player's `left_at` equals the session's date. This is the "leave day counts" change in this part's constraints. Confirm each such line with a read-only query, and list it for the owner's report. Any other `UNEXPECTED` line is a bug to trace before moving on.

- [ ] **Step 4: Record the part**

Add to `.superpowers/r2/progress.md`: the Part 3 commits, the suite count, the figures result, and any leave-day line found. Add to `.superpowers/r2/differences.md`: "A player leaving on a session's date is now listed on that session, where the old roster stopped the day before. Spec section 3, rosters after leaving."

---

## Self-review against spec section 6

| Spec line | Task |
|---|---|
| `Roster::expected`: one registration in the branch with one of the session's categories, active on the date | P3.1 |
| A session with no branch matches any registration | P3.1 |
| The comment "a player has one category" goes | P3.1, Step 5 |
| Saved marks stay frozen; `leave()` doesn't touch them | P3.1 test. `forSession`, `MonthSheet` keep preferring marks, unchanged |
| `MarkRecorder`: new mark takes the session branch's category; by-hand with no row takes main; old marks don't change | P3.2 |
| Add-by-hand candidates and `in_branch` use active registrations | P3.3 |
| Category tags on rows (`AttendanceController`, `AttendanceSheetController`) | P3.3 |
| `PlayerNames`, at-risk, injuries, ranking, certificate eligibility; left there means not active | P3.4 |
| Branch tabs: every registration | P3.5 test, `branchTabs()` unchanged |
| Preseason target per tab uses that tab's category | P3.5 |
| Player with no registration, in every group | Tests in P3.1 to P3.5 |
| Query budget for the session roster (section 4) | P3.6 |
| Runs on SQLite, MariaDB, PostgreSQL (section 10) | P3.7 |
