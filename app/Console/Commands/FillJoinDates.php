<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Services\Player\JoinDateSuggestion;
use App\Services\Player\RegistrationService;
use Illuminate\Console\Command;

/**
 * Owner request 2026-11-08: date the join-dates queue in one go with
 * 1 September of each recorded year (see JoinDateSuggestion). A second branch
 * of a two-branch player (swimming) is left for the admin to date by hand.
 */
class FillJoinDates extends Command
{
    protected $signature = 'players:fill-join-dates {--force : Save the dates (default is a dry run)}';

    protected $description = 'Give registrations with only a join year the date 1 September of that year (dry run unless --force)';

    public function handle(RegistrationService $registrations): int
    {
        $force = (bool) $this->option('force');
        $mainBranchId = Branch::mainId();

        $rows = JoinDateSuggestion::withTwoBranches(BranchRegistration::query()->needsJoinDate()->select('branch_player.*'))
            ->whereHas('player', fn ($q) => $q->where('archived', false))
            ->with('player:id,birthdate')
            ->orderBy('player_id')
            ->orderBy('branch_id')
            ->get();

        $filled = 0;
        $left = 0;
        foreach ($rows as $registration) {
            $date = JoinDateSuggestion::for($registration, $mainBranchId);
            if ($date === null) {
                $left++;

                continue;
            }
            if ($force) {
                $registrations->setJoinDate($registration, $date);
            }
            $filled++;
        }

        $this->info(($force ? 'Dated' : 'Would date')." {$filled} registration(s) with 1 September of their join year.");
        $this->line("{$left} left to date by hand (second branch of a two-branch player, no year, or 1 September before birth or after today).");
        if (! $force && $filled > 0) {
            $this->comment('Dry run: nothing saved. Run again with --force to save.');
        }

        return self::SUCCESS;
    }
}
