<?php

namespace App\Observers;

use App\Models\Branch;
use App\Services\Finance\CashRegisterProvisioner;

class BranchObserver
{
    /**
     * Every branch starts with one treasury and one register per category.
     */
    public function created(Branch $branch): void
    {
        app(CashRegisterProvisioner::class)->forBranch($branch);
    }
}
