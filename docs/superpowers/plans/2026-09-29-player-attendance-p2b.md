# Player Attendance — P2b (statistics, profile card, dashboard card, exports) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the recorded marks into numbers: a club-wide statistics page with charts, tables, a top/bottom 5 and XLSX/CSV/PDF exports; an "Attendance" card on the player profile with its own PDF; and an attendance card on the dashboard's members tab.

**Architecture:** One service, `App\Services\Attendance\AttendanceStats`, computes every figure from a fixed number of grouped queries (schema/query builders only, same SQL on sqlite and MySQL, no per-player queries). It works on held sessions in a `'Y-m-d'` period, filtered by an optional category and/or player; counts stay raw and the discipline rules change the score only. `AttendanceStatsController` serves the page (`attendance.stats`) and its exports (`attendance.stats.export?format=xlsx|csv|pdf`). The profile card is a Vue partial that fetches `attendance.players.show` (JSON, gated on `attendance.view` by its route name) after the profile has painted; `attendance.players.report` prints the same data as a PDF. The dashboard's members tab payload gains an `attendance` key built by `App\Services\Dashboard\AttendanceCard`. Status names and colours come from `AttendanceSettings::codes()` (the `attendanceCodes` prop, read through `useAttendanceCodes()`) on screen, and from `AttendanceSettings::labels()` in PDFs and spreadsheets.

**Tech Stack:** Laravel 13, Inertia v2 + Vue 3 (`<script setup>`, JS), Tailwind 3.4 via PostCSS, vue-i18n (flat dotted keys), Chart.js through `vue-chartjs` (bundled locally), mPDF through `PdfService`, PHPUnit feature tests on sqlite.

Spec: `docs/superpowers/specs/2026-09-29-player-attendance-p2-design.md`, step **2b** only (section E). Rules on points, discipline rules, roster and held sessions: sections A–D there and `2026-09-29-player-attendance-design.md`.

## Global Constraints

- Work in the existing worktree `D:/irnb-attendance` on branch `feat/attendance-p2b` (it starts at main `43c1761`, with P1 and 2a merged). It already has `vendor/`, `node_modules/` and `.env`; do not create it. Use Git Bash and start every shell with `cd /d/irnb-attendance`.
- Stage explicit file paths only (`git add <file>...`). Never `git add -A` or `git add .`. Never commit `.superpowers/` (or `.claude/`).
- Commit with exactly: `git commit -m "<subject>" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"`.
- Dates travel as `'Y-m-d'` strings and times as `'H:i'` strings. `training_sessions.date` is a string column; period bounds are compared as strings (`whereBetween('training_sessions.date', [$from, $to])`). Never add `date`/`datetime` casts.
- Offline only: no CDN, remote fonts or remote scripts. Charts use the local `chart.js` + `vue-chartjs` packages through `resources/js/lib/registerCharts.js`.
- Both databases: sqlite (desktop, tests) and MySQL (web). Query builders only. The only raw fragments allowed are portable ones already used in this repo or valid in both engines: `count(*)`, `coalesce(sum(col), 0)`, `sum(case when col > ? then 1 else 0 end)`, `substr(col, 1, 7)`, `havingRaw('count(*) = 1')`.
- Performance: every `AttendanceStats` method runs a fixed number of queries whatever the roster size (a club can have ~300 players). No query inside a loop over players. A test pins this (Task 1).
- This step adds **no migration**. If you think one is needed, stop and ask. (Reminder from 2a: never rebuild a sqlite table that other tables reference with cascading foreign keys; `->change()` or dropping a column cascade-deletes child rows on sqlite.)
- i18n: flat dotted keys in `resources/js/i18n/{ar,fr,en}.json`. In Vue use `t()` only, never `te()`. Error values that are `att.*` keys are shown through `const tr = (e) => (typeof e === 'string' && e.startsWith('att.') ? t(e) : e);` (no new error keys in 2b). Every `flash.*` key a controller emits must exist in all three files (2b emits none).
- New i18n keys are appended with the temporary `att-i18n.mjs` script given in the task (run it, then delete it). Do **not** use `scripts/i18n-add.mjs`: it re-sorts the files. The files must round-trip exactly through `JSON.stringify(o, null, 4) + '\n'`, and the diff must be additions only (the previous last line of each file gains a comma; nothing else changes).
- Templates never call globals: no `@click="window.x()"`; wrap them in a script function (e.g. `const print = () => window.print();`).
- Never name a test helper `session()`: it collides with Laravel's TestCase. The helpers in this plan are `training()`, `heldSession()` and `mark()`.
- Rebuild with `npm run build` before running tests that make Inertia page assertions after a `.vue` file changed (the root view loads the page through the Vite manifest).
- Print: a page's own controls carry `print:hidden`; the layout already hides the sidebar and the header controls.
- Tests: PHPUnit classes with `#[Test]` and `use RefreshDatabase;` (plus `Tests\Support\AttendanceFixtures` where useful), in `tests/Feature` or `tests/Unit`. Run with `php artisan test --filter=<Class>`.
- Permissions come from route names via `config/permissions.php` and `App\Support\PermissionMap`. New routes and what they resolve to:
  - `attendance.stats` → last segment `stats` is not a view verb, so it gets the override `['attendance', 'view']`;
  - `attendance.stats.export` → `export` is a view verb; one route serves `format=xlsx|csv|pdf`;
  - `attendance.players.show` → `show` is a view verb; the route is under the `attendance` module (not `players`), so the profile card needs `attendance.view`;
  - `attendance.players.report` → `report` is a view verb.
- Status names and colours on screen come only from `resources/js/Composables/useAttendanceCodes.js`, which reads the `attendanceCodes` Inertia prop. Pages that render statuses receive it: `Attendance/Stats` (always), `Players/Show` and `Dashboard` (value of `AttendanceSettings::codes()` for users with `attendance.view`, `null` otherwise). Pages declare the prop (`attendanceCodes: { type: Object, default: null }`) so it never falls through as a DOM attribute.
- PDFs and spreadsheets print status names from `AttendanceSettings::labels()`: the configured name in the app locale, else `att.status.<status>` from the UI catalogs through `App\Support\UiLang` (`resources/js/i18n/*.json`). Today's PDFs are mixed (the board table uses `__()` with `lang/*.json`; the academic report uses `UiLang`); attendance PDFs use `UiLang` only, so they read exactly like the screen. PDFs render right-to-left when the app locale is `ar` (`PdfService::stream($html, $filename, app()->getLocale() === 'ar', ...)`).
- Ambiguities in the spec resolved by this plan (report them to the owner when 2b is done):
  1. **Expected** = the player's marks in held sessions of the period. Cancelled sessions (marks kept) and planned sessions never count.
  2. **Category filter**: a mark belongs to category C when C takes part in the session (pivot) **and**, for a joint pre-season session (several categories), the player is currently in C. In a single-category session every mark belongs to that category, guests included. So a joint session's marks are split between its categories, and category rows add up to the club total except for joint-session marks of players whose current category is not in that session.
  3. **Late minutes** = sum of minutes on `late` marks (raw, including lates the rules turn into absences). Left-early minutes are not reported.
  4. **Missed hours** = (absent_excused + absent_unexcused + not_training marks) × their session's length (end − start), shown with one decimal.
  5. **Score** = Σ points per mark, rules applied in this order, per player and period: (a) a `late` mark with minutes **strictly greater** than `late_minutes_as_absent` (when > 0) scores as `absent_unexcused`; (b) of the lates **left after (a)**, every full group of `lates_per_unexcused` (when > 0) adds (`absent_unexcused` points − `late` points) once. Left-early marks are never touched by the late rules. The club and category totals add the players' scores (the rules are never applied to a pooled count).
  6. **Score %** = score ÷ (expected × present points) × 100, clamped to 0–100, one decimal; `null` (shown "—") when nothing is expected or present points ≤ 0. Category rows show no score (the rules are per player).
  7. **Rankings**: top 5 and bottom 5 by score %, only players with **at least 5 expected sessions** and a score %. Ties: top = fewer unexcused, then fewer lates; bottom = more unexcused, then more lates; then player id. The bottom list never repeats a player from the top list (with 7 ranked players it shows 2).
  8. **Periods** reuse `ActivityPeriod` (month / season / custom). The stats page defaults to this month (as the activity pages do); the profile card defaults to the current season; pre-season progress uses the season of the period end.
  9. **Monthly series**: one entry per calendar month from the period start to its end, empty months included. Sessions per month count a joint session once (per category when filtered).
  10. **Profile card**: a JSON endpoint (`attendance.players.show`) fetched with `window.axios` after the profile paints, not an Inertia optional/deferred prop. `PlayerController@show` loads every relation and the transactions eagerly before rendering, so a partial reload for each period change would redo all of it; the endpoint also puts authorization on its own route name (attendance/view) instead of a hand-written check, keeps the profile URL free of card state, and is testable on its own. Its PDF is `attendance.players.report` with the same period.
  11. **Dashboard**: the card lives in the **members** tab (people-centric, loaded with the tab in the same request; the overview tab is finance-centric, visible to everyone and has a 13-query budget). It needs `attendance.view` on top of the tab's `players.view`; users without players/view use the Attendance pages instead. Fixed windows: today, and the last 30 days (today − 29 → today); the dashboard's range and branch filters do not apply. When a category trains today by its weekly schedule but has no session yet (nobody opened that month in the calendar), the card generates that category's month first (idempotent, same generator as the calendar), unless a club closure covers today.
  12. The stats page links players to their profile only for users with `players.view`.
  13. The stats PDF is A4 landscape (`PdfService` gains an optional `$landscape` flag); the player PDF is A4 portrait.

---

## File map

```
app/Services/Attendance/AttendanceStats.php        (new) every number: players, totals, months, categories, player sessions, ranking
app/Services/Attendance/PreseasonProgress.php      + forCategories() (every category in 3 queries)
app/Services/Dashboard/AttendanceCard.php          (new) today's sessions + last 30 days
app/Services/Pdf/ClubHeader.php                    (new) club block for pdf.partials.header
app/Services/Pdf/PdfService.php                    + optional $landscape
app/Support/AttendanceSettings.php                 + labels()
app/Http/Controllers/AttendanceStatsController.php (new) attendance.stats, attendance.stats.export
app/Http/Controllers/AttendancePlayerController.php (new) attendance.players.show (JSON), attendance.players.report (PDF)
app/Http/Controllers/PlayerController.php          show(): attendanceCodes
app/Http/Controllers/DashboardController.php       members payload + attendanceCodes
routes/web.php                                     4 routes in the attendance block
config/permissions.php                             override attendance.stats => view
resources/views/pdf/attendance-stats.blade.php     (new)
resources/views/pdf/attendance-player.blade.php    (new)
resources/js/lib/attendanceStats.js                (new) chart/table helpers
resources/js/Components/Attendance/StatusBreakdown.vue     (new) proportional bar + legend
resources/js/Components/Activity/PeriodFilter.vue  href optional: emits `change` without it
resources/js/Components/StatDoughnut.vue           + unit prop
resources/js/Pages/Attendance/Stats.vue            (new)
resources/js/Pages/Attendance/Partials/StatsPlayerTable.vue (new)
resources/js/Pages/Attendance/Partials/StatsRanking.vue     (new)
resources/js/Pages/Attendance/Index.vue            header link to the stats page
resources/js/Pages/Players/Partials/AttendanceSection.vue   (new)
resources/js/Pages/Players/Show.vue                attendance card + prop
resources/js/Pages/Dashboard/Partials/AttendanceCard.vue    (new)
resources/js/Pages/Dashboard/Partials/MembersTab.vue        renders the card
resources/js/Pages/Dashboard.vue                   attendanceCodes prop
resources/js/i18n/{ar,fr,en}.json
tests: Unit/AttendanceScoreTest, Feature/AttendanceStatsTest, Feature/AttendanceStatsPageTest,
       Feature/AttendanceStatsExportTest, Feature/AttendancePlayerCardTest, Feature/AttendancePlayerReportTest,
       Feature/Dashboard/DashboardAttendanceCardTest;
       updated: Feature/AttendancePermissionTest, Feature/ExportMenuWiringTest, Feature/Dashboard/DashboardPageTest
```

---

### Task 1: `AttendanceStats` service

**Files:**
- Create: `app/Services/Attendance/AttendanceStats.php`
- Modify: `app/Services/Attendance/PreseasonProgress.php`
- Test: `tests/Unit/AttendanceScoreTest.php` (new), `tests/Feature/AttendanceStatsTest.php` (new)

**Interfaces:**
- Consumes: `AttendanceSettings::get()` (`points`, `rules`); tables `attendances`, `training_sessions`, `training_session_category`, `players`, `categories`, `preseason_targets`; `PreseasonProgress`; `Season`; enums `AttendanceStatus`, `SessionState`, `SessionKind`.
- Produces (`App\Services\Attendance\AttendanceStats`, resolved from the container; constructor `PreseasonProgress $preseason`):
  - `const RANKING_MIN_EXPECTED = 5`, `const RANKING_SIZE = 5`, `const MISSED = ['absent_excused', 'absent_unexcused', 'not_training']`.
  - A **row** is `array{player_id: int, expected: int, counts: array<string,int>, pct: array<string,?float>, late_minutes: int, missed_minutes: int, missed_hours: float, score: float, score_pct: ?float}`; `counts`/`pct` always hold the six statuses in `AttendanceStatus::values()` order.
  - `players(string $from, string $to, ?int $categoryId = null, ?int $playerId = null): array<int, row>` keyed and ordered by player id; players with no mark are absent.
  - `emptyRow(int $playerId): row` (zeros, `pct` all null, `score` 0.0, `score_pct` null).
  - `summarize(array $rows): array{expected, counts, pct, late_minutes, missed_minutes, missed_hours, score: float, score_pct: ?float, players: int}`.
  - `monthly(string $from, string $to, ?int $categoryId = null, ?int $playerId = null): array{labels: list<string>, statuses: array<string, list<int>>}` (labels `'Y-m'`).
  - `sessionsByMonth(string $from, string $to, ?int $categoryId = null): array{labels: list<string>, held: list<int>, cancelled: list<int>}`.
  - `categories(string $from, string $to): list<array{category_id: int, name: string, held: int, cancelled: int, preseason: ?array{season: string, done: int, target: ?int}, expected, counts, pct, late_minutes, missed_minutes, missed_hours}>` (every category, by id).
  - `playerSessions(int $playerId, string $from, string $to): list<array{session_id: int, date: string, start_time: string, end_time: string, kind: string, title: ?string, categories: list<string>, status: string, minutes: ?int, reason: ?string, note: ?string}>` newest first.
  - static `score(array $counts, int $longLates, array $points, array $rules): float`, `scorePct(float $score, int $expected, float $presentPoints): ?float`, `duration(string $start, string $end): int` (minutes), `months(string $from, string $to): list<string>`, `ranking(array $rows): array{min_expected: int, top: list<row>, bottom: list<row>}` (rows may carry extra keys; they are kept).
- Produces: `PreseasonProgress::forCategories(DateTimeInterface|string $date): array<int, array{season: string, done: int, target: ?int}>` keyed by category id, every category.

- [ ] **Step 1: Write the failing unit test (pure maths)**

`tests/Unit/AttendanceScoreTest.php`:
```php
<?php

namespace Tests\Unit;

use App\Enums\AttendanceStatus;
use App\Services\Attendance\AttendanceStats;
use App\Support\AttendanceSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AttendanceScoreTest extends TestCase
{
    /** present 1, late 0.75, left_early 0.75, not_training 0.5, absent_excused 0, absent_unexcused -1 */
    private const POINTS = AttendanceSettings::DEFAULTS['points'];

    /** 3 lates = 1 unexcused; a late over 30 minutes = unexcused */
    private const RULES = AttendanceSettings::DEFAULTS['rules'];

    private const OFF = ['lates_per_unexcused' => 0, 'late_minutes_as_absent' => 0];

    private static function counts(array $counts): array
    {
        return $counts + array_fill_keys(AttendanceStatus::values(), 0);
    }

    private static function row(int $id, int $expected, ?float $pct, int $unexcused = 0, int $late = 0): array
    {
        return [
            'player_id' => $id, 'expected' => $expected, 'score_pct' => $pct,
            'counts' => self::counts(['absent_unexcused' => $unexcused, 'late' => $late]),
        ];
    }

    #[Test]
    public function points_add_up_per_status(): void
    {
        $counts = self::counts(['present' => 4, 'left_early' => 2, 'not_training' => 1, 'absent_excused' => 1, 'absent_unexcused' => 1]);

        // 4 + 1.5 + 0.5 + 0 - 1
        $this->assertSame(5.0, AttendanceStats::score($counts, 0, self::POINTS, self::RULES));
    }

    #[Test]
    public function a_long_late_scores_as_an_unexcused_absence(): void
    {
        $counts = self::counts(['present' => 1, 'late' => 2]);

        // One of the two lates is over the threshold: 1 + 0.75 - 1.
        $this->assertSame(0.75, AttendanceStats::score($counts, 1, self::POINTS, self::RULES));
        // Rules off: 1 + 2 x 0.75.
        $this->assertSame(2.5, AttendanceStats::score($counts, 0, self::POINTS, self::OFF));
    }

    #[Test]
    public function every_full_group_of_lates_turns_one_late_into_an_unexcused_absence(): void
    {
        // Seven lates, groups of three: two groups; the seventh stays a late.
        // 7 x 0.75 + 2 x (-1 - 0.75)
        $this->assertSame(1.75, AttendanceStats::score(self::counts(['late' => 7]), 0, self::POINTS, self::RULES));
        $this->assertSame(1.5, AttendanceStats::score(self::counts(['late' => 2]), 0, self::POINTS, self::RULES));
        $this->assertSame(5.25, AttendanceStats::score(self::counts(['late' => 7]), 0, self::POINTS, self::OFF));
    }

    #[Test]
    public function lates_already_scored_as_absences_are_not_grouped_again(): void
    {
        // Seven lates, one of them long: it scores -1 on its own; the six left make two groups.
        // 6 x 0.75 - 1 + 2 x (-1.75) = 0
        $this->assertSame(0.0, AttendanceStats::score(self::counts(['late' => 7]), 1, self::POINTS, self::RULES));
        // Three lates, one long: two remain, no full group. 2 x 0.75 - 1
        $this->assertSame(0.5, AttendanceStats::score(self::counts(['late' => 3]), 1, self::POINTS, self::RULES));
    }

    #[Test]
    public function left_early_marks_are_never_touched_by_the_late_rules(): void
    {
        $this->assertSame(4.5, AttendanceStats::score(self::counts(['left_early' => 6]), 0, self::POINTS, self::RULES));
    }

    #[Test]
    public function score_percent_is_clamped_and_null_when_it_cannot_be_computed(): void
    {
        $this->assertSame(50.0, AttendanceStats::scorePct(5.0, 10, 1.0));
        $this->assertSame(33.3, AttendanceStats::scorePct(1.0, 3, 1.0));
        $this->assertSame(25.0, AttendanceStats::scorePct(1.0, 2, 2.0));   // present worth 2: the maximum is 4
        $this->assertSame(0.0, AttendanceStats::scorePct(-4.0, 4, 1.0));   // below zero: clamped
        $this->assertSame(100.0, AttendanceStats::scorePct(8.0, 4, 1.0));  // a status worth more than present: clamped
        $this->assertNull(AttendanceStats::scorePct(0.0, 0, 1.0));         // nothing expected
        $this->assertNull(AttendanceStats::scorePct(3.0, 3, 0.0));         // present worth nothing
        $this->assertNull(AttendanceStats::scorePct(3.0, 3, -1.0));
    }

    #[Test]
    public function session_length_and_months(): void
    {
        $this->assertSame(90, AttendanceStats::duration('18:00', '19:30'));
        $this->assertSame(60, AttendanceStats::duration('09:15', '10:15'));
        $this->assertSame(0, AttendanceStats::duration('19:30', '18:00'));

        $this->assertSame(['2026-10'], AttendanceStats::months('2026-10-01', '2026-10-31'));
        $this->assertSame(['2026-11', '2026-12', '2027-01', '2027-02'], AttendanceStats::months('2026-11-15', '2027-02-03'));
    }

    #[Test]
    public function the_ranking_keeps_players_with_enough_sessions_and_breaks_ties(): void
    {
        $rows = [
            self::row(1, 10, 100.0),
            self::row(2, 10, 80.0),
            self::row(3, 5, 80.0, late: 2),     // same % as 2 but more lates: after 2
            self::row(4, 6, 50.0, unexcused: 1),
            self::row(5, 6, 50.0, unexcused: 2),
            self::row(6, 8, 10.0, unexcused: 3),
            self::row(7, 8, 10.0, unexcused: 5),
            self::row(8, 4, 100.0),             // 4 sessions: below the minimum
            self::row(9, 10, null),             // no score %
        ];

        $ranking = AttendanceStats::ranking($rows);

        $this->assertSame(5, $ranking['min_expected']);
        $this->assertSame([1, 2, 3, 4, 5], array_column($ranking['top'], 'player_id'));
        // The bottom list never repeats the top one; ties: more unexcused first.
        $this->assertSame([7, 6], array_column($ranking['bottom'], 'player_id'));

        $few = AttendanceStats::ranking([self::row(1, 5, 90.0), self::row(2, 5, 40.0)]);
        $this->assertSame([1, 2], array_column($few['top'], 'player_id'));
        $this->assertSame([], $few['bottom']);
    }
}
```

- [ ] **Step 2: Write the failing feature test (queries)**

`tests/Feature/AttendanceStatsTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\PreseasonTarget;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceStats;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceStatsTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    /** A session; $others join it through the pivot (a joint pre-season session). */
    private function training(
        Category $category,
        string $date,
        string $start = '18:00',
        string $end = '19:30',
        SessionState $state = SessionState::Held,
        SessionKind $kind = SessionKind::Regular,
        array $others = [],
    ): TrainingSession {
        $training = TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => $start, 'end_time' => $end,
            'kind' => $kind, 'state' => $state,
        ]);
        foreach ($others as $other) {
            DB::table('training_session_category')->insert(['training_session_id' => $training->id, 'category_id' => $other->id]);
        }

        return $training;
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status, ?int $minutes = null, ?string $reason = null, ?string $note = null): void
    {
        Attendance::create([
            'training_session_id' => $training->id, 'player_id' => $player->id,
            'status' => $status, 'minutes' => $minutes, 'reason' => $reason, 'note' => $note,
        ]);
    }

    private function stats(): AttendanceStats
    {
        return app(AttendanceStats::class);
    }

    #[Test]
    public function counts_percentages_minutes_and_hours_come_from_held_sessions_only(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $b = $this->player($u15);

        $s1 = $this->training($u15, '2026-10-05', '18:00', '19:30');
        $this->mark($s1, $a, AttendanceStatus::Present);
        $this->mark($s1, $b, AttendanceStatus::Late, 10);
        $s2 = $this->training($u15, '2026-10-07', '18:00', '19:00');
        $this->mark($s2, $a, AttendanceStatus::AbsentUnexcused);
        $this->mark($s2, $b, AttendanceStatus::AbsentExcused, reason: 'illness');
        $s3 = $this->training($u15, '2026-10-09', '18:00', '20:00');
        $this->mark($s3, $a, AttendanceStatus::NotTraining, reason: 'injury');
        $this->mark($s3, $b, AttendanceStatus::Late, 40);
        // Never counted: a cancelled session (its marks are kept), a planned
        // one, and a held session outside the period.
        $this->mark($this->training($u15, '2026-10-12', state: SessionState::Cancelled), $a, AttendanceStatus::Present);
        $this->mark($this->training($u15, '2026-10-14', state: SessionState::Planned), $a, AttendanceStatus::Present);
        $this->mark($this->training($u15, '2026-11-02'), $a, AttendanceStatus::Present);

        $rows = $this->stats()->players('2026-10-01', '2026-10-31');

        $this->assertSame([$a->id, $b->id], array_keys($rows));

        $ra = $rows[$a->id];
        $this->assertSame(3, $ra['expected']);
        $this->assertSame(
            ['present' => 1, 'late' => 0, 'left_early' => 0, 'not_training' => 1, 'absent_excused' => 0, 'absent_unexcused' => 1],
            $ra['counts'],
        );
        $this->assertSame(33.3, $ra['pct']['present']);
        $this->assertSame(0.0, $ra['pct']['late']);
        $this->assertSame(0, $ra['late_minutes']);
        $this->assertSame(180, $ra['missed_minutes']);   // 60 (unexcused) + 120 (not training)
        $this->assertSame(3.0, $ra['missed_hours']);
        $this->assertSame(0.5, $ra['score']);            // 1 - 1 + 0.5
        $this->assertSame(16.7, $ra['score_pct']);       // 0.5 / (3 x 1)

        $rb = $rows[$b->id];
        $this->assertSame(2, $rb['counts']['late']);     // raw marks: the 40-minute late stays a late
        $this->assertSame(1, $rb['counts']['absent_excused']);
        $this->assertSame(50, $rb['late_minutes']);
        $this->assertSame(1.0, $rb['missed_hours']);
        $this->assertSame(-0.25, $rb['score']);          // 0.75, then 40 > 30 scores -1, then 0
        $this->assertSame(0.0, $rb['score_pct']);        // clamped at 0

        $totals = $this->stats()->summarize($rows);
        $this->assertSame(6, $totals['expected']);
        $this->assertSame(2, $totals['counts']['late']);
        $this->assertSame(50, $totals['late_minutes']);
        $this->assertSame(4.0, $totals['missed_hours']);  // 180 + 60 minutes
        $this->assertSame(0.25, $totals['score']);        // 0.5 - 0.25
        $this->assertSame(4.2, $totals['score_pct']);     // 0.25 / 6
        $this->assertSame(2, $totals['players']);
    }

    #[Test]
    public function the_discipline_rules_change_the_score_and_never_the_counts(): void
    {
        $u15 = $this->category();
        $c = $this->player($u15);
        foreach (range(1, 6) as $day) {
            $this->mark($this->training($u15, sprintf('2026-10-%02d', $day)), $c, AttendanceStatus::Late, 5);
        }
        $this->mark($this->training($u15, '2026-10-07'), $c, AttendanceStatus::Late, 45);

        $row = $this->stats()->players('2026-10-01', '2026-10-31')[$c->id];
        $this->assertSame(7, $row['counts']['late']);
        $this->assertSame(0, $row['counts']['absent_unexcused']);
        $this->assertSame(75, $row['late_minutes']);
        // The 45-minute late scores -1; the six left make two groups of three:
        // 6 x 0.75 - 1 + 2 x (-1.75) = 0.
        $this->assertSame(0.0, $row['score']);
        $this->assertSame(0.0, $row['score_pct']);

        AttendanceSettings::save(['rules' => ['lates_per_unexcused' => 0, 'late_minutes_as_absent' => 0]]);

        $row = $this->stats()->players('2026-10-01', '2026-10-31')[$c->id];
        $this->assertSame(7, $row['counts']['late']);
        $this->assertSame(5.25, $row['score']);           // 7 x 0.75, rules off
        $this->assertSame(75.0, $row['score_pct']);
    }

    #[Test]
    public function a_joint_session_is_split_by_category_and_a_guest_stays_with_the_session(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $x = $this->player($u15);
        $y = $this->player($u17);
        $joint = $this->training($u15, '2026-10-03', kind: SessionKind::Preseason, others: [$u17]);
        $this->mark($joint, $x, AttendanceStatus::Present);
        $this->mark($joint, $y, AttendanceStatus::Late, 5);
        $own = $this->training($u15, '2026-10-05');   // U15 only; Y came as a guest
        $this->mark($own, $x, AttendanceStatus::Present);
        $this->mark($own, $y, AttendanceStatus::Present);
        $this->training($u17, '2026-10-06', state: SessionState::Cancelled);
        PreseasonTarget::create(['category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 12]);

        $all = $this->stats()->players('2026-10-01', '2026-10-31');
        $this->assertSame(2, $all[$x->id]['expected']);
        $this->assertSame(2, $all[$y->id]['expected']);

        $u15Rows = $this->stats()->players('2026-10-01', '2026-10-31', $u15->id);
        $this->assertSame(2, $u15Rows[$x->id]['expected']);
        $this->assertSame(1, $u15Rows[$y->id]['expected']);   // the guest mark only; the joint mark is U17's
        $this->assertSame(0, $u15Rows[$y->id]['counts']['late']);

        $u17Rows = $this->stats()->players('2026-10-01', '2026-10-31', $u17->id);
        $this->assertSame([$y->id], array_keys($u17Rows));
        $this->assertSame(1, $u17Rows[$y->id]['counts']['late']);

        $categories = collect($this->stats()->categories('2026-10-01', '2026-10-31'))->keyBy('category_id');
        $this->assertSame(2, $categories[$u15->id]['held']);     // the joint session counts for both
        $this->assertSame(0, $categories[$u15->id]['cancelled']);
        $this->assertSame(3, $categories[$u15->id]['expected']);
        $this->assertSame(1, $categories[$u17->id]['held']);
        $this->assertSame(1, $categories[$u17->id]['cancelled']);
        $this->assertSame(1, $categories[$u17->id]['expected']);
        $this->assertSame(5, $categories[$u17->id]['late_minutes']);
        $this->assertSame(['season' => '2026/27', 'done' => 1, 'target' => 12], $categories[$u15->id]['preseason']);
        $this->assertSame(['season' => '2026/27', 'done' => 1, 'target' => null], $categories[$u17->id]['preseason']);
    }

    #[Test]
    public function months_carry_the_marks_by_status_and_the_sessions_held_and_cancelled(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $this->mark($this->training($u15, '2026-10-05'), $a, AttendanceStatus::Present);
        $this->mark($this->training($u15, '2026-10-07'), $a, AttendanceStatus::Late, 5);
        $this->mark($this->training($u15, '2026-12-02'), $a, AttendanceStatus::AbsentUnexcused);
        $this->training($u15, '2026-10-09', state: SessionState::Cancelled);
        $this->training($u15, '2026-10-12', state: SessionState::Planned);

        $monthly = $this->stats()->monthly('2026-10-01', '2026-12-31');
        $this->assertSame(['2026-10', '2026-11', '2026-12'], $monthly['labels']);
        $this->assertSame([1, 0, 0], $monthly['statuses']['present']);
        $this->assertSame([1, 0, 0], $monthly['statuses']['late']);
        $this->assertSame([0, 0, 1], $monthly['statuses']['absent_unexcused']);
        $this->assertSame([0, 0, 0], $monthly['statuses']['left_early']);

        $sessions = $this->stats()->sessionsByMonth('2026-10-01', '2026-12-31');
        $this->assertSame(['2026-10', '2026-11', '2026-12'], $sessions['labels']);
        $this->assertSame([2, 0, 1], $sessions['held']);
        $this->assertSame([1, 0, 0], $sessions['cancelled']);

        $this->assertSame([0, 0, 1], $this->stats()->monthly('2026-10-01', '2026-12-31', null, $a->id)['statuses']['absent_unexcused']);
    }

    #[Test]
    public function a_players_sessions_list_newest_first_with_every_category(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $x = $this->player($u15);
        $joint = $this->training($u17, '2026-10-03', kind: SessionKind::Preseason, others: [$u15]);
        $joint->update(['title' => 'Running 7.2 km']);
        $this->mark($joint, $x, AttendanceStatus::Late, 12, note: 'Bus');
        $this->mark($this->training($u15, '2026-10-05'), $x, AttendanceStatus::AbsentExcused, reason: 'illness');

        $sessions = $this->stats()->playerSessions($x->id, '2026-10-01', '2026-10-31');

        $this->assertCount(2, $sessions);
        $this->assertSame('2026-10-05', $sessions[0]['date']);
        $this->assertSame('absent_excused', $sessions[0]['status']);
        $this->assertSame('illness', $sessions[0]['reason']);
        $this->assertSame(['U15'], $sessions[0]['categories']);
        $this->assertSame('Running 7.2 km', $sessions[1]['title']);
        $this->assertSame('preseason', $sessions[1]['kind']);
        $this->assertSame(['U17', 'U15'], $sessions[1]['categories']);   // primary first
        $this->assertSame(12, $sessions[1]['minutes']);
        $this->assertSame('Bus', $sessions[1]['note']);
    }

    #[Test]
    public function the_query_count_does_not_grow_with_the_roster(): void
    {
        $u15 = $this->category();
        $trainings = [$this->training($u15, '2026-10-05'), $this->training($u15, '2026-10-07')];
        $addPlayers = function (int $n) use ($u15, $trainings): void {
            foreach (range(1, $n) as $i) {
                $player = $this->player($u15);
                foreach ($trainings as $training) {
                    $this->mark($training, $player, AttendanceStatus::Present);
                }
            }
        };
        $measure = function (): int {
            $stats = $this->stats();
            $stats->players('2026-10-01', '2026-10-31');   // loads the settings once
            DB::flushQueryLog();
            DB::enableQueryLog();
            $stats->players('2026-10-01', '2026-10-31');
            $stats->monthly('2026-10-01', '2026-10-31');
            $stats->categories('2026-10-01', '2026-10-31');
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $addPlayers(3);
        $few = $measure();
        $addPlayers(30);

        $this->assertSame($few, $measure());
        $this->assertLessThanOrEqual(10, $few);
    }
}
```

- [ ] **Step 3: Run the tests to see them fail**

Run: `php artisan test --filter="AttendanceScoreTest|AttendanceStatsTest"`
Expected: FAIL — `Class "App\Services\Attendance\AttendanceStats" not found`.

- [ ] **Step 4: Add `forCategories()` to `PreseasonProgress`**

In `app/Services/Attendance/PreseasonProgress.php`, add the import next to the others:
```php
use Illuminate\Support\Facades\DB;
```
and add this method right after `forCategory()`:
```php
    /**
     * Every category's progress for the season of $date in three queries
     * (the statistics page lists all categories at once).
     *
     * @return array<int, array{season: string, done: int, target: ?int}> keyed by category id
     */
    public function forCategories(DateTimeInterface|string $date): array
    {
        $season = Season::forDate($date);

        $done = DB::table('training_session_category')
            ->join('training_sessions', 'training_sessions.id', '=', 'training_session_category.training_session_id')
            ->where('training_sessions.kind', SessionKind::Preseason->value)
            ->where('training_sessions.state', SessionState::Held->value)
            ->whereBetween('training_sessions.date', [$season->start()->toDateString(), $season->end()->toDateString()])
            ->select('training_session_category.category_id')
            ->selectRaw('count(*) as total')
            ->groupBy('training_session_category.category_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->category_id => (int) $row->total]);

        $targets = PreseasonTarget::where('season_start_year', $season->startYear)
            ->get(['category_id', 'target_count'])
            ->mapWithKeys(fn (PreseasonTarget $target) => [(int) $target->category_id => (int) $target->target_count]);

        return Category::orderBy('id')->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => [
                'season' => $season->label(),
                'done' => $done[(int) $id] ?? 0,
                'target' => $targets[(int) $id] ?? null,
            ]])
            ->all();
    }
```

- [ ] **Step 5: Create the service**

`app/Services/Attendance/AttendanceStats.php`:
```php
<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\Category;
use App\Support\AttendanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every attendance number in one place: per player, per category, per month.
 *
 * Only held sessions count: a cancelled session keeps its marks but is
 * ignored, a planned one has none. "Expected" is the number of marks a player
 * has in those sessions, so later roster changes never rewrite history.
 * Counts are the raw marks; the discipline rules change the score only.
 *
 * Every method runs a fixed number of grouped queries, whatever the roster
 * size, with the query builder only (same SQL on sqlite and MySQL).
 *
 * Category filter: a mark belongs to category C when C takes part in the
 * session and, for a joint pre-season session, the player is currently in C.
 * A single-category session keeps every mark in it, guests included.
 */
final class AttendanceStats
{
    /** Players expected at fewer sessions than this are not ranked. */
    public const RANKING_MIN_EXPECTED = 5;

    public const RANKING_SIZE = 5;

    /** Statuses that cost the player the session's time (missed hours). */
    public const MISSED = ['absent_excused', 'absent_unexcused', 'not_training'];

    private ?array $settings = null;

    public function __construct(private readonly PreseasonProgress $preseason) {}

    /** @return array<int, array<string, mixed>> keyed and ordered by player id */
    public function players(string $from, string $to, ?int $categoryId = null, ?int $playerId = null): array
    {
        $settings = $this->settings();
        $longLate = max(0, (int) $settings['rules']['late_minutes_as_absent']);

        $grouped = $this->marks($from, $to, $categoryId, $playerId)
            ->select('attendances.player_id', 'attendances.status')
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(attendances.minutes), 0) as minutes')
            ->selectRaw('sum(case when attendances.minutes > ? then 1 else 0 end) as long_marks', [$longLate])
            ->groupBy('attendances.player_id', 'attendances.status')
            ->get();

        $counts = [];
        $lateMinutes = [];
        $longLates = [];
        foreach ($grouped as $row) {
            $id = (int) $row->player_id;
            $counts[$id][$row->status] = (int) $row->total;
            if ($row->status === AttendanceStatus::Late->value) {
                $lateMinutes[$id] = (int) $row->minutes;
                $longLates[$id] = $longLate > 0 ? (int) $row->long_marks : 0;
            }
        }
        ksort($counts);

        $missed = $this->missedMinutes($this->marks($from, $to, $categoryId, $playerId), 'attendances.player_id');
        $points = $settings['points'];

        $rows = [];
        foreach ($counts as $id => $byStatus) {
            $base = $this->base($byStatus, $lateMinutes[$id] ?? 0, $missed[$id] ?? 0);
            $score = self::score($base['counts'], $longLates[$id] ?? 0, $points, $settings['rules']);
            $rows[$id] = [
                'player_id' => $id,
                ...$base,
                'score' => $score,
                'score_pct' => self::scorePct($score, $base['expected'], (float) $points['present']),
            ];
        }

        return $rows;
    }

    /** A player with no mark in the period. */
    public function emptyRow(int $playerId): array
    {
        return ['player_id' => $playerId, ...$this->base([], 0, 0), 'score' => 0.0, 'score_pct' => null];
    }

    /** Totals over players() rows; the score adds each player's own score. */
    public function summarize(array $rows): array
    {
        $counts = array_fill_keys(AttendanceStatus::values(), 0);
        $lateMinutes = 0;
        $missedMinutes = 0;
        $score = 0.0;
        foreach ($rows as $row) {
            foreach ($counts as $status => $n) {
                $counts[$status] = $n + $row['counts'][$status];
            }
            $lateMinutes += $row['late_minutes'];
            $missedMinutes += $row['missed_minutes'];
            $score += $row['score'];
        }

        $base = $this->base($counts, $lateMinutes, $missedMinutes);
        $score = round($score, 2);

        return [
            ...$base,
            'score' => $score,
            'score_pct' => self::scorePct($score, $base['expected'], (float) $this->settings()['points']['present']),
            'players' => count($rows),
        ];
    }

    /** @return array{labels: list<string>, statuses: array<string, list<int>>} marks per month and status */
    public function monthly(string $from, string $to, ?int $categoryId = null, ?int $playerId = null): array
    {
        $labels = self::months($from, $to);
        $index = array_flip($labels);
        $statuses = array_fill_keys(AttendanceStatus::values(), array_fill(0, count($labels), 0));

        $rows = $this->marks($from, $to, $categoryId, $playerId)
            ->selectRaw('substr(training_sessions.date, 1, 7) as ym')
            ->addSelect('attendances.status')
            ->selectRaw('count(*) as total')
            ->groupByRaw('substr(training_sessions.date, 1, 7), attendances.status')
            ->get();

        foreach ($rows as $row) {
            if (isset($index[$row->ym], $statuses[$row->status])) {
                $statuses[$row->status][$index[$row->ym]] = (int) $row->total;
            }
        }

        return ['labels' => $labels, 'statuses' => $statuses];
    }

    /** @return array{labels: list<string>, held: list<int>, cancelled: list<int>} a joint session counts once */
    public function sessionsByMonth(string $from, string $to, ?int $categoryId = null): array
    {
        $labels = self::months($from, $to);
        $index = array_flip($labels);
        $held = SessionState::Held->value;
        $cancelled = SessionState::Cancelled->value;
        $series = [$held => array_fill(0, count($labels), 0), $cancelled => array_fill(0, count($labels), 0)];

        $rows = DB::table('training_sessions')
            ->whereBetween('training_sessions.date', [$from, $to])
            ->whereIn('training_sessions.state', [$held, $cancelled])
            ->when($categoryId !== null, fn (Builder $q) => $q->whereIn('training_sessions.id', $this->sessionsOf($categoryId)))
            ->selectRaw('substr(training_sessions.date, 1, 7) as ym')
            ->addSelect('training_sessions.state')
            ->selectRaw('count(*) as total')
            ->groupByRaw('substr(training_sessions.date, 1, 7), training_sessions.state')
            ->get();

        foreach ($rows as $row) {
            if (isset($index[$row->ym])) {
                $series[$row->state][$index[$row->ym]] = (int) $row->total;
            }
        }

        return ['labels' => $labels, 'held' => $series[$held], 'cancelled' => $series[$cancelled]];
    }

    /** @return list<array<string, mixed>> one row per category, by id */
    public function categories(string $from, string $to): array
    {
        // Each mark once per category it belongs to (see the class comment).
        $attributed = fn (): Builder => DB::table('attendances')
            ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
            ->join('training_session_category', 'training_session_category.training_session_id', '=', 'attendances.training_session_id')
            ->join('players', 'players.id', '=', 'attendances.player_id')
            ->where('training_sessions.state', SessionState::Held->value)
            ->whereBetween('training_sessions.date', [$from, $to])
            ->where(fn (Builder $q) => $q
                ->whereColumn('training_session_category.category_id', 'players.category_id')
                ->orWhereIn('attendances.training_session_id', $this->singleCategorySessions()));

        $counts = [];
        $lateMinutes = [];
        $grouped = $attributed()
            ->select('training_session_category.category_id', 'attendances.status')
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(attendances.minutes), 0) as minutes')
            ->groupBy('training_session_category.category_id', 'attendances.status')
            ->get();
        foreach ($grouped as $row) {
            $counts[(int) $row->category_id][$row->status] = (int) $row->total;
            if ($row->status === AttendanceStatus::Late->value) {
                $lateMinutes[(int) $row->category_id] = (int) $row->minutes;
            }
        }
        $missed = $this->missedMinutes($attributed(), 'training_session_category.category_id');

        $sessions = [];
        $byState = DB::table('training_sessions')
            ->join('training_session_category', 'training_session_category.training_session_id', '=', 'training_sessions.id')
            ->whereBetween('training_sessions.date', [$from, $to])
            ->whereIn('training_sessions.state', [SessionState::Held->value, SessionState::Cancelled->value])
            ->select('training_session_category.category_id', 'training_sessions.state')
            ->selectRaw('count(*) as total')
            ->groupBy('training_session_category.category_id', 'training_sessions.state')
            ->get();
        foreach ($byState as $row) {
            $sessions[(int) $row->category_id][$row->state] = (int) $row->total;
        }

        $preseason = $this->preseason->forCategories($to);

        return Category::orderBy('id')->get()
            ->map(fn (Category $category) => [
                'category_id' => $category->id,
                'name' => $category->localized_name,
                'held' => $sessions[$category->id][SessionState::Held->value] ?? 0,
                'cancelled' => $sessions[$category->id][SessionState::Cancelled->value] ?? 0,
                'preseason' => $preseason[$category->id] ?? null,
                ...$this->base($counts[$category->id] ?? [], $lateMinutes[$category->id] ?? 0, $missed[$category->id] ?? 0),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> the player's marks in held sessions, newest first */
    public function playerSessions(int $playerId, string $from, string $to): array
    {
        $marks = $this->marks($from, $to, null, $playerId)
            ->orderByDesc('training_sessions.date')
            ->orderByDesc('training_sessions.start_time')
            ->orderByDesc('training_sessions.id')
            ->get([
                'training_sessions.id', 'training_sessions.date', 'training_sessions.start_time', 'training_sessions.end_time',
                'training_sessions.kind', 'training_sessions.title', 'training_sessions.category_id',
                'attendances.status', 'attendances.minutes', 'attendances.reason', 'attendances.note',
            ]);
        if ($marks->isEmpty()) {
            return [];
        }

        $links = DB::table('training_session_category')
            ->whereIn('training_session_id', $marks->pluck('id'))
            ->get(['training_session_id', 'category_id']);
        $names = Category::whereIn('id', $links->pluck('category_id')->unique())->get()
            ->mapWithKeys(fn (Category $category) => [$category->id => $category->localized_name]);
        $bySession = $links->groupBy('training_session_id');

        return $marks->map(fn ($mark) => [
            'session_id' => (int) $mark->id,
            'date' => $mark->date,
            'start_time' => $mark->start_time,
            'end_time' => $mark->end_time,
            'kind' => $mark->kind,
            'title' => $mark->title,
            // Primary category first, then by id (as TrainingSession::orderedCategories()).
            'categories' => collect($bySession[$mark->id] ?? [])
                ->sortBy(fn ($link) => (int) $link->category_id === (int) $mark->category_id ? 0 : (int) $link->category_id)
                ->map(fn ($link) => $names[(int) $link->category_id] ?? '')
                ->values()
                ->all(),
            'status' => $mark->status,
            'minutes' => $mark->minutes === null ? null : (int) $mark->minutes,
            'reason' => $mark->reason,
            'note' => $mark->note,
        ])->values()->all();
    }

    /**
     * Σ points per mark with the discipline rules applied, in this order:
     * (1) a late over `late_minutes_as_absent` minutes scores as an unexcused
     * absence ($longLates counts them); (2) of the lates left after (1),
     * every full group of `lates_per_unexcused` turns one late into an
     * unexcused absence. A rule set to 0 is off. Left-early marks are never
     * touched.
     *
     * @param  array<string, int>  $counts  raw marks per status
     */
    public static function score(array $counts, int $longLates, array $points, array $rules): float
    {
        $late = (int) ($counts['late'] ?? 0);
        $longLates = min(max(0, $longLates), $late);
        $effective = $counts;
        $effective['late'] = $late - $longLates;
        $effective['absent_unexcused'] = (int) ($counts['absent_unexcused'] ?? 0) + $longLates;

        $score = 0.0;
        foreach (AttendanceStatus::values() as $status) {
            $score += (float) ($points[$status] ?? 0) * (int) ($effective[$status] ?? 0);
        }

        $perGroup = (int) ($rules['lates_per_unexcused'] ?? 0);
        if ($perGroup > 0) {
            $score += intdiv($effective['late'], $perGroup) * ((float) $points['absent_unexcused'] - (float) $points['late']);
        }

        return round($score, 2);
    }

    /** score ÷ (expected × present points), 0–100 with one decimal; null when it cannot be computed. */
    public static function scorePct(float $score, int $expected, float $presentPoints): ?float
    {
        if ($expected <= 0 || $presentPoints <= 0) {
            return null;
        }

        return round(max(0.0, min(100.0, $score / ($expected * $presentPoints) * 100)), 1);
    }

    /** Minutes between two 'H:i' times; 0 if the end is not after the start. */
    public static function duration(string $start, string $end): int
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', $start));
        [$endHour, $endMinute] = array_map('intval', explode(':', $end));

        return max(0, ($endHour * 60 + $endMinute) - ($startHour * 60 + $startMinute));
    }

    /** @return list<string> every 'Y-m' month from $from's to $to's, both included */
    public static function months(string $from, string $to): array
    {
        $labels = [];
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $to)->startOfMonth();
        for ($month = CarbonImmutable::createFromFormat('!Y-m-d', $from)->startOfMonth(); $month->lessThanOrEqualTo($last); $month = $month->addMonth()) {
            $labels[] = $month->format('Y-m');
        }

        return $labels;
    }

    /**
     * Top and bottom RANKING_SIZE by score %, among players with at least
     * RANKING_MIN_EXPECTED expected sessions. Ties: top = fewer unexcused,
     * then fewer lates; bottom = more unexcused, then more lates; then id.
     * The bottom list never repeats a player from the top list.
     */
    public static function ranking(array $rows): array
    {
        $eligible = array_values(array_filter(
            $rows,
            fn (array $row) => $row['expected'] >= self::RANKING_MIN_EXPECTED && $row['score_pct'] !== null,
        ));

        $best = $eligible;
        usort($best, fn (array $a, array $b) => [$b['score_pct'], $a['counts']['absent_unexcused'], $a['counts']['late'], $a['player_id']]
            <=> [$a['score_pct'], $b['counts']['absent_unexcused'], $b['counts']['late'], $b['player_id']]);
        $top = array_slice($best, 0, self::RANKING_SIZE);
        $topIds = array_column($top, 'player_id');

        $worst = array_values(array_filter($eligible, fn (array $row) => ! in_array($row['player_id'], $topIds, true)));
        usort($worst, fn (array $a, array $b) => [$a['score_pct'], $b['counts']['absent_unexcused'], $b['counts']['late'], $a['player_id']]
            <=> [$b['score_pct'], $a['counts']['absent_unexcused'], $a['counts']['late'], $b['player_id']]);

        return [
            'min_expected' => self::RANKING_MIN_EXPECTED,
            'top' => $top,
            'bottom' => array_slice($worst, 0, self::RANKING_SIZE),
        ];
    }

    /** Marks in held sessions of the period, optionally one player's and/or one category's. */
    private function marks(string $from, string $to, ?int $categoryId, ?int $playerId): Builder
    {
        return DB::table('attendances')
            ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
            ->where('training_sessions.state', SessionState::Held->value)
            ->whereBetween('training_sessions.date', [$from, $to])
            ->when($playerId !== null, fn (Builder $q) => $q->where('attendances.player_id', $playerId))
            ->when($categoryId !== null, fn (Builder $q) => $q
                ->whereIn('attendances.training_session_id', $this->sessionsOf($categoryId))
                ->where(fn (Builder $w) => $w
                    ->whereIn('attendances.training_session_id', $this->singleCategorySessions())
                    ->orWhereIn('attendances.player_id', DB::table('players')->select('id')->where('category_id', $categoryId))));
    }

    /** Ids of the sessions a category takes part in (the pivot, so joint sessions too). */
    private function sessionsOf(int $categoryId): Builder
    {
        return DB::table('training_session_category')->select('training_session_id')->where('category_id', $categoryId);
    }

    /** Ids of the sessions with one category (all but joint pre-season sessions). */
    private function singleCategorySessions(): Builder
    {
        return DB::table('training_session_category')
            ->select('training_session_id')
            ->groupBy('training_session_id')
            ->havingRaw('count(*) = 1');
    }

    /** @return array<int, int> minutes missed (absences and not-training × session length) per group key */
    private function missedMinutes(Builder $marks, string $key): array
    {
        $rows = $marks->whereIn('attendances.status', self::MISSED)
            ->select($key.' as group_key', 'training_sessions.start_time', 'training_sessions.end_time')
            ->selectRaw('count(*) as total')
            ->groupBy($key, 'training_sessions.start_time', 'training_sessions.end_time')
            ->get();

        $minutes = [];
        foreach ($rows as $row) {
            $group = (int) $row->group_key;
            $minutes[$group] = ($minutes[$group] ?? 0) + (int) $row->total * self::duration($row->start_time, $row->end_time);
        }

        return $minutes;
    }

    /** Counts of all six statuses, % of expected, late minutes and missed time. */
    private function base(array $byStatus, int $lateMinutes, int $missedMinutes): array
    {
        $counts = [];
        foreach (AttendanceStatus::values() as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }
        $expected = array_sum($counts);

        return [
            'expected' => $expected,
            'counts' => $counts,
            'pct' => array_map(fn (int $n) => $expected > 0 ? round($n / $expected * 100, 1) : null, $counts),
            'late_minutes' => $lateMinutes,
            'missed_minutes' => $missedMinutes,
            'missed_hours' => round($missedMinutes / 60, 1),
        ];
    }

    private function settings(): array
    {
        return $this->settings ??= AttendanceSettings::get();
    }
}
```

- [ ] **Step 6: Run the tests to see them pass**

Run: `php artisan test --filter="AttendanceScoreTest|AttendanceStatsTest|AttendanceTimelineViewTest|AttendanceCalendarTest"`
Expected: PASS (the last two check `PreseasonProgress` is unchanged for the calendar).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Attendance/AttendanceStats.php app/Services/Attendance/PreseasonProgress.php tests/Unit/AttendanceScoreTest.php tests/Feature/AttendanceStatsTest.php
git commit -m "feat(attendance): AttendanceStats service (counts, hours, score with discipline rules, months, categories)" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Statistics page

**Files:**
- Create: `app/Http/Controllers/AttendanceStatsController.php`
- Create: `resources/js/lib/attendanceStats.js`, `resources/js/Components/Attendance/StatusBreakdown.vue`, `resources/js/Pages/Attendance/Stats.vue`, `resources/js/Pages/Attendance/Partials/StatsPlayerTable.vue`, `resources/js/Pages/Attendance/Partials/StatsRanking.vue`
- Modify: `routes/web.php`, `config/permissions.php`, `resources/js/Pages/Attendance/Index.vue`, `resources/js/i18n/{en,fr,ar}.json`
- Test: `tests/Feature/AttendanceStatsPageTest.php` (new); update `tests/Feature/AttendancePermissionTest.php`

**Interfaces:**
- Consumes: `AttendanceStats` (Task 1), `ActivityPeriod::fromRequest()` / `toArray()` (`{period, from, to, label}`), `AttendanceSettings::codes()`, `PeriodFilter.vue` (`period`, `href`, `keep`), `ChartCard.vue`, `chartTheme.js` (`baseOptions`, `barDataset`), `useAttendanceCodes()` (`statuses`, `label`, `color`), `useCan()`.
- Produces: route `GET /attendance/stats` named `attendance.stats` (query `period`, `from`, `to`, `category_id`), override `'attendance.stats' => ['attendance', 'view']`.
- Produces: Inertia page `Attendance/Stats` with props `period`, `categoryId: ?int`, `categories: list<{id, name}>`, `totals` (`summarize()` + `held`, `cancelled`), `monthly`, `sessionsByMonth`, `categoryRows`, `players` (rows + `name`, `membership_id`, `category`, sorted by name), `ranking`, `attendanceCodes`.
- Produces: `AttendanceStatsController::data(Request): array` (private, reused by Task 3).
- Produces JS: `resources/js/lib/attendanceStats.js` exports `monthTick(ym, locale)`, `statusBars(monthly, statuses, label, color, locale)`, `hasMarks(monthly)`, `pct(value)`, `hours(value)`, `periodQuery(period)`; component `@/Components/Attendance/StatusBreakdown.vue` (prop `counts`).
- Produces: i18n keys `att.statistics`, `att.stats_title`, `att.stats.by_month`, `att.stats.sessions_by_month`, `att.stats.by_category`, `att.stats.players`, `att.stats.top`, `att.stats.bottom`, `att.stats.ranking_help` (`{n}`), `att.stats.no_ranking`, `att.stats.no_data`, `att.stats.marks`, `att.stats.held_sessions`, `att.stats.cancelled_sessions`, `att.stats.score_help`, `att.col.expected`, `att.col.late_minutes`, `att.col.missed_hours`, `att.col.score`, `att.col.score_pct`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceStatsPageTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceStatsPageTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

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

    private function heldSession(Category $category, string $date): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status, ?int $minutes = null): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'status' => $status, 'minutes' => $minutes]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    #[Test]
    public function the_page_shows_this_months_figures_by_default(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $a = $this->player($u15);
        $b = $this->player($u17);
        $october = $this->heldSession($u15, '2026-10-05');
        $this->mark($october, $a, AttendanceStatus::Present);
        $this->mark($october, $b, AttendanceStatus::AbsentUnexcused);   // a guest in U15's session
        $this->mark($this->heldSession($u15, '2026-09-28'), $a, AttendanceStatus::Late, 5);   // last month

        $this->actingAs($this->admin())->get(route('attendance.stats'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Stats')
                ->where('period.period', 'month')
                ->where('period.from', '2026-10-01')
                ->where('period.to', '2026-10-31')
                ->where('categoryId', null)
                ->has('categories', 2)
                ->where('totals.expected', 2)
                ->where('totals.held', 1)
                ->where('totals.cancelled', 0)
                ->where('totals.counts.present', 1)
                ->where('totals.counts.late', 0)
                ->where('monthly.labels', ['2026-10'])
                ->where('monthly.statuses.absent_unexcused', [1])
                ->where('sessionsByMonth.held', [1])
                ->has('players', 2)
                ->where('players.0.player_id', $a->id)
                ->where('players.0.name', $a->fullname)
                ->where('players.0.category', 'U15')
                ->where('players.0.score_pct', 100.0)
                ->where('players.1.player_id', $b->id)
                ->where('players.1.category', 'U17')
                ->where('ranking.min_expected', 5)
                ->where('ranking.top', [])
                ->has('categoryRows', 2)
                ->where('categoryRows.0.held', 1)
                ->where('categoryRows.0.expected', 2)
                ->where('categoryRows.1.expected', 0)
                ->where('attendanceCodes.present.code', 'P'));
    }

    #[Test]
    public function a_category_and_a_custom_period_narrow_the_figures(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $a = $this->player($u15);
        $b = $this->player($u17);
        $own = $this->heldSession($u15, '2026-10-05');
        $this->mark($own, $a, AttendanceStatus::Present);
        $this->mark($own, $b, AttendanceStatus::Present);   // a guest: belongs to U15's figures
        $this->mark($this->heldSession($u17, '2026-09-15'), $b, AttendanceStatus::Late, 10);

        $this->actingAs($this->admin())
            ->get(route('attendance.stats', ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-10-31', 'category_id' => $u17->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.period', 'custom')
                ->where('period.label', '2026-09-01 – 2026-10-31')
                ->where('categoryId', $u17->id)
                ->where('monthly.labels', ['2026-09', '2026-10'])
                ->has('players', 1)
                ->where('players.0.player_id', $b->id)
                ->where('players.0.expected', 1)
                ->where('players.0.late_minutes', 10)
                ->where('sessionsByMonth.held', [1, 0]));
    }

    #[Test]
    public function the_page_needs_attendance_view(): void
    {
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.stats'));

        $this->actingAs($this->userWith(['attendance' => ['view']]))->get(route('attendance.stats'))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view']]))->get(route('attendance.stats'))->assertForbidden();
    }
}
```

In `tests/Feature/AttendancePermissionTest.php`, inside `attendance_is_a_module_and_its_routes_map_to_it()`, add after the `attendance.preseason-targets.store` assertion:
```php
        // 2b: statistics, their exports and the profile card only read.
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.stats'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.stats.export'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.players.show'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.players.report'));
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="AttendanceStatsPageTest|AttendancePermissionTest"`
Expected: FAIL — `Route [attendance.stats] not defined.` and `attendance.stats` resolving to `['attendance', 'edit']`.

- [ ] **Step 3: Route and permission**

In `routes/web.php`, add the import next to the other attendance controllers:
```php
use App\Http\Controllers\AttendanceStatsController;
```
and add this line in the attendance block right after the `attendance.index` route:
```php
        Route::get('/attendance/stats', [AttendanceStatsController::class, 'index'])->name('attendance.stats');
```

In `config/permissions.php`, add after the `'attendance.grid' => ['attendance', 'view'],` line:
```php
        // "stats" is not a view verb: reading the statistics must not need edit rights.
        'attendance.stats' => ['attendance', 'view'],
```

- [ ] **Step 4: The controller**

`app/Http/Controllers/AttendanceStatsController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Player;
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\AttendanceStats;
use App\Support\AttendanceSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Club-wide attendance statistics (`attendance.stats`, view): a period (this
 * month by default, the season, or a custom range) and an optional category.
 * Every number comes from AttendanceStats.
 */
class AttendanceStatsController extends Controller
{
    public function __construct(private readonly AttendanceStats $stats) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Attendance/Stats', [
            ...$this->data($request),
            'attendanceCodes' => AttendanceSettings::codes(),
        ]);
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        $validated = $request->validate(['category_id' => ['nullable', 'integer', 'exists:categories,id']]);
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $period = ActivityPeriod::fromRequest($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        $rows = $this->stats->players($from, $to, $categoryId);
        $players = $this->withNames($rows);
        $sessions = $this->stats->sessionsByMonth($from, $to, $categoryId);

        return [
            'period' => $period->toArray(),
            'categoryId' => $categoryId,
            'categories' => Category::orderBy('id')->get()
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->localized_name])
                ->values()->all(),
            'totals' => [
                ...$this->stats->summarize($rows),
                'held' => array_sum($sessions['held']),
                'cancelled' => array_sum($sessions['cancelled']),
            ],
            'monthly' => $this->stats->monthly($from, $to, $categoryId),
            'sessionsByMonth' => $sessions,
            'categoryRows' => $this->stats->categories($from, $to),
            'players' => $players,
            'ranking' => AttendanceStats::ranking($players),
        ];
    }

    /** Adds each player's name, membership id and current category; sorted by name. One query for all. */
    private function withNames(array $rows): array
    {
        $players = Player::with('category')
            ->whereIn('id', array_keys($rows))
            ->get(['id', 'firstname', 'lastname', 'nickname', 'father', 'grandfather', 'membership_id', 'category_id'])
            ->keyBy('id');

        return collect($rows)
            ->map(function (array $row) use ($players) {
                $player = $players->get($row['player_id']);

                return [
                    ...$row,
                    'name' => $player?->fullname ?? '#'.$row['player_id'],
                    'membership_id' => $player?->membership_id,
                    'category' => $player?->category?->localized_name,
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}
```

- [ ] **Step 5: Chart and table helpers**

`resources/js/lib/attendanceStats.js`:
```js
import { barDataset } from '@/lib/chartTheme';
import { parseDay } from '@/lib/attendanceCalendar';

/**
 * Shapes AttendanceStats payloads for charts and tables (statistics page,
 * profile card). Status names and colours are passed in from
 * useAttendanceCodes(); nothing here hard-codes them.
 */

/** Short month tick ("oct. 26") for a 'Y-m' key. */
export const monthTick = (ym, locale) => parseDay(`${ym}-01`).toLocaleDateString(locale, { month: 'short', year: '2-digit' });

/** One bar per month, one stacked segment per status, in the configured colours. */
export function statusBars(monthly, statuses, label, color, locale) {
    return {
        labels: (monthly?.labels ?? []).map((ym) => monthTick(ym, locale)),
        datasets: statuses.map((status) => barDataset(label(status), monthly?.statuses?.[status] ?? [], 0, { stack: 'marks', color: color(status) })),
    };
}

/** True when any month has at least one mark. */
export const hasMarks = (monthly) => Object.values(monthly?.statuses ?? {}).some((series) => series.some((n) => n > 0));

/** 33.3 → "33.3%"; null (nothing expected) → "—". Wrap in <bdi dir="ltr"> for Arabic. */
export const pct = (value) => (value === null || value === undefined ? '—' : `${Number(value).toFixed(1)}%`);

/** Hours with one decimal. */
export const hours = (value) => Number(value ?? 0).toFixed(1);

/** The period as it travels in a URL: from/to only for a custom range. */
export const periodQuery = (period) => (period?.period === 'custom'
    ? { period: 'custom', from: period.from, to: period.to }
    : { period: period?.period ?? 'month' });
```

`resources/js/Components/Attendance/StatusBreakdown.vue`:
```vue
<script setup>
import { computed } from 'vue';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';

/**
 * One proportional bar split by status, with a legend of counts and shares,
 * in the configured names and colours. Used by the statistics page and the
 * dashboard card.
 */
const props = defineProps({
    counts: { type: Object, default: () => ({}) }, // { status: number }
});
const { statuses, label, color } = useAttendanceCodes();

const total = computed(() => statuses.reduce((sum, s) => sum + (props.counts[s] ?? 0), 0));
const share = (s) => (total.value ? ((props.counts[s] ?? 0) / total.value) * 100 : 0);
const summary = computed(() => statuses.map((s) => `${label(s)}: ${props.counts[s] ?? 0}`).join(', '));
</script>

<template>
    <div class="space-y-2">
        <div class="flex h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="img" :aria-label="summary">
            <span
                v-for="s in statuses"
                v-show="counts[s]"
                :key="s"
                class="h-full"
                :style="{ width: `${share(s)}%`, backgroundColor: color(s) }"
                :title="`${label(s)}: ${counts[s] ?? 0}`"
            ></span>
        </div>
        <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
            <li v-for="s in statuses" :key="s" class="inline-flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: color(s) }"></span>
                <span class="text-slate-600 dark:text-slate-300">{{ label(s) }}</span>
                <span class="font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ counts[s] ?? 0 }}</span>
                <bdi dir="ltr" class="tabular-nums text-slate-400">{{ share(s).toFixed(1) }}%</bdi>
            </li>
        </ul>
    </div>
</template>
```

- [ ] **Step 6: The page and its partials**

`resources/js/Pages/Attendance/Partials/StatsPlayerTable.vue`:
```vue
<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';
import { hours, pct } from '@/lib/attendanceStats';

/** Every player of the period, sortable by any column (sorted in the browser: the server sends them all). */
const props = defineProps({
    rows: { type: Array, default: () => [] },
});
const { t, locale } = useI18n();
const { can } = useCan();
const { statuses, label, color } = useAttendanceCodes();

const TEXT = ['name', 'category'];
const sortKey = ref('score_pct');
const sortDir = ref('desc');

function value(row, key) {
    if (statuses.includes(key)) return row.counts[key];
    if (TEXT.includes(key)) return row[key] ?? '';
    return row[key] ?? -1; // no score % (nothing expected) sorts last in descending order
}

function sortBy(key) {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
        return;
    }
    sortKey.value = key;
    sortDir.value = TEXT.includes(key) ? 'asc' : 'desc';
}

const sorted = computed(() => {
    const dir = sortDir.value === 'asc' ? 1 : -1;
    const text = TEXT.includes(sortKey.value);
    return [...props.rows].sort((a, b) => {
        const x = value(a, sortKey.value);
        const y = value(b, sortKey.value);
        const order = text ? String(x).localeCompare(String(y), locale.value) : x - y;
        return order * dir || a.name.localeCompare(b.name, locale.value);
    });
});

const ariaSort = (key) => (sortKey.value !== key ? 'none' : sortDir.value === 'asc' ? 'ascending' : 'descending');

const columns = computed(() => [
    { key: 'name', label: t('att.player'), start: true },
    { key: 'category', label: t('att.category'), start: true },
    { key: 'expected', label: t('att.col.expected') },
    ...statuses.map((s) => ({ key: s, label: label(s), color: color(s) })),
    { key: 'late_minutes', label: t('att.col.late_minutes') },
    { key: 'missed_hours', label: t('att.col.missed_hours') },
    { key: 'score_pct', label: t('att.col.score_pct') },
]);
</script>

<template>
    <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <h2 class="px-4 pt-4 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('att.stats.players') }}</h2>
        <p v-if="!rows.length" class="p-6 text-center text-sm text-slate-500">{{ t('att.stats.no_data') }}</p>
        <table v-else class="mt-2 w-full min-w-[64rem] text-sm">
            <thead>
                <tr class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800/50">
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        :aria-sort="ariaSort(col.key)"
                        class="p-2 font-semibold"
                        :class="col.start ? 'text-start' : 'text-end'"
                    >
                        <button type="button" class="inline-flex items-center gap-1 hover:text-slate-900 dark:hover:text-slate-100" @click="sortBy(col.key)">
                            <span v-if="col.color" class="h-2 w-2 rounded-full" :style="{ backgroundColor: col.color }"></span>
                            {{ col.label }}
                            <span v-if="sortKey === col.key" aria-hidden="true">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
                        </button>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in sorted" :key="row.player_id" class="border-t border-slate-100 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/40">
                    <td class="whitespace-nowrap p-2 font-medium">
                        <Link v-if="can('players', 'view')" :href="route('players.show', row.player_id)" class="text-primary-700 hover:underline dark:text-primary-300">{{ row.name }}</Link>
                        <template v-else>{{ row.name }}</template>
                    </td>
                    <td class="whitespace-nowrap p-2 text-slate-500">{{ row.category ?? '—' }}</td>
                    <td class="p-2 text-end tabular-nums">{{ row.expected }}</td>
                    <td v-for="s in statuses" :key="s" class="whitespace-nowrap p-2 text-end tabular-nums">
                        {{ row.counts[s] }} <bdi dir="ltr" class="text-xs text-slate-400">{{ pct(row.pct[s]) }}</bdi>
                    </td>
                    <td class="p-2 text-end tabular-nums">{{ row.late_minutes }}</td>
                    <td class="p-2 text-end tabular-nums">{{ hours(row.missed_hours) }}</td>
                    <td class="p-2 text-end font-semibold tabular-nums"><bdi dir="ltr">{{ pct(row.score_pct) }}</bdi></td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
```

`resources/js/Pages/Attendance/Partials/StatsRanking.vue`:
```vue
<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan';
import { pct } from '@/lib/attendanceStats';

/** Top and bottom 5 by score % (AttendanceStats::ranking). */
const props = defineProps({
    ranking: { type: Object, required: true }, // { min_expected, top: [], bottom: [] }
});
const { t } = useI18n();
const { can } = useCan();

const lists = computed(() => [
    { key: 'top', rows: props.ranking.top, tone: 'text-emerald-600 dark:text-emerald-400' },
    { key: 'bottom', rows: props.ranking.bottom, tone: 'text-rose-600 dark:text-rose-400' },
]);
</script>

<template>
    <section class="rounded-xl bg-white p-4 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('att.stats.ranking_help', { n: ranking.min_expected }) }}</p>
        <p v-if="!ranking.top.length" class="py-4 text-center text-sm text-slate-500">{{ t('att.stats.no_ranking') }}</p>
        <div v-else class="mt-3 grid gap-4 md:grid-cols-2">
            <div v-for="list in lists" :key="list.key">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t(`att.stats.${list.key}`) }}</h3>
                <p v-if="!list.rows.length" class="mt-2 text-sm text-slate-400">—</p>
                <ol v-else class="mt-2 space-y-1">
                    <li v-for="(row, i) in list.rows" :key="row.player_id" class="flex items-center gap-2 text-sm">
                        <span class="w-5 text-end tabular-nums text-slate-400">{{ i + 1 }}</span>
                        <Link v-if="can('players', 'view')" :href="route('players.show', row.player_id)" class="font-medium text-primary-700 hover:underline dark:text-primary-300">{{ row.name }}</Link>
                        <span v-else class="font-medium">{{ row.name }}</span>
                        <span class="text-xs text-slate-400">{{ row.category ?? '' }}</span>
                        <bdi dir="ltr" class="ms-auto font-semibold tabular-nums" :class="list.tone">{{ pct(row.score_pct) }}</bdi>
                    </li>
                </ol>
            </div>
        </div>
    </section>
</template>
```

`resources/js/Pages/Attendance/Stats.vue`:
```vue
<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Bar } from 'vue-chartjs';
import { useI18n } from 'vue-i18n';
import '@/lib/registerCharts';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PeriodFilter from '@/Components/Activity/PeriodFilter.vue';
import ChartCard from '@/Components/Dashboard/ChartCard.vue';
import StatusBreakdown from '@/Components/Attendance/StatusBreakdown.vue';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';
import { barDataset, baseOptions } from '@/lib/chartTheme';
import { hasMarks, hours, monthTick, pct, periodQuery, statusBars } from '@/lib/attendanceStats';
import StatsPlayerTable from './Partials/StatsPlayerTable.vue';
import StatsRanking from './Partials/StatsRanking.vue';

/**
 * Club-wide attendance statistics for a period (URL: period, from, to) and an
 * optional category (category_id). Every number is computed by
 * AttendanceStats on the server; this page lays it out. Status names and
 * colours come from the configured codes.
 */
const props = defineProps({
    period: { type: Object, required: true },
    categoryId: { type: Number, default: null },
    categories: { type: Array, default: () => [] },
    totals: { type: Object, required: true },
    monthly: { type: Object, required: true },
    sessionsByMonth: { type: Object, required: true },
    categoryRows: { type: Array, default: () => [] },
    players: { type: Array, default: () => [] },
    ranking: { type: Object, required: true },
    attendanceCodes: { type: Object, default: null },
});
const { t, locale } = useI18n();
const { statuses, label, color } = useAttendanceCodes();
const rtl = computed(() => locale.value === 'ar');

const keep = computed(() => (props.categoryId ? { category_id: props.categoryId } : {}));
function pickCategory(value) {
    router.get(route('attendance.stats'), { ...periodQuery(props.period), ...(value ? { category_id: Number(value) } : {}) }, { preserveScroll: true, replace: true });
}

const tiles = computed(() => [
    { key: 'held', label: t('att.stats.held_sessions'), value: props.totals.held },
    { key: 'cancelled', label: t('att.stats.cancelled_sessions'), value: props.totals.cancelled },
    { key: 'marks', label: t('att.stats.marks'), value: props.totals.expected },
    { key: 'late', label: t('att.col.late_minutes'), value: props.totals.late_minutes },
    { key: 'missed', label: t('att.col.missed_hours'), value: hours(props.totals.missed_hours) },
    { key: 'score', label: t('att.col.score_pct'), value: pct(props.totals.score_pct) },
]);

const statusChart = computed(() => statusBars(props.monthly, statuses, label, color, locale.value));
const stackedOptions = computed(() => baseOptions({ rtl: rtl.value, stacked: true }));

const sessionsChart = computed(() => ({
    labels: props.sessionsByMonth.labels.map((ym) => monthTick(ym, locale.value)),
    datasets: [
        barDataset(t('att.state.held'), props.sessionsByMonth.held, 0),
        barDataset(t('att.state.cancelled'), props.sessionsByMonth.cancelled, 1),
    ],
}));
const hasSessions = computed(() => [...props.sessionsByMonth.held, ...props.sessionsByMonth.cancelled].some((n) => n > 0));
const sessionsOptions = computed(() => baseOptions({ rtl: rtl.value }));

const preseasonText = (p) => (!p ? '—' : p.target ? `${p.done}/${p.target}` : String(p.done));

const input = 'h-9 rounded-lg border-slate-300 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-900';
const card = 'rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
const linkButton = 'rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800';
</script>

<template>
    <Head :title="t('att.stats_title')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('att.stats_title') }}</h1>
                <div class="flex items-center gap-2 print:hidden">
                    <Link :href="route('attendance.index')" :class="linkButton">{{ t('attendance') }}</Link>
                </div>
            </div>
        </template>

        <div class="space-y-5">
            <div class="flex flex-wrap items-center gap-3 print:hidden">
                <PeriodFilter :period="period" :href="route('attendance.stats')" :keep="keep" />
                <select :value="categoryId ?? ''" :class="input" :aria-label="t('att.category')" @change="pickCategory($event.target.value)">
                    <option value="">{{ t('att.all_categories') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>

            <section class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
                <div v-for="tile in tiles" :key="tile.key" :class="[card, 'p-4']">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ tile.label }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100"><bdi dir="ltr">{{ tile.value }}</bdi></p>
                </div>
            </section>

            <div :class="[card, 'p-4']">
                <p v-if="!totals.expected" class="text-center text-sm text-slate-500">{{ t('att.stats.no_data') }}</p>
                <StatusBreakdown v-else :counts="totals.counts" />
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ t('att.stats.score_help') }}</p>
            </div>

            <section class="grid gap-5 lg:grid-cols-2">
                <ChartCard :title="t('att.stats.by_month')" :empty="!hasMarks(monthly)" has-table height="h-72">
                    <Bar :data="statusChart" :options="stackedOptions" />
                    <template #table>
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-muted/60 text-xs text-muted-foreground">
                                <tr>
                                    <th class="px-3 py-2 text-start font-medium">{{ t('att.date') }}</th>
                                    <th v-for="s in statuses" :key="s" class="px-3 py-2 text-end font-medium">{{ label(s) }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/70">
                                <tr v-for="(ym, i) in monthly.labels" :key="ym">
                                    <td class="px-3 py-2"><bdi dir="ltr">{{ ym }}</bdi></td>
                                    <td v-for="s in statuses" :key="s" class="px-3 py-2 text-end tabular-nums">{{ monthly.statuses[s][i] }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                </ChartCard>

                <ChartCard :title="t('att.stats.sessions_by_month')" :empty="!hasSessions" has-table height="h-72">
                    <Bar :data="sessionsChart" :options="sessionsOptions" />
                    <template #table>
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-muted/60 text-xs text-muted-foreground">
                                <tr>
                                    <th class="px-3 py-2 text-start font-medium">{{ t('att.date') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ t('att.state.held') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ t('att.state.cancelled') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/70">
                                <tr v-for="(ym, i) in sessionsByMonth.labels" :key="ym">
                                    <td class="px-3 py-2"><bdi dir="ltr">{{ ym }}</bdi></td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ sessionsByMonth.held[i] }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ sessionsByMonth.cancelled[i] }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                </ChartCard>
            </section>

            <section :class="[card, 'overflow-x-auto']">
                <h2 class="px-4 pt-4 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('att.stats.by_category') }}</h2>
                <table class="mt-2 w-full min-w-[60rem] text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800/50">
                            <th class="p-2 text-start font-semibold">{{ t('att.category') }}</th>
                            <th class="p-2 text-end font-semibold">{{ t('att.state.held') }}</th>
                            <th class="p-2 text-end font-semibold">{{ t('att.state.cancelled') }}</th>
                            <th class="p-2 text-end font-semibold">{{ t('att.kind.preseason') }}</th>
                            <th class="p-2 text-end font-semibold">{{ t('att.stats.marks') }}</th>
                            <th v-for="s in statuses" :key="s" class="p-2 text-end font-semibold">
                                <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" :style="{ backgroundColor: color(s) }"></span>{{ label(s) }}</span>
                            </th>
                            <th class="p-2 text-end font-semibold">{{ t('att.col.late_minutes') }}</th>
                            <th class="p-2 text-end font-semibold">{{ t('att.col.missed_hours') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in categoryRows"
                            :key="row.category_id"
                            class="border-t border-slate-100 dark:border-slate-800"
                            :class="{ 'bg-primary-50/60 dark:bg-primary-500/10': row.category_id === categoryId }"
                        >
                            <td class="p-2 font-medium">{{ row.name }}</td>
                            <td class="p-2 text-end tabular-nums">{{ row.held }}</td>
                            <td class="p-2 text-end tabular-nums">{{ row.cancelled }}</td>
                            <td class="p-2 text-end tabular-nums"><bdi dir="ltr">{{ preseasonText(row.preseason) }}</bdi></td>
                            <td class="p-2 text-end tabular-nums">{{ row.expected }}</td>
                            <td v-for="s in statuses" :key="s" class="whitespace-nowrap p-2 text-end tabular-nums">
                                {{ row.counts[s] }} <bdi dir="ltr" class="text-xs text-slate-400">{{ pct(row.pct[s]) }}</bdi>
                            </td>
                            <td class="p-2 text-end tabular-nums">{{ row.late_minutes }}</td>
                            <td class="p-2 text-end tabular-nums">{{ hours(row.missed_hours) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <StatsRanking :ranking="ranking" />
            <StatsPlayerTable :rows="players" />
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 7: Link from the calendar**

In `resources/js/Pages/Attendance/Index.vue`, in the header's `<div class="flex gap-2 print:hidden">`, add as its first child (before the month-grid link):
```vue
                    <Link :href="route('attendance.stats')" class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800"><Icon name="dashboard" />{{ t('att.statistics') }}</Link>
```

- [ ] **Step 8: Append the translations**

Create `att-i18n.mjs` in the repo root:
```js
import { readFileSync, writeFileSync } from 'node:fs';

const keys = {
    'att.statistics': ['Statistics', 'Statistiques', 'الإحصائيات'],
    'att.stats_title': ['Attendance statistics', 'Statistiques de présence', 'إحصائيات الحضور'],
    'att.stats.by_month': ['Marks per month, by status', 'Pointages par mois, par statut', 'التسجيلات شهريًا حسب الحالة'],
    'att.stats.sessions_by_month': ['Sessions held and cancelled per month', 'Séances tenues et annulées par mois', 'الحصص المنجزة والملغاة شهريًا'],
    'att.stats.by_category': ['By category', 'Par catégorie', 'حسب الفئة'],
    'att.stats.players': ['By player', 'Par joueur', 'حسب اللاعب'],
    'att.stats.top': ['Best attendance', 'Meilleure assiduité', 'الأكثر انضباطًا'],
    'att.stats.bottom': ['Lowest attendance', 'Plus faible assiduité', 'الأقل انضباطًا'],
    'att.stats.ranking_help': [
        'Top and bottom 5 by score %, among players expected at {n} sessions or more.',
        'Les 5 premiers et les 5 derniers par score %, parmi les joueurs attendus à au moins {n} séances.',
        'أفضل 5 وأضعف 5 حسب نسبة النقاط، من بين اللاعبين المنتظرين في {n} حصص على الأقل.',
    ],
    'att.stats.no_ranking': [
        'No player has enough sessions in this period to be ranked.',
        "Aucun joueur n'a assez de séances sur cette période pour être classé.",
        'لا يوجد لاعب لديه حصص كافية في هذه الفترة لترتيبه.',
    ],
    'att.stats.no_data': [
        'No attendance recorded in held sessions for this period.',
        'Aucune présence enregistrée dans des séances tenues sur cette période.',
        'لا يوجد حضور مسجل في حصص منجزة خلال هذه الفترة.',
    ],
    'att.stats.marks': ['Marks', 'Pointages', 'التسجيلات'],
    'att.stats.held_sessions': ['Sessions held', 'Séances tenues', 'الحصص المنجزة'],
    'att.stats.cancelled_sessions': ['Sessions cancelled', 'Séances annulées', 'الحصص الملغاة'],
    'att.stats.score_help': [
        'Counts are the marks as recorded. The score adds the points per status with the discipline rules applied; score % compares it with every session marked present.',
        'Les nombres sont les pointages tels quels. Le score additionne les points par statut, règles de discipline appliquées ; le score % le compare à une présence à toutes les séances.',
        'الأعداد هي التسجيلات كما هي. النقاط مجموع نقاط كل حالة مع تطبيق قواعد الانضباط، ونسبة النقاط تقارنها بالحضور في كل الحصص.',
    ],
    'att.col.expected': ['Sessions expected', 'Séances attendues', 'الحصص المنتظرة'],
    'att.col.late_minutes': ['Late (min)', 'Retard (min)', 'التأخر (د)'],
    'att.col.missed_hours': ['Missed (h)', 'Manqué (h)', 'الغياب (سا)'],
    'att.col.score': ['Score', 'Score', 'النقاط'],
    'att.col.score_pct': ['Score %', 'Score %', 'نسبة النقاط'],
};

['en', 'fr', 'ar'].forEach((locale, i) => {
    const file = `resources/js/i18n/${locale}.json`;
    const text = readFileSync(file, 'utf8');
    const data = JSON.parse(text);
    if (JSON.stringify(data, null, 4) + '\n' !== text) throw new Error(`${locale}: file does not round-trip, stop`);
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
git diff -U0 resources/js/i18n | grep '^-[^-]'
```
Expected: exactly three removed lines, one per file, each the previous last key re-added with a trailing comma.

- [ ] **Step 9: Build and run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendanceStatsPageTest|AttendancePermissionTest|AttendanceStatsTest|AttendanceCalendarTest" && npm run i18n:check`
Expected: PASS. (`PermissionMap::resolve()` reads names only, so the `attendance.stats.export` and `attendance.players.*` assertions pass before those routes exist: `export`, `show` and `report` are view verbs.)

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/AttendanceStatsController.php routes/web.php config/permissions.php resources/js/lib/attendanceStats.js resources/js/Components/Attendance/StatusBreakdown.vue resources/js/Pages/Attendance/Stats.vue resources/js/Pages/Attendance/Partials/StatsPlayerTable.vue resources/js/Pages/Attendance/Partials/StatsRanking.vue resources/js/Pages/Attendance/Index.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json tests/Feature/AttendanceStatsPageTest.php tests/Feature/AttendancePermissionTest.php
git commit -m "feat(attendance): statistics page with monthly charts, category table, ranking and sortable player table" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Statistics exports (XLSX / CSV / PDF)

**Files:**
- Create: `app/Services/Pdf/ClubHeader.php`, `resources/views/pdf/attendance-stats.blade.php`
- Modify: `app/Services/Pdf/PdfService.php`, `app/Support/AttendanceSettings.php`, `app/Http/Controllers/AttendanceStatsController.php`, `routes/web.php`, `resources/js/Pages/Attendance/Stats.vue`
- Test: `tests/Feature/AttendanceStatsExportTest.php` (new); update `tests/Feature/ExportMenuWiringTest.php`

**Interfaces:**
- Consumes: `AttendanceStatsController::data()` (Task 2), `Export::download(string $format, string $filename, array $headers, iterable $rows, ?string $title)`, `Export::format(Request)`, `UiLang::get(string $key, ?string $default = null, ?string $locale = null)`, `Media::localFile(?string)`, `ExportMenu.vue` (`href`, `label`, `formats`).
- Produces: `PdfService::render(string $html, bool $rtl = true, bool $landscape = false): string` and `PdfService::stream(string $html, string $filename, bool $rtl = true, bool $landscape = false): Response` (A4-L when `$landscape`); existing calls unchanged.
- Produces: `App\Services\Pdf\ClubHeader::data(): array{name, logo, address, phone, email, currency}` (shape of `pdf.partials.header`'s `$club`).
- Produces: `AttendanceSettings::labels(?string $locale = null): array<string, string>` — status value ⇒ configured name in the locale, else `UiLang::get('att.status.<status>', null, $locale)`; keys in `AttendanceStatus::values()` order.
- Produces: route `GET /attendance/stats/export` named `attendance.stats.export` (same query as the page + `format=xlsx|csv|pdf`, default xlsx). Filename `attendance-stats-{from}-{to}[-{categoryId}]`. Spreadsheet columns: member, membership id, category, sessions expected, then per status "name" and "name %", late (min), missed (h), score, score %. Title: `"<att.stats_title> — <period label> — <category or att.all_categories>"`.
- Produces: Blade view `pdf.attendance-stats` (variables: `club`, `period`, `categoryName`, `totals`, `categoryRows`, `players`, `labels`, `codes`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceStatsExportTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Pdf\PdfService;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceStatsExportTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private const OCTOBER = ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'];

    private function userIn(string $locale): User
    {
        // SetLocale reads users.preferred_lng.
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    /** One U15 player marked late in one October session; returns [player, category]. */
    private function seedOctober(): array
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        $training = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'status' => AttendanceStatus::Late, 'minutes' => 15]);

        return [$player, $u15];
    }

    /** Swaps mPDF for a spy; returns a reference filled with what stream() received. */
    private function spyPdf(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $html, string $filename, bool $rtl = true, bool $landscape = false) use ($seen) {
                $seen['html'] = $html;
                $seen['filename'] = $filename;
                $seen['rtl'] = $rtl;
                $seen['landscape'] = $landscape;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        return $seen;
    }

    #[Test]
    public function the_spreadsheet_lists_every_player_with_the_configured_names(): void
    {
        AttendanceSettings::save(['codes' => ['late' => ['label' => ['ar' => 'Tardy', 'fr' => 'Tardy', 'en' => 'Tardy']]]]);
        [$player] = $this->seedOctober();

        $response = $this->actingAs($this->userIn('fr'))
            ->get(route('attendance.stats.export', self::OCTOBER + ['format' => 'csv']))
            ->assertOk();
        $csv = $response->streamedContent();

        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('content-type'));
        $this->assertStringContainsString('attendance-stats-2026-10-01-2026-10-31.csv', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('Statistiques de présence — 2026-10-01 – 2026-10-31 — ', $csv);
        $this->assertStringContainsString('Présent,"Présent %",Tardy,"Tardy %"', $csv);   // unset name: built-in French
        $this->assertStringContainsString('"Retard (min)"', $csv);
        $this->assertStringContainsString($player->fullname, $csv);
        $this->assertStringContainsString(',U15,1,0,0,1,100,', $csv);   // expected 1; present 0 (0%); late 1 (100%)
    }

    #[Test]
    public function the_category_filter_applies_to_the_export(): void
    {
        [, $u15] = $this->seedOctober();
        $u17 = $this->category('U17');
        $other = $this->player($u17);

        $csv = $this->actingAs($this->userIn('en'))
            ->get(route('attendance.stats.export', self::OCTOBER + ['category_id' => $u17->id, 'format' => 'csv']))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString(' — U17', $csv);
        $this->assertStringNotContainsString(Player::where('category_id', $u15->id)->first()->fullname, $csv);
        $this->assertStringNotContainsString($other->fullname, $csv);   // no mark in the period
    }

    #[Test]
    public function the_pdf_renders(): void
    {
        $this->seedOctober();

        $response = $this->actingAs($this->userIn('ar'))
            ->get(route('attendance.stats.export', self::OCTOBER + ['format' => 'pdf']))
            ->assertOk();

        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
    }

    #[Test]
    public function the_pdf_is_landscape_right_to_left_in_arabic_and_uses_the_configured_names(): void
    {
        AttendanceSettings::save(['codes' => ['late' => ['label' => ['ar' => 'تأخير مسجل', 'fr' => 'Tardy', 'en' => 'Tardy']]]]);
        [$player] = $this->seedOctober();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('ar'))->get(route('attendance.stats.export', self::OCTOBER + ['format' => 'pdf']))->assertOk();

        $this->assertTrue($seen['rtl']);
        $this->assertTrue($seen['landscape']);
        $this->assertSame('attendance-stats-2026-10-01-2026-10-31.pdf', $seen['filename']);
        $this->assertStringContainsString('تأخير مسجل', $seen['html']);
        $this->assertStringContainsString('إحصائيات الحضور', $seen['html']);
        $this->assertStringContainsString($player->fullname, $seen['html']);
        $this->assertStringContainsString('2026-10-01 – 2026-10-31', $seen['html']);
    }

    #[Test]
    public function the_pdf_is_left_to_right_in_french(): void
    {
        $this->seedOctober();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.stats.export', self::OCTOBER + ['format' => 'pdf']))->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertStringContainsString('En retard', $seen['html']);   // unset name: built-in French
    }
}
```

In `tests/Feature/ExportMenuWiringTest.php`, add to the array returned by `routes()` after `'board tasks export' => ['board.tasks.export'],`:
```php
            'attendance stats export' => ['attendance.stats.export'],
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="AttendanceStatsExportTest|ExportMenuWiringTest"`
Expected: FAIL — `Route [attendance.stats.export] not defined.`

- [ ] **Step 3: Landscape PDFs and the shared club header**

In `app/Services/Pdf/PdfService.php` replace the three methods with:
```php
    private function make(bool $rtl, bool $landscape = false): Mpdf
    {
        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $landscape ? 'A4-L' : 'A4',
            'directionality' => $rtl ? 'rtl' : 'ltr',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $tempDir,
            'margin_top' => 12,
            'margin_bottom' => 12,
        ]);

        return $mpdf;
    }

    public function render(string $html, bool $rtl = true, bool $landscape = false): string
    {
        $mpdf = $this->make($rtl, $landscape);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    public function stream(string $html, string $filename, bool $rtl = true, bool $landscape = false): Response
    {
        return response($this->render($html, $rtl, $landscape), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
```

`app/Services/Pdf/ClubHeader.php`:
```php
<?php

namespace App\Services\Pdf;

use App\Models\WebsiteConfig;
use App\Support\Media;

/**
 * The club block every PDF opens with (`pdf.partials.header`): the name in
 * the app locale and the logo as a file path mPDF can read. Same shape as
 * the private club() helpers of ReportController and PlayerPrintController.
 */
final class ClubHeader
{
    /** @return array{name: ?string, logo: ?string, address: ?string, phone: ?string, email: ?string, currency: string} */
    public static function data(): array
    {
        $config = WebsiteConfig::singleton();
        $locale = app()->getLocale();
        $name = $config->club_name;

        return [
            'name' => is_array($name) ? ($name[$locale] ?? $name['en'] ?? $name['ar'] ?? collect($name)->filter()->first()) : $name,
            'logo' => Media::localFile($config->branding['logo'] ?? null),
            'address' => $config->full_address ?: null,
            'phone' => $config->contact_phone,
            'email' => $config->contact_email,
            'currency' => $config->settings['currencySymbol'] ?? $config->settings['currency'] ?? 'DZD',
        ];
    }
}
```

- [ ] **Step 4: Status names for PDFs and spreadsheets**

In `app/Support/AttendanceSettings.php` add the imports:
```php
use App\Enums\AttendanceStatus;
```
(`UiLang` is in the same namespace, no import needed), and add after `codes()`:
```php
    /**
     * Each status's name for server-rendered documents (PDF, spreadsheets):
     * the configured name in $locale (default: the app locale), else the
     * built-in `att.status.<status>` from the UI catalogs, so a document
     * reads exactly like the screen.
     *
     * @return array<string, string> status value => name, in the fixed status order
     */
    public static function labels(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $codes = self::codes();

        $labels = [];
        foreach (AttendanceStatus::values() as $status) {
            $labels[$status] = ($codes[$status]['label'][$locale] ?? null) ?: UiLang::get("att.status.{$status}", null, $locale);
        }

        return $labels;
    }
```

- [ ] **Step 5: The export action and route**

In `routes/web.php`, add after the `attendance.stats` route:
```php
        Route::get('/attendance/stats/export', [AttendanceStatsController::class, 'export'])->name('attendance.stats.export');
```

In `app/Http/Controllers/AttendanceStatsController.php`:

Replace the imports block with:
```php
use App\Enums\AttendanceStatus;
use App\Models\Category;
use App\Models\Player;
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\AttendanceStats;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\AttendanceSettings;
use App\Support\Export;
use App\Support\UiLang;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;
```

Replace the constructor with:
```php
    public function __construct(
        private readonly AttendanceStats $stats,
        private readonly PdfService $pdf,
    ) {}
```

Add after `index()`:
```php
    /**
     * The player table as XLSX (default) or CSV, or the whole page as a PDF
     * (`format=pdf`), for the same period and category as the page.
     */
    public function export(Request $request): FileResponse
    {
        $data = $this->data($request);
        ['from' => $from, 'to' => $to] = $data['period'];
        $categoryName = $data['categoryId'] !== null
            ? collect($data['categories'])->firstWhere('id', $data['categoryId'])['name']
            : UiLang::get('att.all_categories');
        $filename = 'attendance-stats-'.$from.'-'.$to.($data['categoryId'] !== null ? '-'.$data['categoryId'] : '');
        $labels = AttendanceSettings::labels();

        if ($request->query('format') === 'pdf') {
            $html = view('pdf.attendance-stats', [
                ...$data,
                'club' => ClubHeader::data(),
                'categoryName' => $categoryName,
                'labels' => $labels,
                'codes' => AttendanceSettings::codes(),
            ])->render();

            return $this->pdf->stream($html, $filename.'.pdf', app()->getLocale() === 'ar', true);
        }

        $headers = [UiLang::get('col.member'), UiLang::get('col.membership_id'), UiLang::get('col.category'), UiLang::get('att.col.expected')];
        foreach ($labels as $name) {
            array_push($headers, $name, $name.' %');
        }
        array_push($headers, UiLang::get('att.col.late_minutes'), UiLang::get('att.col.missed_hours'), UiLang::get('att.col.score'), UiLang::get('att.col.score_pct'));

        $rows = array_map(function (array $row): array {
            $cells = [$row['name'], (string) $row['membership_id'], $row['category'] ?? '', $row['expected']];
            foreach (AttendanceStatus::values() as $status) {
                array_push($cells, $row['counts'][$status], $row['pct'][$status]);
            }

            return [...$cells, $row['late_minutes'], $row['missed_hours'], $row['score'], $row['score_pct']];
        }, $data['players']);

        return Export::download(Export::format($request), $filename, $headers, $rows,
            UiLang::get('att.stats_title').' — '.$data['period']['label'].' — '.$categoryName);
    }
```

Note for the CSV assertion `,U15,1,0,0,1,100,`: `pct` values are floats (`0.0`, `100.0`); `CsvWriter::stringify()` casts with `(string)`, giving `0` and `100`.

- [ ] **Step 6: The PDF view**

`resources/views/pdf/attendance-stats.blade.php`:
```blade
@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $pct = fn ($value) => $value === null ? '—' : number_format((float) $value, 1).'%';
    $hours = fn ($value) => number_format((float) $value, 1);
    $statuses = array_keys($labels);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 9px; color: #1e293b; }
        .title { font-size: 16px; font-weight: bold; color: #02a85c; margin-bottom: 2px; }
        .sub { font-size: 11px; color: #64748b; margin-bottom: 8px; }
        h2 { font-size: 12px; color: #0f172a; margin: 12px 0 4px; }
        table.rows { width: 100%; border-collapse: collapse; }
        table.rows th { background: #f1f5f9; color: #334155; font-size: 8px; padding: 4px 3px; border: 1px solid #e2e8f0; }
        table.rows td { padding: 3px; border: 1px solid #e2e8f0; }
        .num { text-align: center; }
        .pct { color: #64748b; font-size: 7px; }
        .empty { padding: 16px; text-align: center; color: #94a3b8; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ $L('att.stats_title') }}</div>
    <div class="sub">{{ $L('activity.period_label') }}: <bdi dir="ltr">{{ $period['label'] }}</bdi> &middot; {{ $categoryName }}</div>

    <table class="rows">
        <tr>
            <th>{{ $L('att.stats.held_sessions') }}</th>
            <th>{{ $L('att.stats.cancelled_sessions') }}</th>
            <th>{{ $L('att.stats.marks') }}</th>
            @foreach ($statuses as $status)
                <th><span style="color: {{ $codes[$status]['color'] }};">&#9632;</span> {{ $labels[$status] }}</th>
            @endforeach
            <th>{{ $L('att.col.late_minutes') }}</th>
            <th>{{ $L('att.col.missed_hours') }}</th>
            <th>{{ $L('att.col.score_pct') }}</th>
        </tr>
        <tr>
            <td class="num">{{ $totals['held'] }}</td>
            <td class="num">{{ $totals['cancelled'] }}</td>
            <td class="num">{{ $totals['expected'] }}</td>
            @foreach ($statuses as $status)
                <td class="num">{{ $totals['counts'][$status] }} <span class="pct"><bdi dir="ltr">{{ $pct($totals['pct'][$status]) }}</bdi></span></td>
            @endforeach
            <td class="num">{{ $totals['late_minutes'] }}</td>
            <td class="num">{{ $hours($totals['missed_hours']) }}</td>
            <td class="num"><bdi dir="ltr">{{ $pct($totals['score_pct']) }}</bdi></td>
        </tr>
    </table>

    <h2>{{ $L('att.stats.by_category') }}</h2>
    <table class="rows">
        <tr>
            <th>{{ $L('att.category') }}</th>
            <th>{{ $L('att.state.held') }}</th>
            <th>{{ $L('att.state.cancelled') }}</th>
            <th>{{ $L('att.kind.preseason') }}</th>
            <th>{{ $L('att.stats.marks') }}</th>
            @foreach ($statuses as $status)
                <th>{{ $labels[$status] }}</th>
            @endforeach
            <th>{{ $L('att.col.late_minutes') }}</th>
            <th>{{ $L('att.col.missed_hours') }}</th>
        </tr>
        @foreach ($categoryRows as $row)
            <tr>
                <td>{{ $row['name'] }}</td>
                <td class="num">{{ $row['held'] }}</td>
                <td class="num">{{ $row['cancelled'] }}</td>
                <td class="num"><bdi dir="ltr">{{ $row['preseason'] ? ($row['preseason']['target'] ? $row['preseason']['done'].'/'.$row['preseason']['target'] : $row['preseason']['done']) : '—' }}</bdi></td>
                <td class="num">{{ $row['expected'] }}</td>
                @foreach ($statuses as $status)
                    <td class="num">{{ $row['counts'][$status] }} <span class="pct"><bdi dir="ltr">{{ $pct($row['pct'][$status]) }}</bdi></span></td>
                @endforeach
                <td class="num">{{ $row['late_minutes'] }}</td>
                <td class="num">{{ $hours($row['missed_hours']) }}</td>
            </tr>
        @endforeach
    </table>

    <h2>{{ $L('att.stats.players') }}</h2>
    @if (empty($players))
        <div class="empty">{{ $L('att.stats.no_data') }}</div>
    @else
        <table class="rows">
            <tr>
                <th>#</th>
                <th>{{ $L('col.member') }}</th>
                <th>{{ $L('col.category') }}</th>
                <th>{{ $L('att.col.expected') }}</th>
                @foreach ($statuses as $status)
                    <th>{{ $labels[$status] }}</th>
                @endforeach
                <th>{{ $L('att.col.late_minutes') }}</th>
                <th>{{ $L('att.col.missed_hours') }}</th>
                <th>{{ $L('att.col.score_pct') }}</th>
            </tr>
            @foreach ($players as $row)
                <tr>
                    <td class="num">{{ $loop->iteration }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['category'] ?? '—' }}</td>
                    <td class="num">{{ $row['expected'] }}</td>
                    @foreach ($statuses as $status)
                        <td class="num">{{ $row['counts'][$status] }} <span class="pct"><bdi dir="ltr">{{ $pct($row['pct'][$status]) }}</bdi></span></td>
                    @endforeach
                    <td class="num">{{ $row['late_minutes'] }}</td>
                    <td class="num">{{ $hours($row['missed_hours']) }}</td>
                    <td class="num"><bdi dir="ltr">{{ $pct($row['score_pct']) }}</bdi></td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
```

- [ ] **Step 7: The export menu on the page**

In `resources/js/Pages/Attendance/Stats.vue`:

Add the imports after `import StatusBreakdown from '@/Components/Attendance/StatusBreakdown.vue';`:
```js
import ExportMenu from '@/Components/ExportMenu.vue';
import Icon from '@/Components/Icon.vue';
```
Add after the `keep` computed:
```js
const exportHref = computed(() => route('attendance.stats.export', { ...periodQuery(props.period), ...keep.value }));
```
In the header, replace
```vue
                <div class="flex items-center gap-2 print:hidden">
                    <Link :href="route('attendance.index')" :class="linkButton">{{ t('attendance') }}</Link>
                </div>
```
with
```vue
                <div class="flex items-center gap-2 print:hidden">
                    <Link :href="route('attendance.index')" :class="linkButton">{{ t('attendance') }}</Link>
                    <ExportMenu :href="exportHref" :label="t('export')" :formats="['xlsx', 'csv', 'pdf']">
                        <template #icon><Icon name="download" /></template>
                    </ExportMenu>
                </div>
```

- [ ] **Step 8: Build and run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendanceStatsExportTest|ExportMenuWiringTest|AttendanceStatsPageTest|AttendanceCodeSettingsTest|BoardTablePdfTest"`
Expected: PASS (`BoardTablePdfTest` confirms existing PDFs still stream in portrait with the old signature).

- [ ] **Step 9: Commit**

```bash
git add app/Services/Pdf/ClubHeader.php app/Services/Pdf/PdfService.php app/Support/AttendanceSettings.php app/Http/Controllers/AttendanceStatsController.php routes/web.php resources/views/pdf/attendance-stats.blade.php resources/js/Pages/Attendance/Stats.vue tests/Feature/AttendanceStatsExportTest.php tests/Feature/ExportMenuWiringTest.php
git commit -m "feat(attendance): export the statistics as XLSX, CSV and landscape PDF with the configured status names" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Attendance card on the player profile

**Files:**
- Create: `app/Http/Controllers/AttendancePlayerController.php`, `resources/js/Pages/Players/Partials/AttendanceSection.vue`
- Modify: `routes/web.php`, `app/Http/Controllers/PlayerController.php`, `resources/js/Components/Activity/PeriodFilter.vue`, `resources/js/Components/StatDoughnut.vue`, `resources/js/Pages/Players/Show.vue`, `resources/js/i18n/{en,fr,ar}.json`
- Test: `tests/Feature/AttendancePlayerCardTest.php` (new)

**Interfaces:**
- Consumes: `AttendanceStats::players()`, `emptyRow()`, `monthly()`, `playerSessions()`; `PreseasonProgress::forCategory(int, string)`; `ActivityPeriod::season()`, `fromRequest()`; `lib/attendanceStats.js` (Task 2); `useAttendanceCodes()` (`statuses`, `label`, `color`, `chipStyle`); `dayLabel(key, locale)` from `lib/attendanceCalendar.js`; `window.axios`.
- Produces: route `GET /attendance/players/{player}` named `attendance.players.show` → JSON `{period: {period, from, to, label}, summary: row, monthly: {labels, statuses}, preseason: ?{season, done, target}, sessions: list<playerSessions row>}`; the current season unless `period` is given.
- Produces: `AttendancePlayerController::data(Request, Player): array` (private, reused by Task 5).
- Produces: `Players/Show` prop `attendanceCodes` (`AttendanceSettings::codes()` for attendance/view, else `null`).
- Produces: `PeriodFilter.vue` — `href` optional (default `null`); without it the filter emits `change` with `{ period }` or `{ period: 'custom', from, to }` instead of visiting a URL.
- Produces: `StatDoughnut.vue` prop `unit: String` (default `null` → `t('players')`, unchanged elsewhere).
- Produces: component `Players/Partials/AttendanceSection.vue` (prop `player`), exposing `load(query)`.
- Produces: i18n keys `att.sessions_unit`, `att.profile.by_status`, `att.profile.sessions`, `att.profile.load_error`, `att.retry`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendancePlayerCardTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\PreseasonTarget;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendancePlayerCardTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

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

    private function heldSession(Category $category, string $date, SessionKind $kind = SessionKind::Regular, ?string $title = null, array $others = []): TrainingSession
    {
        $training = TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => $kind, 'state' => SessionState::Held, 'title' => $title,
        ]);
        foreach ($others as $other) {
            DB::table('training_session_category')->insert(['training_session_id' => $training->id, 'category_id' => $other->id]);
        }

        return $training;
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status, ?int $minutes = null, ?string $reason = null, ?string $note = null): void
    {
        Attendance::create([
            'training_session_id' => $training->id, 'player_id' => $player->id,
            'status' => $status, 'minutes' => $minutes, 'reason' => $reason, 'note' => $note,
        ]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    /** U15 player with a joint pre-season late, an excused absence, and a mark last season. */
    private function seedPlayer(): Player
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $player = $this->player($u15);
        $this->mark($this->heldSession($u15, '2026-10-03', SessionKind::Preseason, 'Running 7.2 km', [$u17]), $player, AttendanceStatus::Late, 12, note: 'Bus');
        $this->mark($this->heldSession($u15, '2026-10-05'), $player, AttendanceStatus::AbsentExcused, reason: 'illness');
        $this->mark($this->heldSession($u15, '2026-08-20'), $player, AttendanceStatus::Present);   // season 2025/26
        PreseasonTarget::create(['category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 12]);

        return $player;
    }

    #[Test]
    public function the_card_shows_the_current_season_by_default(): void
    {
        $player = $this->seedPlayer();

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', $player))
            ->assertOk()
            ->assertJsonPath('period.period', 'season')
            ->assertJsonPath('period.from', '2026-09-01')
            ->assertJsonPath('period.to', '2027-08-31')
            ->assertJsonPath('summary.expected', 2)
            ->assertJsonPath('summary.counts.late', 1)
            ->assertJsonPath('summary.counts.absent_excused', 1)
            ->assertJsonPath('summary.late_minutes', 12)
            ->assertJsonPath('preseason.season', '2026/27')
            ->assertJsonPath('preseason.done', 1)
            ->assertJsonPath('preseason.target', 12)
            ->assertJsonCount(12, 'monthly.labels')
            ->assertJsonPath('monthly.labels.1', '2026-10')
            ->assertJsonPath('monthly.statuses.late.1', 1)
            ->assertJsonCount(2, 'sessions')
            ->assertJsonPath('sessions.0.date', '2026-10-05')
            ->assertJsonPath('sessions.0.status', 'absent_excused')
            ->assertJsonPath('sessions.0.reason', 'illness')
            ->assertJsonPath('sessions.1.title', 'Running 7.2 km')
            ->assertJsonPath('sessions.1.kind', 'preseason')
            ->assertJsonPath('sessions.1.categories', ['U15', 'U17'])
            ->assertJsonPath('sessions.1.minutes', 12)
            ->assertJsonPath('sessions.1.note', 'Bus');
    }

    #[Test]
    public function a_period_can_be_picked(): void
    {
        $player = $this->seedPlayer();

        $this->actingAs($this->admin())
            ->getJson(route('attendance.players.show', ['player' => $player, 'period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertJsonPath('period.period', 'custom')
            ->assertJsonPath('summary.expected', 1)
            ->assertJsonPath('summary.counts.present', 1)
            ->assertJsonPath('preseason.season', '2025/26')
            ->assertJsonPath('sessions.0.date', '2026-08-20');

        $this->actingAs($this->admin())
            ->getJson(route('attendance.players.show', ['player' => $player, 'period' => 'month']))
            ->assertJsonPath('period.from', '2026-10-01')
            ->assertJsonPath('summary.expected', 2);
    }

    #[Test]
    public function a_player_without_marks_gets_zeros(): void
    {
        $player = $this->player($this->category());

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', $player))
            ->assertOk()
            ->assertJsonPath('summary.expected', 0)
            ->assertJsonPath('summary.score_pct', null)
            ->assertJsonPath('sessions', []);
    }

    #[Test]
    public function the_card_needs_attendance_view_not_just_players_view(): void
    {
        $player = $this->seedPlayer();

        $this->actingAs($this->userWith(['players' => ['view']]))->getJson(route('attendance.players.show', $player))->assertForbidden();
        $this->actingAs($this->userWith(['attendance' => ['view']]))->getJson(route('attendance.players.show', $player))->assertOk();
    }

    #[Test]
    public function the_profile_sends_the_codes_only_to_attendance_viewers(): void
    {
        $player = $this->seedPlayer();

        $this->actingAs($this->userWith(['players' => ['view'], 'attendance' => ['view']]))
            ->get(route('players.show', $player))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Players/Show')
                ->where('attendanceCodes.present.code', 'P')
                ->where('attendanceCodes.late.color', '#f59e0b'));

        $this->actingAs($this->userWith(['players' => ['view']]))
            ->get(route('players.show', $player))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('attendanceCodes', null));
    }
}
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter=AttendancePlayerCardTest`
Expected: FAIL — `Route [attendance.players.show] not defined.`

- [ ] **Step 3: Route, controller and profile prop**

In `routes/web.php`, add the import:
```php
use App\Http\Controllers\AttendancePlayerController;
```
and in the attendance block after the `attendance.stats.export` route:
```php
        // A player's attendance for the profile card: named attendance.* so it needs attendance/view.
        Route::get('/attendance/players/{player}', [AttendancePlayerController::class, 'show'])->name('attendance.players.show');
```

`app/Http/Controllers/AttendancePlayerController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\PreseasonProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One player's attendance, for the profile card. The routes are named
 * attendance.players.*, so the permission middleware gates them on the
 * attendance module: the profile needs players/view, and its attendance
 * card needs attendance/view on top. The card fetches this after the
 * profile has painted, so the profile never waits for it.
 */
class AttendancePlayerController extends Controller
{
    public function __construct(
        private readonly AttendanceStats $stats,
        private readonly PreseasonProgress $preseason,
    ) {}

    public function show(Request $request, Player $player): JsonResponse
    {
        return response()->json($this->data($request, $player));
    }

    /** @return array<string, mixed> */
    private function data(Request $request, Player $player): array
    {
        // The current season unless the request picks a period.
        $period = $request->query('period') === null ? ActivityPeriod::season() : ActivityPeriod::fromRequest($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        return [
            'period' => $period->toArray(),
            'summary' => $this->stats->players($from, $to, null, $player->id)[$player->id] ?? $this->stats->emptyRow($player->id),
            'monthly' => $this->stats->monthly($from, $to, null, $player->id),
            'preseason' => $player->category_id ? $this->preseason->forCategory((int) $player->category_id, $to) : null,
            'sessions' => $this->stats->playerSessions($player->id, $from, $to),
        ];
    }
}
```

In `app/Http/Controllers/PlayerController.php`, add the import (alphabetical, with the other `App\Support\` imports):
```php
use App\Support\AttendanceSettings;
```
and in `show()`, add to the `Inertia::render('Players/Show', [...])` array right after the `'documents' => ...` entry:
```php
            // The attendance card fetches its own data (attendance.players.show);
            // the page only carries the status names and colours it draws with.
            'attendanceCodes' => $request->user()?->hasPermission('attendance', 'view')
                ? AttendanceSettings::codes()
                : null,
```

- [ ] **Step 4: PeriodFilter without a URL, doughnut unit**

In `resources/js/Components/Activity/PeriodFilter.vue`:

Replace the comment and props block
```js
/**
 * The activity pages' period control, and the only thing that writes their
 * period to the URL (`period`, `from`, `to`), so a view survives a refresh and
 * can be shared. `keep` carries the other query params the page wants kept
 * (e.g. the chosen action); the page number is always dropped.
 */
const props = defineProps({
    period: { type: Object, required: true },
    href: { type: String, required: true },
    keep: { type: Object, default: () => ({}) },
});
```
with
```js
/**
 * A period control (month / season / custom range). With `href` it writes
 * the period to the URL (`period`, `from`, `to`), so a view survives a
 * refresh and can be shared; `keep` carries the other query params the page
 * wants kept (the page number is always dropped). Without `href` it only
 * emits `change` with that query, for a card that fetches its own data.
 */
const props = defineProps({
    period: { type: Object, required: true },
    href: { type: String, default: null },
    keep: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['change']);
```
and replace
```js
function visit(query) {
    router.get(props.href, { ...props.keep, ...query }, {
```
with
```js
function visit(query) {
    if (!props.href) {
        emit('change', query);
        return;
    }
    router.get(props.href, { ...props.keep, ...query }, {
```

In `resources/js/Components/StatDoughnut.vue`, add to `defineProps` after `palette`:
```js
    // What the total counts ("sessions" on the attendance card); players by default.
    unit: { type: String, default: null },
```
and replace both occurrences of `{{ t('players') }}` in the template with `{{ unit ?? t('players') }}`.

- [ ] **Step 5: The card**

`resources/js/Pages/Players/Partials/AttendanceSection.vue`:
```vue
<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Bar } from 'vue-chartjs';
import { useI18n } from 'vue-i18n';
import '@/lib/registerCharts';
import PeriodFilter from '@/Components/Activity/PeriodFilter.vue';
import StatDoughnut from '@/Components/StatDoughnut.vue';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';
import { baseOptions } from '@/lib/chartTheme';
import { dayLabel } from '@/lib/attendanceCalendar';
import { hours, pct, statusBars } from '@/lib/attendanceStats';

/**
 * The profile's attendance card. It fetches its own data
 * (attendance.players.show, gated on attendance/view) once the profile has
 * painted, so the profile never waits for it, and a period change reloads
 * only this card. Status names and colours come from the configured codes.
 */
const props = defineProps({
    player: { type: Object, required: true },
});

const { t, locale } = useI18n();
const { statuses, label, color, chipStyle } = useAttendanceCodes();
const rtl = computed(() => locale.value === 'ar');

const data = ref(null);
const loading = ref(true);
const failed = ref(false);
const lastQuery = ref({});
let ticket = 0; // a slow answer for an older period never overwrites a newer one

async function load(query = {}) {
    const mine = ++ticket;
    lastQuery.value = query;
    loading.value = true;
    failed.value = false;
    try {
        const response = await window.axios.get(route('attendance.players.show', { player: props.player.id, ...query }));
        if (mine === ticket) data.value = response.data;
    } catch {
        if (mine === ticket) failed.value = true;
    } finally {
        if (mine === ticket) loading.value = false;
    }
}
onMounted(() => load());
const retry = () => load(lastQuery.value);
defineExpose({ load });

const summary = computed(() => data.value?.summary ?? null);
const tiles = computed(() => (summary.value
    ? [
        { key: 'expected', label: t('att.col.expected'), value: summary.value.expected },
        { key: 'score', label: t('att.col.score_pct'), value: pct(summary.value.score_pct) },
        { key: 'late', label: t('att.col.late_minutes'), value: summary.value.late_minutes },
        { key: 'missed', label: t('att.col.missed_hours'), value: hours(summary.value.missed_hours) },
    ]
    : []));

const doughnutStats = computed(() => statuses.map((s) => ({ key: s, label: label(s), count: summary.value?.counts?.[s] ?? 0, static: true })));
const doughnutPalette = computed(() => statuses.map((s) => color(s)));
const monthlyChart = computed(() => statusBars(data.value?.monthly, statuses, label, color, locale.value));
const monthlyOptions = computed(() => baseOptions({ rtl: rtl.value, stacked: true }));

const preseasonText = computed(() => {
    const p = data.value?.preseason;
    if (!p) return null;
    return p.target
        ? t('att.preseason_progress', { season: p.season, done: p.done, target: p.target })
        : t('att.preseason_no_target', { season: p.season, done: p.done });
});
const reasonText = (row) => (row.reason ? t(`att.reason.${row.reason}`) : '');
const th = 'p-2 text-start text-xs font-semibold text-slate-500 dark:text-slate-400';
</script>

<template>
    <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('attendance') }}</h3>
            <PeriodFilter v-if="data" :period="data.period" @change="load" />
        </div>

        <div v-if="loading && !data" class="space-y-3 px-5 py-4" aria-busy="true">
            <div class="h-20 animate-pulse rounded-xl bg-slate-100 dark:bg-slate-800"></div>
            <div class="h-44 animate-pulse rounded-xl bg-slate-100 dark:bg-slate-800"></div>
        </div>

        <div v-else-if="failed" class="flex flex-col items-center gap-2 px-5 py-8 text-sm text-slate-500">
            <p>{{ t('att.profile.load_error') }}</p>
            <button type="button" class="rounded-lg px-3 py-1.5 font-semibold text-primary-700 ring-1 ring-primary-300 hover:bg-primary-50 dark:text-primary-300 dark:ring-primary-700" @click="retry">{{ t('att.retry') }}</button>
        </div>

        <div v-else-if="data" class="space-y-5 px-5 py-4 transition-opacity" :class="{ 'opacity-60': loading }">
            <div class="grid gap-3 sm:grid-cols-4">
                <div v-for="tile in tiles" :key="tile.key" class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ tile.label }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100"><bdi dir="ltr">{{ tile.value }}</bdi></p>
                </div>
            </div>
            <p v-if="preseasonText" class="text-sm text-slate-600 dark:text-slate-300">{{ preseasonText }}</p>

            <p v-if="!summary.expected" class="py-6 text-center text-sm text-slate-500">{{ t('att.stats.no_data') }}</p>
            <template v-else>
                <div class="grid gap-4 lg:grid-cols-2">
                    <StatDoughnut :title="t('att.profile.by_status')" :stats="doughnutStats" :palette="doughnutPalette" :unit="t('att.sessions_unit')" />
                    <div class="rounded-2xl p-4 ring-1 ring-slate-200 dark:ring-slate-800">
                        <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">{{ t('att.stats.by_month') }}</p>
                        <div class="h-52"><Bar :data="monthlyChart" :options="monthlyOptions" /></div>
                    </div>
                </div>

                <div>
                    <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('att.profile.sessions') }}</h4>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full min-w-[48rem] text-sm">
                            <thead>
                                <tr class="bg-slate-50 dark:bg-slate-800/50">
                                    <th :class="th">{{ t('att.date') }}</th>
                                    <th :class="th">{{ t('att.col.kind') }}</th>
                                    <th :class="th">{{ t('att.title_goal') }}</th>
                                    <th :class="th">{{ t('att.categories') }}</th>
                                    <th :class="th">{{ t('att.col.status') }}</th>
                                    <th :class="[th, 'text-end']">{{ t('att.minutes') }}</th>
                                    <th :class="th">{{ t('att.reason') }}</th>
                                    <th :class="th">{{ t('att.note') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in data.sessions" :key="row.session_id" class="border-t border-slate-100 dark:border-slate-800">
                                    <td class="whitespace-nowrap p-2 capitalize">
                                        <Link :href="route('attendance.sessions.show', row.session_id)" class="text-primary-700 hover:underline dark:text-primary-300">{{ dayLabel(row.date, locale) }}</Link>
                                        <span dir="ltr" class="ms-1 text-xs text-slate-400">{{ row.start_time }}</span>
                                    </td>
                                    <td class="whitespace-nowrap p-2">{{ t(`att.kind.${row.kind}`) }}</td>
                                    <td class="max-w-[14rem] truncate p-2" :title="row.title ?? ''">{{ row.title }}</td>
                                    <td class="p-2 text-slate-500">{{ row.categories.join(' · ') }}</td>
                                    <td class="p-2"><span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold" :style="chipStyle(row.status)">{{ label(row.status) }}</span></td>
                                    <td class="p-2 text-end tabular-nums">{{ row.minutes ?? '' }}</td>
                                    <td class="p-2">{{ reasonText(row) }}</td>
                                    <td class="p-2 text-slate-500">{{ row.note }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
```

- [ ] **Step 6: Put the card on the profile**

In `resources/js/Pages/Players/Show.vue` (keep the file's leading BOM untouched):

After `import AcademicSection from '@/Pages/Players/Partials/AcademicSection.vue';` add:
```js
import AttendanceSection from '@/Pages/Players/Partials/AttendanceSection.vue';
```
After `import { formatFileNumber, fileDrawer } from '@/lib/fileNumber';` add:
```js
import { useCan } from '@/Composables/useCan';
```
After `const { formatMoney } = useFormatMoney();` add:
```js
const { can } = useCan();
```
In `defineProps`, after the `documents` prop add:
```js
    // Status names and colours for the attendance card; null without attendance/view.
    attendanceCodes: { type: Object, default: null },
```
In the template, after the `<AcademicSection ... />` line add:
```vue

            <!-- Attendance: fetches its own data (attendance/view) after the profile has painted -->
            <AttendanceSection v-if="can('attendance', 'view')" :player="player" />
```

- [ ] **Step 7: Append the translations**

Create `att-i18n.mjs` in the repo root:
```js
import { readFileSync, writeFileSync } from 'node:fs';

const keys = {
    'att.sessions_unit': ['sessions', 'séances', 'حصص'],
    'att.profile.by_status': ['By status', 'Par statut', 'حسب الحالة'],
    'att.profile.sessions': ['Sessions', 'Séances', 'الحصص'],
    'att.profile.load_error': ['Attendance could not be loaded.', 'Impossible de charger les présences.', 'تعذّر تحميل الحضور.'],
    'att.retry': ['Try again', 'Réessayer', 'إعادة المحاولة'],
};

['en', 'fr', 'ar'].forEach((locale, i) => {
    const file = `resources/js/i18n/${locale}.json`;
    const text = readFileSync(file, 'utf8');
    const data = JSON.parse(text);
    if (JSON.stringify(data, null, 4) + '\n' !== text) throw new Error(`${locale}: file does not round-trip, stop`);
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
git diff -U0 resources/js/i18n | grep '^-[^-]'
```
Expected: exactly three removed lines, one per file.

- [ ] **Step 8: Build and run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendancePlayerCardTest|PlayerPaymentFlowTest|PlayerLevelPaymentTest|AttendanceStatsTest" && npm run i18n:check`
Expected: PASS (the two player tests confirm the profile still renders).

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/AttendancePlayerController.php app/Http/Controllers/PlayerController.php routes/web.php resources/js/Components/Activity/PeriodFilter.vue resources/js/Components/StatDoughnut.vue resources/js/Pages/Players/Partials/AttendanceSection.vue resources/js/Pages/Players/Show.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json tests/Feature/AttendancePlayerCardTest.php
git commit -m "feat(attendance): attendance card on the player profile, loaded on its own after the profile paints" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Player attendance PDF

**Files:**
- Create: `resources/views/pdf/attendance-player.blade.php`
- Modify: `app/Http/Controllers/AttendancePlayerController.php`, `routes/web.php`, `resources/js/Pages/Players/Partials/AttendanceSection.vue`, `resources/js/i18n/{en,fr,ar}.json`
- Test: `tests/Feature/AttendancePlayerReportTest.php` (new)

**Interfaces:**
- Consumes: `AttendancePlayerController::data()` (Task 4), `PdfService::stream()`, `ClubHeader::data()`, `AttendanceSettings::labels()` / `codes()` (Task 3), `Media::localFile($player->picture_url)`, `periodQuery()` from `lib/attendanceStats.js`.
- Produces: route `GET /attendance/players/{player}/report` named `attendance.players.report` (same `period`/`from`/`to` as the card), A4 portrait, RTL in Arabic; filename `attendance-{membership_id}-{from}-{to}.pdf`.
- Produces: Blade view `pdf.attendance-player` (variables: `club`, `player`, `photo`, `labels`, `codes`, `period`, `summary`, `monthly`, `preseason`, `sessions`).
- Produces: i18n keys `att.report_title`, `att.print_report`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendancePlayerReportTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Pdf\PdfService;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendancePlayerReportTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private const OCTOBER = ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'];

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

    private function userIn(string $locale): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function seedPlayer(): Player
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        $training = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Preseason, 'state' => SessionState::Held, 'title' => 'Running 7.2 km',
        ]);
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'status' => AttendanceStatus::Late, 'minutes' => 15, 'note' => 'Bus']);

        return $player;
    }

    private function spyPdf(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $html, string $filename, bool $rtl = true, bool $landscape = false) use ($seen) {
                $seen['html'] = $html;
                $seen['filename'] = $filename;
                $seen['rtl'] = $rtl;
                $seen['landscape'] = $landscape;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        return $seen;
    }

    #[Test]
    public function the_report_renders_as_a_pdf(): void
    {
        $player = $this->seedPlayer();

        $response = $this->actingAs($this->userIn('ar'))->get(route('attendance.players.report', $player))->assertOk();

        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
    }

    #[Test]
    public function the_report_lists_the_period_and_sessions_with_the_configured_names(): void
    {
        AttendanceSettings::save(['codes' => ['late' => ['label' => ['ar' => 'Tardy', 'fr' => 'Tardy', 'en' => 'Tardy']]]]);
        $player = $this->seedPlayer();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))
            ->get(route('attendance.players.report', ['player' => $player] + self::OCTOBER))
            ->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertFalse($seen['landscape']);
        $this->assertSame("attendance-{$player->membership_id}-2026-10-01-2026-10-31.pdf", $seen['filename']);
        $this->assertStringContainsString('Rapport de présence', $seen['html']);
        $this->assertStringContainsString($player->fullname, $seen['html']);
        $this->assertStringContainsString('2026-10-01 – 2026-10-31', $seen['html']);
        $this->assertStringContainsString('Running 7.2 km', $seen['html']);
        $this->assertStringContainsString('Tardy', $seen['html']);
        $this->assertStringContainsString('Bus', $seen['html']);
    }

    #[Test]
    public function the_report_is_right_to_left_in_arabic(): void
    {
        $player = $this->seedPlayer();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('ar'))->get(route('attendance.players.report', ['player' => $player] + self::OCTOBER))->assertOk();

        $this->assertTrue($seen['rtl']);
        $this->assertStringContainsString('تقرير الحضور', $seen['html']);
        $this->assertStringContainsString('متأخر', $seen['html']);   // unset name: built-in Arabic
    }

    #[Test]
    public function the_report_needs_attendance_view(): void
    {
        $player = $this->seedPlayer();
        $playersOnly = User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => ['players' => ['view']]])->id]);

        $this->actingAs($playersOnly)->get(route('attendance.players.report', $player))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter=AttendancePlayerReportTest`
Expected: FAIL — `Route [attendance.players.report] not defined.`

- [ ] **Step 3: Route and action**

In `routes/web.php`, after the `attendance.players.show` route:
```php
        Route::get('/attendance/players/{player}/report', [AttendancePlayerController::class, 'report'])->name('attendance.players.report');
```

In `app/Http/Controllers/AttendancePlayerController.php`, add the imports:
```php
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\AttendanceSettings;
use App\Support\Media;
use Symfony\Component\HttpFoundation\Response;
```
replace the constructor with:
```php
    public function __construct(
        private readonly AttendanceStats $stats,
        private readonly PreseasonProgress $preseason,
        private readonly PdfService $pdf,
    ) {}
```
and add after `show()`:
```php
    /** The card as a printable PDF, same period (A4 portrait, right-to-left in Arabic). */
    public function report(Request $request, Player $player): Response
    {
        $player->loadMissing('category');
        $data = $this->data($request, $player);

        $html = view('pdf.attendance-player', [
            ...$data,
            'club' => ClubHeader::data(),
            'player' => $player,
            'photo' => Media::localFile($player->picture_url),
            'labels' => AttendanceSettings::labels(),
            'codes' => AttendanceSettings::codes(),
        ])->render();

        return $this->pdf->stream(
            $html,
            "attendance-{$player->membership_id}-{$data['period']['from']}-{$data['period']['to']}.pdf",
            app()->getLocale() === 'ar',
        );
    }
```

- [ ] **Step 4: The PDF view**

`resources/views/pdf/attendance-player.blade.php`:
```blade
@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $pct = fn ($value) => $value === null ? '—' : number_format((float) $value, 1).'%';
    $statuses = array_keys($labels);
    $preseasonLine = null;
    if ($preseason) {
        $preseasonLine = $preseason['target']
            ? strtr($L('att.preseason_progress'), ['{season}' => $preseason['season'], '{done}' => $preseason['done'], '{target}' => $preseason['target']])
            : strtr($L('att.preseason_no_target'), ['{season}' => $preseason['season'], '{done}' => $preseason['done']]);
    }
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 11px; color: #1e293b; }
        .title { font-size: 18px; font-weight: bold; color: #02a85c; margin: 6px 0 10px; }
        .photo { width: 70px; height: 70px; border-radius: 8px; }
        .muted { color: #64748b; }
        h2 { font-size: 12px; color: #0f172a; margin: 12px 0 4px; }
        table.rows { width: 100%; border-collapse: collapse; }
        table.rows th { background: #f1f5f9; color: #334155; font-size: 9px; padding: 4px; border: 1px solid #e2e8f0; }
        table.rows td { padding: 4px; border: 1px solid #e2e8f0; vertical-align: top; }
        .num { text-align: center; }
        .pct { color: #64748b; font-size: 8px; }
        .empty { padding: 16px; text-align: center; color: #94a3b8; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ $L('att.report_title') }}</div>

    <table style="width:100%; margin-bottom:8px;">
        <tr>
            @if (!empty($photo))
                <td style="width:80px; vertical-align:top;"><img class="photo" src="{{ $photo }}"></td>
            @endif
            <td style="vertical-align:top;">
                <div style="font-size:14px; font-weight:bold;">{{ $player->fullname }}</div>
                <div style="font-family:monospace;">{{ $player->membership_id }}</div>
                <div class="muted">{{ $player->category?->localized_name }}</div>
                <div class="muted">{{ $L('activity.period_label') }}: <bdi dir="ltr">{{ $period['label'] }}</bdi></div>
                @if ($preseasonLine)
                    <div class="muted">{{ $preseasonLine }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="rows">
        <tr>
            <th>{{ $L('att.col.expected') }}</th>
            @foreach ($statuses as $status)
                <th><span style="color: {{ $codes[$status]['color'] }};">&#9632;</span> {{ $labels[$status] }}</th>
            @endforeach
            <th>{{ $L('att.col.late_minutes') }}</th>
            <th>{{ $L('att.col.missed_hours') }}</th>
            <th>{{ $L('att.col.score') }}</th>
            <th>{{ $L('att.col.score_pct') }}</th>
        </tr>
        <tr>
            <td class="num">{{ $summary['expected'] }}</td>
            @foreach ($statuses as $status)
                <td class="num">{{ $summary['counts'][$status] }} <span class="pct"><bdi dir="ltr">{{ $pct($summary['pct'][$status]) }}</bdi></span></td>
            @endforeach
            <td class="num">{{ $summary['late_minutes'] }}</td>
            <td class="num">{{ number_format((float) $summary['missed_hours'], 1) }}</td>
            <td class="num"><bdi dir="ltr">{{ number_format((float) $summary['score'], 2) }}</bdi></td>
            <td class="num"><bdi dir="ltr">{{ $pct($summary['score_pct']) }}</bdi></td>
        </tr>
    </table>

    <h2>{{ $L('att.profile.sessions') }}</h2>
    @if (empty($sessions))
        <div class="empty">{{ $L('att.stats.no_data') }}</div>
    @else
        <table class="rows">
            <tr>
                <th>{{ $L('att.date') }}</th>
                <th>{{ $L('att.col.time') }}</th>
                <th>{{ $L('att.col.kind') }}</th>
                <th>{{ $L('att.title_goal') }}</th>
                <th>{{ $L('att.categories') }}</th>
                <th>{{ $L('att.col.status') }}</th>
                <th>{{ $L('att.minutes') }}</th>
                <th>{{ $L('att.reason') }}</th>
                <th>{{ $L('att.note') }}</th>
            </tr>
            @foreach ($sessions as $row)
                <tr>
                    <td class="num"><bdi dir="ltr">{{ $row['date'] }}</bdi></td>
                    <td class="num"><bdi dir="ltr">{{ $row['start_time'] }}–{{ $row['end_time'] }}</bdi></td>
                    <td>{{ $L('att.kind.'.$row['kind']) }}</td>
                    <td>{{ $row['title'] }}</td>
                    <td>{{ implode(' · ', $row['categories']) }}</td>
                    <td><span style="color: {{ $codes[$row['status']]['color'] ?? '#64748b' }};">&#9632;</span> {{ $labels[$row['status']] ?? $row['status'] }}</td>
                    <td class="num">{{ $row['minutes'] }}</td>
                    <td>{{ $row['reason'] ? $L('att.reason.'.$row['reason']) : '' }}</td>
                    <td>{{ $row['note'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
```

- [ ] **Step 5: The PDF button on the card**

In `resources/js/Pages/Players/Partials/AttendanceSection.vue`:

Add the import after `import StatDoughnut from '@/Components/StatDoughnut.vue';`:
```js
import Icon from '@/Components/Icon.vue';
```
Replace `import { hours, pct, statusBars } from '@/lib/attendanceStats';` with:
```js
import { hours, pct, periodQuery, statusBars } from '@/lib/attendanceStats';
```
Add after `const monthlyOptions = ...;`:
```js
// Same period as the card.
const reportHref = computed(() => (data.value ? route('attendance.players.report', { player: props.player.id, ...periodQuery(data.value.period) }) : null));
```
In the template, replace
```vue
            <PeriodFilter v-if="data" :period="data.period" @change="load" />
```
with
```vue
            <div v-if="data" class="flex flex-wrap items-center gap-2">
                <PeriodFilter :period="data.period" @change="load" />
                <a :href="reportHref" target="_blank" class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800">
                    <Icon name="print" /> {{ t('att.print_report') }}
                </a>
            </div>
```

- [ ] **Step 6: Append the translations**

Create `att-i18n.mjs` in the repo root:
```js
import { readFileSync, writeFileSync } from 'node:fs';

const keys = {
    'att.report_title': ['Attendance report', 'Rapport de présence', 'تقرير الحضور'],
    'att.print_report': ['Attendance report (PDF)', 'Rapport de présence (PDF)', 'تقرير الحضور (PDF)'],
};

['en', 'fr', 'ar'].forEach((locale, i) => {
    const file = `resources/js/i18n/${locale}.json`;
    const text = readFileSync(file, 'utf8');
    const data = JSON.parse(text);
    if (JSON.stringify(data, null, 4) + '\n' !== text) throw new Error(`${locale}: file does not round-trip, stop`);
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
git diff -U0 resources/js/i18n | grep '^-[^-]'
```
Expected: exactly three removed lines, one per file.

- [ ] **Step 7: Build and run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendancePlayerReportTest|AttendancePlayerCardTest" && npm run i18n:check`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/AttendancePlayerController.php routes/web.php resources/views/pdf/attendance-player.blade.php resources/js/Pages/Players/Partials/AttendanceSection.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json tests/Feature/AttendancePlayerReportTest.php
git commit -m "feat(attendance): per-player attendance report PDF from the profile card" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Dashboard card (members tab)

**Files:**
- Create: `app/Services/Dashboard/AttendanceCard.php`, `resources/js/Pages/Dashboard/Partials/AttendanceCard.vue`
- Modify: `app/Http/Controllers/DashboardController.php`, `resources/js/Pages/Dashboard/Partials/MembersTab.vue`, `resources/js/Pages/Dashboard.vue`, `resources/js/i18n/{en,fr,ar}.json`, `tests/Feature/Dashboard/DashboardPageTest.php`
- Test: `tests/Feature/Dashboard/DashboardAttendanceCardTest.php` (new)

**Interfaces:**
- Consumes: `CalendarFeed::sessions(string $from, string $to): Collection` (rows `{id, date, start_time, end_time, kind, state, title, cancel_reason, categories: [{id, name}], marked, summary}`), `SessionGenerator::forMonth(int, int, int)`, `AttendanceStats::players()` / `summarize()`, `TrainingSchedule`, `ClubClosure`, `StatusBreakdown.vue` (Task 2), `KIND_DOT` from `lib/attendanceCalendar.js`.
- Produces: `App\Services\Dashboard\AttendanceCard::get(): array{date: string, today: list<session row>, last30: array{from: string, to: string, expected: int, counts: array<string,int>, pct: array<string,?float>}}`; `const DAYS = 30`.
- Produces: dashboard `members` payload key `attendance` (the array above, or `null` without attendance/view); top-level Dashboard prop `attendanceCodes` (codes or `null`).
- Produces: i18n keys `att.dash.today`, `att.dash.none_today`, `att.dash.last30`, `att.dash.no_marks`, `att.dash.open_calendar`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Dashboard/DashboardAttendanceCardTest.php`:
```php
<?php

namespace Tests\Feature\Dashboard;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\ClubClosure;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class DashboardAttendanceCardTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');   // a Tuesday (ISO weekday 2)
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    /** The members tab, as the client asks for it (partial reload). */
    private function membersTab(User $user): array
    {
        return $this->actingAs($user)->get(route('dashboard'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => 'members',
        ])->assertOk()->json('props.members');
    }

    private function heldSession(Category $category, string $date, ?string $title = null): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held, 'title' => $title,
        ]);
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status, ?int $minutes = null): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'status' => $status, 'minutes' => $minutes]);
    }

    #[Test]
    public function the_members_tab_shows_todays_sessions_and_the_last_30_days(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $this->mark($this->heldSession($u15, '2026-10-20', 'Sprints'), $a, AttendanceStatus::Present);
        $this->mark($this->heldSession($u15, '2026-09-21'), $a, AttendanceStatus::Late, 5);        // day 30 of the window
        $this->mark($this->heldSession($u15, '2026-09-20'), $a, AttendanceStatus::AbsentUnexcused); // day 31: outside

        $card = $this->membersTab($this->admin())['attendance'];

        $this->assertSame('2026-10-20', $card['date']);
        $this->assertCount(1, $card['today']);
        $this->assertSame('Sprints', $card['today'][0]['title']);
        $this->assertSame('held', $card['today'][0]['state']);
        $this->assertSame('U15', $card['today'][0]['categories'][0]['name']);
        $this->assertSame('2026-09-21', $card['last30']['from']);
        $this->assertSame('2026-10-20', $card['last30']['to']);
        $this->assertSame(2, $card['last30']['expected']);
        $this->assertSame(1, $card['last30']['counts']['present']);
        $this->assertSame(1, $card['last30']['counts']['late']);
        $this->assertSame(0, $card['last30']['counts']['absent_unexcused']);
    }

    #[Test]
    public function a_training_day_is_generated_when_nobody_opened_the_month_yet(): void
    {
        $u15 = $this->category('U15');
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 2, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);

        $card = $this->membersTab($this->admin())['attendance'];

        $this->assertCount(1, $card['today']);
        $this->assertSame('planned', $card['today'][0]['state']);
        $this->assertSame('18:00', $card['today'][0]['start_time']);
        $this->assertTrue(TrainingSession::where('category_id', $u15->id)->where('date', '2026-10-20')->exists());
    }

    #[Test]
    public function nothing_is_generated_on_a_closure_day(): void
    {
        $u15 = $this->category('U15');
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 2, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);
        ClubClosure::create(['start_date' => '2026-10-19', 'end_date' => '2026-10-25', 'reason' => 'Holidays']);

        $card = $this->membersTab($this->admin())['attendance'];

        $this->assertSame([], $card['today']);
        $this->assertFalse(TrainingSession::where('category_id', $u15->id)->exists());
    }

    #[Test]
    public function the_card_and_the_codes_need_attendance_view(): void
    {
        $playersOnly = $this->userWith(['players' => ['view']]);
        $this->assertNull($this->membersTab($playersOnly)['attendance']);
        $this->actingAs($playersOnly)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('attendanceCodes', null));

        $both = $this->userWith(['players' => ['view'], 'attendance' => ['view']]);
        $this->assertIsArray($this->membersTab($both)['attendance']);
        $this->actingAs($both)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('attendanceCodes.present.code', 'P'));
    }
}
```

In `tests/Feature/Dashboard/DashboardPageTest.php`, in `every_tab_stays_within_its_query_budget()`, replace
```php
        // members: +3 for leavers — the "left" tile, left-per-month on the growth
        // chart, and departures by category.
        foreach (['members' => 21, 'operations' => 12] as $tab => $budget) {
```
with
```php
        // members: +3 for leavers — the "left" tile, left-per-month on the growth
        // chart, and departures by category.
        // members: +5 for the attendance card — today's schedules, today's
        // sessions, the attendance settings, and the last 30 days' marks
        // grouped by status and by session length.
        foreach (['members' => 26, 'operations' => 12] as $tab => $budget) {
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="DashboardAttendanceCardTest"`
Expected: FAIL — `Undefined array key "attendance"`.

- [ ] **Step 3: The card service**

`app/Services/Dashboard/AttendanceCard.php`:
```php
<?php

namespace App\Services\Dashboard;

use App\Models\ClubClosure;
use App\Models\TrainingSchedule;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\CalendarFeed;
use App\Services\Attendance\SessionGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The members tab's attendance card: today's sessions and the last 30 days
 * by status. Fixed windows, so the dashboard's range and branch filters do
 * not apply (attendance has no branch).
 */
final class AttendanceCard
{
    public const DAYS = 30;

    public function __construct(
        private readonly CalendarFeed $feed,
        private readonly SessionGenerator $generator,
        private readonly AttendanceStats $stats,
    ) {}

    /** @return array{date: string, today: list<array<string, mixed>>, last30: array<string, mixed>} */
    public function get(): array
    {
        $today = CarbonImmutable::today();
        $date = $today->toDateString();
        $this->generateToday($today);

        $from = $today->subDays(self::DAYS - 1)->toDateString();
        $totals = $this->stats->summarize($this->stats->players($from, $date));

        return [
            'date' => $date,
            'today' => $this->feed->sessions($date, $date)->all(),
            'last30' => [
                'from' => $from,
                'to' => $date,
                'expected' => $totals['expected'],
                'counts' => $totals['counts'],
                'pct' => $totals['pct'],
            ],
        ];
    }

    /**
     * Planned sessions exist once someone opens the month in the calendar.
     * When a category trains today by its weekly schedule but has no session
     * yet, generate its month first (idempotent, as the calendar does), so the
     * card never says "no sessions" on a training day. One query on most
     * visits; nothing on a club closure day.
     */
    private function generateToday(CarbonImmutable $today): void
    {
        $date = $today->toDateString();

        $scheduled = TrainingSchedule::where('weekday', $today->dayOfWeekIso)
            ->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->distinct()
            ->pluck('category_id')
            ->map(fn ($id) => (int) $id);
        if ($scheduled->isEmpty()) {
            return;
        }

        $withSession = DB::table('training_session_category')
            ->join('training_sessions', 'training_sessions.id', '=', 'training_session_category.training_session_id')
            ->where('training_sessions.date', $date)
            ->whereIn('training_session_category.category_id', $scheduled)
            ->distinct()
            ->pluck('training_session_category.category_id')
            ->map(fn ($id) => (int) $id);
        $missing = $scheduled->diff($withSession)->values();
        if ($missing->isEmpty() || ClubClosure::where('start_date', '<=', $date)->where('end_date', '>=', $date)->exists()) {
            return;
        }

        DB::transaction(function () use ($missing, $today) {
            foreach ($missing as $categoryId) {
                $this->generator->forMonth($categoryId, $today->year, $today->month);
            }
        });
    }
}
```

- [ ] **Step 4: Wire it into the dashboard**

In `app/Http/Controllers/DashboardController.php`:

Add the imports:
```php
use App\Services\Dashboard\AttendanceCard;
use App\Support\AttendanceSettings;
```
Replace the constructor with:
```php
    public function __construct(
        private readonly HeroStats $hero,
        private readonly OverviewStats $overview,
        private readonly FinanceStats $finance,
        private readonly MemberStats $members,
        private readonly OperationsStats $operations,
        private readonly AttendanceCard $attendance,
    ) {}
```
In `__invoke()`, add after the `'hero' => ...` entry:
```php
            // Status names and colours for the members tab's attendance card.
            'attendanceCodes' => fn (): ?array => $user?->hasPermission('attendance', 'view')
                ? AttendanceSettings::codes()
                : null,
```
In `tabProps()`, replace
```php
            'members' => fn (): ?array => $this->guard($user, 'members')
                ? $this->members->get($filters)
                : null,
```
with
```php
            'members' => fn (): ?array => $this->guard($user, 'members')
                ? [
                    ...$this->members->get($filters),
                    // The attendance card rides with the members tab; it needs
                    // attendance/view on top of the tab's players/view.
                    'attendance' => $user->hasPermission('attendance', 'view') ? $this->attendance->get() : null,
                ]
                : null,
```

- [ ] **Step 5: The card component**

`resources/js/Pages/Dashboard/Partials/AttendanceCard.vue`:
```vue
<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import StatusBreakdown from '@/Components/Attendance/StatusBreakdown.vue';
import { Card, CardHeader, CardTitle } from '@/Components/ui/card';
import { Separator } from '@/Components/ui/separator';
import { KIND_DOT } from '@/lib/attendanceCalendar';

/** Today's sessions and the last 30 days by status (AttendanceCard on the server). */
const props = defineProps({
    data: { type: Object, required: true }, // { date, today: [], last30: { from, to, expected, counts, pct } }
});
const { t } = useI18n();

const hasMarks = computed(() => props.data.last30.expected > 0);
const stateClass = {
    planned: 'text-muted-foreground',
    held: 'text-emerald-600 dark:text-emerald-400',
    cancelled: 'text-muted-foreground line-through',
};
</script>

<template>
    <Card class="border-border/70 shadow-none">
        <CardHeader class="flex-row items-center justify-between space-y-0 px-5 py-4">
            <CardTitle class="text-base">{{ t('attendance') }}</CardTitle>
            <div class="flex gap-3 text-xs font-medium">
                <Link :href="route('attendance.index')" class="text-primary-700 hover:underline dark:text-primary-300">{{ t('att.dash.open_calendar') }}</Link>
                <Link :href="route('attendance.stats')" class="text-primary-700 hover:underline dark:text-primary-300">{{ t('att.statistics') }}</Link>
            </div>
        </CardHeader>
        <Separator />
        <div class="grid gap-5 px-5 py-4 lg:grid-cols-2">
            <section>
                <h3 class="text-sm font-semibold text-muted-foreground">{{ t('att.dash.today') }}</h3>
                <p v-if="!data.today.length" class="mt-3 text-sm text-muted-foreground">{{ t('att.dash.none_today') }}</p>
                <ul v-else class="mt-2 divide-y divide-border/70">
                    <li v-for="s in data.today" :key="s.id">
                        <Link :href="route('attendance.sessions.show', s.id)" class="flex items-center gap-3 rounded-md px-1 py-2 text-sm hover:bg-muted/40">
                            <span dir="ltr" class="w-24 shrink-0 tabular-nums text-muted-foreground">{{ s.start_time }}–{{ s.end_time }}</span>
                            <span class="h-2 w-2 shrink-0 rounded-full" :class="KIND_DOT[s.kind]" :title="t(`att.kind.${s.kind}`)"></span>
                            <span class="min-w-0 flex-1 truncate">
                                <span class="font-medium">{{ s.categories.map((c) => c.name).join(' · ') }}</span>
                                <span v-if="s.title" class="text-muted-foreground"> — {{ s.title }}</span>
                            </span>
                            <span class="shrink-0 text-xs" :class="stateClass[s.state]">{{ t(`att.state.${s.state}`) }}</span>
                        </Link>
                    </li>
                </ul>
            </section>
            <section>
                <h3 class="text-sm font-semibold text-muted-foreground">{{ t('att.dash.last30') }}</h3>
                <p v-if="!hasMarks" class="mt-3 text-sm text-muted-foreground">{{ t('att.dash.no_marks') }}</p>
                <div v-else class="mt-3">
                    <StatusBreakdown :counts="data.last30.counts" />
                </div>
            </section>
        </div>
    </Card>
</template>
```

In `resources/js/Pages/Dashboard/Partials/MembersTab.vue`:

After `import StatTile from '@/Components/Dashboard/StatTile.vue';` add:
```js
import AttendanceCard from '@/Pages/Dashboard/Partials/AttendanceCard.vue';
```
Replace
```vue
        <section v-if="academic && academic.students" class="space-y-3" :aria-label="t('dashboard.mem_academic')">
```
with
```vue
        <AttendanceCard v-if="data?.attendance" :data="data.attendance" />

        <section v-if="academic && academic.students" class="space-y-3" :aria-label="t('dashboard.mem_academic')">
```

In `resources/js/Pages/Dashboard.vue`, add to `defineProps` after `operations`:
```js
    // Status names and colours for the attendance card; null without attendance/view.
    attendanceCodes: { type: Object, default: null },
```

- [ ] **Step 6: Append the translations**

Create `att-i18n.mjs` in the repo root:
```js
import { readFileSync, writeFileSync } from 'node:fs';

const keys = {
    'att.dash.today': ["Today's sessions", 'Séances du jour', 'حصص اليوم'],
    'att.dash.none_today': ['No sessions today.', "Aucune séance aujourd'hui.", 'لا توجد حصص اليوم.'],
    'att.dash.last30': ['Last 30 days', '30 derniers jours', 'آخر 30 يومًا'],
    'att.dash.no_marks': ['No attendance recorded in the last 30 days.', 'Aucune présence enregistrée ces 30 derniers jours.', 'لم يُسجَّل أي حضور خلال آخر 30 يومًا.'],
    'att.dash.open_calendar': ['Open the calendar', 'Ouvrir le calendrier', 'فتح التقويم'],
};

['en', 'fr', 'ar'].forEach((locale, i) => {
    const file = `resources/js/i18n/${locale}.json`;
    const text = readFileSync(file, 'utf8');
    const data = JSON.parse(text);
    if (JSON.stringify(data, null, 4) + '\n' !== text) throw new Error(`${locale}: file does not round-trip, stop`);
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
git diff -U0 resources/js/i18n | grep '^-[^-]'
```
Expected: exactly three removed lines, one per file.

- [ ] **Step 7: Build and run the tests to see them pass**

Run: `npm run build && php artisan test --filter="DashboardAttendanceCardTest|DashboardPageTest|MemberStatsTest" && npm run i18n:check`
Expected: PASS. If the members budget fails, read the listed SQL: the card must add exactly the five queries named in the comment (no generation runs in that test: it has no schedule). Fix a leak rather than raising the number.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Dashboard/AttendanceCard.php app/Http/Controllers/DashboardController.php resources/js/Pages/Dashboard/Partials/AttendanceCard.vue resources/js/Pages/Dashboard/Partials/MembersTab.vue resources/js/Pages/Dashboard.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json tests/Feature/Dashboard/DashboardAttendanceCardTest.php tests/Feature/Dashboard/DashboardPageTest.php
git commit -m "feat(attendance): dashboard card with today's sessions and the last 30 days by status" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Full verification

- [ ] **Step 1: Full test suite**

Run: `php artisan test`
Expected: all green. Fix any failure in the task that caused it, and commit the fix with the explicit paths it touched.

- [ ] **Step 2: Style**

Run: `vendor/bin/pint --dirty`, then `git status --short`.
If pint changed files, stage exactly those paths and commit:
```bash
git add <the files pint changed>
git commit -m "style(attendance): pint" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 3: Frontend build and translations**

Run: `npm run build && npm run i18n:check`
Expected: the build succeeds with no missing-import warnings, and the check prints no `does not resolve` lines. Also run:
```bash
git diff main --stat -- resources/js/i18n
git diff main -U0 -- resources/js/i18n | grep '^-[^-]' | wc -l
```
Expected: the second command prints `3` (in each file only the pre-2b last line changed, by gaining a comma). And `git status --short` shows no leftover `att-i18n.mjs`.

- [ ] **Step 4: No migration, nothing unstaged**

Run: `git diff main --stat -- database/migrations && git status --short`
Expected: no migration changed; only untracked files that were already there before 2b (e.g. `.superpowers/`).

- [ ] **Step 5: Manual check in Arabic and in French**

Serve the worktree (`php artisan serve --port=2027`), with a few held sessions in two months (one of them a joint pre-season session), one cancelled session, and a late of more than 30 minutes. Go through this list twice, once with the interface in **ar** and once in **fr**:
1. Attendance calendar → "Statistics": the page opens on this month; the period picker switches to the season and to a custom range, and the URL keeps `period`, `from`, `to` (and `category_id`).
2. Tiles: sessions held and cancelled match the calendar; the cancelled session's marks are not counted.
3. "Marks per month" stacks the statuses in the colours set in Settings → Codes (change a colour and a name, reload: both follow); the table toggle lists the same numbers.
4. "Sessions held and cancelled per month" shows the cancelled session.
5. Category table: the joint pre-season session counts as held for each of its categories; the pre-season column reads `done/target`; the selected category's row is highlighted.
6. Player table: sort by name, by each status, by late minutes, by missed hours and by score % (arrows flip); percentages read left to right in Arabic; names link to the profile only for a user with players/view.
7. Top 5 / bottom 5: only players with at least 5 expected sessions; nobody appears in both lists; the help line shows 5.
8. Export → Excel, CSV and PDF: the files open; column headers use the configured names; the PDF is landscape, right-to-left in Arabic, with the club header.
9. Player profile (user with attendance/view): the Attendance card appears after the profile, on the current season; doughnut and monthly bars in the configured colours; the session list shows date, type, title, categories, status chip, minutes, reason, note; switching the period reloads only the card; "Attendance report (PDF)" prints the same period.
10. Same profile with a user who has players/view but not attendance/view: no Attendance card, and `/attendance/players/{id}` answers 403.
11. Dashboard → Members: the attendance card lists today's sessions (a scheduled category appears even if its month was never opened in the calendar) and the last 30 days' breakdown; its links open the calendar and the statistics.

- [ ] **Step 6: Hand-off**

Report to the owner: what shipped in 2b, the resolved ambiguities listed under Global Constraints, and the deploy notes. 2b adds no migration and no new setting; `npm run build` ships the new pages. Next: the P1-spec paper sheets (P2) and follow-up (P4: watch list, parent letter, ranking certificate, injury history), which can reuse `AttendanceStats`.
