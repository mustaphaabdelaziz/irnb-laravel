<?php

namespace Tests\Feature\Registrations;

use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Services\Attendance\AtRisk;
use App\Services\Attendance\InjurySpells;
use App\Services\Attendance\PlayerNames;
use App\Services\Pdf\PdfService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\Support\RegistrationSessions;
use Tests\TestCase;

class AttendanceNamesRegistrationTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase, RegistrationFixtures, RegistrationSessions;

    private const FROM = '2026-09-01';

    private const TO = '2027-08-31';

    private const DATES = ['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26', '2026-11-02'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->leaveDate = '2026-11-10';
        $this->twoSportClub();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function marks(Player $player, Branch $branch, Category $category, AttendanceStatus $status = AttendanceStatus::AbsentUnexcused): void
    {
        foreach (self::DATES as $date) {
            $this->markOn($this->sessionIn($branch, [$category], $date, ['state' => SessionState::Held]), $player, $status);
        }
    }

    private function names(array $rows, ?int $branchId = null): array
    {
        return app(PlayerNames::class)->attach($rows, $branchId);
    }

    #[Test]
    public function names_read_the_branchs_row(): void
    {
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => 'SW-2020-00001']);

        $main = $this->names([['player_id' => $player->id]])[0];
        $this->assertSame($this->senior->id, $main['category_id']);
        $this->assertSame('Senior', $main['category']);
        $this->assertTrue($main['active']);

        $swim = $this->names([['player_id' => $player->id]], $this->swim->id)[0];
        $this->assertSame($this->u15->id, $swim['category_id']);
        $this->assertSame('U15', $swim['category']);
        $this->assertSame('SW-2020-00001', $swim['membership_id']);
        $this->assertTrue($swim['active']);
    }

    #[Test]
    public function a_second_row_with_no_id_of_its_own_shows_none(): void
    {
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => null]);

        $this->assertNull($this->names([['player_id' => $player->id]], $this->swim->id)[0]['membership_id']);
    }

    #[Test]
    public function left_a_branch_is_not_active_there(): void
    {
        $player = $this->leftFootball();

        $this->assertFalse($this->names([['player_id' => $player->id]], $this->foot->id)[0]['active']);
        $this->assertTrue($this->names([['player_id' => $player->id]], $this->swim->id)[0]['active']);
        $this->assertTrue($this->names([['player_id' => $player->id]])[0]['active']);
    }

    #[Test]
    public function left_everywhere_is_active_nowhere(): void
    {
        $player = $this->leftBoth();

        $this->assertFalse($this->names([['player_id' => $player->id]], $this->foot->id)[0]['active']);
        $this->assertFalse($this->names([['player_id' => $player->id]], $this->swim->id)[0]['active']);
        $this->assertFalse($this->names([['player_id' => $player->id]])[0]['active']);
    }

    #[Test]
    public function no_registration_uses_the_players_columns(): void
    {
        $flat = $this->noRegistration();

        $row = $this->names([['player_id' => $flat->id]], $this->foot->id)[0];

        $this->assertSame($this->senior->id, $row['category_id']);
        $this->assertTrue($row['active']);
    }

    #[Test]
    public function a_rows_own_branch_beats_the_argument(): void
    {
        $player = $this->bothActive();

        $rows = $this->names([
            ['player_id' => $player->id, 'branch_id' => $this->swim->id],
            ['player_id' => $player->id, 'branch_id' => $this->foot->id],
        ], $this->foot->id);

        $this->assertSame('U15', $rows[0]['category']);
        $this->assertSame('Senior', $rows[1]['category']);
    }

    #[Test]
    public function a_branch_adds_no_query(): void
    {
        $player = $this->bothActive();
        $count = function (?int $branchId) use ($player): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->names([['player_id' => $player->id]], $branchId);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->assertSame(2, $count(null));
        $this->assertSame(2, $count($this->swim->id));
    }

    #[Test]
    public function at_risk_filters_and_shows_the_branchs_category(): void
    {
        $player = $this->bothActive();
        $this->marks($player, $this->swim, $this->u15);
        $risk = app(AtRisk::class);

        $rows = $risk->list(self::FROM, self::TO, null, $this->swim->id);
        $this->assertSame([$player->id], array_column($rows, 'player_id'));
        $this->assertSame('U15', $rows[0]['category']);
        $this->assertCount(1, $risk->list(self::FROM, self::TO, $this->u15->id, $this->swim->id));
        $this->assertCount(0, $risk->list(self::FROM, self::TO, $this->senior->id, $this->swim->id));
    }

    #[Test]
    public function at_risk_drops_a_player_who_left_that_branch(): void
    {
        $player = $this->leftFootball();
        $this->marks($player, $this->foot, $this->senior);
        $this->marks($player, $this->swim, $this->u15);
        $risk = app(AtRisk::class);

        $this->assertSame([], $risk->list(self::FROM, self::TO, null, $this->foot->id));
        $this->assertCount(1, $risk->list(self::FROM, self::TO, null, $this->swim->id));

        $any = $risk->anyBranch(self::FROM, self::TO);
        $this->assertCount(1, $any);
        $this->assertSame($this->swim->id, $any[0]['branch_id']);
        $this->assertSame('U15', $any[0]['category']);
    }

    #[Test]
    public function at_risk_keeps_a_guest_with_no_row_and_no_registration(): void
    {
        $flat = $this->noRegistration();
        $this->marks($flat, $this->foot, $this->senior);

        $rows = app(AtRisk::class)->list(self::FROM, self::TO, null, $this->foot->id);

        $this->assertSame([$flat->id], array_column($rows, 'player_id'));
        $this->assertSame('Senior', $rows[0]['category']);
    }

    #[Test]
    public function injuries_follow_the_branch(): void
    {
        $player = $this->bothActive();
        $left = $this->leftFootball();
        $this->markOn($this->sessionIn($this->swim, [$this->u15], '2026-10-05', ['state' => SessionState::Held]), $player, AttendanceStatus::NotTraining);
        $this->markOn($this->sessionIn($this->foot, [$this->senior], '2026-10-05', ['state' => SessionState::Held]), $left, AttendanceStatus::NotTraining);
        $spells = app(InjurySpells::class);

        $swim = $spells->club(self::FROM, self::TO, null, $this->swim->id);
        $this->assertSame('U15', $swim['current'][0]['category']);
        $this->assertCount(1, $spells->club(self::FROM, self::TO, $this->u15->id, $this->swim->id)['current']);
        $this->assertCount(0, $spells->club(self::FROM, self::TO, $this->senior->id, $this->swim->id)['current']);
        $this->assertSame([], $spells->club(self::FROM, self::TO, null, $this->foot->id)['current']);
    }

    #[Test]
    public function the_ranking_leaves_out_a_player_who_left_that_branch(): void
    {
        $stays = $this->bothActive();
        $left = $this->leftFootball();
        foreach (self::DATES as $date) {
            $session = $this->sessionIn($this->foot, [$this->senior], $date, ['state' => SessionState::Held]);
            $this->markOn($session, $stays, AttendanceStatus::Present);
            $this->markOn($session, $left, AttendanceStatus::Present);
        }

        $this->actingAs($this->admin())->get(route('attendance.ranking', [
            'type' => 'season', 'season' => 2026, 'category_id' => $this->senior->id, 'branch_id' => $this->foot->id,
        ]))->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.player_id', $stays->id)
            ->where('rows.0.category', 'Senior')
            ->where('unranked', 0));
    }

    #[Test]
    public function the_statistics_table_shows_the_branchs_category(): void
    {
        $player = $this->bothActive();
        $this->marks($player, $this->swim, $this->u15, AttendanceStatus::Present);

        $this->actingAs($this->admin())->get(route('attendance.stats', ['branch_id' => $this->swim->id, 'period' => 'custom', 'from' => self::FROM, 'to' => self::TO]))
            ->assertInertia(fn (Assert $page) => $page->where('players', function (Collection $players) use ($player) {
                $this->assertSame('U15', collect($players->firstWhere('player_id', $player->id))['category']);

                return true;
            }));
    }

    #[Test]
    public function a_certificate_needs_the_category_in_that_branch(): void
    {
        $this->mock(PdfService::class, function (MockInterface $mock) {
            $mock->shouldReceive('stream')->andReturnUsing(fn () => response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']));
        });
        $both = $this->bothActive();
        $left = $this->leftFootball();
        $flat = $this->noRegistration();
        $admin = $this->admin();
        $get = fn (Player $p, Branch $b, Category $c) => $this->actingAs($admin)->get(route('attendance.certificates', [
            'category_id' => $c->id, 'branch_id' => $b->id, 'player_id' => $p->id,
        ]));

        $get($both, $this->swim, $this->u15)->assertOk();
        $get($both, $this->foot, $this->senior)->assertOk();
        $get($both, $this->swim, $this->senior)->assertNotFound();
        $get($both, $this->foot, $this->u15)->assertNotFound();
        $get($left, $this->foot, $this->senior)->assertNotFound();
        $get($left, $this->swim, $this->u15)->assertOk();
        $get($flat, $this->foot, $this->senior)->assertNotFound();
    }
}
