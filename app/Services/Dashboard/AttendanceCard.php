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
     * yet, generate its month first (idempotent, as the calendar does), so the
     * card never says "no sessions" on a training day. One query on most
     * visits; nothing on a club closure day.
     */
    private function generateToday(CarbonImmutable $today): void
    {
        $date = $today->toDateString();

        $scheduled = TrainingSchedule::where('weekday', $today->dayOfWeekIso)
            ->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->distinct()
            ->pluck('category_id')
            ->map(fn ($id) => (int) $id);
        if ($scheduled->isEmpty()) {
            return;
        }

        $withSession = DB::table('training_session_category')
            ->join('training_sessions', 'training_sessions.id', '=', 'training_session_category.training_session_id')
            ->where('training_sessions.date', $date)
            ->whereIn('training_session_category.category_id', $scheduled)
            ->distinct()
            ->pluck('training_session_category.category_id')
            ->map(fn ($id) => (int) $id);
        $missing = $scheduled->diff($withSession)->values();
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
