<?php

namespace App\Http\Controllers;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use App\Http\Requests\SaveAttendanceMarksRequest;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceStatusCatalog;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\Roster;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The session screen. The calendar lives in AttendanceCalendarController. */
class AttendanceController extends Controller
{
    /** One session: its roster with marks (everyone present until first saved), its categories and its log. */
    public function show(TrainingSession $session, Roster $roster, AttendanceStatusCatalog $catalog): Response
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
                'branch_id' => $session->branch_id,
                'roster_status_ids' => $roster->statusIdsFor($session),
                'branch' => $session->branch ? ['id' => $session->branch->id, 'name' => $session->branch->localized_name, 'logo_url' => $session->branch->logo_url] : null,
                'categories' => $categories->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->values(),
            ],
            'saved' => $marks->isNotEmpty(),
            'marksCount' => $marks->count(),
            'rows' => $players->map(function (Player $p) use ($marks, $categories, $joint) {
                $mark = $marks->get($p->id);

                return [
                    'player_id' => $p->id,
                    'name' => $p->fullname,
                    'picture' => $p->picture_url,
                    // Where a player comes from only matters when several categories share the session.
                    // Once marked, the mark's own category_id (fixed at marking time) wins over the
                    // player's current one, so a later category change never retags a frozen session.
                    'category' => $joint ? $categories->get($mark?->category_id ?? $p->category_id) : null,
                    'status' => $mark?->status ?? AttendanceStatus::Present->value,
                    'minutes' => $mark?->minutes,
                    'reason' => $mark?->reason?->value,
                    'note' => $mark?->note,
                ];
            })->values(),
            // Anyone to add by hand, whatever their status, category or branch: not archived,
            // not gone before the session date, not already listed; searched by full name;
            // the session's branch members first.
            'candidates' => Player::with(['status', 'category', 'branches'])
                ->where('archived', false)
                ->where(fn ($q) => $q->whereNull('left_at')->orWhereDate('left_at', '>', $session->date))
                ->whereNotIn('id', $players->modelKeys())
                // A legacy "left" status with no leave date has left all the same.
                ->when(Player::leftStatusId(), fn ($q, int $left) => $q->where(fn ($q) => $q->whereNull('status_id')
                    ->orWhere('status_id', '!=', $left)
                    ->orWhereNotNull('left_at')))
                ->orderBy('lastname')->orderBy('firstname')
                ->get([...Roster::COLUMNS, 'status_id'])
                // The session's branch members first (a stable sort keeps the name order); others are guests.
                ->sortBy(fn (Player $p) => $session->branch_id !== null && ! $p->branches->contains('id', $session->branch_id))
                ->map(fn (Player $p) => [
                    'id' => $p->id,
                    'name' => $p->fullname,
                    'picture' => $p->picture_url,
                    'status' => $p->status?->localized_name,
                    'category' => $p->category?->localized_name,
                    'branches' => $p->branches->map(fn ($b) => $b->localized_name)->implode(', '),
                    'in_branch' => $session->branch_id === null || $p->branches->contains('id', $session->branch_id),
                ])
                ->values(),
            'lastCoach' => TrainingSession::where('category_id', $session->category_id)
                ->where('branch_id', $session->branch_id)
                ->whereKeyNot($session->id)->whereNotNull('coach')
                ->orderByDesc('date')->value('coach'),
            // The buttons: every active status, plus a hidden custom one still used in this session.
            'statuses' => $catalog->shown($marks->pluck('status')),
            'reasons' => AbsenceReason::values(),
            'attendanceCodes' => $catalog->codes(),
            // Every category: the Edit dialog can turn an extra session into a pre-season one.
            'allCategories' => Category::orderBy('id')->get()->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])->values(),
            'branches' => Branch::orderBy('id')->get()->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->localized_name])->values(),
            'playerStatuses' => PlayerStatus::options(),
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
