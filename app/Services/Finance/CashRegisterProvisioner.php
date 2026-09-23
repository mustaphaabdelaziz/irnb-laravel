<?php

namespace App\Services\Finance;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FinanceAccount;
use Illuminate\Support\Facades\DB;

class CashRegisterProvisioner
{
    /** The stored name of a category's register (the UI labels it from branch + category). */
    public static function registerName(Category $category): string
    {
        return $category->name.' Cash Register';
    }

    /** Ensure a branch has one treasury and one child register per category. */
    public function forBranch(Branch $branch): FinanceAccount
    {
        return DB::transaction(function () use ($branch) {
            $treasury = FinanceAccount::firstOrCreate(
                ['branch_id' => $branch->id, 'is_treasury' => true],
                fn () => [
                    'name' => $branch->name.' Treasury',
                    'type' => 'cash',
                    'opening_balance' => 0,
                    'current_balance' => 0,
                    'currency' => 'DZD',
                    'is_active' => true,
                    'sort_order' => $this->nextSortOrder(),
                ],
            );

            foreach (Category::query()->orderBy('id')->get() as $category) {
                $this->categoryRegister($branch, $category, $treasury);
            }

            return $treasury;
        });
    }

    /** Ensure a newly created category has one register in every branch. */
    public function forCategory(Category $category): void
    {
        DB::transaction(function () use ($category) {
            foreach (Branch::query()->with('treasury')->orderBy('id')->get() as $branch) {
                $treasury = $branch->treasury ?? $this->forBranch($branch);

                $this->categoryRegister($branch, $category, $treasury);
            }
        });
    }

    private function categoryRegister(Branch $branch, Category $category, FinanceAccount $treasury): FinanceAccount
    {
        $register = FinanceAccount::firstOrCreate(
            ['branch_id' => $branch->id, 'category_id' => $category->id],
            // Closure: the MAX(sort_order) query only runs when creating.
            fn () => [
                'parent_account_id' => $treasury->id,
                'name' => self::registerName($category),
                'type' => 'cash',
                'is_treasury' => false,
                'currency' => 'DZD',
                'is_active' => true,
                'sort_order' => $this->nextSortOrder(),
            ],
        );

        // Re-attach an existing register to the branch treasury (no write when it already is).
        $register->forceFill([
            'parent_account_id' => $treasury->id,
            'is_treasury' => false,
            'is_active' => true,
        ])->save();

        return $register;
    }

    private function nextSortOrder(): int
    {
        return ((int) FinanceAccount::max('sort_order')) + 1;
    }
}
