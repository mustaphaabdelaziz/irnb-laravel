<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionBranchTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function makeSubscription(string $name, array $branchIds = []): Subscription
    {
        $sub = Subscription::create([
            'name' => $name,
            'year' => 2026,
            'amount_student' => 2000,
            'amount_worker' => 3000,
            'is_mandatory' => true,
            'is_active' => true,
        ]);
        if ($branchIds) {
            $sub->branches()->attach($branchIds);
        }

        return $sub;
    }

    private function makePlayer(): Player
    {
        return Player::create([
            'membership_id' => '9'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => 'P'.$this->seq,
            'lastname' => 'Test',
            'is_student' => true,
            'outstanding_debt' => 0,
        ]);
    }

    private function obligation(Player $player, Subscription $sub, float $owed, float $paid): PlayerSubscription
    {
        return PlayerSubscription::create([
            'player_id' => $player->id,
            'subscription_id' => $sub->id,
            'year' => 2026,
            'status_at_time' => 'student',
            'is_mandatory' => true,
            'amount_owed' => $owed,
            'amount_paid' => $paid,
        ]);
    }

    #[Test]
    public function creating_a_subscription_with_branches_writes_the_pivot(): void
    {
        $football = Branch::create(['name' => 'Football']);
        $swim = Branch::create(['name' => 'Swim']);

        $this->actingAs($this->admin())
            ->post(route('subscriptions.store'), [
                'name' => 'Season',
                'year' => 2026,
                'amount_student' => 2000,
                'amount_worker' => 3000,
                'is_mandatory' => true,
                'branch_ids' => [$football->id, $swim->id],
            ])->assertRedirect();

        $sub = Subscription::firstOrFail();
        $this->assertEqualsCanonicalizing(
            [$football->id, $swim->id],
            $sub->branches->pluck('id')->all()
        );
    }

    #[Test]
    public function the_branch_filter_returns_only_that_branchs_subscriptions(): void
    {
        $football = Branch::create(['name' => 'Football']);
        $swim = Branch::create(['name' => 'Swim']);
        $this->makeSubscription('Football Season', [$football->id]);
        $this->makeSubscription('Swim Season', [$swim->id]);

        $this->actingAs($this->admin())
            ->get(route('subscriptions.index', ['branch_id' => $football->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Subscriptions/Index')
                ->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.name', 'Football Season'));
    }

    #[Test]
    public function per_branch_stats_count_a_shared_subscription_in_both_branches(): void
    {
        $football = Branch::create(['name' => 'Football']);
        $swim = Branch::create(['name' => 'Swim']);

        // Shared subscription in BOTH branches.
        $shared = $this->makeSubscription('Shared', [$football->id, $swim->id]);
        $this->obligation($this->makePlayer(), $shared, owed: 2000, paid: 500);

        // Football-only subscription, fully paid.
        $footballOnly = $this->makeSubscription('Football Only', [$football->id]);
        $this->obligation($this->makePlayer(), $footballOnly, owed: 1000, paid: 1000);

        $this->actingAs($this->admin())
            ->get(route('subscriptions.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Ordered by branch name: Football, then Swim. JSON serializes whole
                // amounts as ints, so assert against ints.
                ->where('branchStats.0.name', 'Football')
                ->where('branchStats.0.owed', 3000)
                ->where('branchStats.0.collected', 1500)
                ->where('branchStats.0.outstanding', 1500)
                ->where('branchStats.0.players', 2)
                ->where('branchStats.1.name', 'Swim')
                ->where('branchStats.1.owed', 2000)
                ->where('branchStats.1.collected', 500)
                ->where('branchStats.1.outstanding', 1500)
                ->where('branchStats.1.players', 1));
    }

    #[Test]
    public function untagged_obligations_land_in_the_no_branch_bucket(): void
    {
        $football = Branch::create(['name' => 'Football']);
        $tagged = $this->makeSubscription('Tagged', [$football->id]);
        $this->obligation($this->makePlayer(), $tagged, owed: 1000, paid: 0);

        // Subscription with no branch.
        $untagged = $this->makeSubscription('Untagged', []);
        $this->obligation($this->makePlayer(), $untagged, owed: 800, paid: 300);

        // Manual/previous debt: no subscription at all.
        PlayerSubscription::create([
            'player_id' => $this->makePlayer()->id,
            'subscription_id' => null,
            'label' => 'Old dues',
            'year' => 2026,
            'status_at_time' => 'student',
            'is_mandatory' => true,
            'is_legacy' => true,
            'amount_owed' => 500,
            'amount_paid' => 0,
        ]);

        $this->actingAs($this->admin())
            ->get(route('subscriptions.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $stats = collect($page->toArray()['props']['branchStats']);
                $noBranch = $stats->firstWhere('branch_id', null);
                $this->assertNotNull($noBranch, 'No-branch bucket missing');
                // owed 800+500=1300, collected 300, outstanding (800-300)+(500-0)=1000, 2 players.
                $this->assertEquals(1300, $noBranch['owed']);
                $this->assertEquals(300, $noBranch['collected']);
                $this->assertEquals(1000, $noBranch['outstanding']);
                $this->assertSame(2, $noBranch['players']);
            });
    }
}
