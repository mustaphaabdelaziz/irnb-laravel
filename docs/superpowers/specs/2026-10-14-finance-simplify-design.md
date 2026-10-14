# Finance: season fiscal years, simpler caisses, Transactions redesign — design

Date: 2026-10-14 · Branches: `feat/finance-season-year` (P1) → `feat/finance-caisses` (P2) → `feat/transactions-redesign` (P3), shipped as one release.

## Ask
"Do a full audit and enhance the financial system and make it easy to follow — it feels complicated, especially caisse management. Enhance the design of the Transactions page using UX/UI best practices and fix the display errors. When creating a category don't create a caisse automatically." Plus: fiscal years must follow the season (Sept → Aug). The deleted category caisses on the live data were deleted on purpose; the swimming branch's two caisses stay as they are.

## What the audit found (live data restored 2026-10-13)
- Balances reconcile: 61 000 (investment fund opening) + 50 000 income − 14 390 expenses = 96 610, all in the football coffre tree.
- **Three year conventions at once:** transactions/reports/dashboard use calendar years; `subscriptions.year` / `player_subscriptions.year` use the season **end** year; `App\Support\Season` keys by season **start** year. Result: payments received in Oct 2026 for 2026/27 are booked `fiscal_year = 2027`, while donations/expenses of the same days are booked 2026; the PDF summary (`whereYear(transaction_date)`) disagrees with the Finance page (`fiscal_year`).
- `TransactionController@store` takes `now()->year`, not the date's year; `update` never re-derives it.
- Closed years are enforced only in `TransactionController`; player payments, imports, equipment purchases and the observer write into closed years.
- Cash-register concepts exposed: opening fund, treasury, roll-up, category register, "other accounts", club-wide account, parent account, transfers — over three screens (Registers, Settings, Finance). "Category" means both member category and finance category.
- Display bugs: raw transliterated slugs (`msaryf_ashghal_almkr`) on the PDF summary, dashboard recent/largest lists, export and fallbacks; English pagination ("« Previous / Next »"); raw ISO dates on Show/Registers; English seeded account names ("Opening Fund", "Cash"); literal `tip` key on Registers; non-mirrored back arrows; amounts clipping on phones; no dark-mode amount colours; hidden URL filters with no control; no mobile view; `bank` option labelled `account`; inconsistent `Unpaid` badge colours.
- Payments without a finance category silently create duplicate categories ("Subscription" beside "Subscriptions").

## Decisions (owner, 2026-10-14)
1. **Order:** P1 season year → P2 caisses → P3 Transactions UI. One release.
2. **Fiscal year = season.** Stored under the season **end** year (2026/27 → `2027`, same as subscriptions), shown as "2026/2027". Start month = existing `seasonStartMonth` setting (default September). When the setting is January (calendar season), the key and label are the plain year — same rule as `Subscription::seasonLabel`.
3. **Existing data re-bucketed by date** (backup first).
4. **Caisse model:** coffre per branch (auto-created with the branch); caisses created only by hand, under a coffre or in a "club level" group, optionally linked to one member category. **Creating a category creates no caisse.** Fonds Initial hidden.
5. **One "Caisses" page in the sidebar;** Settings keeps years, budgets and a link to the finance-categories manager.
6. **"Move money" button on each caisse** (modal, pre-filled source, before/after balances) plus one global button.
7. **Leftovers:** Fonds Initial hidden everywhere (kept in DB); صندوق استثمار kept in the club-level group.
8. **Transactions page follows the Players page pattern** and all display bugs are fixed.
9. **Closed season locked everywhere.**
10. **One finance-categories manager** (modal reachable from Transactions "more" menu and from Settings).
11. **Dashboard uses seasons too:** "This season" replaces "This year".

---

## P1 — Fiscal year follows the season (`feat/finance-season-year`)

### Rule
A transaction belongs to the season containing its `transaction_date`. One helper decides it.

`App\Support\FiscalPeriod` (new, built on `Season`):
- `keyForDate($date): int` — `Season::forDate($date)` → end year (or start year when calendar season).
- `forKey(int $key): Season` — inverse.
- `start(int $key)`, `end(int $key)` — dates for `fiscal_years.start_date/end_date`.
- `label(int $key): string` — delegates to `Subscription::seasonLabel` (move that logic here and have `Subscription` call it).
- `currentKey(): int` — equals `Subscription::currentSeasonEndYear()` (that method calls this).

### Every writer uses it
| Place | Today | After |
|---|---|---|
| `TransactionObserver::saving` | calendar year of date; FY row Jan–Dec | `FiscalPeriod::keyForDate`; FY row via `FiscalPeriod::start/end` |
| `TransactionController@store` | `now()->year` when empty | derived from `transaction_date` (field no longer accepted from the request) |
| `TransactionController@update` | keeps old year | re-derived; both old and new season must be open |
| `PlayerTransactionController` :223 / :300 | `$sub->year` / `now()->year` | derived from the payment date (observer) |
| `TransactionImportController` | observer (calendar) | observer (season) |
| `EquipmentStockService` :213, `EquipmentItemController` :542 | calendar / `now()` | observer (season) |
| `ImportMongoJsonData`, `BackfillFinance` | calendar | `FiscalPeriod` |
| `FiscalYearController@store` | `-01-01`/`-12-31` | `FiscalPeriod::start/end` |
| `FinanceService::closeYear` | next year Jan–Dec, overwrites even a closed next year | next season via `FiscalPeriod`; never touches a closed next season |
| `FinanceService::reopenYear` | docblock promises carry-forward cleanup it does not do | fix docblock to match behaviour (keep behaviour) |

`StoreTransactionRequest` drops `fiscal_year` validation (derived server-side).

### Closed season lock
`TransactionObserver::saving` and `deleting` throw a `ClosedFiscalYearException` (renders as a flash error `flash.year_closed_add` with the season label) when the target — and, on update, the original — season is closed. `TransactionController`'s own checks become redundant and are removed in favour of the observer, so every path (player payments, import, equipment, bulk delete) is covered. Imports report the row as failed instead of aborting the batch.

### Readers follow the season
- `FinanceController@index`: default year `FiscalPeriod::currentKey()`; monthly trend ordered Sep → Aug (12 buckets starting at the start month); `Finance/Index.vue` month labels follow.
- `ReportController::financialSummary`: filter by `fiscal_year`, title shows the season label; group by `finance_category_id` and print the localized category name (fixes the slug leak there).
- `DashboardFilters`: `year` range becomes `season` (start → end of `Season::current()`), label "This season"; previous-period comparison = previous season.
- `MemberStats::renewalRate`: compare `player_subscriptions.year` with `FiscalPeriod::currentKey()`.
- `OverviewStats` :141, `HeroStats` :215: effective due date `FiscalPeriod::end(year)` instead of `year-12-31`.
- `Subscription::assignTo` (exceptional, no year): `FiscalPeriod::currentKey()`.
- UI year labels (Finance tabs, Settings years table, budget picker, Transactions season filter): "2026/2027" via a shared `seasonLabel` JS helper fed by `seasonStartMonth`; Settings "add year" defaults to the current season key.
- Remove the unused `fiscalYearStart` setting.

### Data migration
`2026_10_14_000001_rebucket_fiscal_years_by_season`:
1. For every transaction: `fiscal_year = keyForDate(transaction_date)`, `fiscal_year_id` = matching row (created when missing).
2. Every `fiscal_years` row: `start_date/end_date` rewritten from `FiscalPeriod`.
3. Recompute cached totals (`FinanceService::recomputeYear`) for every row. Closed rows keep status; their `closing_balance` is recomputed.
4. Empty rows are kept (owner may delete them in Settings).
Backup: the existing pre-migration backup routine (`database.pre-<feature>-<date>.sqlite`) is taken by hand before running on the live copy. Live data result: all 36 transactions in 2026/2027 (income 50 000, expense 14 390); row 2026 becomes 2025/2026 with zero totals.
`FixSubscriptionBilling` matching on `player_subscriptions.year = transactions.fiscal_year` is unaffected for normal payments (date inside the subscription's season); document the edge case in its help text.

### Tests
New: `FiscalPeriodTest` (Sept and January start months, boundaries Aug 31/Sep 1), observer derivation, store/update re-derivation, closed-season lock on each writer (manual, player payment, import row, equipment, bulk delete), close/reopen carry-forward, migration re-bucketing, report by fiscal year, dashboard season range, renewal rate. Update the tests listed in the audit that assert calendar behaviour.

---

## P2 — Simpler caisses (`feat/finance-caisses`)

### Model
- `BranchObserver::created` still creates the branch coffre — and **only** the coffre (`CashRegisterProvisioner::forBranch` no longer loops categories).
- `CategoryObserver` no longer provisions on `created`; keeps renaming a linked caisse's stored name on `updated`; on `deleting` unlinks (category_id → null) without deactivating.
- `FinanceAccountController` accepts optional `category_id` (member category) for a caisse under a coffre; unique (`branch_id`, `category_id`) stays. Coffres cannot be linked.
- `DefaultRegisterResolver`: category caisse → branch coffre → first active club-level **cash** account, never the Fonds Initial, never an orphaned treasury.
- Fonds Initial: excluded from `selectable()`, Transactions filters, Finance balance list, transfer pickers. Kept in DB; if its balance is non-zero it still appears (read-only line in club level) so no money disappears from totals.
- Delete/deactivate rules, one place (`FinanceAccountController`):
  - caisse with no transactions/transfers → deletable;
  - caisse with history → deactivate only (allowed, no longer forced active);
  - coffre → not deletable while it has caisses or history; not deactivatable;
  - Fonds Initial → neither.
  Flash texts match the rules.

### One "Caisses" page (`finance.registers.index`, sidebar entry under Finance)
- Header: total cash, number of active caisses, "Move money" (global), "Add caisse".
- One card per branch coffre: coffre balance (own cash) + total of branch; list of its caisses (name, linked category chip, balance, inactive badge), each row with Move money / Edit (IconButton).
- "Club level" card: accounts without branch (bank, صندوق استثمار).
- Transfer history (paginated, formatted dates, labelled accounts).
- Add/Edit caisse modal: name, place (coffre of branch X / club level), linked category (optional, only when under a coffre), type (cash/bank/other), opening balance, account number (bank), notes, active.
- Move money modal: from (pre-filled when opened from a row), to, amount, date, note; shows "From: 83 000 → 73 000 · To: 13 610 → 23 610"; server keeps the sufficient-balance check.
- Plain vocabulary in ar/fr/en: Coffre (خزينة الفرع / Coffre de la section), Caisse (صندوق / Caisse), Club level (على مستوى النادي / Niveau club), Move money (نقل الأموال / Déplacer de l'argent).
- `Finance/Settings.vue`: accounts list removed (replaced by a "Manage caisses" link). The years table in Settings is the single place to close/reopen a season; Finance Index keeps the open/closed banner with a link to Settings instead of its own buttons. Budgets stay in Settings.
- Fix: `tip` key, `bank` option label, back arrow `rtl:rotate-180`, dead `deleteRegister`, `window.confirm` → `ConfirmModal`.

### Tests
`CashRegisterManagementTest` rewritten: branch creates coffre only; category creates nothing; manual caisse with/without category; resolver order and Fonds Initial exclusion; delete/deactivate matrix; transfer validation. Page props test for the Caisses page.

---

## P3 — Transactions redesign (`feat/transactions-redesign`)

### Layout (Players pattern)
- `StatStrip` (income, expenses, net, debts) from a new `ModuleStats::transactions()` using the active filters (debts stays global, labelled as such). Add `strip.tx_*` keys.
- Foldable "Statistics" section (state in localStorage `transactions.stats`): `StatBars` by finance category and by caisse; clicking a bar toggles that filter.
- Filter row: search, type, finance category, caisse, **season** (default current), **date range**; clear-filters `IconButton` when any filter is on. Every URL-driven filter (`status`, `category`, `date_from/to`, `fiscal_year`) gets a visible control or chip — no hidden filters.
- Header: Add (primary); "More" dropdown: import, template, export, manage finance categories.
- Table: date (app-locale formatted), type badge, title (or category name), category, caisse, player, amount (±, `tabular-nums`, dark-mode colours), actions. Status column dropped from the list (all rows are Paid/Partial in practice) and shown on Show.
- Mobile (< md): card list (title, amount, date, caisse, category chip, actions).
- Per-page select; bulk bar with `ms-auto`; `ConfirmModal` for single and bulk delete.
- `Show`, `Create`, `Edit`: mirrored back arrows, formatted dates, consistent status colours; form defaults the caisse via the resolver (never Fonds Initial/club "Cash" unless that is the resolver's answer).

### Display fixes outside the page
- Slugs never shown: `OverviewStats` recent list, `FinanceStats` largest expense, export register/category columns, `TransactionTitle::categoryLabel` fallback → use the finance category's localized name.
- `TransactionObserver` no longer creates finance categories from slugs: it maps known slugs (`subscription`, `donation`, `debt_payment`, `equipment`…) to the existing system categories and falls back to a single "Other income/expense" category.
- Pagination: `lang/ar/pagination.php`, `lang/fr/pagination.php`; `Pagination.vue` mirrors arrows in RTL and wraps.
- Dates serialized as `Y-m-d` / `Y-m-d H:i` (model `serializeDate` on Transaction, FinanceTransfer) and formatted in the UI with the app locale.
- Seeded account names ("Opening Fund", "Cash") get translated labels via `useFinanceAccountLabel`.
- `StatCard`/`StatTile` money values shrink/wrap instead of clipping; currency from an i18n key, not hard-coded.

### Finance categories manager
`CategoryManager` modal: list grouped income/expense, add/edit (ar/fr/en names, colour, active), delete with `ConfirmModal` (blocked when used, with count). Colour edits no longer mutate props. Settings opens the same modal; its separate list is removed. `is_system` categories: rename allowed, delete blocked.

### i18n check
`scripts/i18n-check.mjs` also scans `t('…')`/`$t('…')` literal keys in `resources/js/**/*.vue|js` and fails on keys missing in any locale.

### Tests
Index props (stats follow filters, season default), category mapping in observer, PDF summary category names, pagination translations, i18n check passes; Vue pieces verified in the browser (desktop + phone width, ar RTL + fr).

---

## Out of scope
Budgets redesign, multi-currency, bank reconciliation, swimming caisse cleanup (owner keeps both), recreating the deleted football category caisses.
