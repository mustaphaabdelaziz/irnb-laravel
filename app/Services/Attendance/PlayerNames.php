<?php

namespace App\Services\Attendance;

use App\Models\Category;
use App\Models\Player;
use Illuminate\Support\Facades\DB;

/**
 * Who each attendance row is about: the player's name (Player::fullname),
 * membership id, current category and whether they are still active (not
 * archived, not left). Two queries for any number of rows (the players, then
 * their categories), none for no rows. Shared by the statistics page, the
 * at-risk list, the ranking and the injuries list.
 *
 * The players are read as plain rows: with a full club, hydrating a model per
 * player took most of the statistics page's time.
 */
final class PlayerNames
{
    /**
     * @param  array<array-key, array{player_id: int}>  $rows
     * @return list<array<string, mixed>> the rows, in their order, with name, membership_id, category_id, category, active
     */
    public function attach(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $players = DB::table('players')
            ->whereIn('id', array_unique(array_column($rows, 'player_id')))
            ->get(['id', ...Player::NAME_COLUMNS, 'membership_id', 'category_id', 'archived', 'left_at'])
            ->keyBy('id');

        $categories = Category::whereIn('id', $players->pluck('category_id')->filter()->unique()->values())
            ->get()
            ->mapWithKeys(fn (Category $category) => [$category->id => $category->localized_name]);

        return array_values(array_map(function (array $row) use ($players, $categories): array {
            $player = $players->get($row['player_id']);

            return [
                ...$row,
                'name' => $player === null ? '#'.$row['player_id']
                    : Player::fullnameOf($player->lastname, $player->firstname, $player->nickname, $player->father, $player->grandfather, $player->gender),
                'membership_id' => $player?->membership_id,
                'category_id' => $player?->category_id === null ? null : (int) $player->category_id,
                'category' => $player?->category_id === null ? null : $categories->get((int) $player->category_id),
                'active' => $player !== null && ! $player->archived && $player->left_at === null,
            ];
        }, $rows));
    }
}
