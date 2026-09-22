# Dashboard Redesign — Phase 2 (Finance tab) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fill the Finance tab — where the money went, where it came from, what each account holds, and how long the club can keep going at its current burn.

**Architecture:** One new stat provider, `app/Services/Dashboard/FinanceStats.php`, following the Phase 1 shape: `get(DashboardFilters $filters): array`, SQL aggregation only, branch-scoped through `BranchScope`. `DashboardController` swaps its finance placeholder for the provider. One new page partial, `FinanceTab.vue`, built from the Phase 1 component library.

**Tech Stack:** As Phase 1.

## Global Constraints

Everything in [Phase 1's plan](2026-09-20-dashboard-redesign-phase-1.md) still binds. Additionally:

- **Category charts are magnitude, not identity** — one sequential blue hue, darkest for the largest bar. Not eight categorical hues.
- **Cap category rows at 8 plus "Other".** Never generate a ninth colour; fold the tail.
- **Runway is suppressed when burn rate is zero.** No infinity, no "∞ months".
- **Transfers are not income or expense.** They move money between accounts and must never enter a cash-flow or category total, or the same dinar is counted twice.
- **The Finance tab needs `finance.view`.** The provider returns null without it; the client hides the tab.
- **Query budget:** the tab's own reload stays at or under 14 queries, asserted by test.

---

## File Structure

| File | Responsibility |
|---|---|
| Create `app/Services/Dashboard/FinanceStats.php` | Summary figures, category breakdowns, account balances, transfers |
| Create `tests/Feature/Dashboard/FinanceStatsTest.php` | Math, exclusions, branch isolation, empty safety |
| Create `resources/js/Pages/Dashboard/Partials/FinanceTab.vue` | The tab's layout |
| Modify `app/Http/Controllers/DashboardController.php` | Swap the finance placeholder for the provider |
| Modify `tests/Feature/Dashboard/DashboardPageTest.php` | Assert the tab's real payload and its budget |
| Modify `resources/js/Pages/Dashboard.vue` | Render `FinanceTab` |
| Modify `resources/js/i18n/{ar,fr,en}.json` | New keys |

---

## Task 1: FinanceStats provider

**Interfaces:**
- Produces `FinanceStats::get(DashboardFilters $filters): array` with keys:
  - `summary`: list of `['key' => string, 'value' => ?float, 'format' => 'money'|'months', 'meta' => ?array]`, keys `avg_transaction`, `largest_expense`, `burn_rate`, `runway`
  - `expenseByCategory` / `incomeByCategory`: list of `['name' => string, 'amount' => float, 'share' => float]`, descending, at most 9 rows with the last named "Other"
  - `accounts`: list of `['id' => int, 'name' => string, 'branch' => ?string, 'is_treasury' => bool, 'opening' => float, 'current' => float]`
  - `transfers`: `['count' => int, 'total' => float, 'recent' => list of ['from' => string, 'to' => string, 'amount' => float, 'date' => string]]`

- [x] **Step 1: Write the failing test** covering:
  - average transaction size over the window, archived excluded
  - largest single expense returns its amount, label and date
  - burn rate is mean monthly expense across the window, not the total
  - runway is treasury ÷ burn rate, and is null when burn rate is zero
  - expense and income split by finance category, uncategorised folded into "Uncategorised"
  - a ninth category folds into "Other" and the row count never exceeds 9
  - transfers are excluded from every category and summary figure
  - branch filter isolates accounts and transactions
  - an empty database returns empty lists and null runway without dividing by zero

- [x] **Step 2: Run it and watch it fail** — `php artisan test --filter=FinanceStatsTest`

- [x] **Step 3: Implement the provider**

- [x] **Step 4: Run it green, then Pint, then commit**

---

## Task 2: Wire the controller

- [x] **Step 1: Extend `DashboardPageTest`** — the finance payload has `summary`, `accounts` and `transfers` for a permitted user; stays null without `finance.view`; the tab reload stays within budget.
- [x] **Step 2: Replace the placeholder resolver** with `fn () => $this->guard($user, 'finance') ? $this->finance->get($filters) : null`
- [x] **Step 3: Run the suite, Pint, commit**

---

## Task 3: FinanceTab.vue

- [x] **Step 1: Build the tab** — a four-tile summary row using `StatTile`, two `ChartCard` horizontal bars (expense and income by category, sequential ramp, table view on each), an accounts card using `Meter` for each account against the largest balance, and a transfers card.
- [x] **Step 2: Render it from `Dashboard.vue`**
- [x] **Step 3: Add the i18n keys to all three locales**
- [x] **Step 4: `npm run build`, then drive the page in the browser and look at it**
- [x] **Step 5: Commit**
