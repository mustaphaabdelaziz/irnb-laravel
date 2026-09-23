# Player Academic Tracking (Semester GPAs) — Design

Date: 2026-09-23
Status: Approved

## Goal

Track study progress of student players. Every player with `is_student = true`
gets an education section on their profile holding school info and semester
GPAs, with a trend chart, a printable academic report, a players-list filter
and a dashboard widget.

## Decisions

| Topic | Decision |
|---|---|
| Grade scale | Fixed /20, pass mark 10 |
| Record content | Academic year + period + GPA + optional remark |
| School info | Stored on player: level, institution, field/class |
| Display | Table + trend chart + stats (latest, average, delta) |
| Workers | Section always hidden when `is_student = false` (records kept in DB) |
| Periods | T1, T2, T3 |
| Permissions | Reuse `players` module (view/add/edit/delete) |
| Print | New A4 "academic report" PDF; member card untouched |
| List filter | `academic` = `at_risk` / `good` / `none`, students only |
| Dashboard | Members tab card: students, club average, at-risk, missing GPA |
| Education level | Fixed list |

## Data model

### `players` — new nullable columns

- `education_level` string(20): one of `primary`, `middle`, `secondary`,
  `vocational`, `licence`, `master`, `doctorate`
- `institution` string(255)
- `field_of_study` string(255) — class or speciality

### New table `player_academic_records`

| Column | Type |
|---|---|
| id | bigint PK |
| player_id | FK → players, cascade on delete |
| academic_year | unsigned smallint — start year (2025 means "2025/2026") |
| period | string(10): `T1`, `T2`, `T3` |
| gpa | decimal(4,2), 0–20 |
| remark | text nullable |
| timestamps | |

Unique index `(player_id, academic_year, period)`.

### Ordering and "latest"

Chronological order: `academic_year` asc, then period rank
`T1=1, T2=2, T3=3`.
**Latest GPA** = the last record in that order. **Average** = mean of all the
player's GPAs. **Delta** = latest minus the previous record's GPA (null when
fewer than two records).

Period rank lives in one place: a `AcademicPeriod` enum (PHP) exposing
`rank()`, plus a SQL `CASE` expression helper used by the filter and dashboard
subqueries.

## Backend

- `App\Enums\AcademicPeriod`, `App\Enums\EducationLevel`.
- `App\Models\PlayerAcademicRecord` (fillable, casts `gpa` decimal:2,
  `academic_year` int); `Player::academicRecords()` hasMany.
- `PlayerAcademicRecordController` — `store`, `update`, `destroy`.
  Routes: `players.academic-records.store|update|destroy`
  (`/players/{player}/academic-records[/{record}]`), permissions derived
  automatically (add/edit/delete). Record must belong to the route player (404
  otherwise).
- Form requests `StoreAcademicRecordRequest` / `UpdateAcademicRecordRequest`:
  `academic_year` integer 1990–2100, `period` in enum, `gpa` numeric 0–20,
  `remark` nullable string max 1000, uniqueness per player+year+period
  (ignoring self on update). Store/update rejected with a validation error when
  the player is not a student.
- Flash keys: `flash.academic_record_added|updated|deleted`.
- `PlayerController@show`: when student, pass `academicRecords` (chronological)
  and the three school fields. `Store/UpdatePlayerRequest` validate the three
  fields (`education_level` in enum).
- Players list filter in the shared filter method (index + export):
  `academic=at_risk` → students whose latest GPA < 10; `good` → latest ≥ 10;
  `none` → students with no record. Implemented with a latest-GPA subquery.
- `MemberStats`: academic block — `students`, `average` (mean of each
  student's latest GPA, null if none), `at_risk`, `missing`.
- `ReportController@academicReport` → `resources/views/pdf/academic-report.blade.php`
  (A4: club header, player identity + photo, school info, semester table with
  pass/fail marking, average, latest). Route `players.academic-report`, mapped
  to `['players', 'view']` in `config/permissions.php`. 404 when not a student.

## Frontend

- `PlayerForm.vue`: education level select, institution, field of study —
  shown only when student.
- `Players/Partials/AcademicSection.vue` rendered in `Show.vue` when
  `player.is_student`:
  - School info rows.
  - Stat row: latest GPA, average, delta badge (green up / red down).
  - Line chart (vue-chartjs, already installed): x = "2025/2026 S1", y 0–20,
    dashed reference line at 10. Hidden when fewer than 2 records.
  - Table: year, period, GPA (green ≥ 10, red < 10), remark, edit/delete
    actions gated by permissions.
  - Add/edit modal; delete with confirm.
  - "Print academic report" button.
- `Players/Index.vue`: "Academic" filter select (All / At risk / Good / No GPA).
- `Dashboard/Partials/MembersTab.vue`: academic card with the four stats;
  at-risk and missing link to the filtered players list.
- Translations in `lang/ar.json` and `lang/fr.json` (flat keys); run
  `npm run i18n:check`.

## Error handling

- Duplicate year+period → validation error on the field.
- GPA outside 0–20 → validation error.
- Non-student player → validation error on store/update; PDF returns 404.
- Switching a player to worker keeps records; they reappear if switched back.

## Testing (PHPUnit feature tests)

- Create, update, delete record; flash messages.
- Uniqueness and GPA range validation; non-student rejection.
- Record of another player → 404.
- Latest-GPA ordering (T3 after T2 within a year, later year wins).
- List filter buckets `at_risk`, `good`, `none`; workers excluded.
- Dashboard academic stats values.
- Academic report route returns 200 for student, 404 for worker.
- Permission: user without `players.add` gets 403 on store.

## Out of scope

Per-subject grades, non-/20 scales, notifications, bulk GPA import.

## Changelog

- 2026-09-23: periods reduced to trimesters T1–T3 (semesters and yearly average removed) at user request.
