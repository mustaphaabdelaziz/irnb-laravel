# Dashboard Redesign — Phase 1 (Foundation, Shell, Hero, Overview) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the flat `/dashboard` page with a filtered, tabbed analytics shell — URL-driven time/branch/compare filters, a six-tile hero KPI row with sparklines and meaning-aware deltas, and a working Overview tab — on a backend of testable per-domain stat providers.

**Architecture:** `DashboardController` becomes a thin resolver over `app/Services/Dashboard/*` stat providers, each a single-purpose class taking a `DashboardFilters` DTO. Tab payloads ship as Inertia v2 optional props so a tab's queries only run when that tab is requested. The Vue side gets a reusable `Components/Dashboard/` library and a `chartTheme.js` module that removes hex literals from components.

**Tech Stack:** Laravel 13, Inertia v2, Vue 3 `<script setup>`, Tailwind 4, shadcn-vue, Chart.js 4 via vue-chartjs, SQLite (web + NativePHP desktop), PHPUnit-style tests with `RefreshDatabase`.

## Global Constraints

- **Spec:** `docs/superpowers/specs/2026-09-20-dashboard-redesign-design.md`. Every decision there is binding.
- **Chart series colours** — fixed order, never cycled. Light: `#2a78d6` `#eb6834` `#1baf7a` `#eda100` `#e87ba4` `#008300` `#4a3aa7` `#e34948`. Dark: `#3987e5` `#d95926` `#199e70` `#c98500` `#d55181` `#008300` `#9085e9` `#e66767`.
- **Money in = slot 1 blue. Money out = slot 2 orange.** Never emerald/rose as two adjacent chart marks — that pair fails CVD at ΔE 5.8 light / 4.6 dark.
- **emerald / rose / amber** stay semantic in UI chrome only (badges, delta chips, text values).
- **Delta tone follows meaning, not sign.** Falling debt is green.
- **Query budgets:** measured at 18 queries for the hero row, 11 for an Overview reload, 27 for a first paint carrying both. Asserted by test with small headroom.
- **No caching** in this phase.
- **Excluded rows:** money stats use `archived = false`; member stats exclude `archived = true` players; exempt subscription lines are out of debt and collection numerators and denominators alike.
- **Every new user-facing string** is a flat key in all three of `resources/js/i18n/{ar,fr,en}.json`. `npm run i18n:check` must pass. Call `t()` directly — never gate on `te()`.
- **Test style:** PHPUnit class style with `#[Test]` attributes and `RefreshDatabase`, matching `tests/Feature/DashboardTest.php`. Only `User` and `Role` have factories; build other fixtures with `Model::create()` or `RegisterPlayerService`.
- **Run tests with `composer test`** (it clears cached config first — `php artisan test` alone can wipe the real database).
- **RTL:** Arabic is first-class. No `left`/`right` utilities; use `start`/`end`.

---

## File Structure

**Backend — create**

| File | Responsibility |
|---|---|
| `app/Services/Dashboard/DashboardFilters.php` | Readonly DTO: resolved date window, previous window, branch, compare flag. Parses and validates request input. |
| `app/Services/Dashboard/Support/MonthBucket.php` | Driver-aware `YYYY-MM` SQL expression + the 12-month label series. |
| `app/Services/Dashboard/Support/DeltaCalculator.php` | Delta percent, direction, and the suppression rules. |
| `app/Services/Dashboard/Support/BranchScope.php` | One place that knows each entity's path to a branch. |
| `app/Services/Dashboard/HeroStats.php` | The six KPI tiles and their sparklines. |
| `app/Services/Dashboard/OverviewStats.php` | Cash flow series, debt aging, alerts, activity feed. |

**Backend — modify**

| File | Change |
|---|---|
| `app/Http/Controllers/DashboardController.php` | Rewrite as a thin resolver over the providers. |

**Frontend — create**

| File | Responsibility |
|---|---|
| `resources/js/lib/chartTheme.js` | Palette by role, Chart.js defaults, CSS-variable reads, RTL awareness. |
| `resources/js/Components/Dashboard/StatTile.vue` | The one tile anatomy. |
| `resources/js/Components/Dashboard/Sparkline.vue` | Inline SVG sparkline, no Chart.js. |
| `resources/js/Components/Dashboard/DeltaBadge.vue` | Arrow + percent + period, tone by meaning. |
| `resources/js/Components/Dashboard/ChartCard.vue` | Chart frame with empty/loading states and table toggle slot. |
| `resources/js/Components/Dashboard/Meter.vue` | Ratio against a limit. |
| `resources/js/Components/Dashboard/AlertChip.vue` | Icon + label + count status chip. |
| `resources/js/Components/Dashboard/DashboardFilterBar.vue` | Range, branch, compare controls; URL-driven. |
| `resources/js/Pages/Dashboard/Partials/OverviewTab.vue` | Overview tab content. |

**Frontend — modify**

| File | Change |
|---|---|
| `resources/js/Pages/Dashboard.vue` | Rewrite as the shell: filter bar, hero row, tab switcher. |
| `resources/js/lib/registerCharts.js` | Register the elements the new charts need. |
| `resources/js/i18n/{ar,fr,en}.json` | New keys. |

**Tests — create**

| File | Covers |
|---|---|
| `tests/Feature/Dashboard/DashboardFiltersTest.php` | Window resolution, previous window, boundaries, all-time. |
| `tests/Feature/Dashboard/HeroStatsTest.php` | Tile math, empty safety, branch isolation. |
| `tests/Feature/Dashboard/OverviewStatsTest.php` | Aging buckets, cash flow series, alerts. |
| `tests/Feature/Dashboard/DashboardPageTest.php` | Props, lazy tabs, permissions, query budgets. |

`tests/Feature/DashboardTest.php` is replaced by `DashboardPageTest.php` and deleted in Task 6.

---

## Task 1: Filters DTO and support utilities

**Files:**
- Create: `app/Services/Dashboard/DashboardFilters.php`
- Create: `app/Services/Dashboard/Support/MonthBucket.php`
- Create: `app/Services/Dashboard/Support/DeltaCalculator.php`
- Create: `app/Services/Dashboard/Support/BranchScope.php`
- Test: `tests/Feature/Dashboard/DashboardFiltersTest.php`

**Interfaces:**
- Produces:
  - `DashboardFilters::fromRequest(Request $r): self`
  - Public readonly: `?CarbonImmutable $from`, `?CarbonImmutable $to`, `?CarbonImmutable $prevFrom`, `?CarbonImmutable $prevTo`, `string $range`, `?int $branchId`, `bool $compare`, `string $tab`
  - `DashboardFilters::isAllTime(): bool`, `hasComparison(): bool`, `toArray(): array`
  - `MonthBucket::expression(string $column): string`
  - `MonthBucket::lastTwelve(CarbonImmutable $end): array` → list of `'YYYY-MM'`
  - `DeltaCalculator::compute(float $current, ?float $previous, string $direction): ?array` where `$direction` is `'up_good'` or `'down_good'`; returns `['percent' => float, 'tone' => 'positive'|'negative'|'neutral', 'raw' => float]` or `null`
  - `BranchScope::players(Builder $q, ?int $branchId): Builder`, `::transactions(...)`, `::equipmentItems(...)`, `::subscriptions(...)`

- [x] **Step 1: Write the failing test**

`tests/Feature/Dashboard/DashboardFiltersTest.php`:

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Services\Dashboard\DashboardFilters;
use App\Services\Dashboard\Support\DeltaCalculator;
use App\Services\Dashboard\Support\MonthBucket;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardFiltersTest extends TestCase
{
    private function filters(array $query = []): DashboardFilters
    {
        return DashboardFilters::fromRequest(Request::create('/dashboard', 'GET', $query));
    }

    #[Test]
    public function it_defaults_to_the_current_month_with_comparison_on(): void
    {
        $this->travelTo('2026-05-14 10:00:00');

        $f = $this->filters();

        $this->assertSame('month', $f->range);
        $this->assertSame('2026-05-01', $f->from->toDateString());
        $this->assertSame('2026-05-31', $f->to->toDateString());
        $this->assertSame('2026-04-01', $f->prevFrom->toDateString());
        $this->assertSame('2026-04-30', $f->prevTo->toDateString());
        $this->assertTrue($f->compare);
        $this->assertSame('overview', $f->tab);
    }

    #[Test]
    public function all_time_has_no_window_and_no_comparison(): void
    {
        $f = $this->filters(['range' => 'all', 'compare' => '1']);

        $this->assertTrue($f->isAllTime());
        $this->assertNull($f->from);
        $this->assertFalse($f->hasComparison());
    }

    #[Test]
    public function an_unknown_range_falls_back_to_month(): void
    {
        $this->assertSame('month', $this->filters(['range' => 'banana'])->range);
    }

    #[Test]
    public function last_twelve_months_spans_twelve_whole_months(): void
    {
        $this->travelTo('2026-05-14 10:00:00');

        $f = $this->filters(['range' => 'last12']);

        $this->assertSame('2025-06-01', $f->from->toDateString());
        $this->assertSame('2026-05-31', $f->to->toDateString());
    }

    #[Test]
    public function branch_is_null_when_absent_or_all(): void
    {
        $this->assertNull($this->filters()->branchId);
        $this->assertNull($this->filters(['branch' => 'all'])->branchId);
        $this->assertSame(3, $this->filters(['branch' => '3'])->branchId);
    }

    #[Test]
    public function month_bucket_returns_a_driver_appropriate_expression(): void
    {
        // The test suite runs on SQLite.
        $this->assertStringContainsString('strftime', MonthBucket::expression('transaction_date'));
    }

    #[Test]
    public function last_twelve_labels_end_with_the_given_month(): void
    {
        $labels = MonthBucket::lastTwelve(\Carbon\CarbonImmutable::parse('2026-05-14'));

        $this->assertCount(12, $labels);
        $this->assertSame('2025-06', $labels[0]);
        $this->assertSame('2026-05', $labels[11]);
    }

    #[Test]
    public function delta_tone_follows_meaning_not_sign(): void
    {
        $up = DeltaCalculator::compute(120, 100, 'up_good');
        $this->assertSame(20.0, $up['percent']);
        $this->assertSame('positive', $up['tone']);

        $debtFell = DeltaCalculator::compute(80, 100, 'down_good');
        $this->assertSame(-20.0, $debtFell['percent']);
        $this->assertSame('positive', $debtFell['tone']);

        $debtRose = DeltaCalculator::compute(120, 100, 'down_good');
        $this->assertSame('negative', $debtRose['tone']);
    }

    #[Test]
    public function delta_is_suppressed_when_there_is_no_previous_value(): void
    {
        $this->assertNull(DeltaCalculator::compute(120, null, 'up_good'));
        $this->assertNull(DeltaCalculator::compute(120, 0.0, 'up_good'));
    }

    #[Test]
    public function an_unchanged_value_is_neutral(): void
    {
        $this->assertSame('neutral', DeltaCalculator::compute(100, 100, 'up_good')['tone']);
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter=DashboardFiltersTest`
Expected: FAIL — `Class "App\Services\Dashboard\DashboardFilters" not found`

- [x] **Step 3: Write the implementation**

Create the four classes per the interfaces above. `DashboardFilters` resolves windows with `CarbonImmutable`, clamps `range` to the allowed set (`month`, `last_month`, `quarter`, `year`, `last12`, `all`), forces `compare` false for `all`, and clamps `tab` to `overview|finance|members|operations`. `MonthBucket::expression()` switches on `DB::connection()->getDriverName()`. `DeltaCalculator::compute()` returns `null` when `$previous` is null or zero, computes `($current - $previous) / $previous * 100` rounded to one decimal, and maps sign to tone through `$direction`. `BranchScope` applies `whereHas('branches', ...)` for players and subscriptions, `whereHas('financeAccount', fn ($q) => $q->where('branch_id', $id))` for transactions, and the `branch_equipment_item` pivot for equipment.

- [x] **Step 4: Run tests**

Run: `composer test -- --filter=DashboardFiltersTest`
Expected: PASS, 10 tests

- [x] **Step 5: Run Pint**

Run: `vendor/bin/pint app/Services/Dashboard tests/Feature/Dashboard`
Expected: no style errors remaining

- [x] **Step 6: Commit**

```bash
git add app/Services/Dashboard tests/Feature/Dashboard
git commit -m "feat(dashboard): filters DTO, month bucketing, delta rules, branch scope"
```

---

## Task 2: HeroStats provider

**Files:**
- Create: `app/Services/Dashboard/HeroStats.php`
- Test: `tests/Feature/Dashboard/HeroStatsTest.php`

**Interfaces:**
- Consumes: `DashboardFilters`, `MonthBucket`, `DeltaCalculator`, `BranchScope` from Task 1.
- Produces: `HeroStats::get(DashboardFilters $filters): array` → a list of six tiles, each
  `['key' => string, 'value' => float|int, 'format' => 'number'|'money'|'percent', 'delta' => ?array, 'spark' => float[], 'meta' => ?array]`
  with `key` in `members`, `collection_rate`, `net_cash_flow`, `outstanding_debt`, `equipment_on_loan`, `treasury`.

- [x] **Step 1: Write the failing test**

`tests/Feature/Dashboard/HeroStatsTest.php` covering:
- `it_counts_active_members_and_excludes_archived`
- `collection_rate_is_paid_over_paid_plus_owed_excluding_exempt_lines`
- `collection_rate_is_null_when_nothing_is_billed` (asserts `value === null`, not `0`)
- `net_cash_flow_is_income_minus_expense_within_the_window`
- `archived_transactions_are_excluded_from_cash_flow`
- `outstanding_debt_sparkline_reconstructs_from_subscription_lines`
- `equipment_on_loan_counts_open_rentals_and_reports_overdue_separately`
- `branch_filter_isolates_one_branch_from_another`
- `every_tile_has_a_key_and_a_format`

Each test builds fixtures with `Model::create()` / `RegisterPlayerService`, calls `app(HeroStats::class)->get($filters)`, and asserts on the returned array.

- [x] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter=HeroStatsTest`
Expected: FAIL — `Class "App\Services\Dashboard\HeroStats" not found`

- [x] **Step 3: Write the implementation**

One aggregate query per tile, plus grouped-by-month queries for the sparklines. Debt and treasury sparklines use the reconstruction rules from the spec (accrual from `player_subscriptions`; opening funds plus cumulative non-archived transactions). `collection_rate` returns `null` when the denominator is zero so the tile renders `—`.

- [x] **Step 4: Run tests**

Run: `composer test -- --filter=HeroStatsTest`
Expected: PASS

- [x] **Step 5: Commit**

```bash
git add app/Services/Dashboard/HeroStats.php tests/Feature/Dashboard/HeroStatsTest.php
git commit -m "feat(dashboard): hero KPI stat provider"
```

---

## Task 3: OverviewStats provider

**Files:**
- Create: `app/Services/Dashboard/OverviewStats.php`
- Test: `tests/Feature/Dashboard/OverviewStatsTest.php`

**Interfaces:**
- Produces: `OverviewStats::get(DashboardFilters $filters): array` with keys
  - `cashFlow`: `['labels' => string[], 'income' => float[], 'expense' => float[], 'net' => float[]]`
  - `debtAging`: list of `['bucket' => '0-30'|'31-60'|'61-90'|'90+', 'amount' => float, 'players' => int]`
  - `alerts`: list of `['key' => string, 'count' => int, 'severity' => 'warning'|'serious'|'critical', 'href' => ?string]`
  - `activity`: list of `['type' => 'transaction'|'registration'|'rental', 'at' => string, 'label' => string, 'amount' => ?float, 'href' => ?string]`, max 10

- [x] **Step 1: Write the failing test**

Covering: aging buckets place a line by `due_date` with the December-of-`year` fallback; exempt lines are excluded; the cash flow series always has 12 labelled points even with no data; alerts fire for overdue rentals, low stock, negative balance and members unpaid over 60 days; the activity feed merges sources, sorts newest first and caps at 10.

- [x] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter=OverviewStatsTest`
Expected: FAIL — class not found

- [x] **Step 3: Write the implementation**

- [x] **Step 4: Run tests**

Run: `composer test -- --filter=OverviewStatsTest`
Expected: PASS

- [x] **Step 5: Commit**

```bash
git add app/Services/Dashboard/OverviewStats.php tests/Feature/Dashboard/OverviewStatsTest.php
git commit -m "feat(dashboard): overview stat provider"
```

---

## Task 4: Controller rewrite with lazy tabs, permissions and budgets

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Create: `tests/Feature/Dashboard/DashboardPageTest.php`
- Delete: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: `HeroStats`, `OverviewStats`, `DashboardFilters`.
- Produces props: `filters`, `branches`, `hero`, `overview` (optional), `finance` (optional, empty in this phase), `members` (optional, empty), `operations` (optional, empty).

- [x] **Step 1: Write the failing test**

Covering: the page renders with `filters` and `hero`; `overview` is absent from a plain visit and present on `?tab=overview` partial reload; a user without `finance.view` gets an empty finance payload even when requesting it directly; the hero costs ≤ 6 queries and overview ≤ 8, asserted with `DB::listen`.

- [x] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter=DashboardPageTest`
Expected: FAIL

- [x] **Step 3: Rewrite the controller**

- [x] **Step 4: Delete the superseded test**

```bash
git rm tests/Feature/DashboardTest.php
```

- [x] **Step 5: Run the whole suite**

Run: `composer test`
Expected: PASS, no regressions

- [x] **Step 6: Commit**

```bash
git add app/Http/Controllers/DashboardController.php tests/Feature/Dashboard/DashboardPageTest.php
git commit -m "feat(dashboard): thin controller over stat providers with lazy tab props"
```

---

## Task 5: Chart theme module

**Files:**
- Create: `resources/js/lib/chartTheme.js`
- Modify: `resources/js/lib/registerCharts.js`

**Interfaces:**
- Produces: `palette(mode)`, `seriesColor(slot, mode)`, `MONEY_IN`, `MONEY_OUT`, `sequentialRamp(steps, mode)`, `applyChartDefaults()`, `baseOptions({ rtl })`, `moneyTooltip(formatMoney)`.

- [x] **Step 1: Write the module**

Exports the two palettes verbatim from Global Constraints, reads `--surface`/`--muted-foreground` from CSS custom properties at call time so theme switches are picked up, sets Chart.js defaults (font stack, hairline solid gridlines, no dashes, `pointRadius: 0` with `pointHoverRadius: 4`, `borderWidth: 2`, bar `borderRadius: 4` with `borderSkipped: 'start'`, `maxBarThickness: 24`), and flips `reverse` on the category scale plus legend/tooltip alignment when `rtl` is true.

- [x] **Step 2: Register the extra Chart.js pieces**

Add the elements the Overview charts need to `registerCharts.js`.

- [x] **Step 3: Build to verify no import errors**

Run: `npm run build`
Expected: build succeeds

- [x] **Step 4: Commit**

```bash
git add resources/js/lib
git commit -m "feat(dashboard): validated chart palette and shared Chart.js theme"
```

---

## Task 6: Dashboard component library

**Files:**
- Create: `resources/js/Components/Dashboard/{StatTile,Sparkline,DeltaBadge,ChartCard,Meter,AlertChip,DashboardFilterBar}.vue`

**Interfaces:**
- `StatTile` props: `label: String`, `value: [Number,String,null]`, `format: 'number'|'money'|'percent'`, `delta: Object|null`, `spark: Array`, `icon: String`, `meta: String|null`, `href: String|null`
- `Sparkline` props: `points: Array`, `tone: 'positive'|'negative'|'neutral'`
- `DeltaBadge` props: `delta: Object|null`, `periodLabel: String`
- `ChartCard` props: `title: String`, `subtitle: String|null`, `empty: Boolean`, `loading: Boolean`; slots `default`, `actions`, `table`
- `Meter` props: `value: Number`, `max: Number`, `label: String`
- `AlertChip` props: `label: String`, `count: Number`, `severity: String`, `href: String|null`
- `DashboardFilterBar` props: `filters: Object`, `branches: Array`; emits nothing — it drives the URL through `useListFilters`

Sparse-data rules are part of these contracts: `StatTile` renders `—` for a null value, hides the delta and sparkline when `delta` is null, and `ChartCard` renders its empty state rather than an empty axis.

- [x] **Step 1: Write the components**

- [x] **Step 2: Build**

Run: `npm run build`
Expected: succeeds

- [x] **Step 3: Commit**

```bash
git add resources/js/Components/Dashboard
git commit -m "feat(dashboard): shared stat tile, sparkline, chart card and filter bar"
```

---

## Task 7: Dashboard shell and Overview tab

**Files:**
- Modify: `resources/js/Pages/Dashboard.vue`
- Create: `resources/js/Pages/Dashboard/Partials/OverviewTab.vue`

- [x] **Step 1: Rewrite `Dashboard.vue` as the shell**

Header, `DashboardFilterBar`, hero row of six `StatTile`s, tab switcher that calls `router.reload({ only: [tab] })` and caches loaded tabs in a `reactive` map. Tabs the user cannot view are not rendered.

- [x] **Step 2: Write `OverviewTab.vue`**

Cash flow `ChartCard` (bar + line, one axis), debt aging horizontal stacked bar, alert strip of `AlertChip`s, activity feed list. Every chart gets a table toggle.

- [x] **Step 3: Build**

Run: `npm run build`
Expected: succeeds

- [x] **Step 4: Commit**

```bash
git add resources/js/Pages/Dashboard.vue resources/js/Pages/Dashboard
git commit -m "feat(dashboard): tabbed shell with hero row and overview tab"
```

---

## Task 8: Translations and verification

**Files:**
- Modify: `resources/js/i18n/{ar,fr,en}.json`

- [x] **Step 1: Add every new key to all three locale files**

- [x] **Step 2: Run the i18n check**

Run: `npm run i18n:check`
Expected: no missing keys

- [x] **Step 3: Run the full suite and linters**

Run: `composer test && vendor/bin/pint --test && npm run build`
Expected: all pass

- [x] **Step 4: Commit**

```bash
git add resources/js/i18n
git commit -m "feat(dashboard): translations for the redesigned dashboard"
```

---

## Out of scope for Phase 1

Finance, Members and Operations tab content (their props exist but return empty), and the five module stat strips. Those are Phase 2 and Phase 3, each with its own plan.
