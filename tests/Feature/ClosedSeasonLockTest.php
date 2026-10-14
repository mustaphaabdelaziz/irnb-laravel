<?php

namespace Tests\Feature;

use App\Exceptions\ClosedFiscalYearException;
use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClosedSeasonLockTest extends TestCase
{
    use RefreshDatabase;

    private function tx(string $date): Transaction
    {
        return Transaction::create([
            'amount' => 1000, 'transaction_date' => $date, 'transaction_type' => 'income',
            'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid',
        ]);
    }

    private function close(int $key): void
    {
        FiscalYear::where('year', $key)->update(['status' => 'closed']);
    }

    #[Test]
    public function no_transaction_can_be_added_to_a_closed_season(): void
    {
        $this->tx('2026-10-01');
        $this->close(2027);

        $this->expectException(ClosedFiscalYearException::class);
        $this->tx('2026-11-01');
    }

    #[Test]
    public function a_transaction_cannot_be_moved_out_of_or_into_a_closed_season(): void
    {
        $old = $this->tx('2026-05-01');
        $open = $this->tx('2026-10-01');
        $this->close(2026);

        try {
            $old->update(['transaction_date' => '2026-10-02']);
            $this->fail('moved out of a closed season');
        } catch (ClosedFiscalYearException) {
        }

        $this->expectException(ClosedFiscalYearException::class);
        $open->update(['transaction_date' => '2026-05-02']);
    }

    #[Test]
    public function a_transaction_in_a_closed_season_cannot_be_archived_or_deleted(): void
    {
        $tx = $this->tx('2026-10-01');
        $this->close(2027);

        try {
            $tx->update(['archived' => true]);
            $this->fail('archived inside a closed season');
        } catch (ClosedFiscalYearException) {
        }

        $this->expectException(ClosedFiscalYearException::class);
        $tx->delete();
    }

    #[Test]
    public function a_player_payment_into_a_closed_season_comes_back_as_a_flash_error(): void
    {
        Carbon::setTestNow('2026-10-14');
        $this->tx('2026-10-01');
        $this->close(2027);
        $admin = User::factory()->admin()->create();
        $player = Player::create(['membership_id' => '9000000001', 'firstname' => 'P', 'lastname' => 'Test', 'is_student' => true]);

        $this->actingAs($admin)
            ->from(route('players.show', $player))
            ->post(route('players.transactions.store', $player), ['category' => 'donation', 'amount' => 500, 'payment_method' => 'cash'])
            ->assertRedirect(route('players.show', $player))
            ->assertSessionHas('error', ['key' => 'flash.season_closed', 'params' => ['season' => '2026/2027']]);

        $this->assertSame(1, Transaction::count());
    }

    #[Test]
    public function the_manual_form_reports_the_closed_season(): void
    {
        $this->tx('2026-10-01');
        $this->close(2027);
        $admin = User::factory()->admin()->create();
        $category = FinanceCategory::firstOrCreate(['type' => 'income', 'name' => 'Donations'], ['is_active' => true]);

        $this->actingAs($admin)->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'title' => 'Late', 'amount' => 500, 'transaction_type' => 'income',
                'transaction_date' => '2026-10-10', 'finance_category_id' => $category->id,
                'payment_method' => 'cash', 'status' => 'Paid',
            ])
            ->assertSessionHas('error', ['key' => 'flash.season_closed', 'params' => ['season' => '2026/2027']]);
    }
}
