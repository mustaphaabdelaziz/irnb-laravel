# Duplicate players — find and merge

Date: 2026-11-26
Status: approved by owner (decision rounds 2026-11-26)

## Goal

Discover players entered twice by chance and merge them. The user picks criteria,
sees a scored list of candidate pairs, and on each row either merges the pair or
marks it "not duplicate". Layout follows the owner's reference screenshot
(«Doublons potentiels»: Player A | Player B | Score | Reasons | Status | Actions).

## Owner decisions

| Topic | Decision |
|---|---|
| Criteria | Name (first + last), Birthdate, Phone, Father. Chips, ticked = AND filter on the pair's reasons |
| Ranking | Score per pair + min-score dropdown, combined with criteria chips |
| Rows | One row per pair |
| "Not duplicate" | Remembered forever; Dismissed status shows it with an undo |
| Merge screen | Side by side, pick keeper, per-field radio where values differ, empty fields auto-filled |
| Clashes | Smart rules per record kind (below), preview lists each clash + result |
| Safety | Automatic DB backup before each merge + activity-log entry |
| Access | New right `Players → Merge duplicates`, starts empty (granted nobody) |
| Archived | Always included, shown with a badge |
| Location | "Find duplicates" button on the Players list (shown only with the right); no sidebar item |

## Data

Pairs are **computed live** on every load / refresh; only decisions are stored.

New table `player_duplicate_decisions`:

- `id`
- `player_a_id`, `player_b_id` — nullable FKs to `players` (`nullOnDelete`); stored ordered `a < b` at decision time
- `status` — `dismissed` | `merged`
- `player_a_snapshot`, `player_b_snapshot` — JSON: full name, membership ID, birthdate (so merged rows still display after the duplicate is deleted)
- `kept_player_id` — nullable, for `merged`
- `reasons` (JSON), `score` (int) — as computed when decided
- `decided_by` (FK users, nullOnDelete), `decided_at`
- `timestamps`
- unique (`player_a_id`, `player_b_id`); merging a dismissed pair updates that row to `merged`

Undo of a dismissal deletes the decision row → pair returns to Pending.

## Scoring

Normalisation reuses `App\Support\NameNormalizer` (أ/إ/آ→ا, ة→ه, ى→ي, spaces,
case). Phones: digits only, leading `+213`/`00213`/`213` → `0`; `phones` is a JSON list,
any shared number counts.

| Reason (code) | Points |
|---|---|
| `same_name` — normalised first + last identical | 80 |
| `similar_name` — first/last swapped, or Levenshtein ≤ 2 on the normalised full key (key length > 4) | 50 |
| `same_birthdate` | 60 |
| `same_phone` | 60 |
| `same_father` — normalised, both non-empty | 30 |
| `different_gender` — both set and differ (penalty) | −60 |

`same_name` and `similar_name` are exclusive. A pair is listed only when score ≥ 50.

Candidate generation (avoid all-pairs): bucket players by normalised name key,
birthdate, each phone, and father key; candidates = players sharing at least one
bucket. `similar_name` is tested only inside birthdate / phone / father buckets,
plus a swapped-name bucket. Pending excludes pairs having a decision row.

Criteria chip mapping: Name ⇐ `same_name` or `similar_name`; Birthdate ⇐
`same_birthdate`; Phone ⇐ `same_phone`; Father ⇐ `same_father`.

## Page

Route under players, e.g. `players.duplicates.index` (permission derived from route
name → new right). Inertia page:

- Header: «Doublons potentiels» + count badge + collapse; Status dropdown (Pending /
  Dismissed / Merged); Min-score dropdown (All, ≥50, ≥100, ≥150, ≥200); refresh.
- Criteria chips: Name · Birthdate · Phone · Father.
- Table: Player A | Player B (formatted full name via `Player::fullname`, branch
  membership ID, photo, archived badge) | Score | reason chips | Status | actions:
  - 👁 compare (opens the merge screen read-only / both player pages)
  - ✕ not duplicate (Pending) / undo (Dismissed)
  - ↔ merge
- Merged status lists decisions from snapshots, no actions.
- Filters kept in the query string. Icon-only buttons via `IconButton`. Dark mode +
  320 px rules apply. i18n ar/fr/en.

## Merge screen

`players.duplicates.merge` (GET preview, POST confirm) for a pair.

- Two columns; radio at top chooses the keeper (default: older record).
- For each player field where both values are non-empty and differ: radio to take
  either value. Empty on keeper + filled on the other → auto-filled. Photo chosen the
  same way. Membership ID stays the keeper's.
- Preview: counts of records moving (subscriptions, transactions, attendance,
  documents, registrations, …) and each clash with its resolved outcome.
- Confirm → backup → merge in one DB transaction → decision row `merged` + activity
  log entry with the removed player's snapshot → redirect to keeper's page with flash.
- Stale-pair guard: if either player no longer exists, redirect back with an error.

## Merge engine (`App\Services\Player\PlayerMerger`)

Duplicate = B, keeper = K. Every FK to `players` and every polymorphic reference is
repointed to K; clashes resolved as follows:

| Record | Rule |
|---|---|
| `branch_player` (same branch) | earliest join date wins (its membership ID kept); `registration_status_changes` and `player_documents` of the loser move onto the winner |
| `player_documents` (same registration + type) | received beats missing; both received → keep K's, move B's `player_document_files` onto it |
| `attendances` (same session) | row with a status beats empty; both → K's |
| `team_players`, `competition_players`, `game_players` | K's unless K's row is empty |
| `injury_notes` (player, branch, start_date) | K's |
| `player_academic_years` (same year) | K's; B's records for periods K lacks move in |
| `training_group_members`, `player_other_positions`, `activity_player` | duplicates dropped, rest moved |
| `player_subscriptions`, `player_achievements`, `player_emergency_contacts`, `suspensions`, `game_events.player_id` / `related_player_id`, `board_members` | repointed |
| Polymorphic: `activity_logs.subject`, `equipment_rentals.rentable`, `inventory_session_participants.participant`, `payment_orders.beneficiary`, `transactions.related_entity` | repointed when type = Player |

After moving: apply chosen field values to K, recompute K's `outstanding_debt`
through the existing finance service, delete B (cascades now find nothing).
The full table list is verified at plan time against the live schema
(`pragma foreign_key_list`) so a missed FK fails a test, not production.

Backup: existing `BackupService` before the transaction; backup failure aborts the merge.

## Error handling

- Missing right → 403 (route-name permission middleware).
- Same player on both sides → validation error.
- Any exception inside the transaction → rollback, flash error, nothing deleted.

## Tests

- Normaliser / scorer: Arabic variants, swapped names, phone formats, gender penalty, floor 50.
- Candidate finder: buckets, decisions excluded from Pending, criteria AND filter, min score.
- Dismiss / undo.
- Each clash rule with factory-built pairs.
- Full merge: no row anywhere references B (schema-driven assertion), field choices
  applied, debt recomputed, decision + activity log written, backup called, rollback
  on failure.
- Access: page + actions refused without the right.

## Out of scope

- Merging 3+ players in one step (merge pairs one after another).
- Automatic merging without review.
- Detecting duplicates at player creation time.

## Docs

Help Center article (ar + fr + en twins) for finding and merging duplicates.
