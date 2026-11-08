# Literary report — model layout (2025 report) — design

Date: 2026-11-08. Status: approved by owner in brainstorming, awaiting spec review.

Builds on `2026-11-01-social-activities-literary-report-design.md` (activities module + literary report, shipped).

## Why

The owner shared the club's real 2025 literary report (`التقرير الأدبي 2025.pdf`, Word-made, 34 pages). Its shape:

- Header: club name, «التقرير الأدبي لسنة 2025 م».
- Four chapters, each a numbered table with columns **No / nature of activity / place / date**:
  تنظيمات النادي (14 rows), المشاركات الرياضية (10), الحملات التطوعية (7), تكوينات (2).
- Sports participation rows carry the organizer and a result («والتتويج بطلا للدورة», «وصيف لبطل الدورة»).
- Dates in words: «28 مارس», «من 09 إلى 10 أكتوبر», «موسم 2025-2026», «17 من كل شهر».
- A closing note: «ملاحظة يمكن التأكد من صحة معلومات الانشطة عبر الصفحة الرسمية للنادي التالية: …».
- Two signature blocks: «إمضاء وختم واسم رئيس الجمعية», «إمضاء وختم واسم الامين العام», with names.
- A photo appendix: one photo + caption per activity, grouped by chapter behind divider pages 01–05 (the fifth, إعلانات النادي, holds registration posters).

The app's literary report prints the activities as a summary-by-type table followed by one long block per activity with up to 4 inline photos, ISO dates, no signatures, no note. This change makes the activities part match the model while keeping the app's other sections.

## Owner decisions (2026-11-08)

| Topic | Decision |
|---|---|
| Chapters | Activity type = chapter. Each type with activities in the period prints as its own table, in the type list's sort order |
| Sports participations | Recorded as activities (chapter المشاركات الرياضية) with new optional `organizer` and `result` fields; competitions unchanged |
| Dates | Day + month name in the UI language (Algerian month names in Arabic), ranges in words, no year; optional free-text `date_label` prints instead |
| Photos | Appendix at the end, grouped by chapter, one photo per activity; "for the report" limit drops from 4 to 1 |
| Activity types at deploy | Add the 5 model chapters at the top if missing; old types untouched |
| Signatures | Two optional slots per report, each a board member pick |
| Official page note | Free-text `note` on the report, printed after the chapter tables |
| Inventory list | Out of scope (stays an activity row; inventory printed from Equipment) |
| Row text | Title + a small details line built from organizer, result, categories, partners |
| Report text + counts | Behind a per-report "include details" option, off by default |
| Section defaults | All sections on for new reports, as today |

## Data

One migration, `2026_11_08_100001_literary_report_model_layout.php`.

`activities` — add:
- `organizer` string(150) nullable — «من تنظيم …»
- `result` string(150) nullable — free text, e.g. «بطل الدورة», «وصيف»
- `date_label` string(100) nullable — replaces the computed date in the PDF, e.g. «17 من كل شهر», «موسم 2025-2026»

`starts_on` stays required: it decides which report period the activity falls in.

`activity_types` — insert, when no type of the same name exists, in this order and with `sort_order` below every existing type (existing types shift down by 5, keeping their relative order):
تنظيمات النادي، المشاركات الرياضية، الحملات التطوعية، التكوينات، إعلانات النادي.
Existing types and activities are not changed. `down()` deletes the inserted types only when no activity uses them and restores the old sort orders.

`literary_reports` — add:
- `note` text nullable
- `include_details` boolean default false
- `signer_1_board_member_id`, `signer_2_board_member_id` — nullable FK → `board_members`, null on delete

Existing reports: their saved `sections` array gains `signatures` and `photos` (migration appends both keys), so they print the new parts without a manual edit.

`activity_photos` — no schema change. `ActivityPhoto::REPORT_LIMIT` becomes 1. Migration data fix: per activity, keep `in_report` on the first flagged photo by (`sort_order`, `id`) and clear the rest.

## Behaviour

### Report photo (single choice)

`ActivityPhotoController@update` with `in_report = true` sets that photo and clears `in_report` on the activity's other photos, inside one transaction. No more "limit reached" validation error. Unticking leaves the activity with none.

### Human dates — `App\Support\HumanDate`

`HumanDate::range(CarbonInterface $start, ?CarbonInterface $end, string $locale): string`

| Case | ar | fr | en |
|---|---|---|---|
| one day (end null or equal) | 28 مارس | 28 mars | 28 March |
| same month | من 09 إلى 10 أكتوبر | du 09 au 10 octobre | 9–10 October |
| different months | من 28 مارس إلى 04 أفريل | du 28 mars au 04 avril | 28 March – 4 April |
| different years | من 28 ديسمبر 2025 إلى 04 جانفي 2026 | adds years | adds years |

Arabic month names (Algeria): جانفي، فيفري، مارس، أفريل، ماي، جوان، جويلية، أوت، سبتمبر، أكتوبر، نوفمبر، ديسمبر. Arabic and French days are two-digit (model style). The year is shown only when the range crosses a year. In the PDF, an activity's non-blank `date_label` prints instead of `HumanDate::range`.

### Sections

`LiteraryReport::SECTIONS` becomes, in print order:

`cover, intro, members, board, attendance, sports, activities, finance, conclusion, signatures, photos`

`LiteraryReportBuilder::MODULES` adds `photos => activities`; `signatures` needs no right.

**activities** (`ActivitiesSection`, rewritten; same right `activities.view`). Activities with `starts_on` in the period, grouped by type, groups ordered by type `sort_order` then name, rows by `starts_on` then id. Data per chapter: type name, rows. Per row:
- `number` — 1-based within the chapter, printed two-digit (01, 02 …) as in the model
- `title`
- `details` — non-blank parts joined with « · »: «من تنظيم {organizer}», `result`, category names (comma-joined, UI-language separator), «بالتنسيق مع {partners}» (labels via i18n keys)
- `place` — `location`
- `date` — `date_label` or `HumanDate::range`

Report-level: `note` printed after the last chapter table (nl2br, escaped). When `include_details` is on: before the chapters, the summary table by chapter (count, beneficiaries, participants, total row — the current by-type table, relabelled); after each chapter table, the `report` text of each of its activities that has one, under its title. No photos in this section any more.

Empty period: one «no data for this period» line (unchanged rule); the note still prints when filled.

**signatures** (new `SignaturesSection`). For each filled signer slot whose board member still exists: label from i18n key `literary.pdf.signature` with a `{role}` placeholder — ar «إمضاء وختم واسم {role}» (role label from `BoardMember::roleModel`, falling back to the raw role name), the member's live name (board member identity follows the linked player — see board-member-from-player), and an empty box for stamp/signature. Two slots print side by side (slot 1 on the start side). No slot filled → section skipped (like empty editorial sections).

**photos** (new `PhotosSection`). Same activities and grouping as the chapters. Per activity, the photo is: the `in_report` photo, else the first photo by (`sort_order`, `id`), else none (activity skipped in the appendix). Caption = photo caption, else activity title. Each chapter with at least one photo starts on a new page with a divider (chapter number two-digit + chapter name, centred, large), then photos one per row, scaled to the content width with max height ~ 110 mm, caption under each. Images read from the public disk via `Media::localFile` (offline rule). No photos at all → section skipped.

Print order inside the PDF follows `SECTIONS`; each section still starts on a new page.

## UI

- **Activity form** (create/edit): three new inputs — organizer, result, date label (hint: «يُطبع بدل التاريخ، مثال: 17 من كل شهر»). Validation: strings, max lengths above.
- **Activity show**: photo toggle relabelled "report photo"; behaves as single choice (ticking one moves the mark). `reportLimit` prop removed.
- **Activity show / list**: date shown with `date_label` when set (otherwise unchanged).
- **Literary report edit**: `note` text area; "include details" checkbox; two signer selects (board members, current term first, then others; empty option); toggles for the new `signatures` and `photos` sections (photos disabled with the usual hint without `activities.view`).
- i18n: new keys in ar/fr/en flat catalogs (`activities.*`, `literary.*`), insert-only; `npm run i18n:check` clean. No key segment after a message may be a String method name.

## Tests (Pest)

- `HumanDate::range`: one day, same month, cross month, cross year, in ar and fr; en smoke.
- `ActivitiesSection`: chapters ordered by type sort order; rows by date; numbering; details line parts and omissions; `date_label` override; note passed through; details only with `include_details`.
- `PhotosSection`: flagged photo wins; fallback to first photo; activity without photos skipped; caption fallback; empty → skipped.
- `SignaturesSection`: names + role labels; empty slot skipped; deleted member → slot skipped; none → section skipped.
- Report photo toggle: ticking a second photo clears the first; unticking leaves none.
- Migration: adds the 5 types once at the top (re-run safe against existing names), keeps old types and activities; trims multiple flagged photos to one; appends `signatures`/`photos` to existing reports.
- Request validation for the new activity and report fields; signer must exist in `board_members`.
- Permission: `photos` omitted without `activities.view`; `signatures` printed without extra rights.
- PDF route returns `application/pdf` with all sections on (smoke), Arabic.

## Deploy

`php artisan migrate`, then desktop build. Production is PostgreSQL: migration uses the schema builder and plain query-builder updates only (no SQLite-only SQL).

## Out of scope

Inventory appendix, competition final rankings, cover redesign, Word export, per-report choice of inline vs appendix photos, more than one photo per activity in the report.
