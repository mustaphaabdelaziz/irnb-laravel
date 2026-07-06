# Equipment Item Serial Auto-Generation — Design

**Date:** 2026-07-06
**Status:** Approved (brainstorm)

## Goal

Equipment items (materials) currently require a hand-typed `unique_identifier`
(e.g. `BALL-001`) in the add-item form. Replace this with a serial the system
generates automatically, following a fixed formula:

```
{CLUB}-{YYYY}-{CODE}-{NNNNN}
```

Example: `IRNB-2026-BALL-00001`

## Decisions (locked)

| Question              | Decision                                                    |
|-----------------------|-------------------------------------------------------------|
| Category code source  | New admin-defined `code` column on `equipment_categories`   |
| Counter scope         | Resets per **(year + category code)**                       |
| Year format           | 4-digit (`2026`)                                            |
| Editable?             | Auto, **read-only** — server generates on save; form previews |
| Club abbreviation     | `WebsiteConfig.club_short_name`, uppercased, alnum-only; fallback `CLUB` |
| Generation mechanism  | Server-side `SerialNumberService` + read-only preview endpoint |

## Format parts

- **CLUB** — `WebsiteConfig::singleton()->club_short_name`, uppercased, non-alphanumeric
  stripped. Empty/unset → `CLUB`.
- **YYYY** — 4-digit year taken from the item's `purchase_date`.
- **CODE** — `code` of the item's category, resolved via
  `equipment_catalogs.category` (name string) → `EquipmentCategory`. If a row
  has no code, fall back to a derived value (first 4 alnum of the category name,
  uppercased).
- **NNNNN** — zero-padded counter, minimum 5 digits, scoped to the
  `CLUB-YYYY-CODE-` prefix. Widens to 6+ digits past 99999 rather than failing.

## Section 1 — Data model

- **Migration:** add `code` (string, nullable) to `equipment_categories`.
  Backfill every existing row with a derived default (first 4 alphanumeric
  characters of `name`, uppercased) so generation never hits a null code.
- Add `code` to `EquipmentCategory::$fillable`.
- Surface `code` in **Settings → Equipment Categories** create/edit UI
  (`resources/js/Pages/Settings/EquipmentCategories.vue` +
  `EquipmentCategoryController`). Admin can refine the auto-derived defaults.
- `equipment_items.unique_identifier` stays the storage column (already unique).
  No schema change there.

## Section 2 — Serial generation service

New `App\Services\Equipment\SerialNumberService`, the single source of truth.

- `generate(EquipmentItem $item): string` — builds the full serial from the
  item's catalog (→ category code) and `purchase_date`.
- `previewNext(int $catalogId, string $purchaseDate): string` — same formula,
  used by the form preview. Preview value is best-effort (the definitive number
  is assigned on save).

**Counter + race safety:**
- Generation runs inside a `DB::transaction`.
- Compute next `NNNNN` from the current max `unique_identifier` matching the
  `CLUB-YYYY-CODE-` prefix, `+1`, zero-padded.
- The existing `unique:equipment_items,unique_identifier` DB constraint is the
  backstop. On the rare concurrent-insert collision, catch and retry (max 3
  attempts). Desktop app is effectively single-user, so contention is near-zero;
  the constraint keeps the web path safe.

## Section 3 — Wiring (controller + form)

**Backend — `EquipmentItemController::store`:**
- Remove `unique_identifier` from validated input (no longer user-supplied).
- Inside the existing `DB::transaction`: instantiate the `EquipmentItem`
  (catalog_id, purchase_date, condition, location, notes), call
  `SerialNumberService::generate($item)`, set `unique_identifier`, then save.
  Serial assignment stays atomic with the row insert.
- Keep the optional purchase-price → `Transaction` logic unchanged.

**Preview endpoint:**
- `GET equipment/items/preview-serial?catalog_id=&purchase_date=` →
  `{ serial: 'IRNB-2026-BALL-00001' }`. Registered beside the other
  `equipment.items.*` routes in `routes/web.php`.

**Frontend — `Show.vue` add-item modal:**
- Remove `unique_identifier` from `addItemForm`.
- Replace the editable "ID / Serial" `TextInput` with a **read-only** preview
  field. Fetch the preview when the modal opens and whenever `purchase_date`
  changes (category is fixed by the current catalog). Display with a
  "assigned on save" note.

## Section 4 — Testing

- **`SerialNumberService`** feature test: format correctness; per-(year+category)
  counter increment; year rollover resets the counter; missing `club_short_name`
  → `CLUB` fallback; distinct categories do not share a counter.
- **Item store** feature test: POST without `unique_identifier`; assert stored
  serial matches the formula and is unique; second item in same year+category
  increments the counter.
- **Migration backfill** test: existing categories receive a non-null derived
  `code`.
- Follow existing conventions in `tests/Feature/EquipmentHistoryTest.php`.

## Out of scope

- Backfilling/renaming serials of already-existing equipment items. Existing
  `unique_identifier` values are left untouched; only new items follow the
  formula.
- Changing how the catalog (`EquipmentCatalog`) itself is identified.
