<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionCategoryEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function category(string $name): Category
    {
        return Category::create(['name' => $name]);
    }

    private function makePlayer(?Category $category): Player
    {
        return Player::create([
            'membership_id' => '81'.str_pad((string) ++$this->seq, 8, '0', STR_PAD_LEFT),
            'firstname' => 'Elig', 'lastname' => 'Player'.$this->seq,
            'is_student' => true, 'outstanding_debt' => 0,
            'category_id' => $category?->id,
        ]);
    }

    /** @param  array<int, Category>  $categories */
    private function catalogSub(string $name, array $categories = [], float $price = 1500): Subscription
    {
        $sub = Subscription::create([
            'name' => $name,
            'year' => (int) now()->year,
            'amount_student' => $price, 'amount_worker' => $price,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $sub->categories()->attach(collect($categories)->pluck('id'));

        return $sub;
    }

    private function assign(Player $player, Subscription $sub): PlayerSubscription
    {
        return PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => $sub->id, 'transaction_id' => null,
            'year' => $sub->year, 'status_at_time' => 'student',
            'is_mandatory' => false, 'amount_owed' => (float) $sub->amount_student, 'amount_paid' => 0,
        ]);
    }

    /** @return array<int, string> */
    private function profileSubscriptionNames(Player $player): array
    {
        $names = [];
        $this->actingAs($this->admin())
            ->get(route('players.show', $player))
            ->assertOk()
            ->assertInertia(function ($page) use (&$names) {
                $names = collect($page->toArray()['props']['availableSubscriptions'])->pluck('name')->sort()->values()->all();
            });

        return $names;
    }

    #[Test]
    public function the_profile_lists_only_subscriptions_for_the_players_category_or_all_categories(): void
    {
        $cadet = $this->category('Cadet');
        $ecole = $this->category('Ecole');
        $senior = $this->category('Senior');
        $this->catalogSub('Open to all');
        $this->catalogSub('Youth camp', [$cadet, $ecole]);
        $this->catalogSub('Senior league', [$senior]);

        $this->assertSame(['Open to all', 'Youth camp'], $this->profileSubscriptionNames($this->makePlayer($cadet)));
    }

    #[Test]
    public function the_profile_still_lists_an_out_of_category_subscription_the_player_already_owes(): void
    {
        $cadet = $this->category('Cadet');
        $senior = $this->category('Senior');
        $player = $this->makePlayer($cadet);
        $this->assign($player, $this->catalogSub('Senior league', [$senior]));

        $this->assertSame(['Senior league'], $this->profileSubscriptionNames($player));
    }

    #[Test]
    public function a_player_without_a_category_only_sees_subscriptions_open_to_all(): void
    {
        $this->catalogSub('Open to all');
        $this->catalogSub('Youth camp', [$this->category('Cadet')]);

        $this->assertSame(['Open to all'], $this->profileSubscriptionNames($this->makePlayer(null)));
    }

    #[Test]
    public function paying_a_subscription_outside_the_players_category_is_rejected(): void
    {
        $player = $this->makePlayer($this->category('Cadet'));
        $senior = $this->catalogSub('Senior league', [$this->category('Senior')]);

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'subscription_id' => $senior->id,
                'category' => 'subscription',
                'amount' => 1500,
                'payment_method' => 'cash',
            ])
            ->assertSessionHasErrors('subscription_id');

        $this->assertDatabaseCount('player_subscriptions', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    #[Test]
    public function paying_a_subscription_matching_the_players_category_assigns_it(): void
    {
        $cadet = $this->category('Cadet');
        $player = $this->makePlayer($cadet);
        $youth = $this->catalogSub('Youth camp', [$cadet]);

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'subscription_id' => $youth->id,
                'category' => 'subscription',
                'amount' => 1500,
                'payment_method' => 'cash',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1500.0, (float) PlayerSubscription::where('player_id', $player->id)
            ->where('subscription_id', $youth->id)->firstOrFail()->amount_paid);
    }

    #[Test]
    public function an_out_of_category_subscription_already_owed_can_still_be_paid(): void
    {
        $player = $this->makePlayer($this->category('Cadet'));
        $senior = $this->catalogSub('Senior league', [$this->category('Senior')]);
        $ps = $this->assign($player, $senior);

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'subscription_id' => $senior->id,
                'category' => 'subscription',
                'amount' => 1500,
                'payment_method' => 'cash',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1500.0, (float) $ps->fresh()->amount_paid);
    }

    #[Test]
    public function the_subscription_page_only_offers_players_of_its_categories(): void
    {
        $cadet = $this->category('Cadet');
        $ecole = $this->category('Ecole');
        $senior = $this->category('Senior');
        $youth = $this->catalogSub('Youth camp', [$cadet, $ecole]);
        $cadetPlayer = $this->makePlayer($cadet);
        $ecolePlayer = $this->makePlayer($ecole);
        $this->makePlayer($senior);
        $this->makePlayer(null);

        $this->actingAs($this->admin())
            ->get(route('subscriptions.show', $youth))
            ->assertOk()
            ->assertInertia(function ($page) use ($cadetPlayer, $ecolePlayer) {
                $ids = collect($page->toArray()['props']['availablePlayers'])->pluck('id')->sort()->values()->all();
                $this->assertSame([$cadetPlayer->id, $ecolePlayer->id], $ids);
            });
    }

    #[Test]
    public function assigning_one_player_outside_the_subscription_categories_is_refused(): void
    {
        $youth = $this->catalogSub('Youth camp', [$this->category('Cadet')]);
        $senior = $this->makePlayer($this->category('Senior'));

        $this->actingAs($this->admin())
            ->post(route('subscriptions.assignOne', $youth), ['player_id' => $senior->id])
            ->assertSessionHas('error', 'flash.player_not_eligible_for_subscription');

        $this->assertDatabaseCount('player_subscriptions', 0);
    }

    #[Test]
    public function assigning_one_eligible_player_creates_the_obligation(): void
    {
        $cadet = $this->category('Cadet');
        $youth = $this->catalogSub('Youth camp', [$cadet]);
        $player = $this->makePlayer($cadet);

        $this->actingAs($this->admin())
            ->post(route('subscriptions.assignOne', $youth), ['player_id' => $player->id])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('player_subscriptions', ['player_id' => $player->id, 'subscription_id' => $youth->id]);
    }

    #[Test]
    public function assign_all_only_assigns_players_of_the_subscription_categories(): void
    {
        $cadet = $this->category('Cadet');
        $ecole = $this->category('Ecole');
        $youth = $this->catalogSub('Youth camp', [$cadet, $ecole]);
        $cadetPlayer = $this->makePlayer($cadet);
        $ecolePlayer = $this->makePlayer($ecole);
        $this->makePlayer($this->category('Senior'));
        $this->makePlayer(null);

        $this->actingAs($this->admin())
            ->post(route('subscriptions.assign', $youth), ['assign_all' => true])
            ->assertSessionHas('success');

        $this->assertEqualsCanonicalizing(
            [$cadetPlayer->id, $ecolePlayer->id],
            PlayerSubscription::where('subscription_id', $youth->id)->pluck('player_id')->all(),
        );
    }

    #[Test]
    public function assign_all_with_a_category_outside_the_subscription_assigns_nobody(): void
    {
        $youth = $this->catalogSub('Youth camp', [$this->category('Cadet')]);
        $senior = $this->category('Senior');
        $this->makePlayer($senior);

        $this->actingAs($this->admin())
            ->post(route('subscriptions.assign', $youth), ['assign_all' => true, 'category_id' => $senior->id]);

        $this->assertDatabaseCount('player_subscriptions', 0);
    }

    #[Test]
    public function assigning_a_player_list_skips_players_outside_the_subscription_categories(): void
    {
        $cadet = $this->category('Cadet');
        $youth = $this->catalogSub('Youth camp', [$cadet]);
        $cadetPlayer = $this->makePlayer($cadet);
        $seniorPlayer = $this->makePlayer($this->category('Senior'));

        $this->actingAs($this->admin())
            ->post(route('subscriptions.assign', $youth), ['player_ids' => [$cadetPlayer->id, $seniorPlayer->id]]);

        $this->assertSame(
            [$cadetPlayer->id],
            PlayerSubscription::where('subscription_id', $youth->id)->pluck('player_id')->all(),
        );
    }
}
