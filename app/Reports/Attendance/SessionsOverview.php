<?php

namespace App\Reports\Attendance;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\TrainingSession;
use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Support\Season;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Training sessions of a period: held, cancelled or still planned, with the
 * coach as written on the session and how many players were marked. A
 * scheduled session says nothing about attendance until it is marked.
 */
class SessionsOverview extends Report
{
    public function key(): string
    {
        return 'sessions';
    }

    public function category(): string
    {
        return 'attendance';
    }

    public function module(): string
    {
        return 'attendance';
    }

    public function icon(): string
    {
        return 'calendar';
    }

    public function filters(): array
    {
        return [
            Filter::date('from', 'reports.filter.from'),
            Filter::date('to', 'reports.filter.to'),
            Filter::select('branch_id', 'reports.filter.branch', Branch::orderBy('name')->get()
                ->map(fn (Branch $b) => ['value' => $b->id, 'label' => $b->localized_name])->all()),
            Filter::multi('category_id', 'col.category', Category::orderBy('name')->get()
                ->map(fn (Category $c) => ['value' => $c->id, 'label' => $c->localized_name])->all()),
            Filter::multi('state', 'col.status', array_map(
                fn (string $s) => ['value' => $s, 'label' => UiLang::get('att.state.'.$s)], SessionState::values())),
            Filter::multi('kind', 'col.type', array_map(
                fn (string $k) => ['value' => $k, 'label' => UiLang::get('att.kind.'.$k)], SessionKind::values())),
            Filter::multi('coach', 'reports.col.coach', TrainingSession::query()->whereNotNull('coach')->where('coach', '!=', '')
                ->distinct()->orderBy('coach')->pluck('coach')->map(fn (string $c) => ['value' => $c, 'label' => $c])->all()),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('date', 'col.date', Column::DATE),
            new Column('time', 'reports.col.time'),
            new Column('branch', 'reports.filter.branch'),
            new Column('category', 'col.category'),
            new Column('kind', 'col.type'),
            new Column('state', 'col.status'),
            new Column('coach', 'reports.col.coach'),
            new Column('marked', 'reports.col.players_marked', Column::NUMBER),
        ];
    }

    public function sortable(): array
    {
        return ['date' => 'training_sessions.date', 'coach' => 'training_sessions.coach', 'marked' => 'attendances_count'];
    }

    public function defaultSort(): array
    {
        return ['date', 'desc'];
    }

    public function rows(ReportFilters $filters): Builder
    {
        return $this->base($filters)->with(['branch', 'category', 'categories'])->withCount('attendances');
    }

    /** @param TrainingSession $record */
    public function row(mixed $record): array
    {
        $categories = $record->categories->isNotEmpty() ? $record->categories : collect([$record->category])->filter();

        return [
            'date' => substr((string) $record->date, 0, 10),
            'time' => trim(substr((string) $record->start_time, 0, 5).'–'.substr((string) $record->end_time, 0, 5), '–'),
            'branch' => $record->branch?->localized_name,
            'category' => $categories->map(fn (Category $c) => $c->localized_name)->implode(', '),
            'kind' => $record->kind ? UiLang::get('att.kind.'.$record->kind->value) : null,
            'state' => $record->state ? UiLang::get('att.state.'.$record->state->value) : null,
            'coach' => $record->coach,
            'marked' => (int) $record->attendances_count,
        ];
    }

    public function summary(ReportFilters $filters): array
    {
        $byState = $this->base($filters)->toBase()->groupBy('training_sessions.state')
            ->selectRaw('training_sessions.state as s, COUNT(*) as c')->pluck('c', 's');

        return [
            ['label' => 'att.stats.held_sessions', 'value' => (int) ($byState['held'] ?? 0), 'format' => 'number'],
            ['label' => 'att.stats.cancelled_sessions', 'value' => (int) ($byState['cancelled'] ?? 0), 'format' => 'number'],
            ['label' => 'reports.kpi.planned_sessions', 'value' => (int) ($byState['planned'] ?? 0), 'format' => 'number'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        $byCoach = $this->base($filters)->toBase()->where('training_sessions.state', SessionState::Held->value)
            ->groupBy('training_sessions.coach')->selectRaw('training_sessions.coach as c, COUNT(*) as n')->orderByDesc('n')->get();

        return [
            ['type' => 'bar', 'title' => 'reports.chart.held_by_coach', 'unit' => 'reports.kpi.sessions', 'stats' => $byCoach
                ->map(fn ($r) => ['key' => (string) ($r->c ?? 'none'), 'label' => $r->c ?: UiLang::get('reports.none'), 'count' => (int) $r->n])->all()],
        ];
    }

    /** @return Builder<TrainingSession> */
    private function base(ReportFilters $filters): Builder
    {
        // Without a start date the overview opens with the current season.
        $from = $filters->get('from') ?? Season::current()->start()->toDateString();
        $query = TrainingSession::query()->whereDate('training_sessions.date', '>=', $from);

        if ($filters->filled('to')) {
            $query->whereDate('training_sessions.date', '<=', $filters->get('to'));
        }
        if ($filters->filled('branch_id')) {
            $query->where('training_sessions.branch_id', $filters->get('branch_id'));
        }
        // A pre-season session can take several categories (training_session_category).
        if ($filters->filled('category_id')) {
            $ids = $filters->get('category_id');
            $query->where(fn (Builder $q) => $q->whereIn('training_sessions.category_id', $ids)
                ->orWhereIn('training_sessions.id', DB::table('training_session_category')->whereIn('category_id', $ids)->select('training_session_id')));
        }
        foreach (['state', 'kind', 'coach'] as $column) {
            if ($filters->filled($column)) {
                $query->whereIn('training_sessions.'.$column, $filters->get($column));
            }
        }

        return $query;
    }
}
