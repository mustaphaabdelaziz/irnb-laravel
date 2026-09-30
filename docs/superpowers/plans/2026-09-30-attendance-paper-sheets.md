# Player Attendance — Paper Sheets (P2 of the P1 spec) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Print blank attendance sheets to take to the pitch: a monthly grid per category (A4 landscape) and a single-session sheet (A4 portrait). The coach fills them in by hand; the staff type them into the month grid later. Both are printable from the attendance calendar, the month grid and the session page.

**Architecture:** The month grid's roster × sessions logic moves out of `AttendanceGridController::show()` into a small service, `App\Services\Attendance\MonthSheet`, which both the grid page and the new month-sheet PDF use, so screen and paper always list the same players and sessions. A new `AttendanceSheetController` renders two Blade views (`pdf.attendance-month-sheet`, `pdf.attendance-session-sheet`) through `PdfService::stream()` (RTL in Arabic, landscape flag for the month sheet), with the shared `ClubHeader`, status names from `AttendanceSettings::labels()`, codes and colours from `AttendanceSettings::codes()`, and every other text from `UiLang`. Routes `attendance.sheets.month` and `attendance.sheets.session` are gated as `attendance.view` through overrides in `config/permissions.php`. The Vue pages get plain `<a :href target="_blank">` print links.

**Tech Stack:** Laravel 13, Inertia v2 + Vue 3 (`<script setup>`, JS), Tailwind 3.4, vue-i18n (flat dotted keys), mPDF through `App\Services\Pdf\PdfService`, PHPUnit feature tests on sqlite with `#[Test]`.

Spec: `docs/superpowers/specs/2026-09-29-player-attendance-design.md`, section **"Paper sheets (P2)"**. Codes, colours and names: `docs/superpowers/specs/2026-09-29-player-attendance-p2-design.md` (codes are configurable; marks store the status, never the code).

## Global Constraints

- Work in the existing worktree `D:/irnb-attendance` on branch `feat/attendance-next` (it starts at main `ecb0850`, with P1, 2a and 2b merged). It already has `vendor/`, `node_modules/` and `.env`; do not create it. Use Git Bash and start every shell with `cd /d/irnb-attendance`.
- Stage explicit file paths only (`git add <file>...`). Never `git add -A` or `git add .`. Never commit `.superpowers/` (or `.claude/`).
- Commit with exactly: `git commit -m "<subject>" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"`.
- Dates travel as `'Y-m-d'` strings and times as `'H:i'` strings (`training_sessions.date` is a string column). Never add `date`/`datetime` casts.
- Offline only: no CDN, remote fonts, remote images or remote scripts, in the PDFs too (mPDF uses its bundled fonts; the club logo is a local file from `ClubHeader`).
- Blade views print with `{{ }}` only, never `{!! !!}`. mPDF page-number placeholders (`{PAGENO}`, `{nbpg}`) are plain text and pass through `{{ }}` unchanged.
- i18n: flat dotted keys in `resources/js/i18n/{ar,fr,en}.json`. In Vue use `t()` only, never `te()`. New keys are appended with the temporary `att-i18n.mjs` script given in Task 2 (run it, then delete it). Do **not** use `scripts/i18n-add.mjs`: it re-sorts the files. The files must round-trip exactly through `JSON.stringify(o, null, 4) + '\n'`, and the diff must be additions only (the previous last line of each file gains a comma; nothing else changes). This plan emits no `flash.*` key.
- Templates never call globals: no `@click="window.x()"`. Print buttons are plain `<a :href="..." target="_blank">` links to the PDF route.
- Never name a test helper `session()`: it collides with Laravel's TestCase. The helpers in this plan are `training()`, `mark()`, `userIn()`, `userWith()`, `spyPdf()`, `sheetHtml()` and `pages()`.
- JSON round-trips turn whole floats into ints (`1.0` comes back as `1`): compare JSON/Inertia numbers with that in mind. (The sheets carry no floats; this matters only if you touch grid props.)
- Rebuild with `npm run build` before running tests that make Inertia page assertions after a `.vue` file changed (the root view loads the page through the Vite manifest).
- Print: a page's own controls carry `print:hidden`.
- **No migration is expected.** If one ever becomes necessary: never rebuild a sqlite table that other tables reference with cascading foreign keys (`->change()` or dropping a column rebuilds the table on sqlite and cascade-deletes child rows).
- `Player::booted()` clears `left_at` unless the status is "left": tests that need a leave date create the player with `['status_id' => Player::leftStatusId(), 'left_at' => '...']`.
- Tests: PHPUnit classes with `#[Test]` and `use RefreshDatabase;` (plus `Tests\Support\AttendanceFixtures`), in `tests/Feature`. Run with `php artisan test --filter=<Class>`. PDF tests swap `PdfService` for a spy that records `stream($html, $filename, $rtl, $landscape)` and assert the real HTML; each sheet also has at least one real mPDF render.
- Permissions come from route names via `config/permissions.php` and `App\Support\PermissionMap::deriveAction()` (view verbs: `index`, `show`, `export`, `template`, `card`, `history`, `report`, `receipt`, `minutes`, `calendar`, `inventory`, `preview-serial`; anything else falls to `edit`). New routes:
  - `attendance.sheets.month` → last segment `month` is not a view verb → override `['attendance', 'view']`;
  - `attendance.sheets.session` → last segment `session` is not a view verb → override `['attendance', 'view']`.
  Both are asserted in `AttendancePermissionTest` and by a 200/403 request test per sheet.
- PDFs print status names from `AttendanceSettings::labels()` (configured name in the app locale, else `att.status.<status>` through `UiLang`), codes and colours from `AttendanceSettings::codes()` (normalised with `AttendanceCode::normalise()`, the form the grid accepts), and every other text from `UiLang::get()` — exactly like `pdf.attendance-stats` and `pdf.attendance-player`. PDFs are right-to-left when the app locale is `ar`: `PdfService::stream($html, $filename, app()->getLocale() === 'ar', $landscape)`.
- Ambiguities in the spec resolved by this plan (report them to the owner when done):
  1. **Month roster.** Rows are exactly the month grid's rows: for each non-cancelled session of the month, its frozen roster once marked, else the expected roster **on that session's date** (category members, not archived, `left_at` null or after the date — `Roster::expected()`); a row is every player on at least one of those lists. This is the spec's "active, not archived, not left before the month started", tightened per session: a player who left on the 3rd, before the month's first session on the 5th, is not listed, because he could never be marked. Where a player is not on a session's list (he left, joined the category later, or was not in a marked session), his cell is printed grey: the grid will not accept a code there either.
  2. **Pre-filling marked sessions.** Blank by default. `?filled=1` prints the stored codes (month sheet) or the ticks, minutes, reasons and notes (session sheet) as a record. The calendar offers the blank sheet only; the grid page offers both; the session page offers the "with marks" link once the session has marks. The session's coach, title/goal and notes are printed whenever they are set (they are planning data, not marks).
  3. **"File number"** is the player's paper-folder number (`players.file_number`, zero-padded by `FileNumber::format()`, "—" when unassigned), not the membership id, as in the board table PDF.
  4. **Names** print as "LASTNAME Firstname", sorted by last name then first name — the same text and order as the month grid, so typing the sheet in is row-for-row.
  5. **Joint pre-season sessions** appear on the sheet of every category taking part, with the session's whole roster (as in the grid, where saving a column replaces the whole session). A player whose category is not the sheet's category shows it in small print, e.g. "(U17)". On the session sheet a joint session tags every player with his category; a single-category session tags nobody.
  6. **Many sessions.** At most **16 session columns** per table (`AttendanceSheetController::MAX_COLUMNS`); beyond that the sessions continue in a second part on a new page with the same rows, titled "Sessions 17–20 of 20". Each part is a complete sheet (club header, legend, signature boxes).
  7. **Page split.** The rows flow across pages and mPDF repeats the table's `<thead>` on each page (about 20 rows on the first page under the club header, about 25 on the next ones, at 6.5 mm per row — enough to hand-write "R15"). A page footer carries "category · month — Page n of m" so loose pages can be put back together. No manual row chunking.
  8. **Blank space for the unplanned.** The month sheet ends with **2 blank columns** (date to write in) when they fit in the last part, for a session added on the day (pre-season sessions are created ad hoc). The session sheet ends with **3 blank rows** for a player who came without being on the list. Both are explained in the legend.
  9. **Column headers**: short weekday, `dd/mm`, start time, a kind marker for non-regular sessions (localised short mark, e.g. "PP" = préparation physique, "SUP" = supplémentaire; explained in the legend), and the title/goal cut to 24 characters in small print.
  10. **Legend** = every status in the configured order: colour square, code (with "15" after minute-taking codes, e.g. `R15`), configured name; then the rule "one code per cell, minutes after the late/left-early codes, an empty cell counts as present when typed into the grid" (the grid's own rule), the kind markers, the grey-cell and blank-column notes. The session sheet adds the excuse reasons.
  11. **Signature boxes** (month sheet): coach name, coach signature, and "entered in the app on … by …" for the person who types it in. Session sheet log: coach, title/goal, notes, signature, entered on/by.
  12. **A month without sessions** prints the header and "No sessions this month." (no table): a sheet without columns is useless, and the calendar already says to add a weekly schedule.
  13. **Cancelled sessions**: excluded from the month sheet. The session page hides the print link on a cancelled session; the URL still works and prints a "Cancelled: reason" banner.
  14. **Print for a date range: skipped.** Not trivial: a range crosses months and seasons, so headers, generation (month by month), the column split and the grid's month unit would all need a second code path. Printing successive months covers it.
  15. **Route names** `attendance.sheets.month` / `attendance.sheets.session` read clearly in `routes/web.php`; neither last segment is a view verb, so both get an explicit `['attendance', 'view']` override (asserted).

---

## File map

```
app/Services/Attendance/MonthSheet.php                  (new) sessions + roster cells + players for one category and month (generates the month first)
app/Services/Attendance/Roster.php                      COLUMNS + file_number (the session sheet prints it)
app/Http/Controllers/AttendanceGridController.php       show() uses MonthSheet (behaviour unchanged)
app/Http/Controllers/AttendanceSheetController.php      (new) month() and session() PDFs
routes/web.php                                          2 routes in the attendance block
config/permissions.php                                  overrides attendance.sheets.month / .session => view
resources/views/pdf/attendance-month-sheet.blade.php    (new) A4 landscape
resources/views/pdf/attendance-session-sheet.blade.php  (new) A4 portrait
resources/js/Pages/Attendance/Index.vue                 "Print sheet" link (month view)
resources/js/Pages/Attendance/Grid.vue                  "Print sheet" + "Print with marks" links
resources/js/Pages/Attendance/Session.vue               "Print session sheet" (+ "Print with marks" once saved)
resources/js/i18n/{ar,fr,en}.json                       att.sheet.* keys (additions only)
tests: Feature/AttendanceMonthSheetTest (new), Feature/AttendanceSheetTranslationsTest (new),
       Feature/AttendanceMonthSheetPdfTest (new), Feature/AttendanceSessionSheetPdfTest (new),
       Feature/AttendanceSheetLinksTest (new);
       updated: Feature/AttendancePermissionTest; unchanged but must stay green: Feature/AttendanceGridTest
```

---

### Task 1: `MonthSheet` service, shared by the month grid

**Files:**
- Create: `app/Services/Attendance/MonthSheet.php`
- Modify: `app/Http/Controllers/AttendanceGridController.php`
- Test: `tests/Feature/AttendanceMonthSheetTest.php` (new); `tests/Feature/AttendanceGridTest.php` must stay green unchanged

**Interfaces:**
- Consumes: `SessionGenerator::forMonth(int $categoryId, int $year, int $month): int`; `Roster::expected(int|array $categoryIds, string $date): Collection<int, Player>`; `TrainingSession::includingCategory(int)`, `TrainingSession::categoryIds(): array<int,int>`; `AttendanceCode::fromSettings()->format(AttendanceStatus, ?int): string`; enum `SessionState`.
- Produces: `App\Services\Attendance\MonthSheet` (container-resolved; constructor `SessionGenerator $generator, Roster $roster`) with
  `build(int $categoryId, int $year, int $month): array{sessions: Collection<int, TrainingSession>, cells: array<int, array<int, string>>, players: Collection<int, Player>}`
  - `sessions`: the month's non-cancelled sessions the category takes part in (own + joint through the pivot), ordered by date, start time, id; `attendances` and `categories` eager-loaded.
  - `cells`: `[playerId => [sessionId => code]]`, in session order; a key exists only where the player is on that session's list; the value is the stored code (`AttendanceCode::format`) for a marked session, `''` otherwise.
  - `players`: every player with at least one cell, columns `id, firstname, lastname, file_number, category_id`, ordered by last name then first name.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceMonthSheetTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Attendance\MonthSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceMonthSheetTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function training(Category $category, string $date, array $extra = []): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ] + $extra);
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status = AttendanceStatus::Present, ?int $minutes = null): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'status' => $status, 'minutes' => $minutes]);
        $training->update(['state' => SessionState::Held]);
    }

    #[Test]
    public function it_generates_the_month_and_leaves_out_cancelled_sessions(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);
        $cancelled = $this->training($u15, '2026-10-07', ['state' => SessionState::Cancelled, 'cancel_reason' => 'Rain']);

        $sheet = app(MonthSheet::class)->build($u15->id, 2026, 10);

        // October 2026: Mondays 5, 12, 19, 26.
        $this->assertSame(['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26'], $sheet['sessions']->pluck('date')->all());
        $this->assertNotContains($cancelled->id, $sheet['sessions']->modelKeys());
        $this->assertSame([$a->id], $sheet['players']->modelKeys());
        $this->assertSame(array_fill_keys($sheet['sessions']->modelKeys(), ''), $sheet['cells'][$a->id]);
    }

    #[Test]
    public function cells_follow_the_expected_roster_before_marking_and_the_frozen_one_after(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $left = Player::leftStatusId();
        $stays = $this->player($u15);
        $this->player($u15, ['archived' => true]);
        $this->player($u15, ['status_id' => $left, 'left_at' => '2026-09-20']);
        $leftMid = $this->player($u15, ['status_id' => $left, 'left_at' => '2026-10-10']);
        $moved = $this->player($u15);
        $held = $this->training($u15, '2026-10-05');
        $this->mark($held, $stays);
        $this->mark($held, $moved, AttendanceStatus::Late, 15);
        $moved->update(['category_id' => $u17->id]);
        $newcomer = $this->player($u15);
        $early = $this->training($u15, '2026-10-07');
        $planned = $this->training($u15, '2026-10-12');

        $sheet = app(MonthSheet::class)->build($u15->id, 2026, 10);

        $this->assertSame([$stays->id, $leftMid->id, $moved->id, $newcomer->id], $sheet['players']->modelKeys());
        $this->assertSame([$held->id => 'P', $early->id => '', $planned->id => ''], $sheet['cells'][$stays->id]);
        $this->assertSame([$early->id => ''], $sheet['cells'][$leftMid->id]);
        $this->assertSame([$held->id => 'R15'], $sheet['cells'][$moved->id]);
        $this->assertSame([$early->id => '', $planned->id => ''], $sheet['cells'][$newcomer->id]);
        $this->assertArrayHasKey('file_number', $sheet['players']->first()->getAttributes());
    }

    #[Test]
    public function a_joint_session_brings_its_whole_roster_into_each_category(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $a = $this->player($u15);
        $b = $this->player($u17);
        $joint = $this->training($u17, '2026-10-08', ['kind' => SessionKind::Preseason]);
        $joint->categories()->syncWithoutDetaching([$u15->id]);
        $this->training($u17, '2026-10-09');

        $sheet = app(MonthSheet::class)->build($u15->id, 2026, 10);

        $this->assertSame([$joint->id], $sheet['sessions']->modelKeys());
        $this->assertSame([$a->id, $b->id], $sheet['players']->modelKeys());
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php artisan test --filter=AttendanceMonthSheetTest`
Expected: FAIL — `Target class [App\Services\Attendance\MonthSheet] does not exist.`

- [ ] **Step 3: Write the service**

`app/Services/Attendance/MonthSheet.php`:
```php
<?php

namespace App\Services\Attendance;

use App\Enums\SessionState;
use App\Models\Player;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One category's month as players × sessions, shared by the month grid and
 * the printed month sheet so paper and screen always list the same people.
 * A cell exists only where the player is on that session's list: the frozen
 * marks once saved, else the expected roster on the session's date. A joint
 * pre-season session brings its whole roster, since saving its column
 * replaces all of its marks.
 */
final class MonthSheet
{
    private const PLAYER_COLUMNS = ['id', 'firstname', 'lastname', 'file_number', 'category_id'];

    public function __construct(
        private readonly SessionGenerator $generator,
        private readonly Roster $roster,
    ) {}

    /**
     * Generates the month first (idempotent), like opening it on the calendar.
     *
     * @return array{sessions: Collection<int, TrainingSession>, cells: array<int, array<int, string>>, players: Collection<int, Player>}
     */
    public function build(int $categoryId, int $year, int $month): array
    {
        $this->generator->forMonth($categoryId, $year, $month);
        $first = CarbonImmutable::create($year, $month, 1);

        $sessions = TrainingSession::includingCategory($categoryId)
            ->where('state', '!=', SessionState::Cancelled->value)
            ->whereBetween('date', [$first->toDateString(), $first->endOfMonth()->toDateString()])
            ->with(['attendances', 'categories'])
            ->orderBy('date')->orderBy('start_time')->orderBy('id')
            ->get();

        $codes = AttendanceCode::fromSettings();
        $cells = [];
        $expected = [];
        foreach ($sessions as $session) {
            if ($session->attendances->isNotEmpty()) {
                foreach ($session->attendances as $mark) {
                    $cells[$mark->player_id][$session->id] = $codes->format($mark->status, $mark->minutes);
                }

                continue;
            }
            $categoryIds = $session->categoryIds();
            $key = $session->date.'|'.implode(',', $categoryIds);
            $expected[$key] ??= $this->roster->expected($categoryIds, $session->date)->modelKeys();
            foreach ($expected[$key] as $playerId) {
                $cells[$playerId][$session->id] = '';
            }
        }

        $players = Player::whereIn('id', array_keys($cells))
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::PLAYER_COLUMNS);

        return ['sessions' => $sessions, 'cells' => $cells, 'players' => $players];
    }
}
```

- [ ] **Step 4: Make the grid use it**

In `app/Http/Controllers/AttendanceGridController.php`, replace the whole `show()` method with:
```php
    public function show(Request $request, MonthSheet $sheet): Response
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'month' => ['required', 'date_format:Y-m'],
        ]);
        $category = Category::findOrFail($data['category_id']);
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month']);
        ['sessions' => $sessions, 'cells' => $cells, 'players' => $players] = $sheet->build($category->id, $anchor->year, $anchor->month);

        return Inertia::render('Attendance/Grid', [
            'category' => ['id' => $category->id, 'name' => $category->localized_name],
            'month' => $anchor->format('Y-m'),
            'sessions' => $sessions->map(fn (TrainingSession $s) => [
                'id' => $s->id, 'date' => $s->date, 'start_time' => $s->start_time, 'kind' => $s->kind->value, 'state' => $s->state->value,
            ])->values(),
            'rows' => $players->map(fn (Player $p) => ['id' => $p->id, 'name' => trim("{$p->lastname} {$p->firstname}")])->values(),
            'cells' => (object) $cells,
            'attendanceCodes' => AttendanceSettings::codes(),
        ]);
    }
```
In the imports, add `use App\Services\Attendance\MonthSheet;` and remove `use App\Services\Attendance\SessionGenerator;` (no longer used; `Roster`, `AttendanceCode`, `SessionState` stay: `save()` uses them). Update the class docblock's first sentence to: `Month grid: players × sessions (see MonthSheet), filled with the paper-sheet codes.` and keep the rest.

- [ ] **Step 5: Run the tests to see them pass**

Run: `php artisan test --filter="AttendanceMonthSheetTest|AttendanceGridTest"`
Expected: PASS (3 new tests, and every grid test unchanged).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/MonthSheet.php app/Http/Controllers/AttendanceGridController.php tests/Feature/AttendanceMonthSheetTest.php
git commit -m "refactor(attendance): extract the month grid's roster x sessions into MonthSheet" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Sheet translations

**Files:**
- Modify: `resources/js/i18n/en.json`, `resources/js/i18n/fr.json`, `resources/js/i18n/ar.json` (additions only)
- Test: `tests/Feature/AttendanceSheetTranslationsTest.php` (new)

**Interfaces:**
- Consumes: nothing.
- Produces: the `att.sheet.*` keys below in all three catalogs, read by the PDFs through `UiLang::get()` and by the Vue pages through `t()`. Placeholders: `att.sheet.legend_help` `{late}`; `att.sheet.part` `{from}`, `{to}`, `{total}`; `att.sheet.page` `{page}`, `{pages}`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceSheetTranslationsTest.php`:
```php
<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendanceSheetTranslationsTest extends TestCase
{
    public const KEYS = [
        'att.sheet.month_title', 'att.sheet.session_title', 'att.sheet.month', 'att.sheet.file_no',
        'att.sheet.legend', 'att.sheet.legend_help', 'att.sheet.off_roster', 'att.sheet.blank_columns',
        'att.sheet.blank_rows', 'att.sheet.tick_help', 'att.sheet.kind_mark.preseason', 'att.sheet.kind_mark.extra',
        'att.sheet.part', 'att.sheet.page', 'att.sheet.coach_name', 'att.sheet.signature',
        'att.sheet.entered_on', 'att.sheet.entered_by', 'att.sheet.filled_note',
        'att.sheet.print', 'att.sheet.print_filled', 'att.sheet.print_session', 'att.sheet.print_hint',
    ];

    #[Test]
    public function every_sheet_label_exists_in_the_three_catalogs(): void
    {
        foreach (['ar', 'fr', 'en'] as $locale) {
            $catalog = json_decode((string) file_get_contents(resource_path("js/i18n/{$locale}.json")), true);
            foreach (self::KEYS as $key) {
                $this->assertNotEmpty($catalog[$key] ?? null, "{$locale}: {$key} is missing");
            }
        }
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php artisan test --filter=AttendanceSheetTranslationsTest`
Expected: FAIL — `ar: att.sheet.month_title is missing`.

- [ ] **Step 3: Append the translations**

Create `att-i18n.mjs` in the repo root:
```js
import { readFileSync, writeFileSync } from 'node:fs';

// [en, fr, ar]
const keys = {
    'att.sheet.month_title': ['Attendance sheet', 'Feuille de présence', 'ورقة الحضور'],
    'att.sheet.session_title': ['Session attendance sheet', 'Feuille de présence de la séance', 'ورقة حضور الحصة'],
    'att.sheet.month': ['Month', 'Mois', 'الشهر'],
    'att.sheet.file_no': ['File no.', 'N° dossier', 'رقم الملف'],
    'att.sheet.legend': ['Codes', 'Codes', 'الرموز'],
    'att.sheet.legend_help': [
        'Write one code per cell; after the late and left-early codes write the minutes (e.g. {late}15). An empty cell counts as present when typed into the month grid.',
        'Un code par case ; après les codes de retard et de départ anticipé, écrivez les minutes (ex. {late}15). Une case vide compte comme présent lors de la saisie dans la grille du mois.',
        'اكتب رمزًا واحدًا في كل خانة، وبعد رمزَي التأخر والمغادرة المبكرة اكتب عدد الدقائق (مثال: {late}15). الخانة الفارغة تُحتسب حضورًا عند إدخالها في شبكة الشهر.',
    ],
    'att.sheet.off_roster': ["Grey cell: the player is not on that session's list.", 'Case grise : le joueur ne figure pas sur la liste de cette séance.', 'الخانة الرمادية: اللاعب غير مدرج في قائمة هذه الحصة.'],
    'att.sheet.blank_columns': ['Empty columns: write the date of a session added on the day.', "Colonnes vides : inscrivez la date d'une séance ajoutée le jour même.", 'الأعمدة الفارغة: اكتب تاريخ حصة أُضيفت في اليوم نفسه.'],
    'att.sheet.blank_rows': ['Empty rows: players who came without being on the list.', 'Lignes vides : joueurs venus sans figurer sur la liste.', 'الأسطر الفارغة: لاعبون حضروا دون أن يكونوا في القائمة.'],
    'att.sheet.tick_help': [
        'Tick one status per player and write the minutes for a late arrival or an early departure.',
        'Cochez un statut par joueur et indiquez les minutes en cas de retard ou de départ anticipé.',
        'ضع علامة على حالة واحدة لكل لاعب، واكتب عدد الدقائق عند التأخر أو المغادرة المبكرة.',
    ],
    'att.sheet.kind_mark.preseason': ['PS', 'PP', 'ت.ب'],
    'att.sheet.kind_mark.extra': ['EX', 'SUP', 'إض'],
    'att.sheet.part': ['Sessions {from}–{to} of {total}', 'Séances {from} à {to} sur {total}', 'الحصص {from}–{to} من {total}'],
    'att.sheet.page': ['Page {page} of {pages}', 'Page {page} sur {pages}', 'صفحة {page} من {pages}'],
    'att.sheet.coach_name': ['Coach name', "Nom de l'entraîneur", 'اسم المدرب'],
    'att.sheet.signature': ['Signature', 'Signature', 'التوقيع'],
    'att.sheet.entered_on': ['Entered in the app on', "Saisi dans l'application le", 'أُدخلت في التطبيق بتاريخ'],
    'att.sheet.entered_by': ['by', 'par', 'بواسطة'],
    'att.sheet.filled_note': ['Printed with the marks already recorded.', 'Imprimée avec les présences déjà enregistrées.', 'طُبعت مع الحضور المسجَّل.'],
    'att.sheet.print': ['Print sheet', 'Imprimer la feuille', 'طباعة الورقة'],
    'att.sheet.print_filled': ['Print with marks', 'Imprimer avec les présences', 'طباعة مع الحضور المسجَّل'],
    'att.sheet.print_session': ['Print session sheet', 'Imprimer la feuille de séance', 'طباعة ورقة الحصة'],
    'att.sheet.print_hint': ['Blank A4 sheet to fill in by hand on the pitch.', 'Feuille A4 vierge à remplir à la main sur le terrain.', 'ورقة A4 فارغة تُملأ يدويًا في الملعب.'],
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
Expected: exactly three removed lines, one per file (the previous last key gaining a comma). None of the strings contains `|` or `@` (vue-i18n syntax characters).

- [ ] **Step 4: Run the tests to see them pass**

Run: `php artisan test --filter=AttendanceSheetTranslationsTest && npm run i18n:check`
Expected: PASS, and the check prints no `does not resolve` line.

- [ ] **Step 5: Commit**

```bash
git add resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json tests/Feature/AttendanceSheetTranslationsTest.php
git commit -m "feat(attendance): translations for the paper sheets" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Month sheet PDF (A4 landscape)

**Files:**
- Create: `app/Http/Controllers/AttendanceSheetController.php`, `resources/views/pdf/attendance-month-sheet.blade.php`
- Modify: `routes/web.php`, `config/permissions.php`, `tests/Feature/AttendancePermissionTest.php`
- Test: `tests/Feature/AttendanceMonthSheetPdfTest.php` (new)

**Interfaces:**
- Consumes: `MonthSheet::build()` (Task 1); `ClubHeader::data()`; `AttendanceSettings::labels(): array<string,string>` and `::codes(): array<string, array{code, color, label}>`; `AttendanceCode::normalise(?string): string`; `AttendanceStatus::from($value)->takesMinutes()`; `Season::forDate($date)->label()`; `FileNumber::format(?int): string`; `PdfService::stream(string $html, string $filename, bool $rtl, bool $landscape)`; the `att.sheet.*` keys (Task 2).
- Produces:
  - Route `GET /attendance/sheets/month?category_id=<int>&month=<Y-m>[&filled=1]`, name `attendance.sheets.month`, permission `['attendance', 'view']` (override).
  - `App\Http\Controllers\AttendanceSheetController` (constructor `PdfService $pdf`) with `public const MAX_COLUMNS = 16`, `public const BLANK_COLUMNS = 2`, `public const BLANK_ROWS = 3`, `month(Request $request, MonthSheet $sheet): Symfony\Component\HttpFoundation\Response`, and private static helpers `fileNumber(Player $player): string`, `name(Player $player): string`.
  - Filename `attendance-sheet-{categoryId}-{Y-m}.pdf`; `$rtl = locale === 'ar'`; `$landscape = true`.
  - View `pdf.attendance-month-sheet` receives: `club`; `category` `array{id: int, name: string}`; `monthLabel` string (e.g. "octobre 2026"); `season` string (e.g. "2026/27"); `groups` `list<array{columns: list<array{id: int, day: string, date: string (dd/mm), time: string, kind: string, title: ?string}>, from: int, to: int, blank: int}>`; `total` int; `rows` `list<array{id: int, file_number: string, name: string, category: ?string}>`; `cells` (from `MonthSheet`); `filled` bool; `labels`; `codes`.
  - HTML markers the tests rely on: one `<thead>` per part; `<pagebreak />` between parts; `class="cell"` (on a list), `class="off"` (grey, not on that session's list), `class="blank"` (blank column cell), `class="kind"` (kind marker in a header); `{PAGENO}`/`{nbpg}` in the footer.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AttendanceMonthSheetPdfTest.php`:
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
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Pdf\PdfService;
use App\Services\Player\FileNumber;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceMonthSheetPdfTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function userIn(string $locale): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function training(Category $category, string $date, array $extra = []): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ] + $extra);
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status = AttendanceStatus::Present, ?int $minutes = null): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'status' => $status, 'minutes' => $minutes]);
        $training->update(['state' => SessionState::Held]);
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

    private function url(Category $category, array $query = []): string
    {
        return route('attendance.sheets.month', ['category_id' => $category->id, 'month' => '2026-10'] + $query);
    }

    private function sheetHtml(Category $category, string $locale = 'fr', array $query = []): string
    {
        $seen = $this->spyPdf();
        $this->actingAs($this->userIn($locale))->get($this->url($category, $query))->assertOk();

        return $seen['html'];
    }

    /** Pages in a real mPDF document (page objects, not the /Pages tree). */
    private static function pages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page\b#', $pdf);
    }

    #[Test]
    public function the_sheet_renders_as_a_real_pdf_in_arabic(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-10-05', ['kind' => SessionKind::Preseason, 'title' => 'جري 7 كم']);

        $response = $this->actingAs($this->userIn('ar'))->get($this->url($u15))->assertOk();

        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    #[Test]
    public function the_french_sheet_is_landscape_with_the_club_category_month_and_season(): void
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        FileNumber::assign($player);
        $this->training($u15, '2026-10-05', ['title' => 'Sprint 30 m']);
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get($this->url($u15))->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertTrue($seen['landscape']);
        $this->assertSame("attendance-sheet-{$u15->id}-2026-10.pdf", $seen['filename']);
        foreach (['Feuille de présence', 'U15', 'octobre 2026', '2026/27', 'Test001 P1', '0001', '05/10', '18:00', 'Sprint 30 m', 'Signature', '<thead>', '{PAGENO}', '{nbpg}'] as $text) {
            $this->assertStringContainsString($text, $seen['html'], $text);
        }
        $this->assertStringNotContainsString('Imprimée avec les présences', $seen['html']);
    }

    #[Test]
    public function printing_generates_the_month_first(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);

        $html = $this->sheetHtml($u15);

        $this->assertSame(4, TrainingSession::count());
        foreach (['05/10', '12/10', '19/10', '26/10'] as $date) {
            $this->assertStringContainsString($date, $html);
        }
    }

    #[Test]
    public function rows_follow_the_roster_rules(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $left = Player::leftStatusId();
        $stays = $this->player($u15);                                                  // Test001 P1
        $this->player($u15, ['archived' => true]);                                     // Test002 P2
        $this->player($u15, ['status_id' => $left, 'left_at' => '2026-09-20']);       // Test003 P3: left before the month
        $this->player($u15, ['status_id' => $left, 'left_at' => '2026-10-10']);       // Test004 P4: left mid-month
        $moved = $this->player($u15);                                                  // Test005 P5
        $held = $this->training($u15, '2026-10-05');
        $this->mark($held, $stays);
        $this->mark($held, $moved);
        $moved->update(['category_id' => $u17->id]);
        $this->player($u15);                                                           // Test006 P6: newcomer
        $this->training($u15, '2026-10-07');
        $this->training($u15, '2026-10-12');

        $html = $this->sheetHtml($u15);

        foreach (['Test001 P1', 'Test004 P4', 'Test005 P5', 'Test006 P6', '(U17)'] as $listed) {
            $this->assertStringContainsString($listed, $html, $listed);
        }
        $this->assertStringNotContainsString('Test002 P2', $html);
        $this->assertStringNotContainsString('Test003 P3', $html);
        // Grey cells: P4 is on the 7th only (the 5th is frozen, he left before the 12th),
        // P5 on the frozen 5th only, P6 not on the frozen 5th.
        $this->assertSame(5, substr_count($html, 'class="off"'));
        // Blank sheet: the stored "P" marks of the 5th are not printed.
        $this->assertSame(0, substr_count($html, 'class="cell">P<'));
    }

    #[Test]
    public function a_joint_pre_season_session_appears_with_its_whole_roster(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $this->player($u15);
        $this->player($u17);
        $joint = $this->training($u17, '2026-10-08', ['kind' => SessionKind::Preseason, 'start_time' => '09:00', 'end_time' => '10:30']);
        $joint->categories()->syncWithoutDetaching([$u15->id]);
        $this->training($u17, '2026-10-09');

        $html = $this->sheetHtml($u15);

        $this->assertStringContainsString('08/10', $html);
        $this->assertStringContainsString('09:00', $html);
        $this->assertStringContainsString('class="kind">PP<', $html);
        $this->assertStringContainsString('Test002 P2', $html);
        $this->assertStringContainsString('(U17)', $html);
        $this->assertStringNotContainsString('09/10', $html);
    }

    #[Test]
    public function cancelled_sessions_are_left_out(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-10-05');
        $this->training($u15, '2026-10-14', ['state' => SessionState::Cancelled, 'cancel_reason' => 'Pluie']);

        $html = $this->sheetHtml($u15);

        $this->assertStringContainsString('05/10', $html);
        $this->assertStringNotContainsString('14/10', $html);
    }

    #[Test]
    public function stored_codes_are_printed_only_when_asked(): void
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        $this->mark($this->training($u15, '2026-10-05'), $player, AttendanceStatus::Late, 25);

        $blank = $this->sheetHtml($u15);
        $filled = $this->sheetHtml($u15, 'fr', ['filled' => 1]);

        $this->assertStringNotContainsString('R25', $blank);
        $this->assertStringContainsString('class="cell">R25<', $filled);
        $this->assertStringContainsString('Imprimée avec les présences déjà enregistrées.', $filled);
    }

    #[Test]
    public function the_legend_lists_every_configured_code_and_name(): void
    {
        AttendanceSettings::save(['codes' => ['late' => ['code' => 'T', 'label' => ['fr' => 'Tardif']]]]);
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-10-05');

        $html = $this->sheetHtml($u15);

        foreach (['T15', 'Tardif', 'Présent', 'D15', 'Parti tôt', 'Présent, sans entraînement', 'AE', 'Absent (justifié)', 'AN', 'Absent (non justifié)', '#059669'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringNotContainsString('R15', $html);
    }

    #[Test]
    public function the_arabic_sheet_is_right_to_left(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-10-05');
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('ar'))->get($this->url($u15))->assertOk();

        $this->assertTrue($seen['rtl']);
        $this->assertTrue($seen['landscape']);
        $this->assertStringContainsString('ورقة الحضور', $seen['html']);
        $this->assertStringContainsString('متأخر', $seen['html']);
    }

    #[Test]
    public function more_than_sixteen_sessions_continue_in_a_second_part(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        for ($day = 1; $day <= 20; $day++) {
            $this->training($u15, sprintf('2026-10-%02d', $day));
        }

        $html = $this->sheetHtml($u15);

        $this->assertSame(2, substr_count($html, '<thead>'));
        $this->assertSame(1, substr_count($html, '<pagebreak'));
        $this->assertStringContainsString('Séances 1 à 16 sur 20', $html);
        $this->assertStringContainsString('Séances 17 à 20 sur 20', $html);
        // The two blank columns fit after the last four sessions (one row).
        $this->assertSame(2, substr_count($html, 'class="blank"'));
    }

    #[Test]
    public function a_month_without_sessions_says_so(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);

        $html = $this->sheetHtml($u15);

        $this->assertStringContainsString('Aucune séance ce mois-ci.', $html);
        $this->assertStringNotContainsString('<thead>', $html);
    }

    #[Test]
    public function a_short_roster_fits_on_one_page_and_a_long_one_flows_over_several(): void
    {
        $u15 = $this->category('U15');
        for ($i = 0; $i < 8; $i++) {
            $this->player($u15);
        }
        foreach (['2026-10-05', '2026-10-07', '2026-10-12', '2026-10-14'] as $date) {
            $this->training($u15, $date);
        }
        $admin = $this->userIn('fr');

        $short = (string) $this->actingAs($admin)->get($this->url($u15))->assertOk()->getContent();
        $this->assertSame(1, self::pages($short));

        for ($i = 0; $i < 32; $i++) {
            $this->player($u15);
        }
        $long = (string) $this->actingAs($admin)->get($this->url($u15))->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(2, self::pages($long));
    }

    #[Test]
    public function printing_needs_attendance_view_only(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-10-05');
        $this->spyPdf();

        $this->actingAs($this->userWith(['attendance' => ['view']]))->get($this->url($u15))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view']]))->get($this->url($u15))->assertForbidden();
    }
}
```

In `tests/Feature/AttendancePermissionTest.php`, inside `attendance_is_a_module_and_its_routes_map_to_it()`, after the line `$this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.players.report'));` add:
```php

        // Paper sheets only read. "month" is not a view verb, so the route has an override.
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.sheets.month'));
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="AttendanceMonthSheetPdfTest|AttendancePermissionTest"`
Expected: FAIL — `Route [attendance.sheets.month] not defined.`, and the permission test fails with `['attendance', 'edit']` instead of `view` (the route is not in the map yet, so it resolves by prefix to the default `edit`).

- [ ] **Step 3: Add the route and the permission override**

In `routes/web.php`, add the import next to the other attendance controllers:
```php
use App\Http\Controllers\AttendanceSheetController;
```
and in the attendance block, right after the `attendance.grid.save` route:
```php
        // Paper sheets to print and fill in by hand (A4 PDFs); both only read.
        Route::get('/attendance/sheets/month', [AttendanceSheetController::class, 'month'])->name('attendance.sheets.month');
```
In `config/permissions.php`, after the `'attendance.stats' => ['attendance', 'view'],` line:
```php
        // "month" and "session" are not view verbs: printing a blank sheet must not need edit rights.
        'attendance.sheets.month' => ['attendance', 'view'],
```

- [ ] **Step 4: Write the controller**

`app/Http/Controllers/AttendanceSheetController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\MonthSheet;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Services\Player\FileNumber;
use App\Support\AttendanceSettings;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paper sheets the coach takes to the pitch and fills in by hand; the staff
 * type them into the month grid afterwards. Blank by default; `filled=1`
 * prints what is already recorded, as a record. Both routes only read
 * (attendance/view, see config/permissions.php).
 */
class AttendanceSheetController extends Controller
{
    /** Session columns per printed table; more continue in a second part on a new page. */
    public const MAX_COLUMNS = 16;

    /** Blank columns after the month's last session, for a session added on the day, when they fit. */
    public const BLANK_COLUMNS = 2;

    /** Blank rows at the end of the session sheet, for a player who came without being on the list. */
    public const BLANK_ROWS = 3;

    public function __construct(private readonly PdfService $pdf) {}

    /** One category's month: players × sessions, A4 landscape (right-to-left in Arabic). */
    public function month(Request $request, MonthSheet $sheet): Response
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'month' => ['required', 'date_format:Y-m'],
            'filled' => ['nullable', 'boolean'],
        ]);
        $category = Category::findOrFail($data['category_id']);
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month']);
        $locale = app()->getLocale();
        ['sessions' => $sessions, 'cells' => $cells, 'players' => $players] = $sheet->build($category->id, $anchor->year, $anchor->month);

        $columns = $sessions->map(fn (TrainingSession $s) => [
            'id' => $s->id,
            'day' => CarbonImmutable::createFromFormat('!Y-m-d', $s->date)->locale($locale)->translatedFormat('D'),
            'date' => substr($s->date, 8, 2).'/'.substr($s->date, 5, 2),
            'time' => $s->start_time,
            'kind' => $s->kind->value,
            'title' => $s->title ? Str::limit($s->title, 24) : null,
        ])->values()->all();

        $chunks = array_chunk($columns, self::MAX_COLUMNS);
        $groups = [];
        foreach ($chunks as $i => $chunk) {
            $last = $i === count($chunks) - 1;
            $groups[] = [
                'columns' => $chunk,
                'from' => $i * self::MAX_COLUMNS + 1,
                'to' => $i * self::MAX_COLUMNS + count($chunk),
                'blank' => $last && count($chunk) + self::BLANK_COLUMNS <= self::MAX_COLUMNS ? self::BLANK_COLUMNS : 0,
            ];
        }

        // Players from another category (a joint session's roster, a guest) carry its name.
        $otherIds = $players->pluck('category_id')->filter()->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === $category->id)->unique()->values()->all();
        $others = Category::whereIn('id', $otherIds)->get()
            ->mapWithKeys(fn (Category $c) => [$c->id => $c->localized_name]);

        $html = view('pdf.attendance-month-sheet', [
            'club' => ClubHeader::data(),
            'category' => ['id' => $category->id, 'name' => $category->localized_name],
            'monthLabel' => $anchor->locale($locale)->translatedFormat('F Y'),
            'season' => Season::forDate($anchor)->label(),
            'groups' => $groups,
            'total' => count($columns),
            'rows' => $players->map(fn (Player $p) => [
                'id' => $p->id,
                'file_number' => self::fileNumber($p),
                'name' => self::name($p),
                'category' => $others->get((int) $p->category_id),
            ])->values()->all(),
            'cells' => $cells,
            'filled' => $request->boolean('filled'),
            'labels' => AttendanceSettings::labels(),
            'codes' => AttendanceSettings::codes(),
        ])->render();

        return $this->pdf->stream($html, "attendance-sheet-{$category->id}-{$anchor->format('Y-m')}.pdf", $locale === 'ar', true);
    }

    /** The folder number, zero-padded; a dash when the player has none yet. */
    private static function fileNumber(Player $player): string
    {
        return FileNumber::format($player->file_number === null ? null : (int) $player->file_number) ?: '—';
    }

    /** "LASTNAME Firstname", exactly as the month grid lists the player. */
    private static function name(Player $player): string
    {
        return trim("{$player->lastname} {$player->firstname}");
    }
}
```

- [ ] **Step 5: Write the view**

`resources/views/pdf/attendance-month-sheet.blade.php`:
```blade
@php
    use App\Enums\AttendanceStatus;
    use App\Services\Attendance\AttendanceCode;

    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $statuses = array_keys($labels);
    $code = fn (string $status) => AttendanceCode::normalise($codes[$status]['code']);
    $legendCode = fn (string $status) => $code($status).(AttendanceStatus::from($status)->takesMinutes() ? '15' : '');
    $marks = ['preseason' => $L('att.sheet.kind_mark.preseason'), 'extra' => $L('att.sheet.kind_mark.extra')];
    $footer = $category['name'].' · '.$monthLabel.' — '.strtr($L('att.sheet.page'), ['{page}' => '{PAGENO}', '{pages}' => '{nbpg}']);
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
        td.cell, td.blank { text-align: center; font-weight: bold; font-size: 10px; }
        td.off { background: #d1d5db; }
        .kind, .kind-legend { font-weight: bold; color: #b45309; }
        .goal { font-size: 6px; font-weight: normal; color: #64748b; }
        .guest { font-size: 7px; color: #64748b; }
        .legend { margin-top: 5px; font-size: 8px; line-height: 1.5; }
        table.sign { width: 100%; margin-top: 6px; border-collapse: collapse; page-break-inside: avoid; }
        table.sign td { width: 33%; height: 13mm; border: 1px solid #94a3b8; vertical-align: top; padding: 3px; font-size: 8px; color: #64748b; }
    </style>
</head>
<body>
    <htmlpagefooter name="sheet"><div style="font-size:7px; color:#94a3b8; text-align:center;">{{ $footer }}</div></htmlpagefooter>
    <sethtmlpagefooter name="sheet" value="on" />

    @if ($groups === [])
        @include('pdf.partials.header')
        <div class="title">{{ $L('att.sheet.month_title') }}</div>
        <div class="sub">{{ $L('att.category') }}: <b>{{ $category['name'] }}</b> &middot; {{ $L('att.sheet.month') }}: <b>{{ $monthLabel }}</b> &middot; {{ $L('att.season') }}: <b><bdi dir="ltr">{{ $season }}</bdi></b></div>
        <div class="muted">{{ $L('att.no_sessions') }}</div>
    @endif

    @foreach ($groups as $group)
        @if (! $loop->first)
            <pagebreak />
        @endif
        @include('pdf.partials.header')
        <div class="title">{{ $L('att.sheet.month_title') }}</div>
        <div class="sub">
            {{ $L('att.category') }}: <b>{{ $category['name'] }}</b> &middot; {{ $L('att.sheet.month') }}: <b>{{ $monthLabel }}</b> &middot; {{ $L('att.season') }}: <b><bdi dir="ltr">{{ $season }}</bdi></b>
            @if (count($groups) > 1)
                &middot; {{ strtr($L('att.sheet.part'), ['{from}' => $group['from'], '{to}' => $group['to'], '{total}' => $total]) }}
            @endif
            @if ($filled)
                &middot; {{ $L('att.sheet.filled_note') }}
            @endif
        </div>

        <table class="sheet">
            <thead>
                <tr>
                    <th style="width:6mm;">#</th>
                    <th style="width:14mm;">{{ $L('att.sheet.file_no') }}</th>
                    <th style="width:52mm;">{{ $L('att.player') }}</th>
                    @foreach ($group['columns'] as $column)
                        <th>{{ $column['day'] }}<br><bdi dir="ltr">{{ $column['date'] }}</bdi><br><bdi dir="ltr">{{ $column['time'] }}</bdi>@if (isset($marks[$column['kind']]))<br><span class="kind">{{ $marks[$column['kind']] }}</span>@endif @if ($column['title'])<br><span class="goal">{{ $column['title'] }}</span>@endif</th>
                    @endforeach
                    @for ($i = 0; $i < $group['blank']; $i++)
                        <th class="blank-head">&nbsp;<br>__/__<br>&nbsp;</th>
                    @endfor
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="num">{{ $loop->iteration }}</td>
                        <td class="num">{{ $row['file_number'] }}</td>
                        <td>{{ $row['name'] }}@if ($row['category']) <span class="guest">({{ $row['category'] }})</span>@endif</td>
                        @foreach ($group['columns'] as $column)
                            @if (array_key_exists($column['id'], $cells[$row['id']] ?? []))
                                <td class="cell">{{ $filled ? $cells[$row['id']][$column['id']] : '' }}</td>
                            @else
                                <td class="off"></td>
                            @endif
                        @endforeach
                        @for ($i = 0; $i < $group['blank']; $i++)
                            <td class="blank"></td>
                        @endfor
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="legend">
            <b>{{ $L('att.sheet.legend') }}:</b>
            @foreach ($statuses as $status)
                <span style="color: {{ $codes[$status]['color'] }};">&#9632;</span> <b><bdi dir="ltr">{{ $legendCode($status) }}</bdi></b> {{ $labels[$status] }}@if (! $loop->last) &nbsp;&middot;&nbsp; @endif
            @endforeach
            <br>{{ strtr($L('att.sheet.legend_help'), ['{late}' => $code('late')]) }}
            <br><span class="kind-legend">{{ $marks['preseason'] }}</span> = {{ $L('att.kind.preseason') }} &middot; <span class="kind-legend">{{ $marks['extra'] }}</span> = {{ $L('att.kind.extra') }} &middot; {{ $L('att.sheet.off_roster') }}
            @if ($group['blank'])
                &middot; {{ $L('att.sheet.blank_columns') }}
            @endif
        </div>

        <table class="sign">
            <tr>
                <td>{{ $L('att.sheet.coach_name') }}</td>
                <td>{{ $L('att.sheet.signature') }}</td>
                <td>{{ $L('att.sheet.entered_on') }} ____________ &nbsp; {{ $L('att.sheet.entered_by') }} ____________</td>
            </tr>
        </table>
    @endforeach
</body>
</html>
```
The legend's kind markers use `class="kind-legend"`, not `class="kind"`, so `class="kind">PP<` only matches a column header (the joint-session test relies on it).

- [ ] **Step 6: Run the tests to see them pass**

Run: `php artisan test --filter="AttendanceMonthSheetPdfTest|AttendancePermissionTest|AttendanceMonthSheetTest|AttendanceGridTest"`
Expected: PASS. If `a_short_roster_fits_on_one_page_and_a_long_one_flows_over_several` fails on the one-page half, the page is too tall: shrink the club-header gap or the legend line height, never the 6.5 mm row height (it must stay writable). If the page count is 0, print the first 2 kB of the PDF and adjust the `pages()` regex to the page-object form mPDF writes.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/AttendanceSheetController.php resources/views/pdf/attendance-month-sheet.blade.php routes/web.php config/permissions.php tests/Feature/AttendanceMonthSheetPdfTest.php tests/Feature/AttendancePermissionTest.php
git commit -m "feat(attendance): printable month sheet per category (A4 landscape)" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Session sheet PDF (A4 portrait)

**Files:**
- Modify: `app/Http/Controllers/AttendanceSheetController.php`, `app/Services/Attendance/Roster.php`, `routes/web.php`, `config/permissions.php`, `tests/Feature/AttendancePermissionTest.php`
- Create: `resources/views/pdf/attendance-session-sheet.blade.php`
- Test: `tests/Feature/AttendanceSessionSheetPdfTest.php` (new)

**Interfaces:**
- Consumes: `Roster::forSession(TrainingSession): Collection<int, Player>` (now also selecting `file_number`); `TrainingSession::orderedCategories()`; enums `SessionState`, `AbsenceReason`; `AttendanceSettings::labels()/codes()`; `AttendanceCode::normalise()`; `AttendanceSheetController::fileNumber()/name()/BLANK_ROWS` (Task 3); `att.sheet.*` keys.
- Produces:
  - Route `GET /attendance/sheets/sessions/{session}[?filled=1]`, name `attendance.sheets.session`, permission `['attendance', 'view']` (override).
  - `AttendanceSheetController::session(Request $request, TrainingSession $session, Roster $roster): Response`; filename `attendance-session-{id}-{Y-m-d}.pdf`; `$rtl = locale === 'ar'`; `$landscape = false`.
  - View `pdf.attendance-session-sheet` receives: `club`; `session` `array{date: string, dateLabel: string, time: string, kind: string, categories: list<string>, coach: ?string, title: ?string, notes: ?string, cancelled: bool, cancel_reason: ?string}`; `rows` `list<array{file_number: string, name: string, category: ?string, status: ?string, minutes: ?int, reason: ?string, note: ?string}>` (mark fields null unless `filled`); `blankRows` int; `filled` bool; `labels`; `codes`; `reasons` `list<string>`.
  - HTML markers: `<thead>`; `class="tick"` (an X in the status column, filled only); `class="blank-row"` (3 per sheet).
  - `Roster::COLUMNS` = `['id', 'firstname', 'lastname', 'file_number', 'category_id']`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AttendanceSessionSheetPdfTest.php`:
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
use App\Services\Pdf\PdfService;
use App\Services\Player\FileNumber;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceSessionSheetPdfTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function userIn(string $locale): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function training(Category $category, string $date, array $extra = []): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ] + $extra);
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status = AttendanceStatus::Present, array $extra = []): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'status' => $status] + $extra);
        $training->update(['state' => SessionState::Held]);
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

    private function sheetHtml(TrainingSession $training, string $locale = 'fr', array $query = []): string
    {
        $seen = $this->spyPdf();
        $this->actingAs($this->userIn($locale))->get(route('attendance.sheets.session', ['session' => $training->id] + $query))->assertOk();

        return $seen['html'];
    }

    #[Test]
    public function the_sheet_renders_as_a_real_pdf_in_arabic(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $training = $this->training($u15, '2026-10-05', ['title' => 'جري 7 كم', 'coach' => 'كريم']);

        $response = $this->actingAs($this->userIn('ar'))->get(route('attendance.sheets.session', $training))->assertOk();

        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    #[Test]
    public function the_french_sheet_is_portrait_with_the_session_header_and_log(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $player = $this->player($u15);
        FileNumber::assign($player);
        $joint = $this->training($u15, '2026-10-05', ['kind' => SessionKind::Preseason, 'title' => 'Running 7.2 km', 'coach' => 'Karim']);
        $joint->categories()->syncWithoutDetaching([$u17->id]);
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.sheets.session', $joint))->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertFalse($seen['landscape']);
        $this->assertSame("attendance-session-{$joint->id}-2026-10-05.pdf", $seen['filename']);
        foreach (['Feuille de présence de la séance', 'U15 · U17', 'Préparation physique', 'lundi 5 octobre 2026', '18:00–19:30', 'Running 7.2 km', 'Karim', '0001', 'Signature', '<thead>'] as $text) {
            $this->assertStringContainsString($text, $seen['html'], $text);
        }
    }

    #[Test]
    public function before_marking_the_list_is_the_expected_roster_of_every_category(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $left = Player::leftStatusId();
        $this->player($u15);                                                          // Test001 P1
        $this->player($u17);                                                          // Test002 P2
        $this->player($u15, ['archived' => true]);                                    // Test003 P3
        $this->player($u15, ['status_id' => $left, 'left_at' => '2026-10-01']);      // Test004 P4: left before
        $this->player($u17, ['status_id' => $left, 'left_at' => '2026-10-20']);      // Test005 P5: leaves later
        $joint = $this->training($u15, '2026-10-05', ['kind' => SessionKind::Preseason]);
        $joint->categories()->syncWithoutDetaching([$u17->id]);

        $html = $this->sheetHtml($joint);

        foreach (['Test001 P1', 'Test002 P2', 'Test005 P5', '(U15)', '(U17)'] as $listed) {
            $this->assertStringContainsString($listed, $html, $listed);
        }
        $this->assertStringNotContainsString('Test003 P3', $html);
        $this->assertStringNotContainsString('Test004 P4', $html);
        $this->assertSame(3, substr_count($html, 'class="blank-row"'));
    }

    #[Test]
    public function after_marking_the_list_is_frozen(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $stays = $this->player($u15);   // Test001 P1
        $moved = $this->player($u15);   // Test002 P2
        $held = $this->training($u15, '2026-10-05');
        $this->mark($held, $stays);
        $this->mark($held, $moved);
        $moved->update(['category_id' => $u17->id]);
        $this->player($u15);            // Test003 P3: newcomer

        $html = $this->sheetHtml($held);

        $this->assertStringContainsString('Test001 P1', $html);
        $this->assertStringContainsString('Test002 P2', $html);
        $this->assertStringNotContainsString('Test003 P3', $html);
        // A single-category session tags nobody.
        $this->assertStringNotContainsString('(U17)', $html);
    }

    #[Test]
    public function status_columns_use_the_configured_codes_and_names(): void
    {
        AttendanceSettings::save(['codes' => ['late' => ['code' => 'T', 'label' => ['fr' => 'Tardif']]]]);
        $u15 = $this->category('U15');
        $this->player($u15);
        $training = $this->training($u15, '2026-10-05');

        $html = $this->sheetHtml($training);

        foreach (['<bdi dir="ltr">T</bdi>', 'Tardif', 'Présent', 'Parti tôt', 'Présent, sans entraînement', 'Absent (justifié)', 'Absent (non justifié)', 'AE', 'AN', 'Blessure', 'Maladie', 'École', 'Minutes'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
    }

    #[Test]
    public function stored_marks_are_printed_only_when_asked(): void
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        $this->player($u15);
        $held = $this->training($u15, '2026-10-05');
        $this->mark($held, $player, AttendanceStatus::Late, ['minutes' => 25, 'note' => 'Bus en retard']);
        $this->mark($held, Player::where('id', '!=', $player->id)->first());

        $blank = $this->sheetHtml($held);
        $filled = $this->sheetHtml($held, 'fr', ['filled' => 1]);

        $this->assertStringNotContainsString('Bus en retard', $blank);
        $this->assertSame(0, substr_count($blank, 'class="tick"'));
        $this->assertStringContainsString('Bus en retard', $filled);
        $this->assertSame(2, substr_count($filled, 'class="tick"'));
        $this->assertStringContainsString('Imprimée avec les présences déjà enregistrées.', $filled);
    }

    #[Test]
    public function the_arabic_sheet_is_right_to_left(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $training = $this->training($u15, '2026-10-05');
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('ar'))->get(route('attendance.sheets.session', $training))->assertOk();

        $this->assertTrue($seen['rtl']);
        $this->assertFalse($seen['landscape']);
        $this->assertStringContainsString('ورقة حضور الحصة', $seen['html']);
        $this->assertStringContainsString('متأخر', $seen['html']);
    }

    #[Test]
    public function a_cancelled_session_prints_with_a_banner(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $training = $this->training($u15, '2026-10-05', ['state' => SessionState::Cancelled, 'cancel_reason' => 'Pluie']);

        $this->assertStringContainsString('Annulée : Pluie', $this->sheetHtml($training));
    }

    #[Test]
    public function printing_needs_attendance_view_only(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $training = $this->training($u15, '2026-10-05');
        $this->spyPdf();

        $this->actingAs($this->userWith(['attendance' => ['view']]))->get(route('attendance.sheets.session', $training))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view']]))->get(route('attendance.sheets.session', $training))->assertForbidden();
    }
}
```

In `tests/Feature/AttendancePermissionTest.php`, after the `attendance.sheets.month` assertion added in Task 3, add:
```php
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.sheets.session'));
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="AttendanceSessionSheetPdfTest|AttendancePermissionTest"`
Expected: FAIL — `Route [attendance.sheets.session] not defined.`, and the permission assertion gets `['attendance', 'edit']`.

- [ ] **Step 3: Route, override, roster column**

In `routes/web.php`, right after the `attendance.sheets.month` route:
```php
        Route::get('/attendance/sheets/sessions/{session}', [AttendanceSheetController::class, 'session'])->name('attendance.sheets.session');
```
In `config/permissions.php`, right after `'attendance.sheets.month' => ['attendance', 'view'],`:
```php
        'attendance.sheets.session' => ['attendance', 'view'],
```
In `app/Services/Attendance/Roster.php`, replace
```php
    private const COLUMNS = ['id', 'firstname', 'lastname', 'category_id'];
```
with
```php
    // file_number: the session sheet prints the paper-folder number.
    private const COLUMNS = ['id', 'firstname', 'lastname', 'file_number', 'category_id'];
```

- [ ] **Step 4: Add the controller action**

In `app/Http/Controllers/AttendanceSheetController.php`, add the imports
```php
use App\Enums\AbsenceReason;
use App\Enums\SessionState;
use App\Services\Attendance\Roster;
```
and, after `month()`, the method:
```php
    /**
     * One session, A4 portrait: its list (frozen once marked, else the
     * expected roster of every category taking part), a tick column per
     * status, minutes, reason, note, and the session log to fill in.
     */
    public function session(Request $request, TrainingSession $session, Roster $roster): Response
    {
        $request->validate(['filled' => ['nullable', 'boolean']]);
        $filled = $request->boolean('filled');
        $locale = app()->getLocale();
        $session->loadMissing('categories');
        $players = $roster->forSession($session);
        // Primary category first, then the others of a joint pre-season session.
        $categories = $session->orderedCategories()->mapWithKeys(fn (Category $c) => [$c->id => $c->localized_name]);
        $joint = $categories->count() > 1;
        $marks = $filled ? $session->attendances()->get()->keyBy('player_id') : collect();

        $html = view('pdf.attendance-session-sheet', [
            'club' => ClubHeader::data(),
            'session' => [
                'date' => $session->date,
                'dateLabel' => CarbonImmutable::createFromFormat('!Y-m-d', $session->date)->locale($locale)->translatedFormat('l j F Y'),
                'time' => "{$session->start_time}–{$session->end_time}",
                'kind' => $session->kind->value,
                'categories' => $categories->values()->all(),
                'coach' => $session->coach,
                'title' => $session->title,
                'notes' => $session->notes,
                'cancelled' => $session->state === SessionState::Cancelled,
                'cancel_reason' => $session->cancel_reason,
            ],
            'rows' => $players->map(function (Player $p) use ($marks, $categories, $joint) {
                $mark = $marks->get($p->id);

                return [
                    'file_number' => self::fileNumber($p),
                    'name' => self::name($p),
                    // Where a player comes from only matters when several categories share the session.
                    'category' => $joint ? $categories->get($p->category_id) : null,
                    'status' => $mark?->status->value,
                    'minutes' => $mark?->minutes,
                    'reason' => $mark?->reason?->value,
                    'note' => $mark?->note,
                ];
            })->values()->all(),
            'blankRows' => self::BLANK_ROWS,
            'filled' => $filled,
            'labels' => AttendanceSettings::labels(),
            'codes' => AttendanceSettings::codes(),
            'reasons' => AbsenceReason::values(),
        ])->render();

        return $this->pdf->stream($html, "attendance-session-{$session->id}-{$session->date}.pdf", $locale === 'ar');
    }
```
(Check `AbsenceReason::values()` exists — it is used by `AttendanceController::show()`; it does.)

- [ ] **Step 5: Write the view**

`resources/views/pdf/attendance-session-sheet.blade.php`:
```blade
@php
    use App\Services\Attendance\AttendanceCode;

    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $statuses = array_keys($labels);
    $code = fn (string $status) => AttendanceCode::normalise($codes[$status]['code']);
    $footer = implode(' · ', $session['categories']).' · '.$session['date'].' — '.strtr($L('att.sheet.page'), ['{page}' => '{PAGENO}', '{pages}' => '{nbpg}']);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 10px; color: #1e293b; }
        .title { font-size: 16px; font-weight: bold; color: #02a85c; }
        .sub { font-size: 11px; color: #334155; margin-bottom: 4px; }
        .muted { color: #64748b; }
        .banner { padding: 4px 6px; background: #f1f5f9; color: #334155; margin-bottom: 6px; }
        table.sheet { width: 100%; border-collapse: collapse; }
        table.sheet th { background: #f1f5f9; color: #334155; font-size: 7px; padding: 2px; border: 1px solid #94a3b8; text-align: center; }
        table.sheet td { height: 7mm; padding: 1px 3px; border: 1px solid #94a3b8; }
        td.num { text-align: center; font-family: monospace; }
        td.tick { text-align: center; font-weight: bold; font-size: 11px; }
        .code { font-size: 10px; font-weight: bold; }
        .guest { font-size: 7px; color: #64748b; }
        .help { font-size: 8px; color: #64748b; margin-top: 4px; }
        table.log { width: 100%; margin-top: 8px; border-collapse: collapse; page-break-inside: avoid; }
        table.log td { border: 1px solid #94a3b8; padding: 4px; vertical-align: top; }
        table.log td.label { width: 28%; color: #64748b; font-size: 9px; }
    </style>
</head>
<body>
    <htmlpagefooter name="sheet"><div style="font-size:7px; color:#94a3b8; text-align:center;">{{ $footer }}</div></htmlpagefooter>
    <sethtmlpagefooter name="sheet" value="on" />

    @include('pdf.partials.header')

    <div class="title">{{ $L('att.sheet.session_title') }}</div>
    <div class="sub"><b>{{ implode(' · ', $session['categories']) }}</b> &middot; {{ $L('att.kind.'.$session['kind']) }} &middot; {{ $session['dateLabel'] }} &middot; <bdi dir="ltr">{{ $session['time'] }}</bdi></div>
    @if ($session['title'])
        <div class="sub">{{ $L('att.title_goal') }}: <b>{{ $session['title'] }}</b></div>
    @endif
    @if ($session['cancelled'])
        <div class="banner">{{ strtr($L('att.cancelled_because'), ['{reason}' => (string) $session['cancel_reason']]) }}</div>
    @endif
    @if ($filled)
        <div class="muted">{{ $L('att.sheet.filled_note') }}</div>
    @endif

    <table class="sheet">
        <thead>
            <tr>
                <th style="width:6mm;">#</th>
                <th style="width:13mm;">{{ $L('att.sheet.file_no') }}</th>
                <th>{{ $L('att.player') }}</th>
                @foreach ($statuses as $status)
                    <th style="width:10mm;"><span class="code" style="color: {{ $codes[$status]['color'] }};"><bdi dir="ltr">{{ $code($status) }}</bdi></span><br>{{ $labels[$status] }}</th>
                @endforeach
                <th style="width:11mm;">{{ $L('att.minutes') }}</th>
                <th style="width:18mm;">{{ $L('att.reason') }}</th>
                <th style="width:28mm;">{{ $L('att.note') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td class="num">{{ $loop->iteration }}</td>
                    <td class="num">{{ $row['file_number'] }}</td>
                    <td>{{ $row['name'] }}@if ($row['category']) <span class="guest">({{ $row['category'] }})</span>@endif</td>
                    @foreach ($statuses as $status)
                        @if ($row['status'] === $status)
                            <td class="tick">X</td>
                        @else
                            <td></td>
                        @endif
                    @endforeach
                    <td class="num">{{ $row['minutes'] }}</td>
                    <td>{{ $row['reason'] ? $L('att.reason.'.$row['reason']) : '' }}</td>
                    <td>{{ $row['note'] }}</td>
                </tr>
            @endforeach
            @for ($i = 0; $i < $blankRows; $i++)
                <tr class="blank-row">
                    <td class="num">{{ count($rows) + $i + 1 }}</td>
                    <td></td>
                    <td></td>
                    @foreach ($statuses as $status)
                        <td></td>
                    @endforeach
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            @endfor
        </tbody>
    </table>

    <div class="help">{{ $L('att.sheet.tick_help') }} {{ $L('att.sheet.blank_rows') }}</div>
    <div class="help"><b>{{ $L('att.reason') }}:</b>
        @foreach ($reasons as $reason)
            {{ $L('att.reason.'.$reason) }}@if (! $loop->last) &middot; @endif
        @endforeach
    </div>

    <table class="log">
        <tr><td class="label">{{ $L('att.coach') }}</td><td>{{ $session['coach'] }}</td></tr>
        <tr><td class="label">{{ $L('att.title_goal') }}</td><td>{{ $session['title'] }}</td></tr>
        <tr><td class="label">{{ $L('att.notes') }}</td><td style="height:22mm;">{{ $session['notes'] }}</td></tr>
        <tr><td class="label">{{ $L('att.sheet.signature') }}</td><td style="height:14mm;"></td></tr>
        <tr><td class="label">{{ $L('att.sheet.entered_on') }}</td><td>____________ &nbsp; {{ $L('att.sheet.entered_by') }} ____________</td></tr>
    </table>
</body>
</html>
```

- [ ] **Step 6: Run the tests to see them pass**

Run: `php artisan test --filter="AttendanceSessionSheetPdfTest|AttendancePermissionTest|AttendanceMonthSheetPdfTest|AttendanceSessionTest|MarkRecorderTest|AttendanceGridTest"`
Expected: PASS (the extra `file_number` column in `Roster` changes nothing for the session page or the recorder).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/AttendanceSheetController.php app/Services/Attendance/Roster.php resources/views/pdf/attendance-session-sheet.blade.php routes/web.php config/permissions.php tests/Feature/AttendanceSessionSheetPdfTest.php tests/Feature/AttendancePermissionTest.php
git commit -m "feat(attendance): printable session sheet (A4 portrait)" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Print buttons on the calendar, the grid and the session page

**Files:**
- Modify: `resources/js/Pages/Attendance/Index.vue`, `resources/js/Pages/Attendance/Grid.vue`, `resources/js/Pages/Attendance/Session.vue`
- Test: `tests/Feature/AttendanceSheetLinksTest.php` (new)

**Interfaces:**
- Consumes: routes `attendance.sheets.month` (`{ category_id, month, filled? }`) and `attendance.sheets.session` (`{ session, filled? }`) through Ziggy's `route()`; `t('att.sheet.print' | 'att.sheet.print_filled' | 'att.sheet.print_session' | 'att.sheet.print_hint')`; `Icon name="print"`.
- Produces: computed hrefs `sheetHref` (all three pages) and `filledSheetHref` (Grid, Session), rendered as `<a :href="sheetHref" target="_blank">`; no new props.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceSheetLinksTest.php`:
```php
<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The print buttons are plain links opening the PDF in a new tab (they work
 * in the desktop shell and never call a global from the template).
 */
class AttendanceSheetLinksTest extends TestCase
{
    public static function pages(): array
    {
        return [
            'calendar' => ['Attendance/Index.vue', "route('attendance.sheets.month'", false],
            'grid' => ['Attendance/Grid.vue', "route('attendance.sheets.month'", true],
            'session' => ['Attendance/Session.vue', "route('attendance.sheets.session'", true],
        ];
    }

    #[Test]
    #[DataProvider('pages')]
    public function the_page_links_to_its_sheet_in_a_new_tab(string $file, string $route, bool $withMarks): void
    {
        $source = (string) file_get_contents(resource_path("js/Pages/{$file}"));

        $this->assertStringContainsString($route, $source);
        $this->assertStringContainsString(':href="sheetHref" target="_blank"', $source);
        $this->assertSame($withMarks, str_contains($source, ':href="filledSheetHref" target="_blank"'));
        $this->assertStringNotContainsString('@click="window', $source);
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php artisan test --filter=AttendanceSheetLinksTest`
Expected: FAIL — `Failed asserting that '...' contains "route('attendance.sheets.month'"` for each page.

- [ ] **Step 3: Calendar (month view)**

In `resources/js/Pages/Attendance/Index.vue`:
- change `import { ref } from 'vue';` to `import { computed, ref } from 'vue';`
- after `const today = dateKey(new Date());` add:
```js
// The blank month sheet for the category and month on screen (month view only).
const sheetHref = computed(() => (props.categoryId ? route('attendance.sheets.month', { category_id: props.categoryId, month: props.month }) : null));
```
- in the header, right after the `att.grid` `<Link>`, add:
```vue
                    <a v-if="categoryId && view === 'month'" :href="sheetHref" target="_blank" :title="t('att.sheet.print_hint')" class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800"><Icon name="print" />{{ t('att.sheet.print') }}</a>
```

- [ ] **Step 4: Month grid**

In `resources/js/Pages/Attendance/Grid.vue`:
- after `const monthLabel = computed(...)` add:
```js
// Paper sheets for this category and month: blank, or with the codes already recorded.
const sheetHref = computed(() => route('attendance.sheets.month', { category_id: props.category.id, month: props.month }));
const filledSheetHref = computed(() => route('attendance.sheets.month', { category_id: props.category.id, month: props.month, filled: 1 }));
```
- replace
```vue
                <button v-if="editable" :disabled="!dirty.size" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50" @click="save">{{ t('att.save') }}</button>
```
with
```vue
                <div class="flex flex-wrap items-center gap-2 print:hidden">
                    <a :href="sheetHref" target="_blank" :title="t('att.sheet.print_hint')" class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800"><Icon name="print" />{{ t('att.sheet.print') }}</a>
                    <a :href="filledSheetHref" target="_blank" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800">{{ t('att.sheet.print_filled') }}</a>
                    <button v-if="editable" :disabled="!dirty.size" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50" @click="save">{{ t('att.save') }}</button>
                </div>
```

- [ ] **Step 5: Session page**

In `resources/js/Pages/Attendance/Session.vue`:
- after `const editable = computed(...)` add:
```js
// Paper sheet for this session: blank, or with the marks once they are saved.
const sheetHref = computed(() => route('attendance.sheets.session', props.session.id));
const filledSheetHref = computed(() => route('attendance.sheets.session', { session: props.session.id, filled: 1 }));
```
- replace the header's action block
```vue
                <div v-if="editable" class="flex gap-2">
                    <button v-if="canEditCategories" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-amber-700 ring-1 ring-amber-200 hover:bg-amber-50 dark:text-amber-300 dark:ring-amber-900" @click="openCategories">{{ t('att.edit_categories') }}</button>
                    <button class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700" @click="showMove = true">{{ t('att.move') }}</button>
                    <button class="rounded-lg px-3 py-1.5 text-sm font-semibold text-rose-600 ring-1 ring-rose-200 hover:bg-rose-50 dark:ring-rose-900" @click="showCancel = true">{{ t('att.cancel') }}</button>
                </div>
```
with
```vue
                <div class="flex flex-wrap gap-2 print:hidden">
                    <a v-if="!cancelled" :href="sheetHref" target="_blank" :title="t('att.sheet.print_hint')" class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800"><Icon name="print" />{{ t('att.sheet.print_session') }}</a>
                    <a v-if="!cancelled && saved" :href="filledSheetHref" target="_blank" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800">{{ t('att.sheet.print_filled') }}</a>
                    <template v-if="editable">
                        <button v-if="canEditCategories" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-amber-700 ring-1 ring-amber-200 hover:bg-amber-50 dark:text-amber-300 dark:ring-amber-900" @click="openCategories">{{ t('att.edit_categories') }}</button>
                        <button class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700" @click="showMove = true">{{ t('att.move') }}</button>
                        <button class="rounded-lg px-3 py-1.5 text-sm font-semibold text-rose-600 ring-1 ring-rose-200 hover:bg-rose-50 dark:ring-rose-900" @click="showCancel = true">{{ t('att.cancel') }}</button>
                    </template>
                </div>
```

- [ ] **Step 6: Build and run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendanceSheetLinksTest|AttendanceCalendarTest|AttendanceGridTest|AttendanceSessionTest|AttendanceCodesOnPagesTest" && npm run i18n:check`
Expected: the build succeeds; PASS; the i18n check prints no `does not resolve` line (the three pages now use `att.sheet.print*` keys added in Task 2).

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/Attendance/Index.vue resources/js/Pages/Attendance/Grid.vue resources/js/Pages/Attendance/Session.vue tests/Feature/AttendanceSheetLinksTest.php
git commit -m "feat(attendance): print buttons for the month and session sheets" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Full verification

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
Expected: the build succeeds with no missing-import warnings; the check prints no `does not resolve` line. Then:
```bash
git diff main -U0 -- resources/js/i18n | grep '^-[^-]' | wc -l
```
Expected: `3` (in each file only the previous last line changed, by gaining a comma). `git status --short` shows no leftover `att-i18n.mjs`.

- [ ] **Step 4: No migration, nothing stray**

Run: `git diff main --stat -- database/migrations && git status --short`
Expected: the first command prints nothing (this plan adds no migration); the second shows only untracked files that were there before (e.g. `.superpowers/`).

- [ ] **Step 5: Manual check, in Arabic and in French, on paper**

Serve the worktree (`php artisan serve --port=2027`). Prepare: one category with a weekly schedule and ~30 players (one archived, one who left last month, one who leaves mid-month), a joint pre-season session with a second category, one cancelled session, one held session with a late of 15 minutes and an excused absence with a note, and a code or name changed in Settings → Codes. Go through the list twice, interface in **ar** then in **fr**:
1. Calendar, month view: "Print sheet" opens a PDF in a new tab for the category and month on screen; the button is absent in the week, agenda and timeline views. Switching category or month changes the sheet.
2. Month sheet: club header, then category, month name in the interface language, season; column headers show weekday, dd/mm, time, the kind marker on the pre-season and extra sessions, the title in small print; the cancelled session is absent; the joint session is present with the other category's players tagged; the archived player and the one who left last month are absent; grey cells where a player is not on a session's list; two blank date columns at the end.
3. Legend: every status with its configured code and colour, `R15`-style samples (or the changed code), the rule line, kind markers, grey-cell and blank-column notes; coach name / signature / entered on-by boxes.
4. 30 players: the table continues on page 2 with the header row repeated; the footer reads "category · month — Page 1 of 2".
5. Temporarily add extra sessions so the month has 20: the sheet splits into two parts ("Sessions 1–16 of 20", "17–20 of 20"), each with the full roster, legend and signatures. Delete them afterwards.
6. Month grid: "Print sheet" gives the same sheet; "Print with marks" prints the recorded codes (e.g. R15, AE) in their cells and the "printed with the marks" note.
7. Session page: "Print session sheet" (A4 portrait): categories (all of them for the joint session), kind, full date, time, title; one row per roster player (tagged by category on the joint session), a tick column per status headed by the configured code and name, minutes, reason, note; three blank rows; the reasons line; the log (coach and title pre-printed when set, notes, signature, entered on/by). "Print with marks" appears once the session is saved and ticks the recorded statuses with minutes, reasons and notes. No print button on a cancelled session.
8. Arabic: both sheets read right to left (numbers, names on the right, sessions running right to left), dates and times stay left-to-right and legible, Arabic codes (if configured) print correctly.
9. Print both sheets on real A4 at 100 % (no "fit to page"): nothing clipped at the margins; month cells are wide and tall enough to hand-write "R15"; the session sheet's tick boxes and note column are usable with a pen.
10. Permissions: a user with only attendance/view sees and uses every print button; a user without attendance gets 403 on both URLs.
11. Desktop app (offline, no network): both sheets print, with the club logo.

- [ ] **Step 6: Hand-off**

Report to the owner: what shipped (month sheet, session sheet, print buttons), the resolved ambiguities listed under Global Constraints (especially: blank by default with an optional "with marks" print; per-session roster rule; file number = folder number; 16 columns per part; blank columns/rows for the unplanned; date-range printing skipped), and the deploy notes: no migration, no new setting; `npm run build` ships the new buttons.
