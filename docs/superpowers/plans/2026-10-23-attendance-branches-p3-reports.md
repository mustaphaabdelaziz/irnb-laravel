# Attendance branches P3 (per-branch reports) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A player in two branches (sports) has separate attendance statistics per branch: every player-level number (score, counts, missed time, streaks, at-risk, ranking, injury spells, pre-season progress) comes from one branch's sessions only, and every report page, export, PDF and the profile card shows one branch at a time.

**Architecture:** One branch condition, `BranchScope::apply()` (`+training_sessions.branch_id = ?`, null = no condition), threaded as a trailing `?int $branchId = null` through `AttendanceStats`, `AtRisk`, `InjurySpells` and `PreseasonProgress::forCategories()`; it adds a `where`, never a query. `injury_notes` gains `branch_id` + virtual `branch_key` in its unique key. Report controllers resolve the branch with `BranchChoice::fromRequest()` (the calendar's remembered choice) and render a shared `BranchSwitch.vue`; the profile card resolves its own tab (`branchTabs()`) without touching the remembered choice. The dashboard counts players flagged in any branch via `AtRisk::anyBranch()`.

**Tech Stack:** Laravel 13, PHPUnit feature tests (sqlite :memory:), Inertia + Vue 3 `<script setup>`, Tailwind, vue-i18n flat keys, mPDF.

**Spec:** `docs/superpowers/specs/2026-10-23-attendance-branches-design.md` (package 3, section "Reports").

## Global Constraints

- Work only in worktree `D:/irnb-reports`, branch `feat/attendance-branch-reports`. Never write to `D:/irnb-laravel` (Task 8 only copies its dev DB, read-only, into the scratchpad). Stage explicit file paths only (never `git add -A` / `git add .`).
- Null `branch_id` = the whole club = no branch condition (only reachable in a club without branches; same meaning as `Roster`, `CalendarFeed`, `PreseasonProgress`).
- Migrations: no DB foreign-key constraint on `branch_id`; never rebuild an sqlite table; explicit index names ≤ 64 characters; every step idempotent (`Schema::hasColumn` / `Schema::hasIndex` guards) so a half-failed MySQL run (non-transactional DDL) finishes on the next run and the desktop app's `migrate` on every boot changes nothing. Production is MySQL; tests and desktop are sqlite.
- Never run `php artisan migrate` on a real database, and never `--env=testing` (there is no `.env.testing`; it hits the dev DB). Tests use `RefreshDatabase` on sqlite :memory:.
- Run `php artisan config:clear` before tests in the worktree. If Inertia tests fail with ViteManifestNotFound, run `npm run build` once.
- A test file takes ~90 s: iterate with `--filter=<Class>`. Before every commit, run the FULL suite `php artisan test` (~7 min, run it in the background): shared services and constants break unrelated tests. A red test anywhere is the task's to fix before committing.
- Test helpers are never named `session()` or `seed()`.
- New UI strings only through `node scripts/i18n-add.mjs <json>` with the JSON written in `C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/f5180bdf-51af-4cd4-a47b-5d8543b2a1bb/scratchpad/`; never overwrite an existing key; then `npm run i18n:check`. This plan needs **no new key** (the switch and PDFs reuse `branch`); if a step ends up needing one, use the script.
- `npm run build` after any Vue change; `npm run test:js` must stay green.
- Offline only: no CDN or remote resource.
- PDFs (mPDF, Arabic UI, right-to-left): plain text in cells, Latin/number runs wrapped in `<bdi dir="ltr">` as the existing views do; no flex/grid CSS; check one PDF in Arabic by eye in Task 8.
- Every commit message ends with the trailer line `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Decisions made while planning

1. Null branch = no branch condition everywhere (not `branch_id is null`): equivalent in a club without branches, and consistent with packages 1–2.
2. A mark counts for its session's branch whatever the player's membership: a guest hand-added to a football session (a swimmer) appears on football's stats, ranking, alerts and injuries with those marks; the category filters keep using the player's current category.
3. Profile card tabs = branches the player is a member of now ∪ branches of any of their marks (all time, so tabs do not change with the period), ordered by id; tabs shown only with two or more; one-branch players get no tabs but their numbers are still that branch's.
4. Card default tab: `branch_id` asked → the remembered attendance branch if it is one of the player's → the player's first branch. The card never writes the remembered choice; `?attendance_branch=` on the profile URL opens a tab (used by report-page and dashboard links).
5. A player in no branch with no branch marks, in a club with branches: no tabs, `branchId` null (numbers over every session, which are all zero) and no pre-season line.
6. `injury_notes` unique key becomes `(player_id, branch_key, start_date)` named `injury_notes_branch_spell_unique` (`branch_key` = virtual `coalesce(branch_id, 0)`); the old `injury_notes_player_id_start_date_unique` is dropped after it (the new key starts with `player_id`, so MySQL's foreign key keeps an index).
7. Notes backfill (refines "like the other tables"): the branch of the session holding the player's injury mark on the note's start date, else the branch with the most players, else null. Notes saved between the package-1 and package-3 deploys may belong to swimming. A new migration does this; the package-1 migration is not edited (already run).
8. Saving an injury detail requires `branch_id` once the club has branches (the card sends its tab); the spell check uses that branch's spells.
9. The match call-up "injured" badge (`PlayerFlags::injured()`, not in the spec) = an open spell in any branch, unioned per branch; it stays a warning for a player injured in either sport.
10. Branch condition written `+column = ?` (`BranchScope`): real databases have no `sqlite_stat1`, and sqlite without statistics would lead by the new `*_branch_id_index` equality instead of the date index.
11. Dashboard: a player flagged in two branches counts once and is listed with the branch where they fare worst (the list's order: lowest score %, then longest streak); the name links to the profile with `?attendance_branch=`; the branch name shows only when the club has two or more branches.
12. Certificates name the branch through the existing `{category}` placeholder as "Branch · Category" (no translation edited). A single player's certificate is refused (404) unless they have marks in that branch + category or are now in the category and a member of the branch.
13. Exports and PDFs: file names gain `-b{branchId}` only when a branch is set (clubs without branches keep today's names); titles and headers name the branch.
14. Report links (letter, profile) from a branch page carry the branch (`branch_id`, `attendance_branch`).
15. Dashboard members-tab query budget goes 32 → 33 (`AtRisk::anyBranch()` reads the branch list).

---

### Task 1: Branch scope and per-branch numbers in the stats core

**Files:**
- Create: `app/Services/Attendance/BranchScope.php`
- Modify: `app/Services/Attendance/AttendanceStats.php` (`players()` 56–98, `monthly()` 134–154, `sessionsByMonth()` 157–182, `categories()` 185–240, `playerSessions()` 243–283, `unexcusedStreaks()` 297–322, `marks()` 434–445)
- Modify: `app/Services/Attendance/PreseasonProgress.php` (`forCategories()` 47–76)
- Modify: `app/Services/Attendance/AtRisk.php` (`list()` 66–99, `forPlayer()` 107–119)
- Create: `tests/Support/BranchAttendanceFixtures.php`
- Test: `tests/Feature/AttendanceBranchStatsTest.php`

**Interfaces:**
- Consumes: `training_sessions.branch_id` and `training_sessions_branch_id_index` (package 1).
- Produces:
  - `BranchScope::apply(Illuminate\Database\Query\Builder $query, ?int $branchId, string $column = 'training_sessions.branch_id'): Builder`
  - `AttendanceStats::players(string $from, string $to, ?int $categoryId = null, ?int $playerId = null, ?int $branchId = null): array`
  - `AttendanceStats::monthly(string $from, string $to, ?int $categoryId = null, ?int $playerId = null, ?int $branchId = null): array`
  - `AttendanceStats::sessionsByMonth(string $from, string $to, ?int $categoryId = null, ?int $branchId = null): array`
  - `AttendanceStats::categories(string $from, string $to, ?int $branchId = null): array`
  - `AttendanceStats::playerSessions(int $playerId, string $from, string $to, ?int $branchId = null): array`
  - `AttendanceStats::unexcusedStreaks(string $from, string $to, ?int $playerId = null, ?int $branchId = null): array`
  - `PreseasonProgress::forCategories(DateTimeInterface|string $date, ?int $branchId = null): array`
  - `AtRisk::list(string $from, string $to, ?int $categoryId = null, ?int $branchId = null): array`
  - `AtRisk::forPlayer(int $playerId, string $from, string $to, array $row, ?int $branchId = null): array`
  - Test trait `Tests\Support\BranchAttendanceFixtures`: `twoSports()`, `branchSession()`, `branchMark()`, `twoSportClub()`, `injuredFootballer()`, `spyPdf()`.
- Query counts: unchanged (a `where`, no query). Guarded by `AttendanceStatsTest::the_query_count_does_not_grow_with_the_roster`, `AttendanceStreaksTest::one_query_whatever_the_roster`, `AttendanceAtRiskTest::the_list_costs_the_same_queries_for_3_or_30_players_at_risk` and the new `the_branch_adds_no_query`.

- [ ] **Step 1: Write the shared test trait**

`tests/Support/BranchAttendanceFixtures.php`:

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
use App\Services\Pdf\PdfService;
use Mockery\MockInterface;

/**
 * A two-sport club for the per-branch report tests (use with
 * AttendanceFixtures). Branches are created before any session: the club's
 * first branch adopts club-wide rows (BranchObserver).
 */
trait BranchAttendanceFixtures
{
    /** @return array{0: Branch, 1: Branch} football (lower id), swimming */
    private function twoSports(): array
    {
        return [Branch::create(['name' => 'Football']), Branch::create(['name' => 'Swimming'])];
    }

    private function branchSession(Branch $branch, Category $category, string $date, array $extra = []): TrainingSession
    {
        return TrainingSession::create(array_merge([
            'branch_id' => $branch->id, 'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ], $extra));
    }

    private function branchMark(TrainingSession $training, Player $player, AttendanceStatus $status, ?string $reason = null): void
    {
        Attendance::create([
            'training_session_id' => $training->id, 'player_id' => $player->id, 'category_id' => $player->category_id,
            'status' => $status, 'reason' => $reason,
        ]);
    }

    /**
     * U15, five Mondays (2026-09-07 → 10-05), each with a football and a
     * swimming session at 18:00 (football created first, so it sorts first).
     * "both" (member of both): football AN, AN, AN, P, P (0 %, streak 3);
     * swimming P × 5 (100 %). "footer" (football only): P × 5. "swimmer"
     * (swimming only): P × 5. Mixed together, "both" would be 10 expected,
     * 40 %, longest streak 1 (each swimming mark breaks it).
     *
     * @return array{foot: Branch, swim: Branch, u15: Category, both: Player, footer: Player, swimmer: Player}
     */
    private function twoSportClub(): array
    {
        [$foot, $swim] = $this->twoSports();
        $u15 = $this->category('U15');
        $both = $this->player($u15);
        $footer = $this->player($u15);
        $swimmer = $this->player($u15);
        $foot->players()->attach([$both->id, $footer->id]);
        $swim->players()->attach([$both->id, $swimmer->id]);
        foreach (['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05'] as $i => $date) {
            $football = $this->branchSession($foot, $u15, $date);
            $swimming = $this->branchSession($swim, $u15, $date);
            $this->branchMark($football, $both, $i < 3 ? AttendanceStatus::AbsentUnexcused : AttendanceStatus::Present);
            $this->branchMark($football, $footer, AttendanceStatus::Present);
            $this->branchMark($swimming, $both, AttendanceStatus::Present);
            $this->branchMark($swimming, $swimmer, AttendanceStatus::Present);
        }

        return compact('foot', 'swim', 'u15', 'both', 'footer', 'swimmer');
    }

    /**
     * U15 "both" plays football and swims. Football: 10-01 P, 10-05 not
     * training (injury), 10-12 excused (injury) — the latest football mark,
     * so the football spell 10-05 → 10-12 is open. Swimming: 10-08 P, 10-14 P.
     * Mixed together the swimming marks would cut it into two closed spells.
     *
     * @return array{foot: Branch, swim: Branch, u15: Category, both: Player}
     */
    private function injuredFootballer(): array
    {
        [$foot, $swim] = $this->twoSports();
        $u15 = $this->category('U15');
        $both = $this->player($u15);
        $foot->players()->attach($both->id);
        $swim->players()->attach($both->id);
        $this->branchMark($this->branchSession($foot, $u15, '2026-10-01'), $both, AttendanceStatus::Present);
        $this->branchMark($this->branchSession($foot, $u15, '2026-10-05'), $both, AttendanceStatus::NotTraining, 'injury');
        $this->branchMark($this->branchSession($swim, $u15, '2026-10-08'), $both, AttendanceStatus::Present);
        $this->branchMark($this->branchSession($foot, $u15, '2026-10-12'), $both, AttendanceStatus::AbsentExcused, 'injury');
        $this->branchMark($this->branchSession($swim, $u15, '2026-10-14'), $both, AttendanceStatus::Present);

        return compact('foot', 'swim', 'u15', 'both');
    }

    /** Swaps mPDF for a spy; returns a reference filled with what stream() received. */
    private function spyPdf(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $html, string $filename, bool $rtl = true, bool $landscape = false) use ($seen) {
                $seen['html'] = $html;
                $seen['filename'] = $filename;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        return $seen;
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/AttendanceBranchStatsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\PreseasonTarget;
use App\Services\Attendance\AtRisk;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\BranchScope;
use App\Services\Attendance\PreseasonProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceBranchStatsTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    private const FROM = '2026-09-01';

    private const TO = '2026-10-31';

    private function stats(): AttendanceStats
    {
        return app(AttendanceStats::class);
    }

    #[Test]
    public function a_two_sport_players_numbers_come_from_one_branch_at_a_time(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->twoSportClub();

        $football = $this->stats()->players(self::FROM, self::TO, null, $both->id, $foot->id)[$both->id];
        $swimming = $this->stats()->players(self::FROM, self::TO, null, $both->id, $swim->id)[$both->id];
        $mixed = $this->stats()->players(self::FROM, self::TO, null, $both->id)[$both->id];

        $this->assertSame(5, $football['expected']);
        $this->assertSame(3, $football['counts']['absent_unexcused']);
        $this->assertSame(0.0, $football['score_pct']);
        $this->assertSame(270, $football['missed_minutes']);   // 3 × 90 min
        $this->assertSame(5, $swimming['expected']);
        $this->assertSame(0, $swimming['counts']['absent_unexcused']);
        $this->assertSame(100.0, $swimming['score_pct']);
        $this->assertSame(0, $swimming['missed_minutes']);
        // No branch = no condition (a club without branches): every session.
        $this->assertSame(10, $mixed['expected']);
        $this->assertSame(40.0, $mixed['score_pct']);
    }

    #[Test]
    public function months_sessions_list_and_streaks_follow_the_branch(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->twoSportClub();
        $stats = $this->stats();

        $this->assertSame([3, 0], $stats->monthly(self::FROM, self::TO, null, $both->id, $foot->id)['statuses']['absent_unexcused']);
        $this->assertSame([4, 1], $stats->monthly(self::FROM, self::TO, null, $both->id, $swim->id)['statuses']['present']);
        $this->assertSame([0, 0], $stats->monthly(self::FROM, self::TO, null, $both->id, $swim->id)['statuses']['absent_unexcused']);

        $swimSessions = $stats->playerSessions($both->id, self::FROM, self::TO, $swim->id);
        $this->assertCount(5, $swimSessions);
        $this->assertSame(['present'], array_values(array_unique(array_column($swimSessions, 'status'))));

        $this->assertSame(3, $stats->unexcusedStreaks(self::FROM, self::TO, $both->id, $foot->id)[$both->id]['longest']);
        $this->assertSame(0, $stats->unexcusedStreaks(self::FROM, self::TO, $both->id, $swim->id)[$both->id]['longest']);
        // Mixed, each swimming mark would break the football streak.
        $this->assertSame(1, $stats->unexcusedStreaks(self::FROM, self::TO, $both->id)[$both->id]['longest']);
    }

    #[Test]
    public function sessions_categories_and_preseason_count_one_branch(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'u15' => $u15] = $this->twoSportClub();
        $this->branchSession($swim, $u15, '2026-10-12', ['state' => SessionState::Cancelled, 'cancel_reason' => 'Pool closed']);
        $this->branchSession($foot, $u15, '2026-10-03', ['kind' => SessionKind::Preseason]);
        PreseasonTarget::create(['branch_id' => $foot->id, 'category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 10]);
        PreseasonTarget::create(['branch_id' => $swim->id, 'category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 4]);
        $stats = $this->stats();

        $swimMonths = $stats->sessionsByMonth(self::FROM, self::TO, null, $swim->id);
        $this->assertSame([4, 1], $swimMonths['held']);
        $this->assertSame([0, 1], $swimMonths['cancelled']);
        $this->assertSame([4, 2], $stats->sessionsByMonth(self::FROM, self::TO, $u15->id, $foot->id)['held']);

        $football = $stats->categories(self::FROM, self::TO, $foot->id)[0];
        $this->assertSame(6, $football['held']);
        $this->assertSame(0, $football['cancelled']);
        $this->assertSame(10, $football['expected']);   // both × 5 + footer × 5
        $this->assertSame(1, $football['preseason']['done']);
        $this->assertSame(10, $football['preseason']['target']);

        $swimming = $stats->categories(self::FROM, self::TO, $swim->id)[0];
        $this->assertSame(5, $swimming['held']);
        $this->assertSame(1, $swimming['cancelled']);
        $this->assertSame(0, $swimming['preseason']['done']);
        $this->assertSame(4, $swimming['preseason']['target']);

        $this->assertSame(['done' => 0, 'target' => 4], array_intersect_key(
            app(PreseasonProgress::class)->forCategories('2026-10-05', $swim->id)[$u15->id],
            ['done' => 0, 'target' => 0],
        ));
    }

    #[Test]
    public function a_guest_from_another_branch_counts_in_the_sessions_branch(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'u15' => $u15, 'swimmer' => $swimmer] = $this->twoSportClub();
        $this->branchMark($this->branchSession($foot, $u15, '2026-10-12'), $swimmer, AttendanceStatus::Present);

        $this->assertSame(1, $this->stats()->players(self::FROM, self::TO, null, null, $foot->id)[$swimmer->id]['expected']);
        $this->assertSame(5, $this->stats()->players(self::FROM, self::TO, null, null, $swim->id)[$swimmer->id]['expected']);
    }

    #[Test]
    public function at_risk_flags_come_from_one_branch(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->twoSportClub();
        $risk = app(AtRisk::class);

        $football = $risk->list(self::FROM, self::TO, null, $foot->id);
        $this->assertSame([$both->id], array_column($football, 'player_id'));
        $this->assertSame(3, $football[0]['longest_streak']);
        $this->assertTrue($football[0]['streak']);
        $this->assertTrue($football[0]['low_score']);
        $this->assertSame([], $risk->list(self::FROM, self::TO, null, $swim->id));

        $swimRow = $this->stats()->players(self::FROM, self::TO, null, $both->id, $swim->id)[$both->id];
        $this->assertFalse($risk->forPlayer($both->id, self::FROM, self::TO, $swimRow, $swim->id)['at_risk']);
        $footRow = $this->stats()->players(self::FROM, self::TO, null, $both->id, $foot->id)[$both->id];
        $flags = $risk->forPlayer($both->id, self::FROM, self::TO, $footRow, $foot->id);
        $this->assertTrue($flags['streak']);
        $this->assertSame(3, $flags['longest_streak']);
    }

    #[Test]
    public function the_branch_adds_no_query(): void
    {
        ['foot' => $foot] = $this->twoSportClub();
        $count = function (?int $branchId): int {
            $stats = $this->stats();
            $stats->players(self::FROM, self::TO);   // loads the settings and the custom codes once
            DB::flushQueryLog();
            DB::enableQueryLog();
            $stats->players(self::FROM, self::TO, branchId: $branchId);
            $stats->monthly(self::FROM, self::TO, branchId: $branchId);
            $stats->sessionsByMonth(self::FROM, self::TO, branchId: $branchId);
            $stats->categories(self::FROM, self::TO, $branchId);
            $stats->unexcusedStreaks(self::FROM, self::TO, branchId: $branchId);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        Once::flush();
        $without = $count(null);
        Once::flush();

        $this->assertSame($without, $count($foot->id));
    }

    #[Test]
    public function the_branch_condition_never_leads_the_query_plan(): void
    {
        $query = DB::table('attendances')
            ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
            ->where('training_sessions.state', SessionState::Held->value)
            ->whereBetween('training_sessions.date', [self::FROM, self::TO]);
        BranchScope::apply($query, 1);

        $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings()))->pluck('detail')->implode("\n");

        $this->assertStringContainsString('+training_sessions.branch_id = ?', $query->toSql());
        $this->assertStringNotContainsString('training_sessions_branch_id_index', $plan);
        $this->assertSame(BranchScope::apply(DB::table('attendances'), null)->toSql(), DB::table('attendances')->toSql());
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan config:clear && php artisan test --filter=AttendanceBranchStatsTest`
Expected: FAIL — `Class "App\Services\Attendance\BranchScope" not found`, `Unknown named parameter $branchId`, and assertion failures (football `expected` 10 instead of 5: the extra positional argument is ignored today).

- [ ] **Step 4: Create `BranchScope`**

`app/Services/Attendance/BranchScope.php`:

```php
<?php

namespace App\Services\Attendance;

use Illuminate\Database\Query\Builder;

/**
 * The one branch condition of the attendance reports. A mark belongs to its
 * session's branch (`training_sessions.branch_id`), so a two-sport player's
 * numbers are computed per sport. Null = no condition: a club without
 * branches, where every session is club-wide.
 *
 * Written `+column = ?` on purpose. Real databases have no sqlite_stat1
 * (never ANALYZEd), and without statistics sqlite leads by an equality on an
 * indexed column over a date range: a plain `branch_id = ?` would walk the
 * branch's whole history through `training_sessions_branch_id_index`
 * instead of the period through the date index (and, in InjurySpells'
 * per-player "latest mark" probes, sort it for every player). The unary plus
 * keeps the index out of reach on sqlite and is a no-op on MySQL.
 */
final class BranchScope
{
    public static function apply(Builder $query, ?int $branchId, string $column = 'training_sessions.branch_id'): Builder
    {
        return $branchId === null ? $query : $query->whereRaw('+'.$column.' = ?', [$branchId]);
    }
}
```

- [ ] **Step 5: Thread the branch through `AttendanceStats`**

Class doc (after the "Every method runs a fixed number of grouped queries" paragraph) — add:

```php
 * Per branch: every method takes a trailing ?int $branchId. A mark belongs
 * to its session's branch (BranchScope), whatever the player's membership,
 * so a two-sport player has separate numbers per sport and a guest from
 * another branch counts in the session's. Null = no branch condition (a
 * club without branches). The branch adds a condition, never a query.
```

`players()` (lines 56–61, 82):

```php
    public function players(string $from, string $to, ?int $categoryId = null, ?int $playerId = null, ?int $branchId = null): array
    {
        $settings = $this->settings();
        $longLate = max(0, (int) $settings['rules']['late_minutes_as_absent']);

        $grouped = $this->marks($from, $to, $categoryId, $playerId, $branchId)
```

and

```php
        $missed = $this->missedMinutes($this->marks($from, $to, $categoryId, $playerId, $branchId), 'attendances.player_id');
```

`monthly()` (lines 134, 140):

```php
    public function monthly(string $from, string $to, ?int $categoryId = null, ?int $playerId = null, ?int $branchId = null): array
```

```php
        $rows = $this->marks($from, $to, $categoryId, $playerId, $branchId)
```

`sessionsByMonth()` (lines 157, 165–168):

```php
    public function sessionsByMonth(string $from, string $to, ?int $categoryId = null, ?int $branchId = null): array
```

```php
        $rows = DB::table('training_sessions')
            ->whereBetween('training_sessions.date', [$from, $to])
            ->whereIn('training_sessions.state', [$held, $cancelled])
            ->when($categoryId !== null, fn (Builder $q) => $q->whereIn('training_sessions.id', $this->sessionsOf($categoryId)))
            ->tap(fn (Builder $q) => BranchScope::apply($q, $branchId))
```

`categories()` — before (lines 185–196):

```php
    public function categories(string $from, string $to): array
    {
        // Each mark exactly once, under the category it is attributed to
        // (see the class comment and attributedTo()).
        $attributed = fn (): Builder => $this->attributedTo(
            DB::table('attendances')
                ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
                ->join('training_session_category', 'training_session_category.training_session_id', '=', 'attendances.training_session_id')
                ->where('training_sessions.state', SessionState::Held->value)
                ->whereBetween('training_sessions.date', [$from, $to]),
            'training_session_category.category_id',
        );
```

after:

```php
    public function categories(string $from, string $to, ?int $branchId = null): array
    {
        // Each mark exactly once, under the category it is attributed to
        // (see the class comment and attributedTo()).
        $attributed = fn (): Builder => $this->attributedTo(
            BranchScope::apply(DB::table('attendances')
                ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
                ->join('training_session_category', 'training_session_category.training_session_id', '=', 'attendances.training_session_id')
                ->where('training_sessions.state', SessionState::Held->value)
                ->whereBetween('training_sessions.date', [$from, $to]), $branchId),
            'training_session_category.category_id',
        );
```

and in the same method, before (lines 215–218) / after:

```php
        $byState = DB::table('training_sessions')
            ->join('training_session_category', 'training_session_category.training_session_id', '=', 'training_sessions.id')
            ->whereBetween('training_sessions.date', [$from, $to])
            ->whereIn('training_sessions.state', [SessionState::Held->value, SessionState::Cancelled->value])
```

```php
        $byState = DB::table('training_sessions')
            ->join('training_session_category', 'training_session_category.training_session_id', '=', 'training_sessions.id')
            ->whereBetween('training_sessions.date', [$from, $to])
            ->whereIn('training_sessions.state', [SessionState::Held->value, SessionState::Cancelled->value])
            ->tap(fn (Builder $q) => BranchScope::apply($q, $branchId))
```

and line 227:

```php
        $preseason = $this->preseason->forCategories($to, $branchId);
```

`playerSessions()` (lines 243–245):

```php
    public function playerSessions(int $playerId, string $from, string $to, ?int $branchId = null): array
    {
        $marks = $this->marks($from, $to, null, $playerId, $branchId)
```

`unexcusedStreaks()` (lines 297–299):

```php
    public function unexcusedStreaks(string $from, string $to, ?int $playerId = null, ?int $branchId = null): array
    {
        $marks = $this->marks($from, $to, null, $playerId, $branchId)
```

`marks()` — replace lines 433–445 with:

```php
    /** Marks in held sessions of the period, optionally one player's, one category's and/or one branch's. */
    private function marks(string $from, string $to, ?int $categoryId, ?int $playerId, ?int $branchId = null): Builder
    {
        return BranchScope::apply(DB::table('attendances')
            ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
            ->where('training_sessions.state', SessionState::Held->value)
            ->whereBetween('training_sessions.date', [$from, $to])
            ->when($playerId !== null, fn (Builder $q) => $q->where('attendances.player_id', $playerId))
            ->when($categoryId !== null, fn (Builder $q) => $this->attributedTo(
                $q->whereIn('attendances.training_session_id', $this->sessionsOf($categoryId)),
                $categoryId,
            )), $branchId);
    }
```

- [ ] **Step 6: `PreseasonProgress::forCategories()` per branch**

Replace lines 161–196 (doc + method) with:

```php
    /**
     * Every category's progress for the season of $date in three queries
     * (the statistics page lists all categories at once), in one branch:
     * its held pre-season sessions and its target. Null = every branch's
     * sessions and, per category, the highest target any branch set.
     *
     * @return array<int, array{season: string, done: int, target: ?int}> keyed by category id
     */
    public function forCategories(DateTimeInterface|string $date, ?int $branchId = null): array
    {
        $season = Season::forDate($date);

        $done = DB::table('training_session_category')
            ->join('training_sessions', 'training_sessions.id', '=', 'training_session_category.training_session_id')
            ->where('training_sessions.kind', SessionKind::Preseason->value)
            ->where('training_sessions.state', SessionState::Held->value)
            ->whereBetween('training_sessions.date', [$season->start()->toDateString(), $season->end()->toDateString()])
            ->tap(fn ($q) => BranchScope::apply($q, $branchId))
            ->select('training_session_category.category_id')
            ->selectRaw('count(*) as total')
            ->groupBy('training_session_category.category_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->category_id => (int) $row->total]);

        // One target per category: the branch's, or the highest across branches (as milestones() reads it).
        $targets = PreseasonTarget::where('season_start_year', $season->startYear)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('category_id, max(target_count) as target_count')
            ->groupBy('category_id')
            ->pluck('target_count', 'category_id')
            ->mapWithKeys(fn ($count, $categoryId) => [(int) $categoryId => (int) $count]);

        return Category::orderBy('id')->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => [
                'season' => $season->label(),
                'done' => $done[(int) $id] ?? 0,
                'target' => $targets[(int) $id] ?? null,
            ]])
            ->all();
    }
```

- [ ] **Step 7: Thread the branch through `AtRisk`**

`list()` — signature and its two reads (lines 66–72):

```php
    public function list(string $from, string $to, ?int $categoryId = null, ?int $branchId = null): array
    {
        $thresholds = $this->thresholds();
        $streaks = $this->stats->unexcusedStreaks($from, $to, null, $branchId);

        $flagged = [];
        foreach ($this->stats->players($from, $to, null, null, $branchId) as $playerId => $row) {
```

Its doc gains, after "worst first: lowest score % (none last), then longest streak, then name.": `Numbers come from one branch's sessions ($branchId; null = no condition).`

`forPlayer()` (lines 107–109):

```php
    public function forPlayer(int $playerId, string $from, string $to, array $row, ?int $branchId = null): array
    {
        $streak = $this->stats->unexcusedStreaks($from, $to, $playerId, $branchId)[$playerId] ?? self::NO_STREAK;
```

Its doc: `$row is the card's own players() row (or emptyRow()), for the same branch.`

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan config:clear && php artisan test --filter="AttendanceBranchStatsTest|AttendanceStatsTest|AttendanceStreaksTest|AttendanceAtRiskTest"`
Expected: PASS.

- [ ] **Step 9: Full suite**

Run (background): `php artisan test` → all green.

- [ ] **Step 10: Commit**

```bash
git add app/Services/Attendance/BranchScope.php app/Services/Attendance/AttendanceStats.php app/Services/Attendance/PreseasonProgress.php app/Services/Attendance/AtRisk.php tests/Support/BranchAttendanceFixtures.php tests/Feature/AttendanceBranchStatsTest.php
git commit -m "$(cat <<'EOF'
feat(attendance): statistics and at-risk flags per branch in the services

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Injury spells and details per branch

**Files:**
- Create: `database/migrations/2026_10_23_300001_add_branch_to_injury_notes.php`
- Modify: `app/Models/InjuryNote.php` (doc, `$fillable`)
- Modify: `app/Observers/BranchObserver.php` (adoption list)
- Modify: `app/Http/Controllers/BranchController.php:92-96` (`destroy` deletes the branch's details)
- Modify: `app/Services/Attendance/InjurySpells.php` (class doc; `all()` 103–106, `forPlayer()` 139–160, `club()` 172–229, `marks()` 244–254, `lastNonInjuryMarkBefore()` 262–279, `injuryMarksBetween()` 311–322, `latestMarkIsInjury()` 339–361)
- Modify: `app/Http/Controllers/AttendanceInjuryController.php` (`store()` 55–81)
- Modify: `app/Services/Competitions/PlayerFlags.php` (`injured()` 22–28)
- Test: `tests/Feature/AttendanceBranchInjuriesTest.php`

**Interfaces:**
- Consumes: Task 1 `BranchScope::apply()`, trait `BranchAttendanceFixtures`.
- Produces:
  - Column `injury_notes.branch_id` (nullable unsigned bigint, index `injury_notes_branch_id_index`), virtual `injury_notes.branch_key`, unique `injury_notes_branch_spell_unique (player_id, branch_key, start_date)`; old `injury_notes_player_id_start_date_unique` dropped. Migration object has public `backfill(): void`.
  - `InjurySpells::all(int $playerId, ?int $branchId = null): array`
  - `InjurySpells::forPlayer(int $playerId, string $from, string $to, ?int $branchId = null): array`
  - `InjurySpells::club(string $from, string $to, ?int $categoryId = null, ?int $branchId = null): array`
  - `POST attendance.injury-notes.store` accepts `branch_id` (required once a branch exists).
  - `PlayerFlags::injured()`: open spell in any branch.
- Query counts: `club()` unchanged (five queries; guarded by `AttendanceInjurySpellsTest::the_club_list_runs_a_fixed_number_of_queries` and the new `the_branch_adds_no_query_to_the_club_list`). `PlayerFlags::injured()`: 1 + 5 per branch.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceBranchInjuriesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InjuryNote;
use App\Services\Attendance\InjurySpells;
use App\Services\Competitions\PlayerFlags;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceBranchInjuriesTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    private const FROM = '2026-09-01';

    private const TO = '2027-08-31';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function spells(): InjurySpells
    {
        return app(InjurySpells::class);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_23_300001_add_branch_to_injury_notes.php');
    }

    #[Test]
    public function a_spell_is_built_from_one_branchs_marks(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->injuredFootballer();

        $this->assertSame(
            [['start' => '2026-10-05', 'end' => '2026-10-12', 'sessions' => 2, 'open' => true]],
            $this->spells()->all($both->id, $foot->id),
        );
        $this->assertSame([], $this->spells()->all($both->id, $swim->id));
        // Mixed, the swimming marks would cut the spell in two and close it.
        $this->assertSame([false, false], array_column($this->spells()->all($both->id), 'open'));
    }

    #[Test]
    public function the_club_list_is_one_branchs(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->injuredFootballer();

        $football = $this->spells()->club(self::FROM, self::TO, null, $foot->id);
        $this->assertSame([$both->id], array_column($football['current'], 'player_id'));
        $this->assertSame('2026-10-05', $football['current'][0]['start']);

        $swimming = $this->spells()->club(self::FROM, self::TO, null, $swim->id);
        $this->assertSame([], $swimming['current']);
        $this->assertSame([], $swimming['spells']);
    }

    #[Test]
    public function details_are_kept_per_branch(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->injuredFootballer();
        InjuryNote::create(['player_id' => $both->id, 'branch_id' => $foot->id, 'start_date' => '2026-10-05', 'body_part' => 'Knee']);
        InjuryNote::create(['player_id' => $both->id, 'branch_id' => $swim->id, 'start_date' => '2026-10-05', 'body_part' => 'Shoulder']);

        $football = $this->spells()->forPlayer($both->id, self::FROM, self::TO, $foot->id);
        $this->assertSame('Knee', $football['spells'][0]['note']['body_part']);
        $this->assertSame([], $football['unmatched']);

        $swimming = $this->spells()->forPlayer($both->id, self::FROM, self::TO, $swim->id);
        $this->assertSame([], $swimming['spells']);
        $this->assertSame(['Shoulder'], array_column($swimming['unmatched'], 'body_part'));

        $this->assertTrue(Schema::hasIndex('injury_notes', 'injury_notes_branch_spell_unique'));
        $this->assertFalse(Schema::hasIndex('injury_notes', 'injury_notes_player_id_start_date_unique'));
        $this->expectException(UniqueConstraintViolationException::class);
        InjuryNote::create(['player_id' => $both->id, 'branch_id' => $foot->id, 'start_date' => '2026-10-05']);
    }

    #[Test]
    public function the_card_saves_a_detail_on_its_branchs_spell(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->injuredFootballer();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('attendance.injury-notes.store', $both), ['start_date' => '2026-10-05', 'branch_id' => $foot->id, 'body_part' => 'Knee'])
            ->assertCreated()
            ->assertJsonPath('note.body_part', 'Knee');
        $this->assertSame($foot->id, InjuryNote::sole()->branch_id);

        // No swimming spell starts that day.
        $this->actingAs($admin)->postJson(route('attendance.injury-notes.store', $both), ['start_date' => '2026-10-05', 'branch_id' => $swim->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
        // Once the club has branches, a detail names its branch.
        $this->actingAs($admin)->postJson(route('attendance.injury-notes.store', $both), ['start_date' => '2026-10-05'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    }

    #[Test]
    public function the_backfill_gives_a_detail_its_spells_branch_else_the_largest(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'u15' => $u15, 'both' => $both] = $this->injuredFootballer();
        // Swimming has the most players, so only the injury mark can send a detail to football.
        $swim->players()->attach([$this->player($u15)->id, $this->player($u15)->id]);
        $onSpell = InjuryNote::create(['player_id' => $both->id, 'start_date' => '2026-10-05']);
        $noMark = InjuryNote::create(['player_id' => $both->id, 'start_date' => '2026-09-15']);

        $this->migration()->up();   // a second run: every step finds its work done, then backfills

        $this->assertSame($foot->id, $onSpell->fresh()->branch_id);
        $this->assertSame($swim->id, $noMark->fresh()->branch_id);
    }

    #[Test]
    public function without_branches_the_backfill_leaves_details_club_wide(): void
    {
        $note = InjuryNote::create(['player_id' => $this->player($this->category())->id, 'start_date' => '2026-10-05']);

        $this->migration()->backfill();

        $this->assertNull($note->fresh()->branch_id);
    }

    #[Test]
    public function the_clubs_first_branch_adopts_club_wide_details(): void
    {
        $note = InjuryNote::create(['player_id' => $this->player($this->category())->id, 'start_date' => '2026-10-05']);

        $branch = Branch::create(['name' => 'Football']);

        $this->assertSame($branch->id, $note->fresh()->branch_id);
    }

    #[Test]
    public function deleting_an_unused_branch_drops_its_details(): void
    {
        $this->twoSports();
        $tennis = Branch::create(['name' => 'Tennis']);
        InjuryNote::create(['player_id' => $this->player($this->category())->id, 'branch_id' => $tennis->id, 'start_date' => '2026-10-05']);

        $this->actingAs($this->admin())->delete(route('branches.destroy', $tennis))->assertSessionHas('success');

        $this->assertSame(0, InjuryNote::count());
    }

    #[Test]
    public function the_match_badge_flags_a_player_injured_in_any_branch(): void
    {
        ['both' => $both] = $this->injuredFootballer();

        $this->assertArrayHasKey($both->id, app(PlayerFlags::class)->injured());
    }

    #[Test]
    public function the_branch_adds_no_query_to_the_club_list(): void
    {
        ['foot' => $foot] = $this->injuredFootballer();
        $count = function (?int $branchId): int {
            $spells = $this->spells();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $spells->club(self::FROM, self::TO, null, $branchId);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->assertSame($count(null), $count($foot->id));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan config:clear && php artisan test --filter=AttendanceBranchInjuriesTest`
Expected: FAIL — migration file missing; `table injury_notes has no column named branch_id`; `all()` with a branch still returns the mixed spells.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_10_23_300001_add_branch_to_injury_notes.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Injury details per branch. A two-sport player's football and swimming
 * spells are separate (InjurySpells), so a detail is keyed by player,
 * branch and the spell's start. Same rules as
 * 2026_10_23_100001_add_branch_to_attendance_tables: `branch_id` null = the
 * whole club, no foreign-key constraint (BranchController::destroy() deletes
 * a branch's details), and the unique key on the virtual `branch_key`
 * (0 = whole club) since NULLs never collide in a unique index.
 *
 * Every step checks its own work, so a run that failed half way (MySQL DDL
 * is not transactional) finishes on the next run, and a second run changes
 * nothing. Literal status values, not the enums: a migration must not move
 * when the code does.
 */
return new class extends Migration
{
    private const UNIQUE = 'injury_notes_branch_spell_unique';

    private const OLD_UNIQUE = 'injury_notes_player_id_start_date_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('injury_notes', 'branch_id')) {
            Schema::table('injury_notes', fn (Blueprint $t) => $t->unsignedBigInteger('branch_id')->nullable());
        }
        if (! Schema::hasIndex('injury_notes', 'injury_notes_branch_id_index')) {
            Schema::table('injury_notes', fn (Blueprint $t) => $t->index('branch_id', 'injury_notes_branch_id_index'));
        }
        if (! Schema::hasColumn('injury_notes', 'branch_key')) {
            Schema::table('injury_notes', fn (Blueprint $t) => $t->unsignedBigInteger('branch_key')->virtualAs('coalesce(branch_id, 0)'));
        }
        // Starts with player_id: MySQL's player_id foreign key keeps an index once the old key is gone.
        if (! Schema::hasIndex('injury_notes', self::UNIQUE)) {
            Schema::table('injury_notes', fn (Blueprint $t) => $t->unique(['player_id', 'branch_key', 'start_date'], self::UNIQUE));
        }
        if (Schema::hasIndex('injury_notes', self::OLD_UNIQUE)) {
            Schema::table('injury_notes', fn (Blueprint $t) => $t->dropUnique(self::OLD_UNIQUE));
        }

        $this->backfill();
    }

    /**
     * Every detail saved before details had a branch goes to the branch of
     * the session holding the player's injury mark on the spell's start (its
     * first mark; earliest session that day), else to the branch with the
     * most players (ties: lowest id), as the other attendance tables were.
     * No branch at all: stays null. Few rows, one lookup each.
     */
    public function backfill(): void
    {
        $largest = DB::table('branches')
            ->leftJoin('branch_player', 'branch_player.branch_id', '=', 'branches.id')
            ->groupBy('branches.id')
            ->orderByRaw('count(branch_player.player_id) desc')
            ->orderBy('branches.id')
            ->value('branches.id');
        if ($largest === null) {
            return;
        }

        $notes = DB::table('injury_notes')->whereNull('branch_id')->orderBy('id')->get(['id', 'player_id', 'start_date']);
        foreach ($notes as $note) {
            $branchId = DB::table('attendances')
                ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
                ->where('attendances.player_id', $note->player_id)
                ->where('training_sessions.date', $note->start_date)
                ->where('training_sessions.state', 'held')
                ->whereNotNull('training_sessions.branch_id')
                ->where(fn ($q) => $q->where('attendances.status', 'not_training')
                    ->orWhere(fn ($q) => $q->where('attendances.status', 'absent_excused')->where('attendances.reason', 'injury')))
                ->orderBy('training_sessions.start_time')
                ->orderBy('training_sessions.id')
                ->value('training_sessions.branch_id');
            DB::table('injury_notes')->where('id', $note->id)->update(['branch_id' => $branchId ?? $largest]);
        }
    }

    public function down(): void
    {
        Schema::table('injury_notes', function (Blueprint $t) {
            $t->unique(['player_id', 'start_date'], self::OLD_UNIQUE);
            $t->dropUnique(self::UNIQUE);
        });
        Schema::table('injury_notes', fn (Blueprint $t) => $t->dropColumn('branch_key'));
        Schema::table('injury_notes', function (Blueprint $t) {
            $t->dropIndex('injury_notes_branch_id_index');
            $t->dropColumn('branch_id');
        });
    }
};
```

- [ ] **Step 4: Model, observer, branch delete**

`app/Models/InjuryNote.php` — doc and fillable:

```php
/**
 * Details of one injury spell (see InjurySpells), keyed by the player, the
 * branch whose marks build the spell (null = the whole club) and the
 * spell's start date. Dates are 'Y-m-d' strings; no date casts.
 */
class InjuryNote extends Model
{
    use SerializesClubDates;

    protected $fillable = ['player_id', 'branch_id', 'start_date', 'body_part', 'description', 'returned_on', 'created_by'];
```

`app/Observers/BranchObserver.php` — the adoption block becomes:

```php
        // The club's first branch adopts every club-wide attendance row, as the
        // migrations' backfills do (2026_10_23_100001_add_branch_to_attendance_tables,
        // 2026_10_23_300001_add_branch_to_injury_notes).
        if (Branch::count() === 1) {
            foreach (['training_sessions', 'training_schedules', 'deleted_session_slots', 'preseason_targets', 'injury_notes'] as $table) {
                DB::table($table)->whereNull('branch_id')->update(['branch_id' => $branch->id]);
            }
        }
```

`app/Http/Controllers/BranchController.php` — the transaction in `destroy()` (lines 92–96):

```php
        DB::transaction(function () use ($branch) {
            PreseasonTarget::where('branch_id', $branch->id)->delete();
            DB::table('deleted_session_slots')->where('branch_id', $branch->id)->delete();
            // Only stale details can remain: a branch with sessions is refused above.
            DB::table('injury_notes')->where('branch_id', $branch->id)->delete();
            $branch->delete();
        });
```

- [ ] **Step 5: `InjurySpells` per branch**

No import needed (`BranchScope` is in the same namespace). Class doc — append a paragraph before the closing `*/`:

```php
 *
 * Per branch: every public method takes a trailing ?int $branchId. Spells
 * are built from the marks of that branch's sessions only (BranchScope), so
 * a football spell ends only with a later football mark and a swimming mark
 * never touches it; details are matched within the branch. Null = no branch
 * condition (a club without branches). The branch adds conditions, never a
 * query.
```

`all()`:

```php
    /** @return list<array{start: string, end: string, sessions: int, open: bool}> the player's whole history in the branch, oldest first */
    public function all(int $playerId, ?int $branchId = null): array
    {
        return self::fromMarks($this->marks($branchId)->where('attendances.player_id', $playerId)->cursor(), $this->skipped())[$playerId] ?? [];
    }
```

`forPlayer()` — signature and the two lines that read:

```php
    public function forPlayer(int $playerId, string $from, string $to, ?int $branchId = null): array
    {
        $spells = $this->all($playerId, $branchId);
        $notes = InjuryNote::where('player_id', $playerId)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('start_date')
            ->get();
```

(the rest of the method is unchanged; its doc gains "in one branch").

`club()` — signature and body changes (before → after):

```php
    public function club(string $from, string $to, ?int $categoryId = null): array
```

```php
    public function club(string $from, string $to, ?int $categoryId = null, ?int $branchId = null): array
```

```php
                ->whereIn('p.id', $this->injuryMarksBetween($from, $to))
                ->orWhereIn('p.id', $this->latestMarkIsInjury())
                ->orWhereIn('p.id', $this->latestMarkIsInjury($from)))
            ->select('p.id as player_id')
            ->selectSub($this->lastNonInjuryMarkBefore($from), 'since')
```

```php
                ->whereIn('p.id', $this->injuryMarksBetween($from, $to, $branchId))
                ->orWhereIn('p.id', $this->latestMarkIsInjury(null, $branchId))
                ->orWhereIn('p.id', $this->latestMarkIsInjury($from, $branchId)))
            ->select('p.id as player_id')
            ->selectSub($this->lastNonInjuryMarkBefore($from, $branchId), 'since')
```

```php
        $byPlayer = self::fromMarks($this->scanFrom($players, $this->sessionLed($from))
            ->where('training_sessions.state', SessionState::Held->value)
```

```php
        $byPlayer = self::fromMarks(BranchScope::apply($this->scanFrom($players, $this->sessionLed($from)), $branchId)
            ->where('training_sessions.state', SessionState::Held->value)
```

```php
        $notes = InjuryNote::whereIn('player_id', array_keys($byPlayer))->get()
            ->keyBy(fn (InjuryNote $note) => $note->player_id.'|'.$note->start_date);
```

```php
        $notes = InjuryNote::whereIn('player_id', array_keys($byPlayer))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->get()
            ->keyBy(fn (InjuryNote $note) => $note->player_id.'|'.$note->start_date);
```

`marks()`:

```php
    /** Held-session marks in spell order: player, date, start time, session id; optionally one branch's. */
    private function marks(?int $branchId = null): Builder
    {
        return BranchScope::apply(DB::table('attendances')
            ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
            ->where('training_sessions.state', SessionState::Held->value)
            ->orderBy('attendances.player_id')
            ->orderBy('training_sessions.date')
            ->orderBy('training_sessions.start_time')
            ->orderBy('training_sessions.id')
            ->select('attendances.player_id', 'attendances.status', 'attendances.reason', 'training_sessions.date'), $branchId);
    }
```

`lastNonInjuryMarkBefore()` — signature `private function lastNonInjuryMarkBefore(string $from, ?int $branchId = null): Builder` and its return wrapped:

```php
        return BranchScope::apply(DB::table('training_sessions as s2')
            ->crossJoin('attendances as a2')
            ->whereColumn('a2.training_session_id', 's2.id')
            ->whereColumn('a2.player_id', 'p.id')
            ->where('s2.state', SessionState::Held->value)
            ->where('s2.date', '<', $from)
            ->whereNotIn('a2.status', $this->skipped())
            // Not an injury mark (isInjury): an excused absence without a reason is not one.
            ->where('a2.status', '!=', AttendanceStatus::NotTraining->value)
            ->where(fn (Builder $q) => $q->where('a2.status', '!=', AttendanceStatus::AbsentExcused->value)
                ->orWhereNull('a2.reason')
                ->orWhere('a2.reason', '!=', AbsenceReason::Injury->value))
            ->orderByDesc('s2.date')
            ->limit(1)
            ->select('s2.date'), $branchId, 's2.branch_id');
```

`injuryMarksBetween()`:

```php
    private function injuryMarksBetween(string $from, string $to, ?int $branchId = null): Builder
    {
        return BranchScope::apply(DB::table('training_sessions as s')
            ->crossJoin('attendances as a')
            ->whereColumn('a.training_session_id', 's.id')
            ->where('s.state', SessionState::Held->value)
            ->whereBetween('s.date', [$from, $to])
            ->where(fn (Builder $q) => $q->where('a.status', AttendanceStatus::NotTraining->value)
                ->orWhere(fn (Builder $q) => $q->where('a.status', AttendanceStatus::AbsentExcused->value)
                    ->where('a.reason', AbsenceReason::Injury->value)))
            ->select('a.player_id'), $branchId, 's.branch_id');
    }
```

`latestMarkIsInjury()` — its doc gains "In one branch, only players with a mark in it are asked, and only its sessions are walked." and the method becomes:

```php
    private function latestMarkIsInjury(?string $before = null, ?int $branchId = null): Builder
    {
        return DB::table('players as p')
            ->whereExists(fn (Builder $any) => $any->selectRaw('1')
                ->from('attendances as a0')
                ->whereColumn('a0.player_id', 'p.id')
                ->when($branchId !== null, fn (Builder $q) => BranchScope::apply(
                    $q->join('training_sessions as s0', 's0.id', '=', 'a0.training_session_id'),
                    $branchId,
                    's0.branch_id',
                )))
            ->where(fn (Builder $latest) => $latest
                ->selectRaw('case when a2.status = ? or (a2.status = ? and a2.reason = ?) then 1 else 0 end', [
                    AttendanceStatus::NotTraining->value, AttendanceStatus::AbsentExcused->value, AbsenceReason::Injury->value,
                ])
                ->from('training_sessions as s2')
                ->crossJoin('attendances as a2')
                ->whereColumn('a2.training_session_id', 's2.id')
                ->whereColumn('a2.player_id', 'p.id')
                ->where('s2.state', SessionState::Held->value)
                ->whereNotIn('a2.status', $this->skipped())
                ->when($before !== null, fn (Builder $q) => $q->where('s2.date', '<', $before))
                ->tap(fn (Builder $q) => BranchScope::apply($q, $branchId, 's2.branch_id'))
                ->orderByDesc('s2.date')
                ->orderByDesc('s2.start_time')
                ->orderByDesc('s2.id')
                ->limit(1), 1)
            ->select('p.id');
    }
```

- [ ] **Step 6: Saving a detail names its branch**

`app/Http/Controllers/AttendanceInjuryController.php` — add imports `use App\Models\Branch;` and `use Illuminate\Validation\Rule;`; `store()` becomes:

```php
    /** Adds the details of the branch's spell starting on `start_date`, or replaces them if it has some. */
    public function store(Request $request, Player $player): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            // The card's branch tab: a spell is one branch's once the club has branches.
            'branch_id' => [Rule::requiredIf(fn () => Branch::exists()), 'nullable', 'integer', 'exists:branches,id'],
            ...self::DETAIL_RULES,
            'returned_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        if (! in_array($data['start_date'], array_column($this->spells->all($player->id, $branchId), 'start'), true)) {
            throw ValidationException::withMessages(['start_date' => 'att.injury.error.no_spell']);
        }

        $key = ['player_id' => $player->id, 'branch_id' => $branchId, 'start_date' => $data['start_date']];
        try {
            $note = InjuryNote::firstOrNew($key);
            $note->fill(self::details($data));
            if (! $note->exists) {
                $note->created_by = $request->user()?->id;
            }
            $note->save();
        } catch (UniqueConstraintViolationException) {
            // Another request added this spell's detail since the lookup: replace it, as saving again does.
            $note = InjuryNote::where($key)->firstOrFail();
            $note->update(self::details($data));
        }

        return response()->json(['note' => $note->toDetail()], $note->wasRecentlyCreated ? 201 : 200);
    }
```

(`where(['branch_id' => null])` compiles to `branch_id is null`, so a club without branches keeps working.)

- [ ] **Step 7: Match badge in any branch**

`app/Services/Competitions/PlayerFlags.php` — add `use App\Models\Branch;`; `injured()` becomes:

```php
    /**
     * Ids of players injured today in any branch: a spell is one branch's
     * (InjurySpells), and a player hurt in either sport gets the warning.
     * A club without branches is one pass with no branch condition.
     *
     * @return array<int, true>
     */
    public function injured(): array
    {
        $today = now()->toDateString();
        $branchIds = Branch::orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all() ?: [null];

        $injured = [];
        foreach ($branchIds as $branchId) {
            foreach ($this->spells->club($today, $today, null, $branchId)['current'] as $row) {
                $injured[$row['player_id']] = true;
            }
        }

        return $injured;
    }
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan config:clear && php artisan test --filter="AttendanceBranchInjuriesTest|AttendanceInjurySpellsTest|AttendanceInjuryNotesTest|PlayerFlagsTest|CallUpTest|AttendanceBranchMigrationTest"`
Expected: PASS.

- [ ] **Step 9: Full suite**

Run (background): `php artisan test` → all green.

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_10_23_300001_add_branch_to_injury_notes.php app/Models/InjuryNote.php app/Observers/BranchObserver.php app/Http/Controllers/BranchController.php app/Services/Attendance/InjurySpells.php app/Http/Controllers/AttendanceInjuryController.php app/Services/Competitions/PlayerFlags.php tests/Feature/AttendanceBranchInjuriesTest.php
git commit -m "$(cat <<'EOF'
feat(attendance): injury spells and their details per branch

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Shared branch switch; statistics page and exports per branch

**Files:**
- Modify: `app/Services/Attendance/BranchChoice.php` (add `fromRequest()`, `remembered()`, `options()`)
- Modify: `app/Http/Controllers/AttendanceCalendarController.php:96-98` (use `BranchChoice::options()`)
- Create: `resources/js/Components/Attendance/BranchSwitch.vue`
- Modify: `resources/js/Pages/Attendance/Index.vue:102-116` (use `BranchSwitch`)
- Modify: `app/Http/Controllers/AttendanceStatsController.php` (`export()` 44–85, `data()` 88–118)
- Modify: `resources/views/pdf/attendance-stats.blade.php:28`
- Modify: `resources/js/Pages/Attendance/Stats.vue` (props 25–36, `keep`/`pickCategory` 43–47, filters 96–102, partials 200–201)
- Modify: `resources/js/Pages/Attendance/Partials/StatsPlayerTable.vue` (props 11–14, `letterHref` 53, profile link 92)
- Modify: `resources/js/Pages/Attendance/Partials/StatsRanking.vue` (props 9–11, profile link 32)
- Test: `tests/Feature/AttendanceBranchStatsPageTest.php`

**Interfaces:**
- Consumes: Task 1 `AttendanceStats` branch parameters.
- Produces:
  - `BranchChoice::fromRequest(Request $request): ?int` (validates `branch_id` exists, then `resolve()`; remembers it)
  - `BranchChoice::remembered(Request $request): ?int` (read only)
  - `BranchChoice::options(): array` → `list<array{id: int, name: string, logo_url: ?string}>`
  - `BranchSwitch.vue` props `branches` (Array), `branchId` (Number|null); emits `switch(id)`; renders only with 2+ branches.
  - Inertia props on `Attendance/Stats`: `branches`, `branchId`. Export file names `attendance-stats-{from}-{to}[-b{branchId}][-{categoryId}]`.
- Query counts: `fromRequest()` = 1 (`exists` rule, only when asked) + `resolve()`'s 1; `options()` = 1. No query-count test covers report pages.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceBranchStatsPageTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceBranchStatsPageTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    private const PERIOD = ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-10-31'];

    #[Test]
    public function the_page_shows_one_branchs_numbers(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both, 'swimmer' => $swimmer] = $this->twoSportClub();

        $this->actingAs($this->admin())->get(route('attendance.stats', self::PERIOD + ['branch_id' => $swim->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Stats')
                ->where('branchId', $swim->id)
                ->has('branches', 2)
                ->where('branches.0.name', 'Football')
                ->where('totals.held', 5)
                ->where('totals.expected', 10)
                ->where('players', fn ($rows) => collect($rows)->pluck('player_id')->sort()->values()->all() === [$both->id, $swimmer->id])
                ->where('players', fn ($rows) => collect($rows)->firstWhere('player_id', $both->id)['score_pct'] == 100));

        $this->actingAs($this->admin())->get(route('attendance.stats', self::PERIOD + ['branch_id' => $foot->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('players', fn ($rows) => collect($rows)->firstWhere('player_id', $both->id)['score_pct'] == 0)
                ->where('categoryRows.0.expected', 10));
    }

    #[Test]
    public function the_branch_defaults_to_the_first_then_is_shared_with_the_calendar(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'u15' => $u15] = $this->twoSportClub();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.stats', self::PERIOD))
            ->assertInertia(fn (Assert $page) => $page->where('branchId', $foot->id));
        $this->actingAs($admin)->get(route('attendance.stats', self::PERIOD + ['branch_id' => $swim->id]))->assertOk();

        $this->actingAs($admin)->get(route('attendance.stats', self::PERIOD))
            ->assertInertia(fn (Assert $page) => $page->where('branchId', $swim->id));
        $this->actingAs($admin)->get(route('attendance.index', ['category_id' => $u15->id]))
            ->assertInertia(fn (Assert $page) => $page->where('branchId', $swim->id));
    }

    #[Test]
    public function an_unknown_branch_is_refused(): void
    {
        $this->twoSportClub();

        $this->actingAs($this->admin())->get(route('attendance.stats', self::PERIOD + ['branch_id' => 999]))
            ->assertSessionHasErrors('branch_id');
    }

    #[Test]
    public function the_spreadsheet_is_one_branchs(): void
    {
        ['swim' => $swim, 'both' => $both, 'footer' => $footer, 'swimmer' => $swimmer] = $this->twoSportClub();

        $response = $this->actingAs($this->admin())
            ->get(route('attendance.stats.export', self::PERIOD + ['branch_id' => $swim->id, 'format' => 'csv']))
            ->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString("attendance-stats-2026-09-01-2026-10-31-b{$swim->id}.csv", (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString(' — Swimming — ', $csv);
        $this->assertStringContainsString($both->fullname, $csv);
        $this->assertStringContainsString($swimmer->fullname, $csv);
        $this->assertStringNotContainsString($footer->fullname, $csv);
    }

    #[Test]
    public function the_pdf_names_the_branch(): void
    {
        ['foot' => $foot] = $this->twoSportClub();
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.stats.export', self::PERIOD + ['branch_id' => $foot->id, 'format' => 'pdf']))->assertOk();

        $this->assertSame("attendance-stats-2026-09-01-2026-10-31-b{$foot->id}.pdf", $seen['filename']);
        $this->assertStringContainsString('Football', $seen['html']);
    }

    #[Test]
    public function a_club_without_branches_has_no_switch(): void
    {
        $this->category('U15');

        $this->actingAs($this->admin())->get(route('attendance.stats', self::PERIOD))
            ->assertInertia(fn (Assert $page) => $page->where('branchId', null)->has('branches', 0));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan config:clear && php artisan test --filter=AttendanceBranchStatsPageTest`
Expected: FAIL — props `branchId` / `branches` missing; the CSV lists the footballer.

- [ ] **Step 3: `BranchChoice` helpers**

Append to `app/Services/Attendance/BranchChoice.php` (inside the class, after `resolve()`), and change the class doc's first line to "The branch the attendance pages show (calendar, month grid, month sheet, report pages):":

```php
    /**
     * The branch a report page shows: a valid `branch_id` from the query
     * (an unknown one fails validation), else as resolve(). The choice is
     * shared with the calendar: switching on one page switches them all.
     */
    public static function fromRequest(Request $request): ?int
    {
        $data = $request->validate(['branch_id' => ['nullable', 'integer', 'exists:branches,id']]);

        return self::resolve($request, $data['branch_id'] ?? null);
    }

    /** The remembered branch, read without changing it (the profile card's default tab); null when none. */
    public static function remembered(Request $request): ?int
    {
        $id = $request->session()->get(self::SESSION_KEY);

        return $id === null ? null : (int) $id;
    }

    /** @return list<array{id: int, name: string, logo_url: ?string}> the switch's branches, by id */
    public static function options(): array
    {
        return Branch::orderBy('id')->get()
            ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->localized_name, 'logo_url' => $branch->logo_url])
            ->values()
            ->all();
    }
```

`app/Http/Controllers/AttendanceCalendarController.php` lines 96–98 become:

```php
            // The branch switch; lazy like `categories`.
            'branches' => fn () => BranchChoice::options(),
```

(remove the now-unused `use App\Models\Branch;` only if nothing else in the file uses it — check with `grep -n "Branch::" app/Http/Controllers/AttendanceCalendarController.php`.)

- [ ] **Step 4: Shared switch component**

`resources/js/Components/Attendance/BranchSwitch.vue`:

```vue
<script setup>
import { useI18n } from 'vue-i18n';

/**
 * The attendance pages' branch (sport) switch: one branch at a time, shown
 * when the club has two or more. The server remembers the choice
 * (BranchChoice); the page navigates on `switch`.
 */
defineProps({
    branches: { type: Array, default: () => [] }, // [{ id, name, logo_url? }]
    branchId: { type: Number, default: null },
});
const emit = defineEmits(['switch']);
const { t } = useI18n();
</script>

<template>
    <div v-if="branches.length > 1" role="tablist" :aria-label="t('branch')" class="inline-flex rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800">
        <button
            v-for="b in branches"
            :key="b.id"
            type="button"
            role="tab"
            :aria-selected="branchId === b.id"
            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-sm font-semibold"
            :class="branchId === b.id ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-slate-100' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'"
            @click="branchId !== b.id && emit('switch', b.id)"
        >
            <img v-if="b.logo_url" :src="b.logo_url" alt="" class="h-4 w-4 rounded object-contain" />
            {{ b.name }}
        </button>
    </div>
</template>
```

`resources/js/Pages/Attendance/Index.vue`: add `import BranchSwitch from '@/Components/Attendance/BranchSwitch.vue';` and replace the inline tablist (lines 102–116, from `<div v-if="branches.length > 1" role="tablist"` to its closing `</div>`) with:

```vue
                    <BranchSwitch :branches="branches" :branch-id="branchId" @switch="switchBranch" />
```

- [ ] **Step 5: Statistics controller**

`app/Http/Controllers/AttendanceStatsController.php` — add `use App\Services\Attendance\BranchChoice;`; class doc: "a period (…), an optional category, and one branch (BranchChoice, shared with the calendar)." Replace `export()` and `data()` with:

```php
    /**
     * The player table as XLSX (default) or CSV, or the whole page as a PDF
     * (`format=pdf`), for the same period, branch and category as the page.
     */
    public function export(Request $request): FileResponse
    {
        $data = $this->data($request);
        ['from' => $from, 'to' => $to] = $data['period'];
        $categoryName = $data['categoryId'] !== null
            ? collect($data['categories'])->firstWhere('id', $data['categoryId'])['name']
            : UiLang::get('att.all_categories');
        $branchName = $data['branchId'] !== null ? collect($data['branches'])->firstWhere('id', $data['branchId'])['name'] : null;
        $filename = 'attendance-stats-'.$from.'-'.$to
            .($data['branchId'] !== null ? '-b'.$data['branchId'] : '')
            .($data['categoryId'] !== null ? '-'.$data['categoryId'] : '');
        $catalog = app(AttendanceStatusCatalog::class);
        // A hidden custom code only gets a column while the data has marks for it.
        $labels = $catalog->labels(null, $catalog->withMarks($data['totals']['counts'], ...array_column($data['categoryRows'], 'counts')));

        if ($request->query('format') === 'pdf') {
            $html = view('pdf.attendance-stats', [
                ...$data,
                'club' => ClubHeader::data(),
                'categoryName' => $categoryName,
                'branchName' => $branchName,
                'labels' => $labels,
                'codes' => $catalog->codes(),
            ])->render();

            return $this->pdf->stream($html, $filename.'.pdf', app()->getLocale() === 'ar', true);
        }

        $headers = [UiLang::get('col.member'), UiLang::get('col.membership_id'), UiLang::get('col.category'), UiLang::get('att.col.expected')];
        foreach ($labels as $name) {
            array_push($headers, $name, $name.' %');
        }
        array_push($headers, UiLang::get('att.col.late_minutes'), UiLang::get('att.col.missed_hours'), UiLang::get('att.col.score'), UiLang::get('att.col.score_pct'));

        $rows = array_map(function (array $row) use ($labels): array {
            $cells = [$row['name'], (string) $row['membership_id'], $row['category'] ?? '', $row['expected']];
            foreach (array_keys($labels) as $status) {
                array_push($cells, $row['counts'][$status], $row['pct'][$status]);
            }

            return [...$cells, $row['late_minutes'], $row['missed_hours'], $row['score'], $row['score_pct']];
        }, $data['players']);

        return Export::download(Export::format($request), $filename, $headers, $rows,
            implode(' — ', array_filter([UiLang::get('att.stats_title'), $data['period']['label'], $branchName, $categoryName])));
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        $validated = $request->validate(['category_id' => ['nullable', 'integer', 'exists:categories,id']]);
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $branchId = BranchChoice::fromRequest($request);
        $period = ActivityPeriod::fromRequest($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        $rows = $this->stats->players($from, $to, $categoryId, null, $branchId);
        $players = $this->withNames($rows);
        $sessions = $this->stats->sessionsByMonth($from, $to, $categoryId, $branchId);

        return [
            'period' => $period->toArray(),
            'categoryId' => $categoryId,
            'categories' => Category::orderBy('id')->get()
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->localized_name])
                ->values()->all(),
            'branches' => BranchChoice::options(),
            'branchId' => $branchId,
            'totals' => [
                ...$this->stats->summarize($rows),
                'held' => array_sum($sessions['held']),
                'cancelled' => array_sum($sessions['cancelled']),
            ],
            'monthly' => $this->stats->monthly($from, $to, $categoryId, null, $branchId),
            'sessionsByMonth' => $sessions,
            'categoryRows' => $this->stats->categories($from, $to, $branchId),
            'players' => $players,
            // The table stays historical (departed/archived players included); the owner
            // decided they are not ranked, so only active players feed the top/bottom 5.
            'ranking' => AttendanceStats::ranking(array_values(array_filter($players, fn (array $row) => $row['active']))),
        ];
    }
```

`resources/views/pdf/attendance-stats.blade.php` line 28 becomes:

```blade
    <div class="sub">{{ $L('activity.period_label') }}: <bdi dir="ltr">{{ $period['label'] }}</bdi> &middot; @if (!empty($branchName)){{ $branchName }} &middot; @endif{{ $categoryName }}</div>
```

- [ ] **Step 6: Statistics page**

`resources/js/Pages/Attendance/Stats.vue`:
- import: `import BranchSwitch from '@/Components/Attendance/BranchSwitch.vue';`
- props — add after `categories`:

```js
    branches: { type: Array, default: () => [] },
    branchId: { type: Number, default: null },
```

- replace lines 43–47 (`keep`, `exportHref`, `pickCategory`) with:

```js
// The filters every link and visit keeps: category and branch.
const branchQuery = computed(() => (props.branchId ? { branch_id: props.branchId } : {}));
const keep = computed(() => ({ ...(props.categoryId ? { category_id: props.categoryId } : {}), ...branchQuery.value }));
const exportHref = computed(() => route('attendance.stats.export', { ...periodQuery(props.period), ...keep.value }));
function pickCategory(value) {
    router.get(route('attendance.stats'), { ...periodQuery(props.period), ...branchQuery.value, ...(value ? { category_id: Number(value) } : {}) }, { preserveScroll: true, replace: true });
}
function switchBranch(id) {
    router.get(route('attendance.stats'), { ...periodQuery(props.period), ...keep.value, branch_id: id }, { preserveScroll: true, replace: true });
}
```

- in the filter row (line 96), first child:

```vue
                <BranchSwitch :branches="branches" :branch-id="branchId" @switch="switchBranch" />
```

- lines 200–201:

```vue
            <StatsRanking :ranking="ranking" :branch-id="branchId" />
            <StatsPlayerTable :rows="players" :period="period" :branch-id="branchId" />
```

`resources/js/Pages/Attendance/Partials/StatsPlayerTable.vue`:
- props add `branchId: { type: Number, default: null }, // the page's branch: letters and profile links keep it`
- line 53:

```js
const letterHref = (row) => route('attendance.players.letter', { player: row.player_id, ...periodQuery(props.period), ...(props.branchId ? { branch_id: props.branchId } : {}) });
// The profile opens its attendance card on the page's branch.
const profileHref = (row) => route('players.show', { player: row.player_id, ...(props.branchId ? { attendance_branch: props.branchId } : {}) });
```

- line 92: `:href="route('players.show', row.player_id)"` → `:href="profileHref(row)"`.

`resources/js/Pages/Attendance/Partials/StatsRanking.vue`:
- props add `branchId: { type: Number, default: null },`
- after `lists`: `const profileHref = (row) => route('players.show', { player: row.player_id, ...(props.branchId ? { attendance_branch: props.branchId } : {}) });`
- line 32: `:href="route('players.show', row.player_id)"` → `:href="profileHref(row)"`.

- [ ] **Step 7: Build and run tests**

Run: `npm run build` → succeeds.
Run: `php artisan config:clear && php artisan test --filter="AttendanceBranchStatsPageTest|AttendanceStatsPageTest|AttendanceStatsExportTest|AttendanceCalendarBranchTest|AttendanceCalendarTest"`
Expected: PASS.

- [ ] **Step 8: Full suite and JS tests**

Run: `npm run test:js` → green. Run (background): `php artisan test` → all green.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Attendance/BranchChoice.php app/Http/Controllers/AttendanceCalendarController.php resources/js/Components/Attendance/BranchSwitch.vue resources/js/Pages/Attendance/Index.vue app/Http/Controllers/AttendanceStatsController.php resources/views/pdf/attendance-stats.blade.php resources/js/Pages/Attendance/Stats.vue resources/js/Pages/Attendance/Partials/StatsPlayerTable.vue resources/js/Pages/Attendance/Partials/StatsRanking.vue tests/Feature/AttendanceBranchStatsPageTest.php
git commit -m "$(cat <<'EOF'
feat(attendance): statistics page and exports show one branch

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: At-risk alerts per branch; dashboard counts any branch

**Files:**
- Modify: `app/Services/Attendance/AtRisk.php` (whole file below: `list()` split into `flagged()` + `named()`, new `anyBranch()`)
- Modify: `app/Services/Dashboard/AttendanceCard.php:17-22,48-68`
- Modify: `resources/js/Pages/Dashboard/Partials/AttendanceCard.vue:72-74`
- Modify: `app/Http/Controllers/AttendanceAlertsController.php` (whole file below)
- Modify: `resources/js/Pages/Attendance/Alerts.vue` (props 18–24, 28–33, filters 64–70, links 96, 114–115)
- Modify: `tests/Feature/Dashboard/DashboardPageTest.php` (members budget 32 → 33 and its comment)
- Test: `tests/Feature/AttendanceBranchAlertsTest.php`

**Interfaces:**
- Consumes: Task 1 `AtRisk::list(…, ?int $branchId)`; Task 3 `BranchChoice::fromRequest()`, `options()`, `BranchSwitch.vue`.
- Produces:
  - `AtRisk::anyBranch(string $from, string $to): array` — distinct players, rows of `list()` plus `branch_id` (?int) and `branch` (?string, only with 2+ branches).
  - Dashboard `members.attendance.risk.worst[*]` gains `branch_id`, `branch`; `risk.count` = distinct players.
  - Inertia props on `Attendance/Alerts`: `branches`, `branchId`. Export file name `attendance-at-risk-{from}-{to}[-b{branchId}][-{categoryId}]`.
- Query counts: `list()` unchanged (6). `anyBranch()` = 1 (branches) + 3 per branch (at least one pass) + the custom codes 1 + names 2 (skipped when nobody is flagged): 7 without branches (one more than before), 10 with two. Guarded by `DashboardAttendanceCardTest::the_risk_card_costs_the_same_queries_for_3_or_30_players_at_risk`, `DashboardPageTest::every_tab_stays_within_its_query_budget` (budget 33), and the new test below.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceBranchAlertsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\TrainingSession;
use App\Models\WebsiteConfig;
use App\Services\Attendance\AtRisk;
use App\Services\Dashboard\AttendanceCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceBranchAlertsTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');   // season 2026/27: 2026-09-01 → 2027-08-31
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** "both" also misses three swimming sessions in a row: flagged in swimming too (25 %, streak 3), worse in football (0 %). */
    private function flaggedInBoth(): array
    {
        $club = $this->twoSportClub();
        foreach (['2026-10-12', '2026-10-13', '2026-10-14'] as $date) {
            $this->branchMark($this->branchSession($club['swim'], $club['u15'], $date), $club['both'], AttendanceStatus::AbsentUnexcused);
        }

        return $club;
    }

    #[Test]
    public function the_alerts_page_lists_one_branchs_players_at_risk(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->twoSportClub();

        $this->actingAs($this->admin())->get(route('attendance.alerts', ['branch_id' => $foot->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Alerts')
                ->where('branchId', $foot->id)
                ->has('branches', 2)
                ->has('rows', 1)
                ->where('rows.0.player_id', $both->id)
                ->where('rows.0.longest_streak', 3));

        $this->actingAs($this->admin())->get(route('attendance.alerts', ['branch_id' => $swim->id]))
            ->assertInertia(fn (Assert $page) => $page->where('branchId', $swim->id)->has('rows', 0));
    }

    #[Test]
    public function a_guest_is_judged_on_the_marks_in_the_sessions_branch(): void
    {
        ['foot' => $foot, 'swimmer' => $swimmer] = $this->twoSportClub();
        // The swimmer, hand-added to three football sessions, missed them all.
        foreach (TrainingSession::where('branch_id', $foot->id)->orderBy('date')->limit(3)->get() as $training) {
            $this->branchMark($training, $swimmer, AttendanceStatus::AbsentUnexcused);
        }

        $this->actingAs($this->admin())->get(route('attendance.alerts', ['branch_id' => $foot->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows', fn ($rows) => collect($rows)->firstWhere('player_id', $swimmer->id)['longest_streak'] === 3));
    }

    #[Test]
    public function the_export_is_one_branchs(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->twoSportClub();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('attendance.alerts.export', ['branch_id' => $foot->id, 'format' => 'csv']))->assertOk();
        $this->assertStringContainsString("-b{$foot->id}.csv", (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString(' — Football — ', $response->streamedContent());
        $this->assertStringContainsString($both->fullname, $response->streamedContent());

        $swimCsv = $this->actingAs($admin)->get(route('attendance.alerts.export', ['branch_id' => $swim->id, 'format' => 'csv']))->streamedContent();
        $this->assertStringNotContainsString($both->fullname, $swimCsv);
    }

    #[Test]
    public function the_dashboard_counts_a_player_flagged_in_two_branches_once_with_the_worst_branch(): void
    {
        ['foot' => $foot, 'both' => $both] = $this->flaggedInBoth();

        $risk = app(AttendanceCard::class)->get()['risk'];

        $this->assertSame(1, $risk['count']);
        $this->assertSame($both->id, $risk['worst'][0]['player_id']);
        $this->assertSame($foot->id, $risk['worst'][0]['branch_id']);
        $this->assertSame('Football', $risk['worst'][0]['branch']);
    }

    #[Test]
    public function any_branch_costs_a_fixed_number_of_queries(): void
    {
        $this->flaggedInBoth();
        $risk = app(AtRisk::class);
        WebsiteConfig::singleton();
        $risk->thresholds();   // loads the settings once

        DB::flushQueryLog();
        DB::enableQueryLog();
        $risk->anyBranch('2026-09-01', '2027-08-31');
        DB::disableQueryLog();

        // The branches: 1, the custom codes: 1, per branch players() 2 + the streak scan 1 (× 2), names: 2.
        $this->assertSame(10, count(DB::getQueryLog()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan config:clear && php artisan test --filter=AttendanceBranchAlertsTest`
Expected: FAIL — `branchId` prop missing, `anyBranch()` undefined, dashboard `branch_id` key missing.

- [ ] **Step 3: `AtRisk` with `anyBranch()`**

Replace `app/Services/Attendance/AtRisk.php` with:

```php
<?php

namespace App\Services\Attendance;

use App\Models\Branch;

/**
 * Players at risk over a period, from the attendance settings' alerts:
 *  - a low score: score % strictly below `alerts.min_score_pct`, only for
 *    players expected at RANKING_MIN_EXPECTED sessions or more (the ranking's
 *    minimum, so one early absence does not raise an alert);
 *  - an unexcused streak: `alerts.unexcused_streak` or more unexcused
 *    absences in a row at any time in the period (the longest streak; the
 *    current one is reported beside it).
 * A threshold of 0 turns its rule off. Every number comes from AttendanceStats,
 * over one branch's sessions ($branchId; null = no branch condition, a club
 * without branches): a two-sport player is judged per sport.
 */
final class AtRisk
{
    private const NO_STREAK = ['current' => 0, 'longest' => 0, 'last_date' => null];

    /** $stats is public so the dashboard card shares this instance and the settings are read once. */
    public function __construct(
        public readonly AttendanceStats $stats,
        private readonly PlayerNames $names,
    ) {}

    /** @return array{min_score_pct: int, unexcused_streak: int, min_expected: int} */
    public function thresholds(): array
    {
        $alerts = $this->stats->settings()['alerts'];

        return [
            'min_score_pct' => (int) $alerts['min_score_pct'],
            'unexcused_streak' => (int) $alerts['unexcused_streak'],
            'min_expected' => AttendanceStats::RANKING_MIN_EXPECTED,
        ];
    }

    /**
     * @param  array{expected: int, score_pct: ?float}  $row  a players() row
     * @param  array{longest: int}  $streak  an unexcusedStreaks() row
     * @param  array{min_score_pct: int, unexcused_streak: int, min_expected: int}  $thresholds
     * @return array{at_risk: bool, low_score: bool, streak: bool}
     */
    public static function evaluate(array $row, array $streak, array $thresholds): array
    {
        $min = $thresholds['min_score_pct'];
        $lowScore = $min > 0
            && $row['expected'] >= $thresholds['min_expected']
            && $row['score_pct'] !== null
            && $row['score_pct'] < $min;
        $inARow = $thresholds['unexcused_streak'];
        $longStreak = $inARow > 0 && $streak['longest'] >= $inARow;

        return ['at_risk' => $lowScore || $longStreak, 'low_score' => $lowScore, 'streak' => $longStreak];
    }

    /**
     * Every active player (not archived, not left) at risk over the period
     * in one branch, worst first: lowest score % (none last), then longest
     * streak, then name. $categoryId keeps the players now in that category;
     * their numbers still cover all their marks in the branch, since the
     * risk is the person's. Six queries whatever the roster (the custom
     * codes: 1, players(): 2, the streak scan: 1, names: 2), four when
     * nobody is at risk.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $from, string $to, ?int $categoryId = null, ?int $branchId = null): array
    {
        return $this->named($this->flagged($from, $to, $branchId), $categoryId);
    }

    /**
     * The players at risk in any branch, each once (the dashboard card): a
     * player flagged in two branches is kept with the branch where they fare
     * worst, this list's order. Each row is a list() row plus `branch_id`
     * and `branch` (its name, only when the club has two or more branches).
     * A club without branches is one pass with no branch condition.
     * 1 (the branches) + 3 per pass + the custom codes 1 + names 2 queries,
     * the names skipped when nobody is at risk.
     *
     * @return list<array<string, mixed>>
     */
    public function anyBranch(string $from, string $to): array
    {
        $branches = Branch::orderBy('id')->get();
        $names = $branches->count() > 1
            ? $branches->mapWithKeys(fn (Branch $branch) => [$branch->id => $branch->localized_name])->all()
            : [];

        $flagged = [];
        foreach ($branches->isEmpty() ? [null] : $branches->modelKeys() as $branchId) {
            foreach ($this->flagged($from, $to, $branchId) as $row) {
                $flagged[] = [...$row, 'branch_id' => $branchId, 'branch' => $names[$branchId] ?? null];
            }
        }

        $rows = [];
        foreach ($this->named($flagged, null) as $row) {
            $rows[$row['player_id']] ??= $row;   // worst first: a player's first row is their worst branch
        }

        return array_values($rows);
    }

    /**
     * One player's flags for the profile card, over the card's period and branch.
     * $row is the card's own players() row (or emptyRow()), for the same branch.
     *
     * @return array{at_risk: bool, low_score: bool, streak: bool, score_pct: ?float, current_streak: int, longest_streak: int, min_score_pct: int, unexcused_streak: int, min_expected: int}
     */
    public function forPlayer(int $playerId, string $from, string $to, array $row, ?int $branchId = null): array
    {
        $streak = $this->stats->unexcusedStreaks($from, $to, $playerId, $branchId)[$playerId] ?? self::NO_STREAK;
        $thresholds = $this->thresholds();

        return [
            ...self::evaluate($row, $streak, $thresholds),
            'score_pct' => $row['score_pct'],
            'current_streak' => $streak['current'],
            'longest_streak' => $streak['longest'],
            ...$thresholds,
        ];
    }

    /**
     * The flagged players of one branch (null: no branch condition), without
     * names. Three queries (players(): 2, the streak scan: 1).
     *
     * @return list<array<string, mixed>>
     */
    private function flagged(string $from, string $to, ?int $branchId): array
    {
        $thresholds = $this->thresholds();
        $streaks = $this->stats->unexcusedStreaks($from, $to, null, $branchId);

        $flagged = [];
        foreach ($this->stats->players($from, $to, null, null, $branchId) as $playerId => $row) {
            $streak = $streaks[$playerId] ?? self::NO_STREAK;
            $flags = self::evaluate($row, $streak, $thresholds);
            if (! $flags['at_risk']) {
                continue;
            }
            $flagged[] = [
                'player_id' => $playerId,
                'expected' => $row['expected'],
                'score_pct' => $row['score_pct'],
                'unexcused' => $row['scored']['absent_unexcused'],
                'current_streak' => $streak['current'],
                'longest_streak' => $streak['longest'],
                'last_date' => $streak['last_date'],
                'low_score' => $flags['low_score'],
                'streak' => $flags['streak'],
            ];
        }

        return $flagged;
    }

    /**
     * Names attached (PlayerNames), active players only, optionally those now
     * in $categoryId, worst first (usort is stable: equal rows keep their order).
     *
     * @return list<array<string, mixed>>
     */
    private function named(array $flagged, ?int $categoryId): array
    {
        $rows = array_values(array_filter(
            $this->names->attach($flagged),
            fn (array $row) => $row['active'] && ($categoryId === null || $row['category_id'] === $categoryId),
        ));
        usort($rows, fn (array $a, array $b) => [$a['score_pct'] ?? 101, $b['longest_streak'], $a['name']]
            <=> [$b['score_pct'] ?? 101, $a['longest_streak'], $b['name']]);

        return $rows;
    }
}
```

- [ ] **Step 4: Dashboard card**

`app/Services/Dashboard/AttendanceCard.php` — class doc lines 17–22:

```php
/**
 * The members tab's attendance card: today's sessions, the last 30 days by
 * status, and the players at risk this season in any branch (each counted
 * once, listed with the branch where they fare worst; count and the 5
 * worst). Fixed windows, and every branch's sessions, so the dashboard's
 * range and branch filters do not apply (each session names its branch instead).
 */
```

Line 49 and the `worst` mapping (lines 64–67):

```php
        $atRisk = $this->risk->anyBranch($season['from'], $season['to']);
```

```php
                'worst' => array_map(
                    fn (array $row) => Arr::only($row, ['player_id', 'name', 'category', 'score_pct', 'longest_streak', 'low_score', 'streak', 'branch_id', 'branch']),
                    array_slice($atRisk, 0, self::RISK_SHOWN),
                ),
```

`resources/js/Pages/Dashboard/Partials/AttendanceCard.vue` — replace lines 72–73 (the `<li>` opening tag and its `<Link>`) with the three lines below: the row link opens the profile on the flagged branch, and names it when the club has several. Line 74 (`<span v-if="row.streak" …>`) and the rest stay unchanged.

```vue
                    <li v-for="row in data.risk.worst" :key="row.player_id" class="flex items-center gap-2 py-1.5 text-sm">
                        <Link :href="route('players.show', { player: row.player_id, ...(row.branch_id ? { attendance_branch: row.branch_id } : {}) })" class="min-w-0 flex-1 truncate font-medium hover:underline">{{ row.name }}</Link>
                        <span v-if="row.branch" class="shrink-0 text-xs text-muted-foreground">{{ row.branch }}</span>
```

`tests/Feature/Dashboard/DashboardPageTest.php` — in `every_tab_stays_within_its_query_budget()`, add to the comment block before the loop:

```php
        // members: +1 for the branches the at-risk card walks (AtRisk::anyBranch):
        // a player is judged per branch; a club with N >= 2 branches adds 3 per extra branch.
```

and change `['members' => 32, 'operations' => 12]` to `['members' => 33, 'operations' => 12]`.

- [ ] **Step 5: Alerts controller**

Replace `app/Http/Controllers/AttendanceAlertsController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\AtRisk;
use App\Services\Attendance\BranchChoice;
use App\Support\Export;
use App\Support\UiLang;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;

/**
 * Players at risk (`attendance.alerts`, view): a period, the current season
 * by default, one branch (BranchChoice, shared with the calendar) and an
 * optional category (the players' current one). The rules and every number
 * come from AtRisk / AttendanceStats, over that branch's sessions.
 */
class AttendanceAlertsController extends Controller
{
    public function __construct(private readonly AtRisk $risk) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Attendance/Alerts', $this->data($request));
    }

    /** The list as XLSX (default) or CSV, for the same period, branch and category as the page. */
    public function export(Request $request): FileResponse
    {
        $data = $this->data($request);
        ['from' => $from, 'to' => $to] = $data['period'];
        $categoryName = $data['categoryId'] !== null
            ? collect($data['categories'])->firstWhere('id', $data['categoryId'])['name']
            : UiLang::get('att.all_categories');
        $branchName = $data['branchId'] !== null ? collect($data['branches'])->firstWhere('id', $data['branchId'])['name'] : null;

        $headers = [
            UiLang::get('col.member'), UiLang::get('col.membership_id'), UiLang::get('att.category'),
            UiLang::get('att.col.expected'), UiLang::get('att.col.score_pct'), UiLang::get('att.risk.col.unexcused'),
            UiLang::get('att.risk.col.current_streak'), UiLang::get('att.risk.col.longest_streak'),
            UiLang::get('att.risk.col.last_session'), UiLang::get('att.risk.col.reasons'),
        ];
        $rows = array_map(fn (array $row): array => [
            $row['name'], (string) $row['membership_id'], $row['category'] ?? '', $row['expected'], $row['score_pct'],
            $row['unexcused'], $row['current_streak'], $row['longest_streak'], $row['last_date'] ?? '',
            implode(' · ', array_filter([
                $row['low_score'] ? UiLang::get('att.risk.flag.low_score') : null,
                $row['streak'] ? UiLang::get('att.risk.flag.streak') : null,
            ])),
        ], $data['rows']);

        return Export::download(
            Export::format($request),
            'attendance-at-risk-'.$from.'-'.$to
                .($data['branchId'] !== null ? '-b'.$data['branchId'] : '')
                .($data['categoryId'] !== null ? '-'.$data['categoryId'] : ''),
            $headers,
            $rows,
            implode(' — ', array_filter([UiLang::get('att.risk.title'), $data['period']['label'], $branchName, $categoryName])),
        );
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        $validated = $request->validate(['category_id' => ['nullable', 'integer', 'exists:categories,id']]);
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $branchId = BranchChoice::fromRequest($request);
        $period = ActivityPeriod::fromRequestOrSeason($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        return [
            'period' => $period->toArray(),
            'categoryId' => $categoryId,
            'categories' => Category::orderBy('id')->get()
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->localized_name])
                ->values()->all(),
            'branches' => BranchChoice::options(),
            'branchId' => $branchId,
            'thresholds' => $this->risk->thresholds(),
            'rows' => $this->risk->list($from, $to, $categoryId, $branchId),
        ];
    }
}
```

- [ ] **Step 6: Alerts page**

`resources/js/Pages/Attendance/Alerts.vue`:
- import `import BranchSwitch from '@/Components/Attendance/BranchSwitch.vue';`
- props add `branches: { type: Array, default: () => [] },` and `branchId: { type: Number, default: null },`
- replace lines 28–33 with:

```js
const branchQuery = computed(() => (props.branchId ? { branch_id: props.branchId } : {}));
const keep = computed(() => ({ ...(props.categoryId ? { category_id: props.categoryId } : {}), ...branchQuery.value }));
const exportHref = computed(() => route('attendance.alerts.export', { ...periodQuery(props.period), ...keep.value }));
// The letter and the profile for the page's branch.
const letterHref = (row) => route('attendance.players.letter', { player: row.player_id, ...periodQuery(props.period), ...branchQuery.value });
const profileHref = (row) => route('players.show', { player: row.player_id, ...(props.branchId ? { attendance_branch: props.branchId } : {}) });
function pickCategory(value) {
    router.get(route('attendance.alerts'), { ...periodQuery(props.period), ...branchQuery.value, ...(value ? { category_id: Number(value) } : {}) }, { preserveScroll: true, replace: true });
}
function switchBranch(id) {
    router.get(route('attendance.alerts'), { ...periodQuery(props.period), ...keep.value, branch_id: id }, { preserveScroll: true, replace: true });
}
```

- filter row (line 64), first child: `<BranchSwitch :branches="branches" :branch-id="branchId" @switch="switchBranch" />`
- lines 96 and 115: `:href="route('players.show', row.player_id)"` → `:href="profileHref(row)"`.

- [ ] **Step 7: Build and run tests**

Run: `npm run build` → succeeds.
Run: `php artisan config:clear && php artisan test --filter="AttendanceBranchAlertsTest|AttendanceAlertsPageTest|AttendanceAtRiskTest|DashboardAttendanceCardTest|DashboardPageTest"`
Expected: PASS.

- [ ] **Step 8: Full suite**

Run (background): `php artisan test` → all green.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Attendance/AtRisk.php app/Services/Dashboard/AttendanceCard.php resources/js/Pages/Dashboard/Partials/AttendanceCard.vue app/Http/Controllers/AttendanceAlertsController.php resources/js/Pages/Attendance/Alerts.vue tests/Feature/Dashboard/DashboardPageTest.php tests/Feature/AttendanceBranchAlertsTest.php
git commit -m "$(cat <<'EOF'
feat(attendance): at-risk alerts per branch; dashboard counts any branch once

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Ranking and certificates per branch + category

**Files:**
- Modify: `app/Http/Controllers/AttendanceRankingController.php` (`index()` 37–71, `certificates()` 78–123, `ranking()` 172–179)
- Modify: `resources/js/Pages/Attendance/Ranking.vue` (props 15–23, `query()` 27–33, filters 66, profile link 100)
- Test: `tests/Feature/AttendanceBranchRankingTest.php`

**Interfaces:**
- Consumes: Task 1 `AttendanceStats::players(…, $branchId)`; Task 3 `BranchChoice::fromRequest()`, `options()`, `BranchSwitch.vue`.
- Produces: Inertia props `branches`, `branchId` on `Attendance/Ranking`; `attendance.certificates` takes `branch_id`; file names `certificates-{categoryId}[-b{branchId}]-{key}.pdf`, `certificate-{membershipId}[-b{branchId}]-{key}.pdf`.
- Query counts: unchanged apart from `BranchChoice` (+2) and, for one player's certificate outside the ranking, one `branch_player` lookup.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceBranchRankingTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceBranchRankingTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    private function season(array $extra): array
    {
        return ['type' => 'season', 'season' => 2026] + $extra;
    }

    #[Test]
    public function each_branch_ranks_its_own_marks(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'u15' => $u15, 'both' => $both, 'footer' => $footer, 'swimmer' => $swimmer] = $this->twoSportClub();

        $this->actingAs($this->admin())->get(route('attendance.ranking', $this->season(['category_id' => $u15->id, 'branch_id' => $foot->id])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Ranking')
                ->where('branchId', $foot->id)
                ->has('branches', 2)
                ->where('rows', fn ($rows) => collect($rows)->pluck('player_id')->all() === [$footer->id, $both->id])
                ->where('unranked', 0));

        // Both at 100 % in swimming: ties go to the lower id.
        $this->actingAs($this->admin())->get(route('attendance.ranking', $this->season(['category_id' => $u15->id, 'branch_id' => $swim->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows', fn ($rows) => collect($rows)->pluck('player_id')->all() === [$both->id, $swimmer->id])
                ->where('rows.0.score_pct', 100));
    }

    #[Test]
    public function the_podium_certificates_name_the_branch_and_its_players(): void
    {
        ['swim' => $swim, 'u15' => $u15, 'both' => $both, 'footer' => $footer, 'swimmer' => $swimmer] = $this->twoSportClub();
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.certificates', $this->season(['category_id' => $u15->id, 'branch_id' => $swim->id])))->assertOk();

        $this->assertSame("certificates-{$u15->id}-b{$swim->id}-season-2026.pdf", $seen['filename']);
        $this->assertStringContainsString('Swimming · U15', $seen['html']);
        $this->assertStringContainsString($both->fullname, $seen['html']);
        $this->assertStringContainsString($swimmer->fullname, $seen['html']);
        $this->assertStringNotContainsString($footer->fullname, $seen['html']);
    }

    #[Test]
    public function no_certificate_for_a_player_outside_the_branch(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'u15' => $u15, 'footer' => $footer, 'swimmer' => $swimmer] = $this->twoSportClub();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.certificates', $this->season(['category_id' => $u15->id, 'branch_id' => $swim->id, 'player_id' => $footer->id])))
            ->assertNotFound();
        $this->actingAs($admin)->get(route('attendance.certificates', $this->season(['category_id' => $u15->id, 'branch_id' => $foot->id, 'player_id' => $swimmer->id])))
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan config:clear && php artisan test --filter=AttendanceBranchRankingTest`
Expected: FAIL — `branchId` prop missing; football ranking lists all three players; certificates file name has no branch.

- [ ] **Step 3: Controller**

`app/Http/Controllers/AttendanceRankingController.php` — add `use App\Models\Branch;`, `use App\Services\Attendance\BranchChoice;`, `use Illuminate\Support\Facades\DB;`. Class doc: "One category's attendance ranking in one branch (BranchChoice, shared with the calendar) for a month or a season, …".

`index()` — after the `$categoryId` line:

```php
        $branchId = BranchChoice::fromRequest($request);
```

replace the `ranking()` call:

```php
            : $this->ranking($categoryId, $period, $branchId);
```

and add to the props after `'categoryId' => $categoryId,`:

```php
            'branches' => BranchChoice::options(),
            'branchId' => $branchId,
```

`certificates()` becomes:

```php
    public function certificates(Request $request): FileResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'player_id' => ['nullable', 'integer', 'exists:players,id'],
        ]);
        $category = Category::findOrFail($data['category_id']);
        $branchId = BranchChoice::fromRequest($request);
        $branch = $branchId === null ? null : Branch::find($branchId);
        $period = $this->period($request);
        ['rows' => $rows] = $this->ranking($category->id, $period, $branchId);
        $branchKey = $branchId === null ? '' : "-b{$branchId}";

        if (isset($data['player_id'])) {
            $playerId = (int) $data['player_id'];
            $row = collect($rows)->firstWhere('player_id', $playerId);
            if ($row === null) {
                $own = $this->stats->players($period['from'], $period['to'], $category->id, $playerId, $branchId)[$playerId] ?? null;
                // Neither expected in the branch's category over the period nor in it now: no certificate "for" it.
                $inItNow = (int) Player::whereKey($playerId)->value('category_id') === $category->id
                    && ($branchId === null || DB::table('branch_player')->where('branch_id', $branchId)->where('player_id', $playerId)->exists());
                abort_if(($own['expected'] ?? 0) === 0 && ! $inItNow, 404);
                $row = $this->names->attach([$own ?? $this->stats->emptyRow($playerId)])[0];
                // Departed or archived: no certificate for them either, even outside the ranking.
                abort_if(! $row['active'], 404);
            }
            $chosen = [$row];
            $filename = "certificate-{$row['membership_id']}{$branchKey}-{$period['key']}.pdf";
        } else {
            $chosen = array_slice($rows, 0, self::PODIUM);
            abort_if($chosen === [], 404);
            $filename = "certificates-{$category->id}{$branchKey}-{$period['key']}.pdf";
        }

        $html = view('pdf.attendance-certificates', [
            'club' => ClubHeader::data(),
            // The certificate line's {category}: "Branch · Category" once the club has branches.
            'category' => $branch === null ? $category->localized_name : $branch->localized_name.' · '.$category->localized_name,
            'periodLabel' => $period['label'],
            'date' => now()->format('d/m/Y'),
            'certificates' => array_map(fn (array $row): array => [
                'name' => $row['name'],
                'rank' => isset($row['rank']) && $row['rank'] <= self::PODIUM ? $row['rank'] : null,
                'score_pct' => $row['score_pct'],
            ], $chosen),
        ])->render();

        return $this->pdf->stream($html, $filename, app()->getLocale() === 'ar', true);
    }
```

`ranking()`:

```php
    private function ranking(int $categoryId, array $period, ?int $branchId = null): array
    {
        $rows = $this->stats->players($period['from'], $period['to'], $categoryId, null, $branchId);
        $active = array_values(array_filter($this->names->attach($rows), fn (array $row) => $row['active']));
        $ranked = AttendanceStats::ranked($active);

        return ['rows' => $ranked, 'unranked' => count($active) - count($ranked)];
    }
```

(its doc: "The category's ranked players in the branch, with names, …")

- [ ] **Step 4: Ranking page**

`resources/js/Pages/Attendance/Ranking.vue`:
- import `import BranchSwitch from '@/Components/Attendance/BranchSwitch.vue';`
- props add `branches: { type: Array, default: () => [] },` and `branchId: { type: Number, default: null },`
- `query()` (lines 27–33):

```js
// The page's filters as they travel in a URL; `changes` overrides them.
function query(changes = {}) {
    const q = { category_id: props.categoryId, ...(props.branchId ? { branch_id: props.branchId } : {}), type: props.period.type, ...changes };
    if (q.type === 'season') q.season = changes.season ?? props.period.season;
    else q.month = changes.month ?? props.period.month;
    return q;
}
const visit = (changes) => router.get(route('attendance.ranking'), query(changes), { preserveScroll: true, replace: true });
const profileHref = (row) => route('players.show', { player: row.player_id, ...(props.branchId ? { attendance_branch: props.branchId } : {}) });
```

- filter row (line 66), first child: `<BranchSwitch :branches="branches" :branch-id="branchId" @switch="(id) => visit({ branch_id: id })" />`
- line 100: `:href="route('players.show', row.player_id)"` → `:href="profileHref(row)"`.

- [ ] **Step 5: Build and run tests**

Run: `npm run build` → succeeds.
Run: `php artisan config:clear && php artisan test --filter="AttendanceBranchRankingTest|AttendanceRankingPageTest|AttendanceCertificatesPdfTest"`
Expected: PASS.

- [ ] **Step 6: Full suite**

Run (background): `php artisan test` → all green.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/AttendanceRankingController.php resources/js/Pages/Attendance/Ranking.vue tests/Feature/AttendanceBranchRankingTest.php
git commit -m "$(cat <<'EOF'
feat(attendance): ranking and certificates per branch and category

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Profile attendance card tabs; report and letter per branch

**Files:**
- Modify: `app/Http/Controllers/AttendancePlayerController.php` (whole file below)
- Modify: `resources/views/pdf/attendance-player.blade.php:43`
- Modify: `resources/views/pdf/attendance-letter.blade.php:31-33`
- Modify: `resources/js/Pages/Players/Partials/AttendanceSection.vue` (imports 2–15, load 31–53, hrefs 74–76, header 93–97, injuries 173)
- Modify: `resources/js/Pages/Players/Partials/InjuryList.vue` (props 16–19, store call 54)
- Test: `tests/Feature/AttendanceBranchPlayerCardTest.php`

**Interfaces:**
- Consumes: Task 1 (stats/at-risk branch params), Task 2 (`InjurySpells::forPlayer(…, $branchId)`, store `branch_id`), Task 3 (`BranchChoice::remembered()`, `BranchSwitch.vue`).
- Produces: `attendance.players.show|report|letter` take `branch_id`; JSON adds `branches` (`[{id, name}]`), `branchId` (?int), `branch` (?string); profile URL query `attendance_branch` opens a tab. File names gain `-b{branchId}`.
- Query counts: +1 per request (the tabs query); +1 `Branch::exists()` only when the player has no tab.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceBranchPlayerCardTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\PreseasonTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceBranchPlayerCardTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');   // season 2026/27
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function a_two_sport_player_gets_a_tab_per_branch_with_its_own_numbers(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->twoSportClub();
        $admin = $this->admin();

        $football = $this->actingAs($admin)->getJson(route('attendance.players.show', $both))->assertOk();
        $football->assertJsonPath('branches', [['id' => $foot->id, 'name' => 'Football'], ['id' => $swim->id, 'name' => 'Swimming']])
            ->assertJsonPath('branchId', $foot->id)
            ->assertJsonPath('branch', 'Football')
            ->assertJsonPath('summary.expected', 5)
            ->assertJsonPath('summary.score_pct', 0)
            ->assertJsonPath('risk.at_risk', true)
            ->assertJsonPath('risk.longest_streak', 3);

        $swimming = $this->actingAs($admin)->getJson(route('attendance.players.show', ['player' => $both, 'branch_id' => $swim->id]))->assertOk();
        $swimming->assertJsonPath('branchId', $swim->id)
            ->assertJsonPath('summary.score_pct', 100)
            ->assertJsonPath('risk.at_risk', false)
            ->assertJsonCount(5, 'sessions');
        $this->assertSame(0, array_sum($swimming->json('monthly.statuses.absent_unexcused')));
        $this->assertSame(3, array_sum($football->json('monthly.statuses.absent_unexcused')));
    }

    #[Test]
    public function the_default_tab_is_the_remembered_branch_and_the_card_never_changes_it(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->twoSportClub();
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('attendance.stats', ['branch_id' => $swim->id]))->assertOk();

        $this->actingAs($admin)->getJson(route('attendance.players.show', $both))->assertJsonPath('branchId', $swim->id);
        $this->actingAs($admin)->getJson(route('attendance.players.show', ['player' => $both, 'branch_id' => $foot->id]))->assertJsonPath('branchId', $foot->id);

        $this->assertSame($swim->id, session('attendance.branch_id'));
    }

    #[Test]
    public function tabs_cover_membership_and_marks_and_a_foreign_branch_falls_back(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'u15' => $u15, 'footer' => $footer, 'swimmer' => $swimmer] = $this->twoSportClub();
        $this->branchMark($this->branchSession($foot, $u15, '2026-10-12'), $swimmer, AttendanceStatus::Present);
        $admin = $this->admin();

        // One branch: one tab (the card hides a single tab) and that branch's numbers.
        $this->actingAs($admin)->getJson(route('attendance.players.show', $footer))
            ->assertJsonCount(1, 'branches')
            ->assertJsonPath('branchId', $foot->id);
        // A guest mark in football gives the swimmer a football tab too.
        $this->actingAs($admin)->getJson(route('attendance.players.show', $swimmer))
            ->assertJsonCount(2, 'branches');
        // Asking for a branch the player has nothing in shows their first branch instead.
        $this->actingAs($admin)->getJson(route('attendance.players.show', ['player' => $footer, 'branch_id' => $swim->id]))
            ->assertJsonPath('branchId', $foot->id);
    }

    #[Test]
    public function injuries_and_preseason_follow_the_tab(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'u15' => $u15, 'both' => $both] = $this->twoSportClub();
        $this->branchMark($this->branchSession($foot, $u15, '2026-10-12'), $both, AttendanceStatus::NotTraining, 'injury');
        PreseasonTarget::create(['branch_id' => $foot->id, 'category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 10]);
        PreseasonTarget::create(['branch_id' => $swim->id, 'category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 4]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(route('attendance.players.show', ['player' => $both, 'branch_id' => $foot->id]))
            ->assertJsonCount(1, 'injuries.spells')
            ->assertJsonPath('injuries.spells.0.open', true)
            ->assertJsonPath('preseason.target', 10);
        $this->actingAs($admin)->getJson(route('attendance.players.show', ['player' => $both, 'branch_id' => $swim->id]))
            ->assertJsonCount(0, 'injuries.spells')
            ->assertJsonPath('preseason.target', 4);
    }

    #[Test]
    public function the_report_prints_the_tab_on_screen(): void
    {
        ['swim' => $swim, 'both' => $both] = $this->twoSportClub();
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.players.report', ['player' => $both, 'branch_id' => $swim->id]))->assertOk();

        $this->assertSame("attendance-{$both->membership_id}-2026-09-01-2027-08-31-b{$swim->id}.pdf", $seen['filename']);
        $this->assertStringContainsString('Swimming', $seen['html']);
    }

    #[Test]
    public function the_letter_lists_the_tabs_absences_only(): void
    {
        ['foot' => $foot, 'both' => $both] = $this->twoSportClub();
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.players.letter', ['player' => $both, 'branch_id' => $foot->id]))->assertOk();

        $this->assertSame("attendance-letter-{$both->membership_id}-2026-09-01-2027-08-31-b{$foot->id}.pdf", $seen['filename']);
        $this->assertStringContainsString('Football', $seen['html']);
        $this->assertStringContainsString('07/09/2026', $seen['html']);   // a football unexcused absence
    }

    #[Test]
    public function the_swimming_letter_has_no_football_absence(): void
    {
        ['swim' => $swim, 'both' => $both] = $this->twoSportClub();
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.players.letter', ['player' => $both, 'branch_id' => $swim->id]))->assertOk();

        $this->assertStringNotContainsString('07/09/2026', $seen['html']);
    }

    #[Test]
    public function a_club_without_branches_has_no_tabs(): void
    {
        $player = $this->player($this->category());

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', $player))
            ->assertJsonPath('branches', [])
            ->assertJsonPath('branchId', null)
            ->assertJsonPath('branch', null);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan config:clear && php artisan test --filter=AttendanceBranchPlayerCardTest`
Expected: FAIL — no `branches` / `branchId` keys; `summary.expected` 10.

- [ ] **Step 3: Controller**

Replace `app/Http/Controllers/AttendancePlayerController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Player;
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\AtRisk;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\AttendanceStatusCatalog;
use App\Services\Attendance\BranchChoice;
use App\Services\Attendance\InjurySpells;
use App\Services\Attendance\PreseasonProgress;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\AttendanceSettings;
use App\Support\Media;
use App\Support\UiLang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * One player's attendance, for the profile card. The routes are named
 * attendance.players.*, so the permission middleware gates them on the
 * attendance module: the profile needs players/view, and its attendance
 * card needs attendance/view on top. The card fetches this after the
 * profile has painted, so the profile never waits for it.
 *
 * A player in two branches has separate numbers per branch: the card shows
 * one branch at a time (branchTabs()), and the PDF report and the parent
 * letter print the tab on screen.
 */
class AttendancePlayerController extends Controller
{
    /** The marks a parent letter lists: absences (custom absence codes too), lates and early departures. */
    public const LETTER_STATUSES = ['late', 'left_early', 'absent_excused', 'absent_unexcused'];

    public function __construct(
        private readonly AttendanceStats $stats,
        private readonly PreseasonProgress $preseason,
        private readonly PdfService $pdf,
        private readonly AtRisk $risk,
        private readonly InjurySpells $injuries,
        private readonly AttendanceStatusCatalog $catalog,
    ) {}

    public function show(Request $request, Player $player): JsonResponse
    {
        $data = $this->data($request, $player);
        ['from' => $from, 'to' => $to] = $data['period'];

        return response()->json([
            ...$data,
            // The card's warning banner, for the card's own period and branch.
            'risk' => $this->risk->forPlayer($player->id, $from, $to, $data['summary'], $data['branchId']),
            // The card's Injuries section: the branch's spells overlapping the period, with details, and unmatched details.
            'injuries' => $this->injuries->forPlayer($player->id, $from, $to, $data['branchId']),
        ]);
    }

    /** The card as a printable PDF, same period and branch (A4 portrait, right-to-left in Arabic). */
    public function report(Request $request, Player $player): Response
    {
        $player->loadMissing('category');
        $data = $this->data($request, $player);
        ['from' => $from, 'to' => $to] = $data['period'];

        $html = view('pdf.attendance-player', [
            ...$data,
            'club' => ClubHeader::data(),
            'player' => $player,
            'photo' => Media::localFile($player->picture_url),
            // A hidden custom code only shows while the player has marks for it in the period.
            'labels' => $this->catalog->labels(null, $this->catalog->withMarks($data['summary']['counts'])),
            'codes' => $this->catalog->codes(),
        ])->render();

        return $this->pdf->stream(
            $html,
            "attendance-{$player->membership_id}-{$from}-{$to}".self::branchSuffix($data['branchId']).'.pdf',
            app()->getLocale() === 'ar',
        );
    }

    /**
     * A formal letter to the player's parents about the period (the card's,
     * or the current season) in the card's branch: club header, date,
     * recipient (the first emergency contact, else "parent / guardian of"),
     * the configured subject and text with their placeholders filled in, the
     * absences, lates and early departures, totals, and signature lines. A4
     * portrait, right-to-left in Arabic.
     */
    public function letter(Request $request, Player $player): Response
    {
        $player->loadMissing('category');
        [$tabs, $branchId] = $this->branchTabs($request, $player);
        [$period, $summary] = $this->periodAndSummary($request, $player, $branchId);
        ['from' => $from, 'to' => $to] = $period;
        $listed = $this->catalog->keysBehavingAs(...self::LETTER_STATUSES);
        $rows = array_reverse(array_values(array_filter(
            $this->stats->playerSessions($player->id, $from, $to, $branchId),
            fn (array $row) => in_array($row['status'], $listed, true),
        )));
        $club = ClubHeader::data();
        $periodText = self::day($from).' – '.self::day($to);
        // As they count: a custom absence code adds to the absences.
        $counts = $summary['scored'];
        $letter = AttendanceSettings::letter([
            'player' => $player->fullname,
            'category' => $player->category?->localized_name ?? '—',
            'period' => $periodText,
            'absences' => $counts['absent_excused'] + $counts['absent_unexcused'],
            'lates' => $counts['late'],
            'club' => $club['name'] ?? '',
        ]);
        $contact = $player->emergencyContacts()->orderBy('id')->value('name');

        $html = view('pdf.attendance-letter', [
            'club' => $club,
            'date' => now()->format('d/m/Y'),
            'recipient' => $contact ?: strtr(UiLang::get('att.letter.guardian_of'), ['{player}' => $player->fullname]),
            'subject' => $letter['subject'],
            'body' => $letter['body'],
            'branch' => self::branchName($tabs, $branchId),
            'periodText' => $periodText,
            'rows' => $rows,
            'summary' => $summary,
            // The totals: unexcused, excused, lates, early departures, then each custom absence code.
            'shown' => [
                'absent_unexcused', 'absent_excused', 'late', 'left_early',
                ...array_diff($this->catalog->keysBehavingAs('absent_unexcused', 'absent_excused'), ['absent_unexcused', 'absent_excused']),
            ],
            'labels' => $this->catalog->labels(),
        ])->render();

        return $this->pdf->stream(
            $html,
            "attendance-letter-{$player->membership_id}-{$from}-{$to}".self::branchSuffix($branchId).'.pdf',
            app()->getLocale() === 'ar',
        );
    }

    /** 'Y-m-d' → 'dd/mm/yyyy', as printed on letters. */
    private static function day(string $date): string
    {
        return substr($date, 8, 2).'/'.substr($date, 5, 2).'/'.substr($date, 0, 4);
    }

    /** '-b{id}' in a branch's file name; nothing for the whole club. */
    private static function branchSuffix(?int $branchId): string
    {
        return $branchId === null ? '' : "-b{$branchId}";
    }

    /** @param  list<array{id: int, name: string}>  $tabs */
    private static function branchName(array $tabs, ?int $branchId): ?string
    {
        foreach ($tabs as $tab) {
            if ($tab['id'] === $branchId) {
                return $tab['name'];
            }
        }

        return null;
    }

    /**
     * The card's branch tabs: every branch the player is a member of now or
     * has a mark in (any date, so the tabs do not change with the period),
     * by id; and the tab shown: the asked `branch_id` when it is one of
     * them, else the remembered attendance branch (BranchChoice) when it is,
     * else the first. No tab (a club without branches, or a player in none):
     * null, and the numbers cover every session. The card never changes the
     * remembered choice. One query.
     *
     * @return array{0: list<array{id: int, name: string}>, 1: ?int}
     */
    private function branchTabs(Request $request, Player $player): array
    {
        $asked = $request->validate(['branch_id' => ['nullable', 'integer']])['branch_id'] ?? null;
        $tabs = Branch::query()
            ->whereIn('id', DB::table('branch_player')->select('branch_id')->where('player_id', $player->id))
            ->orWhereIn('id', DB::table('attendances')
                ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
                ->where('attendances.player_id', $player->id)
                ->whereNotNull('training_sessions.branch_id')
                ->select('training_sessions.branch_id'))
            ->orderBy('id')
            ->get()
            ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->localized_name])
            ->values()
            ->all();
        $ids = array_column($tabs, 'id');

        foreach ([$asked === null ? null : (int) $asked, BranchChoice::remembered($request)] as $candidate) {
            if ($candidate !== null && in_array($candidate, $ids, true)) {
                return [$tabs, $candidate];
            }
        }

        return [$tabs, $ids[0] ?? null];
    }

    /**
     * The requested period (default: the current season) and the player's summary over it in the branch.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function periodAndSummary(Request $request, Player $player, ?int $branchId): array
    {
        $period = ActivityPeriod::fromRequestOrSeason($request)->toArray();

        return [$period, $this->stats->players($period['from'], $period['to'], null, $player->id, $branchId)[$player->id] ?? $this->stats->emptyRow($player->id)];
    }

    /** @return array<string, mixed> */
    private function data(Request $request, Player $player): array
    {
        [$tabs, $branchId] = $this->branchTabs($request, $player);
        [$period, $summary] = $this->periodAndSummary($request, $player, $branchId);
        ['from' => $from, 'to' => $to] = $period;

        return [
            'period' => $period,
            'branches' => $tabs,
            'branchId' => $branchId,
            'branch' => self::branchName($tabs, $branchId),
            'summary' => $summary,
            'monthly' => $this->stats->monthly($from, $to, null, $player->id, $branchId),
            // The tab's own target; a player in no branch of a club with branches has none to show.
            'preseason' => $player->category_id && ($branchId !== null || ! Branch::exists())
                ? $this->preseason->forCategory((int) $player->category_id, $to, $branchId)
                : null,
            'sessions' => $this->stats->playerSessions($player->id, $from, $to, $branchId),
        ];
    }
}
```

- [ ] **Step 4: PDF views**

`resources/views/pdf/attendance-player.blade.php` — after line 43 (`<div class="muted">{{ $player->category?->localized_name }}</div>`):

```blade
                @if (!empty($branch))
                    <div class="muted">{{ $L('branch') }}: {{ $branch }}</div>
                @endif
```

`resources/views/pdf/attendance-letter.blade.php` — in `table.meta`, after the "to" row (line 32):

```blade
        @if (!empty($branch))
            <tr><td class="label">{{ $L('branch') }}</td><td>{{ $branch }}</td></tr>
        @endif
```

- [ ] **Step 5: Profile card**

`resources/js/Pages/Players/Partials/AttendanceSection.vue`:
- line 3: `import { Link, usePage } from '@inertiajs/vue3';`
- after `import InjuryList from './InjuryList.vue';`: `import BranchSwitch from '@/Components/Attendance/BranchSwitch.vue';`
- component doc gains: ` * A two-sport player has one tab per branch; every number, the report and the letter are the tab's.`
- replace lines 31–53 (`data` … `defineExpose`) with:

```js
const data = ref(null);
const loading = ref(true);
const failed = ref(false);
const lastQuery = ref({}); // the period, as PeriodFilter sends it
let ticket = 0; // a slow answer for an older period or tab never overwrites a newer one

// The branch tab. A link from a branch's report page or the dashboard
// (?attendance_branch=) opens on that branch; otherwise the server picks
// (the remembered branch when the player has it, else their first).
const page = usePage();
const branchId = ref(Number(new URL(page.url, window.location.origin).searchParams.get('attendance_branch')) || null);
const branchQuery = () => (branchId.value ? { branch_id: branchId.value } : {});

async function load(query = {}) {
    const mine = ++ticket;
    lastQuery.value = query;
    loading.value = true;
    failed.value = false;
    try {
        const response = await window.axios.get(route('attendance.players.show', { player: props.player.id, ...query, ...branchQuery() }));
        if (mine === ticket) {
            data.value = response.data;
            branchId.value = response.data.branchId; // the tab the server settled on
        }
    } catch {
        if (mine === ticket) failed.value = true;
    } finally {
        if (mine === ticket) loading.value = false;
    }
}
onMounted(() => load());
const retry = () => load(lastQuery.value);
function switchBranch(id) {
    branchId.value = id;
    load(lastQuery.value);
}
defineExpose({ load });
```

- replace lines 73–76 (report and letter hrefs) with:

```js
// Same period and branch tab as the card.
const printQuery = computed(() => (data.value ? { player: props.player.id, ...periodQuery(data.value.period), ...(data.value.branchId ? { branch_id: data.value.branchId } : {}) } : null));
const reportHref = computed(() => (printQuery.value ? route('attendance.players.report', printQuery.value) : null));
// The parent letter, for the same period and branch.
const letterHref = computed(() => (printQuery.value ? route('attendance.players.letter', printQuery.value) : null));
```

- header (line 93), first child of `<div v-if="data" class="flex flex-wrap items-center gap-2">`:

```vue
                <BranchSwitch :branches="data.branches" :branch-id="data.branchId" @switch="switchBranch" />
```

- line 173: `<InjuryList v-if="data.injuries" :player-id="player.id" :branch-id="data.branchId" :injuries="data.injuries" @saved="retry" />`

`resources/js/Pages/Players/Partials/InjuryList.vue`:
- props add `branchId: { type: Number, default: null }, // the card's tab: a spell is one branch's`
- line 54:

```js
        else await window.axios.post(route('attendance.injury-notes.store', props.playerId), { start_date: e.start_date, ...(props.branchId ? { branch_id: props.branchId } : {}), ...details });
```

- [ ] **Step 6: Build and run tests**

Run: `npm run build` → succeeds.
Run: `php artisan config:clear && php artisan test --filter="AttendanceBranchPlayerCardTest|AttendancePlayerCardTest|AttendancePlayerReportTest|AttendanceLetterPdfTest|AttendanceInjuryNotesTest"`
Expected: PASS.

- [ ] **Step 7: Full suite and JS tests**

Run: `npm run test:js` → green. Run (background): `php artisan test` → all green.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/AttendancePlayerController.php resources/views/pdf/attendance-player.blade.php resources/views/pdf/attendance-letter.blade.php resources/js/Pages/Players/Partials/AttendanceSection.vue resources/js/Pages/Players/Partials/InjuryList.vue tests/Feature/AttendanceBranchPlayerCardTest.php
git commit -m "$(cat <<'EOF'
feat(attendance): profile card tab per branch; report and letter print the tab

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Injuries page per branch; session sheet names its branch

**Files:**
- Modify: `app/Http/Controllers/AttendanceInjuryController.php` (`index()` 37–52, class doc 18–22)
- Modify: `resources/js/Pages/Attendance/Injuries.vue` (props 18–24, 28–31, filters 56, profile link 81)
- Modify: `app/Http/Controllers/AttendanceSheetController.php` (`session()` 119, 129–140)
- Modify: `resources/views/pdf/attendance-session-sheet.blade.php:7,39`
- Test: `tests/Feature/AttendanceBranchPagesTest.php`

**Interfaces:**
- Consumes: Task 2 `InjurySpells::club(…, $branchId)`; Task 3 `BranchChoice::fromRequest()`, `options()`, `BranchSwitch.vue`.
- Produces: Inertia props `branches`, `branchId` on `Attendance/Injuries`; session sheet view data `session.branch` (?string).
- Query counts: `club()` unchanged; the sheet loads `branch` with the categories (+1).

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceBranchPagesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceBranchPagesTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_injuries_page_is_one_branchs(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->injuredFootballer();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.injuries', ['branch_id' => $foot->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Injuries')
                ->where('branchId', $foot->id)
                ->has('branches', 2)
                ->has('current', 1)
                ->where('current.0.player_id', $both->id)
                ->where('current.0.start', '2026-10-05'));

        $this->actingAs($admin)->get(route('attendance.injuries', ['branch_id' => $swim->id]))
            ->assertInertia(fn (Assert $page) => $page->has('current', 0)->has('spells', 0));
    }

    #[Test]
    public function the_session_sheet_names_its_branch(): void
    {
        [, $swim] = $this->twoSports();
        $training = $this->branchSession($swim, $this->category('U15'), '2026-10-05', ['state' => SessionState::Planned]);
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.sheets.session', $training))->assertOk();

        $this->assertStringContainsString('<b>Swimming</b>', $seen['html']);
    }

    #[Test]
    public function a_club_wide_session_sheet_names_no_branch(): void
    {
        $training = TrainingSession::create([
            'category_id' => $this->category('U15')->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.sheets.session', $training))->assertOk();

        $this->assertStringNotContainsString('&middot; <b>U15</b>', $seen['html']);
        $this->assertStringContainsString('<b>U15</b>', $seen['html']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan config:clear && php artisan test --filter=AttendanceBranchPagesTest`
Expected: FAIL — `branchId` prop missing; football and swimming mixed (no current spell); the sheet has no `<b>Swimming</b>`.

- [ ] **Step 3: Injuries controller and page**

`app/Http/Controllers/AttendanceInjuryController.php` — add `use App\Services\Attendance\BranchChoice;`; class doc: "…and the club's injuries list, one branch at a time (BranchChoice, shared with the calendar)."; `index()` becomes:

```php
    /**
     * The club's injuries (`attendance.injuries`, view) in one branch: who is
     * injured now, and every spell in the period (default: the current
     * season), for active players, optionally those now in one category.
     */
    public function index(Request $request): PageResponse
    {
        $validated = $request->validate(['category_id' => ['nullable', 'integer', 'exists:categories,id']]);
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $branchId = BranchChoice::fromRequest($request);
        $period = ActivityPeriod::fromRequestOrSeason($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        return Inertia::render('Attendance/Injuries', [
            'period' => $period->toArray(),
            'categoryId' => $categoryId,
            'categories' => Category::orderBy('id')->get()
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->localized_name])
                ->values()->all(),
            'branches' => BranchChoice::options(),
            'branchId' => $branchId,
            ...$this->spells->club($from, $to, $categoryId, $branchId),
        ]);
    }
```

`resources/js/Pages/Attendance/Injuries.vue`:
- import `import BranchSwitch from '@/Components/Attendance/BranchSwitch.vue';`
- props add `branches: { type: Array, default: () => [] },` and `branchId: { type: Number, default: null },`
- replace lines 28–31 with:

```js
const branchQuery = computed(() => (props.branchId ? { branch_id: props.branchId } : {}));
const keep = computed(() => ({ ...(props.categoryId ? { category_id: props.categoryId } : {}), ...branchQuery.value }));
function pickCategory(value) {
    router.get(route('attendance.injuries'), { ...periodQuery(props.period), ...branchQuery.value, ...(value ? { category_id: Number(value) } : {}) }, { preserveScroll: true, replace: true });
}
function switchBranch(id) {
    router.get(route('attendance.injuries'), { ...periodQuery(props.period), ...keep.value, branch_id: id }, { preserveScroll: true, replace: true });
}
// The profile opens its attendance card on the page's branch, where details are added.
const profileHref = (row) => route('players.show', { player: row.player_id, ...(props.branchId ? { attendance_branch: props.branchId } : {}) });
```

- filter row (line 56), first child: `<BranchSwitch :branches="branches" :branch-id="branchId" @switch="switchBranch" />`
- line 81: `:href="route('players.show', row.player_id)"` → `:href="profileHref(row)"`.

- [ ] **Step 4: Session sheet**

`app/Http/Controllers/AttendanceSheetController.php` `session()`:
- line 119: `$session->loadMissing('categories', 'branch');`
- in the `'session' => [` array, after `'kind' => $session->kind->value,`:

```php
                // The sport, when the club has branches (null = a club-wide session).
                'branch' => $session->branch?->localized_name,
```

`resources/views/pdf/attendance-session-sheet.blade.php`:
- line 7:

```blade
    $footer = (!empty($session['branch']) ? $session['branch'].' · ' : '').implode(' · ', $session['categories']).' · '.$session['date'].' — '.strtr($L('att.sheet.page'), ['{page}' => '{PAGENO}', '{pages}' => '{nbpg}']);
```

- line 39:

```blade
    <div class="sub">@if (!empty($session['branch'])){{ $L('branch') }}: <b>{{ $session['branch'] }}</b> &middot; @endif<b>{{ implode(' · ', $session['categories']) }}</b> &middot; {{ $L('att.kind.'.$session['kind']) }} &middot; {{ $session['dateLabel'] }} &middot; <bdi dir="ltr">{{ $session['time'] }}</bdi></div>
```

- [ ] **Step 5: Build and run tests**

Run: `npm run build` → succeeds.
Run: `php artisan config:clear && php artisan test --filter="AttendanceBranchPagesTest|AttendanceInjuriesPageTest|AttendanceSessionSheetPdfTest|AttendanceSheetTranslationsTest"`
Expected: PASS.

- [ ] **Step 6: Full suite**

Run (background): `php artisan test` → all green.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/AttendanceInjuryController.php resources/js/Pages/Attendance/Injuries.vue app/Http/Controllers/AttendanceSheetController.php resources/views/pdf/attendance-session-sheet.blade.php tests/Feature/AttendanceBranchPagesTest.php
git commit -m "$(cat <<'EOF'
feat(attendance): injuries page per branch; session sheet names its branch

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Full verification

- [ ] **Step 1:** `npm run i18n:check` → no missing or unused keys (this package adds none).
- [ ] **Step 2:** `npm run build` → succeeds; `npm run test:js` → green.
- [ ] **Step 3:** `vendor/bin/pint --dirty` → no change left (commit any formatting fix with the explicit paths it touched, message `style: pint`, trailer included).
- [ ] **Step 4:** Full suite in the background: `php artisan config:clear && php artisan test` → all green. A test that posts an injury detail without `branch_id` while a branch exists gets `'branch_id' => Branch::value('id')`; never loosen the rule.
- [ ] **Step 5: Migration on a copy (never the real DB).** Copy the dev DB read-only into the scratchpad: `cp D:/irnb-laravel/database/database.sqlite C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/f5180bdf-51af-4cd4-a47b-5d8543b2a1bb/scratchpad/p3-check.sqlite`, then from `D:/irnb-reports`: `DB_DATABASE=C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/f5180bdf-51af-4cd4-a47b-5d8543b2a1bb/scratchpad/p3-check.sqlite php artisan migrate --force` twice (the second run reports nothing to migrate). With the same `DB_DATABASE`, `php artisan tinker --execute="dump(DB::table('injury_notes')->whereNull('branch_id')->count(), DB::select('select player_id, branch_key, start_date, count(*) c from injury_notes group by 1, 2, 3 having c > 1'));"` → `0` and `[]` (when the copy has branches).
- [ ] **Step 6: Query plan on the copy.** With the same `DB_DATABASE`, run tinker: build `DB::table('attendances')->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')->where('training_sessions.state', 'held')->whereBetween('training_sessions.date', ['2026-09-01', '2026-10-31'])`, apply `App\Services\Attendance\BranchScope::apply($q, <football id>)`, and `dump(DB::select('EXPLAIN QUERY PLAN '.$q->toSql(), $q->getBindings()))` → the plan uses `training_sessions_date_index`, never `training_sessions_branch_id_index`.
- [ ] **Step 7: Browser QA** (app at `http://127.0.0.1:2026` from the worktree, `php artisan serve --port=2026`, dev DB untouched — point `DB_DATABASE` at the scratch copy): statistics, alerts, ranking, injuries each show the switch with two branches, switching keeps period and category, exports carry the branch; the calendar shows the branch last chosen on a report page; a two-sport player's profile card shows two tabs, each tab's report/letter PDF names its branch; a one-branch player shows no tab; the dashboard at-risk card links a flagged player to the right tab; the session sheet shows the branch. Print one letter in Arabic and check the branch row reads right-to-left.
- [ ] **Step 8:** `git log --stat main..HEAD` review: each commit holds only its task's files; nothing outside `D:/irnb-reports`.

---

## Spec coverage (self-review)

| Spec "Reports" item | Task |
|---|---|
| Every player number from one branch's sessions (`training_sessions.branch_id`; null = club without branches) | 1 (stats, streaks, at-risk, pre-season), 2 (injury spells) |
| Report pages: switch, one branch, remembered `attendance.branch_id`, no "all" view | 3 (stats + export + PDF), 4 (alerts + export), 5 (ranking + certificates), 7 (injuries) |
| Two-sport player appears in each branch's pages with that branch's marks | 1, 3, 4, 5, 7 tests |
| Profile card: tab per branch with marks or membership, none for one branch; own tiles, chart, sessions, banner, injuries, pre-season | 6 |
| Injury spells per branch; `injury_notes.branch_id` backfilled, unique key with short name | 2 |
| PDF report and parent letter per branch from the current tab | 6 |
| Dashboard at-risk: distinct players flagged in any branch, link to the flagged branch | 4 |
| Session paper sheet shows the branch name | 7 |
| BranchObserver adoption + branch delete cleanup for the new column | 2 |
