<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FinanceAccount;
use App\Support\FinanceAccountLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinanceAccountLabelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function accounts_are_named_like_the_caisses_page(): void
    {
        app()->setLocale('fr');
        $branch = Branch::create(['name' => 'Football']);
        $senior = Category::create(['name' => 'Senior', 'name_fr' => 'Séniors']);
        $legacy = FinanceAccount::create([
            'branch_id' => $branch->id, 'category_id' => $senior->id, 'parent_account_id' => $branch->treasury->id,
            'name' => 'Senior Cash Register', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ]);
        $named = FinanceAccount::create([
            'branch_id' => $branch->id, 'parent_account_id' => $branch->treasury->id,
            'name' => 'Caisse cadets', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ]);
        $bank = FinanceAccount::create(['name' => 'BNA', 'type' => 'bank', 'is_active' => true, 'currency' => 'DZD']);
        $fund = FinanceAccount::where('is_opening_fund', true)->first()
            ?? FinanceAccount::create(['name' => 'Opening Fund', 'type' => 'cash', 'is_opening_fund' => true, 'is_active' => true, 'currency' => 'DZD']);
        // Seeded by the create_finance_tables migration.
        $cash = FinanceAccount::whereNull('branch_id')->where('name', 'Cash')->firstOrFail();

        $label = fn (FinanceAccount $a) => FinanceAccountLabel::for($a->fresh(['branch', 'category']));

        $this->assertSame('Coffre — Football', $label($branch->treasury));
        $this->assertSame('Séniors — Football', $label($legacy));
        $this->assertSame('Caisse cadets — Football', $label($named));
        $this->assertSame('BNA', $label($bank));
        $this->assertSame('Fonds Initial', $label($fund));
        $this->assertSame('Caisse du club', $label($cash));
        $this->assertSame('', FinanceAccountLabel::for(null));
    }
}
