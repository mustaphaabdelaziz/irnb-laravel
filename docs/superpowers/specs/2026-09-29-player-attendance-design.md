# Player Attendance — Design

Date: 2026-09-29 · Status: approved in brainstorming, pending spec review

## Goal

Track every player at every club training session and every pre-season
fitness session (préparation physique): on time, late, left early, present but
not training, absent with excuse, absent without excuse. Report per period
(any date range, with month and season shortcuts) club-wide and per player on
the profile. Print blank sheets to track on paper and type them in later.

## Owner decisions

| Topic | Decision |
|---|---|
| Group unit | **Category.** One session = one category on one date/time. |
| Pre-season | **Target count per category per season** (e.g. 12). Sessions are created ad hoc on the day, tagged `preseason`. Progress shown as 7/12. |
| Statuses | present, late (+minutes), left_early (+minutes), not_training (came, did not train: injured/sick), absent_excused (+reason +note), absent_unexcused. |
| Excuse reasons | injury, illness, school, family, travel, other (+ free note). |
| Periods | Any from–to range; shortcuts: this month, previous month, current season, previous season. No fixed trimesters. |
| Paper | Both formats: monthly grid per category and single-session sheet. Entry by grid for both. |
| Sessions | Auto-generated from weekly schedule; cancel (with reason), move, extra sessions; club closures skip generation. |
| Who marks | Any user with the new `attendance` RBAC module rights. |
| Coach | Free-text name, prefilled from the category's last session. |
| Counting | **Every status counted separately** (count + % of expected sessions). No merged rate. |
| Ranking/alerts | **Configurable points per status** produce a score used for ranking and alerts only. |
| Discipline rules | Affect the **score only**; displayed counts always reflect raw marks. |
| Phasing | P1 core → P2 paper → P3 reports → P4 follow-up. Spec covers all; each phase planned and shipped separately. |

## Data model (P1)

```
training_schedules   id, category_id FK, weekday tinyint(1=Mon..7=Sun),
                     start_time, end_time, valid_from date, valid_to date null,
                     timestamps
club_closures        id, start_date, end_date, reason string, timestamps
preseason_targets    id, category_id FK, season_start_year smallint,
                     target_count smallint, unique(category_id, season_start_year)
training_sessions    id, category_id FK, date, start_time, end_time,
                     kind enum(regular, preseason, extra),
                     state enum(planned, held, cancelled),
                     cancel_reason string null, moved_from date null,
                     coach string null, theme string null, notes text null,
                     schedule_id FK null (source schedule, for regeneration),
                     timestamps, unique(category_id, date, start_time)
attendances          id, training_session_id FK cascade, player_id FK cascade,
                     status enum(present, late, left_early, not_training,
                                 absent_excused, absent_unexcused),
                     minutes smallint null (late or left-early minutes),
                     reason enum(injury, illness, school, family, travel, other) null,
                     note string null, recorded_by FK users null, timestamps,
                     unique(training_session_id, player_id)
```

PHP enums: `SessionKind`, `SessionState`, `AttendanceStatus`, `AbsenceReason`
(same style as `App\Enums\AcademicPeriod`).

Validation: `minutes` required and > 0 only for late / left_early; `reason`
required only for absent_excused (also allowed for not_training); both nulled
otherwise.

### Settings (existing `WebsiteConfig.settings`, key `attendance`)

```
points:      { present: 1, late: 0.75, left_early: 0.75, not_training: 0.5,
               absent_excused: 0, absent_unexcused: -1 }
rules:       { lates_per_unexcused: 3 (0 = off),
               late_minutes_as_absent: 30 (0 = off) }
alerts:      { min_score_pct: 60, unexcused_streak: 3 }
```

Defaults above; editable in the attendance settings tab.

## Session generation (P1)

- `SessionGenerator::forMonth(Category, year, month)` creates `planned`
  regular sessions for each schedule row valid on each date, skipping dates
  inside a club closure. Idempotent via the unique key (`insertOrIgnore`).
- Triggered lazily when a month is opened on the attendance calendar (desktop
  app is offline with no reliable scheduler) and by an explicit
  "generate month" button. Never generates future months beyond the viewed one.
- Never touches sessions that are `held` or `cancelled`, or that were moved.
- **Cancel**: state → cancelled, reason required. Existing marks are kept but
  excluded from reports.
- **Move**: sets new date/time, `moved_from` = old date. Generation will not
  recreate the old slot (checked against `moved_from`).
- **Extra / preseason**: created manually with any date/time.

## Roster snapshot (fair expected sessions)

When a session is first opened for marking, the roster is computed (every
player shown as `present`) from players who, on the session date:

- has `category_id` = session category,
- is not `archived`,
- has no `left_at`, or `left_at` > session date.

`attendances` rows are persisted on first save (not on open), so a viewed-but-unsaved
session leaves no data. The marker can add a player (e.g. guest from another
category) or remove one from the roster. Reports count only existing rows,
so later category changes, departures and newcomers never rewrite history.

A session counts in reports only if `state = held`. Saving marks sets `held`.
`planned` sessions with no marks are ignored.

## Screens (P1)

- **Sidebar** → Members → *Attendance* (module `attendance`, view).
- **Calendar page** `attendance.index`: category picker, month view; sessions
  coloured by state and kind; buttons: generate month, add extra, add
  preseason, cancel, move. Pre-season progress badge per category.
- **Session page** `attendance.sessions.show`: player list, default Present,
  one-tap status chips; minutes/reason/note fields appear only when relevant;
  session log (coach prefilled, theme, notes); "all present" and save.
- **Month grid entry** `attendance.grid`: players × held/planned dates of the
  month, cells take codes `P`, `R<min>`, `D<min>`, `B`, `AE`, `AN`
  (AE reason picked in a small popover; defaults to `other`). Arrow/Tab/Enter
  navigation. Empty cell on a planned session = not yet marked. Saving a
  column marks that session held.
- **Settings tab** `attendance.settings`: schedules per category, closures,
  pre-season targets per season, points, rules, alert thresholds.

Codes shown in the UI localised (ar/fr/en); the grid accepts the Latin codes
above in every locale, plus the localised letters.

## Permissions & activity (P1)

- Add `attendance` to `Role::MODULES` and `config/permissions.php`
  (`attendance.*` routes). Settings routes map to `edit`.
- Migration grants the module to existing roles that hold `players` rights
  (same actions), so nothing disappears for current users.
- `ActivityAction`: `attendance.session_marked` (count), `attendance.session_cancelled`,
  `attendance.session_moved`, `attendance.session_created`. Recorded at call
  sites via `ActivityRecorder`, no free text in properties.

## Paper sheets (P2)

Via `PdfService` (mPDF, RTL for ar), Blade views in `resources/views/pdf/`.

- **Monthly grid** (A4 landscape): club header, category, month, player rows
  (file number, name) × scheduled session dates (from generated sessions,
  generating the month first), empty cells, code legend, coach name and
  signature boxes. Splits over pages beyond ~25 players.
- **Session sheet** (A4 portrait): one session; columns P / R / D / B / AE /
  AN to tick, minutes, reason, notes; session log lines.
- Printable from the calendar page (month grid) and session page.

## Reports (P3)

Service `AttendanceReport` computes, for a period and optional category/player:
per status count, % of expected (expected = attendance rows in held sessions),
total late minutes, total missed hours (absences × session duration), raw
score and score % (score ÷ max possible points), discipline rules applied to
the score only.

- **Global report** `attendance.report`: period picker with shortcuts;
  category summary table (sessions held / cancelled, preseason n/target,
  status breakdown); player table sortable by any column; PDF + XLSX/CSV
  export via `PdfService` / `Export`.
- **Player profile tab** "Attendance": same period picker, status breakdown,
  score, month heatmap, session list with notes/reasons, pre-season progress,
  PDF export.
- **Charts**: status breakdown per month per category (stacked bars) on the
  report page; dashboard widget: today's sessions + last 30 days breakdown for
  users with `attendance.view`.

## Follow-up (P4)

- **Watch list**: players whose score % < `alerts.min_score_pct` in the current
  season, or with `alerts.unexcused_streak` consecutive unexcused absences.
  Dashboard card + list page.
- **Parent letter** PDF (ar/fr): player, period, absences and lates with dates,
  signature block. From the profile tab and the watch list.
- **Assiduity ranking**: per category per month or season, by score (ties:
  fewer unexcused, then fewer lates). Certificate PDF in the style of the
  academic certificates.
- **Injury history** on the profile: timeline of `not_training` and
  absent_excused(`injury`) marks, grouped into spells of consecutive sessions,
  with sessions missed per spell.

## Testing

Pest feature tests per phase:

- generation idempotent; closures skipped; moved sessions not recreated;
  schedule `valid_from/valid_to` respected; held/cancelled untouched;
- roster snapshot: category change, left player, archived player, newcomer;
- validation of minutes/reason per status; grid code parsing;
- report maths: counts, %, minutes, cancelled excluded, rules affect score only;
- permissions: `attendance` module gates every route; migration grants;
- activity records written; PDFs render (ar + fr) without error;
- i18n keys present in ar/fr/en (`npm run i18n:check`).

## Out of scope

Match convocations, coach accounts restricted to categories, parent-facing
portal/SMS, per-branch schedules, Excel import of marks.
