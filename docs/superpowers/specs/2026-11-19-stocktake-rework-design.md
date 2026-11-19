# Stock-take rework — design

Date: 2026-11-19. Owner-approved the same day. Queued in Help Center 3a ("stock-take gaps → own round after Part 3").

## Problem

Found while documenting the equipment stock-take (`resources/help/ar/equipment/stock-take.md`):

1. A lot (one row of N units, e.g. 40 cones) is all-or-nothing on the count sheet. The server already stores `found_quantity`, but `Inventory/Session.vue` never sends it.
2. Units out on loan are on the sheet at the lot's full size. «مفقود» writes them off as lost while the loan is still open.
3. Lots already «مفقود» (`status = Lost`) are on the sheet again and can be written off twice.
4. While counting, the tiles mix units (expected) and rows (found/missing).
5. Each line has a `note` column but the sheet has no box for it.
6. The stock-take PDF prints English words (title, type, status, columns, Found/Missing).
7. Report, PDF and export show only ✓/✗, never «47 / 50».

## Owner decisions (2026-11-19)

- **Counting:** the counter types how many they see. A line of more than one unit reads «موجود [47] من 50»; the 3 missing leave stock as «مفقود» at completion. The word «دفعة» (lot) is never shown. Single units keep «موجود / مفقود».
- **On loan:** left off the sheet. Expected = units in the club; the line notes «5 معارة». An item fully on loan has no line. Nothing on loan can be written off by a count.
- **Lost / under repair:** lost lots are off the sheet; lots «قيد الإصلاح» stay on it and are counted. «خارج الخدمة» stays (it is in storage).
- **Extras, all chosen:** tiles count units live; note box per line; PDF in the app language; numbers in report/PDF/export.
- **Not in this round:** units above expected (cap at expected; extras come in through «استلام مخزون»), changing the condition of part of a lot during the count, French/English article text.

## Design

### 1. Count sheet at start (`InventoryController::store`)

- Snapshot lots whose status is neither `Retired` nor `Lost`.
- For each lot: `on_loan = EquipmentStockService::outstandingQuantity($item)`, `expected = quantity − on_loan`. A lot with `expected = 0` gets no line.
- New column `inventory_session_items.on_loan_quantity` (unsigned int, default 0) keeps the snapshot so the line can say «5 معارة» even if loans change during the count. Additive migration; the desktop app runs it on boot.
- `total_expected` = sum of `expected`.

### 2. Counting (`counts` + `Session.vue`)

- Line with `expected_quantity > 1`: number box 0…expected, starts at expected. Line with `expected_quantity = 1`: the two buttons as today.
- `found` stays as the server field: `found = found_quantity > 0`. Validation: `found_quantity` integer, `0 ≤ found_quantity ≤ expected_quantity` of that line (checked per line on the server).
- «تحديد الكل موجود» sets every line to its full expected number.
- Note box per line (max 500, as validated today).
- Line subtitle shows «N معارة» when `on_loan_quantity > 0`.
- Tiles while open, in units: expected = `total_expected`; found = sum of the found numbers; missing = expected − found.
- Condition and location selects unchanged; they stay disabled when nothing was found.

### 3. Completing (`complete`)

- As today: found units keep/update condition and location; missing units go through `writeOffMissing`.
- Safety net for sheets made before this change or loans made during the count:
  - skip a line whose item is now `Lost` or `Retired`;
  - cap the written-off units at the units physically in the club now: `quantity − outstandingQuantity` (status ignored, so units under repair can still be written off).
- Totals as today (units).

### 4. Report, PDF, export

- Completed report (`Session.vue`) and PDF (`pdf/inventory-report.blade.php`): a discrepancy is also a line where `found_quantity < expected_quantity`. The found column shows «47 / 50» for lines of more than one unit, ✓/✗ (PDF: «موجود» / «مفقود») for single units.
- PDF: every visible word in the app language (title, type, status, participants, tiles, column names, «لا فوارق»), conditions via `EquipmentItem::stateLabel` as today.
- Export (`InventoryController::export`): add columns found and expected quantity next to the result; the note column already exists.

### 5. Help and tests

- Rewrite `resources/help/ar/equipment/stock-take.md` to match: typed numbers, «معارة» note, lent items not on the sheet, lost lots not on the sheet. The example becomes: 2 of the 40 cones are missing plus «ساعة توقيت 3»; «مرمى 1» is on loan and is not on the sheet.
- `HelpDemoEquipmentSeeder::snapshot` follows the new store rule; demo already has 2 balls on loan and the goal on loan. Retake the `eq-stock-take-*` shots (light + dark); `help:check` passes.
- Tests: expected at start (lent part removed, fully lent skipped, lost skipped, under repair kept, on_loan stored); partial count → lost lot of the missing units; cap at expected (422); note saved; safety net (loan made after start, item lost after start); report/PDF/export show the numbers; PDF has no English words in Arabic.

## Rules

Nothing pushed until the owner says "push". Explicit staging. i18n edits insert-only. No desktop build unless asked. Commits end with the Co-Authored-By line.
