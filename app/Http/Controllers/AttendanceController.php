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
use App\Services\Player\RegistrationDisplay;
use Illuminate\Database\Eloquent\Collection;
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
        $rowCategories = $joint ? $roster->categoryIdsIn($players, $session->branch_id) : [];

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
            'rows' => $players->map(function (Player $p) use ($marks, $categories, $joint, $rowCategories) {
                $mark = $marks->get($p->id);

                return [
                    'player_id' => $p->id,
                    'name' => $p->fullname,
                    'picture' => $p->picture_url,
                    // Where a player comes from only matters when several categories share the session.
                    // Once marked, the mark's own category_id (fixed at marking time) wins over the
                    // player's current one in this branch, so a later change never retags a frozen session.
                    'category' => $joint ? $categories->get($mark?->category_id ?? $rowCategories[$p->id] ?? null) : null,
                    'status' => $mark?->status ?? AttendanceStatus::Present->value,
                    'minutes' => $mark?->minutes,
                    'reason' => $mark?->reason?->value,
                    'note' => $mark?->note,
                ];
            })->values(),
            'candidates' => $this->candidates($session, $players),
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

    /**
     * Anyone to add by hand, whatever their status, category or branch: not archived,
     * still a member somewhere on the session date, not already listed; searched by
     * full name. The session's active members come first, with that branch's values.
     * Everyone else is a guest, with their main values and the branches they're still in.
     *
     * @param  Collection<int, Player>  $listed
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function candidates(TrainingSession $session, Collection $listed): \Illuminate\Support\Collection
    {
        $display = RegistrationDisplay::make();
        $branchNames = Branch::all()->mapWithKeys(fn (Branch $b) => [$b->id => $b->localized_name]);
        $categoryNames = Category::all()->mapWithKeys(fn (Category $c) => [$c->id => $c->localized_name]);
        $statusNames = PlayerStatus::all()->mapWithKeys(fn (PlayerStatus $s) => [$s->id => $s->localized_name]);
        $branchId = $session->branch_id;
        $inBranch = fn (Player $p) => $branchId === null || $display->isActiveIn($p, $branchId);

        return Player::with('registrations')
            ->registeredWhere(activeOn: $session->date)
            ->where('archived', false)
            ->whereNotIn('id', $listed->modelKeys())
            ->orderBy('lastname')->orderBy('firstname')
            ->get([...Roster::COLUMNS, 'status_id', 'left_at'])
            // The session's branch members first (a stable sort keeps the name order); others are guests.
            ->sortBy(fn (Player $p) => ! $inBranch($p))
            ->map(function (Player $p) use ($display, $branchId, $inBranch, $branchNames, $categoryNames, $statusNames) {
                $values = $display->values($p, $inBranch($p) ? $branchId : null);

                return [
                    'id' => $p->id,
                    'name' => $p->fullname,
                    'picture' => $p->picture_url,
                    'status' => $statusNames->get($values['status_id']),
                    'category' => $categoryNames->get($values['category_id']),
                    'branches' => $p->registrations
                        ->filter(fn ($r) => $display->isActiveIn($p, (int) $r->branch_id))
                        ->sortBy('branch_id')
                        ->map(fn ($r) => $branchNames->get($r->branch_id))
                        ->implode(', '),
                    'in_branch' => $inBranch($p),
                ];
            })
            ->values();
    }
}
