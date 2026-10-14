# Competitions & Matches — Design

Date: 2026-10-14
Status: approved design, pending implementation plan

## Goal

Let the club manage team competitions and matches for any team sport: build
teams with per-season rosters, register a squad per competition, call players
up for each match, set the lineup on a pitch (e.g. 11 for football), record
what happened, and get player/team statistics.

## Market inspiration (summary)

- Universal in club apps (TeamSnap, Spond, SportEasy, My Coach FFF): match as an
  event with opponent / home-away / score; call-up of players.
- Mid tier (Spond, SportEasy, 360Player): formation picker on a pitch, starters
  + bench; event timeline (goals, assists, cards, subs with minute); minutes
  played computed from subs; season stats.
- Federation systems (COMET, PlayHQ): squad registered per competition,
  card-accumulation suspensions. Rare in club apps — a differentiator here.
- Printable club-side match report: rare — a gap we fill.
- Multi-sport pattern worth copying: a game-format template (players on field,
  bench, periods, scoring, positions, formations) attached to team/competition.

## Scope decisions

| Area | Decision |
|---|---|
| Sports | Team sports only (no swimming in this module). Generic via editable game-format presets: football 11 / 8 / 5, futsal, handball, basketball, volleyball. Positions become per-sport. |
| Team unit | New `Team` = sport (Branch) + category + name. Permanent; roster and coaches stored per season; "copy from last season". Players may be in a team of another category (playing up). |
| Player lists | Team season roster → competition squad → match call-up → lineup. |
| Competition | Name, type (league / cup / tournament / friendly), season, team, squad, our record (W/D/L, GF/GA, form). No league table. Optional format override. Suspension rules. |
| Friendlies | A match may have no competition; then call-up picks from the team roster. |
| Match info | Saved opponents list, saved venues list, round label, meeting time & place, home/away/neutral, date, kickoff. |
| Call-up | Coach selects; reason for not-called optional; convocation PDF. Players do not RSVP. |
| Lineup | Pitch + formation presets per format; drag players to spots, rest = bench; captain, goalkeeper. Shirt number from roster, overridable per match. |
| Events | Entered after the match (offline-friendly). Goals (scorer, assist, minute, type), sanctions per sport, substitutions with minute (→ minutes played), score per period, extra time, shootout. Coach rating + note per player, man of the match, match notes. |
| Suspensions | Automatic rules per competition + manual suspensions. |
| Eligibility | Warnings only (never blocks): injured, suspended, missing licence number, missing match documents, unpaid subscription. |
| Access | Coach edits own teams (assigned that season), views all. Role permissions apply on top. |
| Outputs | Player "Matches" tab, team/competition stats, match sheet PDF, calendar shared with training sessions, dashboard widget. |
| Attendance | Separate records; shown together in calendar; optional "cancel that day's training session" on match create. |

## Delivery phases

Each phase ships usable on its own.

1. **Setup & fixtures** — game formats + formations + per-sport positions,
   teams / rosters / coaches, opponents, venues, competitions + squads,
   matches (info, status), call-up + convocation PDF, calendar, permissions.
2. **Match day** — pitch lineup, match report (period scores, events, ratings,
   notes, MOTM), minutes played, match sheet PDF.
3. **Insights** — player Matches tab, team/competition stats, suspensions
   (auto + manual), eligibility warnings (licence, documents, unpaid), dashboard
   widget.

Note: the phase 1 call-up already shows the injured badge (`InjurySpells`
exists). The suspended, licence, documents and unpaid badges arrive in phase 3.

## Data model

Season everywhere = `season_start_year` integer (same as
`preseason_targets`, derived via `App\Support\Season`).

### Game formats (App Configuration)

- `game_formats`: `id`, `branch_id` FK, `name`, `name_ar`, `name_fr`,
  `name_en`, `players_on_field`, `max_bench`, `max_called_up`,
  `periods_count`, `period_minutes`, `period_label` (half / quarter / set /
  period), `scoring` (goals / points / sets), `sets_to_win` nullable,
  `has_extra_time`, `has_shootout`, `sanction_types` JSON (list of
  `{key, ends_play, counts_as}`; e.g. football yellow / red, handball
  two_minutes / red, basketball foul / technical — `ends_play` marks
  dismissals, `counts_as` maps to `yellow` / `red` for suspension rules),
  `is_preset`, `active`, timestamps.
- `formations`: `id`, `game_format_id` FK, `name` ("4-3-3"), `slots` JSON
  (list of `{x, y, position_id, label}`; count = `players_on_field`),
  timestamps.
- `positions`: add `branch_id` nullable FK. Existing positions migrate to the
  Football branch.

Presets seeded through a data migration (desktop build does not run seeders).

### Teams

- `teams`: `id`, `branch_id`, `category_id`, `name`, `default_game_format_id`,
  `active`, timestamps.
- `team_players`: `id`, `team_id`, `season_start_year`, `player_id`,
  `shirt_number` nullable. Unique (team, season, player); unique (team,
  season, shirt_number) when not null.
- `team_coaches`: `id`, `team_id`, `season_start_year`, `user_id`, `role`
  (head / assistant). Unique (team, season, user).

### Shared lists (App Configuration, per-list permissions)

- `opponents`: `id`, `name`, `city` nullable, `logo` nullable (`/media`
  relative path), timestamps.
- `venues`: `id`, `name`, `address` nullable, `is_home`, timestamps.

### Competitions

- `competitions`: `id`, `team_id`, `season_start_year`, `name`, `type`
  (league / cup / tournament / friendly), `game_format_id` nullable
  (override), `red_ban_matches` (default 1), `yellow_threshold` nullable,
  `yellow_ban_matches` (default 1), `archived`, `notes` nullable, timestamps.
- `competition_players`: `id`, `competition_id`, `player_id`, `hidden`
  (default false). Unique (competition, player).

### Matches

The model is named `Game` (table `games`) because `match` is a reserved word in PHP 8.

- `games`: `id`, `team_id`, `competition_id` nullable, `season_start_year`,
  `game_format_id` (resolved and frozen at creation), `opponent_id`,
  `venue_id` nullable, `side` (home / away / neutral), `round_label`
  nullable, `date`, `kickoff_time` nullable, `meeting_time` nullable,
  `meeting_place` nullable, `status` (scheduled / played / postponed /
  cancelled), `our_score` nullable, `their_score` nullable, `period_scores`
  JSON nullable (`[[us, them], …]`), `extra_time_played`, `shootout_us`
  nullable, `shootout_them` nullable, `formation_id` nullable, `notes`
  nullable, timestamps.
- `game_players`: `id`, `game_id`, `player_id`, `called_up` bool,
  `not_called_reason` nullable, `role` (starter / bench / none),
  `shirt_number` nullable, `position_id` nullable, `slot` nullable (index in
  formation slots), `is_captain`, `is_goalkeeper`, `minutes_played` nullable
  (computed), `rating` nullable (1–10), `coach_note` nullable, `is_mvp`.
  Unique (game, player); unique (game, shirt_number) when not null.
- `game_events`: `id`, `game_id`, `type` (goal / sanction / substitution),
  `subtype` (goal: normal / penalty / own_goal / free_kick; sanction: a key
  from the format's `sanction_types`; substitution: null), `period` nullable,
  `minute`, `player_id` (scorer / sanctioned / player in), `related_player_id`
  nullable (assist / player out), timestamps.
- `suspensions`: `id`, `player_id`, `competition_id` nullable (null = all
  competitions), `source` (auto / manual), `matches_count`, `reason`
  nullable, `game_id` nullable (triggering game), timestamps.

### Additions to existing tables

- `players.licence_number` nullable string.
- `document_types.required_for_matches` bool default false.

## Screens & flows

Sidebar: new group (final label to agree; e.g. "Competitions") with Matches,
Teams, Competitions. Settings: Game formats (+ formation editor), Opponents,
Venues.

- **Teams list**: cards (sport, category, head coach, roster count) + season
  switcher.
- **Team page** tabs: Roster (add players filtered by sport/category, any
  category allowed; inline shirt numbers; copy from last season), Coaches,
  Competitions, Matches, Stats (P3).
- **Competitions list**: filter by season and team.
- **Competition page** tabs: Squad (pick from team roster, warning badges),
  Matches, Record (W/D/L, GF/GA, last-5 form), Rules (format override,
  suspension settings), Stats (P3).
- **Matches list + calendar**: upcoming / past, filters (team, competition,
  status); calendar also shows training sessions.
- **Create match**: team → competition (optional) → opponent, venue, side,
  date, kickoff, meeting time/place, round label; checkbox "cancel the
  training session that day" when one exists for the team's category.
- **Match page** — four steps:
  1. Info: edit details, status.
  2. Call-up: list = competition squad (or team roster for a friendly); tick
     players; badges; not-called reason; respects `max_called_up`;
     convocation PDF.
  3. Lineup (P2): pick formation; drag called-up players to slots; others =
     bench; captain, GK, shirt override.
  4. Report (P2): period scores, extra time, shootout; events list (goal /
     sanction / sub with minute and player pickers); per-player rating, note,
     MOTM; match notes; match sheet PDF.
- **Player page**: new "Matches" tab (P3) with season stats and match list.
- **Dashboard**: next match + last results widget (P3).

PDFs are generated server-side like existing exports and work offline (no
remote fonts or assets).

## Rules & computations

- **Format resolution**: competition override, otherwise team default. Copied onto
  `games.game_format_id` at creation, so later template edits don't rewrite history.
- **Minutes played** (played games): match length = `periods_count ×
  period_minutes` (+ extra time when played). Starter plays from 0 until
  subbed off or the end. A sub plays from the minute they come on until
  subbed off or the end. A sanction whose type has `ends_play` (e.g. red card)
  ends minutes at that minute. If no subs are recorded, starters get the full
  match and bench players get 0.
- **Score**: if period scores are entered, the final score is their sum. For
  `scoring = sets`, the final score is sets won, derived from set scores.
  Otherwise the final score is entered directly. Our goal events are checked
  against `our_score`: a mismatch shows a warning and doesn't block saving.
- **Suspensions** (recomputed whenever a report is saved or a game deleted):
  a sanction counting as `red` creates an auto suspension of `red_ban_matches` in that
  competition. When the count of sanctions counting as `yellow` reaches `yellow_threshold`, an auto suspension of
  `yellow_ban_matches` is created and the count resets. Served = the team's played
  games in that competition after the triggering game. Manual suspensions can
  target one competition or all. Friendlies don't count.
- **Warnings** (badge + confirm, never block): injured (open spell from
  `InjurySpells`), suspended, no licence number, missing documents (types
  with `required_for_matches` not received), unpaid subscription debt.
- **Integrity**:
  - A player must be in the squad (or roster for a friendly) to be called up.
  - A player must be called up to be in the lineup.
  - Starters ≤ `players_on_field`; called up ≤ `max_called_up`.
  - Shirt numbers are unique per game.
  - Event minutes run from 0 to the match length.
  - Removing a squad player who appears in a played game is blocked; hide instead.
- **Stats (P3)** are calculated by query from `game_players` and `game_events`, with no stored
  totals: appearances, starts, minutes, goals, assists, cards, average rating;
  team W/D/L, GF/GA, form.
- **Deletion**:
  - Deleting a game cascades its players and events and recomputes auto suspensions.
  - A team or competition with games can be archived but not deleted.

## Permissions

- New role modules `teams`, `competitions`, `games` (view / add / edit /
  delete), registered in `Role::MODULES` / `MODULE_GROUPS` and
  `config/permissions.php`; grant migration for existing installs (admin
  full; coach view on all three plus edit on squads / call-ups / lineups /
  reports).
- Opponents, venues and game formats follow the per-list App Configuration
  permission pattern.
- Coach edit scope is enforced by a policy: the user must be in `team_coaches` for the
  game's / competition's team and season. Admin / god bypass as usual.

## i18n

All UI strings in `resources/js/i18n/{ar,en,fr}.json` (flat dotted keys; run
`npm run i18n:check`). Arabic default. Player names always via
`Player::fullname`.

## Testing

- Pest feature tests: format resolution, call-up / lineup integrity rules,
  coach edit scope, permissions and grant migration, cancel-training option.
- Unit tests: minutes calculator, score derivation (goals / points / sets),
  suspension calculator, warnings resolver.
- PDFs: smoke tests that the convocation and match sheet render.

## Out of scope

Swimming / individual sports, league tables / standings, fixture import from
federations, player RSVP / player accounts, live sideline entry, match fees,
transport planning, multiple lineups per match, team-level stats (possession,
shots, corners).
