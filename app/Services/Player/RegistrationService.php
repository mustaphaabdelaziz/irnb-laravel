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
use App\Support\ValidationAttributes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

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

            foreach ($this->mainCardFirst($cards) as $card) {
                $registration = $existing[(int) $card['branch_id']] ?? null;
                if ($registration === null) {
                    $this->register($player, $card);
                } elseif ($this->isLeft($registration) && ! array_key_exists('status_id', $card)) {
                    $this->reopen($registration, $card);
                } else {
                    $this->change($registration, $card, $user);
                }
            }

            $absent = $existing->keys()->diff(collect($cards)->pluck('branch_id')->map(fn ($id) => (int) $id));
            $this->leave($player, $absent->all());

            $this->syncOtherPositions($player, $cards);
            $this->refreshMirror($player);
        });
    }

    /**
     * The main branch's card first, so it adopts the player's existing ID whatever
     * order the cards arrive in. The rest keep their order.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    public function mainCardFirst(array $cards): array
    {
        $mainId = Branch::mainId();

        return collect($cards)
            ->sortBy(fn (array $card) => (int) $card['branch_id'] === $mainId ? 0 : 1)
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $card */
    public function register(Player $player, array $card): BranchRegistration
    {
        return DB::transaction(function () use ($player, $card) {
            $current = $player->registrations()->where('branch_id', (int) $card['branch_id'])->first();
            if ($current !== null) {
                if ($this->isLeft($current)) {
                    $this->reopen($current, $card);
                }

                return $current;
            }

            $first = ! $player->registrations()->exists();
            $joinDate = empty($card['join_date']) ? null : $card['join_date'];
            // legacy_join_year always keeps the year the player held before this registration.
            $legacyYear = $card['legacy_join_year'] ?? ($first ? $player->join_year : null);
            $year = $joinDate !== null ? (int) substr((string) $joinDate, 0, 4) : $legacyYear;

            $id = match (true) {
                $first && $player->membership_id !== null => $player->membership_id,
                $year !== null => MembershipNumber::forBranch(Branch::findOrFail($card['branch_id']), (int) $year),
                default => null,
            };

            $details = array_fill_keys(self::DETAILS, null);
            foreach (self::DETAILS as $field) {
                $details[$field] = array_key_exists($field, $card)
                    ? $card[$field]
                    : ($first ? $this->fromPlayer($player, $field, (int) $card['branch_id']) : null);
            }
            $details['status_id'] ??= PlayerStatus::where('code', 'registered')->value('id');

            $registration = $player->registrations()->create([
                'branch_id' => (int) $card['branch_id'],
                'membership_id' => $id,
                'join_date' => $joinDate,
                'legacy_join_year' => $legacyYear,
                ...$details,
            ]);

            // A main position another branch owns is not lost: it waits among the other positions.
            if ($first && $player->position_id !== null && (int) ($card['position_id'] ?? 0) !== (int) $player->position_id) {
                $owner = Position::whereKey($player->position_id)->value('branch_id');
                if ($owner !== null && (int) $owner !== (int) $card['branch_id']) {
                    $player->otherPositions()->syncWithoutDetaching([$player->position_id]);
                }
            }

            $this->syncOtherPositions($player, [$card]);
            $this->refreshMirror($player);

            return $registration;
        });
    }

    /**
     * Whether a date shared by many players (bulk edit, the Members dialog) is theirs
     * to take for this branch: only a genuinely new registration. A player with no row
     * here who holds a year and has no other registration keeps that year instead.
     */
    public function takesSharedDate(Player $player, int $branchId): bool
    {
        if ($player->registrations()->where('branch_id', $branchId)->exists()) {
            return false;
        }

        return $player->registrations()->exists() || $player->join_year === null;
    }

    /** Register with the shared date, passed only when the registration takes it. */
    public function registerWithSharedDate(Player $player, int $branchId, ?string $date): BranchRegistration
    {
        $card = ['branch_id' => $branchId];
        if (! empty($date) && $this->takesSharedDate($player, $branchId)) {
            $card['join_date'] = $date;
        }

        return $this->register($player, $card);
    }

    /**
     * Check the shared date before anything is written. It is required when at least
     * one of the registrations takes it, and may not precede a taker's birth date.
     *
     * @param  iterable<array{0: Player, 1: int}>  $pairs  player and branch about to be registered
     *
     * @throws ValidationException
     */
    public function assertSharedDate(iterable $pairs, ?string $date): void
    {
        // A player's second new branch in one save follows their first, so it takes the date too.
        $newFor = [];
        $takers = collect($pairs)->filter(function (array $pair) use (&$newFor) {
            [$player, $branchId] = $pair;
            if ($player->registrations()->where('branch_id', $branchId)->exists()) {
                return false;
            }
            $takes = $this->takesSharedDate($player, $branchId) || ($newFor[$player->id] ?? 0) > 0;
            $newFor[$player->id] = ($newFor[$player->id] ?? 0) + 1;

            return $takes;
        });
        if ($takers->isEmpty()) {
            return;
        }
        if (empty($date)) {
            throw ValidationException::withMessages([
                'join_date' => __('validation.required', ['attribute' => ValidationAttributes::for(app()->getLocale())['join_date']]),
            ]);
        }
        $before = $takers->contains(function (array $pair) use ($date) {
            $birth = $pair[0]->birthdate?->toDateString();

            return $birth !== null && $date < $birth;
        });
        if ($before) {
            throw ValidationException::withMessages(['join_date' => __('validation.join_date_before_birth')]);
        }
    }

    /**
     * Fields a card does not carry stay as they are. A saved date is never
     * cleared, and legacy_join_year always keeps the original year.
     *
     * @param  array<string, mixed>  $card
     */
    private function change(BranchRegistration $registration, array $card, ?User $user = null): void
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
        if ($left === null) {
            throw new RuntimeException('The "left" player status is missing.');
        }

        DB::transaction(function () use ($player, $branchIds, $left, $date) {
            $player->registrations()->whereIn('branch_id', $branchIds)->get()
                ->reject(fn (BranchRegistration $r) => (int) $r->status_id === $left)
                ->each(function (BranchRegistration $r) use ($left, $date) {
                    $r->status_id = $left;
                    $r->left_at = $date ?? now()->toDateString();
                    $r->save();
                });

            $this->refreshMirror($player);
        });
    }

    /**
     * One field from bulk edit: status on the registration of $branchId only,
     * position on its branch's, category on the primary.
     */
    public function setField(Player $player, string $field, mixed $value, ?int $branchId = null): void
    {
        DB::transaction(function () use ($player, $field, $value, $branchId) {
            $registrations = $player->registrations()->get();

            if ($registrations->isEmpty()) {
                $player->update([$field => $value]);

                // The main position can never also sit in the "other positions" pivot.
                if ($field === 'position_id' && $value) {
                    $player->otherPositions()->detach($value);
                }

                return;
            }
            if ($field === 'status_id' && $branchId === null) {
                throw new InvalidArgumentException('A status change needs the branch it applies to.');
            }

            $targets = match ($field) {
                'status_id' => $registrations->where('branch_id', $branchId),
                'position_id' => collect([$this->positionOwner($registrations, $player, $value)])->filter(),
                default => collect([$this->primary($player)]),
            };

            foreach ($targets as $registration) {
                $registration->{$field} = $value;
                $registration->save();
            }

            if ($field === 'position_id' && $value && $targets->isNotEmpty()) {
                $player->otherPositions()->detach($value);
            }
            $this->refreshMirror($player);
        });
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

    /**
     * Undo a registration added by mistake. Anything real keeps it: a subscription
     * obligation on its branch, or being the player's last one. Later releases add
     * documents and custom values to this check.
     *
     * @return string|null the flash key when refused
     */
    public function removeMistake(BranchRegistration $registration): ?string
    {
        $player = $registration->player;

        if ($player->registrations()->count() === 1) {
            return 'flash.registration_last';
        }

        $hasObligations = $player->playerSubscriptions()
            ->whereHas('subscription.branches', fn ($q) => $q->where('branches.id', $registration->branch_id))
            ->exists();
        if ($hasObligations) {
            return 'flash.registration_has_obligations';
        }

        DB::transaction(function () use ($player, $registration) {
            $id = $registration->membership_id;
            // The old-format ID to keep findable: the row's recorded one, or its own ID when that is old-format.
            $old = $registration->legacy_membership_id ?? (MembershipNumber::isLegacy($id) ? $id : null);
            $branchPositions = Position::where('branch_id', $registration->branch_id)->pluck('id');
            $player->otherPositions()->detach($branchPositions);
            $registration->delete();

            // The player's copy of the ID must keep pointing at a real one.
            $next = $this->primary($player);
            if ($next->membership_id === null) {
                $next->membership_id = MembershipNumber::isLegacy($id)
                    ? $id
                    : ($next->effectiveYear() ? MembershipNumber::forBranch($next->branch, $next->effectiveYear()) : null);
            }
            // The deleted row's old-format ID must stay searchable. The column holds one value:
            // a next row that already has one keeps its own.
            if ($old !== null && $next->membership_id !== $old) {
                $next->legacy_membership_id ??= $old;
            }
            if ($next->isDirty()) {
                $next->save();
            }

            $this->refreshMirror($player);
        });

        return null;
    }

    /** Copy the primary registration into players.*. A player with none keeps their own values. */
    public function refreshMirror(Player $player): void
    {
        $primary = $this->primary($player);
        if ($primary === null) {
            return;
        }

        $registrations = $player->registrations()->get();
        [$status, $leftAt] = $this->overallStatus($primary, $registrations);

        DB::table('players')->where('id', $player->id)->update([
            'category_id' => $primary->category_id,
            'position_id' => $primary->position_id,
            'licence_number' => $primary->licence_number,
            'status_id' => $status,
            'left_at' => $leftAt,
            'join_year' => $registrations->map(fn (BranchRegistration $r) => $r->effectiveYear())->filter()->min() ?? $player->join_year,
            'membership_id' => $primary->membership_id ?? $player->membership_id,
        ]);
    }

    /**
     * The status and leave date the player copy holds: left only when left
     * everywhere (with the latest leave date), else the primary's status and no date.
     *
     * @param  Collection<int, BranchRegistration>  $registrations
     * @return array{0: int|null, 1: string|null}
     */
    public function overallStatus(BranchRegistration $primary, Collection $registrations): array
    {
        $left = Player::leftStatusId();
        if ($left !== null && $registrations->every(fn (BranchRegistration $r) => (int) $r->status_id === $left)) {
            return [$left, $registrations->max(fn (BranchRegistration $r) => $r->left_at?->toDateString())];
        }

        return [$primary->status_id, null];
    }

    private function isLeft(BranchRegistration $registration): bool
    {
        return Player::isLeftStatus($registration->status_id);
    }

    /**
     * A left registration comes back as the same row, with its ID, year and original
     * join date, or none if it never had one. A card's date changes nothing here.
     *
     * @param  array<string, mixed>  $card
     */
    private function reopen(BranchRegistration $registration, array $card): void
    {
        foreach (self::DETAILS as $field) {
            if ($field !== 'left_at' && array_key_exists($field, $card)) {
                $registration->{$field} = $card[$field];
            }
        }
        $registration->status_id = $card['status_id'] ?? PlayerStatus::where('code', 'registered')->value('id');
        // The saving hook clears left_at once the status is no longer left.
        $registration->save();

        $this->syncOtherPositions($registration->player, [$card]);
        $this->refreshMirror($registration->player);
    }

    /** What a player's first registration starts from when the card is silent. */
    private function fromPlayer(Player $player, string $field, int $branchId): mixed
    {
        return match ($field) {
            // A branchless position stays; one another branch owns waits among the other positions.
            'position_id' => $player->position_id !== null
                && in_array(Position::whereKey($player->position_id)->value('branch_id'), [null, $branchId], false)
                ? $player->position_id : null,
            'left_at' => $player->left_at?->toDateString(),
            'sporting_status_id' => null,
            default => $player->{$field},
        };
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
     * change, and a main position is never also an "other" one. A position with
     * no branch is replaced whenever any card speaks, because the form re-lists
     * those on its first card.
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
