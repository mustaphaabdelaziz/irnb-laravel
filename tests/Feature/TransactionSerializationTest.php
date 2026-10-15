<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceTransfer;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionSerializationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function transactions_and_money_moves_reach_the_screens_as_plain_dates(): void
    {
        Carbon::setTestNow('2026-10-15 09:41:27');

        $tx = Transaction::create([
            'amount' => 10, 'transaction_type' => 'income', 'category' => 'donation', 'status' => 'Paid',
            'transaction_date' => '2026-10-01 14:30:00',
        ])->fresh()->toArray();

        $this->assertSame('2026-10-01', $tx['transaction_date']);
        $this->assertSame('2026-10-15 09:41', $tx['created_at']);

        $a = FinanceAccount::create(['name' => 'A', 'type' => 'cash', 'opening_balance' => 100, 'is_active' => true, 'currency' => 'DZD']);
        $b = FinanceAccount::create(['name' => 'B', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD']);
        $move = FinanceTransfer::create([
            'from_account_id' => $a->id, 'to_account_id' => $b->id, 'amount' => 5, 'transfer_date' => '2026-10-02',
        ])->fresh()->toArray();

        $this->assertSame('2026-10-02', $move['transfer_date']);
        $this->assertSame('2026-10-15 09:41', $move['created_at']);
    }
}
