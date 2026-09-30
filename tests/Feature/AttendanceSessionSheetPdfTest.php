<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Pdf\PdfService;
use App\Services\Player\FileNumber;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceSessionSheetPdfTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

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
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ], $extra)); // overrides win
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status = AttendanceStatus::Present, array $extra = []): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'status' => $status] + $extra);
        $training->update(['state' => SessionState::Held]);
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

    private function sheetHtml(TrainingSession $training, string $locale = 'fr', array $query = []): string
    {
        $seen = $this->spyPdf();
        $this->actingAs($this->userIn($locale))->get(route('attendance.sheets.session', ['session' => $training->id] + $query))->assertOk();

        return $seen['html'];
    }

    #[Test]
    public function the_sheet_renders_as_a_real_pdf_in_arabic(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $training = $this->training($u15, '2026-10-05', ['title' => 'جري 7 كم', 'coach' => 'كريم']);

        $response = $this->actingAs($this->userIn('ar'))->get(route('attendance.sheets.session', $training))->assertOk();

        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    #[Test]
    public function the_french_sheet_is_portrait_with_the_session_header_and_log(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $player = $this->player($u15);
        FileNumber::assign($player);
        $joint = $this->training($u15, '2026-10-05', ['kind' => SessionKind::Preseason, 'title' => 'Running 7.2 km', 'coach' => 'Karim']);
        $joint->categories()->syncWithoutDetaching([$u17->id]);
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.sheets.session', $joint))->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertFalse($seen['landscape']);
        $this->assertSame("attendance-session-{$joint->id}-2026-10-05.pdf", $seen['filename']);
        foreach (['Feuille de présence de la séance', 'U15 · U17', 'Préparation physique', 'lundi 5 octobre 2026', '18:00–19:30', 'Running 7.2 km', 'Karim', '0001', 'Signature', '<thead>'] as $text) {
            $this->assertStringContainsString($text, $seen['html'], $text);
        }
    }

    #[Test]
    public function before_marking_the_list_is_the_expected_roster_of_every_category(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $left = Player::leftStatusId();
        $this->player($u15);                                                          // Test001 P1
        $this->player($u17);                                                          // Test002 P2
        $this->player($u15, ['archived' => true]);                                    // Test003 P3
        $this->player($u15, ['status_id' => $left, 'left_at' => '2026-10-01']);      // Test004 P4: left before
        $this->player($u17, ['status_id' => $left, 'left_at' => '2026-10-20']);      // Test005 P5: leaves later
        $joint = $this->training($u15, '2026-10-05', ['kind' => SessionKind::Preseason]);
        $joint->categories()->syncWithoutDetaching([$u17->id]);

        $html = $this->sheetHtml($joint);

        foreach (['Test001 P1', 'Test002 P2', 'Test005 P5', '(U15)', '(U17)'] as $listed) {
            $this->assertStringContainsString($listed, $html, $listed);
        }
        $this->assertStringNotContainsString('Test003 P3', $html);
        $this->assertStringNotContainsString('Test004 P4', $html);
        $this->assertSame(3, substr_count($html, 'class="blank-row"'));
    }

    #[Test]
    public function after_marking_the_list_is_frozen(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $stays = $this->player($u15);   // Test001 P1
        $moved = $this->player($u15);   // Test002 P2
        $held = $this->training($u15, '2026-10-05');
        $this->mark($held, $stays);
        $this->mark($held, $moved);
        $moved->update(['category_id' => $u17->id]);
        $this->player($u15);            // Test003 P3: newcomer

        $html = $this->sheetHtml($held);

        $this->assertStringContainsString('Test001 P1', $html);
        $this->assertStringContainsString('Test002 P2', $html);
        $this->assertStringNotContainsString('Test003 P3', $html);
        // A single-category session tags nobody.
        $this->assertStringNotContainsString('(U17)', $html);
    }

    #[Test]
    public function status_columns_use_the_configured_codes_and_names(): void
    {
        AttendanceSettings::save(['codes' => ['late' => ['code' => 'T', 'label' => ['fr' => 'Tardif']]]]);
        $u15 = $this->category('U15');
        $this->player($u15);
        $training = $this->training($u15, '2026-10-05');

        $html = $this->sheetHtml($training);

        foreach (['<bdi dir="ltr">T</bdi>', 'Tardif', 'Présent', 'Parti tôt', 'Présent, sans entraînement', 'Absent (justifié)', 'Absent (non justifié)', 'AE', 'AN', 'Blessure', 'Maladie', 'École', 'Minutes'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
    }

    #[Test]
    public function a_blank_session_sheet_prints_no_marks(): void
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        $this->player($u15);
        $held = $this->training($u15, '2026-10-05');
        $this->mark($held, $player, AttendanceStatus::Late, ['minutes' => 25, 'note' => 'Bus en retard']);
        $this->mark($held, Player::where('id', '!=', $player->id)->first());

        $blank = $this->sheetHtml($held);

        $this->assertStringNotContainsString('Bus en retard', $blank);
        $this->assertSame(0, substr_count($blank, 'class="tick"'));
    }

    #[Test]
    public function the_filled_session_sheet_prints_the_marks(): void
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        $this->player($u15);
        $held = $this->training($u15, '2026-10-05');
        $this->mark($held, $player, AttendanceStatus::Late, ['minutes' => 25, 'note' => 'Bus en retard']);
        $this->mark($held, Player::where('id', '!=', $player->id)->first());

        $filled = $this->sheetHtml($held, 'fr', ['filled' => 1]);

        $this->assertStringContainsString('Bus en retard', $filled);
        $this->assertSame(2, substr_count($filled, 'class="tick"'));
        $this->assertStringContainsString('Imprimée avec les présences déjà enregistrées.', $filled);
    }

    #[Test]
    public function the_arabic_sheet_is_right_to_left(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $training = $this->training($u15, '2026-10-05');
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('ar'))->get(route('attendance.sheets.session', $training))->assertOk();

        $this->assertTrue($seen['rtl']);
        $this->assertFalse($seen['landscape']);
        $this->assertStringContainsString('ورقة حضور الحصة', $seen['html']);
        $this->assertStringContainsString('متأخر', $seen['html']);
    }

    #[Test]
    public function a_cancelled_session_prints_with_a_banner(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $training = $this->training($u15, '2026-10-05', ['state' => SessionState::Cancelled, 'cancel_reason' => 'Pluie']);

        $this->assertStringContainsString('Annulée : Pluie', $this->sheetHtml($training));
    }

    #[Test]
    public function printing_needs_attendance_view_only(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $training = $this->training($u15, '2026-10-05');
        $this->spyPdf();

        $this->actingAs($this->userWith(['attendance' => ['view']]))->get(route('attendance.sheets.session', $training))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view']]))->get(route('attendance.sheets.session', $training))->assertForbidden();
    }
}
