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
| Mail register | Outgoing **and** incoming |
| Outgoing letter | Register entry + optional letter body printed on letterhead |
| Auto mail reference | Mission orders, purchase orders, absence letters, match convocations |
| Reprints | Reuse the same reference for the same document |
| Mission travelers | One or more people (user / board member / player or typed), one sheet each |
| Import | Suppliers, 141 letters, ~60 old payment orders (history), 2 missions |
| Menu | Payment + purchase orders under Finance; Suppliers in App Configuration; new **Secretariat** (الأمانة) section: Mail register, Mission orders |
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
- **Payment order** (all statuses except cancelled, draft printed with a "DRAFT" watermark).
- **Bank transfer order** when method is bank or ccp: beneficiary bank + RIB, club account
  (`WebsiteConfig.banking_info`) as debit account, amount in figures and words.
- **Cash order** when method is cash: who receives, amount in figures and words.
- Amount in words for ar / fr / en: `app/Support/AmountInWords.php` (dinars + centimes).

### Pages
- Finance → **Payment orders**: list with year / status / supplier / method filters, search
  on subject / number, totals; create / edit form; show page with status actions and print
  buttons.

### Import (shared button, see Import)
- Suppliers: distinct `Fonctionnement.Fournisseur` with `BANQUE F`, `N° COMPTE (RIB) F`, plus
  names from the other two tables; dedupe by normalized name.
- Orders: `Fonctionnement` rows → legacy transfer orders; `الامر بالدفع` and `order de
  versement` merged on (date, amount) → one legacy order (source `payment` or `cash`).
  Status paid, no transaction created (history only — finance totals untouched).

## P2 — Purchase orders

- `purchase_orders`: `year`, `number`, legacy fields, `status` draft / sent / received /
  cancelled, `order_date`, `supplier_id`, `delivery_mode`, `payment_mode`, `observation`,
  audit columns. Own `NN/YYYY` sequence. Its mail reference (P3) is found through the mail
  entry's `source_*` columns, no FK here.
- `purchase_order_lines`: `equipment_catalog_id` nullable, `designation`, `quantity`,
  `unit_price`, `amount` (computed server-side), `sort_order`.
- Sent: locks lines; print available from draft (watermark) onward.
- **Received** dialog, both optional:
  - receive catalog lines into stock through `EquipmentStockService::receive` with
    `record_expense = false` (no expense here);
  - create a draft payment order prefilled (supplier, total, subject, `purchase_order_id`).
- Money is recorded once, when that payment order is marked paid.
- Payment order form can link an existing purchase order of the same supplier.
- Import: the single 2021 order as legacy.

## P3 — Mail register

- `mail_entries`: `direction` out / in, `year`, `number`, legacy fields, `entry_date`
  (nullable for legacy), `correspondent` (recipient or sender), `address`, `subject`,
  `body` text (outgoing letter), `sender_reference` + `received_date` (incoming),
  attachment (private disk, pattern of transaction receipts), `notes`,
  `source_type` / `source_id` / `source_key` (unique together when set), `created_by`.
- Pages: Secretariat → **Mail register** with Outgoing / Incoming tabs, year filter, search;
  create / edit; attach scan.
- **Letter PDF** (outgoing with body): letterhead, reference, date, recipient, address,
  subject, body (`nl2br`, no `pre-wrap` in mPDF), president signature.
- **Auto reference** `MailRegister::referenceFor($source, $key, $correspondent, $subject)`:
  firstOrCreate on (`source_type`, `source_id`, `source_key`); reprint returns the same
  reference. Keys:
  - mission order: the order (P4);
  - purchase order: the order, when printed from status sent onward;
  - absence letter: player + period;
  - match convocation: the game.
  The reference is printed on those PDFs.
- New **Secretariat** nav section (`useNavigation.js`), module `mail`.
- Import: 141 `Corresponds` rows, outgoing, legacy references kept, empty dates stay empty,
  «طبعت» moved to notes.

## P4 — Mission orders

- `mission_orders`: `order_date`, `destination`, `reason`, `departure_date`, `return_date`,
  `transport`, legacy fields, `created_by`. Mail reference through the mail entry's
  `source_*` columns.
- `mission_order_travelers`: `traveler_type` user / board_member / player / null (typed),
  `traveler_id`, `name`, `function` (snapshot), `sort_order`.
- Print: one A4 sheet per traveler in one PDF, mail reference on each, president signature.
  First print takes the mail reference.
- Secretariat → **Mission orders** list / form / show; module `missions`.
- Import: the 2 missions of 2018 (refs 59, 60 as legacy references).

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
