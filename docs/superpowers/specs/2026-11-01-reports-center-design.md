# Reports Center — design

Date: 2026-11-01. Status: draft, awaiting owner approval.
Source brief: owner's "Enterprise Reports & BI module" master prompt (pasted 2026-11-01), applied to the real architecture: "use the existing architecture, take inspiration, build the reports menu without breaking anything".

## 1. Audit summary (Phase 1)

Stack: Laravel 13, Inertia + Vue 3 (`<script setup>`), no vue-router/pinia, chart.js 4 + vue-chartjs, mPDF (Arabic shaping), hand-written `XlsxWriter` (desktop PHP has no xmlwriter → PhpSpreadsheet impossible), vue-i18n flat JSON catalogs ar/fr/en reused server-side via `UiLang`. Desktop build runs offline (NativePHP).

Reusable pieces already in place:

| Need | Existing piece |
|---|---|
| xlsx / csv | `App\Support\Export::download($format, $filename, $headers, $rows, $title)` (RTL sheet for ar) |
| PDF | `App\Services\Pdf\PdfService::stream($html, $name, $rtl, $landscape)`, `ClubHeader::data()`, `resources/views/pdf/partials/header.blade.php` |
| Export button | `Components/ExportMenu.vue` (`:formats="['xlsx','csv','pdf']"`) |
| KPI tiles / charts | `Components/Dashboard/{StatTile,StatStrip,ChartCard}.vue`, `StatBars.vue`, `StatDoughnut.vue` |
| Filters | `MultiSelectFilter`, `SearchableSelect`, `Competitions/SeasonSelect`, `App\Support\Season`, `Services\Activity\ActivityPeriod` (month/season/custom), `Services\Attendance\BranchChoice` |
| Nav + Ctrl+K | `Composables/useNavigation.js` sections → `lib/navigation.js` (gated by `can(module,'view')`) → `CommandPalette` |
| RBAC | `config/permissions.php` + `PermissionMap` (route-name derived) + `User::hasPermission` + `useCan` |
| History | `ActivityLog` (route-mapped activity tracking, users batch Oct) |
| Player name | `Player::fullname` / `NAME_COLUMNS` (see player-name-format rule) |

Existing reports (stay where they are, unchanged): players list xlsx/PDF, board table, academic results, member card, academic report; season financial summary PDF (`reports.financial`), transactions xlsx, per-subscription xlsx, receipts; catalog/items xlsx, Equipment Inventory page (not in nav), stock-take xlsx + PDF; match stats xlsx/PDF, match sheets; attendance stats/alerts/ranking/injuries/certificates/letters/sheets; board members/tasks xlsx, minutes.

Gap: no single place to find them, and many useful cross-cutting reports don't exist (sections 4–8).

### Brief items with no data behind them (not built, documented)

| Brief item | Reality | What we do instead |
|---|---|---|
| Membership expiration | No membership validity period exists; subscriptions are season debts | Outstanding payments report + document expiry report |
| Medals & rankings, individual results, disciplines | Team-sport model (games, scores, events); no medals; league table absent by design (`CompetitionRecord`) | Results, W/D/L record, scorers (existing stats), discipline |
| Rental cost / rental payment status | `equipment_rentals` has no price field | Rental report without cost columns |
| Maintenance cost / provider / next date | Only item `status = under repair` + `equipment_histories` events | "Under repair & lifecycle" report from status + history |
| Competition location / participants registration date | Competitions have team, season, type, format; squad pivot without dates | Overview uses team, season, type, games, record, squad size |
| Coach as an entity on sessions | `training_sessions.coach` is free text | Sessions grouped by that text, labelled as such |
| Monthly registrations | `players.join_year` is year-only; `created_at` = data-entry date, not joining | Joins per season by `join_year`; leavers by `left_at` |
| Saved configurations, scheduled/background exports, stored report files | Not justified: exports are synchronous and small; desktop is offline | Not built |

## 2. Decisions (taken by Claude on the owner's instruction to follow the existing architecture)

1. **Menu**: new sidebar section **Reports** (icon: new `chart` glyph in `Icon.vue`) with items *Overview* + one per category (*Players, Finance, Equipment, Competitions, Attendance*). Each category item is gated by its source module's view right, so the sidebar stays short (≤ 6 items); Ctrl+K finds the category entries (per-report search entries can follow later). A category item shows when the user can view any module behind it (`modules: [...]` on the nav item).
2. **Access** follows the source module, no new permission rows: Players → `players.view` (documents report → `documents.view`), Finance → `finance.view` (subscription reports → `subscriptions.view`), Equipment → `equipment.view` (stock-take report → `inventory.view`), Competitions → `games.view`, Attendance → `attendance.view`. Checked server-side per report on view **and** export; the hub hides what the user can't open. The existing `reports` module and `reports.financial` route are left exactly as they are.
3. **Existing reports are linked, not moved**: the hub shows them as cards that open their current page/PDF, with their current permission. No module page loses a button.
4. **Read-only**: report classes only run SELECTs. No migration in this batch.
5. **History & favorites** = per-viewer lists in `localStorage` (recently opened reports + pinned reports, try/catch, page works without it). Not `ActivityLog`: activity tracking records edits only (`RecordRouteActivity`), and exports would inflate its counts and charts. No new tables.
6. **Print** = the PDF (desktop has no reliable browser print); screen view also gets `print:hidden` on chrome for web users.
7. **Finance numbers reuse existing predicates/services** (same "counted transaction" filter as `TransactionController` stats / `FinanceController::yearDetail`, cash basis, subscriptions = debt not income, transfers excluded from income/expense). Every finance report gets a test that reconciles its totals against the existing screen for the same filters.
8. The "Donations" card bug in `ReportController::financialSummary` (legacy `category='donation'` slug) is **reported, not fixed here** — separate small fix if the owner wants it.

## 3. Architecture

### Backend

```
app/Reports/
  Report.php              abstract: key(), category(), module(), action='view',
                          filters(): FilterDef[], columns(): ColumnDef[],
                          query(ReportFilters): Builder|Collection,
                          summary(ReportFilters): array (KPI tiles),
                          chart(ReportFilters): ?array, sortable(): string[],
                          landscape(): bool
  ReportRegistry.php      list of report classes + link entries (existing reports);
                          forUser(User) filters by permission
  ReportFilters.php       validates request against the report's FilterDefs
                          (dates, ids exist, enum values); defaults (current season)
  ReportExporter.php      rows → Export::download (xlsx/csv) or PdfService + pdf/report.blade.php
  Players/…, Finance/…, Equipment/…, Competitions/…, Attendance/…   one class per report
app/Http/Controllers/ReportsCenterController.php
  index()                       hub
  category(string $category)    list of reports in a category
  show(string $key)             viewer (paginated rows, summary, chart)
  export(string $key)           ?format=xlsx|csv|pdf, full rows, same filters
```

Routes (inside the existing `auth, approved, permission` group): URLs `/reports`, `/reports/{category}`, `/reports/{category}/{key}`, `/reports/{category}/{key}/export`; names `reports.hub`, `reports.category`, `reports.view`, `reports.download`. These four names go in `config/permissions.php` → `unguarded` at route level, because authorization is per report inside the controller (abort 403 if `!$report->allows($user)`, on view and export alike). `{category}` is constrained to the five category keys so `/reports/financial` (declared first, keeps its `reports/view` override) is never captured.

Pagination: viewer pages rows server-side (25/50/100), sorting only on whitelisted columns. Summary + chart computed with DB aggregates, not from loaded rows. Exports stream the full filtered set (`cursor()`/chunks); PDF capped with a clear message above N rows (suggest xlsx).

### Frontend

```
Pages/Reports/Hub.vue        category cards (icon, name, description, count), pinned, recent
Pages/Reports/Category.vue   report cards of one category (new + linked existing)
Pages/Reports/Show.vue       generic viewer:
  Components/Reports/ReportHeader.vue    title, description, period, applied filters, generated at
  Components/Reports/ReportFilters.vue   renders FilterDefs (date range / season / multi-select / select / number range)
                                         Generate + Reset; filters live in the query string (Inertia GET, preserveState)
  StatStrip/StatTile (existing)          summary
  Components/Reports/ReportTable.vue     sortable headers, numeric/currency/date alignment, totals row, empty state, Pagination
  ChartCard/StatBars/StatDoughnut        chart; Chart ⇄ Table toggle on the same data
  ExportMenu (existing)                  xlsx / csv / pdf
```

Column/filter labels come from i18n keys declared by the report class (`reports.<key>.*`), resolved on the client with `t()` and server-side with `UiLang::get()` for exports. Currency/number/date formatting reuses the club TZ/currency helpers already used by finance pages.

PDF template `pdf/report.blade.php`: club header partial, title, period, applied filters, generated timestamp + user, KPI line, table with `<thead>` (mPDF repeats it on each page), totals row, footer `page {PAGENO}/{nbpg}`, RTL for ar.

## 4. Report catalog

✅ = new, built from existing data. 🔗 = existing report linked in the hub.

### Players (`players.view`)
| Key | Report | Filters | Notes |
|---|---|---|---|
| `players.directory` ✅ **pilot** | Player directory | category, branch, gender, age range, status, join year, wilaya, archived | membership no., full name, birth date, age, gender, phone, category, branches, status, join year |
| `players.demographics` ✅ | Headcount & demographics | branch, status, season | category × gender, age buckets × gender, per branch, per status; charts |
| `players.movement` ✅ | Joins & leavers | season range, branch, category | joins by `join_year`, leavers by `left_at` (list + counts per season) |
| `players.documents` ✅ (`documents.view`) | Documents compliance | document type, state (missing / expiring within N days / expired), category, branch | one row per player × missing/expiring doc with `valid_until` |
| `players.birthdays` ✅ | Birthdays | month, category, branch | |
| 🔗 | Players list PDF, board table, academic results | | current pages |

### Finance (`finance.view`)
| Key | Report | Filters | Notes |
|---|---|---|---|
| `finance.summary` ✅ **pilot** | Income & expenses summary | date range or season, caisse, category, payment method | totals, net, by category, by month, by payment method, by caisse; reconciles with Transactions stats |
| `finance.journal` ✅ | Transactions journal | as above + type (income/expense) | date, title, type, category, method, caisse, player, amount, recorded by; covers the brief's Income and Expenses reports |
| `finance.cashbook` ✅ | Caisse statement (cash flow) | caisse (required), date range | opening balance, entries + transfers in/out, running balance, closing; reuses register balance logic |
| `finance.budget` ✅ | Budget vs actual | season | planned, actual, variance per category (same numbers as Finance page) |
| `finance.debtors` ✅ (`subscriptions.view`) | Outstanding payments | season, subscription, kind, branch, category, overdue only | player, phone, subscription, owed, paid, remaining, due date, days overdue |
| `finance.collection` ✅ (`subscriptions.view`) | Subscription collection | season, kind, branch | per subscription: enrolled, paid/partial/unpaid/exempt, billed, collected, rate |
| 🔗 | Season financial summary PDF, transactions export | | |

### Equipment (`equipment.view`)
| Key | Report | Filters | Notes |
|---|---|---|---|
| `equipment.inventory` ✅ **pilot** | Inventory list | catalog category, catalog, status, condition, location, branch | catalog, designation/serial, qty, condition, status, location, branch, unit price, value, purchase date |
| `equipment.out` ✅ | Equipment out | type (assignment / rental, never mixed in totals), overdue only, holder | holder, phone, item, qty out, checkout, due, days overdue |
| `equipment.valuation` ✅ | Status & valuation | branch, category | units and value by status / condition / category / branch (same numbers as Inventory page) |
| `equipment.lifecycle` ✅ | Lifecycle & movements | date range, event type, catalog | `equipment_histories` across items (repair, lost, retired, moved, assigned…) |
| `equipment.stocktake` ✅ (`inventory.view`) | Stock-take losses | date range | missing units and value per session and total |
| 🔗 | Inventory page, catalog export, stock-take PDF | | |

### Competitions (`games.view`)
| Key | Report | Filters | Notes |
|---|---|---|---|
| `competitions.results` ✅ **pilot** | Season results | season, team, competition, side | date, team, competition, opponent, H/A, score, W/D/L; summary W/D/L, GF/GA per team |
| `competitions.fixtures` ✅ | Fixtures calendar | date range, team, competition | upcoming/past list chronological |
| `competitions.discipline` ✅ | Discipline & suspensions | season, team, competition | cards per match/player, suspensions with served/remaining |
| `competitions.appearances` ✅ | Appearances & call-ups | season, team, competition | called, played, starts, minutes, not-called reasons |
| 🔗 | Match stats (scorers, assists, leaders) | | `games.stats` already full |

### Attendance (`attendance.view`)
| Key | Report | Filters | Notes |
|---|---|---|---|
| `attendance.sessions` ✅ | Sessions overview | period, branch, category, coach (text) | held / cancelled / moved per category, branch, coach text |
| 🔗 | Stats, alerts, ranking, injuries, monthly sheets | | current pages |

## 5. Phases (one branch + worktree each, desktop build at the end of each)

- **R1 — Infrastructure + pilots**: registry, filters, exporter, generic PDF template, controller + routes, hub/category/viewer pages, nav section + Ctrl+K entries, linked existing reports, activity logging, favorites, i18n ar/fr/en, the 4 pilots (`players.directory`, `finance.summary`, `equipment.inventory`, `competitions.results`).
- **R2 — Players + Finance** remaining reports.
- **R3 — Equipment + Competitions + Attendance** remaining reports.

## 6. Testing

- Pest feature tests per report: permission (403 without the module right, also on export), filter validation (bad dates, unknown ids), correct rows on seeded data, empty state.
- Finance reconciliation tests: report totals == existing screen totals for the same filters (Transactions stats, Finance year detail, Registers balances, Subscriptions stats).
- Equipment: assignments and rentals never summed together.
- Export tests: xlsx/csv headers + row count; PDF renders for ar (RTL) and fr.
- JS: `tests/js/navigation.test.mjs` extended for the new section; `npm run i18n:check` clean.
- Full suite + `npm run build` before merge; MySQL dry run for any raw SQL (avoid SQLite-only `strftime`, use portable month bucketing).

## 7. Non-goals

No new tables, no change to existing routes/screens/permissions, no new export library, no CDN assets, no background jobs, no stored report files, no saved report configurations.
