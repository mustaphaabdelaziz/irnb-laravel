# Subscription kinds, per-category prices, bulk transaction delete, deletable fiscal years

Date: 2026-09-24 · Branch: `feat/subscription-kinds`

## Decisions (owner, 2026-09-24)

| Topic | Decision |
|---|---|
| Kinds | `annual` (has a season, shown "2025/2026") and `exceptional` (no year — e.g. club t-shirt) |
| Annual year | Follows the club season (`seasonStartMonth` setting) |
| Existing data | Old `year = 2026` reads as season **2025/2026** |
| Pricing | Default student + worker price, plus an optional student + worker override per attached category |
| Exceptional | Never auto-assigned; assigned by hand; **not** counted in player debt (always optional) |
| Exceptional pricing | Same model as annual (default + per-category overrides) |
| Bulk delete tx | Archive the selected rows; rows in a closed fiscal year are skipped and counted in the flash |
| Hidden delete button | Actions column pinned to the end edge (sticky, RTL-safe), edit/delete as icons |
| Fiscal year delete | Allowed when the year is open and has no active (non-archived) transaction. Its archived transactions are purged; its budget lines go with it (FK cascade) |

## Data model

- `subscriptions.kind` string, default `annual`.
- `subscriptions.year` becomes nullable. For annual subscriptions it keeps holding the **season end year**
  (2026 = season 2025/2026). This matches the owner's reading of existing rows with no data migration, and keeps
  `player_subscriptions.year` (dashboard stats, overdue fallback `year-12-31`) meaning what it meant.
  Exceptional subscriptions store `null`.
- `category_subscription.amount_student` / `amount_worker` nullable decimals — a null falls back to the
  subscription default.
- `Subscription::amountFor(Player)` reads the player's category override first.
- `Subscription::year_label` (appended): `"2025/2026"`, or `"2026"` when the season is the calendar year, or `null`
  for exceptional. `designation` uses it.
- `assignTo()` on an exceptional subscription writes `is_mandatory = false` and `year = current calendar year`
  (the obligation row's year column is not nullable and dashboards group by it).
- Exceptional names must be unique among exceptional subscriptions (the DB unique on `(name, year)` does not
  catch `NULL` years).

## Transactions

- `POST /transactions/bulk-destroy` → `transactions.bulkDestroy`, permission override `['transactions', 'delete']`.
- Archives in one DB transaction with observer events muted, then recomputes once: account balances, each touched
  fiscal year, each touched player subscription.
- Index: checkbox column + bulk bar (reuses `useBulkSelection`), sticky icon actions column.

## Fiscal years

- `DELETE /finance/years/{fiscalYear}` → `finance.years.destroy` (derived `finance/delete`).
- Refused when closed or when any non-archived transaction belongs to it (by FK or by the integer `fiscal_year`).
- Settings years table shows a delete icon only on deletable years (`can_delete` from the controller).
- Caveat: a later transaction dated in a deleted year recreates it with opening balance 0.
