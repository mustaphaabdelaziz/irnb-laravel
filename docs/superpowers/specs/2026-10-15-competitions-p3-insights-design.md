# Competitions & Matches — Phase 3: Insights — Design

Date: 2026-10-15
Status: approved design, pending implementation plan
Builds on: Phase 1 (868d378) and Phase 2 (1a22c81). Specs: `2026-10-14-competitions-matches-design.md`, `2026-10-14-competitions-p2-match-day-design.md`.

## Goal

This phase finishes the module with:
- **Suspensions**: automatic from cards, plus manual ones.
- **Eligibility warnings** in the call-up, squad and roster: suspended, no licence, documents missing, unpaid.
- **Statistics**: on the player page, the team page, the competition page and a club-wide page, with exports.
- **A dashboard card** for matches.

## Owner decisions (2026-10-15)

| Topic | Decision |
|---|---|
| Card bans | Counted per competition from match reports. A sanction that counts as red gives `red_ban_matches`. Sanctions that count as yellow add up to `yellow_threshold`, give `yellow_ban_matches`, and then the count starts again. Friendlies don't count. |
| Serving a ban | Each played match of the team in that competition after the triggering match counts as one served, even when the player isn't called up. Postponed and cancelled matches don't count. |
| Manual bans | Either **N matches** (one competition, or all competitions) or **a date range** (`starts_on`–`ends_on`). |
| Warnings | Warnings only, never blocking. The badges are injured (Phase 1), suspended, no licence, documents missing and unpaid. |
| Unpaid badge | Shown to everyone who sees the list, without an amount. The amount appears only for users with Finance → view. |
| Documents for matches | A new `document_types.required_for_matches` flag. Missing means the flagged type is active, applies to the player, and is not received or has expired (existing checklist rules). |
| Licence | One `players.licence_number` field, on the player form, the player page and the import. Renewals stay in the Documents module. |
| Statistics screens | All four: a player page section, a team Stats tab, a competition Stats tab, and a club Statistics page. All have a season filter. |
| Exports | Excel and PDF on the club page and the team stats tab. **Ratings are excluded from both exports.** |
| Dashboard | An Overview card with the next 3 matches and last 3 results. A coach sees their own teams first; admins see all. |

## Data model

- `players.licence_number`: string(50), nullable. It goes in the player create/edit forms, the show page, and the import (a new column, `licence_number`).
- `document_types.required_for_matches`: boolean, default false. It is a checkbox on the Document types settings page.
- `suspensions` (new table):
  - `id`
  - `player_id`: FK, cascade on delete.
  - `competition_id`: nullable FK, cascade on delete. Null means all competitions; only manual bans can be null.
  - `source`: `auto` or `manual`.
  - `kind`: `matches` or `dates`.
  - `matches_count`: unsigned tinyint, nullable. Required for `matches`.
  - `starts_on` / `ends_on`: `'Y-m-d'` strings, nullable. Required for `dates`. A manual `matches` ban across all competitions also uses `starts_on` as the date it begins counting from.
  - `cause`: `red` or `yellows`, nullable. For automatic bans only.
  - `game_id`: nullable FK, cascade on delete. The match that triggered an automatic ban.
  - `reason`: string(255), nullable.
  - `created_by`: nullable FK to users, null on delete.
  - timestamps
  - Indexes: (`competition_id`, `source`) and (`player_id`).

## Engines

### `SuspensionEngine::recompute(Competition)`

1. Delete the competition's `auto` rows.
2. Walk its played games in date order (`date`, then `id`). For each game, take the sanction events whose subtype maps to `counts_as` in the game's own `rules.sanction_types`. Within a game, events are ordered by minute (nulls last), then by id.
3. Apply the rules:
   - `red` gives an auto ban of `red_ban_matches`, with cause `red`. A value of 0 means no ban.
   - `yellow` adds one to that player's counter in this competition. When the counter reaches `yellow_threshold`, the player gets an auto ban of `yellow_ban_matches` with cause `yellows`, and the counter resets. A null threshold means yellow cards never lead to a ban.

It runs inside the triggering transaction. The triggers are:
- report save (`GameReportController`);
- game delete, when the game had a competition;
- competition update, when any of the three rule fields changed;
- call-up save, when events were removed.

### `SuspensionStatus`

This service answers "who is suspended for this game, and why", for a list of player ids, in a constant number of queries.

- **Matches ban in one competition:** served = the number of the team's played games in that competition dated after the trigger game (same date with a larger id also counts). Remaining = `matches_count` − served. It is active when remaining > 0, and it only applies to games of that competition.
- **Manual matches ban in all competitions:** served = the number of played games with a competition, dated on or after `starts_on`, of any team whose roster that season includes the player.
- **Dates ban:** active when the game date, or today on non-game screens, falls between `starts_on` and `ends_on` inclusive. It applies to every game. For a single competition it applies to that competition only.

Output per player: `{active: bool, remaining: ?int, until: ?string, reason_key, detail}`.

### `MatchStats`

This is one aggregate query builder that every statistics screen and export uses.

- **Filters:** `season` (required), `team_id`, `competition_id`, `player_id`.
- **Base rows:** the `game_players` rows of played games (`status = played`) where `called_up` is set.
- **Per player:**
  - `appearances`: rows with `played` set.
  - `starts`: rows with `role = starter` and `played` set.
  - `minutes`: sum of `minutes_played`. `sets`: sum of `sets_played`. The two are never mixed: sport comes from `games.rules->sport`, so rows are grouped by sport whenever more than one is present.
  - `goals`: events of type `goal` with the player as `player_id`, except `opponent_own_goal`. `assists`: goals where the player is `related_player_id`.
  - `yellows` and `reds`: sanction events whose subtype maps to `counts_as` in that game's rules. Other sanctions are counted as `other_sanctions` and shown on the player page only.
  - `avg_rating`: the mean of non-null ratings, rounded to 1 decimal. `rated`: how many ratings it averages.
  - `motm`: count of rows with `is_mvp` set.
- **Leaderboards:** top N by goals, assists, minutes, appearances, cards (reds × 3 + yellows), and avg_rating. The rating board only includes players with `rated` ≥ 3.
- **Team record and form:** reuse `CompetitionRecord::fromGames`.

**Mapping sanction subtypes to yellow and red:** this needs each game's rules, which the database can't join. Sanction events are loaded once per query scope, as (game_id, subtype, player_id), and mapped in PHP using a game-id → rules map. Everything else is SQL `GROUP BY`.

### `PlayerFlags` (extended)

`for(list<int> $playerIds, ?Game $game): array<int, {injured, suspended, suspension, no_licence, documents_missing, documents, unpaid, debt}>`.

The whole list is computed in one pass:
- **injured:** existing, from `InjurySpells`.
- **suspended:** `SuspensionStatus`.
- **no_licence:** an empty `licence_number`.
- **documents_missing:** the `DocumentChecklist` rules limited to types with `required_for_matches`. It uses the SQL helpers, or one bulk query for the list.
- **unpaid:** `outstanding_debt > 0`. `debt` is included only when the viewer has finance/view, and is null otherwise.

The match call-up, the competition squad and the team roster all use it.

## Screens

- **Badges** in the call-up, squad and roster:
  - injured 🟧
  - suspended ⛔, with a tooltip such as "2 matches left — red card vs X" or "until 30/11"
  - no licence 🪪
  - documents missing 📄, with a tooltip listing the names
  - unpaid 💰, with the amount in the tooltip only for finance viewers
- **Competition page, Suspensions tab:**
  - active bans first, then served ones;
  - an "Add manual suspension" form: player, this competition or all competitions, matches or dates, and a reason;
  - delete is available on manual bans only.
- **Player page:** a self-loading `MatchesSection`, using the `AttendanceSection` pattern with its own JSON endpoint, placed after the attendance section. It shows:
  - season totals (per sport when there are several);
  - the match list (date, opponent, minutes or sets, goals, cards, rating);
  - active suspensions.
  It is shown only with `games/view`.
- **Team page, Stats tab:** the record, form and leaderboards for the selected season, plus an export menu.
- **Competition page, Stats tab:** the same leaderboards, scoped to that competition.
- **Club Statistics page:** sidebar item "Statistics" under Competitions & matches, route `games.stats` at `/games/stats`. It has season, team and competition filters, the leaderboards and a full player table, plus the export menu (Excel and PDF).
- **Dashboard:** an Overview tab card that needs `games/view`. It shows the next 3 matches (scheduled or postponed, on or after today) and the last 3 played, with score and a W/D/L colour. A coach's teams are listed first. Clicking a row opens the match. It is built by a `CompetitionStats` dashboard service.

## Permissions

- **Stats pages, tabs, the player section endpoint and the exports:** games/view. Overrides are added where a route name isn't a view verb.
- **Manual suspension store and destroy:** competitions/edit, plus `TeamAccess::authorize` against the competition's team and season. A ban across all competitions needs a team context: the form is reached from a competition and authorizes against that competition's team.
- **Activity codes:** `SUSPENSION_ADDED` and `SUSPENSION_REMOVED`, in the competitions area, with ar/en/fr labels. Remember the hard-coded count in `ActivityRecorderTest`.

## Testing

- **Unit:**
  - the `SuspensionEngine` rules: red, yellows reaching the threshold, the counter reset, a null threshold, a 0-match ban, friendlies ignored;
  - the `SuspensionStatus` countdowns: postponed and cancelled games don't count, the all-competitions manual ban, date bans;
  - `MatchStats` totals and the sport split.
- **Feature:**
  - recompute triggers: report save/edit, game delete, rule change, call-up removal;
  - manual bans of both kinds, with permissions and coach scope;
  - every badge condition, and that unpaid shows no amount without finance view;
  - the four stats screens with their filters;
  - Excel and PDF exports with no rating column or values;
  - the dashboard card's permission and ordering;
  - the licence field in the form and the import.

## Out of scope

League tables, live entry, opponent events, several line-ups per match, a formation editor.
