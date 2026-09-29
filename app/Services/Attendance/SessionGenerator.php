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
 * key ignores slots that already exist, including cancelled ones, and dates
 * whose sessions were moved away from are never regenerated. A slot the
 * category already attends through another category's joint pre-season
 * session is left free too.
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

        $moved = TrainingSession::where('category_id', $categoryId)
            ->whereNotNull('moved_from')
            ->whereBetween('moved_from', [$from, $to])
            ->pluck('moved_from')
            ->flip();

        $joint = DB::table('training_sessions')
            ->join('training_session_category', 'training_session_category.training_session_id', '=', 'training_sessions.id')
            ->where('training_session_category.category_id', $categoryId)
            ->where('training_sessions.category_id', '!=', $categoryId)
            ->whereBetween('training_sessions.date', [$from, $to])
            ->get(['training_sessions.date', 'training_sessions.start_time'])
            ->mapWithKeys(fn ($s) => ["{$s->date} {$s->start_time}" => true]);

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
                    || isset($moved[$date])
                    || isset($joint["{$date} {$schedule->start_time}"])) {
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

        return DB::transaction(function () use ($rows, $categoryId, $from, $to) {
            $inserted = $rows === [] ? 0 : DB::table('training_sessions')->insertOrIgnore($rows);

            // insertOrIgnore returns no ids, and a prior call may have inserted
            // sessions but been interrupted before linking them: link every
            // session of this category and month that has no pivot row yet, in
            // one insert-select. insertOrIgnoreUsing tolerates a concurrent
            // request linking the same session first.
            DB::table('training_session_category')->insertOrIgnoreUsing(
                ['training_session_id', 'category_id'],
                DB::table('training_sessions as s')
                    ->select('s.id', 's.category_id')
                    ->where('s.category_id', $categoryId)
                    ->whereBetween('s.date', [$from, $to])
                    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('training_session_category as p')
                        ->whereColumn('p.training_session_id', 's.id')
                        ->whereColumn('p.category_id', 's.category_id')),
            );

            return $inserted;
        });
    }
}
