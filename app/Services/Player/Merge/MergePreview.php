<?php

namespace App\Services\Player\Merge;

use App\Models\Player;
use Illuminate\Support\Facades\DB;

/** How many of the duplicate's records move to the kept player. */
final class MergePreview
{
    /** Every foreign key to players. A test compares this list with the live schema. */
    public const FOREIGN_KEYS = [
        'player_emergency_contacts.player_id', 'player_achievements.player_id', 'player_subscriptions.player_id',
        'board_members.player_id', 'player_other_positions.player_id', 'player_academic_years.player_id',
        'player_documents.player_id', 'attendances.player_id', 'injury_notes.player_id', 'team_players.player_id',
        'competition_players.player_id', 'game_players.player_id', 'game_events.player_id',
        'game_events.related_player_id', 'suspensions.player_id', 'activity_player.player_id',
        'branch_player.player_id', 'training_group_members.player_id',
        'player_duplicate_decisions.player_a_id', 'player_duplicate_decisions.player_b_id',
        'player_duplicate_decisions.kept_player_id',
    ];

    /** @return array<string, int> table => rows that move (non-zero only) */
    public function counts(int $duplicate): array
    {
        $counts = [];
        foreach (self::FOREIGN_KEYS as $key) {
            [$table, $column] = explode('.', $key);
            if ($table === 'player_duplicate_decisions') {
                continue;
            }
            $count = DB::table($table)->where($column, $duplicate)->count();
            if ($count > 0) {
                $counts[$table] = ($counts[$table] ?? 0) + $count;
            }
        }

        $payments = DB::table('transactions')->whereIn('related_entity_type', ['Player', Player::class])->where('related_entity_id', $duplicate)->count();
        if ($payments > 0) {
            $counts['transactions'] = $payments;
        }
        $loans = DB::table('equipment_rentals')->where('rentable_type', Player::class)->where('rentable_id', $duplicate)->count();
        if ($loans > 0) {
            $counts['equipment_rentals'] = $loans;
        }

        return $counts;
    }
}
