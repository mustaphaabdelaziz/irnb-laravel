# Stats redesign — Phase 3: document delivery completion

Date: 2026-11-01. Parent spec: `2026-11-01-stats-redesign-design.md` (§2.8, §5 "Document completion", §6 phase 3). Branch `feat/stats-p3` (worktree `D:\irnb-stats`), from origin/main `3a227e4`.

## 1. Goal

An "installation progress bar" of how much of the club's paperwork is in: documents received out of documents required, plus how many players have a complete file. Shown on the Dashboard Members tab, the Players list and Teams/Show.

## 2. Owner decisions (2026-11-01)

| Topic | Decision |
|---|---|
| Dashboard population | Current members: `archived = false` and `notLeft()`, following the dashboard branch filter. The dashboard date range does not apply ("as of today"). |
| Expiring within 30 days | Counted as received (same as the player page), drawn as an amber slice of the bar. |
| Drill-down | Bar parts and table rows link to the Players list with its existing filters. |
| Players list placement | Slim strip above the table; follows every active list filter. |
| Dashboard breakdown | Two ChartCards side by side: "By category" and "By document type". |
| Row order | Worst % first (tables can be re-sorted). |
| Teams/Show | Strip over the season roster + fold-out list "type — missing for N players". |
| Permission | `documents → view` everywhere (parent spec); without it the server sends `null` and nothing renders. |

## 3. Counting rules (identical to the player checklist)

For each player × each **active, required** document type:

- **required** — the type applies to the player's age (`DocumentType::appliesTo` / `earliestApplicableBirthdate`) and the player has no `exempt` record for it. Exception: a Photo is settled by a non-empty `players.picture_url` whatever its record says, so a picture makes it required + received even with an exempt record (matches `evaluate()` order).
- **missing** — the existing `missingTermSql` (no exempt record, no received record valid today; expired = missing; Photo without picture).
- **expiring** — required, not missing, received record with `valid_until` in `[today, today+30]` (same window as `EXPIRES_SOON_DAYS`); a Photo settled by a picture is never expiring.
- **received** = required − missing (includes expiring).
- **complete player** — `players_total` counts every player in the population; a player is complete when their missing count is 0 (a player with zero required documents is complete).
- Inactive types and optional (`is_required = false`) types are never counted.

## 4. Backend

### `DocumentChecklist` (new SQL twins, existing methods untouched)
- `requiredTypeSql(DocumentType, ?today)` and `expiringTypeSql(DocumentType, ?today)` — per-type 1/0 expressions over `players`, beside `missingTypeSql`.
- `DocumentChecklistTest` extended: for a fixture set of players (exempt, expired, expiring, photo with/without picture, age-limited type, inactive type, optional type), the SQL required/expiring/missing per type equals what `evaluate()` says.

### `App\Services\Player\DocumentStats` (new)
Takes an already-filtered `Builder` of players. Aggregates in SQL, no per-player PHP loop.
- `summary(Builder $players): ?array` → `{ required, received, expiring, missing, pct, players_complete, players_total, by_type: [{ id, name, required, received, expiring, missing, pct }] }` — one query (per-type `SUM(term)` columns + `SUM(CASE WHEN missing_total = 0 ...)`). `pct` = received / required × 100, one decimal; `null` when required = 0. Returns `null` when no active required type exists.
- `byCategory(Builder $players): array` → `[{ category_id, name, required, received, expiring, missing, pct, players_total, players_complete }]` — one `GROUP BY players.category_id` query, localized category name (same fallback as the Players list charts).
- Rows sorted by `pct` ascending (null last), then name.

### Placements
- **Dashboard** (`MemberStats` or a sibling called from the Members tab payload): new key `documents` = `{ summary, by_category }` or `null` without `documents → view`. Population = `BranchScope::players(Player::query()->where('archived', false)->notLeft(), $branchId)`. ≤ 2 extra queries; the Members-tab budget in `tests/Feature/Dashboard/DashboardPageTest.php` is raised deliberately in the same commit.
- **Players list** (`PlayerController::index`): new prop `documentStats` = `DocumentStats::summary()` over `statsQuery($request)` (same filters as the rows, no pagination), `null` without the right. +1 query.
- **Teams/Show** (`TeamController::show`): new prop `documentStats` = summary over the season roster player ids, `null` without the right. +1 query.

No schema change, no route change, existing props keep shape and meaning.

## 5. Frontend

### `Components/Stats/CompletionBar.vue` (new)
- Props: `received`, `expiring`, `missing`, `required`, `playersComplete`, `playersTotal`, `links` (optional `{ missing?: href }`), `size` (`'lg' | 'sm'`).
- One horizontal bar: received-not-expiring (positive token), expiring (warning token), missing (negative token). Headline "Documents received — 82 %" via `formatPercent`, counts via `formatNumber`, line "N of M players have a complete file".
- `role="progressbar"`, `aria-valuenow` = pct, `aria-valuetext` with the counts. Logical (RTL-safe) layout, dark-mode tokens, no fixed widths (375 px phone).
- Segment widths from a pure helper `lib/completion.js` (`segments({received, expiring, missing, required})` → percentages that sum to 100, tiny non-zero slices get a minimum visible width) — node:test covered.
- Empty states: `required = 0` → "No required documents set up"; `playersTotal = 0` → empty text.

### Dashboard Members tab
Big `CompletionBar` (lg) at the top of the tab, then two ChartCards: stacked horizontal bars (received / expiring / missing, theme colours) with StatTable (`name, required, received, expiring, missing, pct`; totals only for count columns) and CSV. Links:
- "missing" → `players.index?documents[]=missing` (+ `branch_id[]` when the dashboard has a branch).
- type row → `documents[]=missing-<id>`; category row → `category_id[]=<id>` + `documents[]=missing`.

### Players list
`CompletionBar` (sm) strip above the table, recomputed with the filters (it arrives with the page props). Its "missing" link adds `documents[]=missing` to the current filters.

### Teams/Show
`CompletionBar` (sm) above the roster + a `<details>` fold-out listing each type with missing > 0 as "Type — missing for N players", worst first. No links (the Players list has no roster filter).

i18n: new flat dotted keys in ar/fr/en via the insert-only script; `npm run i18n:check` green.

## 6. Guardrails

- No change to existing calculations, `missingCountSql`, the list's `missing_documents_count`, or the player checklist.
- Shared components' public APIs unchanged (ChartCard, StatTable, StatTile).
- Offline only; no new dependency.
- Query budgets measured and raised deliberately; Players list page time checked with `scripts/perf/scale.php` at 1,890 players (target: < +30 ms).

## 7. Testing

- PHPUnit: SQL/PHP agreement (§4); `DocumentStats` — exempt, expired, expiring, photo with/without picture, photo exempt + picture, inactive type, optional type, age-limited type, branch isolation, zero required types (`null`), zero players, worst-first order, players_complete; placements — `documents → view` gate on all three (prop `null`), dashboard population excludes archived and left, Players list follows a filter, Teams/Show uses the season roster; query budgets.
- JS: `lib/completion.js` segments.
- `npm run build`, `npm run i18n:check`, `php artisan test`.
- Browser QA (headless Chrome): RTL + dark, 375 px, links land on the filtered list, CSV, view-only user without documents right sees nothing.
