<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionLabelsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_export_names_categories_and_caisses_never_slugs(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        $branch = Branch::create(['name' => 'Football']);
        $works = FinanceCategory::create(['type' => 'expense', 'name' => 'مصاريف أشغال المقر', 'is_active' => true]);
        Transaction::create([
            'title' => 'Peinture', 'amount' => 770, 'transaction_type' => 'expense', 'category' => 'msaryf_ashghal_almkr',
            'finance_category_id' => $works->id, 'finance_account_id' => $branch->treasury->id,
            'status' => 'Paid', 'payment_method' => 'cash', 'transaction_date' => '2026-10-11 16:00:00',
        ]);

        $csv = $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'ar']))
            ->get(route('transactions.export', ['format' => 'csv']))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('مصاريف أشغال المقر', $csv);
        $this->assertStringContainsString('خزينة Football', $csv);
        $this->assertStringContainsString('2026-10-11', $csv);
        $this->assertStringNotContainsString('16:00', $csv);
        $this->assertStringNotContainsString('msaryf', $csv);
        $this->assertStringNotContainsString('Football Treasury', $csv);
    }
}
