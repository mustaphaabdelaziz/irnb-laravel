<?php

use App\Services\Finance\SeasonRebucketer;
use Illuminate\Database\Migrations\Migration;

/**
 * Fiscal years become seasons (spec 2026-10-14, P1). Re-keys existing
 * transactions by their date and gives fiscal-year rows season dates.
 * Not reversible: the old calendar keys are not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(SeasonRebucketer::class)->run();
    }

    public function down(): void
    {
        // Intentionally empty — calendar keys cannot be reconstructed safely.
    }
};
