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

class SubscriptionDiscountTest extends TestCase
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

    private function makeObligation(Player $player, array $overrides = []): PlayerSubscription
    {
        return PlayerSubscription::create(array_merge([
            'player_id' => $player->id,
            'subscription_id' => null,
            'label' => 'Annual',
            'year' => 2026,
            'status_at_time' => 'student',
            'is_mandatory' => true,
            'amount_owed' => 2000,
            'amount_paid' => 0,
        ], $overrides));
    }

    private function recalc(Player $player): void
    {
        app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());
    }

    #[Test]
    public function a_percent_discount_reduces_what_is_owed_without_touching_the_price(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeObligation($player, ['discount_type' => 'percent', 'discount_value' => 20]);

        $this->recalc($player);
        $sub->refresh();

        $this->assertSame(2000.0, (float) $sub->amount_owed, 'original price must survive');
        $this->assertSame(400.0, (float) $sub->discount_amount);
        $this->assertSame(1600.0, (float) $sub->net_owed);
        $this->assertSame(1600.0, (float) $sub->remaining_amount);
        $this->assertSame(1600.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function a_fixed_amount_discount_reduces_what_is_owed(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeObligation($player, ['discount_type' => 'amount', 'discount_value' => 500]);

        $this->recalc($player);
        $sub->refresh();

        $this->assertSame(2000.0, (float) $sub->amount_owed);
        $this->assertSame(500.0, (float) $sub->discount_amount);
        $this->assertSame(1500.0, (float) $sub->remaining_amount);
        $this->assertSame(1500.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function a_discount_larger_than_the_price_is_clamped_and_never_goes_negative(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeObligation($player, ['discount_type' => 'amount', 'discount_value' => 5000]);

        $this->recalc($player);
        $sub->refresh();

        $this->assertSame(2000.0, (float) $sub->discount_amount, 'clamped to the price');
        $this->assertSame(0.0, (float) $sub->net_owed);
        $this->assertSame(0.0, (float) $sub->remaining_amount);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function a_full_discount_reads_as_paid_with_no_payment_recorded(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeObligation($player, ['discount_type' => 'percent', 'discount_value' => 100]);

        $this->recalc($player);
        $sub->refresh();

        $this->assertSame(0.0, (float) $sub->remaining_amount);
        $this->assertSame('paid', $sub->payment_status);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);
        $this->assertDatabaseCount('transactions', 0);
    }

    #[Test]
    public function exempt_overrides_any_discount(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeObligation($player, [
            'is_exempt' => true,
            'discount_type' => 'percent',
            'discount_value' => 20,
        ]);

        $this->recalc($player);

        $this->assertSame('exempt', $sub->fresh()->payment_status);
        $this->assertSame(0.0, (float) $sub->fresh()->remaining_amount);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function without_a_discount_nothing_changes(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeObligation($player);

        $this->recalc($player);
        $sub->refresh();

        $this->assertSame(0.0, (float) $sub->discount_amount);
        $this->assertSame(2000.0, (float) $sub->net_owed);
        $this->assertSame(2000.0, (float) $sub->remaining_amount);
        $this->assertSame('unpaid', $sub->payment_status);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function paying_the_net_amount_settles_a_discounted_subscription(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeObligation($player, ['discount_type' => 'percent', 'discount_value' => 20]);

        $this->actingAs($this->admin())->post(route('players.transactions.store', $player), [
            'player_subscription_id' => $sub->id,
            'amount' => 1600,
            'category' => 'subscription',
            'payment_method' => 'cash',
        ])->assertRedirect();

        $this->assertSame(1600.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame('paid', $sub->fresh()->payment_status);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);
        // No donation split: 1600 is exactly the net.
        $this->assertSame(0, Transaction::where('category', 'donation')->count());
    }

    #[Test]
    public function paying_more_than_the_net_splits_the_excess_into_a_donation(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeObligation($player, ['discount_type' => 'percent', 'discount_value' => 20]);

        // Net is 1600; pay the full undiscounted 2000.
        $this->actingAs($this->admin())->post(route('players.transactions.store', $player), [
            'player_subscription_id' => $sub->id,
            'amount' => 2000,
            'category' => 'subscription',
            'payment_method' => 'cash',
        ])->assertRedirect();

        $this->assertSame(1600.0, (float) $sub->fresh()->amount_paid, 'only the net lands on the sub');
        $this->assertSame('paid', $sub->fresh()->payment_status);
        $this->assertSame(400.0, (float) Transaction::where('category', 'donation')->sum('amount'));
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function a_discount_can_be_set_through_the_edit_route(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeObligation($player);

        $this->actingAs($this->admin())
            ->put(route('players.subscriptions.update', [$player, $sub]), [
                'amount_owed' => 2000,
                'discount_type' => 'percent',
                'discount_value' => 25,
            ])->assertRedirect();

        $this->assertSame('percent', $sub->fresh()->discount_type);
        $this->assertSame(500.0, (float) $sub->fresh()->discount_amount);
        $this->assertSame(1500.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function a_percent_discount_over_100_is_rejected(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeObligation($player);

        $this->actingAs($this->admin())
            ->put(route('players.subscriptions.update', [$player, $sub]), [
                'amount_owed' => 2000,
                'discount_type' => 'percent',
                'discount_value' => 150,
            ])->assertSessionHasErrors('discount_value');

        $this->assertNull($sub->fresh()->discount_type);
    }

    #[Test]
    public function clearing_the_discount_type_clears_the_value(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeObligation($player, ['discount_type' => 'percent', 'discount_value' => 20]);

        $this->actingAs($this->admin())
            ->put(route('players.subscriptions.update', [$player, $sub]), [
                'amount_owed' => 2000,
                'discount_type' => '',
            ])->assertRedirect();

        $sub->refresh();
        $this->assertNull($sub->discount_type);
        $this->assertNull($sub->discount_value);
        $this->assertSame(2000.0, (float) $sub->remaining_amount);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }
}
