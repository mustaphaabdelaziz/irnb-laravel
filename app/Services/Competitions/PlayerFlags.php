<?php

namespace App\Services\Competitions;

use App\Models\Competition;
use App\Models\DocumentType;
use App\Models\Game;
use App\Models\Player;
use App\Models\User;
use App\Services\Attendance\InjurySpells;
use App\Services\Player\DocumentChecklist;

/**
 * Warning badges shown when picking players. Warnings only — the coach always
 * decides. Phase 1 has "injured" (an injury spell still open today, from the
 * attendance marks); suspended, no licence, documents for matches and unpaid are in for().
 */
final class PlayerFlags
{
    public function __construct(private readonly InjurySpells $spells, private readonly SuspensionStatus $suspensions) {}

    /** @return array<int, true> ids of players injured today */
    public function injured(): array
    {
        $today = now()->toDateString();

        return array_fill_keys(array_column($this->spells->club($today, $today)['current'], 'player_id'), true);
    }

    /**
     * Every badge for a list of players, in one pass. The owed amount is only
     * given to viewers with finance/view; everyone else just sees "unpaid".
     *
     * @param  list<int>  $playerIds
     * @return array<int, array{injured: bool, suspended: bool, suspension: ?array, no_licence: bool, documents_missing: bool, documents: list<string>, unpaid: bool, debt: ?float}>
     */
    public function for(array $playerIds, ?User $viewer = null, ?Game $game = null, ?Competition $competition = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $playerIds)));
        if ($ids === []) {
            return [];
        }
        $injured = $this->injured();
        $suspended = $this->suspensions->forPlayers($ids, $game, $competition);
        $types = DocumentType::query()->where('is_active', true)->where('required_for_matches', true)
            ->orderBy('sort_order')->orderBy('id')->get();

        $query = Player::query()->whereIn('id', $ids)->select(['id', 'licence_number', 'outstanding_debt']);
        foreach ($types as $type) {
            [$sql, $bindings] = DocumentChecklist::missingTypeSql($type);
            $query->selectRaw("{$sql} as doc_{$type->id}", $bindings);
        }
        $seeMoney = (bool) $viewer?->hasPermission('finance', 'view');

        $flags = [];
        foreach ($query->get() as $player) {
            $documents = $types->filter(fn (DocumentType $t) => (int) $player->{'doc_'.$t->id} === 1)
                ->map(fn (DocumentType $t) => (string) ($t->localized_name ?? $t->name))->values()->all();
            $debt = (float) $player->outstanding_debt;
            $flags[$player->id] = [
                'injured' => isset($injured[$player->id]),
                'suspended' => isset($suspended[$player->id]),
                'suspension' => $suspended[$player->id] ?? null,
                'no_licence' => trim((string) $player->licence_number) === '',
                'documents_missing' => $documents !== [],
                'documents' => $documents,
                'unpaid' => $debt > 0,
                'debt' => $seeMoney && $debt > 0 ? $debt : null,
            ];
        }

        return $flags;
    }
}
