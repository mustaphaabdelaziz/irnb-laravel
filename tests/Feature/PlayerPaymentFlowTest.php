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

class PlayerPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function makePlayer(): Player
    {
        return Player::create([
            'membership_id' => '80'.str_pad((string) ++$this->seq, 8, '0', STR_PAD_LEFT),
            'firstname' => 'Flow', 'lastname' => 'Test',
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
    }

    private function makeSub(Player $player, float $owed = 2000): PlayerSubscription
    {
        $sub = PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => null, 'transaction_id' => null,
            'year' => (int) now()->year, 'status_at_time' => 'student',
            'is_mandatory' => true, 'amount_owed' => $owed, 'amount_paid' => 0,
        ]);
        app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());

        return $sub;
    }

    #[Test]
    public function exempt_checkbox_waives_the_subscription_without_a_payment(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'player_subscription_id' => $sub->id,
                'category' => 'subscription',
                'is_exempt' => true,
            ])
            ->assertRedirect();

        $this->assertTrue((bool) $sub->fresh()->is_exempt);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function unchecking_exempt_restores_the_debt(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player);
        $sub->update(['is_exempt' => true]);
        app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'player_subscription_id' => $sub->id,
                'category' => 'subscription',
                'is_exempt' => false,
            ])
            ->assertRedirect();

        $this->assertFalse((bool) $sub->fresh()->is_exempt);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function overpayment_splits_the_excess_into_a_donation(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'player_subscription_id' => $sub->id,
                'category' => 'subscription',
                'amount' => 2500,
                'payment_method' => 'cash',
            ])
            ->assertRedirect();

        $subTx = Transaction::where('category', 'subscription')->firstOrFail();
        $donationTx = Transaction::where('category', 'donation')->firstOrFail();

        $this->assertSame(2000.0, (float) $subTx->amount);
        $this->assertSame($sub->id, $subTx->player_subscription_id);
        $this->assertSame('Paid', $subTx->status);
        $this->assertSame(500.0, (float) $donationTx->amount);
        $this->assertNull($donationTx->player_subscription_id);

        $this->assertSame(2000.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function underpayment_leaves_the_remainder_as_debt(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'player_subscription_id' => $sub->id,
                'category' => 'subscription',
                'amount' => 500,
                'payment_method' => 'cash',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame('Partial', Transaction::firstOrFail()->status);
        $this->assertSame(500.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(1500.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function editing_a_payment_archives_the_original_and_records_a_new_one(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'player_subscription_id' => $sub->id,
                'category' => 'subscription',
                'amount' => 800,
                'payment_method' => 'cash',
            ])->assertRedirect();

        $original = Transaction::firstOrFail();
        $this->assertSame(800.0, (float) $sub->fresh()->amount_paid);

        $this->actingAs($this->admin())
            ->put(route('players.transactions.update', [$player, $original]), [
                'amount' => 500,
                'payment_method' => 'cash',
            ])
            ->assertRedirect();

        $this->assertTrue((bool) $original->fresh()->archived);
        $active = Transaction::where('archived', false)->get();
        $this->assertCount(1, $active);
        $this->assertSame(500.0, (float) $active->first()->amount);
        $this->assertSame(500.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(1500.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function removing_a_payment_archives_it_and_restores_debt(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'player_subscription_id' => $sub->id,
                'category' => 'subscription',
                'amount' => 800,
                'payment_method' => 'cash',
            ])->assertRedirect();

        $tx = Transaction::firstOrFail();
        $this->assertSame(800.0, (float) $sub->fresh()->amount_paid);

        $this->actingAs($this->admin())
            ->delete(route('players.transactions.destroy', [$player, $tx]))
            ->assertRedirect();

        $this->assertTrue((bool) $tx->fresh()->archived);
        $this->assertSame(0.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function a_player_cannot_edit_another_players_transaction(): void
    {
        $player = $this->makePlayer();
        $other = $this->makePlayer();
        $sub = $this->makeSub($other, 2000);

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $other), [
                'player_subscription_id' => $sub->id,
                'category' => 'subscription',
                'amount' => 800,
            ])->assertRedirect();

        $tx = Transaction::firstOrFail();

        $this->actingAs($this->admin())
            ->put(route('players.transactions.update', [$player, $tx]), ['amount' => 100])
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->delete(route('players.transactions.destroy', [$player, $tx]))
            ->assertForbidden();
    }
}
