<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Services\LiteraryReport\Sections\FinanceSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinanceSectionTest extends TestCase
{
    use RefreshDatabase;

    private function build(): array
    {
        return app(FinanceSection::class)->build(CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2026-08-31'));
    }

    private function tx(string $type, float $amount, string $date, ?FinanceCategory $category = null, array $extra = []): Transaction
    {
        return Transaction::create($extra + [
            'title' => 'T', 'amount' => $amount, 'transaction_date' => $date, 'transaction_type' => $type,
            'category' => $type === 'income' ? 'donation' : 'other', 'payment_method' => 'cash', 'status' => 'Paid',
            'finance_category_id' => $category?->id,
        ]);
    }

    #[Test]
    public function totals_are_cash_basis_and_exclude_archived_and_out_of_period_entries(): void
    {
        $donations = FinanceCategory::create(['type' => 'income', 'name' => 'LR Donations']);
        $grants = FinanceCategory::create(['type' => 'income', 'name' => 'LR Grants']);
        $travel = FinanceCategory::create(['type' => 'expense', 'name' => 'LR Travel']);

        $this->tx('income', 1000, '2025-09-01', $grants);
        $this->tx('income', 400, '2026-08-31 23:00:00', $donations);
        $this->tx('income', 300, '2026-01-10', $donations);
        $this->tx('income', 50, '2026-01-10')->newQuery()->whereKey(Transaction::max('id'))->update(['finance_category_id' => null]); // observer auto-categorises; force none
        $this->tx('expense', 300, '2026-02-02', $travel);
        $this->tx('income', 999, '2026-03-03', $grants, ['archived' => true]);     // cancelled
        $this->tx('income', 777, '2026-09-01', $grants);                           // after the period

        $data = $this->build();

        $this->assertEquals(1750.0, $data['income']);
        $this->assertEquals(300.0, $data['expense']);
        $this->assertEquals(1450.0, $data['balance']);
        $this->assertEquals([
            ['name' => 'LR Grants', 'total' => 1000.0],
            ['name' => 'LR Donations', 'total' => 700.0],
            ['name' => null, 'total' => 50.0],
        ], $data['top_income']);
        $this->assertEquals([['name' => 'LR Travel', 'total' => 300.0]], $data['top_expense']);
        $this->assertNotSame('', $data['currency']);
    }
}
