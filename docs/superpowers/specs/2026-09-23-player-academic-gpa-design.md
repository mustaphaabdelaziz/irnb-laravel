# Player Academic Tracking — Design (v2: school years, trimesters, certificates)

Date: 2026-09-23 (v2 2026-09-24)
Status: Approved

## Goal

Track study progress of student players. Every player with `is_student = true`
gets an education section on their profile. Each **school year** is its own
record holding that year's school info (level, institution, class) and its
trimester grades, so the history of schools and classes is kept and each year
is averaged separately. Each trimester may carry a certificate (Excellence,
Congratulations, Encouragement, Honor Roll). Also: a printable academic report,
players-list filters and dashboard figures.

## Decisions

| Topic | Decision |
|---|---|
| School info | Per school year (not on the player): level (required), institution, class/field |
| Periods | Trimesters T1, T2, T3 only |
| Grade scale | From the year's level: `primary` → /10 (pass 5), every other level → /20 (pass 10) |
| Year average | Mean of the trimesters entered; "provisional" until all three exist |
| Add GPA flow | One window: academic year → (if the year is new: level, institution, class) → trimester → grade → certificate |
| Certificate | One per trimester or none: `excellence`, `congratulations`, `encouragement`, `honor_roll`. Auto-suggested from the grade, editable |
| Thresholds | Editable in Settings; defaults /20: 16, 15, 14, 12 — /10: 8, 7.5, 7, 6 |
| Profile display | One line per school year: year · level · institution · class · T1 · T2 · T3 (grade + certificate badge) · year average |
| Chart | One point per year: year average as % of the scale |
| Stats | Latest trimester, current year average, change vs previous year's average |
| At risk | Latest trimester below half its scale |
| Club average (dashboard) | Mean of each student's latest trimester converted to /20 |
| Current school year | `App\Support\Season::current()->startYear` (club setting `seasonStartMonth`, default September) |
| Certificates elsewhere | Profile badge, PDF, dashboard counts (current school year), players-list filter (current school year) |
| Workers | Section hidden when `is_student = false`; data kept |
| Permissions | Reuse `players` module; `players.academic-report` → view |

## Data model

Never deployed before v2, so the original migration
`2026_09_23_110000_add_academic_tracking.php` is rewritten in place.

### `player_academic_years`
| Column | Type |
|---|---|
| id | PK |
| player_id | FK → players, cascade |
| academic_year | unsigned smallint — start year (2025 = "2025/2026"), 1990–2100 |
| education_level | string(20): `primary`, `middle`, `secondary`, `vocational`, `licence`, `master`, `doctorate` |
| institution | string(255) nullable |
| field_of_study | string(255) nullable — class or speciality |
| timestamps | |

Unique `(player_id, academic_year)`.

### `player_academic_records` (one trimester)
| Column | Type |
|---|---|
| id | PK |
| player_academic_year_id | FK → player_academic_years, cascade |
| period | string(10): `T1`, `T2`, `T3` |
| gpa | decimal(4,2), 0 – scale of the year |
| certificate | string(20) nullable: `excellence`, `congratulations`, `encouragement`, `honor_roll` |
| remark | text nullable |
| timestamps | |

Unique `(player_academic_year_id, period)`.

`players` gets **no** school columns (removed from v1).

### Thresholds
Stored in `website_configs.settings.academicCertificates`:
`{"20": {"excellence":16,"congratulations":15,"encouragement":14,"honor_roll":12}, "10": {"excellence":8,"congratulations":7.5,"encouragement":7,"honor_roll":6}}`.
Missing keys fall back to these defaults. Suggestion = highest certificate whose threshold ≤ grade.

## Rules
- Store a grade: player must be a student; grade ≤ the year's scale; one grade per trimester per year; a new year requires `education_level`.
- Changing a year's level is refused if any of its grades exceeds the new scale.
- Deleting a year deletes its trimesters.
- "Latest trimester" = highest `academic_year`, then highest period rank, then highest id.

## Out of scope
Per-subject grades, weighted trimesters, notifications, bulk import.

## Changelog
- 2026-09-23: periods reduced to trimesters T1–T3 (semesters and yearly average removed) at user request.
- 2026-09-24: v2 — school info moved from the player to a per-year record; /10 scale for primary; year average; certificates with editable thresholds.
