<?php

namespace App\Services\Competitions;

use App\Services\Attendance\InjurySpells;

/**
 * Warning badges shown when picking players. Warnings only — the coach always
 * decides. Phase 1 has "injured" (an injury spell still open today, from the
 * attendance marks); phase 3 adds suspended, licence, documents and unpaid.
 */
final class PlayerFlags
{
    public function __construct(private readonly InjurySpells $spells) {}

    /** @return array<int, true> ids of players injured today */
    public function injured(): array
    {
        $today = now()->toDateString();

        return array_fill_keys(array_column($this->spells->club($today, $today)['current'], 'player_id'), true);
    }
}
