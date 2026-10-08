<?php

namespace App\Services\Attendance;

use App\Models\Player;

/**
 * Who each attendance row is about: the player's name (Player::fullname),
 * membership id, current category and whether they are still active (not
 * archived, not left). Two queries for any number of rows (the players, then
 * their categories), none for no rows. Shared by the statistics page, the
 * at-risk list, the ranking and the injuries list.
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

        $players = Player::with('category')
            ->whereIn('id', array_unique(array_column($rows, 'player_id')))
            ->get(['id', ...Player::NAME_COLUMNS, 'membership_id', 'category_id', 'archived', 'left_at'])
            ->keyBy('id');

        return array_values(array_map(function (array $row) use ($players): array {
            $player = $players->get($row['player_id']);

            return [
                ...$row,
                'name' => $player?->fullname ?? '#'.$row['player_id'],
                'membership_id' => $player?->membership_id,
                'category_id' => $player?->category_id === null ? null : (int) $player->category_id,
                'category' => $player?->category?->localized_name,
                'active' => $player !== null && ! $player->archived && $player->left_at === null,
            ];
        }, $rows));
    }
}
