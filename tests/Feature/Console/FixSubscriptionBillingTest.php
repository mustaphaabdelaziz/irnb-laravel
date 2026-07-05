<?php

namespace Tests\Feature\Console;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FixSubscriptionBillingTest extends TestCase
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

    private function seedLegacy(): array
    {
        $sub = Subscription::create([
            'name' => 'Annual', 'year' => 2025,
            'amount_student' => 2000, 'amount_worker' => 3000,
            'is_mandatory' => true, 'is_active' => true,
        ]);
        $player = $this->makePlayer();

        // a legacy bill (to be deleted) + a real payment (to be kept + linked)
        $bill = Transaction::create([
            'amount' => 2000, 'transaction_date' => now(), 'transaction_type' => 'income',
            'category' => 'subscription', 'status' => 'Unpaid', 'payment_account' => '/',
            'related_entity_type' => 'Player', 'related_entity_id' => $player->id, 'fiscal_year' => 2025,
        ]);
        $payment = Transaction::create([
            'amount' => 1200, 'transaction_date' => now(), 'transaction_type' => 'income',
            'category' => 'subscription', 'status' => 'Partial',
            'related_entity_type' => 'Player', 'related_entity_id' => $player->id, 'fiscal_year' => 2025,
        ]);
        $ps = PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => $sub->id, 'transaction_id' => $payment->id,
            'year' => 2025, 'status_at_time' => 'student', 'is_mandatory' => true,
            'amount_owed' => 2000, 'amount_paid' => 1200,
        ]);

        return compact('player', 'ps', 'bill', 'payment');
    }

    #[Test]
    public function dry_run_reports_but_keeps_everything(): void
    {
        $this->seedLegacy();

        $this->artisan('finance:fix-subscription-billing')->assertSuccessful();

        // dry-run: bill still present
        $this->assertDatabaseHas('transactions', ['status' => 'Unpaid', 'category' => 'subscription']);
    }

    #[Test]
    public function force_deletes_bills_links_payments_and_recomputes(): void
    {
        ['player' => $player, 'ps' => $ps, 'payment' => $payment] = $this->seedLegacy();

        $this->artisan('finance:fix-subscription-billing --force')->assertSuccessful();

        // bill gone, payment kept + linked
        $this->assertDatabaseMissing('transactions', ['status' => 'Unpaid', 'category' => 'subscription']);
        $this->assertSame($ps->id, $payment->fresh()->player_subscription_id);
        // recomputed: paid=1200, debt = 2000-1200 = 800
        $this->assertSame(1200.0, (float) $ps->fresh()->amount_paid);
        $this->assertSame(800.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function an_unresolved_payment_is_left_unlinked(): void
    {
        $player = $this->makePlayer();
        // real payment, but NO subscription exists for this player/year
        $payment = Transaction::create([
            'amount' => 500, 'transaction_date' => now(), 'transaction_type' => 'income',
            'category' => 'subscription', 'status' => 'Partial',
            'related_entity_type' => 'Player', 'related_entity_id' => $player->id, 'fiscal_year' => 2025,
        ]);

        $this->artisan('finance:fix-subscription-billing --force')->assertSuccessful();

        $this->assertNull($payment->fresh()->player_subscription_id);
    }

    #[Test]
    public function reconciliation_corrects_a_wrong_stored_amount_paid(): void
    {
        $player = $this->makePlayer();
        $sub = PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => null, 'transaction_id' => null,
            'year' => 2025, 'status_at_time' => 'student', 'is_mandatory' => true,
            'amount_owed' => 2000, 'amount_paid' => 999, // deliberately wrong stored value
        ]);
        // a real payment of 1200 for the same player/year, not yet linked
        Transaction::create([
            'amount' => 1200, 'transaction_date' => now(), 'transaction_type' => 'income',
            'category' => 'subscription', 'status' => 'Partial',
            'related_entity_type' => 'Player', 'related_entity_id' => $player->id, 'fiscal_year' => 2025,
        ]);

        $this->artisan('finance:fix-subscription-billing --force')->assertSuccessful();

        $this->assertSame(1200.0, (float) $sub->fresh()->amount_paid); // corrected from 999
        $this->assertSame(800.0, (float) $player->fresh()->outstanding_debt);
    }
}
