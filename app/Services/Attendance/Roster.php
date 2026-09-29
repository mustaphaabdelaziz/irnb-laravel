<?php

namespace App\Services\Attendance;

use App\Models\Player;
use App\Models\TrainingSession;
use Illuminate\Support\Collection;

/**
 * Who is expected at a session. Before the first save it is the category's
 * members on that date; after, it is exactly the saved marks. Freezing it
 * keeps history fair: later category changes, departures and newcomers never
 * rewrite a past session.
 */
final class Roster
{
    private const COLUMNS = ['id', 'firstname', 'lastname', 'category_id'];

    /** @return Collection<int, Player> */
    public function expected(int $categoryId, string $date): Collection
    {
        return Player::where('category_id', $categoryId)
            ->where('archived', false)
            ->where(fn ($q) => $q->whereNull('left_at')->orWhereDate('left_at', '>', $date))
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::COLUMNS);
    }

    /** @return Collection<int, Player> */
    public function forSession(TrainingSession $session): Collection
    {
        if (! $session->attendances()->exists()) {
            return $this->expected($session->category_id, $session->date);
        }

        return Player::whereIn('id', $session->attendances()->select('player_id'))
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::COLUMNS);
    }
}
