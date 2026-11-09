<?php

namespace App\Services\Player;

use App\Models\Branch;
use App\Models\BranchRegistration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 1 September of the recorded year, the start of a season, for a registration
 * with no exact join date. Offered for the main branch (football) and for a
 * player's only branch; a second branch of a two-branch player is dated by hand.
 * Never before birth or after today.
 */
class JoinDateSuggestion
{
    /** Adds `two_branches` to a branch_player query. */
    public static function withTwoBranches(Builder $query): Builder
    {
        return $query->addSelect(['two_branches' => DB::table('branch_player as other')
            ->selectRaw('COUNT(*) > 1')
            ->whereColumn('other.player_id', 'branch_player.player_id')]);
    }

    /**
     * Dates every queued registration (not archived) that has a suggestion.
     * With $save false it only counts. Used by players:fill-join-dates and the
     * one-time migration that reaches desktop installs and the server.
     *
     * @return array{filled: int, left: int}
     */
    public static function fillAll(RegistrationService $registrations, bool $save = true): array
    {
        $mainBranchId = Branch::mainId();
        $rows = self::withTwoBranches(BranchRegistration::query()->needsJoinDate()->select('branch_player.*'))
            ->whereHas('player', fn ($q) => $q->where('archived', false))
            ->with('player:id,birthdate')
            ->orderBy('player_id')
            ->orderBy('branch_id')
            ->get();

        $filled = 0;
        $left = 0;
        foreach ($rows as $registration) {
            $date = self::for($registration, $mainBranchId);
            if ($date === null) {
                $left++;

                continue;
            }
            if ($save) {
                $registrations->setJoinDate($registration, $date);
            }
            $filled++;
        }

        return ['filled' => $filled, 'left' => $left];
    }

    /** Needs the registration's `two_branches` and its player's birthdate loaded. */
    public static function for(BranchRegistration $registration, ?int $mainBranchId): ?string
    {
        $year = $registration->legacy_join_year;
        if ($year === null) {
            return null;
        }
        if ($registration->two_branches && (int) $registration->branch_id !== $mainBranchId) {
            return null;
        }

        $date = sprintf('%04d-09-01', $year);
        $birthdate = $registration->player->birthdate?->toDateString();
        if ($date > now()->toDateString() || ($birthdate !== null && $date < $birthdate)) {
            return null;
        }

        return $date;
    }
}
