<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountApprovalTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function approved_active_users_reach_the_dashboard(): void
    {
        $user = User::factory()->create(); // factory default: approved + active + verified

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    #[Test]
    public function unapproved_users_are_redirected_to_the_pending_page(): void
    {
        $user = User::factory()->create(['approved' => false]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('account.pending'));
    }

    #[Test]
    public function deactivated_users_are_redirected_to_the_pending_page(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('account.pending'));
    }

    #[Test]
    public function the_pending_page_itself_is_reachable_while_pending(): void
    {
        $user = User::factory()->create(['approved' => false]);

        $this->actingAs($user)->get(route('account.pending'))->assertOk();
    }

    #[Test]
    public function a_player_payment_cannot_be_updated_via_a_different_players_path(): void
    {
        $admin = User::factory()->create();

        $playerA = Player::create(['membership_id' => '2024000001', 'firstname' => 'A']);
        $playerB = Player::create(['membership_id' => '2024000002', 'firstname' => 'B']);

        $transaction = Transaction::create([
            'amount' => 1000,
            'transaction_date' => now(),
            'transaction_type' => 'income',
            'category' => 'subscription',
            'status' => 'Paid',
            'related_entity_type' => 'Player',
            'related_entity_id' => $playerA->id,
            'fiscal_year' => now()->year,
        ]);

        // Updating player A's transaction through player B's URL must be forbidden.
        $this->actingAs($admin)
            ->put(route('players.transactions.update', [$playerB->id, $transaction->id]), ['amount' => 5])
            ->assertForbidden();

        // The legitimate path still works.
        $this->actingAs($admin)
            ->put(route('players.transactions.update', [$playerA->id, $transaction->id]), ['amount' => 5])
            ->assertRedirect();
    }
}
