<?php

namespace Tests\Feature\Registrations;

use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\Player;
use App\Models\PreseasonTarget;
use App\Services\Pdf\PdfService;
use App\Services\Player\RegistrationService;
use App\Support\AttendanceSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\Support\RegistrationSessions;
use Tests\TestCase;

class PlayerAttendanceCardRegistrationTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase, RegistrationFixtures, RegistrationSessions;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');   // season 2026/27
        $this->twoSportClub();
        PreseasonTarget::create(['branch_id' => $this->foot->id, 'category_id' => $this->senior->id, 'season_start_year' => 2026, 'target_count' => 10]);
        PreseasonTarget::create(['branch_id' => $this->swim->id, 'category_id' => $this->u15->id, 'season_start_year' => 2026, 'target_count' => 4]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function spyPdf(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->andReturnUsing(function (string $html, string $filename) use ($seen) {
                $seen['html'] = $html;
                $seen['filename'] = $filename;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        return $seen;
    }

    private function swimmingId(Player $player): string
    {
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => 'SW-2020-00001']);

        return 'SW-2020-00001';
    }

    #[Test]
    public function each_tab_uses_its_own_category_for_the_target(): void
    {
        $player = $this->bothActive();

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', ['player' => $player, 'branch_id' => $this->foot->id]))
            ->assertJsonPath('preseason.target', 10);
        $this->actingAs($this->admin())->getJson(route('attendance.players.show', ['player' => $player, 'branch_id' => $this->swim->id]))
            ->assertJsonPath('preseason.target', 4);
    }

    #[Test]
    public function a_left_branch_keeps_its_tab_and_its_target(): void
    {
        $player = $this->leftFootball();

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', ['player' => $player, 'branch_id' => $this->foot->id]))
            ->assertJsonCount(2, 'branches')
            ->assertJsonPath('branchId', $this->foot->id)
            ->assertJsonPath('preseason.target', 10);
    }

    #[Test]
    public function left_everywhere_keeps_every_tab(): void
    {
        $player = $this->leftBoth();

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', ['player' => $player, 'branch_id' => $this->swim->id]))
            ->assertJsonCount(2, 'branches')
            ->assertJsonPath('preseason.target', 4);
    }

    #[Test]
    public function a_tab_with_marks_but_no_registration_has_no_target(): void
    {
        $player = Player::create(['firstname' => 'Solo', 'lastname' => 'Test', 'join_year' => 2020, 'membership_id' => 'SOLO-1']);
        app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);
        $this->markOn($this->sessionIn($this->swim, [$this->u15], '2026-10-05', ['state' => SessionState::Held]), $player, AttendanceStatus::Present);

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', ['player' => $player, 'branch_id' => $this->swim->id]))
            ->assertJsonCount(2, 'branches')
            ->assertJsonPath('preseason', null);
    }

    #[Test]
    public function no_registration_in_a_club_with_branches_has_no_target(): void
    {
        $this->actingAs($this->admin())->getJson(route('attendance.players.show', $this->noRegistration()))
            ->assertJsonPath('branchId', null)
            ->assertJsonPath('preseason', null);
    }

    #[Test]
    public function the_report_prints_the_tabs_id_and_category(): void
    {
        $player = $this->bothActive();
        $id = $this->swimmingId($player);
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.players.report', ['player' => $player, 'branch_id' => $this->swim->id]))->assertOk();

        $this->assertStringContainsString($id, $seen['html']);
        $this->assertStringContainsString('U15', $seen['html']);
        $this->assertStringNotContainsString('Senior', $seen['html']);
        $this->assertStringContainsString("attendance-{$id}-", $seen['filename']);
    }

    #[Test]
    public function the_report_of_a_tab_with_no_registration_prints_the_main_values(): void
    {
        $player = Player::create(['firstname' => 'Solo', 'lastname' => 'Test', 'join_year' => 2020, 'membership_id' => 'SOLO-2']);
        app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);
        $this->markOn($this->sessionIn($this->swim, [$this->u15], '2026-10-05', ['state' => SessionState::Held]), $player, AttendanceStatus::Present);
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.players.report', ['player' => $player, 'branch_id' => $this->swim->id]))->assertOk();

        $this->assertStringContainsString('Senior', $seen['html']);
    }

    #[Test]
    public function the_letter_names_the_tabs_category_and_id(): void
    {
        $player = $this->bothActive();
        $id = $this->swimmingId($player);
        $body = 'CAT={category}';
        AttendanceSettings::save(['letter' => ['body' => ['ar' => $body, 'fr' => $body, 'en' => $body]]]);
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.players.letter', ['player' => $player, 'branch_id' => $this->swim->id]))->assertOk();

        $this->assertStringContainsString('CAT=U15', $seen['html']);
        $this->assertStringContainsString("attendance-letter-{$id}-", $seen['filename']);
    }

    #[Test]
    public function a_registration_with_no_id_leaves_no_empty_segment_in_the_file_names(): void
    {
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => null]);
        $query = ['player' => $player, 'branch_id' => $this->swim->id];
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.players.report', $query))->assertOk();
        $this->assertStringStartsWith('attendance-2026-', $seen['filename']);
        $this->assertStringNotContainsString('--', $seen['filename']);

        $this->actingAs($this->admin())->get(route('attendance.players.letter', $query))->assertOk();
        $this->assertStringStartsWith('attendance-letter-2026-', $seen['filename']);
    }
}
