<?php

namespace Database\Seeders;

use App\Enums\GameSide;
use App\Enums\GameStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Formation;
use App\Models\Game;
use App\Models\GameFormat;
use App\Models\GamePlayer;
use App\Models\Opponent;
use App\Models\Player;
use App\Models\Position;
use App\Models\Team;
use App\Models\User;
use App\Models\Venue;
use App\Services\Competitions\GameMinutes;
use App\Services\Competitions\SuspensionEngine;
use App\Services\Competitions\TeamRoster;
use App\Services\Player\RegistrationService;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The demo team, competition and matches for the Help Center competitions
 * articles. Called only from the guarded HelpDemoSeeder. Models are written
 * directly (no ActivityRecorder, no training cancelled), the way the call-up,
 * line-up and report controllers store them. Matches fall on Fridays around
 * the seed day D, so they never touch the attendance demo's training days.
 * Report minutes are the match clock (0–40 in «كرة القدم 5»): the report form
 * of a timed format asks a minute only and saves no period.
 */
class HelpDemoCompetitionsSeeder extends Seeder
{
    public const OPP_UNION = 'اتحاد الشباب';

    public const OPP_STAR = 'نجم الساحل';

    public const OPP_HOPE = 'أمل المدينة';

    public const OPP_YOUTH = 'شباب الحي';

    public const VENUE_HOME = 'ملعب حي النصر';

    public const VENUE_HOME_ADDRESS = 'شارع الاستقلال';

    public const VENUE_TOWN = 'ملعب البلدية';

    public const VENUE_HALL = 'القاعة متعددة الرياضات';

    /** Both demo teams: lists read «الفريق أ · أقل من 17 سنة» / «الفريق أ · أقل من 14 سنة». */
    public const TEAM_NAME = 'الفريق أ';

    public const CATEGORY_U17 = 'أقل من 17 سنة';

    public const CATEGORY_U14 = 'أقل من 14 سنة';

    /** Licence numbers on the Football registration, in U17 shirt order; أحمد بن علي keeps none. */
    public const LICENCED = ['عبد الرحمن', 'رياض', 'أنس', 'ياسين', 'أمين', 'زكرياء'];

    public const COMPETITION = 'البطولة الولائية لأقل من 17 سنة';

    public const ROUND_1 = 'الجولة 1';

    public const ROUND_2 = 'الجولة 2';

    public const ROUND_3 = 'الجولة 3';

    public const ROUND_4 = 'الجولة 4';

    public const KICKOFF = '15:00';

    public const MEETING_TIME = '14:00';

    public const MEETING_PLACE = 'مقر النادي';

    public const REASON_BANNED = 'موقوف';

    public const REASON_INJURED = 'مصاب';

    public const COACH_NOTE = 'تمركز جيد في الدفاع';

    private CarbonImmutable $today;

    /** @var array<string, Player> keyed by first name */
    private array $players = [];

    /** @var array<string, int> U17 roster shirt by first name */
    private array $shirts = [];

    /** @var array<int, ?int> position id per 2-2 slot */
    private array $slotPositions = [];

    private Formation $formation;

    public function run(): void
    {
        HelpDemoSeeder::assertThrowawayDatabase();

        $this->today = CarbonImmutable::today();
        $season = Season::current()->startYear;
        $football = Branch::where('name', 'Football')->firstOrFail();

        // The presets migration ran before the branches existed: give them to Football now.
        GameFormat::whereIn('sport', ['football', 'futsal'])->whereNull('branch_id')->update(['branch_id' => $football->id]);
        Position::whereNull('branch_id')->update(['branch_id' => $football->id]);

        $opponents = [];
        foreach ([
            [self::OPP_UNION, 'البليدة', ['#1d4ed8', '#facc15', 'shield']],
            [self::OPP_STAR, 'تيبازة', ['#b91c1c', '#f8fafc', 'circle']],
            [self::OPP_HOPE, 'المدية', ['#15803d', '#f8fafc', 'diamond']],
            [self::OPP_YOUTH, null, null],
        ] as [$name, $city, $logo]) {
            $image = $logo === null ? null : HelpDemoImages::logo(...$logo);
            $opponents[$name] = Opponent::create([
                'name' => $name,
                'city' => $city,
                'logo_url' => $image['url'] ?? null,
                'logo_filename' => $image['filename'] ?? null,
            ]);
        }

        // is_home only lists the club's grounds first (and badges them in settings).
        $venues = [
            self::VENUE_HOME => Venue::create(['name' => self::VENUE_HOME, 'address' => self::VENUE_HOME_ADDRESS, 'is_home' => true]),
            self::VENUE_TOWN => Venue::create(['name' => self::VENUE_TOWN, 'is_home' => false]),
            self::VENUE_HALL => Venue::create(['name' => self::VENUE_HALL, 'is_home' => false]),
        ];

        $five = GameFormat::where('is_preset', true)->where('name', 'Football 5')->firstOrFail();
        $eight = GameFormat::where('is_preset', true)->where('name', 'Football 8')->firstOrFail();
        $this->formation = Formation::where('game_format_id', $five->id)->where('name', '2-2')->firstOrFail();
        $positions = Position::where('branch_id', $football->id)->pluck('id', 'abbreviation');
        // As GameLineupController: a slot's label names the position (GK, DF; ST has none).
        $this->slotPositions = array_map(fn (array $slot) => $positions[$slot['label']] ?? null, $this->formation->slots);

        foreach (['عبد الرحمن' => 'بلعيد', 'رياض' => 'حمداني', 'أنس' => 'لعريبي', 'أمين' => 'قاسمي', 'ياسين' => 'بوزيد', 'زكرياء' => 'عمراني', 'أحمد' => 'بن علي'] as $first => $last) {
            $this->players[$first] = Player::where('archived', false)->where('firstname', $first)->where('lastname', $last)->firstOrFail();
        }

        $category = fn (string $name) => Category::where('branch_id', $football->id)->where('name', $name)->firstOrFail();
        $u17 = Team::create([
            'branch_id' => $football->id, 'category_id' => $category(self::CATEGORY_U17)->id,
            'name' => self::TEAM_NAME, 'default_game_format_id' => $five->id,
        ]);
        $u14 = Team::create([
            'branch_id' => $football->id, 'category_id' => $category(self::CATEGORY_U14)->id,
            'name' => self::TEAM_NAME, 'default_game_format_id' => $eight->id,
        ]);

        $this->shirts = ['عبد الرحمن' => 1, 'رياض' => 4, 'أنس' => 7, 'أمين' => 10, 'ياسين' => 8, 'زكرياء' => 11, 'أحمد' => 14];
        $roster = app(TeamRoster::class);
        $roster->replace($u17, $season, $this->rows($this->shirts));
        // Last season only: this season stays empty, so the copy button shows.
        $roster->replace($u14, $season - 1, $this->rows(['أحمد' => 9, 'ياسين' => 7, 'زكرياء' => 5]));

        // Licences on the Football registration (what TeamMembers::licence reads), mirrored onto players as the app does.
        foreach (self::LICENCED as $i => $first) {
            DB::table('branch_player')->where('player_id', $this->players[$first]->id)->where('branch_id', $football->id)
                ->update(['licence_number' => self::licence($season, $i + 1)]);
            app(RegistrationService::class)->refreshMirror($this->players[$first]);
        }

        $samir = User::where('username', HelpDemoUsersSeeder::COACH)->firstOrFail();
        DB::table('team_coaches')->insert([
            'team_id' => $u17->id, 'season_start_year' => $season, 'user_id' => $samir->id, 'role' => 'head',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $competition = Competition::create([
            'team_id' => $u17->id, 'season_start_year' => $season, 'name' => self::COMPETITION, 'type' => 'league',
            'game_format_id' => $five->id, 'red_ban_matches' => 1, 'yellow_threshold' => 3, 'yellow_ban_matches' => 1,
        ]);
        foreach ($this->players as $player) {
            DB::table('competition_players')->insert([
                'competition_id' => $competition->id, 'player_id' => $player->id, 'hidden' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $match = fn (int $friday, GameSide $side, string $venue, string $opponent, ?string $round, GameStatus $status) => Game::create([
            'team_id' => $u17->id, 'competition_id' => $competition->id, 'season_start_year' => $season,
            'game_format_id' => $five->id, 'opponent_id' => $opponents[$opponent]->id, 'venue_id' => $venues[$venue]->id,
            'side' => $side, 'round_label' => $round, 'date' => $this->friday($friday)->toDateString(),
            'kickoff_time' => self::KICKOFF, 'meeting_time' => self::MEETING_TIME, 'meeting_place' => self::MEETING_PLACE,
            'status' => $status,
        ]);

        $m1 = $match(-3, GameSide::Home, self::VENUE_HOME, self::OPP_UNION, self::ROUND_1, GameStatus::Played);
        $m2 = $match(-2, GameSide::Away, self::VENUE_TOWN, self::OPP_STAR, self::ROUND_2, GameStatus::Played);
        $m3 = $match(-1, GameSide::Home, self::VENUE_HOME, self::OPP_HOPE, self::ROUND_3, GameStatus::Played);
        $m4 = $match(1, GameSide::Away, self::VENUE_HALL, self::OPP_UNION, self::ROUND_4, GameStatus::Scheduled);
        $match(-4, GameSide::Away, self::VENUE_TOWN, self::OPP_YOUTH, null, GameStatus::Postponed);

        $starters = ['عبد الرحمن', 'أنس', 'ياسين', 'أمين', 'زكرياء'];

        // M1 — 3–1 at home.
        $this->lineup($m1, $starters, ['رياض', 'أحمد'], goalkeeper: 'عبد الرحمن');
        $this->report($m1, [[2, 1], [1, 0]], [
            ['goal', 'normal', 12, 'أمين', 'أنس'],
            ['sanction', 'yellow', 18, 'أنس', null],
            ['goal', 'normal', 19, 'أنس', 'أمين'],
            ['goal', 'penalty', 28, 'أمين', null],
            ['substitution', null, 30, 'رياض', 'زكرياء'],
        ], ratings: ['أمين' => 9, 'أنس' => 8, 'عبد الرحمن' => 7, 'ياسين' => 7, 'زكرياء' => 6, 'رياض' => 6], mvp: 'أمين', notes: ['ياسين' => self::COACH_NOTE]);

        // M2 — 1–1 away; the goalkeeper's red card bans him for M3.
        $this->lineup($m2, $starters, ['رياض', 'أحمد'], goalkeeper: 'عبد الرحمن');
        $this->report($m2, [[1, 0], [0, 1]], [
            ['goal', 'normal', 15, 'ياسين', 'أمين'],
            ['sanction', 'yellow', 23, 'أنس', null],
            ['sanction', 'red', 32, 'عبد الرحمن', null],
            ['substitution', null, 33, 'أحمد', 'ياسين'],
        ]);

        // M3 — 2–0 at home, زكرياء in goal; the banned and the injured left out with a reason.
        $this->leftOut($m3, ['عبد الرحمن' => self::REASON_BANNED, 'رياض' => self::REASON_INJURED]);
        $this->lineup($m3, ['زكرياء', 'أنس', 'ياسين', 'أمين', 'أحمد'], [], goalkeeper: 'زكرياء');
        $this->report($m3, [[1, 0], [1, 0]], [
            ['goal', 'normal', 9, 'أمين', null],
            ['goal', 'normal', 34, 'أحمد', 'أنس'],
        ]);

        // M4 — call-up saved (رياض out, injured), no line-up yet.
        $this->leftOut($m4, ['رياض' => self::REASON_INJURED]);
        foreach (['عبد الرحمن', 'أنس', 'أمين', 'ياسين', 'زكرياء', 'أحمد'] as $first) {
            GamePlayer::create([
                'game_id' => $m4->id, 'player_id' => $this->players[$first]->id, 'called_up' => true,
                'shirt_number' => $this->shirts[$first], 'shirt_override' => false,
            ]);
        }

        app(SuspensionEngine::class)->recompute($competition);
    }

    /** «L2026-0101»: season start year, then 01 and the row number. */
    public static function licence(int $season, int $n): string
    {
        return sprintf('L%d-01%02d', $season, $n);
    }

    /** The Friday $n weeks from D's week: -1 the last Friday before D, 1 the first after it. */
    private function friday(int $n): CarbonImmutable
    {
        return $n < 0
            ? $this->today->previous(CarbonImmutable::FRIDAY)->addWeeks($n + 1)
            : $this->today->next(CarbonImmutable::FRIDAY)->addWeeks($n - 1);
    }

    /** @param array<string, int> $shirts @return list<array{player_id: int, shirt_number: int}> */
    private function rows(array $shirts): array
    {
        return array_map(
            fn (string $first, int $shirt) => ['player_id' => $this->players[$first]->id, 'shirt_number' => $shirt],
            array_keys($shirts), $shirts,
        );
    }

    /** Called up and placed as the call-up + line-up controllers save it: starters in slots 0–4 (2-2), the rest on the bench. */
    private function lineup(Game $game, array $starters, array $bench, string $goalkeeper): void
    {
        $game->update(['formation_id' => $this->formation->id]);
        foreach ([...$starters, ...$bench] as $i => $first) {
            $starter = $i < count($starters);
            GamePlayer::create([
                'game_id' => $game->id, 'player_id' => $this->players[$first]->id, 'called_up' => true,
                'role' => $starter ? 'starter' : 'bench',
                'slot' => $starter ? $i : null,
                'position_id' => $starter ? $this->slotPositions[$i] : null,
                'shirt_number' => $this->shirts[$first], 'shirt_override' => false,
                'is_captain' => $first === 'أمين',
                'is_goalkeeper' => $first === $goalkeeper,
            ]);
        }
    }

    /** @param array<string, string> $reasons */
    private function leftOut(Game $game, array $reasons): void
    {
        foreach ($reasons as $first => $reason) {
            GamePlayer::create([
                'game_id' => $game->id, 'player_id' => $this->players[$first]->id, 'called_up' => false,
                'not_called_reason' => $reason, 'role' => 'none',
            ]);
        }
    }

    /**
     * The report as GameReportController saves it. An event is [type, subtype,
     * minute, player, related]: a goal's related player is the assist; a
     * substitution's player comes on and its related player goes off.
     */
    private function report(Game $game, array $periods, array $events, array $ratings = [], ?string $mvp = null, array $notes = []): void
    {
        $game->update([
            'period_scores' => $periods,
            'our_score' => array_sum(array_column($periods, 0)),
            'their_score' => array_sum(array_column($periods, 1)),
        ]);
        foreach ($events as [$type, $subtype, $minute, $player, $related]) {
            $game->events()->create([
                'type' => $type, 'subtype' => $subtype, 'period' => null, 'minute' => $minute,
                'player_id' => $this->players[$player]->id,
                'related_player_id' => $related === null ? null : $this->players[$related]->id,
            ]);
        }
        foreach ($game->players()->where('called_up', true)->with('player')->get() as $gp) {
            $first = $gp->player->firstname;
            $gp->update(['rating' => $ratings[$first] ?? null, 'coach_note' => $notes[$first] ?? null, 'is_mvp' => $first === $mvp]);
        }
        app(GameMinutes::class)->refresh($game->fresh());
    }
}
