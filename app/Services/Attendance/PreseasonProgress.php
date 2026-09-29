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
use Illuminate\Support\Facades\DB;

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
     * Every category's progress for the season of $date in three queries
     * (the statistics page lists all categories at once).
     *
     * @return array<int, array{season: string, done: int, target: ?int}> keyed by category id
     */
    public function forCategories(DateTimeInterface|string $date): array
    {
        $season = Season::forDate($date);

        $done = DB::table('training_session_category')
            ->join('training_sessions', 'training_sessions.id', '=', 'training_session_category.training_session_id')
            ->where('training_sessions.kind', SessionKind::Preseason->value)
            ->where('training_sessions.state', SessionState::Held->value)
            ->whereBetween('training_sessions.date', [$season->start()->toDateString(), $season->end()->toDateString()])
            ->select('training_session_category.category_id')
            ->selectRaw('count(*) as total')
            ->groupBy('training_session_category.category_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->category_id => (int) $row->total]);

        $targets = PreseasonTarget::where('season_start_year', $season->startYear)
            ->get(['category_id', 'target_count'])
            ->mapWithKeys(fn (PreseasonTarget $target) => [(int) $target->category_id => (int) $target->target_count]);

        return Category::orderBy('id')->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => [
                'season' => $season->label(),
                'done' => $done[(int) $id] ?? 0,
                'target' => $targets[(int) $id] ?? null,
            ]])
            ->all();
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
