<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FiscalPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionShowTraceTest extends TestCase
{
    use RefreshDatabase;

    private function tx(User $recorder): Transaction
    {
        return Transaction::create([
            'title' => 'Hall rent', 'amount' => 1000, 'transaction_type' => 'expense', 'category' => 'rent',
            'payment_method' => 'cash', 'status' => 'Paid', 'recorded_by_user_id' => $recorder->id,
        ]);
    }

    #[Test]
    public function anyone_who_may_view_transactions_sees_the_trace_and_history(): void
    {
        $recorder = User::factory()->admin()->create(['name' => 'Cashier One']);
        $tx = $this->tx($recorder);
        $viewer = User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::create(['key' => 'reader', 'name' => ['en' => 'Reader'], 'permissions' => ['transactions' => ['view']]])->id,
        ]);

        $this->actingAs($viewer)->get(route('transactions.show', $tx))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Transactions/Show')
                ->has('history', 1)
                ->where('history.0.type', 'recorded')
                ->where('history.0.user', 'Cashier One')
                ->where('trace.recorded.user', 'Cashier One')
                ->where('trace.recorded.at', $tx->created_at->format('Y-m-d H:i'))
                ->where('trace.modified', null)
                ->where('cancellation', null)
                ->where('group', null)
                ->where('season.label', FiscalPeriod::label((int) $tx->fiscal_year))
                ->where('season.closed', false)
                ->missing('prints'));
    }

    #[Test]
    public function a_cancelled_transaction_in_a_closed_season_says_so(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Cashier One']);
        $tx = $this->tx($admin);
        $this->actingAs($admin)->delete(route('transactions.destroy', $tx))->assertRedirect();
        FiscalYear::where('year', $tx->fiscal_year)->update(['status' => 'closed']);

        $this->actingAs($admin)->get(route('transactions.show', $tx))
            ->assertInertia(fn (Assert $page) => $page
                ->where('cancellation.user', 'Cashier One')
                ->where('cancellation.replaced_by', [])
                ->where('trace.modified.type', 'cancelled')
                ->where('season.closed', true));
    }

    #[Test]
    public function the_page_no_longer_sends_the_recorder_account(): void
    {
        $admin = User::factory()->admin()->create();
        $tx = $this->tx($admin);

        $props = $this->actingAs($admin)->get(route('transactions.show', $tx))->viewData('page')['props'];

        $this->assertArrayNotHasKey('email', $props['transaction']['recorded_by'] ?? []);
        $this->assertArrayNotHasKey('received_by', $props['transaction']);
    }
}
