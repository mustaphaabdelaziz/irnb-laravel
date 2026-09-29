<?php

namespace App\Services\Dashboard;

use App\Models\ClubClosure;
use App\Models\TrainingSchedule;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\CalendarFeed;
use App\Services\Attendance\SessionGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The members tab's attendance card: today's sessions and the last 30 days
 * by status. Fixed windows, so the dashboard's range and branch filters do
 * not apply (attendance has no branch).
 */
final class AttendanceCard
{
    public const DAYS = 30;

    public function __construct(
        private readonly CalendarFeed $feed,
        private readonly SessionGenerator $generator,
        private readonly AttendanceStats $stats,
    ) {}

    /** @return array{date: string, today: list<array<string, mixed>>, last30: array<string, mixed>} */
    public function get(): array
    {
        $today = CarbonImmutable::today();
        $date = $today->toDateString();
        $this->generateToday($today);

        $from = $today->subDays(self::DAYS - 1)->toDateString();
        $totals = $this->stats->summarize($this->stats->players($from, $date));

        return [
            'date' => $date,
            'today' => $this->feed->sessions($date, $date)->all(),
            'last30' => [
                'from' => $from,
                'to' => $date,
                'expected' => $totals['expected'],
                'counts' => $totals['counts'],
                'pct' => $totals['pct'],
            ],
        ];
    }

    /**
     * Planned sessions exist once someone opens the month in the calendar.
     * When a category trains today by its weekly schedule but has no session
     * at that slot yet, generate its month first (idempotent, as the calendar
     * does), so the card never hides a regular slot behind an extra or joint
     * pre-season session held today at another time. Checked per slot
     * (category, start_time), not per category, since another session today
     * only covers the slot it shares the same start with. One query on most
     * visits; nothing on a club closure day.
     */
    private function generateToday(CarbonImmutable $today): void
    {
        $date = $today->toDateString();

        $scheduled = TrainingSchedule::where('weekday', $today->dayOfWeekIso)
            ->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->get(['category_id', 'start_time']);
        if ($scheduled->isEmpty()) {
            return;
        }

        $categoryIds = $scheduled->pluck('category_id')->unique()->map(fn ($id) => (int) $id)->values();

        // Today's sessions (to match a slot already held at the same start)
        // together with any session moved away from today for these
        // categories (a moved session guards the whole category for the day,
        // like the generator itself), in one query joined through the pivot.
        $sessionRows = DB::table('training_session_category')
            ->join('training_sessions', 'training_sessions.id', '=', 'training_session_category.training_session_id')
            ->whereIn('training_session_category.category_id', $categoryIds)
            ->where(fn ($q) => $q->where('training_sessions.date', $date)->orWhere('training_sessions.moved_from', $date))
            ->get(['training_session_category.category_id', 'training_sessions.start_time', 'training_sessions.date', 'training_sessions.moved_from']);

        $heldSlots = $sessionRows->where('date', $date)
            ->map(fn ($row) => ((int) $row->category_id).'|'.$row->start_time)
            ->flip();
        $movedAwayCategories = $sessionRows->where('moved_from', $date)
            ->pluck('category_id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        $missing = $scheduled
            ->reject(fn ($slot) => isset($heldSlots[((int) $slot->category_id).'|'.$slot->start_time]) || isset($movedAwayCategories[(int) $slot->category_id]))
            ->pluck('category_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values();
        if ($missing->isEmpty() || ClubClosure::where('start_date', '<=', $date)->where('end_date', '>=', $date)->exists()) {
            return;
        }

        DB::transaction(function () use ($missing, $today) {
            foreach ($missing as $categoryId) {
                $this->generator->forMonth($categoryId, $today->year, $today->month);
            }
        });
    }
}
