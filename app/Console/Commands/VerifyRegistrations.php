<?php

namespace App\Console\Commands;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Services\Player\RegistrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Read-only checks on real data, before and after a release. */
class VerifyRegistrations extends Command
{
    protected $signature = 'registrations:verify';

    protected $description = 'Check that registrations and the player copy agree';

    public function handle(RegistrationService $registrations): int
    {
        $failures = 0;

        $this->line('registrations: '.BranchRegistration::count());
        $this->line('needing a join date: '.BranchRegistration::needsJoinDate()->count());
        $this->line('no branch: '.Player::whereDoesntHave('registrations')->count());
        $this->line('no ID yet: '.BranchRegistration::whereNull('membership_id')->count());
        $this->line('old-format IDs: '.BranchRegistration::whereNotNull('membership_id')->where('membership_id', 'not like', '%-%')->count());

        $duplicateIds = DB::table('branch_player')->whereNotNull('membership_id')
            ->groupBy('membership_id')->havingRaw('COUNT(*) > 1')->pluck('membership_id');
        $failures += $this->report('duplicate IDs', $duplicateIds->all());

        $earliest = fn (Player $player) => $player->registrations->map(fn ($r) => $r->effectiveYear())->filter()->min();

        $copyErrors = [];
        $yearErrors = [];
        Player::query()->whereHas('registrations')->with('registrations')->chunkById(200, function ($players) use ($registrations, $earliest, &$copyErrors, &$yearErrors) {
            foreach ($players as $player) {
                $primary = $registrations->primary($player);

                foreach (['category_id', 'position_id', 'licence_number'] as $field) {
                    if ((string) $player->{$field} !== (string) $primary->{$field}) {
                        $copyErrors[] = "player {$player->id}: {$field}";
                    }
                }
                if ($primary->membership_id !== null && $player->membership_id !== $primary->membership_id) {
                    $copyErrors[] = "player {$player->id}: membership_id";
                }

                $year = $earliest($player);
                if ($year !== null && (int) $player->join_year !== (int) $year) {
                    $yearErrors[] = "player {$player->id}: {$player->join_year} vs {$year}";
                }
            }
        });

        $failures += $this->report('player copy differs from primary', $copyErrors);
        $failures += $this->report('join year differs', $yearErrors);

        $this->line($failures === 0 ? 'OK' : "FAILED: {$failures} check(s)");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param  list<string>  $problems */
    private function report(string $label, array $problems): int
    {
        if ($problems === []) {
            return 0;
        }

        $this->error($label.': '.count($problems));
        foreach (array_slice($problems, 0, 20) as $problem) {
            $this->line('  '.$problem);
        }

        return 1;
    }
}
