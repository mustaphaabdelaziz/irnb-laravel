<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use App\Models\Player;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionSeasonYearTest extends TestCase
{
    use RefreshDatabase;

    private function tx(string $date, array $extra = []): Transaction
    {
        return Transaction::create([
            'amount' => 1000, 'transaction_date' => $date, 'transaction_type' => 'income',
            'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid',
        ] + $extra);
    }

    #[Test]
    public function a_transaction_lands_in_the_season_of_its_date(): void
    {
        $this->assertSame(2026, $this->tx('2026-08-31')->fiscal_year);
        $october = $this->tx('2026-10-06');

        $this->assertSame(2027, $october->fiscal_year);
        $fy = FiscalYear::find($october->fiscal_year_id);
        $this->assertSame(2027, $fy->year);
        $this->assertSame('2026-09-01', $fy->start_date->toDateString());
        $this->assertSame('2027-08-31', $fy->end_date->toDateString());
    }

    #[Test]
    public function a_passed_fiscal_year_is_ignored_in_favour_of_the_date(): void
    {
        $this->assertSame(2027, $this->tx('2026-10-06', ['fiscal_year' => 2026])->fiscal_year);
    }

    #[Test]
    public function changing_the_date_moves_the_transaction_to_its_new_season(): void
    {
        $tx = $this->tx('2026-08-20');
        $tx->update(['transaction_date' => '2026-09-02']);

        $this->assertSame(2027, $tx->fresh()->fiscal_year);
    }

    #[Test]
    public function a_backdated_manual_entry_uses_its_own_season_not_today(): void
    {
        Carbon::setTestNow('2026-10-14');
        $admin = User::factory()->admin()->create();
        $category = FinanceCategory::firstOrCreate(['type' => 'income', 'name' => 'Donations'], ['is_active' => true]);

        $this->actingAs($admin)->post(route('transactions.store'), [
            'title' => 'Old gift', 'amount' => 500, 'transaction_type' => 'income',
            'transaction_date' => '2026-05-10', 'finance_category_id' => $category->id,
            'payment_method' => 'cash', 'status' => 'Paid',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2026, Transaction::where('title', 'Old gift')->value('fiscal_year'));
    }

    #[Test]
    public function a_subscription_payment_is_booked_by_payment_date(): void
    {
        Carbon::setTestNow('2026-10-14');
        $admin = User::factory()->admin()->create();
        $player = Player::create(['membership_id' => '9000000001', 'firstname' => 'P', 'lastname' => 'Test', 'is_student' => true]);
        $sub = Subscription::create(['name' => 'Annual', 'year' => 2026, 'amount_student' => 1000, 'amount_worker' => 1000, 'is_mandatory' => true, 'is_active' => true, 'kind' => 'annual']);
        $owed = $sub->assignTo($player);

        $this->actingAs($admin)->post(route('players.transactions.store', $player), [
            'category' => 'subscription', 'player_subscription_id' => $owed->id, 'amount' => 1000, 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        // A 2025/26 debt paid in October 2026 is 2026/27 cash.
        $this->assertSame(2027, Transaction::where('player_subscription_id', $owed->id)->value('fiscal_year'));
    }

    #[Test]
    public function editing_a_player_donation_keeps_its_original_date_and_season(): void
    {
        Carbon::setTestNow('2026-10-14');
        $admin = User::factory()->admin()->create();
        $player = Player::create(['membership_id' => '9000000001', 'firstname' => 'P', 'lastname' => 'Test', 'is_student' => true]);
        $original = $this->tx('2026-08-20', ['related_entity_type' => 'Player', 'related_entity_id' => $player->id]);

        $this->actingAs($admin)->put(route('players.transactions.update', [$player, $original]), [
            'amount' => 1200, 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $edited = Transaction::where('archived', false)->sole();
        $this->assertNotSame($original->id, $edited->id);
        $this->assertSame('2026-08-20', $edited->transaction_date->toDateString());
        $this->assertSame(2026, $edited->fiscal_year);
    }

    #[Test]
    public function editing_a_subscription_payment_keeps_its_original_date_and_season(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->admin()->create();
        $player = Player::create(['membership_id' => '9000000001', 'firstname' => 'P', 'lastname' => 'Test', 'is_student' => true]);
        $sub = Subscription::create(['name' => 'Annual', 'year' => 2026, 'amount_student' => 1000, 'amount_worker' => 1000, 'is_mandatory' => true, 'is_active' => true, 'kind' => 'annual']);
        $owed = $sub->assignTo($player);
        $this->actingAs($admin)->post(route('players.transactions.store', $player), [
            'category' => 'subscription', 'player_subscription_id' => $owed->id, 'amount' => 600, 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();
        $original = Transaction::where('player_subscription_id', $owed->id)->sole();

        Carbon::setTestNow('2026-10-14');
        $this->actingAs($admin)->put(route('players.transactions.update', [$player, $original]), [
            'amount' => 800, 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $edited = Transaction::where('archived', false)->sole();
        $this->assertSame('2026-08-20', $edited->transaction_date->toDateString());
        $this->assertSame(2026, $edited->fiscal_year);
        $this->assertEquals(800, (float) $owed->fresh()->amount_paid);
    }
}
