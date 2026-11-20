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

class TransactionCorrectionLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Player $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $this->player = Player::create([
            'membership_id' => '8000000001', 'firstname' => 'Flow', 'lastname' => 'Test',
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
    }

    private function line(float $owed): PlayerSubscription
    {
        $line = PlayerSubscription::create([
            'player_id' => $this->player->id, 'subscription_id' => null, 'transaction_id' => null,
            'year' => (int) now()->year, 'status_at_time' => 'student',
            'is_mandatory' => true, 'amount_owed' => $owed, 'amount_paid' => 0,
        ]);
        app(RecalculatePlayerDebtService::class)->forPlayer($this->player->fresh());

        return $line;
    }

    private function pay(PlayerSubscription $line, float $amount): Transaction
    {
        $this->actingAs($this->admin)->post(route('players.transactions.store', $this->player), [
            'player_subscription_id' => $line->id,
            'category' => 'subscription',
            'amount' => $amount,
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    #[Test]
    public function a_corrected_payment_points_at_the_one_it_replaces(): void
    {
        $original = $this->pay($this->line(2000), 800);

        $this->actingAs($this->admin)
            ->put(route('players.transactions.update', [$this->player, $original]), ['amount' => 500, 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();

        $replacement = Transaction::where('archived', false)->sole();
        $this->assertSame($original->id, $replacement->replaces_transaction_id);
        $this->assertNull($original->fresh()->replaces_transaction_id);
    }

    #[Test]
    public function every_row_of_a_split_correction_points_at_the_original(): void
    {
        $original = $this->pay($this->line(500), 500);

        // 800 against a 500 line: 500 on the subscription, 300 as a donation.
        $this->actingAs($this->admin)
            ->put(route('players.transactions.update', [$this->player, $original]), ['amount' => 800, 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Transaction::where('archived', false)->count());
        $this->assertSame(2, Transaction::where('replaces_transaction_id', $original->id)->count());
    }

    #[Test]
    public function a_new_payment_replaces_nothing(): void
    {
        $this->assertNull($this->pay($this->line(2000), 800)->replaces_transaction_id);
    }
}
