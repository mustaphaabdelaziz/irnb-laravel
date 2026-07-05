<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\User;
use App\Services\Finance\RecalculatePlayerDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerSubscriptionExemptTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function makePlayer(): Player
    {
        return Player::create([
            'membership_id' => '900000001',
            'firstname' => 'Ex', 'lastname' => 'Empt',
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
    }

    private function makeSub(Player $player): PlayerSubscription
    {
        return PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => null, 'transaction_id' => null,
            'year' => (int) now()->year, 'status_at_time' => 'student',
            'is_mandatory' => true, 'amount_owed' => 2000, 'amount_paid' => 0,
        ]);
    }

    #[Test]
    public function exempt_toggles_the_flag_and_clears_debt(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player);
        app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);

        $this->actingAs($this->admin())
            ->patch(route('players.subscriptions.exempt', [$player, $sub]))
            ->assertRedirect();

        $this->assertTrue((bool) $sub->fresh()->is_exempt);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);

        // Toggling again un-exempts and restores debt.
        $this->actingAs($this->admin())
            ->patch(route('players.subscriptions.exempt', [$player, $sub]))
            ->assertRedirect();

        $this->assertFalse((bool) $sub->fresh()->is_exempt);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function cannot_exempt_a_subscription_of_another_player(): void
    {
        $player = $this->makePlayer();
        $other = Player::create([
            'membership_id' => '900000002', 'firstname' => 'Other', 'lastname' => 'Guy',
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
        $sub = $this->makeSub($other);

        $this->actingAs($this->admin())
            ->patch(route('players.subscriptions.exempt', [$player, $sub]))
            ->assertForbidden();

        $this->assertFalse((bool) $sub->fresh()->is_exempt);
    }
}
