# Transaction cancellation (replace delete/remove) — design

**Date:** 2026-09-19
**Status:** Approved

## Problem

Money records can be made to disappear by a single mis-click:

- Transactions page **Delete** (`transactions.destroy`) sets `archived = true`; the row vanishes
  from the list and from every total.
- Player page payment **Remove** (`players.transactions.destroy`) does the same.

Neither records a reason, who did it or when, and nothing in the UI shows the row again. A mistake
is invisible and cannot be traced.

## Goal

Transactions are never deleted or hidden. The only way to take one out of the books is an explicit,
permanent **Cancel** with a mandatory reason. Cancelled transactions stay visible, clearly marked,
and are excluded from every balance, total and debt calculation.

## Scope

In scope: general transactions (Transactions pages) and player payments (Players/Show payment
history), plus the internal paths that archive transactions (player payment edit, player permanent
delete).

Out of scope (unchanged): player subscription lines, register-to-register transfers, finance
categories/accounts in settings, CSV export (stays active-only, consistent with totals), in-place
edit of active general transactions.

## Decisions

| Question | Decision |
|---|---|
| Reason | Required, 3–500 characters |
| Reversible? | No. Cancel is final; to correct, record a new transaction |
| Visibility | Cancelled rows shown in lists, marked, excluded from all sums |
| Legacy archived rows | Shown as cancelled, reason label "Removed before cancel feature" |
| Permission | Same as the old delete: module `delete` action |

## Approach

Keep `transactions.archived` as the single "excluded from money" flag and add cancellation metadata
next to it. Cancel sets `archived = true` **and** the metadata.

Why: ~25 existing queries (FinanceService balances and fiscal totals, RecalculatePlayerDebtService,
Dashboard, Finance, Report, Subscription controllers) already filter `archived = false`, so they stay
correct untouched. Restoring an older backup also stays correct: its archived rows remain excluded.

Rejected:
- Replacing `archived` with `cancelled_at` everywhere — touches ~25 query sites, and restoring an
  older backup would resurrect archived money into the totals.
- Adding `Cancelled` to the `status` enum — mixes payment status (Paid/Partial/…) with lifecycle,
  loses the original status, and needs a table rebuild on SQLite.

`archived = true` on a transaction now means "cancelled". `cancelled_at` is always set for such
rows (backfilled for legacy ones); `cancel_reason` is null only for legacy rows.

## Backend

### Database

Migration `add_cancellation_to_transactions`:

- `cancelled_at` nullable timestamp.
- `cancelled_by_user_id` nullable foreign key → `users.id`, `nullOnDelete`.
- `cancel_reason` nullable text.
- Backfill: for rows with `archived = true`, set `cancelled_at = updated_at` (reason and user stay
  null).

### Model — `App\Models\Transaction`

- Add the three columns to `$fillable`; cast `cancelled_at` to `datetime`.
- `cancelledBy(): BelongsTo` → `User` via `cancelled_by_user_id`.
- Appended `is_cancelled` accessor = `(bool) archived`.

### Service — `App\Services\Finance\CancelTransactionService`

`cancel(Transaction $transaction, ?User $user, string $reason): void`

1. Throws `App\Exceptions\TransactionCancellationException` (carries a flash key + params) when
   the transaction is already cancelled (`flash.transaction_already_cancelled`). Controllers catch
   it and return `back()->with('error', ['key' => …, 'params' => …])`.
2. Throws the same exception when its fiscal year is closed (`flash.year_closed_cancel`, param
   `year`). This adds the closed-year guard to player payments, which lack it today.
3. Single `update` of `archived = true`, `cancelled_at = now()`, `cancelled_by_user_id`,
   `cancel_reason`. The existing `TransactionObserver::saved` recomputes account balances, the
   fiscal year and the linked subscription's `amount_paid`.
4. If the transaction belongs to a player (`related_entity_type = 'Player'`), runs
   `RecalculatePlayerDebtService::forPlayer`.

### Routes

- `Route::resource('transactions', …)` gets `->except(['destroy'])`.
- New `POST /transactions/{transaction}/cancel` → `TransactionController@cancel`, name
  `transactions.cancel`.
- `DELETE /players/{player}/transactions/{transaction}` removed; new
  `POST /players/{player}/transactions/{transaction}/cancel` → `PlayerTransactionController@cancel`,
  name `players.transactions.cancel`.
- No route can delete a transaction any more.

### Permissions

- `App\Support\PermissionMap::deriveAction`: `'cancel'` maps to `'delete'`.
- `config/permissions.php` overrides: replace `players.transactions.destroy` with
  `'players.transactions.cancel' => ['players', 'delete']`.

### Controllers

- `TransactionController@cancel(CancelTransactionRequest, Transaction)` → service, redirect back to
  `transactions.show` with `flash.transaction_cancelled`.
- `PlayerTransactionController@cancel(CancelTransactionRequest, Player, Transaction)` → ownership
  check, service, redirect to `players.show` with `flash.payment_cancelled`.
- `CancelTransactionRequest`: `cancel_reason` → `required|string|min:3|max:500`.
- `TransactionController@index`: drop the `archived = false` filter on the listing query; add a
  `state` filter (`active` → `archived = false`, `cancelled` → `archived = true`, absent → all);
  eager-load `cancelledBy:id,name`. Summary totals on the page keep excluding cancelled rows.
- `TransactionController@show`: eager-load `cancelledBy`.
- `TransactionController@edit/update` and `PlayerTransactionController@update`: refuse cancelled
  transactions with `flash.transaction_cancelled_readonly`.
- `PlayerController@show`: payment history query drops `archived = false`, eager-loads
  `cancelledBy:id,name`.

### Related paths

- Player payment **Edit** (today: archive + recreate): cancels the original through the service
  with reason `"Replaced by edit"` (i18n-independent stored text), then creates the new payment,
  inside the existing DB transaction. The service guards apply, so editing a payment in a closed
  fiscal year is refused.
- Player **permanent delete** (`PlayerController::permanentlyDelete`): instead of a bulk
  `archived = true`, sets `archived`, `cancelled_at`, `cancelled_by_user_id`, and reason
  `"Player permanently deleted"` on the player's still-active transactions (already-cancelled rows
  untouched). Closed-year guard does not apply here (the player is gone; the money must leave the
  books either way).

### Receipt PDF

`resources/views/pdf/receipt.blade.php`: when the transaction is cancelled, render a large
"CANCELLED / ANNULÉ / ملغى" stamp and the cancel date and reason, so a cancelled receipt cannot pass
as valid.

## Frontend

### `resources/js/Components/CancelTransactionModal.vue` (new)

Built on `Modal`. Props: `show`, `transaction`, `action` (URL). Shows date, amount, category, and the
warning "Cancellation is permanent. The amount will be removed from balances and totals. To correct
it, record a new transaction." Required **Reason** textarea; Confirm disabled until the trimmed
reason has ≥ 3 characters; posts with `useForm` and shows the `cancel_reason` validation error
inline; emits `close` on success.

### Transactions/Index.vue

- **Delete** button → **Cancel** (opens the modal); hidden on cancelled rows.
- Cancelled rows: muted text, struck-through amount, red **Cancelled** badge beside the status
  badge; `title` tooltip with reason, user and date (legacy label when reason is null).
- New **State** filter select: All (default) / Active / Cancelled, wired through the existing
  filter mechanism (`state` query param).

### Transactions/Show.vue

- Cancelled: red banner "Cancelled on {date} by {user} — {reason}" (legacy label / unknown user
  fallback); **Edit** hidden.
- Active: **Cancel** button next to Edit.

### Players/Show.vue — payment history

- Includes cancelled payments, styled like Index rows (badge, muted, strikethrough, tooltip).
- **Remove** → **Cancel** via the modal; Edit and Cancel hidden on cancelled rows.
- The old remove-payment `ConfirmModal` and its `remove_payment_warning` usage are removed.

### i18n

New keys in `ar.json`, `en.json`, `fr.json`: cancel button, modal title/warning/reason label/
placeholder, Cancelled badge, banner text, legacy reason label, State filter labels, flashes
(`flash.transaction_cancelled`, `flash.payment_cancelled`, `flash.transaction_already_cancelled`,
`flash.year_closed_cancel`, `flash.transaction_cancelled_readonly`). Keys unused after the change
(e.g. `flash.transaction_archived`, `flash.payment_removed`) are removed if nothing else uses them.
`npm run i18n:check` must pass. Flash keys are read with `t()` directly (see flat dotted keys note).

## Testing (Pest feature tests)

1. Cancel without reason → 422 / validation error; transaction unchanged.
2. Cancel sets `archived`, `cancelled_at`, `cancelled_by_user_id`, `cancel_reason`; account
   `current_balance`, fiscal-year totals, subscription `amount_paid` and player debt are recomputed
   without the amount.
3. Cancelling an already-cancelled transaction → error flash, metadata unchanged.
4. Cancel in a closed fiscal year → error flash, from both the Transactions route and the player
   route.
5. Edit / update of a cancelled transaction refused (both controllers).
6. Transactions index lists cancelled rows; `state=active` and `state=cancelled` filter correctly.
7. `DELETE /transactions/{id}` and `DELETE /players/{p}/transactions/{id}` no longer exist
   (405/404).
8. `transactions.cancel` requires `transactions.delete` permission; user without it → 403.
   `players.transactions.cancel` requires `players.delete`.
9. Player payment edit cancels the original with reason "Replaced by edit" and creates the new one.
10. Legacy archived row (archived, no reason) is returned in the index with `is_cancelled = true`
    and null reason; the migration backfill sets `cancelled_at`.
11. Player permanent delete cancels the player's active transactions with the fixed reason.
