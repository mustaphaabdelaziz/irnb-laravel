<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManualDebtsPageTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
        ]);
    }

    private function makePlayer(string $first = 'P', string $last = 'Test'): Player
    {
        return Player::create([
            'membership_id' => '9'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => $first,
            'lastname' => $last,
            'is_student' => true,
            'outstanding_debt' => 0,
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

    /** A manual debt when $sub is null, otherwise an obligation of that subscription. */
    private function debt(Player $player, ?Subscription $sub, float $owed, float $paid, array $extra = []): PlayerSubscription
    {
        return PlayerSubscription::create(array_merge([
            'player_id' => $player->id,
            'subscription_id' => $sub?->id,
            'label' => $sub ? null : 'Old debt',
            'year' => 2026,
            'status_at_time' => 'student',
            'is_mandatory' => true,
            'is_legacy' => $sub === null,
            'amount_owed' => $owed,
            'amount_paid' => $paid,
        ], $extra));
    }

    #[Test]
    public function the_scope_keeps_manual_debts_and_branchless_subscriptions_only(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $manual = $this->debt($this->makePlayer(), null, 1500, 0);
        $branchless = $this->debt($this->makePlayer(), $this->makeSubscription('Loose'), 2000, 0);
        $this->debt($this->makePlayer(), $this->makeSubscription('Season', [$branch->id]), 2000, 0);

        $this->assertEqualsCanonicalizing(
            [$manual->id, $branchless->id],
            PlayerSubscription::query()->withoutBranch()->pluck('id')->all()
        );
    }
}
