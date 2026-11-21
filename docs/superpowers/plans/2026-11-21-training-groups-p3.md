# Training Groups P3 (Report Filter) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The attendance statistics (and their export), the attendance ranking (and its certificates) and the players-at-risk list (and its export) get a Group select when the chosen category has training groups; with a group chosen, every number comes from that group's sessions only, and with "all groups" every number is exactly what it was before.

**Architecture:** No schema change. One new condition class, `GroupScope` (a mark belongs to its session's `training_sessions.group_id`), built like `BranchScope` and added to the queries of `AttendanceStats` through a trailing `?int $groupId` parameter. One new request reader, `GroupChoice` (the report pages' `group_id`, valid only for the page's branch + category), shared by the three controllers. `AtRisk` passes the group through. Everything else that reads `AttendanceStats` (injuries, profile card, player PDF, parent letter, dashboard, literary report) keeps calling it without a group and is pinned by tests before the service is touched.

**Tech Stack:** Laravel 13, PHPUnit class tests (`#[Test]`, `RefreshDatabase`), Inertia + Vue 3 `<script setup>`, vue-i18n flat dotted keys, mPDF Blade views, Playwright help shots, SQLite (dev/desktop) and MySQL/MariaDB.

**Spec:** `docs/superpowers/specs/2026-11-20-training-groups-design.md` (phase 3 of 3: "Reports (phase 3)", and the owner decisions "Statistics, ranking, alerts → Extra group filter" and "Report numbers of a moved player")

## Global Constraints

- Work in the git worktree `D:/irnb-groups` on a new branch `feat/training-groups-p3`, created from the tip of `feat/training-groups-p2` (phase 2 is finished and verified there; its merge into `main` waits for the owner) (`git switch -c feat/training-groups-p3 feat/training-groups-p2`). `vendor`, `node_modules` and the Vite build already exist there; never junction them. Never touch `D:/irnb-laravel`.
- Phase 2 must be in `main` before anything else. Check: `git grep -n "NO_GROUP" main -- app/Services/Attendance/MonthSheet.php`, `git grep -n "function options" main -- app/Models/TrainingGroup.php` and `git grep -n "const CADETS" main -- resources/help/shots.js` must each print a line. If one prints nothing, stop and report: this plan builds on phase 2.
- This plan lives at `docs/superpowers/plans/2026-11-21-training-groups-p3.md`. If it is still untracked when work starts, commit it first on the branch, alone: `docs(attendance): training groups P3 plan`.
- Edits are given as old → new against phase 1's head (`40b687bd`) plus phase 2's plan. Phase 2 does not edit any PHP or Vue file this plan edits. Three files are shared with other work: `resources/js/i18n/{ar,fr,en}.json` (phase 2 and `feat/status-history` add keys elsewhere in them) and `resources/help/shots.js` (phase 2 adds shots and the `CADETS` constant). Find each anchor by its text, not by its line number; add this plan's lines next to what is there and never delete code this plan does not show. If an anchor's text is gone, stop and report.
- Stage explicit file paths only, never a directory. Do not push. The owner decides when `main` is pushed.
- Tests are PHPUnit classes in `tests/Feature`, not Pest functions. Run one class with `php artisan test --filter=<ClassName>`. Never run two test commands at the same time. A private test helper must not reuse a `TestCase` method name (`session`, `get`, `post`, `put`, `json`, `call`, `seed`, `travel`, `mock`).
- No migration in this phase: phase 1's `training_sessions.group_id` (indexed, nullable) is all it needs.
- `group_id` null always means "the whole category": football and every category without groups must behave exactly as before. A category without groups gets no Group field and no extra text anywhere. "All groups" must give byte-for-byte the numbers, file names and titles the reports have today.
- Unchanged, and pinned by Task 1: the injuries page, the player profile attendance card, the player PDF report, the parent letter, the dashboard cards, the literary report. Do not add a group parameter to `AttendanceStats::categories()`, `AttendanceStats::playerSessions()`, `InjurySpells` or `PreseasonProgress`.
- No SQLite-only SQL: the suite also runs on MariaDB. The group condition is written `+training_sessions.group_id = ?` (see "How the group condition is written, and why").
- Player names always come from `Player::fullname`.
- i18n: flat dotted keys in `resources/js/i18n/{ar,fr,en}.json`. The files are sorted by key: insert each new key at its alphabetical place without reformatting the file. Call with `t()` directly, never `te()`. No key segment named after a String method (`link`, `search`, `replace`, `at`, `sub`, `big`, `small`, `fixed`, `anchor`, `length`). Never use `att.group.<something>` or `att.grid.<something>` (they would nest under the flat keys `att.group` and `att.grid`).
- No CDN or remote resource. The desktop app runs offline.
- Interface names: Arabic «الفوج» / «الأفواج», French « Groupe », English "Group". The reports' first entry is the new `att.all_groups` («كل الأفواج» / « Tous les groupes » / "All groups").
- Help Center: an Arabic article change needs its `fr` and `en` twins in the same commit; `php artisan help:check` and `php artisan test --filter=Help` must pass. Demo data stays Arabic in every language.
- The owner's own servers on ports 2026 and 5173 must not be touched. The help shots serve on 8765; the final browser check serves on 2027.
- Commit messages are conventional (`feat(attendance): …`) and end with:
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

### Rules this plan fixes where the spec is silent

1. **The reports' Group select** lists «كل الأفواج» (the default) and then the training groups of the page's branch + category, by name then id (`TrainingGroup::options()`, phase 2). It is shown only when that list is not empty. With "all categories" (statistics, alerts) there is no select.
2. **No entry for the sessions with no group.** The month grid's switch needs one (`MonthSheet::NO_GROUP`), because those sessions would otherwise be unreachable; on the reports "all groups" already counts them, and «كل الأفواج» beside «كل الفئة» would read as the same thing. `group_id=0` is treated like any unknown value.
3. **An unknown `group_id` means all groups**, silently: no value, `0`, a group of another category or branch, a category without groups, no category. This is how the reports already treat a category left over from another branch. A value that is not an integer fails validation.
4. **Changing the branch or the category drops the group** (the browser stops sending it; the server would ignore it anyway).
5. **Statistics page with a group:** the six tiles, the status breakdown, the two charts, the top/bottom 5, the player table, the comparison with the previous period, and the three export formats follow the group. The "by category" table (with its pre-season column) always compares whole categories: it never follows the group, exactly as it never follows the category filter. A pre-season session never has a group (phase 2).
6. **Ranking with a group:** the pool is the active players with a counted mark in the group's sessions; the 5-session minimum, the tie rules and the "not ranked" count apply inside the group. Certificates name the group after the category (`Swimming · Minime · G1`) and their file name gains `-g{id}`. A single player's certificate for a group needs at least one counted mark in that group's sessions over the period (404 otherwise).
7. **Alerts with a group:** both rules (low score, unexcused streak) are computed from the group's sessions only. The category filter keeps meaning "players now in that category", as today. The row's «رسالة إلى الولي» and profile links are unchanged: they keep the branch's whole numbers (spec: parent letter and profile card unchanged).
8. **Exports:** the file name gains `-g{id}` after the category; the title line gains the group's name after the category's. Without a group both are unchanged.
9. **A guest's mark** (a player added by hand to another group's session) counts in that session's group, as a guest from another branch counts in the session's branch.
10. **A player who moved** needs no special code: the marks until the move sit in the old group's sessions and the rest in the new group's.

### How the group condition is written, and why

`GroupScope::apply()` adds `whereRaw('+training_sessions.group_id = ?', [$groupId])`, the same shape as `BranchScope` (read its class comment).

- `training_sessions.group_id` is indexed (phase 1: `training_sessions_group_id_index`, used by `TrainingGroup::isInUse()` and the retag on a schedule line). Real databases are never ANALYZEd, and without statistics sqlite prefers an equality on an indexed column to a date range: a plain `group_id = ?` would make every report query lead by the group's whole history instead of the period.
- The unary plus keeps that index out of reach on sqlite and is a no-op on MySQL/MariaDB. The query plan with a group is then **the same plan** as with all groups, plus one filter on rows already read. A group can only make a report cheaper, never dearer than the report as it is today, which is the measured baseline of the existing performance work.
- Checked while planning on a scratch sqlite 3.40 database with these tables' indexes: for the marks query, the streak scan and the sessions-per-month query, `+training_sessions.group_id = ?` gives the identical `EXPLAIN QUERY PLAN` to no group, while `training_sessions.group_id = ?` switches all three to `training_sessions_group_id_index`. Task 2 pins this in a test.
- The group adds a condition, never a query: the query-count tests of `AttendanceStats`, `AtRisk` and the dashboard are untouched, and Task 2 adds one for the group. The pages gain one small query (the category's groups) only when a category is chosen.

## File Structure

| File | Responsibility |
|---|---|
| `tests/Support/GroupAttendanceFixtures.php` | `splitCategory()`: one category in two groups with a moved player, for every test of this plan |
| `tests/Feature/AttendanceGroupReportsUnchangedTest.php` | Pins today's numbers: the category without a group, and the six surfaces that never follow a group |
| `app/Services/Attendance/GroupScope.php` | The one group condition of the reports |
| `app/Services/Attendance/AttendanceStats.php` | `?int $groupId` on `players()`, `monthly()`, `sessionsByMonth()`, `unexcusedStreaks()` |
| `app/Services/Attendance/GroupChoice.php` | The report pages' `group_id`: options and the valid choice |
| `app/Http/Controllers/AttendanceStatsController.php`, `resources/js/Pages/Attendance/Stats.vue` | Group select on the statistics; group in the export names |
| `app/Http/Controllers/AttendanceRankingController.php`, `resources/js/Pages/Attendance/Ranking.vue` | Group select on the ranking; certificates per group |
| `app/Services/Attendance/AtRisk.php` | `?int $groupId` on `list()` |
| `app/Http/Controllers/AttendanceAlertsController.php`, `resources/js/Pages/Attendance/Alerts.vue` | Group select on the alerts; group in the export names |
| `resources/js/i18n/{ar,fr,en}.json` | 1 new key (`att.all_groups`) |
| `resources/help/shots.js` | One new shot (`att-ranking-group`) |
| `resources/help/{ar,fr,en}/attendance/stats-ranking.md`, `resources/help/{ar,fr,en}/attendance/alerts-injuries.md` | One paragraph each |

---

### Task 1: Fixture and pins of today's numbers

No production code. The fixture every later test uses, and a test class that pins what must not change: the category's numbers when no group is chosen, and the six surfaces the spec lists as unchanged. It passes at once; from Task 2 on it is the regression guard.

**Files:**
- Create: `tests/Support/GroupAttendanceFixtures.php`
- Test: `tests/Feature/AttendanceGroupReportsUnchangedTest.php`

**Interfaces:**
- Consumes: `Tests\Support\AttendanceFixtures` (`admin()`, `player(Category)`), `Tests\Support\BranchAttendanceFixtures` (`branchSession(Branch, Category, string $date, array $extra)`, `branchMark(TrainingSession, Player, AttendanceStatus, ?string $reason)`, `spyPdf()`), `TrainingGroup`, `GroupMembership::add(TrainingGroup $group, int $playerId, ?string $from): void` (phase 1).
- Produces: `GroupAttendanceFixtures::splitCategory(): array{swim: Branch, minime: Category, g1: TrainingGroup, g2: TrainingGroup, a: Player, b: Player, mover: Player}` with these numbers over 2026-09-01 → 2026-11-30 (and over season 2026/27):

  | | all groups | G1 | G2 |
  |---|---|---|---|
  | held sessions (Sep, Oct, Nov) | 10, 10, 2 | 4, 5, 1 | 4, 5, 1 |
  | cancelled | 0, 0, 1 | 0, 0, 0 | 0, 0, 1 |
  | `a` expected / score % | 12 / 100.0 | 10 / 100.0 | — |
  | `b` expected / score % / missed min / longest streak | 12 / 50.0 / 360 / 1 | — | 10 / 40.0 / 360 / 3 |
  | `mover` expected / score % / missed min / longest streak | 12 / 50.0 / 270 / 1 | 5 / 0.0 / 270 / 3 | 5 / 100.0 / 0 / 0 |
  | ranking | a, b, mover | a, mover | mover, b |
  | at risk | b, mover | mover | b |

- [ ] **Step 1: Write the fixture**

Create `tests/Support/GroupAttendanceFixtures.php`:

```php
<?php

namespace Tests\Support;

use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingGroup;
use App\Services\Attendance\GroupMembership;

/**
 * A category split into two training groups, for the tests of the reports'
 * group filter (use with AttendanceFixtures and BranchAttendanceFixtures).
 */
trait GroupAttendanceFixtures
{
    /**
     * Swimming · Minime in two groups, season 2026/27.
     *
     * Ten Saturdays (2026-09-05 → 11-07): G1 at 09:30–11:00 (90 min), then G2
     * at 11:00–13:00 (120 min). Two Tuesdays (09-08, 09-15) at 17:00–18:30 for
     * the whole category (no group). One cancelled G2 session on 2026-11-14.
     *
     * "a" (G1): present at everything.
     * "b" (G2): unexcused on the first three Saturdays, then present.
     * "mover" (G1, then G2 from 2026-10-10): the first five Saturdays with G1
     * (unexcused × 3, present × 2), the last five with G2 (present × 5).
     * All three are present on both Tuesdays, which break every streak when
     * the groups are read together.
     *
     * G1: a 10 expected, 100 %; mover 5 expected, 0 %, streak 3, 270 min missed.
     * G2: b 10 expected, 40 %, streak 3, 360 min missed; mover 5 expected, 100 %.
     * All groups: 12 expected each; a 100 %, b 50 %, mover 50 %; longest streak 1.
     *
     * @return array{swim: Branch, minime: Category, g1: TrainingGroup, g2: TrainingGroup, a: Player, b: Player, mover: Player}
     */
    private function splitCategory(): array
    {
        $swim = Branch::create(['name' => 'Swimming']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $g1 = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G1']);
        $g2 = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G2']);

        [$a, $b, $mover] = [$this->player($minime), $this->player($minime), $this->player($minime)];
        foreach ([$a, $b, $mover] as $player) {
            $swim->players()->attach($player->id, ['membership_id' => $player->membership_id]);
        }

        $membership = app(GroupMembership::class);
        $membership->add($g1, $a->id, null);
        $membership->add($g2, $b->id, null);
        $membership->add($g1, $mover->id, null);
        $membership->add($g2, $mover->id, '2026-10-10');

        $saturdays = ['2026-09-05', '2026-09-12', '2026-09-19', '2026-09-26', '2026-10-03', '2026-10-10', '2026-10-17', '2026-10-24', '2026-10-31', '2026-11-07'];
        foreach ($saturdays as $i => $date) {
            $first = $this->branchSession($swim, $minime, $date, ['group_id' => $g1->id, 'start_time' => '09:30', 'end_time' => '11:00']);
            $second = $this->branchSession($swim, $minime, $date, ['group_id' => $g2->id, 'start_time' => '11:00', 'end_time' => '13:00']);
            $earlyMiss = $i < 3 ? AttendanceStatus::AbsentUnexcused : AttendanceStatus::Present;
            $this->branchMark($first, $a, AttendanceStatus::Present);
            $this->branchMark($second, $b, $earlyMiss);
            $this->branchMark($i < 5 ? $first : $second, $mover, $earlyMiss);
        }

        foreach (['2026-09-08', '2026-09-15'] as $date) {
            $plain = $this->branchSession($swim, $minime, $date, ['start_time' => '17:00', 'end_time' => '18:30']);
            foreach ([$a, $b, $mover] as $player) {
                $this->branchMark($plain, $player, AttendanceStatus::Present);
            }
        }

        $this->branchSession($swim, $minime, '2026-11-14', [
            'group_id' => $g2->id, 'start_time' => '11:00', 'end_time' => '13:00',
            'state' => SessionState::Cancelled, 'cancel_reason' => 'Pool closed',
        ]);

        return compact('swim', 'minime', 'g1', 'g2', 'a', 'b', 'mover');
    }
}
```

- [ ] **Step 2: Write the pins**

Create `tests/Feature/AttendanceGroupReportsUnchangedTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\TrainingSession;
use App\Services\Attendance\AtRisk;
use App\Services\Attendance\AttendanceStats;
use App\Services\Dashboard\AttendanceCard;
use App\Services\LiteraryReport\Sections\AttendanceSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\Support\GroupAttendanceFixtures;
use Tests\TestCase;

/**
 * What the reports' group filter must never change: a category's numbers
 * while no group is chosen, and the pages that have no group filter at all
 * (injuries, profile card, player PDF, parent letter, dashboard, literary
 * report). Written before the filter existed; it must pass before and after.
 */
class AttendanceGroupReportsUnchangedTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, GroupAttendanceFixtures, RefreshDatabase;

    private const FROM = '2026-09-01';

    private const TO = '2026-11-30';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');   // season 2026/27: 2026-09-01 → 2027-08-31
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_services_count_every_session_of_the_category(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'a' => $a, 'b' => $b, 'mover' => $mover] = $this->splitCategory();
        $stats = app(AttendanceStats::class);

        $rows = $stats->players(self::FROM, self::TO, $minime->id, null, $swim->id);
        $this->assertSame([$a->id, $b->id, $mover->id], array_keys($rows));
        $this->assertSame([12, 12, 12], array_column($rows, 'expected'));
        $this->assertSame([100.0, 50.0, 50.0], array_column($rows, 'score_pct'));
        $this->assertSame([0, 360, 270], array_column($rows, 'missed_minutes'));

        $sessions = $stats->sessionsByMonth(self::FROM, self::TO, $minime->id, $swim->id);
        $this->assertSame([10, 10, 2], $sessions['held']);
        $this->assertSame([0, 0, 1], $sessions['cancelled']);
        $this->assertSame([6, 0, 0], $stats->monthly(self::FROM, self::TO, $minime->id, null, $swim->id)['statuses']['absent_unexcused']);

        // Read together, the whole-category Tuesdays break both streaks.
        $streaks = $stats->unexcusedStreaks(self::FROM, self::TO, null, $swim->id);
        $this->assertSame(1, $streaks[$b->id]['longest']);
        $this->assertSame(1, $streaks[$mover->id]['longest']);

        $atRisk = app(AtRisk::class)->list(self::FROM, self::TO, $minime->id, $swim->id);
        $this->assertEqualsCanonicalizing([$b->id, $mover->id], array_column($atRisk, 'player_id'));
        $this->assertSame([false, false], array_column($atRisk, 'streak'));
    }

    #[Test]
    public function the_three_report_pages_show_the_whole_category_when_no_group_is_asked(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'a' => $a, 'b' => $b, 'mover' => $mover] = $this->splitCategory();
        $admin = $this->admin();
        $filters = ['branch_id' => $swim->id, 'category_id' => $minime->id];

        $this->actingAs($admin)->get(route('attendance.stats', $filters + ['period' => 'custom', 'from' => self::FROM, 'to' => self::TO]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Stats')
                ->where('totals.held', 22)
                ->where('totals.cancelled', 1)
                ->where('totals.expected', 36)
                ->where('totals.score_pct', 66.7)
                ->where('totals.missed_hours', 10.5)
                ->where('categoryRows.0.held', 22)
                ->where('categoryRows.0.expected', 36));

        $this->actingAs($admin)->get(route('attendance.ranking', $filters + ['type' => 'season', 'season' => 2026]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Ranking')
                ->where('rows', fn ($rows) => collect($rows)->pluck('player_id')->all() === [$a->id, $b->id, $mover->id])
                ->where('rows.1.score_pct', 50)
                ->where('unranked', 0));

        $this->actingAs($admin)->get(route('attendance.alerts', $filters))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Alerts')
                ->where('rows', fn ($rows) => collect($rows)->pluck('player_id')->sort()->values()->all() === [$b->id, $mover->id])
                ->where('rows.0.longest_streak', 1)
                ->where('rows.1.longest_streak', 1));
    }

    #[Test]
    public function an_injury_spell_runs_across_group_and_whole_category_sessions(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1] = $this->splitCategory();
        $hurt = $this->player($minime);
        $swim->players()->attach($hurt->id, ['membership_id' => $hurt->membership_id]);
        $saturday = TrainingSession::where('group_id', $g1->id)->where('date', '2026-11-07')->sole();
        $this->branchMark($saturday, $hurt, AttendanceStatus::NotTraining, 'injury');
        $tuesday = $this->branchSession($swim, $minime, '2026-11-10', ['start_time' => '17:00', 'end_time' => '18:30']);
        $this->branchMark($tuesday, $hurt, AttendanceStatus::AbsentExcused, 'injury');

        // The injuries page has no group filter: a group_id in its URL changes nothing.
        $this->actingAs($this->admin())
            ->get(route('attendance.injuries', ['branch_id' => $swim->id, 'category_id' => $minime->id, 'group_id' => $g1->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Injuries')
                ->has('current', 1)
                ->where('current.0.player_id', $hurt->id)
                ->where('current.0.start', '2026-11-07')
                ->where('current.0.end', '2026-11-10')
                ->where('current.0.sessions', 2)
                ->where('current.0.open', true)
                ->missing('groups')
                ->missing('groupId'));
    }

    #[Test]
    public function the_profile_card_counts_both_groups_of_a_moved_player(): void
    {
        ['swim' => $swim, 'g1' => $g1, 'mover' => $mover] = $this->splitCategory();

        $card = $this->actingAs($this->admin())
            ->getJson(route('attendance.players.show', ['player' => $mover->id, 'branch_id' => $swim->id, 'group_id' => $g1->id]))
            ->assertOk()
            ->assertJsonPath('summary.expected', 12)
            ->assertJsonPath('summary.score_pct', 50)
            ->assertJsonPath('summary.missed_minutes', 270)
            ->assertJsonPath('risk.low_score', true)
            ->assertJsonPath('risk.streak', false)
            ->assertJsonPath('risk.longest_streak', 1);

        $this->assertCount(12, $card->json('sessions'));
    }

    #[Test]
    public function the_player_pdf_lists_the_sessions_of_both_groups(): void
    {
        ['swim' => $swim, 'g1' => $g1, 'mover' => $mover] = $this->splitCategory();
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())
            ->get(route('attendance.players.report', ['player' => $mover->id, 'branch_id' => $swim->id, 'group_id' => $g1->id]))
            ->assertOk();

        $this->assertStringEndsWith("-2026-09-01-2027-08-31-b{$swim->id}.pdf", $seen['filename']);
        $this->assertStringContainsString('<td class="num">12</td>', $seen['html']);   // expected
        foreach (['2026-09-05', '2026-09-08', '2026-11-07'] as $date) {               // G1, whole category, G2
            $this->assertStringContainsString($date, $seen['html']);
        }
    }

    #[Test]
    public function the_parent_letter_lists_the_absences_of_every_session(): void
    {
        ['swim' => $swim, 'g2' => $g2, 'mover' => $mover] = $this->splitCategory();
        $seen = $this->spyPdf();

        // Asked "for G2", where the player missed nothing: the letter still lists the three absences with G1.
        $this->actingAs($this->admin())
            ->get(route('attendance.players.letter', ['player' => $mover->id, 'branch_id' => $swim->id, 'group_id' => $g2->id]))
            ->assertOk();

        $this->assertStringEndsWith("-2026-09-01-2027-08-31-b{$swim->id}.pdf", $seen['filename']);
        foreach (['05/09/2026', '12/09/2026', '19/09/2026'] as $day) {
            $this->assertStringContainsString($day, $seen['html']);
        }
    }

    #[Test]
    public function the_dashboard_card_counts_every_group(): void
    {
        ['b' => $b, 'mover' => $mover] = $this->splitCategory();

        $card = app(AttendanceCard::class)->get();

        // The last 30 days hold three Saturdays: one G1 mark and two G2 marks each.
        $this->assertSame(9, $card['last30']['expected']);
        $this->assertSame(9, $card['last30']['counts']['present']);
        $this->assertSame(2, $card['risk']['count']);
        $this->assertEqualsCanonicalizing([$b->id, $mover->id], array_column($card['risk']['worst'], 'player_id'));
        $this->assertSame([1, 1], array_column($card['risk']['worst'], 'longest_streak'));
    }

    #[Test]
    public function the_literary_report_counts_every_group(): void
    {
        $this->splitCategory();

        $data = app(AttendanceSection::class)->build(CarbonImmutable::parse(self::FROM), CarbonImmutable::parse(self::TO));

        // 30 of the 36 marks are present: 83.3 %.
        $this->assertSame(22, $data['held']);
        $this->assertSame([['name' => 'Swimming', 'held' => 22, 'rate' => 83.3]], $data['branches']);
        $this->assertSame([['name' => 'Minime', 'held' => 22, 'expected' => 36, 'rate' => 83.3]], $data['categories']);
    }
}
```

- [ ] **Step 3: Run the pins**

Run: `php artisan test --filter=AttendanceGroupReportsUnchangedTest`
Expected: PASS (8 tests). Nothing was changed yet: these are today's numbers. If one fails, the fixture or the assertion is wrong, not the application: fix the test (the table under **Interfaces** gives every number), never production code.

- [ ] **Step 4: Commit**

```bash
git add tests/Support/GroupAttendanceFixtures.php tests/Feature/AttendanceGroupReportsUnchangedTest.php
git commit -m "test(attendance): pin the report numbers the group filter must not change"
```

---

### Task 2: `GroupScope` and the group in `AttendanceStats`

**Files:**
- Create: `app/Services/Attendance/GroupScope.php`
- Modify: `app/Services/Attendance/AttendanceStats.php` (class comment, `players()`, `monthly()`, `sessionsByMonth()`, `unexcusedStreaks()`, `marks()`)
- Test: `tests/Feature/AttendanceGroupStatsTest.php`

**Interfaces:**
- Consumes: `GroupAttendanceFixtures::splitCategory()` (Task 1); `BranchScope::apply(Builder $query, ?int $branchId, string $column = 'training_sessions.branch_id'): Builder` (existing).
- Produces:
  - `GroupScope::apply(\Illuminate\Database\Query\Builder $query, ?int $groupId): Builder` — null adds nothing; an id adds `+training_sessions.group_id = ?`. The query must already read `training_sessions` under that name.
  - `AttendanceStats::players(string $from, string $to, ?int $categoryId = null, ?int $playerId = null, ?int $branchId = null, ?int $groupId = null): array`
  - `AttendanceStats::monthly(string $from, string $to, ?int $categoryId = null, ?int $playerId = null, ?int $branchId = null, ?int $groupId = null): array`
  - `AttendanceStats::sessionsByMonth(string $from, string $to, ?int $categoryId = null, ?int $branchId = null, ?int $groupId = null): array`
  - `AttendanceStats::unexcusedStreaks(string $from, string $to, ?int $playerId = null, ?int $branchId = null, ?int $groupId = null): array`
  - Return shapes are unchanged. `categories()` and `playerSessions()` are not touched.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttendanceGroupStatsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\GroupScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\Support\GroupAttendanceFixtures;
use Tests\TestCase;

class AttendanceGroupStatsTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, GroupAttendanceFixtures, RefreshDatabase;

    private const FROM = '2026-09-01';

    private const TO = '2026-11-30';

    private function stats(): AttendanceStats
    {
        return app(AttendanceStats::class);
    }

    #[Test]
    public function a_group_counts_only_the_marks_of_its_own_sessions(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1, 'g2' => $g2, 'a' => $a, 'b' => $b, 'mover' => $mover] = $this->splitCategory();

        $first = $this->stats()->players(self::FROM, self::TO, $minime->id, null, $swim->id, $g1->id);
        $second = $this->stats()->players(self::FROM, self::TO, $minime->id, null, $swim->id, $g2->id);

        $this->assertSame([$a->id, $mover->id], array_keys($first));
        $this->assertSame(10, $first[$a->id]['expected']);
        $this->assertSame(100.0, $first[$a->id]['score_pct']);
        $this->assertSame(5, $first[$mover->id]['expected']);
        $this->assertSame(3, $first[$mover->id]['counts']['absent_unexcused']);
        $this->assertSame(2, $first[$mover->id]['counts']['present']);
        $this->assertSame(-1.0, $first[$mover->id]['score']);
        $this->assertSame(0.0, $first[$mover->id]['score_pct']);
        $this->assertSame(270, $first[$mover->id]['missed_minutes']);   // 3 × 90 min

        $this->assertSame([$b->id, $mover->id], array_keys($second));
        $this->assertSame(10, $second[$b->id]['expected']);
        $this->assertSame(40.0, $second[$b->id]['score_pct']);
        $this->assertSame(360, $second[$b->id]['missed_minutes']);      // 3 × 120 min
        $this->assertSame(5, $second[$mover->id]['expected']);
        $this->assertSame(100.0, $second[$mover->id]['score_pct']);
        $this->assertSame(0, $second[$mover->id]['missed_minutes']);
    }

    #[Test]
    public function without_a_group_the_numbers_are_the_whole_categorys(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1, 'g2' => $g2, 'b' => $b, 'mover' => $mover] = $this->splitCategory();
        $stats = $this->stats();

        $all = $stats->players(self::FROM, self::TO, $minime->id, null, $swim->id);

        $this->assertSame($all, $stats->players(self::FROM, self::TO, $minime->id, null, $swim->id, null));
        $this->assertSame(12, $all[$mover->id]['expected']);
        $this->assertSame(50.0, $all[$mover->id]['score_pct']);
        $this->assertSame(270, $all[$mover->id]['missed_minutes']);
        $this->assertSame(12, $all[$b->id]['expected']);
        $this->assertSame(360, $all[$b->id]['missed_minutes']);

        // A player who moved has the marks until the move in the old group and the rest in the new one: none is lost.
        $old = $stats->players(self::FROM, self::TO, $minime->id, $mover->id, $swim->id, $g1->id)[$mover->id];
        $new = $stats->players(self::FROM, self::TO, $minime->id, $mover->id, $swim->id, $g2->id)[$mover->id];
        $this->assertSame(12, $old['expected'] + $new['expected'] + 2);   // + the two whole-category Tuesdays
    }

    #[Test]
    public function months_sessions_and_streaks_follow_the_group(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1, 'g2' => $g2, 'mover' => $mover] = $this->splitCategory();
        $stats = $this->stats();

        $months = $stats->monthly(self::FROM, self::TO, $minime->id, null, $swim->id, $g1->id);
        $this->assertSame(['2026-09', '2026-10', '2026-11'], $months['labels']);
        $this->assertSame([3, 0, 0], $months['statuses']['absent_unexcused']);
        $this->assertSame([5, 6, 1], $months['statuses']['present']);

        $first = $stats->sessionsByMonth(self::FROM, self::TO, $minime->id, $swim->id, $g1->id);
        $this->assertSame([4, 5, 1], $first['held']);
        $this->assertSame([0, 0, 0], $first['cancelled']);
        $second = $stats->sessionsByMonth(self::FROM, self::TO, $minime->id, $swim->id, $g2->id);
        $this->assertSame([4, 5, 1], $second['held']);
        $this->assertSame([0, 0, 1], $second['cancelled']);
        $this->assertSame([10, 10, 2], $stats->sessionsByMonth(self::FROM, self::TO, $minime->id, $swim->id)['held']);

        $this->assertSame(
            ['current' => 0, 'longest' => 3, 'last_date' => '2026-10-03'],
            $stats->unexcusedStreaks(self::FROM, self::TO, $mover->id, $swim->id, $g1->id)[$mover->id],
        );
        $this->assertSame(
            ['current' => 0, 'longest' => 0, 'last_date' => '2026-11-07'],
            $stats->unexcusedStreaks(self::FROM, self::TO, $mover->id, $swim->id, $g2->id)[$mover->id],
        );
        // Read together, each whole-category Tuesday breaks the streak.
        $this->assertSame(1, $stats->unexcusedStreaks(self::FROM, self::TO, $mover->id, $swim->id)[$mover->id]['longest']);
    }

    #[Test]
    public function a_guest_counts_in_the_group_of_the_session(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1, 'g2' => $g2, 'b' => $b] = $this->splitCategory();
        // b, of G2, makes up a session with G1 (added by hand on the session page).
        $withFirst = TrainingSession::where('group_id', $g1->id)->where('date', '2026-11-07')->sole();
        $this->branchMark($withFirst, $b, AttendanceStatus::Present);

        $this->assertSame(1, $this->stats()->players(self::FROM, self::TO, $minime->id, $b->id, $swim->id, $g1->id)[$b->id]['expected']);
        $this->assertSame(10, $this->stats()->players(self::FROM, self::TO, $minime->id, $b->id, $swim->id, $g2->id)[$b->id]['expected']);
        $this->assertSame(13, $this->stats()->players(self::FROM, self::TO, $minime->id, $b->id, $swim->id)[$b->id]['expected']);
    }

    #[Test]
    public function the_group_adds_no_query(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1] = $this->splitCategory();
        $count = function (?int $groupId) use ($swim, $minime): int {
            $stats = $this->stats();
            $stats->players(self::FROM, self::TO);   // loads the settings and the custom codes once
            DB::flushQueryLog();
            DB::enableQueryLog();
            $stats->players(self::FROM, self::TO, $minime->id, null, $swim->id, $groupId);
            $stats->monthly(self::FROM, self::TO, $minime->id, null, $swim->id, $groupId);
            $stats->sessionsByMonth(self::FROM, self::TO, $minime->id, $swim->id, $groupId);
            $stats->unexcusedStreaks(self::FROM, self::TO, null, $swim->id, $groupId);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        Once::flush();
        $without = $count(null);
        Once::flush();

        $this->assertSame($without, $count($g1->id));
    }

    #[Test]
    public function the_group_condition_never_changes_the_query_plan(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('EXPLAIN QUERY PLAN is sqlite\'s; the unary plus is a no-op on the other databases.');
        }

        $marks = fn () => DB::table('attendances')
            ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
            ->where('training_sessions.state', SessionState::Held->value)
            ->whereBetween('training_sessions.date', [self::FROM, self::TO]);
        $plan = fn ($query) => collect(DB::select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings()))->pluck('detail')->implode("\n");
        $scoped = GroupScope::apply($marks(), 1);

        $this->assertStringContainsString('+training_sessions.group_id = ?', $scoped->toSql());
        $this->assertStringNotContainsString('training_sessions_group_id_index', $plan($scoped));
        // The plan of "all groups": a group can only make a report cheaper, never dearer.
        $this->assertSame($plan($marks()), $plan($scoped));
        $this->assertSame($marks()->toSql(), GroupScope::apply($marks(), null)->toSql());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AttendanceGroupStatsTest`
Expected: FAIL — `Class "App\Services\Attendance\GroupScope" not found` (last test) and wrong numbers in the others: PHP ignores the extra argument, so a "group" still returns the whole category (for example `Failed asserting that two arrays are identical` on the first test's `array_keys`).

- [ ] **Step 3: Write `GroupScope`**

Create `app/Services/Attendance/GroupScope.php`:

```php
<?php

namespace App\Services\Attendance;

use Illuminate\Database\Query\Builder;

/**
 * The one training-group condition of the attendance reports (statistics,
 * ranking and certificates, players at risk). A mark belongs to its session's
 * group (`training_sessions.group_id`), whatever group the player is in
 * today: a player who changed group mid-season has the marks until the move
 * in the old group and the rest in the new one. Null = no condition: every
 * session of the category, the ones with no group included, which is what
 * the reports have always counted.
 *
 * Written `+column = ?` for the reason BranchScope gives: `group_id` is
 * indexed, and without sqlite_stat1 a plain `group_id = ?` would make sqlite
 * lead by the group's whole history instead of the period through the date
 * index. With the unary plus the plan is the plan of "all groups" plus one
 * filter, so a group never costs more than the report without it. It is a
 * no-op on MySQL.
 */
final class GroupScope
{
    /** $query must read `training_sessions` under its own name. */
    public static function apply(Builder $query, ?int $groupId): Builder
    {
        return $groupId === null ? $query : $query->whereRaw('+training_sessions.group_id = ?', [$groupId]);
    }
}
```

- [ ] **Step 4: Add the group to `AttendanceStats`**

In `app/Services/Attendance/AttendanceStats.php`:

In the class comment, insert these nine lines right before the line ` * Category attribution: a single-category session keeps every mark in it,` (that is, after the empty ` *` line that follows `club without branches). The branch adds a condition, never a query.`):

```php
 * Per training group: players(), monthly(), sessionsByMonth() and
 * unexcusedStreaks() take a trailing ?int $groupId (the group filter of the
 * statistics, ranking and at-risk pages). A mark belongs to its session's
 * group (GroupScope), whatever group the player is in today. Null = no
 * group condition: every session, as before groups existed. The group adds
 * a condition, never a query. categories() and playerSessions() take none:
 * the table of categories, the profile card, the player's PDF and the
 * parent letter always cover every session.
 *
```

(The block ends with its own empty ` *` line, so the two paragraphs stay apart as elsewhere in the comment.)

Replace:

```php
    public function players(string $from, string $to, ?int $categoryId = null, ?int $playerId = null, ?int $branchId = null): array
```

with:

```php
    public function players(string $from, string $to, ?int $categoryId = null, ?int $playerId = null, ?int $branchId = null, ?int $groupId = null): array
```

Replace:

```php
    public function monthly(string $from, string $to, ?int $categoryId = null, ?int $playerId = null, ?int $branchId = null): array
```

with:

```php
    public function monthly(string $from, string $to, ?int $categoryId = null, ?int $playerId = null, ?int $branchId = null, ?int $groupId = null): array
```

The text `$this->marks($from, $to, $categoryId, $playerId, $branchId)` occurs exactly three times (twice in `players()`, once in `monthly()`). Replace all three with:

```php
$this->marks($from, $to, $categoryId, $playerId, $branchId, $groupId)
```

Replace:

```php
    public function sessionsByMonth(string $from, string $to, ?int $categoryId = null, ?int $branchId = null): array
```

with:

```php
    public function sessionsByMonth(string $from, string $to, ?int $categoryId = null, ?int $branchId = null, ?int $groupId = null): array
```

and, in that method only (the same `tap` line also exists in `categories()`, which must not change), replace:

```php
            ->tap(fn (Builder $q) => BranchScope::apply($q, $branchId))
            ->selectRaw('substr(training_sessions.date, 1, 7) as ym')
            ->addSelect('training_sessions.state')
```

with:

```php
            ->tap(fn (Builder $q) => GroupScope::apply(BranchScope::apply($q, $branchId), $groupId))
            ->selectRaw('substr(training_sessions.date, 1, 7) as ym')
            ->addSelect('training_sessions.state')
```

Replace:

```php
    public function unexcusedStreaks(string $from, string $to, ?int $playerId = null, ?int $branchId = null): array
    {
        $marks = $this->marks($from, $to, null, $playerId, $branchId)
            ->orderBy('attendances.player_id')
```

with:

```php
    public function unexcusedStreaks(string $from, string $to, ?int $playerId = null, ?int $branchId = null, ?int $groupId = null): array
    {
        $marks = $this->marks($from, $to, null, $playerId, $branchId, $groupId)
            ->orderBy('attendances.player_id')
```

(`playerSessions()` holds the same `$this->marks($from, $to, null, $playerId, $branchId)` call: leave it as it is.)

Replace:

```php
    /** Marks in held sessions of the period, optionally one player's, one category's and/or one branch's. */
    private function marks(string $from, string $to, ?int $categoryId, ?int $playerId, ?int $branchId = null): Builder
    {
        return BranchScope::apply(DB::table('attendances')
```

with:

```php
    /** Marks in held sessions of the period, optionally one player's, one category's, one branch's and/or one training group's. */
    private function marks(string $from, string $to, ?int $categoryId, ?int $playerId, ?int $branchId = null, ?int $groupId = null): Builder
    {
        return GroupScope::apply(BranchScope::apply(DB::table('attendances')
```

and, at the end of the same method, replace:

```php
                $categoryId,
            )), $branchId);
    }
```

with:

```php
                $categoryId,
            )), $branchId), $groupId);
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=AttendanceGroupStatsTest`
Expected: PASS (6 tests).

Run: `php artisan test --filter=AttendanceGroupReportsUnchangedTest`
Expected: PASS (8 tests): nothing moved for the callers that pass no group.

Run: `php artisan test --filter=AttendanceBranchStatsTest`
Expected: PASS (its query-count and query-plan tests still hold).

Run: `php artisan test --filter=AttendanceStatsTest`
Expected: PASS.

Run: `php artisan test --filter=AttendanceStreaksTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/GroupScope.php app/Services/Attendance/AttendanceStats.php tests/Feature/AttendanceGroupStatsTest.php
git commit -m "feat(attendance): attendance numbers can be read for one training group"
```

---

### Task 3: Group select on the statistics page and its exports

**Files:**
- Create: `app/Services/Attendance/GroupChoice.php`
- Modify: `app/Http/Controllers/AttendanceStatsController.php` (class comment, `export()`, `deltas()`, `data()`)
- Modify: `resources/js/Pages/Attendance/Stats.vue`
- Modify: `resources/js/i18n/ar.json`, `resources/js/i18n/fr.json`, `resources/js/i18n/en.json` (1 key)
- Test: `tests/Feature/AttendanceGroupStatsPageTest.php`

**Interfaces:**
- Consumes: `AttendanceStats::players/monthly/sessionsByMonth(…, ?int $groupId)` (Task 2); `TrainingGroup::options(?int $categoryId = null): Collection` of `['id' => int, 'branch_id' => ?int, 'category_id' => int, 'name' => string]`, by name then id (phase 2, Task 1); `GroupAttendanceFixtures::splitCategory()` (Task 1).
- Produces:
  - `GroupChoice::fromRequest(Request $request, ?int $categoryId, ?int $branchId): array` — `['options' => list<['id' => int, 'name' => string]>, 'selected' => ?int]`. Validates `group_id` as `nullable|integer`. `selected` is the asked id when it is one of the options, else null.
  - `GroupChoice::options(?int $categoryId, ?int $branchId): array` — the options alone; `[]` (and no query) without a category.
  - Query parameter `group_id` on `attendance.stats` and `attendance.stats.export`; Inertia props `groups` and `groupId` on `Attendance/Stats`.
  - i18n key `att.all_groups` (Tasks 4 and 5 use it too).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttendanceGroupStatsPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\TrainingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\Support\GroupAttendanceFixtures;
use Tests\TestCase;

class AttendanceGroupStatsPageTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, GroupAttendanceFixtures, RefreshDatabase;

    private const PERIOD = ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-11-30'];

    /** Swimming · Minime over the fixture's period, plus $extra (which wins). */
    private function filters(array $club, array $extra = []): array
    {
        return $extra + self::PERIOD + ['branch_id' => $club['swim']->id, 'category_id' => $club['minime']->id];
    }

    #[Test]
    public function the_page_follows_the_chosen_group(): void
    {
        $club = $this->splitCategory();
        ['minime' => $minime, 'g1' => $g1, 'g2' => $g2, 'a' => $a, 'b' => $b, 'mover' => $mover] = $club;
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.stats', $this->filters($club, ['group_id' => $g1->id])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Stats')
                ->where('categoryId', $minime->id)
                ->where('groupId', $g1->id)
                ->where('groups', [['id' => $g1->id, 'name' => 'G1'], ['id' => $g2->id, 'name' => 'G2']])
                ->where('totals.held', 10)
                ->where('totals.cancelled', 0)
                ->where('totals.expected', 15)
                ->where('totals.players', 2)
                ->where('totals.score_pct', 60)
                ->where('totals.missed_hours', 4.5)
                ->where('sessionsByMonth.held', [4, 5, 1])
                ->where('monthly.statuses.absent_unexcused', [3, 0, 0])
                ->where('players', fn ($rows) => collect($rows)->pluck('player_id')->sort()->values()->all() === [$a->id, $mover->id])
                ->where('players', fn ($rows) => collect($rows)->firstWhere('player_id', $mover->id)['score_pct'] == 0)
                ->where('ranking.top', fn ($rows) => collect($rows)->pluck('player_id')->all() === [$a->id, $mover->id])
                // The table of categories compares whole categories: it never follows the group.
                ->where('categoryRows.0.held', 22)
                ->where('categoryRows.0.expected', 36));

        $this->actingAs($admin)->get(route('attendance.stats', $this->filters($club, ['group_id' => $g2->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('groupId', $g2->id)
                ->where('totals.held', 10)
                ->where('totals.cancelled', 1)
                ->where('totals.expected', 15)
                ->where('totals.score_pct', 60)
                ->where('totals.missed_hours', 6)
                ->where('players', fn ($rows) => collect($rows)->pluck('player_id')->sort()->values()->all() === [$b->id, $mover->id])
                ->where('players', fn ($rows) => collect($rows)->firstWhere('player_id', $mover->id)['score_pct'] == 100));
    }

    #[Test]
    public function all_groups_is_the_default_and_what_an_unknown_group_falls_back_to(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'g1' => $g1, 'g2' => $g2] = $club;
        $cadet = Category::create(['name' => 'Cadet', 'branch_id' => $swim->id]);
        $foreign = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $cadet->id, 'name' => 'C1']);
        $admin = $this->admin();

        // No value, an id that does not exist, 0 (the month grid's «whole category»), another category's group.
        foreach ([[], ['group_id' => 999999], ['group_id' => 0], ['group_id' => $foreign->id]] as $extra) {
            $this->actingAs($admin)->get(route('attendance.stats', $this->filters($club, $extra)))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('groupId', null)
                    ->where('groups', [['id' => $g1->id, 'name' => 'G1'], ['id' => $g2->id, 'name' => 'G2']])
                    ->where('totals.held', 22)
                    ->where('totals.expected', 36)
                    ->where('totals.score_pct', 66.7));
        }
    }

    #[Test]
    public function the_group_field_needs_a_category_that_has_groups(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'g1' => $g1] = $club;
        $benjamin = Category::create(['name' => 'Benjamin', 'branch_id' => $swim->id]);
        $admin = $this->admin();

        // All categories: no field, and a group in the URL is ignored.
        $this->actingAs($admin)->get(route('attendance.stats', self::PERIOD + ['branch_id' => $swim->id, 'group_id' => $g1->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('categoryId', null)
                ->where('groups', [])
                ->where('groupId', null)
                ->where('totals.expected', 36));

        // A category without groups: no field either.
        $this->actingAs($admin)->get(route('attendance.stats', $this->filters($club, ['category_id' => $benjamin->id, 'group_id' => $g1->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('categoryId', $benjamin->id)
                ->where('groups', [])
                ->where('groupId', null)
                ->where('totals.expected', 0));
    }

    #[Test]
    public function a_group_that_is_not_a_number_is_refused(): void
    {
        $club = $this->splitCategory();

        $this->actingAs($this->admin())->get(route('attendance.stats', $this->filters($club, ['group_id' => 'abc'])))
            ->assertSessionHasErrors('group_id');
    }

    #[Test]
    public function the_comparison_with_the_period_before_follows_the_group(): void
    {
        $club = $this->splitCategory();
        ['g1' => $g1] = $club;
        $october = ['from' => '2026-10-01', 'to' => '2026-10-31'];   // the 31 days before: 2026-08-31 → 09-30
        $admin = $this->admin();

        // G1: 5 sessions in October against 4 in September.
        $this->actingAs($admin)->get(route('attendance.stats', $this->filters($club, $october + ['group_id' => $g1->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals.held', 5)
                ->where('deltas.held.raw', 1)
                ->where('deltas.held.percent', 25));

        // The whole category: 10 in October against 10 in September (4 + 4 + the two Tuesdays).
        $this->actingAs($admin)->get(route('attendance.stats', $this->filters($club, $october)))
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals.held', 10)
                ->where('deltas.held.raw', 0));
    }

    #[Test]
    public function the_spreadsheet_is_one_groups(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1, 'a' => $a, 'b' => $b, 'mover' => $mover] = $club;

        $response = $this->actingAs($this->admin())
            ->get(route('attendance.stats.export', $this->filters($club, ['group_id' => $g1->id, 'format' => 'csv'])))
            ->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString(
            "attendance-stats-2026-09-01-2026-11-30-b{$swim->id}-{$minime->id}-g{$g1->id}.csv",
            (string) $response->headers->get('content-disposition'),
        );
        $this->assertStringContainsString(' — Swimming — Minime — G1', $csv);
        $this->assertStringContainsString($a->fullname, $csv);
        $this->assertStringContainsString($mover->fullname, $csv);
        $this->assertStringNotContainsString($b->fullname, $csv);
    }

    #[Test]
    public function the_pdf_names_the_group_after_the_category(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'minime' => $minime, 'g2' => $g2, 'a' => $a, 'b' => $b] = $club;
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())
            ->get(route('attendance.stats.export', $this->filters($club, ['group_id' => $g2->id, 'format' => 'pdf'])))
            ->assertOk();

        $this->assertSame("attendance-stats-2026-09-01-2026-11-30-b{$swim->id}-{$minime->id}-g{$g2->id}.pdf", $seen['filename']);
        $this->assertStringContainsString('Minime · G2', $seen['html']);
        $this->assertStringContainsString($b->fullname, $seen['html']);
        $this->assertStringNotContainsString($a->fullname, $seen['html']);
    }

    #[Test]
    public function without_a_group_the_export_is_named_as_before(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'minime' => $minime, 'b' => $b] = $club;

        $response = $this->actingAs($this->admin())
            ->get(route('attendance.stats.export', $this->filters($club, ['format' => 'csv'])))
            ->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString(
            "attendance-stats-2026-09-01-2026-11-30-b{$swim->id}-{$minime->id}.csv",
            (string) $response->headers->get('content-disposition'),
        );
        $this->assertStringContainsString(' — Swimming — Minime', $csv);
        $this->assertStringNotContainsString(' — Minime — ', $csv);
        $this->assertStringContainsString($b->fullname, $csv);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AttendanceGroupStatsPageTest`
Expected: FAIL — the page has no `groupId` / `groups` props (`Failed asserting that the property [groupId] exists` or similar), and the export names carry no group. Only the last test may already pass.

- [ ] **Step 3: Write `GroupChoice`**

Create `app/Services/Attendance/GroupChoice.php`:

```php
<?php

namespace App\Services\Attendance;

use App\Models\TrainingGroup;
use Illuminate\Http\Request;

/**
 * The training group a report page is narrowed to (statistics, ranking and
 * certificates, players at risk, and their exports): `group_id` in the query,
 * when it is one of the groups of the page's branch + category. Anything
 * else — no value, an unknown id, a group of another category or branch, a
 * category without groups, no category — means all groups: the numbers the
 * report had before groups existed.
 *
 * Unlike the month grid's switch (MonthSheet::groupSwitch()) there is no
 * entry for the sessions with no group: "all groups" already counts them.
 */
final class GroupChoice
{
    /**
     * A `group_id` that is not an integer fails validation; an integer that is
     * not one of the options is ignored.
     *
     * @return array{options: list<array{id: int, name: string}>, selected: ?int}
     */
    public static function fromRequest(Request $request, ?int $categoryId, ?int $branchId): array
    {
        $data = $request->validate(['group_id' => ['nullable', 'integer']]);
        $options = self::options($categoryId, $branchId);
        $asked = isset($data['group_id']) ? (int) $data['group_id'] : null;

        return [
            'options' => $options,
            'selected' => in_array($asked, array_column($options, 'id'), true) ? $asked : null,
        ];
    }

    /**
     * The groups of one branch + category (a null branch = the club-wide
     * groups), by name. No category: none, and no query.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function options(?int $categoryId, ?int $branchId): array
    {
        if ($categoryId === null) {
            return [];
        }

        return TrainingGroup::options($categoryId)
            ->filter(fn (array $group) => $group['branch_id'] === $branchId)
            ->map(fn (array $group) => ['id' => $group['id'], 'name' => $group['name']])
            ->values()
            ->all();
    }
}
```

- [ ] **Step 4: Use it in the statistics controller**

In `app/Http/Controllers/AttendanceStatsController.php`:

Add the import (after `use App\Services\Attendance\BranchChoice;`):

```php
use App\Services\Attendance\GroupChoice;
```

In the class comment, replace:

```php
 * one branch (BranchChoice, shared with the calendar). Every number comes from
 * AttendanceStats.
```

with:

```php
 * one branch (BranchChoice, shared with the calendar). A category split into
 * training groups can be narrowed to one of them (GroupChoice): the numbers
 * then come from that group's sessions only, except the table of categories,
 * which always compares whole categories. Every number comes from
 * AttendanceStats.
```

In `export()`, replace:

```php
        $branchName = $data['branchId'] !== null ? collect($data['branches'])->firstWhere('id', $data['branchId'])['name'] : null;
        $filename = 'attendance-stats-'.$from.'-'.$to
            .($data['branchId'] !== null ? '-b'.$data['branchId'] : '')
            .($data['categoryId'] !== null ? '-'.$data['categoryId'] : '');
```

with:

```php
        $branchName = $data['branchId'] !== null ? collect($data['branches'])->firstWhere('id', $data['branchId'])['name'] : null;
        // A chosen training group follows the category wherever the report is named.
        $groupName = $data['groupId'] !== null ? collect($data['groups'])->firstWhere('id', $data['groupId'])['name'] : null;
        $filename = 'attendance-stats-'.$from.'-'.$to
            .($data['branchId'] !== null ? '-b'.$data['branchId'] : '')
            .($data['categoryId'] !== null ? '-'.$data['categoryId'] : '')
            .($data['groupId'] !== null ? '-g'.$data['groupId'] : '');
```

Replace (the PDF's view data):

```php
                'categoryName' => $categoryName,
                'branchName' => $branchName,
```

with:

```php
                'categoryName' => $groupName === null ? $categoryName : $categoryName.' · '.$groupName,
                'branchName' => $branchName,
```

Replace (the spreadsheet's title):

```php
            implode(' — ', array_filter([UiLang::get('att.stats_title'), $data['period']['label'], $branchName, $categoryName])));
```

with:

```php
            implode(' — ', array_filter([UiLang::get('att.stats_title'), $data['period']['label'], $branchName, $categoryName, $groupName])));
```

In the comment of `deltas()`, replace `window before (same length, same category and branch). Counts only, no` with `window before (same length, same category, branch and group). Counts only, no`, and in its body replace:

```php
        $totals = $this->stats->summarize($this->stats->players($from, $to, $data['categoryId'], null, $data['branchId']));
        $held = array_sum($this->stats->sessionsByMonth($from, $to, $data['categoryId'], $data['branchId'])['held']);
```

with:

```php
        $totals = $this->stats->summarize($this->stats->players($from, $to, $data['categoryId'], null, $data['branchId'], $data['groupId']));
        $held = array_sum($this->stats->sessionsByMonth($from, $to, $data['categoryId'], $data['branchId'], $data['groupId'])['held']);
```

In `data()`, replace:

```php
        $period = ActivityPeriod::fromRequest($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        $rows = $this->stats->players($from, $to, $categoryId, null, $branchId);
        $players = $this->withNames($rows, $branchId);
        $sessions = $this->stats->sessionsByMonth($from, $to, $categoryId, $branchId);
```

with:

```php
        // The category's training groups in this branch, and the one asked for (null = all groups).
        ['options' => $groups, 'selected' => $groupId] = GroupChoice::fromRequest($request, $categoryId, $branchId);
        $period = ActivityPeriod::fromRequest($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        $rows = $this->stats->players($from, $to, $categoryId, null, $branchId, $groupId);
        $players = $this->withNames($rows, $branchId);
        $sessions = $this->stats->sessionsByMonth($from, $to, $categoryId, $branchId, $groupId);
```

Replace:

```php
            'branches' => BranchChoice::options(),
            'branchId' => $branchId,
            'totals' => [
```

with:

```php
            'branches' => BranchChoice::options(),
            'branchId' => $branchId,
            // The Group select ([] = none to offer) and the group shown (null = all groups).
            'groups' => $groups,
            'groupId' => $groupId,
            'totals' => [
```

Replace:

```php
            'monthly' => $this->stats->monthly($from, $to, $categoryId, null, $branchId),
            'sessionsByMonth' => $sessions,
            'categoryRows' => $this->stats->categories($from, $to, $branchId),
```

with:

```php
            'monthly' => $this->stats->monthly($from, $to, $categoryId, null, $branchId, $groupId),
            'sessionsByMonth' => $sessions,
            // Whole categories, whatever the category or the group chosen above.
            'categoryRows' => $this->stats->categories($from, $to, $branchId),
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=AttendanceGroupStatsPageTest`
Expected: PASS (8 tests).

Run: `php artisan test --filter=AttendanceBranchStatsPageTest`
Expected: PASS.

Run: `php artisan test --filter=AttendanceStatsExportTest`
Expected: PASS.

Run: `php artisan test --filter=AttendanceStatsPageTest`
Expected: PASS.

Run: `php artisan test --filter=AttendanceGroupReportsUnchangedTest`
Expected: PASS.

- [ ] **Step 6: Add the i18n key**

Insert at its alphabetical place in the three files: right after the `"att.all_categories"` line, before `"att.all_kinds"`.

`resources/js/i18n/ar.json`:

```json
    "att.all_groups": "كل الأفواج",
```

`resources/js/i18n/fr.json`:

```json
    "att.all_groups": "Tous les groupes",
```

`resources/js/i18n/en.json`:

```json
    "att.all_groups": "All groups",
```

- [ ] **Step 7: The Group select on the page**

In `resources/js/Pages/Attendance/Stats.vue`:

In the comment above `defineProps`, replace:

```js
 * Club-wide attendance statistics for a period (URL: period, from, to) and an
 * optional category (category_id). Every number is computed by
```

with:

```js
 * Club-wide attendance statistics for a period (URL: period, from, to), an
 * optional category (category_id) and, when that category is split into
 * training groups, an optional group (group_id). Every number is computed by
```

Replace:

```js
    branchId: { type: Number, default: null },
    totals: { type: Object, required: true },
```

with:

```js
    branchId: { type: Number, default: null },
    groups: { type: Array, default: () => [] }, // the chosen category's training groups in this branch: [{ id, name }]
    groupId: { type: Number, default: null }, // null = all groups
    totals: { type: Object, required: true },
```

Replace:

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

with:

```js
// The filters the period control and the export keep: category, training group and branch.
const branchQuery = computed(() => (props.branchId ? { branch_id: props.branchId } : {}));
const categoryQuery = computed(() => (props.categoryId ? { category_id: props.categoryId } : {}));
const keep = computed(() => ({ ...categoryQuery.value, ...(props.groupId ? { group_id: props.groupId } : {}), ...branchQuery.value }));
const exportHref = computed(() => route('attendance.stats.export', { ...periodQuery(props.period), ...keep.value }));
// A training group belongs to one branch + category: another category or branch starts on all groups.
function pickCategory(value) {
    router.get(route('attendance.stats'), { ...periodQuery(props.period), ...branchQuery.value, ...(value ? { category_id: Number(value) } : {}) }, { preserveScroll: true, replace: true });
}
function pickGroup(value) {
    router.get(route('attendance.stats'), { ...periodQuery(props.period), ...branchQuery.value, ...categoryQuery.value, ...(value ? { group_id: Number(value) } : {}) }, { preserveScroll: true, replace: true });
}
function switchBranch(id) {
    router.get(route('attendance.stats'), { ...periodQuery(props.period), ...categoryQuery.value, branch_id: id }, { preserveScroll: true, replace: true });
}
```

Replace:

```js
const activeCount = computed(() => (props.period.period !== 'month' ? 1 : 0) + (props.categoryId ? 1 : 0));
```

with:

```js
const activeCount = computed(() => (props.period.period !== 'month' ? 1 : 0) + (props.categoryId ? 1 : 0) + (props.groupId ? 1 : 0));
```

In the template, replace:

```vue
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </StatsToolbar>
```

with:

```vue
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <select v-if="groups.length" :value="groupId ?? ''" :class="input" :aria-label="t('att.group')" @change="pickGroup($event.target.value)">
                    <option value="">{{ t('att.all_groups') }}</option>
                    <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
                </select>
            </StatsToolbar>
```

(The header's links to the alerts, ranking and injuries pages stay exactly as they are: `AttendanceFollowupLinksTest` reads them, and they open those pages on their own defaults.)

- [ ] **Step 8: Build and run the checks**

Run: `npm run build`
Expected: builds with no error.

Run: `npm run i18n:check`
Expected: PASS.

Run: `php artisan test --filter=AttendanceFollowupLinksTest`
Expected: PASS.

Run: `php artisan test --filter=AttendanceGroupStatsPageTest`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Attendance/GroupChoice.php app/Http/Controllers/AttendanceStatsController.php resources/js/Pages/Attendance/Stats.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/AttendanceGroupStatsPageTest.php
git commit -m "feat(attendance): statistics and their export for one training group"
```

---

### Task 4: Group select on the ranking, certificates per group

**Files:**
- Modify: `app/Http/Controllers/AttendanceRankingController.php` (class comment, `index()`, `certificates()`, `ranking()`)
- Modify: `resources/js/Pages/Attendance/Ranking.vue`
- Test: `tests/Feature/AttendanceGroupRankingTest.php`

**Interfaces:**
- Consumes: `AttendanceStats::players(…, ?int $groupId)` (Task 2); `GroupChoice::fromRequest(Request $request, ?int $categoryId, ?int $branchId): array{options: list<array{id: int, name: string}>, selected: ?int}` and the i18n key `att.all_groups` (Task 3); `GroupAttendanceFixtures::splitCategory()` (Task 1).
- Produces: query parameter `group_id` on `attendance.ranking` and `attendance.certificates`; Inertia props `groups` and `groupId` on `Attendance/Ranking`; `data-help="ranking-group"` on the select (Task 6 rings it); certificate file names `certificates-{category}-b{branch}-g{group}-{period}.pdf` and `certificate-{membership id}-b{branch}-g{group}-{period}.pdf`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttendanceGroupRankingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\TrainingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\Support\GroupAttendanceFixtures;
use Tests\TestCase;

class AttendanceGroupRankingTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, GroupAttendanceFixtures, RefreshDatabase;

    /** Season 2026/27 of Swimming · Minime, plus $extra (which wins). */
    private function seasonOf(array $club, array $extra = []): array
    {
        return $extra + ['type' => 'season', 'season' => 2026, 'branch_id' => $club['swim']->id, 'category_id' => $club['minime']->id];
    }

    #[Test]
    public function each_group_ranks_the_marks_of_its_own_sessions(): void
    {
        $club = $this->splitCategory();
        ['g1' => $g1, 'g2' => $g2, 'a' => $a, 'b' => $b, 'mover' => $mover] = $club;
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.ranking', $this->seasonOf($club, ['group_id' => $g1->id])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Ranking')
                ->where('groupId', $g1->id)
                ->where('groups', [['id' => $g1->id, 'name' => 'G1'], ['id' => $g2->id, 'name' => 'G2']])
                ->where('rows', fn ($rows) => collect($rows)->pluck('player_id')->all() === [$a->id, $mover->id])
                ->where('rows.0.expected', 10)
                ->where('rows.0.score_pct', 100)
                ->where('rows.1.rank', 2)
                ->where('rows.1.expected', 5)
                ->where('rows.1.score_pct', 0)
                ->where('unranked', 0));

        // The player who moved is last with G1 and first with G2.
        $this->actingAs($admin)->get(route('attendance.ranking', $this->seasonOf($club, ['group_id' => $g2->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('groupId', $g2->id)
                ->where('rows', fn ($rows) => collect($rows)->pluck('player_id')->all() === [$mover->id, $b->id])
                ->where('rows.0.rank', 1)
                ->where('rows.0.score_pct', 100)
                ->where('rows.1.score_pct', 40));
    }

    #[Test]
    public function all_groups_is_the_default_and_what_an_unknown_group_falls_back_to(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'a' => $a, 'b' => $b, 'mover' => $mover] = $club;
        $cadet = Category::create(['name' => 'Cadet', 'branch_id' => $swim->id]);
        $foreign = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $cadet->id, 'name' => 'C1']);
        $benjamin = Category::create(['name' => 'Benjamin', 'branch_id' => $swim->id]);
        $admin = $this->admin();

        // No value, an id that does not exist, 0 (the month grid's «whole category»), another category's group.
        foreach ([[], ['group_id' => 999999], ['group_id' => 0], ['group_id' => $foreign->id]] as $extra) {
            $this->actingAs($admin)->get(route('attendance.ranking', $this->seasonOf($club, $extra)))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('groupId', null)
                    ->has('groups', 2)
                    ->where('rows', fn ($rows) => collect($rows)->pluck('player_id')->all() === [$a->id, $b->id, $mover->id])
                    ->where('rows.2.expected', 12)
                    ->where('rows.2.score_pct', 50));
        }

        // Another category shows its own groups, and starts on all of them.
        $this->actingAs($admin)->get(route('attendance.ranking', $this->seasonOf($club, ['category_id' => $cadet->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('groupId', null)
                ->where('groups', [['id' => $foreign->id, 'name' => 'C1']]));

        // A category without groups offers no field, whatever the URL says.
        $this->actingAs($admin)->get(route('attendance.ranking', $this->seasonOf($club, ['category_id' => $benjamin->id, 'group_id' => $foreign->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('groupId', null)
                ->where('groups', []));
    }

    #[Test]
    public function the_podium_certificates_name_the_group(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'minime' => $minime, 'g2' => $g2, 'a' => $a, 'b' => $b, 'mover' => $mover] = $club;
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.certificates', $this->seasonOf($club, ['group_id' => $g2->id])))->assertOk();

        $this->assertSame("certificates-{$minime->id}-b{$swim->id}-g{$g2->id}-season-2026.pdf", $seen['filename']);
        $this->assertStringContainsString('Swimming · Minime · G2', $seen['html']);
        $this->assertStringContainsString($mover->fullname, $seen['html']);
        $this->assertStringContainsString($b->fullname, $seen['html']);
        $this->assertStringNotContainsString($a->fullname, $seen['html']);
    }

    #[Test]
    public function one_players_certificate_carries_the_score_of_the_group(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'g2' => $g2, 'mover' => $mover] = $club;
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())
            ->get(route('attendance.certificates', $this->seasonOf($club, ['group_id' => $g2->id, 'player_id' => $mover->id])))
            ->assertOk();

        $this->assertSame("certificate-{$mover->membership_id}-b{$swim->id}-g{$g2->id}-season-2026.pdf", $seen['filename']);
        $this->assertStringContainsString('Swimming · Minime · G2', $seen['html']);
        $this->assertStringContainsString('100.0%', $seen['html']);       // with G2; 50.0% over the whole category
        $this->assertStringNotContainsString('50.0%', $seen['html']);
    }

    #[Test]
    public function no_group_certificate_without_a_mark_in_the_groups_sessions(): void
    {
        $club = $this->splitCategory();
        ['g1' => $g1, 'g2' => $g2, 'a' => $a, 'b' => $b] = $club;
        $admin = $this->admin();

        // a never trained with G2, b never with G1: both are in the category, neither gets that group's certificate.
        $this->actingAs($admin)->get(route('attendance.certificates', $this->seasonOf($club, ['group_id' => $g2->id, 'player_id' => $a->id])))
            ->assertNotFound();
        $this->actingAs($admin)->get(route('attendance.certificates', $this->seasonOf($club, ['group_id' => $g1->id, 'player_id' => $b->id])))
            ->assertNotFound();
    }

    #[Test]
    public function without_a_group_the_certificates_are_named_as_before(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'minime' => $minime] = $club;
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.certificates', $this->seasonOf($club)))->assertOk();

        $this->assertSame("certificates-{$minime->id}-b{$swim->id}-season-2026.pdf", $seen['filename']);
        $this->assertStringContainsString('Swimming · Minime', $seen['html']);
        $this->assertStringNotContainsString('Minime · G', $seen['html']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AttendanceGroupRankingTest`
Expected: FAIL — no `groupId` prop on the page, and the certificates carry no group. The last test already passes.

- [ ] **Step 3: The controller**

In `app/Http/Controllers/AttendanceRankingController.php`:

Add the import (after `use App\Services\Attendance\BranchChoice;`):

```php
use App\Services\Attendance\GroupChoice;
```

In the class comment, replace:

```php
 * attributed to the category, as the statistics page does when filtered on
 * it. Only reads (attendance/view, see config/permissions.php).
```

with:

```php
 * attributed to the category, as the statistics page does when filtered on
 * it. A category split into training groups can be ranked one group at a
 * time (GroupChoice): the pool, the minimum and the ranks then come from
 * that group's sessions only, and the certificates name the group.
 * Only reads (attendance/view, see config/permissions.php).
```

In `index()`, replace:

```php
        $period = $this->period($request);
        ['rows' => $rows, 'unranked' => $unranked] = $categoryId === null
            ? ['rows' => [], 'unranked' => 0]
            : $this->ranking($categoryId, $period, $branchId);
        $current = Season::current();
```

with:

```php
        // The category's training groups in this branch, and the one asked for (null = all groups).
        ['options' => $groups, 'selected' => $groupId] = GroupChoice::fromRequest($request, $categoryId, $branchId);
        $period = $this->period($request);
        ['rows' => $rows, 'unranked' => $unranked] = $categoryId === null
            ? ['rows' => [], 'unranked' => 0]
            : $this->ranking($categoryId, $period, $branchId, $groupId);
        $current = Season::current();
```

and replace:

```php
            'branches' => BranchChoice::options(),
            'branchId' => $branchId,
            'period' => $period,
```

with:

```php
            'branches' => BranchChoice::options(),
            'branchId' => $branchId,
            // The Group select ([] = none to offer) and the group ranked (null = all groups).
            'groups' => $groups,
            'groupId' => $groupId,
            'period' => $period,
```

In `certificates()`, replace:

```php
        $branch = $branchId === null ? null : Branch::find($branchId);
        $period = $this->period($request);
        ['rows' => $rows] = $this->ranking($category->id, $period, $branchId);
        $branchKey = $branchId === null ? '' : "-b{$branchId}";
```

with:

```php
        $branch = $branchId === null ? null : Branch::find($branchId);
        ['options' => $groups, 'selected' => $groupId] = GroupChoice::fromRequest($request, $category->id, $branchId);
        $groupName = $groupId === null ? null : collect($groups)->firstWhere('id', $groupId)['name'];
        $period = $this->period($request);
        ['rows' => $rows] = $this->ranking($category->id, $period, $branchId, $groupId);
        // "-b2", then "-g7" when the ranking was narrowed to a training group.
        $branchKey = ($branchId === null ? '' : "-b{$branchId}").($groupId === null ? '' : "-g{$groupId}");
```

Replace:

```php
                $own = $this->stats->players($period['from'], $period['to'], $category->id, $playerId, $branchId)[$playerId] ?? null;
                // Neither expected in the branch's category over the period nor in it now: no certificate "for" it.
                $inItNow = Player::whereKey($playerId)
                    ->registeredWhere(branches: $branchId, categories: [$category->id], active: true)
                    ->exists();
```

with:

```php
                $own = $this->stats->players($period['from'], $period['to'], $category->id, $playerId, $branchId, $groupId)[$playerId] ?? null;
                // Neither expected in the branch's category over the period nor in it now: no certificate "for" it.
                // A training group's certificate is about its sessions: without a mark in them, none either.
                $inItNow = $groupId === null && Player::whereKey($playerId)
                    ->registeredWhere(branches: $branchId, categories: [$category->id], active: true)
                    ->exists();
```

Replace:

```php
            // The certificate line's {category}: "Branch · Category" once the club has branches.
            'category' => $branch === null ? $category->localized_name : $branch->localized_name.' · '.$category->localized_name,
```

with:

```php
            // The certificate line's {category}: "Branch · Category" once the club has branches, then the training group.
            'category' => implode(' · ', array_filter([$branch?->localized_name, $category->localized_name, $groupName])),
```

Replace:

```php
    private function ranking(int $categoryId, array $period, ?int $branchId = null): array
    {
        $rows = $this->stats->players($period['from'], $period['to'], $categoryId, null, $branchId);
```

with:

```php
    private function ranking(int $categoryId, array $period, ?int $branchId = null, ?int $groupId = null): array
    {
        $rows = $this->stats->players($period['from'], $period['to'], $categoryId, null, $branchId, $groupId);
```

and, in the comment above that method, replace `The category's ranked players in the branch, with names, and how many active` with `The category's ranked players in the branch (in one training group, when given), with names, and how many active`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=AttendanceGroupRankingTest`
Expected: PASS (6 tests).

Run: `php artisan test --filter=AttendanceBranchRankingTest`
Expected: PASS.

Run: `php artisan test --filter=AttendanceRankingPageTest`
Expected: PASS.

Run: `php artisan test --filter=AttendanceCertificatesPdfTest`
Expected: PASS.

- [ ] **Step 5: The Group select on the page**

In `resources/js/Pages/Attendance/Ranking.vue`:

In the comment above `defineProps`, replace:

```js
 * then fewer lates). Players under the minimum are counted, not ranked.
```

with:

```js
 * then fewer lates). Players under the minimum are counted, not ranked.
 * A category split into training groups can be ranked one group at a time.
```

Replace:

```js
    branchId: { type: Number, default: null },
    period: { type: Object, required: true }, // { type, month, season, from, to, label }
```

with:

```js
    branchId: { type: Number, default: null },
    groups: { type: Array, default: () => [] }, // the category's training groups in this branch: [{ id, name }]
    groupId: { type: Number, default: null }, // null = all groups
    period: { type: Object, required: true }, // { type, month, season, from, to, label }
```

Replace:

```js
function query(changes = {}) {
    const q = { category_id: props.categoryId, ...(props.branchId ? { branch_id: props.branchId } : {}), type: props.period.type, ...changes };
    if (q.type === 'season') q.season = changes.season ?? props.period.season;
```

with:

```js
function query(changes = {}) {
    const q = { category_id: props.categoryId, ...(props.branchId ? { branch_id: props.branchId } : {}), group_id: props.groupId, type: props.period.type, ...changes };
    // A training group belongs to one branch + category: another one starts on all groups. No group = no parameter.
    if ('category_id' in changes || 'branch_id' in changes || !q.group_id) delete q.group_id;
    if (q.type === 'season') q.season = changes.season ?? props.period.season;
```

In the template, replace:

```vue
                <select data-help="ranking-category" :value="categoryId ?? ''" :class="input" :aria-label="t('att.category')" @change="visit({ category_id: Number($event.target.value) })">
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
```

with:

```vue
                <select data-help="ranking-category" :value="categoryId ?? ''" :class="input" :aria-label="t('att.category')" @change="visit({ category_id: Number($event.target.value) })">
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <select v-if="groups.length" data-help="ranking-group" :value="groupId ?? ''" :class="input" :aria-label="t('att.group')" @change="visit({ group_id: Number($event.target.value) || null })">
                    <option value="">{{ t('att.all_groups') }}</option>
                    <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
                </select>
```

Nothing else changes: `podiumHref` and `certificateHref` are built from `query()`, so both certificate links now carry the group; their `:href="podiumHref"` / `:href="certificateHref(row)"` attributes stay exactly as they are (`AttendanceFollowupLinksTest` reads them).

- [ ] **Step 6: Build and run the checks**

Run: `npm run build`
Expected: builds with no error.

Run: `npm run i18n:check`
Expected: PASS.

Run: `php artisan test --filter=AttendanceFollowupLinksTest`
Expected: PASS.

Run: `php artisan test --filter=AttendanceGroupRankingTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/AttendanceRankingController.php resources/js/Pages/Attendance/Ranking.vue tests/Feature/AttendanceGroupRankingTest.php
git commit -m "feat(attendance): ranking and certificates for one training group"
```

---

### Task 5: Group select on the players at risk and their export

**Files:**
- Modify: `app/Services/Attendance/AtRisk.php` (class comment, `list()`, `flagged()`)
- Modify: `app/Http/Controllers/AttendanceAlertsController.php` (class comment, `export()`, `data()`)
- Modify: `resources/js/Pages/Attendance/Alerts.vue`
- Test: `tests/Feature/AttendanceGroupAlertsTest.php`

**Interfaces:**
- Consumes: `AttendanceStats::players(…, ?int $groupId)` and `AttendanceStats::unexcusedStreaks(…, ?int $groupId)` (Task 2); `GroupChoice::fromRequest(Request $request, ?int $categoryId, ?int $branchId): array{options: list<array{id: int, name: string}>, selected: ?int}` and the i18n key `att.all_groups` (Task 3); `GroupAttendanceFixtures::splitCategory()` (Task 1).
- Produces: `AtRisk::list(string $from, string $to, ?int $categoryId = null, ?int $branchId = null, ?int $groupId = null): array` (same rows as before); query parameter `group_id` on `attendance.alerts` and `attendance.alerts.export`; Inertia props `groups` and `groupId` on `Attendance/Alerts`. `AtRisk::anyBranch()` and `AtRisk::forPlayer()` are not touched.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttendanceGroupAlertsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\Attendance\AtRisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\Support\GroupAttendanceFixtures;
use Tests\TestCase;

class AttendanceGroupAlertsTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, GroupAttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');   // season 2026/27: 2026-09-01 → 2027-08-31, the page's default period
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Swimming · Minime, plus $extra (which wins). */
    private function filters(array $club, array $extra = []): array
    {
        return $extra + ['branch_id' => $club['swim']->id, 'category_id' => $club['minime']->id];
    }

    #[Test]
    public function the_flags_come_from_one_groups_sessions(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1, 'g2' => $g2, 'b' => $b, 'mover' => $mover] = $this->splitCategory();
        $risk = app(AtRisk::class);

        $first = $risk->list('2026-09-01', '2027-08-31', $minime->id, $swim->id, $g1->id);
        $this->assertSame([$mover->id], array_column($first, 'player_id'));
        $this->assertSame(5, $first[0]['expected']);
        $this->assertSame(0.0, $first[0]['score_pct']);
        $this->assertSame(3, $first[0]['unexcused']);
        $this->assertSame(3, $first[0]['longest_streak']);
        $this->assertSame(0, $first[0]['current_streak']);
        $this->assertSame('2026-10-03', $first[0]['last_date']);
        $this->assertTrue($first[0]['low_score']);
        $this->assertTrue($first[0]['streak']);

        // With G2 the player who moved is at 100 %: only b is flagged there.
        $second = $risk->list('2026-09-01', '2027-08-31', $minime->id, $swim->id, $g2->id);
        $this->assertSame([$b->id], array_column($second, 'player_id'));
        $this->assertSame(40.0, $second[0]['score_pct']);
        $this->assertSame(3, $second[0]['longest_streak']);

        // No group: both, for their score over the whole category; the Tuesdays break the streaks.
        $all = $risk->list('2026-09-01', '2027-08-31', $minime->id, $swim->id);
        $this->assertEqualsCanonicalizing([$b->id, $mover->id], array_column($all, 'player_id'));
        $this->assertSame([false, false], array_column($all, 'streak'));
        $this->assertSame($all, $risk->list('2026-09-01', '2027-08-31', $minime->id, $swim->id, null));
    }

    #[Test]
    public function the_page_follows_the_chosen_group(): void
    {
        $club = $this->splitCategory();
        ['g1' => $g1, 'g2' => $g2, 'b' => $b, 'mover' => $mover] = $club;
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.alerts', $this->filters($club, ['group_id' => $g1->id])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Alerts')
                ->where('groupId', $g1->id)
                ->where('groups', [['id' => $g1->id, 'name' => 'G1'], ['id' => $g2->id, 'name' => 'G2']])
                ->has('rows', 1)
                ->where('rows.0.player_id', $mover->id)
                ->where('rows.0.expected', 5)
                ->where('rows.0.score_pct', 0)
                ->where('rows.0.longest_streak', 3)
                ->where('rows.0.last_date', '2026-10-03')
                ->where('rows.0.low_score', true)
                ->where('rows.0.streak', true));

        $this->actingAs($admin)->get(route('attendance.alerts', $this->filters($club, ['group_id' => $g2->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('groupId', $g2->id)
                ->has('rows', 1)
                ->where('rows.0.player_id', $b->id)
                ->where('rows.0.score_pct', 40)
                ->where('rows.0.longest_streak', 3));
    }

    #[Test]
    public function all_groups_is_the_default_and_what_an_unknown_group_falls_back_to(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'g1' => $g1, 'b' => $b, 'mover' => $mover] = $club;
        $admin = $this->admin();

        foreach ([[], ['group_id' => 999999], ['group_id' => 0]] as $extra) {
            $this->actingAs($admin)->get(route('attendance.alerts', $this->filters($club, $extra)))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('groupId', null)
                    ->has('groups', 2)
                    ->where('rows', fn ($rows) => collect($rows)->pluck('player_id')->sort()->values()->all() === [$b->id, $mover->id])
                    ->where('rows.0.longest_streak', 1)
                    ->where('rows.1.longest_streak', 1));
        }

        // All categories: no field, and a group in the URL is ignored.
        $this->actingAs($admin)->get(route('attendance.alerts', ['branch_id' => $swim->id, 'group_id' => $g1->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('categoryId', null)
                ->where('groups', [])
                ->where('groupId', null)
                ->has('rows', 2));
    }

    #[Test]
    public function the_export_is_one_groups(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1, 'b' => $b, 'mover' => $mover] = $club;
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->get(route('attendance.alerts.export', $this->filters($club, ['group_id' => $g1->id, 'format' => 'csv'])))
            ->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString("-b{$swim->id}-{$minime->id}-g{$g1->id}.csv", (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString(' — Swimming — Minime — G1', $csv);
        $this->assertStringContainsString($mover->fullname, $csv);
        $this->assertStringNotContainsString($b->fullname, $csv);

        // Without a group the file is named as before and lists both.
        $plain = $this->actingAs($admin)
            ->get(route('attendance.alerts.export', $this->filters($club, ['format' => 'csv'])))
            ->assertOk();
        $this->assertStringContainsString("-b{$swim->id}-{$minime->id}.csv", (string) $plain->headers->get('content-disposition'));
        $this->assertStringNotContainsString(' — Minime — ', $plain->streamedContent());
        $this->assertStringContainsString($b->fullname, $plain->streamedContent());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AttendanceGroupAlertsTest`
Expected: FAIL — `AtRisk::list()` ignores the extra argument (the first test gets two players instead of one), the page has no `groupId` prop, and the export carries no group.

- [ ] **Step 3: Pass the group through `AtRisk`**

In `app/Services/Attendance/AtRisk.php`:

In the class comment, replace:

```php
 * A threshold of 0 turns its rule off. Every number comes from AttendanceStats,
 * over one branch's sessions ($branchId; null = no branch condition, a club
 * without branches): a two-sport player is judged per sport.
```

with:

```php
 * A threshold of 0 turns its rule off. Every number comes from AttendanceStats,
 * over one branch's sessions ($branchId; null = no branch condition, a club
 * without branches): a two-sport player is judged per sport. The page's list
 * can also be read over one training group's sessions ($groupId, GroupScope);
 * the dashboard card and the profile card never are.
```

Replace:

```php
    public function list(string $from, string $to, ?int $categoryId = null, ?int $branchId = null): array
    {
        return $this->named($this->flagged($from, $to, $branchId), $categoryId, $branchId);
    }
```

with:

```php
    public function list(string $from, string $to, ?int $categoryId = null, ?int $branchId = null, ?int $groupId = null): array
    {
        return $this->named($this->flagged($from, $to, $branchId, $groupId), $categoryId, $branchId);
    }
```

In the comment above `list()`, replace:

```php
     * their numbers still cover all their marks in the branch, since the
     * risk is the person's. Six queries whatever the roster (the custom
```

with:

```php
     * their numbers still cover all their marks in the branch, since the
     * risk is the person's. $groupId keeps only the marks of that training
     * group's sessions: both rules are then judged on them alone.
     * Six queries whatever the roster (the custom
```

Replace:

```php
    private function flagged(string $from, string $to, ?int $branchId): array
    {
        $thresholds = $this->thresholds();
        $streaks = $this->stats->unexcusedStreaks($from, $to, null, $branchId);

        $flagged = [];
        foreach ($this->stats->players($from, $to, null, null, $branchId) as $playerId => $row) {
```

with:

```php
    private function flagged(string $from, string $to, ?int $branchId, ?int $groupId = null): array
    {
        $thresholds = $this->thresholds();
        $streaks = $this->stats->unexcusedStreaks($from, $to, null, $branchId, $groupId);

        $flagged = [];
        foreach ($this->stats->players($from, $to, null, null, $branchId, $groupId) as $playerId => $row) {
```

and, in the comment above `flagged()`, replace `The flagged players of one branch (null: no branch condition), without` with `The flagged players of one branch (null: no branch condition), over one training group's sessions when given, without`.

(`anyBranch()` keeps calling `$this->flagged($from, $to, $branchId)`: the dashboard never follows a group.)

- [ ] **Step 4: The controller**

In `app/Http/Controllers/AttendanceAlertsController.php`:

Add the import (after `use App\Services\Attendance\BranchChoice;`):

```php
use App\Services\Attendance\GroupChoice;
```

In the class comment, replace:

```php
 * optional category (the players' current one). The rules and every number
 * come from AtRisk / AttendanceStats, over that branch's sessions.
```

with:

```php
 * optional category (the players' current one). A category split into
 * training groups can be narrowed to one of them (GroupChoice): the rules
 * are then judged on that group's sessions only. The rules and every number
 * come from AtRisk / AttendanceStats, over that branch's sessions.
```

In `export()`, replace:

```php
        $branchName = $data['branchId'] !== null ? collect($data['branches'])->firstWhere('id', $data['branchId'])['name'] : null;

        $headers = [
```

with:

```php
        $branchName = $data['branchId'] !== null ? collect($data['branches'])->firstWhere('id', $data['branchId'])['name'] : null;
        // A chosen training group follows the category wherever the list is named.
        $groupName = $data['groupId'] !== null ? collect($data['groups'])->firstWhere('id', $data['groupId'])['name'] : null;

        $headers = [
```

Replace:

```php
            'attendance-at-risk-'.$from.'-'.$to
                .($data['branchId'] !== null ? '-b'.$data['branchId'] : '')
                .($data['categoryId'] !== null ? '-'.$data['categoryId'] : ''),
            $headers,
            $rows,
            implode(' — ', array_filter([UiLang::get('att.risk.title'), $data['period']['label'], $branchName, $categoryName])),
```

with:

```php
            'attendance-at-risk-'.$from.'-'.$to
                .($data['branchId'] !== null ? '-b'.$data['branchId'] : '')
                .($data['categoryId'] !== null ? '-'.$data['categoryId'] : '')
                .($data['groupId'] !== null ? '-g'.$data['groupId'] : ''),
            $headers,
            $rows,
            implode(' — ', array_filter([UiLang::get('att.risk.title'), $data['period']['label'], $branchName, $categoryName, $groupName])),
```

In `data()`, replace:

```php
        $period = ActivityPeriod::fromRequestOrSeason($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        return [
```

with:

```php
        // The category's training groups in this branch, and the one asked for (null = all groups).
        ['options' => $groups, 'selected' => $groupId] = GroupChoice::fromRequest($request, $categoryId, $branchId);
        $period = ActivityPeriod::fromRequestOrSeason($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        return [
```

and replace:

```php
            'branches' => BranchChoice::options(),
            'branchId' => $branchId,
            'thresholds' => $this->risk->thresholds(),
            'rows' => $this->risk->list($from, $to, $categoryId, $branchId),
```

with:

```php
            'branches' => BranchChoice::options(),
            'branchId' => $branchId,
            // The Group select ([] = none to offer) and the group judged on (null = all groups).
            'groups' => $groups,
            'groupId' => $groupId,
            'thresholds' => $this->risk->thresholds(),
            'rows' => $this->risk->list($from, $to, $categoryId, $branchId, $groupId),
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=AttendanceGroupAlertsTest`
Expected: PASS (4 tests).

Run: `php artisan test --filter=AttendanceBranchAlertsTest`
Expected: PASS (its fixed query count of `anyBranch()` still holds).

Run: `php artisan test --filter=AttendanceAlertsPageTest`
Expected: PASS.

Run: `php artisan test --filter=AttendanceAtRiskTest`
Expected: PASS.

Run: `php artisan test --filter=DashboardAttendanceCardTest`
Expected: PASS.

Run: `php artisan test --filter=AttendanceGroupReportsUnchangedTest`
Expected: PASS.

- [ ] **Step 6: The Group select on the page**

In `resources/js/Pages/Attendance/Alerts.vue`:

In the comment above `defineProps`, replace:

```js
 * default, and an optional category (the players' current one). A player is
 * listed for a low score, an unexcused streak, or both.
```

with:

```js
 * default, and an optional category (the players' current one). A player is
 * listed for a low score, an unexcused streak, or both. A category split
 * into training groups can be judged on one group's sessions.
```

Replace:

```js
    branchId: { type: Number, default: null },
    rows: { type: Array, default: () => [] },
});
```

with:

```js
    branchId: { type: Number, default: null },
    groups: { type: Array, default: () => [] }, // the chosen category's training groups in this branch: [{ id, name }]
    groupId: { type: Number, default: null }, // null = all groups
    rows: { type: Array, default: () => [] },
});
```

Replace:

```js
const branchQuery = computed(() => (props.branchId ? { branch_id: props.branchId } : {}));
const keep = computed(() => ({ ...(props.categoryId ? { category_id: props.categoryId } : {}), ...branchQuery.value }));
const exportHref = computed(() => route('attendance.alerts.export', { ...periodQuery(props.period), ...keep.value }));
```

with:

```js
const branchQuery = computed(() => (props.branchId ? { branch_id: props.branchId } : {}));
const categoryQuery = computed(() => (props.categoryId ? { category_id: props.categoryId } : {}));
// What the period control and the export keep: category, training group and branch.
const keep = computed(() => ({ ...categoryQuery.value, ...(props.groupId ? { group_id: props.groupId } : {}), ...branchQuery.value }));
const exportHref = computed(() => route('attendance.alerts.export', { ...periodQuery(props.period), ...keep.value }));
```

Replace:

```js
function switchBranch(id) {
    router.get(route('attendance.alerts'), { ...periodQuery(props.period), ...keep.value, branch_id: id }, { preserveScroll: true, replace: true });
}
```

with:

```js
// A training group belongs to one branch + category: another category or branch starts on all groups.
function pickGroup(value) {
    router.get(route('attendance.alerts'), { ...periodQuery(props.period), ...branchQuery.value, ...categoryQuery.value, ...(value ? { group_id: Number(value) } : {}) }, { preserveScroll: true, replace: true });
}
function switchBranch(id) {
    router.get(route('attendance.alerts'), { ...periodQuery(props.period), ...categoryQuery.value, branch_id: id }, { preserveScroll: true, replace: true });
}
```

(`pickCategory()` stays as it is: it already rebuilds the query without the group. `letterHref()` and `profileHref()` stay as they are too: the letter and the profile keep the branch's whole numbers.)

In the template, replace:

```vue
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>

            <ul data-help="alerts-rules" class="space-y-0.5 text-sm text-slate-600 dark:text-slate-300">
```

with:

```vue
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <select v-if="groups.length" :value="groupId ?? ''" :class="input" :aria-label="t('att.group')" @change="pickGroup($event.target.value)">
                    <option value="">{{ t('att.all_groups') }}</option>
                    <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
                </select>
            </div>

            <ul data-help="alerts-rules" class="space-y-0.5 text-sm text-slate-600 dark:text-slate-300">
```

- [ ] **Step 7: Build and run the checks**

Run: `npm run build`
Expected: builds with no error.

Run: `npm run i18n:check`
Expected: PASS.

Run: `php artisan test --filter=AttendanceFollowupLinksTest`
Expected: PASS.

Run: `php artisan test --filter=AttendanceGroupAlertsTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Attendance/AtRisk.php app/Http/Controllers/AttendanceAlertsController.php resources/js/Pages/Attendance/Alerts.vue tests/Feature/AttendanceGroupAlertsTest.php
git commit -m "feat(attendance): players at risk and their export for one training group"
```

---

### Task 6: Help Center

One paragraph in each of the two report articles, in the three languages, and one new shot. The existing shots of these articles (`att-stats`, `att-ranking-month`, `att-ranking`, `att-alerts`, `att-alerts-letter`) are taken on football, which has no groups: their screens did not change, so they are **not** retaken (their content also depends on the seed day, and retaking them would only add unrelated image changes).

The demo club's two groups (phase 2) have planned sessions only, so a chosen group would rank nobody. The new shot therefore shows the ranking of Swimming · «أصاغر» for the season on «كل الأفواج»: the field is there, the list is full.

**Files:**
- Modify: `resources/help/shots.js` (one shot)
- Modify: `resources/help/ar/attendance/stats-ranking.md`, `resources/help/fr/attendance/stats-ranking.md`, `resources/help/en/attendance/stats-ranking.md`
- Modify: `resources/help/ar/attendance/alerts-injuries.md`, `resources/help/fr/attendance/alerts-injuries.md`, `resources/help/en/attendance/alerts-injuries.md`
- Create: `public/help-shots/{ar,fr,en}/att-ranking-group.json`, `public/help-shots/{ar,fr,en}/light/att-ranking-group.webp`, `public/help-shots/{ar,fr,en}/dark/att-ranking-group.webp`

**Interfaces:**
- Consumes: `data-help="ranking-group"` (Task 4); the i18n labels `att.all_groups` (Task 3), `att.group`, `att.stats_title`, `att.ranking.title`, `att.risk.title`, `att.letter.print`, `att.injury.title` (existing); the constant `CADETS` (`'branch_id=1&category_id=11'`) and the demo groups «الفوج 1» / «الفوج 2» of Swimming · «أصاغر» (phase 2, Tasks 7 and 8); the article `attendance/training-groups` (phase 2, Task 8).
- Produces: shot `att-ranking-group`.

- [ ] **Step 1: Read the authoring rules**

Read `resources/help/README.md` in full (sections "Rules", "Screenshots", "Translations (French, English)", "Check before committing"). Follow it wherever it is stricter than this task. Open `resources/help/fr/attendance/stats-ranking.md` and `resources/help/en/attendance/stats-ranking.md` to see the quote style of each language: French uses « … » with no-break spaces inside the quotes and before `?`, `:` and `;`; English uses curly quotes “ … ”. The French texts below are typed with plain spaces: put the no-break spaces in when you save them, as the file already does.

- [ ] **Step 2: The shot**

In `resources/help/shots.js`, add right after the line of the `att-ranking` shot (the one that starts with `{ name: 'att-ranking', url:` and ends with `'ranking-certificate-first'] },`), before the comment `// Alerts and injuries open from the statistics with no category and the current season.`:

```js
    // A category split into training groups (Swimming «أصاغر», HelpDemoAttendanceSeeder): the
    // ranking gains the «الفوج» field, on «كل الأفواج». The demo's group sessions are planned only,
    // so a chosen group would rank nobody: the shot stays on all groups, where the five swimmers
    // have their six Tuesday and Thursday sessions.
    { name: 'att-ranking-group', url: `/attendance/ranking?${CADETS}&type=season`, targets: ['ranking-group'] },
```

`CADETS` is declared by phase 2 near the top of the file (`const CADETS = 'branch_id=1&category_id=11';`). If `grep -n "const CADETS" resources/help/shots.js` prints nothing, stop and report: phase 2 is not in.

- [ ] **Step 3: The statistics and ranking article**

In the three `attendance/stats-ranking.md` files: add `attendance/training-groups` at the end of the `related:` list, add two keywords before the last keyword (ar: `الأفواج, فوج`; fr: `groupes, groupe`; en: `groups, group`), and insert the paragraph and its figure, with one blank line before and after, right before the `:::tip` block.

`ar`:

```markdown
فئة مقسّمة إلى أفواج؟ عند اختيارها تظهر خانة «الفوج» بجانب خانة الفئة، في «إحصائيات الحضور» وفي «ترتيب المواظبة». «كل الأفواج» تعرض أرقام الفئة كاملة. اختر فوجًا لترى أرقام حصصه فقط. الشهادات تحمل اسم الفوج. اللاعب الذي غيّر فوجه يظهر في الفوجين، كلٌّ بحصصه.
::figure att-ranking-group ring=ranking-group
```

`fr`:

```markdown
Une catégorie répartie en groupes ? Quand vous la choisissez, le champ « Groupe » apparaît à côté de la catégorie, dans « Statistiques de présence » et dans « Classement d'assiduité ». « Tous les groupes » affiche les chiffres de toute la catégorie. Choisissez un groupe pour voir seulement les chiffres de ses séances. Les certificats portent le nom du groupe. Un joueur qui a changé de groupe apparaît dans les deux groupes, chacun avec ses séances.
::figure att-ranking-group ring=ranking-group
```

`en`:

```markdown
Is the category split into groups? When you choose it, a “Group” field appears next to the category, in “Attendance statistics” and in “Attendance ranking”. “All groups” shows the whole category's figures. Choose a group to see the figures of its sessions only. The certificates carry the group's name. A player who changed group appears in both groups, each with its own sessions.
::figure att-ranking-group ring=ranking-group
```

- [ ] **Step 4: The alerts and injuries article**

In the three `attendance/alerts-injuries.md` files: add `attendance/training-groups` at the end of the `related:` list, add the same two keywords before the last keyword (ar: `الأفواج, فوج`; fr: `groupes, groupe`; en: `groups, group`), and insert the paragraph, with one blank line before and after, right before the `:::tip` block. No figure.

`ar`:

```markdown
فئة مقسّمة إلى أفواج؟ في «اللاعبون المعرّضون للخطر»، اختر الفئة: تظهر خانة «الفوج». اختر فوجًا ليُحسب التنبيه من حصص هذا الفوج فقط. «رسالة إلى الولي» وصفحة اللاعب تبقيان بأرقام الفرع كله. صفحة «الإصابات» لا تتغيّر.
```

`fr`:

```markdown
Une catégorie répartie en groupes ? Dans « Joueurs à risque », choisissez la catégorie : le champ « Groupe » apparaît. Choisissez un groupe pour que l'alerte soit calculée sur ses séances seulement. La « Lettre aux parents » et la page du joueur gardent les chiffres de toute la section. La page « Blessures » ne change pas.
```

`en`:

```markdown
Is the category split into groups? In “Players at risk”, choose the category: a “Group” field appears. Choose a group so the alert is worked out from its sessions only. The “Parent letter” and the player's page keep the figures of the whole branch. The “Injuries” page does not change.
```

(If another feature has changed these files meanwhile, keep its text and only add these lines.)

- [ ] **Step 5: Take the shot in the three languages**

The script seeds its own database (`storage/help-demo.sqlite`), serves it on port 8765 and stops its server itself; it needs the fresh build. The owner's servers on 2026 and 5173 are not used: leave them alone. Do not run anything else in the worktree while it runs.

```bash
npm run build
npm run help:shots -- att-ranking-group
npm run help:shots -- --locale=fr att-ranking-group
npm run help:shots -- --locale=en att-ranking-group
```

Expected: each run ends with a `✓ light …` and a `✓ dark …` line for the shot and no `✗`. If a run stops on "no element with data-help", the anchor `ranking-group` is missing on the page: the category has no group in the demo club (phase 2's seeder) or Task 4's select is not there. Fix the cause, never the check.

Then look at `public/help-shots/ar/light/att-ranking-group.webp`, and at the French and English ones: the «الفوج» field sits right after the category field and reads «كل الأفواج» (« Tous les groupes », "All groups"), the category reads «أصاغر», and the table lists the swimmers. Near a season's start (September) the table can be empty: the field is still there, and the shot is still right.

- [ ] **Step 6: Check**

Run: `php artisan help:check`
Expected: `Help content OK — <n> article(s).` with the same number as before this task (no article was added).

Run: `php artisan help:check --locale=fr` and `php artisan help:check --locale=en`
Expected: OK for both.

Run: `php artisan test --filter=Help`
Expected: PASS.

- [ ] **Step 7: Commit**

Stage the article files, `shots.js`, and each image and JSON the runs wrote (`git status --short public/help-shots` lists them: nine files, three per language), by path:

```bash
git add resources/help/shots.js
git add resources/help/ar/attendance/stats-ranking.md resources/help/fr/attendance/stats-ranking.md resources/help/en/attendance/stats-ranking.md
git add resources/help/ar/attendance/alerts-injuries.md resources/help/fr/attendance/alerts-injuries.md resources/help/en/attendance/alerts-injuries.md
git add <each public/help-shots/{ar,fr,en}/ file listed by git status>
git commit -m "docs(help): the group filter of the attendance reports"
```

---

### Task 7: Whole-branch verification

**Files:** none (verification only; a fix goes into the task it belongs to, as a new commit).

- [ ] **Step 1: Run the whole PHP suite in the background**

Run (background, one run at a time): `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit`
Expected: all tests pass. A failure in a test this plan did not write means a shared rule changed: read it, fix the cause, run the whole suite again.

- [ ] **Step 2: Run the front-end checks**

Run: `npm run build` — Expected: no error.
Run: `npm run i18n:check` — Expected: PASS.
Run: `npm run test:js` — Expected: PASS.
Run: `php artisan help:check` — Expected: no error (also with `--locale=fr` and `--locale=en`).

- [ ] **Step 3: Prepare a copy of the dev database**

This phase has no migration; the copy only has to be up to date with `main`. Copy `D:/irnb-laravel/database/database.sqlite` to the worktree as `database/groups-p3-check.sqlite` (read the live file once to copy it; never open it from the worktree). Then:

```bash
DB_DATABASE="$(pwd)/database/groups-p3-check.sqlite" APP_CONFIG_CACHE="$(pwd)/bootstrap/cache/config-groups-p3-check.php" php artisan migrate --force
DB_DATABASE="$(pwd)/database/groups-p3-check.sqlite" APP_CONFIG_CACHE="$(pwd)/bootstrap/cache/config-groups-p3-check.php" php artisan migrate --force
```

Expected: the first run applies what the copy lacks, if anything (never a migration of this branch: it has none); the second says "Nothing to migrate".

Create a throwaway account on the copy to sign in with:

```bash
DB_DATABASE="$(pwd)/database/groups-p3-check.sqlite" APP_CONFIG_CACHE="$(pwd)/bootstrap/cache/config-groups-p3-check.php" php artisan tinker --execute="App\Models\User::create(['name' => 'QA P3', 'username' => 'qa-p3', 'email' => 'qa-p3@example.invalid', 'password' => Illuminate\Support\Facades\Hash::make('qa-p3-pass'), 'firstname' => 'QA', 'lastname' => 'P3', 'gender' => 'Male', 'phones' => [], 'is_user' => true, 'is_active' => true, 'approved' => true, 'privileges' => ['superadmin', 'admin'], 'preferred_lng' => 'ar', 'email_verified_at' => now()]);"
```

The reports need held group sessions with marks, which the dev data does not have. Create the folder `.superpowers/sdd/qa/p3/` (git-ignored) and write `.superpowers/sdd/qa/p3/seed.php`:

```php
<?php

// Throwaway data for the phase 3 browser check. Writes to the COPY of the dev database only.

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Attendance\GroupMembership;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\Roster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

if (! str_contains((string) DB::connection()->getDatabaseName(), 'groups-p3-check')) {
    throw new RuntimeException('Not the QA copy: nothing written.');
}

$today = CarbonImmutable::today();
$roster = app(Roster::class);

// The branch + category with the longest list today (a club without branches: no branch).
$best = null;
foreach (Branch::orderBy('id')->pluck('id')->all() ?: [null] as $branchId) {
    foreach (Category::forBranch($branchId)->orderBy('id')->get() as $category) {
        $listed = $roster->expected($category->id, $today->toDateString(), null, $branchId);
        if ($best === null || $listed->count() > $best[2]->count()) {
            $best = [$branchId, $category, $listed];
        }
    }
}
if ($best === null || $best[2]->count() < 4) {
    throw new RuntimeException('No category with four players on its list.');
}
[$branchId, $category, $listed] = $best;
[$steady, $mover, $weak, $other] = $listed->take(4)->values()->all();

$g1 = TrainingGroup::create(['branch_id' => $branchId, 'category_id' => $category->id, 'name' => 'QA 1']);
$g2 = TrainingGroup::create(['branch_id' => $branchId, 'category_id' => $category->id, 'name' => 'QA 2']);

// The last ten Saturdays before today, oldest first.
$saturdays = [];
for ($day = $today->subDay(); count($saturdays) < 10; $day = $day->subDay()) {
    if ($day->dayOfWeekIso === 6) {
        array_unshift($saturdays, $day->toDateString());
    }
}

// steady: QA 1. mover: QA 1 for five Saturdays, then QA 2. weak and other: QA 2.
$membership = app(GroupMembership::class);
$membership->add($g1, $steady->id, null);
$membership->add($g1, $mover->id, null);
$membership->add($g2, $mover->id, $saturdays[5]);
$membership->add($g2, $weak->id, null);
$membership->add($g2, $other->id, null);

$user = User::where('username', 'qa-p3')->firstOrFail();
$recorder = app(MarkRecorder::class);
$planned = fn (TrainingGroup $group, string $date, string $start, string $end) => TrainingSession::create([
    'branch_id' => $branchId, 'category_id' => $category->id, 'group_id' => $group->id, 'date' => $date,
    'start_time' => $start, 'end_time' => $end, 'kind' => SessionKind::Extra, 'state' => SessionState::Planned,
]);

foreach ($saturdays as $i => $date) {
    $earlyMiss = $i < 3 ? 'absent_unexcused' : 'present';
    $first = [['player_id' => $steady->id, 'status' => 'present']];
    $second = [['player_id' => $weak->id, 'status' => $earlyMiss], ['player_id' => $other->id, 'status' => 'present']];
    if ($i < 5) {
        $first[] = ['player_id' => $mover->id, 'status' => $earlyMiss];
    } else {
        $second[] = ['player_id' => $mover->id, 'status' => 'present'];
    }
    // Early hours, so no real session of the category holds the same slot.
    $recorder->save($planned($g1, $date, '06:00', '07:30'), $first, $user);
    $recorder->save($planned($g2, $date, '07:30', '09:30'), $second, $user);
}

echo json_encode([
    'branch_id' => $branchId,
    'category_id' => $category->id,
    'category' => $category->name,
    'groups' => ['QA 1' => $g1->id, 'QA 2' => $g2->id],
    'players' => [
        'steady' => [$steady->id, $steady->fullname],
        'mover' => [$mover->id, $mover->fullname],
        'weak' => [$weak->id, $weak->fullname],
        'other' => [$other->id, $other->fullname],
    ],
    'saturdays' => $saturdays,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
```

Run it once and keep what it prints (the QA script needs the ids and the names):

```bash
DB_DATABASE="$(pwd)/database/groups-p3-check.sqlite" APP_CONFIG_CACHE="$(pwd)/bootstrap/cache/config-groups-p3-check.php" php artisan tinker --execute="require base_path('.superpowers/sdd/qa/p3/seed.php');"
```

Expected: a JSON block with `branch_id`, `category_id`, two group ids, four players and ten dates. If it stops on a unique-key error, a real session already holds 06:00 or 07:30 on one of those Saturdays: delete the copy, copy the dev database again, change the two start times in the script and run again.

What the copy now holds, in the two QA groups only (the four players may have other, real marks in the category, which "all groups" also counts):

| | QA 1 | QA 2 |
|---|---|---|
| held sessions this season | 10 | 10 |
| steady | 10 expected, 100 % | — |
| mover | 5 expected, 0 %, 3 unexcused in a row | 5 expected, 100 % |
| weak | — | 10 expected, 40 %, 3 unexcused in a row |
| other | — | 10 expected, 100 % |

- [ ] **Step 4: Browser check on that copy, driven by Playwright**

Serve the copy on port 2027 (ports 2026 and 5173 belong to the owner: do not touch them):

```bash
DB_DATABASE="$(pwd)/database/groups-p3-check.sqlite" APP_CONFIG_CACHE="$(pwd)/bootstrap/cache/config-groups-p3-check.php" php artisan serve --port=2027 --no-reload
```

Drive a real browser with the repository's Playwright (`import { chromium } from 'playwright'`, `chromium.launch({ channel: 'chromium' })`, as `scripts/help-shots.mjs` does) from a throwaway script kept in `.superpowers/sdd/qa/p3/` (git-ignored), signing in at `http://127.0.0.1:2027/login` as `qa-p3` / `qa-p3-pass`, and saving one screenshot per check there. `B`, `C`, `G1`, `G2` below are the ids the seed printed.

1. **Statistics, all groups.** Open `/attendance/stats?branch_id=B&category_id=C&period=season`. A «الفوج» field sits after the category field and reads «كل الأفواج». Write down the six tiles.
2. **Statistics, one group.** Choose «QA 1». The URL gains `group_id=G1`; «الحصص المنجزة» reads 10 and the marks tile 15; the player table lists two players (steady 10 expected, 100 %; mover 5 expected, 0 %); the "active filters" count went up by one. The table of categories shows the same row for the category as in check 1.
3. **Back to all groups.** Choose «كل الأفواج»: the six tiles are the ones written down in check 1, and the URL has no `group_id`.
4. **Changing category or branch drops the group.** With «QA 2» chosen, pick another category: no `group_id` in the URL, and no «الفوج» field if that category has no groups. Pick «كل الفئات»: no field. Go back to the category, choose «QA 2», switch the period to «هذا الشهر»: the group stays.
5. **Statistics exports.** With «QA 1» chosen, read the export menu's link: it carries `group_id=G1`. Request it with `format=csv` and save the file: its name ends with `-gG1.csv` and its first line ends with the category's name, then `QA 1`. Request `format=pdf` and save it: the line under the title names the category, then `QA 1`.
6. **Ranking.** Open `/attendance/ranking?branch_id=B&category_id=C&type=season`: the «الفوج» field is there on «كل الأفواج». Choose «QA 1»: two rows, steady first with 100 %, mover second with 0 %. Choose «QA 2»: mover and other at 100 %, weak last at 40 %. Switch «موسم» to «شهر» and back: the group stays. Change the category: the group is dropped.
7. **Certificates.** With «QA 2» chosen, request the «طباعة شهادات الثلاثة الأوائل» link's `href` and save the PDF: its name contains `-gG2-`, and each page names the category, then `QA 2`. Request the first row's «شهادة» link: one page, with the group. Request `/attendance/certificates?branch_id=B&category_id=C&type=season&group_id=G2&player_id=<steady's id>`: 404.
8. **Alerts.** Open `/attendance/alerts?branch_id=B&category_id=C`: the «الفوج» field is there. Choose «QA 1»: mover is listed with 0 %, a longest streak of 3 and both reasons; steady is not. Choose «QA 2»: weak is listed, mover is not. The export link carries the group; the CSV's first line names it.
9. **Letter and profile stay whole.** On the alerts page with «QA 1», request mover's «رسالة إلى الولي» link and save the PDF: its URL has no `group_id`, and it lists his three absences. Open mover's profile from the row: the attendance card shows at least 10 sessions this season, from both groups.
10. **Pages without the filter.** `/attendance/injuries?branch_id=B&category_id=C` has no «الفوج» field. The dashboard's members tab loads with its attendance card.
11. **No groups, no change.** Choose a category without groups (football, for example) on the statistics, the ranking and the alerts: none of the three has a «الفوج» field, and their export links carry no `group_id`.
12. **Languages and sizes.** French, then English, then dark mode, then a 320 px wide window, on the three pages with a group chosen: the field reads « Groupe » / "Group" with « Tous les groupes » / "All groups" as its first entry, stays readable, and nothing overflows sideways.
13. **Help Center.** Open «كيف أتابع المواظبة وأطبع الشهادات» and «كيف أتابع التنبيهات والإصابات» in Arabic, French and English: the new paragraph is there before the tip, and the first article shows the new image in light and dark.

Stop the server, then delete `database/groups-p3-check.sqlite` and `bootstrap/cache/config-groups-p3-check.php`. The qa folder stays (it is git-ignored).

- [ ] **Step 5: Report to the controller**

Do not merge, do not push, do not delete the branch: the controller and the owner decide. Report the suite totals, the front-end checks, the two migrate runs, each of the thirteen browser checks as passed or failed (with the screenshot's name), and `git log --oneline main..HEAD`.

---

## Self-review notes

- Spec coverage (phase 3 only): `GroupScope`, built like `BranchScope`, a mark belongs to its session's `group_id` → Task 2; Group select on statistics + export, shown when the chosen category has groups, default "all groups" = today's numbers → Task 3; ranking + certificates → Task 4; alerts + export → Task 5; "every number (score, counts, missed time, streaks, at-risk flags, rank) comes from that group's sessions only" → `AttendanceGroupStatsTest` (score, counts, missed minutes, streaks), `AttendanceGroupRankingTest` (rank), `AttendanceGroupAlertsTest` (flags); "Unchanged: injuries page, player profile card, player PDF report and parent letter, dashboard cards, literary report" → `AttendanceGroupReportsUnchangedTest` (Task 1, written before any change, re-run in Tasks 2, 3 and 5); spec tests "the group filter narrows statistics, ranking and alerts to the group's sessions; a moved player has marks in both groups; no filter equals today's numbers" → the three page tests, `without_a_group_the_numbers_are_the_whole_categorys`, and Task 1's pins; Help Center in three languages → Task 6; whole suite in the background → Task 7. Nothing here adds a group to the calendar filter, a group column to an export, a group to the player card, or a group to the injuries page.
- Decisions recorded under "Rules this plan fixes where the spec is silent": no "sessions with no group" entry in the reports (rule 2); unknown group = all groups (rule 3); the "by category" table and the pre-season column never follow the group (rule 5); certificates name the group and a single certificate for a group needs a mark in its sessions (rule 6); the alert row's letter and profile links stay whole-branch (rule 7); `-g{id}` in export file names (rule 8).
- Names used across tasks: `GroupAttendanceFixtures::splitCategory()` returning `swim`, `minime`, `g1`, `g2`, `a`, `b`, `mover`; `GroupScope::apply(Builder $query, ?int $groupId)`; trailing `?int $groupId = null` on `AttendanceStats::players/monthly/sessionsByMonth/unexcusedStreaks` and on `AtRisk::list`; `GroupChoice::fromRequest(Request, ?int $categoryId, ?int $branchId)` returning `options` / `selected`, and `GroupChoice::options(?int $categoryId, ?int $branchId)`; query parameter `group_id`; Inertia props `groups` / `groupId` on `Attendance/Stats`, `Attendance/Ranking`, `Attendance/Alerts`; JS `categoryQuery`, `pickGroup` (Stats and Alerts) and `query()` (Ranking); i18n `att.all_groups`; anchor `ranking-group`; shot `att-ranking-group`. From phase 2, used as they are: `TrainingGroup::options(?int $categoryId = null)`, `CADETS` in `shots.js`, the demo groups of «أصاغر», the article `attendance/training-groups`. `MonthSheet::NO_GROUP` is deliberately not used by the reports.
- Placeholder scan: every code step shows its code; the only angle-bracket placeholders are the image paths `git status` prints in Task 6 and the ids the seed prints in Task 7.
