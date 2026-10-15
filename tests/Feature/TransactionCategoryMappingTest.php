<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionCategoryMappingTest extends TestCase
{
    use RefreshDatabase;

    private function tx(string $type, string $slug, array $extra = []): Transaction
    {
        return Transaction::create([
            'amount' => 100, 'transaction_type' => $type, 'category' => $slug,
            'status' => 'Paid', 'payment_method' => 'cash', 'transaction_date' => now(),
        ] + $extra);
    }

    #[Test]
    public function the_apps_own_payment_kinds_land_in_the_seeded_categories(): void
    {
        $before = FinanceCategory::count();

        $this->assertSame('SUB', $this->tx('income', 'subscription')->financeCategory->code);
        $this->assertSame('DON', $this->tx('income', 'donation')->financeCategory->code);
        $this->assertSame('ARR', $this->tx('income', 'debt_payment')->financeCategory->code);
        $this->assertSame('MEM', $this->tx('income', 'membership_fee')->financeCategory->code);
        $this->assertSame('EQP', $this->tx('expense', 'equipment')->financeCategory->code);
        $this->assertSame('EQP', $this->tx('expense', 'equipment_purchase')->financeCategory->code);

        $this->assertSame($before, FinanceCategory::count());
    }

    #[Test]
    public function an_unknown_slug_lands_in_other_never_in_a_new_category(): void
    {
        $before = FinanceCategory::count();

        $this->assertSame('OEX', $this->tx('expense', 'msaryf_ashghal_almkr')->financeCategory->code);
        $this->assertSame('OIN', $this->tx('income', 'miscellaneous')->financeCategory->code);
        $this->assertSame('OIN', $this->tx('income', '')->financeCategory->code);

        $this->assertSame($before, FinanceCategory::count());
        $this->assertFalse(FinanceCategory::where('name', 'Msaryf Ashghal Almkr')->exists());
    }

    #[Test]
    public function a_club_that_removed_a_seeded_category_falls_back_to_its_legacy_twin(): void
    {
        FinanceCategory::where('code', 'SUB')->delete();
        $twin = FinanceCategory::create(['type' => 'income', 'name' => 'Subscription', 'is_active' => true]);

        $this->assertSame($twin->id, $this->tx('income', 'subscription')->finance_category_id);
    }

    #[Test]
    public function a_missing_other_category_is_recreated_once(): void
    {
        FinanceCategory::where('code', 'OEX')->delete();

        $first = $this->tx('expense', 'rent-of-the-hall');
        $second = $this->tx('expense', 'paint');

        $this->assertSame($first->finance_category_id, $second->finance_category_id);
        $other = $first->financeCategory;
        $this->assertSame('OEX', $other->code);
        $this->assertTrue($other->is_system);
        $this->assertSame(1, FinanceCategory::where('type', 'expense')->where('code', 'OEX')->count());
    }

    #[Test]
    public function a_category_chosen_by_the_caller_is_kept(): void
    {
        $rent = FinanceCategory::create(['type' => 'expense', 'name' => 'Rent', 'is_active' => true]);

        $this->assertSame($rent->id, $this->tx('expense', 'rent', ['finance_category_id' => $rent->id])->finance_category_id);
    }
}
