<?php

namespace App\Services\Attendance;

use App\Models\Player;
use App\Models\TrainingGroup;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;

/**
 * The training groups as the settings page shows them: every group with how
 * many roster players it holds on the day, and, for each branch + category
 * that has groups, its roster with each player's group. A player with no
 * group there is waiting: no group session lists them. Read-only.
 */
final class GroupBoard
{
    public function __construct(
        private readonly Roster $roster,
        private readonly GroupMembership $membership,
    ) {}

    /** @return array{groups: list<array<string, mixed>>, rosters: array<string, list<array<string, mixed>>>} */
    public function build(string $date): array
    {
        $groups = TrainingGroup::orderBy('branch_id')->orderBy('category_id')->orderBy('name')->orderBy('id')->get();
        if ($groups->isEmpty()) {
            return ['groups' => [], 'rosters' => []];
        }

        $used = array_flip([
            ...TrainingSchedule::whereNotNull('group_id')->distinct()->pluck('group_id')->all(),
            ...TrainingSession::whereNotNull('group_id')->distinct()->pluck('group_id')->all(),
        ]);
        $current = $this->membership->currentGroupIds($groups->modelKeys(), $date);

        $rosters = [];
        $counts = [];
        foreach ($groups->groupBy(fn (TrainingGroup $g) => self::key($g->branch_id, $g->category_id)) as $key => $siblings) {
            $first = $siblings->first();
            $own = array_flip($siblings->modelKeys());

            $rosters[$key] = $this->roster->expected($first->category_id, $date, null, $first->branch_id)
                ->map(function (Player $player) use ($current, $own, &$counts) {
                    // A membership left over from another category does not count here.
                    $groupId = isset($current[$player->id], $own[$current[$player->id]]) ? $current[$player->id] : null;
                    if ($groupId !== null) {
                        $counts[$groupId] = ($counts[$groupId] ?? 0) + 1;
                    }

                    return ['id' => $player->id, 'name' => $player->fullname, 'group_id' => $groupId];
                })
                ->values()->all();
        }

        return [
            'groups' => $groups->map(fn (TrainingGroup $g) => [
                'id' => $g->id,
                'branch_id' => $g->branch_id,
                'category_id' => $g->category_id,
                'name' => $g->name,
                'key' => self::key($g->branch_id, $g->category_id),
                'members' => $counts[$g->id] ?? 0,
                'in_use' => isset($used[$g->id]),
            ])->values()->all(),
            'rosters' => $rosters,
        ];
    }

    /** One branch + category, as the page keys its rosters (0 = the whole club). */
    public static function key(?int $branchId, int $categoryId): string
    {
        return ((int) $branchId).':'.$categoryId;
    }
}
