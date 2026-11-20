# Training groups inside a category — design

**Date:** 2026-11-20
**Status:** Approved (owner decisions below)

## Need

Swimming · Minime trains on Saturday in two groups: one at 09:30–11:00, one at 11:00–12:30.
A weekly schedule line is keyed by branch + category, and a session's roster is every player of
that branch + category (`Roster::expected`). So both Saturday sessions list the whole Minime
category, and the owner removes the other group's players by hand in every session, every week.
The removal is frozen only for that one session.

The owner wants to attach each player to a group once, and have each session list only its
group.

## Owner decisions (2026-11-20)

| Question | Decision |
|---|---|
| What a group is | A named group inside one branch + category. A weekly schedule line picks a group; a group can have several lines |
| Groups per player | One group only per branch at a time |
| Player of the category with no group | Appears in no group session; the settings page shows how many are waiting |
| Two groups, same category, same day and start time | Never. The slot key (branch, category, date, start) stays as it is |
| Where players are assigned | Both: a tick list on the group (Attendance settings) and a Group field on the player's branch card |
| Month grid and printed month sheet | One sheet per group |
| Statistics, ranking, alerts | Extra group filter |
| Player changes group mid-season | From a date the owner enters; unsaved sessions before that date still list the player in the old group |
| Report numbers of a moved player | A mark belongs to its session's group: the player shows in the old group with the marks until the move and in the new group after. Without a group filter the category numbers are complete |

## Design

### Data

- `training_groups`: `id`, `branch_id` (nullable = whole club, as elsewhere in attendance),
  `category_id`, `name` (one free text, max 60, unique inside branch + category), timestamps.
- `training_group_members`: `id`, `training_group_id`, `player_id`, `from_date` (nullable =
  since always), `to_date` (nullable = still a member), timestamps. Foreign keys with cascade on
  both parents (new tables, so no sqlite rebuild). Index on (`player_id`, `training_group_id`).
- `group_id` nullable, indexed, on `training_schedules` and `training_sessions`. No foreign-key
  constraint, for the same reason as `branch_id` (adding one rebuilds the sqlite table, and
  `training_sessions` has cascading children). **Null = the whole category, exactly as today.**
  Football and every category without groups are untouched.
- The migration only adds tables, columns and indexes. No backfill: every existing row keeps
  `group_id` null.
- No SQLite-only SQL (the suite also runs on MariaDB).

### Membership rules

One service, `App\Services\Attendance\GroupMembership`, is the only writer of
`training_group_members`.

- A player is a member of a group on a date when `from_date` is null or ≤ the date, and
  `to_date` is null or ≥ the date.
- A player has at most one membership open on any date inside one branch.
- Every change takes a date **D** ("changes apply from"), which may be empty = "from the
  beginning":
  - **Add to a group from D:** any membership of the player in another group of the same branch
    that is open on D is closed at D − 1, then a membership starts at D.
  - **Add from the beginning:** the player's memberships in that branch are deleted and one
    membership with no start date is created. This is the first-setup path and the way to fix a
    mistake.
  - **Remove from D:** the membership is closed at D − 1. **Remove from the beginning:** the row
    is deleted.
  - A close that would end before the membership starts deletes the row.
- When a registration's category changes (`RegistrationService`, single edit and bulk edit), the
  player's open membership in that branch is closed the day before (a group belongs to one
  category).
- Saved sessions are never touched by any of this: their roster is the saved marks.

### Roster

- `Roster::expected()` gains `?int $groupId`. With a group, the existing conditions stay (branch,
  category, roster statuses, not left before the date) and one is added: the player is a member
  of that group on the session date. Null group = no extra condition.
- `Roster::forSession()`, the grid save and `MonthSheet` pass `$session->group_id`.
- Frozen-after-first-save behaviour is unchanged.
- Hand-adding a player on the session page is unchanged: anyone can still be added by hand, so a
  swimmer making up a missed session with the other group can be added for that day.

### Generator and schedule lines

- A weekly schedule line gets a Group select listing the groups of its branch + category (first
  option: whole category). `validateSchedule` rejects a group of another branch or category.
- `SessionGenerator` copies the line's `group_id` to each session it creates.
- When a line is saved with a different group, in the same transaction as today's purge:
  every session of that line with no saved marks takes the new `group_id` (past ones, and moved
  ones that the purge leaves alone); future unmarked planned sessions are purged and regenerated
  as today. Sessions with saved marks keep their group and their marks.
- The slot key and `assertSlotFree` do not change: two lines of the same branch + category on the
  same weekday and start time stay impossible, whatever their groups.
- Joint pre-season sessions (several categories) never have a group.

### Settings page: Groups card

A new card on Attendance settings, above the weekly schedule.

- Lists the groups by branch and category, each with its member count today.
- Create (branch, category, name), rename, delete. Delete is refused while a schedule line or a
  session uses the group (`flash.training_group_in_use`); otherwise it removes the group and its
  memberships.
- **Members dialog** per group: the category's roster today (`Roster::expected` with the default
  statuses), with a search box. Each row has a tick, the full name (`Player::fullname`) and, when
  the player is in another group, that group's name. One "changes apply from" date on top:
  empty ("from the beginning") when the group has no members yet, today otherwise. Save applies
  every tick change through `GroupMembership`.
- **Waiting count:** for each branch + category that has at least one group, the number of roster
  players with no membership today, with their names on click. This is how a new player is not
  forgotten.
- Routes are named `attendance.groups.*`, so they fall under the attendance edit right through
  the route-name-derived permission middleware.

### Calendar and session page

- The group name follows the category wherever a session is named: calendar views, session page
  header, session paper sheet (`Minime · الفوج 1`).
- No group filter on the calendar.

### Player branch card (phase 2)

- Each branch card of the player form gets a Group select: the groups of that card's branch and
  category, or none. Hidden when the category has no groups.
- Changing an existing group shows the "from" date (default today, can be emptied = from the
  beginning). A first assignment has no start date.
- Saved through `GroupMembership` by `RegistrationService`.
- The player page shows the current group beside the category on the branch card.

### Month grid, printed month sheet, hand-added sessions (phase 2)

- `MonthSheet::build()` gains `?int $groupId` and keeps only that group's sessions.
- The month grid and the printed month sheet get a group switch when the category has groups in
  that branch: one entry per group, plus "whole category" for sessions with no group when the
  month has any. Default: the first group. The printed sheet names the group in its header.
- Add-session dialog: optional Group select for the chosen branch + category; hidden for
  pre-season.
- Session Edit dialog: the group can change until marks are saved, on hand-added sessions only (a
  regular session follows its schedule line), the same rule as the branch.

### Reports (phase 3)

- `GroupScope`, built like `BranchScope`: a mark belongs to its session's `group_id`.
- Statistics (+ export), ranking (+ certificates) and alerts (+ export) get a Group select, shown
  when the chosen category has groups. Default "all groups" = today's numbers. With a group,
  every number (score, counts, missed time, streaks, at-risk flags, rank) comes from that group's
  sessions only.
- Unchanged: injuries page, player profile card, player PDF report and parent letter, dashboard
  cards, literary report.

### Names in the interface

Arabic «الفوج» / «الأفواج», French « Groupe », English "Group". New i18n keys go in ar, fr and
en, checked with `npm run i18n:check` (no key segment named like a String method).

## Delivery

Three phases, one plan and one branch each, built in a worktree.

1. **Groups + roster** — migration, `GroupMembership`, Groups card with members dialog and
   waiting count, Group select on schedule lines, generator, roster, group name on calendar and
   session page. After this phase the Saturday sessions list the right players.
2. **Player card + sheets** — Group field on the branch card with the move date, month grid and
   printed month sheet per group, group in the add-session and Edit dialogs, session paper sheet.
3. **Report filter** — `GroupScope`, group select on statistics, ranking + certificates, alerts,
   and their exports.

Each phase updates the Help Center attendance articles in Arabic with their French and English
twins.

## Testing

- Phase 1: a group session lists only members on its date; a player with no group is in no group
  session and is counted as waiting; a session with no group lists the whole category; a move
  from date D lists the player in the old group before D and the new one from D; "from the
  beginning" replaces the history; a category change closes the membership; saving a line with a
  new group retags unsaved sessions and leaves saved ones; the generator copies the group; a group
  of another branch or category is rejected on a line; a group in use cannot be deleted; saved
  sessions never change.
- Phase 2: the branch card assigns and moves through the same rules; grid and sheet show one
  group's sessions and players; grid save accepts only the group session's roster; the Edit
  dialog locks the group after marks.
- Phase 3: the group filter narrows statistics, ranking and alerts to the group's sessions; a
  moved player has marks in both groups; no filter equals today's numbers.
- Whole suite with `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit`, in the background.
- After merge: `php artisan migrate` on the dev database (backup first); desktop build only when
  the owner asks.
