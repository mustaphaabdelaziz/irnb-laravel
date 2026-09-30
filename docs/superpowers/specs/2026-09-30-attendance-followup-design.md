# Attendance Follow-up — Design

Date: 2026-09-30 · Status: approved in brainstorming · Builds on attendance P1, 2a, 2b and paper sheets
(main at 1c8b3ae). Replaces section "Follow-up (P4)" of `2026-09-29-player-attendance-design.md`.

## Owner decisions

| Topic | Decision |
|---|---|
| Players at risk | A page plus a dashboard card. Default period: current season, with a period picker. |
| Parent letter | A formal letter whose paragraph can be edited in settings, with placeholders. |
| Ranking | Per category, for a month or a season. Certificates for the top 3, or for any chosen player. |
| Injuries | Both: spells built automatically from the marks, plus details you can add to a spell. |

## A. Players at risk

- **Rule** (from `AttendanceSettings` alerts): over the period, a player is at risk when either
  - their score % is below `alerts.min_score_pct` (only players with at least 5 expected sessions, the same minimum as the rankings), or
  - they have `alerts.unexcused_streak` or more consecutive unexcused absences. A streak counts the player's own held sessions in date order. Any other status breaks it. The period's **current** streak and the **longest** streak are both reported.
- Threshold 0 turns that rule off.
- All numbers come from `AttendanceStats`.
- **Page** `attendance.alerts`:
  - Filters: period picker (default: current season) and category filter.
  - Table columns: player (links to the profile when the user can view players), category, score %, unexcused count, current streak, longest streak, last session, and the reason flags (low score / streak).
  - Actions per row: print the parent letter, open the profile.
  - Export: XLSX/CSV.
- **Dashboard card** (members tab, requires attendance.view): the number of players at risk this season, the 5 worst, and a link to the page.
- **Profile**: the Attendance card shows a warning banner when the player is at risk for the selected period.

## B. Parent letter

- **PDF** `attendance.players.letter`: A4 portrait, right-to-left in Arabic. Period is the same as the card or page link, default current season. It contains:
  - the club header (ClubHeader) and the date;
  - the recipient: the first emergency contact's name if there is one, otherwise "Parent / guardian of {player}";
  - the subject;
  - the editable paragraph;
  - a table of absences (excused and unexcused), lates and early departures, with date, kind, status, minutes and reason;
  - totals;
  - signature lines for the coach and the president.
- **Editable text**: settings key `attendance.letter` holds `subject` and `body` per locale (ar/fr/en).
  - Placeholders: `{player}`, `{category}`, `{period}`, `{absences}`, `{lates}`, `{club}`.
  - Empty means the built-in default text is used.
  - Edited in a new card on the attendance settings page, with the list of placeholders.
  - Text is escaped and line breaks are kept.
- **Links** from the risk page, the profile Attendance card and the stats player table.

## C. Ranking and certificates

- **Page** `attendance.ranking`:
  - Filters: category (required, default is the first category), period type month or season, period value.
  - Ranking by score % with the stats-page tie rules, minimum 5 expected sessions.
  - The whole list is shown, with rank, player, expected sessions, present count, score %, and a medal on the top 3.
  - Actions: "Print top 3 certificates" (one PDF, one page each) and "Certificate" per row.
- **Certificate PDF** `attendance.certificates`, A4 landscape:
  - club logo and name, a title ("Certificate of assiduity"), player name, category, period, rank (1st/2nd/3rd, or none for a chosen player outside the top 3), and score %;
  - date, and signature lines for the coach and the president;
  - a decorative border using plain CSS only (offline);
  - Arabic or French.
- A link to the ranking page from the stats page.

## D. Injuries

- **Spells** are built automatically, per player, from their marks in held sessions, in date order:
  - A mark is an injury mark when its status is `not_training`, or its status is `absent_excused` with reason `injury`.
  - Consecutive injury marks (no other held-session mark of the player in between) form one spell: start date, end date, sessions missed, and whether it is still open (the player's latest held mark is an injury mark).
- **Details**: a new table `injury_notes` with
  - `id`, `player_id` (cascade), `start_date` ('Y-m-d', the spell's start), `body_part` (string 60, nullable), `description` (text, nullable), `returned_on` ('Y-m-d', nullable), `created_by`, and timestamps;
  - a unique key on (`player_id`, `start_date`).

  A detail attaches to the spell with the same start date. When a spell's start changes because an earlier mark is edited, the detail stays keyed to its date and is shown as unmatched ("detail without a matching spell"), never lost.
- **Profile**: an Injuries section in the Attendance card, showing the spells timeline (dates, sessions missed, open or closed, body part and description when present). With attendance.edit you can add or edit a spell's details in a small modal.
- **Club list** `attendance.injuries`: players with an open spell (current injuries) plus the spells in the period, with a category filter and links to the profiles.
- **Migration**: create `injury_notes`, guarded with `hasTable`. It adds no column to an existing table.

## Permissions

All pages and PDFs need `attendance.view`; editing injury details and the letter text needs `attendance.edit`. Route names must resolve to these through `config/permissions.php` overrides where the last segment is not a view verb, and the tests must assert them.

## Testing

- Risk: the rules at their boundaries (threshold equal, 0 = off, current vs longest streak, minimum expected).
- Letter: placeholder substitution and escaping, emergency-contact fallback, RTL.
- Ranking: order and ties; the top-3 certificate PDF has 3 pages; a chosen player outside the top 3 gets no rank.
- Spells: joining consecutive marks, open or closed, attaching details and showing unmatched ones, a player with no injuries.
- Every new route: permission checks, and a real render of each PDF.
- `npm run build` and `npm run i18n:check`.

## Out of scope

SMS or email sending, a parent portal, a medical file or attached documents, and making the at-risk list an automatic notification.
