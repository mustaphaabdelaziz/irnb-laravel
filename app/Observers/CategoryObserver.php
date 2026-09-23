<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\FinanceAccount;
use App\Services\Finance\CashRegisterProvisioner;

class CategoryObserver
{
    public function created(Category $category): void
    {
        app(CashRegisterProvisioner::class)->forCategory($category);
    }

    public function updated(Category $category): void
    {
        if ($category->wasChanged('name')) {
            FinanceAccount::where('category_id', $category->id)->update([
                'name' => CashRegisterProvisioner::registerName($category),
            ]);
        }
    }

    /** Keep historical accounts for audit, but remove them from new selections. */
    public function deleting(Category $category): void
    {
        FinanceAccount::where('category_id', $category->id)->update(['is_active' => false]);
    }
}
