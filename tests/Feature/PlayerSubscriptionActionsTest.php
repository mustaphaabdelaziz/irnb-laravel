<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerSubscriptionActionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'email_verified_at' => now()]);
    }

    private function player(): Player
    {
        return Player::create([
            'firstname' => 'Test',
            'membership_id' => (string) random_int(202400001, 202499999),
            'join_year' => 2024,
            'is_student' => true,
        ]);
    }

    private function line(Player $player, array $attrs = []): PlayerSubscription
    {
        $sub = Subscription::create([
            'name' => 'Annual', 'year' => 2024, 'amount_student' => 2000, 'amount_worker' => 3000,
            'is_mandatory' => true, 'is_active' => true,
        ]);

        return PlayerSubscription::create(array_merge([
            'player_id' => $player->id,
            'subscription_id' => $sub->id,
            'year' => 2024,
            'status_at_time' => 'student',
            'is_mandatory' => true,
            'amount_owed' => 2000,
            'amount_paid' => 0,
        ], $attrs));
    }

    #[Test]
    public function it_updates_the_obligation_and_recalculates_debt(): void
    {
        $player = $this->player();
        $line = $this->line($player);

        $this->actingAs($this->admin())
            ->put(route('players.subscriptions.update', [$player, $line]), [
                'amount_owed' => 1500,
                'is_exempt' => false,
                'due_date' => '2024-12-31',
            ])
            ->assertRedirect();

        $line->refresh();
        $this->assertSame('1500.00', $line->amount_owed);
        $this->assertSame('2024-12-31', $line->due_date->toDateString());
        $this->assertSame('1500.00', (string) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function exempting_a_line_zeroes_its_contribution_to_debt(): void
    {
        $player = $this->player();
        $line = $this->line($player);

        $this->actingAs($this->admin())
            ->put(route('players.subscriptions.update', [$player, $line]), [
                'amount_owed' => 2000,
                'is_exempt' => true,
            ])
            ->assertRedirect();

        $this->assertTrue($line->fresh()->is_exempt);
        $this->assertSame('0.00', (string) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function it_deletes_a_line_without_payments(): void
    {
        $player = $this->player();
        $line = $this->line($player);

        $this->actingAs($this->admin())
            ->delete(route('players.subscriptions.destroy', [$player, $line]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('player_subscriptions', ['id' => $line->id]);
    }

    #[Test]
    public function it_refuses_to_delete_a_line_with_payments(): void
    {
        $player = $this->player();
        $line = $this->line($player);
        Transaction::create([
            'amount' => 500, 'transaction_date' => '2024-06-01', 'transaction_type' => 'income',
            'category' => 'subscription', 'status' => 'Partial', 'fiscal_year' => 2024,
            'player_subscription_id' => $line->id, 'archived' => false,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('players.subscriptions.destroy', [$player, $line]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('player_subscriptions', ['id' => $line->id]);
    }

    #[Test]
    public function it_404s_when_the_line_belongs_to_another_player(): void
    {
        $player = $this->player();
        $other = $this->player();
        $line = $this->line($other);

        $this->actingAs($this->admin())
            ->put(route('players.subscriptions.update', [$player, $line]), ['amount_owed' => 10])
            ->assertNotFound();
    }
}
