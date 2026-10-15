<?php

namespace App\Services\Finance;

use App\Models\Branch;
use App\Models\FinanceAccount;

class CashRegisterProvisioner
{
    /**
     * Ensure a branch has its coffre (the branch treasury). Caisses inside it
     * are created by hand — a new member category never creates one.
     */
    public function forBranch(Branch $branch): FinanceAccount
    {
        return FinanceAccount::firstOrCreate(
            ['branch_id' => $branch->id, 'is_treasury' => true],
            fn () => [
                'name' => $branch->name.' Treasury',
                'type' => 'cash',
                'opening_balance' => 0,
                'current_balance' => 0,
                'currency' => 'DZD',
                'is_active' => true,
                'sort_order' => ((int) FinanceAccount::max('sort_order')) + 1,
            ],
        );
    }
}
