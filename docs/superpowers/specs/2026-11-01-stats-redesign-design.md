# Statistics redesign — design

Date: 2026-11-01. Status: approved by owner (decision rounds 2026-11-01), branch `feat/stats-redesign` (worktree `D:\irnb-stats`).
Source brief: owner's "Enterprise-grade statistics & analytics redesign" master prompt (pasted 2026-11-01), applied to the real architecture.

## 1. Audit summary

Stack: Laravel 13, Inertia v2 + Vue 3 `<script setup>`, Tailwind v3 with shadcn-style CSS-variable tokens, `.dark` class dark mode, RTL-first (logical properties). chart.js 4.5 + vue-chartjs 5 are the only chart libraries — no new library. Export: `App\Support\Export` (own xlsx/csv writers) + mPDF, surfaced by `ExportMenu.vue`. Desktop build runs offline (no CDN, ever).

Already in place (the redesign evolves these, it does not replace them):

| Need | Existing piece |
|---|---|
| Chart frame + chart/table toggle + loading + empty | `Components/Dashboard/ChartCard.vue` |
| KPI card with delta + sparkline | `Components/Dashboard/StatTile.vue`, `DeltaBadge.vue`, `StatStrip.vue` |
| Previous-period delta (null when no previous value) | `App\Support\DeltaCalculator` (backend only) |
| Palette, RTL axes, dark, money tooltips | `resources/js/lib/chartTheme.js` (`baseOptions`, `lineDataset`, `barDataset`, `seriesColor`) |
| Export | `ExportMenu.vue` + `Export::download` |

### Inventory

| Module / page | Charts | Table toggle | chartTheme | KPI style |
|---|---|---|---|---|
| Dashboard hero + 4 tabs | ~10 bar/line/mixed | most; By age + Rentals have none, category tables lack `<thead>` | yes | StatTile |
| Attendance/Stats | 2 bar | yes | yes | hand-written `card p-4` |
| Users/Activity Index + Show (`ActivityCharts`) | 4 | yes | yes | computed in browser |
| Finance/Index | line, 2 doughnut, budget bars | no | no (hex colours) | `StatCard` |
| Board/Tasks stats tab | 2 doughnut (`StatDoughnut`), 2 bar | no | no (hex colours) | hand-written |
| Players/Show → AttendanceSection | doughnut + stacked bar | no | partly (StatDoughnut own palette) | `bg-slate-50` tiles |
| Players/Show → AcademicSection | line | grades table beside it | yes | `bg-slate-50` tiles |
| Players/Index, Transactions/Index | StatBars (CSS) | n/a | n/a | StatStrip |
| Subscriptions/Show, Equipment Inventory/History, Board/Index, Finance/Registers, Inventory Index/Session | none | n/a | n/a | `StatCard` / hand-written |
| Games/Stats, Teams, Competitions, Attendance Ranking/Alerts/Injuries | none (tables, leaders) | n/a | n/a | mixed — **out of scope** |

Gaps: 4 KPI styles; 2 chart pages bypass the theme and lack tables; no shared stats table; no error state anywhere (no `onError`); charts don't recolour on a live dark toggle; uneven export.

## 2. Owner decisions (2026-11-01)

1. **Architecture**: evolve ChartCard / StatTile / chartTheme; no parallel StatisticsPanel family.
2. **Scope**: all chart pages + replace every old KPI tile (`StatCard`, hand-written tiles) with StatTile. Games/Teams/Competitions/Ranking/Alerts/Injuries tables stay as they are.
3. **Chart types**: one fixed best type per dataset + chart/table toggle. No chart-type switcher.
4. **New previous-period comparisons** (only on period figures — snapshots such as stock value, on-loan, balances have no history and get no badge):
   - Finance season page: income / expense / net vs previous season.
   - Dashboard Finance tab: avg transaction, largest expense, burn rate vs previous window.
   - Attendance stats: attendance %, late minutes, missed hours, sessions held vs previous period.
   - Treasury hero tile: follow the selected range (today it compares the last two months whatever the range).
5. **Filters**: shared `StatsToolbar` visual shell around each page's existing filter logic; no new period model, no backend filter changes.
6. **Export**: per-panel CSV built client-side from the exact table rows (UTF-8 BOM). Existing `ExportMenu` exports unchanged.
7. **Fix `board.tasks`**: route derives to board → edit (no override, "tasks" not a view verb in `PermissionMap::deriveAction`). Add `'board.tasks' => ['board', 'view']`; task mutations keep their edit guard.
8. **New: document delivery completion** ("installation progress bar" of how much of the team's documents are delivered):
   - Metric: big bar = documents received / documents required; line under it = "N of M players have a complete file". Same rules as the player checklist: expired counts as missing, exempt / not required / inactive types excluded, Photo satisfied by profile picture.
   - Shown on: Dashboard Members tab (club-wide, per category, per document type; follows the branch filter), Players list (follows the list's active filters), Teams/Show (season roster).
   - Gated by `documents → view` everywhere, like the player checklist.
9. **One shared table component**: this branch builds `StatTable`; the Reports Center session (spec `2026-11-01-reports-center-design.md`) uses it instead of writing its own `ReportTable`.

## 3. Guardrails

- No existing calculation changes. New numbers are new keys; existing keys keep shape and meaning.
- Money stays behind Finance → view (`DashboardController::money()`); `null` = hidden, `undefined` = still loading.
- Dashboard query budgets (`DashboardPageTest`) stay green; where a new delta adds queries, measure and raise the budget deliberately in the same commit.
- Chart ⇄ table switch is client-only: no request, filters untouched. Table DOM renders only while the table is shown (desktop DOM-volume cost).
- No CDN / remote resource. i18n keys flat dotted in ar/fr/en; `npm run i18n:check` green.
- Existing ChartCard / StatTile props keep working; new props are optional.

## 4. Components

### `ChartCard.vue` (evolved, same path)
New optional props: `error` (String|Boolean — shows message + Retry, emits `retry`), `csv` (`{ filename, columns, rows }` — shows a download button in table view and chart view). Toggle becomes a labelled segmented control **Chart | Table** (`role="tablist"`, keyboard reachable) when `hasTable`. State order: loading → error → empty → table/chart. Existing callers unchanged.

### `Components/Stats/StatTable.vue` (new, shared with Reports Center)
- Props: `columns: [{ key, label, format: 'number'|'money'|'percent'|'text'|'date', align?, sortable?, total?: 'sum'|'none' }]`, `rows`, `sort` (`{ key, dir }` — optional controlled), `sortMode: 'client'|'server'` (default client), `totals` (Boolean or object of precomputed totals), `dense`, `emptyText`.
- Emits `sort` (server mode). Slot `cell-<key>` for custom cells, slot `footer` (pagination goes here — `Pagination.vue`).
- Always renders `<thead>` (sticky inside a scroll box), numeric columns `tabular-nums` aligned to the end, sort indicator with `aria-sort`, totals row only for columns with `total: 'sum'` (never for percent/averages), horizontal scroll on narrow screens, empty state.
- Formatting via existing `useFormatMoney`, `useFormatDate`, `lib/attendanceStats.pct`. Money cells = `formatMoney(v)` + muted `currencyLabel` (from `useCurrency`), same as StatTile; CSV keeps the raw number and appends the currency label to that column's header.
- `totals` object is keyed by column `key` (server-computed totals for paginated data); with `totals: true` and client rows, sums columns marked `total: 'sum'`.
- Reports Center R1 consumes it (confirmed 2026-11-01): server sort, precomputed totals, all five formats, footer pagination slot, empty state. It rebases `feat/reports-r1` onto this branch once StatTable + statTable.js + ChartCard toggle are committed — tell that session when they are.

### `lib/statTable.js` (new, pure)
`sortRows(rows, key, dir)` (null-last, locale-aware text compare), `sumColumn(rows, key)`, `toCsv(columns, rows)` — mirrors `App\Support\Export\CsvWriter`: UTF-8 BOM, CRLF, comma delimiter, RFC 4180 quoting, same formula-injection escaping for string cells (`= + - @` tab/CR prefixes), raw numbers rather than localized strings, header row = translated column labels, `downloadCsv(filename, csv)` (Blob + object URL, revoked). Unit tests in `tests/js/statTable.test.mjs`.

### `Components/Stats/StatsToolbar.vue` (new)
Shell: default slot for the page's filter controls, `activeCount` prop → badge, `resettable` + emits `reset`, `loading` spinner. Wraps (does not replace) `PeriodFilter`, `BranchSwitch`, `DashboardFilterBar` contents, Finance year tabs, Attendance category select.

### `Components/Stats/CompletionBar.vue` (new)
Props: `received`, `required`, `label`, `caption`, `size: 'lg'|'sm'`, `href?`. Shows % (one decimal, `—` when required = 0, never "0%" for nothing-to-deliver), `received / required`, tone by % (danger < 50, warning < 80, success ≥ 80), `role="progressbar"` with aria values.

### `StatTile.vue` — single KPI card
Unchanged API; new tiles use it. `StatCard.vue` deleted once unused (Phase 4).

### `Composables/useChartTheme.js` (new)
Returns a reactive `themeVersion` bumped by a `MutationObserver` on `<html>` class/`data-theme`; pages fold it into their `computed` chart options so charts recolour on toggle. Observer disconnected on unmount.

## 5. Backend additions

- **Finance season deltas** — `FinanceController::index` adds `detail.deltas = { income, expense, net }` via `DeltaCalculator` against the previous season row (UP_GOOD income/net, DOWN_GOOD expense); null when no previous season.
- **Dashboard Finance tab** — `FinanceStats` summary items gain `delta` for avg_transaction (neutral tone), largest_expense and burn_rate (DOWN_GOOD) over `DashboardFilters` previous window; none when compare is off. Runway stays without delta (derived from a snapshot).
- **Treasury hero delta** — compare treasury balance at end of current window vs end of previous window (opening + counted transactions up to the date), not last two sparkline months.
- **Attendance deltas** — `AttendanceStats::summarize` re-run on the previous window of the same length (month → previous month, season → previous season, custom → same-length window ending the day before). Timed with `scripts/perf/scale.php`; if it doubles page time at 1,890 players, compute only the four needed figures.
- **Document completion** — `DocumentChecklist::expectedCountSql()` (SQL twin of "required and applicable" per player) next to `missingCountSql()`; `DocumentChecklistTest` extended so PHP and SQL agree on expected counts too. New `App\Services\Player\DocumentStats`: `summary(Builder $players)` → `{ required, received, pct, players_complete, players_total }`, `byCategory(Builder)`, `byType(Builder)`. Callers pass their already-filtered player query (dashboard branch, players list filters, team roster for the season). Aggregates in SQL, no per-player PHP loop.
- **Permission** — `config/permissions.php`: `'board.tasks' => ['board', 'view']`.

## 6. Phases (one plan each, merged after browser QA)

1. **Foundation + Finance/Index pilot** — ChartCard evolution, StatTable, statTable.js (+tests), StatsToolbar, useChartTheme. Finance/Index: chartTheme colours, ChartCard with tables for monthly line, both doughnuts, budget; StatTile row with season deltas; all-years table on StatTable; per-panel CSV. **Stop for owner check before Phase 2.**
2. **Migrate chart pages** — Dashboard tabs (missing tables, StatTable everywhere, Finance-tab deltas, treasury fix, error state on tab/filter reload), Attendance/Stats (StatTile + deltas), Board/Tasks stats (theme, tables, StatDoughnut onto chartTheme palette, `board.tasks` fix), Activity, player AttendanceSection / AcademicSection.
3. **Document delivery** — expectedCountSql + DocumentStats + CompletionBar on Dashboard Members tab, Players list, Teams/Show.
4. **KPI sweep** — StatCard / hand-written tiles → StatTile on Subscriptions/Show, Equipment Inventory + History, Board/Index, Finance/Registers, Inventory Index + Session; delete StatCard.

## 7. Testing

- PHPUnit: deltas (values, null when no previous period, tone), treasury follows range, attendance previous window boundaries, DocumentChecklist expected-count agreement, DocumentStats (exempt, expired, photo, inactive type, branch isolation, zero required), documents/view gate on all three placements, `board.tasks` view-only access, dashboard query budgets, money gate unchanged.
- `npm run test:js` (statTable.js), `npm run build`, `npm run i18n:check`, `php artisan test`.
- Browser QA at 127.0.0.1:2026 per phase: chart/table switch keeps filters, CSV opens in Excel with Arabic intact, RTL + dark, mobile width (no page-level horizontal scroll), console clean.

## 8. Coordination

Peer session building Reports Center (same repo, `D:\irnb-laravel`): informed that `Components/Stats/StatTable.vue` (+ `lib/statTable.js`) lands here first and supports server sort + pagination slot for its viewer; ChartCard changes are backward compatible. Both branches touch i18n catalogs and possibly `Icon.vue` — merge with care, never sweep the other session's keys (see git staging discipline).
