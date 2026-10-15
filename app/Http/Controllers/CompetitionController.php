<?php

namespace App\Http\Controllers;

use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\Game;
use App\Models\GameFormat;
use App\Models\Player;
use App\Models\Team;
use App\Services\Competitions\CompetitionRecord;
use App\Services\Competitions\SuspensionEngine;
use App\Services\Competitions\TeamAccess;
use App\Support\Season;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CompetitionController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'season' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'team_id' => ['nullable', 'integer'],
            'archived' => ['nullable', 'boolean'],
        ]);
        $season = $request->integer('season') ?: Season::current()->startYear;

        $competitions = Competition::query()
            ->with(['team.category', 'games:id,competition_id,date,status,our_score,their_score'])
            ->where('season_start_year', $season)
            ->when($filters['team_id'] ?? null, fn ($q, $id) => $q->where('team_id', $id))
            ->when(! $request->boolean('archived'), fn ($q) => $q->where('archived', false))
            ->orderBy('name')->get();

        return Inertia::render('Competitions/Index', [
            'competitions' => $competitions->map(fn (Competition $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type->value,
                'archived' => $c->archived,
                'team' => $c->team->name.' · '.$c->team->category?->localized_name,
                'games_count' => $c->games->count(),
                'record' => CompetitionRecord::fromGames($c->games),
            ]),
            'teams' => Team::query()->where('archived', false)->with('category')->orderBy('name')->get()
                ->map(fn (Team $t) => ['value' => $t->id, 'label' => $t->name.' · '.$t->category?->localized_name, 'branch_id' => $t->branch_id]),
            'formats' => GameFormat::query()->where('active', true)->whereNotNull('branch_id')->orderBy('name')
                ->get(['id', 'branch_id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'types' => CompetitionType::values(),
            'season' => $season,
            'seasons' => Season::options(),
            'filters' => ['team_id' => $filters['team_id'] ?? null, 'archived' => $request->boolean('archived')],
            'manageableTeamIds' => TeamAccess::manageableTeamIds($request->user(), $season),
            'canAdd' => $request->user()->hasPermission('competitions', 'add'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'season_start_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(CompetitionType::class)],
            'game_format_id' => ['nullable', 'integer', 'exists:game_formats,id'],
        ]);
        $team = Team::findOrFail($data['team_id']);
        TeamAccess::authorize($request->user(), $team, (int) $data['season_start_year']);
        $this->assertFormatFits($data['game_format_id'] ?? null, $team);

        $competition = Competition::create($data);

        return redirect()->route('competitions.show', $competition)->with('success', 'flash.competition_created');
    }

    public function show(Request $request, Competition $competition): Response
    {
        $competition->load(['team.branch', 'team.category', 'team.defaultFormat', 'format']);
        $team = $competition->team;
        $season = $competition->season_start_year;
        $squad = DB::table('competition_players')->where('competition_id', $competition->id)->pluck('hidden', 'player_id');
        $games = $competition->games()->with('opponent:id,name')->orderBy('date')->get();

        return Inertia::render('Competitions/Show', [
            'competition' => [
                ...$competition->only(['id', 'name', 'season_start_year', 'game_format_id', 'red_ban_matches', 'yellow_threshold', 'yellow_ban_matches', 'archived', 'notes']),
                'type' => $competition->type->value,
                'team' => ['id' => $team->id, 'name' => $team->name, 'category' => $team->category?->localized_name],
                'season_label' => Season::forStartYear($season)->label(),
                'format' => ($competition->format ?? $team->defaultFormat)?->localized_name,
            ],
            'roster' => $team->rosterFor($season)->with('category')->get()
                ->map(fn (Player $p) => [
                    'player_id' => $p->id,
                    'name' => $p->fullname,
                    'category' => $p->category?->localized_name,
                    'shirt_number' => $p->pivot->shirt_number,
                    'in_squad' => $squad->has($p->id) && ! $squad[$p->id],
                ])->sortBy('name')->values(),
            'games' => $games->map(fn (Game $g) => [
                'id' => $g->id, 'date' => $g->date, 'opponent' => $g->opponent->name, 'side' => $g->side->value,
                'round_label' => $g->round_label, 'status' => $g->status->value,
                'our_score' => $g->our_score, 'their_score' => $g->their_score,
            ]),
            'record' => CompetitionRecord::fromGames($games),
            'formats' => GameFormat::query()->where('branch_id', $team->branch_id)->where('active', true)->orderBy('name')
                ->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'types' => CompetitionType::values(),
            'canManage' => TeamAccess::canManage($request->user(), $team, $season),
        ]);
    }

    public function update(Request $request, Competition $competition, SuspensionEngine $engine): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $competition->team, $competition->season_start_year);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(CompetitionType::class)],
            'game_format_id' => ['nullable', 'integer', 'exists:game_formats,id'],
            'red_ban_matches' => ['required', 'integer', 'min:0', 'max:20'],
            'yellow_threshold' => ['nullable', 'integer', 'min:1', 'max:20'],
            'yellow_ban_matches' => ['required', 'integer', 'min:0', 'max:20'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'archived' => ['sometimes', 'boolean'],
        ]);
        $this->assertFormatFits($data['game_format_id'] ?? null, $competition->team);
        $competition->fill($data);
        $rulesChanged = $competition->isDirty(['red_ban_matches', 'yellow_threshold', 'yellow_ban_matches']);
        $competition->save();
        if ($rulesChanged) {
            $engine->recompute($competition);
        }

        return back()->with('success', 'flash.competition_updated');
    }

    /** A competition with matches keeps its history: it is archived, not deleted. */
    public function destroy(Request $request, Competition $competition): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $competition->team, $competition->season_start_year);
        if ($competition->games()->exists()) {
            $competition->update(['archived' => true]);

            return redirect()->route('competitions.index')->with('success', 'flash.competition_archived');
        }
        $competition->delete();

        return redirect()->route('competitions.index')->with('success', 'flash.competition_deleted');
    }

    private function assertFormatFits(?int $formatId, Team $team): void
    {
        if ($formatId !== null && (int) GameFormat::whereKey($formatId)->value('branch_id') !== $team->branch_id) {
            throw ValidationException::withMessages(['game_format_id' => 'comp.error.format_other_sport']);
        }
    }
}
