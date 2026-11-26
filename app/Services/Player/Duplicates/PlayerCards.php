<?php

namespace App\Services\Player\Duplicates;

use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** What the duplicates pages show of a player, loaded for many players in two queries. */
final class PlayerCards
{
    /**
     * @param  list<int>  $ids
     * @return Collection<int, array{id:int, name:string, membership_ids:list<string>, birthdate:?string, picture_url:?string, archived:bool}>
     */
    public static function for(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $memberships = DB::table('branch_player')
            ->whereIn('player_id', $ids)
            ->whereNotNull('membership_id')
            ->orderBy('branch_id')
            ->get(['player_id', 'membership_id'])
            ->groupBy('player_id');

        return Player::query()
            ->whereIn('id', $ids)
            ->get(['id', 'membership_id', 'birthdate', 'picture_url', 'archived', ...Player::NAME_COLUMNS])
            ->mapWithKeys(fn (Player $p) => [$p->id => [
                'id' => $p->id,
                'name' => $p->fullname,
                'membership_ids' => ($memberships[$p->id] ?? collect())->pluck('membership_id')->map(fn ($m) => (string) $m)->values()->all()
                    ?: array_values(array_filter([(string) $p->membership_id])),
                'birthdate' => $p->birthdate?->toDateString(),
                'picture_url' => $p->picture_url,
                'archived' => (bool) $p->archived,
            ]]);
    }
}
