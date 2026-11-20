<?php

namespace App\Reports\Players;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\RegistrationStatusChange;
use App\Services\Player\StatusHistory;
use Illuminate\Support\Collection;

/**
 * Departures and returns over all years, from the status history plus the leave
 * dates no line records (bulk departures). With branches, it means leaving or
 * coming back to one of those branches. Without, it means the club: on that day
 * no other registration of the player was a member.
 */
final class MovementEvents
{
    public function __construct(private readonly StatusHistory $history) {}

    /**
     * @param  list<int>|null  $branches
     * @param  list<int>|null  $categories  applied with branches only
     * @return Collection<int, object{player_id: int, on: string}>
     */
    public function departures(?array $branches, ?array $categories): Collection
    {
        $left = Player::leftStatusId();
        if ($left === null) {
            return collect();
        }

        $ids = RegistrationStatusChange::query()->where('to_status_id', $left)->pluck('branch_registration_id')
            ->merge(BranchRegistration::query()->where('status_id', $left)->whereNotNull('left_at')->pluck('id'))
            ->unique()->values();
        $events = $this->events($ids, $branches, $categories, $left, fn ($e) => (int) $e->to === $left);

        // A player with no registration keeps his leave date on players.
        if ($branches === null) {
            $events = $events->merge(
                Player::query()->whereNotNull('left_at')->whereDoesntHave('registrations')->get(['id', 'left_at'])
                    ->map(fn (Player $p) => (object) ['player_id' => (int) $p->id, 'on' => $p->left_at->toDateString()])
            );
        }

        return $events->values();
    }

    /**
     * Only recorded returns: a bulk return keeps no line.
     *
     * @param  list<int>|null  $branches
     * @param  list<int>|null  $categories  applied with branches only
     * @return Collection<int, object{player_id: int, on: string}>
     */
    public function returns(?array $branches, ?array $categories): Collection
    {
        $left = Player::leftStatusId();
        if ($left === null) {
            return collect();
        }

        $ids = RegistrationStatusChange::query()
            ->where('from_status_id', $left)
            ->where(fn ($q) => $q->whereNull('to_status_id')->orWhere('to_status_id', '!=', $left))
            ->pluck('branch_registration_id')->unique()->values();

        return $this->events($ids, $branches, $categories, $left, fn ($e) => $e->from !== null && (int) $e->from === $left && (int) $e->to !== $left);
    }

    /**
     * @param  Collection<int, int>  $ids  candidate registrations
     * @return Collection<int, object{player_id: int, on: string}>
     */
    private function events(Collection $ids, ?array $branches, ?array $categories, int $left, callable $matches): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        $candidates = BranchRegistration::query()
            ->whereIn('id', $ids->all())
            ->when($branches !== null, fn ($q) => $q->inBranches($branches))
            ->when($branches !== null && $categories !== null, fn ($q) => $q->inCategories($categories))
            ->with('statusChanges')
            ->get();

        // Club-wide, the player's other registrations decide whether the club was left.
        $siblings = $branches === null
            ? BranchRegistration::query()->whereIn('player_id', $candidates->pluck('player_id')->unique()->all())
                ->with('statusChanges')->get()->groupBy('player_id')
            : collect();

        return $candidates->flatMap(function (BranchRegistration $registration) use ($matches, $siblings, $branches, $left) {
            $others = $branches === null
                ? ($siblings[$registration->player_id] ?? collect())->reject(fn (BranchRegistration $o) => $o->id === $registration->id)
                : collect();

            return $this->history->timeline($registration, $left)
                ->filter($matches)
                ->filter(fn ($e) => $others->every(fn (BranchRegistration $o) => ! $this->history->wasMemberOn($o, $e->on, $left)))
                ->map(fn ($e) => (object) ['player_id' => (int) $registration->player_id, 'on' => $e->on]);
        })->values();
    }
}
