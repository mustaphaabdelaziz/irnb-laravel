<?php

namespace App\Http\Controllers;

use App\Enums\GameSide;
use App\Enums\GameStatus;
use App\Enums\SessionState;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Opponent;
use App\Models\Player;
use App\Models\Team;
use App\Models\TrainingSession;
use App\Models\Venue;
use App\Services\Attendance\SessionGenerator;
use App\Services\Competitions\CallUpCandidates;
use App\Services\Competitions\PlayerFlags;
use App\Services\Competitions\TeamAccess;
use App\Services\Competitions\TrainingCanceller;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    public function index(Request $request, SessionGenerator $generator): Response
    {
        $filters = $request->validate([
            'team_id' => ['nullable', 'integer'],
            'competition_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(GameStatus::class)],
            'view' => ['nullable', Rule::in(['list', 'month'])],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $view = $filters['view'] ?? 'list';
        $month = $filters['month'] ?? now()->format('Y-m');
        $today = now()->toDateString();
        $open = [GameStatus::Scheduled->value, GameStatus::Postponed->value];

        $base = fn () => Game::query()
            ->with(['team:id,name', 'competition:id,name', 'opponent:id,name,logo_url', 'venue:id,name'])
            ->when($filters['team_id'] ?? null, fn ($q, $id) => $q->where('team_id', $id))
            ->when($filters['competition_id'] ?? null, fn ($q, $id) => $q->where('competition_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

        $props = ['upcoming' => [], 'past' => null, 'monthGames' => [], 'sessions' => []];
        if ($view === 'month') {
            $from = $month.'-01';
            $to = CarbonImmutable::parse($from)->endOfMonth()->toDateString();
            $props['monthGames'] = $base()->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('kickoff_time')->get()
                ->map(fn (Game $g) => self::row($g));
            $props['sessions'] = $this->sessions($filters['team_id'] ?? null, $month, $generator);
        } else {
            $props['upcoming'] = $base()->where('date', '>=', $today)->whereIn('status', $open)
                ->orderBy('date')->orderBy('kickoff_time')->get()->map(fn (Game $g) => self::row($g));
            $props['past'] = $base()->where(fn ($q) => $q->where('date', '<', $today)->orWhereNotIn('status', $open))
                ->orderByDesc('date')->paginate(25)->withQueryString()->through(fn (Game $g) => self::row($g));
        }

        $user = $request->user();

        return Inertia::render('Games/Index', $props + [
            'view' => $view,
            'month' => $month,
            'filters' => Arr::only($filters, ['team_id', 'competition_id', 'status']),
            'teams' => Team::query()->where('archived', false)->with('category')->orderBy('name')->get()
                ->map(fn (Team $t) => ['value' => $t->id, 'label' => $t->name.' · '.$t->category?->localized_name]),
            'competitions' => Competition::query()->where('archived', false)->orderByDesc('season_start_year')->orderBy('name')
                ->get(['id', 'team_id', 'name', 'season_start_year']),
            'opponents' => Opponent::query()->orderBy('name')->get(['id', 'name'])->map(fn (Opponent $o) => ['value' => $o->id, 'label' => $o->name]),
            'venues' => Venue::query()->orderByDesc('is_home')->orderBy('name')->get(['id', 'name', 'is_home']),
            'manageableTeamIds' => TeamAccess::manageableTeamIds($user, Season::current()->startYear),
            'canAdd' => $user->hasPermission('games', 'add'),
            'openCreate' => $request->boolean('create'),
        ]);
    }

    public function store(Request $request, TrainingCanceller $canceller): RedirectResponse
    {
        $data = $request->validate($this->rules() + [
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'competition_id' => ['nullable', 'integer', 'exists:competitions,id'],
            'cancel_training' => ['sometimes', 'boolean'],
        ]);
        $team = Team::findOrFail($data['team_id']);
        $competition = isset($data['competition_id']) ? Competition::findOrFail($data['competition_id']) : null;
        if ($competition !== null && $competition->team_id !== $team->id) {
            throw ValidationException::withMessages(['competition_id' => 'comp.error.competition_other_team']);
        }
        $season = $competition?->season_start_year ?? Season::forDate($data['date'])->startYear;
        TeamAccess::authorize($request->user(), $team, $season);

        $game = Game::create(Arr::except($data, ['cancel_training']) + [
            'season_start_year' => $season,
            'game_format_id' => $competition?->game_format_id ?? $team->default_game_format_id,
            'status' => GameStatus::Scheduled,
        ]);
        $cancelled = $request->boolean('cancel_training') ? $canceller->cancelForGame($game, $request->user()) : 0;

        return redirect()->route('games.show', $game)
            ->with('success', $cancelled > 0 ? 'flash.game_created_training_cancelled' : 'flash.game_created');
    }

    public function show(Request $request, Game $game, CallUpCandidates $candidates, PlayerFlags $flags): Response
    {
        $game->load(['team.category', 'competition', 'opponent', 'venue', 'format', 'players']);

        return Inertia::render('Games/Show', [
            'game' => [
                ...$game->only(['id', 'date', 'kickoff_time', 'meeting_time', 'meeting_place', 'round_label', 'our_score', 'their_score', 'notes', 'opponent_id', 'venue_id', 'season_start_year']),
                'side' => $game->side->value,
                'status' => $game->status->value,
                'team' => ['id' => $game->team->id, 'name' => $game->team->name, 'category' => $game->team->category?->localized_name],
                'competition' => $game->competition ? ['id' => $game->competition->id, 'name' => $game->competition->name] : null,
                'opponent' => ['id' => $game->opponent->id, 'name' => $game->opponent->name, 'logo_url' => $game->opponent->logo_url],
                'venue' => $game->venue ? ['name' => $game->venue->name, 'address' => $game->venue->address] : null,
                'format' => [
                    'name' => $game->format->localized_name,
                    'players_on_field' => (int) $game->rule('players_on_field'),
                    'max_called_up' => (int) $game->rule('max_called_up'),
                ],
            ],
            'candidates' => (function () use ($game, $candidates, $flags) {
                $injured = $flags->injured();
                $rows = $game->players->keyBy('player_id');

                return $candidates->for($game)->map(fn (Player $p) => [
                    'player_id' => $p->id,
                    'name' => $p->fullname,
                    'category' => $p->category?->localized_name,
                    'shirt_number' => $rows->get($p->id)?->shirt_number ?? $p->shirt_number,
                    'called_up' => (bool) $rows->get($p->id)?->called_up,
                    'not_called_reason' => $rows->get($p->id)?->not_called_reason,
                    'injured' => isset($injured[$p->id]),
                ])->values();
            })(),
            'opponents' => Opponent::query()->orderBy('name')->get(['id', 'name'])->map(fn (Opponent $o) => ['value' => $o->id, 'label' => $o->name]),
            'venues' => Venue::query()->orderByDesc('is_home')->orderBy('name')->get(['id', 'name']),
            'canManage' => TeamAccess::canManage($request->user(), $game->team, $game->season_start_year),
        ]);
    }

    public function update(Request $request, Game $game): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $game->team, $game->season_start_year);
        $data = $request->validate($this->rules() + [
            'status' => ['required', Rule::enum(GameStatus::class)],
            'our_score' => ['nullable', 'required_if:status,played', 'integer', 'min:0', 'max:999'],
            'their_score' => ['nullable', 'required_if:status,played', 'integer', 'min:0', 'max:999'],
        ]);
        if ($data['status'] !== GameStatus::Played->value) {
            $data['our_score'] = null;
            $data['their_score'] = null;
        }
        // A friendly's season follows its date; a competition match keeps the competition's.
        if ($game->competition_id === null) {
            $data['season_start_year'] = Season::forDate($data['date'])->startYear;
            // Moving it to another season needs the right to manage the team in that season too.
            if ($data['season_start_year'] !== $game->season_start_year) {
                TeamAccess::authorize($request->user(), $game->team, $data['season_start_year']);
            }
        }
        $game->update($data);

        return back()->with('success', 'flash.game_updated');
    }

    public function destroy(Request $request, Game $game): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $game->team, $game->season_start_year);
        $game->delete();

        return redirect()->route('games.index')->with('success', 'flash.game_deleted');
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'opponent_id' => ['required', 'integer', 'exists:opponents,id'],
            'venue_id' => ['nullable', 'integer', 'exists:venues,id'],
            'side' => ['required', Rule::enum(GameSide::class)],
            'round_label' => ['nullable', 'string', 'max:100'],
            'date' => ['required', 'date_format:Y-m-d'],
            'kickoff_time' => ['nullable', 'date_format:H:i'],
            'meeting_time' => ['nullable', 'date_format:H:i'],
            'meeting_place' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, mixed> */
    private static function row(Game $game): array
    {
        return [
            'id' => $game->id,
            'date' => $game->date,
            'kickoff_time' => $game->kickoff_time,
            'team' => $game->team->name,
            'competition' => $game->competition?->name,
            'opponent' => $game->opponent->name,
            'opponent_logo' => $game->opponent->logo_url,
            'venue' => $game->venue?->name,
            'side' => $game->side->value,
            'status' => $game->status->value,
            'round_label' => $game->round_label,
            'our_score' => $game->our_score,
            'their_score' => $game->their_score,
        ];
    }

    /** The filtered team's training sessions in $month, so the calendar shows matches next to training. */
    private function sessions(?int $teamId, string $month, SessionGenerator $generator): array
    {
        $team = $teamId ? Team::find($teamId) : null;
        if ($team === null) {
            return [];
        }
        [$year, $monthNumber] = array_map('intval', explode('-', $month));
        $generator->forMonth($team->category_id, $year, $monthNumber);

        return TrainingSession::query()->where('category_id', $team->category_id)->where('date', 'like', $month.'-%')
            ->orderBy('date')->orderBy('start_time')->get(['id', 'date', 'start_time', 'end_time', 'state'])
            ->map(fn (TrainingSession $s) => [
                'id' => $s->id, 'date' => $s->date, 'time' => $s->start_time.'–'.$s->end_time,
                'cancelled' => $s->state === SessionState::Cancelled,
            ])->all();
    }
}
