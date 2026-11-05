<?php

namespace App\Services\Attendance;

use App\Models\Category;
use App\Models\Player;
use Illuminate\Support\Facades\DB;

/**
 * Who each attendance row is about, in the branch being viewed: the player's
 * name (Player::fullname), and from their registration there the membership id,
 * current category and whether they are still active (not archived, not left
 * that branch). A player with no registration there, or no branch being viewed,
 * shows their own columns, which hold their main registration. Two queries for
 * any number of rows (the players with their registrations, then the
 * categories), none for no rows. Shared by the statistics page, the at-risk
 * list, the ranking and the injuries list.
 *
 * The players are read as plain rows: with a full club, hydrating a model per
 * player took most of the statistics page's time.
 */
final class PlayerNames
{
    /**
     * @param  array<array-key, array{player_id: int, branch_id?: int|null}>  $rows  a row's own branch_id beats $branchId
     * @return list<array<string, mixed>> the rows, in their order, with name, membership_id, category_id, category, active
     */
    public function attach(array $rows, ?int $branchId = null): array
    {
        if ($rows === []) {
            return [];
        }

        $branchOf = fn (array $row): ?int => $row['branch_id'] ?? $branchId;
        $branchIds = collect($rows)->map($branchOf)->filter()->unique()->values()->all();
        $columns = array_map(fn (string $column) => 'players.'.$column, ['id', ...Player::NAME_COLUMNS, 'membership_id', 'category_id', 'archived', 'left_at']);

        // One row per player and branch asked about, in the same query as the player.
        $players = DB::table('players')
            ->whereIn('players.id', array_unique(array_column($rows, 'player_id')))
            ->select($columns)
            ->when($branchIds !== [], fn ($q) => $q
                ->leftJoin('branch_player as r', fn ($join) => $join->on('r.player_id', '=', 'players.id')->whereIn('r.branch_id', $branchIds))
                ->leftJoin('player_statuses as rs', 'rs.id', '=', 'r.status_id')
                ->addSelect(['r.branch_id as reg_branch_id', 'r.category_id as reg_category_id', 'r.membership_id as reg_membership_id', 'rs.code as reg_status_code']))
            ->get()
            ->groupBy('id');

        $categories = Category::whereIn('id', $players->flatten(1)
            ->flatMap(fn ($player) => [$player->category_id, $player->reg_category_id ?? null])
            ->filter()->unique()->values())
            ->get()
            ->mapWithKeys(fn (Category $category) => [$category->id => $category->localized_name]);

        return array_values(array_map(function (array $row) use ($players, $categories, $branchOf): array {
            $found = $players->get($row['player_id']);
            $player = $found?->first();
            $branch = $branchOf($row);
            $registration = $branch === null ? null : $found?->firstWhere('reg_branch_id', $branch);
            $categoryId = $registration !== null ? $registration->reg_category_id : $player?->category_id;
            $hasLeft = $registration !== null ? $registration->reg_status_code === 'left' : $player?->left_at !== null;

            return [
                ...$row,
                'name' => $player === null ? '#'.$row['player_id']
                    : Player::fullnameOf($player->lastname, $player->firstname, $player->nickname, $player->father, $player->grandfather, $player->gender),
                // A registration's id is its own: none of its own shows none. The player's column serves a player with no registration.
                'membership_id' => $registration !== null ? $registration->reg_membership_id : $player?->membership_id,
                'category_id' => $categoryId === null ? null : (int) $categoryId,
                'category' => $categoryId === null ? null : $categories->get((int) $categoryId),
                'active' => $player !== null && ! $player->archived && ! $hasLeft,
            ];
        }, $rows));
    }
}
