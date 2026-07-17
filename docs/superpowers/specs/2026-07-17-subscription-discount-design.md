# Player Subscription Discount — Design

**Date:** 2026-07-17
**Status:** Approved

## Problem

Admins need to grant a player a discount on a subscription — either a **percentage** (e.g. −20%) or a
**fixed amount** (e.g. −400 DZD). Today the only way is to hand-edit `amount_owed` in the obligation
edit modal, which silently destroys the real price: a discounted subscription becomes
indistinguishable from a pricing typo, and there is no record that a discount was granted.

## Decision

Store the discount **separately** from the price. `amount_owed` is never mutated — it remains the
real price — and the discount is recorded as `discount_type` + `discount_value` on the obligation.
What the player owes becomes a derived value (`net_owed`), so the discount is visible, auditable and
reversible.

Rejected alternatives:
- **Reduce `amount_owed` directly** — simplest, no schema change, but the original price and the fact
  a discount was given are lost forever.
- **Discount as a negative obligation row** — full history, but negative rows leak into every debt and
  stats query and break the paid/unpaid status model.

**Exempt stays independent.** `is_exempt` continues to waive the whole obligation
(`remaining_amount = 0`); a discount applies to non-exempt obligations. Exempt wins over discount.

## Data model

Migration adds two nullable columns to `player_subscriptions`:

| column           | type                              | notes                                   |
|------------------|-----------------------------------|-----------------------------------------|
| `discount_type`  | enum(`percent`, `amount`), null   | null = no discount                      |
| `discount_value` | decimal(12,2), null               | percent: 0–100. amount: money           |

Both added to `PlayerSubscription::$fillable`; `discount_value` cast `decimal:2`.

## Model — the feature lives here

Three accessors on `PlayerSubscription`:

- **`discount_amount`** — the discount in money:
  - `percent` → `amount_owed × discount_value / 100`
  - `amount`  → `discount_value`
  - no type/value → `0`
  - **Clamped to `[0, amount_owed]`** so a discount can never exceed the price or go negative,
    whatever is stored.
- **`net_owed`** — `max(0, amount_owed − discount_amount)`
- **`remaining_amount`** (existing, updated) — exempt → `0`; otherwise `max(0, net_owed − amount_paid)`

`discount_amount` and `net_owed` are appended so the UI can render them.

Because player debt already derives from `remaining_amount`, `Player::calculateTotalDebt()`, the debt
summary card, the dashboard KPIs and top-debtors all become discount-aware with **no changes**.

## Status

`payment_status` gains one clause so a full (100%) discount reads `paid` instead of `unpaid`:

```php
if ($this->isExempt()) return 'exempt';
if ($remaining <= 0 && ($amount_paid > 0 || $discount_amount > 0)) return 'paid';
if ($amount_paid > 0) return 'partial';
return 'unpaid';
```

With no discount this is byte-identical to current behaviour, so existing billing tests stay valid.

## Call sites that must change (found by tracing, not assumed)

1. **`PlayerTransactionController::createSubscriptionPayment()`** computes
   `$remaining = amount_owed − amount_paid` directly. With a discount, a payment larger than *net*
   would over-credit the subscription instead of splitting the excess into a player-level donation.
   It must use the discount-aware `$sub->remaining_amount`.
2. **`SubscriptionController::branchStats()`** computes outstanding in raw PHP as
   `is_exempt ? 0 : max(0, owed − paid)`. It must use the `remaining_amount` accessor and select the
   discount columns. `owed` in that table stays **gross** (the real price); `outstanding` is net.

`RecalculatePlayerDebtService` needs no change: it recomputes `amount_paid` from linked payments and
then re-reads `calculateTotalDebt()`.

## Backend

- **`PlayerSubscriptionController::update`** accepts `discount_type` and `discount_value`:
  - `discount_type` → `nullable`, `in:percent,amount`
  - `discount_value` → `nullable`, `numeric`, `min:0`, `required_with:discount_type`,
    and `max:100` when `discount_type = percent`
  - clearing the type clears the value (no orphan discount)
  - The server clamps the resulting money to `amount_owed` via the accessor regardless of input.

## Frontend (`resources/js/Pages/Players/Show.vue`)

- **Obligation edit modal:** discount type select (none / percent / amount) + value input, showing the
  resulting net owed live. Applies to every obligation, including manual/previous debts.
- **Obligations table:** new **Discount** column rendering e.g. `-20% (400)` or `-400`, and `—` when
  none. The Amount column keeps showing the original price; Remaining already reflects the discount.
- **i18n (en/fr/ar):** `discount`, `discount_type`, `discount_value`, `discount_percent`,
  `discount_amount`, `no_discount`, `net_owed`.

## Error handling

- Validation as above; percent > 100 rejected.
- Discount money clamped to `[0, amount_owed]` in the accessor — defence in depth, so bad stored data
  can never produce negative debt.
- Exempt overrides discount (remaining 0).

## Testing

1. A percent discount reduces `remaining_amount` and the player's debt; `amount_owed` unchanged.
2. A fixed-amount discount does the same.
3. Discount is clamped at `amount_owed` — remaining never goes negative.
4. `percent > 100` is rejected by validation.
5. A 100% discount → status `paid`, debt 0, with no payment recorded.
6. A payment larger than *net owed* splits the excess into a player-level donation.
7. Exempt overrides discount → remaining 0, status `exempt`.
8. No discount → behaviour identical to today (regression guard).

## Out of scope

- No discount at assign time (assign, then edit).
- No catalog-wide / bulk discounts.
- No discount reason or audit trail field.
