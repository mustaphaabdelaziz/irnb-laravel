# Post-Testing Round 2 — Design

**Date:** 2026-09-22
**Status:** Approved in brainstorming — awaiting written-spec review

Sixteen notes raised after the second round of manual testing. They touch five areas, so this is an
**umbrella spec** (same shape as `2026-07-20-post-testing-enhancements-design.md`): it records the
whole picture and every decision, then each package (P1–P5) gets its own implementation plan and its
own branch.

| # | Note | Package |
|---|------|---------|
| 1 | Material assignment type (work use vs rental) | P1 |
| 2 | User activity tracking & statistics | P5 |
| 3 | Transaction title | P1 |
| 4 | Player identification in transactions | P1 |
| 5 | Export templates in .xlsx and .csv, Arabic/French safe | P4 |
| 6 | Membership ID ↔ player file number | P2 |
| 7 | Localized status values | P1 (screens), P4 (exports) |
| 8 | Meeting deletion / cancellation | P1 |
| 9 | Board term edit bug | P1 |
| 10 | Multiple player positions | P2 |
| 11 | Document uploads per document type | P3 |
| 12 | Document checklist / missing documents | P3 |
| 13 | Manageable document types | P3 |
| 14 | Official Wilaya names | P2 |
| 15 | Job management with inline creation | P2 |
| 16 | Player details page with icons | P2 |

**Sequencing:** P1 → P2 → P3 → P4 → P5. P5 (activity) goes last so it hooks into each feature's final
code path; its backfill recovers history from existing attribution columns, so nothing is lost by
waiting.

**Branching:** the owner first commits the in-progress cash-register / transfer work on
`feat/player-previous-debts`. Each package then branches from that point
(`feat/round2-p1-quick-fixes`, `feat/round2-p2-player-identity`, …).

---

## Cross-cutting rules

These apply to every package.

- **Identifiers, never names.** Relations use ids. A display name is never used to find a record.
- **Stable codes, translated labels.** The database stores a code (`paid`, `scheduled`, `assignment`).
  Screens, exports and PDFs translate it at render time. Stored text is never frozen in one language.
- **One label catalog.** Screen labels live in `resources/js/i18n/{ar,fr,en}.json` (flat keys; call
  `t()` directly, never guard with `te()`). P1 adds `App\Support\UiLang`, a small PHP reader over those
  same catalogs. Server-rendered text (generated transaction titles, export headers and values) then
  uses the same translations as the screens, so each label is translated once. The three catalogs
  stay the same size; `npm run i18n:check` passes.
- **Desktop needs data in migrations.** The NativePHP build runs `migrate` on boot and never runs
  seeders. Reference data that must reach installed copies goes in migrations: official wilaya names,
  player-status codes, default document types, backfills. Each package that changes the schema also
  refreshes `storage/app/seed/database.sqlite` before a desktop build.
- **No table rebuilds on SQLite.** Existing enum columns are not changed. New states use new nullable
  columns, as in the transaction-cancellation design.
- **Destructive actions** use `ConfirmModal` (not `window.confirm`). Business records are cancelled
  or archived, not deleted.
- **Tests:** PHPUnit class style with `#[Test]` and `RefreshDatabase`. Run with `composer test`, which
  clears the config cache first. Every feature covers its normal flow and its edge cases.

---

# P1 — Quick fixes

## #9 Board term edit

**Cause (found during exploration).**
- `BoardTerm` casts `start_date` / `end_date` as `date`, so Inertia sends
  `"2024-01-01T00:00:00.000000Z"`.
- `Members.vue:35` binds that value straight into `<input type="date">`, which only accepts
  `yyyy-MM-dd`. Both fields therefore open **empty**, and the hidden ISO value is sent back unchanged.
- The term modal renders **no validation errors**. A failed save (for example `after_or_equal:start_date`
  against a date the user cannot see) leaves the modal open without a message; the user closes it
  believing it saved. The owner reported exactly this: the date fields showed empty, and the date
  changes did not apply.

**Fix.**
- Cast `start_date` / `end_date` as `date:Y-m-d`.
- `openTermEdit` also slices to 10 characters, for safety.
- The modal shows `termForm.errors` under each field.
- `openTermEdit` calls `clearErrors()`, as `openTermCreate` already does.
- The same unsliced-date bug is fixed in `Tasks.vue:128` and `:179` (`due_date`).

**Tests.**
- Updating a term's dates stores them.
- End before start returns a validation error.
- The edit payload serialises dates as `Y-m-d`.

## #7 Localized statuses — screens

**Stored codes.** Most statuses are already stored as codes. What is broken is display.

**Raw English rendered today, all fixed:**
- `Transactions/Index.vue:162` and `Transactions/Show.vue:59` (payment status)
- `Subscriptions/Show.vue:259` (`payment_status`)
- `Dashboard/Partials/OperationsTab.vue:226` (equipment status)
- `Players/Show.vue:446-447` (category, payment method)

**Rendering.** A `useStatusLabel()` composable maps `(domain, code)` to a catalog key. It reuses
existing keys (`paid`, `student`, …) and adds the missing ones. Every status render goes through it.

**`player_statuses.code`.** New nullable, unique column. The migration fills it for the six seeded rows:

| Arabic name | code |
|---|---|
| منخرط | `registered` |
| معتزل | `retired` |
| متوقف | `paused` |
| غادر الفريق | `left` |
| غير واضح | `unclear` |
| معاقب | `sanctioned` |

`RegisterPlayerService` then picks the default status with `where('code', 'registered')` instead of
`where('name', 'منخرط')`.

**Guard.** `npm run i18n:check` is extended to assert that every code in every `useStatusLabel`
domain has a key in all three catalogs.

**Out of this package.** Export headers and values are handled in P4. The membership status on the
player page arrives with the P2 page redesign.

## #8 Meetings — cancel only, never delete

**Decision (owner).** Meetings are never deleted. Cancelling keeps the record.

**Removed.**
- The `board.meetings.destroy` route and `BoardMeetingController::destroy`. No page calls them today,
  and the method left the attachment file orphaned.

**Schema.** `board_meetings` gets three new nullable columns:
- `cancelled_at`
- `cancelled_by_user_id` (FK users, nullOnDelete)
- `cancel_reason` (text)

The existing `status` enum already contains `cancelled` and is not altered.

**Cancel action.**
- `POST /board/meetings/{meeting}/cancel` (`board.meetings.cancel`). The permission derives to
  board/edit.
- Allowed only while `status = scheduled`.
- The reason is required (3–500 characters).
- It sets `status`, `cancelled_at`, `cancelled_by_user_id` and `cancel_reason`.

**Status dropdown and updates.**
- The dropdown in `Meeting.vue` offers only `scheduled` / `held`. `update` validation accepts only those
  two, so cancelling happens only through the action.
- A cancelled meeting is read-only: update, attendance and attachment endpoints refuse it with a
  flash error.

**Display.**
- Badge plus reason, and who / when, on `Meeting.vue`.
- Grey chip on `Meetings.vue`, with a status filter (all / scheduled / held / cancelled).
- Excluded from "upcoming", from `meetings_total` and from attendance-rate figures on `Board/Index`.
  The calendar shows it struck through.

**Legacy rows.** Rows already set to `cancelled` through the old dropdown get
`cancelled_at = updated_at` and no reason; the reason is displayed as "—".

**UI.** A `ConfirmModal` with a reason textarea. The board pages' `window.confirm` calls touched by
this work move to `ConfirmModal`.

**Tests.**
- Cancelling a scheduled meeting works.
- Cancelling a held or cancelled meeting is refused.
- A missing reason is refused.
- Update and attendance on a cancelled meeting are refused.
- The destroy route no longer exists.
- Stats exclude cancelled meetings.

## #3 Transaction title

**Schema.** `transactions.title` string(150), nullable. Legacy rows have none.

**Manual form.**
- `title` is `required|string|max:150` in `StoreTransactionRequest`, which also serves `update`.
- When editing a legacy row, the form pre-fills `title` with the generated label, so saving needs no
  retyping.

**Automatic transactions leave `title` null.** These are subscription payments, donations, debt
payments, equipment purchases and imports without a title. Their label is **generated at render time**
in the viewer's language by `App\Support\TransactionTitle::for($tx)`, for example
"Subscription payment · 2026 · Benali Amine". The label then follows the language switch instead of
being stored in one language.
- The presenter reads relations the caller has eager-loaded (finance category, player, subscription
  line). It never lazy-loads.
- The value is exposed as `display_title` = `title` if set, otherwise the generated label.

**Displayed in:**
- Transactions list: a Title column, with description kept as secondary text.
- Transaction details.
- Player page transaction table.
- Receipt PDF.
- Dashboard activity feed (`OverviewStats::activity`, which today uses `description ?: category`).
- Transactions export.

**Search.** `TransactionController::applyFilters` also matches `title`, the player's name and the
membership ID.

**Import.** Optional `title` column. When it is empty, the label is generated.

## #4 Player identification in transactions

**Finding.** Transactions already store the player **id** (`related_entity_type = 'Player'` plus
`related_entity_id`). The problem is identification in the picker:
- Options show only `firstname lastname`, and "Amine null" when `lastname` is null.
- There is no membership ID, and search matches the name only.
- Two players with the same name are indistinguishable.

**Changes.**
- **Validation:** `related_entity_id` must exist in `players` whenever `related_entity_type = Player`
  (no existence check today).
- **Payload:** `TransactionController::formOptions` sends `id`, `fullname`, `membership_id`, the
  localized category, birth year and photo. `file_number` is added in P2.
- **`SearchableSelect`:** gains an optional per-option `description` line and `keywords`. Filtering
  matches label, description and keywords. The component is reused, not forked.
- **Option layout:**
  **Benali Amine**
  `202600017 · Cadets · 2010`
  Search matches name words in any order and the membership ID.
- **After selection:** a compact player card under the field shows photo, membership ID, category,
  branches and outstanding debt, with a link to the player.
- **Transactions list and details:** a Player column and link. Today the related player is not shown
  at all.

## #1 Assignment type (work use vs rental)

**Finding.** `equipment_rentals.type` (`rental` / `assignment`) already exists
(`2026_07_20_000004`), and the rent modal has a toggle. What is missing is enforcement and
visibility.

**Decision (owner).** Assignments go to **players only**. External people can rent but not be
assigned equipment.

**Changes.**
- **Validation:** `RentEquipmentRequest` refuses `type = assignment` unless `rentable_type = Player`.
  Existing rows are untouched.
- **Badge:** a `RentalTypeBadge.vue` component with a distinct colour and icon each for *Assigned
  (work)* and *Rented*. It is used everywhere a rental appears: catalog item table, item history,
  overdue list, dashboard, player page, the new page below.
- **Several holders per lot:** `EquipmentItem::activeRental()` is a `hasOne`, so a lot out with
  several people shows one holder, and Return acts on that one only. An `openRentals()` `hasMany` is
  added. The catalog table lists every holder, each with its own Return button.
- **New "Equipment out" page:** `equipment.out` (equipment/view). It has Rentals and Assignments tabs
  and lists holder, item or lot, quantity outstanding, since and due date, with overdue highlighted.
  It supports a holder search and a return action.
- **Player page, new Equipment section:** current and past items, rentals and assignments shown
  separately. The controller already eager-loads `equipmentRentals` (`PlayerController.php:134`) and
  the page never renders it.
- **Clean-up in passing:** the stale `due_date` watcher at `Catalog/Show.vue:343-345` (the form has
  `expected_days`, not `due_date`) and the "staff member" comment at `:347`.

---

# P2 — Player identity & reference data

## #6 Membership ID and file number

**The owner's real problem.** Paper files are listed on per-category "board tables" (name, membership
ID, file number). Categories change every season (cadet → junior → senior), so any number tied to the
category or to a list position goes stale every year.

**Decision.** Separate *where the file lives* from *which list the player is on*.

**Membership ID is frozen.**
- `PlayerController::update:310-313` no longer regenerates `membership_id` when `join_year` changes.
- Once issued, it never changes. It is printed on cards and folders.

**Permanent file number.**
- `players.file_number`: unsigned integer, unique. It is a single club-wide counter, the number
  written on the folder once.
- **Assignment:** `max + 1` inside the registration transaction, with a retry on unique collision (the
  same pattern as `MembershipNumber::generateUnique`). It is never reused, even after archiving or
  permanent deletion.
- **Backfill:** existing players are numbered once, ordered by `join_year`, then `membership_id`,
  then `id`. This runs as a data migration so desktop installs get it.
- **Not accepted from forms or CSV import**, like the membership ID.
- **Display:** zero-padded to four digits, with the drawer: `0123 · drawer 2`.
  - `drawer = ceil(file_number / drawer_size)`.
  - `drawer_size` is a Settings → General value, default 100.
- **Search:** the players list and the transaction picker match it, with or without leading zeros.

**Cabinet.**
- Folders are sorted by file number and never move.
- When a player changes category, only the printed board table changes.

**Season setting.**
- Settings → General gains `season_start_month` (1–12, default 9).
- `App\Support\Season` exposes `current()`, `forDate($date)`, start and end dates, and a label
  (`2026/27`, or `2026` when the start month is January). P3 reuses it.

**Printables (mPDF, following `ReportController` conventions):**
- **Folder label.** Single (`players.label`) or batch for a selection or filter (`players.labels`).
  - Content: Arabic and Latin name, large file number, drawer, membership ID, category, and a QR code.
  - The QR encodes the **membership ID** rather than a URL, because desktop URLs are local
    (`127.0.0.1:<dynamic port>`) and useless on a phone. USB and phone scanners type the ID into the
    app's search.
  - QR rendering uses mPDF's `<barcode type="QR">`. The plan verifies whether this needs the
    `mpdf/qrcode` package and checks it on the bundled `php.exe`.
- **Category board table** (`players.board-table`):
  - One category, the current or a chosen season.
  - Rows: name, membership ID, file number, drawer, sorted by name.
  - Printed from the players list when a category filter is active. The same rows are exportable in
    P4.

## #14 Official Wilaya names (58)

**Findings.**
- `players.state` is a free string holding the Latin name.
- `database/seeders/algeria_wilayas.json` has typos (`Se9tif`, `Saefda`, `Ghardaefa`, `Tbessa`),
  inconsistent accents, no French names, and swapped longitude / latitude.
- Legacy players hold `GHARDAIA`, which matches nothing.
- `country_states` exists but the app never reads it: `PlayerController::algeriaGeo()` reads the JSON
  on every request.

**Decision (owner).** The 58 official wilayas.

**Single source.** `country_states` becomes the source. A data migration:
- adds `code` (char 2, `01`–`58`, unique within the country), `name_fr` and `name_ar`;
- upserts the 58 official French and Arabic names, keyed by number. The list is embedded in the
  migration so desktop gets it;
- sets `name` to the official French name. The model uses `HasLocalizedName`, so English falls back to
  the French name.
- `ar_name` stays, deprecated.

**Players.**
- New `players.wilaya_id`: FK to `country_states`, nullable, nullOnDelete.
- **Backfill** by normalised match of `state`: lower-cased, accents stripped, spaces and hyphens
  collapsed, plus an alias map covering the typos above and upper-case legacy values.
- Unmatched values stay in `state` (kept for one release, like the `status_value` precedent) and are
  listed on a small admin notice with a "fix" link.
- `state` is no longer written by the form. This also resolves the form sending `null` into a
  `NOT NULL` column (`PlayerForm.vue:139`).

**Everywhere wilayas appear:**
- **Dropdown:** value = id, label = `47 · Ghardaïa` or `47 · غرداية` by language, searchable in both
  languages and by code.
- **Show page and exports:** localized name.
- **Players list:** new wilaya filter.
- **Import:** accepts the code, the French name, the Arabic name or known legacy spellings.

**City stays free text**, with commune suggestions keyed by wilaya code. Rebuilding the commune list
is out of scope.

## #10 Multiple positions

**Decision (owner).** One main position plus other positions.

**Schema.**
- `players.position_id` stays and becomes the **main** position. Stats, member card, list column,
  import and bulk edit keep working unchanged, and existing players need no migration.
- New pivot `player_other_positions` (`player_id`, `position_id`, composite PK, cascade) for the rest.
  Validation forbids listing the main position there too, so there is no double source of truth.

**Behaviour.**
- **Form:** main position select plus an "other positions" multi-select (chips).
- **Filter "plays X":** matches main **or** other (`whereHas`).
- **Stats chart:** main position only, so the total equals the player count.
- **List column:** `MF +2`, with a tooltip listing all positions.
- **Export:** "Main position" and "Other positions" columns.
- **Import:** optional "other positions" column, comma-separated abbreviations or names.

**Clean-up in passing.** `Settings/Positions.vue` sends a `description` field that has no column.
It is removed.

## #15 Jobs — translations, inline creation, no duplicates

**Finding.** Settings → Jobs already exists: `MemberJobController` and `Pages/Settings/Jobs.vue`. It
has a single French `name`, and `destroy` has no in-use guard. Because the FK is nullOnDelete,
deleting a job silently clears it from every player.

**Translations.**
- `member_jobs` gets `name_ar`, `name_fr` and `name_en`, all nullable.
- The model uses `HasLocalizedName`.
- Backfill copies `name` into `name_fr`. `name` stays the canonical fallback.

**Duplicate prevention.**
- `App\Support\NameNormalizer`: lower-case, accents stripped, Arabic normalised (alef variants → ا,
  ة → ه, ى → ي, tatweel and diacritics removed), whitespace collapsed.
- `JobDuplicateFinder` compares a candidate's names against every name column of every job:
  - an **exact normalised match** is refused, with the existing job returned and offered as "use this
    one";
  - a **similar match** (one name contains the other, or a small edit distance) returns a warning the
    user may override.

**Inline creation.**
- In `PlayerForm.vue` the job field gains "+ New job". It opens a small modal (ar / fr / en names, at
  least one required).
- The modal posts to `POST /jobs/quick` (`jobs.quick.store`, derives to add), which returns JSON:
  - `201 {job}` on creation;
  - `409 {duplicate}` on an exact match;
  - `200 {similar: [...]}` for a warning.
- The new or chosen job is appended to the options and selected **without reloading the page**, so
  the half-filled player form is kept.
- The button is hidden for users without the jobs add permission.

**Management page.**
- Translations, a usage count per job, delete refused while any player or user uses it, and
  **merge** (reassign `players.member_job_id` and `users.member_job_id` to the target, then delete the
  source).
- The page keeps its own sidebar entry.

**Player page, export and import** show and accept the localized job name.

## #16 Player details page

Redesign of the info area of `Players/Show.vue`, built with the existing `Icon.vue` (new glyphs drawn
in the same 24px, 1.6-stroke style). Every field keeps its text label; the icon sits beside it.

**Fields with icons:**
- membership ID (with copy button)
- file number and drawer
- date of birth and age
- gender
- positions (main plus others)
- wilaya and city
- phone
- email
- job
- student or worker
- membership status (localized, currently never shown)
- category
- branches
- join year
- blood group

**Newly rendered data** (already loaded by the controller, never shown):
- emergency contacts
- medical conditions
- the P1 Equipment section
- the P3 document checklist

**Actions:**
- print folder label
- member card (an icon replaces the 🪪 emoji)
- edit
- delete

The page stays within the existing card, badge and spacing conventions.

---

# P3 — Player documents

## Data model

**`document_types`**

| column | notes |
|---|---|
| `code` | unique slug |
| `name`, `name_ar`, `name_fr`, `name_en` | `HasLocalizedName` |
| `is_required` | bool |
| `validity` | string: `none` / `season` / `date` |
| `is_active` | bool, default true |
| `sort_order` | uint |

A pivot **`document_type_category`** (`document_type_id`, `category_id`) holds the optional category
limit. No rows means the type applies to all categories.

**`player_documents`**, one row per (player, type), unique:

| column | notes |
|---|---|
| `player_id` | FK, cascade |
| `document_type_id` | FK, restrict |
| `state` | `received` / `exempt` |
| `received_at` | date, nullable |
| `valid_until` | date, nullable |
| `exempt_reason` | nullable |
| `notes` | nullable |
| `recorded_by_user_id` | FK users, nullOnDelete |
| timestamps | |

**`player_document_files`**, several per document:

| column | notes |
|---|---|
| `player_document_id` | FK, cascade |
| `path` | on the private disk |
| `original_name` | |
| `mime` | |
| `size` | |
| `uploaded_by_user_id` | FK users, nullOnDelete |
| `created_at` | |

**Validity rules.**
- `validity = season`: `valid_until` is the end of the season containing `received_at`
  (`App\Support\Season`).
- `validity = date`: the user enters the expiry date, required when marking received.
- `validity = none`: `valid_until` is null.
- **Renewal** updates `received_at` / `valid_until` on the same row. Earlier files stay attached with
  their upload dates as history.

## Checklist semantics

`App\Services\Player\DocumentChecklist::for(Player)` lists every active type (plus inactive types the
player has a document for, greyed). Each type gets exactly one state:

| state | condition | counts as missing |
|---|---|---|
| Received + scanned | `received`, valid, ≥ 1 file | no |
| Received (paper only) | `received`, valid, no file | no |
| Expired | `received`, `valid_until < today` | **yes** (if required) |
| Missing | required, applicable, no row | **yes** |
| Not required | exempt row, **or** optional type not provided, **or** player's category outside the type's category limit | no |

**Per-player exemption.** Any applicable type can be marked "Not required" with a short reason, and
undone.

**Players list.** A missing-documents count column, plus filters for "has missing documents" and
"missing type X". This is computed in SQL (a subquery count), not per row in PHP.

## Files and security

**Uploads.**
- Allowed types: `pdf`, `jpg`, `jpeg`, `png`, `webp`.
- Maximum 10 MB. This matches the existing meeting-attachment rule (`BoardMeetingController.php:59-80`).
- Stored under random filenames; the original name is kept for downloads.
- Images are stored unmodified so they stay legible.

**Storage and access.**
- Files live on the **private** `local` disk (`storage/app/private/player-documents/{player_id}/`).
- They are **never** served from `/media`, which is public.
- Access goes through `players.documents.files.show` (view inline) and `…download`, behind auth and
  the players/view permission.
- Marking, uploading, exempting and removing files need players/edit.

**Backup and restore.** `BackupService` today includes only `storage/app/public`. It is extended to
include `storage/app/private/player-documents`, both directions, with a test.

**Permanent deletion.** Permanently deleting a player removes their document files from disk.

## Document types management

Settings → Document types (the `categories` module, like the other lookup pages):
- add and edit: names, required, validity, category limit, order;
- activate and deactivate;
- delete only while unused;
- renaming a type or changing its rules never breaks existing records, because they reference the id.

**Default types**, seeded **in a migration** (desktop) and editable afterwards:

| type | required | validity | note |
|---|---|---|---|
| Birth certificate | yes | none | |
| Photo | yes | none | |
| Medical certificate | yes | season | |
| Parental authorization | no | season | admin limits it to minor categories and marks it required; the seed cannot know which categories are minors |
| ID card copy | no | date | |
| Residence certificate | no | none | |
| School certificate | no | season | |

## Player page

A "Documents" card with the checklist chips and per-type actions:
- mark received (with date, and expiry for `date` types)
- upload one or more files
- view
- download
- remove a file (confirm)
- renew
- exempt with a reason
- undo exemption

The card header shows "3 missing" at a glance.

---

# P4 — Exports and templates (.xlsx and .csv)

## Constraint

The desktop PHP has no `ext-xmlwriter` / `ext-xmlreader`, so PhpSpreadsheet 500s there. That is why
`ExcelExporter` emits CSV today. `ext-zip` **is** present.

## Decision — server-side pure-PHP XLSX writer

- Build the OOXML parts as strings and zip them with `ZipArchive`.
- Use a small MIT library (e.g. SimpleXLSXGen) **only if** it uses no XMLWriter, XMLReader or DOM and
  passes a smoke test on the bundled `php.exe` (including Composer's runtime platform check).
  Otherwise write an in-house writer of about 200 lines.
- One code path for web and desktop.

Rejected alternatives:
- **Browser-side SheetJS:** two data paths, heavy for large exports.
- **Custom PHP binary with XMLWriter:** build maintenance on every release.

## Design

**Writer.**
- `App\Support\Export::download(string $format, string $filename, array $headers, iterable $rows,
  ?string $title)` dispatches to `CsvWriter` (the current `Csv::download` behaviour) or `XlsxWriter`.
- It replaces `ExcelExporter`, which is CSV-only today.

**XLSX output.**
- Right-to-left sheet view when the locale is `ar`.
- Bold, frozen header row.
- Sensible column widths.
- Numbers written as numbers and dates as dates.
- Title row when a title is given.

**Format menu.** An `ExportMenu.vue` dropdown (Excel .xlsx default / CSV) on **every** export and
import template:
- players
- subscriptions
- transactions
- equipment catalogs and items
- inventory
- board members and tasks
- the four import templates (players, transactions, equipment catalogs, equipment items)
- the P2 category board table

**Localized headers and values** (the export half of #7):
- Headers are translated into the exporting user's language through `UiLang`. Today they are raw
  English keys.
- Statuses, categories, wilayas, jobs and positions use their localized labels.

**Import templates.**
- Headers in the user's language.
- Importers recognise a header in any of ar / fr / en (alias map), falling back to column position, so
  a template downloaded in French imports fine for an Arabic user.

**CSV.**
- **Investigate the reported garbled Arabic before changing anything.** The owner saw strange symbols
  when opening an exported CSV in Excel, although `Csv::download` writes a UTF-8 BOM.
  - Download real files from the web build and the desktop build.
  - Hex-dump the first bytes.
  - Check for output emitted before the BOM and for the open path used in Excel.
  - Fix the actual cause.
- Keep the BOM, write CRLF line endings, and keep the comma delimiter. The owner did not report a
  single-column problem.
- A test asserts that the response starts with `EF BB BF` and that Arabic round-trips.

**Imports.**
- Every import accepts `.xlsx` and `.csv`. The transactions import is CSV-only today; it gets the
  existing SheetJS client-side conversion used by the players and equipment imports.
- CSV files that are not valid UTF-8 (Excel's "CSV (ANSI)" save) are converted from Windows-1256,
  which covers both Arabic and French accented letters.

**Transactions import/export alignment.**
- The export has Cash Register at column 7, where the import expects Description. The columns are
  aligned.
- P1 widened the gap: the export puts **Title** at column 2 while the import reads it at column 8, so
  re-importing an exported file shifts every column. Fix both ends together here.
- The export's title and spacer rows are skipped correctly on re-import.
- A title column is added.

**Tests.**
- The XLSX unzips, and its sheet XML contains the Arabic and French strings intact.
- The CSV starts with a BOM.
- A CP1256 CSV imports correctly.
- Headers are recognised in all three languages.
- The transactions export → import round-trip works.

---

# P5 — User activity tracking

Adopts the July D2 design (`2026-07-20-post-testing-enhancements-design.md`, D2), with the owner's
decisions: **counts now, points later**; **own stats for everyone, all users for users/view**.

## Storage

`activity_logs` is append-only, with no `updated_at`, following the `equipment_histories` precedent:

| column | notes |
|---|---|
| `id` | |
| `user_id` | FK users, nullOnDelete — the actor |
| `action` | string(64) domain event code |
| `subject_type` / `subject_id` | nullable morph |
| `properties` | json, nullable — small context (amount, quantity, count) |
| `occurred_at` | timestamp |

Indexes: (`user_id`, `occurred_at`) and (`action`, `occurred_at`).

**Recording.**
- Events are recorded **explicitly** at the point of the business action, through
  `App\Services\Activity\ActivityRecorder::record($user, $action, $subject, $properties)`.
- Never by a model observer: observers fire on imports, migrations and background fixes, which would
  inflate the counts.
- No sensitive values go into `properties`.

## Events

| area | actions |
|---|---|
| Players | `player_registered`, `player_imported` (with a count), `player_archived` |
| Money | `transaction_recorded` (with amount), `payment_recorded` (subscription or player-level), `transaction_cancelled` (recorded where transactions are archived or cancelled today) |
| Equipment | `stock_received` (with quantity), `equipment_rented`, `equipment_assigned`, `equipment_returned` |
| Board | `meeting_created`, `meeting_cancelled`, `task_created`, `task_completed` |
| Inventory | `stocktake_conducted` |
| Documents | `document_received`, `document_file_uploaded` |
| Jobs | `job_created` |

Page views are not tracked.

**Fix in passing.** `RegisterPlayerService::handle` receives `$recordedByUserId` and never uses it
(its closure at `:19` captures only `$attributes`). It now records the actor.

## Backfill

A data migration (so desktop installs run it on boot) seeds `activity_logs` from existing attribution
columns:
- `transactions.recorded_by_user_id`
- `equipment_histories.user_id` with `event_type`
- `board_meetings.created_by_user_id`
- `board_tasks.created_by_user_id`
- `inventory_sessions.conducted_by_user_id`
- `finance_transfers.created_by_user_id`

It is idempotent: backfilled rows carry `properties.backfilled = true`, and existing
(`action`, `subject`) pairs are skipped. Player registrations before this release have no recorded
author and stay uncounted. The report says so.

## Reports

**My activity.** On each user's profile, a page open to that user only.

**Comparison.** `users.activity.index` (users/view) is a table of every user for a period: this month,
the current season (`App\Support\Season`) or a custom range. Payments show count and amount, ranked on
count.

**Per-user detail.** `users.activity.show` (users/view). Every number drills through to the underlying
records.

**Quality column.** Each count sits next to a **"later cancelled / archived"** count (transactions
recorded then cancelled, players registered then archived), so quantity is read alongside quality.

**Points later.** Rows are keyed by stable action codes. A future `activity_weights` mapping (action →
points) can score history without collecting anything again.

**Retention.** Nothing is pruned. At club scale this is a few thousand rows a year.

---

## Out of scope

- Rebuilding the commune (city) list.
- Wilayas beyond the 58.
- A points or reward system (P5 stores data so it can be added later).
- Page-view and login tracking.
- Translating position names.
- Reinstating a cancelled meeting (create a new meeting instead).
- Transaction cancellation (`2026-09-19-transaction-cancellation-design.md`), approved but not
  implemented. P5 records its event wherever the cancel or archive point is at the time.

## Found in passing — not fixed here

- `players.is_student` defaults to true in the DB and in `RegisterPlayerService:21`, while
  `PlayerForm.vue:43` defaults new players to worker.
- `emergency_contact_relationship` is in the player form state (`PlayerForm.vue:56`), but no input
  renders it.
- The Settings → General `default_language` setting is read nowhere; `SetLocale` ignores it.
