<?php

namespace App\Services\Competitions;

use App\Models\Branch;
use App\Models\Competition;
use App\Models\DocumentType;
use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Attendance\InjurySpells;
use App\Services\Player\DocumentChecklist;
use App\Services\Player\RegistrationDisplay;
use Illuminate\Support\Facades\DB;

/**
 * Warning badges shown when picking players. Warnings only — the coach always
 * decides. Phase 1 has "injured" (an injury spell still open today, from the
 * attendance marks); suspended, no licence, documents for matches and unpaid are in for().
 */
final class PlayerFlags
{
    public function __construct(private readonly InjurySpells $spells, private readonly SuspensionStatus $suspensions) {}

    /**
     * Ids of players injured today in any branch: a spell is one branch's
     * (InjurySpells), and a player hurt in either sport gets the warning.
     * A club without branches is one pass with no branch condition.
     *
     * @return array<int, true>
     */
    public function injured(): array
    {
        $today = now()->toDateString();
        $branchIds = Branch::orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all() ?: [null];

        $injured = [];
        foreach ($branchIds as $branchId) {
            foreach ($this->spells->club($today, $today, null, $branchId)['current'] as $row) {
                $injured[$row['player_id']] = true;
            }
        }

        return $injured;
    }

    /**
     * Every badge for a list of players, in one pass, as one team sees them: the
     * licence of the team's branch and that sport's debts. The team is the one
     * given, else the game's or competition's. With none, the main licence and
     * every charge. The owed amount is only given to viewers with finance/view;
     * everyone else just sees "unpaid".
     *
     * @param  list<int>  $playerIds
     * @return array<int, array{injured: bool, suspended: bool, suspension: ?array, no_licence: bool, documents_missing: bool, documents: list<string>, unpaid: bool, debt: ?float}>
     */
    public function for(array $playerIds, ?User $viewer = null, ?Game $game = null, ?Competition $competition = null, ?Team $team = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $playerIds)));
        if ($ids === []) {
            return [];
        }
        $team ??= $game?->team ?? $competition?->team;
        $members = $team === null ? null : TeamMembers::of($team);
        $display = RegistrationDisplay::make();
        $injured = $this->injured();
        $suspended = $this->suspensions->forPlayers($ids, $game, $competition);
        $branchId = $team === null ? null : (int) $team->branch_id;
        $owed = $this->owedFor($ids, $branchId);
        $types = DocumentType::query()->where('is_active', true)->where('required_for_matches', true)
            ->orderBy('sort_order')->orderBy('id')->get();

        $query = Player::query()->whereIn('id', $ids)->select(['id', 'licence_number'])->with('registrations');
        foreach ($types as $type) {
            [$sql, $bindings] = DocumentChecklist::missingTypeSql($type);
            $query->selectRaw("{$sql} as doc_{$type->id}", $bindings);
        }
        $seeMoney = (bool) $viewer?->hasPermission('finance', 'view');

        $flags = [];
        foreach ($query->get() as $player) {
            $documents = $types->filter(fn (DocumentType $t) => (int) $player->{'doc_'.$t->id} === 1)
                ->map(fn (DocumentType $t) => (string) ($t->localized_name ?? $t->name))->values()->all();
            $licence = $members !== null ? $members->licence($player) : $display->values($player)['licence_number'];
            $debt = round($owed[$player->id] ?? 0.0, 2);
            $flags[$player->id] = [
                'injured' => isset($injured[$player->id]),
                'suspended' => isset($suspended[$player->id]),
                'suspension' => $suspended[$player->id] ?? null,
                'no_licence' => trim((string) $licence) === '',
                'documents_missing' => $documents !== [],
                'documents' => $documents,
                'unpaid' => $debt > 0,
                'debt' => $seeMoney && $debt > 0 ? $debt : null,
            ];
        }

        return $flags;
    }

    /**
     * What each player still owes a sport: charges of subscriptions with that
     * branch ticked, or none ticked. A deleted subscription has none ticked: its
     * sport can't be known and the money is still owed. Leaving a branch cancels nothing.
     *
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private function owedFor(array $ids, ?int $branchId): array
    {
        $ticked = fn ($q) => $q->selectRaw('1')->from('branch_subscription')
            ->whereColumn('branch_subscription.subscription_id', 'player_subscriptions.subscription_id');

        $owed = [];
        DB::table('player_subscriptions')->whereIn('player_id', $ids)
            ->when($branchId !== null, fn ($q) => $q->where(fn ($sport) => $sport
                ->whereNotExists($ticked)
                ->orWhereExists(fn ($t) => $ticked($t)->where('branch_subscription.branch_id', $branchId))))
            ->get(['player_id', 'amount_owed', 'amount_paid', 'is_exempt', 'discount_type', 'discount_value'])
            ->each(function ($row) use (&$owed) {
                $money = PlayerSubscription::moneyFor($row->amount_owed, $row->amount_paid, (bool) $row->is_exempt, $row->discount_type, $row->discount_value);
                $owed[(int) $row->player_id] = ($owed[(int) $row->player_id] ?? 0.0) + $money['remaining'];
            });

        return $owed;
    }
}
