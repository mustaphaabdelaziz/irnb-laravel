# Manual debts page — design

Date: 2026-11-19
Status: approved by owner (design), spec awaiting review

## Problem

The Subscriptions page shows a "Financial state by branch" table. Its last row,
«بدون فرع» / "No branch", is a computed bucket: it sums every obligation that
belongs to no branch. The row is not clickable and no screen lists what is
inside it. The owner saw 2 players and 500 DZD outstanding there and had no way
to find out who they were without a database query.

In practice the bucket holds manual debts: lines added by hand from a player's
page (`PlayerSubscriptionController::store`, `subscription_id = null`,
`is_legacy = true`). They are only visible one player at a time.

## Goal

From the «بدون فرع» row, reach a page that lists every obligation in that
bucket, with a link to each player, so the owner can act on them from the
player page.

## Decisions (owner, 2026-11-19)

- Scope: the No-branch bucket only. Branch rows do not get a details page.
- Read-only: view and open the player. Pay, edit and delete stay on the player
  page; this page changes no money records.
- A dedicated page, not a modal or an expanding row.

## What counts as a No-branch obligation

One rule, used by both the summary row and the new page:

> a `player_subscriptions` row whose `subscription_id` is null, **or** whose
> subscription has no row in `branch_subscription`.

Today `SubscriptionController::branchStats()` applies this rule in PHP inside
the loop that fans each obligation out to its branches; that loop stays as it
is. The new page applies the same rule as a query scope on `PlayerSubscription`
(`scopeWithoutBranch`). A test asserts the page totals equal the row's totals,
so the two cannot drift unnoticed.

A subscription saved with no branch ticked also lands in the bucket. None exist
in the owner's data today, but the page lists those obligations too, so the
numbers always match the row.

## Entry point

`resources/js/Pages/Subscriptions/Index.vue`, branch table:

- The «بدون فرع» row's name cell becomes a link to the new page, with a small
  chevron icon so it reads as clickable. The row is only rendered when the
  bucket is non-empty (unchanged).
- A named branch row's name cell becomes a button that sets the existing branch
  filter of the subscriptions list below to that branch. No new page.

## The page

Route: `GET /subscriptions/manual-debts`, name `subscriptions.manual-debts`,
declared **before** `Route::resource('subscriptions', …)` so `manual-debts` is
not captured as a `{subscription}` id.

Controller: `SubscriptionController::manualDebts(Request $request)`, rendering
`Subscriptions/ManualDebts.vue`.

Title: «الديون اليدوية» / "Manual debts" / « Dettes manuelles ».

Layout, top to bottom:

1. Header: title, back link to the Subscriptions page.
2. One explanatory line: these debts were added by hand on a player's page;
   they belong to no subscription, hence to no branch.
3. Summary tiles (StatStrip): number of debts, owed, collected, still owed.
   Totals cover the whole bucket, not the current filter or page.
4. Filters: status (all / unpaid / paid) and a search box matching player name
   or debt label. Uses the existing `useListFilters` + partial reload pattern.
5. Table, one row per obligation:
   - Player — `Player::fullname`, links to `players.show`
   - Membership ID
   - Label — the debt's `label`; for an obligation of a branch-less
     subscription, the subscription's name
   - Season — `year_label`-style season, blank when none
   - Owed, Paid, Left — from `PlayerSubscription::moneyFor`, so discounts and
     exemptions are honoured, same as the summary row
   - Date added — `created_at` in the club timezone
   - Status badge — paid / partial / unpaid / exempt
6. Pagination, 25 per page.

Order: obligations with something left to pay first, then newest first.

Phone (down to 320 px): the table collapses to stacked cards, following the
pattern the other lists use. Dark mode follows the existing palette rules.

Empty state: the page is reachable by URL even when the bucket is empty; it
then shows the standard "no results" row and zero tiles.

## Permissions

The `permission` middleware derives the right from the route name. The module
resolves to Subscriptions, but `PermissionMap::deriveAction()` maps any unknown
last segment to `edit`, so `manual-debts` is added to its `view` list. The page
then needs only the existing Subscriptions → view right. No new right and no
migration. Money is shown, consistent with the
Subscriptions page itself, which already shows these totals.

## i18n

New flat keys in `ar`, `fr` and `en` for: page title, explanation line, tile
labels, status values, column headers not already present, search placeholder.
Insert-only edits. No key segment after a message may be a String method name.
`npm run i18n:check` must pass.

## Help Center

One short paragraph added to the subscriptions article explaining the «بدون
فرع» row and the page behind it, in Arabic with French and English twins.

## Testing (Pest)

- The page lists obligations with a null subscription and obligations of a
  branch-less subscription, and excludes obligations of a subscription that has
  a branch.
- Page totals equal the "No branch" row of `branchStats()`.
- A discounted and an exempt obligation show the same owed/left values as the
  summary row.
- Status filter: unpaid shows only rows with something left; paid shows only
  settled rows.
- Search matches player name and label.
- Ordering: unpaid before paid, then newest first.
- A user without Subscriptions → view gets 403.
- Route order: `/subscriptions/manual-debts` resolves to the page, not to
  `subscriptions.show`.

The suite must also pass on MariaDB (no SQLite-only SQL in the scope or the
ordering).

## Out of scope

- Giving a manual debt a branch.
- Editing, paying or deleting from this page.
- Export / print of the list.
- A details page for named branches.
