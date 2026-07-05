<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionFinanceCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function it_rejects_a_category_whose_type_mismatches(): void
    {
        $expenseCat = FinanceCategory::create(['type' => 'expense', 'name' => 'Rent', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->post(route('transactions.store'), [
                'transaction_type' => 'income',
                'finance_category_id' => $expenseCat->id,
                'amount' => 100,
                'transaction_date' => now()->toDateString(),
                'payment_method' => 'cash',
                'status' => 'Paid',
            ])
            ->assertSessionHasErrors('finance_category_id');
    }

    #[Test]
    public function it_stores_finance_category_and_ccp_fields(): void
    {
        // firstOrCreate: the finance_tables migration seeds a default 'Donations'
        // income category (unique on type+name), so a plain create() here would
        // collide with that seed data under RefreshDatabase.
        $incomeCat = FinanceCategory::firstOrCreate(['type' => 'income', 'name' => 'Donations'], ['is_active' => true]);

        $this->actingAs($this->admin())
            ->post(route('transactions.store'), [
                'transaction_type' => 'income',
                'finance_category_id' => $incomeCat->id,
                'amount' => 500,
                'transaction_date' => now()->toDateString(),
                'payment_method' => 'ccp',
                'payment_account' => '0012345678',
                'payment_ccp_key' => '55',
                'payment_holder' => 'Club IRNB',
                'status' => 'Paid',
            ])
            ->assertRedirect();

        $tx = Transaction::firstOrFail();
        $this->assertSame($incomeCat->id, $tx->finance_category_id);
        $this->assertSame('55', $tx->payment_ccp_key);
        $this->assertSame('donations', $tx->category); // legacy string derived from the category name
    }
}
