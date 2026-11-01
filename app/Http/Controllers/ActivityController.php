<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActivityRequest;
use App\Models\Activity;
use App\Models\ActivityPhoto;
use App\Models\ActivityType;
use App\Models\BoardMember;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        // Query strings can carry arrays (?branch[]=1): anything non-scalar counts as absent.
        $scalar = fn (string $key): ?string => is_scalar($value = $request->query($key)) ? (string) $value : null;
        $branch = $scalar('branch');

        $filters = [
            'types' => array_values(array_filter(
                array_map('strval', array_filter((array) $request->query('types', []), 'is_scalar')),
                'ctype_digit',
            )),
            'branch' => $branch !== null && ctype_digit($branch) ? $branch : null,
            'from' => $this->date($scalar('from')),
            'to' => $this->date($scalar('to')),
            'search' => trim($scalar('search') ?? ''),
        ];

        $query = Activity::query()
            ->when($filters['types'], fn (Builder $q, $types) => $q->whereIn('activity_type_id', $types))
            ->when($filters['branch'], fn (Builder $q, $branch) => $q->where('branch_id', $branch))
            // An activity counts in a period when any of its days falls inside it.
            ->when($filters['from'], fn (Builder $q, $from) => $q->whereRaw('coalesce(ends_on, starts_on) >= ?', [$from]))
            ->when($filters['to'], fn (Builder $q, $to) => $q->where('starts_on', '<=', $to))
            ->when($filters['search'] !== '', function (Builder $q) use ($filters) {
                $like = '%'.$filters['search'].'%';
                $q->where(fn (Builder $w) => $w->where('title', 'like', $like)
                    ->orWhere('location', 'like', $like)
                    ->orWhere('partners', 'like', $like));
            });

        // Totals over the whole filtered set, not just the page.
        $filteredIds = fn () => (clone $query)->select('activities.id');
        $stats = [
            'count' => (clone $query)->count(),
            'beneficiaries' => (int) (clone $query)->sum('beneficiaries_count'),
            'participants' => (int) (clone $query)->sum('external_volunteers_count')
                + DB::table('activity_player')->whereIn('activity_id', $filteredIds())->count()
                + DB::table('activity_board_member')->whereIn('activity_id', $filteredIds())->count(),
        ];

        $activities = $query
            ->with([
                'type:id,name',
                'branch',
                'cover' => fn ($q) => $q->select(['activity_photos.id', 'activity_photos.activity_id', 'activity_photos.thumb_path']),
            ])
            ->withCount(['players', 'boardMembers'])
            ->orderByDesc('starts_on')->orderByDesc('id')
            ->paginate(20)->withQueryString()
            ->through(function (Activity $activity) {
                $cover = $activity->cover;
                $activity->unsetRelation('cover');
                $activity->setAttribute('cover', $cover ? ['thumb_url' => $cover->thumb_url] : null);

                return $activity;
            });

        return Inertia::render('Activities/Index', [
            'activities' => $activities,
            'stats' => $stats,
            'filters' => $filters,
            'types' => ActivityType::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Activities/Form', ['activity' => null] + $this->formOptions());
    }

    public function store(ActivityRequest $request): RedirectResponse
    {
        $activity = DB::transaction(function () use ($request) {
            $activity = Activity::create($request->activityData() + ['created_by_user_id' => $request->user()->id]);
            $this->syncLinks($activity, $request->links());

            return $activity;
        });

        ActivityRecorder::record($request->user(), ActivityAction::ACTIVITY_CREATED, $activity);

        return redirect()->route('activities.show', $activity)->with('success', 'flash.activity_created');
    }

    public function show(Activity $activity): Response
    {
        $activity->load([
            'type:id,name',
            'branch',
            'responsible' => fn ($q) => $q->withPlayer(),
            'categories',
            'players' => fn ($q) => $q->select(['players.id', ...Player::NAME_COLUMNS, 'membership_id']),
            'boardMembers' => fn ($q) => $q->withPlayer(),
            'photos',
            'files',
        ]);

        $data = $activity->toArray();
        $data['responsible'] = $activity->responsible ? ['id' => $activity->responsible->id, 'name' => $activity->responsible->name] : null;
        $data['players'] = $activity->players->map(fn (Player $p) => ['id' => $p->id, 'name' => $p->fullname, 'membership_id' => $p->membership_id])->values();
        $data['board_members'] = $activity->boardMembers->map(fn (BoardMember $m) => ['id' => $m->id, 'name' => $m->name])->values();

        return Inertia::render('Activities/Show', [
            'activity' => $data,
            'reportLimit' => ActivityPhoto::REPORT_LIMIT,
        ]);
    }

    public function edit(Activity $activity): Response
    {
        $data = $activity->toArray();
        $data['category_ids'] = $activity->categories()->pluck('categories.id')->all();
        $data['player_ids'] = $activity->players()->pluck('players.id')->all();
        $data['board_member_ids'] = $activity->boardMembers()->pluck('board_members.id')->all();

        return Inertia::render('Activities/Form', ['activity' => $data] + $this->formOptions($activity));
    }

    public function update(ActivityRequest $request, Activity $activity): RedirectResponse
    {
        DB::transaction(function () use ($request, $activity) {
            $activity->update($request->activityData());
            $this->syncLinks($activity, $request->links());
        });

        return redirect()->route('activities.show', $activity)->with('success', 'flash.activity_updated');
    }

    public function destroy(Activity $activity): RedirectResponse
    {
        $activity->delete();

        return redirect()->route('activities.index')->with('success', 'flash.activity_deleted');
    }

    /** @param  array{categories: int[], players: int[], boardMembers: int[]}  $links */
    private function syncLinks(Activity $activity, array $links): void
    {
        $activity->categories()->sync($links['categories']);
        $activity->players()->sync($links['players']);
        $activity->boardMembers()->sync($links['boardMembers']);
    }

    /**
     * Pickers for the form. Players and board members already linked stay
     * pickable on edit even once archived / former, so saving the form never
     * drops them silently.
     *
     * @return array<string, mixed>
     */
    private function formOptions(?Activity $activity = null): array
    {
        $linked = $activity?->players()->pluck('players.id')->all() ?? [];
        $linkedMembers = $activity
            ? [...$activity->boardMembers()->pluck('board_members.id')->all(), ...array_filter([$activity->responsible_board_member_id])]
            : [];

        return [
            'types' => ActivityType::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::orderBy('name')->get(),
            'categories' => Category::orderBy('name')->get(),
            'players' => Player::query()
                ->where(fn (Builder $q) => $q->where('archived', false)->orWhereIn('id', $linked))
                ->orderBy('lastname')->orderBy('firstname')
                ->get(['id', ...Player::NAME_COLUMNS, 'membership_id'])
                ->map(fn (Player $p) => ['value' => $p->id, 'label' => $p->fullname, 'description' => $p->membership_id])
                ->values(),
            'boardMembers' => BoardMember::withPlayer()
                ->where(fn (Builder $q) => $q->where('status', 'active')->orWhereIn('id', $linkedMembers))
                ->orderBy('sort_order')->get()
                ->map(fn (BoardMember $m) => ['value' => $m->id, 'label' => $m->name])
                ->values(),
        ];
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
