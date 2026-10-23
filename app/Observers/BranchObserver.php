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
        // migrations' backfills do (2026_10_23_100001_add_branch_to_attendance_tables,
        // 2026_10_23_300001_add_branch_to_injury_notes).
        if (Branch::count() === 1) {
            foreach (['training_sessions', 'training_schedules', 'deleted_session_slots', 'preseason_targets', 'injury_notes'] as $table) {
                DB::table($table)->whereNull('branch_id')->update(['branch_id' => $branch->id]);
            }
        }
    }
}
