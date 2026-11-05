# Branch registrations, Release 2, Part 2: Subscriptions and finance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Subscriptions, prices, default caisses, debt reports, the forecast and receipts all read the player's registration, so branch and category are judged on one row and a left branch gets no new charge but keeps its debt.

**Architecture:** `Player::scopeEligibleFor` becomes a thin call to `registeredWhere`, and `Subscription::appliesTo` asks that same query, so the rule exists once. Price and caisse pick a registration in memory through `RegistrationDisplay`, costing no query per row. SQL reports use the mirror for main values and join the registration only when exactly one branch is selected.

**Tech Stack:** Laravel 13, Eloquent, PHPUnit attribute tests, Vue 3 (one comment), plain JS with `node --test`. Spec: `docs/superpowers/specs/2026-11-05-branch-registrations-r2-design.md`, section 5.

## Global Constraints

See the main plan `2026-11-05-branch-registrations-r2.md`. They apply in full. Parts 0 and 1 are done; Parts 3 to 7 are not. Also, for this part:

- **No amount changes.** `player_subscriptions` gets no new column and no row is rewritten. `leave()` touches no charge. `FixSubscriptionBilling` is not touched.
- The JS test command is `npm run test:js` (`package.json` has no `test` script).
- `BranchScope` keeps counting left registrations, because their debt is still owed. Member counts built on it move in Part 6, not here.
- `FiltersPlayers::applyCategoryBranch` belongs to Part 6. `ReadsObligations` has its own filter code, and this part changes that.

**Decisions this part settles where the spec leaves a choice. Each is pinned by a test:**

| Case | Rule |
|---|---|
| Player left football and pays a football debt, with no caisse chosen | The subscription's branch: football's caisse. "Never a branch the player left" limits the main-registration fallback, so the money still lands with the sport it was owed to |
| Player left everywhere pays with no subscription | Club-level cash, or blank if the club has none |
| No-branch subscription whose main category isn't on its list, though another registration's is | The spec's literal rule: main registration, then the existing fallbacks (sole category's price, then default) |
| Grouped receipt with a donation line for the excess | The donation has no subscription and doesn't break "all lines share it" |
| A one-branch player who has left | No longer eligible, as "active registrations only" requires. **This differs from the spec's "each one-branch player's eligibility identical"; see Task P2.9 Step 3** |

---

### Task P2.1: Eligibility on one active registration

**Files:**
- Create: `tests/Feature/Registrations/SubscriptionEligibilityTest.php`
- Modify: `app/Models/Player.php` (`scopeEligibleFor`)
- Modify: `app/Models/Subscription.php` (`scopeOpenTo`, `appliesTo`; remove `appliesToCategory`, `appliesToBranches`)
- Modify: `app/Http/Controllers/SubscriptionController.php` (`show`'s `outsidePlayers`, `unassignedPlayers`, `assign`)
- Modify: `resources/js/Pages/Subscriptions/Show.vue` (comment)
- Modify: `tests/Feature/SubscriptionOutsideCategoryTest.php` (docblock)

**Interfaces:**
- Consumes: `Player::registeredWhere`, `RegistrationFixtures`.
- Produces: `Player::scopeEligibleFor(Subscription $subscription, bool $anyCategory = false, ?int $onlyCategory = null)`; `Subscription::scopeOpenTo(Player $player)` and `Subscription::appliesTo(Player $player, bool $anyCategory = false): bool`, same signatures as today, new rule.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/SubscriptionEligibilityTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

/** Spec section 5: branch and category must match one active registration. */
class SubscriptionEligibilityTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function sub(array $branches = [], array $categories = []): Subscription
    {
        $sub = Subscription::create([
            'name' => 'Season '.uniqid(), 'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $sub->branches()->attach(collect($branches)->pluck('id'));
        $sub->categories()->attach(collect($categories)->pluck('id'));

        return $sub;
    }

    /** The query, the single check and the player page's catalog must always agree. */
    private function assertApplies(bool $expected, Subscription $sub, Player $player): void
    {
        $this->assertSame($expected, Player::query()->whereKey($player->id)->eligibleFor($sub)->exists(), 'eligibleFor');
        $this->assertSame($expected, $sub->appliesTo($player), 'appliesTo');
        $this->assertSame($expected, Subscription::query()->whereKey($sub->id)->openTo($player)->exists(), 'openTo');
    }

    #[Test]
    public function both_active_matches_only_when_branch_and_category_share_a_registration(): void
    {
        $player = $this->bothActive();

        $this->assertApplies(true, $this->sub([$this->foot], [$this->senior]), $player);
        $this->assertApplies(true, $this->sub([$this->swim], [$this->u15]), $player);
        $this->assertApplies(false, $this->sub([$this->swim], [$this->senior]), $player);
        $this->assertApplies(false, $this->sub([$this->foot], [$this->u15]), $player);
        $this->assertApplies(true, $this->sub([$this->foot]), $player);
        $this->assertApplies(true, $this->sub([], [$this->u15]), $player);
        $this->assertApplies(true, $this->sub(), $player);
    }

    #[Test]
    public function a_branch_the_player_left_gives_no_new_charge(): void
    {
        $player = $this->leftFootball();

        $this->assertApplies(false, $this->sub([$this->foot]), $player);
        $this->assertApplies(false, $this->sub([$this->foot], [$this->senior]), $player);
        $this->assertApplies(false, $this->sub([], [$this->senior]), $player);
        $this->assertApplies(true, $this->sub([$this->swim], [$this->u15]), $player);
        $this->assertApplies(true, $this->sub([], [$this->u15]), $player);
        $this->assertApplies(true, $this->sub(), $player);
    }

    #[Test]
    public function left_everywhere_matches_nothing(): void
    {
        $player = $this->leftBoth();

        $this->assertApplies(false, $this->sub(), $player);
        $this->assertApplies(false, $this->sub([$this->swim]), $player);
        $this->assertApplies(false, $this->sub([], [$this->u15]), $player);
    }

    #[Test]
    public function no_registration_is_judged_on_the_players_own_columns(): void
    {
        $player = $this->noRegistration();

        $this->assertApplies(true, $this->sub(), $player);
        $this->assertApplies(true, $this->sub([], [$this->senior]), $player);
        $this->assertApplies(false, $this->sub([], [$this->u15]), $player);
        $this->assertApplies(false, $this->sub([$this->foot]), $player);

        $player->update(['status_id' => PlayerStatus::where('code', 'left')->value('id')]);
        $this->assertApplies(false, $this->sub(), $player->fresh());
    }

    #[Test]
    public function any_category_waives_the_category_but_not_the_active_branch(): void
    {
        $sub = $this->sub([$this->foot], [$this->u15]);

        $this->assertFalse($sub->appliesTo($this->bothActive()));
        $this->assertTrue($sub->appliesTo($this->bothActive('Other'), anyCategory: true));
        $this->assertFalse($sub->appliesTo($this->leftFootball(), anyCategory: true));
    }

    #[Test]
    public function assign_all_with_a_category_reads_the_registration_in_the_ticked_branch(): void
    {
        $player = $this->bothActive();
        $sub = $this->sub([$this->swim]);

        $this->actingAs($this->admin())
            ->post(route('subscriptions.assign', $sub), ['assign_all' => true, 'category_id' => $this->senior->id]);
        $this->assertDatabaseCount('player_subscriptions', 0);

        $this->post(route('subscriptions.assign', $sub), ['assign_all' => true, 'category_id' => $this->u15->id])
            ->assertSessionHas('success');
        $this->assertSame([$player->id], PlayerSubscription::where('subscription_id', $sub->id)->pluck('player_id')->all());
    }

    #[Test]
    public function assign_all_leaves_out_a_player_who_left_that_branch(): void
    {
        $stays = $this->bothActive();
        $this->leftFootball();
        $this->leftBoth();
        $sub = $this->sub([$this->foot]);

        $this->actingAs($this->admin())->post(route('subscriptions.assign', $sub), ['assign_all' => true]);

        $this->assertSame([$stays->id], PlayerSubscription::where('subscription_id', $sub->id)->pluck('player_id')->all());
    }

    #[Test]
    public function picking_a_player_by_id_cannot_bypass_the_rule(): void
    {
        $left = $this->leftFootball();
        $sub = $this->sub([$this->foot]);

        $this->actingAs($this->admin())->post(route('subscriptions.assign', $sub), ['player_ids' => [$left->id]]);
        $this->assertDatabaseCount('player_subscriptions', 0);

        $this->post(route('subscriptions.assignOne', $sub), ['player_id' => $left->id, 'outside_category' => true])
            ->assertSessionHas('error', 'flash.player_not_eligible_for_subscription');
        $this->assertDatabaseCount('player_subscriptions', 0);
    }

    #[Test]
    public function the_outside_list_shows_active_members_of_its_branches_not_already_matched(): void
    {
        $sub = $this->sub([$this->swim], [$this->senior]);
        $otherCategory = $this->bothActive();
        $this->leftBoth();
        $leftSwim = $this->bothActive('LeftSwim');
        app(RegistrationService::class)->leave($leftSwim, [$this->swim->id]);
        $matched = Player::create(['firstname' => 'Matched', 'join_year' => 2020]);
        app(RegistrationService::class)->register($matched, ['branch_id' => $this->swim->id, 'category_id' => $this->senior->id]);

        $ids = [];
        $available = [];
        $this->actingAs($this->admin())
            ->get(route('subscriptions.show', $sub))
            ->assertInertia(function (Assert $page) use (&$ids, &$available) {
                $available = collect($page->toArray()['props']['availablePlayers'])->pluck('id')->all();
                $page->reloadOnly('outsidePlayers', function (Assert $reload) use (&$ids) {
                    $ids = collect($reload->toArray()['props']['outsidePlayers'])->pluck('id')->all();
                });
            });

        $this->assertSame([$matched->id], $available);
        $this->assertSame([$otherCategory->id], $ids);
    }

    #[Test]
    public function the_player_page_keeps_an_owed_subscription_of_a_left_branch_but_offers_no_new_one(): void
    {
        $player = $this->leftFootball();
        $owed = $this->sub([$this->foot]);
        $owed->update(['name' => 'Owed']);
        $owed->assignTo($player);
        $this->sub([$this->foot])->update(['name' => 'New football']);
        $this->sub([$this->swim])->update(['name' => 'Swimming']);

        $this->actingAs($this->admin())
            ->get(route('players.show', $player))
            ->assertInertia(fn (Assert $page) => $this->assertSame(
                ['Owed', 'Swimming'],
                collect($page->toArray()['props']['availableSubscriptions'])->pluck('name')->sort()->values()->all(),
            ));
    }

    #[Test]
    public function paying_follows_the_rule_and_a_debt_stays_payable_after_leaving(): void
    {
        $player = $this->leftFootball();
        $new = $this->sub([$this->foot]);
        $owed = $this->sub([$this->foot])->assignTo($player);

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'subscription_id' => $new->id, 'category' => 'subscription', 'amount' => 100, 'payment_method' => 'cash',
            ])
            ->assertSessionHasErrors('subscription_id');

        $this->post(route('players.transactions.store', $player), [
            'player_subscription_id' => $owed->id, 'category' => 'subscription', 'amount' => 100, 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertSame(100.0, (float) $owed->fresh()->amount_paid);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan config:clear && php artisan test tests/Feature/Registrations/SubscriptionEligibilityTest.php`
Expected: FAIL, including `both_active_matches_only_when_branch_and_category_share_a_registration` on `$swim` + `$senior`.

- [ ] **Step 3: Rewrite `Player::scopeEligibleFor`**

Replace the method and its docblock in `app/Models/Player.php`:

```php
    /**
     * Players a subscription applies to: one active registration in one of its
     * branches (any branch when it has none) with one of its categories (any
     * when it has none). Subscription::appliesTo asks this same query.
     *
     * With $anyCategory only the branch rule holds: a player of another category
     * can still be put on it by hand. $onlyCategory narrows to one category,
     * on that same registration.
     */
    public function scopeEligibleFor(Builder $query, Subscription $subscription, bool $anyCategory = false, ?int $onlyCategory = null): void
    {
        $own = $subscription->categories->pluck('id');
        $branches = $subscription->branches->pluck('id');

        $categories = match (true) {
            $onlyCategory !== null => $anyCategory || $own->isEmpty() || $own->contains($onlyCategory) ? [$onlyCategory] : [],
            $anyCategory || $own->isEmpty() => null,
            default => $own,
        };

        $query->registeredWhere(
            branches: $branches->isEmpty() ? null : $branches,
            categories: $categories,
            active: true,
        );
    }
```

- [ ] **Step 4: Rewrite `Subscription::scopeOpenTo` and `appliesTo`**

In `app/Models/Subscription.php`, replace the `scopeOpenTo` docblock and method:

```php
    /**
     * Subscriptions a player may be newly charged: open to the branch and the
     * category of one active registration. Left everywhere: none. A player with no
     * registration at all has only their own category, and no branch.
     */
    public function scopeOpenTo(Builder $query, Player $player): Builder
    {
        $rows = $player->registrations()->active()->get(['branch_id', 'category_id']);

        if ($rows->isEmpty()) {
            if ($player->registrations()->exists() || Player::isLeftStatus($player->status_id)) {
                return $query->whereRaw('1 = 0');
            }
            $rows = collect([(object) ['branch_id' => null, 'category_id' => $player->category_id]]);
        }

        return $query->where(function (Builder $any) use ($rows) {
            foreach ($rows as $row) {
                $any->orWhere(fn (Builder $one) => $one
                    ->forCategory($row->category_id)
                    ->where(function (Builder $open) use ($row) {
                        $open->whereDoesntHave('branches');

                        if ($row->branch_id) {
                            $open->orWhereHas('branches', fn (Builder $sub) => $sub->where('branches.id', $row->branch_id));
                        }
                    }));
            }
        });
    }
```

Delete `appliesToCategory` and `appliesToBranches`; nothing else calls them. Replace `appliesTo` and its docblock with:

```php
    /**
     * Whether the player may be put on this subscription: Player::scopeEligibleFor,
     * so the rule exists once. $anyCategory waives the category (a hand-picked
     * player from another category); the branch must still match.
     */
    public function appliesTo(Player $player, bool $anyCategory = false): bool
    {
        return Player::query()->whereKey($player->id)->eligibleFor($this, $anyCategory)->exists();
    }
```

- [ ] **Step 5: Move the outside list and bulk assign in `SubscriptionController`**

In `show()`, replace the `outsidePlayers` entry and its comment:

```php
            // Active members of its branches it doesn't otherwise apply to (a footballer
            // of category A who also swims in B), loaded when the add dialog asks.
            'outsidePlayers' => Inertia::optional(fn () => $subscription->categories->isEmpty() ? [] : $this->unassignedPlayers($subscription, outside: true)),
```

Replace `unassignedPlayers`' query opening, so the outside list excludes everyone the normal list holds:

```php
        return Player::query()->eligibleFor($subscription, anyCategory: $outside)
            ->when($outside, fn ($query) => $query->whereNotIn('id', Player::query()->select('id')->eligibleFor($subscription)))
            ->where('archived', false)
```

The rest of that method stays until Task P2.2.

Replace the top half of `assign()`, from `$query = Player::query()->eligibleFor($subscription);` through the `DB::transaction` body's loop:

```php
        $query = $request->boolean('assign_all')
            ? Player::query()
                ->eligibleFor($subscription, onlyCategory: $request->filled('category_id') ? $request->integer('category_id') : null)
                ->where('archived', false)
            : Player::query()->eligibleFor($subscription)->whereIn('id', $request->input('player_ids', []));

        $players = $query->with('registrations')->get();

        $existingPlayerIds = PlayerSubscription::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('player_id', $players->modelKeys())
            ->pluck('player_id');

        $newPlayers = $players->reject(fn (Player $player) => $existingPlayerIds->contains($player->id));

        DB::transaction(function () use ($newPlayers, $subscription, $request) {
            foreach ($newPlayers as $player) {
                $subscription->assignTo($player);
                app(RecalculatePlayerDebtService::class)->forPlayer($player);
            }

            if ($newPlayers->isNotEmpty()) {
                ActivityRecorder::record($request->user(), ActivityAction::PLAYERS_ASSIGNED, $subscription, [
                    'count' => $newPlayers->count(),
                ]);
            }
        });

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', ['key' => 'flash.players_assigned', 'params' => ['count' => $newPlayers->count()]]);
```

- [ ] **Step 6: Correct the two stale comments**

In `resources/js/Pages/Subscriptions/Show.vue`, replace lines 108-109:

```js
// Active members of the subscription's branches it doesn't otherwise apply to,
// fetched the first time it is ticked.
```

In `tests/Feature/SubscriptionOutsideCategoryTest.php`, replace the class docblock:

```php
/**
 * The subscription page can hand-pick an active member of its branches from
 * another category (football A, swimming B). The branch rule still holds.
 */
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test tests/Feature/Registrations/SubscriptionEligibilityTest.php tests/Feature/SubscriptionBranchEligibilityTest.php tests/Feature/SubscriptionOutsideCategoryTest.php tests/Feature/SubscriptionKindsTest.php`
Expected: all PASS. These older tests use `branches()->attach()`, whose creating hook copies the player's category onto the registration, so they need no change.

- [ ] **Step 8: Commit**

```bash
git add app/Models/Player.php app/Models/Subscription.php app/Http/Controllers/SubscriptionController.php resources/js/Pages/Subscriptions/Show.vue tests/Feature/SubscriptionOutsideCategoryTest.php tests/Feature/Registrations/SubscriptionEligibilityTest.php
git commit -m "feat(subscriptions): branch and category must match one active registration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P2.2: Price from the matching registration

**Files:**
- Create: `tests/Feature/Registrations/SubscriptionPriceTest.php`
- Modify: `app/Models/Subscription.php` (`amountFor`, `assignTo`)
- Modify: `app/Http/Controllers/SubscriptionController.php` (`unassignedPlayers`, `assign`)
- Modify: `app/Http/Controllers/PlayerController.php` (`availableSubscriptions`)

**Interfaces:**
- Consumes: `RegistrationDisplay::make()`, `->main()`, `->isActiveIn()`.
- Produces: `Subscription::amountFor(Player $player, ?RegistrationDisplay $display = null): float` and `Subscription::assignTo(Player $player, ?RegistrationDisplay $display = null): PlayerSubscription`. A loop passes one display and eager-loads `registrations`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/SubscriptionPriceTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Player\RegistrationDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

/** Spec section 5: the price of the matching registration, otherwise the main one's. */
class SubscriptionPriceTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    /** @param  array<int, float>  $prices  category id => price */
    private function sub(array $branches, array $prices): Subscription
    {
        $sub = Subscription::create([
            'name' => 'Season', 'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $sub->branches()->attach(collect($branches)->pluck('id'));
        foreach ($prices as $categoryId => $price) {
            $sub->categories()->attach($categoryId, ['amount_student' => $price, 'amount_worker' => $price]);
        }

        return $sub;
    }

    private function prices(): array
    {
        return [$this->senior->id => 900.0, $this->u15->id => 700.0];
    }

    #[Test]
    public function a_one_branch_subscription_charges_that_branchs_category(): void
    {
        $player = $this->bothActive();

        $this->assertSame(900.0, $this->sub([$this->foot], $this->prices())->amountFor($player));
        $this->assertSame(700.0, $this->sub([$this->swim], $this->prices())->amountFor($player));
    }

    #[Test]
    public function no_branch_charges_the_main_registrations_category(): void
    {
        $this->assertSame(900.0, $this->sub([], $this->prices())->amountFor($this->bothActive()));
    }

    #[Test]
    public function after_leaving_the_main_branch_the_remaining_one_sets_the_price(): void
    {
        $this->assertSame(700.0, $this->sub([], $this->prices())->amountFor($this->leftFootball()));
    }

    #[Test]
    public function two_ticked_branches_both_matching_charge_the_main_price_once(): void
    {
        $player = $this->bothActive();
        $sub = $this->sub([$this->foot, $this->swim], $this->prices());

        $this->assertSame(900.0, $sub->amountFor($player));

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('subscriptions.assign', $sub), ['assign_all' => true]);
        $this->post(route('subscriptions.assign', $sub), ['assign_all' => true]);
        $this->post(route('subscriptions.assignOne', $sub), ['player_id' => $player->id])
            ->assertSessionHas('error', 'flash.player_already_subscribed');

        $this->assertSame(900.0, (float) PlayerSubscription::where('player_id', $player->id)->sole()->amount_owed);
    }

    #[Test]
    public function two_ticked_branches_with_one_active_registration_charge_that_one(): void
    {
        $this->assertSame(700.0, $this->sub([$this->foot, $this->swim], $this->prices())->amountFor($this->leftFootball()));
    }

    #[Test]
    public function a_no_branch_subscription_uses_the_main_category_even_when_another_row_matches(): void
    {
        $player = $this->bothActive();
        $junior = Category::create(['name' => 'Junior']);

        // Main category Senior is not on the list: sole category's price, then default.
        $this->assertSame(700.0, $this->sub([], [$this->u15->id => 700.0])->amountFor($player));
        $this->assertSame(1500.0, $this->sub([], [$this->u15->id => 700.0, $junior->id => 650.0])->amountFor($player));
    }

    #[Test]
    public function a_hand_added_player_outside_the_categories_keeps_the_existing_fallbacks(): void
    {
        $player = $this->bothActive();
        $junior = Category::create(['name' => 'Junior']);

        $this->assertSame(650.0, $this->sub([$this->swim], [$junior->id => 650.0])->amountFor($player));
        $this->assertSame(1500.0, $this->sub([$this->swim], [$junior->id => 650.0, $this->senior->id => 900.0])->amountFor($player));
    }

    #[Test]
    public function no_registration_uses_the_players_own_category(): void
    {
        $this->assertSame(900.0, $this->sub([], $this->prices())->amountFor($this->noRegistration()));
    }

    #[Test]
    public function a_price_costs_no_query_per_player_when_loaded(): void
    {
        foreach (range(1, 5) as $n) {
            $this->bothActive("P$n");
        }
        $sub = $this->sub([$this->swim], $this->prices())->load(['categories', 'branches']);
        $players = Player::with('registrations')->get();
        $display = RegistrationDisplay::make();

        DB::enableQueryLog();
        $players->each(fn (Player $p) => $sub->amountFor($p, $display));
        $this->assertCount(0, DB::getQueryLog());
    }

    #[Test]
    public function bulk_assign_charges_each_player_their_registrations_price(): void
    {
        $two = $this->bothActive();
        $sub = $this->sub([$this->swim], $this->prices());

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('subscriptions.assign', $sub), ['assign_all' => true]);

        $this->assertSame(700.0, (float) PlayerSubscription::where('player_id', $two->id)->sole()->amount_owed);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/SubscriptionPriceTest.php`
Expected: FAIL on `a_one_branch_subscription_charges_that_branchs_category`, because the swimming price comes from the mirror's Senior.

- [ ] **Step 3: Rewrite `amountFor` and `assignTo`**

In `app/Models/Subscription.php`, add `use App\Services\Player\RegistrationDisplay;`, and replace `amountFor` and its docblock:

```php
    /**
     * What this subscription charges the player: the student or the worker rate,
     * from the override of the category that prices them (see pricingCategoryId).
     * A category not on its list pays the price of its one category when it has
     * just one, the default otherwise. A loop passes one $display.
     */
    public function amountFor(Player $player, ?RegistrationDisplay $display = null): float
    {
        $column = $player->is_student ? 'amount_student' : 'amount_worker';

        $categoryId = $this->pricingCategoryId($player, $display ?? RegistrationDisplay::make());
        $category = $categoryId ? $this->categories->firstWhere('id', $categoryId) : null;
        $category ??= $this->categories->count() === 1 ? $this->categories->first() : null;

        return (float) ($category?->pivot?->{$column} ?? $this->{$column});
    }

    /**
     * The one active registration matching a subscription with branches; the main
     * registration when several match or it has no branch. Nothing when a
     * hand-added player matches none. A player with no registration: their own.
     */
    private function pricingCategoryId(Player $player, RegistrationDisplay $display): ?int
    {
        $main = $display->main($player);
        if ($main === null) {
            return $player->category_id;
        }

        $branchIds = $this->branches->pluck('id');
        if ($branchIds->isEmpty()) {
            return $main->category_id;
        }

        $categoryIds = $this->categories->pluck('id');
        $matching = $player->registrations->filter(fn ($row) => $branchIds->contains($row->branch_id)
            && $display->isActiveIn($player, (int) $row->branch_id)
            && ($categoryIds->isEmpty() || $categoryIds->contains($row->category_id)));

        return match ($matching->count()) {
            0 => null,
            1 => $matching->first()->category_id,
            default => $main->category_id,
        };
    }
```

Change the `assignTo` signature and its price line:

```php
    public function assignTo(Player $player, ?RegistrationDisplay $display = null): PlayerSubscription
```

```php
            'amount_owed' => $this->amountFor($player, $display),
```

- [ ] **Step 4: Pass one display in the loops**

In `SubscriptionController::assign`, add `use App\Services\Player\RegistrationDisplay;` at the file's top, and in the transaction:

```php
        DB::transaction(function () use ($newPlayers, $subscription, $request) {
            $display = RegistrationDisplay::make();

            foreach ($newPlayers as $player) {
                $subscription->assignTo($player, $display);
                app(RecalculatePlayerDebtService::class)->forPlayer($player);
            }
```

In `unassignedPlayers`, replace the end of the chain, from `->with('category')`:

```php
            ->with(['category', 'registrations'])
            ->orderBy('lastname')
            ->get(['id', ...Player::NAME_COLUMNS, 'is_student', 'category_id', 'membership_id'])
            ->each(function (Player $player) use ($subscription, $display) {
                $player->setAttribute('price', $subscription->amountFor($player, $display));
                $player->unsetRelation('registrations');
            });
```

and add `$display = RegistrationDisplay::make();` as the method's first line.

In `PlayerController.php`, add `use App\Services\Player\RegistrationDisplay;`. In `availableSubscriptions`, add `$display = RegistrationDisplay::make();` after `$assigned`, add `$display` to the `map` closure's `use`, and change the price line:

```php
                $owed = $s->amountFor($player, $display);
```

Replace the method's docblock first sentence:

```php
     * The subscription catalog this player may pay, with their status for each:
     * every subscription open to one of their active registrations (mandatory and optional),
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/Registrations/SubscriptionPriceTest.php tests/Feature/Registrations/SubscriptionEligibilityTest.php tests/Feature/SubscriptionOutsideCategoryTest.php tests/Feature/SubscriptionKindsTest.php tests/Feature/CashRegisterManagementTest.php`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Subscription.php app/Http/Controllers/SubscriptionController.php app/Http/Controllers/PlayerController.php tests/Feature/Registrations/SubscriptionPriceTest.php
git commit -m "feat(subscriptions): price a player from the matching registration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P2.3: Subscription page shows the registration's category and ID

Spec section 9's rule (one branch: that registration's values; otherwise main), on the page and export that belong to this part.

**Files:**
- Create: `tests/Feature/Registrations/SubscriptionPageValuesTest.php`
- Modify: `app/Http/Controllers/SubscriptionController.php` (`show`, `unassignedPlayers`, `showRows`, `assignedQuery`, `export`)

**Interfaces:**
- Consumes: `RegistrationDisplay::singleBranch()`, `->shown()`.
- Produces: nothing new for other parts.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/SubscriptionPageValuesTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class SubscriptionPageValuesTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create());
    }

    private function sub(array $branches = []): Subscription
    {
        $sub = Subscription::create([
            'name' => 'Season', 'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $sub->branches()->attach(collect($branches)->pluck('id'));

        return $sub;
    }

    private function props(Subscription $sub): array
    {
        $props = [];
        $this->get(route('subscriptions.show', $sub))
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    #[Test]
    public function a_one_branch_subscription_shows_that_registrations_values(): void
    {
        $player = $this->bothActive();
        $swimId = $player->registrationIn($this->swim->id)->membership_id;
        $sub = $this->sub([$this->swim]);

        $offered = $this->props($sub)['availablePlayers'][0];
        $this->assertSame($swimId, $offered['membership_id']);
        $this->assertSame('U15', $offered['category']['name']);
        $this->assertArrayNotHasKey('registrations', $offered);

        $sub->assignTo($player);
        $row = $this->props($sub)['playerSubscriptions'][0]['player'];
        $this->assertSame($swimId, $row['membership_id']);
        $this->assertSame('U15', $row['category']['name']);
    }

    #[Test]
    public function no_branch_or_two_branches_show_the_main_registration(): void
    {
        $player = $this->bothActive();
        $footId = $player->registrationIn($this->foot->id)->membership_id;

        foreach ([[], [$this->foot, $this->swim]] as $branches) {
            $sub = $this->sub($branches);
            $sub->assignTo($player);
            $row = $this->props($sub)['playerSubscriptions'][0]['player'];
            $this->assertSame($footId, $row['membership_id']);
            $this->assertSame('Senior', $row['category']['name']);
        }
    }

    #[Test]
    public function a_debt_of_a_left_branch_keeps_that_registrations_values(): void
    {
        $player = $this->leftFootball();
        $footId = $player->registrationIn($this->foot->id)->membership_id;
        $sub = $this->sub([$this->foot]);
        $sub->assignTo($player);

        $row = $this->props($sub)['playerSubscriptions'][0]['player'];

        $this->assertSame($footId, $row['membership_id']);
        $this->assertSame('Senior', $row['category']['name']);
    }

    #[Test]
    public function no_registration_shows_the_players_own_values(): void
    {
        $player = $this->noRegistration();
        $player->update(['membership_id' => '2020FLAT1']);
        $sub = $this->sub();
        $sub->assignTo($player);

        $row = $this->props($sub)['playerSubscriptions'][0]['player'];

        $this->assertSame('2020FLAT1', $row['membership_id']);
        $this->assertSame('Senior', $row['category']['name']);
    }

    #[Test]
    public function the_export_follows_the_page(): void
    {
        $player = $this->bothActive();
        $sub = $this->sub([$this->swim]);
        $sub->assignTo($player);

        $csv = $this->get(route('subscriptions.export', ['subscription' => $sub, 'format' => 'csv']))->assertOk()->streamedContent();

        $this->assertStringContainsString($player->registrationIn($this->swim->id)->membership_id, $csv);
        $this->assertStringContainsString('U15', $csv);
        $this->assertStringNotContainsString('Senior', $csv);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/SubscriptionPageValuesTest.php`
Expected: FAIL on `a_one_branch_subscription_shows_that_registrations_values`: Senior instead of U15.

- [ ] **Step 3: Add the helper and use it**

In `SubscriptionController.php`, add above `unassignedPlayers`:

```php
    /** The branch this subscription's page speaks for: its one branch, otherwise none (main values). */
    private function pageBranch(Subscription $subscription): ?int
    {
        return RegistrationDisplay::singleBranch($subscription->branches->pluck('id'));
    }

    /**
     * What the page and export show for a player: that branch's registration, else
     * the main one. A player with no registration shows their own values.
     *
     * @return array{membership_id: ?string, category: ?Category}
     */
    private function shownValues(Player $player, ?int $branchId, RegistrationDisplay $display): array
    {
        $row = $display->shown($player, $branchId);

        return [
            'membership_id' => $row?->membership_id ?? $player->membership_id,
            'category' => $row ? $row->category : $player->category,
        ];
    }
```

In `show()`, change the first rows line:

```php
        $rows = $this->showRows($this->assignedQuery($subscription)->get(), $this->pageBranch($subscription));
```

In `unassignedPlayers`, the full method becomes:

```php
    private function unassignedPlayers(Subscription $subscription, bool $outside = false): Collection
    {
        $display = RegistrationDisplay::make();
        $branchId = $this->pageBranch($subscription);

        return Player::query()->eligibleFor($subscription, anyCategory: $outside)
            ->when($outside, fn ($query) => $query->whereNotIn('id', Player::query()->select('id')->eligibleFor($subscription)))
            ->where('archived', false)
            ->whereNotIn('id', PlayerSubscription::query()->select('player_id')->where('subscription_id', $subscription->id))
            ->with(['category', 'registrations.category'])
            ->orderBy('lastname')
            ->get(['id', ...Player::NAME_COLUMNS, 'is_student', 'category_id', 'membership_id'])
            ->each(function (Player $player) use ($subscription, $display, $branchId) {
                $shown = $this->shownValues($player, $branchId, $display);
                $player->setAttribute('price', $subscription->amountFor($player, $display));
                $player->setAttribute('membership_id', $shown['membership_id']);
                $player->setRelation('category', $shown['category']);
                $player->unsetRelation('registrations');
            });
    }
```

In `showRows`, change the signature and docblock parameter:

```php
     * @param  Collection<int, PlayerSubscription>  $playerSubscriptions
     * @return list<array<string, mixed>>
     */
    private function showRows(Collection $playerSubscriptions, ?int $branchId): array
```

Add `$display = RegistrationDisplay::make();` after the `$category` closure, add `$display, $branchId` to the `map` closure's `use`, and replace the `player` entry:

```php
                $money = $ps->money();
                $player = $ps->player;
                $shown = $player === null ? null : $this->shownValues($player, $branchId, $display);

                return [
                    'id' => $ps->id,
                    'player_id' => $ps->player_id,
                    'amount_owed' => $ps->amount_owed,
                    'amount_paid' => $ps->amount_paid,
                    'remaining_amount' => $money['remaining'],
                    'payment_status' => $money['status'],
                    'player' => $player === null ? null : [
                        'id' => $player->id,
                        'membership_id' => $shown['membership_id'],
                        'lastname' => $player->lastname,
                        'fullname' => $player->fullname,
                        'category' => $category($shown['category']),
                    ],
                ];
```

In `assignedQuery`, extend `with`:

```php
            ->with([
                'player' => fn ($player) => $player->select(['id', 'membership_id', 'category_id', ...Player::NAME_COLUMNS]),
                'player.category',
                'player.registrations.category',
            ]);
```

In `export`, before the row loop add:

```php
        $display = RegistrationDisplay::make();
        $branchId = $this->pageBranch($subscription);
```

and replace the loop's body:

```php
        foreach ($playerSubscriptions as $ps) {
            $player = $ps->player;
            $name = $player ? $player->fullname : 'N/A';
            $shown = $player ? $this->shownValues($player, $branchId, $display) : ['membership_id' => null, 'category' => null];

            $rows[] = [
                $rowNum++,
                $shown['membership_id'] !== null ? (string) $shown['membership_id'] : '-',
                $name,
                $shown['category']?->localized_name ?? '-',
                (float) $ps->amount_owed,
                (float) $ps->amount_paid,
                // Same key the page's statusLabel('payment', ...) resolves to.
                UiLang::get((string) $ps->payment_status, strtoupper((string) $ps->payment_status)),
            ];
        }
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test tests/Feature/Registrations/SubscriptionPageValuesTest.php tests/Feature/SubscriptionBranchEligibilityTest.php tests/Feature/SubscriptionOutsideCategoryTest.php tests/Feature/SubscriptionBranchTest.php`
Expected: all PASS. If `the_export_follows_the_page` fails on `Senior`, check the sheet's title didn't bring it in; the subscription name is `Season`.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SubscriptionController.php tests/Feature/Registrations/SubscriptionPageValuesTest.php
git commit -m "feat(subscriptions): the page and export show the branch's registration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P2.4: Pin "debt survives leaving"

The code already behaves this way. These tests stop a later change from breaking it, and the comments say why.

**Files:**
- Create: `tests/Feature/Registrations/DebtSurvivesLeavingTest.php`
- Modify: `app/Services/Finance/PruneOutOfBranchObligations.php` (docblock)
- Modify: `app/Services/Dashboard/Support/BranchScope.php` (docblock)

**Interfaces:** none new.

- [ ] **Step 1: Write the test**

`tests/Feature/Registrations/DebtSurvivesLeavingTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\Subscription;
use App\Services\Dashboard\Support\BranchScope;
use App\Services\Finance\PruneOutOfBranchObligations;
use App\Services\Finance\RecalculatePlayerDebtService;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

/** Spec section 5: leaving never cancels money. */
class DebtSurvivesLeavingTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    private function footballSub(): Subscription
    {
        $sub = Subscription::create([
            'name' => 'Football', 'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $sub->branches()->attach($this->foot->id);

        return $sub;
    }

    #[Test]
    public function leaving_touches_no_charge(): void
    {
        $player = $this->bothActive();
        $owed = $this->footballSub()->assignTo($player);
        app(RecalculatePlayerDebtService::class)->forPlayer($player);
        $before = DB::table('player_subscriptions')->where('id', $owed->id)->first();
        $debt = (float) $player->fresh()->outstanding_debt;

        app(RegistrationService::class)->leave($player, [$this->foot->id], $this->leaveDate);

        $this->assertEquals($before, DB::table('player_subscriptions')->where('id', $owed->id)->first());
        $this->assertSame($debt, (float) $player->fresh()->outstanding_debt);
        $this->assertSame(1500.0, $debt);
    }

    #[Test]
    public function leaving_every_branch_still_owes(): void
    {
        $player = $this->bothActive();
        $owed = $this->footballSub()->assignTo($player);

        app(RegistrationService::class)->leave($player, [$this->foot->id, $this->swim->id], $this->leaveDate);

        $this->assertModelExists($owed);
    }

    #[Test]
    public function pruning_counts_a_left_registration_as_in_the_branch(): void
    {
        $sub = $this->footballSub();
        $owed = $sub->assignTo($this->leftFootball());
        $outsider = Player::create(['firstname' => 'Outsider', 'join_year' => 2020]);
        app(RegistrationService::class)->register($outsider, ['branch_id' => $this->swim->id]);
        $stale = $sub->assignTo($outsider);

        $removed = app(PruneOutOfBranchObligations::class)->forSubscription($sub);

        $this->assertSame(1, $removed);
        $this->assertModelExists($owed);
        $this->assertModelMissing($stale);
    }

    #[Test]
    public function the_branch_scope_keeps_a_left_registration_for_its_debt(): void
    {
        $left = $this->leftFootball();

        $this->assertSame([$left->id], BranchScope::players(Player::query(), $this->foot->id)->pluck('id')->all());
        $this->assertSame([$left->id], BranchScope::playerIdsQuery($this->foot->id)->pluck('player_id')->all());
    }
}
```

- [ ] **Step 2: Run it**

Run: `php artisan test tests/Feature/Registrations/DebtSurvivesLeavingTest.php`
Expected: all PASS with no code change. If any fails, stop: an earlier task broke a money rule.

- [ ] **Step 3: Add the two comments**

In `PruneOutOfBranchObligations.php`, extend the class docblock's last sentence:

```php
 * partly paid or exempt stays, so no recorded money loses its obligation. A
 * registration marked left still counts as in the branch: leaving never cancels debt.
```

In `BranchScope.php`, add to the class docblock:

```php
 * A player who left a branch still counts in it here, because their debt there
 * is still owed. A figure about current members must add its own active rule.
```

- [ ] **Step 4: Commit**

```bash
git add app/Services/Finance/PruneOutOfBranchObligations.php app/Services/Dashboard/Support/BranchScope.php tests/Feature/Registrations/DebtSurvivesLeavingTest.php
git commit -m "test(finance): pin that leaving a branch never cancels a debt

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P2.5: Default caisse

**Files:**
- Create: `tests/Feature/Registrations/DefaultRegisterRegistrationTest.php`
- Modify: `app/Services/Finance/DefaultRegisterResolver.php` (`forPlayer`, `forSubscription`)
- Modify: `app/Http/Controllers/PlayerTransactionController.php` (`defaultRegisterId`)
- Modify: `app/Http/Controllers/PlayerController.php` (`availableSubscriptions`)
- Modify: `app/Http/Controllers/TransactionController.php` (`formOptions`)
- Modify: `app/Observers/TransactionObserver.php` (`saving`)

**Interfaces:**
- Consumes: `RegistrationDisplay`, `Player::registrationIn`.
- Produces: `DefaultRegisterResolver::forPlayer(Player $player): ?FinanceAccount` (reads `registrations`, not `branches`) and `DefaultRegisterResolver::forSubscription(Subscription $subscription, Player $player): ?FinanceAccount` (null unless exactly one branch). `for()` is unchanged.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/DefaultRegisterRegistrationTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FinanceAccount;
use App\Models\Player;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\DefaultRegisterResolver;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

/** Spec section 5: the caisse for a payment. */
class DefaultRegisterRegistrationTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private FinanceAccount $footSenior;

    private FinanceAccount $swimU15;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->footSenior = $this->register($this->foot, $this->senior);
        $this->swimU15 = $this->register($this->swim, $this->u15);
    }

    private function register(Branch $branch, Category $category): FinanceAccount
    {
        return FinanceAccount::create([
            'branch_id' => $branch->id, 'category_id' => $category->id, 'parent_account_id' => $branch->treasury->id,
            'name' => 'Caisse '.$branch->name, 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ]);
    }

    private function clubCash(): FinanceAccount
    {
        return FinanceAccount::whereNull('branch_id')->where('name', 'Cash')->firstOrFail();
    }

    private function resolver(): DefaultRegisterResolver
    {
        return DefaultRegisterResolver::load();
    }

    private function sub(array $branches = [], array $categories = []): Subscription
    {
        $sub = Subscription::create([
            'name' => 'Season', 'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $sub->branches()->attach(collect($branches)->pluck('id'));
        $sub->categories()->attach(collect($categories)->pluck('id'));

        return $sub;
    }

    #[Test]
    public function a_player_uses_the_main_registrations_caisse_whatever_the_row_order(): void
    {
        $player = Player::create(['firstname' => 'SwimFirst', 'join_year' => 2020]);
        $service = app(RegistrationService::class);
        $service->register($player, ['branch_id' => $this->swim->id, 'category_id' => $this->u15->id]);
        $service->register($player, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);

        $this->assertSame($this->footSenior->id, $this->resolver()->forPlayer($player->fresh())->id);
    }

    #[Test]
    public function a_left_main_branch_is_never_used(): void
    {
        $this->assertSame($this->swimU15->id, $this->resolver()->forPlayer($this->leftFootball())->id);
    }

    #[Test]
    public function left_everywhere_goes_to_club_cash(): void
    {
        $this->assertSame($this->clubCash()->id, $this->resolver()->forPlayer($this->leftBoth())->id);
    }

    #[Test]
    public function no_registration_goes_to_club_cash(): void
    {
        $this->assertSame($this->clubCash()->id, $this->resolver()->forPlayer($this->noRegistration())->id);
    }

    #[Test]
    public function a_one_branch_subscription_uses_that_branch_with_the_players_category_there(): void
    {
        $player = $this->bothActive();

        $this->assertSame($this->swimU15->id, $this->resolver()->forSubscription($this->sub([$this->swim]), $player)->id);
        $this->assertSame($this->swimU15->id, $this->resolver()->forSubscription($this->sub([$this->swim], [$this->u15, $this->senior]), $player)->id);
    }

    #[Test]
    public function a_one_branch_one_category_subscription_uses_its_own_category(): void
    {
        $this->assertSame($this->footSenior->id, $this->resolver()->forSubscription($this->sub([$this->foot], [$this->senior]), $this->leftBoth())->id);
    }

    #[Test]
    public function with_no_category_caisse_it_uses_that_branchs_treasury(): void
    {
        $this->swimU15->update(['is_active' => false]);

        $this->assertSame($this->swim->treasury->id, $this->resolver()->forSubscription($this->sub([$this->swim]), $this->bothActive())->id);
    }

    #[Test]
    public function no_branch_or_two_branches_leave_it_to_the_player(): void
    {
        $player = $this->bothActive();

        $this->assertNull($this->resolver()->forSubscription($this->sub(), $player));
        $this->assertNull($this->resolver()->forSubscription($this->sub([$this->foot, $this->swim]), $player));
    }

    #[Test]
    public function a_debt_of_a_left_branch_is_paid_into_that_branch(): void
    {
        $player = $this->leftFootball();
        $owed = $this->sub([$this->foot])->assignTo($player);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('players.transactions.store', $player), [
                'player_subscription_id' => $owed->id, 'category' => 'subscription', 'amount' => 100, 'payment_method' => 'cash',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->footSenior->id, Transaction::latest('id')->value('finance_account_id'));
    }

    #[Test]
    public function a_donation_uses_the_main_registrations_caisse(): void
    {
        $player = $this->leftFootball();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('players.transactions.store', $player), ['category' => 'donation', 'amount' => 50, 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->swimU15->id, Transaction::latest('id')->value('finance_account_id'));
    }

    #[Test]
    public function a_transaction_saved_with_no_account_follows_the_same_rule(): void
    {
        $player = $this->bothActive();
        $owed = $this->sub([$this->swim])->assignTo($player);

        $transaction = Transaction::create([
            'amount' => 100, 'transaction_date' => now(), 'transaction_type' => 'income', 'category' => 'subscription',
            'payment_method' => 'cash', 'status' => 'Paid',
            'related_entity_type' => 'Player', 'related_entity_id' => $player->id, 'player_subscription_id' => $owed->id,
        ]);

        $this->assertSame($this->swimU15->id, $transaction->finance_account_id);
    }

    #[Test]
    public function the_pages_preselect_the_same_caisse(): void
    {
        $player = $this->leftFootball();
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('players.show', $player))
            ->assertInertia(fn (Assert $page) => $page->where('defaultFinanceAccountId', $this->swimU15->id));

        $this->get(route('transactions.create'))
            ->assertInertia(fn (Assert $page) => $page->where('players.0.default_finance_account_id', $this->swimU15->id));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/DefaultRegisterRegistrationTest.php`
Expected: FAIL, at least `ArgumentCountError` or wrong caisse on `a_one_branch_subscription_uses_that_branch_with_the_players_category_there`, and football's caisse on `a_left_main_branch_is_never_used`.

- [ ] **Step 3: Rewrite the resolver**

In `app/Services/Finance/DefaultRegisterResolver.php`, add `use App\Services\Player\RegistrationDisplay;`, add the property after `$clubWide`:

```php
    private ?RegistrationDisplay $display = null;
```

Replace `forPlayer` and `forSubscription`:

```php
    /**
     * The main registration's register. Never a branch the player left: left
     * everywhere, or with no registration, it is the club-level account. Eager-load
     * `registrations` when resolving for many players.
     */
    public function forPlayer(Player $player): ?FinanceAccount
    {
        $this->display ??= RegistrationDisplay::make();
        $main = $this->display->main($player);

        return $main !== null && $this->display->isActiveIn($player, (int) $main->branch_id)
            ? $this->for((int) $main->branch_id, $main->category_id)
            : $this->for(null, null);
    }

    /**
     * The register of a subscription tied to exactly one branch, or null otherwise.
     * Its one category, else the player's category in that branch, else that branch's
     * treasury. A debt of a branch the player left still lands there: the money
     * was owed to that sport. Expects both relations loaded.
     */
    public function forSubscription(Subscription $subscription, Player $player): ?FinanceAccount
    {
        if ($subscription->branches->count() !== 1) {
            return null;
        }

        $branchId = (int) $subscription->branches->first()->id;
        $categoryId = $subscription->categories->count() === 1
            ? $subscription->categories->first()->id
            : $player->registrationIn($branchId)?->category_id;

        return $this->for($branchId, $categoryId);
    }
```

Replace the class docblock's opening sentence:

```php
 * Picks the cash register a payment lands in when none was chosen: the
 * subscription's branch when it has exactly one, else the player's main
 * registration, each with its category register, else that branch's treasury,
 * else the first club-level cash account. The server default and the value
 * the forms pre-select both come from here, so they cannot disagree.
```

- [ ] **Step 4: Update the four callers**

`PlayerController::availableSubscriptions`:

```php
                    'default_finance_account_id' => $registers->forSubscription($s, $player)?->id,
```

`PlayerTransactionController::defaultRegisterId`, replacing its docblock and body:

```php
    /**
     * Where a payment lands when the form left the register empty: the register
     * of a one-branch subscription, else the player's default.
     */
    private function defaultRegisterId(Player $player, ?PlayerSubscription $sub = null): ?int
    {
        $registers = DefaultRegisterResolver::load();
        $player->loadMissing('registrations');
        $subscription = $sub?->loadMissing(['subscription.branches:id', 'subscription.categories:id'])->subscription;

        $register = ($subscription ? $registers->forSubscription($subscription, $player) : null)
            ?? $registers->forPlayer($player);

        return $register?->id;
    }
```

`TransactionController::formOptions`, the `players` query's `with`:

```php
                ->with([
                    'branches:id,name,name_ar,name_fr,name_en',
                    'category:id,name,name_ar,name_fr,name_en',
                    'registrations:id,player_id,branch_id,category_id,status_id,join_date,legacy_join_year',
                ])
```

`TransactionObserver::saving`, replacing the block under `if (empty($transaction->finance_account_id))`:

```php
        if (empty($transaction->finance_account_id)) {
            $player = $transaction->related_entity_type === 'Player' && $transaction->related_entity_id
                ? Player::with('registrations')->find($transaction->related_entity_id)
                : null;
            $subscription = $player && $transaction->player_subscription_id
                ? PlayerSubscription::with(['subscription.branches:id', 'subscription.categories:id'])
                    ->find($transaction->player_subscription_id)?->subscription
                : null;
            $registers = DefaultRegisterResolver::load();

            $transaction->finance_account_id = ($player
                ? ($subscription ? $registers->forSubscription($subscription, $player) : null) ?? $registers->forPlayer($player)
                : $registers->for(null, null))?->id;
        }
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/Registrations/DefaultRegisterRegistrationTest.php tests/Feature/CashRegisterManagementTest.php tests/Feature/TransactionFormOptionsTest.php tests/Feature/ActivityMoneyEventsTest.php`
Expected: all PASS. `selecting_a_subscription_defaults_to_its_branch_category_register` keeps passing, because that subscription has one category.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Finance/DefaultRegisterResolver.php app/Http/Controllers/PlayerTransactionController.php app/Http/Controllers/PlayerController.php app/Http/Controllers/TransactionController.php app/Observers/TransactionObserver.php tests/Feature/Registrations/DefaultRegisterRegistrationTest.php
git commit -m "feat(finance): default caisse follows the subscription's branch, then the main registration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P2.6: Debt reports filter and show the registration

**Files:**
- Create: `tests/Feature/Registrations/ObligationReportsTest.php`
- Modify: `app/Reports/Concerns/ReadsObligations.php` (`obligations`)

`OutstandingPayments` and `SubscriptionCollection` read `obligations()` rows, so they need no edit of their own: `membership_id` and `category_id` keep those names.

**Interfaces:**
- Consumes: `Player::registeredWhere`, `RegistrationDisplay::singleBranch`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/ObligationReportsTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class ObligationReportsTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create());
    }

    private function owes(Player $player): void
    {
        Subscription::create([
            'name' => 'Club', 'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ])->assignTo($player);
    }

    /** @return list<array<string, mixed>> */
    private function rows(array $query = []): array
    {
        $rows = [];
        $this->get(route('reports.view', ['finance', 'debtors', ...$query]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$rows) {
                $rows = $page->toArray()['props']['rows']['data'];
            });

        return $rows;
    }

    #[Test]
    public function branch_and_category_filters_hold_on_one_registration(): void
    {
        $this->owes($this->bothActive());

        $this->assertCount(0, $this->rows(['branch_id' => [$this->swim->id], 'category_id' => [$this->senior->id]]));
        $this->assertCount(1, $this->rows(['branch_id' => [$this->swim->id], 'category_id' => [$this->u15->id]]));
    }

    #[Test]
    public function no_branch_or_several_show_the_main_registration(): void
    {
        $player = $this->bothActive();
        $this->owes($player);
        $footId = $player->registrationIn($this->foot->id)->membership_id;

        foreach ([[], ['branch_id' => [$this->foot->id, $this->swim->id]]] as $query) {
            $rows = $this->rows($query);
            $this->assertCount(1, $rows);
            $this->assertSame('Senior', $rows[0]['category']);
            $this->assertSame($footId, $rows[0]['membership_id']);
        }
    }

    #[Test]
    public function one_branch_shows_that_registration(): void
    {
        $player = $this->bothActive();
        $this->owes($player);

        $rows = $this->rows(['branch_id' => [$this->swim->id]]);

        $this->assertCount(1, $rows);
        $this->assertSame('U15', $rows[0]['category']);
        $this->assertSame($player->registrationIn($this->swim->id)->membership_id, $rows[0]['membership_id']);
    }

    #[Test]
    public function a_debt_stays_listed_under_a_branch_the_player_left(): void
    {
        $player = $this->leftFootball();
        $this->owes($player);

        $rows = $this->rows(['branch_id' => [$this->foot->id]]);

        $this->assertCount(1, $rows);
        $this->assertSame('Senior', $rows[0]['category']);
    }

    #[Test]
    public function left_everywhere_still_appears_with_no_filter(): void
    {
        $this->owes($this->leftBoth());

        $this->assertCount(1, $this->rows());
    }

    #[Test]
    public function no_registration_matches_on_its_own_category_and_no_branch(): void
    {
        $player = $this->noRegistration();
        $player->update(['membership_id' => '2020FLAT1']);
        $this->owes($player);

        $this->assertSame('2020FLAT1', $this->rows()[0]['membership_id']);
        $this->assertCount(1, $this->rows(['category_id' => [$this->senior->id]]));
        $this->assertCount(0, $this->rows(['category_id' => [$this->u15->id]]));
        $this->assertCount(0, $this->rows(['branch_id' => [$this->foot->id]]));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/ObligationReportsTest.php`
Expected: FAIL on `branch_and_category_filters_hold_on_one_registration` and `one_branch_shows_that_registration`.

- [ ] **Step 3: Rewrite the filter and identity columns**

In `app/Reports/Concerns/ReadsObligations.php`, add `use App\Models\Player;` and `use App\Services\Player\RegistrationDisplay;`. Replace the `category_id` and `branch_id` conditions and the final `get` call:

```php
        $branches = $filters->filled('branch_id') ? $filters->get('branch_id') : null;
        $categories = $filters->filled('category_id') ? $filters->get('category_id') : null;

        // Left registrations count: their debt is still owed.
        if ($branches !== null || $categories !== null) {
            $query->whereIn('players.id', Player::query()->select('players.id')->registeredWhere(branches: $branches, categories: $categories));
        }

        // One branch selected shows that registration; otherwise the player copy holds the main one.
        $branch = RegistrationDisplay::singleBranch($branches);
        if ($branch !== null) {
            $query->join('branch_player as shown', fn ($join) => $join->on('shown.player_id', '=', 'players.id')->where('shown.branch_id', $branch));
        }
        $identity = $branch === null
            ? ['players.membership_id', 'players.category_id']
            : [DB::raw('COALESCE(shown.membership_id, players.membership_id) as membership_id'), 'shown.category_id as category_id'];

        return $query->get([
            'ps.id', 'ps.player_id', 'ps.label', 'ps.amount_owed', 'ps.amount_paid', 'ps.is_exempt', 'ps.discount_type', 'ps.discount_value', 'ps.due_date',
            's.id as subscription_id', 's.name as subscription_name', 's.year as subscription_year', 's.kind as subscription_kind',
            ...$identity, 'players.phones',
            'players.firstname', 'players.lastname', 'players.nickname', 'players.father', 'players.grandfather', 'players.gender',
        ])->map(function (object $row) {
```

The rest of the method stays.

- [ ] **Step 4: Run the tests**

Run: `php artisan test tests/Feature/Registrations/ObligationReportsTest.php tests/Feature/Reports/FinanceReportsTest.php`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Reports/Concerns/ReadsObligations.php tests/Feature/Registrations/ObligationReportsTest.php
git commit -m "feat(reports): debt reports filter and show the registration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P2.7: The forecast reads each registration's category

Who the forecast counts stays exactly as today, including players who left. Task P2.9 reports that to the owner as a question.

**Files:**
- Create: `tests/js/tarificationRoster.test.mjs`
- Modify: `app/Services/Finance/TarificationData.php` (`roster`)
- Modify: `resources/js/lib/tarification.js` (`matchingGroups`, `priceFor`)
- Modify: `tests/Feature/SubscriptionTarificationTest.php` (`the_roster_groups_players_once_and_leaves_out_archived_and_exempt`; add one test)

**Interfaces:**
- Produces: `tarification.roster` entries become `{category_id: int|null, is_student: bool, registrations: list<{branch_id: int, category_id: int|null}>, count: int}`. `branch_ids` is gone. `category_id` is the main registration's, from the mirror.

- [ ] **Step 1: Write the failing JS test**

`tests/js/tarificationRoster.test.mjs`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { autoPlayers, averagePrice } from '../../resources/js/lib/tarification.js';

const FOOT = 1;
const SWIM = 2;
const SENIOR = 10;
const U15 = 20;

const twoBranch = {
    category_id: SENIOR,
    is_student: true,
    count: 3,
    registrations: [{ branch_id: FOOT, category_id: SENIOR }, { branch_id: SWIM, category_id: U15 }],
};
const noRegistration = { category_id: SENIOR, is_student: true, count: 2, registrations: [] };

const sub = (over = {}) => ({
    amount_student: 1500,
    amount_worker: 1500,
    category_ids: [],
    branch_ids: [],
    category_prices: {},
    ...over,
});

test('branch and category must hold on one registration', () => {
    assert.equal(autoPlayers([twoBranch], sub({ branch_ids: [SWIM], category_ids: [U15] })), 3);
    assert.equal(autoPlayers([twoBranch], sub({ branch_ids: [SWIM], category_ids: [SENIOR] })), 0);
    assert.equal(autoPlayers([twoBranch], sub({ branch_ids: [FOOT] })), 3);
    assert.equal(autoPlayers([twoBranch], sub({ category_ids: [U15] })), 3);
});

test('a two-branch player counts once', () => {
    assert.equal(autoPlayers([twoBranch], sub()), 3);
    assert.equal(autoPlayers([twoBranch], sub({ branch_ids: [FOOT, SWIM] })), 3);
});

test('no registration matches on its own category and never on a branch', () => {
    assert.equal(autoPlayers([noRegistration], sub({ category_ids: [SENIOR] })), 2);
    assert.equal(autoPlayers([noRegistration], sub({ category_ids: [U15] })), 0);
    assert.equal(autoPlayers([noRegistration], sub({ branch_ids: [FOOT] })), 0);
});

test('price follows Subscription::amountFor', () => {
    const prices = {
        [SENIOR]: { amount_student: 900, amount_worker: 900 },
        [U15]: { amount_student: 700, amount_worker: 700 },
    };
    const categories = { category_ids: [SENIOR, U15], category_prices: prices };

    assert.equal(averagePrice([twoBranch], sub({ ...categories, branch_ids: [SWIM] })), 700);
    assert.equal(averagePrice([twoBranch], sub({ ...categories, branch_ids: [FOOT, SWIM] })), 900);
    assert.equal(averagePrice([twoBranch], sub(categories)), 900);
});

test('a main category off the list takes the sole price, then the default', () => {
    assert.equal(averagePrice([twoBranch], sub({
        category_ids: [U15],
        category_prices: { [U15]: { amount_student: 700, amount_worker: 700 } },
    })), 700);
    assert.equal(averagePrice([twoBranch], sub({
        category_ids: [U15, 30],
        category_prices: { [U15]: { amount_student: 700, amount_worker: 700 }, 30: { amount_student: 650, amount_worker: 650 } },
    })), 1500);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:js`
Expected: `tarificationRoster.test.mjs` FAILS on `branch and category must hold on one registration`. Other JS tests pass.

- [ ] **Step 3: Rewrite the two JS functions**

In `resources/js/lib/tarification.js`, replace `matchingGroups`:

```js
// The registrations a subscription applies to, as in Player::scopeEligibleFor:
// branch and category hold on one row. With none, the group's own category and no branch.
function matchingRows(group, subscription) {
    const categoryIds = subscription.category_ids ?? [];
    const branchIds = subscription.branch_ids ?? [];
    const rows = group.registrations?.length ? group.registrations : [{ branch_id: null, category_id: group.category_id }];

    return rows.filter((row) => {
        const inCategory = !categoryIds.length || (row.category_id !== null && includesId(categoryIds, row.category_id));
        const inBranch = !branchIds.length || (row.branch_id !== null && includesId(branchIds, row.branch_id));

        return inCategory && inBranch;
    });
}

export function matchingGroups(roster, subscription) {
    return roster.filter((group) => matchingRows(group, subscription).length > 0);
}
```

Replace `priceFor`:

```js
// The category that prices a group: the one matching row of a subscription with
// branches, otherwise the main one. Off the list: its sole category, or none.
function pricingCategory(group, subscription) {
    const rows = matchingRows(group, subscription);
    const categoryIds = subscription.category_ids ?? [];
    const category = (subscription.branch_ids ?? []).length && rows.length === 1 ? rows[0].category_id : group.category_id;

    if (includesId(categoryIds, category)) return category;

    return categoryIds.length === 1 ? categoryIds[0] : null;
}

// Same result as Subscription::amountFor, from values that may still be unsaved.
function priceFor(group, subscription) {
    const column = group.is_student ? 'amount_student' : 'amount_worker';
    const override = subscription.category_prices?.[pricingCategory(group, subscription)]?.[column];

    return number(isBlank(override) ? subscription[column] : override);
}
```

- [ ] **Step 4: Run the JS test**

Run: `npm run test:js`
Expected: all PASS.

- [ ] **Step 5: Update the PHP test and add the new one**

In `tests/Feature/SubscriptionTarificationTest.php`, replace the two `branch_ids` assertions in `the_roster_groups_players_once_and_leaves_out_archived_and_exempt`:

```php
                        && collect($groups[1]['registrations'])->pluck('branch_id')->all() === collect([$football->id, $swimming->id])->sort()->values()->all()
                        && $groups[0]['count'] === 1
                        && $groups[0]['registrations'] === [];
```

Add `use App\Services\Finance\TarificationData;` and `use App\Services\Player\RegistrationService;`, and add this test after it:

```php
    #[Test]
    public function the_roster_reads_each_registrations_category_and_still_counts_left_players(): void
    {
        $senior = Category::create(['name' => 'Senior']);
        $u15 = Category::create(['name' => 'U15']);
        $football = Branch::create(['name' => 'Football']);
        $swimming = Branch::create(['name' => 'Swimming']);
        $service = app(RegistrationService::class);

        $two = $this->player();
        $service->register($two, ['branch_id' => $football->id, 'category_id' => $senior->id]);
        $service->register($two, ['branch_id' => $swimming->id, 'category_id' => $u15->id]);

        $left = $this->player();
        $service->register($left, ['branch_id' => $football->id, 'category_id' => $senior->id]);
        $service->leave($left, [$football->id], '2026-03-15');

        $roster = collect(app(TarificationData::class)->for(null)['roster']);

        $this->assertSame(2, $roster->sum('count'));
        $this->assertTrue($roster->contains(fn (array $group) => $group['registrations'] === [
            ['branch_id' => $football->id, 'category_id' => $senior->id],
            ['branch_id' => $swimming->id, 'category_id' => $u15->id],
        ]));
        $this->assertSame($senior->id, $roster->firstWhere('count', 1)['category_id']);
    }
```

- [ ] **Step 6: Run it to verify it fails**

Run: `php artisan test tests/Feature/SubscriptionTarificationTest.php`
Expected: FAIL with an undefined `registrations` key.

- [ ] **Step 7: Rewrite `TarificationData::roster`**

Replace the method and docblock:

```php
    /**
     * Players grouped by main category, status and exact set of registrations: a
     * small prop however big the club, and a player in two branches counts once.
     * A left player or registration still counts here. The owner is asked about this.
     *
     * @return list<array{category_id: int|null, is_student: bool, registrations: list<array{branch_id: int, category_id: int|null}>, count: int}>
     */
    private function roster(?Subscription $subscription): array
    {
        $exempt = $subscription === null
            ? collect()
            : PlayerSubscription::query()
                ->where('subscription_id', $subscription->id)
                ->where('is_exempt', true)
                ->pluck('player_id');

        $registrations = DB::table('branch_player')->orderBy('branch_id')->get(['player_id', 'branch_id', 'category_id'])
            ->groupBy('player_id')
            ->map(fn (Collection $rows): array => $rows
                ->map(fn ($row): array => [
                    'branch_id' => (int) $row->branch_id,
                    'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                ])
                ->all());

        return Player::query()
            ->where('archived', false)
            ->whereNotIn('id', $exempt)
            ->toBase()
            ->get(['id', 'category_id', 'is_student'])
            ->groupBy(fn ($player): string => implode('|', [
                $player->category_id ?? '',
                (int) $player->is_student,
                json_encode($registrations[$player->id] ?? []),
            ]))
            ->map(fn (Collection $players): array => [
                'category_id' => $players->first()->category_id === null ? null : (int) $players->first()->category_id,
                'is_student' => (bool) $players->first()->is_student,
                'registrations' => $registrations[$players->first()->id] ?? [],
                'count' => $players->count(),
            ])
            ->values()
            ->all();
    }
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test tests/Feature/SubscriptionTarificationTest.php && npm run test:js`
Expected: all PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Finance/TarificationData.php resources/js/lib/tarification.js tests/js/tarificationRoster.test.mjs tests/Feature/SubscriptionTarificationTest.php
git commit -m "feat(tarification): the forecast reads each registration's category

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P2.8: Receipts name one registration

**Files:**
- Create: `app/Services/Finance/ReceiptRegistration.php`
- Create: `tests/Feature/Registrations/ReceiptRegistrationTest.php`
- Modify: `app/Services/Finance/ReceiptData.php` (`build`)
- Modify: `app/Services/Finance/ReceiptPrinter.php` (`snapshot`)
- Modify: `resources/views/pdf/receipt.blade.php:45-48`
- Modify: `resources/views/pdf/receipt-ticket.blade.php:67-72`

**Interfaces:**
- Consumes: `RegistrationDisplay::make()`, `->shown()`.
- Produces: `ReceiptRegistration::shown(Player $player, Collection $lines): ?BranchRegistration`. `$lines` is every transaction printed on the paper. Views receive `memberId`, `memberCategory` and `branches` (now one name, or null).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/ReceiptRegistrationTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\ReceiptPrint;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\ReceiptData;
use App\Services\Finance\ReceiptPrinter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

/** Spec section 5: a receipt shows the subscription's branch, otherwise the main registration. */
class ReceiptRegistrationTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    private function owed(Player $player, array $branches = []): PlayerSubscription
    {
        $sub = Subscription::create([
            'name' => 'Season '.uniqid(), 'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $sub->branches()->attach(collect($branches)->pluck('id'));

        return $sub->assignTo($player);
    }

    private function paid(Player $player, ?PlayerSubscription $line, string $category = 'subscription'): Transaction
    {
        return Transaction::create([
            'amount' => 100, 'transaction_date' => now(), 'transaction_type' => 'income', 'category' => $category,
            'payment_method' => 'cash', 'status' => 'Paid',
            'related_entity_type' => 'Player', 'related_entity_id' => $player->id, 'player_subscription_id' => $line?->id,
        ]);
    }

    private function receipt(Transaction $transaction, ?Collection $lines = null): array
    {
        $user = User::factory()->admin()->create();
        $print = app(ReceiptPrinter::class)->record($transaction, ReceiptPrint::FORMAT_THERMAL, $user, $lines);

        return app(ReceiptData::class)->build($transaction, $print, $lines);
    }

    private function assertShows(array $data, ?string $branch, ?string $category, ?string $id): void
    {
        $this->assertSame($branch, $data['branches']);
        $this->assertSame($category, $data['memberCategory']);
        $this->assertSame($id, $data['memberId']);
    }

    #[Test]
    public function a_one_branch_subscription_shows_that_registration(): void
    {
        $player = $this->bothActive();
        $data = $this->receipt($this->paid($player, $this->owed($player, [$this->swim])));

        $this->assertShows($data, 'Swimming', 'U15', $player->registrationIn($this->swim->id)->membership_id);
    }

    #[Test]
    public function no_branch_or_two_branches_show_the_main_registration(): void
    {
        $player = $this->bothActive();
        $footId = $player->registrationIn($this->foot->id)->membership_id;

        $this->assertShows($this->receipt($this->paid($player, $this->owed($player))), 'Football', 'Senior', $footId);
        $this->assertShows($this->receipt($this->paid($player, $this->owed($player, [$this->foot, $this->swim]))), 'Football', 'Senior', $footId);
    }

    #[Test]
    public function a_payment_with_no_subscription_shows_the_main_registration(): void
    {
        $player = $this->bothActive();

        $this->assertShows($this->receipt($this->paid($player, null, 'donation')), 'Football', 'Senior', $player->registrationIn($this->foot->id)->membership_id);
    }

    #[Test]
    public function a_group_uses_the_branch_only_when_all_lines_share_it(): void
    {
        $player = $this->bothActive();
        $swimA = $this->paid($player, $this->owed($player, [$this->swim]));
        $swimB = $this->paid($player, $this->owed($player, [$this->swim]));
        $foot = $this->paid($player, $this->owed($player, [$this->foot]));

        $shared = $this->receipt($swimA, new Collection([$swimA, $swimB]));
        $this->assertSame('Swimming', $shared['branches']);

        $mixed = $this->receipt($swimA, new Collection([$swimA, $foot]));
        $this->assertSame('Football', $mixed['branches']);
    }

    #[Test]
    public function a_grouped_donation_does_not_break_the_shared_branch(): void
    {
        $player = $this->bothActive();
        $swim = $this->paid($player, $this->owed($player, [$this->swim]));
        $donation = $this->paid($player, null, 'donation');

        $this->assertSame('Swimming', $this->receipt($swim, new Collection([$swim, $donation]))['branches']);
    }

    #[Test]
    public function a_paid_debt_of_a_left_branch_still_names_it(): void
    {
        $player = $this->leftFootball();
        $data = $this->receipt($this->paid($player, $this->owed($player, [$this->foot])));

        $this->assertShows($data, 'Football', 'Senior', $player->registrationIn($this->foot->id)->membership_id);
    }

    #[Test]
    public function left_everywhere_still_prints(): void
    {
        $player = $this->leftBoth();

        $this->assertSame('Senior', $this->receipt($this->paid($player, $this->owed($player)))['memberCategory']);
    }

    #[Test]
    public function no_registration_shows_the_players_own_values_and_no_branch(): void
    {
        $player = $this->noRegistration();
        $player->update(['membership_id' => '2020FLAT1']);

        $this->assertShows($this->receipt($this->paid($player, $this->owed($player))), null, 'Senior', '2020FLAT1');
    }

    #[Test]
    public function both_papers_print_it_and_the_snapshot_records_the_same_id(): void
    {
        $player = $this->bothActive();
        $swimId = $player->registrationIn($this->swim->id)->membership_id;
        $footId = $player->registrationIn($this->foot->id)->membership_id;
        $transaction = $this->paid($player, $this->owed($player, [$this->swim]));
        $data = $this->receipt($transaction);

        foreach (['pdf.receipt', 'pdf.receipt-ticket'] as $view) {
            $html = view($view, [...$data, 'rtl' => false])->render();
            $this->assertStringContainsString($swimId, $html);
            $this->assertStringContainsString('U15', $html);
            $this->assertStringContainsString('Swimming', $html);
            $this->assertStringNotContainsString($footId, $html);
        }

        $this->assertSame($swimId, ReceiptPrint::sole()->snapshot['membership_id']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/ReceiptRegistrationTest.php`
Expected: FAIL with `Undefined array key "memberCategory"` or a wrong `branches` value.

- [ ] **Step 3: Write `ReceiptRegistration`**

`app/Services/Finance/ReceiptRegistration.php`:

```php
<?php

namespace App\Services\Finance;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Services\Player\RegistrationDisplay;
use Illuminate\Support\Collection;

/**
 * Which registration a printed receipt names: the branch of the subscription it
 * settles when that subscription has exactly one; a group uses it only if all
 * its subscription lines share it; otherwise the main registration.
 */
class ReceiptRegistration
{
    /** @param  Collection<int, Transaction>  $lines  every transaction on the paper */
    public function shown(Player $player, Collection $lines): ?BranchRegistration
    {
        return RegistrationDisplay::make()->shown($player, $this->sharedBranch($lines));
    }

    /**
     * A line with no subscription (the donation of what was paid beyond them) has
     * no say. A line whose subscription was deleted, or spans several branches, does.
     *
     * @param  Collection<int, Transaction>  $lines
     */
    private function sharedBranch(Collection $lines): ?int
    {
        $ids = $lines->pluck('player_subscription_id')->filter()->unique();
        if ($ids->isEmpty()) {
            return null;
        }

        $branches = PlayerSubscription::with('subscription.branches:id')->whereKey($ids->all())->get()
            ->map(function (PlayerSubscription $line) {
                $own = $line->subscription?->branches;

                return $own !== null && $own->count() === 1 ? (int) $own->first()->id : null;
            });

        return $branches->count() === $ids->count() && $branches->unique()->count() === 1 ? $branches->first() : null;
    }
}
```

- [ ] **Step 4: Use it in `ReceiptData`**

In `app/Services/Finance/ReceiptData.php`, add the constructor after the `RELATIONS` constant:

```php
    public function __construct(private readonly ReceiptRegistration $registrations) {}
```

In `build()`, replace the `$player` lookup:

```php
        $player = $transaction->related_entity_type === 'Player' && $transaction->related_entity_id
            ? Player::with(['category', 'registrations.branch', 'registrations.category'])->find($transaction->related_entity_id)
            : null;
        $registration = $player ? $this->registrations->shown($player, $lines ?? collect([$transaction])) : null;
```

Replace the `'branches'` entry:

```php
            'branches' => $registration?->branch?->localized_name,
            'memberId' => $registration?->membership_id ?? $player?->membership_id,
            'memberCategory' => ($registration ? $registration->category : $player?->category)?->localized_name,
```

- [ ] **Step 5: Use it in the two views**

`resources/views/pdf/receipt.blade.php`, lines 45-48 become:

```blade
            <tr><td class="label">{{ __('Member') }}</td><td>{{ $relatedName }}@if ($memberId) — {{ $memberId }}@endif</td></tr>
            @if ($memberCategory)
                <tr><td class="label">{{ __('Category') }}</td><td>{{ $memberCategory }}</td></tr>
            @endif
```

`resources/views/pdf/receipt-ticket.blade.php`, lines 67-72 become:

```blade
            @if ($memberId)
                <tr><td>{{ __('Membership No.') }}</td><td class="value" align="{{ $end }}">{{ $memberId }}</td></tr>
            @endif
            @if ($memberCategory)
                <tr><td>{{ __('Category') }}</td><td class="value" align="{{ $end }}">{{ $memberCategory }}</td></tr>
            @endif
```

- [ ] **Step 6: Make the snapshot say what the paper says**

In `app/Services/Finance/ReceiptPrinter.php`, add `use App\Models\Player;`, and a constructor above `record`:

```php
    public function __construct(private readonly ReceiptRegistration $registrations) {}
```

In `snapshot()`, replace the first line:

```php
        $single = [...$this->single($transaction), 'membership_id' => $this->printedId($transaction, $lines)];
```

Add this method after `snapshot()`:

```php
    /**
     * The ID the paper prints. Not in CHECKED_FIELDS: it is a record of the paper,
     * and a registration's ID changing later doesn't make the payment suspect.
     *
     * @param  Collection<int, Transaction>|null  $lines
     */
    private function printedId(Transaction $transaction, ?Collection $lines): ?string
    {
        $player = $transaction->related_entity_type === 'Player' && $transaction->related_entity_id
            ? Player::with('registrations')->find($transaction->related_entity_id)
            : null;
        if ($player === null) {
            return null;
        }

        return $this->registrations->shown($player, $lines ?? collect([$transaction]))?->membership_id ?? $player->membership_id;
    }
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test tests/Feature/Registrations/ReceiptRegistrationTest.php tests/Feature/ReceiptPrintTest.php`
Expected: all PASS. `ReceiptPrintTest` needs no change: its player has an old-format ID on the player, and `memberId` falls back to it, because a bare `attach` gives the row none.

- [ ] **Step 8: Check the paper visually**

mPDF ignores some CSS, so a passing HTML test doesn't prove the layout. Render both receipts for a two-branch player on the real-data copy, in Arabic and French:

```bash
( . .superpowers/r2/env-sqlite.sh; php artisan serve --port=2027 --no-reload )
```

In a browser at `http://127.0.0.1:2027`, open a swimming payment of a two-branch player and print the A4 and ticket versions. Expected: one branch name, its category and its `SW-` ID, right-to-left in Arabic. IDM may capture a stale same-named PDF, so check the file's time. Stop the server afterwards. If the two-branch player has no swimming payment in the copy, record one on the copy.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Finance/ReceiptRegistration.php app/Services/Finance/ReceiptData.php app/Services/Finance/ReceiptPrinter.php resources/views/pdf/receipt.blade.php resources/views/pdf/receipt-ticket.blade.php tests/Feature/Registrations/ReceiptRegistrationTest.php
git commit -m "feat(receipts): a receipt names the subscription's branch registration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P2.9: Closing check of Part 2

**Files:**
- Modify: `.superpowers/r2/progress.md`, `.superpowers/r2/differences.md`, `.superpowers/r2/owner-questions.md` (git-ignored)

- [ ] **Step 1: Run the full checks**

```bash
php artisan config:clear
php artisan test 2>&1 | tail -15
npm run test:js 2>&1 | tail -5
npm run i18n:check
npm run build 2>&1 | tail -3
```

Expected: only `ManualSuspensionsTest` may fail. If a test elsewhere fails because it changes `players.category_id` directly on a player with a registration and then expects eligibility or a price to follow, change that test to write through `app(RegistrationService::class)->setField($player, 'category_id', $id)`. Don't weaken the rule. Any failure of another kind is a bug in this part.

- [ ] **Step 2: Compare the pinned figures**

```bash
( . .superpowers/r2/env-sqlite.sh; php .superpowers/r2/figures.php p2 )
php .superpowers/r2/diff-figures.php p1 p2 > .superpowers/r2/data/diff-p2.txt; tail -1 .superpowers/r2/data/diff-p2.txt
grep -E "player_subscriptions|transactions" .superpowers/r2/data/diff-p2.txt | wc -l
grep "^UNEXPECTED" .superpowers/r2/data/diff-p2.txt | grep -v "^UNEXPECTED: eligible [0-9]* lost player" | wc -l
```

Expected:
- `player_subscriptions` and `transactions` lines: **0**. Any money difference stops this part.
- Other `UNEXPECTED` lines: **0**.
- `UNEXPECTED: eligible ... lost player` lines are expected. `diff-figures.php` exits 1 because of them. Step 3 verifies each one.
- `two-branch:` lines are allowed. Copy them to `.superpowers/r2/differences.md`, each traced to "branch and category on one registration".

- [ ] **Step 3: Prove the lost players are exactly players who left**

```bash
grep -oE "^UNEXPECTED: eligible [0-9]+ lost player [0-9]+" .superpowers/r2/data/diff-p2.txt | awk '{print $NF}' | sort -un > .superpowers/r2/data/lost-p2.txt
( . .superpowers/r2/env-sqlite.sh; php artisan tinker --execute='
$lost = array_map("intval", file(".superpowers/r2/data/lost-p2.txt"));
$active = App\Models\Player::whereKey($lost)->registeredWhere(active: true)->pluck("id")->all();
echo "lost ".count($lost).", still active somewhere: ".json_encode($active).PHP_EOL;' )
```

Expected: `still active somewhere: []`. The test copy has 12 left registrations, so at most about 12 players appear. A one-branch player still active in that list is a bug.

Record this in `.superpowers/r2/differences.md` for the owner's report: **"A player who has left is no longer offered on a subscription page or payable on a new subscription. Before this release they were. Debts they already owe are unchanged."** The spec's verification section says each one-branch player's eligibility stays identical, while its section 5 says active registrations only. Section 5 is the owner's decision, so it wins, and the owner is told.

- [ ] **Step 4: Check the new query on the other two databases**

```bash
( . .superpowers/r2/env-pgsql.sh; export DB_DATABASE=sportclub_r2_test DB_URL=
  php artisan test tests/Feature/Registrations/SubscriptionEligibilityTest.php tests/Feature/Registrations/SubscriptionPriceTest.php tests/Feature/Registrations/SubscriptionPageValuesTest.php tests/Feature/Registrations/ObligationReportsTest.php tests/Feature/Registrations/DefaultRegisterRegistrationTest.php tests/Feature/Registrations/ReceiptRegistrationTest.php )
( export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3399 DB_DATABASE=sportclub_r2_test DB_USERNAME=root DB_PASSWORD= DB_URL= APP_CONFIG_CACHE="D:/irnb-branch-reg-r2/.superpowers/r2/no-config-cache.php"
  php artisan test tests/Feature/Registrations/SubscriptionEligibilityTest.php tests/Feature/Registrations/SubscriptionPriceTest.php tests/Feature/Registrations/SubscriptionPageValuesTest.php tests/Feature/Registrations/ObligationReportsTest.php tests/Feature/Registrations/DefaultRegisterRegistrationTest.php tests/Feature/Registrations/ReceiptRegistrationTest.php )
```

Expected: all PASS on both. Start the private instances first if stopped (main plan, Tasks 3 and 7). Fix a failure in this part's SQL here. A failure in older code goes to `pg-report.md`.

- [ ] **Step 5: Queue the owner's question**

Add to `.superpowers/r2/owner-questions.md`:

> **Forecast and players who left.** The subscription forecast (Tarification) counts every non-archived player, including players who left. So a subscription's "expected players" can be higher than who can actually be charged. Example on the test copy: count the left players with `SELECT COUNT(*) FROM players WHERE status_id = (SELECT id FROM player_statuses WHERE code = 'left') AND archived = 0`, and quote that number. Kept as it is today, as the spec says. Should the forecast leave them out? Asked at Part 8's report. Changing it is one line in `TarificationData::roster`, plus using only active rows there.

Run the query above on the copy and put the number in the note. Record the suite counts in `.superpowers/r2/progress.md`.

## Self-review against spec section 5

| Spec requirement | Task |
|---|---|
| Eligibility table on active registrations | P2.1 |
| Bulk assign, available list, player page catalog | P2.1 |
| Outside-category list: active members not matched; left player not listed | P2.1 |
| Price: matching registration; several or no branch: main; fallbacks stay | P2.2 |
| Two ticked branches: one charge per person; no new column | P2.2 |
| `leave()` touches no charge; `PruneOutOfBranchObligations` keeps left as in branch | P2.4 |
| Caisse: subscription's one branch, else main; never left; never first row | P2.5 |
| `ReadsObligations`, `OutstandingPayments`: shared query, registration's category and ID | P2.6 |
| `BranchScope` keeps left registrations | P2.4 |
| `TarificationData` per registration; left players unchanged; question to owner | P2.7, P2.9 |
| `ReceiptData`, `ReceiptPrinter`, both PDFs; group rule | P2.8 |
| `FixSubscriptionBilling` not touched | Global constraints. It calls none of the changed methods |
| Four cases in tests; one-branch figures identical; money identical | Each task, P2.9 |
