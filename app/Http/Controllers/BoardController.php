<?php

namespace App\Http\Controllers;

use App\Models\BoardMeeting;
use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\BoardTask;
use App\Models\BoardTerm;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Services\Export\ExcelExporter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    public function index(): Response
    {
        $tasks = BoardTask::query();
        $currentTerm = BoardTerm::where('is_current', true)->first() ?? BoardTerm::latest('id')->first();

        // Meetings/tasks scoped to the current term's date window when it has one.
        $termMeetings = BoardMeeting::query();
        if ($currentTerm?->start_date) {
            $termMeetings->where('meeting_date', '>=', $currentTerm->start_date);
            if ($currentTerm->end_date) {
                $termMeetings->where('meeting_date', '<=', $currentTerm->end_date->copy()->endOfDay());
            }
        }

        return Inertia::render('Board/Index', [
            'stats' => [
                'members' => BoardMember::where('status', 'active')->count(),
                'meetings_held' => BoardMeeting::where('status', 'held')->count(),
                'meetings_total' => (clone $termMeetings)->count(),
                'tasks_open' => (clone $tasks)->whereIn('status', ['not_started', 'in_progress'])->count(),
                'tasks_completed' => (clone $tasks)->where('status', 'completed')->count(),
                'tasks_total' => (clone $tasks)->count(),
                'tasks_overdue' => (clone $tasks)->whereIn('status', ['not_started', 'in_progress'])
                    ->whereNotNull('due_date')->whereDate('due_date', '<', now())->count(),
            ],
            'currentTerm' => $currentTerm,
            'upcomingMeetings' => BoardMeeting::where('status', 'scheduled')
                ->where('meeting_date', '>=', now()->startOfDay())
                ->orderBy('meeting_date')->limit(5)->get(),
            'recentTasks' => BoardTask::with('member:id,name')
                ->whereIn('status', ['not_started', 'in_progress'])
                ->orderByRaw('due_date is null, due_date asc')->limit(6)->get(),
            'membersByRole' => BoardMember::where('status', 'active')
                ->with('roleModel:id,name,sort_order')
                ->get()
                ->sortBy(fn (BoardMember $m) => [$m->roleModel?->sort_order ?? 99, $m->sort_order])
                ->values(),
        ]);
    }

    /**
     * Month calendar of board meetings (clickable + createable), plus read-only
     * markers for board-task due dates and unpaid subscription due dates. The
     * grid shows the queried month padded by a week each side so leading/trailing
     * cells are populated; `?month=YYYY-MM` drives prev/next navigation.
     */
    public function calendar(Request $request): Response
    {
        $anchor = $request->query('month')
            ? Carbon::createFromFormat('Y-m', $request->query('month'))->startOfMonth()
            : Carbon::now()->startOfMonth();

        $from = $anchor->copy()->subDays(7)->startOfDay();
        $to = $anchor->copy()->endOfMonth()->addDays(7)->endOfDay();

        $meetings = BoardMeeting::whereBetween('meeting_date', [$from, $to])
            ->orderBy('meeting_date')
            ->get(['id', 'title', 'meeting_date', 'type', 'status'])
            ->map(fn (BoardMeeting $m) => [
                'kind' => 'meeting',
                'id' => $m->id,
                'title' => $m->title,
                'date' => $m->meeting_date->toDateString(),
                'time' => $m->meeting_date->format('H:i'),
                'status' => $m->status,
                'type' => $m->type,
            ]);

        $tasks = BoardTask::whereNotNull('due_date')
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->with('member:id,name')
            ->get(['id', 'title', 'due_date', 'status', 'board_member_id'])
            ->map(fn (BoardTask $t) => [
                'kind' => 'task',
                'id' => $t->id,
                'title' => $t->title,
                'date' => $t->due_date->toDateString(),
                'status' => $t->status,
                'assignee' => $t->member?->name,
            ]);

        $subscriptions = PlayerSubscription::whereNotNull('due_date')
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->with('player:id,firstname,lastname')
            ->get()
            ->filter(fn (PlayerSubscription $s) => $s->payment_status !== 'paid')
            ->map(fn (PlayerSubscription $s) => [
                'kind' => 'subscription',
                'id' => $s->id,
                'title' => trim(($s->player?->firstname ?? '').' '.($s->player?->lastname ?? '')) ?: 'Subscription',
                'date' => $s->due_date->toDateString(),
                'amount' => (float) $s->remaining_amount,
            ])->values();

        return Inertia::render('Board/Calendar', [
            'month' => $anchor->format('Y-m'),
            'events' => $meetings->concat($tasks)->concat($subscriptions)->values(),
        ]);
    }

    public function members(Request $request): Response
    {
        $terms = BoardTerm::orderByDesc('is_current')->orderByDesc('start_date')->orderByDesc('id')->get();
        $currentTerm = $terms->firstWhere('is_current', true) ?? $terms->first();

        // Selected term (?term=id) drives which members show; default = current.
        $selectedTermId = $request->integer('term') ?: $currentTerm?->id;

        $members = BoardMember::withCount('tasks')
            ->with('roleModel:id,name,sort_order')
            ->when($selectedTermId, fn ($q) => $q->where('board_term_id', $selectedTermId))
            ->orderBy('status')
            ->get()
            ->sortBy(fn (BoardMember $m) => [$m->status === 'active' ? 0 : 1, $m->roleModel?->sort_order ?? 99, $m->sort_order])
            ->values();

        return Inertia::render('Board/Members', [
            'members' => $members,
            'terms' => $terms,
            'roles' => BoardRole::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'selectedTermId' => $selectedTermId,
            'players' => Player::where('archived', false)
                ->orderBy('lastname')->orderBy('firstname')
                ->get(['id', 'firstname', 'lastname', 'membership_id'])
                ->map(fn (Player $p) => [
                    'id' => $p->id,
                    'name' => trim($p->firstname.' '.$p->lastname),
                    'membership_id' => $p->membership_id,
                ]),
        ]);
    }

    public function meetings(): Response
    {
        return Inertia::render('Board/Meetings', [
            'meetings' => BoardMeeting::withCount(['attendances as present_count' => fn ($q) => $q->where('status', 'present')])
                ->withCount('tasks')
                ->orderByDesc('meeting_date')->get(),
        ]);
    }

    public function meeting(BoardMeeting $meeting): Response
    {
        $meeting->load(['attendances.member', 'tasks.member', 'createdBy:id,name']);
        $present = $meeting->attendances->keyBy('board_member_id');

        return Inertia::render('Board/Meeting', [
            'meeting' => $meeting,
            'members' => BoardMember::where('status', 'active')->orderBy('sort_order')->get()
                ->map(fn (BoardMember $m) => [
                    'id' => $m->id, 'name' => $m->name, 'role' => $m->role,
                    'attendance' => $present[$m->id]->status ?? null,
                ]),
            'allMembers' => BoardMember::orderBy('sort_order')->get(['id', 'name', 'role']),
        ]);
    }

    public function tasks(): Response
    {
        $columns = ['not_started', 'in_progress', 'completed', 'cancelled'];
        $all = BoardTask::with(['member:id,name', 'meeting:id,title'])
            ->orderByRaw('due_date is null, due_date asc')->get();

        return Inertia::render('Board/Tasks', [
            'columns' => collect($columns)->mapWithKeys(fn ($c) => [$c => $all->where('status', $c)->values()]),
            'members' => BoardMember::where('status', 'active')->orderBy('sort_order')->get(['id', 'name', 'role']),
            'meetings' => BoardMeeting::orderByDesc('meeting_date')->limit(50)->get(['id', 'title', 'meeting_date']),
            'stats' => $this->taskStats($all),
        ]);
    }

    /**
     * Task evaluation metrics for the board Tasks page (Stats view).
     *
     * @param  \Illuminate\Support\Collection<int, BoardTask>  $all
     * @return array<string, mixed>
     */
    private function taskStats(\Illuminate\Support\Collection $all): array
    {
        $total = $all->count();
        $completed = $all->where('status', 'completed')->count();
        $active = $all->whereIn('status', ['not_started', 'in_progress']);
        $overdue = $active->filter(fn (BoardTask $t) => $t->due_date && $t->due_date->isPast())->count();

        // Per-member evaluation: assigned / completed / completion rate. Top 3 by completed.
        $memberEval = $all->whereNotNull('board_member_id')
            ->groupBy('board_member_id')
            ->map(function ($tasks) {
                $assigned = $tasks->count();
                $done = $tasks->where('status', 'completed')->count();

                return [
                    'name' => $tasks->first()->member?->name ?? '—',
                    'assigned' => $assigned,
                    'completed' => $done,
                    'in_progress' => $tasks->where('status', 'in_progress')->count(),
                    'rate' => $assigned ? (int) round($done / $assigned * 100) : 0,
                ];
            })
            ->sortByDesc('completed')
            ->sortByDesc('rate')
            ->values();

        // Completed-per-month for the current year, and completed-per-year (evaluation).
        $year = Carbon::now()->year;
        $monthly = array_fill(1, 12, 0);
        $yearly = [];
        foreach ($all->where('status', 'completed')->whereNotNull('completed_at') as $task) {
            $yr = (int) $task->completed_at->year;
            $yearly[$yr] = ($yearly[$yr] ?? 0) + 1;
            if ($yr === $year) {
                $monthly[(int) $task->completed_at->month]++;
            }
        }
        ksort($yearly);

        return [
            'total' => $total,
            'completed' => $completed,
            'inProgress' => $all->where('status', 'in_progress')->count(),
            'notStarted' => $all->where('status', 'not_started')->count(),
            'cancelled' => $all->where('status', 'cancelled')->count(),
            'overdue' => $overdue,
            'completionRate' => $total ? (int) round($completed / $total * 100) : 0,
            'avgProgress' => $total ? (int) round($all->avg('progress')) : 0,
            'byPriority' => collect(['high', 'medium', 'low'])
                ->map(fn ($p) => ['priority' => $p, 'count' => $all->where('priority', $p)->count()])
                ->filter(fn ($r) => $r['count'] > 0)->values(),
            'topMembers' => $memberEval->take(3),
            'memberCount' => $memberEval->count(),
            'monthly' => array_values($monthly),
            'currentYear' => $year,
            'yearly' => collect($yearly)->map(fn ($count, $yr) => ['year' => $yr, 'count' => $count])->values(),
        ];
    }

    public function exportMembers(ExcelExporter $exporter)
    {
        $rows = BoardMember::orderBy('sort_order')->get()->map(fn (BoardMember $m) => [
            $m->name, $m->role, $m->email, $m->phone,
            $m->term_start?->format('Y-m-d'), $m->term_end?->format('Y-m-d'), $m->status,
        ])->all();

        return $exporter->download('Board Members', ['Name', 'Role', 'Email', 'Phone', 'Term Start', 'Term End', 'Status'], $rows, 'board-members.xlsx');
    }

    public function exportTasks(ExcelExporter $exporter)
    {
        $rows = BoardTask::with(['member:id,name', 'meeting:id,title'])->orderByDesc('id')->get()->map(fn (BoardTask $t) => [
            $t->title, $t->member?->name, $t->meeting?->title, $t->priority, $t->status,
            $t->progress.'%', $t->due_date?->format('Y-m-d'),
        ])->all();

        return $exporter->download('Board Tasks', ['Title', 'Assignee', 'Meeting', 'Priority', 'Status', 'Progress', 'Due Date'], $rows, 'board-tasks.xlsx');
    }
}
