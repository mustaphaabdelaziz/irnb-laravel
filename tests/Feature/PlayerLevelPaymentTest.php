<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerLevelPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function makePlayer(): Player
    {
        return Player::create([
            'membership_id' => '900000009', 'firstname' => 'Pay', 'lastname' => 'Level',
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
    }

    #[Test]
    public function a_donation_is_recorded_player_level_with_no_subscription(): void
    {
        $player = $this->makePlayer();

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'amount' => 500,
                'category' => 'donation',
                'payment_method' => 'cash',
            ])
            ->assertRedirect();

        $tx = Transaction::firstOrFail();
        $this->assertSame('donation', $tx->category);
        $this->assertNull($tx->player_subscription_id);
        $this->assertSame('income', $tx->transaction_type);
        $this->assertSame('Paid', $tx->status);
        $this->assertSame('Player', $tx->related_entity_type);
        $this->assertSame($player->id, (int) $tx->related_entity_id);
        $this->assertSame(500.0, (float) $tx->amount);
    }

    #[Test]
    public function a_subscription_payment_still_requires_a_subscription(): void
    {
        $player = $this->makePlayer();

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'amount' => 700,
                'category' => 'subscription',
                'payment_method' => 'cash',
            ])
            ->assertSessionHasErrors('subscription_id');

        $this->assertDatabaseCount('transactions', 0);
    }

    #[Test]
    public function player_profile_receives_player_level_transactions(): void
    {
        $player = $this->makePlayer();

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'amount' => 500, 'category' => 'donation', 'payment_method' => 'cash',
            ])->assertRedirect();

        $this->actingAs($this->admin())
            ->get(route('players.show', $player))
            ->assertInertia(fn ($page) => $page
                ->component('Players/Show')
                ->has('transactions', 1)
                ->where('transactions.0.category', 'donation'));
    }
}
