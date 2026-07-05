# Transactions Enhancement — Design

Date: 2026-07-03
Status: Approved (scope confirmed)

## Problem

The Transactions section (`Create` / `Edit` / `Index`) is disconnected from the
finance backbone that already exists in the database. Symptoms:

- Category is a hardcoded string enum (`subscription, donation, equipment, salary,
  debt_payment, other`) shared across income **and** expense — not managed, not
  separated by type.
- A full income/expense category system (`finance_categories`, with CRUD controller
  `FinanceCategoryController` and management UI in `Finance/Settings.vue`) already
  exists but the transaction forms never receive or use it.
- Payment method is a plain `<select>` (cash/bank/ccp/other) with no fields to capture
  a CCP number, clé, or bank details.
- Player selection is a plain non-searchable `<select>`.
- The index has no financial summary (income / expense / net / debts).

## Goal

Wire the Transactions UI to the existing finance system and close the five gaps the
user asked for:

1. Income categories separated from expense categories.
2. Categories manageable (create / edit / delete) from the transactions area.
3. CCP / bank transfer selection reveals extra fields (CCP number for the Algerian
   money system, bank details).
4. Searchable player input.
5. Financial statistics on the index (total income, total expense, net, debts).

## Existing building blocks (reuse, do not rebuild)

- Table `finance_categories`: `type` enum(`income`,`expense`), `name`, `code`, `color`,
  `sort_order`, `is_active`, `is_system`, `parent_id`. Unique `[type, name]`. Seeded
  with 7 income + 9 expense system categories.
- `FinanceCategoryController@store/update/destroy`; routes `finance.categories.*`
  (admin-only). Destroy already blocked when transactions reference the category.
- Table `finance_accounts` (cash/bank/other, `account_number`, balances).
- `Transaction` already has FKs `finance_category_id`, `finance_account_id`,
  `fiscal_year_id` + relations.
- `TransactionObserver`: on save, if `finance_category_id` is empty it firstOrCreates a
  category by name; also recomputes fiscal-year totals and account balances. Setting
  `finance_category_id` explicitly bypasses the firstOrCreate branch; the recompute
  stays and is desired.
- `players.outstanding_debt` (decimal, denormalized) + `Player::calculateTotalDebt()`.
- Club's own CCP details in `website_configs.banking_info.ccp` (`accountNumber`,
  `key`, `holder`), surfaced via `WebsiteConfig::getCcpFormattedAttribute()`.
- `BackfillFinance` console command (exists) to link legacy rows.
- `Finance/Index.vue` dashboard (all-time totals, per-year breakdown, monthly trend,
  budget vs actual) — stays the home for deep analytics.

## Decisions (confirmed with user)

- **Categories**: wire transactions to `finance_categories` (not a second system).
- **CCP/bank fields**: per-transaction reference details (counterparty/source of the
  payment), stored on the transaction.
- **Statistics**: a filtered stat bar on the index (income/expense/net reflect active
  filters; debts is a global figure).
- **Accounting model**: cash-basis (income = cash received; subscriptions are debts,
  not income). See §G.
- **Historical bill rows**: auto-clean via a one-time command. See §G.
- **Debt scope (club policy)**: only **mandatory** subscriptions count as debt.
  Optional subscriptions are only assigned when the admin chooses, and their unpaid
  balance is tracked/displayed but is **not** counted as debt. See §G.
- **Out of scope (YAGNI, confirmed)**: server-side player search, category hierarchy
  UI, and any player-payment-modal rework beyond sharing the payment-method list.

---

## Design

### A. Categories wired to `finance_categories`

**Backend**
- `TransactionController@create` / `@edit`: pass
  `financeCategories = FinanceCategory::where('is_active', true)
  ->orderBy('type')->orderBy('sort_order')->orderBy('name')
  ->get(['id','type','name','color'])`.
- `StoreTransactionRequest`:
  - `finance_category_id` → `required`, `exists:finance_categories,id`.
  - Add a rule (closure or `Rule`) asserting the chosen category's `type` equals the
    submitted `transaction_type`.
  - Drop the old `category in:...` rule. Keep `category` producible server-side.
- `@store` / `@update`: after validation, set the legacy `category` string from the
  chosen category name (e.g. `Str::slug(name, '_')` or lowercased name) so the
  NOT-NULL `category` column, legacy index display, and the `PlayerSubscription`
  donation check (`transaction->category === 'donation'`) keep working. Persist
  `finance_category_id` explicitly.

**Frontend (shared form, see §F)**
- Bind a `finance_category_id` field.
- Options computed from `financeCategories` filtered by `form.transaction_type`.
- Watch `transaction_type`: if the currently selected category's type no longer
  matches, reset `finance_category_id` (to empty or the first matching option).
- Render an optional color dot next to each option label where practical.

**Backfill / fallback**
- Old rows with null `finance_category_id`: index/show display fall back to the string
  `category`. Run `BackfillFinance` to link them (documented as a one-time step; not a
  code change in this feature).

### B. Manageable categories (create / edit / delete)

- New component `CategoryManager.vue` (modal), reusing existing `finance.categories.*`
  routes:
  - Two columns: **Income** | **Expense**.
  - Each row inline-editable: name, color, active toggle → `PUT finance.categories.update`.
  - "Add" row at the bottom of each column → `POST finance.categories.store`
    (`type` set by column).
  - Delete button → `DELETE finance.categories.destroy`; surface the server's in-use
    error (category referenced by transactions) as a toast/inline message.
- Launch points: a "Manage categories" button on `Transactions/Index.vue` and a small
  "+ manage" affordance next to the category field in the form. Both **admin-only**
  (guard on the same ability that gates `finance.categories.*`); hidden for non-admins.
- The same `CategoryManager.vue` can later replace the ad-hoc category block in
  `Finance/Settings.vue`, but that swap is optional and not required for this feature.
- After a successful mutation, refresh the category list the form uses (Inertia partial
  reload of `financeCategories`, or reload the modal's own list + emit an update).

### C. CCP / bank conditional fields

**Migration** (new, additive, nullable — no drops):
- `payment_ccp_key` (string, nullable) — CCP clé.
- `payment_bank_name` (string, nullable) — bank name.
- `payment_holder` (string, nullable) — account holder name.
- `payment_reference` (string, nullable) — transfer reference / note.
- Reuse existing `payment_account` (string) for the primary number: CCP account number
  or bank RIB.

**Model**: add the four new columns to `Transaction::$fillable`.

**Frontend (shared form)**
- Payment-method list becomes: `cash`, `bank`, `ccp`, `baridimob`, `other` (adds
  `baridimob` to match the player payment modal; keeps drift out).
- `payment_method === 'ccp'` → show: CCP number (`payment_account`), clé
  (`payment_ccp_key`), holder (`payment_holder`).
- `payment_method === 'bank'` → show: bank name (`payment_bank_name`), account/RIB
  (`payment_account`), holder (`payment_holder`), reference (`payment_reference`).
- Optional convenience: a "use club CCP" button that prefills the CCP fields from the
  club config (`banking_info.ccp`), passed to the form as a prop from the controller.
- Switching payment method away from ccp/bank clears the now-hidden fields on submit.

**Validation** (`StoreTransactionRequest`): all four new fields + `payment_account`
`nullable|string|max:255`.

### D. Searchable player

- New reusable component `SearchableSelect.vue` (pure Vue 3, no external dependency, no
  CDN — CSP-safe):
  - Props: `modelValue`, `options` (array of `{value, label}`), `placeholder`,
    `disabled`.
  - Text input filters options client-side (case-insensitive, matches label).
  - Keyboard: ArrowUp/Down to move, Enter to select, Esc to close; click to select;
    clearable (× button) to reset to empty.
  - Emits `update:modelValue`.
- Use it in the shared transaction form for player selection, mapping players to
  `{value: id, label: fullname || "firstname lastname"}`. Bound to `related_entity_id`
  (with `related_entity_type = 'Player'` set on submit as today).

### E. Filtered stats bar (index)

**Backend** (`TransactionController@index`)
- Build the filtered query once (existing filter logic), then before pagination compute
  on a clone:
  - `income = (clone $query)->where('transaction_type','income')->sum('amount')`
  - `expense = (clone $query)->where('transaction_type','expense')->sum('amount')`
  - `net = income - expense`
- `debts = (float) Player::where('archived', false)->sum('outstanding_debt')` (global,
  independent of transaction filters). `outstanding_debt` is now a live recomputed
  cache — see §G. Income/expense sums are correct once the bill transactions are
  removed (§G, F1): every remaining income row is a real payment.
- Pass `stats = { income, expense, net, debts }` to the view.
- Eager-load `financeCategory` on the paginated list; support filtering by
  `finance_category_id` (in addition to / replacing the legacy string `category`
  filter).

**Frontend** (`Transactions/Index.vue`)
- Four stat tiles above the filters: Total Income (emerald), Total Expense (rose), Net
  (color by sign), Outstanding Debts (amber) — reuse the existing stat-tile styling
  used on the finance/dashboard pages, formatted with `useFormatMoney`.
- Debts tile labeled to make clear it is a global figure, not filter-scoped.
- Category filter dropdown lists finance categories (optionally grouped by type);
  category column shows `financeCategory?.name` with its color dot, falling back to the
  legacy string.

### F. Scoped cleanup (files touched anyway)

- Extract a single `Transactions/Partials/TransactionForm.vue` from the near-duplicate
  `Create.vue` / `Edit.vue`; both pages render it with an `isEdit`/`transaction` prop.
  All new conditional logic (category filtering, CCP/bank fields, searchable player)
  lives in one place.
- Add i18n keys to `en.json`, `fr.json`, `ar.json` for the new labels: CCP number, CCP
  clé, bank name, holder, reference, baridimob, manage categories, total income, total
  expense, net, outstanding debts, "use club CCP", search player, etc.

### G. Subscription & payment correctness (F1–F6)

The player subscription/payment flow has correctness bugs discovered during design.
Decision (confirmed): **cash-basis** accounting + **auto-clean** the historical bill
rows. Income = money actually received; a subscription is a debt until paid and is
never itself an income transaction.

**Target model (simple, single source of truth)**
- A `PlayerSubscription` is the obligation: `amount_owed`, `amount_paid`, `status`,
  and a snapshotted `is_mandatory` (from the subscription at assignment time).
- A **payment** is an `income` `Transaction` linked to its subscription by a new FK.
- `amount_paid` = SUM of that subscription's linked, non-archived payment amounts —
  **recomputed**, never hand-incremented. Applies to all subs (mandatory + optional).
- `remaining` = `max(0, amount_owed - amount_paid)`; a subscription with any linked
  `donation` payment is **exempt** (remaining 0). Shown per-row for every sub.
- `status` = `ResolvePaymentStatusService(amount_paid, amount_owed, isExempt)`.
- **Debt** counts only **mandatory** subscriptions (club policy). Optional subs are
  tracked and their remaining is displayed, but excluded from the debt total.
- `players.outstanding_debt` = SUM of `remaining` across the player's **mandatory,
  non-exempt** subscriptions — a **live recomputed cache** read by *all four* debt
  readers: players index, player show, dashboard, and the transactions stats bar (one
  rule, four readers, no drift).

**Schema**
- Migration: add `transactions.player_subscription_id` (nullable FK →
  `player_subscriptions`, `nullOnDelete`), indexed. Add it to `Transaction::$fillable`.
- Migration: add `player_subscriptions.is_mandatory` (boolean, default true), snapshot
  of the subscription's flag at assignment. Backfilled from `subscriptions.is_mandatory`
  for existing rows by the cleanup command. Add to `PlayerSubscription::$fillable`.
- Keep `player_subscriptions.transaction_id` for now (back-compat) but it is no longer
  authoritative; the new FK on transactions is.

**Recompute service + observer**
- New `App\Services\Finance\RecalculatePlayerDebtService`:
  - `forSubscription(PlayerSubscription $sub)`: recompute `amount_paid` (sum of linked
    non-archived income payments), exemption, `status`; save the sub; then recompute
    the owning player's `outstanding_debt`.
  - `forPlayer(Player $player)`: recompute `outstanding_debt` = SUM of `remaining` over
    the player's **mandatory, non-exempt** subscriptions.
- `TransactionObserver@saved/deleted/restored`: if the transaction has a
  `player_subscription_id`, call `forSubscription` on it. (This is in addition to the
  existing fiscal-year-total and account-balance recompute the observer already does.)

**Fixes mapped**
- **F1** — the full-amount `Unpaid` income "bill" is created in **three** places:
  `RegisterPlayerService`, `SubscriptionController@assign`, and
  `SubscriptionController@assignOne`. All three stop creating the bill transaction and
  create the `PlayerSubscription` only (`amount_paid = 0`, `status = Unpaid`,
  `is_mandatory` snapshot). No income until a real payment exists.
- **F2** — one debt rule for **all four** readers (players index, player show,
  dashboard, transactions stats bar): they read the recomputed `outstanding_debt`
  cache. `Player::calculateTotalDebt()` is the single recompute function that sets it;
  the `remaining_amount` accessor uses the same remaining rule for per-row display. No
  more index-vs-show-vs-dashboard disagreement.
- **F3** — `PlayerTransactionController@update`: after updating the transaction, the
  observer recompute resyncs `amount_paid`/`status`/`outstanding_debt`. Remove the
  stale manual math.
- **F4** — archiving/deleting a payment triggers the observer recompute → debt drops
  correctly.
- **F5** — payments now link via `player_subscription_id` (one sub → many payments);
  `amount_paid` is derived, so no single-`transaction_id` overwrite problem. Donation
  exemption is evaluated over all linked payments, not one last link.
- **F6** — `players.outstanding_debt` is recomputed on every payment change and is the
  read path everywhere (fast `SUM` for the stats bar).
- **Debt scope** — `outstanding_debt` and all four readers count only mandatory,
  non-exempt subscriptions; `DashboardController`'s `unpaidPlayers` becomes
  `Player::where('archived', false)->where('outstanding_debt', '>', 0)->count()`.

**`PlayerTransactionController@store`** — set `player_subscription_id` on the created
payment; drop the manual `amount_paid += amount` (observer recomputes it). Keep the
`fiscal_year`, `related_entity`, and status wiring.

**Subscription assignment** — `RegisterPlayerService`, `assign`, `assignOne` create the
`PlayerSubscription` (with `is_mandatory` snapshot) and then call
`RecalculatePlayerDebtService::forPlayer` so the debt cache reflects a newly assigned
mandatory subscription. Assigning an optional sub leaves `outstanding_debt` unchanged.

**One-time cleanup command** (`php artisan finance:fix-subscription-billing`, or extend
`BackfillFinance`). **Dry-run by default**; requires `--force` to write. Prints every
count (bills to delete, links made, reconciliation mismatches) before acting.
1. Identify the subscription **bill** transactions — marker: `transaction_type =
   'income'` AND `category = 'subscription'` AND `status = 'Unpaid'`. Exact: a recorded
   payment is never `Unpaid` (its paid amount is always > 0). Note this also matches
   legacy-imported Unpaid subscription rows, which under cash-basis are likewise not
   real income and should go. Delete only under `--force`.
2. Backfill `player_subscriptions.is_mandatory` from `subscriptions.is_mandatory`.
3. Link each surviving payment transaction (income, not `Unpaid`, related to a Player)
   to a subscription — set `transactions.player_subscription_id` — by matching
   `player_id` + `fiscal_year` (payments already stamp `fiscal_year = sub.year`),
   preferring the mandatory sub on ties, falling back to the existing
   `player_subscriptions.transaction_id` back-reference. Report any payment that stays
   unresolved or ambiguous.
4. Recompute `amount_paid` (= sum of linked payments), `status`, and `outstanding_debt`
   for every player via `RecalculatePlayerDebtService`. **Reconciliation guard**: report
   every player whose recomputed `amount_paid` differs from the previously stored value
   (surfaces any historical link ambiguity for manual review rather than silently
   changing a balance).
Idempotent and safe to re-run.

## Data & compatibility notes

- `category` (string, NOT NULL) and `payment_method`/`payment_account` columns are kept;
  no destructive schema change. New payment columns are additive and nullable.
- Legacy transactions render via string fallback until `BackfillFinance` links them.
- The observer's recompute of year totals / account balances is unchanged and still
  fires on save.

## Testing

- Feature test: storing a transaction with `transaction_type=income` and an **expense**
  `finance_category_id` fails validation (type mismatch).
- Feature test: store sets both `finance_category_id` and a derived legacy `category`
  string; index stats return correct income/expense/net for a filtered set.
- Feature test: CCP fields persist when `payment_method=ccp`.
- Component test (or manual): `SearchableSelect` filters and selects by keyboard;
  `CategoryManager` create/edit/delete round-trips and surfaces the in-use delete guard.
- Feature test (F1): registering a player creates a subscription but **no** income
  transaction; total income stays 0 until a payment is recorded.
- Feature test (F5/F3): two payments on one subscription set `amount_paid` to their
  sum; editing one payment's amount resyncs `amount_paid`, `status`, and the player's
  `outstanding_debt`.
- Feature test (F4): archiving a payment reduces `amount_paid` and raises debt again.
- Feature test (F2/F6): index `total_debt`, show `totalDebt`/`outstanding_debt`, and
  the transactions stats-bar debts all return the same value for the same data.
- Feature test (cleanup): the fix command removes bill rows, preserves real payments,
  and is idempotent (second run changes nothing).
- Feature test (debt scope): assigning an **optional** subscription (via `assignOne`)
  adds a tracked sub but does **not** increase `outstanding_debt`; assigning a
  **mandatory** one does. No income transaction is created by either.

## Out of scope (confirmed)

- Server-side player search.
- Category hierarchy / sub-category UI (the `parent_id` column stays unused here).
- Player payment modal rework beyond aligning its payment-method list.
