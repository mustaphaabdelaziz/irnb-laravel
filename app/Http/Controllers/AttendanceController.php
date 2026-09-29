<?php

namespace App\Http\Controllers;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Category;
use App\Models\PreseasonTarget;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Attendance\SessionGenerator;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    /** Month calendar of one category. Opening a month generates its planned sessions. */
    public function index(Request $request, SessionGenerator $generator): Response
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $categories = Category::orderBy('id')->get()
            ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])->values();
        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : data_get($categories->first(), 'id');
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month'] ?? now()->format('Y-m'));

        $sessions = collect();
        $preseason = null;
        $hasSchedule = false;

        if ($categoryId !== null) {
            $generator->forMonth($categoryId, $anchor->year, $anchor->month);

            $sessions = TrainingSession::where('category_id', $categoryId)
                ->whereBetween('date', [$anchor->startOfMonth()->toDateString(), $anchor->endOfMonth()->toDateString()])
                ->withCount('attendances')
                ->orderBy('date')->orderBy('start_time')
                ->get()
                ->map(fn (TrainingSession $s) => [
                    'id' => $s->id, 'date' => $s->date, 'start_time' => $s->start_time, 'end_time' => $s->end_time,
                    'kind' => $s->kind->value, 'state' => $s->state->value, 'theme' => $s->theme,
                    'marked' => $s->attendances_count,
                ]);

            $season = Season::forDate($anchor);
            $preseason = [
                'season' => $season->label(),
                'done' => TrainingSession::where('category_id', $categoryId)
                    ->where('kind', SessionKind::Preseason->value)
                    ->where('state', SessionState::Held->value)
                    ->whereBetween('date', [$season->start()->toDateString(), $season->end()->toDateString()])
                    ->count(),
                'target' => PreseasonTarget::where('category_id', $categoryId)
                    ->where('season_start_year', $season->startYear)->value('target_count'),
            ];
            $hasSchedule = TrainingSchedule::where('category_id', $categoryId)->exists();
        }

        return Inertia::render('Attendance/Index', [
            'categories' => $categories,
            'categoryId' => $categoryId,
            'month' => $anchor->format('Y-m'),
            'sessions' => $sessions,
            'preseason' => $preseason,
            'hasSchedule' => $hasSchedule,
        ]);
    }
}
