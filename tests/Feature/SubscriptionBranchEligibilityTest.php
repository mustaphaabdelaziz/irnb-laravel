<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** A branch subscription is only for that branch's players (and its categories). */
class SubscriptionBranchEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function makePlayer(?Branch $branch, ?Category $category = null, string $lastname = ''): Player
    {
        $player = Player::create([
            'membership_id' => '82'.str_pad((string) ++$this->seq, 8, '0', STR_PAD_LEFT),
            'firstname' => 'Br', 'lastname' => $lastname ?: 'Player'.$this->seq,
            'is_student' => true, 'outstanding_debt' => 0,
            'category_id' => $category?->id,
        ]);
        if ($branch) {
            $player->branches()->attach($branch->id);
        }

        return $player;
    }

    /** @param  array<int, Branch>  $branches  @param  array<int, Category>  $categories */
    private function catalogSub(string $name, array $branches = [], array $categories = []): Subscription
    {
        $sub = Subscription::create([
            'name' => $name,
            'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $sub->branches()->attach(collect($branches)->pluck('id'));
        $sub->categories()->attach(collect($categories)->pluck('id'));

        return $sub;
    }

    private function assign(Player $player, Subscription $sub, float $paid = 0): PlayerSubscription
    {
        return PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => $sub->id, 'transaction_id' => null,
            'year' => $sub->year, 'status_at_time' => 'student',
            'is_mandatory' => false, 'amount_owed' => 1500, 'amount_paid' => $paid,
        ]);
    }

    #[Test]
    public function the_subscription_page_only_offers_players_of_its_branches_and_categories(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $football = Branch::create(['name' => 'Football']);
        $cadet = Category::create(['name' => 'Cadet']);
        $senior = Category::create(['name' => 'Senior']);
        $sub = $this->catalogSub('Swim season', [$swim], [$cadet]);
        $swimCadet = $this->makePlayer($swim, $cadet);
        $this->makePlayer($swim, $senior);
        $this->makePlayer($football, $cadet);
        $this->makePlayer(null, $cadet);

        $this->actingAs($this->admin())
            ->get(route('subscriptions.show', $sub))
            ->assertOk()
            ->assertInertia(fn ($page) => $this->assertSame(
                [$swimCadet->id],
                collect($page->toArray()['props']['availablePlayers'])->pluck('id')->all(),
            ));
    }

    #[Test]
    public function assign_all_only_assigns_players_of_its_branches(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $football = Branch::create(['name' => 'Football']);
        $sub = $this->catalogSub('Swim season', [$swim]);
        $swimmer = $this->makePlayer($swim);
        $this->makePlayer($football);
        $this->makePlayer(null);

        $this->actingAs($this->admin())
            ->post(route('subscriptions.assign', $sub), ['assign_all' => true])
            ->assertSessionHas('success');

        $this->assertSame([$swimmer->id], PlayerSubscription::where('subscription_id', $sub->id)->pluck('player_id')->all());
    }

    #[Test]
    public function a_subscription_without_branch_stays_open_to_every_player(): void
    {
        $sub = $this->catalogSub('Club card');
        $this->makePlayer(Branch::create(['name' => 'Swim']));
        $this->makePlayer(null);

        $this->actingAs($this->admin())
            ->post(route('subscriptions.assign', $sub), ['assign_all' => true]);

        $this->assertSame(2, PlayerSubscription::where('subscription_id', $sub->id)->count());
    }

    #[Test]
    public function assigning_one_player_outside_the_branch_is_refused(): void
    {
        $sub = $this->catalogSub('Swim season', [Branch::create(['name' => 'Swim'])]);
        $footballer = $this->makePlayer(Branch::create(['name' => 'Football']));

        $this->actingAs($this->admin())
            ->post(route('subscriptions.assignOne', $sub), ['player_id' => $footballer->id])
            ->assertSessionHas('error', 'flash.player_not_eligible_for_subscription');

        $this->assertDatabaseCount('player_subscriptions', 0);
    }

    #[Test]
    public function the_player_profile_only_lists_subscriptions_of_the_players_branches(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $football = Branch::create(['name' => 'Football']);
        $this->catalogSub('Swim season', [$swim]);
        $this->catalogSub('Football season', [$football]);
        $this->catalogSub('Club card');
        $swimmer = $this->makePlayer($swim);

        $this->actingAs($this->admin())
            ->get(route('players.show', $swimmer))
            ->assertOk()
            ->assertInertia(fn ($page) => $this->assertSame(
                ['Club card', 'Swim season'],
                collect($page->toArray()['props']['availableSubscriptions'])->pluck('name')->sort()->values()->all(),
            ));
    }

    #[Test]
    public function paying_a_subscription_of_another_branch_is_rejected(): void
    {
        $sub = $this->catalogSub('Swim season', [Branch::create(['name' => 'Swim'])]);
        $footballer = $this->makePlayer(Branch::create(['name' => 'Football']));

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $footballer), [
                'subscription_id' => $sub->id,
                'category' => 'subscription',
                'amount' => 1500,
                'payment_method' => 'cash',
            ])
            ->assertSessionHasErrors('subscription_id');

        $this->assertDatabaseCount('player_subscriptions', 0);
    }

    #[Test]
    public function changing_the_branches_removes_untouched_obligations_of_players_left_outside(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $football = Branch::create(['name' => 'Football']);
        $sub = $this->catalogSub('Season', [$football]);
        $swimmer = $this->makePlayer($swim);
        $footballer = $this->makePlayer($football);
        $unpaid = $this->assign($swimmer, $sub);
        $kept = $this->assign($footballer, $sub);
        $swimmer->update(['outstanding_debt' => 1500]);

        $partialSwimmer = $this->makePlayer($swim);
        $partial = $this->assign($partialSwimmer, $sub, 500);

        $exemptSwimmer = $this->makePlayer($swim);
        $exempt = $this->assign($exemptSwimmer, $sub);
        $exempt->update(['is_exempt' => true]);

        $this->actingAs($this->admin())
            ->put(route('subscriptions.update', $sub), [
                'kind' => 'annual', 'name' => 'Season', 'year' => $sub->year,
                'amount_student' => 1500, 'amount_worker' => 1500,
                'is_mandatory' => false, 'is_active' => true,
                'branch_ids' => [$football->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($unpaid);
        $this->assertModelExists($kept);
        $this->assertModelExists($partial);
        $this->assertModelExists($exempt);
        $this->assertSame(0.0, (float) $swimmer->fresh()->outstanding_debt);
    }

    #[Test]
    public function the_assigned_list_is_sorted_by_name_and_the_export_can_be_searched(): void
    {
        $sub = $this->catalogSub('Club card');
        foreach (['Zitouni', 'Amrani', 'Mansouri'] as $lastname) {
            $this->assign($this->makePlayer(null, null, $lastname), $sub);
        }

        $this->actingAs($this->admin())
            ->get(route('subscriptions.show', $sub))
            ->assertInertia(fn ($page) => $this->assertSame(
                ['Amrani', 'Mansouri', 'Zitouni'],
                collect($page->toArray()['props']['playerSubscriptions'])->pluck('player.lastname')->all(),
            ));

        $csv = $this->get(route('subscriptions.export', ['subscription' => $sub, 'format' => 'csv', 'search' => 'mansou']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Mansouri', $csv);
        $this->assertStringNotContainsString('Zitouni', $csv);
    }
}
