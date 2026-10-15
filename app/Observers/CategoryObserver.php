<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\FinanceAccount;

class CategoryObserver
{
    /**
     * A caisse linked to a deleted category keeps its money, name and history;
     * it simply stops being the category's default caisse.
     */
    public function deleting(Category $category): void
    {
        FinanceAccount::where('category_id', $category->id)->update(['category_id' => null]);
    }
}
