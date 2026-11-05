# Branch registrations, Release 2, Part 4: Teams and matches Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Team rosters, call-ups, match badges and match displays read the registration in the team's branch, and a player who left that branch stays on the roster flagged, can't be called up, and isn't copied to next season.

**Architecture:** One small helper, `App\Services\Competitions\TeamMembers`, holds the team-specific rules on top of Part 1's `RegistrationDisplay`: what "left this team's sport" means, and which category, position and licence to show. Controllers and services call it instead of reading `players.*`. The match "unpaid" badge stops using `players.outstanding_debt` and sums that sport's charges from `player_subscriptions` through `PlayerSubscription::moneyFor`.

**Tech Stack:** Laravel 13, Eloquent, PHPUnit attribute tests, Vue 3 with Inertia. Spec: `docs/superpowers/specs/2026-11-05-branch-registrations-r2-design.md`, section 7.

## Global Constraints

See the main plan `docs/superpowers/plans/2026-11-05-branch-registrations-r2.md`, which apply in full. Parts 0 to 3 are done when this part starts. Part-specific:

- **No match history changes.** No code in this part writes to `game_players`, `game_events` or `team_players` for a player because they left. A call-up made before they left stays.
- `team_players` rows are never deleted because a player left a branch.
- The document flag in `PlayerFlags` and the document filters in `TeamController`, `GameController` and `CompetitionController` belong to Part 5. Leave `DocumentChecklist::missingTypeSql` and `documentStats` as they are.
- Team labels (`$team->name.' · '.$team->category?->localized_name`) read the team's own category, not a mirror column. Part 7 handles repeated names.

**Checked and not changed, because they read no player mirror column:**

| File | What it reads |
|---|---|
| `app/Services/Competitions/ReportWarnings.php` | `game_players.position_id` (the slot in this match) |
| `app/Services/Competitions/TrainingCanceller.php` | `teams.category_id` and `teams.branch_id` |
| `app/Http/Controllers/GameLineupController.php` | `game_players` rows and positions of the team's branch. A player called up before leaving stays selectable, as the spec requires |
| `app/Http/Controllers/CompetitionSquadController.php` | `team_players`. A left player's squad row stays, and `CallUpCandidates` is what stops the call-up |
| `app/Http/Controllers/TeamRosterController.php` | `team_players`, through `TeamRoster` |
| `app/Services/Competitions/MatchSheets.php` | The team's category and `game_players` |
| `app/Http/Controllers/GameController.php:244-246` | `teams.category_id` |

---

### Task P4.1: The `TeamMembers` helper

**Files:**
- Create: `app/Services/Competitions/TeamMembers.php`
- Create: `tests/Feature/Competitions/TeamMembersTest.php`

**Interfaces:**
- Consumes: `RegistrationDisplay::make()`, `->values(Player, ?int)`, `->shown(Player, ?int)`, `->isActiveIn(Player, int)`; `Player::registrationIn(?int)`; `Player::leftStatusId()`.
- Produces: `TeamMembers::RELATIONS` (`list<string>`, eager-load these on the players); `TeamMembers::of(Team $team): self`; `->hasLeft(Player): bool`; `->isActiveMember(Player): bool`; `->categoryId(Player): ?int`; `->category(Player): ?Category`; `->position(Player): ?Position`; `->licence(Player): ?string`.

**The rules it holds:**

| The player has | `hasLeft` | `category` | `position`, `licence` |
|---|---|---|---|
| A registration in the team's branch | That row's status | That row's | That row's |
| Registrations, none in the team's branch | Only when left everywhere | The main registration's | None: another sport's position or licence means nothing here |
| No registration at all | Their own status | Their own | Their own |

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/TeamMembersTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\Team;
use App\Services\Competitions\TeamMembers;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class TeamMembersTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase, RegistrationFixtures;

    private Team $footTeam;

    private Team $swimTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->footTeam = $this->team($this->senior, ['branch_id' => $this->foot->id]);
        $this->swimTeam = $this->team($this->u15, ['branch_id' => $this->swim->id]);
    }

    private function loaded(Player $player): Player
    {
        return Player::with(TeamMembers::RELATIONS)->findOrFail($player->id);
    }

    private function footballOnly(string $name = 'Solo'): Player
    {
        $player = Player::create(['firstname' => $name, 'lastname' => 'Test', 'join_year' => 2020]);
        app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);

        return $player->refresh();
    }

    #[Test]
    public function both_active_reads_each_teams_own_registration(): void
    {
        $player = $this->loaded($this->bothActive());

        $this->assertSame('Senior', TeamMembers::of($this->footTeam)->category($player)->name);
        $this->assertSame('U15', TeamMembers::of($this->swimTeam)->category($player)->name);
        $this->assertSame($this->u15->id, TeamMembers::of($this->swimTeam)->categoryId($player));
        $this->assertFalse(TeamMembers::of($this->footTeam)->hasLeft($player));
        $this->assertTrue(TeamMembers::of($this->swimTeam)->isActiveMember($player));
    }

    #[Test]
    public function left_football_counts_on_the_football_team_only(): void
    {
        $player = $this->loaded($this->leftFootball());
        $foot = TeamMembers::of($this->footTeam);
        $swim = TeamMembers::of($this->swimTeam);

        $this->assertTrue($foot->hasLeft($player));
        $this->assertFalse($foot->isActiveMember($player));
        $this->assertSame('Senior', $foot->category($player)->name);
        $this->assertFalse($swim->hasLeft($player));
        $this->assertTrue($swim->isActiveMember($player));
    }

    #[Test]
    public function left_both_counts_on_both_teams(): void
    {
        $player = $this->loaded($this->leftBoth());

        $this->assertTrue(TeamMembers::of($this->footTeam)->hasLeft($player));
        $this->assertTrue(TeamMembers::of($this->swimTeam)->hasLeft($player));
    }

    #[Test]
    public function no_registration_uses_the_players_own_columns(): void
    {
        $position = Position::create(['branch_id' => $this->foot->id, 'name' => 'Keeper', 'abbreviation' => 'GK']);
        $flat = $this->noRegistration();
        $flat->update(['licence_number' => 'OWN-1', 'position_id' => $position->id]);
        $members = TeamMembers::of($this->footTeam);
        $player = $this->loaded($flat);

        $this->assertFalse($members->hasLeft($player));
        $this->assertFalse($members->isActiveMember($player));
        $this->assertSame('Senior', $members->category($player)->name);
        $this->assertSame($this->senior->id, $members->categoryId($player));
        $this->assertSame('OWN-1', $members->licence($player));
        $this->assertSame('GK', $members->position($player)->abbreviation);

        $flat->update(['status_id' => PlayerStatus::where('code', 'left')->value('id')]);
        $this->assertTrue($members->hasLeft($this->loaded($flat)));
    }

    #[Test]
    public function a_member_of_another_sport_shows_their_category_but_no_licence_or_position(): void
    {
        $position = Position::create(['branch_id' => $this->foot->id, 'name' => 'Keeper', 'abbreviation' => 'GK']);
        $player = $this->footballOnly();
        $player->registrations()->update(['licence_number' => 'FB-1', 'position_id' => $position->id]);
        $members = TeamMembers::of($this->swimTeam);
        $loaded = $this->loaded($player);

        $this->assertFalse($members->hasLeft($loaded));
        $this->assertFalse($members->isActiveMember($loaded));
        $this->assertSame('Senior', $members->category($loaded)->name);
        $this->assertNull($members->licence($loaded));
        $this->assertNull($members->position($loaded));
    }

    #[Test]
    public function a_member_of_another_sport_who_left_it_has_left_this_team_too(): void
    {
        $player = $this->footballOnly();
        app(RegistrationService::class)->leave($player, [$this->foot->id], $this->leaveDate);

        $this->assertTrue(TeamMembers::of($this->swimTeam)->hasLeft($this->loaded($player)));
    }

    #[Test]
    public function licence_and_position_come_from_the_teams_branch_not_the_mirror(): void
    {
        $keeper = Position::create(['branch_id' => $this->foot->id, 'name' => 'Keeper', 'abbreviation' => 'GK']);
        $free = Position::create(['branch_id' => $this->swim->id, 'name' => 'Freestyle', 'abbreviation' => 'FR']);
        $player = $this->bothActive();
        $player->registrations()->where('branch_id', $this->foot->id)->update(['licence_number' => 'FB-1', 'position_id' => $keeper->id]);
        $player->registrations()->where('branch_id', $this->swim->id)->update(['licence_number' => 'SW-1', 'position_id' => $free->id]);
        $loaded = $this->loaded($player);

        $this->assertSame('FB-1', TeamMembers::of($this->footTeam)->licence($loaded));
        $this->assertSame('SW-1', TeamMembers::of($this->swimTeam)->licence($loaded));
        $this->assertSame('FR', TeamMembers::of($this->swimTeam)->position($loaded)->abbreviation);
    }

    #[Test]
    public function a_list_costs_no_query_per_player(): void
    {
        foreach (range(1, 5) as $n) {
            $this->bothActive("P$n");
        }
        $players = Player::with(TeamMembers::RELATIONS)->get();
        $members = TeamMembers::of($this->swimTeam);

        DB::enableQueryLog();
        $players->each(function (Player $player) use ($members) {
            $members->hasLeft($player);
            $members->isActiveMember($player);
            $members->category($player);
            $members->categoryId($player);
            $members->position($player);
            $members->licence($player);
        });
        $this->assertCount(0, DB::getQueryLog());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan config:clear && php artisan test tests/Feature/Competitions/TeamMembersTest.php`
Expected: FAIL, `Class "App\Services\Competitions\TeamMembers" not found`.

- [ ] **Step 3: Write the helper**

`app/Services/Competitions/TeamMembers.php`:

```php
<?php

namespace App\Services\Competitions;

use App\Models\Category;
use App\Models\Player;
use App\Models\Position;
use App\Models\Team;
use App\Services\Player\RegistrationDisplay;

/**
 * What one team sees of a player: the registration in the team's branch. A
 * player from another sport (rosters allow it) shows their main category, but
 * has no licence or position here. A player with no registration at all shows
 * their own columns. Eager-load RELATIONS, and a list costs no query per row.
 */
final class TeamMembers
{
    public const RELATIONS = ['registrations.category', 'registrations.position', 'category', 'position'];

    private function __construct(
        private readonly RegistrationDisplay $display,
        private readonly int $branchId,
        private readonly ?int $leftStatusId,
    ) {}

    public static function of(Team $team): self
    {
        return new self(RegistrationDisplay::make(), (int) $team->branch_id, Player::leftStatusId());
    }

    /** Gone from this team's sport: that registration is left, or with none there, left everywhere. */
    public function hasLeft(Player $player): bool
    {
        return $this->leftStatusId !== null
            && (int) $this->display->values($player, $this->branchId)['status_id'] === $this->leftStatusId;
    }

    public function isActiveMember(Player $player): bool
    {
        return $this->display->isActiveIn($player, $this->branchId);
    }

    public function categoryId(Player $player): ?int
    {
        $id = $this->display->values($player, $this->branchId)['category_id'];

        return $id === null ? null : (int) $id;
    }

    public function category(Player $player): ?Category
    {
        $row = $this->display->shown($player, $this->branchId);

        return $row === null ? $player->category : $row->category;
    }

    public function position(Player $player): ?Position
    {
        $row = $player->registrationIn($this->branchId);
        if ($row !== null) {
            return $row->position;
        }

        return $player->registrations->isEmpty() ? $player->position : null;
    }

    public function licence(Player $player): ?string
    {
        $row = $player->registrationIn($this->branchId);
        if ($row !== null) {
            return $row->licence_number;
        }

        return $player->registrations->isEmpty() ? $player->licence_number : null;
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/Competitions/TeamMembersTest.php`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Competitions/TeamMembers.php tests/Feature/Competitions/TeamMembersTest.php
git commit -m "feat(teams): read a player through the registration in the team's branch

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P4.2: Team and competition pages

**Files:**
- Modify: `app/Http/Controllers/TeamController.php` (`show`)
- Modify: `app/Http/Controllers/CompetitionController.php` (`show`)
- Modify: `resources/js/Pages/Teams/Show.vue`
- Modify: `resources/js/Pages/Competitions/Show.vue`
- Modify: `resources/js/i18n/ar.json`, `resources/js/i18n/en.json`, `resources/js/i18n/fr.json` (through the script)
- Create: `tests/Feature/Competitions/TeamRegistrationsTest.php`

**Interfaces:**
- Consumes: `TeamMembers` (Task P4.1).
- Produces: each row of the `roster` prop on `Teams/Show` and `Competitions/Show` carries `left_branch: bool`. Task P4.4 changes `PlayerFlags::for`. This task calls it with `team: $team`, and PHP refuses that named argument until P4.4 adds it, so **this task adds the parameter first** (Step 3) and P4.4 uses it.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/TeamRegistrationsTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class TeamRegistrationsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase, RegistrationFixtures;

    private Team $footTeam;

    private Team $swimTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->footTeam = $this->team($this->senior, ['branch_id' => $this->foot->id]);
        $this->swimTeam = $this->team($this->u15, ['branch_id' => $this->swim->id]);
    }

    private function teamPage(Team $team)
    {
        return $this->actingAs($this->admin())->get(route('teams.show', ['team' => $team, 'season' => 2026]))->assertOk();
    }

    private function row(array $rows, string $key, int $id): array
    {
        return collect($rows)->firstWhere($key, $id);
    }

    #[Test]
    public function the_roster_shows_the_category_of_the_teams_branch(): void
    {
        $player = $this->bothActive();
        $this->onRoster($this->swimTeam, 2026, [$player]);

        $this->teamPage($this->swimTeam)->assertInertia(fn (Assert $page) => $page
            ->where('roster.0.player_id', $player->id)
            ->where('roster.0.category', 'U15')
            ->where('roster.0.left_branch', false));
    }

    #[Test]
    public function a_player_who_left_the_branch_stays_on_the_roster_flagged(): void
    {
        $left = $this->leftFootball();
        $stays = $this->bothActive();
        $this->onRoster($this->footTeam, 2026, [$left, $stays]);

        $this->teamPage($this->footTeam)->assertInertia(fn (Assert $page) => $page
            ->has('roster', 2)
            ->where('roster', fn ($rows) => $this->row($rows->all(), 'player_id', $left->id)['left_branch'] === true
                && $this->row($rows->all(), 'player_id', $left->id)['category'] === 'Senior'
                && $this->row($rows->all(), 'player_id', $stays->id)['left_branch'] === false));

        $this->assertDatabaseHas('team_players', ['team_id' => $this->footTeam->id, 'player_id' => $left->id, 'season_start_year' => 2026]);
    }

    #[Test]
    public function left_both_and_no_registration_are_flagged_by_their_own_status(): void
    {
        $gone = $this->leftBoth();
        $flat = $this->noRegistration();
        $this->onRoster($this->footTeam, 2026, [$gone, $flat]);

        $this->teamPage($this->footTeam)->assertInertia(fn (Assert $page) => $page
            ->where('roster', fn ($rows) => $this->row($rows->all(), 'player_id', $gone->id)['left_branch'] === true
                && $this->row($rows->all(), 'player_id', $flat->id)['left_branch'] === false
                && $this->row($rows->all(), 'player_id', $flat->id)['category'] === 'Senior'));
    }

    #[Test]
    public function in_sport_means_an_active_registration_in_the_teams_branch(): void
    {
        $both = $this->bothActive();
        $leftFoot = $this->leftFootball();
        $gone = $this->leftBoth();
        $flat = $this->noRegistration();

        $this->teamPage($this->footTeam)->assertInertia(fn (Assert $page) => $page
            ->where('candidates', function ($rows) use ($both, $leftFoot, $gone, $flat) {
                $rows = $rows->all();

                return $this->row($rows, 'id', $both->id)['in_sport'] === true
                    && $this->row($rows, 'id', $leftFoot->id)['in_sport'] === false
                    && $this->row($rows, 'id', $flat->id)['in_sport'] === false
                    && $this->row($rows, 'id', $gone->id) === null;
            }));
    }

    #[Test]
    public function the_candidates_category_is_the_one_in_the_teams_branch(): void
    {
        $both = $this->bothActive();
        $flat = $this->noRegistration();

        $this->teamPage($this->swimTeam)->assertInertia(fn (Assert $page) => $page
            ->where('candidates', fn ($rows) => $this->row($rows->all(), 'id', $both->id)['category_id'] === $this->u15->id
                && $this->row($rows->all(), 'id', $flat->id)['category_id'] === $this->senior->id));
    }

    #[Test]
    public function a_saved_roster_keeps_a_left_player_when_they_are_sent_back(): void
    {
        $left = $this->leftFootball();
        $this->onRoster($this->footTeam, 2026, [$left]);

        $this->actingAs($this->admin())->put(route('teams.roster.update', $this->footTeam), [
            'season' => 2026, 'players' => [['player_id' => $left->id, 'shirt_number' => 7]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(7, (int) DB::table('team_players')->where('player_id', $left->id)->value('shirt_number'));
    }

    #[Test]
    public function the_competition_page_flags_and_labels_by_the_teams_branch(): void
    {
        $left = $this->leftFootball();
        $swimmer = $this->bothActive();
        $this->onRoster($this->footTeam, 2026, [$left]);
        $this->onRoster($this->swimTeam, 2026, [$swimmer]);
        $foot = $this->competition($this->footTeam, 2026);
        $swim = $this->competition($this->swimTeam, 2026);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('competitions.show', $foot))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('roster.0.left_branch', true));
        $this->actingAs($admin)->get(route('competitions.show', $swim))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roster.0.category', 'U15')
                ->where('roster.0.left_branch', false));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Competitions/TeamRegistrationsTest.php`
Expected: FAIL on the `left_branch` and category assertions.

- [ ] **Step 3: Give `PlayerFlags::for` the team**

In `app/Services/Competitions/PlayerFlags.php`, add `use App\Models\Team;` to the imports and change the signature:

```php
    public function for(array $playerIds, ?User $viewer = null, ?Game $game = null, ?Competition $competition = null, ?Team $team = null): array
```

Task P4.4 reads it. Until then it is unused.

- [ ] **Step 4: Change `TeamController::show`**

Add `use App\Services\Competitions\TeamMembers;` to the imports. Replace from `$roster = $team->rosterFor($season)->with('category')->get()` through the end of the `$candidates` statement with:

```php
        $members = TeamMembers::of($team);

        $roster = $team->rosterFor($season)->with(TeamMembers::RELATIONS)->get()
            ->map(fn (Player $p) => [
                'player_id' => $p->id,
                'name' => $p->fullname,
                'category' => $members->category($p)?->localized_name,
                'shirt_number' => $p->pivot->shirt_number,
                'injured' => isset($injured[$p->id]),
                // Stays on the roster, marked; can't be called up or copied to next season.
                'left_branch' => $members->hasLeft($p),
            ])->sortBy('name')->values();
        $badges = $flags->for($roster->pluck('player_id')->all(), $request->user(), team: $team);
        $roster = $roster->map(fn (array $row) => $row + ['flags' => $badges[$row['player_id']] ?? null])->values();

        $candidates = Player::query()->where('archived', false)->notLeft()
            ->whereNotIn('id', $roster->pluck('player_id'))
            ->with('registrations')
            ->get(['id', 'category_id', ...Player::NAME_COLUMNS])
            ->map(fn (Player $p) => [
                'id' => $p->id,
                'name' => $p->fullname,
                'category_id' => $members->categoryId($p),
                'in_sport' => $members->isActiveMember($p),
            ])->sortBy('name')->values();
```

- [ ] **Step 5: Change `CompetitionController::show`**

Add `use App\Services\Competitions\TeamMembers;` to the imports. Replace the `$roster = $team->rosterFor($season)->with('category')->get()` statement with:

```php
        $members = TeamMembers::of($team);
        $roster = $team->rosterFor($season)->with(TeamMembers::RELATIONS)->get()
            ->map(fn (Player $p) => [
                'player_id' => $p->id,
                'name' => $p->fullname,
                'category' => $members->category($p)?->localized_name,
                'shirt_number' => $p->pivot->shirt_number,
                'in_squad' => $squad->has($p->id) && ! $squad[$p->id],
                'left_branch' => $members->hasLeft($p),
            ])->sortBy('name')->values();
```

The `$flags->for(..., null, $competition)` call stays as it is: Task P4.4 finds the team through the competition.

- [ ] **Step 6: Add the i18n key**

Write `.superpowers/r2/keys-p4.json`:

```json
{
    "comp.left_branch": {
        "ar": "غادر هذه الرياضة",
        "fr": "A quitté ce sport",
        "en": "Left this sport"
    }
}
```

Run: `node scripts/i18n-add.mjs .superpowers/r2/keys-p4.json && npm run i18n:check`
Expected: the key is inserted in all three catalogs and the check passes.

- [ ] **Step 7: Show the mark on the team page**

In `resources/js/Pages/Teams/Show.vue`, replace:

```html
                            <td class="py-2 font-medium text-slate-900 dark:text-slate-100">
                                {{ row.name }}
                                <PlayerBadges :flags="row.flags" class="ms-2" />
                            </td>
```

with:

```html
                            <td class="py-2 font-medium text-slate-900 dark:text-slate-100">
                                {{ row.name }}
                                <span v-if="row.left_branch" class="ms-2 rounded bg-slate-200 px-1.5 py-0.5 text-xs text-slate-700 dark:bg-slate-700 dark:text-slate-200">{{ t('comp.left_branch') }}</span>
                                <PlayerBadges :flags="row.flags" class="ms-2" />
                            </td>
```

In `addPlayer`, change the pushed row so it has the same shape:

```js
    if (c) rows.value.push({ player_id: c.id, name: c.name, category: null, shirt_number: null, injured: false, left_branch: false });
```

- [ ] **Step 8: Show the mark on the competition page**

In `resources/js/Pages/Competitions/Show.vue`, replace:

```html
                        <span class="font-medium">{{ r.name }}</span>
                        <PlayerBadges :flags="r.flags" />
```

with:

```html
                        <span class="font-medium">{{ r.name }}</span>
                        <span v-if="r.left_branch" class="rounded bg-slate-200 px-1.5 py-0.5 text-xs text-slate-700 dark:bg-slate-700 dark:text-slate-200">{{ t('comp.left_branch') }}</span>
                        <PlayerBadges :flags="r.flags" />
```

- [ ] **Step 9: Run the tests**

Run: `php artisan test tests/Feature/Competitions/TeamRegistrationsTest.php tests/Feature/Competitions/TeamsTest.php tests/Feature/Competitions/CompetitionsTest.php`
Expected: all PASS. `the_team_page_shows_the_season_roster_and_candidates` stays green unchanged.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/TeamController.php app/Http/Controllers/CompetitionController.php app/Services/Competitions/PlayerFlags.php resources/js/Pages/Teams/Show.vue resources/js/Pages/Competitions/Show.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/TeamRegistrationsTest.php
git commit -m "feat(teams): rosters show the team's branch and mark who left it

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P4.3: Call-ups and the season copy

**Files:**
- Modify: `app/Services/Competitions/CallUpCandidates.php`
- Modify: `app/Services/Competitions/TeamRoster.php` (`copyFromPreviousSeason`)
- Modify: `app/Http/Controllers/GameController.php` (`show`, the `candidates` closure)
- Modify: `app/Http/Controllers/GameCallUpController.php` (`convocation`)
- Modify: `tests/Feature/Competitions/TeamRegistrationsTest.php`

`GameCallUpController::update` needs no code change: it validates `player_id` against `CallUpCandidates`, and it only ever drops rows of current candidates. The tests below pin both.

**Interfaces:**
- Consumes: `TeamMembers`; `Player::scopeRegisteredWhere`.
- Produces: `CallUpCandidates::for(Game)` leaves out players who left the team's branch, and its players have `TeamMembers::RELATIONS` loaded.

- [ ] **Step 1: Add the failing tests**

In `tests/Feature/Competitions/TeamRegistrationsTest.php`, add these imports:

```php
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Services\Pdf\PdfService;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;
```

Add these methods to the class:

```php
    private function callUp($game, int $playerId)
    {
        return $this->actingAs($this->admin())->put(route('games.call-up.update', $game), [
            'players' => [['player_id' => $playerId, 'called_up' => true, 'not_called_reason' => null]],
        ]);
    }

    #[Test]
    public function a_player_who_left_the_branch_is_not_a_call_up_candidate(): void
    {
        $left = $this->leftFootball();
        $stays = $this->bothActive();
        $gone = $this->leftBoth();
        $flatLeft = $this->noRegistration('FlatLeft');
        $flatLeft->update(['status_id' => \App\Models\PlayerStatus::where('code', 'left')->value('id')]);
        $flat = $this->noRegistration('FlatStays');
        $this->onRoster($this->footTeam, 2026, [$left, $stays, $gone, $flatLeft, $flat]);
        $game = $this->game($this->footTeam);

        $this->actingAs($this->admin())->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates', fn ($rows) => collect($rows)->pluck('player_id')->sort()->values()->all()
                    === collect([$stays->id, $flat->id])->sort()->values()->all()));
    }

    #[Test]
    public function they_stay_a_candidate_on_the_other_sports_team(): void
    {
        $player = $this->leftFootball();
        $this->onRoster($this->swimTeam, 2026, [$player]);
        $game = $this->game($this->swimTeam);

        $this->actingAs($this->admin())->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.0.player_id', $player->id)
                ->where('candidates.0.category', 'U15'));
    }

    #[Test]
    public function the_server_refuses_a_call_up_that_names_a_player_who_left(): void
    {
        $left = $this->leftFootball();
        $this->onRoster($this->footTeam, 2026, [$left]);
        $game = $this->game($this->footTeam);

        $this->callUp($game, $left->id)->assertSessionHasErrors('players.0.player_id');

        $this->assertDatabaseMissing('game_players', ['game_id' => $game->id, 'player_id' => $left->id]);
    }

    #[Test]
    public function a_call_up_made_before_they_left_stays_with_its_events(): void
    {
        $player = $this->bothActive();
        $other = $this->bothActive('Other');
        $this->onRoster($this->footTeam, 2026, [$player, $other]);
        $game = $this->game($this->footTeam);
        $this->callUp($game, $player->id)->assertSessionHasNoErrors();
        $event = GameEvent::create(['game_id' => $game->id, 'type' => 'goal', 'player_id' => $player->id, 'period' => 1, 'minute' => 10]);

        app(\App\Services\Player\RegistrationService::class)->leave($player, [$this->foot->id], $this->leaveDate);

        // Saving the call-up again, now without them, must not touch their row.
        $this->callUp($game, $other->id)->assertSessionHasNoErrors();

        $this->assertTrue(GamePlayer::where('game_id', $game->id)->where('player_id', $player->id)->sole()->called_up);
        $this->assertDatabaseHas('game_events', ['id' => $event->id, 'player_id' => $player->id]);
    }

    #[Test]
    public function the_season_copy_skips_who_left_the_teams_branch_only(): void
    {
        $leftFoot = $this->leftFootball();
        $stays = $this->bothActive();
        $gone = $this->leftBoth();
        $flat = $this->noRegistration();
        $flatLeft = $this->noRegistration('FlatLeft');
        $flatLeft->update(['status_id' => \App\Models\PlayerStatus::where('code', 'left')->value('id')]);
        $this->onRoster($this->footTeam, 2025, [$leftFoot, $stays, $gone, $flat, $flatLeft]);
        $this->onRoster($this->swimTeam, 2025, [$leftFoot]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('teams.roster.copy', $this->footTeam), ['season' => 2026])
            ->assertSessionHas('success', 'flash.team_roster_copied');
        $this->assertEqualsCanonicalizing([$stays->id, $flat->id], $this->footTeam->rosterFor(2026)->pluck('players.id')->all());

        $this->actingAs($admin)->post(route('teams.roster.copy', $this->swimTeam), ['season' => 2026])
            ->assertSessionHas('success', 'flash.team_roster_copied');
        $this->assertSame([$leftFoot->id], $this->swimTeam->rosterFor(2026)->pluck('players.id')->all());

        $this->assertDatabaseHas('team_players', ['team_id' => $this->footTeam->id, 'player_id' => $leftFoot->id, 'season_start_year' => 2025]);
    }

    #[Test]
    public function a_player_from_another_sport_is_still_copied(): void
    {
        $footballer = $this->bothActive();
        $this->onRoster($this->swimTeam, 2025, [$footballer]);
        $solo = \App\Models\Player::create(['firstname' => 'Solo', 'lastname' => 'Test', 'join_year' => 2020]);
        app(\App\Services\Player\RegistrationService::class)->register($solo, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);
        $this->onRoster($this->swimTeam, 2025, [$solo]);

        $this->actingAs($this->admin())->post(route('teams.roster.copy', $this->swimTeam), ['season' => 2026]);

        $this->assertEqualsCanonicalizing([$footballer->id, $solo->id], $this->swimTeam->rosterFor(2026)->pluck('players.id')->all());
    }

    #[Test]
    public function the_convocation_sheet_shows_the_category_of_the_teams_branch(): void
    {
        // The team's own label says Senior, so "U15" can only come from the player's row.
        $team = $this->team($this->senior, ['branch_id' => $this->swim->id]);
        $player = $this->bothActive();
        $this->onRoster($team, 2026, [$player]);
        $game = $this->game($team);
        $this->callUp($game, $player->id)->assertSessionHasNoErrors();

        $html = null;
        $this->mock(PdfService::class, function (MockInterface $mock) use (&$html) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $rendered) use (&$html) {
                $html = $rendered;

                return new Response('pdf');
            });
        });

        $this->actingAs($this->admin())->get(route('games.convocation', $game))->assertOk();

        $this->assertStringContainsString('U15', $html);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test tests/Feature/Competitions/TeamRegistrationsTest.php`
Expected: FAIL on the candidate, refusal, copy and convocation tests. `a_call_up_made_before_they_left_stays_with_its_events` and `a_player_from_another_sport_is_still_copied` may pass already: they pin behaviour that must not break.

- [ ] **Step 3: Leave them out of the candidates**

In `app/Services/Competitions/CallUpCandidates.php`, change the docblock's first sentence and the query:

```php
/**
 * Who may be called up for a game: the competition's visible squad members
 * still on the team's season roster, or that roster for a friendly, minus
 * anyone who left the team's branch. Each player carries the roster's shirt
 * number; ordered by number, then name.
 */
final class CallUpCandidates
{
    /** @return Collection<int, Player> */
    public function for(Game $game): Collection
    {
        $shirts = DB::table('team_players')
            ->where('team_id', $game->team_id)->where('season_start_year', $game->season_start_year)
            ->pluck('shirt_number', 'player_id');
        $ids = $game->competition_id !== null
            ? DB::table('competition_players')->where('competition_id', $game->competition_id)->where('hidden', false)
                ->whereIn('player_id', $shirts->keys())->pluck('player_id')
            : $shirts->keys();
        $members = TeamMembers::of($game->team);

        return Player::query()->whereIn('id', $ids)->with(TeamMembers::RELATIONS)->get()
            ->reject(fn (Player $p) => $members->hasLeft($p))
            ->each(fn (Player $p) => $p->setAttribute('shirt_number', ($n = $shirts[$p->id] ?? null) === null ? null : (int) $n))
            ->sortBy([
                fn (Player $a, Player $b) => ($a->shirt_number ?? 999) <=> ($b->shirt_number ?? 999),
                fn (Player $a, Player $b) => strcmp($a->fullname, $b->fullname),
            ])->values();
    }
}
```

- [ ] **Step 4: Show the branch's category on the match page**

In `app/Http/Controllers/GameController.php`, add `use App\Services\Competitions\TeamMembers;` to the imports. In the `candidates` closure of `show`, add `$members` and change the category line:

```php
            'candidates' => (function () use ($game, $candidates, $injured, $flags, $request) {
                $rows = $game->players->keyBy('player_id');
                $list = $candidates->for($game);
                $badges = $flags->for($list->pluck('id')->all(), $request->user(), $game);
                $members = TeamMembers::of($game->team);

                return $list->map(fn (Player $p) => [
                    'player_id' => $p->id,
                    'name' => $p->fullname,
                    'category' => $members->category($p)?->localized_name,
                    'shirt_number' => $rows->get($p->id)?->shirt_number ?? $p->shirt_number,
                    'called_up' => (bool) $rows->get($p->id)?->called_up,
                    'not_called_reason' => $rows->get($p->id)?->not_called_reason,
                    'injured' => isset($injured[$p->id]),
                    'flags' => $badges[$p->id] ?? null,
                ])->values();
            })(),
```

- [ ] **Step 5: Show it on the convocation**

In `app/Http/Controllers/GameCallUpController.php`, add `use App\Services\Competitions\TeamMembers;` to the imports. In `convocation`, replace the `$players` statement with:

```php
        $members = TeamMembers::of($game->team);
        $players = $game->players()->where('called_up', true)->with(['player' => fn ($q) => $q->with(TeamMembers::RELATIONS)])->get()
            ->sortBy([
                fn (GamePlayer $a, GamePlayer $b) => ($a->shirt_number ?? 999) <=> ($b->shirt_number ?? 999),
                fn (GamePlayer $a, GamePlayer $b) => strcmp($a->player->fullname, $b->player->fullname),
            ])
            ->map(fn (GamePlayer $gp) => [
                'shirt' => $gp->shirt_number,
                'name' => $gp->player->fullname,
                'category' => $members->category($gp->player)?->localized_name,
            ])->values()->all();
```

- [ ] **Step 6: Skip them in the season copy**

In `app/Services/Competitions/TeamRoster.php`, replace `copyFromPreviousSeason` with:

```php
    /**
     * Copies last season's roster (and numbers) onto an empty $season, leaving out
     * archived players, those who left the club, and those who left the team's branch.
     */
    public function copyFromPreviousSeason(Team $team, int $season): int
    {
        $left = Player::leftStatusId();
        $rows = DB::table('team_players')
            ->where('team_id', $team->id)->where('season_start_year', $season - 1)
            ->whereIn('player_id', Player::query()->where('archived', false)->notLeft()
                ->whereNot(fn ($q) => $q->registeredWhere(branches: $team->branch_id, statuses: $left === null ? [] : [$left]))
                ->select('id'))
            ->get(['player_id', 'shirt_number'])
            ->map(fn ($row) => ['player_id' => (int) $row->player_id, 'shirt_number' => $row->shirt_number === null ? null : (int) $row->shirt_number])
            ->all();

        $this->replace($team, $season, $rows);

        return count($rows);
    }
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test tests/Feature/Competitions/TeamRegistrationsTest.php tests/Feature/Competitions/CallUpTest.php tests/Feature/Competitions/TeamsTest.php tests/Feature/Competitions/GamesTest.php`
Expected: all PASS. `re_saving_an_old_call_up_keeps_a_departed_players_row` and `last_seasons_roster_is_copied_without_archived_players` stay green unchanged.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Competitions/CallUpCandidates.php app/Services/Competitions/TeamRoster.php app/Http/Controllers/GameController.php app/Http/Controllers/GameCallUpController.php tests/Feature/Competitions/TeamRegistrationsTest.php
git commit -m "feat(matches): a player who left the branch can't be called up or copied

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P4.4: Licence and unpaid badges per sport

**Files:**
- Modify: `app/Services/Competitions/PlayerFlags.php` (`for`, docblocks, a new private method)
- Modify: `tests/Feature/Competitions/PlayerFlagsTest.php`
- Create: `tests/Feature/Competitions/PlayerFlagsPerSportTest.php`

**Interfaces:**
- Consumes: `TeamMembers::licence`, `RegistrationDisplay::values`, `PlayerSubscription::moneyFor`, the `?Team $team` parameter from Task P4.2.
- Produces: `PlayerFlags::for(array $playerIds, ?User $viewer = null, ?Game $game = null, ?Competition $competition = null, ?Team $team = null): array`. The team is `$team`, else the game's, else the competition's. With no team at all: the main registration's licence and every charge. The return shape does not change.

**Part 5 relies on:** the `Player` query in `for` keeps its `selectRaw` for the document SQL, and now has `with('registrations')`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/PlayerFlagsPerSportTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Competitions\PlayerFlags;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class PlayerFlagsPerSportTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase, RegistrationFixtures;

    private Team $footTeam;

    private Team $swimTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->footTeam = $this->team($this->senior, ['branch_id' => $this->foot->id]);
        $this->swimTeam = $this->team($this->u15, ['branch_id' => $this->swim->id]);
    }

    private function subscription(array $branchIds = []): Subscription
    {
        $subscription = Subscription::create([
            'name' => 'Fee '.uniqid(), 'year' => 2026, 'amount_student' => 2000, 'amount_worker' => 3000,
            'is_mandatory' => true, 'is_active' => true,
        ]);
        if ($branchIds !== []) {
            $subscription->branches()->attach($branchIds);
        }

        return $subscription;
    }

    private function charge(Player $player, ?Subscription $subscription, float $owed, float $paid = 0, array $extra = []): PlayerSubscription
    {
        return PlayerSubscription::create($extra + [
            'player_id' => $player->id, 'subscription_id' => $subscription?->id, 'year' => 2026,
            'status_at_time' => 'student', 'is_mandatory' => true, 'amount_owed' => $owed, 'amount_paid' => $paid,
        ]);
    }

    /** @return array<string, mixed> */
    private function flags(Player $player, Team $team, ?User $viewer = null): array
    {
        return app(PlayerFlags::class)->for([$player->id], $viewer ?? $this->admin(), team: $team)[$player->id];
    }

    #[Test]
    public function unpaid_counts_that_sports_debts_only(): void
    {
        $player = $this->bothActive();
        $this->charge($player, $this->subscription([$this->foot->id]), 1000);

        $this->assertTrue($this->flags($player, $this->footTeam)['unpaid']);
        $this->assertSame(1000.0, $this->flags($player, $this->footTeam)['debt']);
        $this->assertFalse($this->flags($player, $this->swimTeam)['unpaid']);
        $this->assertNull($this->flags($player, $this->swimTeam)['debt']);
    }

    #[Test]
    public function the_stored_total_is_not_used(): void
    {
        $player = $this->bothActive();
        DB::table('players')->where('id', $player->id)->update(['outstanding_debt' => 9000]);

        $this->assertFalse($this->flags($player, $this->footTeam)['unpaid']);
    }

    #[Test]
    public function the_amount_is_that_sports_alone(): void
    {
        $player = $this->bothActive();
        $this->charge($player, $this->subscription([$this->foot->id]), 1000);
        $this->charge($player, $this->subscription([$this->swim->id]), 300);

        $this->assertSame(1000.0, $this->flags($player, $this->footTeam)['debt']);
        $this->assertSame(300.0, $this->flags($player, $this->swimTeam)['debt']);
    }

    #[Test]
    public function a_subscription_with_no_branch_counts_on_both_teams(): void
    {
        $player = $this->bothActive();
        $this->charge($player, $this->subscription(), 2000);

        $this->assertSame(2000.0, $this->flags($player, $this->footTeam)['debt']);
        $this->assertSame(2000.0, $this->flags($player, $this->swimTeam)['debt']);
    }

    #[Test]
    public function a_subscription_ticked_for_both_counts_on_both_teams(): void
    {
        $player = $this->bothActive();
        $this->charge($player, $this->subscription([$this->foot->id, $this->swim->id]), 2000);

        $this->assertTrue($this->flags($player, $this->footTeam)['unpaid']);
        $this->assertTrue($this->flags($player, $this->swimTeam)['unpaid']);
    }

    #[Test]
    public function a_charge_whose_subscription_was_deleted_still_counts(): void
    {
        $player = $this->bothActive();
        $subscription = $this->subscription([$this->foot->id]);
        $charge = $this->charge($player, $subscription, 700);
        $subscription->delete();
        DB::table('player_subscriptions')->where('id', $charge->id)->update(['subscription_id' => null]);

        $this->assertSame(700.0, $this->flags($player, $this->footTeam)['debt']);
        $this->assertSame(700.0, $this->flags($player, $this->swimTeam)['debt']);
    }

    #[Test]
    public function a_settled_discounted_or_exempt_charge_is_not_unpaid(): void
    {
        $player = $this->bothActive();
        $football = [$this->foot->id];
        $this->charge($player, $this->subscription($football), 500, 500);
        $this->charge($player, $this->subscription($football), 500, 0, ['discount_type' => 'percent', 'discount_value' => 100]);
        $this->charge($player, $this->subscription($football), 500, 0, ['is_exempt' => true]);

        $this->assertFalse($this->flags($player, $this->footTeam)['unpaid']);
    }

    #[Test]
    public function a_partly_paid_charge_shows_what_remains(): void
    {
        $player = $this->bothActive();
        $this->charge($player, $this->subscription([$this->foot->id]), 1000, 400);

        $this->assertSame(600.0, $this->flags($player, $this->footTeam)['debt']);
    }

    #[Test]
    public function debt_stays_on_the_team_of_a_branch_they_left(): void
    {
        $player = $this->leftFootball();
        $this->charge($player, $this->subscription([$this->foot->id]), 1000);

        $this->assertTrue($this->flags($player, $this->footTeam)['unpaid']);
        $this->assertFalse($this->flags($player, $this->swimTeam)['unpaid']);
    }

    #[Test]
    public function left_both_still_shows_what_each_sport_is_owed(): void
    {
        $player = $this->leftBoth();
        $this->charge($player, $this->subscription([$this->swim->id]), 300);

        $this->assertSame(300.0, $this->flags($player, $this->swimTeam)['debt']);
        $this->assertFalse($this->flags($player, $this->footTeam)['unpaid']);
    }

    #[Test]
    public function no_registration_uses_own_licence_and_counts_charges_by_the_same_rule(): void
    {
        $flat = $this->noRegistration();
        $flat->update(['licence_number' => 'OWN-1']);
        $this->charge($flat, $this->subscription([$this->foot->id]), 800);

        $this->assertFalse($this->flags($flat, $this->footTeam)['no_licence']);
        $this->assertTrue($this->flags($flat, $this->footTeam)['unpaid']);
        $this->assertFalse($this->flags($flat, $this->swimTeam)['unpaid']);
    }

    #[Test]
    public function the_licence_is_the_one_in_the_teams_branch(): void
    {
        $player = $this->bothActive();
        $player->registrations()->where('branch_id', $this->foot->id)->update(['licence_number' => 'FB-1']);
        $player->registrations()->where('branch_id', $this->swim->id)->update(['licence_number' => null]);
        app(RegistrationService::class)->refreshMirror($player);

        $this->assertFalse($this->flags($player, $this->footTeam)['no_licence']);
        $this->assertTrue($this->flags($player, $this->swimTeam)['no_licence']);
    }

    #[Test]
    public function a_blank_licence_counts_as_none(): void
    {
        $player = $this->bothActive();
        $player->registrations()->where('branch_id', $this->foot->id)->update(['licence_number' => '   ']);

        $this->assertTrue($this->flags($player, $this->footTeam)['no_licence']);
    }

    #[Test]
    public function the_team_comes_from_the_game_or_the_competition(): void
    {
        $player = $this->bothActive();
        $this->charge($player, $this->subscription([$this->foot->id]), 1000);
        $flags = app(PlayerFlags::class);
        $admin = $this->admin();
        $swimGame = $this->game($this->swimTeam);
        $footCompetition = $this->competition($this->footTeam, 2026);

        $this->assertFalse($flags->for([$player->id], $admin, $swimGame)[$player->id]['unpaid']);
        $this->assertTrue($flags->for([$player->id], $admin, null, $footCompetition)[$player->id]['unpaid']);
    }

    #[Test]
    public function with_no_team_every_charge_and_the_main_licence_count(): void
    {
        $player = $this->bothActive();
        $player->registrations()->where('branch_id', $this->foot->id)->update(['licence_number' => 'FB-1']);
        $this->charge($player, $this->subscription([$this->foot->id]), 1000);
        $this->charge($player, $this->subscription([$this->swim->id]), 300);

        $flags = app(PlayerFlags::class)->for([$player->id], $this->admin())[$player->id];

        $this->assertSame(1300.0, $flags['debt']);
        $this->assertFalse($flags['no_licence']);
    }

    #[Test]
    public function the_amount_stays_hidden_without_finance_view(): void
    {
        $player = $this->bothActive();
        $this->charge($player, $this->subscription([$this->foot->id]), 1000);
        $viewer = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => ['games' => ['view']]])->id]);

        $flags = $this->flags($player, $this->footTeam, $viewer);

        $this->assertTrue($flags['unpaid']);
        $this->assertNull($flags['debt']);
    }

    #[Test]
    public function badges_cost_no_query_per_player(): void
    {
        $count = function (int $players): int {
            $ids = [];
            foreach (range(1, $players) as $n) {
                $player = $this->bothActive("Q$players$n");
                $this->charge($player, $this->subscription([$this->foot->id]), 100);
                $ids[] = $player->id;
            }
            $admin = $this->admin();
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(PlayerFlags::class)->for($ids, $admin, team: $this->footTeam);
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $this->assertSame($count(1), $count(5));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Competitions/PlayerFlagsPerSportTest.php`
Expected: FAIL, because the unpaid badge still reads `outstanding_debt`.

- [ ] **Step 3: Rewrite the badges**

In `app/Services/Competitions/PlayerFlags.php`, add these imports:

```php
use App\Models\PlayerSubscription;
use App\Services\Player\RegistrationDisplay;
use Illuminate\Support\Facades\DB;
```

Replace the class docblock's sentence and the `for` method, and add `owedFor`:

```php
    /**
     * Every badge for a list of players, in one pass, as one team sees them: the
     * licence of the team's branch and that sport's debts. The team is the one
     * given, else the game's or competition's. With none, the main licence and
     * every charge. The owed amount is only given to viewers with finance/view;
     * everyone else just sees "unpaid".
     *
     * @param  list<int>  $playerIds
     * @return array<int, array{injured: bool, suspended: bool, suspension: ?array, no_licence: bool, documents_missing: bool, documents: list<string>, unpaid: bool, debt: ?float}>
     */
    public function for(array $playerIds, ?User $viewer = null, ?Game $game = null, ?Competition $competition = null, ?Team $team = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $playerIds)));
        if ($ids === []) {
            return [];
        }
        $team ??= $game?->team ?? $competition?->team;
        $members = $team === null ? null : TeamMembers::of($team);
        $display = RegistrationDisplay::make();
        $injured = $this->injured();
        $suspended = $this->suspensions->forPlayers($ids, $game, $competition);
        $branchId = $team === null ? null : (int) $team->branch_id;
        $owed = $this->owedFor($ids, $branchId);
        $types = DocumentType::query()->where('is_active', true)->where('required_for_matches', true)
            ->orderBy('sort_order')->orderBy('id')->get();

        $query = Player::query()->whereIn('id', $ids)->select(['id', 'licence_number'])->with('registrations');
        foreach ($types as $type) {
            [$sql, $bindings] = DocumentChecklist::missingTypeSql($type);
            $query->selectRaw("{$sql} as doc_{$type->id}", $bindings);
        }
        $seeMoney = (bool) $viewer?->hasPermission('finance', 'view');

        $flags = [];
        foreach ($query->get() as $player) {
            $documents = $types->filter(fn (DocumentType $t) => (int) $player->{'doc_'.$t->id} === 1)
                ->map(fn (DocumentType $t) => (string) ($t->localized_name ?? $t->name))->values()->all();
            $licence = $members !== null ? $members->licence($player) : $display->values($player)['licence_number'];
            $debt = round($owed[$player->id] ?? 0.0, 2);
            $flags[$player->id] = [
                'injured' => isset($injured[$player->id]),
                'suspended' => isset($suspended[$player->id]),
                'suspension' => $suspended[$player->id] ?? null,
                'no_licence' => trim((string) $licence) === '',
                'documents_missing' => $documents !== [],
                'documents' => $documents,
                'unpaid' => $debt > 0,
                'debt' => $seeMoney && $debt > 0 ? $debt : null,
            ];
        }

        return $flags;
    }

    /**
     * What each player still owes a sport: charges of subscriptions with that
     * branch ticked, or none ticked. A deleted subscription has none ticked: its
     * sport can't be known and the money is still owed. Leaving a branch cancels nothing.
     *
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private function owedFor(array $ids, ?int $branchId): array
    {
        $ticked = fn ($q) => $q->selectRaw('1')->from('branch_subscription')
            ->whereColumn('branch_subscription.subscription_id', 'player_subscriptions.subscription_id');

        $owed = [];
        DB::table('player_subscriptions')->whereIn('player_id', $ids)
            ->when($branchId !== null, fn ($q) => $q->where(fn ($sport) => $sport
                ->whereNotExists($ticked)
                ->orWhereExists(fn ($t) => $ticked($t)->where('branch_subscription.branch_id', $branchId))))
            ->get(['player_id', 'amount_owed', 'amount_paid', 'is_exempt', 'discount_type', 'discount_value'])
            ->each(function ($row) use (&$owed) {
                $money = PlayerSubscription::moneyFor($row->amount_owed, $row->amount_paid, (bool) $row->is_exempt, $row->discount_type, $row->discount_value);
                $owed[(int) $row->player_id] = ($owed[(int) $row->player_id] ?? 0.0) + $money['remaining'];
            });

        return $owed;
    }
```

- [ ] **Step 4: Update the two existing tests in `PlayerFlagsTest`**

They set `outstanding_debt` directly, which the badge no longer reads. In `tests/Feature/Competitions/PlayerFlagsTest.php`, add `use App\Models\PlayerSubscription;` and this helper:

```php
    private function charge(\App\Models\Player $player, float $owed): void
    {
        PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => null, 'label' => 'Fee', 'year' => 2026,
            'status_at_time' => 'student', 'is_mandatory' => true, 'amount_owed' => $owed, 'amount_paid' => 0,
        ]);
    }
```

In `flags_cover_licence_documents_debt_and_suspension`, replace `$p->update(['outstanding_debt' => 1500]);` with `$this->charge($p, 1500);`.

In `the_debt_amount_is_hidden_without_finance_view_and_the_call_up_carries_flags`, replace `$p->update(['outstanding_debt' => 900]);` with `$this->charge($p, 900);`.

The assertions stay. These players have no registration, so they use their own licence.

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/Competitions/PlayerFlagsPerSportTest.php tests/Feature/Competitions/PlayerFlagsTest.php tests/Feature/Competitions/LicenceAndMatchDocumentsTest.php tests/Feature/Competitions/TeamRegistrationsTest.php`
Expected: all PASS. If `badges_cost_no_query_per_player` fails, print the query log and find which query repeats. `SuspensionStatus::forPlayers` and `injured()` were not changed, so a repeat most likely means `registrations` isn't loaded.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Competitions/PlayerFlags.php tests/Feature/Competitions/PlayerFlagsPerSportTest.php tests/Feature/Competitions/PlayerFlagsTest.php
git commit -m "feat(matches): licence and unpaid badges follow the team's sport

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P4.5: Usual position in the lineup

**Files:**
- Modify: `app/Services/Competitions/MatchPage.php` (`lineup`)
- Modify: `tests/Feature/Competitions/TeamRegistrationsTest.php`

**Interfaces:**
- Consumes: `TeamMembers::position`.

- [ ] **Step 1: Add the failing tests**

In `tests/Feature/Competitions/TeamRegistrationsTest.php`, add `use App\Models\Position;` and these methods:

```php
    #[Test]
    public function the_lineup_shows_the_usual_position_of_the_teams_branch(): void
    {
        $keeper = Position::create(['branch_id' => $this->foot->id, 'name' => 'Keeper', 'abbreviation' => 'GK']);
        $free = Position::create(['branch_id' => $this->swim->id, 'name' => 'Freestyle', 'abbreviation' => 'FR']);
        $player = $this->bothActive();
        $player->registrations()->where('branch_id', $this->foot->id)->update(['position_id' => $keeper->id]);
        $player->registrations()->where('branch_id', $this->swim->id)->update(['position_id' => $free->id]);
        $this->onRoster($this->swimTeam, 2026, [$player]);
        $game = $this->game($this->swimTeam);
        $this->callUp($game, $player->id)->assertSessionHasNoErrors();

        $this->actingAs($this->admin())->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('lineup.players.0.usual_position', 'FR'));
    }

    #[Test]
    public function no_registration_shows_their_own_position(): void
    {
        $keeper = Position::create(['branch_id' => $this->foot->id, 'name' => 'Keeper', 'abbreviation' => 'GK']);
        $flat = $this->noRegistration();
        $flat->update(['position_id' => $keeper->id]);
        $this->onRoster($this->footTeam, 2026, [$flat]);
        $game = $this->game($this->footTeam);
        $this->callUp($game, $flat->id)->assertSessionHasNoErrors();

        $this->actingAs($this->admin())->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('lineup.players.0.usual_position', 'GK'));
    }

    #[Test]
    public function a_player_called_up_before_leaving_stays_in_the_lineup(): void
    {
        $player = $this->bothActive();
        $this->onRoster($this->footTeam, 2026, [$player]);
        $game = $this->game($this->footTeam);
        $this->callUp($game, $player->id)->assertSessionHasNoErrors();

        app(\App\Services\Player\RegistrationService::class)->leave($player, [$this->foot->id], $this->leaveDate);

        $this->actingAs($this->admin())->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates', 0)
                ->where('lineup.players.0.player_id', $player->id));
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test tests/Feature/Competitions/TeamRegistrationsTest.php --filter="usual_position|own_position|stays_in_the_lineup"`
Expected: `the_lineup_shows_the_usual_position_of_the_teams_branch` FAILS, because it gets `GK` from the mirror. The other two pin behaviour and may pass.

- [ ] **Step 3: Change `MatchPage::lineup`**

In `app/Services/Competitions/MatchPage.php`, replace the first lines of `lineup`:

```php
        $game->loadMissing(['team', 'format.formations', 'players.player.position', 'players.player.registrations.position']);
        $injured ??= $this->flags->injured();
        $members = TeamMembers::of($game->team);
```

Replace the `usual_position` line:

```php
                    'usual_position' => $members->position($gp->player)?->abbreviation,
```

`TeamMembers` is in the same namespace, so it needs no import. `TeamMembers::position` only reads `registrations`, `registrations.position` and `position`, so no row costs a query.

- [ ] **Step 4: Run the tests**

Run: `php artisan test tests/Feature/Competitions/TeamRegistrationsTest.php tests/Feature/Competitions/LineupTest.php tests/Feature/Competitions/MatchReportTest.php`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Competitions/MatchPage.php tests/Feature/Competitions/TeamRegistrationsTest.php
git commit -m "feat(matches): the lineup shows the usual position in the team's branch

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P4.6: Close Part 4

**Files:** none, unless a check fails.

- [ ] **Step 1: Run the full checks**

```bash
php artisan config:clear
php artisan test 2>&1 | tail -5
npm run test:js 2>&1 | tail -3
npm run i18n:check
npm run build 2>&1 | tail -3
```

Expected: only `ManualSuspensionsTest` may fail, as at the baseline. Fix any other failure before going on. If a test elsewhere sets `outstanding_debt` and expects the match badge to follow it, change it to a real charge, as in Task P4.4 Step 4.

- [ ] **Step 2: Run the new tests on PostgreSQL and MariaDB**

Start the private instances if they are stopped (main plan, Tasks 3 and 7).

```bash
( . .superpowers/r2/env-pgsql.sh; export DB_DATABASE=sportclub_r2_test DB_URL=
  php artisan test tests/Feature/Competitions/TeamMembersTest.php tests/Feature/Competitions/TeamRegistrationsTest.php tests/Feature/Competitions/PlayerFlagsPerSportTest.php )
( export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3399 DB_DATABASE=sportclub_r2_test DB_USERNAME=root DB_PASSWORD= DB_URL= APP_CONFIG_CACHE="D:/irnb-branch-reg-r2/.superpowers/r2/no-config-cache.php"
  php artisan test tests/Feature/Competitions/TeamMembersTest.php tests/Feature/Competitions/TeamRegistrationsTest.php tests/Feature/Competitions/PlayerFlagsPerSportTest.php )
```

Expected: all PASS on both. Money comes back from PostgreSQL as text such as `1000.00`, which `moneyFor` already takes.

- [ ] **Step 3: Compare the pinned figures on the real-data copy**

```bash
( . .superpowers/r2/env-sqlite.sh
  php .superpowers/r2/figures.php p4
  php .superpowers/r2/sweep.php p4 | tail -5 )
php .superpowers/r2/diff-figures.php baseline p4
php .superpowers/r2/diff-sweep.php baseline p4 > .superpowers/r2/data/sweep-p4.txt
grep -n -A30 -E "PROPS (teams|games|competitions)\." .superpowers/r2/data/sweep-p4.txt | head -120
```

Expected:

- `diff-figures`: `unexpected differences: 0`. This part changes no money, eligibility, session roster or mirror value, so any difference comes from Parts 2 and 3, and those parts' reports already list it.
- No 5xx.
- `teams.show`, `competitions.show`, `games.show`: every roster row gains `left_branch`. The real data has one team, football Minime, so category text stays the same for football-only players.
- `flags.unpaid` and `flags.debt` may change. They now come from the charges, not from `players.outstanding_debt`, so **list every player whose badge or amount changed** in `.superpowers/r2/differences.md`, and explain each: another sport's debt no longer counted, or a stored total that had drifted from the charges. An unexplained change on a one-branch player is a bug.

- [ ] **Step 4: Check the mark in a browser**

Serve the copy on its own port, never 2026:

```bash
( . .superpowers/r2/env-sqlite.sh; php artisan serve --port=2027 --no-reload )
```

Mark a player as left football on the copy, then open their team page: Arabic, French and English; light and dark; 320 px and 1280. The "left" mark must wrap beside the name with no sideways scrolling at 320 px. Check the match page's call-up list leaves them out.

- [ ] **Step 5: Record progress**

Add to `.superpowers/r2/progress.md`: Part 4 done, the commit hashes, the suite counts, and the list of changed badges from Step 3. Part 8's report to the owner uses that list. Do not merge or push.

---

## Self-review against spec section 7

| Spec requirement | Task |
|---|---|
| Roster candidates and "in sport": active registration in the team's branch; category filter uses that registration | P4.2 (`TeamController::show`; `Teams/Show.vue`'s existing filters read the new values) |
| Left player's row carries `left_branch` and shows a "left" mark | P4.2 |
| `CallUpCandidates` leaves them out | P4.3 |
| Server refuses a call-up naming one | P4.3 (existing `Rule::in`, now pinned by a test) |
| Call-up made before they left stays; match history never changed | P4.3, P4.5 (pinned by tests; no write added) |
| `TeamRoster` season copy skips them | P4.3 |
| `PlayerFlags` licence from the team's branch | P4.4 |
| Unpaid: team's branch ticked, or none ticked; deleted subscription counts; `outstanding_debt` not used | P4.4 |
| Usual position in the lineup | P4.5 |
| Category displays use the team's branch registration | P4.2 (team, competition), P4.3 (match page, convocation) |
| Four cases and the query budget | P4.1, P4.2, P4.3, P4.4 |

**Decisions this part makes where the spec is silent, reported to the owner in Part 8:**

- A player on a roster with no registration in the team's sport (rosters allow this) shows their main category, but has no licence or position there, so they get the "no licence" badge. They count as left only when left everywhere.
- A player with no registration whose own status is `left` is flagged and can't be called up, following the spec's fallback rule. Today such a player could be called up.
- A left player already in a competition's squad stays there, unchanged, and is only stopped at the call-up.
