<?php

namespace App\Services\Player;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Old IDs (202200017) become FB-2022-00017. A separate reviewed action, since
 * the branch codes must be chosen first. Can run again, and can be undone from
 * legacy_membership_id.
 */
class MembershipIdConversion
{
    public function __construct(private readonly RegistrationService $registrations) {}

    /**
     * IDs whose year already matches go first, so they keep their numbers; then
     * those whose year changes; rows with no ID last, taking fresh numbers.
     *
     * @return array{changes: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function plan(): array
    {
        $rows = BranchRegistration::query()
            ->with(['branch:id,code,name,name_ar,name_fr,name_en', 'player:id,'.implode(',', Player::NAME_COLUMNS)])
            ->orderBy('id')
            ->get();

        $taken = $rows->pluck('membership_id')->filter(fn ($id) => $id !== null && ! MembershipNumber::isLegacy($id))->flip()->all();
        $changes = [];
        $skipped = [];

        $pending = $rows->filter(fn (BranchRegistration $r) => $r->membership_id === null || MembershipNumber::isLegacy($r->membership_id));

        foreach ($pending->sortBy(fn (BranchRegistration $r) => [$this->phase($r), $r->id]) as $row) {
            $year = $row->effectiveYear();
            if ($year === null) {
                $skipped[] = ['registration_id' => $row->id, 'player_name' => $row->player->fullname];

                continue;
            }

            $to = $this->newId($row, $year, $taken);
            $taken[$to] = true;
            $changes[] = [
                'registration_id' => $row->id,
                'player_id' => $row->player_id,
                'player_name' => $row->player->fullname,
                'branch' => $row->branch->localized_name,
                'from' => $row->membership_id,
                'to' => $to,
                'year_changed' => $row->membership_id !== null && substr($row->membership_id, 0, 4) !== sprintf('%04d', $year),
            ];
        }

        return ['changes' => $changes, 'skipped' => $skipped];
    }

    public function convert(?User $user): int
    {
        $changes = $this->plan()['changes'];

        DB::transaction(function () use ($changes, $user) {
            foreach ($changes as $change) {
                BranchRegistration::whereKey($change['registration_id'])->update([
                    'membership_id' => $change['to'],
                    'legacy_membership_id' => $change['from'],
                ]);
            }
            Player::whereIn('id', array_unique(array_column($changes, 'player_id')))->get()
                ->each(fn (Player $player) => $this->registrations->refreshMirror($player));

            if ($changes !== []) {
                ActivityRecorder::record($user, ActivityAction::MEMBERSHIP_IDS_CONVERTED, null, ['count' => count($changes)]);
            }
        });

        return count($changes);
    }

    /** Rows that held no old ID keep their new one: it is still valid. */
    public function undo(?User $user): int
    {
        $rows = BranchRegistration::whereNotNull('legacy_membership_id')->get();

        DB::transaction(function () use ($rows, $user) {
            foreach ($rows as $row) {
                BranchRegistration::whereKey($row->id)->update([
                    'membership_id' => $row->legacy_membership_id,
                    'legacy_membership_id' => null,
                ]);
            }
            Player::whereIn('id', $rows->pluck('player_id')->unique())->get()
                ->each(fn (Player $player) => $this->registrations->refreshMirror($player));

            if ($rows->isNotEmpty()) {
                ActivityRecorder::record($user, ActivityAction::MEMBERSHIP_IDS_CONVERTED, null, ['count' => $rows->count(), 'undone' => true]);
            }
        });

        return $rows->count();
    }

    private function phase(BranchRegistration $row): int
    {
        if ($row->membership_id === null) {
            return 2;
        }

        return substr($row->membership_id, 0, 4) === sprintf('%04d', (int) $row->effectiveYear()) ? 0 : 1;
    }

    /** @param  array<string, bool>  $taken */
    private function newId(BranchRegistration $row, int $year, array $taken): string
    {
        $code = $row->branch->code;

        if ($row->membership_id !== null && preg_match('/^\d{9}$/', $row->membership_id) === 1) {
            $kept = MembershipNumber::compose($code, $year, (int) substr($row->membership_id, 4));
            if (! isset($taken[$kept])) {
                return $kept;
            }
        }

        $prefix = sprintf('%s-%04d-', $code, $year);
        $max = 0;
        foreach (array_keys($taken) as $id) {
            if (str_starts_with($id, $prefix)) {
                $max = max($max, (int) substr($id, strlen($prefix)));
            }
        }

        return MembershipNumber::compose($code, $year, $max + 1);
    }
}
