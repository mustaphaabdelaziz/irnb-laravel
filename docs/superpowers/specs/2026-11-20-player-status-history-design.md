# Player status history — design

Date: 2026-11-20
Status: approved by owner (design), spec awaiting review

## Problem

A player's membership of a branch is one row in `branch_player`
(`BranchRegistration`): join date, status, and `left_at`. The row holds the
present only.

- Leaving sets the status coded `left` and stamps `left_at`.
- Coming back reopens the same row (`RegistrationService::reopen`): the status
  is overwritten and the saving hook clears `left_at`. The departure is gone.
- Any other status change (suspended, registered, …) simply overwrites the
  previous one.

So the club cannot answer "when did he leave, when did he come back, how many
times", and the Joined/Left report (`PlayerMovement`) forgets a departure the
day the player returns, because it reads only the current `left_at`.

## Goal

Changing a status never destroys what came before. For each branch a player
belongs to, the player page shows the full story — joined, every status change
with its date, note and author — and the movement report counts past departures
and returns correctly.

## Decisions (owner, 2026-11-20)

- Keep **every status change**, not only leave and return.
- History is **per branch** (per registration), like join dates.
- A returning player keeps the **same membership ID and first join date**; the
  return is a history line, not a new registration.
- Past history can be **added, corrected and deleted by hand**.
- Changing a status in the form **asks for the date** (default today) and an
  **optional free note**. No reason list.
- Shown on the **player page per branch**; the **movement report** uses it.
  Not on printed sheets.
- **Bulk changes from the players list record no line.** Only the player form
  does.

## Data

### New table `registration_status_changes`

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `branch_registration_id` | FK → `branch_player` | cascade on delete |
| `from_status_id` | FK → `player_statuses`, nullable | null on delete; null also means "unknown" |
| `to_status_id` | FK → `player_statuses`, nullable | null on delete |
| `changed_on` | date | the day the change took effect, chosen by the user |
| `note` | string(255), nullable | free text |
| `user_id` | FK → `users`, nullable | null on delete; who recorded the line |
| `origin` | string(10) | `form`, `manual` or `derived` |
| `created_at`, `updated_at` | timestamps | when it was recorded / last corrected |

Indexes: (`branch_registration_id`, `changed_on`) and (`to_status_id`,
`changed_on`). Explicit constraint names, as in the other registration
migrations.

`origin`:

- `form` — written when a status was changed in the player form.
- `manual` — a past line typed by hand on the player page.
- `derived` — written by the system from a `left_at` that was about to be lost,
  or by the seeding migration. It has no user.

Model `RegistrationStatusChange`; `BranchRegistration::statusChanges()` has
many, ordered by `changed_on` then `id`.

### What does not change

`branch_player.status_id` and `left_at` remain the current state and every
existing reader keeps working. History describes the past and never drives the
current status. Membership ID, join date, documents, debts and attendance are
untouched.

### Seeding migration

One `derived` line (`from` null → `left`, `changed_on` = `left_at`) for every
registration that is currently left with a known leave date. A left row with no
date gets nothing: there is no day to record. Idempotent: skips a registration
that already has a matching line.

## Recording

All writes go through a small service, `App\Services\Player\StatusHistory`:

- `record(registration, from, to, date, note, user, origin)`
- `keepDeparture(registration)` — the safety net below
- `statusOn(registration, date)` — the status a registration held on a day:
  the `to` of its latest line on or before that day; else the `from` of its
  earliest later line; else its current status. With `from` null the answer is
  "unknown", which counts as a member (not left).

### From the player form

`RegistrationService::sync()` is the path the player form uses. In `change()`
and `reopen()`, when a card moves an existing registration from one status to a
different one, a `form` line is written with the card's date and note.

Each card gains two fields, read only when its status actually changes:

- `status_changed_on` — required then, `Y-m-d`, not in the future, not before
  the birth date, not before the registration's join date when one is known.
  Missing (older client) falls back to today.
- `status_note` — optional, max 255.

When the new status is `left`, `left_at` takes `status_changed_on`: the leave
date and the date of the change are the same fact. A new registration writes no
line: its start is the join date.

### Bulk changes

`RegistrationService::setField()`, `leave()` and `register()` (bulk edit, the
branches bulk action, the Members dialog) write no line — the owner's choice.

### Safety net: a leave date is never lost

In `BranchRegistration`'s saving hook, beside the rule that clears `left_at`:
when a registration stops being left and its old `left_at` is known, and no
line already records a departure on that day, a `derived` line (`from` null →
`left`, `changed_on` = old `left_at`) is written first. It sits in the model so
no writer, bulk or not, can skip it. A bulk return therefore keeps the
departure, though the return itself has no line.

### The leave date and its line are one fact

While a registration is left, its `left_at` and its latest departure line stay
equal: correcting the leave date in the form moves that line's `changed_on`;
correcting that line's date on the player page moves `left_at`. Deleting the
line leaves `left_at` alone.

## Player form

`RegistrationCard.vue`: when the status select differs from the saved status of
an existing registration, a "date of change" field (default today, club's local
day) and a note field appear under it. They replace the leave-date field for
the moment of leaving. For a registration that is already left and whose status
is not being changed, the leave-date field stays, as today, to correct the day.
`registrationCards.js` carries the two new fields; they are dropped from the
payload when the status is unchanged.

## Player page

`RegistrationsSection.vue`: each branch block gains a timeline.

```
Joined 01/09/2022
Left 15/06/2024 — moved to another city          (by Amine, 16/06/2024)
Registered 01/09/2026                             (by Amine, 01/09/2026)
```

- First point: the join date (or the join year, or "unknown") — read from the
  registration, not stored as a line.
- Then each line: the new status, the date, the note, and in small text who
  recorded it and when. A `derived` line shows no author. A deleted status
  shows a neutral "removed status" label.
- The current status is the last line's, as the block already shows.

With the Players → edit right, three actions:

- **Add a past line** — status (required), previous status (optional), date,
  note. Origin `manual`.
- **Edit a line** — date and note on any line; the statuses too on a `manual`
  line.
- **Delete a line** — with confirmation.

Same date rules as the form. These actions change history only; the current
status is changed in the player form. Routes sit under the player and must map
to the Players → edit right in the route-name permission middleware; a line is
always checked to belong to that player's registration.

`PlayerController::show` eager-loads `registrations.statusChanges` with status
and user names (narrow columns).

## Movement report

`PlayerMovement` gains a third movement, **Returned**, and reads the history.

A **departure** of a registration in year Y is a line to `left` dated in Y, or
a current `left_at` in Y that no line records (covers bulk changes). A
**return** is a line from `left` to another status dated in Y.

- **A branch is chosen** — Left lists players with a departure in Y on a
  registration of that branch; Returned lists players with a return in Y there.
- **No branch chosen** — the meaning stays the club-wide one of today: a
  player left the club when a departure left him with no branch at all, i.e.
  on that day every other registration of his was itself left or not yet
  joined (`StatusHistory::statusOn`). Returned mirrors it: a return while every
  other registration was left or not yet joined. The candidates (players with a
  departure or return in Y) are few; this is resolved in PHP and passed to the
  query as IDs, like the report's existing PHP grouping.
- **Joined** is unchanged (join year).

Columns: `left_at` shows the departure the row was found by (not the current
`left_at`, which is empty once he returned); a new `returned_at` column shows
the return that followed it, when there is one. For Returned rows `returned_at`
is the return in Y and `left_at` the departure before it. Joined rows keep the
current `left_at` and an empty `returned_at`.

Summary adds a Returned count. The leavers-by-year chart counts departures by
the same rules. A bulk return has no line, so it does not appear under
Returned.

## Out of scope

- Players with no branch registration: their status lives on `players` and has
  no history.
- Lines for bulk changes; a list of reasons; the printed player sheet.
- Other readers of `left_at` (dashboard member stats, literary report members
  section, attendance rosters) keep reading the current state. A returned
  player still looks active across his past absence there.
- Deleting a registration added by mistake deletes its lines with it.

## Text and help

New interface text in ar, fr and en (flat dotted keys, insert-only, no key
segment named after a String method; `npm run i18n:check`). The Help Center
player article gains a short section on the history, in Arabic with its French
and English twins.

## Tests

- Form: a status change writes one `form` line with the chosen date, note and
  user; an unchanged status writes none; moving to `left` sets `left_at` to the
  chosen date; date rules reject future, before-birth and before-join dates.
- Return through the form writes the return line and keeps ID and join date.
- Bulk status, bulk branches and the Members dialog write no `form` line.
- Safety net: a left registration with no departure line returns by any path →
  one `derived` departure line; with a line already there → none added.
- Leave date and latest departure line stay equal when either is corrected.
- Manual add, edit, delete; a line of another player's registration is refused;
  the edit right is required.
- `statusOn` for days before, between and after lines, and with no lines.
- Report: a returned player is counted in his leaving year, per branch and
  club-wide; a player who left one branch but stayed in another is not a
  club-wide leaver; Returned lists him; a bulk departure with no line is still
  counted.
- Seeding migration: one line per dated left registration, none for undated,
  second run adds nothing.
- SQL portable across SQLite and MySQL.
