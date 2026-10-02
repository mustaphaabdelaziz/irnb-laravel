# Multi-select filters — plan (Stream C, 2026-10-02)

Spec: `docs/superpowers/specs/2026-10-02-attendance-and-filters-batch-design.md` (Stream C, decisions 4 + 5).

## Wire format
- Inertia 2.3 `router.get` serialises arrays with qs `brackets` (`key[]=a&key[]=b`); Ziggy `route()` (export links) uses `indices` (`key[0]=a`). PHP parses both into arrays.
- Old links / bookmarks / dashboard drill-downs send scalars (`?academic=at_risk`) — still accepted.

## Backend
1. `App\Support\ListFilter` — `values($request, $key)` (scalar or array → trimmed, non-empty, unique strings, capped at 100), `ids()` (positive ints only), `only($request, multi, scalar)` for the `filters` echo (multi keys always echoed as lists).
2. Players `applyPlayerFilters`: every select filter → `whereIn` / OR group; special values stay (wilaya `none`, documents `missing|expiring|missing-{id}`, age buckets, academic buckets, certificates) — OR of their conditions inside one nested where; AND across filters.
3. Status default (decision 4): no status selected → exclude code `left` (NULL status kept). Selected → exactly those. Lives in `applyPlayerFilters` so list, charts and export agree. The status chart drops its own filter AND the default, so it still shows a Left slice to click.
4. Transactions (type, finance_category_id, finance_account_id + the URL-only category/fiscal_year/status), Users (status OR, role OR), Subscriptions (branch, kind, year), Equipment catalog (category).

## Frontend
1. `Components/MultiSelectFilter.vue` — checkbox dropdown, no dependency: summary (placeholder / single label / "n selected"), search when > 8 options, select all + clear, option groups, Esc / click-outside / tab-away close, arrow keys between checkboxes, logical (RTL-safe) classes.
2. `useListFilters`: empty array = empty; `asList()` helper to init refs from scalar or array props.
3. `StatDoughnut`: array `modelValue` toggles membership (scalar usage unchanged).
4. Players page: all select filters + chart clicks multi; localStorage restore keeps arrays and tolerates old scalars; board-table / academic print get the category only when exactly one is selected.
5. Other pages: Transactions, Users, Subscriptions, Equipment catalog.

## Tests (TDD)
- New `PlayerMultiSelectFiltersTest`: multi values per filter, scalar compat, left hidden by default / shown when selected, null status kept, stats + export follow the default, status chart still lists Left, mixed special values.
- New tests for Transactions / Users / Subscriptions / Equipment multi + scalar + export.
- Update scalar `filters.*` echo assertions to lists.

## Checks
`npm run i18n:check`, `npm run build`, full `php artisan test`.
