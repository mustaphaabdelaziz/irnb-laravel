<?php

namespace App\Observers;

use App\Models\Branch;
use App\Services\Finance\CashRegisterProvisioner;
use Illuminate\Support\Facades\DB;

class BranchObserver
{
    /**
     * Every branch starts with its coffre (treasury); caisses are added by hand.
     */
    public function created(Branch $branch): void
    {
        app(CashRegisterProvisioner::class)->forBranch($branch);

        // The club's first branch adopts every club-wide attendance row, as the
        // migration's backfill does (2026_10_23_100001_add_branch_to_attendance_tables).
        if (Branch::count() === 1) {
            foreach (['training_sessions', 'training_schedules', 'deleted_session_slots', 'preseason_targets'] as $table) {
                DB::table($table)->whereNull('branch_id')->update(['branch_id' => $branch->id]);
            }
        }
    }
}
