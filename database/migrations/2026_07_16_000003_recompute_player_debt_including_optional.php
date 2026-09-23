<?php

use App\Models\Player;
use App\Services\Finance\RecalculatePlayerDebtService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Optional subscriptions now count toward a player's debt: assigning an
     * obligation means it is owed, mandatory or not. Every outstanding_debt
     * cached under the old (mandatory-only) rule is therefore stale, so
     * recompute them all. Runs on desktop upgrades via migrate-on-boot.
     */
    public function up(): void
    {
        $debt = app(RecalculatePlayerDebtService::class);

        Player::query()->chunkById(200, function ($players) use ($debt) {
            foreach ($players as $player) {
                $debt->forPlayer($player);
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo: the cache is derived and is rebuilt by whichever
        // rule Player::calculateTotalDebt() currently implements.
    }
};
