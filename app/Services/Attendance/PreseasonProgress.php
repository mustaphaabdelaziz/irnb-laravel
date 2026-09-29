<?php

namespace App\Services\Attendance;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Category;
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

    /**
     * Per category and season: "started" at its first held pre-season
     * session, "completed" at the held session whose count reaches the
     * target (none without a target). Only milestones dated in [from, to].
     *
     * @return array<int, array{milestone: string, date: string, time: string, session_id: int, category_id: int, category: string, done: ?int, target: ?int}>
     */
    public function milestones(string $from, string $to, ?int $categoryId = null): array
    {
        $categories = Category::query()->when($categoryId !== null, fn ($q) => $q->whereKey($categoryId))->orderBy('id')->get();
        // The window is at most 12 months, so it spans one or two seasons.
        $seasons = collect([Season::forDate($from), Season::forDate($to)])->unique(fn (Season $s) => $s->startYear);
        $events = [];

        foreach ($seasons as $season) {
            $targets = PreseasonTarget::where('season_start_year', $season->startYear)->pluck('target_count', 'category_id');

            foreach ($categories as $category) {
                $held = $this->held($category->id, $season)
                    ->orderBy('date')->orderBy('start_time')->orderBy('id')
                    ->get(['id', 'date', 'start_time']);
                if ($held->isEmpty()) {
                    continue;
                }

                $target = $targets->get($category->id);
                $reached = [['started', $held->first(), null]];
                if ($target > 0 && $held->count() >= $target) {
                    $reached[] = ['completed', $held[$target - 1], (int) $target];
                }

                foreach ($reached as [$milestone, $session, $count]) {
                    if ($session->date < $from || $session->date > $to) {
                        continue;
                    }
                    $events[] = [
                        'milestone' => $milestone,
                        'date' => $session->date,
                        'time' => $session->start_time,
                        'session_id' => $session->id,
                        'category_id' => $category->id,
                        'category' => $category->localized_name,
                        'done' => $count,
                        'target' => $count,
                    ];
                }
            }
        }

        return $events;
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
