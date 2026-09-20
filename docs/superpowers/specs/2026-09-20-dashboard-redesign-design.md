# Dashboard redesign and statistics expansion — design

**Date:** 2026-09-20
**Status:** Approved

## Problem

The dashboard (`/dashboard`) presents 14 raw numbers, 3 charts and 2 lists on a single flat page.
Three concrete problems:

1. **No context on any number.** Every value is an absolute with nothing to compare it to — no
   period delta, no trend, no target. "142 members" and "38 400 DZD" tell the reader nothing about
   whether things are improving.
2. **No time control.** Some numbers are current-month, some are all-time, some are current-year,
   and nothing on screen says which is which. `monthlyIncome` is this month; `totalIncome` is all
   time; `monthlyRevenue` is this calendar year. The reader cannot tell them apart.
3. **The chart colors fail accessibility.** Measured with the dataviz validator:

   ```
   Current palette (#059669,#0f766e,#ca8a04,#e11d48,#475569,#0284c7,#65a30d,#c026d3)
     [FAIL] Chroma floor         #0f766e, #475569 read as gray
     [WARN] CVD separation       #475569 <-> #e11d48  ΔE 6.4 (protan)
     [FAIL] Normal-vision floor  #0f766e <-> #059669  ΔE 10.3 — below the 15 floor
     [WARN] Contrast vs surface  #ca8a04 at 2.86:1 — below 3:1
   ```

   `#0f766e` and `#059669` are indistinguishable to readers with **full** colour vision, not just
   colourblind ones.

   The income/expense pair is worse, because it is the green-vs-red trap:

   ```
   emerald #059669 vs rose #e11d48 (light)   [FAIL] CVD ΔE 5.8 (deutan)
   emerald #34d399 vs rose #fb7185 (dark)    [FAIL] CVD ΔE 4.6 (deutan)
   blue    #2a78d6 vs orange #eb6834 (light) [PASS] CVD ΔE 24.7 · normal 33.6 · contrast ≥3:1
   ```

Meanwhile the domain is far richer than the dashboard shows: subscriptions with per-player
paid/owed/exempt/discount state, multi-branch finance accounts with treasury hierarchy and
transfers, equipment catalog with stock, rentals and inventory sessions, board members and
meetings, player statuses and categories. None of it reaches the dashboard.

## Goal

A dashboard that answers, in order: *is anything wrong right now*, *where is the money*, *how is
membership doing*, *what is happening operationally* — each number carrying its period and its
direction of travel, each chart readable by every reader in both light and dark mode, and each
module index page carrying a small strip of the same numbers scoped to that page.

## Scope

**In scope**

- Full rebuild of `/dashboard`: filter bar, hero KPI row, four lazy-loaded tabs.
- A shared dashboard component library under `resources/js/Components/Dashboard/`.
- A chart theming module replacing hardcoded hex in chart components.
- Stat strips on five module index pages: Players, Transactions, Subscriptions, Equipment Catalog,
  Finance.
- Backend stat providers under `app/Services/Dashboard/` with query budgets and Pest coverage.
- New i18n keys in all three locale files, verified by `npm run i18n:check`.

**Out of scope**

- Role-specific dashboard *layouts* (per-role widget arrangement). Widgets hide by permission, but
  every permitted user sees the same arrangement.
- User-customisable widget grids, drag/drop, saved layouts.
- Porting the result to `D:\sportclub-laravel` (the generic template). Components are written
  instance-neutral so the port is mechanical, but the port is separate work.
- New reports or PDF exports beyond the existing `reports.financial` link.
- Any change to how the underlying data is recorded (finance, subscriptions, debt calculation).

## Decisions

| Question | Decision |
|---|---|
| Layout | Tabbed shell, tab data lazy-loaded via Inertia v2 `Inertia::optional()` |
| Filters | Time range, branch, compare-to-previous — all in the URL |
| Tabs | Overview · Finance · Members · Operations |
| Chart series colours | Validated 8-slot categorical set, fixed order, never cycled |
| Money in / money out | Blue `#2a78d6` / orange `#eb6834` in every chart, always |
| Semantic colours | emerald/rose/amber keep their meaning in UI chrome only, never as adjacent chart marks |
| Caching | None initially. Add per-widget only when a measured budget is exceeded |
| Dark mode | Selected steps from the same ramps, validated against the dark surface — not an automatic flip |
| Permission enforcement | Both client (`useCan`) and server (stat provider), not client alone |

---

## Part 1 — Shell and information architecture

```
┌──────────────────────────────────────────────────────────────┐
│ Dashboard                                                    │
│ [This month ▾] [All branches ▾] [⇄ vs previous]      [PDF]   │  sticky filter bar
├──────────────────────────────────────────────────────────────┤
│ ┌───────┐┌───────┐┌───────┐┌───────┐┌───────┐┌───────┐       │
│ │Members││Collect││Net    ││Debt   ││On loan││Treasury│      │  hero KPI row
│ │  142  ││  87%  ││+38 400││142 000││  6    ││ 512 300│      │  (always visible)
│ │▲ +4   ││▲ +6pt ││▲ +12% ││▼ −8%  ││2 late ││▲ +7%   │      │
│ │▁▃▅▇▆▇ ││▇▅▃▅▆▇ ││▃▅▇▅▆▇ ││▇▆▅▃▂▁ ││       ││▃▄▅▆▆▇  │      │
│ └───────┘└───────┘└───────┘└───────┘└───────┘└───────┘       │
├──────────────────────────────────────────────────────────────┤
│  Overview │ Finance │ Members │ Operations                   │  lazy tabs
└──────────────────────────────────────────────────────────────┘
```

### Filter bar

| Control | Values | Effect |
|---|---|---|
| Time range | This month · Last month · This quarter · This year · Last 12 months · All time | Bounds every period-scoped stat and time series |
| Branch | All branches · one of the configured branches | Scopes every stat that has a branch path |
| Compare | on / off | Shows Δ vs the previous equivalent period on tiles, ghost series on time charts |

State lives in the URL: `?range=quarter&branch=2&compare=1&tab=finance`. A view is shareable,
browser-back works, and a refresh keeps the view. Default on first visit: `range=month`,
`branch=all`, `compare=on`, `tab=overview`.

"All time" disables the compare toggle (there is no previous period) — the control greys out with a
tooltip rather than silently producing nothing.

### Hero KPI row

Six tiles, always visible, identical anatomy, no exceptions:

```
label (sentence case, muted, 13px)
value (tabular-nums, 30px, semibold, text token — never a series colour)
delta chip (arrow + percent + period name, e.g. "▲ 12% vs last month")
sparkline (12 points, 2px line, no axis, no dots except the endpoint)
```

| Tile | Value | Sparkline | Delta meaning |
|---|---|---|---|
| Active members | count of non-archived players | monthly active count, 12 pts | up is good |
| Collection rate | `paid / (paid + owed)` across player subscriptions in range | monthly rate, 12 pts | up is good |
| Net cash flow | income − expense in range | monthly net, 12 pts | up is good |
| Outstanding debt | sum of `players.outstanding_debt` | monthly total, 12 pts | **down is good** |
| Equipment on loan | open rentals, with an overdue sub-count | — | down is good |
| Treasury balance | sum of treasury account balances | monthly balance, 12 pts | up is good |

**Delta tone follows meaning, not sign.** Debt falling renders green with a down arrow. This is the
single rule most dashboards get wrong.

#### Which date bounds each stat

`player_subscriptions` carries a `year` and a nullable `due_date`, not a transaction date, so
"in range" needs stating per stat rather than assuming a single column:

| Stat | Bounded by |
|---|---|
| Collection rate | `player_subscriptions.due_date`, falling back to 31 December of `year` when null |
| Net cash flow, income, expense | `transactions.transaction_date` |
| Active members, new registrations | `players.created_at` for "new"; current snapshot for "active" |
| Outstanding debt | see below |
| Equipment on loan | `equipment_rentals.checkout_date` for the period count; open rentals are a snapshot |
| Treasury balance | see below |

#### Two stats have no stored history

`players.outstanding_debt` and account balances are **current snapshots**. Neither has a history
table, so a 12-point sparkline cannot read them directly. Both are reconstructed:

- **Debt at month end M** = `SUM(amount_owed − amount_paid)` over `player_subscriptions` whose
  due date (with the same fallback above) is on or before the end of M, excluding exempt lines.
  This is the accrual view and will not always equal today's `outstanding_debt` column to the dinar;
  the tile's headline value uses the column, the sparkline uses the reconstruction, and the tooltip
  labels the sparkline "accrued debt" so the two are not read as the same series.
- **Treasury at month end M** = account opening funds plus the cumulative sum of non-cancelled
  transactions up to the end of M. Consistent with how the finance pages already derive balances.

If reconstruction proves too slow or too divergent during implementation, the fallback is to drop
those two sparklines rather than ship a misleading line — the tiles keep value and delta.

**The debt tile's delta measures newly accrued unpaid debt, not the running total.** A cumulative
accrual can only rise, so comparing totals period over period would report "neutral or worse"
forever and the tile could never show good news about debt being brought down. The delta therefore
compares debt that *came due within* each window and is still unpaid: less new debt than last period
is the good direction. The headline value stays the current total.

When compare is off, the delta chip and sparkline are removed from the DOM and the tile shrinks. No
empty placeholder, no dash, no zero.

### Tab loading

First paint ships `filters` + `hero` + `overview` only. Switching tab fires
`router.reload({ only: ['finance'], preserveScroll: true, preserveState: true })` and the result is
cached in component state for the session. Changing any filter invalidates the cache and refetches
the active tab only.

---

## Part 2 — Statistics catalogue

### Overview tab — "is anything wrong right now"

| Widget | Form | Rationale |
|---|---|---|
| Cash flow, 12 months | income + expense columns with a net line, **one axis** (all DZD) | trend plus polarity in one frame |
| Debt aging | horizontal stacked bar: 0–30 / 31–60 / 61–90 / 90+ days | part-to-whole on an ordinal scale |
| Alert strip | status chips, icon + label + count | overdue rentals · low stock · negative account balance · members unpaid > 60 days |
| Activity feed | merged timeline, last 10 | transactions, registrations, rentals, in one stream |

Debt aging buckets measure from `player_subscriptions.due_date`, falling back to the subscription
year end when `due_date` is null.

**Low stock has no threshold in the schema.** `equipment_catalogs` carries no reorder point, so the
dashboard applies one flat rule — a catalog whose available quantity (stocked minus currently out on
loan) is 2 or less — rather than inventing a column. If per-catalog thresholds are wanted later,
that is a schema change and its own piece of work.

**Alerts with a count of zero are omitted, not rendered green.** A strip of "all clear" chips trains
the reader to stop looking at the one component whose job is to be noticed.

### Finance tab

Income vs expense by month · expense breakdown by finance category (horizontal bar, sequential
blue ramp) · income composition (subscriptions / donations / other, stacked) · account balances
with opening-fund meters · branch treasury comparison · transfers in/out · average transaction size
· largest single expense in range · burn rate (mean monthly expense over the range) · runway
(treasury ÷ burn rate, in months).

Runway suppresses itself when burn rate is zero rather than rendering infinity.

### Members tab

New registrations per month with a cumulative active line · renewal rate vs last year · age pyramid
by category · category distribution · status distribution (`player_statuses`) · student vs worker ·
gender split · top states and cities · debt distribution (how many owe 0 / under 5k / 5–20k / over
20k) · mean and median debt · archived-this-period count.

Renewal rate = players with a paid subscription line in year N who also have one in year N+1,
divided by the year N population. Undefined for the first year of data; the widget says so.

### Operations tab

Per-subscription funnel (enrolled → partial → paid → exempt) · collection rate per subscription
(bar, sorted descending) · collection trend by month · equipment stock value · items by status ·
low-stock list · on-loan table with overdue rows highlighted · rentals per month · inventory
coverage (date of last session, count of items never counted).

### Module stat strips

A four-tile strip using the same `StatTile.vue`, placed above the page's existing filter row.
Strips respect the **page's own** filters, not the dashboard's.

| Page | Tiles |
|---|---|
| `Players/Index` | active · with debt · total debt · new this month |
| `Transactions/Index` | period income · expense · net · count |
| `Subscriptions/Index` | enrolled · collected · owed · collection % |
| `Equipment/Catalog/Index` | items · stock value · on loan · low stock |
| `Finance/Index` | treasury total · accounts · transfers this period · largest balance |

---

## Part 3 — Visual and chart system

### Two colour languages, deliberately separate

**Semantic — UI chrome only.** emerald / rose / amber keep their current meaning for badges, delta
chips, text values and alert states. They always appear *alone*, beside an icon and a sign, so CVD
adjacent-pair separation does not apply to them.

**Categorical — chart marks.** Validated 8-slot set, assigned in fixed order, never cycled:

| Slot | Hue | Light | Dark |
|---|---|---|---|
| 1 | blue | `#2a78d6` | `#3987e5` |
| 2 | orange | `#eb6834` | `#d95926` |
| 3 | aqua | `#1baf7a` | `#199e70` |
| 4 | yellow | `#eda100` | `#c98500` |
| 5 | magenta | `#e87ba4` | `#d55181` |
| 6 | green | `#008300` | `#008300` |
| 7 | violet | `#4a3aa7` | `#9085e9` |
| 8 | red | `#e34948` | `#e66767` |

Dark values are selected steps validated against the dark surface, not lightened light values.

**Money in = blue (slot 1), money out = orange (slot 2)** — in every chart, on every page, without
exception. Sequential encodings (debt aging, expense magnitude, heat) use the blue ramp light→dark.
Diverging encodings (over/under budget) use blue ↔ red with a gray midpoint, never a hue at the
midpoint.

A ninth series is never a generated hue. It folds into "Other", facets into small multiples, or the
widget becomes a table.

### Chart infrastructure

`resources/js/lib/chartTheme.js` exports the palette by role, Chart.js global defaults, and reads
live CSS custom properties so light/dark and LTR/RTL swap without component-level recomputation.
Chart components stop containing hex literals. `registerCharts.js` gains the elements the new chart
types need.

### Mark specs

Bars and columns ≤24px thick, 4px rounded data-end, square at the baseline · lines 2px with round
join and cap · markers ≥8px with a 2px surface-colour ring · area fills at ~10% opacity · a 2px
surface-colour gap between stacked segments and adjacent bars · gridlines hairline, solid, one step
off surface, never dashed · legend present for two or more series, absent for one · direct labels on
endpoints and extremes only, never a value on every point · axis text, values and legend text wear
text tokens, never the series colour.

Three light-mode slots (aqua, yellow, magenta) sit below 3:1 contrast on the light surface. The
relief rule applies: any chart using them ships visible direct labels or the table view. This is not
optional.

### New components — `resources/js/Components/Dashboard/`

| Component | Responsibility |
|---|---|
| `StatTile.vue` | The one tile anatomy. Used by the hero row and every module strip. |
| `ChartCard.vue` | Title, subtitle, action slot, fixed aspect, empty and loading states. |
| `Sparkline.vue` | Inline SVG, no Chart.js instance. Cheap enough for 6+ per page. |
| `Meter.vue` | A single ratio against a limit, same-ramp track. |
| `DeltaBadge.vue` | Arrow, percent, period label, tone by meaning not sign. |
| `AlertChip.vue` | Status chip: icon + label + count, never colour alone. |
| `RangePicker.vue` | The time-range control. |
| `DataTableToggle.vue` | Flips any chart to its underlying table. |

Each has one job, a declared prop contract, and no knowledge of where its data came from.

### Sparse data

Current production volumes are small (77 players, 11 transactions, 5 equipment items). The design
must look correct at that size, not only when full:

- A time series with fewer than 2 points renders as a stat tile, not an empty axis.
- A widget with zero rows renders a one-line empty state with a link to the action that would create
  data — not a dashed placeholder box.
- A delta whose previous period had no data is suppressed entirely. No `+∞%`, no `+100%`.
- A ratio with a zero denominator renders `—`, not `0%` or `NaN`.

### RTL and i18n

Arabic is a first-class locale. Under `dir="rtl"` charts flip axis direction, legend alignment and
tooltip anchoring. Numbers stay `tabular-nums` and LTR regardless of locale. Every new string is a
flat key added to all of `resources/js/i18n/{ar,fr,en}.json` and verified by `npm run i18n:check`.
Per the known vue-i18n behaviour with flat dotted keys, components call `t()` directly and never
gate on `te()`.

---

## Part 4 — Backend architecture

```
app/Services/Dashboard/
  DashboardFilters.php          readonly DTO: from, to, prevFrom, prevTo, branchId, compare
  HeroStats.php                 6 KPI tiles + 12-point sparklines
  OverviewStats.php
  FinanceStats.php
  MemberStats.php
  OperationsStats.php
  Support/MonthBucket.php       driver-aware month expression
  Support/DeltaCalculator.php   delta, direction, suppression rules
```

Each stat provider is a single-purpose class with one public method,
`get(DashboardFilters $filters): array`. It can be understood, tested and changed without reading
the others. `DashboardController` becomes a thin resolver:

```php
return Inertia::render('Dashboard', [
    'filters'    => $filters->toArray(),
    'hero'       => fn () => $this->hero->get($filters),
    'overview'   => Inertia::optional(fn () => $this->overview->get($filters)),
    'finance'    => Inertia::optional(fn () => $this->finance->get($filters)),
    'members'    => Inertia::optional(fn () => $this->members->get($filters)),
    'operations' => Inertia::optional(fn () => $this->operations->get($filters)),
]);
```

### Query discipline

Every statistic is SQL aggregation. No hydrate-then-loop: the current `monthlyRevenue` loads every
transaction of the year into memory to bucket it in PHP, and that pattern does not get carried
forward. Month bucketing goes through `MonthBucket`, which returns
`strftime('%Y-%m', <col>)` on SQLite and `DATE_FORMAT(<col>, '%Y-%m')` on MySQL, so the queries stay
portable across the web and desktop deployments.

Budget: hero ≤ 6 queries, each tab ≤ 8. A Pest test asserts the counts via `DB::listen` so a future
widget cannot silently introduce an N+1.

**Excluded rows.** Every money statistic excludes out-of-books transactions using whichever
mechanism is live at implementation time: today that is `archived = false`, matching the rest of the
app. If the approved transaction-cancellation design has shipped by then, the stat providers use its
cancelled flag instead. The exclusion is applied in one shared query scope, not repeated per widget,
so switching mechanisms is a one-line change. Archived players are excluded from every member
statistic; exempt subscription lines are excluded from debt and collection-rate numerators and
denominators alike, and are reported as their own count in the Operations funnel.

### Caching

None initially. The dataset is small and the desktop app is opened specifically to read *current*
numbers; a stale five-minute cache would be a correctness bug rather than a speed-up. If a widget
later exceeds its query budget, that widget alone gets `Cache::remember`, keyed by the filter
signature, invalidated by the relevant model observer. Measured, not preemptive.

### Branch scoping

| Entity | Path to branch |
|---|---|
| Players | `branch_player` pivot (many-to-many) |
| Transactions | `financeAccount.branch_id` |
| Equipment items | `branch_equipment_item` pivot |
| Subscriptions | `branch_subscription` pivot |
| Finance accounts | `finance_accounts.branch_id` |

`DashboardFilters::$branchId === null` means all branches. A player belonging to two branches counts
in both branch-scoped views and once in the all-branches view.

### Permissions

Widgets check module permission on both sides. Client: `useCan()` hides the tab and any tile whose
module the user cannot view. Server: the stat provider returns an empty payload for a module the
user cannot view, so a hand-crafted `?tab=finance` yields nothing. Superadmin short-circuits as it
does elsewhere.

Tab visibility by module: Finance tab → `finance.view`; Members tab → `players.view`; Operations tab
→ `equipment.view` or `subscriptions.view` (either grants the tab; individual widgets still check
their own module). Overview is visible to any authenticated user, with each of its widgets filtered
by the module it draws from.

### Testing

Pest feature tests per stat provider, using model factories:

- **Math** — collection rate, deltas, aging buckets, renewal rate, runway produce known values from
  known fixtures.
- **Filter isolation** — branch A's data never appears in a branch B query; a range boundary
  includes its first day and excludes the next period's first day.
- **Empty safety** — zero players, zero transactions, zero previous period: no division by zero, no
  infinity, no exception.
- **Permissions** — a user without `finance.view` receives an empty finance payload.
- **Budgets** — query counts stay within the stated limits.

---

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Four tabs of widgets is a large surface to build at once | Phase it: shell + hero first, then one tab per batch, each independently shippable |
| Charts look empty at current data volumes | Sparse-data rules are part of the component contract, not an afterthought |
| New i18n keys drift across three locales | `npm run i18n:check` runs as part of the verification step for every batch |
| Divergence from the generic template grows | Components written instance-neutral; the port is tracked as follow-up work |
| Branch filter multiplies query complexity | Branch scoping lives in `DashboardFilters` and is applied by a single shared scope method per entity, not re-implemented per widget |

## Verification

Work is complete when:

- `php artisan test` passes, including the new dashboard test suite.
- `npm run i18n:check` reports no missing keys.
- `vendor/bin/pint --test` passes.
- The chart palette is unchanged from the validated set recorded in Part 3. If any hue is altered,
  the new set must be re-run through the dataviz skill's `scripts/validate_palette.js` in both
  `--mode light` and `--mode dark` and must pass before it ships. The validator is not vendored into
  this repo; it lives with the skill.
- The dashboard has been opened in both locales, both text directions and both colour themes, and
  visually checked for label collision, overflow and empty-state correctness.
