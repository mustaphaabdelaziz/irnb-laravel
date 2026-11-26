<?php

namespace App\Services\Attendance;

use App\Models\Branch;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A player attendance card's branch tabs: every branch the player is a
 * member of now or has a mark in (any date, so the tabs do not change with
 * the period), by id; and the tab shown: the asked `branch_id` when it is one
 * of them, else the remembered attendance branch (BranchChoice) when it is,
 * else the first. No tab (a club without branches, or a player in none):
 * null, and the numbers cover every session. Never changes the remembered
 * choice. One query. Shared by the card, its PDFs and the mail register's
 * parent-letter source, so a letter and its register entry name the same tab.
 */
final class PlayerBranchTabs
{
    /** @return array{0: list<array{id: int, name: string}>, 1: ?int} */
    public static function resolve(Request $request, Player $player): array
    {
        $asked = $request->validate(['branch_id' => ['nullable', 'integer']])['branch_id'] ?? null;
        $tabs = Branch::query()
            ->whereIn('id', DB::table('branch_player')->select('branch_id')->where('player_id', $player->id))
            ->orWhereIn('id', DB::table('attendances')
                ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
                ->where('attendances.player_id', $player->id)
                ->whereNotNull('training_sessions.branch_id')
                ->select('training_sessions.branch_id'))
            ->orderBy('id')
            ->get()
            ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->localized_name])
            ->values()
            ->all();
        $ids = array_column($tabs, 'id');

        foreach ([$asked === null ? null : (int) $asked, BranchChoice::remembered($request)] as $candidate) {
            if ($candidate !== null && in_array($candidate, $ids, true)) {
                return [$tabs, $candidate];
            }
        }

        return [$tabs, $ids[0] ?? null];
    }
}
