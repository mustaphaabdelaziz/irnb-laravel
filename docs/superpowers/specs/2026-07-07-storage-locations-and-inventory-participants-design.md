# Storage Locations & Inventory Participants — Design

**Date:** 2026-07-07
**Status:** Approved (brainstorm)

## Goal

Two inventory-facing enhancements:

1. **Storage locations** — replace the free-text item location with an
   admin-managed list (Storage 01, Storage 02, …) so the inventory count sheet
   can be grouped by storage and locations stay consistent.
2. **Inventory participants** — record who took part in a stock-take (linked
   users/players) so staff can follow up with them after the count.

## Decisions (locked)

| Question              | Decision                                                                 |
|-----------------------|--------------------------------------------------------------------------|
| Location model        | Admin-managed name list; item `location` stays a **string** (dropdown of names) |
| Location input        | **Strict** dropdown — managed names only; an item's existing legacy value is preserved as a selectable option |
| Participants          | **Linked** users/players (polymorphic, like rentals)                     |
| Participant timing    | Editable on the **session page while `in_progress`**; read-only once completed |
| Count sheet           | **Grouped by location** (`expected_location`)                            |

---

## Part A — Storage locations

### Data model
- New table `storage_locations`: `id`, `name` (string, unique), `timestamps`.
- Model `App\Models\StorageLocation` (fillable `name`).
- **No change** to `equipment_items.location` — it stays a nullable string.
  Managed names are just the allowed dropdown values; existing values remain
  valid data.

### Settings CRUD (admin)
- `StorageLocationController` with `index`, `store`, `update`, `destroy`,
  mirroring `EquipmentCategoryController`. Validation: `name` required,
  unique. `destroy` is always allowed (locations aren't a FK; deleting one just
  removes it from the dropdown — items keep their stored string).
- Routes `Route::resource('storage-locations', …)` (or explicit index/store/
  update/destroy) in the **admin** middleware group, beside
  `equipment-categories`.
- Vue page `Settings/StorageLocations.vue`, mirroring
  `Settings/EquipmentCategories.vue` (add form + inline edit + delete).
- Add a nav/settings entry alongside Equipment Categories.

### Item location becomes a dropdown
- `EquipmentCatalogController::show` passes a `storageLocations` prop
  (array of names, `StorageLocation::orderBy('name')->pluck('name')`).
- In `Equipment/Catalog/Show.vue`, the add-item and edit-item modals replace
  the free-text Location `TextInput` with a `<select>` whose options are the
  storage-location names, plus a blank option. In the **edit** modal, if the
  item's current `location` is not among the managed names, prepend it as a
  selected option so legacy values are not lost.
- Store/update validation is unchanged (`location` stays
  `nullable|string|max:255`) — the dropdown constrains the UI; the backend
  still accepts any string (so legacy values and the strict-UI selection both
  persist). No new server-side enum enforcement.

### Inventory count sheet uses locations
- `InventoryController::show` passes `storageLocations` (names) to the session
  view.
- In `Inventory/Session.vue`, the per-row `actual_location` free-text becomes a
  `<select>` of storage-location names (blank allowed). The `counts` endpoint is
  unchanged (`actual_location` stays `nullable|string|max:160`).
- The count sheet **groups rows by `expected_location`**: a header per location
  ("Storage 01 (12)", then its rows), locations sorted, items with no location
  under an "Unassigned" group. Grouping is presentational (client-side over the
  existing `session.items`).

---

## Part B — Inventory participants

### Data model
- New table `inventory_session_participants`: `id`,
  `inventory_session_id` (FK → `inventory_sessions`, `cascadeOnDelete`),
  polymorphic `participant` (`participant_type`, `participant_id` via
  `$table->morphs('participant')`), `timestamps`,
  `unique(['inventory_session_id', 'participant_type', 'participant_id'])`.
- Model `App\Models\InventorySessionParticipant` with `morphTo participant`.
- `InventorySession::participants(): HasMany` → `InventorySessionParticipant`
  (eager-load `participant`). Polymorphic types persist as full class names
  (`App\Models\User` / `App\Models\Player`) — no morph map in this app.

### Sync endpoint
- `InventoryController::participants(Request, InventorySession)`:
  `abort_if($session->status !== 'in_progress', 403)`. Validates
  `participants` = array of `{ type: in:User,Player, id: integer }`.
  Replaces the session's participant rows with the provided set (delete all,
  re-insert), mapping `User`→`App\Models\User`, `Player`→`App\Models\Player`,
  each validated to exist. `back()` with success.
- Route `POST /equipment/stocktake/{session}/participants` →
  `inventory.participants`, in the same group as the other `inventory.*` routes.

### Session page UI
- `InventoryController::show` also passes `users` (id + name, active users) and
  `players` (id + `firstname lastname`) for the pickers, plus the session's
  current participants (via the eager-loaded relation on `session`).
- In `Inventory/Session.vue`, while `session.status === 'in_progress'`, a
  participants panel: a type toggle (Staff/User ↔ Member/Player), a
  `SearchableSelect` of that type's people, an "add" button building a local
  list, removable chips, and a save that POSTs the list to
  `inventory.participants`. When completed, show participants read-only.
- Participants appear on the session page and are added to the PDF report
  (`pdf/inventory-report` blade) as a comma-separated names line under the
  session sub-header. The Excel export (a per-item count sheet) is left
  unchanged — session-level participants don't fit its row model.

---

## i18n
Add to `en.json`, `fr.json`, `ar.json` (skip any that already exist):
`storage_locations`, `storage_location`, `participants`, `add_participant`,
`staff`, `member`, `unassigned`.

## Testing
- **StorageLocation CRUD**: admin can create (unique name enforced), rename,
  delete; non-admin is refused.
- **Item location**: `show` exposes `storageLocations`; store/update still
  persist a location string (dropdown is UI-only).
- **Participants**: `participants` endpoint syncs a mixed User+Player set;
  re-posting replaces the set; posting to a completed session is refused (403);
  the relation returns the linked models.
- Follow existing conventions (`tests/Feature/EquipmentHistoryTest.php`,
  `EquipmentItemManagementTest.php`, `TransactionFinanceCategoryTest.php` for
  admin-gated routes).

## Out of scope
- Migrating existing free-text `location` values into `storage_locations` rows
  (they remain valid strings; admins can add matching managed names).
- A foreign key from `equipment_items` to `storage_locations`.
- Editing participants after a session is completed.
- Per-location capacity, addresses, or nesting.
- Participants in the Excel export (per-item count sheet — unchanged).
