# Club documents from the old Access app — design

Owner-approved 2026-11-25. Source: `Sauvegarde de Sauvegarde de IRNB 2021 FOOT BALL.accdb`
(old MS Access app, 45 tables). Four features the Access app had and ASIREM lacks, plus a
one-time import of the old records. One package per plan and branch, shipped in order
P1 → P2 → P3 → P4.

## What exists vs the gap

| Need | Exists in ASIREM | Gap |
|---|---|---|
| Payment order | Expense transactions with `payment_method`, `payment_account`, `payment_holder`, `payment_reference`; A4 receipt PDF with `ClubHeader` letterhead; yearly numbering pattern (`InventoryController` INV-YYYY-NN) | No supplier, no RIB, no cheque method, no order number, no expense → board meeting link, meetings have no PV number |
| Purchase order | Equipment "Receive Stock" (`EquipmentStockService::receive`) can create the expense | No supplier, no order document |
| Mail register | Absence letter, match convocation PDFs | No register, no reference numbers anywhere |
| Mission order | Coaches are Users (`team_coaches`), `BoardMember`, free-text session coach | No mission document |

Old Access data (how it was really used):
- `Fonctionnement` (18): bank transfer orders to suppliers — number `NN/YYYY`, date, subject, amount, supplier, bank, RIB, transfer number, nature. Cheque/VAT/commission columns never used.
- `order de versement` (24) and `الامر بالدفع` (19): the same expenses — cash orders (often paying back the treasurer) and the Arabic payment order. Meeting / invoice / purchase-request columns never filled.
- `Corresponds` (141): outgoing letters 2016–2024, reference `YYYY/NN`, recipient, "address" (54 rows hold the status «طبعت» = printed), date (half empty).
- `Ordre de Mission` (2): two coach training trips, 2018, refs 59 and 60.
- `bon de commande` (1): one camera order, 2021.

Out of scope (owner reviewed): `la sous chemise` / `aventages` (another body's staff welfare
records), players / attendance / matches / cotisations / frais / revenues (ASIREM already covers
them), copies and leftover tables.

## Owner decisions

| Topic | Decision |
|---|---|
| Payment order vs expense | **Both**: a register of orders, and orders printable from expenses |
| Order life | Draft → approved → paid (creates the expense), or cancelled. An existing expense can get an order attached |
| Supplier | **Suppliers list** in App Configuration, reused by purchase orders |
| Beneficiary | Supplier **or** club person (app user / board member) |
| Forms | **All three like Access**: payment order, bank transfer order, cash order |
| Extra fields | Cheque method (number + date), invoice number + date, board meeting link (meeting gets PV number), purchase order link |
| Order numbering | One sequence `NN/YYYY`, calendar year; transfer / cash order share the order's number |
| Print language | Follow the app language (ar / fr / en) |
| Approve | Separate **approve** right in Roles |
| Signatures | President + Treasurer names from current board members, blank line to sign |
| Purchase order | Draft → sent → received, or cancelled; on received: optional stock receive + prefilled payment order |
| Purchase lines | Equipment catalog item **or** free text |
| Purchase approval | No approve step (money is controlled at the payment order) |
| Purchase "requested by" | Free text (Access `service`) |
| Purchase prices | Optional; every line needs a price before a payment order can be made; prices stay editable after sent until a payment order is linked |
| Purchase delivery | Whole order received at once (no partial deliveries) |
| Purchase payment mode | Same list as payment orders (transfer / cheque / cash) |
| Purchase supplier | Required (legacy order may have none) |
| Purchase print | President + Treasurer signatures |
| Equipment ↔ expense | When the linked payment order is paid, the order's received equipment points to that expense |
| Mail register | Outgoing **and** incoming |
| Outgoing letter | Register entry + optional letter body printed on letterhead |
| Auto mail reference | Mission orders (P4, on first print); purchase orders, absence letters, match convocations through a **tick box at print** (owner 2026-11-26) |
| Mail tick box defaults | Ticked for convocations and sent purchase orders, unticked for absence letters, hidden for draft orders and for users without the mail add right |
| Reprints | Reuse the same reference for the same document; once a document has one, every print shows it |
| Incoming numbering | Own sequence per year, shown with a mark (ar `و 2026/01`, fr `A-2026/01`, en `IN-2026/01`); outgoing `2026/01` |
| Mail removal | Cancel keeps the number; never deleted |
| Mail letter signatures | President + Secretary General (board role `secretary`) |
| Mail correspondent | Free text with suggestions from earlier entries |
| Mail scan | One file per entry, replaceable |
| Entries made from a print | Correspondent, subject, notes, scan editable; source link and number fixed; can be cancelled |
| Mission travelers | One or more people (user / board member / player or typed), one sheet each |
| Mission number | None of its own: the mail-register reference (outgoing `YYYY/NN`) taken on first print, like the old Access refs (owner 2026-11-26) |
| Mission after print | Editable; reprint keeps the same reference; cancel keeps it (also cancels the mail entry), never deleted |
| Mission team add | "Add a team" adds the team's players of the season + its coaches, then untick who stays home |
| Mission extra fields | None (same fields as the Access sheet) |
| Mission signatures | President + Secretary General |
| Mission costs | No link to money |
| Mission printing | All sheets in one PDF + a print button per traveler (same reference) |
| Import | Suppliers, 141 letters, ~60 old payment orders (history), 2 missions |
| Menu | Payment + purchase orders under Finance; Suppliers in App Configuration; new **Secretariat** (الأمانة) section: Mail register, Mission orders |
| Finance reset | Keeps all payment orders as history (status unchanged, link to the wiped expense cleared) |
| Draft print | A "DRAFT" banner at the top is enough (no watermark) |
| Build order | P1 suppliers + payment orders → P2 purchase orders → P3 mail register → P4 mission orders |

## Shared rules

- **Numbering service** (`app/Services/Documents/YearlySequence.php`): `next(table, year)` returns
  max(number)+1 for the year inside the caller's `DB::transaction`; tables carry a unique
  index on `(year, number)` (on mail: `(direction, year, number)`) so a race fails loudly
  instead of duplicating, and the service retries once on unique violation. Cancelled
  documents keep their number (gaps stay visible). Display formats: payment / purchase order
  `NN/YYYY` (2-digit pad, wider when > 99), mail `YYYY/NN`.
- **Legacy rows** (imported): `number` null, `legacy_reference` string keeps the old text,
  `is_legacy` true. They never enter the sequence and are read-only.
- **PDFs** via `PdfService`, letterhead `pdf/partials/header.blade.php`, RTL when locale is
  `ar` (`<body dir="rtl">`, `<bdo dir="ltr">` around RIBs, numbers, dates — see mPDF notes in
  memory). Language = current UI locale, labels through `UiLang` (`$L(...)`).
- **Signature block** partial `pdf/partials/signatures.blade.php`: President and Treasurer
  from active `BoardMember` rows with role `president` / `treasurer` (current term); name
  printed under the title, blank line if no member.
- **Permissions**: one module per feature in `config/permissions.php` + `Role::MODULES` /
  `MODULE_GROUPS`; print routes map to `view` via `overrides`. Each package ships an
  idempotent grant migration (pattern `2026_10_15_100003_grant_competition_permissions.php`):
  system roles full, others none.
- **Activity log**: new `ActivityAction` entries (create / approve / pay / cancel / print /
  import); properties hold ids and references only, never names or free text.
- **SQL** portable SQLite + MySQL (no `strftime`, no `||`); year from PHP, not SQL functions.
- **i18n**: ar / fr / en keys insert-only, flat dotted, `npm run i18n:check`; no key segment
  after a message may be a String method name.

## P1 — Suppliers + payment orders

### Data
- `suppliers`: `name` (unique, case-insensitive check via `NameNormalizer`), `activity`,
  `address`, `phone`, `bank_name`, `rib`, `notes`, `active` bool, timestamps.
  App Configuration list (pattern `VenueController` / `Settings/Venues.vue`), own module
  `suppliers`, own nav entry. Delete blocked when referenced (deactivate instead).
- `board_meetings.pv_number` nullable string (shown and editable on the meeting form).
- `transactions` adds: `supplier_id` nullable FK, `invoice_number`, `invoice_date`,
  `cheque_date`, `board_meeting_id` nullable FK. Cheque number reuses `payment_reference`.
  `payment_method` gains `cheque` (form shows number + date block; `StoreTransactionRequest`
  updated). Fields appear on the expense form, show page and revision history.
- `payment_orders`:
  - identity: `year`, `number` (nullable for legacy), `legacy_reference`, `is_legacy`,
    `legacy_source` (`transfer` / `cash` / `payment`).
  - `status` enum draft / approved / paid / cancelled; `order_date`; `subject` (150);
    `amount` decimal(12,2); `finance_category_id`; `finance_account_id`; `payment_method`.
  - beneficiary: `beneficiary_type` (supplier / user / board_member), `beneficiary_id`;
    snapshot columns `beneficiary_name`, `beneficiary_bank`, `beneficiary_rib` filled at
    approval (drafts read live).
  - `payment_reference` (cheque / transfer number), `payment_date`, `invoice_number`,
    `invoice_date`, `board_meeting_id`, `purchase_order_id` (nullable, FK added in P2),
    `observation` text.
  - `transaction_id` nullable FK (the expense), `created_by`, `approved_by`, `approved_at`,
    `paid_at`, `cancelled_by`, `cancelled_at`, `cancel_reason`.

### Behaviour
- Number assigned on create (draft).
- Draft: editable. Orders are never deleted (the number is consumed); cancel instead.
- **Approve** (right `payment_orders.approve`): freezes beneficiary snapshot; order becomes
  read-only except cancel / mark paid.
- **Mark paid**: one `DB::transaction` creating the expense `Transaction` (title = subject,
  amount, category, account, method, payment fields, supplier, invoice, meeting, status
  Paid) and setting `transaction_id`, `paid_at`. Exactly one expense per order (guard on
  `transaction_id` null + status approved).
- **From an expense**: "Create payment order" on an expense without an order makes an order
  in status paid, linked, fields copied, number from the sequence of the expense year.
- **Cancel**: draft or approved, reason required. A paid order cannot be cancelled (fix the
  expense through the existing correction flow).
- Expense page shows its order (number + link); order page shows its expense.

### Prints (`PaymentOrderPrintController`)
- **Payment order** (all statuses except cancelled, draft printed with a "DRAFT" banner).
- **Bank transfer order** when method is bank or ccp: beneficiary bank + RIB, club account
  (`WebsiteConfig.banking_info`) as debit account, amount in figures and words.
- **Cash order** when method is cash: who receives, amount in figures and words.
- Amount in words for ar / fr / en: `app/Support/AmountInWords.php` (dinars + centimes).

### Pages
- Finance → **Payment orders**: list with year / status / supplier / method filters, search
  on subject / number, totals; create / edit form; show page with status actions and print
  buttons.

### Import (shared button, see Import)
- Suppliers: distinct `Fonctionnement.Fournisseur` with `BANQUE F`, `N° COMPTE (RIB) F`;
  dedupe by normalized name. As built, supplier names come from the transfer orders only — the
  other two tables name mostly the treasurer, so their beneficiaries stay plain names (an
  imported order is linked to a supplier when its beneficiary matches one).
- Orders: `Fonctionnement` rows → legacy transfer orders; `الامر بالدفع` and `order de
  versement` merged on (date, amount) → one legacy order (source `payment` or `cash`).
  Status paid, no transaction created (history only — finance totals untouched).

## P2 — Purchase orders

Mirrors P1: own `PurchaseOrder` model, `purchase_order_lines`, `PurchaseOrderService`
(atomic conditional claims for state changes), `PurchaseOrderException` flash pattern.

### Data

- `purchase_orders`: `year`, `number` (given at creation via `YearlySequence`, own `NN/YYYY`
  sequence, unique `(year, number)`), legacy fields (`is_legacy`, `legacy_reference`,
  `legacy_source`), `status` draft / sent / received / cancelled, `order_date`,
  `supplier_id` (required; null only on legacy rows), `requested_by` (free text, ≤ 200),
  `delivery_mode` (free text, ≤ 200), `payment_mode` (`PaymentOrder::METHODS`: transfer /
  cheque / cash), `observation`, `sent_at`, `received_at`, `cancelled_at`, audit columns
  (`created_by`, `sent_by`, `received_by`, `cancelled_by`). Its mail reference (P3) is found
  through the mail entry's `source_*` columns, no FK here.
- `purchase_order_lines`: `purchase_order_id` (cascade delete), `equipment_catalog_id`
  nullable, `designation` (required; prefilled from the catalog name), `quantity` (≥ 1),
  `unit_price` nullable, `amount` nullable (quantity × unit_price, computed server-side),
  `sort_order`. At least one line per order.
- FK added on `payment_orders.purchase_order_id` (nullOnDelete).
- `equipment_items.purchase_order_id` nullable (nullOnDelete): set on items received from
  the order.

### Behaviour

- **Draft**: everything editable. Print available with the "DRAFT" banner.
- **Send** (draft → sent): supplier, designations, catalog links and quantities lock.
  **Unit prices stay editable** (sent or received) until an active payment order is linked.
- **Cancel**: from draft or sent; keeps its number. Received orders cannot be cancelled.
- **Receive** (sent → received), whole order at once. Dialog: received date (default
  today) and two optional ticks:
  - *Put catalog lines into stock*: shown when the order has catalog lines and the user may
    receive equipment stock. Each catalog line → `EquipmentStockService::receive` with
    `quantity`, line `unit_price` (may be null), `designation` from the line, condition
    New, `received_via = purchase`, `record_expense = false`, `purchase_date` = received
    date, and `purchase_order_id` on every created item. Free-text lines never go to stock.
  - *Create payment order*: shown when every line has a price and the user has
    `payment_orders.create`. Draft payment order with beneficiary = the supplier (bank,
    RIB from the supplier), amount = order total, subject "Purchase order NN/YYYY",
    method = the order's payment mode, `purchase_order_id`.
  Both run in the same transaction as the status change.
- A received order with no active (non-cancelled) payment order shows a **Create payment
  order** button (same prefill, same price rule). **At most one active payment order per
  purchase order**; a second is refused.
- **Payment order form**: optional link to a received purchase order of the same supplier
  with no active payment order. Changing supplier clears the link.
- **Paid**: when `PaymentOrderService::markPaid` creates the expense for an order linked to
  a purchase order, it sets `purchase_transaction_id` on that purchase order's equipment
  items where it is still null. Money is recorded once, at payment.
- Order page shows its lines, total, linked payment order (with status) and received
  equipment count; payment order page links back to the purchase order.
- Finance reset leaves purchase orders untouched (their payment orders stay too, see P1).

### Print (`PurchaseOrderPrintController`)

One form "Bon de commande" / «سند طلب», UI language, letterhead, number, date, supplier
name / address / phone, requested by, delivery mode, payment mode, lines table
(designation, quantity, unit price, amount; unpriced cells blank), total and amount in
words only when every line has a price, observation, signatures partial (President +
Treasurer). Draft prints carry the "DRAFT" banner.

### Rights and menu

Module `purchase_orders` in the finance group (view / create / edit; no approve). Print
route → view. Send / receive / cancel / create-payment-order → edit. Grant migration:
system roles full, others none. Menu: under Finance next to payment orders. Activity
codes: `PURCHASE_ORDER_CREATED`, `_UPDATED`, `_SENT`, `_RECEIVED`, `_CANCELLED`,
`_PRINTED` (ids, references, totals only).

### Import

The single 2021 camera order → legacy, read-only, status received, no supplier, one
free-text line (designation, quantity 1, no price), `legacy_reference` "01/2021",
old "N° Ordre" kept in observation (not linked: P1's legacy 01/2021 is another expense).
Idempotent like the P1 sections. `scripts/legacy/export-access.ps1` gains an optional
`purchase_orders` section from `bon de commande` (format id stays `access-legacy-v1`; a file
without the section still imports). Claude re-exports `D:\BACKUPS\irnb-access-legacy.json`.
Stock-receive right in the dialog = the `equipment.stock.receive` route permission.

## P3 — Mail register

Design approved by the owner 2026-11-26.

### Data

- `mail_entries`: `direction` out / in, `year`, `number` (null for legacy), `status`
  active / cancelled (+ `cancelled_by`, `cancelled_at`), `entry_date` (nullable for legacy),
  `correspondent` (recipient or sender), `address`, `subject`, `body` text (outgoing letter),
  `sender_reference` + `received_date` (incoming), one attachment (private disk, pattern of
  transaction receipts, replaceable), `notes`, `source_type` / `source_id` / `source_key`
  (unique together when set), `is_legacy`, `legacy_reference`, `legacy_source`, `created_by`.
- Unique `(direction, year, number)`; numbers from `YearlySequence` scoped by direction.
  Outgoing and incoming each count from 01 every calendar year.
- Display: outgoing `YYYY/NN`; incoming the same with the localized mark (ar `و `, fr `A-`,
  en `IN-`). Legacy rows show `legacy_reference`.

### Behaviour

- Create / edit outgoing or incoming entries. Cancel keeps the number, never delete.
  Cancelled entries are read-only. Legacy entries: only notes and scan editable (lets the
  secretary attach scans of old letters).
- Entries made from a print: correspondent, address, subject, notes, scan editable;
  `direction`, number, date and source link fixed; can be cancelled. The page links to the
  source document (absence letter player page, game, purchase order) when the user may view it.
- Correspondent field suggests distinct correspondents used before (same direction first).
- `MailRegister::register(string $type, int $id, string $key, array $defaults, User $by)`
  returns the existing active entry for (`source_type`, `source_id`, `source_key`) or creates
  one (outgoing, today, next number) inside a transaction; `MailRegister::find(...)` only
  looks up. A cancelled entry does not block a new one for the same source.
- **Tick box at print**: the print button on the absence letter, game convocation and
  purchase order opens a small dialog with «Register in the mail register». Ticking POSTs
  to a register route (needs `mail` add right), then the PDF opens. The PDF routes only look
  up the entry, so opening, refreshing or reprinting never creates a number. Once a source
  has an active entry, every print shows its reference and the dialog shows it instead of
  the box. Defaults: ticked for convocations and for purchase orders sent / received,
  unticked for absence letters, hidden for draft or cancelled orders and for users without
  the add right (they print straight away).
- Source keys: absence letter `player:{id}|branch:{id or 0}|{from}|{to}`; convocation the
  game; purchase order the order. Default correspondent / subject come from the document
  (guardian / contact name, the team for a convocation, supplier).

### Pages, rights, menu

- New **Secretariat** (الأمانة) nav section in `useNavigation.js` with **Mail register**
  (P4 adds Mission orders there).
- Mail register: Outgoing / Incoming tabs, year filter, search (reference, correspondent,
  subject), status badge, link to source; create / edit form; show page with scan and
  letter print.
- Module `mail` with view / add / edit / delete (delete = cancel); print → view, register →
  add. New rights go to system roles only (owner rule), by migration.

### Print

- **Letter PDF** (outgoing entry with a body): letterhead, reference, date, recipient,
  address, subject, body (`nl2br`, `PdfText` for Latin lines in ar), then **President +
  Secretary General** signatures (current-term active board members with role `president` /
  `secretary`, blank line if none). `BoardSignatories` gains the secretary.
- Absence letter, convocation and purchase order PDFs print «Ref: …» near the date when the
  source has an active entry.

### Import

- `Corresponds` (141 rows) → outgoing legacy entries: legacy reference text kept, empty dates
  stay empty, recipient → correspondent, «طبعت» in the address column moved to notes.
  Export script gains an optional `mail` section (format id stays `access-legacy-v1`; files
  without it still import). Idempotent by (`legacy_source`, `legacy_reference`, date,
  correspondent). Claude re-exports `D:\BACKUPS\irnb-access-legacy.json`.

## P4 — Mission orders

Design approved by the owner 2026-11-26.

### Data

- `mission_orders`: `order_date`, `destination`, `reason`, `departure_date`, `return_date`
  (dates only), `transport`, `status` active / cancelled (+ `cancelled_by`, `cancelled_at`),
  `is_legacy`, `legacy_reference`, `legacy_source`, `created_by`, `lock_version`. No number
  of its own: the reference is the active mail entry with source `mission_order` / order id /
  key `order` (`MailRegister::find`), shown as its `reference`; legacy orders show
  `legacy_reference`.
- `mission_order_travelers`: `mission_order_id` (cascade), `traveler_type` user /
  board_member / player / null (typed), `traveler_id`, `name`, `function` (both snapshots,
  editable), `sort_order`.

### Behaviour

- Create / edit (active, non-legacy orders; also after printing). Cancel keeps the order and
  cancels its active mail entry in the same transaction (number stays in the register);
  never deleted. Legacy orders read-only.
- Travelers: at least one. Add a person (user, board member, player) or a typed name;
  function defaults: user → «مدرب» / coach label, board member → their board role label,
  player → «لاعب — {category}»; editable. **Add a team**: the team's players of the current
  season (`TeamMembers`) + its coaches for that season, each a traveler row the user can
  untick before saving; duplicates (same type + id) are not added twice.
- **Print takes the reference**: the Print buttons (all sheets, one traveler) POST
  `missions.register` (needs `missions` edit) which calls `MailRegister::register('mission_order',
  id, 'order', defaults)` — correspondent = travelers' names (first three, then «+N»),
  subject = «أمر بمهمة — {destination}» (localized label) — then open the PDF. The PDF GET
  routes only look up the reference. A user with view only prints directly (reference shown
  when one exists). Cancelled and legacy orders print without registering.
- The mail register shows the mission order as an entry's source (link when the viewer has
  `missions` view); `MailSources` gains the type.

### Print

- One A4 sheet per traveler, all in one PDF (`missions.print`), or one traveler
  (`missions.print-traveler`). Each sheet: letterhead, «أمر بمهمة / Ordre de mission»,
  reference, date, name, function, destination, reason, departure and return dates,
  transport, then President + Secretary General signatures (`BoardSignatories`). Page break
  between sheets. Same mPDF rules (RTL, `PdfText`, tables).

### Pages, rights, menu

- Secretariat → **Mission orders**: list (year of order date, search by destination /
  traveler / reference, status), create / edit / show, cancel, print buttons.
- Module `missions` in the `secretariat` group, actions view / add / edit / delete
  (delete = cancel); print → view; register → edit. Grant migration: system roles only.

### Import

- `Ordre de Mission` (2 rows, 2018, one traveler each) → legacy orders with
  `legacy_reference` (refs 59, 60), one typed traveler (Nom + Prénom, Fonction). No mail
  entries created (the old letters are already in the imported mail register). Export
  script gains an optional `missions` section; idempotent by (`legacy_source`,
  `legacy_reference`, order date).

## Import of old Access data

- Export (done once by Claude, PowerShell + ACE OLEDB): one JSON file with the needed tables.
  **Not committed** (names, RIBs). Owner keeps it next to backups.
- Superadmin action "Import old Access data" (Settings, god / superadmin only) uploads the
  JSON and runs `LegacyAccessImporter` per section available so far (P1 suppliers + orders,
  P3 mail, P4 missions, P2 purchase order). Idempotent: legacy rows keyed by
  (`legacy_source`, `legacy_reference`, date, amount) — re-import changes nothing. Result
  screen shows created / skipped counts. Works on web PC and desktop (no command line).

## Testing

- Per package feature tests: numbering (sequence per year, cancelled keeps number, unique
  index), status transitions and forbidden ones, approve right enforced, mark-paid creates
  exactly one expense, expense → order, purchase received with and without stock / payment
  order, mail reference reused on reprint, permission grants.
- PDF smoke tests (200 + `application/pdf`) for every print, ar and fr.
- Import test with a small fixture JSON, run twice → same counts.
- `npm run i18n:check`, `npm run build`, full suite
  (`php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit`) before each merge.
