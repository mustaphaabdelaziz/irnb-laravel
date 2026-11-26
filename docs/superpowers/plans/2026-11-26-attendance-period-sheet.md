# Attendance Period Sheet + Totals Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Print recorded attendance for any period (one page per month + summary), and show Present · Absent · Rate on the filled month and session sheets.

**Architecture:** A pure `SheetTotals` helper counts a player's marks. `MonthSheet::build()` gains optional date bounds and returns raw statuses. A new `PeriodSheet` service loops `MonthSheet` month by month per category/training group, and a new invokable controller renders `pdf/attendance-period-sheet.blade.php`. Shared column building moves to `SheetColumns`, and the shared table/legend markup moves to two Blade partials used by both the month and period sheets.

**Tech Stack:** Laravel 13, PHPUnit feature tests, mPDF via `App\Services\Pdf\PdfService`, Inertia + Vue 3, vue-i18n JSON catalogs (`resources/js/i18n/{ar,fr,en}.json`, also read server-side by `App\Support\UiLang`).

Spec: `docs/superpowers/specs/2026-11-26-attendance-period-sheet-design.md`.

## Global Constraints

- Present = `present` + `late` + `left_early` behaviours. Absent = `absent_excused` + `absent_unexcused`. `not_training` = neither. `not_counted` = not expected.
- Rate = `round(present / expected × 100, 1)`; null (printed «—») when expected = 0. Printed inside `<bdi dir="ltr">` as `90.0%`.
- Unmarked cells count nowhere.
- Period span ≤ 13 months (`PeriodSheet::MAX_MONTHS`). Months after the current month are skipped.
- Blank month sheet unchanged. Totals only on the filled month sheet, on the last part.
- New route `attendance.sheets.period` → `['attendance', 'view']` in `config/permissions.php`.
- i18n: insert-only (add new lines, never reorder or rewrite others), same key in ar, fr, en; `npm run i18n:check` must pass. No key segment after a message may be a String method name (`link`, `search`, …).
- Offline only: no CDN or remote resources.
- Work in a worktree (`D:/irnb-period-sheet`, branch `feat/attendance-period-sheet`, copy `vendor/` and run `npm ci && npm run build`; no junctions). Stage explicit files only.
- Tests: `php vendor/phpunit/phpunit/phpunit --filter <Name>`. Whole suite: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit` (never in parallel). Run `php artisan config:clear` first if the config is cached.
- Commit trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` (check subagents did not write another model).

---

### Task 1: `SheetTotals` helper

**Files:**
- Create: `app/Services/Attendance/SheetTotals.php`
- Test: `tests/Feature/AttendanceSheetTotalsTest.php`

**Interfaces:**
- Produces: `SheetTotals::of(iterable $statuses): array{present:int, absent:int, expected:int, rate:?float}`, `SheetTotals::sum(array $rows): array{...same}` (static), `SheetTotals::rateLabel(?float $rate): string` (static, `"90.0%"` or `"—"`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\AttendanceCustomStatus;
use App\Services\Attendance\SheetTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendanceSheetTotalsTest extends TestCase
{
    use RefreshDatabase;

    private function custom(string $code, string $behaviour): string
    {
        return AttendanceCustomStatus::createWithKey([
            'code' => $code, 'color' => '#7c3aed', 'label_fr' => $code, 'behaviour' => $behaviour, 'is_active' => true,
        ])->key;
    }

    #[Test]
    public function present_late_and_left_early_count_as_present_absences_as_absent(): void
    {
        $totals = app(SheetTotals::class)->of(['present', 'late', 'left_early', 'absent_excused', 'absent_unexcused', 'not_training']);

        $this->assertSame(['present' => 3, 'absent' => 2, 'expected' => 6, 'rate' => 50.0], $totals);
    }

    #[Test]
    public function a_custom_code_counts_as_its_behaviour_and_not_counted_is_left_out(): void
    {
        $trip = $this->custom('VO', 'present');
        $off = $this->custom('NC', 'not_counted');

        $totals = app(SheetTotals::class)->of([$trip, $off, 'absent_unexcused']);

        $this->assertSame(['present' => 1, 'absent' => 1, 'expected' => 2, 'rate' => 50.0], $totals);
    }

    #[Test]
    public function nothing_marked_gives_a_null_rate(): void
    {
        $this->assertSame(['present' => 0, 'absent' => 0, 'expected' => 0, 'rate' => null], app(SheetTotals::class)->of([]));
    }

    #[Test]
    public function sum_adds_rows_and_recomputes_the_rate(): void
    {
        $sum = SheetTotals::sum([
            ['present' => 2, 'absent' => 1, 'expected' => 3, 'rate' => 66.7],
            ['present' => 1, 'absent' => 0, 'expected' => 3, 'rate' => 33.3],
        ]);

        $this->assertSame(['present' => 3, 'absent' => 1, 'expected' => 6, 'rate' => 50.0], $sum);
    }

    #[Test]
    public function the_rate_label_has_one_decimal_or_a_dash(): void
    {
        $this->assertSame('90.0%', SheetTotals::rateLabel(90.0));
        $this->assertSame('66.7%', SheetTotals::rateLabel(66.7));
        $this->assertSame('—', SheetTotals::rateLabel(null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/phpunit/phpunit/phpunit --filter AttendanceSheetTotalsTest`
Expected: FAIL, `Class "App\Services\Attendance\SheetTotals" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services\Attendance;

/**
 * The Present · Absent · Rate totals printed on the attendance sheets, from
 * the marks of the cells printed. Not the points-based score of the
 * statistics: present = trained (even partly), absent = excused or not,
 * «came, did not train» neither; a not_counted code is not expected;
 * an unmarked cell is not passed in, so it counts nowhere.
 */
final class SheetTotals
{
    public const PRESENT = ['present', 'late', 'left_early'];

    public const ABSENT = ['absent_excused', 'absent_unexcused'];

    public function __construct(private readonly AttendanceStatusCatalog $catalog) {}

    /**
     * @param  iterable<string>  $statuses  status keys of one player's marked cells
     * @return array{present: int, absent: int, expected: int, rate: ?float}
     */
    public function of(iterable $statuses): array
    {
        $present = $absent = $expected = 0;
        foreach ($statuses as $status) {
            $behaviour = $this->catalog->behaviour((string) $status);
            if ($behaviour === AttendanceStatusCatalog::NOT_COUNTED) {
                continue;
            }
            $expected++;
            if (in_array($behaviour, self::PRESENT, true)) {
                $present++;
            } elseif (in_array($behaviour, self::ABSENT, true)) {
                $absent++;
            }
        }

        return self::row($present, $absent, $expected);
    }

    /**
     * @param  list<array{present: int, absent: int, expected: int}>  $rows
     * @return array{present: int, absent: int, expected: int, rate: ?float}
     */
    public static function sum(array $rows): array
    {
        return self::row(
            array_sum(array_column($rows, 'present')),
            array_sum(array_column($rows, 'absent')),
            array_sum(array_column($rows, 'expected')),
        );
    }

    /** "90.0%", or "—" when nothing was expected. */
    public static function rateLabel(?float $rate): string
    {
        return $rate === null ? '—' : number_format($rate, 1).'%';
    }

    /** @return array{present: int, absent: int, expected: int, rate: ?float} */
    private static function row(int $present, int $absent, int $expected): array
    {
        return [
            'present' => $present,
            'absent' => $absent,
            'expected' => $expected,
            'rate' => $expected > 0 ? round($present / $expected * 100, 1) : null,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/phpunit/phpunit/phpunit --filter AttendanceSheetTotalsTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/SheetTotals.php tests/Feature/AttendanceSheetTotalsTest.php
git commit -m "feat(attendance): SheetTotals counts present, absent and rate for printed sheets"
```

---

### Task 2: `MonthSheet` date bounds, raw statuses, guest categories

**Files:**
- Modify: `app/Services/Attendance/MonthSheet.php` (`build()`, new `guestCategories()`)
- Modify: `app/Http/Controllers/AttendanceSheetController.php` (`month()` uses `guestCategories()`)
- Test: `tests/Feature/AttendanceMonthSheetBoundsTest.php`

**Interfaces:**
- Produces:
  - `MonthSheet::build(int $categoryId, int $year, int $month, ?int $branchId = null, ?int $groupId = null, ?string $from = null, ?string $to = null): array{sessions: Collection, cells: array, statuses: array<int, array<int, string>>, players: Collection}`. `statuses[playerId][sessionId]` = raw status key of each marked cell.
  - `MonthSheet::guestCategories(Collection $players, int $categoryId, ?int $branchId): array<int, string>` → playerId ⇒ localized name of the player's current category in the branch, only for players whose category is not `$categoryId`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\TrainingSession;
use App\Services\Attendance\MonthSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceMonthSheetBoundsTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function training(Category $category, string $date): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
    }

    #[Test]
    public function bounds_cut_the_month_and_no_bounds_keep_it_whole(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $early = $this->training($u15, '2026-10-05');
        $late = $this->training($u15, '2026-10-20');
        $sheet = app(MonthSheet::class);

        $this->assertSame([$early->id, $late->id], $sheet->build($u15->id, 2026, 10)['sessions']->modelKeys());
        $this->assertSame([$late->id], $sheet->build($u15->id, 2026, 10, null, null, '2026-10-15', null)['sessions']->modelKeys());
        $this->assertSame([$early->id], $sheet->build($u15->id, 2026, 10, null, null, null, '2026-10-15')['sessions']->modelKeys());
    }

    #[Test]
    public function statuses_hold_the_raw_key_of_each_marked_cell_only(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $b = $this->player($u15);
        $marked = $this->training($u15, '2026-10-05');
        $this->training($u15, '2026-10-12'); // not saved yet: expected roster, no status
        Attendance::create(['training_session_id' => $marked->id, 'player_id' => $a->id, 'category_id' => $u15->id, 'status' => 'late', 'minutes' => 10]);
        Attendance::create(['training_session_id' => $marked->id, 'player_id' => $b->id, 'category_id' => $u15->id, 'status' => 'absent_unexcused']);

        $statuses = app(MonthSheet::class)->build($u15->id, 2026, 10)['statuses'];

        $this->assertSame([$a->id => [$marked->id => 'late'], $b->id => [$marked->id => 'absent_unexcused']], $statuses);
    }

    #[Test]
    public function guest_categories_name_only_players_from_another_category(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $own = $this->player($u15);
        $guest = $this->player($u17);

        $names = app(MonthSheet::class)->guestCategories(collect([$own, $guest]), $u15->id, null);

        $this->assertSame([$guest->id => 'U17'], $names);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/phpunit/phpunit/phpunit --filter AttendanceMonthSheetBoundsTest`
Expected: FAIL (bounds ignored, `Undefined array key "statuses"`, `Call to undefined method guestCategories()`).

- [ ] **Step 3: Implement in `MonthSheet`**

Add `use App\Models\Category;` to the imports. Replace `build()` with:

```php
    /**
     * Generates the month first (idempotent), like opening it on the calendar.
     * $branchId null = every branch's sessions (a club without branches).
     * $groupId null = every session; NO_GROUP = only the sessions with no group;
     * a training group's id = only its sessions.
     * $from / $to (Y-m-d, inclusive) cut the month for a period sheet; null = no bound.
     * `statuses` holds the raw status key of each marked cell (the totals count them).
     *
     * @return array{sessions: Collection<int, TrainingSession>, cells: array<int, array<int, string>>, statuses: array<int, array<int, string>>, players: Collection<int, Player>}
     */
    public function build(int $categoryId, int $year, int $month, ?int $branchId = null, ?int $groupId = null, ?string $from = null, ?string $to = null): array
    {
        $this->generator->forMonth($categoryId, $year, $month);

        $sessions = $this->monthSessions($categoryId, $year, $month, $branchId)
            ->when($groupId === self::NO_GROUP, fn ($q) => $q->whereNull('group_id'))
            ->when($groupId !== null && $groupId !== self::NO_GROUP, fn ($q) => $q->where('group_id', $groupId))
            ->when($from !== null, fn ($q) => $q->where('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('date', '<=', $to))
            ->with(['attendances', 'categories'])
            ->orderBy('date')->orderBy('start_time')->orderBy('id')
            ->get();

        $codes = AttendanceCode::fromSettings();
        $cells = [];
        $statuses = [];
        $expected = [];
        foreach ($sessions as $session) {
            if ($session->attendances->isNotEmpty()) {
                foreach ($session->attendances as $mark) {
                    $cells[$mark->player_id][$session->id] = $codes->format($mark->status, $mark->minutes);
                    $statuses[$mark->player_id][$session->id] = $mark->status;
                }

                continue;
            }
            $categoryIds = $session->categoryIds();
            $statusIds = $this->roster->statusIdsFor($session);
            $key = $session->branch_id.'|'.$session->group_id.'|'.$session->date.'|'.implode(',', $categoryIds).'|'.implode(',', $statusIds);
            $expected[$key] ??= $this->roster->expected($categoryIds, $session->date, $statusIds, $session->branch_id, $session->group_id)->modelKeys();
            foreach ($expected[$key] as $playerId) {
                $cells[$playerId][$session->id] = '';
            }
        }

        $players = Player::whereIn('id', array_keys($cells))
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::PLAYER_COLUMNS);

        return ['sessions' => $sessions, 'cells' => $cells, 'statuses' => $statuses, 'players' => $players];
    }

    /**
     * Players from another category (a joint session's roster, a guest) carry its name on the sheets.
     * Deliberately the player's CURRENT category in this branch, not any mark's frozen one: the month
     * sheet lists people as they are today.
     *
     * @param  Collection<int, Player>  $players
     * @return array<int, string> player id => category name, only for players outside $categoryId
     */
    public function guestCategories(Collection $players, int $categoryId, ?int $branchId): array
    {
        $categoryOf = $this->roster->categoryIdsIn($players, $branchId);
        $otherIds = collect($categoryOf)->filter()->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === $categoryId)->unique()->values()->all();
        $names = Category::whereIn('id', $otherIds)->get()
            ->mapWithKeys(fn (Category $c) => [$c->id => $c->localized_name]);

        $guests = [];
        foreach ($players as $player) {
            $name = $names->get((int) ($categoryOf[$player->id] ?? 0));
            if ($name !== null) {
                $guests[$player->id] = $name;
            }
        }

        return $guests;
    }
```

If `Roster::categoryIdsIn()` type-hints something other than `Collection`, match its exact signature (read it first) and keep `guestCategories()` passing the same type.

- [ ] **Step 4: Use it in `AttendanceSheetController::month()`**

In `month()`, replace the block from the comment `// Players from another category ...` through the `$others = Category::whereIn(...)...;` statement with:

```php
        // Players from another category carry its name (their CURRENT category, see guestCategories()).
        $guests = $sheet->guestCategories($players, $category->id, $branch?->id);
```

and in the `'rows'` map replace `'category' => $others->get((int) ($categoryOf[$p->id] ?? 0)),` with `'category' => $guests[$p->id] ?? null,`. Remove the `Roster $roster` parameter from `month()` if it is no longer used there (keep it in `session()`).

- [ ] **Step 5: Run the tests**

Run: `php vendor/phpunit/phpunit/phpunit --filter "AttendanceMonthSheetBoundsTest|AttendanceMonthSheetTest|AttendanceMonthSheetPdfTest|AttendanceGroupSheetsPdfTest|AttendanceGridTest|AttendanceGroupGridTest"`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/MonthSheet.php app/Http/Controllers/AttendanceSheetController.php tests/Feature/AttendanceMonthSheetBoundsTest.php
git commit -m "feat(attendance): month sheet date bounds, raw statuses and shared guest categories"
```

---

### Task 3: `SheetColumns`, shared PDF partials, totals on the filled month sheet

**Files:**
- Create: `app/Services/Attendance/SheetColumns.php`
- Create: `resources/views/pdf/partials/attendance-sheet-table.blade.php`
- Create: `resources/views/pdf/partials/attendance-sheet-legend.blade.php`
- Modify: `resources/views/pdf/attendance-month-sheet.blade.php`
- Modify: `app/Http/Controllers/AttendanceSheetController.php`
- Modify: `resources/js/i18n/ar.json`, `fr.json`, `en.json` (insert keys)
- Modify: `tests/Feature/AttendanceSheetTranslationsTest.php` (`KEYS`)
- Test: `tests/Feature/AttendanceSheetTotalsPdfTest.php`

**Interfaces:**
- Consumes: `SheetTotals::of()`, `SheetTotals::rateLabel()` (Task 1); `MonthSheet::build()['statuses']` (Task 2).
- Produces:
  - `SheetColumns::MAX = 16`, `SheetColumns::BLANK = 2`
  - `SheetColumns::of(Collection $sessions, string $locale): list<array{id:int, day:string, date:string, time:string, kind:string, title:?string}>`
  - `SheetColumns::parts(array $columns, bool $blankColumns = true): list<array{columns: list<array>, from:int, to:int, blank:int}>`
  - Partial `pdf.partials.attendance-sheet-table` with vars `part`, `rows` (list of `{id, name, category}`), `cells`, `filled` (bool), `totals` (`array<int, totals>|null`). It also reads inherited `$L` and `$kindMarks`.
  - Partial `pdf.partials.attendance-sheet-legend` with vars `blank` (int), `withTotals` (bool). It also reads inherited `$L`, `$statuses`, `$codes`, `$labels`, `$code`, `$legendCode`, `$kindMarks`.
  - i18n keys `att.sheet.total_present`, `att.sheet.total_absent`, `att.sheet.total_rate`, `att.sheet.totals_help`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceSheetTotalsPdfTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    private function training(Category $category, string $date): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
    }

    private function markAs(TrainingSession $training, Player $player, string $status): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'category_id' => $training->category_id, 'status' => $status]);
    }

    private function monthHtml(Category $category, array $query = []): string
    {
        $seen = $this->spyPdf();
        $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'fr']))
            ->get(route('attendance.sheets.month', ['category_id' => $category->id, 'month' => '2026-10'] + $query))
            ->assertOk();

        return $seen['html'];
    }

    #[Test]
    public function the_filled_month_sheet_prints_present_absent_and_rate_per_player(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $s1 = $this->training($u15, '2026-10-05');
        $s2 = $this->training($u15, '2026-10-12');
        $this->markAs($s1, $a, 'present');
        $this->markAs($s2, $a, 'absent_unexcused');

        $html = $this->monthHtml($u15, ['filled' => 1]);

        $this->assertSame(3, substr_count($html, '<th class="total"'));
        $this->assertStringContainsString('<td class="total">1</td>', $html);
        $this->assertStringContainsString('50.0%', $html);
    }

    #[Test]
    public function the_blank_month_sheet_has_no_totals(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-10-05');

        $this->assertStringNotContainsString('class="total"', $this->monthHtml($u15));
    }

    #[Test]
    public function with_two_parts_the_totals_are_on_the_last_part_only(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        for ($day = 1; $day <= 17; $day++) {
            $this->training($u15, sprintf('2026-10-%02d', $day));
        }

        $this->assertSame(3, substr_count($this->monthHtml($u15, ['filled' => 1]), '<th class="total"'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/phpunit/phpunit/phpunit --filter AttendanceSheetTotalsPdfTest`
Expected: FAIL (0 `class="total"` occurrences).

- [ ] **Step 3: Create `SheetColumns`**

```php
<?php

namespace App\Services\Attendance;

use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The session columns of the printed month and period sheets, and how they
 * split into parts of at most MAX columns (a new page each).
 */
final class SheetColumns
{
    /** Session columns per printed table; more continue in a second part on a new page. */
    public const MAX = 16;

    /** Blank columns after the month's last session, for a session added on the day, when they fit. */
    public const BLANK = 2;

    /**
     * @param  Collection<int, TrainingSession>  $sessions
     * @return list<array{id: int, day: string, date: string, time: string, kind: string, title: ?string}>
     */
    public static function of(Collection $sessions, string $locale): array
    {
        return $sessions->map(fn (TrainingSession $s) => [
            'id' => $s->id,
            'day' => CarbonImmutable::createFromFormat('!Y-m-d', $s->date)->locale($locale)->translatedFormat('D'),
            'date' => substr($s->date, 8, 2).'/'.substr($s->date, 5, 2),
            'time' => $s->start_time,
            'kind' => $s->kind->value,
            'title' => $s->title ? Str::limit(self::wrapLongWords($s->title), 24) : null,
        ])->values()->all();
    }

    /**
     * $blankColumns false = a record (period sheet): never any blank column.
     *
     * @param  list<array>  $columns
     * @return list<array{columns: list<array>, from: int, to: int, blank: int}>
     */
    public static function parts(array $columns, bool $blankColumns = true): array
    {
        $chunks = array_chunk($columns, self::MAX);
        $parts = [];
        foreach ($chunks as $i => $chunk) {
            $last = $i === count($chunks) - 1;
            $parts[] = [
                'columns' => $chunk,
                'from' => $i * self::MAX + 1,
                'to' => $i * self::MAX + count($chunk),
                'blank' => $blankColumns && $last && count($chunk) + self::BLANK <= self::MAX ? self::BLANK : 0,
            ];
        }

        return $parts;
    }

    /**
     * Breaks any word over $width characters into $width-character chunks
     * joined by spaces, so mPDF can wrap a long session title instead of
     * widening the whole column. mb_str_split() counts characters, not bytes,
     * so it never cuts a multibyte (e.g. Arabic) character.
     */
    public static function wrapLongWords(string $text, int $width = 10): string
    {
        return implode(' ', array_map(
            fn (string $word) => mb_strlen($word) > $width ? implode(' ', mb_str_split($word, $width)) : $word,
            explode(' ', $text)
        ));
    }
}
```

- [ ] **Step 4: Update `AttendanceSheetController::month()`**

- Constants become `public const MAX_COLUMNS = SheetColumns::MAX;` and `public const BLANK_COLUMNS = SheetColumns::BLANK;`. Delete the private `wrapLongWords()` method. Drop the `Str` import if it is unused.
- Add a `SheetTotals $totals` parameter to `month()` (method injection), plus the imports `App\Services\Attendance\SheetColumns` and `App\Services\Attendance\SheetTotals`.
- Destructure `statuses` too: `['sessions' => $sessions, 'cells' => $cells, 'statuses' => $statuses, 'players' => $players] = $sheet->build(...)`.
- Replace the `$columns = ...map...` block and the whole `$chunks`/`$groups` loop with:

```php
        $columns = SheetColumns::of($sessions, $locale);
        $groups = SheetColumns::parts($columns);

        // Present · Absent · Rate per player, on the filled sheet only (a blank sheet has nothing to count).
        $rowTotals = null;
        if ($request->boolean('filled')) {
            $rowTotals = [];
            foreach ($players as $player) {
                $rowTotals[$player->id] = $totals->of($statuses[$player->id] ?? []);
            }
        }
```

- Add `'totals' => $rowTotals,` to the view data.

- [ ] **Step 5: Create the table partial `resources/views/pdf/partials/attendance-sheet-table.blade.php`**

```blade
{{--
    One part of a players × sessions sheet (month and period sheets).
    $part: ['columns', 'blank']; $rows: [{id, name, category}]; $cells: playerId => sessionId => code;
    $filled: print the codes; $totals: playerId => SheetTotals row, or null for no totals columns.
    Inherited from the page: $L, $kindMarks.
--}}
<table class="sheet">
    <thead>
        <tr>
            <th style="width:6mm;">#</th>
            <th style="width:52mm;">{{ $L('att.player') }}</th>
            @foreach ($part['columns'] as $column)
                <th>{{ $column['day'] }}<br><bdi dir="ltr">{{ $column['date'] }}</bdi><br><bdi dir="ltr">{{ $column['time'] }}</bdi>@if (isset($kindMarks[$column['kind']]))<br><span class="kind">{{ $kindMarks[$column['kind']] }}</span>@endif @if ($column['title'])<br><span class="goal">{{ $column['title'] }}</span>@endif</th>
            @endforeach
            @for ($i = 0; $i < $part['blank']; $i++)
                <th class="blank-head">&nbsp;<br>__/__<br>&nbsp;</th>
            @endfor
            @if ($totals !== null)
                <th class="total" style="width:10mm;">{{ $L('att.sheet.total_present') }}</th>
                <th class="total" style="width:10mm;">{{ $L('att.sheet.total_absent') }}</th>
                <th class="total" style="width:12mm;">{{ $L('att.sheet.total_rate') }}</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            <tr>
                <td class="num">{{ $loop->iteration }}</td>
                <td>{{ $row['name'] }}@if ($row['category']) <span class="guest">({{ $row['category'] }})</span>@endif</td>
                @foreach ($part['columns'] as $column)
                    @if (array_key_exists($column['id'], $cells[$row['id']] ?? []))
                        <td class="cell">{{ $filled ? $cells[$row['id']][$column['id']] : '' }}</td>
                    @else
                        <td class="off"></td>
                    @endif
                @endforeach
                @for ($i = 0; $i < $part['blank']; $i++)
                    <td class="blank"></td>
                @endfor
                @if ($totals !== null)
                    @php $rowTotal = $totals[$row['id']] ?? ['present' => 0, 'absent' => 0, 'rate' => null]; @endphp
                    <td class="total">{{ $rowTotal['present'] }}</td>
                    <td class="total">{{ $rowTotal['absent'] }}</td>
                    <td class="total"><bdi dir="ltr">{{ \App\Services\Attendance\SheetTotals::rateLabel($rowTotal['rate']) }}</bdi></td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>
```

- [ ] **Step 6: Create the legend partial `resources/views/pdf/partials/attendance-sheet-legend.blade.php`**

```blade
{{--
    Legend under a players × sessions table. $blank: blank columns on this part; $withTotals: explain the totals.
    Inherited from the page: $L, $statuses, $codes, $labels, $code, $legendCode, $kindMarks.
--}}
<div class="legend">
    <b>{{ $L('att.sheet.legend') }}:</b>
    @foreach ($statuses as $status)
        <span style="color: {{ $codes[$status]['color'] }};">&#9632;</span> <b><bdi dir="ltr">{{ $legendCode($status) }}</bdi></b> {{ $labels[$status] }}@if (! $loop->last) &nbsp;&middot;&nbsp; @endif
    @endforeach
    <br>{{ strtr($L('att.sheet.legend_help'), ['{late}' => $code('late')]) }}
    <br><span class="kind-legend">{{ $kindMarks['preseason'] }}</span> = {{ $L('att.kind.preseason') }} &middot; <span class="kind-legend">{{ $kindMarks['extra'] }}</span> = {{ $L('att.kind.extra') }} &middot; {{ $L('att.sheet.off_roster') }}
    @if ($blank)
        &middot; {{ $L('att.sheet.blank_columns') }}
    @endif
    @if ($withTotals)
        <br>{{ $L('att.sheet.totals_help') }}
    @endif
</div>
```

- [ ] **Step 7: Use the partials in `attendance-month-sheet.blade.php`**

- In the top `@php` block rename `$marks` to `$kindMarks` (the definition line only; its other uses move into the partials).
- In `<style>` add: `th.total, td.total { text-align: center; font-weight: bold; background: #f8fafc; }`
- Inside `@foreach ($groups as $group)`, replace the whole `<table class="sheet">…</table>` with:

```blade
        @include('pdf.partials.attendance-sheet-table', [
            'part' => $group,
            'rows' => $rows,
            'cells' => $cells,
            'filled' => $filled,
            'totals' => $loop->last ? ($totals ?? null) : null,
        ])
```

- Replace the whole `<div class="legend">…</div>` with:

```blade
        @include('pdf.partials.attendance-sheet-legend', ['blank' => $group['blank'], 'withTotals' => $loop->last && ($totals ?? null) !== null])
```

Leave the header, sub line and signature table as they are.

- [ ] **Step 8: Add the i18n keys (insert-only, next to the other `att.sheet.*` lines, keeping alphabetical position)**

`fr.json`:
```json
    "att.sheet.total_absent": "Absent",
    "att.sheet.total_present": "Présent",
    "att.sheet.total_rate": "Taux",
    "att.sheet.totals_help": "Présent = présent + retard + départ anticipé · Absent = absence justifiée ou non · Taux = présences ÷ séances notées.",
```
`en.json`:
```json
    "att.sheet.total_absent": "Absent",
    "att.sheet.total_present": "Present",
    "att.sheet.total_rate": "Rate",
    "att.sheet.totals_help": "Present = present + late + left early · Absent = excused or unexcused absence · Rate = present ÷ marked sessions.",
```
`ar.json`:
```json
    "att.sheet.total_absent": "غائب",
    "att.sheet.total_present": "حاضر",
    "att.sheet.total_rate": "النسبة",
    "att.sheet.totals_help": "حاضر = حاضر + متأخر + انصراف مبكر · غائب = غياب بعذر أو بدون عذر · النسبة = الحضور ÷ الحصص المسجلة.",
```

Append the four keys to `AttendanceSheetTranslationsTest::KEYS`.

- [ ] **Step 9: Run the tests**

Run: `php vendor/phpunit/phpunit/phpunit --filter "AttendanceSheetTotalsPdfTest|AttendanceMonthSheetPdfTest|AttendanceGroupSheetsPdfTest|AttendanceSheetTranslationsTest|AttendanceBranchPagesTest"`
Expected: all PASS. The existing month sheet tests prove the refactor kept the blank and filled sheets identical.

Then: `npm run i18n:check`. Expected: no errors.

- [ ] **Step 10: Commit**

```bash
git add app/Services/Attendance/SheetColumns.php app/Http/Controllers/AttendanceSheetController.php resources/views/pdf/partials/attendance-sheet-table.blade.php resources/views/pdf/partials/attendance-sheet-legend.blade.php resources/views/pdf/attendance-month-sheet.blade.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/AttendanceSheetTotalsPdfTest.php tests/Feature/AttendanceSheetTranslationsTest.php
git commit -m "feat(attendance): present, absent and rate on the filled month sheet; shared sheet partials"
```

---

### Task 4: Summary line on the filled session sheet

**Files:**
- Modify: `app/Http/Controllers/AttendanceSheetController.php` (`session()`)
- Modify: `resources/views/pdf/attendance-session-sheet.blade.php`
- Modify: `resources/js/i18n/{ar,fr,en}.json`, `tests/Feature/AttendanceSheetTranslationsTest.php`
- Test: `tests/Feature/AttendanceSheetTotalsPdfTest.php` (add tests)

**Interfaces:**
- Consumes: `SheetTotals::of()`, `SheetTotals::rateLabel()`.
- Produces: i18n key `att.sheet.session_summary` with `{present}`, `{absent}`, `{rate}` placeholders.

- [ ] **Step 1: Add the failing tests to `AttendanceSheetTotalsPdfTest`**

```php
    private function sessionHtml(TrainingSession $training, array $query = []): string
    {
        $seen = $this->spyPdf();
        $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'fr']))
            ->get(route('attendance.sheets.session', ['session' => $training->id] + $query))
            ->assertOk();

        return $seen['html'];
    }

    #[Test]
    public function the_filled_session_sheet_prints_a_summary_line(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $b = $this->player($u15);
        $c = $this->player($u15);
        $training = $this->training($u15, '2026-10-05');
        $this->markAs($training, $a, 'present');
        $this->markAs($training, $b, 'late');
        $this->markAs($training, $c, 'absent_excused');

        $html = $this->sessionHtml($training, ['filled' => 1]);

        $this->assertStringContainsString('class="summary"', $html);
        $this->assertStringContainsString('Présents : 2 · Absents : 1', $html);
        $this->assertStringContainsString('66.7%', $html);
    }

    #[Test]
    public function the_blank_session_sheet_has_no_summary_line(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $training = $this->training($u15, '2026-10-05');
        $this->markAs($training, $a, 'present');

        $this->assertStringNotContainsString('class="summary"', $this->sessionHtml($training));
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `php vendor/phpunit/phpunit/phpunit --filter AttendanceSheetTotalsPdfTest`
Expected: the 2 new tests FAIL.

- [ ] **Step 3: Implement**

In `session()`, add a `SheetTotals $totals` parameter and, after `$marks = ...`:

```php
        // Present · Absent · Rate of the session, on the filled sheet of a marked session only.
        $summary = $filled && $marks->isNotEmpty() ? $totals->of($marks->pluck('status')) : null;
```

Pass `'summary' => $summary,` to the view.

In `attendance-session-sheet.blade.php` add `.summary { font-size: 11px; font-weight: bold; margin-top: 4px; }` to `<style>`. Right after `</table>` of `table.sheet`, before the first `<div class="help">`, add:

```blade
    @if (! empty($summary))
        <div class="summary">{!! strtr(e($L('att.sheet.session_summary')), [
            '{present}' => $summary['present'],
            '{absent}' => $summary['absent'],
            '{rate}' => '<bdi dir="ltr">'.e(\App\Services\Attendance\SheetTotals::rateLabel($summary['rate'])).'</bdi>',
        ]) !!}</div>
    @endif
```

i18n (insert-only):
- fr: `"att.sheet.session_summary": "Présents : {present} · Absents : {absent} · Taux : {rate}",`
- en: `"att.sheet.session_summary": "Present: {present} · Absent: {absent} · Rate: {rate}",`
- ar: `"att.sheet.session_summary": "الحاضرون: {present} · الغائبون: {absent} · النسبة: {rate}",`

Append `'att.sheet.session_summary'` to `AttendanceSheetTranslationsTest::KEYS`.

- [ ] **Step 4: Run the tests**

Run: `php vendor/phpunit/phpunit/phpunit --filter "AttendanceSheetTotalsPdfTest|AttendanceSessionSheetPdfTest|AttendanceSheetTranslationsTest"` and `npm run i18n:check`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AttendanceSheetController.php resources/views/pdf/attendance-session-sheet.blade.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/AttendanceSheetTotalsPdfTest.php tests/Feature/AttendanceSheetTranslationsTest.php
git commit -m "feat(attendance): present, absent and rate line on the filled session sheet"
```

---

### Task 5: `PeriodSheet` service

**Files:**
- Create: `app/Services/Attendance/PeriodSheet.php`
- Test: `tests/Feature/AttendancePeriodSheetTest.php`

**Interfaces:**
- Consumes: `MonthSheet::groupSwitch()`, `MonthSheet::build(..., $from, $to)`, `MonthSheet::guestCategories()`, `MonthSheet::NO_GROUP`, `SheetTotals::of()`, `SheetTotals::sum()`.
- Produces: `PeriodSheet::MAX_MONTHS = 13` and `PeriodSheet::build(string $from, string $to, ?int $branchId, ?int $categoryId = null, ?int $groupId = null): list<Section>`, where:
  - `Section = array{category: Category, group: ?string, months: list<Month>, summary: ?list<array{player: Player, guest: ?string, totals: array}>}`
  - `Month = array{month: string /* Y-m */, sessions: Collection<TrainingSession>, cells: array, players: Collection<Player>, totals: array<int, array>, guests: array<int, string>}`
  - `group` is null for a category without groups. A group gets its name, and NO_GROUP gets `UiLang::get('att.group_whole')`.
  - With a `$groupId` that is not one of the category's entries, every entry is printed (period sheet fallback, matching «all groups» on the Stats page).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Services\Attendance\GroupMembership;
use App\Services\Attendance\PeriodSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendancePeriodSheetTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-26 10:00:00');
    }

    private function training(Category $category, string $date, array $extra = []): TrainingSession
    {
        return TrainingSession::create(array_merge([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ], $extra));
    }

    private function markAs(TrainingSession $training, Player $player, string $status): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'category_id' => $training->category_id, 'status' => $status]);
    }

    #[Test]
    public function one_page_per_month_cut_to_the_bounds_with_a_summary(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $this->training($u15, '2026-09-28'); // before the period
        $oct = $this->training($u15, '2026-10-20');
        $nov = $this->training($u15, '2026-11-03');
        $this->markAs($oct, $a, 'present');
        $this->markAs($nov, $a, 'absent_unexcused');

        $sections = app(PeriodSheet::class)->build('2026-10-15', '2026-11-30', null, $u15->id);

        $this->assertCount(1, $sections);
        $this->assertNull($sections[0]['group']);
        $this->assertSame(['2026-10', '2026-11'], array_column($sections[0]['months'], 'month'));
        $this->assertSame([$oct->id], $sections[0]['months'][0]['sessions']->modelKeys());
        $this->assertSame(['present' => 1, 'absent' => 0, 'expected' => 1, 'rate' => 100.0], $sections[0]['months'][0]['totals'][$a->id]);
        $this->assertSame(['present' => 1, 'absent' => 1, 'expected' => 2, 'rate' => 50.0], $sections[0]['summary'][0]['totals']);
        $this->assertTrue($sections[0]['summary'][0]['player']->is($a));
    }

    #[Test]
    public function a_single_month_has_no_summary(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-10-05');

        $this->assertNull(app(PeriodSheet::class)->build('2026-10-01', '2026-10-31', null, $u15->id)[0]['summary']);
    }

    #[Test]
    public function months_after_the_current_one_are_skipped(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-11-10');
        $this->training($u15, '2026-12-08');

        $sections = app(PeriodSheet::class)->build('2026-11-01', '2027-01-31', null, $u15->id);

        $this->assertSame(['2026-11'], array_column($sections[0]['months'], 'month'));
    }

    #[Test]
    public function all_categories_give_one_section_each_and_empty_ones_are_dropped(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $this->category('U19'); // no session
        $this->player($u15);
        $this->player($u17);
        $this->training($u15, '2026-10-05');
        $this->training($u17, '2026-10-06');

        $sections = app(PeriodSheet::class)->build('2026-10-01', '2026-10-31', null);

        $this->assertSame([$u15->id, $u17->id], array_map(fn ($s) => $s['category']->id, $sections));
    }

    #[Test]
    public function a_category_with_groups_gives_one_section_per_group_or_only_the_chosen_one(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $dolphins = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'Dauphins']);
        $sharks = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'Requins']);
        foreach ([$dolphins, $sharks] as $group) {
            $player = $this->player($minime);
            $swim->players()->attach($player->id);
            app(GroupMembership::class)->add($group, $player->id, null);
            $this->training($minime, '2026-10-10', ['branch_id' => $swim->id, 'group_id' => $group->id]);
        }

        $all = app(PeriodSheet::class)->build('2026-10-01', '2026-10-31', $swim->id, $minime->id);
        $one = app(PeriodSheet::class)->build('2026-10-01', '2026-10-31', $swim->id, $minime->id, $sharks->id);

        $this->assertSame(['Dauphins', 'Requins'], array_column($all, 'group'));
        $this->assertSame(['Requins'], array_column($one, 'group'));
    }
}
```

Note: two group sessions share date `2026-10-10` and start `18:00`. If the model forbids two groups of one category on the same day and start time (owner rule), give the second one `'start_time' => '19:30', 'end_time' => '21:00'` instead.

- [ ] **Step 2: Run to verify failure**

Run: `php vendor/phpunit/phpunit/phpunit --filter AttendancePeriodSheetTest`
Expected: FAIL, `Class "App\Services\Attendance\PeriodSheet" not found`.

- [ ] **Step 3: Implement `PeriodSheet`**

```php
<?php

namespace App\Services\Attendance;

use App\Models\Category;
use App\Models\Player;
use App\Support\UiLang;
use Carbon\CarbonImmutable;

/**
 * The printed period sheet: for each category (and each of its training
 * groups), one MonthSheet page per month of [from, to], cut to the bounds,
 * then a summary of the period per player when more than one month has
 * sessions. Months after the current one are skipped: they hold no marks,
 * and building them would generate future sessions.
 */
final class PeriodSheet
{
    public const MAX_MONTHS = 13;

    public function __construct(
        private readonly MonthSheet $sheet,
        private readonly SheetTotals $totals,
    ) {}

    /**
     * $categoryId null = every category of the branch with sessions in the period, by id.
     * $groupId (with a category only): one training group, or MonthSheet::NO_GROUP; null or
     * unknown = every group entry of the category.
     *
     * @return list<array{category: Category, group: ?string, months: list<array>, summary: ?list<array>}>
     */
    public function build(string $from, string $to, ?int $branchId, ?int $categoryId = null, ?int $groupId = null): array
    {
        $months = $this->months($from, $to);
        if ($months === []) {
            return [];
        }
        $categories = $categoryId !== null
            ? Category::whereKey($categoryId)->get()
            : Category::forBranch($branchId)->orderBy('id')->get();

        $sections = [];
        foreach ($categories as $category) {
            $entries = $this->groupEntries($category->id, $months, $branchId, $categoryId !== null ? $groupId : null);
            foreach ($entries as $entry) {
                $section = $this->section($category, $entry, $months, $from, $to, $branchId);
                if ($section !== null) {
                    $sections[] = $section;
                }
            }
        }

        return $sections;
    }

    /**
     * Every [year, month] touching [from, to], up to the current month.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function months(string $from, string $to): array
    {
        $cursor = CarbonImmutable::createFromFormat('!Y-m-d', $from)->startOfMonth();
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to)->startOfMonth();
        $current = CarbonImmutable::now()->startOfMonth();
        if ($end->greaterThan($current)) {
            $end = $current;
        }

        $months = [];
        while ($cursor->lessThanOrEqualTo($end)) {
            $months[] = [$cursor->year, $cursor->month];
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    /**
     * The sections of one category: [id => null, name => null] when it has no groups,
     * else each group (by name), plus NO_GROUP when any month has sessions with no group.
     *
     * @param  list<array{0: int, 1: int}>  $months
     * @return list<array{id: ?int, name: ?string}>
     */
    private function groupEntries(int $categoryId, array $months, ?int $branchId, ?int $requested): array
    {
        $entries = [];
        foreach ($months as [$year, $month]) {
            foreach ($this->sheet->groupSwitch($categoryId, $year, $month, $branchId)['options'] as $option) {
                $entries[$option['id']] ??= ['id' => $option['id'], 'name' => $option['name'] ?? UiLang::get('att.group_whole')];
            }
        }
        if ($entries === []) {
            return [['id' => null, 'name' => null]];
        }
        // «Whole category» last, as on the month grid's switch.
        if (isset($entries[MonthSheet::NO_GROUP])) {
            $noGroup = $entries[MonthSheet::NO_GROUP];
            unset($entries[MonthSheet::NO_GROUP]);
            $entries[MonthSheet::NO_GROUP] = $noGroup;
        }
        if ($requested !== null && isset($entries[$requested])) {
            return [$entries[$requested]];
        }

        return array_values($entries);
    }

    /**
     * @param  array{id: ?int, name: ?string}  $entry
     * @param  list<array{0: int, 1: int}>  $months
     */
    private function section(Category $category, array $entry, array $months, string $from, string $to, ?int $branchId): ?array
    {
        $pages = [];
        $players = [];
        $perPlayer = [];
        foreach ($months as [$year, $month]) {
            $built = $this->sheet->build($category->id, $year, $month, $branchId, $entry['id'], $from, $to);
            if ($built['sessions']->isEmpty()) {
                continue;
            }
            $totals = [];
            foreach ($built['players'] as $player) {
                $totals[$player->id] = $this->totals->of($built['statuses'][$player->id] ?? []);
                $perPlayer[$player->id][] = $totals[$player->id];
                $players[$player->id] = $player;
            }
            $pages[] = [
                'month' => sprintf('%04d-%02d', $year, $month),
                'sessions' => $built['sessions'],
                'cells' => $built['cells'],
                'players' => $built['players'],
                'totals' => $totals,
                'guests' => $this->sheet->guestCategories($built['players'], $category->id, $branchId),
            ];
        }
        if ($pages === []) {
            return null;
        }

        $summary = null;
        if (count($pages) > 1) {
            $ordered = collect($players)->sortBy([['lastname', 'asc'], ['firstname', 'asc']])->values();
            $guests = $this->sheet->guestCategories($ordered, $category->id, $branchId);
            $summary = $ordered->map(fn (Player $p) => [
                'player' => $p,
                'guest' => $guests[$p->id] ?? null,
                'totals' => SheetTotals::sum($perPlayer[$p->id]),
            ])->all();
        }

        return ['category' => $category, 'group' => $entry['name'], 'months' => $pages, 'summary' => $summary];
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php vendor/phpunit/phpunit/phpunit --filter AttendancePeriodSheetTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/PeriodSheet.php tests/Feature/AttendancePeriodSheetTest.php
git commit -m "feat(attendance): PeriodSheet builds month pages and a summary per category and group"
```

---

### Task 6: Period sheet route, controller, PDF

**Files:**
- Create: `app/Http/Controllers/AttendancePeriodSheetController.php`
- Create: `resources/views/pdf/attendance-period-sheet.blade.php`
- Modify: `routes/web.php` (next to `attendance.sheets.month`, line ~502)
- Modify: `config/permissions.php` (next to line 91)
- Modify: `tests/Feature/AttendancePermissionTest.php` (line ~47)
- Modify: `resources/js/i18n/{ar,fr,en}.json`, `tests/Feature/AttendanceSheetTranslationsTest.php`
- Test: `tests/Feature/AttendancePeriodSheetPdfTest.php`

**Interfaces:**
- Consumes: `PeriodSheet::build()`, `PeriodSheet::MAX_MONTHS`, `SheetColumns::of()`, `SheetColumns::parts(…, false)`, the two partials, `SheetTotals::rateLabel()`, `BranchChoice::resolve($request, ?int)`.
- Produces: route `GET /attendance/sheets/period` named `attendance.sheets.period`, with query `from`, `to`, `branch_id?`, `category_id?`, `group_id?`. i18n keys `att.sheet.period_title`, `att.sheet.period`, `att.sheet.summary_title`, `att.sheet.total_expected`, `att.sheet.period_too_long`, `att.sheet.no_sessions_period`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendancePeriodSheetPdfTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-26 10:00:00');
    }

    private function training(Category $category, string $date): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
    }

    private function markAs(TrainingSession $training, Player $player, string $status): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'category_id' => $training->category_id, 'status' => $status]);
    }

    private function url(array $query): string
    {
        return route('attendance.sheets.period', $query);
    }

    private function html(array $query, string $locale = 'fr'): string
    {
        $seen = $this->spyPdf();
        $this->actingAs(User::factory()->admin()->create(['preferred_lng' => $locale]))->get($this->url($query))->assertOk();

        return $seen['html'];
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    #[Test]
    public function the_sheet_renders_as_a_real_pdf_in_arabic(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $this->markAs($this->training($u15, '2026-10-05'), $a, 'present');
        $this->markAs($this->training($u15, '2026-11-02'), $a, 'absent_unexcused');

        $response = $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'ar']))
            ->get($this->url(['from' => '2026-10-01', 'to' => '2026-11-30', 'category_id' => $u15->id]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function two_months_print_month_pages_with_totals_and_a_summary(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $this->markAs($this->training($u15, '2026-10-05'), $a, 'present');
        $this->markAs($this->training($u15, '2026-11-02'), $a, 'absent_unexcused');

        $html = $this->html(['from' => '2026-10-01', 'to' => '2026-11-30', 'category_id' => $u15->id]);

        $this->assertStringContainsString('Feuille de présence de la période', $html);
        $this->assertStringContainsString('octobre 2026', $html);
        $this->assertStringContainsString('novembre 2026', $html);
        $this->assertStringContainsString('Récapitulatif de la période', $html);
        $this->assertStringContainsString('100.0%', $html); // October page
        $this->assertStringContainsString('50.0%', $html);  // summary
    }

    #[Test]
    public function one_month_has_no_summary_page(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-10-05');

        $this->assertStringNotContainsString('Récapitulatif de la période', $this->html(['from' => '2026-10-01', 'to' => '2026-10-31', 'category_id' => $u15->id]));
    }

    #[Test]
    public function all_categories_print_each_category(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $this->player($u15);
        $this->player($u17);
        $this->training($u15, '2026-10-05');
        $this->training($u17, '2026-10-06');

        $html = $this->html(['from' => '2026-10-01', 'to' => '2026-10-31']);

        $this->assertStringContainsString('U15', $html);
        $this->assertStringContainsString('U17', $html);
    }

    #[Test]
    public function an_empty_period_says_so(): void
    {
        $u15 = $this->category('U15');

        $this->assertStringContainsString('Aucune séance sur cette période.', $this->html(['from' => '2026-10-01', 'to' => '2026-10-31', 'category_id' => $u15->id]));
    }

    #[Test]
    public function dates_are_validated(): void
    {
        $admin = User::factory()->admin()->create(['preferred_lng' => 'fr']);

        $this->actingAs($admin)->get($this->url(['from' => '2026-10-31', 'to' => '2026-10-01']))->assertSessionHasErrors('to');
        $this->actingAs($admin)->get($this->url(['from' => '2025-09-01', 'to' => '2026-10-01']))->assertSessionHasErrors('to');
        $this->actingAs($admin)->get($this->url(['to' => '2026-10-01']))->assertSessionHasErrors('from');
    }

    #[Test]
    public function printing_needs_attendance_view_only(): void
    {
        $u15 = $this->category('U15');
        $this->training($u15, '2026-10-05');
        $this->spyPdf();
        $query = ['from' => '2026-10-01', 'to' => '2026-10-31', 'category_id' => $u15->id];

        $this->actingAs($this->userWith(['attendance' => ['view']]))->get($this->url($query))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view']]))->get($this->url($query))->assertForbidden();
    }
}
```

The existing `att.no_sessions` says «ce mois-ci», so the period sheet uses a new key `att.sheet.no_sessions_period` (Step 6).

Also add to `AttendancePermissionTest` next to the other sheet asserts:

```php
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.sheets.period'));
```

- [ ] **Step 2: Run to verify failure**

Run: `php vendor/phpunit/phpunit/phpunit --filter "AttendancePeriodSheetPdfTest|AttendancePermissionTest"`
Expected: FAIL, `Route [attendance.sheets.period] not defined`.

- [ ] **Step 3: Route and permission**

`routes/web.php`, after the `attendance.sheets.month` line:

```php
        Route::get('/attendance/sheets/period', AttendancePeriodSheetController::class)->name('attendance.sheets.period');
```

Add `use App\Http\Controllers\AttendancePeriodSheetController;` with the other controller imports at the top.

`config/permissions.php`, after `'attendance.sheets.session' => ['attendance', 'view'],`:

```php
        'attendance.sheets.period' => ['attendance', 'view'],
```

- [ ] **Step 4: Controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Player;
use App\Services\Attendance\AttendanceStatusCatalog;
use App\Services\Attendance\BranchChoice;
use App\Services\Attendance\PeriodSheet;
use App\Services\Attendance\SheetColumns;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\Season;
use App\Support\UiLang;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The recorded attendance of a period (`attendance.sheets.period`, view):
 * per category and training group, one page per month with each player's
 * codes and Present · Absent · Rate, then a period summary. Launched from
 * the statistics page and the month grid.
 */
class AttendancePeriodSheetController extends Controller
{
    public function __construct(private readonly PdfService $pdf) {}

    public function __invoke(Request $request, PeriodSheet $sheet, AttendanceStatusCatalog $catalog): Response
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            // As on the month sheet: a training group, or 0 = the sessions with no group; none = every group.
            'group_id' => ['nullable', 'integer', 'min:0'],
        ]);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $data['from']);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $data['to']);
        if ($to->greaterThan($from->addMonthsNoOverflow(PeriodSheet::MAX_MONTHS)->subDay())) {
            throw ValidationException::withMessages(['to' => UiLang::get('att.sheet.period_too_long')]);
        }

        $branch = Branch::find(BranchChoice::resolve($request, $data['branch_id'] ?? null));
        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $groupId = isset($data['group_id']) ? (int) $data['group_id'] : null;
        $locale = app()->getLocale();
        $sections = $sheet->build($data['from'], $data['to'], $branch?->id, $categoryId, $groupId);

        $used = collect($sections)->flatMap(fn (array $s) => collect($s['months'])
            ->flatMap(fn (array $m) => $m['sessions']->flatMap->attendances->pluck('status')));
        $seasonFrom = Season::forDate($from)->label();
        $seasonTo = Season::forDate($to)->label();

        $html = view('pdf.attendance-period-sheet', [
            'club' => ClubHeader::data(),
            'branch' => $branch?->localized_name,
            'periodLabel' => $from->format('d/m/Y').' → '.$to->format('d/m/Y'),
            'season' => $seasonFrom === $seasonTo ? $seasonFrom : $seasonFrom.' – '.$seasonTo,
            'sections' => array_map(fn (array $s) => [
                'category' => $s['category']->localized_name,
                'group' => $s['group'],
                'months' => array_map(fn (array $m) => [
                    'label' => CarbonImmutable::createFromFormat('!Y-m', $m['month'])->locale($locale)->translatedFormat('F Y'),
                    'parts' => SheetColumns::parts(SheetColumns::of($m['sessions'], $locale), false),
                    'rows' => $m['players']->map(fn (Player $p) => [
                        'id' => $p->id, 'name' => $p->fullname, 'category' => $m['guests'][$p->id] ?? null,
                    ])->values()->all(),
                    'cells' => $m['cells'],
                    'totals' => $m['totals'],
                ], $s['months']),
                'summary' => $s['summary'] === null ? null : array_map(fn (array $r) => [
                    'name' => $r['player']->fullname, 'category' => $r['guest'], 'totals' => $r['totals'],
                ], $s['summary']),
            ], $sections),
            'labels' => $catalog->labels(null, $catalog->shown($used)),
            'codes' => $catalog->codes(),
        ])->render();

        $filename = "attendance-period-{$data['from']}-{$data['to']}"
            .($branch !== null ? '-b'.$branch->id : '')
            .($categoryId !== null ? '-'.$categoryId : '')
            .($categoryId !== null && $groupId !== null ? '-g'.$groupId : '');

        return $this->pdf->stream($html, $filename.'.pdf', $locale === 'ar', true);
    }
}
```

`Season::forDate()` accepts `DateTimeInterface|string`. `CarbonImmutable` is fine.

- [ ] **Step 5: PDF view `resources/views/pdf/attendance-period-sheet.blade.php`**

```blade
@php
    use App\Enums\AttendanceStatus;
    use App\Services\Attendance\AttendanceCode;
    use App\Services\Attendance\SheetTotals;

    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $statuses = array_keys($labels);
    $code = fn (string $status) => AttendanceCode::normalise($codes[$status]['code']);
    $legendCode = fn (string $status) => $code($status).((AttendanceStatus::tryFrom($status)?->takesMinutes() ?? false) ? '15' : '');
    $kindMarks = ['preseason' => $L('att.sheet.kind_mark.preseason'), 'extra' => $L('att.sheet.kind_mark.extra')];
    $footer = ($branch ? $branch.' · ' : '').$periodLabel.' — '.strtr($L('att.sheet.page'), ['{page}' => '{PAGENO}', '{pages}' => '{nbpg}']);
    $first = true;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 9px; color: #1e293b; }
        .title { font-size: 15px; font-weight: bold; color: #02a85c; }
        .sub { font-size: 11px; color: #334155; margin-bottom: 6px; }
        .muted { color: #64748b; }
        table.sheet { width: 100%; border-collapse: collapse; }
        table.sheet th { background: #f1f5f9; color: #334155; font-size: 8px; padding: 2px; border: 1px solid #94a3b8; text-align: center; }
        table.sheet td { height: 6.5mm; padding: 1px 3px; border: 1px solid #94a3b8; }
        td.num { text-align: center; font-family: monospace; }
        td.cell { text-align: center; font-weight: bold; font-size: 10px; }
        td.off { background: #d1d5db; }
        th.total, td.total { text-align: center; font-weight: bold; background: #f8fafc; }
        .kind, .kind-legend { font-weight: bold; color: #b45309; }
        .goal { font-size: 6px; font-weight: normal; color: #64748b; }
        .guest { font-size: 7px; color: #64748b; }
        .legend { margin-top: 5px; font-size: 8px; line-height: 1.5; }
    </style>
</head>
<body>
    <htmlpagefooter name="sheet"><div style="font-size:7px; color:#94a3b8; text-align:center;">{{ $footer }}</div></htmlpagefooter>
    <sethtmlpagefooter name="sheet" value="on" />

    @if ($sections === [])
        @include('pdf.partials.header')
        <div class="title">{{ $L('att.sheet.period_title') }}</div>
        <div class="sub">@if ($branch){{ $L('branch') }}: <b>{{ $branch }}</b> &middot; @endif{{ $L('att.sheet.period') }}: <b><bdi dir="ltr">{{ $periodLabel }}</bdi></b> &middot; {{ $L('att.season') }}: <b><bdi dir="ltr">{{ $season }}</bdi></b></div>
        <div class="muted">{{ $L('att.sheet.no_sessions_period') }}</div>
    @endif

    @foreach ($sections as $section)
        @foreach ($section['months'] as $month)
            @php $monthTotal = $month['parts'][count($month['parts']) - 1]['to']; @endphp
            @foreach ($month['parts'] as $part)
                @if (! $first)
                    <pagebreak />
                @endif
                @php $first = false; @endphp
                @include('pdf.partials.header')
                <div class="title">{{ $L('att.sheet.period_title') }}</div>
                <div class="sub">
                    @if ($branch){{ $L('branch') }}: <b>{{ $branch }}</b> &middot; @endif{{ $L('att.category') }}: <b>{{ $section['category'] }}</b>@if ($section['group']) &middot; {{ $L('att.group') }}: <b>{{ $section['group'] }}</b>@endif &middot; {{ $L('att.sheet.month') }}: <b>{{ $month['label'] }}</b> &middot; {{ $L('att.sheet.period') }}: <b><bdi dir="ltr">{{ $periodLabel }}</bdi></b> &middot; {{ $L('att.season') }}: <b><bdi dir="ltr">{{ $season }}</bdi></b>
                    @if (count($month['parts']) > 1)
                        &middot; {{ strtr($L('att.sheet.part'), ['{from}' => $part['from'], '{to}' => $part['to'], '{total}' => $monthTotal]) }}
                    @endif
                </div>

                @include('pdf.partials.attendance-sheet-table', [
                    'part' => $part,
                    'rows' => $month['rows'],
                    'cells' => $month['cells'],
                    'filled' => true,
                    'totals' => $loop->last ? $month['totals'] : null,
                ])
                @include('pdf.partials.attendance-sheet-legend', ['blank' => 0, 'withTotals' => $loop->last])
            @endforeach
        @endforeach

        @if ($section['summary'] !== null)
            <pagebreak />
            @include('pdf.partials.header')
            <div class="title">{{ $L('att.sheet.summary_title') }}</div>
            <div class="sub">@if ($branch){{ $L('branch') }}: <b>{{ $branch }}</b> &middot; @endif{{ $L('att.category') }}: <b>{{ $section['category'] }}</b>@if ($section['group']) &middot; {{ $L('att.group') }}: <b>{{ $section['group'] }}</b>@endif &middot; {{ $L('att.sheet.period') }}: <b><bdi dir="ltr">{{ $periodLabel }}</bdi></b> &middot; {{ $L('att.season') }}: <b><bdi dir="ltr">{{ $season }}</bdi></b></div>
            <table class="sheet">
                <thead>
                    <tr>
                        <th style="width:6mm;">#</th>
                        <th>{{ $L('att.player') }}</th>
                        <th class="total" style="width:18mm;">{{ $L('att.sheet.total_present') }}</th>
                        <th class="total" style="width:18mm;">{{ $L('att.sheet.total_absent') }}</th>
                        <th class="total" style="width:18mm;">{{ $L('att.sheet.total_expected') }}</th>
                        <th class="total" style="width:18mm;">{{ $L('att.sheet.total_rate') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['summary'] as $row)
                        <tr>
                            <td class="num">{{ $loop->iteration }}</td>
                            <td>{{ $row['name'] }}@if ($row['category']) <span class="guest">({{ $row['category'] }})</span>@endif</td>
                            <td class="total">{{ $row['totals']['present'] }}</td>
                            <td class="total">{{ $row['totals']['absent'] }}</td>
                            <td class="total">{{ $row['totals']['expected'] }}</td>
                            <td class="total"><bdi dir="ltr">{{ SheetTotals::rateLabel($row['totals']['rate']) }}</bdi></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="legend">{{ $L('att.sheet.totals_help') }}</div>
        @endif
    @endforeach
</body>
</html>
```

- [ ] **Step 6: i18n keys (insert-only)**

fr:
```json
    "att.sheet.no_sessions_period": "Aucune séance sur cette période.",
    "att.sheet.period": "Période",
    "att.sheet.period_title": "Feuille de présence de la période",
    "att.sheet.period_too_long": "La période ne peut pas dépasser 13 mois.",
    "att.sheet.summary_title": "Récapitulatif de la période",
    "att.sheet.total_expected": "Séances",
```
en:
```json
    "att.sheet.no_sessions_period": "No sessions in this period.",
    "att.sheet.period": "Period",
    "att.sheet.period_title": "Attendance sheet for the period",
    "att.sheet.period_too_long": "The period cannot be longer than 13 months.",
    "att.sheet.summary_title": "Period summary",
    "att.sheet.total_expected": "Sessions",
```
ar:
```json
    "att.sheet.no_sessions_period": "لا توجد حصص في هذه الفترة.",
    "att.sheet.period": "الفترة",
    "att.sheet.period_title": "ورقة الحضور للفترة",
    "att.sheet.period_too_long": "لا يمكن أن تتجاوز الفترة 13 شهرا.",
    "att.sheet.summary_title": "ملخص الفترة",
    "att.sheet.total_expected": "الحصص",
```

Append the six keys to `AttendanceSheetTranslationsTest::KEYS`.

- [ ] **Step 7: Run the tests**

Run: `php vendor/phpunit/phpunit/phpunit --filter "AttendancePeriodSheetPdfTest|AttendancePermissionTest|AttendanceSheetTranslationsTest"` and `npm run i18n:check`
Expected: all PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/AttendancePeriodSheetController.php resources/views/pdf/attendance-period-sheet.blade.php routes/web.php config/permissions.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/AttendancePeriodSheetPdfTest.php tests/Feature/AttendancePermissionTest.php tests/Feature/AttendanceSheetTranslationsTest.php
git commit -m "feat(attendance): printable period sheet, one page per month plus a summary"
```

---

### Task 7: Buttons on the Stats and Grid pages

**Files:**
- Modify: `resources/js/Pages/Attendance/Stats.vue`
- Modify: `resources/js/Pages/Attendance/Grid.vue`
- Modify: `resources/js/i18n/{ar,fr,en}.json`, `tests/Feature/AttendanceSheetTranslationsTest.php`
- Test: `tests/Feature/AttendanceSheetLinksTest.php` (read it first; add a case in the same style if it checks page props or rendered links)

**Interfaces:**
- Consumes: route `attendance.sheets.period` (Task 6). Stats props `period` (`{period, from, to}`), `categoryId`, `groupId`, `branchId`. Grid props `category`, `month`, `branch`, `groupId`.
- Produces: i18n keys `att.sheet.print_period`, `att.sheet.print_period_hint`, `att.sheet.period_order`.

- [ ] **Step 1: Stats page button**

In `Stats.vue` `<script setup>`, after `exportHref`:

```js
// The recorded attendance of this period, branch, category and group, one page per month.
const periodSheetHref = computed(() => route('attendance.sheets.period', { from: props.period.from, to: props.period.to, ...keep.value }));
```

In the template, right before `<ExportMenu …/>`:

```vue
                    <IconButton :href="periodSheetHref" external target="_blank" icon="print" :label="t('att.sheet.print_period')" :title="t('att.sheet.print_period_hint')" />
```

Check that `props.period` carries `from` and `to` (the controller's `data()` sets them; `export()` reads `$data['period']['from']`). If the prop lacks them, add them in `AttendanceStatsController::data()`.

- [ ] **Step 2: Grid page dialog**

In `Grid.vue` imports add `import Modal from '@/Components/Modal.vue';`. After `filledSheetHref` add:

```js
// Any period of this branch, category and group, from a small dialog (defaults to the grid's month).
const showPeriod = ref(false);
const period = reactive({ from: '', to: '' });
const periodValid = computed(() => !!period.from && !!period.to && period.from <= period.to);
const periodSheetHref = computed(() => (periodValid.value
    ? route('attendance.sheets.period', { from: period.from, to: period.to, category_id: props.category.id, ...branchParam.value, ...groupParam.value })
    : null));
function openPeriod() {
    const [y, m] = props.month.split('-').map(Number);
    period.from = `${props.month}-01`;
    period.to = `${props.month}-${String(new Date(y, m, 0).getDate()).padStart(2, '0')}`;
    showPeriod.value = true;
}
const dateInput = 'mt-1 block w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100';
```

In the header buttons, after the filled-sheet `IconButton`:

```vue
                    <IconButton icon="calendar" :label="t('att.sheet.print_period')" :title="t('att.sheet.print_period_hint')" @click="openPeriod" />
```

Before the closing `</AuthenticatedLayout>`:

```vue
        <Modal :show="showPeriod" max-width="md" @close="showPeriod = false">
            <div class="space-y-3 p-5">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.sheet.print_period') }}</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('att.sheet.print_period_hint') }}</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="block text-sm text-slate-700 dark:text-slate-300">{{ t('activity.from') }}<input v-model="period.from" type="date" :class="dateInput" /></label>
                    <label class="block text-sm text-slate-700 dark:text-slate-300">{{ t('activity.to') }}<input v-model="period.to" type="date" :class="dateInput" /></label>
                </div>
                <InputError :message="period.from && period.to && !periodValid ? t('att.sheet.period_order') : ''" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:text-slate-400 dark:ring-slate-700" @click="showPeriod = false">{{ t('att.close') }}</button>
                    <a
                        :href="periodSheetHref ?? undefined"
                        target="_blank"
                        :aria-disabled="!periodValid"
                        :class="['rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700', periodValid ? '' : 'pointer-events-none opacity-50']"
                        @click="showPeriod = false"
                    >{{ t('print') }}</a>
                </div>
            </div>
        </Modal>
```

A plain `<a target="_blank">` is used instead of `window.open`, the same way every other sheet opens (it works on the desktop app).

- [ ] **Step 3: i18n keys (insert-only)**

fr:
```json
    "att.sheet.period_order": "La date de fin doit être le même jour ou après la date de début.",
    "att.sheet.print_period": "Imprimer une période",
    "att.sheet.print_period_hint": "Présences enregistrées, une page par mois, avec les totaux.",
```
en:
```json
    "att.sheet.period_order": "The end date must be on or after the start date.",
    "att.sheet.print_period": "Print a period",
    "att.sheet.print_period_hint": "Recorded attendance, one page per month, with totals.",
```
ar:
```json
    "att.sheet.period_order": "يجب أن يكون تاريخ النهاية في يوم البداية أو بعده.",
    "att.sheet.print_period": "طباعة فترة",
    "att.sheet.print_period_hint": "الحضور المسجل، صفحة لكل شهر، مع المجاميع.",
```

Append the three keys to `AttendanceSheetTranslationsTest::KEYS`.

- [ ] **Step 4: Verify**

Run: `npm run i18n:check`. Expected: no errors.
Run: `npm run build`. Expected: build succeeds.
Run: `php vendor/phpunit/phpunit/phpunit --filter "AttendanceSheetTranslationsTest|AttendanceSheetLinksTest|AttendanceStatsPageTest|AttendanceGridTest"`. Expected: PASS.

- [ ] **Step 5: Browser check**

Start a test server on a DB copy (see memories `worktree-test-server-media`, `db-swap-gotchas`). Then:
- Stats page: period «season», one category → the button opens a PDF with month pages and a summary.
- Grid page: the dialog defaults to the month. Setting `to` before `from` shows the error and disables Print. A valid range opens the PDF.
- Check in Arabic: rates read `90.0%` left-to-right, columns right-to-left, totals on the last part only.
- Filled month sheet: totals columns. Filled session sheet: summary line.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Attendance/Stats.vue resources/js/Pages/Attendance/Grid.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/AttendanceSheetTranslationsTest.php
git commit -m "feat(attendance): print-a-period buttons on the statistics page and the month grid"
```

---

### Task 8: Full suite + final review

- [ ] **Step 1:** Run `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit`. Expected: all green (baseline ≈ 3477+ tests plus the new ones).
- [ ] **Step 2:** Run `npm run i18n:check` and `npm run build`. Both clean.
- [ ] **Step 3:** Final whole-branch review (opus) against the spec. Fix findings and re-run the suite.
- [ ] **Step 4:** Merge into main per [[push-only-verified-tip]]: merge main into the branch, re-run the suite, then `git merge --ff-only`. Push only when the owner asks.
