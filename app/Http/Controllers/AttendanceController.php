<?php

namespace App\Http\Controllers;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Http\Requests\SaveAttendanceMarksRequest;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\Roster;
use App\Support\AttendanceSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The session screen. The calendar lives in AttendanceCalendarController. */
class AttendanceController extends Controller
{
    /** One session: its roster with marks (everyone present until first saved), its categories and its log. */
    public function show(TrainingSession $session, Roster $roster): Response
    {
        $marks = $session->attendances()->get()->keyBy('player_id');
        // Loaded once up front: both forSession() and orderedCategories() read
        // $session->categories, so this saves querying it twice.
        $session->loadMissing('categories');
        $players = $roster->forSession($session);
        // Primary category first, then the others of a joint pre-season session.
        $categories = $session->orderedCategories()
            ->mapWithKeys(fn (Category $c) => [$c->id => $c->localized_name]);
        $joint = $categories->count() > 1;

        return Inertia::render('Attendance/Session', [
            'session' => [
                'id' => $session->id, 'date' => $session->date, 'start_time' => $session->start_time, 'end_time' => $session->end_time,
                'kind' => $session->kind->value, 'state' => $session->state->value, 'cancel_reason' => $session->cancel_reason,
                'moved_from' => $session->moved_from, 'coach' => $session->coach, 'title' => $session->title, 'notes' => $session->notes,
                'category' => $categories->implode(' · '),
                'category_id' => $session->category_id,
                'categories' => $categories->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->values(),
            ],
            'saved' => $marks->isNotEmpty(),
            'rows' => $players->map(function (Player $p) use ($marks, $categories, $joint) {
                $mark = $marks->get($p->id);

                return [
                    'player_id' => $p->id,
                    'name' => trim("{$p->lastname} {$p->firstname}"),
                    // Where a player comes from only matters when several categories share the session.
                    // Once marked, the mark's own category_id (fixed at marking time) wins over the
                    // player's current one, so a later category change never retags a frozen session.
                    'category' => $joint ? $categories->get($mark?->category_id ?? $p->category_id) : null,
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
            'attendanceCodes' => AttendanceSettings::codes(),
            'allCategories' => $session->kind === SessionKind::Preseason
                ? Category::orderBy('id')->get()->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])->values()
                : [],
        ]);
    }

    public function saveMarks(SaveAttendanceMarksRequest $request, TrainingSession $session, MarkRecorder $recorder): RedirectResponse
    {
        $data = $request->validated();
        $recorder->save($session, $data['marks'], $request->user(), [
            'coach' => $data['coach'] ?? null, 'title' => $data['title'] ?? null, 'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'flash.attendance_saved');
    }
}
