<?php

namespace App\Console\Commands;

use App\Services\Player\JoinDateSuggestion;
use App\Services\Player\RegistrationService;
use Illuminate\Console\Command;

/**
 * Owner request 2026-11-08: date the join-dates queue in one go with
 * 1 September of each recorded year (see JoinDateSuggestion). A second branch
 * of a two-branch player (swimming) is left for the admin to date by hand.
 * The 2026_11_09 migration runs the same fill once on every database.
 */
class FillJoinDates extends Command
{
    protected $signature = 'players:fill-join-dates {--force : Save the dates (default is a dry run)}';

    protected $description = 'Give registrations with only a join year the date 1 September of that year (dry run unless --force)';

    public function handle(RegistrationService $registrations): int
    {
        $force = (bool) $this->option('force');
        ['filled' => $filled, 'left' => $left] = JoinDateSuggestion::fillAll($registrations, $force);

        $this->info(($force ? 'Dated' : 'Would date')." {$filled} registration(s) with 1 September of their join year.");
        $this->line("{$left} left to date by hand (second branch of a two-branch player, no year, or 1 September before birth or after today).");
        if (! $force && $filled > 0) {
            $this->comment('Dry run: nothing saved. Run again with --force to save.');
        }

        return self::SUCCESS;
    }
}
