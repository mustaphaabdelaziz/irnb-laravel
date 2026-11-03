<?php

namespace App\Services\Player;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only production writer of registrations and of the players.* columns they
 * feed. Until every reader has moved, those columns copy the primary registration.
 */
class RegistrationService
{
    private const DETAILS = ['category_id', 'position_id', 'licence_number', 'status_id', 'left_at', 'sporting_status_id'];

    /**
     * Make the player's registrations match the cards. A branch with no card is
     * marked as left, never deleted: it keeps its date, ID and history.
     *
     * @param  list<array<string, mixed>>  $cards
     */
    public function sync(Player $player, array $cards, ?User $user = null): void
    {
        DB::transaction(function () use ($player, $cards, $user) {
            $existing = $player->registrations()->get()->keyBy('branch_id');

            foreach ($cards as $card) {
                $registration = $existing[(int) $card['branch_id']] ?? null;
                $registration
                    ? $this->change($registration, $card, $user)
                    : $this->register($player, $card);
            }

            $absent = $existing->keys()->diff(collect($cards)->pluck('branch_id')->map(fn ($id) => (int) $id));
            $this->leave($player, $absent->all());

            $this->syncOtherPositions($player, $cards);
            $this->refreshMirror($player);
        });
    }

    /** @param  array<string, mixed>  $card */
    public function register(Player $player, array $card): BranchRegistration
    {
        return DB::transaction(function () use ($player, $card) {
            $first = ! $player->registrations()->exists();
            $joinDate = $card['join_date'] ?? null;
            $legacyYear = $card['legacy_join_year'] ?? (($first && $joinDate === null) ? $player->join_year : null);
            $year = $joinDate !== null ? (int) substr((string) $joinDate, 0, 4) : $legacyYear;

            $id = match (true) {
                $first && $player->membership_id !== null => $player->membership_id,
                $year !== null => MembershipNumber::forBranch(Branch::findOrFail($card['branch_id']), (int) $year),
                default => null,
            };

            $details = array_fill_keys(self::DETAILS, null);
            foreach (self::DETAILS as $field) {
                $details[$field] = $card[$field] ?? null;
            }
            $details['status_id'] ??= PlayerStatus::where('code', 'registered')->value('id');

            $registration = $player->registrations()->create([
                'branch_id' => (int) $card['branch_id'],
                'membership_id' => $id,
                'join_date' => $joinDate,
                'legacy_join_year' => $legacyYear,
                ...$details,
            ]);

            $this->syncOtherPositions($player, [$card]);
            $this->refreshMirror($player);

            return $registration;
        });
    }

    /**
     * Fields a card does not carry stay as they are. A saved date is never
     * cleared, and legacy_join_year always keeps the original year.
     *
     * @param  array<string, mixed>  $card
     */
    public function change(BranchRegistration $registration, array $card, ?User $user = null): void
    {
        foreach (self::DETAILS as $field) {
            if (array_key_exists($field, $card)) {
                $registration->{$field} = $card[$field];
            }
        }
        if (! empty($card['join_date'])) {
            $this->applyDate($registration, (string) $card['join_date'], $user);
        }
        $registration->save();
    }

    public function setJoinDate(BranchRegistration $registration, string $date, ?User $user = null): void
    {
        DB::transaction(function () use ($registration, $date, $user) {
            $this->applyDate($registration, $date, $user);
            $registration->save();
            $this->refreshMirror($registration->player);
        });
    }

    /** @param  list<int>  $branchIds */
    public function leave(Player $player, array $branchIds, ?string $date = null): void
    {
        if ($branchIds === []) {
            return;
        }
        $left = Player::leftStatusId();

        $player->registrations()->whereIn('branch_id', $branchIds)->get()
            ->reject(fn (BranchRegistration $r) => (int) $r->status_id === (int) $left)
            ->each(function (BranchRegistration $r) use ($left, $date) {
                $r->status_id = $left;
                $r->left_at = $date ?? now()->toDateString();
                $r->save();
            });

        $this->refreshMirror($player);
    }

    /** One field from bulk edit: status on every registration, position on its branch's, category on the primary. */
    public function setField(Player $player, string $field, mixed $value): void
    {
        $registrations = $player->registrations()->get();

        if ($registrations->isEmpty()) {
            $player->update([$field => $value]);

            return;
        }

        $targets = match ($field) {
            'status_id' => $registrations,
            'position_id' => collect([$this->positionOwner($registrations, $player, $value)])->filter(),
            default => collect([$this->primary($player)]),
        };

        foreach ($targets as $registration) {
            $registration->{$field} = $value;
            $registration->save();
        }

        if ($field === 'position_id' && $value) {
            $player->otherPositions()->detach($value);
        }
        $this->refreshMirror($player);
    }

    /**
     * The main branch's registration if not left, else the earliest joined not
     * left, else the lowest id.
     */
    public function primary(Player $player): ?BranchRegistration
    {
        $registrations = $player->registrations()->orderBy('id')->get();
        if ($registrations->isEmpty()) {
            return null;
        }

        $left = Player::leftStatusId();
        $active = $registrations->reject(fn (BranchRegistration $r) => $left !== null && (int) $r->status_id === $left);

        return $active->firstWhere('branch_id', Branch::mainId())
            ?? $active->sortBy(fn (BranchRegistration $r) => [$this->joinSortKey($r), $r->id])->first()
            ?? $registrations->first();
    }

    /** Copy the primary registration into players.*. A player with none keeps their own values. */
    public function refreshMirror(Player $player): void
    {
        $primary = $this->primary($player);
        if ($primary === null) {
            return;
        }

        $registrations = $player->registrations()->get();
        $left = Player::leftStatusId();
        $allLeft = $left !== null && $registrations->every(fn (BranchRegistration $r) => (int) $r->status_id === $left);

        DB::table('players')->where('id', $player->id)->update([
            'category_id' => $primary->category_id,
            'position_id' => $primary->position_id,
            'licence_number' => $primary->licence_number,
            'status_id' => $allLeft ? $left : $primary->status_id,
            'left_at' => $allLeft ? $registrations->max(fn (BranchRegistration $r) => $r->left_at?->toDateString()) : null,
            'join_year' => $registrations->map(fn (BranchRegistration $r) => $r->effectiveYear())->filter()->min() ?? $player->join_year,
            'membership_id' => $primary->membership_id ?? $player->membership_id,
        ]);
    }

    private function applyDate(BranchRegistration $registration, string $date, ?User $user): void
    {
        $before = $registration->effectiveYear();
        $registration->join_date = $date;
        $after = (int) substr($date, 0, 4);

        if ($registration->membership_id === null) {
            $registration->membership_id = MembershipNumber::forBranch($registration->branch, $after);

            return;
        }
        if ($before === null || $before === $after) {
            return;
        }

        $old = $registration->membership_id;
        $registration->membership_id = MembershipNumber::forBranch($registration->branch, $after);
        if (MembershipNumber::isLegacy($old)) {
            $registration->legacy_membership_id ??= $old;
        }
        ActivityRecorder::record($user, ActivityAction::MEMBERSHIP_ID_CHANGED, $registration->player, [
            'from' => $old,
            'to' => $registration->membership_id,
        ]);
    }

    /** An exact date sorts by itself; a year-only row sorts at the end of its year; unknown goes last. */
    private function joinSortKey(BranchRegistration $registration): string
    {
        return $registration->join_date?->toDateString()
            ?? ($registration->legacy_join_year !== null ? $registration->legacy_join_year.'-12-31' : '9999-12-31');
    }

    /** @param  Collection<int, BranchRegistration>  $registrations */
    private function positionOwner(Collection $registrations, Player $player, mixed $positionId): ?BranchRegistration
    {
        if (! $positionId) {
            return $this->primary($player);
        }
        $branchId = Position::whereKey($positionId)->value('branch_id');

        return $branchId === null ? $this->primary($player) : $registrations->firstWhere('branch_id', $branchId);
    }

    /**
     * Other positions stay in player_other_positions: a position's branch says
     * which registration it belongs to. Only the branches the cards speak for
     * change, and a main position is never also an "other" one.
     *
     * @param  list<array<string, mixed>>  $cards
     */
    private function syncOtherPositions(Player $player, array $cards): void
    {
        $speaking = collect($cards)->filter(fn (array $card) => array_key_exists('other_position_ids', $card));

        if ($speaking->isNotEmpty()) {
            $branchIds = $speaking->pluck('branch_id')->map(fn ($id) => (int) $id);
            $kept = $player->otherPositions()->get(['positions.id', 'positions.branch_id'])
                ->reject(fn (Position $p) => $p->branch_id === null || $branchIds->contains((int) $p->branch_id))
                ->modelKeys();
            $chosen = $speaking->flatMap(fn (array $card) => $card['other_position_ids'] ?? [])->map(fn ($id) => (int) $id);

            $player->otherPositions()->sync($chosen->merge($kept)->unique()->values()->all());
        }

        $mains = $player->registrations()->whereNotNull('position_id')->pluck('position_id')->all();
        if ($mains !== []) {
            $player->otherPositions()->detach($mains);
        }
    }
}
