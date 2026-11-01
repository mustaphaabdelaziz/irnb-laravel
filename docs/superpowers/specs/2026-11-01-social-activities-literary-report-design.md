# Social activities & literary report — design

Date: 2026-11-01. Status: approved by owner in brainstorming, awaiting spec review.

Owner's need: track the club's volunteer campaigns and social activities (awareness sessions, summer camps, …) with photos and reports, and produce the year-end **literary report** (التقرير الأدبي) of the club from them plus the data the app already holds.

## Owner decisions

| Topic | Decision |
|---|---|
| Report scope | Full literary report, not only the activities section |
| Period | Free period (from / to picked per report) |
| Participants | Linked to existing players and board members (optional) + numeric counts |
| Planning depth | After-the-fact documentation only: no planned/cancelled status, no task list |
| Activity types | Editable App Configuration list with its own right, seeded defaults |
| Activity report | Written text (printed in the literary report) + attached files (archive only, not merged into the PDF) |
| Photos | Full gallery per activity; up to 4 flagged "for the report"; optional caption |
| Report format | PDF + saved editorial texts (president's word, introduction, conclusion/outlook) |
| Extra activity fields | Partners, branch + categories concerned, responsible board member, cost (informational) |
| Report sections | Members, sports results, board, training & attendance, social activities, finance summary |
| "Sports activities" | Means existing games/competitions — covered by the sports results section; the new module is social only |
| Finance section | Short summary (income / expenses / balance + top categories), finance-right only |
| Menu | New sidebar section «النشاط الاجتماعي» with two items: Activities, Literary report |
| Storage approach | Saved report editions, computed live at export (no frozen snapshot) |

Delivery in two phases, each with its own plan, worktree and merge: **P1** activities module, **P2** literary report.

## P1 — Activities module

### Data

`activity_types` — App Configuration list (same shape and controller pattern as `venues` / `opponents`):
- `name`, `sort_order`, timestamps.
- Seeded by migration: حملة تطوعية، جلسة توعوية، مخيم صيفي، نشاط خيري، زيارة، احتفال / مناسبة (Arabic names; the list is user data, not translated).
- Delete refused while activities use the type.

`activities`:
- `title` (required), `activity_type_id` (required, FK restrict)
- `starts_on` (required date), `ends_on` (nullable date, ≥ `starts_on`; null = single day)
- `location` (nullable string), `partners` (nullable text)
- `branch_id` (nullable FK, null on delete)
- `responsible_board_member_id` (nullable FK → `board_members`, null on delete)
- `cost` (nullable decimal 12,2 — informational, never touches finance)
- `beneficiaries_count` (unsigned int, default 0), `external_volunteers_count` (unsigned int, default 0)
- `description` (nullable text), `report` (nullable long text — the outcome text printed in the literary report)
- `created_by_user_id`, timestamps.

Pivots: `activity_category` (activity ↔ categories concerned), `activity_player`, `activity_board_member` (participants). All cascade on activity delete.

Participant count (derived, not stored) = linked players + linked board members + `external_volunteers_count`.

`activity_photos`:
- `activity_id` (cascade), `path` (public disk, 1600px max width), `thumb_path` (400px), `caption` (nullable), `in_report` (bool, default false), `sort_order`.
- Stored through `FileStorageService::storeImage` (intervention/image) as `/media/...` relative URLs (see media URL rule).
- Accepts jpeg/png/webp, max 8 MB each; multi-upload.
- At most 4 `in_report` photos per activity, enforced server-side (validation error on the 5th).

`activity_files`:
- `activity_id` (cascade), `path`, `original_name`, `mime`, `size`.
- Stored on the private disk via `PrivateFileStorage`, downloaded through an authorized route (same pattern as board meeting minutes). PDF / Word / images, max 20 MB.

Deleting an activity deletes its photos and files from disk (model `deleting` hook or the controller, inside the same flow as other modules).

### Pages (Inertia + Vue `<script setup>`)

- **Index** `/activities`: list ordered by `starts_on` desc; filters type (multi), branch, period (from/to), search on title/location/partners; stat tiles: number of activities, total beneficiaries, total participants for the filtered set. Rows show cover thumbnail (first photo), type, dates, location, counts. Paginated like other lists.
- **Create / Edit**: form with all fields; categories multi-select; players picker (SearchableSelect, names via `Player::fullname` / `NAME_COLUMNS`); board members picker; responsible board member select.
- **Show** `/activities/{id}`: details, participants (players + board members), gallery with lightbox, upload photos, edit caption, toggle "for the report" (disabled at 4), reorder optional (sort by upload order is enough — YAGNI on drag reorder), delete photo; attached files list with upload/download/delete.
- Action buttons use `IconButton`; i18n keys in ar/fr/en flat catalogs (`activities.*`), checked with `npm run i18n:check`.

### Access

- New module `activities` in `config/permissions.php` (route prefix `activities` → `activities`), actions view/create/edit/delete via the route-name-derived middleware. Photo and file routes map to `edit` (upload/delete/toggle) and `view` (download).
- New module `activity_types` for the configuration list (route prefix `activity-types`).
- Both start empty for every role (per-list permissions rule); superadmin short-circuits.
- Navigation: new section `social` («النشاط الاجتماعي», new icon if none fits) in `useNavigation.js`; item Activities (`module: 'activities'`). `activity_types` goes under App Configuration.

### Tests (Pest)

- CRUD + validation (`ends_on` ≥ `starts_on`, type required).
- Permission: each route refused without the right, allowed with it.
- Photo upload stores both sizes; 5th `in_report` refused; delete removes files.
- Private file download requires `activities.view`; file absent from public `/media`.
- Activity delete removes photos/files.
- Activity type in use cannot be deleted.

## P2 — Literary report

### Data

`literary_reports`:
- `title`, `period_from` (date), `period_to` (date, ≥ from)
- `sections` (json array of enabled section keys)
- `president_word`, `introduction`, `conclusion` (nullable long text — conclusion includes outlook)
- `created_by_user_id`, timestamps.

### Pages

- **Index** `/literary-reports`: list (title, period, updated), create, delete.
- **Edit**: title, period, section toggles, three text areas, «تصدير PDF» button (opens the PDF route).
- Module `literary_reports` (route prefix `literary-reports`), empty for every role. Nav item in the `social` section.

### PDF

Generated live at export with `PdfService` (mPDF, RTL for ar), header from `ClubHeader::data()`, in the UI language. One builder class per section under `app/Services/LiteraryReport/Sections/`, each returning a plain array for its Blade partial; a `LiteraryReportBuilder` assembles enabled + permitted sections in this fixed order:

| # | Key | Content | Source | Right needed |
|---|---|---|---|---|
| 1 | cover | Logo, club name, title, period | `ClubHeader` | — |
| 2 | intro | President's word, introduction (skipped when empty) | saved texts | — |
| 3 | members | Active players at `period_to` by branch × category × gender; joins (`join_year` within the period's years); leavers (`left_at` in period); coaches (`team_coaches` for seasons overlapping the period) | players, teams | `players.view` |
| 4 | board | Current term members with roles; committees with head/deputies; meetings in period (count + date/title list) and their decisions | board | `board.view` |
| 5 | attendance | Sessions held (not cancelled) per branch; attendance rate per branch / category | `AttendanceStats::categories($from, $to, $branchId)` | `attendance.view` |
| 6 | sports | Competitions with games in period, W/D/L/GF/GA per competition (`CompetitionRecord::fromGames` on games filtered by date); top scorers from game events in the period; player achievements dated in the period | games, competitions | `games.view` |
| 7 | activities | Summary table by type (count, beneficiaries, participants); then one block per activity: title, type, dates, location, partners, counts, report text, up to 4 "in report" photos (1600px copy, scaled in PDF) | P1 | `activities.view` |
| 8 | finance | Income, expenses, balance for the period + top 5 income and expense categories; cash basis, same "counted transaction" predicate as `TransactionController` stats; transfers excluded; subscriptions are debt, not income | transactions | `finance.view` |
| 9 | conclusion | Conclusion & outlook (skipped when empty) | saved text | — |

Notes:
- `MatchStats` works per season; the sports section queries games/events by date range instead (new small query, does not change `MatchStats`).
- A section the exporter has no right for is silently omitted; the edit page shows its toggle disabled with a hint.
- Empty computed sections print a one-line "no data for this period" rather than disappearing, so the report structure stays stable.
- Offline-only: no remote fonts or images; photos read from the public disk path.

### Tests (Pest)

- CRUD + permission on `literary_reports`.
- Each section builder against factory data for a fixed period (counts, joins/leavers, W/D/L, activity summary).
- Section omitted when the exporter lacks its source right.
- Finance section totals reconcile with the transactions screen stats for the same period.
- PDF route returns `application/pdf` with all sections enabled (smoke).

## Deploy

- P1 and P2 each: `php artisan migrate` at deploy (new tables + seeded types), then desktop build.
- No change to existing modules' behavior or permissions.

## Out of scope

Planning/status/tasks for activities, linking activity cost to finance, merging attached files into the PDF, frozen report snapshots, Word export, drag-and-drop photo reorder.
