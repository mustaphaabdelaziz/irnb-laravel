<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\RecalculatePlayerDebtService;
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
    public function a_debt_payment_is_recorded_player_level_and_does_not_touch_subscription_debt(): void
    {
        $player = $this->makePlayer();
        $sub = PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => null, 'transaction_id' => null,
            'year' => (int) now()->year, 'status_at_time' => 'student',
            'is_mandatory' => true, 'amount_owed' => 2000, 'amount_paid' => 0,
        ]);
        app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'amount' => 700,
                'category' => 'debt_payment',
                'payment_method' => 'cash',
            ])
            ->assertRedirect();

        $tx = Transaction::firstOrFail();
        $this->assertSame('debt_payment', $tx->category);
        $this->assertNull($tx->player_subscription_id);
        // Legacy debt payment is flat income; the subscription balance is untouched.
        $this->assertSame(0.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
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
            ->assertSessionHasErrors('player_subscription_id');

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
