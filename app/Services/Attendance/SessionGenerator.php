<?php

namespace App\Services\Attendance;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ClubClosure;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns a category's weekly schedule into planned sessions, one month at a
 * time. Called whenever a month is opened (the desktop app has no reliable
 * scheduler), so it must be idempotent: the unique (category, date, start)
 * key ignores slots that already exist, including cancelled ones, and a slot
 * whose session was moved elsewhere (`moved_from`) is skipped.
 */
final class SessionGenerator
{
    public function forMonth(int $categoryId, int $year, int $month): int
    {
        $first = CarbonImmutable::create($year, $month, 1);
        $from = $first->toDateString();
        $to = $first->endOfMonth()->toDateString();

        $schedules = TrainingSchedule::where('category_id', $categoryId)
            ->where('valid_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from))
            ->get();

        if ($schedules->isEmpty()) {
            return 0;
        }

        $closures = ClubClosure::where('start_date', '<=', $to)->where('end_date', '>=', $from)->get(['start_date', 'end_date']);

        $moved = TrainingSession::whereIn('schedule_id', $schedules->modelKeys())
            ->whereNotNull('moved_from')
            ->get(['schedule_id', 'moved_from'])
            ->mapWithKeys(fn (TrainingSession $s) => ["{$s->schedule_id}|{$s->moved_from}" => true]);

        $now = now();
        $rows = [];

        for ($day = $first; $day->toDateString() <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();

            if ($closures->contains(fn (ClubClosure $c) => $c->start_date <= $date && $c->end_date >= $date)) {
                continue;
            }

            foreach ($schedules as $schedule) {
                if ($schedule->weekday !== $day->dayOfWeekIso
                    || $schedule->valid_from > $date
                    || ($schedule->valid_to !== null && $schedule->valid_to < $date)
                    || isset($moved["{$schedule->id}|{$date}"])) {
                    continue;
                }

                $rows[] = [
                    'category_id' => $categoryId,
                    'schedule_id' => $schedule->id,
                    'date' => $date,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'kind' => SessionKind::Regular->value,
                    'state' => SessionState::Planned->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $rows === [] ? 0 : DB::table('training_sessions')->insertOrIgnore($rows);
    }
}
