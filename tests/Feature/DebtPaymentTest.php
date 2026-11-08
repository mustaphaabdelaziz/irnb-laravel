<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\RecalculatePlayerDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «دفع دين» (category debt_payment): the amount settles the player's unpaid
 * obligations oldest season first; anything beyond the total debt is a donation.
 */
class DebtPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function makePlayer(): Player
    {
        return Player::create([
            'membership_id' => '900000019', 'firstname' => 'Debt', 'lastname' => 'Payer',
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
    }

    private function owe(Player $player, int $year, float $amount, array $extra = []): PlayerSubscription
    {
        $sub = PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => null, 'transaction_id' => null,
            'label' => "Debt {$year}", 'year' => $year, 'status_at_time' => 'student',
            'is_mandatory' => true, 'amount_owed' => $amount, 'amount_paid' => 0,
        ] + $extra);
        app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());

        return $sub;
    }

    private function payDebt(Player $player, float $amount): TestResponse
    {
        return $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'amount' => $amount,
                'category' => 'debt_payment',
                'payment_method' => 'cash',
            ]);
    }

    #[Test]
    public function a_debt_payment_settles_the_oldest_obligation_first(): void
    {
        $player = $this->makePlayer();
        // Created newest first, so only the season order can put 2024 first.
        $newer = $this->owe($player, 2025, 3000);
        $older = $this->owe($player, 2024, 2000);

        $this->payDebt($player, 2500)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2000.0, (float) $older->fresh()->amount_paid);
        $this->assertSame(500.0, (float) $newer->fresh()->amount_paid);
        $this->assertSame(2500.0, (float) $player->fresh()->outstanding_debt);

        $txs = Transaction::orderBy('id')->get();
        $this->assertCount(2, $txs);
        $this->assertSame([$older->id, $newer->id], $txs->pluck('player_subscription_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['subscription', 'subscription'], $txs->pluck('category')->all());
        // One payment, one receipt.
        $this->assertNotNull($txs[0]->payment_group);
        $this->assertSame($txs[0]->payment_group, $txs[1]->payment_group);
    }

    #[Test]
    public function an_amount_above_the_total_debt_becomes_a_donation_in_the_same_receipt(): void
    {
        $player = $this->makePlayer();
        $sub = $this->owe($player, 2024, 2000);

        $this->payDebt($player, 2600)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2000.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);

        $donation = Transaction::where('category', 'donation')->firstOrFail();
        $this->assertSame(600.0, (float) $donation->amount);
        $this->assertNull($donation->player_subscription_id);
        $this->assertSame(Transaction::where('category', 'subscription')->value('payment_group'), $donation->payment_group);
    }

    #[Test]
    public function exempt_and_settled_obligations_are_skipped(): void
    {
        $player = $this->makePlayer();
        $this->owe($player, 2023, 1000, ['is_exempt' => true]);
        $this->owe($player, 2023, 800, ['discount_type' => 'percent', 'discount_value' => 100]);
        $due = $this->owe($player, 2024, 2000);

        $this->payDebt($player, 500)->assertRedirect()->assertSessionHasNoErrors();

        $tx = Transaction::sole();
        $this->assertSame($due->id, (int) $tx->player_subscription_id);
        $this->assertSame(1500.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function a_debt_payment_is_refused_when_the_player_owes_nothing(): void
    {
        $player = $this->makePlayer();

        $this->payDebt($player, 500)->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('transactions', 0);
    }

    #[Test]
    public function editing_an_old_unlinked_debt_payment_settles_debt(): void
    {
        $player = $this->makePlayer();
        $sub = $this->owe($player, 2024, 2000);
        $legacy = Transaction::create([
            'amount' => 700, 'transaction_date' => '2025-03-10', 'transaction_type' => 'income',
            'category' => 'debt_payment', 'payment_method' => 'cash',
            'related_entity_type' => 'Player', 'related_entity_id' => $player->id,
            'player_subscription_id' => null, 'status' => 'Paid',
        ]);

        $this->actingAs($this->admin())
            ->put(route('players.transactions.update', [$player, $legacy]), [
                'amount' => 900, 'payment_method' => 'cash',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue((bool) $legacy->fresh()->archived);
        $this->assertSame(900.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(1100.0, (float) $player->fresh()->outstanding_debt);
        $this->assertSame('2025-03-10', Transaction::where('archived', false)->sole()->transaction_date->format('Y-m-d'));
    }
}
