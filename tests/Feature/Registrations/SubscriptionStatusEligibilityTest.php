<?php

namespace Tests\Feature\Registrations;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

/** A subscription may be limited to some player statuses; none ticked = every status but `left`. */
class SubscriptionStatusEligibilityTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private PlayerStatus $registered;

    private PlayerStatus $retired;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->registered = PlayerStatus::firstOrCreate(['code' => 'registered'], ['name' => 'Registered', 'sort_order' => 0]);
        $this->retired = PlayerStatus::firstOrCreate(['code' => 'retired'], ['name' => 'Retired', 'sort_order' => 1]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function sub(array $branches = [], array $statuses = []): Subscription
    {
        $sub = Subscription::create([
            'name' => 'Season '.uniqid(), 'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $sub->branches()->attach(collect($branches)->pluck('id'));
        $sub->statuses()->attach(collect($statuses)->pluck('id'));

        return $sub->fresh();
    }

    private function setStatus(Player $player, $branch, PlayerStatus $status): Player
    {
        BranchRegistration::where('player_id', $player->id)->where('branch_id', $branch->id)->first()
            ->update(['status_id' => $status->id]);

        return $player->refresh();
    }

    private function assertApplies(bool $expected, Subscription $sub, Player $player): void
    {
        $this->assertSame($expected, Player::query()->whereKey($player->id)->eligibleFor($sub)->exists(), 'eligibleFor');
        $this->assertSame($expected, $sub->appliesTo($player), 'appliesTo');
        $this->assertSame($expected, $sub->appliesTo($player, anyCategory: true), 'appliesTo anyCategory');
        $this->assertSame($expected, Subscription::query()->whereKey($sub->id)->openTo($player)->exists(), 'openTo');
    }

    #[Test]
    public function no_ticked_status_keeps_every_status_but_left(): void
    {
        $player = $this->setStatus($this->bothActive(), $this->foot, $this->retired);

        $this->assertApplies(true, $this->sub([$this->foot]), $player);
    }

    #[Test]
    public function a_ticked_list_leaves_out_other_statuses_in_that_branch(): void
    {
        $player = $this->setStatus($this->bothActive(), $this->foot, $this->retired);
        $this->setStatus($player, $this->swim, $this->registered);

        $this->assertApplies(false, $this->sub([$this->foot], [$this->registered]), $player);
        $this->assertApplies(true, $this->sub([$this->foot], [$this->registered, $this->retired]), $player);
        $this->assertApplies(true, $this->sub([$this->swim], [$this->registered]), $player);
        // No branch ticked: one registration with a ticked status is enough.
        $this->assertApplies(true, $this->sub([], [$this->registered]), $player);
    }

    #[Test]
    public function a_registration_without_status_always_qualifies(): void
    {
        $player = $this->bothActive();

        $this->assertApplies(true, $this->sub([$this->foot], [$this->registered]), $player);
    }

    #[Test]
    public function a_player_without_registration_is_judged_on_their_own_status(): void
    {
        $player = $this->noRegistration();
        $player->update(['status_id' => $this->retired->id]);

        $this->assertApplies(false, $this->sub([], [$this->registered]), $player->fresh());
        $this->assertApplies(true, $this->sub([], [$this->retired]), $player->fresh());
    }

    #[Test]
    public function bulk_and_single_assign_follow_the_ticked_statuses(): void
    {
        $retired = $this->setStatus($this->bothActive('Retired'), $this->foot, $this->retired);
        $active = $this->setStatus($this->bothActive('Active'), $this->foot, $this->registered);
        $sub = $this->sub([$this->foot], [$this->registered]);

        $this->actingAs($this->admin())->post(route('subscriptions.assign', $sub), ['assign_all' => true]);
        $this->assertSame([$active->id], PlayerSubscription::where('subscription_id', $sub->id)->pluck('player_id')->all());

        $this->post(route('subscriptions.assign', $sub), ['player_ids' => [$retired->id]]);
        $this->post(route('subscriptions.assignOne', $sub), ['player_id' => $retired->id, 'outside_category' => true])
            ->assertSessionHas('error', 'flash.player_not_eligible_for_subscription');
        $this->assertSame([$active->id], PlayerSubscription::where('subscription_id', $sub->id)->pluck('player_id')->all());
    }

    #[Test]
    public function the_form_saves_the_ticked_statuses_and_all_ticked_means_none_stored(): void
    {
        $left = PlayerStatus::where('code', 'left')->first();
        $payload = [
            'kind' => 'annual', 'name' => 'Season', 'year' => (int) now()->year,
            'amount_student' => 100, 'amount_worker' => 100,
        ];

        $this->actingAs($this->admin())->post(route('subscriptions.store'), [...$payload, 'status_ids' => [$this->registered->id]])
            ->assertSessionHasNoErrors();
        $sub = Subscription::latest('id')->first();
        $this->assertSame([$this->registered->id], $sub->statuses()->pluck('player_statuses.id')->all());

        $allButLeft = PlayerStatus::where('id', '!=', $left?->id)->pluck('id')->all();
        $this->put(route('subscriptions.update', $sub), [...$payload, 'status_ids' => $allButLeft])->assertSessionHasNoErrors();
        $this->assertSame(0, $sub->statuses()->count());

        $this->put(route('subscriptions.update', $sub), [...$payload, 'status_ids' => []])->assertSessionHasErrors('status_ids');
        if ($left) {
            $this->put(route('subscriptions.update', $sub), [...$payload, 'status_ids' => [$left->id]])->assertSessionHasErrors('status_ids.0');
        }
    }

    #[Test]
    public function the_page_flags_assigned_players_whose_status_is_not_covered(): void
    {
        $retired = $this->setStatus($this->bothActive('Retired'), $this->foot, $this->registered);
        $sub = $this->sub([$this->foot]);
        $sub->assignTo($retired);
        $sub->statuses()->attach($this->registered->id);
        $this->setStatus($retired, $this->foot, $this->retired);

        $this->actingAs($this->admin())->get(route('subscriptions.show', $sub))
            ->assertInertia(fn (Assert $page) => $page
                ->where('playerSubscriptions.0.status_not_covered', true)
                ->where('subscription.statuses.0.id', $this->registered->id));
    }
}
