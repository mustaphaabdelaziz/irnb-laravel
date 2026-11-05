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
        static $n = 0;

        $sub = Subscription::create([
            'name' => 'Season '.++$n, 'year' => (int) now()->year,
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
