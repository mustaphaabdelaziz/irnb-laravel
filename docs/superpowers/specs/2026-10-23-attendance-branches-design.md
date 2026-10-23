# Attendance sessions per branch + session edit dialog — design

**Date:** 2026-10-23
**Status:** Approved (owner decisions below)

## Need

Branches are the club's sports (كرة القدم: 188 players, السباحة: 4; 3 players in both).
Attendance sessions are keyed by category only, so a session's roster lists every player of
the category whatever their sport: swimmers show up in football sessions. The owner wants a
session to belong to a branch and list only that branch's players.

Second need: edit a session's details (title, time, …) in one place. Today title/coach/notes
sit in the side panel and save only with the marks, date/time go through a separate Move
dialog, and kind, roster statuses and branch cannot change after creation.

## Owner decisions

| Question | Decision |
|---|---|
| Where the branch applies | Every session: weekly schedules get a branch, so generated sessions are per branch too |
| Existing sessions and schedules | Assigned to football (the branch with the most players) |
| Who a session lists | Members of the session's branch only; a player in two branches is listed in both sports' sessions |
| Calendar | Branch switch beside the category selector, remembered; every view shows that branch only |
| Reports | Branch filter (default all) on stats, ranking + certificates, alerts/at-risk, grid, monthly sheets; ranking per branch + category |
| Same category, same time, two branches | Allowed |
| Pre-season | Targets per branch + category; a joint pre-season session is one branch, several categories |
| Hand-adding a player | Session's branch players first; players of other branches still findable, tagged with their branch |
| Edit dialog | One dialog: title, date, start/end, coach, notes always; branch, roster statuses, categories (pre-season) and kind extra ↔ pre-season until marks are saved |

## Design

### Data

- `branch_id` nullable, indexed, on `training_sessions`, `training_schedules`,
  `deleted_session_slots` and `preseason_targets`. **Null = the whole club**: a club with no
  branches (generic template, fresh install) keeps working unchanged. Forms require a branch
  whenever at least one branch exists.
- No DB foreign-key constraint: adding one rebuilds the sqlite table, and `training_sessions`
  has cascading children. Instead, deleting a branch is refused while it has sessions or
  schedules (`flash.branch_has_sessions`, same pattern as the teams guard); its pre-season
  targets and deleted-slot tombstones are deleted with it.
- Migration backfills every existing row of those tables with the branch that has the most
  players (ties: lowest id); no branch → stays null. A club that creates its first branch later
  gets the same adoption at that moment (BranchObserver::created), so its older calendar stays
  visible. Saved marks (`attendances`) are untouched:
  a mark's branch is its session's.
- Unique keys gain the branch through a virtual column `branch_key = coalesce(branch_id, 0)`
  (NULLs never collide in a unique index, and the generator relies on `insertOrIgnore`):
  `training_sessions (branch_key, category_id, date, start_time)`,
  `deleted_session_slots (branch_key, category_id, date, start_time)`,
  `preseason_targets (branch_key, category_id, season_start_year)`.
- `assertSlotFree` checks branch + categories + date + start: football U13 and swimming U13 can
  both train at 18:00; the same branch and category cannot.
- Migrations only add columns and swap indexes: never rebuild an sqlite table that has
  cascading children (attendances).

### Generator and generation marks

- `SessionGenerator` generates a category's month from every schedule of that category, each
  session taking its schedule's `branch_id`. Its "slot already taken" / moved / deleted-slot
  lookups compare branch too.
- `session_generation_marks` stay keyed by (category, month): one generation pass covers every
  branch of the category, so any change to a schedule or session of a category forgets that
  category's months as today. No key change there.

### Roster

- `Roster::expected()` takes the branch: category + status set as today, plus membership of the
  branch (`branch_player`). Null branch = no branch condition.
- Frozen-after-first-save behaviour unchanged.
- Hand-add candidates on the session page: every active player as today, the session's branch
  members sorted first; others carry their branch name(s) as a tag.

### Calendar and settings

- Calendar: branch switch beside the category selector, shown when at least one branch exists.
  Value lives in the `branch_id` query parameter and is remembered in the user's server session
  (no reload flicker). Default: first branch by id. Month, week, agenda and timeline show
  only that branch's sessions; generation still runs per category for all branches.
- Add-session dialog: branch select, pre-selected from the switch.
- Settings: weekly schedules and pre-season targets get a branch column/select; schedule list
  grouped or filterable by branch.
- Pre-season progress (calendar card) counts per branch + category.

### Session edit dialog

One "Edit" button on the session page (attendance edit permission, not on a cancelled session),
replacing the Move dialog and the pre-season categories dialog. Endpoint
`PUT /attendance/sessions/{session}` (`attendance.sessions.update`).

- Always editable: title, date, start time, end time, coach, notes. A date/time change keeps
  `moved_from` (first original date) exactly as Move does today.
- Editable until marks are first saved (the roster is frozen from then on; fields shown read-only
  with a hint afterwards):
  - branch;
  - roster statuses (`roster_status_ids`);
  - categories, pre-season only (current updateCategories rules: primary dropped → first chosen
    becomes primary);
  - kind extra ↔ pre-season, hand-added sessions only (regular sessions keep their kind).
    Pre-season → extra keeps only the primary category.
- Slot check (`assertSlotFree`) runs on the resulting branch/categories/date/start.
- Title, coach and notes saved here no longer need a marks save; the marks Save keeps sending
  them too, so the side panel stays as it is.
- Activity log: one `TRAINING_SESSION_UPDATED` entry with the changed field names.
- Old `move` and `categories` routes removed with their dialogs (no other callers).

### Reports

Branch filter (multi-select pattern used elsewhere; default = all branches) on:

- stats page and its export, player attendance card stays all branches;
- ranking + certificates: ranked within branch + category;
- alerts / at-risk list and export, dashboard at-risk card stays all branches;
- marking grid and monthly paper sheets (single branch, from the calendar switch);
- session paper sheet shows the branch name.

A mark counts for a branch through its session's `branch_id`.

## Delivery

Three packages, one plan and branch each, in the `D:/irnb-branches` worktree:

1. **Data + generator + roster + calendar/settings** — migration and backfill, slot keys,
   generator, roster, calendar switch, add dialog, settings schedules/targets, pre-season
   progress, hand-add ordering.
2. **Edit dialog** — update endpoint, dialog, removal of move/categories routes.
3. **Report filters** — stats, ranking/certificates, alerts, grid, sheets.

## Testing

- Feature tests per package (Pest/PHPUnit as existing attendance tests): roster excludes other
  branches' players and includes two-branch players in both; same category/time in two branches
  allowed, same branch rejected; generator creates per-branch sessions and respects per-branch
  deleted slots; backfill assigns the largest branch and leaves null when no branch exists;
  edit dialog locks branch/roster/categories/kind after marks, keeps `moved_from`; report
  filters narrow counts and rankings.
- Deploy: `php artisan migrate`, refresh seed sqlite, desktop rebuild only when the owner says.
