<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Pdf\PdfService;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendancePlayerReportTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private const OCTOBER = ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userIn(string $locale): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function seedPlayer(): Player
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        $training = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Preseason, 'state' => SessionState::Held, 'title' => 'Running 7.2 km',
        ]);
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'status' => AttendanceStatus::Late, 'minutes' => 15, 'note' => 'Bus']);

        return $player;
    }

    private function spyPdf(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $html, string $filename, bool $rtl = true, bool $landscape = false) use ($seen) {
                $seen['html'] = $html;
                $seen['filename'] = $filename;
                $seen['rtl'] = $rtl;
                $seen['landscape'] = $landscape;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        return $seen;
    }

    #[Test]
    public function the_report_renders_as_a_pdf(): void
    {
        $player = $this->seedPlayer();

        $response = $this->actingAs($this->userIn('ar'))->get(route('attendance.players.report', $player))->assertOk();

        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
    }

    #[Test]
    public function the_report_lists_the_period_and_sessions_with_the_configured_names(): void
    {
        AttendanceSettings::save(['codes' => ['late' => ['label' => ['ar' => 'Tardy', 'fr' => 'Tardy', 'en' => 'Tardy']]]]);
        $player = $this->seedPlayer();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))
            ->get(route('attendance.players.report', ['player' => $player] + self::OCTOBER))
            ->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertFalse($seen['landscape']);
        $this->assertSame("attendance-{$player->membership_id}-2026-10-01-2026-10-31.pdf", $seen['filename']);
        $this->assertStringContainsString('Rapport de présence', $seen['html']);
        $this->assertStringContainsString($player->fullname, $seen['html']);
        $this->assertStringContainsString('2026-10-01 – 2026-10-31', $seen['html']);
        $this->assertStringContainsString('Running 7.2 km', $seen['html']);
        $this->assertStringContainsString('Tardy', $seen['html']);
        $this->assertStringContainsString('Bus', $seen['html']);
    }

    #[Test]
    public function the_report_is_right_to_left_in_arabic(): void
    {
        $player = $this->seedPlayer();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('ar'))->get(route('attendance.players.report', ['player' => $player] + self::OCTOBER))->assertOk();

        $this->assertTrue($seen['rtl']);
        $this->assertStringContainsString('تقرير الحضور', $seen['html']);
        $this->assertStringContainsString('متأخر', $seen['html']);   // unset name: built-in Arabic
    }

    #[Test]
    public function the_report_needs_attendance_view(): void
    {
        $player = $this->seedPlayer();
        $playersOnly = User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => ['players' => ['view']]])->id]);

        $this->actingAs($playersOnly)->get(route('attendance.players.report', $player))->assertForbidden();
    }
}
