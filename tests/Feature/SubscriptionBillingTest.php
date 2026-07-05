<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\RecalculatePlayerDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function makePlayer(float $debt = 0): Player
    {
        return Player::create([
            'membership_id' => '9'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => 'P'.$this->seq,
            'lastname' => 'Test',
            'is_student' => true,
            'outstanding_debt' => $debt,
        ]);
    }

    private function makeSub(Player $player, float $owed, bool $mandatory = true): PlayerSubscription
    {
        return PlayerSubscription::create([
            'player_id' => $player->id,
            'subscription_id' => null,
            'transaction_id' => null,
            'year' => (int) now()->year,
            'status_at_time' => 'student',
            'is_mandatory' => $mandatory,
            'amount_owed' => $owed,
            'amount_paid' => 0,
        ]);
    }

    private function pay(PlayerSubscription $sub, float $amount, string $category = 'subscription'): Transaction
    {
        return Transaction::create([
            'amount' => $amount,
            'transaction_date' => now(),
            'transaction_type' => 'income',
            'category' => $category,
            'status' => 'Paid',
            'related_entity_type' => 'Player',
            'related_entity_id' => $sub->player_id,
            'player_subscription_id' => $sub->id,
            'fiscal_year' => $sub->year,
        ]);
    }

    #[Test]
    public function amount_paid_is_the_sum_of_linked_payments(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);

        $this->pay($sub, 500);
        $this->pay($sub, 300);

        app(RecalculatePlayerDebtService::class)->forSubscription($sub->fresh());

        $this->assertSame(800.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(1200.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function optional_subscriptions_are_excluded_from_debt(): void
    {
        $player = $this->makePlayer();
        $mandatory = $this->makeSub($player, 2000, true);
        $optional = $this->makeSub($player, 1500, false);

        app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());

        // only the mandatory 2000 counts
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function a_donation_payment_exempts_the_subscription(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);
        $this->pay($sub, 100, 'donation');

        app(RecalculatePlayerDebtService::class)->forSubscription($sub->fresh());

        $this->assertTrue($sub->fresh()->isExempt());
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function editing_a_payment_amount_resyncs_debt(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);
        $payment = $this->pay($sub, 1000);

        $this->assertSame(1000.0, (float) $player->fresh()->outstanding_debt);

        $payment->update(['amount' => 500]);

        $this->assertSame(500.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(1500.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function archiving_a_payment_raises_debt_again(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);
        $payment = $this->pay($sub, 2000);

        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);

        $payment->update(['archived' => true]);

        $this->assertSame(0.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function deleting_a_payment_lowers_amount_paid_and_raises_debt(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);
        $payment = $this->pay($sub, 1200);

        $this->assertSame(800.0, (float) $player->fresh()->outstanding_debt);

        $payment->delete();

        $this->assertSame(0.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function assigning_optional_subscription_does_not_add_debt_or_income(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
        $player = $this->makePlayer();
        $optional = Subscription::create([
            'name' => 'Camp', 'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('subscriptions.assignOne', $optional), ['player_id' => $player->id])
            ->assertRedirect();

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('player_subscriptions', 1);
        $this->assertFalse((bool) PlayerSubscription::first()->is_mandatory);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function assigning_mandatory_subscription_adds_debt_without_income(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
        $player = $this->makePlayer();
        $mandatory = Subscription::create([
            'name' => 'Annual', 'year' => (int) now()->year,
            'amount_student' => 2000, 'amount_worker' => 3000,
            'is_mandatory' => true, 'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('subscriptions.assignOne', $mandatory), ['player_id' => $player->id])
            ->assertRedirect();

        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }
}
