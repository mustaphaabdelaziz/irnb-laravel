# Subscription Branch Tagging + Per-Branch Finance — Design

**Date:** 2026-07-16
**Status:** Approved

## Problem

The club runs distinct activities (football, swim, …) modeled as **branches**. Subscriptions today
have no branch link, so a football subscription and a swim subscription can't be managed or reported
separately. Admins want to manage all subscriptions on one page but see each branch's financial
state (owed / collected / outstanding) separately, and filter the list by branch.

## Decision

Tag subscriptions with branches through a **many-to-many** link, mirroring the existing
`category_subscription` pivot exactly. A subscription can belong to 0, 1, or many branches. Branch is
**derived through the subscription** — no branch column is added to `subscriptions`,
`player_subscriptions`, or `transactions`.

Because a subscription may be tagged to several branches, its debt/collected amounts appear under
**every** branch it is tagged to. Branch financial rows therefore overlap and do not sum to a grand
total — this is the correct many-to-many semantic and is labeled as such in the UI.

## Data model

New pivot table `branch_subscription` (migration, mirrors `category_subscription`):

| column            | notes                                   |
|-------------------|-----------------------------------------|
| `branch_id`       | FK → branches, cascade on delete        |
| `subscription_id` | FK → subscriptions, cascade on delete   |
| primary key       | composite `['branch_id','subscription_id']`, no `id`, no timestamps |

Relations:
- `Subscription::branches()` — belongsToMany via `branch_subscription`.
- `Branch::subscriptions()` — inverse belongsToMany.

## Backend

- **`StoreSubscriptionRequest`** (used for store + update): add
  `'branch_ids' => ['nullable','array']`, `'branch_ids.*' => ['integer','exists:branches,id']`.
- **`SubscriptionController@store` / `@update`:** after save, `$subscription->branches()->sync($request->input('branch_ids', []))`, exactly like `category_ids`.
- **`SubscriptionController@create` / `@edit`:** pass `branches` (`Branch::orderBy('name')->get()`) to the form.
- **`SubscriptionController@index`:**
  - Eager-load `branches` on the subscription list.
  - Apply a `branch_id` filter: `when($request->filled('branch_id'), fn ($q) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $request->branch_id)))` (mirrors the Players page filter).
  - Compute **`branchStats`**: for each branch, aggregate over `player_subscriptions` joined to their
    subscription's branches — `owed` (Σ `amount_owed`), `collected` (Σ `amount_paid`),
    `outstanding` (Σ `remaining_amount`, i.e. exempt → 0), `players` (distinct player count),
    `paid_rate` (`collected / owed`, guard divide-by-zero). Include a **"No branch"** bucket for
    obligations whose subscription has no branch, plus manual/previous debts (null
    `subscription_id`) — so every obligation lands in exactly the branches it belongs to (or the
    No-branch bucket).
  - Pass `branches`, `branchStats`, and `filters` (`year`, `branch_id`).

`outstanding` uses the same rule as player debt: exempt and non-mandatory handling matches
`PlayerSubscription::remaining_amount` semantics. `owed`/`collected` are gross sums so the numbers
are legible even for exempt/optional lines; `outstanding` is the debt-accurate figure.

## Frontend

**`resources/js/Pages/Subscriptions/Create.vue`** (also edit): add a **branch multi-select**
(checkbox group) beside the category multi-select, bound to `form.branch_ids`. Optional. New prop
`branches`. Pre-check the subscription's current branches on edit.

**`resources/js/Pages/Subscriptions/Index.vue`:**
1. **Per-branch financial breakdown** — a table/cards block, one row per branch plus a "No branch"
   row: columns owed / collected / outstanding / players / paid-rate. Always visible. Labeled that
   branches overlap and don't sum to a total.
2. **Branch filter dropdown** (mirrors Players page: `all_branches` option + `branches` list using
   `localized_name || name`), plus surface the existing year filter. Filters re-request via Inertia
   preserving state.
3. Show each subscription's branch badges in the list.

**`resources/js/Pages/Subscriptions/Show.vue`:** display the subscription's branch badges next to the
category badges. No stat changes.

**i18n (en/fr/ar):** `branches` and `all_branches` already exist. Add `branch_financial_state`,
`no_branch`, `owed`, `collected`, `paid_rate`, `branches_overlap_note` as needed (reuse existing keys
where present — `outstanding_debt`, `players`, `collected`/`total_collected`).

## Error handling

- `branch_ids` validated (array of existing branch ids); absent/empty → no branches synced.
- Branch filter with an unknown id → empty result (standard).
- Deleting a branch cascades its pivot rows (subscriptions keep existing, just lose that tag).

## Testing (feature tests)

1. Creating a subscription with `branch_ids` writes `branch_subscription` rows; editing re-syncs.
2. `index?branch_id=X` returns only subscriptions tagged to branch X.
3. `branchStats` sums owed/collected/outstanding per branch correctly; a subscription shared by two
   branches counts its obligation in **both** branch rows.
4. The "No branch" bucket captures obligations of untagged subscriptions (and null-subscription
   manual debts).

## Out of scope

- Player assignment is **not** branch-filtered (players are multi-branch; auto-restricting is fuzzy).
- No branch column on transactions; no branch axis in the main Finance dashboard.
- Branch financial rows intentionally overlap (many-to-many) and are not reconciled to a grand total.
