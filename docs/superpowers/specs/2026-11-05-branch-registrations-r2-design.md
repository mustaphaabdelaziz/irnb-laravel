# Branch registrations, Release 2: readers and category split

**Status: approved by the owner 2026-11-05. Plan: `docs/superpowers/plans/2026-11-05-branch-registrations-r2.md`.**
Baseline: `feat/branch-registrations-r1` at `8fe9643`. Parent spec: `2026-11-03-branch-registrations-design.md`, which stays in force except where this document changes it. Its database line, release table and caisse claim are corrected to match.

## 1. Goal

Release 1 stores category, status, leave date, position, licence and ID per registration, but everything else still reads one copy of those values on `players`, and checks branch and category separately. This release makes every reader use the registration, then gives each branch its own categories.

The category split moves out of Release 3, at the owner's request after testing Release 1. Release 1, Release 2 and the split reach production together.

## 2. Facts this design rests on

From a read of the code and the Release 1 test copy on 2026-11-05. Re-run the figures on a production copy before deploying.

| Fact | Consequence |
|---|---|
| Production runs **PostgreSQL** (owner, 2026-11-05) | Nothing has ever run on PostgreSQL. See section 10 |
| 226 registrations, 9 two-branch players, 3 branches | Football 207, swimming 19, volleyball 0 |
| Football: Senior 93, Ecole 37, Minime 32, Cadet 29, Junior 14, none 2. Swimming: Senior 18, Junior 1 | The split creates two categories: swimming Senior and Junior |
| All 4 subscriptions have a branch ticked. All 9 two-branch players already pay football and swimming separately | No existing charge changes |
| Sessions: 12, all football. Teams: 1, football. Schedules, preseason targets and activity categories: 0 | Little data to repoint |
| Category caisses: football Senior, swimming Senior | The swimming Senior caisse moves to swimming's new Senior |
| `users.category_id` has no reader | Stays on the main branch's row. Not repointed |
| `CategoryObserver` only blanks a caisse's category on delete; `CashRegisterProvisioner` says a category never creates a caisse | The split creates no caisse |
| `main` merges into Release 1 with no conflicts (`git merge-tree`, at `9cb9661`) | Step 0 merges it |

## 3. Owner decisions, 2026-11-05

| Topic | Decision |
|---|---|
| Who a subscription applies to | Branch and category must match **one active registration**. Hand-adding outside the category stays |
| Subscription with no branch | Owed **once per person**, at the main registration's category price. To charge per sport, create one subscription per branch |
| Debt on leaving a branch | Stays owed. Leaving never cancels money. New charges for that branch stop |
| Rosters after leaving | Listed on unmarked sessions up to the leave date, not after. Marked sessions stay frozen |
| Team roster after leaving | Stays on the roster flagged as left, can't be called up, isn't copied to next season. Nothing is deleted |
| Match "unpaid" badge | That sport's debts only |
| Players list | One row per person. No branch filter: the main registration's values. A filter on one branch: that branch's values |
| Category charts | Club-wide, a two-branch player counts in both bars, and the chart says bars can exceed the total. Total members counts them once |
| Member card | A two-branch player's card asks which branch, and shows that branch's category and ID |
| Receipt | The subscription's branch; otherwise the main registration |
| Photo document | One profile picture settles Photo on all the player's branches. Other documents stay per branch |
| Category split | The main branch keeps its rows. Other branches get only what they use |
| Managing categories | A Categories tab on a branch page. The old page sends people there |
| Repeated names where no single branch applies | The sport is added only when the name repeats: "Senior · Swimming" |
| PostgreSQL | Checked as part of this work, not before planning it |
| Delivery | Built on top of Release 1. `main` stays deployable. All merge together |

Also approved in the presented design: the caisse for a payment, the category on an attendance mark, a category must belong to its branch, the Excel import rule, new clubs with no branches, and branches with no categories. Each is detailed below.

**Definitions used below.** *Active* registration: its status is not `left`. *Main* registration: `RegistrationService::primary()`.

## 4. The shared query

One place answers "is this player in this branch, with this category and status, on this date". No reader writes that rule itself.

- `BranchRegistration` scopes: `active()`, `activeOn($date)` (not left, or `left_at` on or after the date), `inBranches($ids)`, `inCategories($ids)`, `withStatuses($ids)`.
- `Player::scopeRegisteredWhere(?branches, ?categories, ?statuses, ?activeOn)`: one `EXISTS` holding **all** the given conditions on the same row. This replaces `whereIn` on a mirror column plus `whereHas('branches')`.
- `Player::registrationIn($branchId)`: the registration to display on a branch's page, from an eager-loaded `registrations` relation, so no query per row.
- **A player with no registration at all** (a club with no branches) has its values only on `players`. The scope and accessors fall back to those columns for such a player. Every test group covers this case.
- No lower bound on `join_date` anywhere in this release: all 226 rows have none.

Aggregates stay in SQL. A query budget test covers the Players list, the session roster and the dashboard at 1,920 players.

## 5. Subscriptions and finance

**Eligibility** (`Subscription.php`, `Player.php`, `SubscriptionController.php`, `PlayerTransactionController.php`, `PlayerController.php`), using active registrations only:

| Subscription has | Applies when |
|---|---|
| Branches and categories | One registration has both |
| Branches only | A registration in one of them |
| Categories only | A registration with one of them |
| Neither | Any registration |

Bulk assign, the available list and the player page's catalog all use this. The outside-category list shows active members of the subscription's branches not matched above; a player who left that branch is no longer listed.

**Price:** the category price of the matching registration. If several match, or the subscription has no branch, the main registration's. The existing fallbacks stay: sole category's price, then default. A subscription ticked for two branches is treated like one with no branch: **one charge per person**, as the duplicate check at `SubscriptionController.php:427` does today. `player_subscriptions` gets no new column.

**Leaving:** `leave()` touches no charge. `PruneOutOfBranchObligations` keeps counting a left registration as in the branch, so it can't delete that debt.

**Caisse for a payment** (`DefaultRegisterResolver`): the subscription's branch if it has exactly one; otherwise the main registration's. Never a branch the player left, and never "first row, no order". This is only the caisse preselected on the form (owner, 2026-11-05): the cashier may pick any active caisse, and that choice is kept. An old debt from a branch the player left preselects that branch's caisse.

**Reports and dashboards:** `ReadsObligations` and `OutstandingPayments` filter through the shared query and show the registration's category and ID. `BranchScope` keeps counting left registrations, since their debt is still owed. `TarificationData` reads each registration's category; whether it counts left players stays as it is today, and that behaviour is reported to the owner as a question.

**Receipts** (`ReceiptData`, `ReceiptPrinter`, both PDFs): the branch of the subscription if it has exactly one; a grouped receipt uses it only if all lines share it; otherwise the main registration.

**Not touched:** `FixSubscriptionBilling`, a one-time command for existing data.

## 6. Attendance

- `Roster::expected`: players with one registration in the session's branch whose category is one of the session's categories (primary or pivot), active on the session's date. A session with no branch matches any registration. The comment "a player has one category" goes.
- Saved marks stay frozen: `Roster` and `MonthSheet` keep preferring them, and `leave()` doesn't touch them.
- `MarkRecorder`: a new mark stores the category of the player's registration in the session's branch. A player added by hand with no registration there stores the main registration's. Old marks don't change.
- Add-by-hand candidates and `in_branch` (`AttendanceController`): use active registrations.
- Category tags on rows (`AttendanceController`, `AttendanceSheetController`), `PlayerNames`, at-risk, injuries, ranking and certificate eligibility: the registration in the branch being viewed. A player who left that branch is not active there.
- Branch tabs on the player's attendance page: every registration, so history stays reachable. The preseason target per tab uses that tab's category.

## 7. Teams and matches

- Roster candidates and the "in sport" check (`TeamController`, `Teams/Show.vue`): active registration in the team's branch. The category filter uses that registration.
- A left player's row carries a `left_branch` flag and shows a "left" mark.
- `CallUpCandidates` leaves them out, and the server refuses a call-up that names one. A call-up made before they left stays. Match history is never changed.
- `TeamRoster` season copy skips them.
- `PlayerFlags`: licence from the team's branch registration. Unpaid: charges whose subscription has the team's branch ticked, or has no branch ticked. A charge whose subscription was deleted counts, because its sport can't be known and the money is still owed. Today's `outstanding_debt` total is not used here.
- Usual position in the lineup (`MatchPage`) and category displays: the registration in the team's branch.

## 8. Documents

Follows parent spec sections 3.2 and 7.

- `player_documents` gets `branch_registration_id`, nullable only for players with no registration. Unique becomes (`branch_registration_id`, `document_type_id`). `player_id` stays. A foreign key on every database (owner, 2026-11-05: "protect it"), `ON DELETE SET NULL`, so removing a registration never takes a document or its files with it. SQLite gets it from `ALTER TABLE ... ADD COLUMN ... REFERENCES`, which needs no table rebuild, so document files are not at risk. `registrations:verify` still reports a row with no registration. A blank value escapes the unique key, so the writers hold that rule for a player with no registration.
- Existing rows go to the main registration. **Those 9 players' swimming documents start as missing**, as the owner chose; the report to the owner says so.
- `document_type_branch` is not in this release, so every type applies to every branch.
- `DocumentChecklist` and its SQL twins work per registration. **Photo:** a player with a profile picture has Photo received on every registration.
- A left registration counts as nothing missing. A club-wide "documents missing" badge or filter means missing on any active registration; with a branch selected, on that branch's.
- `PlayerDocumentsCard` takes a registration. The player page shows one tab per registration through `BranchSwitch`, as the parent spec planned.
- Writers and readers to change: `PlayerDocumentService`, `PlayerDocumentController`, `DocumentStats`, `PlayerFlags`, `MemberStats`, `PlayerDocuments` report, and the document filters in `PlayerController`, `TeamController`, `GameController`, `CompetitionController`.

## 9. Lists, exports, imports, dashboards, reports, cards

**Filters:** every list with branch, category or status uses the shared query (`PlayerController`, `FiltersPlayers`, `PlayerDirectory`, `ReadsObligations`). So Swimming + Senior can no longer match a football Senior.

**Displayed values:** exactly one branch selected shows that registration's values. None or several selected shows the main registration's. This covers the list rows, export, print list, Directory and Movement. Report tables with no branch filter show main values.

**Counts:**

| Figure | Rule |
|---|---|
| Total members, people counts | Once per person |
| Club-wide category charts (`byCategory`, `leftByCategory`, Directory, Demographics, `MemberStats`, `DocumentStats`) | Per active registration, with a visible note that bars can exceed the total. Left-by-category counts left registrations |
| Status charts | Club-wide: once per person, overall status (left only when left everywhere). With one branch: that registration's status |
| Left, club-wide | Left everywhere, as `RegistrationService::overallStatus` says. With a branch selected: left that branch |

**Join-year figures move in Release 4**, as the parent spec says. Until then, a second-branch join stays uncounted in Movement and the literary report.

**Literary report:** branch rows use that branch's registrations, so they stop showing the primary's category.

**Member card:** a two-branch player's button asks which branch; a `branch` parameter chooses the registration. One active registration: no question. `attendance-player` and `academic-report` PDFs follow the branch being viewed, otherwise main values.

**Player page ID:** the selected tab's registration.

**Spreadsheet import:** see section 11. A second-sport row for an existing person still creates a second player; that is today's behaviour and stays.

**`ImportMongoJsonData`:** writes the mirror and creates no registration, so it can't stay as it is. The plan checks whether anything still runs it. If nothing does, the owner is asked about removing it; otherwise it moves to `RegistrationService`.

## 10. PostgreSQL

A private instance on a scratch data directory and its own port, never the machine's running `postgresql-x64-18` service. Production's real data isn't available here, so a script copies the test copy's rows into a PostgreSQL schema migrated to the same version.

**Step 0 runs first and only reports:** Release 1's migrations, `registrations:verify`, then every GET page and export compared with SQLite. It also checks these concerns, found by reading the code:

- `BackupService` only knows the SQLite file and has no driver check. Online backups may not work at all.
- 23 searches use `'like'`, which is case-sensitive on PostgreSQL. `mohamed` may not find `Mohamed`.
- 154 raw SQL fragments in `app/`.
- Comparing a `date` column to text, and `SUBSTR` on a date (parent spec's anniversary query; Release 4).

Findings go to the owner, who decides. A page failing online today is a fix for `main`, on its own branch, not something to hold back for this release.

**Step 8 repeats it** on the finished work, and adds the migrations and the suite. Every new query and migration in this release must run on SQLite, MariaDB and PostgreSQL. Player search, including every membership ID, ignores letter case on all three, because this release makes every ID Latin and PostgreSQL's `LIKE` is case-sensitive (owner 2026-11-05). The other searches are a separate job.

## 11. Category split

**Schema:** `categories` gets `branch_id` (nullable, see below; a foreign key on every database, `ON DELETE RESTRICT`; SQLite gets it from `ALTER TABLE ... ADD COLUMN ... REFERENCES`, which needs no table rebuild, so its children are not at risk) and `is_active`. Unique name becomes unique (`branch_id`, `name`). The parent spec's `sort_order` waits for the later branch-admin release, because nothing here needs it; lists keep sorting by name.

**Migration:**

1. Every category goes to the main branch, keeping its ID. A club with one branch stops here. A club with no branches leaves all of them blank.
2. For each other branch, find the categories used by its registrations, sessions, schedules, targets, deleted slots, teams, caisses or activities, or priced on a subscription ticked only for that branch (owner 2026-11-05: "Piscine 2026" ticked only for swimming, with a Cadets price and no swimming Cadets yet, brings Cadets to swimming). A price on a subscription ticked for several branches, or none, causes no copy by itself. Copy each, with all its names and description.
3. Repoint that branch's rows to the copy: registrations, sessions and the session pivot, schedules, targets, deleted slots, teams, caisses, activity pivot, attendance marks through their session. Generation marks for a repointed schedule's category are copied, so no month is generated twice.
4. Prices in `category_subscription`: a subscription ticked only for that branch has its row moved to the copy. A subscription ticked for several branches, or none, gets a row for the copy with the same amounts. So no amount changes.
5. Refresh the mirror for players whose main registration moved.

It can run twice with no change. `down` merges copies back by name onto the main branch's row.

**A category must belong to its branch.** Enforced on registrations, sessions (all categories of a joint session), schedules, targets, teams and caisses. Subscriptions and activities: a category must belong to a ticked or chosen branch, if any. A stored value that no longer fits stays until edited, as positions do. A session with no branch takes any category.

**Branch page.** `Settings/Branches.vue` stays a list and links to a new page per branch with two tabs: **General** (today's edit form) and **Categories**. Custom fields and documents tabs arrive later. Rights stay `categories.*`, and the tab shows only with `categories.view`.

- `/categories` redirects to the main branch's tab. With no branches it stays the page it is today.
- Add, edit, deactivate and delete happen on the tab. Delete keeps today's refusals. A category can't move to another branch.
- Deactivated: hidden from pickers for new choices, kept where stored, still in reports.
- Deleting a branch: refused as today while it has registrations; its unused categories go with it. Used ones refuse the delete.

**Pickers.**

- Where a branch is known (registration card, attendance, sessions, schedules, teams, caisses), only that branch's categories are listed, plus the stored value. The server checks too. The calendar's default category is the first of that branch.
- The subscription form lists those of the ticked branches, or all if none is ticked.
- Where no single branch applies (Players filter and bulk edit, report filters, chips, charts, team labels), names get the sport when repeated. `positionLabels.js` becomes a general labeller used for both.
- A branch with no categories hides the field on its card and doesn't require it.

**Excel import:** a category name is looked up within the row's branch; a row with no branch uses the main branch. A name that branch doesn't have is a row error, never created silently.

**New clubs:** with no branches, categories have no owner. `CategorySeeder` still seeds them. **The first branch created takes all unowned categories.**

## 12. Release 1 leftovers fixed here

From the Release 1 final review:

- A form opened before a registration was added elsewhere marks that registration left on save. Absent cards must not mean "left" without an explicit removal.
- The activity log doesn't name the player on queue saves and registration deletes.
- The bulk message counts players it skipped.
- The Members dialog stamps today on a left player with no leave date and no branch.
- A form save adding a first card and a second-branch card together drops a position owned by the second branch.
- A card's ID header stays blank after a delete gives the next row an ID.
- `primary()` runs one query per player.

Fixes for what the owner finds while testing Release 1 go on `feat/branch-registrations-r1`, then merge up.

## 13. Build order

Branch `feat/branch-registrations-r2`, worktree `D:\irnb-branch-reg-r2`.

| # | Part | Sections |
|---|---|---|
| 0 | Merge `main`; PostgreSQL first look; report | 10 |
| 1 | Shared query | 4 |
| 2 | Subscriptions and finance | 5 |
| 3 | Attendance | 6 |
| 4 | Teams and matches | 7 |
| 5 | Documents | 8 |
| 6 | Lists, exports, imports, dashboards, reports, cards, leftovers | 9, 12 |
| 7 | Category split, branch page, pickers | 11 |
| 8 | Full check, the owner's browser test, then merge if the owner approves | 10, 14 |

Readers (1 to 6) must finish before the split (7), or two-branch players drop out of rosters. Each part leaves the suite passing.

## 14. Verification

**Each part:** PHP suite, JS tests, `npm run i18n:check`, build.

**Tests for every rule above**, using the football Senior and swimming U15 player, in each of: both active; left football; left both; no registration at all. Also: zero, one and two branches; same-named categories in two branches; a category refused on the wrong branch.

**Pinned figures, on a copy of real data, before and after each part:**

- Total owed, paid and discount per subscription and per player: **identical**.
- Each one-branch player's eligibility, roster, list row and report row: **identical**, except where a decision above changes them on purpose:
  - A player who has left is no longer eligible for new charges (section 5).
  - A player is listed on an unmarked session on their leave date; the old code stopped the day before (section 6).
  - A left registration counts as nothing missing (section 8).
  - Category charts count active registrations, so people who left drop out (section 9).
  - The match "unpaid" badge counts that sport's debts only (section 7).
  - One-branch swimmers' category IDs move to swimming's copies; the names stay the same (section 11).
- Every difference a two-branch player shows is listed and traceable to a decision above.

**`registrations:verify` gains:** every category has a branch once any branch exists; each registration's category belongs to its branch; sessions, schedules, teams and caisses likewise; every document row has a registration, unless the player has none; price rows still give each player the same amount.

**Dry runs of the whole migration, twice each:** the desktop 2.4.2 database, the 2026-11-01 backup, MariaDB and PostgreSQL. Fresh install on all three drivers.

**Browser, on the real forms:** Arabic, French and English; light and dark; 320 px and 1280.

**Not possible here:** production's real PostgreSQL data. Rerun `registrations:verify` on a production copy before deploying, and take a fresh backup first. If `BackupService` can't back up PostgreSQL, the deploy needs `pg_dump`.

## 15. Rules while building

- French says "Section", never "branche". i18n changes are insert-only.
- No CDN or remote resources. No desktop build unless the owner asks.
- Never run against `%APPDATA%\sport-club`, `D:\irnb-laravel\database`, or the owner's test database in `D:\irnb-branch-reg`. Reading that copy read-only is allowed.
- Never `sync` or `detach` registrations. All writes go through `RegistrationService`.
- Don't stage `HANDOFF.md` or `.claude/`.
- Nothing merges or pushes without a fresh approval, following the "push only verified tip" rule.

## 16. Not included

Custom fields, `document_type_branch`, branch `sport_type`, `founded_at`, `is_active`, `uses_licence`, `position_term` and `requires_category`, position `is_active` and `sort_order`, statistics and anniversaries (Release 4), dropping the mirror columns (Release 5), fixing case-sensitive search or backups on PostgreSQL unless the owner approves after step 0.
