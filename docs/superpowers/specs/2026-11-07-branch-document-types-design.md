# Branch document types — design

Date: 2026-11-07 · Status: approved by owner

## Need

Every branch asks for its own documents. A player registered in several branches
sees one documents tab per branch (already built in the branch-registrations
release, 50b996c), and each tab must list only the documents that branch requires.

What exists today:

- `player_documents.branch_registration_id` ties each record to one registration
  (`branch_player.id`), unique on `(branch_registration_id, document_type_id)`.
- `DocumentChecklist::forPlayer()` returns one checklist tab per registration; the
  player page shows them through `BranchSwitch`.
- Document types are one global list: every branch owes all active types (only
  `max_age` narrows them). **This is the gap.**

## Owner decisions

| # | Question | Decision |
|---|----------|----------|
| 1 | What "own documents" means | One shared list of types; each branch ticks the types it requires. A type keeps one definition (name, translations, validity, max age, copies, required, required for matches). |
| 2 | Where branches are ticked | On the Document Types settings page: a "Branches" multi-select per type. |
| 3 | Empty selection | Explicit only: a type applies to the ticked branches, nothing else. Migration ticks every branch on every existing type. A new branch starts with no documents; a new type starts with all branches preselected in the form. |
| 4 | Unticking a branch that has records | Records and files stay. They stop counting everywhere and appear greyed in a "No longer required in this branch" section on that branch's tab, files still downloadable. Re-ticking brings them back. The form warns with the record count before saving. |
| 5 | Copies received | When receiving (or renewing) a document whose type asks for more than 1 copy, the user enters how many copies came in. Fewer than asked = incomplete: shown "2 / 3 copies" in amber and still counted as missing (counts, stats, match eligibility) until the count is raised to the full number. |

Out of scope: per-branch overrides of type settings; a Documents tab on the branch page.

A player with no registration at all (rare, legacy) has no branch: they keep
owing every active type, as today.

## Data

New table `branch_document_type`:

- `branch_id` FK → `branches`, cascade on delete
- `document_type_id` FK → `document_types`, cascade on delete
- unique `(branch_id, document_type_id)`; no timestamps

Migration backfills the cross product of all existing branches × all existing
document types (active or not), so behaviour is unchanged on day one. Must run on
SQLite, MySQL/MariaDB and PostgreSQL (production is PostgreSQL).

Relations: `DocumentType::branches()` and `Branch::documentTypes()` (belongsToMany).

## Rules engine — `app/Services/Player/DocumentChecklist.php`

Single point of truth; all consumers inherit.

- `for(registration)`: the owed types are the active types ticked for
  `registration.branch_id` that pass the age rule. A left registration still owes
  nothing.
- New `retired` list on each checklist tab: records of this registration whose type
  is not ticked for the branch. Records of inactive types keep their current
  handling. Each entry carries the record, its files, and the type name.
  Retired records never count toward missing / expiring / completion.
- Every SQL helper (`missingCountSql`, `missingTypeSql`, `missingRowsSql`,
  `expiringSoonSql`, `requiredTypeSql`, `expiringTypeSql`) adds
  `EXISTS (SELECT 1 FROM branch_document_type bdt WHERE bdt.branch_id = <registration branch> AND bdt.document_type_id = dt.id)`.
  PHP checklist and SQL counts must agree (tested).

## Copies received

Data: `player_documents.copies_received` (unsigned small int, nullable). The
migration sets it to the type's `copies` on every existing `received` row, so no
old receipt turns incomplete on day one. Exempt rows keep null.

Rule: a received record is **incomplete** when `copies_received < type.copies`.

- PHP `evaluate()`, order of checks: exempt → expired → **incomplete** → expires
  soon → received. New state `DocumentChecklist::INCOMPLETE = 'incomplete'`;
  `counts_missing` = expected (same as expired). The item carries
  `copies_received`.
- SQL: in `missingRowsSql` a received record settles the type only when
  `pd.copies_received >= ?` (bound to `$type->copies`; null treated as complete for
  safety: `pd.copies_received IS NULL OR pd.copies_received >= ?`).
  `expiringTypeSql` and `expiringSoonSql` count only complete records (an
  incomplete one is missing, not expiring); `expiringSoonSql` compares with
  `dt.copies`.
- Raising a type's `copies` later makes earlier receipts with fewer copies
  incomplete. The settings form says so under the copies field.

Write paths (`PlayerDocumentController` store + update/renew,
`PlayerDocumentService::markReceived` / `renew`): accept `copies_received`,
validated integer 1…`type.copies`; when left out it is stored as `type.copies`
(the form always sends it, prefilled with the full number).

UI (`PlayerDocumentsCard.vue`): the receive and renew forms show a "Copies
received" number field only when `type.copies > 1`, prefilled with the full
number. An incomplete item shows an amber "2 / 3 copies" badge and a "Renew"-style
action to correct the count (reuses the update route). Exempt flow unchanged.

## Consumers to audit

Each must respect the branch tick, through the helpers or explicitly:

- `app/Http/Controllers/PlayerController.php:69-74` (missing count), `:194`
  (`documentTypes` filter options), `:906-1072` (documents filter)
- `app/Services/Player/DocumentStats.php:33,56,98` (summary, breakdown, type list)
- `app/Services/Dashboard/MemberStats.php:84` (dashboard by-type / by-category)
- `app/Http/Controllers/TeamController.php:142` (roster stats)
- `app/Services/Competitions/PlayerFlags.php:74` (match eligibility)
- `app/Reports/Players/PlayerDocuments.php:56,120` (report lines per type per
  registration; an incomplete record appears as its own state "Incomplete (2 / 3)")
- `app/Console/Commands/VerifyRegistrations.php` (integrity check — add: records
  whose type is not ticked for their branch are reported as info, not errors)

## Document Types settings

`DocumentTypeController` + `resources/js/Pages/Settings/DocumentTypes.vue`:

- Form: "Branches" multi-select (all branches, localized names). Create preselects
  every branch. Validation: `branch_ids` array, each `exists:branches,id`; empty is
  allowed (the type then applies nowhere).
- Store / update sync the pivot inside the same transaction as the type save.
- List: a column of branch chips per type; a type with no branch shows a grey
  "No branch" chip.
- Banner above the list naming every branch that has no ticked type ("Swimming
  requires no documents").
- Index sends, per type, the record count per branch (`player_documents` joined to
  `branch_player`). When the user unticks a branch whose count > 0, a confirm dialog
  before saving: "12 records exist in Swimming for this type. They'll stop counting
  but stay viewable."

## Player page

- Tabs unchanged. Each tab's checklist lists only that branch's types.
- `PlayerDocumentsCard.vue`: collapsed, greyed section "No longer required in this
  branch" listing `retired` records (type, state, dates, files with download). No
  receive / exempt / renew / delete actions.
- `PlayerDocumentController`: store, exempt and renew refuse (validation error on
  `document_type_id`) a type not ticked for the target registration's branch.
  File download and record viewing stay allowed for retired records.

## Players list filter

With a branch selected, the "missing document X" options list only that branch's
ticked types. With "all branches", all active types.

## i18n

New keys (ar, fr, en), insert-only, flat dotted keys; run `npm run i18n:check`.
Segments must not collide with String methods (`link`, `search`, …).

## Tests

- Migration backfill: every branch × every type ticked.
- Checklist: type ticked for branch A only → owed in A's tab, absent from B's tab.
- Retired: record exists, type unticked → listed in `retired`, not counted; re-tick →
  counted again.
- SQL vs PHP: missing / expiring counts agree for a type ticked in one branch only,
  per branch and across branches.
- Store refused for an unticked type; file download of a retired record allowed.
- Controller: create with `branch_ids` syncs; update unticks; empty allowed;
  invalid id rejected.
- Consumers: players list missing filter, report lines, match flags ignore an
  unticked type.
- Copies: migration backfills received rows with the type's copies; 2 of 3
  received → `incomplete`, counted missing in PHP and SQL alike; raising to 3 →
  received; store rejects 0 and more than the type asks; a 1-copy type stores 1
  without the field.
