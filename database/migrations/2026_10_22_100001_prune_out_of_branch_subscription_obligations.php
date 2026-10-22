<?php

use App\Models\Subscription;
use App\Services\Finance\PruneOutOfBranchObligations;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * A branch subscription is now only for that branch's players. Obligations
     * already given to players outside its branches are removed while still
     * untouched (unpaid, not exempt, no payment); paid or partly paid ones stay.
     * Runs on desktop upgrades via migrate-on-boot.
     */
    public function up(): void
    {
        $prune = app(PruneOutOfBranchObligations::class);

        Subscription::query()->whereHas('branches')->each(fn (Subscription $subscription) => $prune->forSubscription($subscription));
    }

    public function down(): void
    {
        // Removed obligations are not restored: they held no payment.
    }
};
