<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameFormat;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Services\Competitions\PlayerFlags;
use App\Services\Competitions\TeamAccess;
use App\Support\Season;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $request->validate(['season' => ['nullable', 'integer', 'min:2000', 'max:2100'], 'archived' => ['nullable', 'boolean']]);
        $season = $request->integer('season') ?: Season::current()->startYear;

        $teams = Team::query()
            ->with([
                'branch', 'category', 'defaultFormat',
                'coaches' => fn ($q) => $q->wherePivot('season_start_year', $season)->wherePivot('role', 'head'),
            ])
            ->withCount(['players as roster_count' => fn ($q) => $q->where('team_players.season_start_year', $season)])
            ->when(! $request->boolean('archived'), fn ($q) => $q->where('archived', false))
            ->orderBy('name')->get();

        return Inertia::render('Teams/Index', [
            'teams' => $teams->map(fn (Team $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'archived' => $team->archived,
                'branch' => $team->branch?->localized_name,
                'category' => $team->category?->localized_name,
                'format' => $team->defaultFormat?->localized_name,
                'roster_count' => $team->roster_count,
                'head_coaches' => $team->coaches->map(fn (User $u) => $u->fullname)->values(),
            ]),
            'branches' => Branch::query()->orderBy('name')->get(),
            'categories' => Category::query()->orderBy('name')->get(),
            'formats' => GameFormat::query()->where('active', true)->whereNotNull('branch_id')->orderBy('name')
                ->get(['id', 'branch_id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'season' => $season,
            'seasons' => Season::options(),
            'showArchived' => $request->boolean('archived'),
            'canCreate' => ! TeamAccess::isScoped($request->user()) && $request->user()->hasPermission('teams', 'add'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(TeamAccess::isScoped($request->user()), 403);
        $team = Team::create($this->validated($request));

        return redirect()->route('teams.show', $team)->with('success', 'flash.team_created');
    }

    public function show(Request $request, Team $team, PlayerFlags $flags): Response
    {
        $request->validate(['season' => ['nullable', 'integer', 'min:2000', 'max:2100']]);
        $season = $request->integer('season') ?: Season::current()->startYear;
        $team->load(['branch', 'category', 'defaultFormat']);
        $injured = $flags->injured();

        $roster = $team->rosterFor($season)->with('category')->get()
            ->map(fn (Player $p) => [
                'player_id' => $p->id,
                'name' => $p->fullname,
                'category' => $p->category?->localized_name,
                'shirt_number' => $p->pivot->shirt_number,
                'injured' => isset($injured[$p->id]),
            ])->sortBy('name')->values();
        $badges = $flags->for($roster->pluck('player_id')->all(), $request->user());
        $roster = $roster->map(fn (array $row) => $row + ['flags' => $badges[$row['player_id']] ?? null])->values();

        $candidates = Player::query()->where('archived', false)->notLeft()
            ->whereNotIn('id', $roster->pluck('player_id'))
            ->with('branches:id')
            ->get(['id', 'category_id', ...Player::NAME_COLUMNS])
            ->map(fn (Player $p) => [
                'id' => $p->id,
                'name' => $p->fullname,
                'category_id' => $p->category_id,
                'in_sport' => $p->branches->contains('id', $team->branch_id),
            ])->sortBy('name')->values();

        return Inertia::render('Teams/Show', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'archived' => $team->archived,
                'branch' => $team->branch?->localized_name,
                'category_id' => $team->category_id,
                'category' => $team->category?->localized_name,
                'default_game_format_id' => $team->default_game_format_id,
                'format' => $team->defaultFormat?->localized_name,
            ],
            'season' => $season,
            'seasons' => Season::options(),
            'roster' => $roster,
            'candidates' => $candidates,
            'previousRosterCount' => DB::table('team_players')->where('team_id', $team->id)->where('season_start_year', $season - 1)->count(),
            'coaches' => $team->coachesFor($season)->get()->map(fn (User $u) => ['user_id' => $u->id, 'role' => $u->pivot->role])->values(),
            'users' => User::query()->where('is_active', true)->get()
                ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->fullname])->sortBy('label')->values(),
            'competitions' => $team->competitions()->where('season_start_year', $season)->orderBy('name')->get(['id', 'name', 'type', 'archived']),
            'games' => $team->games()->where('season_start_year', $season)->with('opponent:id,name')->orderBy('date')->get()
                ->map(fn (Game $g) => [
                    'id' => $g->id, 'date' => $g->date, 'opponent' => $g->opponent->name, 'side' => $g->side->value,
                    'status' => $g->status->value, 'our_score' => $g->our_score, 'their_score' => $g->their_score,
                ]),
            'formats' => GameFormat::query()->where('branch_id', $team->branch_id)->where('active', true)->orderBy('name')
                ->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'categories' => Category::query()->orderBy('name')->get(),
            'canManage' => TeamAccess::canManage($request->user(), $team, $season),
            'canEditTeam' => ! TeamAccess::isScoped($request->user()) && $request->user()->hasPermission('teams', 'edit'),
        ]);
    }

    public function update(Request $request, Team $team): RedirectResponse
    {
        abort_if(TeamAccess::isScoped($request->user()), 403);
        $team->update($this->validated($request, $team));

        return back()->with('success', 'flash.team_updated');
    }

    /** A team with competitions or matches keeps its history: it is archived, not deleted. */
    public function destroy(Request $request, Team $team): RedirectResponse
    {
        abort_if(TeamAccess::isScoped($request->user()), 403);
        if ($team->games()->exists() || $team->competitions()->exists()) {
            $team->update(['archived' => true]);

            return redirect()->route('teams.index')->with('success', 'flash.team_archived');
        }
        $team->delete();

        return redirect()->route('teams.index')->with('success', 'flash.team_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Team $team = null): array
    {
        $rules = [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:100'],
            'default_game_format_id' => ['required', 'integer', 'exists:game_formats,id'],
            'archived' => ['sometimes', 'boolean'],
        ];
        if ($team === null) {
            $rules['branch_id'] = ['required', 'integer', 'exists:branches,id'];
        }
        $data = $request->validate($rules);
        $branchId = $team?->branch_id ?? (int) $data['branch_id'];

        if ((int) GameFormat::whereKey($data['default_game_format_id'])->value('branch_id') !== $branchId) {
            throw ValidationException::withMessages(['default_game_format_id' => 'comp.error.format_other_sport']);
        }
        $duplicate = Team::query()->where('branch_id', $branchId)->where('category_id', $data['category_id'])
            ->where('name', $data['name'])->when($team, fn ($q) => $q->whereKeyNot($team->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'comp.error.team_exists']);
        }

        return $data;
    }
}
