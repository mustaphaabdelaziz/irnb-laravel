<?php

namespace App\Services\Attendance;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\PreseasonTarget;
use App\Models\TrainingSession;
use App\Support\Season;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pre-season progress per category and season. A joint pre-season session
 * counts once for every category in it (owner decision), so this reads the
 * session-category pivot, never `training_sessions.category_id` alone.
 */
final class PreseasonProgress
{
    /** @return array{season: string, done: int, target: ?int} */
    public function forCategory(int $categoryId, DateTimeInterface|string $date): array
    {
        $season = Season::forDate($date);

        return [
            'season' => $season->label(),
            'done' => $this->held($categoryId, $season)->count(),
            'target' => PreseasonTarget::where('category_id', $categoryId)
                ->where('season_start_year', $season->startYear)->value('target_count'),
        ];
    }

    /** Held pre-season sessions of the season that include the category. */
    private function held(int $categoryId, Season $season): Builder
    {
        return TrainingSession::query()
            ->includingCategory($categoryId)
            ->where('kind', SessionKind::Preseason->value)
            ->where('state', SessionState::Held->value)
            ->whereBetween('date', [$season->start()->toDateString(), $season->end()->toDateString()]);
    }
}
