<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Services\Finance\RecalculatePlayerDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FixDebtPaymentsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Player $player;

    private PlayerSubscription $older;

    private PlayerSubscription $newer;

    private Transaction $legacy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->player = Player::create([
            'membership_id' => '900000029', 'firstname' => 'Old', 'lastname' => 'Debt',
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
        $this->newer = $this->owe(2025, 3000);
        $this->older = $this->owe(2024, 2000);

        // What the app used to record for «دفع دين»: flat income, linked to nothing.
        $this->legacy = Transaction::create([
            'amount' => 2500, 'transaction_date' => '2025-03-10', 'transaction_type' => 'income',
            'category' => 'debt_payment', 'payment_method' => 'ccp', 'description' => 'old debt',
            'related_entity_type' => 'Player', 'related_entity_id' => $this->player->id,
            'player_subscription_id' => null, 'status' => 'Paid',
        ]);
        app(RecalculatePlayerDebtService::class)->forPlayer($this->player->fresh());
    }

    private function owe(int $year, float $amount): PlayerSubscription
    {
        return PlayerSubscription::create([
            'player_id' => $this->player->id, 'subscription_id' => null, 'transaction_id' => null,
            'label' => "Debt {$year}", 'year' => $year, 'status_at_time' => 'student',
            'is_mandatory' => true, 'amount_owed' => $amount, 'amount_paid' => 0,
        ]);
    }

    #[Test]
    public function the_dry_run_changes_nothing(): void
    {
        $this->artisan('finance:fix-debt-payments')->assertSuccessful();

        $this->assertFalse((bool) $this->legacy->fresh()->archived);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(5000.0, (float) $this->player->fresh()->outstanding_debt);
    }

    #[Test]
    public function force_settles_the_oldest_obligations_with_the_old_payment(): void
    {
        $this->artisan('finance:fix-debt-payments', ['--force' => true])->assertSuccessful();

        $this->assertTrue((bool) $this->legacy->fresh()->archived);
        $this->assertSame(2000.0, (float) $this->older->fresh()->amount_paid);
        $this->assertSame(500.0, (float) $this->newer->fresh()->amount_paid);
        $this->assertSame(2500.0, (float) $this->player->fresh()->outstanding_debt);

        $created = Transaction::where('archived', false)->orderBy('id')->get();
        $this->assertCount(2, $created);
        foreach ($created as $tx) {
            // Same money, same day, same way it came in.
            $this->assertSame('2025-03-10', $tx->transaction_date->format('Y-m-d'));
            $this->assertSame('ccp', $tx->payment_method);
            $this->assertSame('old debt', $tx->description);
        }
        $this->assertSame(2500.0, (float) $created->sum('amount'));
    }

    #[Test]
    public function running_it_twice_repairs_once(): void
    {
        $this->artisan('finance:fix-debt-payments', ['--force' => true])->assertSuccessful();
        $this->artisan('finance:fix-debt-payments', ['--force' => true])->assertSuccessful();

        $this->assertSame(2, Transaction::where('archived', false)->count());
        $this->assertSame(2500.0, (float) $this->player->fresh()->outstanding_debt);
    }
}
