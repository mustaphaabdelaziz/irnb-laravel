# Player Payment Form + Subscription Exemption — Design

Date: 2026-07-05
Status: Approved (decisions confirmed)

## Problem

The player profile "Add Payment" modal (`resources/js/Pages/Players/Show.vue`) only
records subscription payments:

- The payment **category** is hard-coded to `subscription` in the Inertia form, so the
  UI can never record a `donation` or `debt_payment`, even though the backend already
  accepts all three (`PlayerTransactionController::store`).
- The subscription `<select>` lists **every** subscription, including fully-paid and
  exempt ones, which is noise when adding a payment.
- There is no first-class way to **exempt** a player from a subscription. Today the only
  path is a side effect of `PlayerSubscription::isExempt()`, which returns true when the
  subscription has a non-archived `donation` payment. That conflates "the club received a
  donation" (income) with "this member is waived from paying" (not income), and can't
  survive making donations player-level.

## Goal

1. Turn the Add-Payment modal into a small 3-way form: **Subscription payment**,
   **Donation**, or **Debt payment**.
2. In subscription mode, list all subscriptions (obligatory **and** optional) except
   fully-paid/exempt ones.
3. Make **donation** and **debt payment** player-level income, not tied to any
   subscription.
4. Add an explicit, first-class **exempt** toggle per subscription, decoupled from
   donation income.

## Confirmed decisions

- **Payment categories**: `subscription`, `donation`, `debt_payment`. Default
  `subscription`. Selected via a new "Payment category" `<select>` at the top of the modal.
- **Subscription mode**: subscription `<select>` is shown and **required**. Options =
  all `player_subscriptions` with `remaining_amount > 0` (this naturally excludes both
  fully-paid and exempt subs, since exempt → `remaining_amount = 0`). Both obligatory
  (`is_mandatory = true`) and optional (`is_mandatory = false`) subs appear; optional ones
  carry a `(optional)` marker in the label.
- **Donation**: player-level income. No subscription. Recorded as a `Transaction` with
  `category = donation`, `player_subscription_id = null`. Appears in existing donation
  totals (which key off `category = 'donation'`). Does **not** exempt anything.
- **Debt payment**: player-level income representing **legacy debt** (money owed to the
  club from before the app existed, e.g. pre-2015). No subscription, no allocation, does
  **not** change any subscription balance or the computed `outstanding_debt`. Recorded as
  a `Transaction` with `category = debt_payment`, `player_subscription_id = null`.
- **Amount** (donation/debt): free numeric input, no cap (backend keeps `min:0.01`).
- **Exemption**: explicit `is_exempt` boolean on `player_subscriptions`, toggled by a
  **check button labelled "Exempt"** on each subscription row (toggles exempt / un-exempt).
  An exempt subscription is not unpaid, not debt, and generates no income.

## Behavior detail

### Add-Payment modal (`Players/Show.vue`)

- Add `payment category` `<select>` bound to `paymentForm.category`
  (`subscription` | `donation` | `debt_payment`).
- Wrap the existing subscription `<select>` in `v-if="paymentForm.category === 'subscription'"`.
- On category change, when the new value is not `subscription`, clear
  `paymentForm.player_subscription_id`.
- New computed `payableSubscriptions`: `subscriptions.value.filter(s => parseFloat(s.remaining_amount ?? 0) > 0)`.
  Iterate this (not the raw `subscriptions`) for the options. Label:
  `name (year) — remaining: <money>` plus `(optional)` when `!s.is_mandatory`.
- Amount / payment method / description fields stay as-is for all categories.

### Backend `PlayerTransactionController::store`

- Validation:
  - `category` — `required|string|in:subscription,donation,debt_payment` (unchanged).
  - `player_subscription_id` — `required_if:category,subscription|nullable|integer|exists:player_subscriptions,id`.
  - `amount` — `required|numeric|min:0.01` (unchanged).
  - `payment_method`, `description` — unchanged.
- Branch on category inside the existing `DB::transaction`:
  - **subscription**: unchanged existing flow — `findOrFail` the sub, assert it belongs
    to `$player` (403 otherwise), compute status via `ResolvePaymentStatusService`,
    create the tied `Transaction`, set `playerSub.transaction_id`.
  - **donation / debt_payment**: create a player-level `Transaction`:
    `amount`, `transaction_date = now()`, `transaction_type = 'income'`,
    `category = $validated['category']`, `description`, `payment_method ?? 'cash'`,
    `related_entity_type = 'Player'`, `related_entity_id = $player->id`,
    `player_subscription_id = null`, `recorded_by_user_id = $request->user()?->id`,
    `status = 'Paid'`, `fiscal_year = (int) now()->year`.
    `TransactionObserver::saving` fills `fiscal_year_id`, `finance_category_id`,
    `finance_account_id`; its `recomputeSubscription` no-ops on the null
    `player_subscription_id`.

### Exemption

- **Migration**: add `is_exempt` boolean, default `false`, to `player_subscriptions`.
  Backfill `is_exempt = true` for every subscription that currently has a non-archived
  `donation` payment, so existing exemptions carry over. (After backfill, exemption no
  longer depends on donation payments.)
- **Model `PlayerSubscription`**:
  - Add `is_exempt` to `$fillable` and cast to `boolean`.
  - `isExempt()` becomes `return (bool) $this->is_exempt;`. `remaining_amount` and
    `payment_status` keep using `isExempt()`, so an exempt sub → remaining 0, status
    `exempt`, excluded from `calculateTotalDebt()`.
- **Route**: `PATCH /players/{player}/subscriptions/{subscription}/exempt`
  → `PlayerSubscriptionController::exempt`, name `players.subscriptions.exempt`.
  `{subscription}` is the `player_subscriptions` id.
- **Controller `PlayerSubscriptionController::exempt`**:
  - Guard: the subscription's `player_id` must equal `{player}` (403 otherwise).
  - Toggle `is_exempt` (`$sub->update(['is_exempt' => ! $sub->is_exempt])`).
  - `app(RecalculatePlayerDebtService::class)->forPlayer($player)` to refresh the cached
    `outstanding_debt`.
  - Redirect back to `players.show` with a success flash.
- **Frontend (subscriptions table in `Show.vue`)**:
  - Per row: a check button labelled **"Exempt"** (shows exempt state, e.g. filled/checked
    when `sub.is_exempt`) that `router.patch`es the exempt route.
  - Fix `paymentStatus(sub)` to return `'exempt'` when `sub.is_exempt` (checked before the
    remaining/paid logic), so the badge renders exempt (slate) rather than paid.

### i18n (en / fr / ar)

Add keys: `debt_payment`, `payment_category`, `exempt`, `unexempt`
(and an `exempt_confirm` message if the toggle is confirmed). Reuse existing `donation`,
`subscription`, `amount`, `remaining`, `category`, `add_payment`.

## Out of scope

- No allocation of debt payments across subscriptions (legacy debt = flat income).
- No changes to the standalone `Transactions` form or global finance reporting beyond the
  new `debt_payment` category flowing through the existing observer/report paths.
- No multi-row "pay several subscriptions at once" form (single payment per submit).

## Files touched

- `app/Http/Controllers/PlayerTransactionController.php` — conditional validation + branch.
- `app/Http/Controllers/PlayerSubscriptionController.php` — **new**, `exempt` toggle.
- `app/Models/PlayerSubscription.php` — `is_exempt` fillable/cast, simplified `isExempt()`.
- `database/migrations/xxxx_add_is_exempt_to_player_subscriptions.php` — **new**, column + backfill.
- `routes/web.php` — exempt route.
- `resources/js/Pages/Players/Show.vue` — category selector, filtered sub list, exempt button, status fix.
- `resources/js/i18n/{en,fr,ar}.json` — new keys.
