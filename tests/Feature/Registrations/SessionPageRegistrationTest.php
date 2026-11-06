<?php

namespace Tests\Feature\Registrations;

use App\Enums\AttendanceStatus;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Services\Attendance\Roster;
use App\Services\Pdf\PdfService;
use App\Services\Player\RegistrationService;
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

class SessionPageRegistrationTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase, RegistrationFixtures, RegistrationSessions;

    private Category $u17;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->u17 = Category::create(['name' => 'U17']);
    }

    #[Test]
    public function a_joint_sessions_tag_is_the_category_in_its_branch(): void
    {
        $player = $this->bothActive();
        $session = $this->sessionIn($this->swim, [$this->u15, $this->senior]);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.player_id', $player->id)
                ->where('rows.0.category', 'U15'));
    }

    #[Test]
    public function a_marks_own_category_still_wins(): void
    {
        $player = $this->bothActive();
        $session = $this->sessionIn($this->swim, [$this->u15, $this->senior]);
        $this->markOn($session, $player, AttendanceStatus::Present);
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['category_id' => $this->senior->id]);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertInertia(fn (Assert $page) => $page->where('rows.0.category', 'U15'));
    }

    #[Test]
    public function candidates_follow_active_registrations(): void
    {
        $both = $this->bothActive();
        $leftFoot = $this->leftFootball();
        $leftBoth = $this->leftBoth();
        $flat = $this->noRegistration();
        $registered = PlayerStatus::where('code', 'registered')->firstOrFail()->localized_name;
        // Nobody is on this session's roster: everyone left to add is a candidate.
        $session = $this->sessionIn($this->foot, [$this->u17], '2026-04-01');

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates', 3)
                ->where('candidates.0.id', $both->id)
                ->where('candidates', function (Collection $candidates) use ($both, $leftFoot, $leftBoth, $flat, $registered) {
                    $row = fn (Player $p) => collect($candidates->firstWhere('id', $p->id));

                    $this->assertTrue($row($both)['in_branch']);
                    $this->assertSame('Senior', $row($both)['category']);
                    $this->assertSame($registered, $row($both)['status']);
                    $this->assertSame('Football, Swimming', $row($both)['branches']);

                    // Left football: a guest here, shown as the swimmer they still are.
                    $this->assertFalse($row($leftFoot)['in_branch']);
                    $this->assertSame('U15', $row($leftFoot)['category']);
                    $this->assertSame($registered, $row($leftFoot)['status']);
                    $this->assertSame('Swimming', $row($leftFoot)['branches']);

                    $this->assertNull($candidates->firstWhere('id', $leftBoth->id));

                    $this->assertFalse($row($flat)['in_branch']);
                    $this->assertSame('Senior', $row($flat)['category']);
                    $this->assertSame('', $row($flat)['branches']);

                    return true;
                }));
    }

    #[Test]
    public function a_session_with_no_branch_has_everyone_in_branch(): void
    {
        $flat = $this->noRegistration();
        $session = $this->sessionIn(null, [$this->u17], '2026-04-01');

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.0.id', $flat->id)
                ->where('candidates.0.in_branch', true));
    }

    #[Test]
    public function left_everywhere_is_a_candidate_only_up_to_the_leave_date(): void
    {
        $leftBoth = $this->leftBoth();
        $session = $this->sessionIn($this->foot, [$this->u17], $this->leaveDate);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertInertia(fn (Assert $page) => $page->where('candidates.0.id', $leftBoth->id));
    }

    #[Test]
    public function category_ids_in_reads_each_case_with_one_load(): void
    {
        $both = $this->bothActive();
        $flat = $this->noRegistration('Flat', $this->u17->id);
        $solo = Player::create(['firstname' => 'Solo', 'lastname' => 'Test', 'join_year' => 2020, 'membership_id' => $this->fixtureId()]);
        app(RegistrationService::class)->register($solo, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);
        $players = Player::whereIn('id', [$both->id, $flat->id, $solo->id])->get();

        $ids = app(Roster::class)->categoryIdsIn($players, $this->swim->id);

        $this->assertSame($this->u15->id, $ids[$both->id]);
        $this->assertSame($this->u17->id, $ids[$flat->id]);
        $this->assertSame($this->senior->id, $ids[$solo->id]);
    }

    #[Test]
    public function the_month_sheet_labels_another_category_from_the_branchs_row(): void
    {
        $this->bothActive();
        $this->sessionIn($this->swim, [$this->u15], '2026-10-05');
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $html) use ($seen) {
                $seen['html'] = $html;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        $this->actingAs($this->admin())->get(route('attendance.sheets.month', [
            'category_id' => $this->u15->id, 'month' => '2026-10', 'branch_id' => $this->swim->id,
        ]))->assertOk();

        // The mirror says Senior; in swimming this player is U15, so no label.
        $this->assertStringNotContainsString('(Senior)', $seen['html']);
    }

    #[Test]
    public function a_player_who_left_after_the_session_date_is_in_branch_for_that_session(): void
    {
        $leftFoot = $this->leftFootball();
        $before = $this->sessionIn($this->foot, [$this->u17], '2026-03-10');
        $after = $this->sessionIn($this->foot, [$this->u17], '2026-04-01');

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $before))
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates', fn (Collection $c) => $c->firstWhere('id', $leftFoot->id)['in_branch'] === true));
        $this->get(route('attendance.sessions.show', $after))
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates', fn (Collection $c) => $c->firstWhere('id', $leftFoot->id)['in_branch'] === false));
    }
}
