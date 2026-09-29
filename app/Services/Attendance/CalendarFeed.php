<?php

namespace App\Services\Attendance;

use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Sessions shaped for the calendar views: every category taking part
 * (primary first), title, marked count and, once held, how many players had
 * each status.
 */
final class CalendarFeed
{
    public function __construct(private readonly SessionGenerator $generator) {}

    /** Generates every category's planned sessions for each month the range touches (idempotent). */
    public function generateAll(string $from, string $to): void
    {
        $categoryIds = Category::orderBy('id')->pluck('id');
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $to)->startOfMonth();

        for ($month = CarbonImmutable::createFromFormat('!Y-m-d', $from)->startOfMonth(); $month->lessThanOrEqualTo($last); $month = $month->addMonth()) {
            foreach ($categoryIds as $categoryId) {
                $this->generator->forMonth((int) $categoryId, $month->year, $month->month);
            }
        }
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
            'categories' => $s->categories
                ->sortBy(fn (Category $c) => $c->id === $s->category_id ? 0 : $c->id)
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])
                ->values()->all(),
            'marked' => $s->attendances_count,
            'summary' => $summaries[$s->id] ?? null,
        ])->values();
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
