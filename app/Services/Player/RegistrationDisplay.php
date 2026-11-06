<?php

namespace App\Services\Player;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Player;
use Illuminate\Support\Collection;

/**
 * Which registration's values a list, page or report shows for a player. Build
 * one per request with make(), and eager-load `registrations`: then it costs no
 * query per row. A player with no registration shows their own columns.
 */
final class RegistrationDisplay
{
    public function __construct(
        private readonly ?int $mainBranchId,
        private readonly ?int $leftStatusId,
    ) {}

    public static function make(): self
    {
        return new self(Branch::mainId(), Player::leftStatusId());
    }

    /** The branch a filter means: exactly one selected, otherwise none. */
    public static function singleBranch(iterable|int|null $ids): ?int
    {
        $list = collect($ids ?? [])->filter()->values();

        return $list->count() === 1 ? (int) $list->first() : null;
    }

    public function main(Player $player): ?BranchRegistration
    {
        return RegistrationService::pickPrimary($player->registrations, $this->mainBranchId, $this->leftStatusId);
    }

    /** The registration among these rows that RegistrationService::pickPrimary picks (the main one when it is among them). */
    public function pick(Collection $rows): ?BranchRegistration
    {
        return RegistrationService::pickPrimary($rows, $this->mainBranchId, $this->leftStatusId);
    }

    /** That branch's registration when the player has one, otherwise the main one. */
    public function shown(Player $player, ?int $branchId): ?BranchRegistration
    {
        return $player->registrationIn($branchId) ?? $this->main($player);
    }

    public function isActiveIn(Player $player, int $branchId): bool
    {
        $row = $player->registrationIn($branchId);

        return $row !== null && ! $this->isLeft($row);
    }

    /**
     * @return array{registration_id: int|null, branch_id: int|null, category_id: int|null, position_id: int|null, licence_number: string|null, status_id: int|null, left_at: string|null, membership_id: string|null, in_branch: bool, left_branch: bool}
     */
    public function values(Player $player, ?int $branchId = null): array
    {
        $row = $this->shown($player, $branchId);

        if ($row === null) {
            return [
                'registration_id' => null,
                'branch_id' => null,
                'category_id' => $player->category_id,
                'position_id' => $player->position_id,
                'licence_number' => $player->licence_number,
                'status_id' => $player->status_id,
                'left_at' => $player->left_at?->toDateString(),
                'membership_id' => $player->membership_id,
                'in_branch' => false,
                'left_branch' => false,
            ];
        }

        $inBranch = $branchId !== null && (int) $row->branch_id === $branchId;
        [$status, $leftAt] = $inBranch
            ? [$row->status_id, $row->left_at?->toDateString()]
            : RegistrationService::overallOf($row, $player->registrations, $this->leftStatusId);

        return [
            'registration_id' => $row->id,
            'branch_id' => $row->branch_id,
            'category_id' => $row->category_id,
            'position_id' => $row->position_id,
            'licence_number' => $row->licence_number,
            'status_id' => $status,
            'left_at' => $leftAt,
            'membership_id' => $row->membership_id,
            'in_branch' => $inBranch,
            'left_branch' => $inBranch && $this->isLeft($row),
        ];
    }

    /**
     * Put one branch's values on the player, for a row or a printed page: the
     * attributes, and the relations the caller loaded on the registration. Not for
     * saving. No row in that branch, or no branch asked: nothing changes, because
     * players.* already holds the main registration.
     */
    public function overlay(Player $player, ?int $branchId): Player
    {
        $row = $player->registrationIn($branchId);
        if ($row === null) {
            return $player;
        }

        $player->category_id = $row->category_id;
        $player->position_id = $row->position_id;
        $player->licence_number = $row->licence_number;
        $player->status_id = $row->status_id;
        $player->left_at = $row->left_at?->toDateString();
        // A registration with no ID of its own shows none; players.membership_id is only for players with no registration.
        $player->membership_id = $row->membership_id;

        // Clean, so a stray save() cannot write one branch's values over the players.* mirror.
        $player->syncOriginal();

        foreach (['category', 'position', 'status'] as $relation) {
            if ($row->relationLoaded($relation)) {
                $player->setRelation($relation, $row->getRelation($relation));
            } else {
                // No stale mirror relation next to the branch's *_id: it loads on demand.
                $player->unsetRelation($relation);
            }
        }
        if ($player->relationLoaded('otherPositions')) {
            $player->setRelation('otherPositions', $player->otherPositions
                ->filter(fn ($position) => $position->branch_id === null || (int) $position->branch_id === $branchId)
                ->values());
        }

        return $player;
    }

    private function isLeft(BranchRegistration $row): bool
    {
        return $this->leftStatusId !== null && (int) $row->status_id === $this->leftStatusId;
    }
}
