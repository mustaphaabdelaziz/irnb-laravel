# Post-Testing Enhancements — Design

**Date:** 2026-07-20
**Status:** Draft — awaiting review

Nine remarks raised after manual testing. They touch four unrelated areas of the app, so this
document is an **umbrella spec**: it records the whole picture and the decisions, then each work
package (A–D) gets its own implementation plan and its own branch.

| # | Remark | Package |
|---|--------|---------|
| 1 | Some equipment has no catalog, just quantities (dossards) | A |
| 2 | Rental needs a rental date and a return date | A |
| 3 | Flag equipment as *assigned to a person to work with*, not rented | A |
| 4 | Catalog price is a **value**, not a purchase; buying must be deliberate | A |
| 5 | Flash messages must follow the selected language | B |
| 6 | Player page: filters must update the statistics | C |
| 7 | Multi-select bulk edit (branches, categories, position, status) | C |
| 8 | Admin can set a user's password without knowing the old one | D |
| 9 | Track user activity to reward contributors | D |

**Sequencing:** A → B → C → D. A is the largest and touches schema; B is a quick independent win;
C and D are self-contained. No package depends on another, so the order can change.

---

# Package A — Equipment lot model

## Problem

`equipment_items` currently means **one row = one physical object**, with a mandatory unique serial
(`{CLUB}-{YYYY}-{CODE}-{NNNNN}`) and a mandatory `purchase_date`. Quantity is never stored — availability
is `COUNT(*) WHERE status = 'Available'`.

That is correct for a GPS vest and absurd for 100 dossards or 20 identical balls: the user must create
100 rows, each with a generated serial nobody will ever read. There is no way to say "we have 20 balls"
without inventing 20 identities.

Second, unrelated defect in the same area: adding an item with a price **silently creates an expense
Transaction** (`EquipmentItemController.php:59-73`). Recording what an item is worth and spending money
on it are the same action today, so entering an opening inventory falsifies the books.

## Decision — quantity on the item row

Keep **one** table. Add `quantity` to `equipment_items`. A row stops meaning "one object" and starts
meaning **a lot: N identical units sharing a condition, a purchase date and a price**. A serialized
item is the degenerate case where N = 1.

```
GPS vest        qty 1,   serial IRNB-2025-GPS-00042, condition Good
Ballons Nike    qty 17,  serial NULL,                 condition Good
Ballons Nike    qty 3,   serial NULL,                 condition Damaged
Dossards rouge  qty 100, serial NULL,                 condition Good
```

**Why this over the alternatives:**

- **Separate bulk/consumables module** — two menus, two import flows, two reports, no combined asset
  value. Defeats the point of having a catalog.
- **Separate `equipment_stock` + `equipment_stock_levels` tables alongside items** — considered and
  rejected: it forces every query, rental, report and stock-take to fork on a `tracking_mode` flag,
  and needs two nullable FKs plus a check constraint on `equipment_rentals`.
- **Lot model (chosen)** — `SUM(quantity)` is correct for both kinds, so there is one code path.
  Per-condition counts fall out of the existing `condition` column by splitting lots. Per-batch
  purchase dates and prices fall out for free, which is *more* accurate than 20 rows sharing a
  copy-pasted date. Migration is one column with a default; nothing to backfill.

Its real cost, accepted: every existing `COUNT(*)` over items must become `SUM(quantity)`, and lot
splitting needs a dedicated atomic operation.

## Data model

**`equipment_items`**

| column | change | notes |
|---|---|---|
| `quantity` | **new** unsignedInteger default 1 | units in this lot |
| `unique_identifier` | **now nullable** | serials only for tracked lots |
| `received_via` | **new** enum(`purchase`,`donation`,`opening_balance`,`adjustment`) default `purchase` | why the lot exists |

Check constraint: `quantity = 1 OR unique_identifier IS NULL` — a lot of many units can never carry a
single serial.

**`equipment_catalogs`**

| column | change | notes |
|---|---|---|
| `requires_serial` | **new** bool default `false` | drives the form only, not a second data path |
| `item_count` | **dropped** | dead column, never maintained, silently drifts (only the legacy Mongo importer writes it) |

`requires_serial` **backfills to `true` for existing catalogs** — they already hold serialized items and
flipping them would contradict their own data. Only new catalogs get the bulk-friendly default, chosen
because the club's equipment is mostly count-tracked.

`purchase_price` keeps its column name but changes meaning to **reference unit value**. It is displayed
and used to pre-fill forms. It never causes a Transaction.

**`branch_equipment_item`** (new pivot, follows `branch_player` / `branch_subscription`)

| column | notes |
|---|---|
| `branch_id` | FK branches, cascade |
| `equipment_item_id` | FK equipment_items, cascade |
| unique `[branch_id, equipment_item_id]` | |

Branches attach to the **lot**, not the catalog: a catalog entry is a type and has no location, while a
lot is a physical batch that sits somewhere. This expresses both real cases with one table — 50 dossards
to football and 50 to basketball are two lots with one branch each (exact quantities per branch), while a
shared ball machine is one lot tagged with two branches (no double-counting).

**Empty pivot = club-wide**, not orphaned. Filtering by branch X returns lots tagged X **plus** untagged
lots, since club-wide gear is genuinely usable by X. The UI must show an explicit "All branches" chip so
this does not read as missing data.

**`equipment_rentals`**

| column | change | notes |
|---|---|---|
| `type` | **new** enum(`rental`,`assignment`) default `rental` | remark 3 |
| `quantity` | **new** unsignedInteger default 1 | units taken from the lot |
| `returned_quantity` | **new** unsignedInteger default 0 | partial returns |
| `return_notes` | **new** text nullable | fixes note destruction |
| `checkout_date` | behaviour | user-editable, defaults to today (was hardcoded `now()`) |
| `return_date` | behaviour | user-editable, defaults to today (was hardcoded `now()`) |

**`inventory_session_items`** gains `expected_quantity` / `found_quantity`. Serialized lines are just
quantity 1, so the count sheet stays one component.

## Rental vs assignment (remark 3)

`type = 'rental'` — temporary loan. `due_date` expected, appears in the overdue report. Today's behaviour.

`type = 'assignment'` — equipment given to a person to work with (staff kit, a coach's permanent gear).
No `due_date`, never counted as overdue, surfaced in a separate "assigned to" view rather than the
rentals list.

Both live in `equipment_rentals` because they are the same relation — a quantity of a lot is out with a
person. Splitting them into two tables would duplicate the return logic and the availability maths.

## Partial returns

`returned_quantity` increments on each return. `return_date` is stamped only when it reaches `quantity`.
Returning 6 of 10 dossards leaves 4 outstanding on the same row.

Availability for any lot: `quantity − SUM(rental.quantity − rental.returned_quantity)` over open rentals.
Identical formula for serialized items, where every term is 0 or 1.

## Purchasing (remark 4)

A new **Receive stock** action is the only way stock enters the system. It takes catalog, quantity, unit
price, date, condition, branches, and a **"Record as expense" checkbox, default on**.

- **Ticked** → creates the lot **and** one linked expense Transaction, joined by the existing
  `equipment_items.purchase_transaction_id` FK.
- **Unticked** → lot only, no finance record. Covers donations, found items and opening balances.

The checkbox is what makes the decision reversible: if the coupled behaviour proves wrong in use,
switching to fully-decoupled is a change of default, not a rewrite.

Adding an item **never** creates a Transaction as a side effect any more. The `fiscal_year` bug is fixed
at the same time — it currently uses `now()->year` instead of the purchase date's year
(`EquipmentItemController.php:68`, and the same line in the CSV importer at `:400`).

## Cross-branch rentals

Renting a lot tagged "Football" to a player in "Basketball" shows a **soft warning** and proceeds. A hard
block was rejected: clubs lend across branches constantly, and blocking only trains people to untag
equipment to get around it.

## Service surface

All lot arithmetic lives in a new `EquipmentStockService`, so controllers stay thin and the maths has one
home:

- `receive()` — create a lot, optionally with a linked Transaction
- `splitLot()` — "mark N units as damaged"; atomic, writes both halves to `equipment_histories`
- `availableQuantity()` — the formula above
- `adjust()` — corrections and write-offs, never touches finance

`EquipmentLifecycleService` keeps rent/return/repair/lost but delegates quantity maths to the new service.

## Blast radius

`COUNT(*)` → `SUM(quantity)` at roughly 15 call sites, notably:

- `EquipmentCatalog::getAvailableCountAttribute()` (`app/Models/EquipmentCatalog.php:47-50`)
- the whole inventory report (`EquipmentItemController.php:209-274`)
- catalog list `withCount('items')` (`EquipmentCatalogController.php:22-48`)
- the CSV exports

**A missed call site produces a silently wrong number, not an error.** This package is not
eyeball-verifiable and requires tests covering each count.

## Bugs fixed in passing

- `Show.vue:75` hardcodes `rentable_type: 'Player'`, so equipment can never be assigned to a User despite
  full backend support.
- `EquipmentLifecycleService.php:50` overwrites the checkout note with the return note — data loss.
- `SerialNumberService::nextSequence()` (`:90-102`) pulls every matching serial into PHP and maxes in
  memory. Should be a SQL `MAX`.
- Status `Out of Service` is unreachable — no controller or view sets it. Either wire it or drop it.
- `EquipmentLifecycleService::retire()` (`:135-146`) has no route or caller.

## UI

Because count-tracked equipment dominates, the bulk flow gets the design attention:

- Catalog create form: quantity-first. Serial field hidden unless "track each unit individually".
- Catalog show page: per-condition breakdown (`Good 15 / Fair 2 / Damaged 3`), available vs total.
- Quantity stepper and a "mark N as damaged" split action.
- Branch chips on each lot, with "All branches" for untagged.
- Serialized flow keeps today's screens unchanged.

---

# Package B — Translated flash messages (remark 5)

## Problem

The UI runs on **vue-i18n** with `resources/js/i18n/{ar,en,fr}.json` (586 snake_case keys each), locale
resolved server-side by `SetLocale.php:13-19` (cookie `lang` → `users.preferred_lng` → config) and shared
to Inertia at `HandleInertiaRequests.php:37`.

Flash messages bypass all of it. Controllers flash literal English (`->with('success', 'Player created
successfully.')`) and `FlashMessages.vue:57` renders the string raw. **~116 hardcoded English strings
across 32 controllers** — 106 `success`, 26 `error`.

## Decision — flash a key, translate on the client

Controllers flash a **translation key**; the toast runs it through `t()`.

```php
return back()->with('success', 'flash.player_created');
```

```js
// FlashMessages.vue
{{ te(message) ? t(message) : message }}
```

Falling back to the raw string when the key is unknown means the migration can proceed
controller-by-controller without ever showing a broken toast, and third-party or dynamic strings still
render.

**Rejected:** translating server-side with `__()` and PHP lang files. It would mean maintaining a second
catalog (`lang/*.json`, currently 24 keys, used only by PDF blades) in parallel with the 586-key Vue
catalog — two sources of truth for the same UI language.

For messages with data, flash a small array and pass the params through:

```php
return back()->with('success', ['key' => 'flash.import_done', 'params' => ['count' => $n]]);
```

Roughly 12 flashes currently interpolate a variable (import summaries, `$e->getMessage()`).

Keys are namespaced `flash.*` and added to all three catalogs. English text moves verbatim from the
controller into `en.json`, so nothing is lost in translation.

## Bugs fixed

- **Flashes only appear on first page load.** `FlashMessages.vue:12-14` reads `page.props.flash` in
  `onMounted` only, but the component mounts once in the layout — every subsequent Inertia visit's flash
  is silently dropped. Needs a `watch` on the prop.
- **`status` flash never renders.** Three Breeze auth controllers flash `status`, but it is not in the
  shared props at `HandleInertiaRequests.php:47-50`.
- **`GuestLayout` has no `<FlashMessages>`**, so guest-side messages have nowhere to appear.
- `CategoryManager.vue:41` reads `flash.error` directly instead of using the toast — should be unified.

---

# Package C — Table UX

## C1 — Filter-aware player statistics (remark 6)

**Problem.** The four doughnut panels are built by raw `DB::table` queries hardcoded to
`archived = false` (`PlayerController.php:45-102`). They never call `applyPlayerFilters()`
(`:422-462`), which the list itself uses. So filtering by branch, category, position, age or search
changes the table and leaves the statistics untouched.

Worse, they ignore the archived toggle too: switching to the Archived view still shows active-player
counts.

**Decision.** Route all four stat queries through the same `applyPlayerFilters()` helper the list uses, so
list and statistics are provably the same population.

Each doughnut excludes **its own** dimension from the filter set — the category chart applies every
filter except `category_id`. Otherwise selecting one category collapses that chart to a single 100% slice
and the user can no longer switch category from it. This preserves the existing click-a-slice-to-filter
interaction (`Index.vue:188-191`).

`ageStats` (`:88-102`) currently loads every non-archived player's `birthdate` into PHP and buckets them
in a loop on every index request. It moves to SQL, reusing the bucket expression already written for the
`age` filter at `:444-455`.

## C2 — Multi-select bulk edit (remark 7)

**Problem.** Row selection exists in exactly one place — `Players/Index.vue:120-130` — and is wired only
to archive / restore / force-delete. There is **no shared table component**; all 55 index pages hand-roll
their own `<table>`.

**Decision.** Extract, do not rebuild.

- `useBulkSelection()` composable — the `selected` / `pageIds` / `allSelected` / `toggleAll` / `toggleOne`
  logic lifted out of `Players/Index.vue` verbatim, plus clear-on-filter-change.
- `<BulkActionBar>` component — the floating bar, taking action slots.
- `<BulkEditModal>` component — pick a field, pick a value, apply to selection.

A full shared DataTable rewrite across 55 bespoke pages is explicitly **out of scope**. The composable
lets any page opt in with a few lines.

**Bulk-editable fields, players first:** `category_id`, `position_id`, `status_value`, `branches`
(attach / detach / replace, since it is many-to-many).

Backend: one `bulkUpdate` endpoint per resource, reusing the existing id-validation helper
(`PlayerController.php:467-473`), wrapped in a transaction, permission-gated like the single-record
update, and capped at a sane batch size.

**Open issue — `players.status_value` is a free-form nullable string** with no lookup table and no
constraint. Its allowed values are hardcoded as Arabic literals in a Vue `<select>`
(`PlayerForm.vue:291-299`), and `statusStats` groups on whatever raw strings happen to be in the column,
including imported ones. Bulk editing it either accepts that, or status is promoted to a real lookup
table alongside `positions` and `categories`. **Needs a decision before C2 is planned.**

---

# Package D — Users

## D1 — Admin password reset (remark 8)

**Problem.** There is no admin-initiated password path at all. Passwords are set at self-registration
(`RegisteredUserController.php:37-43`), changed by the user with their current password
(`PasswordController.php:19-24`), or reset by email. `UpdateUserRequest` has no `password` rule and
`Users/Edit.vue` has no password field.

**Decision.** Add an optional `password` to the admin user-edit flow. `User::$casts` already declares
`'password' => 'hashed'` (`app/Models/User.php:83`), so a validated `password` key hashes automatically.

Rules: nullable, `Rules\Password::defaults()`, `confirmed`. Absent or empty → password untouched.

**Security constraints — these are the point of the feature, not decoration:**

- Only a user who passes `isSuperadmin()` may set another user's password, reusing the existing
  `guardSuperadmin()` (`UserController.php:150`). Setting a password is equivalent to becoming that user,
  so it must not be reachable through the ordinary `users.edit` permission — otherwise any user-manager
  can take over a superadmin account. **Privilege escalation is the risk being designed against.**
- The action is logged to the D2 activity log with actor, target and timestamp. Never log the password.
- The target user's existing sessions are invalidated on password change.
- No "show current password" anywhere — hashes are one-way and the UI must not imply otherwise.

## D2 — User activity tracking (remark 9)

**Problem.** Nothing exists. No audit package, no generic log table. `equipment_histories` is the only
append-only trail and covers equipment only. There is no record of who created a player, edited a user or
imported a file — so contribution cannot be measured or rewarded.

**Decision.** A generic append-only `activity_logs` table plus an opt-in model trait.

| column | notes |
|---|---|
| `id` | |
| `user_id` | FK users, nullOnDelete — actor |
| `action` | string, indexed (`created`, `updated`, `deleted`, `imported`, `rented`, `returned`) |
| `subject_type` / `subject_id` | morphs, indexed |
| `changes` | json nullable — dirty attributes only |
| `ip_address` | string nullable |
| `occurred_at` | timestamp, indexed |

No `updated_at` — immutable, matching the `equipment_histories` precedent
(`2026_04_07_080000_fix_equipment_tables.php:33`).

A `LogsActivity` trait registers model observers. **Opt-in per model, not global** — a blanket observer
would log framework churn and drown the signal. Initial set: `Player`, `EquipmentItem`, `Transaction`,
`PlayerSubscription`, `User`.

Sensitive attributes (`password`, `remember_token`) are never written to `changes`.

**Reward view:** contributions per user over a period — players added, equipment received, transactions
recorded, imports run — as a leaderboard, driven by `GROUP BY user_id, action, subject_type` over a date
range. Deliberately a *count of contributions*, not a productivity score; the design does not attempt to
weight or rank actions against each other.

**Retention:** the table grows without bound. A scheduled prune (default 24 months, configurable) ships
with the feature rather than being discovered later.

**Deliberately not included:** who *viewed* what. Read-tracking multiplies volume by an order of
magnitude and answers a question nobody asked.

---

## Open decisions

| # | Question | Package | Blocks |
|---|----------|---------|--------|
| 1 | Promote `players.status_value` to a lookup table, or keep the free-form string? | C2 | bulk edit of status |
| 2 | Wire up or drop the unreachable `Out of Service` status and `retire()`? | A | minor cleanup |
| 3 | Confirm the initial model set for activity logging | D2 | D2 planning |

Nothing blocks Package A.
