# Player Previous / Manual Debts — Design

**Date:** 2026-07-16
**Status:** Approved

## Problem

Some players carried debts from before the app / before subscription management existed. Admins
need to record these debts against a player without knowing which subscription they came from, and
track them through the lifecycle: **unpaid → partial → paid**, plus **exempt** (waived but kept on
record).

## Decision

Reuse the existing `player_subscriptions` obligation mechanism instead of introducing a new `debts`
table. A "previous debt" is a `player_subscriptions` row with no subscription plan attached
(`subscription_id = null`), flagged `is_legacy = true`. This inherits — with no new machinery — debt
counting (`calculateTotalDebt` / `outstanding_debt` / dashboards / top-debtors), partial payments,
and the derived payment status (`unpaid`/`partial`/`paid`/`exempt`).

Rejected alternative: a dedicated `debts` table + model + service. It would duplicate the debt
recompute, payment linking, status resolution, and dashboard queries, and create two parallel debt
systems to keep in sync. No benefit for this use case.

## Data model

One migration: add a nullable `label` (string) column to `player_subscriptions`. Every other needed
column already exists (`year`, `due_date`, `amount_owed`, `amount_paid`, `is_legacy`, `is_mandatory`,
`is_exempt`). Add `'label'` to `PlayerSubscription::$fillable`.

A previous-debt row:

| column          | value                              |
|-----------------|------------------------------------|
| `subscription_id` | `null`                           |
| `is_legacy`     | `true`                             |
| `is_mandatory`  | `true`                             |
| `is_exempt`     | `false` (or `true` if waived)      |
| `label`         | e.g. `"Old dues 2023"` (required)  |
| `year`          | e.g. `2023` (required)             |
| `due_date`      | optional                           |
| `amount_owed`   | remaining owed (required, > 0)     |
| `amount_paid`   | `0`                                |

**Only the remaining owed amount is entered.** Anything paid before the app existed is not recorded
(avoids inflating current-period income under the cash-basis rule). A new debt therefore starts
`unpaid`.

## Status

Status is **derived, never stored** — `PlayerSubscription::getPaymentStatusAttribute()` already
returns:

- `exempt` — when `is_exempt = true` (`remaining_amount` forced to 0 → excluded from total debt)
- `paid` — `remaining ≤ 0` and `amount_paid > 0`
- `partial` — `amount_paid > 0`, still owing
- `unpaid` — `amount_paid = 0`

`is_mandatory = true` + `is_exempt = false` → the debt counts in `calculateTotalDebt()` and flows
into `outstanding_debt`, dashboard KPIs, and top-debtors with no changes to those queries.

**Exempt** debts stay visible in the obligations table (with an `exempt` badge) but drop out of the
owed total — waived, not paid, and kept on record.

## Backend

- **New route:** `POST players.subscriptions.store` → `PlayerSubscriptionController@store`.
  Validates `label` (required, string), `amount_owed` (required, numeric, > 0), `year` (required,
  integer), `due_date` (nullable, date), `is_exempt` (nullable, boolean). Creates the row above with
  `subscription_id = null`, `is_legacy = true`, `is_mandatory = true`, `amount_paid = 0`,
  `status_at_time` per the player's student/worker flag, then calls
  `RecalculatePlayerDebtService::forPlayer($player)`.
- **Extend `PlayerSubscriptionController@update`:** also accept `label` and `year` so previous debts
  are editable. `is_exempt` and `due_date` are already handled, so flipping a debt to/from exempt
  already works.
- **`destroy()` unchanged:** its existing guard blocks deletion while non-archived payments exist.

## Paying a debt down

No server-side payment change. `PlayerTransactionController::store` already accepts
`player_subscription_id` and `resolvePlayerSubscription()` already resolves it, so a payment against a
manual debt runs the same subscription-payment path: records real income now, updates `amount_paid`
via the `TransactionObserver` → `RecalculatePlayerDebtService::forSubscription`, and moves the status
to partial/paid. Overpayment beyond remaining already splits into a player-level donation
(existing behavior).

## Frontend (`resources/js/Pages/Players/Show.vue` only)

1. **Obligations table rendering:** name cell → `sub.label || sub.subscription?.name || '-'`; year
   cell → `sub.subscription?.year || sub.year`. (Both currently read only `sub.subscription?.*`, so
   manual debts would render blank — this is the fix.)
2. **"Add previous debt" button + modal:** fields label, amount owed, year, due date, and an
   **Exempt** checkbox; posts to `players.subscriptions.store`.
3. **Edit modal:** extend to include `label` and `year` (already handles `amount_owed`, `is_exempt`,
   `due_date`).
4. **Payment modal picker:** include the player's manual debts as payable options; when one is
   selected, send `player_subscription_id` (not `subscription_id`). Requires the page payload to
   expose null-subscription obligations (`player_subscription_id`, `label`, `year`,
   `remaining_amount`) — `PlayerController::show` already eager-loads `playerSubscriptions.payments`.
5. **Badge:** confirm `statusColor('exempt')` is handled (subscriptions already use `exempt`).

## Error handling

- Server validation: label/amount/year required, amount > 0.
- Deletion blocked while non-archived payments exist (existing guard).
- Overpayment splits into a donation (existing behavior).

## Testing (feature tests, mirroring `SubscriptionBillingTest`)

1. Creating a previous debt raises the player's `outstanding_debt` and the row shows `unpaid`.
2. A payment moves it `unpaid → partial → paid` and records income.
3. Removing all payments reverts `amount_paid` and status.
4. Deletion is blocked while a non-archived payment exists.
5. Marking a previous debt `exempt` drops it from `outstanding_debt` while the row stays listed with
   an `exempt` badge.
6. A manual debt appears as a payable option in the payment flow (payment via
   `player_subscription_id` succeeds).

## Out of scope

- No global/dedicated debts page — per-player only (on `Players/Show.vue`).
- No recording of pre-app partial payments as income.
- No new debt table, model, or service.
