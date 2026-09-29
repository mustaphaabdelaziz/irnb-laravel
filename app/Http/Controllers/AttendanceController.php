<?php

namespace App\Http\Controllers;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Http\Requests\SaveAttendanceMarksRequest;
use App\Models\Category;
use App\Models\Player;
use App\Models\PreseasonTarget;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\Roster;
use App\Services\Attendance\SessionGenerator;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
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

    /** One session: its roster with marks (everyone present until first saved) and its log. */
    public function show(TrainingSession $session, Roster $roster): Response
    {
        $session->load('category');
        $marks = $session->attendances()->get()->keyBy('player_id');
        $players = $roster->forSession($session);

        return Inertia::render('Attendance/Session', [
            'session' => [
                'id' => $session->id, 'date' => $session->date, 'start_time' => $session->start_time, 'end_time' => $session->end_time,
                'kind' => $session->kind->value, 'state' => $session->state->value, 'cancel_reason' => $session->cancel_reason,
                'moved_from' => $session->moved_from, 'coach' => $session->coach, 'theme' => $session->theme, 'notes' => $session->notes,
                'category' => $session->category?->localized_name,
                'category_id' => $session->category_id,
            ],
            'saved' => $marks->isNotEmpty(),
            'rows' => $players->map(function (Player $p) use ($marks) {
                $mark = $marks->get($p->id);

                return [
                    'player_id' => $p->id,
                    'name' => trim("{$p->lastname} {$p->firstname}"),
                    'status' => $mark?->status->value ?? AttendanceStatus::Present->value,
                    'minutes' => $mark?->minutes,
                    'reason' => $mark?->reason?->value,
                    'note' => $mark?->note,
                ];
            })->values(),
            'candidates' => Player::where('archived', false)->whereNull('left_at')
                ->whereNotIn('id', $players->modelKeys())
                ->orderBy('lastname')->orderBy('firstname')
                ->get(['id', 'firstname', 'lastname'])
                ->map(fn (Player $p) => ['id' => $p->id, 'name' => trim("{$p->lastname} {$p->firstname}")]),
            'lastCoach' => TrainingSession::where('category_id', $session->category_id)
                ->whereKeyNot($session->id)->whereNotNull('coach')
                ->orderByDesc('date')->value('coach'),
            'statuses' => AttendanceStatus::values(),
            'reasons' => AbsenceReason::values(),
        ]);
    }

    public function saveMarks(SaveAttendanceMarksRequest $request, TrainingSession $session, MarkRecorder $recorder): RedirectResponse
    {
        $data = $request->validated();
        $recorder->save($session, $data['marks'], $request->user(), [
            'coach' => $data['coach'] ?? null, 'theme' => $data['theme'] ?? null, 'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'flash.attendance_saved');
    }
}
