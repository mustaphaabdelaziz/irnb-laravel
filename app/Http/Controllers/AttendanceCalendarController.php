<?php

namespace App\Http\Controllers;

use App\Enums\SessionKind;
use App\Models\Category;
use App\Models\TrainingSchedule;
use App\Services\Attendance\CalendarFeed;
use App\Services\Attendance\PreseasonProgress;
use App\Services\Attendance\SessionGenerator;
use App\Support\AttendanceSettings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The attendance calendar: one route (`attendance.index`, gated as view by
 * its name) with a `view` query parameter. Month shows one category; the
 * other views show every category and first generate the shown range for
 * all of them (the generator is idempotent, and skips months already
 * generated from the current inputs).
 */
class AttendanceCalendarController extends Controller
{
    public const VIEWS = ['month', 'week', 'agenda', 'timeline'];

    /** The timeline grows one month at a time up to this many months. */
    public const TIMELINE_MAX_MONTHS = 12;

    public function __construct(
        private readonly SessionGenerator $generator,
        private readonly CalendarFeed $feed,
        private readonly PreseasonProgress $preseason,
    ) {}

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'view' => ['nullable', Rule::in(self::VIEWS)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'month' => ['nullable', 'date_format:Y-m'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
            'kind' => ['nullable', Rule::enum(SessionKind::class)],
        ]);
        $view = $data['view'] ?? 'month';
        // Lazy, so a partial reload while navigating a view skips them (see Index.vue).
        $list = null;
        $categories = function () use (&$list): Collection {
            return $list ??= Category::orderBy('id')->get()
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])->values();
        };

        $props = match ($view) {
            'week' => $this->week($data),
            'agenda' => $this->agenda($data),
            'timeline' => $this->timeline($data),
            default => $this->month($data, $categories),
        };

        return Inertia::render('Attendance/Index', [
            'view' => $view,
            'categories' => $categories,
            'attendanceCodes' => fn () => AttendanceSettings::codes(),
            ...$props,
        ]);
    }

    /** One category's month. Opening it generates its planned sessions. */
    private function month(array $data, Closure $categories): array
    {
        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : data_get($categories()->first(), 'id');
        $anchor = $this->monthAnchor($data);
        $props = ['categoryId' => $categoryId, 'month' => $anchor->format('Y-m'), 'sessions' => [], 'preseason' => null, 'hasSchedule' => false];

        if ($categoryId === null) {
            return $props;
        }

        $this->generator->forMonth($categoryId, $anchor->year, $anchor->month);

        return [
            ...$props,
            'sessions' => $this->feed->sessions($anchor->toDateString(), $anchor->endOfMonth()->toDateString(), $categoryId),
            'preseason' => $this->preseason->forCategory($categoryId, $anchor),
            'hasSchedule' => TrainingSchedule::where('category_id', $categoryId)->exists(),
        ];
    }

    /** Monday to Sunday around `date`, every category. */
    private function week(array $data): array
    {
        $day = isset($data['date']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['date']) : $this->defaultDay($data);
        $start = $day->subDays($day->dayOfWeekIso - 1);
        $end = $start->addDays(6);
        $this->feed->generateAll($start->toDateString(), $end->toDateString());

        return [
            'categoryId' => $this->categoryFilter($data),
            'month' => $start->format('Y-m'),
            'week' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'sessions' => $this->feed->sessions($start->toDateString(), $end->toDateString()),
        ];
    }

    /** The month as a chronological list, every category or those including the filter. */
    private function agenda(array $data): array
    {
        $anchor = $this->monthAnchor($data);
        $from = $anchor->toDateString();
        $to = $anchor->endOfMonth()->toDateString();
        $this->feed->generateAll($from, $to);
        $categoryId = $this->categoryFilter($data);

        return [
            'categoryId' => $categoryId,
            'month' => $anchor->format('Y-m'),
            'sessions' => $this->feed->sessions($from, $to, $categoryId),
        ];
    }

    /**
     * Months `from`..`to` (default: `month`, else the current one) as one
     * chronological list of sessions, closures and pre-season milestones.
     */
    private function timeline(array $data): array
    {
        $to = CarbonImmutable::createFromFormat('!Y-m', $data['to'] ?? $data['month'] ?? now()->format('Y-m'));
        $from = isset($data['from']) ? CarbonImmutable::createFromFormat('!Y-m', $data['from']) : $to;
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }
        if (($to->year - $from->year) * 12 + $to->month - $from->month >= self::TIMELINE_MAX_MONTHS) {
            $from = $to->subMonths(self::TIMELINE_MAX_MONTHS - 1);
        }

        $fromDate = $from->toDateString();
        $toDate = $to->endOfMonth()->toDateString();
        $this->feed->generateAll($fromDate, $toDate);
        $categoryId = $this->categoryFilter($data);
        $kind = $data['kind'] ?? null;

        return [
            'categoryId' => $categoryId,
            'kind' => $kind,
            'month' => $to->format('Y-m'),
            'from' => $from->format('Y-m'),
            'to' => $to->format('Y-m'),
            'events' => $this->feed->timeline($fromDate, $toDate, $categoryId, $kind),
        ];
    }

    private function monthAnchor(array $data): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $data['month'] ?? now()->format('Y-m'));
    }

    /** Today when no month is given or it is the current one, else that month's first day. */
    private function defaultDay(array $data): CarbonImmutable
    {
        $month = $data['month'] ?? null;

        return $month === null || $month === now()->format('Y-m')
            ? CarbonImmutable::createFromFormat('!Y-m-d', now()->toDateString())
            : CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01');
    }

    /** Views other than month show every category unless one is picked. */
    private function categoryFilter(array $data): ?int
    {
        return isset($data['category_id']) ? (int) $data['category_id'] : null;
    }
}
