# Branch registrations, join dates and anniversaries: architecture and migration plan

**Status: approved by the owner 2026-11-03. Release 1 plan: `docs/superpowers/plans/2026-11-03-branch-registrations-r1.md`.**
Date: 2026-11-03. Baseline: `main` at `e3e030e`.

No "previous audit" exists in the repo, specs, memory, git or past session logs. The owner chose on 2026-11-03 to plan from today's code, so this document is the architecture and there is nothing else to merge with.

Stack stays as it is: Laravel 13, Vue 3 with Inertia, Eloquent, SQLite on desktop and dev, PostgreSQL on the server (owner, 2026-11-05; this line first said MySQL family, when the production database was unknown). No new dependencies and no new state library. Forms get their data through Inertia props, as they do today.

## 1. What the code and data show

Figures are from the dev database, which holds the same 217 players as the restored data. Re-run them on a production copy before release 1.

| Area | Today | Consequence |
|---|---|---|
| Registration | `branch_player` pivot, composite key, 226 rows; 208 players in 1 branch, 9 in 2, 0 in none | This table already is the registration. Extend it; a second table would duplicate it |
| Pivot readers | About 20 raw `branch_player` queries; `Player::branches()` used in 19 app files | They keep working if the table name and its two key columns stay |
| Pivot writers | `sync` and `detach` in `PlayerController` (lines 394, 472, 604-606) and `BranchController::syncPlayers` | **Danger:** once registrations hold data, these would silently delete it. All must be replaced |
| Category | 5 shared rows, unique name. `category_id` in 78 app files, 30 JS files, 126 test files | Largest job; readers must move before categories split |
| Swim rows tied to a category | 0 sessions, 0 schedules, 0 teams, 1 category caisse (id 27), 1 subscription price (sub 5) | Splitting is cheap on data, expensive on code |
| Position | `positions.branch_id` already exists, all 11 are football. The main position is on the player | The 9 two-branch players' positions belong to their football registration. Swim registrations get none |
| Other positions | `player_other_positions`; the position implies its branch | Reuse as is, no new table |
| Join year | `players.join_year` on all 217, in 13 app files and 65 test files | Becomes legacy data on the registration |
| Trustworthy dates | None. 153 `created_at` values fall on 2 import days | **Every existing registration starts with no exact date** |
| Membership ID | `YYYYNNNNN`, `string(10)`, unique, in 30 app and 13 JS files. **26 IDs have a year that differs from `join_year`** | See 3.3 |
| Status, leave date | `players.status_id`, `left_at`, in 19 and 12 app files. `Player::booted()` and `leaveDateUpdate()` hold the rule | Rule moves to the registration |
| Documents | `player_documents` unique on player and type, 153 rows (5 for two-branch players, all received), 0 files | Owner chose per-registration tracking. Touches `DocumentChecklist` (362 lines, SQL twins), `DocumentStats`, `PlayerFlags` |
| Association founding date | `website_configs.founding_date` = 1994-07-15 | Reuse it; no new column |
| Reminders | No scheduler, no real mail, desktop offline | Reminders are in-app |
| Hardcoded sports | Only game rules in `Game`, `PitchDrawing`, `LineupBoard`, keyed by game format | No branch has privileges. These stay. `BranchChoice` defaults to the lowest id and should use the main branch |

## 2. Owner decisions, 2026-11-03

| Topic | Decision |
|---|---|
| Baseline | Plan from the code |
| Categories | Each branch owns its list (`categories.branch_id`) |
| Status | Each registration has its own status and leave date. The player counts as left only after leaving every branch |
| Membership ID format | `BRANCHCODE-YEARJOINED-NNNNN`, one per registration. **Replaces** the 2026-10-07 format |
| Existing IDs | Converted to the new format; the old value is kept and stays searchable |
| Correcting a join year | The ID follows the year and gets a new number; the change is logged. Keeps the 2026-10-07 rule |
| Two-branch players' single year | Same year on both registrations, flagged, first in the correction queue |
| Sporting status | New separate optional list. Today's 7 statuses stay as membership status |
| Reminders | In-app only, dismissable once a year |
| Documents | Tracked separately per registration |
| First branch for a player with none | Anything the card leaves empty comes from the player; the registration takes the position if that branch owns it or no branch does, and a position another branch owns moves to the other positions so nothing is lost. The year the player holds is kept as legacy_join_year. Decided during Release 1 |
| Rejoining a branch | Reopens the same row and keeps the original join date and ID; status and leave date clear. Decided during Release 1 |
| Date shared by bulk edit or the Members dialog | Applies only to a genuinely new registration. A rejoiner, or the first registration of a player who holds a year, takes none and enters the queue. Required only when someone selected takes it, and not before their birth date. Decided during Release 1 |
| Bulk status | Bulk edit asks which branch the status applies to; other branches keep their status and leave date. Decided during Release 1 |

## 3. Target data model

### 3.1 `branch_player` (model `App\Models\BranchRegistration`)

The table name stays, so every existing raw query keeps working. `Player::branches()` and `Branch::players()` gain `->using(BranchRegistration::class)`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | New. The table is rebuilt (create, copy, drop, rename), which behaves the same on SQLite and MySQL |
| `branch_id`, `player_id` | FK | Unique together; they replace the composite key |
| `membership_id` | string(24), unique, nullable | See 3.3. A two-branch player's second registration has none until conversion |
| `legacy_membership_id` | string(10), nullable, indexed | The old ID, searchable |
| `join_date` | date, nullable | Source of truth. `NULL` means **needs verification**; no separate flag that could drift |
| `legacy_join_year` | smallint, nullable | Year-only value from migration or import. Never turned into a date |
| `category_id` | FK, nullable | Restrict on delete. Nullable because 2 players have none today |
| `position_id` | FK, nullable | Main position, optional |
| `licence_number` | string, nullable | Federation licence |
| `status_id` | FK, nullable | Membership status |
| `left_at` | date, nullable | Same rule as today: set only when the status is `left` |
| `sporting_status_id` | FK, nullable | New list |
| timestamps | | |

Indexes: `(branch_id, join_date)`, `(join_date)`, `(category_id)`, `(status_id, branch_id)`, `(player_id)`, `(legacy_join_year)`.

`scopeNeedsJoinDate()` is `whereNull('join_date')`. Year, month and day are always derived.

The month and day of a date cannot use an index without a generated column. Anniversary queries filter on `(branch_id, join_date)` and `SUBSTR(join_date, 6, 5)`, a scan of a few thousand rows at most. The test in section 9 holds this to the existing budget.

### 3.2 Other tables

**`branches`, new columns:** `code` (2 to 6 Latin capitals, unique, locked once any ID uses it; this is the brief's "unique identifier", so there is no separate slug), `sport_type` (free text, so a new sport needs no code), `founded_at` (date, nullable), `is_active`, `uses_licence`, `position_term` (`position` or `discipline`; the card's wording), `requires_category`.

**Main branch:** `website_configs.main_branch_id`, a nullable FK. One column guarantees at most one main branch without a custom constraint. `Branch::is_main` is computed. The migration sets it to the branch with the most players, which is football today. Nothing checks it except defaults: which registration is primary, which branch opens first.

**`categories`:** adds `branch_id` (required after the split), `is_active`, `sort_order`. Unique name becomes unique `(branch_id, name)`.

**`positions`:** adds `is_active`, `sort_order`. A branch with no positions shows no position field.

**`sporting_statuses`:** same shape as `player_statuses`, editable by admins, starts with no rows chosen on existing registrations.

**`document_type_branch`:** `document_type_id`, `branch_id`, `is_required`. A type linked to no branch applies to all, using its own `is_required`, the same convention as `branch_subscription`. Today's 7 types need no change.

**`player_documents`:** adds `branch_registration_id`. Unique becomes `(branch_registration_id, document_type_id)`. `player_id` stays for existing readers.

**Custom fields:**
- `branch_custom_fields`: `branch_id`, `code`, `label`, `label_ar`, `label_fr`, `label_en`, `type` (`text`, `number`, `date`, `boolean`, `select`, `multiselect`), `is_required`, `is_active`, `sort_order`, `rules` (JSON limited to `min`, `max`, `max_length`, `min_date`, `max_date`), `default_value`. No free regex.
- `branch_custom_field_options`: `field_id`, the same labels, `is_active`, `sort_order`.
- `branch_registration_field_values`: `registration_id`, `field_id`, `value_text`, `value_number`, `value_date`, `value_boolean`, `option_id`. One row per chosen option for a multi-select. Typed columns keep values searchable with no JSON.
- A field's type locks once it has values. A field or option with values can be deactivated but not deleted.

**`anniversary_dismissals`:** `subject_type` (`registration`, `branch`, `association`), `subject_id` (0 for the association), `anniversary_year`, `dismissed_by`, timestamp. Unique on the first three.

### 3.3 Membership IDs

Format: `{code}-{year}-{NNNNN}`, for example `FB-2022-00017`. Numbering is per branch and year.

- **New registration:** issued from the branch code and the join date's year.
- **Year correction:** new number under the new year, written to the activity log. Adding the day within the same year changes nothing.
- **Reuse (owner decision, 2026-11-03):** a new number is the lowest one free in that branch and year. A number freed by deleting a registration or correcting a year is issued again, so an old printed card can match a later member. A left member keeps their number. Conversion keeps its own rule below.
- **Search:** matches `membership_id` and `legacy_membership_id` on any registration.
- **Display:** a page about a branch shows that branch's ID. Club-wide lists, PDFs and receipts show the primary registration's ID through `players.membership_id`.

**Conversion is a separate reviewed action, not part of the migration**, because the codes must be chosen first. Migration gives every registration its old ID unchanged and suggests a code the admin can edit. A superadmin screen then previews the result and converts:
- Year comes from `join_year`, as the format says "year joined". The **26 IDs whose year differs from `join_year` change year**; the preview lists them.
- The number is kept when free in that branch and year, otherwise the next free one.
- A two-branch player's primary registration keeps the number; the second gets the next free one.
- The action can be run again safely and can be undone from `legacy_membership_id`.
- Registrations created before conversion already use the new format.

### 3.4 Primary registration and the mirror

Until release 5, `players.join_year`, `category_id`, `position_id`, `licence_number`, `status_id`, `left_at` and `membership_id` stay and are written **only** by `RegistrationService`, copying from the primary registration. Readers not yet moved keep working.

- Primary: the main branch's registration if not left, otherwise the earliest joined not left, otherwise the lowest id.
- Overall status: `left` only when every registration is left, with the latest `left_at`. Otherwise the primary's status.
- `join_year`: the earliest effective year.

**No release has two writers.** The player form, bulk edit, import and `syncPlayers` switch to `RegistrationService` in the same release.

### 3.5 Removing someone from a branch

Replaces every `sync` and `detach` on this table. A removal marks the registration as left, keeping its documents, values and ID. A hard delete is allowed only when the registration has no documents, values or subscription obligations and only by explicit action, for a registration added by mistake. Rejoining reopens the same row, one row per player and branch, keeping the original join date and ID.

## 4. Join date migration

1. Back up. On a production copy, run the migrations twice, on MariaDB and SQLite.
2. Copy each row's `players.join_year` to `legacy_join_year`, on both registrations for the 9 two-branch players. `join_date` stays `NULL` for all 226.
3. Copy category, licence, status and `left_at` to each registration. Copy the main position only to the registration whose branch owns that position.
4. `players.join_year` stays until release 5.

**Never** set January 1, and never derive a date from `created_at`.

**Correction queue**, under Players: all registrations with no exact date, two-branch players first, with branch, category and year filters. Inline date entry, saved on Enter. As a hint only, it shows the date the record was entered in the app when the year matches (true for 35 of the 38 joiners of 2026); an admin must confirm it.

**New registrations:** `join_date` is required, not in the future, not before the birth date. A date before the branch's founding date is a warning, not an error, since founding dates may be approximate. Validation lives in Form Requests, while the column stays nullable for legacy rows.

**Spreadsheet import:** a full date is stored as `join_date`; a year alone becomes `legacy_join_year` and enters the queue; an empty value is a row error, where today it defaults to the current year.

**Dates in code:** cast `date:Y-m-d`, send plain `Y-m-d` strings for this column, and never pass them through `new Date()` in the browser. `SerializesClubDates` already handles other dates.

## 5. Statistics

New reports in the existing Reports Center (`app/Reports/Registrations/`), using `ReportFilters`, `StatTable`, `ChartCard` and `StatTile`. All aggregation is in SQL, with no player models loaded.

Filters: date range, year, month, branch, category, registration status.

| Measure | Rule |
|---|---|
| Effective year | `COALESCE(SUBSTR(join_date,1,4), legacy_join_year)`. **Without this, every yearly figure would be empty until all dates are corrected**, which would break existing statistics |
| Yearly | Effective year, with a count of year-only rows beside the total |
| Monthly, anniversaries, average seniority | Exact dates only, with a visible note: "N registrations have no exact date and are not counted here" |
| Registrations and people | Both shown. A new member is counted once, on their first registration; the 9 two-branch players appear once in club totals |
| Seniority | Join date to `left_at` or today. Date difference is not portable, so it lives in one small driver-aware helper |
| Cohorts and retention | By effective year, from join and leave dates |
| Multi-branch | `GROUP BY player_id HAVING COUNT(*) > 1` |

`PlayerMovement`, `PlayerDirectory`, `MembersSection`, the filter year lists and the player CSV move off `join_year`. A test pins that per-year people counts match the figures before migration.

**Limits, stated honestly in the UI:**
- **Returning members are not reported.** The data has no history of leaving and rejoining.
- **Past active and inactive by year** can only use join and leave dates. Earlier pauses or sanctions cannot be reconstructed.
- The 9 two-branch players inflate swimming's early years until corrected.

## 6. Anniversaries

`App\Services\Anniversaries\AnniversaryCalendar`, pure date logic with no database, unit tested.

- **Registration anniversaries:** exact dates only, active registrations only, per registration. A year-only row has none and is counted in a note.
- **Feb 29:** observed on Feb 28 in non-leap years, Feb 29 in leap years.
- **Milestones:** 1, then every 5 years. A constant, not a setting.
- **Founding anniversaries:** branch `founded_at`, association `website_configs.founding_date`, with age and next date. This keeps the existing column instead of the brief's `founded_at`, which would duplicate it. Each is a separate subject type, so they cannot be confused with player dates. Branches have no founding date until an admin enters one.
- **Screens:** a dashboard card (today, next 7 days, count this month) and report pages (today, month, upcoming, milestones), behind `players.view`.
- **No duplicates:** nothing is sent. Reminders are computed when viewed, and a dismissal is stored once per subject and year, for the whole club. The unique key makes a repeat impossible.

## 7. Screens

**Settings → Branches** (existing `Settings/Branches.vue`, grown into a list plus one detail page per branch with tabs): general (code, sport, founding date, main, active, logo), categories, positions or disciplines, documents, custom fields. The existing `Settings/Categories` and `Positions` pages become redirects to it. The existing per-list role rights stay.

A new active branch appears in player forms, filters and reports automatically, since all read from the table. An inactive branch is hidden from new registrations and stays in reports. The main branch must be reassigned before it can be deactivated or deleted.

**Player form:** the personal section stays. The branch picker adds one `RegistrationCard.vue` per branch: join date, category, main and other positions, licence, membership status, sporting status, documents, custom fields. A card shows only what that branch configured. Options come from a `branchConfig` Inertia prop.

Known traps in this form: `forceFormData` drops empty arrays, so clearing a list must send `''` as the form does today, and field names must avoid `useForm` reserved names such as `progress`. Custom fields are nested under `registrations[i].fields[code]`.

**Player page:** one tab per registration, using the existing `BranchSwitch`.

**Documents:** `PlayerDocumentsCard` works per registration. **A two-sport player must deliver documents for each branch.** The 5 existing documents go to the primary registration, and the swim registration starts as missing. The automatic Photo rule needs checking against the player's picture during release 2.

## 8. Rollout

Built in a worktree, one branch per release, each merged only after owner approval. No desktop build unless the owner asks.

**Release 1, Release 2 and the category split reach production together** (split added by the owner, 2026-11-05; `2026-11-05-branch-registrations-r2-design.md` holds the details and overrides this document where they differ). Found while planning Release 1: until readers move, a player who leaves one branch but stays in another still appears in the branch they left, and readers see only the primary registration's category. Each other release can ship alone.

| Release | Content | Depends on |
|---|---|---|
| **1 Foundation** | Table rebuild, backfill, sporting statuses, `RegistrationService` as sole writer, mirror, removal rule, branch code and main branch fields, player cards with the shared categories, correction queue, ID format and conversion screen, `registrations:verify` | |
| **2 Readers** | Move from `players.*` to the registration, by batch: (a) subscriptions and finance, (b) attendance, (c) teams and competitions, (d) documents to per-registration, (e) lists, exports, imports, dashboards, literary report. Then the **category split** and a branch page with a Categories tab | 1 |
| **3 Branch admin** | More branch page tabs, active flags, per-branch documents, custom fields | 2 |
| **4 Stats and anniversaries** | Sections 5 and 6 | 1 only, so it can go earlier if preferred |
| **5 Contract** | Drop the mirror columns and old key paths. Separate approval, at least one release after 2 and 3 are verified on production | all |

**Why the category split comes after the readers:** if categories split first, a two-branch player's mirror points at football's Senior and they drop out of swim Senior rosters. Once readers use the registration's own category, the split is only a data change.

**Category split (release 2, see its spec):** the main branch keeps today's rows and IDs, so its foreign keys don't move. Each other branch gets copies of the categories its registrations use. Then swim registrations, branch-tagged sessions, schedules and teams, swim caisses (id 27) and subscription prices are repointed. An untagged subscription gets a price row for each copy, so amounts don't change. Pickers use the existing `positionLabeller` approach ("Senior · Swimming"). The split creates no caisse: a category never creates one (`CashRegisterProvisioner`), which corrects an earlier claim here about `CategoryObserver`. `users.category_id` (1 row) stays on the main branch's row.

**Rollback:** every release has a pre-migration backup and working `down` methods. Nothing is dropped before release 5.

## 9. Verification

Each release passes the full PHP suite (2322 today, `ManualSuspensionsTest` already fails on date), JS tests, `npm run i18n:check` and a build, then browser QA on the real forms.

**`registrations:verify`** reports on real data and exits non-zero on failure: every pivot row migrated, `legacy_join_year` equals the old value, IDs unique, the mirror matches the primary, no `join_date` was set by migration, per-year people counts unchanged.

**Migration tests:** two runs change nothing; MariaDB and SQLite; an upgrade from the 2.4.2 database and a fresh install; a restored old backup; 0, 1 and 2 branches; null category and position; leave dates; two-branch documents.

**Anniversary tests:** Feb 29 in leap and non-leap years, Feb 28 not doubled, Dec 31 to Jan 1 for "upcoming", year-only excluded, left excluded, a dismissal hiding this year only, two branches giving two anniversaries, the club's timezone at midnight.

**Other tests:** two branches with different dates, categories and statuses; a category not offered to another branch; zero positions; custom field validation per type; removal keeping data; ID change on year correction only; search by old ID; query budget at 1,920 players.

## 10. Not included

Returning-member history, a leave and rejoin log, email or push reminders, admin-set milestones, regex rules on custom fields, per-branch role rights.
