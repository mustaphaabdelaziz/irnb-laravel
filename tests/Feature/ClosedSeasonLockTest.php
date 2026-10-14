<?php

namespace Tests\Feature;

use App\Exceptions\ClosedFiscalYearException;
use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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

    /** @return array<string, mixed> */
    private function formPayload(array $overrides = []): array
    {
        $category = FinanceCategory::firstOrCreate(['type' => 'income', 'name' => 'Donations'], ['is_active' => true]);

        return [
            'title' => 'Donation', 'transaction_type' => 'income', 'finance_category_id' => $category->id,
            'amount' => 500, 'transaction_date' => '2026-10-10', 'status' => 'Paid',
            ...$overrides,
        ];
    }

    #[Test]
    public function a_rejected_receipt_replacement_keeps_the_old_file_and_leaves_no_new_one(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('transactions.store'), $this->formPayload([
            'receipt' => UploadedFile::fake()->create('old.pdf', 100, 'application/pdf'),
        ]))->assertSessionHasNoErrors();
        $tx = Transaction::latest('id')->firstOrFail();
        $old = $tx->receipt_filename;
        Storage::disk('local')->assertExists($old);
        $this->close(2027);

        $this->actingAs($admin)->from(route('transactions.edit', $tx))
            ->put(route('transactions.update', $tx), $this->formPayload([
                'receipt' => UploadedFile::fake()->create('new.pdf', 100, 'application/pdf'),
            ]))
            ->assertSessionHas('error', ['key' => 'flash.season_closed', 'params' => ['season' => '2026/2027']]);

        $this->assertSame($old, $tx->fresh()->receipt_filename);
        Storage::disk('local')->assertExists($old);
        $this->assertSame([$old], Storage::disk('local')->allFiles('receipts'));
    }

    #[Test]
    public function a_rejected_new_transaction_leaves_no_receipt_file_behind(): void
    {
        Storage::fake('local');
        $this->tx('2026-10-01');
        $this->close(2027);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->from(route('transactions.create'))
            ->post(route('transactions.store'), $this->formPayload([
                'receipt' => UploadedFile::fake()->create('late.pdf', 100, 'application/pdf'),
            ]))
            ->assertSessionHas('error', ['key' => 'flash.season_closed', 'params' => ['season' => '2026/2027']]);

        $this->assertSame(1, Transaction::count());
        $this->assertSame([], Storage::disk('local')->allFiles('receipts'));
    }

    #[Test]
    public function deleting_a_transaction_of_a_closed_season_is_refused_and_it_stays_unarchived(): void
    {
        $tx = $this->tx('2026-10-01');
        $this->close(2027);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->from(route('transactions.index'))
            ->delete(route('transactions.destroy', $tx))
            ->assertSessionHas('error', ['key' => 'flash.season_closed', 'params' => ['season' => '2026/2027']]);

        $this->assertFalse((bool) $tx->fresh()->archived);
    }
}
