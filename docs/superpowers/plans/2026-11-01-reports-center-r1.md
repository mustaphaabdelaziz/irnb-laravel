# Reports Center R1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Reports section (sidebar + hub page + generic report viewer with xlsx/csv/PDF) with 4 pilot reports and links to every existing report.

**Architecture:** One PHP class per report (`app/Reports/**`) declaring filters, columns, rows, summary and charts; a registry lists them plus link cards to existing reports; one controller renders the hub, the viewer and the download; one Vue viewer renders any report. Read-only queries only, existing helpers reused (`Export`, `PdfService`, `ClubHeader`, `UiLang`, `ModuleStats`, `CompetitionRecord`, `FiscalPeriod`, `Season`).

**Tech Stack:** Laravel 13, Inertia + Vue 3 `<script setup>`, chart components already in `resources/js/Components` (StatBars, StatDoughnut, Dashboard/StatTile), mPDF, hand-written XlsxWriter, PHPUnit feature tests (class style, `#[Test]`), `node --test` for nav helpers.

Spec: `docs/superpowers/specs/2026-11-01-reports-center-design.md`.

## Global Constraints

- No migration, no new composer/npm dependency, no CDN asset.
- No change to existing routes, screens, permissions or the `reports` module; `reports.financial` keeps working at `/reports/financial`.
- Reports are read-only (SELECT only).
- Access per report = source module view right, enforced in the controller on view **and** download.
- Every UI string in `resources/js/i18n/{ar,fr,en}.json` (flat dotted keys, added with `node scripts/i18n-add.mjs`); server text through `UiLang::get()`; `t()` directly, never `te()` on dotted keys.
- Player names via `Player::fullname` / `NAME_COLUMNS`.
- Finance counted transactions = `archived = false`; season semantics identical to `TransactionController::applyFilters` (absent season + no dates ⇒ current `FiscalPeriod` key).
- Equipment: assignments and rentals never summed under one label; asset value formula identical to `EquipmentItemController::inventory`.
- SQL portable between SQLite (tests, desktop) and MySQL (web): no `strftime`, use `SUBSTR(col, 1, 7)` for months.
- Stage explicit files only; work in worktree `D:\irnb-reports` on branch `feat/reports-r1` with a copied `vendor`.

## File structure

```
app/Reports/Filter.php            filter definition + validation rules + toArray
app/Reports/Column.php            column definition (key, i18n label, type, total) + display()
app/Reports/ReportFilters.php     validated values from a request (+ sort/dir/per_page) + describe()
app/Reports/Report.php            abstract base class
app/Reports/ReportRunner.php      sort + paginate (Builder or Collection), all rows for export, totals
app/Reports/ReportExporter.php    xlsx/csv via Export, PDF via PdfService + pdf.report view
app/Reports/ReportRegistry.php    report classes + link cards, per-user filtering
app/Reports/Players/PlayerDirectory.php
app/Reports/Finance/FinanceSummary.php
app/Reports/Equipment/EquipmentInventory.php
app/Reports/Competitions/CompetitionResults.php
app/Http/Controllers/ReportsCenterController.php
resources/views/pdf/report.blade.php
resources/js/Pages/Reports/Index.vue      hub + category (same page, optional category)
resources/js/Pages/Reports/Show.vue       viewer
resources/js/Components/Reports/ReportFilters.vue
resources/js/Components/Reports/ReportTable.vue
resources/js/lib/reportPrefs.js           pinned + recent (localStorage, try/catch)
routes/web.php, config/permissions.php, resources/js/Composables/useNavigation.js,
resources/js/lib/navigation.js, resources/js/Components/Icon.vue, i18n catalogs   (modified)
tests/Feature/Reports/ReportsCenterTest.php
tests/Feature/Reports/PlayerDirectoryReportTest.php
tests/Feature/Reports/FinanceSummaryReportTest.php
tests/Feature/Reports/EquipmentInventoryReportTest.php
tests/Feature/Reports/CompetitionResultsReportTest.php
tests/js/navigation.test.mjs (modified)
```

## Interfaces (shared by all tasks)

```php
// Filter
Filter::select(string $key, string $label, array $options, mixed $default = null): Filter   // options: list<array{value:int|string,label:string}>
Filter::multi(string $key, string $label, array $options): Filter
Filter::date(string $key, string $label): Filter
Filter::number(string $key, string $label, int $min = 0, int $max = 150): Filter
$filter->rules(): array; $filter->toArray(): array; $filter->key, ->type, ->label, ->options, ->default

// Column
new Column(string $key, string $label, string $type = 'text', bool $total = false)   // type: text|number|money|date|percent
$column->display(mixed $value, string $currency): string; $column->toArray(): array

// ReportFilters
ReportFilters::fromRequest(Request $request, Report $report): ReportFilters   // throws ValidationException
$f->get(string $key): mixed; $f->filled(string $key): bool; $f->sort: ?string; $f->dir: 'asc'|'desc'; $f->perPage: int
$f->values(): array; $f->describe(): list<array{label:string,value:string}>

// Report (abstract)
key(): string; category(): string; module(): string; id(): string ("category.key")
title(): string (i18n key "reports.{category}.{key}.title"); description(): string (".description")
filters(): list<Filter>; columns(): list<Column>
rows(ReportFilters $f): Builder|Collection; row(mixed $record): array<string,mixed>
sortable(): array<string,string>  (column key => SQL expression for Builders, or => column key for Collections)
defaultSort(): array{0:?string,1:string}; summary(ReportFilters $f): list<array{label:string,value:mixed,format:string}>
charts(ReportFilters $f): list<array{type:'bar'|'doughnut',title:string,stats:list<array{key:string|int,label:string,count:float|int}>,unit?:?string}>
totals(ReportFilters $f): array<string,mixed>; landscape(): bool; allows(User $u): bool

// ReportRunner
page(Report $r, ReportFilters $f): LengthAwarePaginator (items = row() arrays)
all(Report $r, ReportFilters $f): iterable<array<string,mixed>>

// ReportExporter
download(Report $r, ReportFilters $f, string $format): Response   // format xlsx|csv|pdf

// ReportRegistry
CATEGORIES = ['players','finance','equipment','competitions','attendance']
find(string $category, string $key): ?Report
cards(User $u, ?string $category = null): list<array{kind:'report'|'link',id:string,category:string,title:string,description:string,href:string,external:bool,icon:string}>
categoryModules(): array<string,list<string>>
```

Routes: `reports.hub` GET `/reports`, `reports.category` GET `/reports/{category}`, `reports.view` GET `/reports/{category}/{report}`, `reports.download` GET `/reports/{category}/{report}/download`; `{category}` constrained to the five keys; declared after `reports.financial`; the four names added to `config/permissions.php` `unguarded`.

---

### Task 1: Report engine (Filter, Column, ReportFilters, Report, ReportRunner)

**Files:** Create `app/Reports/{Filter,Column,ReportFilters,Report,ReportRunner}.php`; Test `tests/Feature/Reports/ReportsCenterTest.php` (engine part, uses an in-test anonymous Report over players).

- [ ] Write failing tests: filter validation rejects an unknown option (422/redirect with errors), rejects `to` before `from`, rejects `sort` not in `sortable()`, rejects `per_page=7`; defaults applied when absent; runner sorts a Builder by whitelisted expression and paginates; runner sorts and paginates a Collection.
- [ ] Run `php artisan test --filter=ReportsCenterTest` → FAIL (classes missing).
- [ ] Implement the five classes per the interfaces above.
- [ ] Re-run → PASS. Commit `feat(reports): report engine`.

### Task 2: Registry, controller, routes, permissions, export, PDF template

**Files:** Create `ReportRegistry.php`, `ReportExporter.php`, `ReportsCenterController.php`, `resources/views/pdf/report.blade.php`; Modify `routes/web.php`, `config/permissions.php`; Tests in `ReportsCenterTest.php`.

- [ ] Failing tests: hub lists only cards the user may open (player-only role sees players cards and no finance cards); `/reports/finance` 403 for that role; `/reports/finance/summary` and its download 403; unknown report 404; `/reports/financial` still streams the season PDF (existing behaviour); god admin sees all five categories; download xlsx returns the spreadsheet content type; download pdf passes HTML containing the report title and applied filters to `PdfService::stream` (mocked like `ReportPdfTest`).
- [ ] Implement registry (link cards: players list, academic results via players page; finance overview, transactions, season summary PDF [module `reports`]; equipment inventory page, stock-take; match stats; attendance stats, alerts, ranking, injuries), controller, routes, unguarded names, exporter, PDF view (club header partial, title, period/filters line, generated at + user, KPI line, table with `<thead>`, totals row, footer page numbers via `<htmlpagefooter>`; RTL when ar; PDF capped at 2000 rows with a note).
- [ ] Re-run → PASS. Commit `feat(reports): hub, viewer routes, downloads`.

### Task 3: Pilot reports

**Files:** Create the four report classes; tests `tests/Feature/Reports/{PlayerDirectory,FinanceSummary,EquipmentInventory,CompetitionResults}ReportTest.php`.

- Player directory: filters category (multi), branch (multi), gender (Male/Female), status (multi), age_min/age_max, join_year, archived (0 default / 1); columns membership_id, name (fullname), birthdate, age, gender, phone (first), category, branches, status, join_year; summary total / boys / girls / average age; charts by category (bar), by gender (doughnut). Tests: filters narrow rows; archived excluded by default; age bounds inclusive; sort by name.
- Finance summary: filters season (FiscalYear years + current, default current), from/to (when set, season ignored unless explicitly sent — same as Transactions), caisse (multi, opening fund excluded), payment_method (multi of stored codes); rows = one per (type, category) with entries, income, expense; totals income/expense; summary income/expense/net from `ModuleStats::transactions()` tiles 0–2 + entries; charts monthly (SUBSTR month), by payment method, by caisse. Tests: archived transactions excluded; totals equal the Transactions strip for the same filters; date range overrides season.
- Equipment inventory: filters category (equipment category names), status (stored codes), condition, location, branch (scopeForBranch semantics); columns catalog, category, designation, serial, quantity (total), condition, status, location, branches, unit_price (item price else catalog price), value, purchase_date; summary lots / units / units out (open rentals within filter, assignments and rentals split) / asset value (Inventory page formula); charts units by status, units by category. Tests: value fallback to catalog price; lost/retired excluded from asset value; rentals vs assignments counted separately.
- Competition results: filters season (Season::options, default current), team, competition, side; rows played games only; columns date, team, competition, round, opponent, side, score (with shootout), result W/D/L; summary from `CompetitionRecord::fromGames`; chart W/D/L doughnut. Tests: scheduled/cancelled excluded; record matches CompetitionRecord; team filter.
- [ ] TDD per report, register each in the registry, commit `feat(reports): pilot reports`.

### Task 4: Frontend (nav, hub, viewer, i18n)

**Files:** Modify `useNavigation.js`, `lib/navigation.js` (`modules` any-of gating), `Icon.vue` (`chart` glyph), i18n catalogs; Create `Pages/Reports/{Index,Show}.vue`, `Components/Reports/{ReportFilters,ReportTable}.vue`, `lib/reportPrefs.js`; Test `tests/js/navigation.test.mjs`.

- [ ] Failing JS test: an item with `modules: ['finance','subscriptions']` shows when `can('subscriptions','view')` only; hidden when none.
- [ ] Implement gating; Reports section (Overview `/reports` exact, then 5 categories with their module lists from `ReportRegistry::categoryModules()` mirrored in the nav).
- [ ] Hub/category page: category cards (icon, name, description, count) on the hub; report cards grouped by category; pinned + recent from `reportPrefs.js`.
- [ ] Viewer: header (title, description, applied filters, generated at), filter form (Generate / Reset; query string via `router.get` with `preserveState`), StatTile strip, chart/table toggle (StatBars/StatDoughnut), ReportTable (sortable headers, numeric alignment, money via existing currency composable, totals row, empty state, Pagination), ExportMenu xlsx/csv/pdf; `print:hidden` on chrome.
- [ ] `npm run test:js`, `npm run i18n:check`, `npm run build` → all green. Commit `feat(reports): reports menu, hub and viewer`.

### Task 5: Verification

- [ ] Full `php artisan test`, `npm run test:js`, `npm run i18n:check`, `npm run build`, `vendor/bin/pint --test` on touched PHP files.
- [ ] Drive the app (127.0.0.1:2026 per owner setup) in ar and fr: hub, each pilot, each download format.
- [ ] Commit fixes; report to owner; merge only on owner's go.
