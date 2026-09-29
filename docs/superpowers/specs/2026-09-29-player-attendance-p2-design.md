# Player Attendance — Part 2 Design

Date: 2026-09-29 · Status: approved in brainstorming · Builds on P1 (merged to main at 1f3985e)
P1 spec: `2026-09-29-player-attendance-design.md`. This part replaces that spec's P3 (reports) and pulls
forward the P3 dashboard widget. P2 paper sheets and P4 follow-up stay as planned in the P1 spec.

## Owner decisions

| Topic | Decision |
|---|---|
| Codes | The 6 statuses stay fixed. For each: code (letters, Arabic allowed), name ar/fr/en, colour, points — editable in attendance settings. |
| Title | "Theme" becomes **Title / goal** (e.g. "Running 7.2 km in 40 min"), shown everywhere a session appears. |
| Multi-category | **Pre-season sessions only** can hold several categories. |
| Pre-season counting | A joint pre-season session counts +1 for **every** category in it. |
| Calendar views | Month (existing) + Week with hours + Agenda list + **Season roadmap** (categories as rows across the season, pre-season / training bars, closures, session ticks). |
| Statistics | Player profile card, club-wide stats page, dashboard card, PDF/Excel export. |
| Delivery | Two steps: **2a** = codes, title, multi-category pre-season, views. **2b** = statistics, profile, dashboard, exports. |

## 2a — A. Configurable codes

Settings key `attendance.codes` in `WebsiteConfig.settings`, merged over defaults like points/rules:

```
codes: {
  present:          { code: 'P',  color: '#059669', label: { ar, fr, en } },
  late:             { code: 'R',  color: '#f59e0b', ... },   // typed with minutes: R15
  left_early:       { code: 'D',  color: '#f97316', ... },   // D10
  not_training:     { code: 'B',  color: '#0284c7', ... },
  absent_excused:   { code: 'AE', color: '#64748b', ... },
  absent_unexcused: { code: 'AN', color: '#e11d48', ... },
}
```

- Label left empty = fall back to the built-in translation `att.status.<status>`.
- Validation: code 1–3 characters, letters only (any script, `\p{L}`), unique across statuses
  (case-insensitive), colour `#rrggbb`.
- `AttendanceCode` reads codes from settings: `parse()` matches a code exactly for simple statuses
  and `code + 1–3 digits` (1–600) for late / left_early; comparison is case-insensitive and
  whitespace-free. `format()` writes the configured code. Empty cell = present (unchanged).
- Changing a code never rewrites stored marks (marks store the status, not the code).
- Everywhere a status is shown (session chips, grid legend and cell colours, calendar) uses the
  configured label and colour. The grid legend is generated from the settings.
- Settings screen: a "Codes" card with one row per status (code, colour picker, ar/fr/en label,
  points — the points input moves here from the points card).

## 2a — B. Title / goal

- Migration renames `training_sessions.theme` → `title`, widened to 150 characters.
- Session screen log field and the add-session modal get "Title / goal".
- Shown on: month calendar chips (truncated, full text on hover), week blocks, agenda rows,
  roadmap ticks (hover), and (2b) the player profile session list.

## 2a — C. Multi-category pre-season sessions

- New pivot `training_session_category` (training_session_id, category_id, unique pair), filled
  for **every** session (single-category sessions get one row). `training_sessions.category_id`
  stays as the primary category (keeps the P1 unique slot key and the generator unchanged).
- Add pre-season modal: category multi-select (primary = the calendar's current category,
  pre-selected). Extra sessions stay single-category. The session screen shows all categories and,
  for a pre-season session, lets you edit them while it is still unmarked.
- Expected roster = players of **any** of the session's categories (same archived / left_at rules),
  no duplicates.
- Slot check: for each category in the session, no other session including that category may have
  the same date + start time.
- Calendar for category X lists every session whose pivot includes X. Pre-season progress for X
  counts held pre-season sessions whose pivot includes X.
- Migration backfills the pivot from `category_id` for existing sessions.

## 2a — D. Calendar views

View switcher on the attendance page (kept in the URL, `view=month|week|agenda|roadmap`):

- **Month** — existing grid, one category.
- **Week** — 7 day columns × hour rows (from the earliest to the latest session of the week, at
  least 08:00–20:00); each session a block from start to end, coloured by kind, showing categories
  and title; all categories; overlapping sessions sit side by side. Previous / next week / today.
- **Agenda** — chronological list for the month: date, time, kind, categories, title, state,
  marked count; optional category filter; links to the session.
- **Roadmap** (season roadmap) — the whole season (season start month → end) left to right,
  zoom month / week, horizontal scroll; one row per category plus a top "Closures" row:
  - pre-season bar per category: from its first to its last pre-season session, labelled with
    progress (held / target, e.g. 8/10);
  - training bar per category: from its first to its last regular/extra session in the season;
  - club closures as shaded bands across all rows;
  - one small tick per session on its date, coloured by kind (cancelled = hollow), hover = date,
    time, title, state; click = session;
  - a "today" line; season picker (current / previous / next).
  The roadmap reads existing sessions only; it does not generate sessions for the whole season.
- Week and agenda generate the shown range for every category first (same lazy generator,
  idempotent).

## 2b — E. Statistics

Service `AttendanceStats` (one place for all numbers) over held sessions in a period, filtered by
category and/or player:

- per status: count and % of expected (expected = the player's attendance rows in held sessions);
- total late minutes; missed hours (absent_* and not_training × session duration);
- score = Σ points with discipline rules applied (every N lates count as one unexcused; a late over
  M minutes counts as unexcused); score % = score ÷ (expected × present points);
- pre-season held / target per category (season of the period end);
- per month series for charts.

Screens:
- **Stats page** `attendance.stats`: period picker (month / season / custom, reuse
  `Activity/PeriodFilter` pattern) + category filter; stacked bar per month by status; held vs
  cancelled per month; category table; sortable player table (each status count and %, late
  minutes, missed hours, score %); top 5 and bottom 5 by score %. Export XLSX/CSV and PDF.
- **Player profile** — "Attendance" card on `Players/Show`, loaded on demand (Inertia optional prop
  or a JSON endpoint), gated on `attendance.view`: period picker, doughnut by status, monthly bar,
  score, pre-season progress, session list (date, kind, title, status, minutes, reason, note);
  PDF export.
- **Dashboard** — card in the members tab (gated on `attendance.view`): today's sessions and the
  last 30 days' breakdown by status.
- Charts use the existing `chartTheme.js` / `ChartCard` / `StatDoughnut`; status colours come from
  the configured codes.

## Testing

Pest/PHPUnit feature tests per step: code settings validation and parser with custom/Arabic codes;
rename migration; pivot backfill; multi-category roster, slot check and pre-season counting; view
endpoints return the right sessions; stats maths (counts, %, minutes, hours, rules on score only,
cancelled excluded); profile endpoint permission; exports render. `npm run build` and
`npm run i18n:check` after each task.

## Out of scope

Paper sheets (P1-spec P2), alerts / parent letter / ranking certificate / injury history (P1-spec
P4), multi-category regular or extra sessions, custom statuses.
