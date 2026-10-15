<?php

namespace App\Observers;

use App\Models\Branch;
use App\Services\Finance\CashRegisterProvisioner;

class BranchObserver
{
    /**
     * Every branch starts with its coffre (treasury); caisses are added by hand.
     */
    public function created(Branch $branch): void
    {
        app(CashRegisterProvisioner::class)->forBranch($branch);
    }
}
