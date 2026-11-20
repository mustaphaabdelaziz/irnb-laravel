# Transaction page: trace and history — design

Date: 2026-11-20
Status: approved by owner (design), spec awaiting review

## Problem

The transaction page (`Transactions/Show.vue`) shows what a transaction is, but
not how it came to be. The owner cannot see on the page who recorded it, at what
time, whether it was edited afterwards, or what it looked like before the edit.

Most of that information is already stored and simply not shown:

- `transactions.recorded_by_user_id` is filled on every row and loaded by
  `TransactionController::show()`, yet never rendered.
- `created_at` holds the exact time; the page shows only `transaction_date`,
  which has no hour.
- `activity_logs` holds an event per recording, edit and cancellation, with the
  user, the time and the impersonating admin (`impersonator_id`).
- Payment reference, bank, holder, CCP key, the uploaded receipt file, the
  payment group and the season are stored and invisible.

Three things are not stored at all:

- An edit records `transaction_updated` with empty properties: who and when,
  not what changed.
- Editing a player payment archives the old transaction and creates a new one
  (`PlayerTransactionController::update`). Nothing links the two.
- A cancelled transaction's page looks like a live one.

## Goal

Open one transaction and read its whole story: who recorded it and when, every
later edit with old and new values, cancellation or correction, and every
receipt printed — enough to diagnose a wrong amount or a disputed payment
without a database query.

## Decisions (owner, 2026-11-20)

- All four extras are in: history timeline, old → new values on edit, cancelled
  banner with correction link, hidden payment details.
- Visibility: everyone who can open the page (Transactions → view). No new
  permission. Same rule as the printed-receipts list today.
- Title and description edits keep the full old and new text.
- Old → new values are stored in a new table, not in the activity log, so they
  are deleted with their transaction and the activity log keeps its rule of
  holding no free text.

## Data

### New table `transaction_revisions`

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `transaction_id` | FK → `transactions` | cascade on delete |
| `activity_log_id` | FK → `activity_logs`, nullable | null on delete; gives who, when, impersonator |
| `diff` | JSON | changed fields only (not `changes`: Eloquent keeps its own `$changes`) |
| `created_at` | timestamp | fallback time when the log row is gone |

`diff` shape: `{ "<field>": { "old": …, "new": … } }`.

Tracked fields: `title`, `amount`, `transaction_date`, `transaction_type`,
`finance_category_id`, `finance_account_id`, `payment_method`,
`payment_account`, `payment_ccp_key`, `payment_bank_name`, `payment_holder`,
`payment_reference`, `status`, `description`.

Special cases:

- `finance_category_id` and `finance_account_id` also store `old_label` and
  `new_label` (the name at edit time). The page shows the live localized name
  while the row exists and the stored label once it is deleted.
- A replaced receipt file is recorded as `"receipt": { "replaced": true }` —
  never a file name.
- `transaction_date` is compared and stored by day (`Y-m-d`).
- Season fields (`fiscal_year`, `fiscal_year_id`) are not tracked: they follow
  the date.

### New column `transactions.replaces_transaction_id`

Nullable FK to `transactions`, null on delete. Set on every transaction that
`PlayerTransactionController::update` creates, pointing at the archived
original. Relations on `Transaction`: `replaces()` and `replacements()`.

No backfill. Past corrections were never linked and guessing would create wrong
links.

## Writing

### `TransactionController::update`

1. Inside one `DB::transaction`: snapshot the tracked fields, save, snapshot
   again and compare. Comparing after the save keeps the values the observer
   sets (a default register, today's date for an undated entry).
2. If at least one tracked field changed (or the receipt file was replaced),
   record `TRANSACTION_UPDATED` with the transaction as subject and create the
   revision row linked to that log row.
3. Nothing changed → no event, no revision.

`transactions.update` is removed from `config/activity.php`, so the
`RecordRouteActivity` middleware no longer records a second, empty event.

The comparison lives in a small class, `App\Services\Finance\TransactionChanges`
(`snapshot(Transaction): array`, `between(array $before, array $after): array`,
`withLabels(array $changes): array`), so it is testable without HTTP.

### `PlayerTransactionController::update`

The query that already re-attaches `payment_group` to the created rows also sets
`replaces_transaction_id`. It runs for every corrected payment, grouped or not.

### `FinanceResetService`

Deletes `transaction_revisions` before `transactions`, next to
`receipt_prints`. Year deletion relies on the cascade.

## Reading: `App\Services\Finance\TransactionHistory`

`entries(Transaction $transaction): array` returns the timeline, oldest first
(`trace()`, `cancellation()` and `group()` build the other props). The
controller passes it to the page; `show()` stays thin. Each entry:

```
type      recorded | correction | edited | cancelled | replaced | printed
at        'Y-m-d H:i' on the club clock
user      name of the acting user, or null
as_user   name of the account acted as, when an admin was logged in as another user
changes   edited / correction only: [{ field, old, new }]
links     correction / replaced only: [{ id }]
print     printed only: { copy_number, format, code }
```

Sources:

| Entry | Built from |
|---|---|
| `recorded` | The row itself: `created_at` + `recordedBy`. The impersonator comes from the matching log event (`transaction_recorded`, `payment_recorded`, `payment_edited`) when one exists. Imported rows and grouped-payment siblings have no log event and still get this line. |
| `correction` | Replaces `recorded` when `replaces_transaction_id` is set. Links to the original. When the original has exactly one replacement, `changes` lists amount, payment method, register and description compared with it; a split into several rows gets the link only. |
| `edited` | Each `transaction_updated` log on the transaction, with its revision's changes. Logs from before this feature have no revision: shown as edited, with user and time, no detail. |
| `cancelled` | Each `transaction_cancelled` log on the transaction. |
| `replaced` | An archived transaction with replacements: time and user of the first replacement, links to all of them. |
| `printed` | Each `ReceiptPrint` of the transaction (copy number, format, code, `printedBy`). |

Impersonation: the log stores the account acted as in `user_id` and the admin
in `impersonator_id`. The entry's `user` is the admin, `as_user` the account —
shown as «{admin} بصفة {account}».

The service loads the logs with their revision and users in one query each (no
N+1), and resolves category and register names in one query per kind.

`show()` also passes:

- `trace`: `{ recorded: {at, user, as_user}, modified: {at, user, as_user} | null }`.
  `modified` is the latest `edited`, `cancelled` or `replaced` entry. It is not
  `updated_at`, which bulk queries also touch.
- `cancellation`: for an archived transaction, `{at, user, as_user, replaced_by: [ids]}`
  from, in order: the cancel log, the replacement, else `updated_at` with no user.
- `group`: when `payment_group` is set, every transaction of the group with the
  same archived state (`id`, `title`, `amount`, `current` for this one) and the
  group total.
- `season`: `{ label, closed }`, read by a query — loading the `fiscalYear`
  relation would serialize it over the `fiscal_year` column of the same name.

The `prints` prop is removed: prints are `printed` entries of `history`. The
eager load of the recorder keeps name columns only, and `receivedBy` is no
longer sent.

## Page layout (top to bottom)

1. **Cancelled banner** — archived only. Rose panel: «ملغاة», who, date and
   time. With replacements: «صُحّحت بالمعاملة #N» links.
2. **Main card** — as today, plus:
   - Season, with a «مغلق» badge when the fiscal year is closed.
   - Payment details block, shown when any of account, CCP key, bank, holder or
     reference is filled.
   - Link to the uploaded receipt file when there is one.
   - «تصحيح للمعاملة #N» link when `replaces_transaction_id` is set.
   - Trace line under a divider: recorded by · date and time; last modified by ·
     date and time (hidden when never modified).
3. **Players card** — unchanged.
4. **Grouped payment card** — only when `group` has rows: each sibling as a link
   with its amount, then the total.
5. **History card** — replaces «الوصولات المطبوعة» and keeps
   `data-help="tx-prints"` (used by `resources/help/shots.js`) and the «التحقق
   من وصل» link. One line per entry: label, changes as `old → new`, user,
   date and time; print lines keep the receipt code.

Formatting:

- Money through `useFormatMoney`, dates through `useFormatDate`, time appended
  as `HH:mm`. Amounts and codes stay `dir="ltr"`.
- Enum values (type, status, payment method) through `useStatusLabel`.
- The timeline is a new component, `Transactions/Partials/TransactionHistory.vue`;
  the banner and trace line stay in `Show.vue`.
- Dark mode and 320 px follow the existing page classes.

## i18n and help

- New keys in ar, fr and en. Flat dotted keys, called with `t()` directly; no
  key segment named after a String method (`link`, `search`, …).
  `npm run i18n:check` must pass.
- Help Center: a new article, `finance/transaction-history` (who recorded a
  transaction and who edited it), in ar with its fr and en twins and one new
  screenshot. The receipts article names the card by its new heading. The two
  existing screenshots of the page are retaken.

## Limits

- Edits made before this ships show as «عُدّلت» with user and time, no values.
- Past player-payment corrections show no link between old and new.
- Receipt prints do not record an impersonator; print lines show the account.

## Out of scope

- Any change to the users activity pages or their counts.
- New columns or filters on the transactions list.
- Tracking edits made by imports, console commands or migrations.
- Equipment and transfer pages.

## Tests (PHPUnit classes, as the rest of `tests/Feature`)

- `TransactionChanges`: only changed tracked fields; amount `1000` vs `1000.00`
  is no change; date compared by day; category and register carry labels.
- Update with a change: one `transaction_updated` log (not two), one revision
  linked to it. Update with no change: neither.
- Update rejected by a closed season: no log, no revision.
- Player payment edit: created rows carry `replaces_transaction_id`, with and
  without a payment group.
- `TransactionHistory`: entry order; `recorded` exists for an imported row and
  for a grouped sibling; legacy `transaction_updated` log yields `edited`
  without changes; impersonated action yields `user` + `as_user`; archived row
  with replacements yields `replaced` with links.
- Deleting a transaction removes its revisions and nulls
  `replaces_transaction_id` on its replacements; finance reset leaves no
  revisions.
- Show page: a user with Transactions → view and no Users → view receives
  `trace`, `history`, `cancellation` and `group`.

## Deploy

Two migrations, run by `migrate` on this PC and at desktop app start. A new
desktop build is needed for the other PC.
