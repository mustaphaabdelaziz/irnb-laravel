# Attendance period sheet + totals on printed sheets — design

Date: 2026-11-26. Status: owner-approved design.

## Need

The owner wants to print recorded attendance for a month, a session, or any
period, with the attendance visible on the paper.

What already exists:

- **Filled month sheet**: Grid page, «print filled» (`attendance.sheets.month`, `filled=1`).
  Players × sessions of one month, mark code in each cell. No totals.
- **Filled session sheet**: Session page, «print filled» (`attendance.sheets.session`, `filled=1`).
- **Stats PDF**: Stats page, Export → PDF. Counts per player for any range, no per-session detail.

Gap: no players × sessions sheet for a custom period, and no per-player totals on any sheet.

## Owner decisions (2026-11-26)

1. Build a period sheet, and add totals to the month sheet.
2. Row totals: **Present · Absent · Rate %** (three columns only).
3. Long period: **one page per month** with that month's totals, then a final summary page with the period totals.
4. Present = present + late + left early. Absent = absent excused + absent unexcused.
   «Came, did not train» (`not_training`) counts as neither. It shows in the cells only.
5. Rate = present ÷ expected sessions. It is not the points-based score % of the Stats page.
6. Launch from **both** the Stats page and the Grid page.
7. «All categories» on the Stats page → one section per category in one PDF.
8. Filled session sheet gets a one-line summary.

## Route

`GET /attendance/sheets/period` → `attendance.sheets.period`, added to
`config/permissions.php` as `['attendance', 'view']`, like the other sheets.

Query:

| param | rule |
|---|---|
| `from`, `to` | required `Y-m-d`, `from ≤ to`, span ≤ 13 months |
| `branch_id` | nullable, resolved by `BranchChoice` as on the other sheets |
| `category_id` | nullable; null = every category of the branch that has sessions in the period |
| `group_id` | nullable, same meaning as on the month sheet (0 = sessions with no group); only used with `category_id` |

## Components

### `MonthSheet::build()` — optional date bounds

`build()` takes two new optional trailing arguments: `?string $from` and `?string $to`.
When given, the month's sessions are cut to `[from, to]`. When they are omitted,
behaviour does not change, so the grid and the existing month sheet are untouched.

### `App\Services\Attendance\SheetTotals` (new)

A pure helper that turns one player's raw statuses into
`['present' => int, 'absent' => int, 'expected' => int, 'rate' => ?float]`.

- Each status counts as its behaviour, from `AttendanceStatusCatalog::behaviour()`. Custom codes follow their behaviour.
- A `not_counted` behaviour is left out of `expected`.
- Every other marked status adds 1 to `expected`.
- An unmarked cell (a session not saved yet) counts nowhere.
- `rate` = `round(present / expected × 100, 1)`, or null when `expected` = 0. Null prints as «—».

`MonthSheet::build()` also returns `statuses: array<playerId, array<sessionId, string>>`,
the raw status of each marked cell, so the totals come from exactly the cells printed.

### `App\Services\Attendance\PeriodSheet` (new)

Builds the period's sections. It reuses `MonthSheet::groupSwitch()` and `build()` month by month.

- **Sections**: the chosen category, or each category of the branch with
  sessions in the period, ordered by id as on the Stats page.
  - A category with training groups gives one section per group switch entry
    that has sessions in the period, or only the chosen `group_id`.
  - A category without groups gives one section.
- **Months**: each calendar month that touches `[from, to]`, cut to the bounds.
  Days after today are cut too (no empty future columns). Months after the current month are skipped: they have no marks, and skipping
  them avoids generating future sessions.
  A month with no sessions in a section prints nothing.
- **Summary**: per section, every player who appears in any month, with the
  totals summed over the period (present, absent, expected, rate).
  When the section covers only one month, there is no summary page.

### PDF `resources/views/pdf/attendance-period-sheet.blade.php` (new)

A4 landscape, right-to-left in Arabic. Same header, column heads, legend,
footer and 16-column split (`AttendanceSheetController::MAX_COLUMNS`) as the
month sheet.

- **Section header**: branch · category · group · period `from → to` · season.
- **Month page**: players × sessions with codes, then Present · Absent · Rate %
  columns. When the month needs more than 16 sessions, the totals columns go on
  the last part only. No blank extra columns: this is a record, not a sheet to fill.
- **Summary page**: # · player · Present · Absent · Expected · Rate %.
- An empty period prints the header with «no sessions».

The shared column-head and legend markup is factored into a Blade partial,
used by both the month sheet and the period sheet.

### Existing sheets

- **Month sheet, filled only**: adds the three totals columns from `SheetTotals`, on the last part. The blank sheet is unchanged.
- **Session sheet, filled only**: one line under the table: «Present n · Absent n · Rate %».

### UI

- **Stats page**: a «Print attendance sheet» `IconButton` next to Export. It
  opens `attendance.sheets.period` in a new tab with the page's period, branch,
  category and group.
- **Grid page**: a «Print period» `IconButton` opens a small dialog with
  from/to date inputs. They default to the first and last day of the grid's
  month. Submitting opens the sheet with the grid's category, branch and group.
- i18n keys in ar, fr and en, insert-only. Run `npm run i18n:check`.

## Error handling

- Validation errors (bad dates, `from > to`, span over 13 months) return the
  usual 422 or redirect. The Grid dialog validates `from ≤ to` before opening.
- An unknown `group_id` on the period sheet prints every group entry, matching «all groups» on the Stats page.
  The month sheet keeps its own fallback (the group switch default).

## Testing

Feature tests:

- route permission (view allowed, no right forbidden)
- validation
- totals: present/late/left early, absences, not_training, a custom code with a built-in behaviour, a not_counted code, unmarked cells, rate null
- period bounds cut a month
- months after the current month are skipped
- all categories gives one section per category
- a category with groups gives one section per group
- a single month has no summary page
- filled month sheet totals appear, blank sheet has none
- session sheet summary line

Unit test for `SheetTotals`.

After the tests, check the PDF visually in Arabic and French.
