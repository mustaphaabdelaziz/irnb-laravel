<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A player has one category for all their branches, so the subscription page
 * can hand-pick a player of its branches from another category (football A,
 * swimming B). The branch rule still holds.
 */
class SubscriptionOutsideCategoryTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** @param  array<int, Branch>  $branches */
    private function makePlayer(array $branches, ?Category $category): Player
    {
        $player = Player::create([
            'membership_id' => '83'.str_pad((string) ++$this->seq, 8, '0', STR_PAD_LEFT),
            'firstname' => 'Out', 'lastname' => 'Player'.$this->seq,
            'is_student' => true, 'outstanding_debt' => 0,
            'category_id' => $category?->id,
        ]);
        $player->branches()->attach(collect($branches)->pluck('id'));

        return $player;
    }

    /** @param  array<int, Category>  $categories  @param  array<int, float|null>  $prices */
    private function catalogSub(Branch $branch, array $categories, array $prices = []): Subscription
    {
        $sub = Subscription::create([
            'name' => 'Swim season',
            'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $sub->branches()->attach($branch->id);
        foreach ($categories as $i => $category) {
            $sub->categories()->attach($category->id, ['amount_student' => $prices[$i] ?? null, 'amount_worker' => $prices[$i] ?? null]);
        }

        return $sub;
    }

    /** @return array<int, int> */
    private function outsidePlayerIds(Subscription $sub): array
    {
        $ids = [];
        $this->actingAs($this->admin())
            ->get(route('subscriptions.show', $sub))
            ->assertInertia(function (Assert $page) use (&$ids) {
                $page->reloadOnly('outsidePlayers', function (Assert $reload) use (&$ids) {
                    $ids = collect($reload->toArray()['props']['outsidePlayers'])->pluck('id')->sort()->values()->all();
                });
            });

        return $ids;
    }

    #[Test]
    public function outside_players_are_only_loaded_on_request(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $sub = $this->catalogSub($swim, [Category::create(['name' => 'B'])]);

        $this->actingAs($this->admin())
            ->get(route('subscriptions.show', $sub))
            ->assertInertia(fn (Assert $page) => $page->missing('outsidePlayers'));
    }

    #[Test]
    public function outside_players_are_its_branch_players_of_other_categories(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $football = Branch::create(['name' => 'Football']);
        $a = Category::create(['name' => 'A']);
        $b = Category::create(['name' => 'B']);
        $sub = $this->catalogSub($swim, [$b]);
        $footballerWhoSwims = $this->makePlayer([$football, $swim], $a);
        $noCategorySwimmer = $this->makePlayer([$swim], null);
        $this->makePlayer([$swim], $b);           // already offered in the normal list
        $this->makePlayer([$football], $a);       // not in the branch

        $this->assertSame([$footballerWhoSwims->id, $noCategorySwimmer->id], $this->outsidePlayerIds($sub));
    }

    #[Test]
    public function an_outside_player_can_be_assigned_by_hand_at_the_subscription_category_price(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $a = Category::create(['name' => 'A']);
        $b = Category::create(['name' => 'B']);
        $sub = $this->catalogSub($swim, [$b], [900]);
        $player = $this->makePlayer([$swim], $a);

        $this->actingAs($this->admin())
            ->post(route('subscriptions.assignOne', $sub), ['player_id' => $player->id, 'outside_category' => true])
            ->assertSessionHas('success');

        $this->assertSame(900.0, (float) PlayerSubscription::where('player_id', $player->id)->sole()->amount_owed);
        $this->assertGreaterThan(0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function an_outside_player_of_a_multi_category_subscription_owes_the_default_price(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $sub = $this->catalogSub($swim, [Category::create(['name' => 'B']), Category::create(['name' => 'C'])], [900, 700]);
        $player = $this->makePlayer([$swim], Category::create(['name' => 'A']));

        $this->actingAs($this->admin())
            ->post(route('subscriptions.assignOne', $sub), ['player_id' => $player->id, 'outside_category' => true]);

        $this->assertSame(1500.0, (float) PlayerSubscription::where('player_id', $player->id)->sole()->amount_owed);
    }

    #[Test]
    public function the_branch_rule_still_holds_for_a_hand_picked_player(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $football = Branch::create(['name' => 'Football']);
        $sub = $this->catalogSub($swim, [Category::create(['name' => 'B'])]);
        $player = $this->makePlayer([$football], Category::create(['name' => 'A']));

        $this->actingAs($this->admin())
            ->post(route('subscriptions.assignOne', $sub), ['player_id' => $player->id, 'outside_category' => true])
            ->assertSessionHas('error', 'flash.player_not_eligible_for_subscription');

        $this->assertDatabaseCount('player_subscriptions', 0);
    }
}
