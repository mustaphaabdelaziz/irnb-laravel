<?php

namespace App\Services\Attendance;

use App\Models\TrainingGroup;
use Illuminate\Http\Request;

/**
 * The training group a report page is narrowed to (statistics, ranking and
 * certificates, players at risk, and their exports): `group_id` in the query,
 * when it is one of the groups of the page's branch + category. Anything
 * else — no value, an unknown id, a group of another category or branch, a
 * category without groups, no category — means all groups: the numbers the
 * report had before groups existed.
 *
 * Unlike the month grid's switch (MonthSheet::groupSwitch()) there is no
 * entry for the sessions with no group: "all groups" already counts them.
 */
final class GroupChoice
{
    /**
     * A `group_id` that is not an integer fails validation; an integer that is
     * not one of the options is ignored.
     *
     * @return array{options: list<array{id: int, name: string}>, selected: ?int}
     */
    public static function fromRequest(Request $request, ?int $categoryId, ?int $branchId): array
    {
        $data = $request->validate(['group_id' => ['nullable', 'integer']]);
        $options = self::options($categoryId, $branchId);
        $asked = isset($data['group_id']) ? (int) $data['group_id'] : null;

        return [
            'options' => $options,
            'selected' => in_array($asked, array_column($options, 'id'), true) ? $asked : null,
        ];
    }

    /**
     * The groups of one branch + category (a null branch = the club-wide
     * groups), by name. No category: none, and no query.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function options(?int $categoryId, ?int $branchId): array
    {
        if ($categoryId === null) {
            return [];
        }

        return TrainingGroup::options($categoryId)
            ->filter(fn (array $group) => $group['branch_id'] === $branchId)
            ->map(fn (array $group) => ['id' => $group['id'], 'name' => $group['name']])
            ->values()
            ->all();
    }
}
