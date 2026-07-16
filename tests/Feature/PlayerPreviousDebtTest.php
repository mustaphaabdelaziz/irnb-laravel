<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerPreviousDebtTest extends TestCase
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

    #[Test]
    public function creating_a_previous_debt_raises_debt_and_starts_unpaid(): void
    {
        $player = $this->makePlayer();

        $this->actingAs($this->admin())
            ->post(route('players.subscriptions.store', $player), [
                'label' => 'Old dues 2023',
                'amount_owed' => 3000,
                'year' => 2023,
            ])
            ->assertRedirect();

        $debt = PlayerSubscription::firstOrFail();
        $this->assertNull($debt->subscription_id);
        $this->assertTrue((bool) $debt->is_legacy);
        $this->assertTrue((bool) $debt->is_mandatory);
        $this->assertSame('Old dues 2023', $debt->label);
        $this->assertSame(2023, $debt->year);
        $this->assertSame(3000.0, (float) $debt->amount_owed);
        $this->assertSame(0.0, (float) $debt->amount_paid);
        $this->assertSame('unpaid', $debt->payment_status);
        $this->assertSame(3000.0, (float) $player->fresh()->outstanding_debt);
        $this->assertDatabaseCount('transactions', 0);
    }

    private function createDebt(Player $player, array $overrides = []): PlayerSubscription
    {
        $this->actingAs($this->admin())
            ->post(route('players.subscriptions.store', $player), array_merge([
                'label' => 'Old dues 2023',
                'amount_owed' => 3000,
                'year' => 2023,
            ], $overrides))
            ->assertRedirect();

        return PlayerSubscription::latest('id')->firstOrFail();
    }

    #[Test]
    public function paying_a_previous_debt_moves_it_unpaid_partial_paid(): void
    {
        $player = $this->makePlayer();
        $debt = $this->createDebt($player);

        // Partial payment.
        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'player_subscription_id' => $debt->id,
                'amount' => 1000,
                'category' => 'subscription',
                'payment_method' => 'cash',
            ])->assertRedirect();

        $this->assertSame(1000.0, (float) $debt->fresh()->amount_paid);
        $this->assertSame('partial', $debt->fresh()->payment_status);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);

        // Real income was recorded for the amount received.
        $this->assertSame(1000.0, (float) Transaction::where('player_subscription_id', $debt->id)->sum('amount'));

        // Pay the rest.
        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'player_subscription_id' => $debt->id,
                'amount' => 2000,
                'category' => 'subscription',
                'payment_method' => 'cash',
            ])->assertRedirect();

        $this->assertSame(3000.0, (float) $debt->fresh()->amount_paid);
        $this->assertSame('paid', $debt->fresh()->payment_status);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function an_exempt_previous_debt_drops_from_debt_but_stays_listed(): void
    {
        $player = $this->makePlayer();
        $debt = $this->createDebt($player, ['is_exempt' => true]);

        $this->assertTrue($debt->isExempt());
        $this->assertSame('exempt', $debt->payment_status);
        $this->assertSame(0.0, (float) $debt->remaining_amount);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);
        // Row is still on record.
        $this->assertDatabaseHas('player_subscriptions', ['id' => $debt->id, 'is_exempt' => true]);
    }

    #[Test]
    public function editing_a_previous_debt_updates_label_year_and_amount(): void
    {
        $player = $this->makePlayer();
        $debt = $this->createDebt($player);

        $this->actingAs($this->admin())
            ->put(route('players.subscriptions.update', [$player, $debt]), [
                'label' => 'Old dues 2022',
                'year' => 2022,
                'amount_owed' => 1500,
            ])->assertRedirect();

        $debt->refresh();
        $this->assertSame('Old dues 2022', $debt->label);
        $this->assertSame(2022, $debt->year);
        $this->assertSame(1500.0, (float) $debt->amount_owed);
        $this->assertSame(1500.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function a_previous_debt_with_a_payment_cannot_be_deleted(): void
    {
        $player = $this->makePlayer();
        $debt = $this->createDebt($player);

        $this->actingAs($this->admin())
            ->post(route('players.transactions.store', $player), [
                'player_subscription_id' => $debt->id,
                'amount' => 500,
                'category' => 'subscription',
                'payment_method' => 'cash',
            ])->assertRedirect();

        $this->actingAs($this->admin())
            ->delete(route('players.subscriptions.destroy', [$player, $debt]))
            ->assertRedirect();

        // Guard keeps the debt while a payment references it.
        $this->assertDatabaseHas('player_subscriptions', ['id' => $debt->id]);
    }
}
