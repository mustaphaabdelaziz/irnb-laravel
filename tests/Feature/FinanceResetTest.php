<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Finance\FinanceResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Start fresh": finance:reset and its superadmin button delete every financial
 * record but keep the setup (registers, categories, fiscal years).
 */
class FinanceResetTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $privileges): User
    {
        return User::factory()->create([
            'privileges' => $privileges,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // VACUUM INTO cannot run inside RefreshDatabase's wrapping transaction;
        // the real backup is exercised against a file database, not here.
        $this->partialMock(FinanceResetService::class)->shouldReceive('backup')->andReturn('backup-dir');
    }

    private function seedFinance(): void
    {
        $player = DB::table('players')->insertGetId([
            'membership_id' => '9000000001', 'firstname' => 'Ali', 'lastname' => 'Test',
            'is_student' => true, 'outstanding_debt' => 2000,
        ]);
        $account = DB::table('finance_accounts')->insertGetId([
            'name' => 'Caisse', 'type' => 'cash', 'opening_balance' => 500, 'current_balance' => 1500,
        ]);
        DB::table('fiscal_years')->insert([
            'year' => 2025, 'status' => 'closed', 'opening_balance' => 500,
            'closing_balance' => 1500, 'total_income' => 1000, 'total_expense' => 0, 'closed_at' => now(),
        ]);
        $sub = DB::table('subscriptions')->insertGetId([
            'name' => 'Annual', 'year' => 2025, 'amount_student' => 2000, 'amount_worker' => 2000,
        ]);
        $branch = DB::table('branches')->insertGetId(['name' => 'Football']);
        DB::table('branch_subscription')->insert(['branch_id' => $branch, 'subscription_id' => $sub]);
        $tx = DB::table('transactions')->insertGetId([
            'amount' => 1000, 'transaction_date' => '2025-01-10', 'transaction_type' => 'income',
            'category' => 'subscription', 'finance_account_id' => $account, 'fiscal_year' => 2025,
        ]);
        DB::table('player_subscriptions')->insert([
            'player_id' => $player, 'subscription_id' => $sub, 'transaction_id' => $tx, 'year' => 2025,
            'status_at_time' => 'student', 'amount_owed' => 3000, 'amount_paid' => 1000,
        ]);
        Storage::disk('local')->put('receipts/r1.pdf', 'pdf');
    }

    private function assertWiped(): void
    {
        foreach (['subscriptions', 'player_subscriptions', 'transactions', 'branch_subscription', 'finance_transfers', 'budgets'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} not emptied");
        }
        $this->assertEquals(0, DB::table('players')->value('outstanding_debt'));
        $this->assertSame(0, DB::table('finance_accounts')->where('current_balance', '!=', 0)->count());
        $this->assertSame(0, DB::table('finance_accounts')->where('opening_balance', '!=', 0)->count());

        // Setup survives; the year is reopened with blank figures.
        $this->assertSame(1, DB::table('players')->count());
        $this->assertSame(1, DB::table('branches')->count());
        $this->assertTrue(DB::table('finance_accounts')->where('name', 'Caisse')->exists());
        $year = DB::table('fiscal_years')->first();
        $this->assertSame('open', $year->status);
        $this->assertNull($year->closing_balance);
        $this->assertNull($year->closed_at);

        Storage::disk('local')->assertMissing('receipts/r1.pdf');
    }

    #[Test]
    public function the_command_wipes_financial_records_and_keeps_the_setup(): void
    {
        $this->seedFinance();

        $this->artisan('finance:reset')
            ->expectsQuestion('Type RESET to continue', 'RESET')
            ->assertSuccessful();

        $this->assertWiped();
    }

    #[Test]
    public function the_command_changes_nothing_without_the_typed_word(): void
    {
        $this->seedFinance();

        $this->artisan('finance:reset')
            ->expectsQuestion('Type RESET to continue', 'yes')
            ->assertFailed();

        $this->assertSame(1, DB::table('transactions')->count());
        $this->assertSame(1, DB::table('subscriptions')->count());
    }

    #[Test]
    public function a_superadmin_can_reset_from_the_settings_page(): void
    {
        $this->seedFinance();

        $this->actingAs($this->user(['superadmin']))
            ->post(route('finance.reset'), ['confirm' => 'RESET'])
            ->assertRedirect()
            ->assertSessionHas('success', 'flash.finance_reset_done');

        $this->assertWiped();
    }

    #[Test]
    public function the_route_needs_the_typed_word(): void
    {
        $this->seedFinance();

        $this->actingAs($this->user(['superadmin']))
            ->post(route('finance.reset'), ['confirm' => 'reset'])
            ->assertSessionHasErrors('confirm');

        $this->assertSame(1, DB::table('transactions')->count());
    }

    #[Test]
    public function a_plain_admin_cannot_reset(): void
    {
        $this->seedFinance();

        $this->actingAs($this->user(['admin']))
            ->post(route('finance.reset'), ['confirm' => 'RESET'])
            ->assertForbidden();

        $this->assertSame(1, DB::table('transactions')->count());
    }
}
