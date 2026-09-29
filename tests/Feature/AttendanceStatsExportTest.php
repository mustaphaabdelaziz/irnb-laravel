<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Pdf\PdfService;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceStatsExportTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private const OCTOBER = ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'];

    private function userIn(string $locale): User
    {
        // SetLocale reads users.preferred_lng.
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    /** One U15 player marked late in one October session; returns [player, category]. */
    private function seedOctober(): array
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        $training = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'status' => AttendanceStatus::Late, 'minutes' => 15]);

        return [$player, $u15];
    }

    /** Swaps mPDF for a spy; returns a reference filled with what stream() received. */
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
    public function the_spreadsheet_lists_every_player_with_the_configured_names(): void
    {
        AttendanceSettings::save(['codes' => ['late' => ['label' => ['ar' => 'Tardy', 'fr' => 'Tardy', 'en' => 'Tardy']]]]);
        [$player] = $this->seedOctober();

        $response = $this->actingAs($this->userIn('fr'))
            ->get(route('attendance.stats.export', self::OCTOBER + ['format' => 'csv']))
            ->assertOk();
        $csv = $response->streamedContent();

        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('content-type'));
        $this->assertStringContainsString('attendance-stats-2026-10-01-2026-10-31.csv', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('Statistiques de présence — 2026-10-01 – 2026-10-31 — ', $csv);
        $this->assertStringContainsString('Présent,"Présent %",Tardy,"Tardy %"', $csv);   // unset name: built-in French
        $this->assertStringContainsString('"Retard (min)"', $csv);
        $this->assertStringContainsString($player->fullname, $csv);
        $this->assertStringContainsString(',U15,1,0,0,1,100,', $csv);   // expected 1; present 0 (0%); late 1 (100%)
    }

    #[Test]
    public function the_category_filter_applies_to_the_export(): void
    {
        [, $u15] = $this->seedOctober();
        $u17 = $this->category('U17');
        $other = $this->player($u17);

        $csv = $this->actingAs($this->userIn('en'))
            ->get(route('attendance.stats.export', self::OCTOBER + ['category_id' => $u17->id, 'format' => 'csv']))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString(' — U17', $csv);
        $this->assertStringNotContainsString(Player::where('category_id', $u15->id)->first()->fullname, $csv);
        $this->assertStringNotContainsString($other->fullname, $csv);   // no mark in the period
    }

    #[Test]
    public function the_pdf_renders(): void
    {
        $this->seedOctober();

        $response = $this->actingAs($this->userIn('ar'))
            ->get(route('attendance.stats.export', self::OCTOBER + ['format' => 'pdf']))
            ->assertOk();

        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
    }

    #[Test]
    public function the_pdf_is_landscape_right_to_left_in_arabic_and_uses_the_configured_names(): void
    {
        AttendanceSettings::save(['codes' => ['late' => ['label' => ['ar' => 'تأخير مسجل', 'fr' => 'Tardy', 'en' => 'Tardy']]]]);
        [$player] = $this->seedOctober();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('ar'))->get(route('attendance.stats.export', self::OCTOBER + ['format' => 'pdf']))->assertOk();

        $this->assertTrue($seen['rtl']);
        $this->assertTrue($seen['landscape']);
        $this->assertSame('attendance-stats-2026-10-01-2026-10-31.pdf', $seen['filename']);
        $this->assertStringContainsString('تأخير مسجل', $seen['html']);
        $this->assertStringContainsString('إحصائيات الحضور', $seen['html']);
        $this->assertStringContainsString($player->fullname, $seen['html']);
        $this->assertStringContainsString('2026-10-01 – 2026-10-31', $seen['html']);
    }

    #[Test]
    public function the_pdf_is_left_to_right_in_french(): void
    {
        $this->seedOctober();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.stats.export', self::OCTOBER + ['format' => 'pdf']))->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertStringContainsString('En retard', $seen['html']);   // unset name: built-in French
    }
}
