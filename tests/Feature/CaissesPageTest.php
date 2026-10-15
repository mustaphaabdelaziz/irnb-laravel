<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FinanceAccount;
use App\Models\FinanceTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CaissesPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_page_groups_caisses_under_their_coffre_and_shows_club_level_accounts(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $coffre = $branch->treasury;
        $coffre->update(['opening_balance' => 1000]);
        $category = Category::create(['name' => 'Senior']);
        $caisse = FinanceAccount::create(['branch_id' => $branch->id, 'parent_account_id' => $coffre->id, 'category_id' => $category->id, 'name' => 'Caisse seniors', 'type' => 'cash', 'opening_balance' => 300, 'is_active' => true, 'currency' => 'DZD']);
        $bank = FinanceAccount::create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 50, 'is_active' => true, 'currency' => 'DZD']);
        FinanceTransfer::create(['from_account_id' => $coffre->id, 'to_account_id' => $caisse->id, 'amount' => 100, 'transfer_date' => '2026-10-05']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('finance.registers.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Registers')
                ->where('coffres.0.branch.id', $branch->id)
                ->where('coffres.0.coffre.id', $coffre->id)
                ->where('coffres.0.own_balance', 900)
                ->where('coffres.0.caisses.0.id', $caisse->id)
                ->where('coffres.0.caisses.0.category.id', $category->id)
                ->where('coffres.0.caisses.0.transfers_count', 1)
                ->where('coffres.0.coffre.transfers_count', 1)
                ->where('clubLevel', fn ($accounts) => collect($accounts)->firstWhere('id', $bank->id)['transfers_count'] === 0)
                ->where('clubLevel', fn ($accounts) => collect($accounts)->pluck('id')->contains($bank->id))
                ->where('openingFund', null)
                ->where('totals.cash', 1350)
                // Caisses only: the seeded Cash and Club Bank Account, the caisse and Bank.
                // The coffre and the Fonds Initial are not counted.
                ->where('totals.active', 4)
                ->where('transfers.data.0.transfer_date', '2026-10-05'));
    }
}
