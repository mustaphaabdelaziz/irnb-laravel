# Attendance codes, rosters, calendar speed, multi-select filters — design (2026-10-02)

Owner batch of 7 notes. Code was mapped first (3 read-only agents), then two decision rounds.
Three independent streams, one branch + worktree each, merged into `main` one after another.

## Owner decisions

| # | Topic | Decision |
|---|-------|----------|
| 1 | Attendance codes CRUD | The 6 base statuses stay (code/colour/labels/points editable, never deletable). Owner can **add custom codes** (code, colour, labels ar/fr/en). Each custom code has one **behaviour**: `present`, `absent_excused`, `absent_unexcused` or `not_counted`. Custom code deletable only if no mark uses it, otherwise it can only be hidden (inactive). Real case: registered player away for work comes to a few sessions → "Travelling" = `not_counted`. Reverses the 2026-09-30 "no custom statuses" decision. |
| 2 | Full name in attendance | `Player::fullname` (lastname firstname (nickname) بن father grandfather) on: session marking page rows + add-player search, month grid rows, PDF session sheet, PDF month sheet. Not on the calendar. |
| 3 | Session roster by player status | Attendance settings hold a **default set of player statuses** (player_statuses ids) for rosters; when never set, default = status with code `registered`. Add-session dialog pre-checks that set and lets the owner change it per session (stored on the session). Auto-generated sessions use the settings default. Players outside the set are added by searching on the session page (any status, any category, non-archived, not left before the session date). |
| 4 | Players list "Left the club" | Hidden by default. No status selected = every status except `left` (+ players with null status stay visible). When statuses are selected, list shows exactly those. Charts, counts, export follow the same rule. |
| 5 | Multi-select filters | Every select filter becomes a multi-select (checkbox dropdown): **Players** (category, branch, family/lastname, status, blood group, academic, certificate, documents, chart clicks toggle values) and **other lists**: Transactions, Users, Subscriptions, Equipment catalog. Selecting values shows only rows matching any selected value (OR within a filter, AND across filters). |
| 6 | Desktop calendar slow | Slow on first open, every month/category switch, all views; owner has weekly schedules. Fix the causes found in code (below); verify by query/write counts in tests. |

## Stream A — attendance (branch `feat/attendance-codes-rosters`)

### A1 Custom codes
- Storage: new table `attendance_custom_statuses` (key unique string ≤ 20 stored in `attendances.status`, e.g. `c_<id>`; code; color; label_ar/fr/en; behaviour; is_active; sort_order; timestamps). Base status settings stay in `AttendanceSettings` JSON.
- `attendances.status` stops being cast to the `AttendanceStatus` enum (a custom key must load without throwing). Keep the enum for base statuses and their rules.
- One resolver (e.g. `AttendanceStatusCatalog`) gives: all active statuses in order (base + custom), code/colour/label per status, behaviour per status (base status behaviour = itself). Everything that loops over `AttendanceStatus::values()` for display (settings, grid, session page, PDFs, stats breakdowns, exports, `useAttendanceCodes.js` STATUSES) uses the catalog instead.
- Rules: custom `present` counts like present (present points); `absent_excused`/`absent_unexcused` count like those base statuses (points, MISSED hours, unexcused streaks / at-risk for unexcused). `not_counted` = that session is excluded for that player: not in expected, score, %, ranking, alerts. Every status (incl. custom) still shown as its own column/count in breakdowns. Custom codes take no minutes and no reason. Injury spells unchanged (base statuses only).
- Codes unique case-insensitive across base + custom; same regex as base (`^\p{L}{1,3}$`). Grid/paper code parser understands custom codes.
- Settings page: codes table gets "Add code", per-row edit, hide/show, delete (blocked with a clear message when marks use it). Validation in Form Requests.

### A2 Roster status sets
- Settings: `roster_status_ids` in attendance settings (multi-select of player statuses).
- `training_sessions.roster_status_ids` json nullable; null = use settings default at view time.
- `Roster::expected()` adds `whereIn('status_id', set)` (on top of category, archived, left_at). Frozen rosters (after first save) unchanged.
- Add-session dialog (calendar page) gets the status checkboxes, pre-checked from settings.
- Session page candidates: non-archived players, any status/category, not left before session date, not already on roster; searchable by full name; show status label.

### A3 Full names — as decision 2. Load the columns `Player::fullname` needs (nickname, father, grandfather) in `Roster::COLUMNS`, `MonthSheet::PLAYER_COLUMNS`, candidates; replace `trim("lastname firstname")` and `AttendanceSheetController::name()`. Reuse `PlayerNames`/accessor; no second formatter.

## Stream B — calendar speed (branch `perf/attendance-calendar`)
Found in code (dev DB too small to show it; desktop = single-request `php -S`, SQLite WAL):
1. `SessionGenerator::forMonth` runs on **every** calendar GET and always opens a write transaction (unconditional `insertOrIgnoreUsing`); week/agenda/timeline loop it over every category × month (timeline 12 months ≈ 375 queries). **Fix:** generate a (category, month) only when its inputs changed since last generation (marker per category+month with a version/signature, bumped by any change to schedules, closures, category set, moved/cancelled sessions, season settings). Repeat GET with nothing changed = **zero writes**. `generateAll` must not re-query schedules per category.
2. No index starting with `training_sessions.date` → add one.
3. `Vite::prefetch(concurrency: 3)` prefetches ~146 chunks into the single-request desktop server right after load, queuing the calendar XHR behind them → disable prefetch when `config('nativephp-internal.running')`.
4. Calendar navigation does full Inertia visits; use partial reloads (`only`) where the page allows, without breaking first load.
Tests: query-count / no-write assertions for month, week, agenda, timeline; regeneration after schedule change still works.
Not fixable here (vendor): NativePHP `fireUpQueueWorkers()` HTTP call + wildcard event listener per request.

## Stream C — multi-select filters (branch `feat/multiselect-filters`)
- New reusable `MultiSelectFilter.vue` (checkbox dropdown, search inside when many options, "n selected" summary, clear; RTL-safe; no new npm dependency — offline rule). Keyboard + click-outside close.
- `useListFilters`: array values; empty array = empty; localStorage restore keeps arrays. Query format `key[]=a&key[]=b`; backend also accepts a scalar for old links (dashboard drill-downs, bookmarks).
- Backend: each filter `whereIn` (OR within filter). Players: `wilaya_id` keeps `none` meaning null (mixable). Status default excludes `left` (decision 4). `filters` echo returns arrays.
- Players `StatDoughnut` clicks toggle a value in the array; selected slices highlighted.
- Board-table / academic print buttons need exactly one category: enabled only when exactly one category is selected.
- Other lists: Transactions (type, finance category, account), Users (status, role), Subscriptions (branch, kind, year), Equipment catalog (category).
- Tests: multi-value, scalar backward-compat, left hidden by default + shown when selected, export honours arrays.

## Cross-cutting constraints
- i18n: flat dotted keys, `t()` never `te()`, add via `node scripts/i18n-add.mjs`, ar+fr+en, `npm run i18n:check`.
- BOM files (e.g. `resources/js/Pages/Players/Index.vue`): keep the BOM, targeted edits.
- Permissions: route-name-derived; new routes need correct action mapping (`config/permissions.php` overrides).
- Offline only: no CDN, no remote fonts, no new runtime network calls.
- Stage explicit files; trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Never `artisan migrate --env=testing`. Tests: `php artisan test` (phpunit uses sqlite :memory:).
- Deploy: `php artisan migrate`; refresh `storage/app/seed/database.sqlite` before desktop build.
