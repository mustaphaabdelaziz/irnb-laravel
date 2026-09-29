<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\TrainingSchedule;
use App\Services\Attendance\CalendarFeed;
use App\Services\Attendance\PreseasonProgress;
use App\Services\Attendance\SessionGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/** The attendance calendar (route `attendance.index`). */
class AttendanceCalendarController extends Controller
{
    public function __construct(
        private readonly SessionGenerator $generator,
        private readonly CalendarFeed $feed,
        private readonly PreseasonProgress $preseason,
    ) {}

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $categories = Category::orderBy('id')->get()
            ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])->values();

        return Inertia::render('Attendance/Index', [
            'categories' => $categories,
            ...$this->month($data, $categories),
        ]);
    }

    /** One category's month. Opening it generates its planned sessions. */
    private function month(array $data, Collection $categories): array
    {
        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : data_get($categories->first(), 'id');
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month'] ?? now()->format('Y-m'));
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
}
