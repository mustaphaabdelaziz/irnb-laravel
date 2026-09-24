<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionBulkDeleteAndFiscalYearDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function userWith(array $permissions): User
    {
        $role = Role::factory()->create(['permissions' => $permissions]);

        return User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);
    }

    private function tx(float $amount, int $year, array $extra = []): Transaction
    {
        return Transaction::create([
            'amount' => $amount,
            'transaction_date' => $year.'-03-01',
            'transaction_type' => 'income',
            'category' => 'donation',
            'payment_method' => 'cash',
            'status' => 'Paid',
            'fiscal_year' => $year,
        ] + $extra);
    }

    #[Test]
    public function bulk_delete_archives_the_selected_transactions(): void
    {
        $a = $this->tx(100, 2026);
        $b = $this->tx(200, 2026);
        $keep = $this->tx(300, 2026);

        $this->actingAs($this->admin())
            ->post(route('transactions.bulkDestroy'), ['ids' => [$a->id, $b->id]])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($flash) => $flash['params']['count'] === 2 && $flash['params']['skipped'] === 0);

        $this->assertTrue($a->fresh()->archived);
        $this->assertTrue($b->fresh()->archived);
        $this->assertFalse($keep->fresh()->archived);
        // Year totals recomputed once, without the archived rows.
        $this->assertEquals(300, FiscalYear::where('year', 2026)->value('total_income'));
    }

    #[Test]
    public function bulk_delete_skips_rows_in_a_closed_year(): void
    {
        $closed = $this->tx(100, 2024);
        $open = $this->tx(200, 2026);
        FiscalYear::where('year', 2024)->update(['status' => 'closed']);

        $this->actingAs($this->admin())
            ->post(route('transactions.bulkDestroy'), ['ids' => [$closed->id, $open->id]])
            ->assertSessionHas('success', fn ($flash) => $flash['key'] === 'flash.transactions_archived_some_skipped'
                && $flash['params']['count'] === 1 && $flash['params']['skipped'] === 1);

        $this->assertFalse($closed->fresh()->archived);
        $this->assertTrue($open->fresh()->archived);
    }

    #[Test]
    public function bulk_deleting_a_payment_puts_the_debt_back(): void
    {
        $player = Player::create([
            'membership_id' => '8300000001', 'firstname' => 'Bulk', 'lastname' => 'Debt',
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
        $sub = PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => null, 'year' => 2026,
            'status_at_time' => 'student', 'is_mandatory' => true, 'amount_owed' => 2000, 'amount_paid' => 0,
        ]);
        $payment = $this->tx(2000, 2026, ['category' => 'subscription', 'player_subscription_id' => $sub->id]);
        $this->assertEquals(0, $player->fresh()->outstanding_debt);

        $this->actingAs($this->admin())->post(route('transactions.bulkDestroy'), ['ids' => [$payment->id]]);

        $this->assertEquals(0, $sub->fresh()->amount_paid);
        $this->assertEquals(2000, $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function bulk_delete_needs_the_delete_permission(): void
    {
        $tx = $this->tx(100, 2026);

        $this->actingAs($this->userWith(['transactions' => ['view', 'add', 'edit']]))
            ->post(route('transactions.bulkDestroy'), ['ids' => [$tx->id]])
            ->assertForbidden();

        $this->assertFalse($tx->fresh()->archived);
    }

    #[Test]
    public function an_empty_open_year_can_be_deleted_with_its_archived_rows_and_budget(): void
    {
        $year = FiscalYear::create(['year' => 2030, 'start_date' => '2030-01-01', 'end_date' => '2030-12-31', 'status' => 'open', 'opening_balance' => 0]);
        $archived = $this->tx(50, 2030);
        $archived->update(['archived' => true]);
        $category = FinanceCategory::create(['type' => 'income', 'name' => 'Dons', 'is_active' => true]);
        Budget::create(['fiscal_year_id' => $year->id, 'finance_category_id' => $category->id, 'planned_amount' => 1000]);

        $this->actingAs($this->admin())
            ->delete(route('finance.years.destroy', $year))
            ->assertSessionHas('success');

        $this->assertModelMissing($year);
        $this->assertModelMissing($archived);
        $this->assertSame(0, Budget::count());
    }

    #[Test]
    public function a_year_with_active_transactions_cannot_be_deleted(): void
    {
        $this->tx(50, 2029);
        $year = FiscalYear::where('year', 2029)->firstOrFail();

        $this->actingAs($this->admin())
            ->delete(route('finance.years.destroy', $year))
            ->assertSessionHas('error');

        $this->assertModelExists($year);
    }

    #[Test]
    public function a_closed_year_cannot_be_deleted(): void
    {
        $year = FiscalYear::create(['year' => 2020, 'start_date' => '2020-01-01', 'end_date' => '2020-12-31', 'status' => 'closed', 'opening_balance' => 0]);

        $this->actingAs($this->admin())->delete(route('finance.years.destroy', $year))->assertSessionHas('error');

        $this->assertModelExists($year);
    }

    #[Test]
    public function the_settings_page_flags_which_years_can_be_deleted(): void
    {
        $this->tx(50, 2029);
        FiscalYear::create(['year' => 2031, 'start_date' => '2031-01-01', 'end_date' => '2031-12-31', 'status' => 'open', 'opening_balance' => 0]);

        $this->actingAs($this->admin())->get(route('finance.settings'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('years', fn ($years) => collect($years)->firstWhere('year', 2031)['can_delete'] === true
                && collect($years)->firstWhere('year', 2029)['can_delete'] === false));
    }
}
