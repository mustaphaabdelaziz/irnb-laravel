# Competitions & Matches — Phase 2: Match day — Design

Date: 2026-10-14
Status: approved design, pending implementation plan
Builds on: `2026-10-14-competitions-matches-design.md` (Phase 1 shipped in 868d378)

## Goal

On match day the coach sets the line-up on a pitch and prints a line-up sheet. After the match they enter the report: score per period, events, minutes and ratings. The app works out minutes played and prints a match report.

## Owner decisions (2026-10-14)

| Topic | Decision |
|---|---|
| Rules history | Each match keeps its **own copy of the game rules**, made at creation, in one JSON column `games.rules`. Editing a format later only affects future matches. Existing matches are filled in by a migration. |
| Line-up shapes | Preset formations only (no formation editor screen). On a match, a dot can be **dragged freely** to another place on the pitch, for that match only. |
| Placing players | **Drag from the bench list onto the pitch.** Dragging onto an occupied spot swaps the players. Dragging back to the list puts the player on the bench. |
| Report entry | **Timeline** of events plus a **per-player grid** for minutes, rating, note and man of the match. |
| Event minute | **Required** for substitutions and for sanctions that send the player off. Optional for goals and other sanctions. |
| Opponent | **Score only.** An opponent's own goal is entered as our goal with subtype `opponent_own_goal` and no scorer. |
| Ratings / notes | Rating 1–10 and a note per player. Visible to staff with Matches view. **Never shown on any PDF.** Man of the match is shown. |
| PDFs | A **line-up sheet** (before the match) and a **match report** (after). |
| Rolling-sub sports | Football 11/8/5: minutes worked out from substitutions. Formats with `rolling_subs`: no substitution events; the coach types minutes per player (optional) and ticks "played". Volleyball counts **sets played**. |

## Data model

### `game_formats`, new columns

- `rolling_subs` boolean, default false. A migration sets it to true for the presets Futsal, Handball, Basketball, Basketball 3x3 and Volleyball.
- `max_substitutions` unsigned tinyint, nullable. Over the limit gives a warning only. Preset Football 11 = 5; the others are null.
- `extra_time_minutes` unsigned tinyint, default 0. Presets: Football 11 = 30, Futsal = 10, Handball = 10, Basketball = 5, Basketball 3x3 = 0.
- The Game formats settings page edits all three fields; validation and translations are added for them.

### `games.rules` (JSON, not null after backfill)

This is a snapshot of the format at match creation. Keys:

`players_on_field, max_bench, max_called_up, max_substitutions, periods_count, period_minutes, period_label, scoring, sets_to_win, has_extra_time, extra_time_minutes, has_shootout, rolling_subs, sanction_types, sport`

- `GameFormat::rulesSnapshot(): array` builds it.
- `GameController::store` saves it on the new match.
- The migration fills it in for existing games from their `game_format_id`.
- `Game::rule(string $key, mixed $default = null)` reads it.
- From Phase 2 on, everything reads the match's rules and never the template. This covers the call-up maximum (Phase 1 code switches over), line-up limits, minutes played, the report and the PDFs.
- `game_format_id` stays on the match, as a reference to the format it came from.

### `game_players`, new columns

- `pos_x`, `pos_y`: unsigned tinyint, nullable, 0–100. Where the dot was dragged on this match. When null, the formation slot's coordinates are used.
- `sets_played`: unsigned tinyint, nullable. Volleyball only.
- `played`: boolean, default false. Set from minutes (non-rolling formats) or ticked by the coach (rolling formats). It counts as an appearance.
- Existing columns used: `role` (starter / bench / none), `slot`, `position_id`, `shirt_number`, `is_captain`, `is_goalkeeper`, `minutes_played`, `rating`, `coach_note`, `is_mvp`.

### `game_events` (new)

- `id`
- `game_id`: FK, deleted with the game.
- `type`: one of `goal`, `sanction`, `substitution`.
- `subtype`, by type:
  - goal: `normal`, `penalty`, `free_kick` or `opponent_own_goal`;
  - sanction: a key from `games.rules.sanction_types`;
  - substitution: null.
- `period`: unsigned tinyint, nullable.
- `minute`: unsigned smallint, nullable. Required for substitutions and for sanctions whose type has `ends_play`.
- `player_id`: nullable FK to players, cascade on delete. The scorer, the player sanctioned, or the player coming on. Null only for `opponent_own_goal`.
- `related_player_id`: nullable FK to players, null on delete. The assist (goal) or the player going off (substitution).
- timestamps
- Index on (`game_id`, `minute`).

## Screens

The match page tabs become **Match · Call-up · Line-up · Report**.

### Line-up tab

- Needs at least one called-up player. Otherwise it shows "Do the call-up first" with a link to the Call-up tab.
- **Pitch** (inline SVG; no remote assets): a football pitch for `sport` football or futsal, and a generic court for the others.
  - A formation picker lists the formations of the match's `game_format_id`.
  - Spots are circles labelled with the slot label.
  - Dots show the player's shirt number and short name, with C and GK markers.
- **Bench list:** called-up players not on the pitch, each with shirt number, usual position abbreviation and the injured badge (PlayerFlags).
- **Drag-and-drop**, using the HTML5 drag API. No new npm dependency unless one is already in the project.
  - List to spot: the player becomes a starter in that slot.
  - Onto an occupied spot: the two players swap; a player coming from the list sends the displaced one to the bench.
  - Spot to list: the player goes back to the bench.
  - A dot dragged to an empty area of the pitch: `pos_x` / `pos_y` are updated.
- **Per starter:** captain toggle, goalkeeper toggle (only for sports with a goalkeeper: football, futsal, handball), and a shirt number override.
- **Counters:** starters x / `players_on_field`, and bench y / `max_bench`. Out of range shows a warning style, never a block.
- **Auto-fill:** fills empty spots with called-up players whose usual position abbreviation matches the slot label, then with the rest.
- **Save** and **Line-up sheet (PDF)**.

### Report tab

1. **Score.**
   - One row per period from `rules.periods_count`, labelled with `period_label`. Volleyball shows up to 5 set rows, and a set can stay empty.
   - An extra-time row if `has_extra_time`, with an "extra time played" toggle.
   - Shootout inputs if `has_shootout`.
   - The final score is worked out: the sum of goals or points, or sets won for `scoring = sets`. When no period rows are filled, the final score can be typed directly.
2. **Timeline.**
   - "Add event" opens a row: type, subtype, period, minute, player, and the related player (assist / player going off).
   - The `substitution` type is hidden when `rules.rolling_subs` is set.
   - Rows are sorted by minute, with an icon per type.
   - The player pickers list line-up players: starters and bench.
3. **Player grid.**
   - Non-rolling formats list starters and the bench. Minutes are read-only and worked out on save.
   - Rolling formats show a "played" checkbox and a minutes input (optional). Volleyball shows sets played instead of minutes.
   - Every row has a rating (1–10, optional) and a note (optional).
   - One man-of-the-match radio across all rows.
4. **Match notes.** This is the `games.notes` field, the same one as on the Match tab.

Saving the report:
- sets `status = played`;
- stores the scores, events and per-player fields;
- works out minutes and `played`;
- enables **Match report (PDF)**.

### PDFs

Both use mPDF with local fonts, Arabic right to left, and `UiLang` labels.

- **Line-up sheet:** the club header, then the match header (teams, competition, date, kick-off, venue). Then a pitch drawing with dots (number and name), a starters table (number, name, position, C / GK), the bench list, and the coaches.
- **Match report:** the club header, the match header, and the final score with periods, extra time and shootout. Then the scorers with assist, subtype and minute; the sanctions with minute; the substitutions (in / out, minute); a players table (number, name, starter / substitute, minutes or sets, goals, cards); and man of the match.
  - **No ratings and no coach notes**, enforced by not passing them to the view.

## Rules and calculations

- **Match length:** `periods_count × period_minutes`, plus `extra_time_minutes` when `extra_time_played`. It is read from `games.rules`.
- **Minutes played** (`MinutesCalculator`, non-rolling formats only):
  - A starter plays from 0 to the minute they are subbed off, sent off, or the end.
  - A substitute plays from coming on to going off, being sent off, or the end.
  - A bench player who never comes on gets 0 and `played = false`.
  - `played = minutes > 0`, or a starter.
  - It is worked out again on every report save, and again on line-up save when a report exists.
- **Report validation** (`ReportValidator`). These block the save:
  - Substitutions:
    - the player coming on is a bench player who isn't on the pitch at that minute;
    - the player going off is on the pitch at that minute;
    - a sent-off player can't come back on.
  - Minutes:
    - every minute is within 0 and the match length;
    - substitutions and send-offs need a minute.
  - Players:
    - event players are line-up players (starters or bench), except `opponent_own_goal`;
    - an assist can't be the scorer.
  - Grid values:
    - at most one man of the match;
    - ratings are 1–10;
    - typed minutes are 0 to the match length;
    - sets played are 0 to `periods_count`.
- **Report warnings** (returned to the UI, never blocking):
  - our goal events don't add up to our score;
  - more substitutions than `max_substitutions`;
  - a starter with no position;
  - the shootout is filled in although the match wasn't a draw.
- **Line-up save.** These block the save:
  - starters must be called up;
  - no more starters than `players_on_field`;
  - shirt numbers are unique within the match;
  - at most one captain;
  - at most one goalkeeper, in sports that have one.

  These only warn:
  - fewer starters than `players_on_field`;
  - more bench players than `max_bench`.

  A starter's `position_id` comes from the slot label: the position with that abbreviation in the team's branch, if one exists.
- **Call-up interplay:** un-calling a player already clears their line-up role (Phase 1). It now also deletes their events in that game, with a warning in the response when there were any.

## Permissions

- The line-up save, report save and their routes are **games / edit**. The coach scope applies: `TeamAccess::authorize` against the game's season.
- Both PDF routes are **games / view**. Their names need view overrides in `config/permissions.php` (e.g. `games.lineup-sheet`, `games.report-sheet`).
- Activity: new codes `LINEUP_SAVED` and `REPORT_SAVED`, recorded through `config/activity.php` in the `competitions` area, with ar/en/fr labels.

## Testing

- **Unit:**
  - `MinutesCalculator`: starters only, a substitution, a double substitution, a send-off, extra time, a sub who never came on.
  - Score derivation for goals, points and sets.
  - `ReportValidator`: substitution timing and the minute range.
- **Feature:**
  - the rules backfill migration, and new games getting a snapshot;
  - a format edit doesn't change an existing game's rules;
  - line-up save, swap, limits and the captain / goalkeeper rules;
  - report save sets played and fills minutes;
  - a rolling format takes typed minutes;
  - volleyball sets;
  - un-calling a player deletes their events;
  - both PDFs: content type plus key text present, and the rating / note text absent;
  - coach scope on both saves;
  - the permission map;
  - activity codes.

## Out of scope (Phase 3 or later)

- suspensions;
- statistics pages (player Matches tab, team / competition stats);
- the dashboard widget;
- licence, documents and unpaid warnings;
- live sideline entry;
- opponent events;
- several line-ups per match;
- a formation editor screen.
