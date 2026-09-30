<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerEmergencyContact;
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

class AttendanceLetterPdfTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private const OCTOBER = ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');   // season 2026/27
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

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function training(Category $category, string $date, array $extra = []): TrainingSession
    {
        return TrainingSession::create(array_merge([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ], $extra)); // overrides win
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status, array $extra = []): void
    {
        Attendance::create(array_merge([
            'training_session_id' => $training->id, 'player_id' => $player->id,
            'category_id' => $player->category_id, 'status' => $status,
        ], $extra));
    }

    /** U15; October: present, late 12, excused (injury), unexcused, left early 20, not training; September: unexcused. */
    private function seedPlayer(): Player
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        $this->mark($this->training($u15, '2026-10-01'), $player, AttendanceStatus::Present);
        $this->mark($this->training($u15, '2026-10-03', ['kind' => SessionKind::Preseason]), $player, AttendanceStatus::Late, ['minutes' => 12]);
        $this->mark($this->training($u15, '2026-10-05'), $player, AttendanceStatus::AbsentExcused, ['reason' => 'injury']);
        $this->mark($this->training($u15, '2026-10-07'), $player, AttendanceStatus::AbsentUnexcused);
        $this->mark($this->training($u15, '2026-10-09'), $player, AttendanceStatus::LeftEarly, ['minutes' => 20]);
        $this->mark($this->training($u15, '2026-10-12'), $player, AttendanceStatus::NotTraining, ['reason' => 'injury']);
        $this->mark($this->training($u15, '2026-09-15'), $player, AttendanceStatus::AbsentUnexcused);

        return $player;
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
    public function the_letter_renders_as_a_real_pdf_in_arabic(): void
    {
        $player = $this->seedPlayer();

        $response = $this->actingAs($this->userIn('ar'))->get(route('attendance.players.letter', $player))->assertOk();

        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    #[Test]
    public function the_french_letter_has_the_recipient_subject_text_details_totals_and_signatures(): void
    {
        $player = $this->seedPlayer();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.players.letter', ['player' => $player] + self::OCTOBER))->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertFalse($seen['landscape']);
        $this->assertSame("attendance-letter-{$player->membership_id}-2026-10-01-2026-10-31.pdf", $seen['filename']);
        $html = $seen['html'];
        foreach ([
            '20/10/2026',                                               // dated today
            'Parent / tuteur de '.$player->fullname,                    // no emergency contact
            'Objet :',
            'Assiduité de '.$player->fullname.' aux entraînements',
            $player->fullname.' (U15) a manqué 2 séance(s)',            // excused + unexcused
            'en retard 1 fois durant la période 01/10/2026 – 31/10/2026.',
            'Détail de la période',
            '03/10/2026', '05/10/2026', '07/10/2026', '09/10/2026',
            'En retard', 'Parti tôt', 'Absent (justifié)', 'Absent (non justifié)', 'Blessure',
            '<td class="num">12</td>', '<td class="num">20</td>',
            'Totaux', 'entraîneur', 'Le président',
        ] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        // Present and "not training" are not listed; September is outside the period.
        $this->assertStringNotContainsString('12/10/2026', $html);
        $this->assertStringNotContainsString('15/09/2026', $html);
        // Oldest first.
        $this->assertLessThan(strpos($html, '09/10/2026'), strpos($html, '03/10/2026'));
    }

    #[Test]
    public function the_first_emergency_contact_is_the_recipient(): void
    {
        $player = $this->seedPlayer();
        PlayerEmergencyContact::create(['player_id' => $player->id, 'name' => 'Karim Benali', 'relationship' => 'father']);
        PlayerEmergencyContact::create(['player_id' => $player->id, 'name' => 'Salma Benali', 'relationship' => 'mother']);
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.players.letter', ['player' => $player] + self::OCTOBER))->assertOk();

        $this->assertStringContainsString('Karim Benali', $seen['html']);
        $this->assertStringNotContainsString('Salma Benali', $seen['html']);
        $this->assertStringNotContainsString('Parent / tuteur de', $seen['html']);
    }

    #[Test]
    public function a_saved_text_is_filled_in_escaped_and_keeps_its_line_breaks(): void
    {
        AttendanceSettings::save(['letter' => [
            'subject' => ['fr' => 'Absences de {player}'],
            'body' => ['fr' => "Madame, Monsieur,\n{player} : {absences} absences et {lates} retard(s) <b>à revoir</b> — {club}"],
        ]]);
        $player = $this->seedPlayer();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.players.letter', ['player' => $player] + self::OCTOBER))->assertOk();

        $html = $seen['html'];
        $this->assertStringContainsString('Absences de '.$player->fullname, $html);
        $this->assertStringContainsString('Madame, Monsieur,<br>', $html);
        $this->assertStringContainsString(': 2 absences et 1 retard(s) &lt;b&gt;à revoir&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>à revoir</b>', $html);
        $this->assertStringNotContainsString('{club}', $html);
    }

    #[Test]
    public function the_arabic_letter_is_right_to_left(): void
    {
        $player = $this->seedPlayer();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('ar'))->get(route('attendance.players.letter', ['player' => $player] + self::OCTOBER))->assertOk();

        $this->assertTrue($seen['rtl']);
        $this->assertStringContainsString('الموضوع:', $seen['html']);
        $this->assertStringContainsString('وليّ أمر '.$player->fullname, $seen['html']);
        $this->assertStringContainsString('غائب بعذر', $seen['html']);
    }

    #[Test]
    public function the_default_period_is_the_current_season(): void
    {
        $player = $this->seedPlayer();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.players.letter', $player))->assertOk();

        $this->assertSame("attendance-letter-{$player->membership_id}-2026-09-01-2027-08-31.pdf", $seen['filename']);
        $this->assertStringContainsString('15/09/2026', $seen['html']);
        $this->assertStringContainsString('(U15) a manqué 3 séance(s)', $seen['html']);
    }

    #[Test]
    public function printing_needs_attendance_view_only(): void
    {
        $player = $this->seedPlayer();
        $this->spyPdf();

        $this->actingAs($this->userWith(['attendance' => ['view']]))->get(route('attendance.players.letter', $player))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view']]))->get(route('attendance.players.letter', $player))->assertForbidden();
    }
}
