<?php

namespace App\Services\Attendance;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\ClubClosure;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sessions shaped for the calendar views: every category taking part
 * (primary first), title, marked count and, once held, how many players had
 * each status. The timeline adds club closures and pre-season milestones.
 */
final class CalendarFeed
{
    public function __construct(
        private readonly SessionGenerator $generator,
        private readonly PreseasonProgress $preseason,
    ) {}

    /** Generates every category's planned sessions for each month the range touches (idempotent). */
    public function generateAll(string $from, string $to): void
    {
        // Only categories with a schedule overlapping the range have anything
        // to generate; skipping the rest avoids a pointless query per month.
        $categoryIds = TrainingSchedule::where('valid_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from))
            ->distinct()->orderBy('category_id')->pluck('category_id');
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $to)->startOfMonth();

        DB::transaction(function () use ($categoryIds, $from, $last) {
            for ($month = CarbonImmutable::createFromFormat('!Y-m-d', $from)->startOfMonth(); $month->lessThanOrEqualTo($last); $month = $month->addMonth()) {
                foreach ($categoryIds as $categoryId) {
                    $this->generator->forMonth((int) $categoryId, $month->year, $month->month);
                }
            }
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    public function sessions(string $from, string $to, ?int $categoryId = null, ?string $kind = null): Collection
    {
        $sessions = TrainingSession::query()
            ->when($categoryId !== null, fn ($q) => $q->includingCategory($categoryId))
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->whereBetween('date', [$from, $to])
            ->with('categories')
            ->withCount('attendances')
            ->orderBy('date')->orderBy('start_time')->orderBy('id')
            ->get();

        $summaries = $this->summaries(
            $sessions->filter(fn (TrainingSession $s) => $s->state === SessionState::Held)->modelKeys(),
        );

        return $sessions->map(fn (TrainingSession $s) => [
            'id' => $s->id,
            'date' => $s->date,
            'start_time' => $s->start_time,
            'end_time' => $s->end_time,
            'kind' => $s->kind->value,
            'state' => $s->state->value,
            'title' => $s->title,
            'cancel_reason' => $s->cancel_reason,
            'categories' => $s->orderedCategories()
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])
                ->values()->all(),
            'marked' => $s->attendances_count,
            'summary' => $summaries[$s->id] ?? null,
        ])->values();
    }

    /**
     * Sessions, club closures and pre-season milestones in [from, to], in
     * date order; on one date a closure comes first and a milestone right
     * after the session that reached it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function timeline(string $from, string $to, ?int $categoryId = null, ?string $kind = null): array
    {
        $events = $this->sessions($from, $to, $categoryId, $kind)
            ->map(fn (array $s) => $s + ['type' => 'session', 'key' => "s{$s['id']}", 'order' => 1, 'time' => $s['start_time']])
            ->all();

        $closures = ClubClosure::where('start_date', '<=', $to)->where('end_date', '>=', $from)->orderBy('start_date')->get();
        foreach ($closures as $closure) {
            $events[] = [
                'type' => 'closure', 'key' => "c{$closure->id}", 'order' => 0, 'time' => '00:00',
                'date' => max($closure->start_date, $from),
                'start_date' => $closure->start_date, 'end_date' => $closure->end_date, 'reason' => $closure->reason,
            ];
        }

        if ($kind === null || $kind === SessionKind::Preseason->value) {
            foreach ($this->preseason->milestones($from, $to, $categoryId) as $m) {
                $events[] = $m + ['type' => 'milestone', 'key' => "m{$m['milestone']}-{$m['category_id']}-{$m['session_id']}", 'order' => 2];
            }
        }

        usort($events, fn (array $a, array $b) => [$a['date'], $a['order'], $a['time']] <=> [$b['date'], $b['order'], $b['time']]);

        return $events;
    }

    /**
     * @param  array<int, int>  $sessionIds
     * @return array<int, array<string, int>> per session: status => number of players
     */
    public function summaries(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        $rows = Attendance::query()
            ->whereIn('training_session_id', $sessionIds)
            ->selectRaw('training_session_id, status, count(*) as total')
            ->groupBy('training_session_id', 'status')
            ->toBase()
            ->get();

        $summaries = [];
        foreach ($rows as $row) {
            $summaries[(int) $row->training_session_id][$row->status] = (int) $row->total;
        }

        return $summaries;
    }
}
