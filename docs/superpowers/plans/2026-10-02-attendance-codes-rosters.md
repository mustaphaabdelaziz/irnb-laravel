# Plan — Stream A: attendance codes, rosters, full names (2026-10-02)

Spec: `docs/superpowers/specs/2026-10-02-attendance-and-filters-batch-design.md` (Stream A).
Branch `feat/attendance-codes-rosters`. TDD per task, one commit per task.

## T1 Custom status storage + catalog
- Migration `attendance_custom_statuses` (key unique ≤20 = `c_<id>`, code, color, label_ar/fr/en, behaviour, is_active, sort_order).
- Model `AttendanceCustomStatus` (+ factory).
- `App\Services\Attendance\AttendanceStatusCatalog`: entries (base from AttendanceSettings + custom, ordered),
  `keys()`, `codes()` (superset of the settings `codes` block: + behaviour/custom/active),
  `labels()`, `behaviour()`, `keysBehavingAs()`, `fold()` (counts → six base statuses, not_counted dropped),
  `takesMinutes()/takesReason()/requiresReason()` (base only).
- `Attendance.status` loses the enum cast (plain string). Tests asserting enum instances compare `->value`.
- Unit/feature tests: ordering, behaviour, fold, custom key loads.

## T2 Marks accept custom keys
- `SaveAttendanceMarksRequest`: status in catalog keys (inactive allowed for re-saves).
- `MarkRecorder`: minutes/reason from the catalog (custom = none).
- `AttendanceCode` works on string keys and knows custom codes (grid parser + formatter, month sheet).
- Grid save/show, session page/sheet use strings.

## T3 Stats semantics
- `AttendanceStats::base()`: `counts` per catalog key (each its own column), `scored` = folded base counts,
  `expected` = Σ scored (not_counted excluded), pct null for not_counted keys.
- score/ranking/at-risk/letter use `scored`; MISSED from behaviour; unexcused streaks count custom
  unexcused, skip not_counted; monthly() per catalog key; export columns per catalog key.
- Every display caller (`attendanceCodes` props, PDF labels/codes) reads the catalog.

## T4 Settings CRUD for custom codes
- Routes `attendance.custom-statuses.store|update|destroy` → overrides `['attendance','edit']`.
- Form Requests (code regex `^\p{L}{1,3}$`, unique case-insensitive across base+custom, colour, labels, behaviour).
- Base code update also checks against custom codes. Delete blocked when marks use it (hide instead).
- Settings.vue: custom codes section (add, edit, hide/show, delete).

## T5 Frontend status lists from the catalog
- `useAttendanceCodes`: `statuses` (all, ordered) / `activeStatuses` from `attendanceCodes`; statusOf knows custom.
- Session.vue, Grid.vue, Stats, breakdowns, calendar views, profile card use them.

## T6 Roster status sets
- Settings `roster_status_ids` (null = status with code `registered`); saved in the main settings form (list replaced, not merged).
- Migration: `training_sessions.roster_status_ids` json nullable.
- `Roster::expected()` filters by the session's set (players with no status count as registered when the set holds it).
- `TrainingSessionController@store` + AddSessionModal checkboxes (pre-checked from settings).
- Session page candidates: non-archived, not left before the session date, any status/category; searchable; status label.

## T7 Full names
- `Roster::COLUMNS`, `MonthSheet::PLAYER_COLUMNS`, candidates load nickname/father/grandfather; rows use `Player::fullname`
  (session page, grid, session sheet, month sheet).

## T8 i18n, build, full suite
- Keys via `node scripts/i18n-add.mjs`; `npm run i18n:check`; `npm run build`; full `php artisan test`.

Deploy: `php artisan migrate` (2 additive migrations).
