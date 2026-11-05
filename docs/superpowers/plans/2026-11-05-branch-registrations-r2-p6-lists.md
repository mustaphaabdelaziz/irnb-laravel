# Branch registrations, Release 2, Part 6: Lists, exports, dashboards, reports, cards, leftovers

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every list, export, chart, report and card reads the registration: a filter on one branch shows that registration's values, category charts count registrations, and the Release 1 leftovers are fixed.

**Architecture:** Two small additions sit on top of Part 1: `Player::scopeListedWhere` holds the rule for list filters, and `RegistrationDisplay::overlay` puts one registration's values on a player for a row or a PDF. `RegistrationCounts` builds the per-registration chart queries in SQL. With no branch selected, or several, readers keep using `players.*`, because the mirror is the main registration and its status is the overall status.

**Tech Stack:** Laravel 13, Eloquent, PHPUnit attribute tests, Vue 3 with Inertia, `node --test`. Spec sections 9 and 12 of `docs/superpowers/specs/2026-11-05-branch-registrations-r2-design.md`.

## Global Constraints

See the main plan `2026-11-05-branch-registrations-r2.md`, which apply in full. Also, for this part:

- Parts 1 to 5 are done. Part 7 (category split) is not: categories have no branch yet, and picker labels don't change here.
- **The JS test script is `npm run test:js`**, not `npm run test`, which doesn't exist in `package.json`.
- Never alias a raw SQL subquery `rows`: it is a reserved word on MySQL 8. Per-registration queries use the alias `reg`.
- `SUBSTR` on a date column fails on PostgreSQL. New code groups years in PHP.
- `BranchScope` doesn't change (spec section 5): a left registration stays in its branch's scope, because debt survives leaving.
- Excel import's category rule belongs to Part 7 (spec section 11). A second-sport row creating a second player stays as it is today.
- Join-year figures don't move (Release 4). A second-branch join stays uncounted in Movement and the literary report, and `new_members` keeps using `created_at`.

**Numbers that change on purpose, for the owner's report (Task P6.10):** club-wide category figures now leave out left registrations, as spec section 9 says. That moves one-branch figures in `byCategory`, Directory's category chart and Demographics' table, even though each person's own row stays identical. Before this part those figures counted everyone not archived.

---

### Task P6.1: The list rule, the overlay and the counts

**Files:**
- Create: `app/Services/Player/RegistrationCounts.php`
- Create: `tests/Feature/Registrations/ListRuleTest.php`
- Modify: `app/Models/Player.php` (`scopeRegisteredWhere`, new `scopeListedWhere`)
- Modify: `app/Services/Player/RegistrationDisplay.php` (new `overlay`)

**Interfaces:**
- Consumes: `Player::scopeRegisteredWhere`, `Player::registrationIn`, `RegistrationDisplay`, `BranchRegistration::everyRow`, `BranchRegistration::constrainActive`, `RegistrationFixtures`.
- Produces: `registeredWhere(..., bool $noStatus = false)`, appended as the last parameter, so earlier calls don't change. It adds "or no status" to the status condition.
- Produces: `Player::scopeListedWhere(?array $branches = null, ?array $categories = null, ?array $statuses = null, bool $noStatus = false, bool $hideLeft = false)`. Null means no condition.
- Produces: `RegistrationDisplay::overlay(Player $player, ?int $branchId): Player`. It needs `registrations` loaded when `$branchId` isn't null, and changes nothing when the player has no row in that branch.
- Produces: `RegistrationCounts::rows(EloquentBuilder|QueryBuilder $players, ?array $branches = null, bool $includeLeft = false): QueryBuilder` with alias `reg`; `RegistrationCounts::byCategory(...same...): Collection` and `RegistrationCounts::byStatus(...same...): Collection` of objects `{key, name, total}`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/ListRuleTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Services\Player\RegistrationCounts;
use App\Services\Player\RegistrationDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class ListRuleTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    private function ids(...$args): array
    {
        return Player::query()->listedWhere(...$args)->orderBy('id')->pluck('id')->all();
    }

    private function left(): int
    {
        return (int) PlayerStatus::where('code', 'left')->value('id');
    }

    #[Test]
    public function one_branch_holds_every_condition_on_the_same_registration(): void
    {
        $player = $this->bothActive();

        $this->assertSame([$player->id], $this->ids(branches: [$this->swim->id], categories: [$this->u15->id]));
        $this->assertSame([], $this->ids(branches: [$this->swim->id], categories: [$this->senior->id]));
    }

    #[Test]
    public function hiding_left_uses_the_registration_with_a_branch_and_the_person_without(): void
    {
        $leftFoot = $this->leftFootball();
        $leftBoth = $this->leftBoth();

        $this->assertSame([], $this->ids(branches: [$this->foot->id], hideLeft: true));
        $this->assertSame([$leftFoot->id], $this->ids(branches: [$this->swim->id], hideLeft: true));
        $this->assertSame([$leftFoot->id], $this->ids(hideLeft: true));
        $this->assertSame([$leftFoot->id, $leftBoth->id], $this->ids());
    }

    #[Test]
    public function a_status_club_wide_is_the_overall_status_and_in_a_branch_that_registrations(): void
    {
        $leftFoot = $this->leftFootball();
        $leftBoth = $this->leftBoth();

        $this->assertSame([$leftBoth->id], $this->ids(statuses: [$this->left()]));
        $this->assertSame([$leftFoot->id, $leftBoth->id], $this->ids(branches: [$this->foot->id], statuses: [$this->left()]));
    }

    #[Test]
    public function a_category_club_wide_ignores_a_left_registration_unless_left_is_asked_for(): void
    {
        $leftFoot = $this->leftFootball();
        $leftBoth = $this->leftBoth();
        $registered = (int) PlayerStatus::where('code', 'registered')->value('id');

        $this->assertSame([], $this->ids(categories: [$this->senior->id], hideLeft: true));
        $this->assertSame([], $this->ids(categories: [$this->senior->id], statuses: [$registered]));
        $this->assertSame([$leftBoth->id], $this->ids(categories: [$this->senior->id], statuses: [$this->left()]));
        $this->assertSame([$leftFoot->id, $leftBoth->id], $this->ids(categories: [$this->senior->id]));
    }

    #[Test]
    public function no_status_matches_a_blank_status_on_the_right_row(): void
    {
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['status_id' => null]);

        $this->assertSame([$player->id], $this->ids(branches: [$this->swim->id], noStatus: true));
        $this->assertSame([], $this->ids(branches: [$this->foot->id], noStatus: true));
    }

    #[Test]
    public function a_player_with_no_registration_uses_their_own_columns(): void
    {
        $flat = $this->noRegistration();

        $this->assertSame([$flat->id], $this->ids(categories: [$this->senior->id], hideLeft: true));
        $this->assertSame([], $this->ids(branches: [$this->foot->id]));
        $this->assertSame([$flat->id], $this->ids(noStatus: true));
    }

    #[Test]
    public function overlay_shows_the_branchs_values_and_only_its_other_positions(): void
    {
        $player = $this->bothActive();
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);
        $striker = Position::create(['name' => 'Striker', 'abbreviation' => 'ST', 'branch_id' => $this->foot->id]);
        $anywhere = Position::create(['name' => 'Captain', 'abbreviation' => 'CP']);
        $player->otherPositions()->attach([$crawl->id, $striker->id, $anywhere->id]);
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => 'SW-2020-00001', 'licence_number' => 'LIC']);
        $loaded = Player::with(['category', 'otherPositions', 'registrations.category'])->findOrFail($player->id);

        RegistrationDisplay::make()->overlay($loaded, $this->swim->id);

        $this->assertSame('SW-2020-00001', $loaded->membership_id);
        $this->assertSame('LIC', $loaded->licence_number);
        $this->assertSame($this->u15->id, $loaded->category_id);
        $this->assertSame('U15', $loaded->category->name);
        $this->assertEqualsCanonicalizing([$crawl->id, $anywhere->id], $loaded->otherPositions->pluck('id')->all());
    }

    #[Test]
    public function overlay_with_no_row_there_changes_nothing(): void
    {
        $loaded = Player::with('registrations')->findOrFail($this->noRegistration()->id);
        $before = $loaded->getAttributes();

        RegistrationDisplay::make()->overlay($loaded, $this->swim->id);

        $this->assertSame($before, $loaded->getAttributes());
    }

    #[Test]
    public function category_counts_put_a_two_branch_player_in_both_bars_and_skip_left_rows(): void
    {
        $this->bothActive('A');
        $this->leftFootball('B');
        $this->leftBoth('C');
        $this->noRegistration('D');

        $bars = RegistrationCounts::byCategory(Player::query())->pluck('total', 'key')->all();
        $this->assertEquals([$this->senior->id => 2, $this->u15->id => 2], $bars);

        $withLeft = RegistrationCounts::byCategory(Player::query(), includeLeft: true)->pluck('total', 'key')->all();
        $this->assertEquals([$this->senior->id => 4, $this->u15->id => 3], $withLeft);

        $football = RegistrationCounts::byCategory(Player::query(), [$this->foot->id], includeLeft: true)->pluck('total', 'key')->all();
        $this->assertEquals([$this->senior->id => 3], $football);
    }

    #[Test]
    public function status_counts_read_the_registration(): void
    {
        $this->leftFootball();

        $football = RegistrationCounts::byStatus(Player::query(), [$this->foot->id], includeLeft: true)->pluck('total', 'key')->all();

        $this->assertEquals([$this->left() => 1], $football);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan config:clear && php artisan test tests/Feature/Registrations/ListRuleTest.php`
Expected: FAIL, `Call to undefined method ... listedWhere`.

- [ ] **Step 3: Add `noStatus` to the shared query**

In `app/Models/Player.php`, `scopeRegisteredWhere`, add the parameter after `?Closure $also = null,` (from Part 3):

```php
        bool $noStatus = false,
```

Replace this line in the `$rows` query:

```php
            ->when($statuses !== null, fn ($q) => $q->withStatuses($statuses))
```

with:

```php
            ->when($statuses !== null && ! $noStatus, fn ($q) => $q->withStatuses($statuses))
            ->when($noStatus, fn ($q) => $q->where(fn ($s) => $s->whereNull('branch_player.status_id')
                ->when($statuses !== null, fn ($s) => $s->orWhereIn('branch_player.status_id', collect($statuses)->all()))))
```

Add `$noStatus` to both closures' `use (...)` lists. In the fallback, replace:

```php
                    ->when($statuses !== null, fn ($q) => $q->whereIn($column('status_id'), collect($statuses)->all()))
```

with:

```php
                    ->when($statuses !== null && ! $noStatus, fn ($q) => $q->whereIn($column('status_id'), collect($statuses)->all()))
                    ->when($noStatus, fn ($q) => $q->where(fn ($s) => $s->whereNull($column('status_id'))
                        ->when($statuses !== null, fn ($s) => $s->orWhereIn($column('status_id'), collect($statuses)->all()))))
```

- [ ] **Step 4: Add the list rule**

In `app/Models/Player.php`, after `scopeRegisteredWhere`:

```php
    /**
     * What a list's filters mean. With a branch, every condition holds on that
     * branch's registration. Club-wide, a status is the person's overall status
     * (left only when left everywhere), and a category is any registration still
     * active, or any at all when the list asks for left people or shows everyone.
     */
    public function scopeListedWhere(
        Builder $query,
        ?array $branches = null,
        ?array $categories = null,
        ?array $statuses = null,
        bool $noStatus = false,
        bool $hideLeft = false,
    ): void {
        $statusAsked = $statuses !== null || $noStatus;

        if ($branches !== null) {
            $query->registeredWhere(branches: $branches, categories: $categories, statuses: $statuses, active: $hideLeft && ! $statusAsked, noStatus: $noStatus);

            return;
        }

        $column = $query->getModel()->qualifyColumn('status_id');
        if ($statusAsked) {
            $query->where(fn (Builder $q) => $q
                ->when($noStatus, fn ($q) => $q->orWhereNull($column))
                ->when($statuses !== null, fn ($q) => $q->orWhereIn($column, $statuses)));
        } elseif ($hideLeft) {
            $query->notLeft();
        }

        if ($categories !== null) {
            $askedLeft = in_array(static::leftStatusId(), $statuses ?? [], false);
            $query->registeredWhere(categories: $categories, active: $statusAsked ? ! $askedLeft : $hideLeft);
        }
    }
```

- [ ] **Step 5: Add the overlay**

In `app/Services/Player/RegistrationDisplay.php`, after `values()`:

```php
    /**
     * Put one branch's values on the player, for a row or a printed page: the
     * attributes, and the relations the caller loaded on the registration. Not for
     * saving. No row in that branch, or no branch asked: nothing changes, because
     * players.* already holds the main registration.
     */
    public function overlay(Player $player, ?int $branchId): Player
    {
        $row = $player->registrationIn($branchId);
        if ($row === null) {
            return $player;
        }

        $player->category_id = $row->category_id;
        $player->position_id = $row->position_id;
        $player->licence_number = $row->licence_number;
        $player->status_id = $row->status_id;
        $player->left_at = $row->left_at?->toDateString();
        $player->membership_id = $row->membership_id ?? $player->membership_id;

        foreach (['category', 'position', 'status'] as $relation) {
            if ($row->relationLoaded($relation)) {
                $player->setRelation($relation, $row->getRelation($relation));
            }
        }
        if ($player->relationLoaded('otherPositions')) {
            $player->setRelation('otherPositions', $player->otherPositions
                ->filter(fn ($position) => $position->branch_id === null || (int) $position->branch_id === $branchId)
                ->values());
        }

        return $player;
    }
```

- [ ] **Step 6: Write `RegistrationCounts`**

`app/Services/Player/RegistrationCounts.php`:

```php
<?php

namespace App\Services\Player;

use App\Models\BranchRegistration;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures counted per registration, in SQL: a two-branch player counts once in
 * each. Players with no registration count once, on their own columns.
 */
final class RegistrationCounts
{
    /**
     * One row per registration of these players. Left registrations are out
     * unless asked for.
     *
     * @param  list<int|string>|null  $branches  null: every branch
     */
    public static function rows(EloquentBuilder|QueryBuilder $players, ?array $branches = null, bool $includeLeft = false): QueryBuilder
    {
        $ids = clone $players;
        $ids = $ids instanceof EloquentBuilder ? $ids->toBase() : $ids;

        $query = DB::query()->fromSub(BranchRegistration::everyRow(), 'reg')
            ->whereIn('reg.player_id', $ids->reorder()->select('players.id'))
            ->when($branches !== null, fn ($q) => $q->whereIn('reg.branch_id', $branches));

        return $includeLeft ? $query : BranchRegistration::constrainActive($query, 'reg.status_id');
    }

    /** @return Collection<int, object{key: int|null, name: string|null, total: int}> */
    public static function byCategory(EloquentBuilder|QueryBuilder $players, ?array $branches = null, bool $includeLeft = false): Collection
    {
        return self::grouped(self::rows($players, $branches, $includeLeft), 'categories', 'category_id');
    }

    /** @return Collection<int, object{key: int|null, name: string|null, total: int}> */
    public static function byStatus(EloquentBuilder|QueryBuilder $players, ?array $branches = null, bool $includeLeft = false): Collection
    {
        return self::grouped(self::rows($players, $branches, $includeLeft), 'player_statuses', 'status_id');
    }

    /** The locale's name, falling back to the base name, chosen in SQL like the dashboard's lookups. */
    private static function grouped(QueryBuilder $rows, string $table, string $foreignKey): Collection
    {
        $locale = in_array(app()->getLocale(), ['ar', 'fr', 'en'], true) ? app()->getLocale() : 'en';
        $column = "{$table}.name_{$locale}";

        return $rows->leftJoin($table, "{$table}.id", '=', "reg.{$foreignKey}")
            ->groupBy("reg.{$foreignKey}", "{$table}.name", $column)
            ->selectRaw("reg.{$foreignKey} as lookup_key, COALESCE(NULLIF({$column}, ''), {$table}.name) as name, COUNT(*) as total")
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row) => (object) ['key' => $row->lookup_key === null ? null : (int) $row->lookup_key, 'name' => $row->name, 'total' => (int) $row->total]);
    }
}
```

- [ ] **Step 7: Run the test**

Run: `php artisan test tests/Feature/Registrations/ListRuleTest.php tests/Feature/Registrations/SharedQueryTest.php`
Expected: all PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Models/Player.php app/Services/Player/RegistrationDisplay.php app/Services/Player/RegistrationCounts.php tests/Feature/Registrations/ListRuleTest.php
git commit -m "feat(registrations): one rule for list filters, per-registration counts

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P6.2: Players list, export and print

**Files:**
- Create: `tests/Feature/Registrations/PlayersListRegistrationsTest.php`
- Modify: `app/Http/Controllers/PlayerController.php` (`index`, `export`, `printList`, `applyPlayerFilters`, new helpers)
- Modify: `resources/js/Components/StatBars.vue`, `resources/js/Pages/Players/Index.vue`
- Modify: `resources/js/i18n/ar.json`, `en.json`, `fr.json` (through `scripts/i18n-add.mjs`)

**Interfaces:**
- Consumes: `Player::scopeListedWhere`, `RegistrationDisplay::{make,overlay}`, `RegistrationCounts`, and Part 5's `PlayerController::selectedBranch(Request): ?int`.
- Produces: i18n key `chart_note.bars_can_exceed_total`, used by Tasks P6.3 and P6.4. `StatBars` optional prop `note: String`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/PlayersListRegistrationsTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class PlayersListRegistrationsTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));
    }

    /** @return array<string, mixed> */
    private function props(array $query = []): array
    {
        $props = [];
        $this->get(route('players.index', $query))->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    private function rowNames(array $query = []): array
    {
        return collect($this->props($query)['players']['data'])->pluck('firstname')->all();
    }

    private function swimId(Player $player, string $id = 'SW-2020-00001'): void
    {
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => $id]);
    }

    #[Test]
    public function swimming_and_senior_no_longer_match_a_football_senior(): void
    {
        $this->bothActive();

        $this->assertSame([], $this->rowNames(['branch_id' => [$this->swim->id], 'category_id' => [$this->senior->id]]));
        $this->assertSame(['Both'], $this->rowNames(['branch_id' => [$this->swim->id], 'category_id' => [$this->u15->id]]));
    }

    #[Test]
    public function a_two_branch_player_is_one_row(): void
    {
        $this->bothActive();

        $this->assertSame(['Both'], $this->rowNames());
        $this->assertSame(['Both'], $this->rowNames(['branch_id' => [$this->foot->id, $this->swim->id]]));
    }

    #[Test]
    public function one_branch_shows_that_registrations_values(): void
    {
        $this->swimId($this->bothActive());

        $row = $this->props(['branch_id' => [$this->swim->id]])['players']['data'][0];

        $this->assertSame('SW-2020-00001', $row['membership_id']);
        $this->assertSame($this->u15->id, $row['category_id']);
        $this->assertSame('U15', $row['category']['name']);
        $this->assertArrayNotHasKey('registrations', $row);
    }

    #[Test]
    public function no_branch_or_several_show_the_main_values(): void
    {
        $this->swimId($this->bothActive());

        foreach ([[], ['branch_id' => [$this->foot->id, $this->swim->id]]] as $query) {
            $row = $this->props($query)['players']['data'][0];
            $this->assertSame($this->senior->id, $row['category_id']);
            $this->assertStringStartsWith('FB-', $row['membership_id']);
        }
    }

    #[Test]
    public function a_player_who_left_one_branch_is_hidden_only_there(): void
    {
        $this->leftFootball();

        $this->assertSame([], $this->rowNames(['branch_id' => [$this->foot->id]]));
        $this->assertSame(['LeftFoot'], $this->rowNames(['branch_id' => [$this->swim->id]]));
        $this->assertSame(['LeftFoot'], $this->rowNames());
    }

    #[Test]
    public function asking_for_left_in_a_branch_finds_who_left_that_branch(): void
    {
        $this->leftFootball();
        $left = PlayerStatus::where('code', 'left')->value('id');

        $this->assertSame(['LeftFoot'], $this->rowNames(['branch_id' => [$this->foot->id], 'status' => [$left]]));
        $this->assertSame([], $this->rowNames(['status' => [$left]]));
    }

    #[Test]
    public function left_everywhere_stays_out_until_asked_for(): void
    {
        $this->leftBoth();
        $left = PlayerStatus::where('code', 'left')->value('id');

        $this->assertSame([], $this->rowNames());
        $this->assertSame(['LeftBoth'], $this->rowNames(['status' => [$left]]));
    }

    #[Test]
    public function a_player_with_no_registration_keeps_working(): void
    {
        $this->noRegistration();

        $this->assertSame(['Flat'], $this->rowNames(['category_id' => [$this->senior->id]]));
        $this->assertSame([], $this->rowNames(['branch_id' => [$this->foot->id]]));
    }

    #[Test]
    public function the_category_chart_counts_both_bars_club_wide_and_the_branchs_row_with_one(): void
    {
        $this->bothActive();

        $bars = fn (array $query) => collect($this->props($query)['categoryStats'])->pluck('count', 'category_id')->all();

        $this->assertEquals([$this->senior->id => 1, $this->u15->id => 1], $bars([]));
        $this->assertEquals([$this->u15->id => 1], $bars(['branch_id' => [$this->swim->id]]));
    }

    #[Test]
    public function the_status_chart_is_the_person_club_wide_and_the_registration_with_one_branch(): void
    {
        $this->leftFootball();
        $left = PlayerStatus::where('code', 'left')->value('id');
        $registered = PlayerStatus::where('code', 'registered')->value('id');

        $bars = fn (array $query) => collect($this->props($query)['statusStats'])->pluck('count', 'status_id')->all();

        $this->assertEquals([$registered => 1], $bars([]));
        $this->assertEquals([$left => 1], $bars(['branch_id' => [$this->foot->id]]));
    }

    #[Test]
    public function the_position_filter_finds_a_second_branchs_main_position(): void
    {
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['position_id' => $crawl->id]);

        $this->assertSame(['Both'], $this->rowNames(['position_id' => [$crawl->id]]));
    }

    #[Test]
    public function the_export_follows_the_one_branch(): void
    {
        $this->swimId($this->bothActive());

        $swim = $this->get(route('players.export', ['branch_id' => [$this->swim->id], 'format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('SW-2020-00001', $swim);
        $this->assertStringContainsString('U15', $swim);

        $all = $this->get(route('players.export', ['format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('FB-2020-', $all);
    }

    #[Test]
    public function the_print_list_follows_the_one_branch(): void
    {
        $this->swimId($this->bothActive());

        $this->get(route('players.print-list', ['branch_id' => [$this->swim->id]]))->assertOk();
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/PlayersListRegistrationsTest.php`
Expected: FAIL on `swimming_and_senior_no_longer_match_a_football_senior` and the display tests.

- [ ] **Step 3: Move the filters to the list rule**

In `app/Http/Controllers/PlayerController.php`, `applyPlayerFilters`:

Delete the `category_id` block (`if ($categoryIds = $ids($pick('category_id'))) { ... }`), and the `branch_id` block (`if ($branchIds = $ids($pick('branch_id'))) { ... }`).

Replace the whole status block, from `$statuses = $pick('status');` to the closing brace of the `elseif`, with:

```php
        $statuses = $pick('status');

        // Branch, category and status go together: with a branch, all three hold on
        // that branch's registration. "Left the club" stays out of sight until a
        // status is picked. A search still finds them (the row shows its status),
        // and the archived view is its own bucket, shown whole.
        $query->listedWhere(
            branches: $ids($pick('branch_id')) ?: null,
            categories: $ids($pick('category_id')) ?: null,
            statuses: $ids($statuses) ?: null,
            noStatus: in_array('none', $statuses, true),
            hideLeft: $statuses === [] && $hideLeftByDefault && ! $searching && ! $archivedView,
        );
```

Replace the position block with:

```php
        if ($positionIds = $ids($pick('position_id'))) {
            // "Plays X" means a main position on any registration, or one of the others.
            $query->where(fn ($q) => $q->whereIn('position_id', $positionIds)
                ->orWhereHas('registrations', fn ($r) => $r->whereIn('position_id', $positionIds))
                ->orWhereHas('otherPositions', fn ($p) => $p->whereIn('positions.id', $positionIds)));
        }
```

- [ ] **Step 4: Add the two helpers**

After `filterSpecs()`:

```php
    /** @return list<int>|null the branches the list is filtered on */
    private function filteredBranches(Request $request): ?array
    {
        $values = ListFilter::get($request, 'branch_id', $this->filterSpecs()['branch_id']);

        return array_values(array_map('intval', array_filter($values, 'ctype_digit'))) ?: null;
    }

    /** Whether the list is showing left people, so per-registration charts count them too. */
    private function chartsIncludeLeft(Request $request): bool
    {
        $statuses = ListFilter::get($request, 'status', $this->filterSpecs()['status']);

        return in_array((string) Player::leftStatusId(), $statuses, true)
            || ($request->filled('search') && is_string($request->input('search')))
            || ($request->has('archived') && $request->boolean('archived'));
    }

    /** The eager load behind RegistrationDisplay::overlay, only when one branch is shown. */
    private function withShownRegistration($query, ?int $branchId): void
    {
        if ($branchId !== null) {
            $query->with([
                'registrations:id,player_id,branch_id,membership_id,category_id,position_id,licence_number,status_id,left_at',
                'registrations.category:id,name,name_ar,name_fr,name_en',
                'registrations.position:id,name,abbreviation,branch_id',
                'registrations.status:id,name,name_ar,name_fr,name_en,sort_order,is_active,code',
            ]);
        }
    }
```

Add `use App\Services\Player\RegistrationCounts;`. Parts 2 and 5 already added `RegistrationDisplay`, and Part 5 added `selectedBranch($request)`, which gives the one branch a filter means.

- [ ] **Step 5: Show the one branch's values in the rows, and move the charts**

In `index()`, after `$this->applyPlayerFilters($query, $request);`:

```php
        // One branch selected: each row shows that registration. None or several: the main one, on players.*.
        $shownBranch = $this->selectedBranch($request);
        $this->withShownRegistration($query, $shownBranch);
```

Replace the line `$players->getCollection()->each->setAppends(['fullname']);` with:

```php
        $display = RegistrationDisplay::make();
        $players->getCollection()->each(function (Player $player) use ($display, $shownBranch) {
            $player->setAppends(['fullname']);
            if ($shownBranch !== null) {
                $display->overlay($player, $shownBranch)->unsetRelation('registrations');
            }
        });
```

Replace the `$categoryStats = ...` statement with:

```php
        // Per registration: a two-branch player counts in both bars (the page says so).
        $categoryStats = RegistrationCounts::byCategory(
            $this->statsQuery($request, 'category_id'),
            $this->filteredBranches($request),
            $this->chartsIncludeLeft($request),
        )
            ->map(fn (object $row) => ['category_id' => $row->key, 'name' => $row->name, 'count' => $row->total])
            ->values();
```

Replace the `$statusCol = ...` line and the `$statusStats = ...` statement with:

```php
        // One branch: that registration's status. Otherwise once per person, overall.
        $statusCol = 'player_statuses.name_'.$locale;
        $statusStats = ($shownBranch !== null
            ? RegistrationCounts::byStatus($this->statsQuery($request, 'status'), [$shownBranch], includeLeft: true)
                ->map(fn (object $row) => ['status_id' => $row->key, 'name' => $row->name, 'count' => $row->total])
            : $this->statsQuery($request, 'status')
                ->leftJoin('player_statuses', 'player_statuses.id', '=', 'players.status_id')
                ->groupBy('players.status_id', 'player_statuses.name', $statusCol)
                ->selectRaw("players.status_id, COALESCE(NULLIF({$statusCol}, ''), player_statuses.name) as name, COUNT(*) as total")
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row) => [
                    'status_id' => $row->status_id,
                    'name' => $row->name,
                    'count' => (int) $row->total,
                ]))
            ->values();
```

Replace the `$positionStats = ...` statement with:

```php
        $positionRows = $shownBranch !== null
            ? RegistrationCounts::rows($this->statsQuery($request, 'position_id'), [$shownBranch], $this->chartsIncludeLeft($request))
                ->leftJoin('positions', 'positions.id', '=', 'reg.position_id')
                ->groupBy('reg.position_id', 'positions.name')
                ->selectRaw('reg.position_id as position_id, positions.name, COUNT(*) as total')
            : $this->statsQuery($request, 'position_id')
                ->leftJoin('positions', 'positions.id', '=', 'players.position_id')
                ->groupBy('players.position_id', 'positions.name')
                ->selectRaw('players.position_id, positions.name, COUNT(*) as total');
        $positionStats = $positionRows
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'position_id' => $row->position_id,
                'name' => $row->name,
                'count' => (int) $row->total,
            ])
            ->values();
```

`$locale` is defined before these statements. Delete the old `$localeCol` line only if nothing else uses it: `grep -n localeCol app/Http/Controllers/PlayerController.php`.

- [ ] **Step 6: Export and print**

In `export()`, after `$this->applyPlayerFilters($query, $request);`:

```php
        $shownBranch = $this->selectedBranch($request);
        $this->withShownRegistration($query, $shownBranch);
        $display = RegistrationDisplay::make();
```

and change the start of the row mapping to apply it:

```php
        $rows = $query->orderBy('lastname')->orderBy('firstname')->get()->map(function (Player $p) use ($display, $shownBranch) {
            $display->overlay($p, $shownBranch);

            return [
                $p->membership_id,
                $p->wilaya?->localized_name,
                $p->position?->abbreviation,
                $p->otherPositions->pluck('abbreviation')->implode(', '),
                $p->fullname,
                $p->category?->localized_name,
                $p->status?->localized_name,
                $p->is_student ? UiLang::get('student', 'student') : UiLang::get('worker', 'worker'),
                $p->join_year !== null ? (int) $p->join_year : null,
                (float) $p->outstanding_debt,
                collect($p->phones ?? [])->implode(' / '),
                $p->branches->map(fn (Branch $b) => $b->localized_name)->implode(' / '),
                $p->memberJob?->localized_name,
            ];
        });
```

In `printList()`, replace these two lines:

```php
        $players = $query->orderBy('lastname')->orderBy('firstname')->get();

        $spec = $this->filterSpecs();
        $branchIds = array_filter(ListFilter::get($request, 'branch_id', $spec['branch_id']), 'ctype_digit');
        $branch = count($branchIds) === 1 ? Branch::find((int) reset($branchIds)) : null;
```

with:

```php
        $shownBranch = $this->selectedBranch($request);
        $this->withShownRegistration($query, $shownBranch);
        $display = RegistrationDisplay::make();
        $players = $query->orderBy('lastname')->orderBy('firstname')->get()
            ->each(fn (Player $player) => $display->overlay($player, $shownBranch));

        $spec = $this->filterSpecs();
        $branch = $shownBranch === null ? null : Branch::find($shownBranch);
```

- [ ] **Step 7: The note on the chart**

Write `.superpowers/r2/p6-keys.json`:

```json
{
    "chart_note.bars_can_exceed_total": {
        "ar": "قد يتجاوز مجموع الفئات عدد الأعضاء: من ينتمي إلى فرعين يُحسب مرة في كل فرع.",
        "fr": "Le total des catégories peut dépasser le nombre de membres : une personne inscrite dans deux sections compte une fois dans chacune.",
        "en": "Category counts can add up to more than the member total: someone in two branches counts once in each."
    },
    "member_card_pick_branch": {
        "ar": "اختر فرع البطاقة",
        "fr": "Choisir la section de la carte",
        "en": "Choose the card's branch"
    }
}
```

Run: `node scripts/i18n-add.mjs .superpowers/r2/p6-keys.json`

In `resources/js/Components/StatBars.vue`, add to `defineProps`, after `format`:

```js
    // A short remark under the title.
    note: { type: String, default: null },
```

Add just above `<p v-if="!stats.length" ...`:

```vue
        <p v-if="note" class="mb-2 text-xs text-slate-500 dark:text-slate-400">{{ note }}</p>
```

In `resources/js/Pages/Players/Index.vue`, replace line 394's `StatBars` with:

```vue
                    <StatBars v-if="categoryChips.length || hasActiveFilters" v-model="categoryFilter" :title="t('by_category')" :stats="categoryChips" :note="!branchFilter.length && branches.length > 1 ? t('chart_note.bars_can_exceed_total') : null" class="xl:col-span-3" />
```

- [ ] **Step 8: Run the tests**

Run:

```bash
php artisan test tests/Feature/Registrations/PlayersListRegistrationsTest.php tests/Feature/PlayerListFiltersTest.php tests/Feature/PlayerMultiSelectFiltersTest.php tests/Feature/PlayerStatsFilterTest.php tests/Feature/PlayerSearchTest.php tests/Feature/BranchFeatureTest.php
npm run i18n:check
```

Expected: all PASS. Existing tests use `branches()->attach` on flat players, and the creating hook copies their values to the row, so they hold. If one changes a player's category **after** attaching, update that test to change the registration too, because the registration is now what is read.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/PlayerController.php resources/js/Components/StatBars.vue resources/js/Pages/Players/Index.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Registrations/PlayersListRegistrationsTest.php
git commit -m "feat(players): the list, export and print read the registration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P6.3: Reports: Directory, Movement, Demographics, Birthdays

**Files:**
- Create: `tests/Feature/Registrations/PlayerReportsRegistrationsTest.php`
- Modify: `app/Reports/Report.php`, `app/Reports/Concerns/FiltersPlayers.php`
- Modify: `app/Reports/Players/PlayerDirectory.php`, `PlayerMovement.php`, `PlayerDemographics.php`, `PlayerBirthdays.php`
- Modify: `app/Http/Controllers/ReportsCenterController.php`, `resources/js/Pages/Reports/Show.vue`

**Interfaces:**
- Consumes: `Player::scopeListedWhere`, `RegistrationDisplay`, `RegistrationCounts`, `BranchRegistration` scopes, `chart_note.bars_can_exceed_total`.
- Produces: `Report::note(ReportFilters $filters): ?string`, an i18n key shown above a report's results. `FiltersPlayers::shownBranch(ReportFilters): ?int`, `FiltersPlayers::overlapNote(ReportFilters): ?string`, `FiltersPlayers::listFilters(ReportFilters): array`, `FiltersPlayers::includesLeft(ReportFilters): bool`.
- `PlayerDocuments` calls `applyCategoryBranch($query, $filters)` with the defaults, and that keeps working. Its checklist belongs to Part 5.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/PlayerReportsRegistrationsTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class PlayerReportsRegistrationsTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create());
        $this->travelTo('2026-05-14 10:00:00');
    }

    /** @return array<string, mixed> */
    private function report(string $key, array $query = []): array
    {
        $props = [];
        $this->get(route('reports.view', ['players', $key, ...$query]))->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    private function swimId(Player $player): void
    {
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => 'SW-2020-00001']);
    }

    #[Test]
    public function directory_filters_on_one_registration_and_shows_its_values(): void
    {
        $this->swimId($this->bothActive());

        $this->assertCount(0, $this->report('directory', ['branch_id' => [$this->swim->id], 'category_id' => [$this->senior->id]])['rows']['data']);

        $row = $this->report('directory', ['branch_id' => [$this->swim->id]])['rows']['data'][0];
        $this->assertSame('SW-2020-00001', $row['membership_id']);
        $this->assertSame('U15', $row['category']);
    }

    #[Test]
    public function directory_with_no_branch_shows_main_values_one_row_per_person(): void
    {
        $this->bothActive();

        $rows = $this->report('directory')['rows']['data'];

        $this->assertCount(1, $rows);
        $this->assertSame('Senior', $rows[0]['category']);
    }

    #[Test]
    public function directory_category_chart_counts_both_and_says_so(): void
    {
        $this->bothActive();

        $props = $this->report('directory');
        $bars = collect($props['charts'][0]['stats'])->pluck('count', 'label')->all();

        $this->assertEquals(['Senior' => 1, 'U15' => 1], $bars);
        $this->assertSame('chart_note.bars_can_exceed_total', $props['note']);
        $this->assertNull($this->report('directory', ['branch_id' => [$this->swim->id]])['note']);
        $this->assertSame(1, $props['summary'][0]['value']);
    }

    #[Test]
    public function directory_status_is_the_registrations_with_a_branch_and_the_persons_without(): void
    {
        $this->leftFootball();
        $left = PlayerStatus::where('code', 'left')->value('id');

        $this->assertCount(1, $this->report('directory', ['branch_id' => [$this->foot->id], 'status_id' => [$left]])['rows']['data']);
        $this->assertCount(0, $this->report('directory', ['status_id' => [$left]])['rows']['data']);
    }

    #[Test]
    public function movement_left_means_everywhere_club_wide_and_that_branch_with_one(): void
    {
        $this->leftFootball();
        $query = ['movement' => 'left', 'year' => 2026];

        $this->assertCount(0, $this->report('movement', $query)['rows']['data']);

        $row = $this->report('movement', [...$query, 'branch_id' => [$this->foot->id]])['rows']['data'][0];
        $this->assertSame($this->leaveDate, $row['left_at']);
        $this->assertSame('Senior', $row['category']);
        $this->assertCount(0, $this->report('movement', [...$query, 'branch_id' => [$this->swim->id]])['rows']['data']);
    }

    #[Test]
    public function movement_left_everywhere_is_listed_club_wide(): void
    {
        $this->leftBoth();

        $this->assertCount(1, $this->report('movement', ['movement' => 'left', 'year' => 2026])['rows']['data']);
    }

    #[Test]
    public function movement_charts_group_leave_years_on_the_shown_branch(): void
    {
        $this->leftFootball();

        $props = $this->report('movement', ['movement' => 'left', 'year' => 2026, 'branch_id' => [$this->foot->id]]);

        $this->assertSame([['key' => '2026', 'label' => '2026', 'count' => 1]], $props['charts'][1]['stats']);
    }

    #[Test]
    public function demographics_table_counts_registrations_and_the_total_counts_people(): void
    {
        $this->bothActive();
        $this->noRegistration();

        $props = $this->report('demographics');
        $byCategory = collect($props['rows']['data'])->pluck('total', 'category')->all();

        $this->assertEquals(['Senior' => 2, 'U15' => 1], $byCategory);
        $this->assertSame(2, $props['totals']['total']);
        $this->assertSame(2, $props['summary'][0]['value']);
        $this->assertSame('chart_note.bars_can_exceed_total', $props['note']);
    }

    #[Test]
    public function demographics_of_one_branch_uses_its_categories(): void
    {
        $this->bothActive();

        $byCategory = collect($this->report('demographics', ['branch_id' => [$this->swim->id]])['rows']['data'])->pluck('total', 'category')->all();

        $this->assertSame(['U15' => 1], $byCategory);
    }

    #[Test]
    public function demographics_leaves_out_left_rows_unless_left_is_picked(): void
    {
        $this->leftFootball();
        $left = PlayerStatus::where('code', 'left')->value('id');
        $this->leftBoth();

        $this->assertSame(['U15' => 1], collect($this->report('demographics')['rows']['data'])->pluck('total', 'category')->all());
        $this->assertEquals(['Senior' => 1, 'U15' => 1], collect($this->report('demographics', ['status_id' => [$left]])['rows']['data'])->pluck('total', 'category')->all());
    }

    #[Test]
    public function birthdays_show_the_one_branchs_category(): void
    {
        $player = $this->bothActive();
        $player->update(['birthdate' => '2010-05-20']);

        $this->assertSame('U15', $this->report('birthdays', ['month' => 5, 'branch_id' => [$this->swim->id]])['rows']['data'][0]['category']);
        $this->assertSame('Senior', $this->report('birthdays', ['month' => 5])['rows']['data'][0]['category']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/PlayerReportsRegistrationsTest.php`
Expected: FAIL.

- [ ] **Step 3: The page note**

In `app/Reports/Report.php`, after `description()`:

```php
    /** i18n key of a remark shown above the results, or null. */
    public function note(ReportFilters $filters): ?string
    {
        return null;
    }
```

In `app/Http/Controllers/ReportsCenterController.php`, add after `'charts' => $report->charts($filters),`:

```php
            'note' => $report->note($filters),
```

In `resources/js/Pages/Reports/Show.vue`, add to `defineProps` after `report: Object,`:

```js
    note: { type: String, default: null },
```

Add just above `<div v-if="charts.length" class="flex justify-end print:hidden">`:

```vue
            <p v-if="note" class="text-sm text-slate-500 dark:text-slate-400">{{ t(note) }}</p>
```

- [ ] **Step 4: Rewrite `FiltersPlayers`**

Replace `applyCategoryBranch` in `app/Reports/Concerns/FiltersPlayers.php`, drop the `DB` import, and add `use App\Services\Player\RegistrationDisplay;` and `use App\Models\Player;`:

```php
    /** Narrow a query on players by the shared list rule. Other tables use their own query. */
    protected function applyCategoryBranch(Builder $query, ReportFilters $filters): Builder
    {
        return $query->listedWhere(...$this->listFilters($filters));
    }

    /** @return array{branches: ?array, categories: ?array} */
    protected function listFilters(ReportFilters $filters): array
    {
        return [
            'branches' => $filters->filled('branch_id') ? (array) $filters->get('branch_id') : null,
            'categories' => $filters->filled('category_id') ? (array) $filters->get('category_id') : null,
        ];
    }

    /** The branch whose values the rows show: exactly one selected. */
    protected function shownBranch(ReportFilters $filters): ?int
    {
        return RegistrationDisplay::singleBranch($filters->filled('branch_id') ? (array) $filters->get('branch_id') : null);
    }

    /** Whether the status filter asks for left people. */
    protected function includesLeft(ReportFilters $filters): bool
    {
        return $filters->filled('status_id') && in_array(Player::leftStatusId(), (array) $filters->get('status_id'), false);
    }

    /** The remark for per-registration category figures, when they can exceed the people total. */
    protected function overlapNote(ReportFilters $filters): ?string
    {
        return ! $filters->filled('branch_id') && Branch::count() > 1 ? 'chart_note.bars_can_exceed_total' : null;
    }
```

Before relying on the shorter signature, confirm no caller passes extra arguments: `grep -rn "applyCategoryBranch(" app`. All callers at the time of writing use two arguments.

- [ ] **Step 5: Directory**

In `app/Reports/Players/PlayerDirectory.php`: add `use App\Reports\Concerns\FiltersPlayers;`, `use App\Services\Player\RegistrationCounts;`, `use App\Services\Player\RegistrationDisplay;`, drop `use Illuminate\Support\Facades\DB;`, and add `use FiltersPlayers;` inside the class. Add these properties at the top of the class:

```php
    /** Set by rows(): the runner calls row() on this same instance. */
    private ?int $rowBranch = null;

    private ?RegistrationDisplay $display = null;
```

Replace `rows()` and the first line of `row()`:

```php
    public function rows(ReportFilters $filters): Builder
    {
        $this->rowBranch = $this->shownBranch($filters);
        $this->display = RegistrationDisplay::make();

        return $this->base($filters)
            ->with(['category', 'status', 'branches'])
            ->when($this->rowBranch !== null, fn ($q) => $q->with(['registrations.category', 'registrations.status']));
    }

    /** @param Player $record */
    public function row(mixed $record): array
    {
        $this->display->overlay($record, $this->rowBranch);

        return [
```

Add after `defaultSort()`:

```php
    public function note(ReportFilters $filters): ?string
    {
        return $this->overlapNote($filters);
    }
```

In `charts()`, replace the `$byCategory` and `$names` statements and the `bar` entry with:

```php
        $byCategory = RegistrationCounts::byCategory(
            $this->base($filters),
            $this->listFilters($filters)['branches'],
            $this->includesLeft($filters),
        );
```

and:

```php
            ['type' => 'bar', 'title' => 'reports.chart.by_category', 'stats' => $byCategory->map(fn (object $r) => [
                'key' => (int) $r->key,
                'label' => $r->name ?? UiLang::get('reports.none'),
                'count' => $r->total,
            ])->values()->all()],
```

In `base()`, replace the `category_id`, `branch_id` and `status_id` blocks with one call, placed where the `category_id` block was:

```php
        $query->listedWhere(
            ...$this->listFilters($filters),
            statuses: $filters->filled('status_id') ? (array) $filters->get('status_id') : null,
        );
```

`Category` stays imported, because `filters()` uses it.

- [ ] **Step 6: Movement**

In `app/Reports/Players/PlayerMovement.php` add `use App\Models\BranchRegistration;`, `use App\Services\Player\RegistrationDisplay;`, `use Illuminate\Support\Collection;`, and these properties:

```php
    private ?int $rowBranch = null;

    private ?RegistrationDisplay $display = null;
```

In `filters()`, replace the `$leftYears` line with:

```php
        $leftYears = BranchRegistration::query()->whereNotNull('left_at')->pluck('left_at')
            ->merge(Player::query()->whereNotNull('left_at')->pluck('left_at'))
            ->map(fn ($d) => (int) substr((string) $d, 0, 4));
```

Replace `rows()` and the start of `row()`:

```php
    public function rows(ReportFilters $filters): Builder
    {
        $this->rowBranch = $this->shownBranch($filters);
        $this->display = RegistrationDisplay::make();

        return $this->base($filters, (string) $filters->get('movement'))
            ->with(['category', 'status', 'branches'])
            ->when($this->rowBranch !== null, fn ($q) => $q->with(['registrations.category', 'registrations.status']));
    }

    /** @param Player $record */
    public function row(mixed $record): array
    {
        $this->display->overlay($record, $this->rowBranch);

        return [
```

Replace the rest of the file from `charts()` to the end of the class with:

```php
    public function charts(ReportFilters $filters): array
    {
        $joined = $this->applyCategoryBranch(Player::query()->whereNotNull('join_year'), $filters)->toBase()
            ->groupBy('join_year')->orderBy('join_year')->selectRaw('join_year as y, COUNT(*) as c')->get();

        $series = fn (Collection $rows) => $rows->map(fn ($r) => ['key' => (string) $r->y, 'label' => (string) $r->y, 'count' => (int) $r->c])->take(-10)->values()->all();

        return [
            ['type' => 'bar', 'title' => 'reports.chart.joins_by_year', 'stats' => $series($joined)],
            ['type' => 'bar', 'title' => 'reports.chart.leavers_by_year', 'stats' => $series($this->leftByYear($filters))],
        ];
    }

    /** Grouped in PHP: a year taken from a date has no form all three databases accept. */
    private function leftByYear(ReportFilters $filters): Collection
    {
        $dates = $filters->filled('branch_id')
            ? $this->leftRegistrations($filters)->pluck('left_at')
            : $this->applyCategoryBranch(Player::query()->whereNotNull('left_at'), $filters)->pluck('left_at');

        return $dates->countBy(fn ($date) => substr((string) $date, 0, 4))
            ->sortKeys()
            ->map(fn (int $count, string $year) => (object) ['y' => $year, 'c' => $count])
            ->values();
    }

    /** With a branch selected, "left" means left that branch, whatever the player's other branches. */
    private function leftRegistrations(ReportFilters $filters, ?int $year = null): Builder
    {
        return BranchRegistration::query()
            ->inBranches((array) $filters->get('branch_id'))
            ->whereNotNull('left_at')
            ->when($filters->filled('category_id'), fn ($q) => $q->inCategories((array) $filters->get('category_id')))
            ->when($year !== null, fn ($q) => $q->whereDate('left_at', '>=', $year.'-01-01')->whereDate('left_at', '<', ($year + 1).'-01-01'));
    }

    /** @return Builder<Player> */
    private function base(ReportFilters $filters, string $movement): Builder
    {
        $year = (int) $filters->get('year');

        if ($movement === 'left' && $filters->filled('branch_id')) {
            return Player::query()->whereIn('id', $this->leftRegistrations($filters, $year)->select('player_id'));
        }

        $query = $movement === 'left'
            ? Player::query()->whereDate('left_at', '>=', $year.'-01-01')->whereDate('left_at', '<', ($year + 1).'-01-01')
            : Player::query()->where('join_year', $year);

        return $this->applyCategoryBranch($query, $filters);
    }
}
```

- [ ] **Step 7: Demographics**

In `app/Reports/Players/PlayerDemographics.php` add `use App\Services\Player\RegistrationCounts;` and `use Illuminate\Database\Eloquent\Builder;`. Add after `landscape()`:

```php
    public function note(ReportFilters $filters): ?string
    {
        return $this->overlapNote($filters);
    }
```

Replace `rows()`:

```php
    public function rows(ReportFilters $filters): Collection
    {
        $names = Category::all()->keyBy('id');

        return $this->registrationRows($filters)->groupBy('category_id')->map(fn (Collection $group, $categoryId) => [
            'category' => $names->get($categoryId)?->localized_name ?? UiLang::get('reports.none'),
            'total' => $group->count(),
            'boys' => $group->where('gender', 'Male')->count(),
            'girls' => $group->where('gender', 'Female')->count(),
            'unknown' => $group->whereNotIn('gender', ['Male', 'Female'])->count(),
            'average_age' => $this->averageAge($group),
        ])->values();
    }
```

Replace `players()` with:

```php
    /** @return Builder<Player> who the report is about */
    private function population(ReportFilters $filters): Builder
    {
        return Player::query()->where('archived', false)->listedWhere(
            ...$this->listFilters($filters),
            statuses: $filters->filled('status_id') ? (array) $filters->get('status_id') : null,
        );
    }

    /** One row per registration: the table's rows, where a two-branch player counts in both. */
    private function registrationRows(ReportFilters $filters): Collection
    {
        return RegistrationCounts::rows($this->population($filters), $this->listFilters($filters)['branches'], $this->includesLeft($filters))
            ->join('players', 'players.id', '=', 'reg.player_id')
            ->get(['reg.player_id', 'reg.category_id', 'players.gender', 'players.birthdate']);
    }

    /** Once per person, for the totals and the tiles: those the rows count. */
    private function players(ReportFilters $filters): Collection
    {
        return $this->registrationRows($filters)->unique('player_id')->values();
    }
```

`totals()`, `summary()` and `charts()` keep calling `$this->players($filters)`.

- [ ] **Step 8: Birthdays**

In `app/Reports/Players/PlayerBirthdays.php`, add `use App\Services\Player\RegistrationDisplay;` and, in `rows()`, replace the query lines and the start of the map:

```php
        $branch = $this->shownBranch($filters);
        $display = RegistrationDisplay::make();

        $query = Player::query()->where('archived', false)->whereNotNull('birthdate')->with('category')
            ->when($branch !== null, fn ($q) => $q->with('registrations.category'));
        $this->applyCategoryBranch($query, $filters);

        return $query->get()
            ->filter(fn (Player $p) => (int) $p->birthdate->month === $month)
            ->each(fn (Player $p) => $display->overlay($p, $branch))
            ->map(fn (Player $p) => [
```

- [ ] **Step 9: Run the tests**

Run:

```bash
php artisan test tests/Feature/Registrations/PlayerReportsRegistrationsTest.php tests/Feature/Reports
```

Expected: all PASS. The existing `movement_lists_joins_by_join_year_and_leavers_by_date` and `demographics_count_players_per_category_and_gender` use flat players, so they hold through the fallback.

- [ ] **Step 10: Commit**

```bash
git add app/Reports/Report.php app/Reports/Concerns/FiltersPlayers.php app/Reports/Players/PlayerDirectory.php app/Reports/Players/PlayerMovement.php app/Reports/Players/PlayerDemographics.php app/Reports/Players/PlayerBirthdays.php app/Http/Controllers/ReportsCenterController.php resources/js/Pages/Reports/Show.vue tests/Feature/Registrations/PlayerReportsRegistrationsTest.php
git commit -m "feat(reports): player reports filter and show one registration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P6.4: Dashboard figures

**Files:**
- Create: `tests/Feature/Registrations/DashboardRegistrationsTest.php`
- Modify: `app/Services/Dashboard/MemberStats.php`
- Modify: `resources/js/Pages/Dashboard/Partials/MembersTab.vue`

**Interfaces:**
- Consumes: `RegistrationCounts`, `BranchRegistration::everyRow`, `chart_note.bars_can_exceed_total`.
- `ModuleStats::players` and `OverviewStats` need **no code change**: they read `players.*`, which is once per person, and `left_at` there is set only when left everywhere. `OverviewStats`' branch filters go through `branch_player` for debt lines and the feed, and debt survives leaving. The test below pins this.
- `DocumentStats::breakdown` by category is Part 5's work. Part 5 left the note on its chart to this task.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/DashboardRegistrationsTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\PlayerStatus;
use App\Services\Dashboard\DashboardFilters;
use App\Services\Dashboard\MemberStats;
use App\Services\Dashboard\ModuleStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class DashboardRegistrationsTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->travelTo('2026-05-14 10:00:00');
    }

    private function members(array $query = []): array
    {
        return app(MemberStats::class)->get(DashboardFilters::fromRequest(Request::create('/dashboard', 'GET', ['range' => 'all', ...$query])));
    }

    private function tile(array $data, string $key): mixed
    {
        return collect($data['summary'])->firstWhere('key', $key)['value'];
    }

    private function bars(array $rows): array
    {
        return collect($rows)->pluck('count', 'name')->all();
    }

    #[Test]
    public function total_members_counts_a_person_once_and_the_category_chart_both_bars(): void
    {
        $this->bothActive();

        $data = $this->members();

        $this->assertSame(1, $this->tile($data, 'total_members'));
        $this->assertEquals(['Senior' => 1, 'U15' => 1], $this->bars($data['byCategory']));
    }

    #[Test]
    public function one_branch_shows_its_own_categories(): void
    {
        $this->bothActive();

        $this->assertSame(['U15' => 1], $this->bars($this->members(['branch' => (string) $this->swim->id])['byCategory']));
    }

    #[Test]
    public function a_left_registration_leaves_the_category_chart_but_joins_left_by_category(): void
    {
        $this->leftFootball();

        $data = $this->members();

        $this->assertSame(['U15' => 1], $this->bars($data['byCategory']));
        $this->assertSame(['Senior' => 1], $this->bars($data['leftByCategory']));
    }

    #[Test]
    public function the_left_tile_means_left_everywhere_club_wide_and_that_branch_with_one(): void
    {
        $this->leftFootball();

        $this->assertSame(0, $this->tile($this->members(), 'left'));
        $this->assertSame(1, $this->tile($this->members(['branch' => (string) $this->foot->id]), 'left'));
        $this->assertSame(0, $this->tile($this->members(['branch' => (string) $this->swim->id]), 'left'));

        $this->leftBoth();
        $this->assertSame(1, $this->tile($this->members(), 'left'));
    }

    #[Test]
    public function status_is_overall_club_wide_and_the_registrations_with_a_branch(): void
    {
        $this->leftFootball();
        $names = PlayerStatus::all()->mapWithKeys(fn (PlayerStatus $status) => [$status->code => $status->localized_name]);

        $this->assertSame([$names['registered'] => 1], $this->bars($this->members()['byStatus']));
        $this->assertSame([$names['left'] => 1], $this->bars($this->members(['branch' => (string) $this->foot->id])['byStatus']));
    }

    #[Test]
    public function the_growth_chart_counts_a_branch_leaver_in_that_branch_only(): void
    {
        $this->leftFootball();

        $this->assertSame(0.0, array_sum($this->members()['growth']['left']));
        $this->assertSame(1.0, array_sum($this->members(['branch' => (string) $this->foot->id])['growth']['left']));
    }

    #[Test]
    public function a_player_with_no_registration_counts_on_their_own_columns(): void
    {
        $this->noRegistration();

        $data = $this->members();

        $this->assertSame(1, $this->tile($data, 'total_members'));
        $this->assertSame(['Senior' => 1], $this->bars($data['byCategory']));
    }

    #[Test]
    public function the_module_strip_counts_people_and_left_everywhere(): void
    {
        $this->bothActive('A');
        $this->leftFootball('B');
        $this->leftBoth('C');
        $tiles = collect(app(ModuleStats::class)->players())->keyBy('key');

        $this->assertSame(2, $tiles['players_active']['value']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/DashboardRegistrationsTest.php`
Expected: FAIL on the category, left-tile and status tests. `the_module_strip_counts_people_and_left_everywhere` passes already.

- [ ] **Step 3: Move `MemberStats`**

In `app/Services/Dashboard/MemberStats.php`, add these imports:

```php
use App\Models\BranchRegistration;
use App\Services\Player\RegistrationCounts;
use Illuminate\Database\Query\Builder as QueryBuilder;
```

Replace `leftPerWindow`:

```php
    /** @return array{current: int, previous: int} */
    private function leftPerWindow(DashboardFilters $filters): array
    {
        if ($filters->from === null) {
            return ['current' => (int) $this->leavers($filters)->count(), 'previous' => 0];
        }

        $row = $this->leavers($filters)
            ->selectRaw(
                'COUNT(CASE WHEN leavers.left_at BETWEEN ? AND ? THEN 1 END) as current_window, '
                .'COUNT(CASE WHEN leavers.left_at BETWEEN ? AND ? THEN 1 END) as previous_window',
                [
                    $filters->from->toDateString(), $filters->to->toDateString(),
                    $filters->prevFrom?->toDateString(), $filters->prevTo?->toDateString(),
                ],
            )
            ->first();

        return [
            'current' => (int) ($row->current_window ?? 0),
            'previous' => (int) ($row->previous_window ?? 0),
        ];
    }
```

Replace `leftByCategory` and `leavers`:

```php
    /**
     * Registrations that left in the window, per category: which teams lose
     * players. A member who left football but still swims counts here.
     */
    private function leftByCategory(DashboardFilters $filters): array
    {
        $localeColumn = 'categories.name_'.app()->getLocale();

        return DB::query()->fromSub(BranchRegistration::everyRow(), 'reg')
            ->whereNotNull('reg.left_at')
            ->when($filters->branchId !== null, fn ($q) => $q->where('reg.branch_id', $filters->branchId))
            ->when($filters->from !== null, fn ($q) => $q->whereBetween('reg.left_at', [$filters->from->toDateString(), $filters->to->toDateString()]))
            ->leftJoin('categories', 'categories.id', '=', 'reg.category_id')
            ->groupBy('categories.id', 'categories.name', $localeColumn)
            ->orderByDesc('total')
            ->selectRaw("COALESCE(NULLIF({$localeColumn}, ''), categories.name) as name, COUNT(*) as total")
            ->get()
            ->map(fn (object $row): array => [
                'name' => $row->name ?: null,
                'labelKey' => $row->name ? null : 'uncategorised',
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * Who left, with a recorded leave date, archived or not: a leaver who was
     * later archived still left. Club-wide that is people who left everywhere,
     * the only ones with a date on the player copy. With a branch, it is whoever
     * left that branch. Members marked "left" before leave dates were recorded
     * have none, and are not counted.
     */
    private function leavers(DashboardFilters $filters): QueryBuilder
    {
        $source = $filters->branchId === null
            ? DB::table('players')->whereNotNull('left_at')->select('left_at')
            : DB::table('branch_player')->where('branch_id', $filters->branchId)->whereNotNull('left_at')->select('left_at');

        return DB::query()->fromSub($source, 'leavers');
    }
```

In `growth()`, replace the `$leftBucket` and `$leftByMonth` statements with:

```php
        $leftBucket = MonthBucket::expression('leavers.left_at');
        $leftByMonth = $this->leavers($filters)
            ->where('leavers.left_at', '>=', CarbonImmutable::parse($labels[0].'-01')->toDateString())
            ->selectRaw("{$leftBucket} as bucket, COUNT(*) as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->all();
```

Replace `byCategory` and `byStatus`:

```php
    /** Per active registration: a two-branch player counts in both bars. The chart says so. */
    private function byCategory(DashboardFilters $filters): array
    {
        return $this->counted(RegistrationCounts::byCategory(
            $this->active($filters),
            $filters->branchId === null ? null : [$filters->branchId],
        ));
    }

    /** Club-wide: once per person, overall. With a branch: that registration's status. */
    private function byStatus(DashboardFilters $filters): array
    {
        return $filters->branchId === null
            ? $this->groupedByRelation($filters, 'player_statuses', 'players.status_id', 'player_statuses.id')
            : $this->counted(RegistrationCounts::byStatus($this->active($filters), [$filters->branchId], includeLeft: true));
    }

    /** @param  Collection<int, object>  $rows */
    private function counted(Collection $rows): array
    {
        return $rows
            ->map(fn (object $row): array => [
                'name' => $row->name ?: null,
                'labelKey' => $row->name ? null : 'uncategorised',
                'count' => $row->total,
            ])
            ->values()
            ->all();
    }
```

`RegistrationCounts::grouped` picks `en` for an unknown locale, while `groupedByRelation` uses the app locale as it stands. The app's locales are `ar`, `fr` and `en`, so the two agree.

- [ ] **Step 4: The note**

In `resources/js/Pages/Dashboard/Partials/MembersTab.vue`, add the same line to the `mem_by_category` `ChartCard` and the `stats.doc_by_category` `ChartCard`:

```vue
                :subtitle="branchId ? null : t('chart_note.bars_can_exceed_total')"
```

and replace the `mem_left_by_category` `ChartCard`'s subtitle with:

```vue
            :subtitle="branchId ? t('dashboard.mem_left_by_category_hint') : `${t('dashboard.mem_left_by_category_hint')} ${t('chart_note.bars_can_exceed_total')}`"
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/Registrations/DashboardRegistrationsTest.php tests/Feature/Dashboard`
Expected: all PASS. In `MemberStatsTest`, any test that writes `left_at` on a player **after** attaching a branch and then filters by that branch reads the registration now: change that test to call `RegistrationService::leave`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Dashboard/MemberStats.php resources/js/Pages/Dashboard/Partials/MembersTab.vue tests/Feature/Registrations/DashboardRegistrationsTest.php
git commit -m "feat(dashboard): member figures count registrations, left follows the branch

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P6.5: Literary report, board table, academic results sheet

**Files:**
- Modify: `app/Services/LiteraryReport/Sections/MembersSection.php`
- Modify: `app/Http/Controllers/PlayerPrintController.php`
- Modify: `app/Services/Player/AcademicResultsSheet.php`
- Modify: `tests/Feature/LiteraryReport/MembersSectionTest.php`, `tests/Feature/AcademicResultsPrintTest.php`

**Interfaces:**
- Consumes: `BranchRegistration::scopeActiveOn`, `Player::scopeRegisteredWhere`, `RegistrationFixtures`.
- `MembersSection` compares with `left_at > end` today, meaning someone who left on the last day is gone by the end of it. To keep that owner rule, it asks `activeOn` for the day after the period.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/LiteraryReport/MembersSectionTest.php`. Add `use App\Services\Player\RegistrationService;` and `use Tests\Support\RegistrationFixtures;`, and `use RegistrationFixtures;` next to the other traits:

```php
    private function byBranch(array $data): array
    {
        return collect($data['rows'])->mapWithKeys(fn ($r) => [($r['branch'] ?? '-').'/'.$r['category'] => $r['total']])->all();
    }

    #[Test]
    public function a_branch_row_shows_that_branchs_category(): void
    {
        $this->twoSportClub();
        $this->bothActive();

        $data = $this->build();

        $this->assertSame(1, $data['total']);
        $this->assertSame(['Football/Senior' => 1, 'Swimming/U15' => 1], $this->byBranch($data));
        $this->assertTrue($data['multi_branch']);
    }

    #[Test]
    public function a_branch_left_before_the_period_end_loses_its_row_only(): void
    {
        $this->twoSportClub();
        $this->leftFootball();

        $data = $this->build();

        $this->assertSame(1, $data['total']);
        $this->assertSame(['Swimming/U15' => 1], $this->byBranch($data));
        $this->assertFalse($data['multi_branch']);
    }

    #[Test]
    public function a_branch_left_after_the_period_end_keeps_its_row(): void
    {
        $this->twoSportClub();
        $player = $this->bothActive();
        app(RegistrationService::class)->leave($player, [$this->foot->id], '2026-10-01');

        $this->assertSame(['Football/Senior' => 1, 'Swimming/U15' => 1], $this->byBranch($this->build()));
    }

    #[Test]
    public function leaving_on_the_last_day_counts_as_gone(): void
    {
        $this->twoSportClub();
        $player = $this->bothActive();
        app(RegistrationService::class)->leave($player, [$this->foot->id], '2026-08-31');

        $this->assertSame(['Swimming/U15' => 1], $this->byBranch($this->build()));
    }

    #[Test]
    public function left_everywhere_is_not_a_member(): void
    {
        $this->twoSportClub();
        $this->leftBoth();

        $data = $this->build();

        $this->assertSame(0, $data['total']);
        $this->assertSame(1, $data['left']);
    }

    #[Test]
    public function no_registration_stays_in_the_no_branch_row(): void
    {
        $this->twoSportClub();
        $this->noRegistration();

        $this->assertSame(['-/Senior' => 1], $this->byBranch($this->build()));
    }
```

Add to `tests/Feature/AcademicResultsPrintTest.php`:

```php
    #[Test]
    public function a_chosen_category_finds_a_student_through_any_registration(): void
    {
        $football = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $swimming = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        $senior = Category::create(['name' => 'Senior']);
        $u15 = Category::create(['name' => 'U15']);
        $student = $this->student('Alpha', $senior, ['T1' => 12]);
        $service = app(RegistrationService::class);
        $service->register($student, ['branch_id' => $football->id, 'category_id' => $senior->id]);
        $service->register($student, ['branch_id' => $swimming->id, 'category_id' => $u15->id]);

        $sections = AcademicResultsSheet::build(2025, $u15->id);

        $this->assertCount(1, $sections);
        $this->assertSame('U15', $sections[0]['category']->name);
        $this->assertSame('Alpha', $sections[0]['rows'][0]['player']->lastname);
    }
```

Add `use App\Models\Branch;` and `use App\Services\Player\RegistrationService;`. The `student()` helper stores its grades in school year 2025 by default.

Create a board-table test in a new file `tests/Feature/Registrations/BoardTableRegistrationsTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class BoardTableRegistrationsTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    #[Test]
    public function the_board_table_lists_a_second_branchs_category_with_that_id(): void
    {
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create());
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => 'SW-2020-00001']);

        $csv = $this->get(route('players.board-table', ['category_id' => $this->u15->id, 'format' => 'csv']))->streamedContent();

        $this->assertStringContainsString('SW-2020-00001', $csv);
        $this->assertStringNotContainsString('FB-2020', $csv);
    }

    #[Test]
    public function a_player_with_no_registration_still_prints(): void
    {
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create());
        $flat = $this->noRegistration();
        $flat->update(['membership_id' => '202000001']);

        $csv = $this->get(route('players.board-table', ['category_id' => $this->senior->id, 'format' => 'csv']))->streamedContent();

        $this->assertStringContainsString('202000001', $csv);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test tests/Feature/LiteraryReport/MembersSectionTest.php tests/Feature/AcademicResultsPrintTest.php tests/Feature/Registrations/BoardTableRegistrationsTest.php`
Expected: FAIL on the new tests.

- [ ] **Step 3: Move `MembersSection`**

Add `use App\Models\BranchRegistration;`. Replace the `$active` and `$links` statements with:

```php
        $active = (clone $activeQuery)->get(['id', 'category_id', 'gender']);
        // The registrations still active at the end of that day, each with its own category.
        $links = BranchRegistration::query()
            ->activeOn($to->addDay()->toDateString())
            ->whereIn('player_id', (clone $activeQuery)->select('id'))
            ->get(['player_id', 'branch_id', 'category_id'])
            ->groupBy('player_id');
```

Replace the player loop:

```php
        foreach ($active as $player) {
            $isFemale = strtolower((string) $player->gender) === 'female';
            $female += $isFemale ? 1 : 0;
            // No registration: the "no branch" bucket, on the player's own category.
            $memberships = $links->get($player->id)?->map(fn (BranchRegistration $r) => [$r->branch_id, $r->category_id])->all()
                ?? [[null, $player->category_id]];
            foreach ($memberships as [$branchId, $categoryId]) {
                $key = ($branchId ?? 0).'|'.($categoryId ?? 0);
                $rows[$key] ??= [
                    'branch' => $branchId ? $branches->get($branchId)?->localized_name : null,
                    'category' => $categoryId ? $categories->get($categoryId)?->localized_name : null,
                    'male' => 0, 'female' => 0, 'total' => 0,
                ];
                $rows[$key][$isFemale ? 'female' : 'male']++;
                $rows[$key]['total']++;
            }
        }
```

Replace the `multi_branch` line:

```php
            'multi_branch' => $links->contains(fn ($rows) => $rows->count() > 1),
```

`DB` stays imported for `coaches`. Update the class comment's "A player in several branches counts in each branch row" sentence to add: "with that branch's category."

- [ ] **Step 4: Move the board table**

In `app/Http/Controllers/PlayerPrintController.php`, replace the `$players` statement in `boardTable`:

```php
        // Left members stay listed, as they always have here; the ID is the one
        // this category's registration holds.
        $players = Player::query()
            ->registeredWhere(categories: [$category->id])
            ->where('archived', false)
            ->with('registrations')
            ->orderBy('lastname')
            ->orderBy('firstname')
            ->get()
            ->each(function (Player $player) use ($category) {
                $row = $player->registrations->firstWhere('category_id', $category->id);
                $player->membership_id = $row?->membership_id ?? $player->membership_id;
            });
```

- [ ] **Step 5: Move the academic sheet**

In `app/Services/Player/AcademicResultsSheet.php`, replace `build`:

```php
    public static function build(int $schoolYear, ?int $categoryId = null): Collection
    {
        $players = Player::query()
            ->where('is_student', true)
            ->where('archived', false)
            ->when($categoryId, fn ($query) => $query->registeredWhere(categories: [$categoryId]))
            ->with([
                'category',
                'academicYears' => fn ($query) => $query->where('academic_year', $schoolYear)->with('records'),
            ])
            ->get();

        // A chosen category is the section's heading, whichever registration holds it.
        // Otherwise each student sits under their main category, once.
        $chosen = $categoryId ? Category::find($categoryId) : null;

        return $players
            ->groupBy(fn (Player $player) => $categoryId ?? $player->category_id ?? 0)
            ->map(fn (Collection $group) => [
                'category' => $chosen ?? $group->first()->category,
                'rows' => self::rank($group),
            ])
            // Categories by name, the order the players list uses; no category last.
            ->sortBy(fn (array $section) => [$section['category'] === null ? 1 : 0, mb_strtolower($section['category']?->name ?? '')])
            ->values();
    }
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/LiteraryReport tests/Feature/AcademicResultsPrintTest.php tests/Feature/Registrations/BoardTableRegistrationsTest.php`
Expected: all PASS, including the existing `a_player_in_two_branches_is_counted_in_each_but_once_in_the_total` and `active_members_follow_the_owner_rule`.

- [ ] **Step 7: Commit**

```bash
git add app/Services/LiteraryReport/Sections/MembersSection.php app/Http/Controllers/PlayerPrintController.php app/Services/Player/AcademicResultsSheet.php tests/Feature/LiteraryReport/MembersSectionTest.php tests/Feature/AcademicResultsPrintTest.php tests/Feature/Registrations/BoardTableRegistrationsTest.php
git commit -m "feat(reports): literary rows, board table and academic sheet use each registration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P6.6: Member card, academic and attendance PDFs, player page ID

**Files:**
- Create: `tests/Feature/Registrations/PlayerCardsRegistrationsTest.php`
- Modify: `app/Http/Controllers/ReportController.php` (`playerCard`, `academicReport`)
- Modify: `app/Http/Controllers/PlayerController.php` (`show`)
- Modify: `resources/js/Pages/Players/Show.vue`, `resources/js/Pages/Players/Partials/AcademicSection.vue`

**Interfaces:**
- Consumes: `RegistrationDisplay::{make,main,overlay}`, `member_card_pick_branch` (added in P6.2).
- Produces: `GET players.card?branch=ID` and `GET players.academic-report?branch=ID`. A branch the player has no registration in shows main values. Inertia prop `mainBranchId` on `Players/Show`.
- Blades don't change: the overlay puts the values on `$player`.
- Part 3 already moved the `attendance-player` PDF to the branch being viewed (`$shown`). The test below only pins it.
- Part 5 added `BranchSwitch` to `Show.vue` for the documents card, with its own `documentBranchId`. This task makes that the page's one selected tab, as the parent spec's "one tab per registration" says. The switch shows in both places and they move together.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/PlayerCardsRegistrationsTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\User;
use App\Services\Pdf\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class PlayerCardsRegistrationsTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private string $html = '';

    private string $filename = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create());
        $this->mock(PdfService::class, fn (MockInterface $mock) => $mock->shouldReceive('stream')
            ->andReturnUsing(function (string $html, string $filename) {
                $this->html = $html;
                $this->filename = $filename;

                return response('pdf');
            }));
    }

    private function twoBranchPlayer(): Player
    {
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => 'SW-2020-00001']);

        return $player;
    }

    #[Test]
    public function the_card_shows_the_chosen_branchs_id_and_category(): void
    {
        $player = $this->twoBranchPlayer();

        $this->get(route('players.card', ['player' => $player->id, 'branch' => $this->swim->id]))->assertOk();

        $this->assertStringContainsString('SW-2020-00001', $this->html);
        $this->assertStringContainsString('U15', $this->html);
        $this->assertStringContainsString('SW-2020-00001', $this->filename);
    }

    #[Test]
    public function the_card_with_no_branch_shows_main_values(): void
    {
        $player = $this->twoBranchPlayer();

        $this->get(route('players.card', $player))->assertOk();

        $this->assertStringContainsString('Senior', $this->html);
        $this->assertStringNotContainsString('SW-2020-00001', $this->html);
    }

    #[Test]
    public function the_card_for_a_branch_the_player_never_joined_shows_main_values(): void
    {
        $player = $this->noRegistration();

        $this->get(route('players.card', ['player' => $player->id, 'branch' => $this->swim->id]))->assertOk();

        $this->assertStringContainsString('Senior', $this->html);
    }

    #[Test]
    public function the_academic_report_follows_the_branch(): void
    {
        $player = $this->twoBranchPlayer();
        $player->update(['is_student' => true]);

        $this->get(route('players.academic-report', ['player' => $player->id, 'branch' => $this->swim->id]))->assertOk();

        $this->assertStringContainsString('SW-2020-00001', $this->html);
        $this->assertStringContainsString('U15', $this->html);
    }

    #[Test]
    public function the_attendance_report_follows_the_branch_being_viewed(): void
    {
        $player = $this->twoBranchPlayer();

        $this->get(route('attendance.players.report', ['player' => $player->id, 'branch_id' => $this->swim->id]))->assertOk();

        $this->assertStringContainsString('SW-2020-00001', $this->html);
        $this->assertStringContainsString('U15', $this->html);
    }

    #[Test]
    public function the_player_page_names_the_main_branch(): void
    {
        $player = $this->leftFootball();

        $this->get(route('players.show', $player))
            ->assertInertia(fn (Assert $page) => $page->where('mainBranchId', $this->swim->id));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/PlayerCardsRegistrationsTest.php`
Expected: FAIL.

- [ ] **Step 3: The PDFs**

In `app/Http/Controllers/ReportController.php`, add `use App\Services\Player\RegistrationDisplay;`, and replace `playerCard`:

```php
    public function playerCard(Request $request, Player $player): Response
    {
        $player->load(['category', 'position', 'registrations.category', 'registrations.position']);
        RegistrationDisplay::make()->overlay($player, $request->integer('branch') ?: null);

        $html = view('pdf.member-card', [
            'club' => ClubHeader::data(),
            'player' => $player,
            'photo' => $this->resolveMediaFile($player->picture_url),
        ])->render();

        return $this->pdf->stream($html, "member-card-{$player->membership_id}.pdf");
    }
```

In `academicReport`, change the signature to `academicReport(Request $request, Player $player)`, and replace the load:

```php
        $player->load(['category', 'academicYears.records', 'registrations.category']);
        RegistrationDisplay::make()->overlay($player, $request->integer('branch') ?: null);
```

- [ ] **Step 4: The player page's prop**

In `PlayerController::show`, add to the `Inertia::render` array after `'player' => $player,`:

```php
            // The tab the page opens on, and the ID it shows first.
            'mainBranchId' => RegistrationDisplay::make()->main($player)?->branch_id,
```

- [ ] **Step 5: The page**

In `resources/js/Pages/Players/Show.vue`, add to `defineProps`, after `player: Object,`:

```js
    mainBranchId: { type: Number, default: null },
```

Replace Part 5's `const documentBranchId = ref(documentTabs.value[0]?.branch_id ?? null);` with the following, so one state serves the page even for someone without the documents right:

```js
const documentBranchId = ref(props.mainBranchId);
```

Add right after that line:

```js
// One tab per registration: the page shows the chosen tab's ID, the main one first.
const registrationTabs = computed(() => (props.player.registrations ?? []).map((registration) => ({
    id: registration.branch_id,
    name: registration.branch?.localized_name || registration.branch?.name,
    logo_url: registration.branch?.logo_url,
})));
const shownBranchId = documentBranchId;
const shownMembershipId = computed(() => (props.player.registrations ?? [])
    .find((registration) => registration.branch_id === shownBranchId.value)?.membership_id
    || props.player.membership_id);

// The card asks which branch only when the player is active in several.
const cardRegistrations = computed(() => (props.player.registrations ?? []).filter((registration) => registration.status?.code !== 'left'));
const showCardPicker = ref(false);
const cardHref = (branchId) => route('players.card', branchId ? { player: props.player.id, branch: branchId } : props.player.id);
```

Replace the two uses of `player.membership_id` in the basic-info block. Lines 408 and 412 become:

```vue
                            <p class="font-mono text-sm text-slate-500 dark:text-slate-400">{{ shownMembershipId }}</p>
```

```vue
                        <PlayerFieldRow icon="idcard" :label="t('membership_id')" :value="shownMembershipId" mono />
```

Add the tabs in the basic-info header. Replace:

```vue
                        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('basic_info') }}</h2>
                        <IconButton :href="route('players.edit', player.id)" icon="pencil" :label="t('edit')" plain size="sm" />
```

with:

```vue
                        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('basic_info') }}</h2>
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <BranchSwitch :branches="registrationTabs" :branch-id="shownBranchId" @switch="shownBranchId = $event" />
                            <IconButton :href="route('players.edit', player.id)" icon="pencil" :label="t('edit')" plain size="sm" />
                        </div>
```

Replace the card button:

```vue
                        <IconButton v-if="cardRegistrations.length > 1" icon="idcard" :label="t('member_card')" @click="showCardPicker = true" />
                        <IconButton v-else :href="cardHref(cardRegistrations[0]?.branch_id)" external target="_blank" icon="idcard" :label="t('member_card')" />
```

Pass the tab to the academic section:

```vue
            <AcademicSection v-if="player.is_student" :player="player" :branch-id="shownBranchId" :certificate-thresholds="certificateThresholds" :current-school-year="currentSchoolYear" />
```

Add the picker just before `<Modal :show="showPaymentModal"`:

```vue
        <Modal :show="showCardPicker" @close="showCardPicker = false" max-width="md">
            <div class="p-6">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('member_card_pick_branch') }}</h3>
                <ul class="mt-4 space-y-2">
                    <li v-for="registration in cardRegistrations" :key="registration.id">
                        <a :href="cardHref(registration.branch_id)" target="_blank" rel="noopener"
                            class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-800"
                            @click="showCardPicker = false">
                            <span class="min-w-0 truncate">{{ registration.branch?.localized_name || registration.branch?.name }}</span>
                            <span class="shrink-0 font-mono text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ registration.membership_id || '—' }}</span>
                        </a>
                    </li>
                </ul>
            </div>
        </Modal>
```

In `resources/js/Pages/Players/Partials/AcademicSection.vue`, add to `defineProps`, after `player`:

```js
    /** The registration tab the player page is showing: the printout follows it. */
    branchId: { type: Number, default: null },
```

and replace the print button's `:href`:

```vue
                <IconButton :href="route('players.academic-report', branchId ? { player: player.id, branch: branchId } : player.id)" external target="_blank" icon="print" :label="t('print_academic_report')" size="sm" />
```

- [ ] **Step 6: Run the tests and build**

Run:

```bash
php artisan test tests/Feature/Registrations/PlayerCardsRegistrationsTest.php tests/Feature/ReportPdfTest.php tests/Feature/AttendancePlayerReportTest.php
npm run build
```

Expected: all PASS, and the build succeeds. Check the PDFs visually in Part 8, because the longer ID has not been seen on a printed card (Release 1 left this unverified).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ReportController.php app/Http/Controllers/AttendancePlayerController.php app/Http/Controllers/PlayerController.php resources/js/Pages/Players/Show.vue resources/js/Pages/Players/Partials/AcademicSection.vue tests/Feature/Registrations/PlayerCardsRegistrationsTest.php
git commit -m "feat(players): the card asks which branch, printouts and the ID follow the tab

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P6.7: Release 1 leftovers

Part 1 Task 6 already fixed "`primary()` runs one query per player": `RegistrationDisplay` and `pickPrimary` read loaded rows. The other six are here.

**The stale-form design.** Today `sync()` treats a branch with no card as "left". The form never removes a saved card: a new card's `remove` only drops it from the page, a saved card's `delete` calls `removeMistake`, and a real departure is the left status on the card. So the only way a saved registration reaches `sync()` with no card is a form opened before someone else added it. **The fix: `sync()` never leaves a registration that has no card.**

An explicit removal is sent the way the form already sends it, and no new field is needed: the card itself, with `status_id` set to the `left` status and `left_at` set to the date. That is the path `change()` already handles. Bulk Detach, bulk Replace and the Members dialog call `RegistrationService::leave()` directly, and they are unchanged.

**Files:**
- Create: `tests/Feature/Registrations/ReleaseOneLeftoversTest.php`
- Modify: `app/Services/Player/RegistrationService.php` (`sync`, `register`, `setField`)
- Modify: `app/Http/Controllers/PlayerController.php` (`bulkUpdate`)
- Modify: `app/Http/Middleware/RecordRouteActivity.php`
- Modify: `resources/js/lib/registrationCards.js`, `resources/js/Pages/Players/Partials/PlayerForm.vue`
- Modify: `tests/js/registrationCards.test.mjs`
- Modify: `tests/Feature/BranchFeatureTest.php`, `tests/Feature/Registrations/RegistrationServiceTest.php`, `tests/Feature/Registrations/PlayerRegistrationCardsTest.php`

**Interfaces:**
- Consumes: `RegistrationService::{leave,register,primary}`.
- Produces: `RegistrationService::setField(...): bool`, true when a row or player was written. `withFreshIds(cards, registrations)` in `registrationCards.js`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/ReleaseOneLeftoversTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\User;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class ReleaseOneLeftoversTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private RegistrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create());
        $this->service = app(RegistrationService::class);
    }

    private function left(): int
    {
        return (int) PlayerStatus::where('code', 'left')->value('id');
    }

    private function base(array $extra = []): array
    {
        return ['firstname' => 'Both', 'lastname' => 'Test', ...$extra];
    }

    /** What the form sends for a card. */
    private function card(Branch $branch, array $extra = []): array
    {
        return [
            'branch_id' => $branch->id, 'join_date' => null, 'category_id' => null, 'position_id' => null,
            'other_position_ids' => '', 'licence_number' => null, 'status_id' => (int) PlayerStatus::where('code', 'registered')->value('id'),
            'left_at' => null, 'sporting_status_id' => null, ...$extra,
        ];
    }

    #[Test]
    public function a_stale_form_leaves_a_newer_registration_alone(): void
    {
        $player = Player::create(['firstname' => 'Both', 'lastname' => 'Test', 'join_year' => 2020]);
        $this->service->register($player, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);
        // The form opens here, showing football only. Someone else then adds swimming.
        $this->service->register($player, ['branch_id' => $this->swim->id, 'join_date' => '2026-01-01']);

        $this->put(route('players.update', $player), $this->base([
            'registrations' => [$this->card($this->foot, ['category_id' => $this->senior->id])],
        ]))->assertSessionHasNoErrors();

        $this->assertNotSame($this->left(), $player->registrations()->where('branch_id', $this->swim->id)->value('status_id'));
        $this->assertNull($player->registrations()->where('branch_id', $this->swim->id)->value('left_at'));
    }

    #[Test]
    public function an_explicit_removal_is_the_left_status_on_the_card(): void
    {
        $player = $this->bothActive();

        $this->put(route('players.update', $player), $this->base([
            'registrations' => [
                $this->card($this->foot, ['category_id' => $this->senior->id]),
                $this->card($this->swim, ['category_id' => $this->u15->id, 'status_id' => $this->left(), 'left_at' => '2026-03-15']),
            ],
        ]))->assertSessionHasNoErrors();

        $swim = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();
        $this->assertSame($this->left(), (int) $swim->status_id);
        $this->assertSame('2026-03-15', $swim->left_at->toDateString());
    }

    #[Test]
    public function a_first_and_second_card_together_keep_the_second_branchs_position(): void
    {
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);
        $player = Player::create(['firstname' => 'Both', 'lastname' => 'Test', 'membership_id' => '201500001', 'join_year' => 2015, 'position_id' => $crawl->id]);

        $this->put(route('players.update', $player), $this->base([
            'registrations' => [
                $this->card($this->foot, ['join_date' => '2026-01-01']),
                $this->card($this->swim, ['join_date' => '2026-01-01']),
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([$crawl->id], $player->otherPositions()->pluck('positions.id')->all());
    }

    #[Test]
    public function a_position_the_second_card_picks_is_not_also_an_other_one(): void
    {
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);
        $player = Player::create(['firstname' => 'Both', 'lastname' => 'Test', 'membership_id' => '201500001', 'join_year' => 2015, 'position_id' => $crawl->id]);

        $this->put(route('players.update', $player), $this->base([
            'registrations' => [
                $this->card($this->foot, ['join_date' => '2026-01-01']),
                $this->card($this->swim, ['join_date' => '2026-01-01', 'position_id' => $crawl->id]),
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([], $player->otherPositions()->pluck('positions.id')->all());
        $this->assertSame($crawl->id, $player->registrations()->where('branch_id', $this->swim->id)->value('position_id'));
    }

    #[Test]
    public function the_members_dialog_keeps_a_left_player_undated(): void
    {
        $player = Player::create(['firstname' => 'Gone', 'lastname' => 'Test']);
        $player->forceFill(['status_id' => $this->left(), 'left_at' => null])->saveQuietly();

        $this->post(route('branches.players.sync', $this->foot), ['player_ids' => [$player->id], 'join_date' => '2020-01-01'])
            ->assertSessionHasNoErrors();

        $this->assertNull($player->registrations()->where('branch_id', $this->foot->id)->value('left_at'));
        $this->assertNull(DB::table('players')->where('id', $player->id)->value('left_at'));
    }

    #[Test]
    public function a_new_card_with_the_left_status_and_no_date_still_stamps_today(): void
    {
        $this->travelTo('2026-05-14 10:00:00');
        $player = Player::create(['firstname' => 'New', 'lastname' => 'Test']);

        $registration = $this->service->register($player, ['branch_id' => $this->foot->id, 'status_id' => $this->left(), 'left_at' => null]);

        $this->assertSame('2026-05-14', $registration->fresh()->left_at->toDateString());
    }

    #[Test]
    public function the_activity_log_names_the_player_on_a_queue_save(): void
    {
        $player = $this->bothActive();
        $row = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();

        $this->put(route('players.join-dates.update', $row), ['join_date' => '2022-05-05'])->assertSessionHasNoErrors();

        $log = ActivityLog::latest('id')->firstOrFail();
        $this->assertSame((new Player)->getMorphClass(), $log->subject_type);
        $this->assertSame($player->id, $log->subject_id);
        $this->assertSame($this->swim->id, $log->properties['branch_id']);
    }

    #[Test]
    public function the_activity_log_names_the_player_on_a_registration_delete(): void
    {
        $player = $this->bothActive();
        $row = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();

        $this->delete(route('players.registrations.destroy', [$player, $row]))->assertSessionHas('success');

        $log = ActivityLog::latest('id')->firstOrFail();
        $this->assertSame($player->id, $log->subject_id);
        $this->assertSame((new Player)->getMorphClass(), $log->subject_type);
    }

    #[Test]
    public function the_bulk_message_counts_only_who_changed(): void
    {
        $inBoth = $this->bothActive('A');
        $onlyFoot = Player::create(['firstname' => 'B', 'lastname' => 'Test', 'join_year' => 2020]);
        $this->service->register($onlyFoot, ['branch_id' => $this->foot->id]);

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$inBoth->id, $onlyFoot->id], 'field' => 'status_id', 'value' => PlayerStatus::where('code', 'registered')->value('id'), 'branch_id' => $this->swim->id,
        ])->assertSessionHas('success', ['key' => 'flash.players_updated', 'params' => ['count' => 1]]);
    }

    #[Test]
    public function the_bulk_message_skips_a_position_no_registration_can_hold(): void
    {
        $player = Player::create(['firstname' => 'B', 'lastname' => 'Test', 'join_year' => 2020]);
        $this->service->register($player, ['branch_id' => $this->foot->id]);
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);

        $this->post(route('players.bulkUpdate'), ['ids' => [$player->id], 'field' => 'position_id', 'value' => $crawl->id])
            ->assertSessionHas('success', ['key' => 'flash.players_updated', 'params' => ['count' => 0]]);
    }

    #[Test]
    public function the_bulk_message_skips_removing_from_a_branch_they_are_not_in(): void
    {
        $inBoth = $this->bothActive('A');
        $onlyFoot = Player::create(['firstname' => 'B', 'lastname' => 'Test', 'join_year' => 2020]);
        $this->service->register($onlyFoot, ['branch_id' => $this->foot->id]);

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$inBoth->id, $onlyFoot->id], 'field' => 'branches', 'value' => [$this->swim->id], 'mode' => 'detach',
        ])->assertSessionHas('success', ['key' => 'flash.players_updated', 'params' => ['count' => 1]]);
    }

    #[Test]
    public function the_bulk_message_skips_adding_a_branch_they_already_hold(): void
    {
        $inBoth = $this->bothActive('A');
        $onlyFoot = Player::create(['firstname' => 'B', 'lastname' => 'Test', 'join_year' => 2020]);
        $this->service->register($onlyFoot, ['branch_id' => $this->foot->id]);

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$inBoth->id, $onlyFoot->id], 'field' => 'branches', 'value' => [$this->swim->id], 'mode' => 'attach', 'join_date' => '2026-01-01',
        ])->assertSessionHas('success', ['key' => 'flash.players_updated', 'params' => ['count' => 1]]);
    }
}
```

Add to `tests/js/registrationCards.test.mjs`, and add `withFreshIds` to its import:

```js
test('a card picks up the ID the server gave its registration after a delete', () => {
    const cards = [
        { id: 1, branch_id: 1, membership_id: '' },
        { branch_id: 2, membership_id: '', is_new: true },
        { id: 3, branch_id: 3, membership_id: 'OLD' },
    ];
    const fresh = withFreshIds(cards, [{ id: 1, membership_id: 'SW-2020-00001' }, { id: 3, membership_id: null }]);
    assert.equal(fresh[0].membership_id, 'SW-2020-00001');
    assert.equal(fresh[1].membership_id, '');
    assert.equal(fresh[2].membership_id, '');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run:

```bash
php artisan test tests/Feature/Registrations/ReleaseOneLeftoversTest.php
npm run test:js
```

Expected: FAIL, except `an_explicit_removal_is_the_left_status_on_the_card` and `a_new_card_with_the_left_status_and_no_date_still_stamps_today`, which pin what must stay.

- [ ] **Step 3: `sync()` and the second card's position**

In `app/Services/Player/RegistrationService.php`, replace `sync()`:

```php
    /**
     * Save the cards. A branch with no card is left alone: it may have been added
     * after the form opened. Leaving is the left status on a card, or leave().
     *
     * @param  list<array<string, mixed>>  $cards
     */
    public function sync(Player $player, array $cards, ?User $user = null): void
    {
        DB::transaction(function () use ($player, $cards, $user) {
            $existing = $player->registrations()->get()->keyBy('branch_id');
            // The position a player with no registration holds: a branch's card can't
            // carry it unless it is the first one, so it must not vanish between them.
            $held = $existing->isEmpty() ? $player->position_id : null;

            foreach ($this->mainCardFirst($cards) as $card) {
                $registration = $existing[(int) $card['branch_id']] ?? null;
                if ($registration === null) {
                    $this->register($player, $card);
                } elseif ($this->isLeft($registration) && ! array_key_exists('status_id', $card)) {
                    $this->reopen($registration, $card);
                } else {
                    $this->change($registration, $card, $user);
                }
            }

            $this->syncOtherPositions($player, $cards);
            $this->keepHeldPosition($player, $held);
            $this->refreshMirror($player);
        });
    }

    /** A main position a branch owns and no card took waits among the other positions. */
    private function keepHeldPosition(Player $player, mixed $positionId): void
    {
        if ($positionId === null
            || Position::whereKey($positionId)->value('branch_id') === null
            || $player->registrations()->where('position_id', $positionId)->exists()) {
            return;
        }

        $player->otherPositions()->syncWithoutDetaching([$positionId]);
    }
```

- [ ] **Step 4: The Members dialog's date**

In `register()`, replace the `$registration = $player->registrations()->create([...]);` statement with:

```php
            $attributes = [
                'branch_id' => (int) $card['branch_id'],
                'membership_id' => $id,
                'join_date' => $joinDate,
                'legacy_join_year' => $legacyYear,
                ...$details,
            ];
            // Copied from the player, not a departure happening now: a left player whose
            // day was never recorded stays undated, past the saving hook's "stamp today".
            $copiesUndatedLeft = $first && ! array_key_exists('status_id', $card) && ! array_key_exists('left_at', $card)
                && Player::isLeftStatus($player->status_id) && $player->left_at === null;
            $registration = $copiesUndatedLeft
                ? $player->registrations()->createQuietly($attributes)
                : $player->registrations()->create($attributes);
```

- [ ] **Step 5: `setField` says whether it wrote**

Change the signature and body of `setField`:

```php
    /**
     * One field from bulk edit: status on the registration of $branchId only,
     * position on its branch's, category on the primary.
     *
     * @return bool whether anything was written: a position no registration of this player can hold writes nothing
     */
    public function setField(Player $player, string $field, mixed $value, ?int $branchId = null): bool
    {
        return DB::transaction(function () use ($player, $field, $value, $branchId) {
            $registrations = $player->registrations()->get();

            if ($registrations->isEmpty()) {
                $player->update([$field => $value]);

                // The main position can never also sit in the "other positions" pivot.
                if ($field === 'position_id' && $value) {
                    $player->otherPositions()->detach($value);
                }

                return true;
            }
            if ($field === 'status_id' && $branchId === null) {
                throw new InvalidArgumentException('A status change needs the branch it applies to.');
            }

            $targets = match ($field) {
                'status_id' => $registrations->where('branch_id', $branchId),
                'position_id' => collect([$this->positionOwner($registrations, $player, $value)])->filter(),
                default => collect([$this->primary($player)]),
            };

            foreach ($targets as $registration) {
                $registration->{$field} = $value;
                $registration->save();
            }

            if ($field === 'position_id' && $value && $targets->isNotEmpty()) {
                $player->otherPositions()->detach($value);
            }
            $this->refreshMirror($player);

            return $targets->isNotEmpty();
        });
    }
```

- [ ] **Step 6: The bulk count**

In `PlayerController::bulkUpdate`, replace the `$registrations = ...` line through the end of the method with:

```php
        $registrations = app(RegistrationService::class);
        $changed = 0;

        DB::transaction(function () use ($ids, $field, $value, $data, $registrations, &$changed) {
            $players = Player::whereIn('id', $ids)->get();

            if ($field !== 'branches') {
                $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;

                $players->each(function (Player $player) use ($registrations, $field, $value, $branchId, &$changed) {
                    // A status belongs to one branch: a player with registrations
                    // but none there is left alone.
                    if ($field === 'status_id' && $branchId !== null
                        && $player->registrations()->exists()
                        && ! $player->registrations()->where('branch_id', $branchId)->exists()) {
                        return;
                    }

                    $changed += $registrations->setField($player, $field, $value ?: null, $branchId) ? 1 : 0;
                });

                return;
            }

            // The main branch first, so it adopts the player's existing ID.
            $branchIds = array_map('intval', (array) $value);
            $mainId = Branch::mainId();
            usort($branchIds, fn (int $a, int $b) => ($b === $mainId) <=> ($a === $mainId));
            $mode = $data['mode'] ?? 'replace';

            // The shared date is for a genuinely new registration only. Check it
            // before anything is written.
            if ($mode !== 'detach') {
                $registrations->assertSharedDate($players->flatMap(function (Player $player) use ($branchIds) {
                    $active = $player->registrations()->active()->pluck('branch_id')->map(fn ($id) => (int) $id)->all();

                    return array_map(fn (int $branchId) => [$player, $branchId], array_values(array_diff($branchIds, $active)));
                })->all(), $data['join_date'] ?? null);
            }

            // Branches are many-to-many, so "set" is ambiguous: replace the
            // whole set, add to it, or remove from it. Removing marks as left.
            $players->each(function (Player $player) use ($branchIds, $mode, $data, $registrations, &$changed) {
                $active = $player->registrations()->active()
                    ->pluck('branch_id')->map(fn ($id) => (int) $id)->all();

                if ($mode === 'detach') {
                    $changed += array_intersect($branchIds, $active) === [] ? 0 : 1;
                    $registrations->leave($player, $branchIds);

                    return;
                }
                $joining = array_diff($branchIds, $active);
                $leaving = $mode === 'replace' ? array_values(array_diff($active, $branchIds)) : [];
                $changed += $joining === [] && $leaving === [] ? 0 : 1;

                foreach ($joining as $branchId) {
                    $registrations->registerWithSharedDate($player, $branchId, $data['join_date'] ?? null);
                }
                $registrations->leave($player, $leaving);
            });
        });

        return back()->with('success', [
            'key' => 'flash.players_updated',
            'params' => ['count' => $changed],
        ]);
    }
```

- [ ] **Step 7: The activity subject**

In `app/Http/Middleware/RecordRouteActivity.php`, add `use App\Models\BranchRegistration;`, and in `record()`, add after the `$properties` block and before `ActivityRecorder::record`:

```php
        // A registration is part of the player: name them, and say which branch.
        if ($subject instanceof BranchRegistration) {
            $properties['branch_id'] = $subject->branch_id;
            $subject = $subject->player;
        }
```

`branch_id` is small context, which the recorder allows. Confirm nothing renders unknown properties: `grep -rn "properties\." resources/js/Pages resources/js/Components | grep -i activ`.

- [ ] **Step 8: The card's ID header**

In `resources/js/lib/registrationCards.js`, add at the end:

```js
/**
 * Saved cards take their ID from the fresh rows: a delete can give the next row
 * its ID, and the form keeps its state, so its cards aren't rebuilt.
 */
export function withFreshIds(cards, registrations = []) {
    return cards.map((card) => {
        const fresh = registrations.find((registration) => registration.id === card.id);
        return fresh ? { ...card, membership_id: fresh.membership_id || '' } : card;
    });
}
```

In `resources/js/Pages/Players/Partials/PlayerForm.vue`, change the import to add `withFreshIds`, and replace the `onSuccess` line:

```js
                form.registrations = withFreshIds(withoutCard(form.registrations, card.id, props.positions), page.props.player?.registrations);
```

- [ ] **Step 9: Change the tests that pinned the old behaviour**

In `tests/Feature/BranchFeatureTest.php`, rename `updating_a_player_marks_a_dropped_branch_left_instead_of_deleting_it` to `updating_a_player_keeps_a_branch_with_no_card_as_it_was`, and replace its last three assertions and the comment with:

```php
        // Both registrations remain, and the one with no card is untouched.
        $this->assertEqualsCanonicalizing([$swim->id, $foot->id], $player->registrations()->pluck('branch_id')->all());
        $this->assertNotSame(Player::leftStatusId(), $player->registrations()->where('branch_id', $swim->id)->value('status_id'));
        $this->assertNotSame(Player::leftStatusId(), $player->registrations()->where('branch_id', $foot->id)->value('status_id'));
```

In `tests/Feature/Registrations/RegistrationServiceTest.php`, rename `a_card_left_out_of_sync_is_marked_left_not_deleted` to `a_card_left_out_of_sync_changes_nothing`, and replace its final assertions with:

```php
        $swim = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();
        $this->assertNotSame($this->left()->id, $swim->status_id);
        $this->assertSame('2025-03-10', $swim->join_date->toDateString());
```

In the rejoin test around line 418 of the same file, replace `$this->service->sync($player, [['branch_id' => $this->foot->id]]);` with:

```php
        $this->service->leave($player, [$this->swim->id]);
```

In `tests/Feature/Registrations/PlayerRegistrationCardsTest.php`, rename `removing_a_card_marks_the_registration_left_and_keeps_it` to `a_saved_branch_with_no_card_is_not_marked_left`, and replace its last two lines with:

```php
        $left = PlayerStatus::where('code', 'left')->value('id');
        $this->assertNotSame($left, $player->registrations()->where('branch_id', $this->swim->id)->value('status_id'));
```

- [ ] **Step 10: Run the tests**

Run:

```bash
php artisan test tests/Feature/Registrations tests/Feature/BranchFeatureTest.php tests/Feature/PlayerBulkUpdateTest.php tests/Feature/ActivityPlayerEventsTest.php
npm run test:js
npm run build
```

Expected: all PASS. Any other test that relied on a missing card meaning "left" gets the same change: it calls `leave()`, or sends the card with the left status.

- [ ] **Step 11: Commit**

```bash
git add app/Services/Player/RegistrationService.php app/Http/Controllers/PlayerController.php app/Http/Middleware/RecordRouteActivity.php resources/js/lib/registrationCards.js resources/js/Pages/Players/Partials/PlayerForm.vue tests/js/registrationCards.test.mjs tests/Feature/Registrations/ReleaseOneLeftoversTest.php tests/Feature/BranchFeatureTest.php tests/Feature/Registrations/RegistrationServiceTest.php tests/Feature/Registrations/PlayerRegistrationCardsTest.php
git commit -m "fix(registrations): a stale form can't mark a branch left, and five smaller Release 1 leftovers

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P6.8: Query budget at 1,920 players

This test covers the Players list, the dashboard and the session roster (spec section 4). The roster is included here whatever Part 3 did, because it costs little more once the players exist.

**Files:**
- Create: `tests/Feature/Registrations/RegistrationQueryBudgetTest.php`

**Interfaces:**
- Consumes: `RegistrationFixtures`, `Roster::forSession`.

- [ ] **Step 1: Write the test**

`tests/Feature/Registrations/RegistrationQueryBudgetTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\PlayerStatus;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Attendance\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

/**
 * The real guard is scale: 1,920 players must cost no more queries than 60. The
 * ceilings catch a slow creep that stays flat with size.
 */
class RegistrationQueryBudgetTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private const FEW = 60;

    private const MANY = 1920;

    private const LIST_CEILING = 70;

    private const DASHBOARD_CEILING = 90;

    private const ROSTER_CEILING = 12;

    private int $seeded = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));
    }

    /** Bulk rows, so the test stays quick. Every eighth player is in both branches. */
    private function seedUpTo(int $total): void
    {
        $status = PlayerStatus::where('code', 'registered')->value('id');
        $now = now()->toDateTimeString();

        foreach (array_chunk(range($this->seeded + 1, $total), 200) as $numbers) {
            DB::table('players')->insert(array_map(fn (int $n) => [
                'firstname' => "Bulk{$n}", 'lastname' => 'Test', 'membership_id' => "FB-2020-{$n}", 'join_year' => 2020,
                'category_id' => $this->senior->id, 'status_id' => $status, 'created_at' => $now, 'updated_at' => $now,
            ], $numbers));

            $ids = DB::table('players')->whereIn('membership_id', array_map(fn (int $n) => "FB-2020-{$n}", $numbers))->pluck('id', 'membership_id');
            $rows = [];
            foreach ($numbers as $n) {
                $playerId = $ids["FB-2020-{$n}"];
                $rows[] = ['branch_id' => $this->foot->id, 'player_id' => $playerId, 'membership_id' => "FB-2020-{$n}", 'category_id' => $this->senior->id, 'status_id' => $status, 'legacy_join_year' => 2020, 'created_at' => $now, 'updated_at' => $now];
                if ($n % 8 === 0) {
                    $rows[] = ['branch_id' => $this->swim->id, 'player_id' => $playerId, 'membership_id' => "SW-2020-{$n}", 'category_id' => $this->u15->id, 'status_id' => $status, 'legacy_join_year' => 2020, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            DB::table('branch_player')->insert($rows);
        }
        $this->seeded = $total;
    }

    private function queries(callable $call): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @param callable(): void $call */
    private function assertBudget(callable $call, int $ceiling): void
    {
        $this->seedUpTo(self::FEW);
        $few = $this->queries($call);
        $this->seedUpTo(self::MANY);
        $many = $this->queries($call);

        $this->assertLessThanOrEqual($few + 2, $many, "Queries grew with players: {$few} at ".self::FEW.", {$many} at ".self::MANY);
        $this->assertLessThanOrEqual($ceiling, $many, "{$many} queries, ceiling {$ceiling}");
    }

    #[Test]
    public function the_players_list_stays_flat(): void
    {
        $this->assertBudget(fn () => $this->get(route('players.index', ['per_page' => 200]))->assertOk(), self::LIST_CEILING);
    }

    #[Test]
    public function the_players_list_of_one_branch_stays_flat(): void
    {
        $this->assertBudget(fn () => $this->get(route('players.index', ['per_page' => 200, 'branch_id' => [$this->swim->id]]))->assertOk(), self::LIST_CEILING);
    }

    #[Test]
    public function the_members_dashboard_stays_flat(): void
    {
        $this->assertBudget(fn () => $this->get(route('dashboard', ['tab' => 'members']))->assertOk(), self::DASHBOARD_CEILING);
    }

    #[Test]
    public function the_members_dashboard_of_one_branch_stays_flat(): void
    {
        $this->assertBudget(fn () => $this->get(route('dashboard', ['tab' => 'members', 'branch' => $this->swim->id]))->assertOk(), self::DASHBOARD_CEILING);
    }

    #[Test]
    public function the_session_roster_stays_flat(): void
    {
        $session = TrainingSession::create([
            'branch_id' => $this->swim->id, 'category_id' => $this->u15->id, 'date' => '2026-05-14',
            'start_time' => '18:00', 'end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);

        $this->assertBudget(fn () => app(Roster::class)->forSession($session), self::ROSTER_CEILING);
    }
}
```

- [ ] **Step 2: Run it**

Run: `php artisan test --filter=QueryBudget`

Expected: PASS. **If the growth assertion fails, there's a query per row: fix it, never loosen it.** The usual cause is `$player->registrations` read without an eager load, or `Player::leftStatusId()` / `Branch::mainId()` called per row instead of through `RegistrationDisplay`. The ceilings are estimates. If only a ceiling fails, print the log, confirm no statement repeats per row, set that ceiling to the measured count plus 10, and write the measured counts in `.superpowers/r2/progress.md`.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Registrations/RegistrationQueryBudgetTest.php
git commit -m "test(registrations): query budget for the list, dashboard and roster at 1,920 players

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P6.9: `ImportMongoJsonData`: stop and ask the owner

The command (signature `irnb:import-mongo-json`) writes `players.*` directly, and creates no registration (spec section 9). Nothing in `app/`, `routes/` or `composer.json` runs it.

**Files:** depend on the owner's answer.

- [ ] **Step 1: Confirm nothing runs it**

```bash
grep -rn "ImportMongoJsonData\|import-mongo-json" app routes composer.json scripts bootstrap config tests docs/*.md 2>/dev/null
```

Expected: only the command's own file. If anything else runs it, skip Step 2, and take Outcome B.

- [ ] **Step 2: Stop and ask the owner**

Ask in plain language, with no other work waiting on the answer:

> The old command that imported players from the first system's export (`irnb:import-mongo-json`) writes players without registrations. Nothing in the app uses it, and your data came through it long ago. Do you want me to remove it, or do you still expect to import that export again?
> 1. Remove it (recommended, if you won't import that export again)
> 2. Keep it, and make it create registrations

Other tasks in this part don't wait for this answer. Record it in `.superpowers/r2/progress.md` and `HANDOFF.md`.

- [ ] **Step 3, Outcome A: the owner chooses removal**

```bash
git rm app/Console/Commands/ImportMongoJsonData.php
php artisan list | grep -c import-mongo-json
php artisan test tests/Feature/Registrations/RegistrationWritersTest.php
```

Expected: `0`, and the guard test passes. If the guard test names this file in an allow-list, remove that entry as well. Commit:

```bash
git add tests/Feature/Registrations/RegistrationWritersTest.php
git commit -m "chore(registrations): remove the old import command, which wrote players without registrations

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 3, Outcome B: the owner keeps it**

`Player::updateOrCreate(...)` near line 443 stays, for a club with no branches. Add this immediately before the `$player->emergencyContacts()->delete();` that follows it, with `use App\Models\Branch;` and `use App\Services\Player\RegistrationService;`. The registration goes in the main branch with no date, because a source with only a year must never get an invented date:

```php
        $mainBranch = Branch::mainId();
        if ($mainBranch !== null) {
            app(RegistrationService::class)->register($player, ['branch_id' => $mainBranch]);
        }
```

`register()` with a sparse card on a first registration reads category, position, licence and status from the player, and keeps the year as `legacy_join_year`. Add a test to `tests/Feature/Registrations/RegistrationWritersTest.php` that runs the command on a one-player fixture folder in the scratchpad with `$this->artisan('irnb:import-mongo-json', ['path' => $dir])`, and asserts one registration in the main branch with `join_date` null. Build the fixture from the JSON shape the command reads at the top of its `handle()`. Commit:

```bash
git add app/Console/Commands/ImportMongoJsonData.php tests/Feature/Registrations/RegistrationWritersTest.php
git commit -m "fix(registrations): the old import command creates registrations

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P6.10: Close Part 6

**Files:**
- Modify: `.superpowers/r2/progress.md` (git-ignored), `.superpowers/r2/differences.md` (git-ignored)

- [ ] **Step 1: Run the full checks**

```bash
php artisan config:clear
php artisan test 2>&1 | tail -8
npm run test:js 2>&1 | tail -3
npm run i18n:check
npm run build 2>&1 | tail -3
```

Expected: only `ManualSuspensionsTest` may fail, as at the baseline.

- [ ] **Step 2: Compare the figures on the real-data copy**

```bash
( . .superpowers/r2/env-sqlite.sh
  php artisan config:show database.connections.sqlite.database
  php artisan registrations:verify
  php .superpowers/r2/figures.php p6
  php .superpowers/r2/sweep.php p6 | tail -5 )
php .superpowers/r2/diff-figures.php p5 p6
php .superpowers/r2/diff-sweep.php p5 p6 > .superpowers/r2/data/sweep-p6.txt
```

If Part 5's figures carry another label, use it. Expected:

- `config:show` prints the `.superpowers/r2/data/real.sqlite` path. Stop if it doesn't.
- `diff-figures`: `unexpected differences: 0`. Money tables are identical, and the mirror is identical. This part writes no real data.
- `sweep`: no 5xx.
- `sweep-p6.txt`: each `PROPS` difference falls under one of these, and goes in `.superpowers/r2/differences.md` with its decision:

| Where | Expected difference | Follows |
|---|---|---|
| `players.index` `categoryStats`, `dashboard` `members.byCategory`, Directory's category chart, Demographics rows | Two-branch players count in both bars. **Left people drop out, which also moves one-branch figures** | Section 9, category charts |
| `dashboard` `members.leftByCategory` | Registrations left, including two-branch players still active elsewhere | Section 9 |
| Literary report rows | The 9 swimmers' branch rows show their swimming category | Section 9 |
| `players.show` | New `mainBranchId` | Section 9, player page ID |
| Every report page | New `note` | Section 9, the visible note |

With no branch filter, each row of the Players list, export, Directory and Movement must be **identical** to Part 5. A row that differs with no filter is a bug.

- [ ] **Step 3: One filtered check by hand**

On the copy, compare the sweep's numbers with the database for one case: a swimming filter on the Players list (`branch_id[]=` the swimming ID) lists exactly the 19 swimming registrations minus any who left, and each row's category is the swimming one. Write the result in `.superpowers/r2/progress.md`.

- [ ] **Step 4: Update the handoff and stop if the owner's question is open**

Add to `HANDOFF.md` (never staged) where Part 6 stands, the owner's Mongo answer or that it is open, and the note above about one-branch category figures, for the Part 8 report. Don't merge or push.

---

## Self-review against the spec

| Spec | Task |
|---|---|
| 9, Filters: every list uses the shared query, so Swimming + Senior can't match a football Senior | P6.1, P6.2, P6.3 |
| 9, Displayed values: one branch that registration, none or several main; rows, export, print, Directory, Movement | P6.1 overlay; P6.2; P6.3 |
| 9, Report tables with no branch filter show main values | P6.3, P6.5 |
| 9, Counts: people once; category charts per active registration with a note; left-by-category counts left registrations | P6.1, P6.2, P6.3, P6.4 |
| 9, Status charts: person club-wide, registration with one branch | P6.2, P6.4 |
| 9, Left: everywhere club-wide, that branch with one | P6.1 list rule; P6.3 Movement; P6.4 |
| 9, `DocumentStats` category figure | **Part 5** supplies the figures. P6.4 adds its note |
| 9, Join-year figures wait for Release 4 | Unchanged, stated in constraints |
| 9, Literary report rows | P6.5 |
| 9, Member card asks which branch, `branch` parameter; `attendance-player` and `academic-report` follow the branch | P6.6 |
| 9, Player page ID | P6.6 |
| 9, Spreadsheet import | Part 7, stated in constraints |
| 9, `ImportMongoJsonData` | P6.9 |
| 4, Query budget | P6.8 |
| 12, Stale form | P6.7, with the design stated |
| 12, Audit subject | P6.7 |
| 12, Bulk count | P6.7 |
| 12, Dialog date stamp | P6.7 |
| 12, First and second card position | P6.7 |
| 12, Card ID header | P6.7 |
| 12, `primary()` query per player | Part 1 Task 6 |
| 14, Every rule in four cases | ListRuleTest, PlayersListRegistrationsTest, DashboardRegistrationsTest, MembersSectionTest, RegistrationDisplayTest |

**Beyond the directive, found while reading:** the Players position filter read only `players.position_id`, so a second branch's main position couldn't be found. P6.2 fixes it. `PlayerPrintController::boardTable` and `AcademicResultsSheet` filter by category, and would lose swimmers after the split. P6.5 moves them.

**Left as it is on purpose:** the board table keeps listing left members, as it does today, so a one-branch player's printed row doesn't change. `MemberStats` `total_members` keeps counting everyone not archived, including left people, for the same reason.
