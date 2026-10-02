<?php

namespace App\Services\Attendance;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ClubClosure;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a category's weekly schedule into planned sessions, one month at a
 * time. Called whenever a month is opened (the desktop app has no reliable
 * scheduler), so it must be idempotent: the unique (category, date, start)
 * key ignores slots that already exist, including cancelled ones, and dates
 * whose sessions were moved away from are never regenerated. A slot the
 * category already attends through another category's joint pre-season
 * session is left free too.
 *
 * A generated month is marked (GenerationMarks) and skipped until one of its
 * inputs changes, so opening the calendar again only reads.
 */
final class SessionGenerator
{
    /** Generates one category's month, unless it is marked as generated from the current inputs. */
    public function forMonth(int $categoryId, int $year, int $month): int
    {
        $first = CarbonImmutable::create($year, $month, 1);
        if (GenerationMarks::has($categoryId, $first->format('Y-m'))) {
            return 0;
        }

        $version = GenerationMarks::version(); // before reading any input
        $from = $first->toDateString();
        $to = $first->endOfMonth()->toDateString();

        $schedules = TrainingSchedule::where('category_id', $categoryId)
            ->where('valid_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from))
            ->orderBy('id')
            ->get();

        if ($schedules->isEmpty()) {
            return 0;
        }

        $closures = ClubClosure::where('start_date', '<=', $to)->where('end_date', '>=', $from)->get(['start_date', 'end_date']);

        return $this->generate($categoryId, $first, $schedules, $closures, $version);
    }

    /**
     * Generates every category's months touched by [from, to] ('Y-m-d') that
     * are not marked yet. Schedules, marks and closures are read once for the
     * whole range; with every month marked this only reads.
     */
    public function forRange(string $from, string $to): void
    {
        $months = GenerationMarks::months($from, $to);
        $firstMonth = $months[0];
        $lastMonth = $months[count($months) - 1];
        $rangeFrom = $firstMonth.'-01';
        $rangeTo = CarbonImmutable::createFromFormat('!Y-m-d', $lastMonth.'-01')->endOfMonth()->toDateString();

        $version = GenerationMarks::version(); // before reading any input
        $schedules = TrainingSchedule::where('valid_from', '<=', $rangeTo)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $rangeFrom))
            ->orderBy('category_id')->orderBy('id')
            ->get()
            ->groupBy('category_id');

        if ($schedules->isEmpty()) {
            return;
        }

        $marked = GenerationMarks::marked($schedules->keys()->map(fn ($id) => (int) $id)->all(), $firstMonth, $lastMonth);
        $pending = [];
        foreach ($months as $month) {
            $first = CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01');
            $monthFrom = $first->toDateString();
            $monthTo = $first->endOfMonth()->toDateString();

            foreach ($schedules as $categoryId => $categorySchedules) {
                if (isset($marked["{$categoryId}:{$month}"])) {
                    continue;
                }
                $active = $categorySchedules
                    ->filter(fn (TrainingSchedule $s) => $s->valid_from <= $monthTo && ($s->valid_to === null || $s->valid_to >= $monthFrom))
                    ->values();
                if ($active->isNotEmpty()) {
                    $pending[] = [(int) $categoryId, $first, $active];
                }
            }
        }

        if ($pending === []) {
            return;
        }

        $closures = ClubClosure::where('start_date', '<=', $rangeTo)->where('end_date', '>=', $rangeFrom)->get(['start_date', 'end_date']);

        DB::transaction(function () use ($pending, $closures, $version) {
            foreach ($pending as [$categoryId, $first, $active]) {
                $this->generate($categoryId, $first, $active, $closures, $version);
            }
        });
    }

    /**
     * @param  Collection<int, TrainingSchedule>  $schedules  the category's schedules valid in the month
     * @param  Collection<int, ClubClosure>  $closures  at least every closure overlapping the month
     * @param  int  $version  GenerationMarks::version() read before the inputs
     */
    private function generate(int $categoryId, CarbonImmutable $first, Collection $schedules, Collection $closures, int $version): int
    {
        $from = $first->toDateString();
        $to = $first->endOfMonth()->toDateString();

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

        return DB::transaction(function () use ($rows, $categoryId, $first, $from, $to, $version) {
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

            // Not marked if an input changed since $version: the next view generates again.
            GenerationMarks::mark($categoryId, $first->format('Y-m'), $version);

            return $inserted;
        });
    }
}
