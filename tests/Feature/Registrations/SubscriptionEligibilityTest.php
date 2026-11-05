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
        $matched = Player::create(['membership_id' => $this->fixtureId(), 'firstname' => 'Matched', 'join_year' => 2020]);
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
