<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reproduction: deleting a payment from the player profile should put the
 * obligation back to unpaid AND put the amount back into the player's debt.
 */
class DeleteTransactionDebtTest extends TestCase
{
    use RefreshDatabase;

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
            'membership_id' => '9000000001',
            'firstname' => 'Ali',
            'lastname' => 'Test',
            'is_student' => true,
            'outstanding_debt' => 0,
        ]);
    }

    #[Test]
    public function deleting_a_payment_via_the_route_restores_the_debt(): void
    {
        $admin = $this->admin();
        $player = $this->makePlayer();

        $sub = PlayerSubscription::create([
            'player_id' => $player->id,
            'subscription_id' => null,
            'year' => 2026,
            'status_at_time' => 'student',
            'is_mandatory' => true,
            'amount_owed' => 2000,
            'amount_paid' => 0,
        ]);

        // Pay it off through the UI route.
        $this->actingAs($admin)->post(route('players.transactions.store', $player), [
            'player_subscription_id' => $sub->id,
            'amount' => 2000,
            'category' => 'subscription',
            'payment_method' => 'cash',
        ])->assertRedirect();

        $this->assertSame(2000.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);

        // Now delete that payment through the UI route.
        $payment = Transaction::where('player_subscription_id', $sub->id)->firstOrFail();
        $this->actingAs($admin)
            ->delete(route('players.transactions.destroy', [$player, $payment]))
            ->assertRedirect();

        // Obligation should be unpaid again...
        $this->assertSame(0.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame('unpaid', $sub->fresh()->payment_status);

        // ...and the money should be back in the debt.
        $this->assertSame(2000.0, (float) $sub->fresh()->remaining_amount);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);

        // And the profile page must show it.
        $this->actingAs($admin)->get(route('players.show', $player))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('totalDebt', 2000));
    }

    #[Test]
    public function deleting_a_payment_made_against_a_catalog_subscription_restores_the_debt(): void
    {
        $admin = $this->admin();
        $player = $this->makePlayer();

        $catalog = Subscription::create([
            'name' => 'Annual',
            'year' => 2026,
            'amount_student' => 2000,
            'amount_worker' => 3000,
            'is_mandatory' => true,
            'is_active' => true,
        ]);

        // Pay via catalog subscription_id (obligation created on demand) — the
        // path the Add Payment modal actually uses.
        $this->actingAs($admin)->post(route('players.transactions.store', $player), [
            'subscription_id' => $catalog->id,
            'amount' => 2000,
            'category' => 'subscription',
            'payment_method' => 'cash',
        ])->assertRedirect();

        $sub = PlayerSubscription::firstOrFail();
        $this->assertSame(2000.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);

        $payment = Transaction::where('player_subscription_id', $sub->id)->firstOrFail();
        $this->actingAs($admin)
            ->delete(route('players.transactions.destroy', [$player, $payment]))
            ->assertRedirect();

        $this->assertSame(0.0, (float) $sub->fresh()->amount_paid);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function deleting_a_payment_on_an_optional_subscription_restores_the_debt(): void
    {
        $admin = $this->admin();
        $player = $this->makePlayer();

        // An OPTIONAL subscription (like إشتراكات الجوارب in the real catalog).
        $sub = PlayerSubscription::create([
            'player_id' => $player->id,
            'subscription_id' => null,
            'year' => 2026,
            'status_at_time' => 'student',
            'is_mandatory' => false,
            'amount_owed' => 2000,
            'amount_paid' => 0,
        ]);

        $this->actingAs($admin)->post(route('players.transactions.store', $player), [
            'player_subscription_id' => $sub->id,
            'amount' => 2000,
            'category' => 'subscription',
            'payment_method' => 'cash',
        ])->assertRedirect();

        $payment = Transaction::where('player_subscription_id', $sub->id)->firstOrFail();
        $this->actingAs($admin)
            ->delete(route('players.transactions.destroy', [$player, $payment]))
            ->assertRedirect();

        $this->assertSame('unpaid', $sub->fresh()->payment_status);
        $this->assertSame(2000.0, (float) $sub->fresh()->remaining_amount);

        // An assigned-but-unpaid obligation is owed, optional or not.
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function an_exempt_obligation_is_still_excluded_from_debt(): void
    {
        $player = $this->makePlayer();

        $sub = PlayerSubscription::create([
            'player_id' => $player->id,
            'subscription_id' => null,
            'year' => 2026,
            'status_at_time' => 'student',
            'is_mandatory' => false,
            'is_exempt' => true,
            'amount_owed' => 2000,
            'amount_paid' => 0,
        ]);

        app(\App\Services\Finance\RecalculatePlayerDebtService::class)->forPlayer($player->fresh());

        $this->assertSame('exempt', $sub->fresh()->payment_status);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);
    }
}