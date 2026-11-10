<?php

namespace Tests\Feature\Help;

use App\Enums\GameStatus;
use App\Enums\SessionState;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Competition;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GameFormat;
use App\Models\GamePlayer;
use App\Models\Opponent;
use App\Models\Player;
use App\Models\Position;
use App\Models\Suspension;
use App\Models\Team;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Venue;
use App\Services\Competitions\CompetitionRecord;
use App\Services\Competitions\PlayerFlags;
use App\Support\Season;
use Database\Seeders\HelpDemoCompetitionsSeeder as Demo;
use Database\Seeders\HelpDemoSeeder;
use Database\Seeders\HelpDemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoCompetitionsSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedOn(string $day): void
    {
        $this->travelTo($day);
        $this->seed(HelpDemoSeeder::class);
    }

    private function player(string $fullname): Player
    {
        return Player::where('archived', false)->get()
            ->first(fn (Player $p) => $p->firstname.' '.$p->lastname === $fullname)
            ?? $this->fail("No demo player {$fullname}");
    }

    private function game(string $round): Game
    {
        return Game::where('round_label', $round)->firstOrFail();
    }

    #[Test]
    public function the_demo_competition_matches_the_articles(): void
    {
        $this->seedOn('2026-11-18 10:00:00');
        $s = Season::current()->startYear;
        $this->assertSame(2026, $s);
        $football = Branch::where('name', 'Football')->firstOrFail();

        // Setup: presets and positions belong to Football.
        $this->assertSame(0, GameFormat::whereIn('sport', ['football', 'futsal'])->where('branch_id', '!=', $football->id)->count());
        $this->assertSame(0, GameFormat::whereIn('sport', ['football', 'futsal'])->whereNull('branch_id')->count());
        $this->assertSame(0, Position::whereNull('branch_id')->count());
        $this->assertGreaterThan(0, Position::where('branch_id', $football->id)->count());

        $this->assertEqualsCanonicalizing(
            [Demo::OPP_UNION, Demo::OPP_STAR, Demo::OPP_HOPE, Demo::OPP_YOUTH],
            Opponent::pluck('name')->all(),
        );
        $this->assertSame(0, Opponent::whereNotNull('logo_url')->count());
        $this->assertNull(Opponent::where('name', Demo::OPP_YOUTH)->value('city'));
        $this->assertSame(
            [Demo::OPP_UNION => Demo::CITY_UNION, Demo::OPP_STAR => Demo::CITY_STAR, Demo::OPP_HOPE => Demo::CITY_HOPE],
            Opponent::whereNotNull('city')->orderBy('id')->pluck('city', 'name')->all(),
        );
        $this->assertSame('البليدة', Opponent::where('name', Demo::OPP_UNION)->value('city'));
        $this->assertEqualsCanonicalizing([Demo::VENUE_HOME, Demo::VENUE_TOWN, Demo::VENUE_HALL], Venue::pluck('name')->all());
        $this->assertSame([Demo::VENUE_HOME], Venue::where('is_home', true)->pluck('name')->all());
        $this->assertSame(Demo::VENUE_HOME_ADDRESS, Venue::where('name', Demo::VENUE_HOME)->value('address'));

        // Team U17: 7 on the season's roster with their shirts, samir head coach.
        $this->assertSame([Demo::TEAM_NAME, Demo::TEAM_NAME], Team::orderBy('id')->pluck('name')->all());
        $team = fn (string $category) => Team::whereHas('category', fn ($q) => $q->where('name', $category))->sole();
        $u17 = $team(Demo::CATEGORY_U17);
        $this->assertSame($football->id, $u17->branch_id);
        $this->assertSame('Football 5', $u17->defaultFormat->name);
        $shirts = $u17->rosterFor($s)->get()->mapWithKeys(fn (Player $p) => [$p->firstname.' '.$p->lastname => (int) $p->pivot->shirt_number])->all();
        $this->assertEquals([
            'عبد الرحمن بلعيد' => 1, 'رياض حمداني' => 4, 'أنس لعريبي' => 7, 'أمين قاسمي' => 10,
            'ياسين بوزيد' => 8, 'زكرياء عمراني' => 11, 'أحمد بن علي' => 14,
        ], $shirts);
        // Licences on the Football registration, all but أحمد بن علي. players.* mirrors the primary
        // registration: رياض and ياسين also swim, and Swimming is the main branch, so theirs stays empty.
        $licences = $u17->rosterFor($s)->get()->mapWithKeys(fn (Player $p) => [$p->firstname => [
            $p->registrations()->where('branch_id', $football->id)->value('licence_number'), $p->licence_number,
        ]])->all();
        $this->assertEquals([
            'عبد الرحمن' => ['L2026-0101', 'L2026-0101'], 'رياض' => ['L2026-0102', null], 'أنس' => ['L2026-0103', 'L2026-0103'],
            'ياسين' => ['L2026-0104', null], 'أمين' => ['L2026-0105', 'L2026-0105'], 'زكرياء' => ['L2026-0106', 'L2026-0106'],
            'أحمد' => [null, null],
        ], $licences);
        $this->assertSame('أشبال', $this->player('رياض حمداني')->registrations()->where('branch_id', '!=', $football->id)->sole()->category->name);
        $this->assertNull($this->player('رياض حمداني')->registrations()->where('branch_id', '!=', $football->id)->value('licence_number'));
        $coaches = $u17->coachesFor($s)->get();
        $this->assertCount(1, $coaches);
        $this->assertSame(HelpDemoUsersSeeder::COACH, $coaches[0]->username);
        $this->assertSame('head', $coaches[0]->pivot->role);

        // Team U14: last season's roster only, so the copy button shows.
        $u14 = $team(Demo::CATEGORY_U14);
        $this->assertSame('Football 8', $u14->defaultFormat->name);
        $this->assertSame(3, $u14->rosterFor($s - 1)->count());
        $this->assertSame(0, $u14->rosterFor($s)->count());
        $this->assertSame(0, $u14->coaches()->count());

        // Competition: rules 1/3/1, squad of 7.
        $competition = Competition::where('name', Demo::COMPETITION)->firstOrFail();
        $this->assertSame($u17->id, $competition->team_id);
        $this->assertSame($s, $competition->season_start_year);
        $this->assertSame('league', $competition->type->value);
        $this->assertSame('Football 5', $competition->format->name);
        $this->assertSame([1, 3, 1], [$competition->red_ban_matches, $competition->yellow_threshold, $competition->yellow_ban_matches]);
        $this->assertSame(7, $competition->players()->wherePivot('hidden', false)->count());

        // Five matches, all in the competition and season S, on Fridays.
        $this->assertSame(5, Game::count());
        $this->assertSame(5, Game::where('competition_id', $competition->id)->where('season_start_year', $s)->count());
        $m1 = $this->game(Demo::ROUND_1);
        $m2 = $this->game(Demo::ROUND_2);
        $m3 = $this->game(Demo::ROUND_3);
        $m4 = $this->game(Demo::ROUND_4);
        $m5 = Game::whereNull('round_label')->firstOrFail();
        $this->assertSame(['2026-10-30', '2026-11-06', '2026-11-13', '2026-11-20', '2026-10-23'], [$m1->date, $m2->date, $m3->date, $m4->date, $m5->date]);
        $this->assertSame(
            [GameStatus::Played, GameStatus::Played, GameStatus::Played, GameStatus::Scheduled, GameStatus::Postponed],
            [$m1->status, $m2->status, $m3->status, $m4->status, $m5->status],
        );
        $this->assertSame([3, 1], [$m1->our_score, $m1->their_score]);
        $this->assertSame([1, 1], [$m2->our_score, $m2->their_score]);
        $this->assertSame([2, 0], [$m3->our_score, $m3->their_score]);
        $this->assertSame([[2, 1], [1, 0]], $m1->period_scores);
        $this->assertSame([null, null], [$m4->our_score, $m4->their_score]);
        $this->assertSame(['home', 'away', 'home', 'away', 'away'], [$m1->side->value, $m2->side->value, $m3->side->value, $m4->side->value, $m5->side->value]);
        $this->assertSame(Demo::OPP_UNION, $m4->opponent->name);
        $this->assertSame(Demo::VENUE_HALL, $m4->venue->name);
        $this->assertSame(Demo::OPP_YOUTH, $m5->opponent->name);
        $this->assertSame('15:00', $m1->kickoff_time);
        $this->assertSame(Demo::MEETING_PLACE, $m4->meeting_place);
        $this->assertSame(40, $m1->matchLength());
        $this->assertSame(0, $m5->players()->count());

        // M1: five events, one substitution (player_id comes on, related_player_id goes off).
        $this->assertSame(5, $m1->events()->count());
        $sub = $m1->events()->where('type', 'substitution')->sole();
        $this->assertSame($this->player('رياض حمداني')->id, $sub->player_id);
        $this->assertSame($this->player('زكرياء عمراني')->id, $sub->related_player_id);
        $this->assertSame(1, $m1->events()->where('type', 'goal')->where('subtype', 'penalty')->count());

        // Minutes worked out by GameMinutes.
        $minutes = fn (Game $g) => $g->players()->with('player')->get()
            ->mapWithKeys(fn (GamePlayer $gp) => [$gp->player->firstname => $gp->minutes_played])->all();
        $m1Minutes = $minutes($m1);
        $this->assertSame(40, $m1Minutes['أمين']);
        $this->assertSame(30, $m1Minutes['زكرياء']);
        $this->assertSame(10, $m1Minutes['رياض']);
        $this->assertSame(0, $m1Minutes['أحمد']);
        $this->assertSame(32, $minutes($m2)['عبد الرحمن']);
        $this->assertTrue((bool) $m1->players()->where('player_id', $this->player('أمين قاسمي')->id)->value('is_mvp'));

        // One automatic ban: عبد الرحمن, red card in M2, one match.
        $ban = Suspension::sole();
        $this->assertSame($this->player('عبد الرحمن بلعيد')->id, $ban->player_id);
        $this->assertSame(['auto', 'red', 1, $m2->id], [$ban->source, $ban->cause, $ban->matches_count, $ban->game_id]);

        // M2: four events; أنس has two yellows (M1, M2), one short of the threshold of three.
        $this->assertSame(['goal' => 1, 'sanction' => 2, 'substitution' => 1], $m2->events()->get()->countBy('type')->sortKeys()->all());
        $this->assertSame(2, GameEvent::where('type', 'sanction')->where('subtype', 'yellow')->where('player_id', $this->player('أنس لعريبي')->id)->count());
        $this->assertSame(0, GameEvent::where('type', 'sanction')->where('subtype', 'yellow')->where('player_id', '!=', $this->player('أنس لعريبي')->id)->count());

        // M3: two goals; only the banned goalkeeper has a not-called row, رياض has none.
        $this->assertSame(['goal' => 2], $m3->events()->get()->countBy('type')->all());
        $this->assertSame([Demo::REASON_BANNED], $m3->players()->where('called_up', false)->pluck('not_called_reason')->all());
        $this->assertFalse($m3->players()->where('player_id', $this->player('رياض حمداني')->id)->exists());
        $this->assertSame(
            [0 => 'زكرياء', 1 => 'أنس', 2 => 'ياسين', 3 => 'أمين', 4 => 'أحمد'],
            $m3->players()->where('role', 'starter')->with('player')->orderBy('slot')->get()->mapWithKeys(fn (GamePlayer $gp) => [$gp->slot => $gp->player->firstname])->all(),
        );
        $this->assertSame(0, $m3->players()->where('role', 'bench')->count());
        $this->assertTrue((bool) $m3->players()->where('player_id', $this->player('زكرياء عمراني')->id)->value('is_goalkeeper'));
        $this->assertTrue((bool) $m3->players()->where('player_id', $this->player('أمين قاسمي')->id)->value('is_captain'));

        // The team's record: 3 played, 2 W 1 D 0 L, goals 6–2.
        $record = CompetitionRecord::fromGames($competition->games()->get());
        $this->assertSame([3, 2, 1, 0, 6, 2, ['W', 'D', 'W']], [
            $record['played'], $record['wins'], $record['draws'], $record['losses'], $record['goals_for'], $record['goals_against'], $record['form'],
        ]);

        // M4: call-up of six saved, no line-up; رياض is injured on the seed day, before M4.
        $this->assertTrue(app(PlayerFlags::class)->injured()[$this->player('رياض حمداني')->id] ?? false);
        $this->assertSame(6, $m4->players()->where('called_up', true)->count());
        $this->assertSame([Demo::REASON_INJURED], $m4->players()->where('called_up', false)->pluck('not_called_reason')->all());
        $this->assertSame(0, $m4->players()->where('role', '!=', 'none')->count());
        $this->assertNull($m4->formation_id);

        // Nothing else of the demo moves.
        $this->assertSame(22, Player::where('archived', false)->count());
        $this->assertSame(1, TrainingSession::where('state', SessionState::Cancelled->value)->count());
        $this->assertSame(0, ActivityLog::whereIn('subject_type', [
            Game::class, GamePlayer::class, GameEvent::class, Team::class, Competition::class, Opponent::class, Venue::class, Suspension::class,
        ])->count());
    }

    #[Test]
    public function a_seed_on_the_first_days_of_a_season_keeps_every_match_in_it(): void
    {
        $this->seedOn('2027-09-03 09:00:00');
        $s = Season::current()->startYear;
        $this->assertSame(2027, $s);

        $this->assertSame(5, Game::where('season_start_year', $s)->count());
        // 2027-09-03 is a Friday: F(-1) is the Friday before it, F(1) the one after; no match on D.
        // F(-3) and F(-4) fall before the season start; the competition keeps them in season S.
        $this->assertSame(
            ['2027-08-13', '2027-08-20', '2027-08-27', '2027-09-10', '2027-08-06'],
            [$this->game(Demo::ROUND_1)->date, $this->game(Demo::ROUND_2)->date, $this->game(Demo::ROUND_3)->date, $this->game(Demo::ROUND_4)->date, Game::whereNull('round_label')->value('date')],
        );
        $this->assertSame(0, Game::where('date', '2027-09-03')->count());
        $this->assertTrue(app(PlayerFlags::class)->injured()[$this->player('رياض حمداني')->id] ?? false);

        $this->actingAs(User::where('username', HelpDemoSeeder::USERNAME)->firstOrFail())
            ->get(route('games.stats'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Games/Stats')
                ->where('season', $s)
                ->where('rows', fn ($rows) => collect($rows)->firstWhere('name', $this->player('أمين قاسمي')->fullname)['appearances'] === 3
                    && collect($rows)->firstWhere('name', $this->player('أمين قاسمي')->fullname)['goals'] === 3));
    }
}
