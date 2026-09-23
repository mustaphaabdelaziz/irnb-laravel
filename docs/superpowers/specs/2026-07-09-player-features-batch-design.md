# Player Features Batch — Design

Date: 2026-07-09
Branch: `feat/player-features-batch`

Batch of 12 fixes/features found during web + desktop (exe) testing. Grouped into 7 work
areas (A–G). Decisions locked with the user are marked **[decided]**.

---

## A. Player list: delete, bulk delete, archive/restore (items 1, 2, 4)

Current: `PlayerController::destroy` sets `archived = true` (soft archive, no hard delete,
no `deleted_at`). No delete button in `Players/Index.vue`. Un-archive only via Edit form
checkbox. Backend `index` already honours an `archived` query param.

**[decided] Archive + Permanent**: delete = archive (reversible); permanent hard-delete
lives only in the Archived view.

Changes:
- Row **Delete** button in both grid + list views of `resources/js/Pages/Players/Index.vue`
  → archives via existing `players.destroy`, with a `ConfirmModal`.
- **Checkbox column** + select-all in the list view. A floating action bar appears when ≥1
  selected → **Archive selected**. New route `POST /players/bulk-archive`
  (`players.bulkArchive`, body `ids[]`).
- **Archived view**: a tab/toggle on the list page that sets `?archived=1`. In archived
  view, each row shows **Restore** + **Delete permanently**; plus bulk **Restore selected**
  and **Delete permanently selected**.
- New routes:
  - `PUT /players/{player}/restore` → `players.restore` (archived=false)
  - `DELETE /players/{player}/force` → `players.forceDelete` (hard delete)
  - `POST /players/bulk-archive` → `players.bulkArchive`
  - `POST /players/bulk-restore` → `players.bulkRestore`
  - `POST /players/bulk-force-delete` → `players.bulkForceDelete`
  - `destroy` stays = archive.
- Permanent delete cascade: delete `emergencyContacts`, `achievements`,
  `playerSubscriptions`; linked `transactions` are **archived** (kept for finance audit),
  not deleted. Delete player picture file. Wrap in a DB transaction.
- Permission: all new routes resolve to the `players` module via existing route-name
  permission middleware.

## B. Player subscription lines: edit + delete (item 3)

Current: `Players/Show.vue` transaction-history table already has edit/delete (via
`PlayerTransactionController`). The **Subscriptions** table (PlayerSubscription obligation
lines) has no per-row actions.

**[decided] Subscription lines**: add edit/delete to the player's Subscriptions table.

Changes:
- Per-row **Edit** modal: `amount_owed`, `is_exempt`, `due_date`.
- Per-row **Delete**: removes the `PlayerSubscription` assignment. **Blocked if the line
  has recorded (non-archived) payments** — message tells the user to remove payments first.
- New `PlayerSubscriptionController` with `update` + `destroy`. Routes:
  - `PUT /players/{player}/subscriptions/{playerSubscription}` → `players.subscriptions.update`
  - `DELETE /players/{player}/subscriptions/{playerSubscription}` → `players.subscriptions.destroy`
- Recalculate player debt (`calculateTotalDebt`) after each.

## C. Team branches, multi-membership (item 5 — الفروع)

Greenfield — no branch/section/discipline concept exists. Only existing many-to-many is
`category_subscription`; there are zero player pivots today.

Approach: admin-managed lookup + many-to-many, cloning the `Category` lookup pattern.

Changes:
- Migration: `branches` table (`id`, `name_ar`, `name_fr`, `name_en`, `description`
  nullable, timestamps) + `branch_player` pivot (`branch_id`, `player_id`, unique pair).
- `Branch` model: `belongsToMany(Player, 'branch_player')`, `localized_name` accessor.
- `Player`: add `branches(): belongsToMany`.
- `BranchController` cloned from `CategoryController` (index/store/update/destroy).
- `resources/js/Pages/Settings/Branches.vue` cloned from `Settings/Categories.vue`, with the
  three per-language name inputs.
- Route `Route::resource('branches', ...)->except(['show','create','edit'])`, permission
  module entry in `config/permissions.php`, sidebar entry in the **administration** section
  of `AuthenticatedLayout.vue`.
- `PlayerForm.vue`: multi-select (checkbox list) bound to `form.branch_ids`;
  `PlayerController::store/update` call `$player->branches()->sync($ids)`.
- Show branches on `Players/Show.vue`. Optional branch filter on the player list.
- Branch membership is optional (0..n). Seed **Swimming** + **Football** (all three langs).

## D. Translations (items 6, 8)

**[decided] Categories → per-language columns.**

- Add `name_ar`, `name_fr`, `name_en` to `categories`; migration backfills all three from the
  existing `name`. Keep `name` as the canonical/slug lookup used by imports (or repurpose —
  see plan). `Settings/Categories.vue` edits all three. Display uses `localized_name`
  accessor (current locale → fallback en → name). Player import matches a category by any
  of the three locale names (case-insensitive).
- **Item states → i18n keys.** In `Equipment/Catalog/Show.vue` the status badge and the
  condition strings currently render the raw capitalized DB enum. Render via
  `t(value.toLowerCase().replaceAll(' ', '_'))`. Add missing keys to `ar/fr/en.json`:
  `out_of_service`, `under_repair`, and condition values `new/good/fair/poor/damaged`
  (statuses `available/rented/lost/retired` already exist as lowercase keys).
- Location values stay raw (free-text, not an enum).

## E. Restore lost items (item 7)

Current: `EquipmentLifecycleService` has no transition out of `Lost`. Item `update()` does
not allow editing `status`. No restore route.

Changes:
- `EquipmentLifecycleService::markAsFound(EquipmentItem $item)` → `status = 'Available'`,
  write an `EquipmentHistory` event with `previous_status = 'Lost'`.
- `EquipmentItemController::markFound()` + route `POST /equipment/items/{item}/mark-found`
  → `equipment.items.mark-found`.
- **Restore** button in `Equipment/Catalog/Show.vue`, shown when `item.status === 'Lost'`.

## F. Export player list (item 9)

No player export today (`ExcelExporter` emits CSV; used by Board/Transactions/Inventory).

Changes:
- `PlayerController::export(Request)` → builds the query with the **same filters as `index`**
  (search, category_id, status, position_id, age, archived), streams CSV via `ExcelExporter`.
- Columns: membership_id, fullname, category (localized), status_value, join_year,
  outstanding_debt, phones (joined), branches (joined).
- Route `GET /players/export` → `players.export` (declared before the resource route).
- **Export** button on `Players/Index.vue` that links with the current filter query string.

## G. Import + form defaults (items 10, 11, 12)

- **Item 10 — import default worker**: flip `PlayerImportController` line ~100 so blank/other
  status → worker: `is_student = mb_strtolower($data['status']) === 'student'`. Update the
  template example cell + header hint. Adjust `PlayerImportTest` expectations.
- **Item 11 — enrolled default**: `PlayerForm.vue` initial `status_value` = `منخرط` on create
  (Edit keeps the stored value). DB column stays nullable; this is a form default only.
- **Item 12 — membership id follows year [decided: regenerate on edit]**: in
  `PlayerController::update` (and/or `RegisterPlayerService`), when `join_year` changes,
  regenerate `membership_id` = `MembershipNumber::format(newYear, nextSequence(newYear))`.
  Only fires when the year actually changes; old id is replaced. Create already previews live.

---

## Build order

1. **Phase 1 — quick wins**: G (import default, enrolled default, id-on-year) + E (restore
   lost) + D-states. Small, isolated.
2. **Phase 2 — list**: A (delete/bulk/archive-restore) + F (export). Same Index.vue +
   controller.
3. **Phase 3**: B (subscription lines).
4. **Phase 4 — schema**: D-categories (per-language) → C (branches, reuses per-lang pattern).

## Testing / verification

- PHP: `php artisan test` (existing `PlayerImportTest` covers import; extend for new default).
  Add feature tests for bulk-archive, restore, force-delete, subscription line update/destroy,
  mark-found, player export, membership-id regeneration.
- Migrations run on desktop boot (see recent commit `2ff709f`) — new migrations auto-apply on
  app update.
- Manual: drive each flow on web; verify RTL/Arabic labels for new UI.

## Risks / notes

- Permanent delete is irreversible — gated behind the Archived view + confirm modal, and
  preserves transactions for finance audit.
- Regenerating membership id on year edit changes a player-visible identifier; acceptable per
  decision. Uniqueness ensured by `nextSequence` loop.
- Per-language categories touch every category-name render site + import matching; sweep for
  `category?.name` usages.
