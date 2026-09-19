# Subscription category eligibility — design

**Date:** 2026-09-19
**Status:** Approved

## Problem

A subscription can already be tagged with player categories (Senior, Junior, Cadet, Minime,
Ecole…) from the create/edit form (`category_subscription` pivot; no category = all
categories). Only player registration (`RegisterPlayerService`) honours that tag.

- The **Add Payment** window on a player profile lists every subscription in the catalog,
  whatever the player's category (`PlayerController::availableSubscriptions()`).
- The pay endpoint accepts any `subscription_id` and assigns it to the player on demand
  (`PlayerTransactionController::resolvePlayerSubscription()`).
- The subscription page lets an admin add any player ("Add player", "Assign all") regardless
  of category.

## Goal

A subscription tagged "Cadet + Ecole" can only be paid by, and assigned to, Cadet and Ecole
players. Other players do not see it in their Add Payment window.

## Eligibility rule

A subscription **applies to** a player when:

- the subscription has **no categories** (applies to everyone), or
- the player's `category_id` is one of the subscription's categories.

A player with no category is only eligible for subscriptions with no categories.

**Existing debts stay payable.** If a player already has a `player_subscriptions` row for a
subscription outside their category (assigned before the category was set/changed, or before
this feature), that subscription is still listed and payable. Only *new* assignments are
blocked.

## Approach

Enforce server-side, from one source of truth on the model. Rejected: filtering only in
`Players/Show.vue` with the `category_ids` already sent — the pay endpoint would still accept
any `subscription_id`.

## Changes

### Model — `App\Models\Subscription`

- `scopeForCategory(Builder $query, ?int $categoryId)`: `whereDoesntHave('categories')`, or
  (when `$categoryId` is set) `orWhereHas('categories', id = $categoryId)`, wrapped in a nested
  `where` so it composes with other constraints.
- `appliesToCategory(?int $categoryId): bool`: same rule for a loaded instance (uses the
  `categories` relation).

`RegisterPlayerService` switches its inline closure to `forCategory()` — no behaviour change.

### Add Payment list — `PlayerController::availableSubscriptions()`

Query becomes: subscriptions `forCategory($player->category_id)` **or** whose id is in the
player's assigned `subscription_id`s. Everything else (mapping, ordering, default register) is
unchanged. `Players/Show.vue` needs no change.

### Pay endpoint — `PlayerTransactionController::resolvePlayerSubscription()`

When paying by `subscription_id` and the player has **no existing row** for it, reject if
`! $catalog->appliesToCategory($player->category_id)`:

```php
throw ValidationException::withMessages([
    'subscription_id' => __('This subscription does not apply to this player\'s category.'),
]);
```

An existing row is returned as today (existing debts stay payable). The
`player_subscription_id` path is unchanged.

### Subscription page — `SubscriptionController`

- `show`: `availablePlayers` only lists eligible players — when the subscription has
  categories, `whereIn('category_id', <subscription category ids>)`; otherwise all
  non-archived players as today.
- `assignOne`: if the player is not eligible, redirect back with
  `error` → `flash.player_not_eligible_for_subscription`; nothing is created.
- `assign`: the candidate player set (both `assign_all` and `player_ids` modes) is restricted to
  eligible players. `assign_all` with a `category_id` outside the subscription's categories
  assigns nobody. The existing `flash.players_assigned` count reports what was created.

### Subscription page — `Subscriptions/Show.vue`

- "Assign all" modal: the category dropdown lists only the subscription's categories (all
  categories when it has none). Empty choice = every eligible player.
- Fix existing bug: the modal posts only `{ category_id }` without `assign_all`, so the
  controller takes the `player_ids` branch and assigns 0 players. Send `assign_all: true`.

### Translations

- `resources/js/i18n/{ar,en,fr}.json`: `flash.player_not_eligible_for_subscription`
  (flat dotted key; use `t()` directly).
- `lang/{ar,fr}.json`: `"This subscription does not apply to this player's category."`.

## Out of scope / unchanged

- Create/edit subscription form: category checkboxes already exist.
- Changing a subscription's categories, or a player's category, never adds or removes existing
  `player_subscriptions` rows.
- Branch tagging does not filter the Add Payment list (unchanged).
- Manual/previous debts (`PlayerSubscriptionController`) are not tied to a catalog subscription
  and are unaffected.

## Testing (PHPUnit feature tests)

- Profile `availableSubscriptions`: a Cadet player sees Cadet-tagged and untagged
  subscriptions, not Senior-tagged ones; sees a Senior-tagged one they already owe; a player
  with no category sees only untagged ones.
- Paying an out-of-category, unassigned `subscription_id` → 422 on `subscription_id`, no
  transaction and no `player_subscriptions` row created.
- Paying an out-of-category subscription the player already owes → succeeds.
- `assignOne` with an ineligible player → error flash, no row; eligible player → row.
- `assign` with `assign_all` → only eligible players assigned; with `player_ids` containing an
  ineligible player → that player skipped.
- `show` `availablePlayers` excludes ineligible players.
- Existing suites stay green (`PlayerPaymentFlowTest`, `RegisterPlayerServiceTest`,
  `SubscriptionBranchTest`, …).
