# Equipment Item Management (designation + edit + delete) — Design

**Date:** 2026-07-07
**Status:** Approved (brainstorm)

## Goal

Let staff manage individual equipment items properly:
1. Give each item an optional **designation** (a human title), e.g. catalog "Football T-shirt" → items "T-shirt n° 10", "T-shirt n° 9".
2. **Edit** an existing item's mutable fields.
3. **Delete** an item safely.

Today items can only be created (add-item modal) and acted on via lifecycle
buttons (rent/return/repair/lost). There is no edit or delete path, and no
per-item title beyond the auto-generated serial.

## Decisions (locked)

| Question         | Decision                                                                 |
|------------------|--------------------------------------------------------------------------|
| Designation      | Optional free-text, `nullable|string|max:255`                            |
| Editable fields  | designation, purchase_date, condition, location, notes                   |
| Serial on edit   | **Never regenerated** — `unique_identifier` is immutable identity        |
| Delete safety    | **Blocked while Rented**; otherwise delete item + cascade rentals/history |
| Purchase txn     | **Kept** on delete (financial record already booked)                     |

## Data model

- Migration: add `designation` (nullable string) to `equipment_items`, after
  `unique_identifier`.
- Add `designation` to `EquipmentItem::$fillable`.
- No other schema change. `equipment_histories.item_id` and
  `equipment_rentals.equipment_item_id` are already `cascadeOnDelete`;
  `equipment_items.purchase_transaction_id` is `nullOnDelete` toward
  `transactions`, so deleting an item cleans its history/rentals and leaves the
  purchase `Transaction` intact.

## Create (add-item modal)

- Add an optional **Designation** text input to the add-item form in
  `resources/js/Pages/Equipment/Catalog/Show.vue` (below the serial preview).
- `EquipmentItemController::store`: add `designation` →
  `nullable|string|max:255` to validation and to the built `EquipmentItem`.

## Edit (new path)

- `EquipmentItemController::update(Request, EquipmentItem $item)`:
  validates `designation` (nullable|string|max:255), `purchase_date`
  (required|date), `condition` (nullable|in:New,Good,Fair,Poor,Damaged),
  `location` (nullable|string|max:255), `notes` (nullable|string), then
  `$item->update($validated)`. `unique_identifier` is **not** accepted or
  changed. `back()` with success.
- Route: `PUT /equipment/items/{item}` → `equipment.items.update`, in the
  authenticated group (same group as `equipment.items.store`), registered
  beside the other `equipment.items.*` routes.
- UI: an **Edit modal** in `Show.vue` (mirrors the add-item modal), prefilled
  from the row's item. Shows the serial read-only. A ✏️ button per row opens
  it. Submits with `form.put(route('equipment.items.update', id))` — no file
  upload is involved, so plain `PUT` (no `_method`/`forceFormData` spoofing)
  is correct and avoids the multipart pitfall the catalog edit form hit.

## Delete (new path)

- `EquipmentItemController::destroy(EquipmentItem $item)`:
  - If `$item->status === 'Rented'` (or an `activeRental` exists) → `back()`
    with an error ("Cannot delete a rented item. Return it first.").
  - Otherwise `$item->delete()` (cascades rentals + histories; keeps the
    purchase transaction) and redirect to `equipment.catalogs.show` with
    success.
- Route: `DELETE /equipment/items/{item}` → `equipment.items.destroy`, in the
  authenticated group.
- UI: a 🗑️ button per row → existing `ConfirmModal` pattern (like the current
  repair/lost confirmations) → `router.delete(route('equipment.items.destroy', id))`.

## Display

- Items table in `Show.vue`: add a **Designation** column showing
  `item.designation || '—'`, next to the serial.
- History page: `EquipmentItemController::history` already returns the item
  payload; add `designation` to it and show it beside the serial in
  `resources/js/Pages/Equipment/History.vue`.
- `EquipmentCatalogController::show` already passes the full item models
  (all columns serialize), so the new `designation`, plus `location`/`notes`
  needed to prefill the edit modal, are available with no controller change
  there.

## i18n

- Add `designation` to `en.json`, `fr.json`, `ar.json`
  (en: "Designation", fr: "Désignation", ar: "التسمية").
- `edit`, `delete`, `save`, `cancel`, `condition`, `notes`, `purchase_date`,
  `location` already exist and are reused. Add `location` only if missing.

## Testing

- **store**: posting with a `designation` persists it; posting without one
  stores null (item still created with its serial).
- **update**: changes designation + condition + location + notes +
  purchase_date; `unique_identifier` is unchanged after update.
- **destroy**: deletes a non-rented item, its `equipment_histories` and
  `equipment_rentals` rows are gone, but its purchase `Transaction` still
  exists; deleting a `Rented` item is refused and the item remains.
- Follow existing conventions (`tests/Feature/EquipmentHistoryTest.php`,
  `EquipmentSerialStoreTest.php`); item-management routes are in the
  authenticated (non-admin) group, so a verified user suffices.

## Out of scope

- Editing the serial or the catalog an item belongs to.
- Updating the linked purchase `Transaction` when `purchase_date` is edited
  (the transaction keeps its original date).
- Bulk edit/delete; soft deletes.
