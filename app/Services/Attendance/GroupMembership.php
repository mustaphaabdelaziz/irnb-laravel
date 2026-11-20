<?php

namespace App\Services\Attendance;

use App\Models\TrainingGroup;
use App\Models\TrainingGroupMember;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of training_group_members. A player has at most one
 * membership open on any date inside one branch.
 *
 * Every change takes the day it applies from ('Y-m-d'), or null = "from the
 * beginning": the first setup, and the way to fix a mistake. A change from a
 * date keeps the history before it, so unsaved sessions before that date
 * still list the player in the old group. Saved sessions are never touched:
 * their roster is their marks.
 */
final class GroupMembership
{
    /** Puts the player in the group from $from on, taking them out of any other group of the same branch. */
    public function add(TrainingGroup $group, int $playerId, ?string $from): void
    {
        DB::transaction(function () use ($group, $playerId, $from) {
            $inBranch = TrainingGroupMember::where('player_id', $playerId)
                ->whereIn('training_group_id', $this->branchGroupIds($group->branch_id));

            if ($from === null) {
                $inBranch->delete();
                $this->open($group, $playerId, null);

                return;
            }

            $rows = $inBranch->get();
            $already = $rows->contains(fn (TrainingGroupMember $m) => $m->training_group_id === $group->id
                && $m->to_date === null
                && ($m->from_date === null || $m->from_date <= $from));
            if ($already) {
                return;
            }

            $this->close($rows, $from);
            $this->open($group, $playerId, $from);
        });
    }

    /** Takes the player out of the group from $from on; null deletes the membership altogether. */
    public function remove(TrainingGroup $group, int $playerId, ?string $from): void
    {
        DB::transaction(function () use ($group, $playerId, $from) {
            $own = TrainingGroupMember::where('player_id', $playerId)->where('training_group_id', $group->id);

            if ($from === null) {
                $own->delete();

                return;
            }
            $this->close($own->get(), $from);
        });
    }

    /** Ends the player's membership in every group of the branch from $from on (their category changed). */
    public function closeInBranch(int $playerId, ?int $branchId, string $from): void
    {
        DB::transaction(fn () => $this->close(
            TrainingGroupMember::where('player_id', $playerId)->whereIn('training_group_id', $this->branchGroupIds($branchId))->get(),
            $from,
        ));
    }

    /**
     * @param  array<int, int>  $groupIds
     * @return array<int, int> player id => group id, for the members of these groups on $date
     */
    public function currentGroupIds(array $groupIds, string $date): array
    {
        if ($groupIds === []) {
            return [];
        }

        return TrainingGroupMember::whereIn('training_group_id', $groupIds)
            ->activeOn($date)
            ->orderBy('id')
            ->get(['player_id', 'training_group_id'])
            ->mapWithKeys(fn (TrainingGroupMember $m) => [$m->player_id => $m->training_group_id])
            ->all();
    }

    /**
     * The group the player is a member of on $date, in each branch (the player
     * form's branch cards, the player page).
     *
     * @return array<int, TrainingGroup> by branch id (0 = the club-wide groups)
     */
    public function groupsOf(int $playerId, string $date): array
    {
        return TrainingGroup::whereIn('id', TrainingGroupMember::where('player_id', $playerId)->activeOn($date)->select('training_group_id'))
            ->orderBy('id')
            ->get()
            ->keyBy(fn (TrainingGroup $group) => (int) $group->branch_id)
            ->all();
    }

    private function open(TrainingGroup $group, int $playerId, ?string $from): void
    {
        TrainingGroupMember::create(['training_group_id' => $group->id, 'player_id' => $playerId, 'from_date' => $from, 'to_date' => null]);
    }

    /**
     * Ends each row the day before $from. A row that ended earlier stays; one
     * that starts on $from or later would end before it starts, so it goes.
     *
     * @param  iterable<TrainingGroupMember>  $rows
     */
    private function close(iterable $rows, string $from): void
    {
        $before = CarbonImmutable::createFromFormat('!Y-m-d', $from)->subDay()->toDateString();

        foreach ($rows as $row) {
            if ($row->to_date !== null && $row->to_date < $from) {
                continue;
            }
            if ($row->from_date !== null && $row->from_date >= $from) {
                $row->delete();

                continue;
            }
            $row->update(['to_date' => $before]);
        }
    }

    /** @return array<int, int> every group of the branch (null = the club-wide groups) */
    private function branchGroupIds(?int $branchId): array
    {
        return TrainingGroup::where('branch_id', $branchId)->pluck('id')->all(); // null → whereNull
    }
}
