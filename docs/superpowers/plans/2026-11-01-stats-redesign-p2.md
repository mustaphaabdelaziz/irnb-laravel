# Statistics redesign — Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move every remaining chart page onto the Phase 1 stats system, and add the comparisons the owner asked for. Pages: the Dashboard tabs, Attendance/Stats, Board/Tasks, Activity, and the player Attendance and Academic sections. Comparisons: the Dashboard Finance tab, a treasury figure that follows the selected range, and Attendance stats. Also fix `board.tasks` so it needs view only.

**Architecture:** The backend adds new keys only (no existing key changes shape or meaning). Every delta goes through `DeltaCalculator`. The frontend reuses the Phase 1 pieces, with `resources/js/Pages/Finance/Index.vue` as the reference migration:
- `ChartCard` (chart/table toggle, csv, error)
- `StatTable`
- `StatTile`
- `StatsToolbar`
- `useChartTheme`
- `rankedBarDataset`

**Tech Stack:** Laravel 13, PHPUnit `#[Test]`, Inertia v2, Vue 3 `<script setup>`, Tailwind v3 (shadcn tokens, RTL logical utilities), chart.js 4.5 + vue-chartjs 5, vue-i18n flat dotted keys, node:test.

Spec: `docs/superpowers/specs/2026-11-01-stats-redesign-design.md` (§2 decisions 4, 7; §5; §6 phase 2).

## Global Constraints

- No existing calculation changes. New numbers are new keys; existing keys keep shape and meaning.
- Money stays behind Finance → view (`DashboardController::money()`); `null` = hidden, `undefined` = still loading.
- Dashboard query budgets (`tests/Feature/Dashboard/DashboardPageTest.php`) stay green. Where a new delta adds queries, measure and raise the budget deliberately in the same commit, with a comment saying what the extra query is.
- Chart ⇄ table switch is client-only: no request, filters untouched. Table DOM renders only while the table is shown.
- No CDN / remote resource; no new npm or composer dependency.
- i18n keys flat dotted in `resources/js/i18n/{ar,fr,en}.json`. Add keys ONLY with the insert-only script, never with `scripts/i18n-add.mjs` (it re-sorts the unsorted catalogs):
  1. Write `{"key":{"ar":"…","fr":"…","en":"…"}}` to a JSON file in the scratchpad `C:\Users\MUSTAPHA\AppData\Local\Temp\claude\d--irnb-laravel\43c0584d-c7d0-4b0b-940c-0a0eedcaa312\scratchpad\`.
  2. Run `node "C:\Users\MUSTAPHA\AppData\Local\Temp\claude\d--irnb-laravel\43c0584d-c7d0-4b0b-940c-0a0eedcaa312\scratchpad\i18n-insert.mjs" <that json>` from `D:\irnb-stats`.
  3. `git diff --stat resources/js/i18n` must show insertions only.
  4. `npm run i18n:check` must stay green.
- Existing ChartCard / StatTile / StatTable props keep working; new props are optional. **StatTable and ChartCard are shared with the Reports Center** (`resources/js/Pages/Reports/*`): do not change their props, slots, or emitted events.
- Numbers and percents format through `useFormatNumber` (`formatNumber`, `formatPercent`), money through `useFormatMoney`. Never `toFixed` + `'%'` in a template.
- Every chart `computed` that builds data or options reads `themeVersion.value` from `useChartTheme()` so charts recolour on a live dark toggle (pattern: `Finance/Index.vue`).
- Per-panel CSV: ChartCard `:csv="{ filename, columns, rows, totals? }"`, built from the exact same `columns`/`rows` (and totals object) the StatTable gets.
- **Totals are always an OBJECT keyed by column key**, never `true`: `toCsv` reads `totals[column.key]`, so `totals: true` leaves the CSV total row blank. Use `columnTotals(columns, rows)` from `@/lib/statTable` (sums the columns marked `total: 'sum'`) and pass the SAME computed object to `<StatTable :totals="…">` and to `csv.totals`. Wherever a task below says "totals" or "totals: true", it means this object over that table's `total: 'sum'` columns.
- Never `total: 'sum'` a count of distinct entities that can repeat across rows (e.g. players per aging bucket).
- Never write files outside `D:\irnb-stats` except in the scratchpad above. Commit with explicit paths only (`git add <file> …`, never `-A`/`.`). Commit trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Build before PHP feature tests (the Vite manifest is needed): `npm run build`. Run PHP tests serially (`php artisan test --filter=…`; no `--parallel`).
- Known unrelated failure: `ManualSuspensionsTest` "a manual ban is added in matches or dates and listed" (date time-bomb, fails from 2026-11-01). Do not fix, do not count it.

## Reference: the Phase 1 pattern (copy this shape)

```vue
<script setup>
import { computed } from 'vue';
import ChartCard from '@/Components/Dashboard/ChartCard.vue';
import StatTable from '@/Components/Stats/StatTable.vue';
import { useChartTheme } from '@/Composables/useChartTheme';
import { baseOptions, rankedBarDataset } from '@/lib/chartTheme';

const { themeVersion } = useChartTheme();

const expenseColumns = computed(() => [
    { key: 'label', label: t('dashboard.category'), format: 'text', sortable: true },
    { key: 'amount', label: t('dashboard.amount'), format: 'money', sortable: true, total: 'sum' },
    { key: 'share', label: t('dashboard.fin_share'), format: 'percent', sortable: true },
]);
// Rows carry the display label as data, so the table, the sort and the CSV agree.
const expenseRows = computed(() => expenses.value.map((r) => ({ ...r, label: rowLabel(r) })));
const expenseTotals = computed(() => columnTotals(expenseColumns.value, expenseRows.value)); // import { columnTotals } from '@/lib/statTable'
const expenseChart = computed(() => {
    themeVersion.value; // recolour on theme toggle
    return { labels: expenseRows.value.map((r) => r.label), datasets: [rankedBarDataset(t('dashboard.amount'), expenseRows.value.map((r) => r.amount))] };
});
</script>

<ChartCard :title="…" has-table :csv="{ filename: 'dashboard-expense-by-category', columns: expenseColumns, rows: expenseRows, totals: expenseTotals }">
    <Bar :data="expenseChart" :options="chartOptions" />
    <template #table>
        <StatTable :columns="expenseColumns" :rows="expenseRows" :row-key="(r) => r.labelKey || r.name" :totals="expenseTotals" dense />
    </template>
</ChartCard>
```

StatTable column `format` ∈ `number | money | percent | text | date`. `total: 'sum'` only for additive columns (never percent/averages/running totals). The totals label sits in the first column, so the first column is never summable. A `csv: (row) => string` accessor on a column exports a localised label instead of the raw value.

---

### Task 1: `board.tasks` needs view only

**Files:**
- Modify: `config/permissions.php` (overrides block near line 118, beside `'board.meetings.minutes' => ['board', 'view'],`)
- Modify: `resources/js/Pages/Board/Tasks.vue` (hide mutations without board → edit)
- Create: `tests/Feature/BoardTasksPermissionTest.php`

**Interfaces:** Produces: `board.tasks` reachable with `['board' => ['view']]`. `board.tasks.store/update/destroy` still need add/edit/delete via derivation (unchanged).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\BoardTask;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardTasksPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    #[Test]
    public function a_board_viewer_can_open_the_tasks_page(): void
    {
        $this->actingAs($this->userWith(['board' => ['view']]))
            ->get(route('board.tasks'))
            ->assertOk();
    }

    #[Test]
    public function a_board_viewer_still_cannot_change_a_task(): void
    {
        $task = BoardTask::create(['title' => 'Paint the fence', 'status' => 'not_started', 'priority' => 'medium']);
        $viewer = $this->userWith(['board' => ['view']]);

        $this->actingAs($viewer)->post(route('board.tasks.store'), ['title' => 'New', 'status' => 'not_started', 'priority' => 'low'])->assertForbidden();
        $this->actingAs($viewer)->put(route('board.tasks.update', $task), ['title' => 'Changed', 'status' => 'completed', 'priority' => 'low'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('board.tasks.destroy', $task))->assertForbidden();
        $this->assertSame('Paint the fence', $task->fresh()->title);
    }

    #[Test]
    public function without_board_view_the_tasks_page_is_refused(): void
    {
        $this->actingAs($this->userWith(['players' => ['view']]))
            ->get(route('board.tasks'))
            ->assertForbidden();
    }
}
```

If `BoardTask::create` needs other required columns, read `database/migrations/*board_tasks*` and `app/Models/BoardTask.php` and add the minimum. If the forbidden response is a redirect in this app, use whatever status `tests/Feature/AttendancePermissionTest.php` asserts for a refused route and say so in the report.

- [ ] **Step 2: Run it to see the first test fail**

Run: `php artisan test --filter=BoardTasksPermissionTest`
Expected: `a_board_viewer_can_open_the_tasks_page` FAILS (403), the other two pass.

- [ ] **Step 3: Add the override**

In `config/permissions.php`, directly under `'board.meetings.minutes' => ['board', 'view'],` add:

```php
        // The tasks board and its stats are reading; changing a task keeps its own add/edit/delete guard.
        'board.tasks' => ['board', 'view'],
```

- [ ] **Step 4: Hide mutations in the page for viewers**

In `resources/js/Pages/Board/Tasks.vue`:
- import `{ useCan } from '@/Composables/useCan'`, `const { can } = useCan(); const canEdit = computed(() => can('board', 'edit'));`
- The "add task" `IconButton` (line ~218): `v-if="canEdit"`.
- Kanban cards: `:draggable="canEdit ? 'true' : 'false'"` instead of `draggable="true"`. Make the column `@drop` handler (`onDrop`) and `openEdit` return early when `!canEdit.value`.
- Keep the timeline/stats views readable for viewers.

- [ ] **Step 5: Run tests**

Run: `npm run build` then `php artisan test --filter="BoardTasksPermissionTest|Board"`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add config/permissions.php resources/js/Pages/Board/Tasks.vue tests/Feature/BoardTasksPermissionTest.php
git commit -m "fix(board): the tasks page needs board view; changes keep their edit guard"
```

---

### Task 2: Dashboard Finance tab deltas

**Files:**
- Modify: `app/Services/Dashboard/Support/DeltaCalculator.php` (add `NEUTRAL` direction)
- Modify: `app/Services/Dashboard/FinanceStats.php` (`summary()`; new private `previousWindow()`)
- Modify: `tests/Feature/Dashboard/FinanceStatsTest.php` (new tests)
- Modify: `tests/Unit/` or `tests/Feature/Dashboard/` DeltaCalculator test if one exists (`grep -rl DeltaCalculator tests`). Add the neutral case there; otherwise put it in FinanceStatsTest.
- Modify (only if measured over): `tests/Feature/Dashboard/DashboardPageTest.php` finance budget (14)

**Interfaces:**
- Produces: `DeltaCalculator::NEUTRAL = 'neutral'`. `compute($c, $p, DeltaCalculator::NEUTRAL)` returns the same percent/raw with `tone` always `'neutral'`.
- Produces: each `finance.summary[]` item gains `'delta' => ?array{percent,raw,tone}`:
  - `avg_transaction`: NEUTRAL
  - `largest_expense`: DOWN_GOOD
  - `burn_rate`: DOWN_GOOD
  - `runway`: always `null`

  The delta is `null` when `! $filters->hasComparison()`, when the current value is null, or when the previous value is null/0.

- [ ] **Step 1: Write the failing tests** (append to `FinanceStatsTest`; the class travels to 2026-05-14)

```php
    #[Test]
    public function the_summary_compares_with_the_previous_month(): void
    {
        // May (current): 2 entries, avg 750, largest expense 500, burn 500.
        $this->transaction('income', 1000, '2026-05-02');
        $this->transaction('expense', 500, '2026-05-03');
        // April (previous): 2 entries, avg 500, largest expense 400, burn 400.
        $this->transaction('income', 600, '2026-04-02');
        $this->transaction('expense', 400, '2026-04-03');

        $summary = $this->summary();

        $this->assertSame(50.0, $summary['avg_transaction']['delta']['percent']);
        $this->assertSame('neutral', $summary['avg_transaction']['delta']['tone']);
        $this->assertSame(25.0, $summary['largest_expense']['delta']['percent']);
        $this->assertSame('negative', $summary['largest_expense']['delta']['tone']);
        $this->assertSame(25.0, $summary['burn_rate']['delta']['percent']);
        $this->assertSame('negative', $summary['burn_rate']['delta']['tone']);
        $this->assertNull($summary['runway']['delta']);
    }

    #[Test]
    public function burn_rate_compares_per_month_across_a_quarter(): void
    {
        // Q2 (Apr–Jun) spends 3 000 → 1 000/month; Q1 (Jan–Mar) spends 6 000 → 2 000/month.
        $this->transaction('expense', 3000, '2026-04-10');
        $this->transaction('expense', 6000, '2026-02-10');

        $delta = $this->summary(['range' => 'quarter'])['burn_rate']['delta'];

        $this->assertSame(-50.0, $delta['percent']);
        $this->assertSame('positive', $delta['tone']);
    }

    #[Test]
    public function no_previous_activity_or_compare_off_means_no_delta(): void
    {
        $this->transaction('expense', 500, '2026-05-03');

        $this->assertNull($this->summary()['largest_expense']['delta']);
        $this->assertNull($this->summary()['burn_rate']['delta']);

        $this->transaction('expense', 400, '2026-04-03');
        $this->assertNull($this->summary(['compare' => '0'])['burn_rate']['delta']);
        $this->assertNull($this->summary(['range' => 'all'])['burn_rate']['delta']);
    }

    #[Test]
    public function the_previous_window_respects_the_branch_and_archive_rules(): void
    {
        $branch = \App\Models\Branch::create(['name' => 'Annexe']);
        $this->transaction('expense', 500, '2026-05-03');
        $this->transaction('expense', 400, '2026-04-03');
        $this->transaction('expense', 9000, '2026-04-04', ['archived' => true]);

        $this->assertSame(25.0, $this->summary()['largest_expense']['delta']['percent']);
        // The other branch has no April spend of its own, so nothing to compare.
        $this->assertNull($this->summary(['branch' => $branch->id])['largest_expense']['delta']);
    }
```

In the branch test, check how `BranchScope::transactions` attributes a transaction to a branch: read `app/Services/Dashboard/Support/BranchScope.php` and the existing `FinanceStatsTest` branch test, if any. Then adjust the fixture so the two April/May transactions belong to the default (non-annexe) side. `Branch::create` may need more columns; copy the existing test's branch fixture.

Add a DeltaCalculator neutral assertion wherever DeltaCalculator is tested:

```php
        $delta = DeltaCalculator::compute(150, 100, DeltaCalculator::NEUTRAL);
        $this->assertSame(50.0, $delta['percent']);
        $this->assertSame('neutral', $delta['tone']);
        $this->assertNull(DeltaCalculator::compute(150, 0, DeltaCalculator::NEUTRAL));
```

- [ ] **Step 2: Run, expect FAIL**

Run: `php artisan test --filter=FinanceStatsTest`
Expected: new tests fail (`Undefined array key "delta"`).

- [ ] **Step 3: Implement**

`DeltaCalculator`: add `public const NEUTRAL = 'neutral';`. In `tone()`, first line `if ($direction === self::NEUTRAL) { return 'neutral'; }`. Update the `@param` doc to list the three directions.

`FinanceStats::summary()`: after computing `$average`, `$largest`, `$burnRate`, add:

```php
        $previous = $filters->hasComparison() ? $this->previousWindow($filters) : null;
```

and give each item a `delta` key:
- `avg_transaction`: `'delta' => $this->delta($average, $previous['average'] ?? null, DeltaCalculator::NEUTRAL)`
- `largest_expense`: `'delta' => $this->delta($largest ? round((float) $largest->amount, 2) : null, $previous['largest'] ?? null, DeltaCalculator::DOWN_GOOD)`
- `burn_rate`: `'delta' => $this->delta($burnRate, $previous['burn_rate'] ?? null, DeltaCalculator::DOWN_GOOD)`
- `runway`: `'delta' => null`

New private methods:

```php
    /**
     * The previous window's average, largest expense and burn rate, in one pass.
     *
     * Same rules as the current window (non-archived, branch-scoped); only the
     * dates move. Burn rate divides by the previous window's own month count.
     *
     * @return array{average: ?float, largest: ?float, burn_rate: ?float}
     */
    private function previousWindow(DashboardFilters $filters): array
    {
        $row = BranchScope::transactions(
            Transaction::query()->where('transactions.archived', false),
            $filters->branchId,
        )
            ->whereBetween('transactions.transaction_date', [$filters->prevFrom, $filters->prevTo])
            ->selectRaw(
                'COUNT(*) as entries, '
                .'SUM(transactions.amount) as amount_total, '
                ."SUM(CASE WHEN transactions.transaction_type = 'expense' THEN transactions.amount ELSE 0 END) as expense_total, "
                ."MAX(CASE WHEN transactions.transaction_type = 'expense' THEN transactions.amount END) as largest"
            )
            ->first();

        $entries = (int) ($row->entries ?? 0);
        $expense = (float) ($row->expense_total ?? 0);
        $months = max(1, $filters->prevFrom->startOfMonth()->diffInMonths($filters->prevTo->startOfMonth()) + 1);

        return [
            'average' => $entries > 0 ? round((float) $row->amount_total / $entries, 2) : null,
            'largest' => $row->largest !== null ? round((float) $row->largest, 2) : null,
            'burn_rate' => $expense > 0 ? round($expense / $months, 2) : null,
        ];
    }

    private function delta(?float $current, ?float $previous, string $direction): ?array
    {
        return $current === null ? null : DeltaCalculator::compute($current, $previous, $direction);
    }
```

Add `use App\Services\Dashboard\Support\DeltaCalculator;`. `diffInMonths` on Carbon 3 returns float: cast with `(int)` like the existing `monthsInWindow` does. If `monthsInWindow` has no cast, mirror it exactly.

- [ ] **Step 4: Run tests; measure the budget**

Run: `npm run build` then `php artisan test --filter="FinanceStatsTest|DeltaCalculator|DashboardPageTest"`.
Expected: all pass. If `the_finance_tab_stays_within_its_query_budget` fails, it must fail by exactly +1 (the one previous-window query). Raise `14` to `15` with the comment `// +1: the previous window for the summary deltas (one query).` Any other overrun is a bug: stop and report.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Dashboard/Support/DeltaCalculator.php app/Services/Dashboard/FinanceStats.php tests/Feature/Dashboard/FinanceStatsTest.php
# plus the DeltaCalculator test file and DashboardPageTest.php if touched
git commit -m "feat(dashboard): finance tab figures compare with the previous window"
```

---

### Task 3: Treasury hero delta follows the selected range

**Files:**
- Modify: `app/Services/Dashboard/HeroStats.php` (`treasury()` only, lines ~377–428)
- Modify: `tests/Feature/Dashboard/HeroStatsTest.php`
- Modify (only if measured over): `DashboardPageTest.php` first-paint budget (31)

**Interfaces:** `hero[treasury].delta` keeps its shape. Only its meaning changes: the balance at the end of the current window vs the balance at the end of the previous window. `value` and `spark` are unchanged.

- [ ] **Step 1: Write the failing tests** (HeroStatsTest travels to 2026-05-14; read its helpers `transaction()` and `tiles()`. If it has no account helper, add one copied from `FinanceStatsTest::account()`.)

```php
    #[Test]
    public function the_treasury_delta_compares_window_ends_not_the_last_two_months(): void
    {
        $this->account(['opening_balance' => 1000]);
        // Quarter Apr–Jun 2026 vs Jan–Mar 2026.
        $this->transaction('income', 1000, '2026-02-10'); // in the previous quarter: end of Q1 = 2 000
        $this->transaction('income', 500, '2026-04-10');  // in this quarter
        $this->transaction('income', 500, '2026-05-10');  // in this quarter: end of Q2 so far = 3 000

        $delta = $this->tiles(['range' => 'quarter'])['treasury']['delta'];

        // 3 000 vs 2 000. (The old last-two-sparkline-months rule would give 3 000 vs 2 500 = 20%.)
        $this->assertSame(50.0, $delta['percent']);
        $this->assertSame('positive', $delta['tone']);
    }

    #[Test]
    public function the_treasury_delta_is_null_when_compare_is_off_or_all_time(): void
    {
        $this->account(['opening_balance' => 1000]);
        $this->transaction('income', 500, '2026-05-10');

        $this->assertNull($this->tiles(['compare' => '0'])['treasury']['delta']);
        $this->assertNull($this->tiles(['range' => 'all'])['treasury']['delta']);
    }
```

The `account()` helper must create an active root account (no parent) on the same branch side as the transactions. Check whether `tiles()` takes a query array (it does in this file: `private function tiles(array $query = [])`).

- [ ] **Step 2: Run, expect FAIL**

Run: `php artisan test --filter=HeroStatsTest`
Expected: the first new test fails (20.0 ≠ 50.0).

- [ ] **Step 3: Implement**

In `treasury()`, replace the delta block:

```php
        $delta = null;
        if ($filters->hasComparison() && count($spark) >= 2) {
            $delta = DeltaCalculator::compute(end($spark), $spark[count($spark) - 2]);
        }
```

with:

```php
        // Balance at the end of each window: opening funds plus every counted
        // transaction up to that day. Following the selected range, not the last
        // two sparkline months, so "this quarter" compares with last quarter.
        $delta = null;
        if ($filters->hasComparison()) {
            $ends = BranchScope::transactions(Transaction::query()->where('archived', false), $filters->branchId)
                ->selectRaw(
                    "SUM(CASE WHEN transaction_date <= ? THEN (CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END) ELSE 0 END) as current_end, "
                    ."SUM(CASE WHEN transaction_date <= ? THEN (CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END) ELSE 0 END) as previous_end",
                    [$filters->to, $filters->prevTo],
                )
                ->first();

            $delta = DeltaCalculator::compute(
                round($opening + (float) ($ends->current_end ?? 0), 2),
                round($opening + (float) ($ends->previous_end ?? 0), 2),
            );
        }
```

Pass dates the same way `twoWindowAggregate` does (Carbon objects bound directly). If the SQLite comparison of a `date` column with a datetime binding misbehaves, pass `->toDateTimeString()`, then `toDateString()` for `<=` on a date column: `$filters->to->toDateString()`. Verify with the test.

- [ ] **Step 4: Run tests; measure the budget**

Run: `npm run build` then `php artisan test --filter="HeroStatsTest|DashboardPageTest|SeasonDashboardTest"`.
Expected: pass. If first paint goes over 31 by exactly 1, raise it to 32 and extend the existing comment with `plus one for the treasury window ends`. Any other overrun: stop and report.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Dashboard/HeroStats.php tests/Feature/Dashboard/HeroStatsTest.php
# plus DashboardPageTest.php if touched
git commit -m "fix(dashboard): treasury change follows the selected range"
```

---

### Task 4: Attendance stats previous-period deltas

**Files:**
- Modify: `app/Services/Activity/ActivityPeriod.php` (add `previous(): self`)
- Modify: `app/Http/Controllers/AttendanceStatsController.php` (`index()` adds a `deltas` prop; `export()` untouched)
- Modify: `tests/Unit/ActivityPeriodTest.php`
- Modify: `tests/Feature/AttendanceStatsPageTest.php`

**Interfaces:**
- Produces: `ActivityPeriod::previous(): self`:
  - month → the previous calendar month (key `month`, label `Y-m`)
  - season → the previous season (start `subYear()`, end `start->subDay()->endOfDay()`, key `season`, label `"{startYear}-{endYear}"`)
  - custom → the same number of days ending the day before start (key `custom`, label `from – to`)
- Produces: Inertia prop `deltas` on `Attendance/Stats`:

  ```
  { held: ?Delta, late_minutes: ?Delta, missed_hours: ?Delta, score_pct: ?Delta }
  ```

  Directions: `held` UP_GOOD, `late_minutes` DOWN_GOOD, `missed_hours` DOWN_GOOD, `score_pct` UP_GOOD. The previous window uses the same category and branch. `score_pct` null on either side → null.

- [ ] **Step 1: Failing unit tests** (`ActivityPeriodTest`, now = 2026-10-15)

```php
    #[Test]
    public function the_previous_month_is_the_calendar_month_before(): void
    {
        $previous = $this->period()->previous();

        $this->assertSame('month', $previous->key);
        $this->assertSame(['period' => 'month', 'from' => '2026-09-01', 'to' => '2026-09-30', 'label' => '2026-09'], $previous->toArray());
    }

    #[Test]
    public function the_previous_season_is_the_year_before(): void
    {
        $config = WebsiteConfig::singleton();
        $config->update(['settings' => [...$config->settings, 'seasonStartMonth' => 7]]);

        $previous = $this->period(['period' => 'season'])->previous()->toArray();

        $this->assertSame('2025-07-01', $previous['from']);
        $this->assertSame('2026-06-30', $previous['to']);
    }

    #[Test]
    public function the_previous_custom_range_has_the_same_length_and_ends_the_day_before(): void
    {
        $previous = $this->period(['period' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-19'])->previous()->toArray();

        $this->assertSame('2026-09-30', $previous['from']);
        $this->assertSame('2026-10-09', $previous['to']);
    }
```

- [ ] **Step 2: Failing feature test** (`AttendanceStatsPageTest`, now = 2026-10-20; reuse its `category()`, `player()`, `heldSession()`, `mark()`, `admin()`)

```php
    #[Test]
    public function the_figures_compare_with_the_previous_period(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        // October: 2 sessions held, 10 late minutes.
        $this->mark($this->heldSession($u15, '2026-10-05'), $a, AttendanceStatus::Late, 10);
        $this->mark($this->heldSession($u15, '2026-10-12'), $a, AttendanceStatus::Present);
        // September: 1 session held, 20 late minutes.
        $this->mark($this->heldSession($u15, '2026-09-14'), $a, AttendanceStatus::Late, 20);

        $this->actingAs($this->admin())->get(route('attendance.stats'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('deltas.held.percent', 100)
                ->where('deltas.held.tone', 'positive')
                ->where('deltas.late_minutes.percent', -50)
                ->where('deltas.late_minutes.tone', 'positive')
                ->has('deltas.score_pct')
                ->has('deltas.missed_hours'));
    }

    #[Test]
    public function nothing_last_period_means_no_comparison(): void
    {
        $u15 = $this->category('U15');
        $this->mark($this->heldSession($u15, '2026-10-05'), $this->player($u15), AttendanceStatus::Present);

        $this->actingAs($this->admin())->get(route('attendance.stats'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('deltas.held', null)
                ->where('deltas.late_minutes', null)
                ->where('deltas.score_pct', null));
    }
```

(Inertia props round-trip through JSON: a whole float arrives as an int, so assert `100`, not `100.0`. Check how the `Late` status records minutes in this app: `mark(..., $minutes)` writes `attendances.minutes`. If `late_minutes` is not fed by that column, read `AttendanceStats::players()` and adjust the fixture so October = 10 and September = 20 late minutes.)

- [ ] **Step 3: Run, expect FAIL**

Run: `php artisan test --filter="ActivityPeriodTest|AttendanceStatsPageTest"`
Expected: new tests fail (`previous()` undefined; `deltas` missing).

- [ ] **Step 4: Implement `ActivityPeriod::previous()`**

```php
    /** The window just before this one: the previous month, the previous season, or the same number of days ending the day before. */
    public function previous(): self
    {
        return match ($this->key) {
            self::MONTH => new self(
                self::MONTH,
                $start = $this->start->subMonthNoOverflow()->startOfMonth(),
                $start->endOfMonth(),
                $start->format('Y-m'),
            ),
            self::SEASON => new self(
                self::SEASON,
                $start = $this->start->subYear(),
                $this->start->subDay()->endOfDay(),
                $start->year.'-'.$this->start->year,
            ),
            default => (function (): self {
                $days = (int) $this->start->startOfDay()->diffInDays($this->end->startOfDay()) + 1;
                $end = $this->start->subDay()->endOfDay();
                $start = $end->subDays($days - 1)->startOfDay();

                return new self(self::CUSTOM, $start, $end, $start->toDateString().' – '.$end->toDateString());
            })(),
        };
    }
```

- [ ] **Step 5: Implement the controller prop**

In `AttendanceStatsController::index()` (not in `data()`, so `export()` never pays for it):

```php
    public function index(Request $request): Response
    {
        $data = $this->data($request);

        return Inertia::render('Attendance/Stats', [
            ...$data,
            'deltas' => $this->deltas($request, $data),
            'attendanceCodes' => app(AttendanceStatusCatalog::class)->codes(),
        ]);
    }

    /**
     * Held sessions, late minutes, missed hours and the score against the
     * window before (same length, same category and branch). Counts only, no
     * names: summarize() over the previous rows plus the held sessions.
     *
     * @return array{held: ?array, late_minutes: ?array, missed_hours: ?array, score_pct: ?array}
     */
    private function deltas(Request $request, array $data): array
    {
        $previous = ActivityPeriod::fromRequest($request)->previous()->toArray();
        ['from' => $from, 'to' => $to] = $previous;
        $totals = $this->stats->summarize($this->stats->players($from, $to, $data['categoryId'], null, $data['branchId']));
        $held = array_sum($this->stats->sessionsByMonth($from, $to, $data['categoryId'], $data['branchId'])['held']);
        $now = $data['totals'];

        return [
            'held' => DeltaCalculator::compute((float) $now['held'], (float) $held),
            'late_minutes' => DeltaCalculator::compute((float) $now['late_minutes'], (float) $totals['late_minutes'], DeltaCalculator::DOWN_GOOD),
            'missed_hours' => DeltaCalculator::compute((float) $now['missed_hours'], (float) $totals['missed_hours'], DeltaCalculator::DOWN_GOOD),
            'score_pct' => $now['score_pct'] === null || $totals['score_pct'] === null
                ? null
                : DeltaCalculator::compute((float) $now['score_pct'], (float) $totals['score_pct']),
        ];
    }
```

Add `use App\Services\Dashboard\Support\DeltaCalculator;`.

- [ ] **Step 6: Run tests + timing**

Run: `npm run build` then `php artisan test --filter="ActivityPeriodTest|AttendanceStats|AttendanceBranchStatsPageTest|AttendancePermissionTest"`.
Expected: pass.
Then time the page on the dev DB copy (`database/database.sqlite` in this worktree):
1. Load `/attendance/stats?period=season` twice with the server on :2027 (log in as `qa_stats` / `QaStats-2026!` via curl with cookies, or use `php artisan tinker` to time `app(AttendanceStatsController::class)`).
2. Report the before/after milliseconds.

If the page more than doubles, report DONE_WITH_CONCERNS with the numbers; do not optimise on your own.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Activity/ActivityPeriod.php app/Http/Controllers/AttendanceStatsController.php tests/Unit/ActivityPeriodTest.php tests/Feature/AttendanceStatsPageTest.php
git commit -m "feat(attendance): stats compare held sessions, late, missed and score with the previous period"
```

---

### Task 5: Phase 1 follow-ups in the shared components

**Files:**
- Modify: `resources/js/Components/Stats/StatTable.vue`
- Modify: `resources/js/Components/Dashboard/ChartCard.vue`
- Modify: `resources/js/Components/Stats/StatsToolbar.vue`
- Modify: `resources/js/Pages/Finance/Index.vue`
- Modify: `resources/js/i18n/{ar,fr,en}.json` (remove nothing unless step 5 says so)

**Interfaces:** No prop, slot or event changes (Reports Center shares these).

- [ ] **Step 1: StatTable sticky header border.** With `border-collapse`, a sticky `th`'s `border-b` scrolls away. Replace the `th` bottom border with an inset shadow on the sticky cells: `shadow-[inset_0_-1px_0_hsl(var(--border))]`, removing `border-b` from those `th`. Keep the same look when not scrolling.
- [ ] **Step 2: ChartCard tab ↔ panel wiring.**
  - Generate one id per instance (`const uid = \`cc-${Math.random().toString(36).slice(2, 9)}\`` at setup, or Vue 3.5 `useId()` if `vue` ≥ 3.5; check `node_modules/vue/package.json`).
  - Each `role="tab"` gets `:id="\`${uid}-tab-chart\`"` / `-tab-table` and `:aria-controls="\`${uid}-panel\`"`.
  - The content wrapper that shows either chart or table gets `role="tabpanel"`, `:id="\`${uid}-panel\`"` and `:aria-labelledby` = the selected tab's id. Only add these when `hasTable`.
- [ ] **Step 3: StatsToolbar badge announces changes.** Wrap the active-filter count badge in `aria-live="polite"` (on the badge element itself).
- [ ] **Step 4: Finance/Index follow-ups.**
  - (a) The budget table `type` column sorts by the raw value. Give the budget rows a `typeLabel` field (`r.type === 'expense' ? t('expense') : t('income')`) and make the column `{ key: 'typeLabel', … }`. The `cell-type` slot becomes `cell-typeLabel`, and the csv accessor can then go. Keep the on-screen text identical.
  - (b) The budget progress bar `aria-valuetext` uses `${b.used}%`. Change it to `formatPercent(b.used)` from `useFormatNumber()`.
- [ ] **Step 5: Orphan key.** `grep -rn "dashboard.toggle_table" resources/js app resources/views`. If the only hits are the three catalogs, delete that one line from each catalog by hand (exactly one deletion per file, nothing else moves). If anything else uses it, leave it.
- [ ] **Step 6: Verify**

Run: `npm run test:js`, `npm run build`, `npm run i18n:check`, `php artisan test --filter="Finance|Report"`.
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Components/Stats/StatTable.vue resources/js/Components/Dashboard/ChartCard.vue resources/js/Components/Stats/StatsToolbar.vue resources/js/Pages/Finance/Index.vue
# plus the 3 catalogs only if step 5 removed the key
git commit -m "fix(stats): sticky header border, tab-panel wiring, live filter count, budget type sort"
```

---

### Task 6: Dashboard shell — load errors and filter toolbar

**Files:**
- Modify: `resources/js/Pages/Dashboard.vue`
- Modify: `resources/js/Components/Dashboard/DashboardFilterBar.vue`
- i18n: none new (reuse `stats.load_failed`, `stats.retry`, `stats.filters`, `stats.active_filters`)

**Interfaces:**
- DashboardFilterBar gains emits `loading` (Boolean) and `failed` (no payload). Its `apply()` keeps the same URL/`only`.
- Dashboard.vue gains `tabError` (ref: tab name or null).

- [ ] **Step 1: Filter bar reports its reload.**
  - In `apply()` add `onStart: () => emit('loading', true)`.
  - Add `let ok = false;`, `onSuccess: () => { ok = true; }`, and `onFinish: () => { emit('loading', false); if (!ok) emit('failed'); }`. Declare the `ok` flag inside `apply` before `router.get`.
  - Inertia 2.3 visit callbacks have no exception hook; "finished without success" covers network errors, server errors and cancels. A cancel (superseded by a newer visit) must not flag an error, so add `onCancel: () => { ok = true; }`.
- [ ] **Step 2: Filter bar inside the shared toolbar shell.**
  - Wrap the filter bar's root content in `<StatsToolbar :active-count="activeCount" :loading="loading" @reset="reset">`.
  - `activeCount` = `(filters.range !== 'month') + (filters.branch ? 1 : 0) + (!filters.compare && filters.range !== 'all' ? 1 : 0)`.
  - `reset()` = `apply({ range: 'month', branch: '', compare: true })`. `apply` uses `??`, so `''` is kept and then `|| undefined` drops it.
  - Keep every existing control and its classes.
- [ ] **Step 3: Dashboard shell tracks failures.**
  - `const tabError = ref(null);`.
  - In `selectTab`'s fetching branch, use the same `ok` flag pattern. On finish without success set `tabError.value = tab` and leave `loaded[tab]` false. On success clear `tabError.value = null`.
  - `<DashboardFilterBar … @loading="(v) => (loadingTab = v)" @failed="tabError = activeTab" />`. On a later successful filter reload, clear `tabError`: watch `props.hero` changes or set it in a new `@loading` false handler only when not failed. Simplest: clear `tabError` in the existing filters watcher.
  - `retry()` = `loaded[activeTab.value] = false;` then re-run the fetch for `activeTab` with `only: ['filters', 'hero', activeTab.value]`, using the same `ok`/`tabError` handling. Extract the fetch into `fetchTab(tab, only)` so selectTab and retry share it.
  - Render above the tab component:

```vue
            <div v-if="tabError === activeTab" role="alert" class="flex flex-col items-center gap-3 rounded-xl border border-border/70 px-5 py-8 text-sm text-muted-foreground">
                <p>{{ t('stats.load_failed') }}</p>
                <IconButton icon="refresh" :label="t('stats.retry')" size="sm" @click="retry" />
            </div>
```

  Check that `IconButton` accepts `size="sm"` and that an icon named `refresh` exists in `resources/js/Components/Icon.vue`. If not, use the icon ChartCard uses for retry.
- [ ] **Step 4: Verify**

Run: `npm run build`, `npm run test:js`, `php artisan test --filter=Dashboard`.
Expected: pass.
Manual check (report it): with `php artisan serve` on :2027 running, stop the server mid-session or block the request in devtools. A tab switch must then show the alert, and Retry must recover once the server is back.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Dashboard.vue resources/js/Components/Dashboard/DashboardFilterBar.vue
git commit -m "feat(dashboard): failed tab or filter reload shows retry; filters in the shared toolbar"
```

---

### Task 7: Dashboard Finance tab on the stats system

**Files:**
- Modify: `resources/js/Pages/Dashboard/Partials/FinanceTab.vue`
- Modify: `resources/js/Pages/Dashboard.vue` (pass `:period-label` to the tab)

**Interfaces:**
- Consumes: `finance.summary[].delta` from Task 2.
- FinanceTab gains prop `periodLabel: { type: String, default: '' }`. Dashboard passes the same `periodLabel` computed it gives the hero row (`t(\`dashboard.vs_${filters.range}\`)`).

- [ ] **Step 1: Tiles show deltas.** In the `StatTile` loop add `:delta="tile.delta"` and `:period-label="periodLabel"`. Runway has `delta: null` and shows no badge.
- [ ] **Step 2: Ranked bars from the shared helper.** Delete the local `categoryChart` ramp logic. Use `rankedBarDataset(t('dashboard.amount'), rows.map((r) => r.amount))` with labels from `rowLabel`, inside computeds that read `themeVersion.value` (`useChartTheme`). `chartOptions` also reads `themeVersion.value`.
- [ ] **Step 3: StatTable + CSV for both category cards.** Use the Reference-pattern columns exactly:
  - `label` text sortable
  - `amount` money sortable `total: 'sum'`
  - `share` percent sortable

  Rows = `rows.map((r) => ({ ...r, label: rowLabel(r) }))`. Row key `(r) => r.labelKey || r.name`. `totals` on.

  CSV filenames: `dashboard-expense-by-category`, `dashboard-income-by-category`. Pass `totals: true` in the csv object.

  Remove the hand-written `<table>` blocks.
- [ ] **Step 4: Verify**

Run: `npm run build`, `npm run test:js`, `php artisan test --filter=Dashboard`.
Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Dashboard/Partials/FinanceTab.vue resources/js/Pages/Dashboard.vue
git commit -m "feat(dashboard): finance tab deltas, shared ranked bars, sortable tables with CSV"
```

---

### Task 8: Dashboard Members tab on the stats system

**Files:**
- Modify: `resources/js/Pages/Dashboard/Partials/MembersTab.vue`
- i18n: `stats.age_band` = ar "الفئة العمرية", fr "Tranche d'âge", en "Age band" (insert-only script)

- [ ] **Step 1: Theme + ranked bars.**
  - `useChartTheme`; every chart computed (`growthChart`, `categoryChart`, `leftCategoryChart`, `ageChart`, and the three options computeds) reads `themeVersion.value`.
  - Replace `magnitudeChart`'s inline ramp with `rankedBarDataset(t('dashboard.members'), rows.map((r) => r.count))`.
- [ ] **Step 2: Growth table → StatTable + CSV.**
  - Columns:
    - `month` text (row value = the `YYYY-MM` label)
    - `joined` number `total: 'sum'`
    - `left` number `total: 'sum'`
    - `cumulative` number (NO total: it is a running total)
  - Label keys: `dashboard.month`, `dashboard.mem_joined`, `dashboard.mem_left`, `dashboard.mem_total`.
  - Rows built from `growth.labels` by index: `left: growthLeft[i] ?? 0`.
  - Not sortable (time order matters): omit `sortable`. Row key `month`. CSV `dashboard-member-growth`, totals.
- [ ] **Step 3: By-category and left-by-category tables get headers.** Use StatTable with:
  - `label` text sortable (`t('dashboard.category')`)
  - `count` number sortable `total: 'sum'` (`t('dashboard.members')`)

  Row key `(r) => r.labelKey || r.name`. CSV `dashboard-members-by-category` and `dashboard-leavers-by-category`, totals.
- [ ] **Step 4: By-age gets a table.**
  - Add `has-table` and a `#table` slot with StatTable:
    - `label` text (`t('stats.age_band')`, value `t(\`dashboard.age_${row.band}\`)`)
    - `count` number `total: 'sum'`
  - Not sortable (ordered scale). Row key `band`. CSV `dashboard-members-by-age`, totals.
- [ ] **Step 5: Verify**

Run: `npm run build`, `npm run test:js`, `npm run i18n:check`, `php artisan test --filter=Dashboard`.
Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Dashboard/Partials/MembersTab.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(dashboard): members tab tables with headers, age table, CSV, live theme"
```

---

### Task 9: Dashboard Overview and Operations tabs on the stats system

**Files:**
- Modify: `resources/js/Pages/Dashboard/Partials/OverviewTab.vue`
- Modify: `resources/js/Pages/Dashboard/Partials/OperationsTab.vue`
- i18n: `stats.rentals` only if `dashboard.ops_rentals` is missing (it exists: reuse it)

- [ ] **Step 1: Overview.**
  - `useChartTheme`; `cashFlowChart`, `cashFlowOptions`, `agingChart`, `agingOptions` read `themeVersion.value`.
  - Cash-flow table → StatTable. Columns, not sortable:
    - `month` text
    - `income` money `total: 'sum'`
    - `expense` money `total: 'sum'`
    - `net` money `total: 'sum'`

    Row key `month`. CSV `dashboard-cash-flow`, totals.
  - Aging table → StatTable. Columns, not sortable (ordered buckets):
    - `bucket` text: show the same label the chart legend uses, `t(\`dashboard.aging_${bucket.bucket.replace('+', '_plus').replace('-', '_')}\`)`. Today the table shows the raw bucket; switching to the translated label is the one intended visible change. Mention it in the report.
    - `amount` money `total: 'sum'`
    - `players` number `total: 'sum'`

    Row key `bucket`. CSV `dashboard-debt-aging`, totals.
- [ ] **Step 2: Operations.**
  - `useChartTheme` on `funnelChart`, `funnelOptions`, `rentalsChart`, `rentalsOptions`.
  - Funnel table → StatTable:
    - `label` text sortable (`${row.name} ${row.year}`)
    - `enrolled`, `paid`, `partial`, `unpaid`, `exempt`: number sortable `total: 'sum'`
    - `rate` percent sortable (no total)

    Row key `label`. CSV `dashboard-subscription-funnel`, totals.
  - Rentals card gets `has-table` + StatTable:
    - `month` text
    - `count` number `total: 'sum'` (`t('dashboard.ops_rentals')`)

    Not sortable. Row key `month`. CSV `dashboard-rentals-per-month`, totals.
- [ ] **Step 3: Verify**

Run: `npm run build`, `npm run test:js`, `npm run i18n:check`, `php artisan test --filter=Dashboard`.
Expected: pass.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Dashboard/Partials/OverviewTab.vue resources/js/Pages/Dashboard/Partials/OperationsTab.vue
git commit -m "feat(dashboard): overview and operations tables on StatTable with CSV; rentals table"
```

---

### Task 10: Attendance/Stats on the stats system

**Files:**
- Modify: `resources/js/Pages/Attendance/Stats.vue`
- i18n (insert-only):
  - `stats.vs_previous_month`: ar "مقارنة بالشهر السابق", fr "vs mois précédent", en "vs previous month"
  - `stats.vs_previous_period`: ar "مقارنة بالفترة السابقة", fr "vs période précédente", en "vs previous period"

**Interfaces:** Consumes prop `deltas` (Task 4): `{ held, late_minutes, missed_hours, score_pct }`, each `?{percent, raw, tone}`.

- [ ] **Step 1: Filters in the toolbar shell.**
  - Wrap `BranchSwitch`, `PeriodFilter` and the category `<select>` in `<StatsToolbar :active-count="activeCount" @reset="resetFilters">`.
  - `activeCount` = `(period.period !== 'month') + (categoryId ? 1 : 0) + (branchId ? 1 : 0)`.
  - `resetFilters` = `router.get(route('attendance.stats'), {}, { preserveScroll: true, replace: true })`.
  - Header actions stay where they are.
- [ ] **Step 2: Tiles → StatTile.**
  - Add `defineProps` entry `deltas: { type: Object, default: () => ({}) }`.
  - Replace the hand-written tile loop with `StatTile`. The grid stays `sm:grid-cols-3 xl:grid-cols-6`:

    | key | label | value | format | delta | icon / tone |
    |---|---|---|---|---|---|
    | held | `att.stats.held_sessions` | `totals.held` | number | `deltas.held` | `calendar` / primary |
    | cancelled | `att.stats.cancelled_sessions` | `totals.cancelled` | number | — | `close` / neutral |
    | marks | `att.stats.marks` | `totals.expected` | number | — | `check` / neutral |
    | late | `att.col.late_minutes` | `totals.late_minutes` | number | `deltas.late_minutes` | `clock` / warning |
    | missed | `att.col.missed_hours` | `totals.missed_hours` | number | `deltas.missed_hours` | `alert` / negative |
    | score | `att.col.score_pct` | `totals.score_pct` | percent | `deltas.score_pct` | `flag` / positive |

    Pick icons that exist in `Icon.vue`; substitute the closest existing name if one is missing.
  - `period-label`:
    - `period.period === 'month'` → `t('stats.vs_previous_month')`
    - `'season'` → `t('stats.vs_previous_season')`
    - else `t('stats.vs_previous_period')`
- [ ] **Step 3: Chart tables → StatTable + CSV; live theme.**
  - `useChartTheme`; `statusChart`, `stackedOptions`, `sessionsChart`, `sessionsOptions` read `themeVersion.value`.
  - "By month" table → StatTable, not sortable:
    - `ym` text (`t('att.date')`)
    - one number column per visible status, `key: s`, label `label(s)`, `total: 'sum'`

    Rows: `{ ym, ...Object.fromEntries(statuses.map((s) => [s, monthly.statuses[s][i]])) }`. Row key `ym`. CSV `attendance-by-month`, totals.
  - "Sessions by month" → StatTable, not sortable:
    - `ym` text
    - `held` number `total: 'sum'`
    - `cancelled` number `total: 'sum'`

    CSV `attendance-sessions-by-month`, totals.
  - The by-category table, StatsRanking and StatsPlayerTable stay as they are (out of scope).
- [ ] **Step 4: Verify**

Run: `npm run build`, `npm run test:js`, `npm run i18n:check`, `php artisan test --filter="AttendanceStats|AttendanceBranchStatsPageTest"`.
Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Attendance/Stats.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(attendance): stats tiles with previous-period deltas, toolbar shell, chart tables with CSV"
```

---

### Task 11: Board/Tasks stats and StatDoughnut on the chart palette

**Files:**
- Modify: `resources/js/Components/StatDoughnut.vue`
- Modify: `resources/js/Pages/Board/Tasks.vue` (stats view only; Task 1 already touched mutations)
- i18n: `stats.year` = ar "السنة", fr "Année", en "Year" (insert-only) unless an equivalent key already exists (`grep '"year"' resources/js/i18n/en.json`)

**Interfaces:** StatDoughnut props unchanged except the `palette` default. It is used by `Board/Tasks.vue`, `Players/Partials/AttendanceSection.vue`, `Reports/Show.vue`, `StatBars.vue` and `useStatSelection.js`; all keep working.

- [ ] **Step 1: StatDoughnut default palette from the theme.**
  - `palette: { type: Array, default: null }`.
  - `colorOf = (stat, i) => stat.color || (props.palette ? props.palette[i % props.palette.length] : seriesColor(i))`.
  - `chartData` and `chartOptions` read `themeVersion.value` (`useChartTheme`).
  - The fade on selection only works for `#rrggbb`. chartTheme colours are `#rrggbb`, so the existing regex keeps working. Do not change the selection logic.
- [ ] **Step 2: Tasks stats KPIs → StatTile.** Replace the four hand-written `card p-4` tiles:
  - total: `stats.total`, icon `task`, tone primary
  - completion rate: value `stats.completionRate`, format `percent`, meta `` `${stats.completed}/${stats.total}` ``, tone positive
  - in progress: `stats.inProgress`, meta `` `${t('avg')} ${formatPercent(stats.avgProgress)}` ``, tone primary
  - overdue: `stats.overdue`, tone negative, icon `alert`

  The completion progress bar under the old tile goes away: the percent tile replaces it.
- [ ] **Step 3: Doughnuts drop the hex palettes.**
  - Delete `statusPalette` and `priorityPalette`.
  - Pin meaning-bearing colours with `color` on each chip from chartTheme `seriesColor`:
    - status: not_started `mutedInk()`, in_progress `seriesColor(0)`, completed `seriesColor(2)`, cancelled `mutedInk()` at reduced emphasis. Use `seriesColor(8)` if two greys look identical.
    - priority: high `seriesColor(7)`, medium `seriesColor(3)`, low `seriesColor(0)`.
  - Chips are computeds, so they also read `themeVersion.value`.
- [ ] **Step 4: Monthly and yearly bars → ChartCard with tables.**
  - Replace the two `card p-5` chart boxes with `ChartCard`:
    - monthly: title `` `${t('completed_monthly')} · ${stats.currentYear}` ``, height `h-52`, `has-table`, empty when all zero
    - yearly: title `t('completed_yearly')` (keep whatever key the page uses today), empty when `!stats.yearly?.length`
  - Datasets via `barDataset(t('completed'), data, 2)` for monthly and `barDataset(t('completed'), data, 0)` for yearly. Options: `baseOptions({ rtl, legend: false })`, replacing the local `barOptions` object. All computeds read `themeVersion.value`.
  - Tables (StatTable, not sortable, totals on):
    - monthly: `month` text (the `monthLabels` value), `count` number `total: 'sum'`. CSV `board-tasks-completed-monthly`.
    - yearly: `year` text (`t('stats.year')`), `count` number `total: 'sum'`. CSV `board-tasks-completed-yearly`.
- [ ] **Step 5: Verify**

Run: `npm run build`, `npm run test:js`, `npm run i18n:check`, `php artisan test --filter="Board|Report|AttendanceBranchPlayerCardTest"`.
Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/StatDoughnut.vue resources/js/Pages/Board/Tasks.vue
# plus the 3 catalogs if stats.year was added
git commit -m "feat(board): task stats on StatTile and ChartCard with tables; doughnuts use the chart palette"
```

---

### Task 12: Activity charts on the stats system

**Files:**
- Modify: `resources/js/Components/Activity/ActivityCharts.vue`
- i18n: none new unless a header label is missing (reuse `activity.*` keys already in the tables)

- [ ] **Step 1: KPI boxes → StatTile.** The four hand-written boxes become `StatTile`:
  - total: `activity.total`, number
  - active days/weeks: value `activeDays`, meta `` `/ ${charts.buckets.length}` ``
  - average per active: value `activeDays ? total / activeDays : 0`, number. formatNumber keeps max 2 decimals, and the old `toFixed(1)` one decimal is acceptable to lose.
  - busiest: value `busiest?.count ?? 0`, meta `bucketLabel(busiest.bucket)` when present
- [ ] **Step 2: Theme.** `useChartTheme`; `timelineData`, `timelineOptions`, `shareData`, `shareOptions`, `actionsData`, `barOptions`, `usersData` read `themeVersion.value`.
- [ ] **Step 3: Tables → StatTable + CSV.** Read the four existing `#table` blocks and keep their columns and labels. Rules:
  - timeline: date-ordered, not sortable. Bucket label as text, one number column per shown area with `total: 'sum'`, plus a `total` number column without a total. CSV `activity-timeline`.
  - areas: sortable. `label` text, `count` number `total: 'sum'`, and a `share` percent column only if the current table has one. CSV `activity-by-area`.
  - actions: sortable. `label` text, `count` number `total: 'sum'`. CSV `activity-by-action`.
  - users: sortable. `name` text, `total` number `total: 'sum'`. CSV `activity-top-users`.

  Pass totals in both StatTable and csv.
- [ ] **Step 4: Verify**

Run: `npm run build`, `npm run test:js`, `php artisan test --filter=Activity`.
Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Activity/ActivityCharts.vue
git commit -m "feat(activity): key figures on StatTile, chart tables on StatTable with CSV, live theme"
```

---

### Task 13: Player Attendance and Academic sections

**Files:**
- Modify: `resources/js/Pages/Players/Partials/AttendanceSection.vue`
- Modify: `resources/js/Pages/Players/Partials/AcademicSection.vue`

- [ ] **Step 1: AttendanceSection tiles → StatTile.**
  - The `bg-slate-50` tile loop becomes `StatTile`, keeping the `sm:grid-cols-4` grid:
    - expected: number
    - score: `summary.score_pct` percent
    - late: number
    - missed: `summary.missed_hours` number
  - No deltas: the profile endpoint has none, and none are invented.
- [ ] **Step 2: AttendanceSection monthly chart → ChartCard with a table.**
  - Wrap the stacked bar (currently `<div class="h-52"><Bar …/></div>`) in `ChartCard`:
    - title `t('att.stats.by_month')`
    - height `h-52`
    - `has-table`
    - empty when `!hasMarks(data.monthly)` (import from `@/lib/attendanceStats`)
  - Table: StatTable, not sortable. `ym` text plus one number column per visible status (`statuses`), `total: 'sum'`. CSV filename `attendance-player-${player.id}-by-month`, totals.
  - `monthlyChart` and `monthlyOptions` read `themeVersion.value`.
  - The doughnut keeps its status colours (it passes `palette` from the codes).
  - The session list table below stays as is.
- [ ] **Step 3: AcademicSection.**
  - The three `bg-slate-50` summary boxes become `StatTile`, keeping their labels and values.
  - If a value is a pre-formatted string (e.g. `"12.50 / 20"`), pass `format="text"` and keep the existing Unicode-isolate trick (see MembersTab `academicAverageDisplay`).
  - The line chart computeds read `themeVersion.value`.
  - The grades table beside the chart stays as is: it is an editable table, not a chart table.
- [ ] **Step 4: Verify**

Run: `npm run build`, `npm run test:js`, `php artisan test --filter="Player|Academic|AttendanceBranchPlayerCardTest"`.
Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Players/Partials/AttendanceSection.vue resources/js/Pages/Players/Partials/AcademicSection.vue
git commit -m "feat(players): attendance and academic figures on StatTile; monthly attendance table with CSV"
```

---

## After all tasks (controller)

1. Full suite: `npm run build && php artisan test`. Only the known `ManualSuspensionsTest` failure is allowed.
2. Browser QA with the scratchpad `qa/qa-cdp.mjs`, extended to: dashboard (4 tabs), attendance stats, board tasks (stats view), users activity, and a player page. Server on :2027, `qa_stats` / `QaStats-2026!`. Check that:
   - the table toggle fires 0 XHR
   - the CSV has a BOM
   - scrollWidth is 375 at phone width
   - dark recolours
   - the console is clean
   - a board view-only user opens `/board/tasks` with no add button
3. Whole-branch review on the most capable model; one fix round.
4. Tell the Reports Center session (`irnb-laravel-f0`): StatDoughnut's default palette changed (Reports/Show uses it), and the ChartCard tabpanel ids are new. No API change.
5. Owner check before merge.
