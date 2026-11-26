<?php

namespace App\Services\Player\Merge;

use App\Models\PlayerDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Records both players hold that may exist only once, and which one stays
 * (owner's smart rules, 2026-11-26). The one source for the merge preview and
 * the merge itself. keeper_row / duplicate_row are ids in the kind's table.
 */
final class MergeClashes
{
    /** Kind => table holding the clashing rows. */
    public const TABLES = [
        'document' => 'player_documents',
        'registration' => 'branch_player',
        'attendance' => 'attendances',
        'team' => 'team_players',
        'competition' => 'competition_players',
        'game' => 'game_players',
        'injury' => 'injury_notes',
        'academic_year' => 'player_academic_years',
        'subscription' => 'player_subscriptions',
    ];

    /** @return list<array{kind:string, label:string, keeper_row:int, duplicate_row:int, winner:string}> */
    public function find(int $keeper, int $duplicate): array
    {
        $registrations = $this->registrations($keeper, $duplicate);

        return [
            ...$this->documents($keeper, $duplicate, $this->registrationMap($registrations)),
            ...$registrations,
            ...$this->attendances($keeper, $duplicate),
            ...$this->teams($keeper, $duplicate),
            ...$this->competitions($keeper, $duplicate),
            ...$this->games($keeper, $duplicate),
            ...$this->injuries($keeper, $duplicate),
            ...$this->academicYears($keeper, $duplicate),
            ...$this->subscriptions($keeper, $duplicate),
        ];
    }

    /** @return array<int, int> loser registration id => winner registration id */
    public function registrationMap(array $clashes): array
    {
        $map = [];
        foreach ($clashes as $clash) {
            if ($clash['kind'] !== 'registration') {
                continue;
            }
            $keeperWins = $clash['winner'] === 'keeper';
            $map[$keeperWins ? $clash['duplicate_row'] : $clash['keeper_row']] = $keeperWins ? $clash['keeper_row'] : $clash['duplicate_row'];
        }

        return $map;
    }

    /** Same branch: the earliest join wins (its membership ID stays). */
    private function registrations(int $keeper, int $duplicate): array
    {
        $names = DB::table('branches')->pluck('name', 'id');

        return $this->pairUp(
            DB::table('branch_player')->whereIn('player_id', [$keeper, $duplicate])->get(['id', 'player_id', 'branch_id', 'join_date', 'legacy_join_year']),
            $keeper,
            fn (object $row) => (string) $row->branch_id,
            fn (object $mine, object $theirs) => $this->joinedOn($theirs) < $this->joinedOn($mine),
            'registration',
            fn (object $row) => (string) ($names[$row->branch_id] ?? ''),
        );
    }

    /** Same registration (after merging registrations) and type: received beats exempt beats missing. */
    private function documents(int $keeper, int $duplicate, array $registrationMap): array
    {
        $names = DB::table('document_types')->pluck('name', 'id');

        return $this->pairUp(
            DB::table('player_documents')->whereIn('player_id', [$keeper, $duplicate])->get(['id', 'player_id', 'document_type_id', 'state', 'branch_registration_id']),
            $keeper,
            fn (object $row) => ($registrationMap[$row->branch_registration_id] ?? $row->branch_registration_id ?? 'none').':'.$row->document_type_id,
            fn (object $mine, object $theirs) => $this->documentRank($theirs->state) > $this->documentRank($mine->state),
            'document',
            fn (object $row) => (string) ($names[$row->document_type_id] ?? ''),
        );
    }

    private function attendances(int $keeper, int $duplicate): array
    {
        $rows = DB::table('attendances')->whereIn('player_id', [$keeper, $duplicate])->get(['id', 'player_id', 'training_session_id', 'status']);
        $dates = DB::table('training_sessions')->whereIn('id', $rows->pluck('training_session_id')->unique())->pluck('date', 'id');

        return $this->pairUp(
            $rows,
            $keeper,
            fn (object $row) => (string) $row->training_session_id,
            fn (object $mine, object $theirs) => blank($mine->status) && filled($theirs->status),
            'attendance',
            fn (object $row) => substr((string) ($dates[$row->training_session_id] ?? ''), 0, 10),
        );
    }

    /** Same team and season: the keeper's row, unless it has no shirt number and the duplicate's has. */
    private function teams(int $keeper, int $duplicate): array
    {
        $names = DB::table('teams')->pluck('name', 'id');

        return $this->pairUp(
            DB::table('team_players')->whereIn('player_id', [$keeper, $duplicate])->get(['id', 'player_id', 'team_id', 'season_start_year', 'shirt_number']),
            $keeper,
            fn (object $row) => $row->team_id.':'.$row->season_start_year,
            fn (object $mine, object $theirs) => $mine->shirt_number === null && $theirs->shirt_number !== null,
            'team',
            fn (object $row) => ($names[$row->team_id] ?? '').' '.$row->season_start_year,
        );
    }

    /** competition_players holds only a `hidden` flag, so a row is never "empty": the keeper's row always wins. */
    private function competitions(int $keeper, int $duplicate): array
    {
        $names = DB::table('competitions')->pluck('name', 'id');

        return $this->pairUp(
            DB::table('competition_players')->whereIn('player_id', [$keeper, $duplicate])->get(['id', 'player_id', 'competition_id']),
            $keeper,
            fn (object $row) => (string) $row->competition_id,
            fn () => false,
            'competition',
            fn (object $row) => (string) ($names[$row->competition_id] ?? ''),
        );
    }

    /** Same match: the keeper's row, unless it is empty (not called up, no minutes, no rating) and the duplicate's is not. */
    private function games(int $keeper, int $duplicate): array
    {
        $rows = DB::table('game_players')->whereIn('player_id', [$keeper, $duplicate])->get(['id', 'player_id', 'game_id', 'called_up', 'played', 'minutes_played', 'rating', 'role', 'position_id', 'shirt_number', 'is_captain', 'is_mvp', 'coach_note']);
        $dates = DB::table('games')->whereIn('id', $rows->pluck('game_id')->unique())->pluck('date', 'id');
        $empty = fn (object $row) => ! $row->called_up && ! $row->played
            && $row->minutes_played === null && $row->rating === null
            && ($row->role === null || $row->role === 'none') && $row->position_id === null && $row->shirt_number === null
            && ! $row->is_captain && ! $row->is_mvp && blank($row->coach_note);

        return $this->pairUp(
            $rows,
            $keeper,
            fn (object $row) => (string) $row->game_id,
            fn (object $mine, object $theirs) => $empty($mine) && ! $empty($theirs),
            'game',
            fn (object $row) => substr((string) ($dates[$row->game_id] ?? ''), 0, 10),
        );
    }

    private function injuries(int $keeper, int $duplicate): array
    {
        return $this->pairUp(
            DB::table('injury_notes')->whereIn('player_id', [$keeper, $duplicate])->get(['id', 'player_id', 'branch_id', 'start_date']),
            $keeper,
            fn (object $row) => ($row->branch_id ?? 0).':'.substr((string) $row->start_date, 0, 10),
            fn () => false,
            'injury',
            fn (object $row) => substr((string) $row->start_date, 0, 10),
        );
    }

    private function academicYears(int $keeper, int $duplicate): array
    {
        return $this->pairUp(
            DB::table('player_academic_years')->whereIn('player_id', [$keeper, $duplicate])->get(['id', 'player_id', 'academic_year']),
            $keeper,
            fn (object $row) => (string) $row->academic_year,
            fn () => false,
            'academic_year',
            fn (object $row) => (string) $row->academic_year,
        );
    }

    /**
     * Same subscription on both (owner 2026-11-26: "keep the paid one"): the row
     * with more paid stays, a tie keeps the keeper's. Manual debts (no
     * subscription) never clash: they just move.
     */
    private function subscriptions(int $keeper, int $duplicate): array
    {
        $rows = DB::table('player_subscriptions')->whereIn('player_id', [$keeper, $duplicate])->whereNotNull('subscription_id')
            ->orderBy('id')->get(['id', 'player_id', 'subscription_id', 'amount_paid']);
        $names = DB::table('subscriptions')->whereIn('id', $rows->pluck('subscription_id')->unique())->pluck('name', 'id');

        return $this->pairUp(
            $rows,
            $keeper,
            fn (object $row) => (string) $row->subscription_id,
            fn (object $mine, object $theirs) => (float) $theirs->amount_paid > (float) $mine->amount_paid,
            'subscription',
            fn (object $row) => (string) ($names[$row->subscription_id] ?? ''),
        );
    }

    /**
     * Rows of both players sharing a slot become clashes.
     *
     * @param  callable(object): string  $slot
     * @param  callable(object, object): bool  $duplicateWins  (keeper's row, duplicate's row)
     * @param  callable(object): string  $label
     */
    private function pairUp(Collection $rows, int $keeper, callable $slot, callable $duplicateWins, string $kind, callable $label): array
    {
        // A slot can repeat within one player (documents with no registration): pair each slot once, with the keeper's first row; the rest just move.
        $mine = $rows->filter(fn (object $row) => (int) $row->player_id === $keeper)->reverse()->keyBy($slot);
        $clashes = [];
        $paired = [];
        foreach ($rows->filter(fn (object $row) => (int) $row->player_id !== $keeper) as $theirs) {
            $key = $slot($theirs);
            $ours = $mine->get($key);
            if ($ours === null || isset($paired[$key])) {
                continue;
            }
            $paired[$key] = true;
            $clashes[] = [
                'kind' => $kind,
                'label' => $label($theirs),
                'keeper_row' => (int) $ours->id,
                'duplicate_row' => (int) $theirs->id,
                'winner' => $duplicateWins($ours, $theirs) ? 'duplicate' : 'keeper',
            ];
        }

        return $clashes;
    }

    /** Earliest known join: the exact date, else 1 September of the legacy year, else never. */
    private function joinedOn(object $row): string
    {
        if ($row->join_date) {
            return substr((string) $row->join_date, 0, 10);
        }

        return $row->legacy_join_year ? $row->legacy_join_year.'-09-01' : '9999-12-31';
    }

    private function documentRank(?string $state): int
    {
        return match ($state) {
            PlayerDocument::RECEIVED => 2,
            PlayerDocument::EXEMPT => 1,
            default => 0,
        };
    }
}
