<?php

namespace App\Services\Player\Merge;

use App\Models\BoardMember;
use App\Models\Player;
use App\Models\PlayerDuplicateDecision;
use App\Models\User;
use App\Services\Finance\RecalculatePlayerDebtService;
use App\Services\Player\Duplicates\DuplicateFinder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Merges a duplicate player into the kept one (owner 2026-11-26): every
 * record moves, once-only records follow MergeClashes, the duplicate is
 * deleted, all in one transaction. Rows move with the query builder so no
 * registration or document hook fires halfway through.
 */
class PlayerMerger
{
    /** Rows repointed as they are once clashes are settled: table => player column. */
    private const REPOINT = [
        ['player_emergency_contacts', 'player_id'],
        ['player_achievements', 'player_id'],
        ['player_subscriptions', 'player_id'],
        ['board_members', 'player_id'],
        ['suspensions', 'player_id'],
        ['game_events', 'player_id'],
        ['game_events', 'related_player_id'],
        ['training_group_members', 'player_id'],
        ['injury_notes', 'player_id'],
        ['attendances', 'player_id'],
        ['team_players', 'player_id'],
        ['competition_players', 'player_id'],
        ['game_players', 'player_id'],
        ['player_academic_years', 'player_id'],
        ['branch_player', 'player_id'],
        ['player_documents', 'player_id'],
    ];

    /** Pivots: the duplicate's row goes when the keeper already has the same one. */
    private const PIVOTS = [
        'player_other_positions' => 'position_id',
        'activity_player' => 'activity_id',
    ];

    public function __construct(
        private FieldMerge $fields,
        private MergeClashes $clashes,
        private DuplicateFinder $finder,
        private RecalculatePlayerDebtService $debts,
    ) {}

    /** @param  array<string, string>  $choices  field => 'keeper' | 'duplicate' */
    public function merge(Player $keeper, Player $duplicate, array $choices, ?User $by): PlayerDuplicateDecision
    {
        if ($keeper->is($duplicate)) {
            throw new InvalidArgumentException('A player cannot be merged into itself.');
        }

        $k = $keeper->id;
        $d = $duplicate->id;
        [$a, $b] = PlayerDuplicateDecision::orderedPair($k, $d);
        $pair = $this->finder->scorePair($a, $b);
        $snapshots = [$k => PlayerDuplicateDecision::snapshot($keeper), $d => PlayerDuplicateDecision::snapshot($duplicate)];
        $attributes = $this->fields->resolve($keeper, $duplicate, $choices);

        return DB::transaction(function () use ($keeper, $k, $d, $a, $b, $pair, $snapshots, $attributes, $by) {
            $this->settleClashes($this->clashes->find($k, $d));
            $this->movePivots($k, $d);
            $this->dropSameGroupSpells($k, $d);
            foreach (self::REPOINT as [$table, $column]) {
                DB::table($table)->where($column, $d)->update([$column => $k]);
            }
            $this->repointMorphs($k, $d);

            PlayerDuplicateDecision::query()
                ->where('status', PlayerDuplicateDecision::DISMISSED)
                ->where(fn ($q) => $q->where('player_a_id', $d)->orWhere('player_b_id', $d))
                ->where(fn ($q) => $q->where('player_a_id', '!=', $a)->orWhere('player_b_id', '!=', $b))
                ->delete();
            $decision = PlayerDuplicateDecision::updateOrCreate(
                ['player_a_id' => $a, 'player_b_id' => $b],
                [
                    'status' => PlayerDuplicateDecision::MERGED,
                    'player_a_snapshot' => $snapshots[$a],
                    'player_b_snapshot' => $snapshots[$b],
                    'kept_player_id' => $k,
                    'score' => $pair['score'],
                    'reasons' => $pair['reasons'],
                    'decided_by' => $by?->id,
                    'decided_at' => now(),
                ],
            );

            DB::table('players')->where('id', $d)->delete();

            $keeper->forceFill($attributes)->save();
            $keeper->refresh();
            BoardMember::where('player_id', $k)->update(['name' => $keeper->fullname]);
            $this->debts->forPlayer($keeper);

            return $decision->fresh();
        });
    }

    /** Losers go; documents first (they follow registrations), then registrations, then the rest. */
    private function settleClashes(array $clashes): void
    {
        foreach ($clashes as $clash) {
            if ($clash['kind'] !== 'document') {
                continue;
            }
            [$winner, $loser] = $this->sides($clash);
            DB::table('player_document_files')->where('player_document_id', $loser)->update(['player_document_id' => $winner]);
            DB::table('player_documents')->where('id', $loser)->delete();
        }

        foreach ($this->clashes->registrationMap($clashes) as $loser => $winner) {
            DB::table('registration_status_changes')->where('branch_registration_id', $loser)->update(['branch_registration_id' => $winner]);
            DB::table('player_documents')->where('branch_registration_id', $loser)->update(['branch_registration_id' => $winner]);
            DB::table('branch_player')->where('id', $loser)->delete();
        }

        foreach ($clashes as $clash) {
            if (in_array($clash['kind'], ['document', 'registration'], true)) {
                continue;
            }
            [$winner, $loser] = $this->sides($clash);
            if ($clash['kind'] === 'academic_year') {
                $held = DB::table('player_academic_records')->where('player_academic_year_id', $winner)->pluck('period');
                DB::table('player_academic_records')->where('player_academic_year_id', $loser)->whereNotIn('period', $held)
                    ->update(['player_academic_year_id' => $winner]);
                DB::table('player_academic_records')->where('player_academic_year_id', $loser)->delete();
            }
            DB::table(MergeClashes::TABLES[$clash['kind']])->where('id', $loser)->delete();
        }
    }

    /** @return array{0:int, 1:int} [winner row, loser row] */
    private function sides(array $clash): array
    {
        return $clash['winner'] === 'keeper'
            ? [$clash['keeper_row'], $clash['duplicate_row']]
            : [$clash['duplicate_row'], $clash['keeper_row']];
    }

    private function movePivots(int $k, int $d): void
    {
        foreach (self::PIVOTS as $table => $other) {
            $held = DB::table($table)->where('player_id', $k)->pluck($other);
            DB::table($table)->where('player_id', $d)->whereIn($other, $held)->delete();
            DB::table($table)->where('player_id', $d)->update(['player_id' => $k]);
        }
    }

    /** The same group spell recorded twice (same group, same start) is one spell. */
    private function dropSameGroupSpells(int $k, int $d): void
    {
        $mine = DB::table('training_group_members')->where('player_id', $k)->get(['training_group_id', 'from_date']);
        foreach ($mine as $spell) {
            DB::table('training_group_members')->where('player_id', $d)
                ->where('training_group_id', $spell->training_group_id)
                ->where('from_date', $spell->from_date)
                ->delete();
        }
    }

    private function repointMorphs(int $k, int $d): void
    {
        DB::table('equipment_rentals')->where('rentable_type', Player::class)->where('rentable_id', $d)->update(['rentable_id' => $k]);

        $sessions = DB::table('inventory_session_participants')->where('participant_type', Player::class)->where('participant_id', $k)->pluck('inventory_session_id');
        DB::table('inventory_session_participants')->where('participant_type', Player::class)->where('participant_id', $d)->whereIn('inventory_session_id', $sessions)->delete();
        DB::table('inventory_session_participants')->where('participant_type', Player::class)->where('participant_id', $d)->update(['participant_id' => $k]);


        DB::table('transactions')->whereIn('related_entity_type', ['Player', Player::class])->where('related_entity_id', $d)->update(['related_entity_id' => $k]);
        DB::table('activity_logs')->where('subject_type', Player::class)->where('subject_id', $d)->update(['subject_id' => $k]);
    }
}
