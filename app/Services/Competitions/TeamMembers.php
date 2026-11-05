<?php

namespace App\Services\Competitions;

use App\Models\Category;
use App\Models\Player;
use App\Models\Position;
use App\Models\Team;
use App\Services\Player\RegistrationDisplay;

/**
 * What one team sees of a player: the registration in the team's branch. A
 * player from another sport (rosters allow it) shows their main category, but
 * has no licence or position here. A player with no registration at all shows
 * their own columns. Eager-load RELATIONS, and a list costs no query per row.
 */
final class TeamMembers
{
    public const RELATIONS = ['registrations.category', 'registrations.position', 'category', 'position'];

    private function __construct(
        private readonly RegistrationDisplay $display,
        private readonly int $branchId,
        private readonly ?int $leftStatusId,
    ) {}

    public static function of(Team $team): self
    {
        return new self(RegistrationDisplay::make(), (int) $team->branch_id, Player::leftStatusId());
    }

    /** Gone from this team's sport: that registration is left, or with none there, left everywhere. */
    public function hasLeft(Player $player): bool
    {
        return $this->leftStatusId !== null
            && (int) $this->display->values($player, $this->branchId)['status_id'] === $this->leftStatusId;
    }

    public function isActiveMember(Player $player): bool
    {
        return $this->display->isActiveIn($player, $this->branchId);
    }

    public function categoryId(Player $player): ?int
    {
        $id = $this->display->values($player, $this->branchId)['category_id'];

        return $id === null ? null : (int) $id;
    }

    public function category(Player $player): ?Category
    {
        $row = $this->display->shown($player, $this->branchId);

        return $row === null ? $player->category : $row->category;
    }

    public function position(Player $player): ?Position
    {
        $row = $player->registrationIn($this->branchId);
        if ($row !== null) {
            return $row->position;
        }

        return $player->registrations->isEmpty() ? $player->position : null;
    }

    public function licence(Player $player): ?string
    {
        $row = $player->registrationIn($this->branchId);
        if ($row !== null) {
            return $row->licence_number;
        }

        return $player->registrations->isEmpty() ? $player->licence_number : null;
    }
}
