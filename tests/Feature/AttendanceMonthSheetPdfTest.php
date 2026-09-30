<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSchedule;
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

class AttendanceMonthSheetPdfTest extends TestCase
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

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status = AttendanceStatus::Present, ?int $minutes = null): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'status' => $status, 'minutes' => $minutes]);
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

    private function url(Category $category, array $query = []): string
    {
        return route('attendance.sheets.month', ['category_id' => $category->id, 'month' => '2026-10'] + $query);
    }

    private function sheetHtml(Category $category, string $locale = 'fr', array $query = []): string
    {
        $seen = $this->spyPdf();
        $this->actingAs($this->userIn($locale))->get($this->url($category, $query))->assertOk();

        return $seen['html'];
    }

    /** Pages in a real mPDF document (page objects, not the /Pages tree). */
    private static function pages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page\b#', $pdf);
    }

    #[Test]
    public function the_sheet_renders_as_a_real_pdf_in_arabic(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-10-05', ['kind' => SessionKind::Preseason, 'title' => 'جري 7 كم']);

        $response = $this->actingAs($this->userIn('ar'))->get($this->url($u15))->assertOk();

        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    #[Test]
    public function the_french_sheet_is_landscape_with_the_club_category_month_and_season(): void
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        FileNumber::assign($player);
        $this->training($u15, '2026-10-05', ['title' => 'Sprint 30 m']);
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get($this->url($u15))->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertTrue($seen['landscape']);
        $this->assertSame("attendance-sheet-{$u15->id}-2026-10.pdf", $seen['filename']);
        foreach (['Feuille de présence', 'U15', 'octobre 2026', '2026/27', 'Test001 P1', '0001', '05/10', '18:00', 'Sprint 30 m', 'Signature', '<thead>', '{PAGENO}', '{nbpg}'] as $text) {
            $this->assertStringContainsString($text, $seen['html'], $text);
        }
        $this->assertStringNotContainsString('Imprimée avec les présences', $seen['html']);
    }

    #[Test]
    public function printing_generates_the_month_first(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);

        $html = $this->sheetHtml($u15);

        $this->assertSame(4, TrainingSession::count());
        foreach (['05/10', '12/10', '19/10', '26/10'] as $date) {
            $this->assertStringContainsString($date, $html);
        }
    }

    #[Test]
    public function rows_follow_the_roster_rules(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $left = Player::leftStatusId();
        $stays = $this->player($u15);                                                  // Test001 P1
        $this->player($u15, ['archived' => true]);                                     // Test002 P2
        $this->player($u15, ['status_id' => $left, 'left_at' => '2026-09-20']);       // Test003 P3: left before the month
        $this->player($u15, ['status_id' => $left, 'left_at' => '2026-10-10']);       // Test004 P4: left mid-month
        $moved = $this->player($u15);                                                  // Test005 P5
        $held = $this->training($u15, '2026-10-05');
        $this->mark($held, $stays);
        $this->mark($held, $moved);
        $moved->update(['category_id' => $u17->id]);
        $this->player($u15);                                                           // Test006 P6: newcomer
        $this->training($u15, '2026-10-07');
        $this->training($u15, '2026-10-12');

        $html = $this->sheetHtml($u15);

        foreach (['Test001 P1', 'Test004 P4', 'Test005 P5', 'Test006 P6', '(U17)'] as $listed) {
            $this->assertStringContainsString($listed, $html, $listed);
        }
        $this->assertStringNotContainsString('Test002 P2', $html);
        $this->assertStringNotContainsString('Test003 P3', $html);
        // Grey cells: P4 is on the 7th only (the 5th is frozen, he left before the 12th),
        // P5 on the frozen 5th only, P6 not on the frozen 5th.
        $this->assertSame(5, substr_count($html, 'class="off"'));
        // Blank sheet: the stored "P" marks of the 5th are not printed.
        $this->assertSame(0, substr_count($html, 'class="cell">P<'));
    }

    #[Test]
    public function a_joint_pre_season_session_appears_with_its_whole_roster(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $this->player($u15);
        $this->player($u17);
        $joint = $this->training($u17, '2026-10-08', ['kind' => SessionKind::Preseason, 'start_time' => '09:00', 'end_time' => '10:30']);
        $joint->categories()->syncWithoutDetaching([$u15->id]);
        $this->training($u17, '2026-10-09');

        $html = $this->sheetHtml($u15);

        $this->assertStringContainsString('08/10', $html);
        $this->assertStringContainsString('09:00', $html);
        $this->assertStringContainsString('class="kind">PP<', $html);
        $this->assertStringContainsString('Test002 P2', $html);
        $this->assertStringContainsString('(U17)', $html);
        $this->assertStringNotContainsString('09/10', $html);
    }

    #[Test]
    public function cancelled_sessions_are_left_out(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-10-05');
        $this->training($u15, '2026-10-14', ['state' => SessionState::Cancelled, 'cancel_reason' => 'Pluie']);

        $html = $this->sheetHtml($u15);

        $this->assertStringContainsString('05/10', $html);
        $this->assertStringNotContainsString('14/10', $html);
    }

    // Split from the brief's single stored_codes_are_printed_only_when_asked():
    // two dispatches to the same mocked route in one test method hit Laravel's
    // per-Route controller cache (Illuminate\Routing\Route::getController()),
    // so the second call kept reusing the first request's PdfService mock.
    // Each half now gets its own setup and request; assertions are unchanged.
    #[Test]
    public function a_blank_sheet_prints_no_stored_codes(): void
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        $this->mark($this->training($u15, '2026-10-05'), $player, AttendanceStatus::Late, 25);

        $blank = $this->sheetHtml($u15);

        $this->assertStringNotContainsString('R25', $blank);
    }

    #[Test]
    public function the_filled_sheet_prints_the_stored_codes(): void
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        $this->mark($this->training($u15, '2026-10-05'), $player, AttendanceStatus::Late, 25);

        $filled = $this->sheetHtml($u15, 'fr', ['filled' => 1]);

        $this->assertStringContainsString('class="cell">R25<', $filled);
        $this->assertStringContainsString('Imprimée avec les présences déjà enregistrées.', $filled);
    }

    #[Test]
    public function the_legend_lists_every_configured_code_and_name(): void
    {
        AttendanceSettings::save(['codes' => ['late' => ['code' => 'T', 'label' => ['fr' => 'Tardif']]]]);
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-10-05');

        $html = $this->sheetHtml($u15);

        foreach (['T15', 'Tardif', 'Présent', 'D15', 'Parti tôt', 'Présent, sans entraînement', 'AE', 'Absent (justifié)', 'AN', 'Absent (non justifié)', '#059669'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringNotContainsString('R15', $html);
    }

    #[Test]
    public function the_arabic_sheet_is_right_to_left(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-10-05');
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('ar'))->get($this->url($u15))->assertOk();

        $this->assertTrue($seen['rtl']);
        $this->assertTrue($seen['landscape']);
        $this->assertStringContainsString('ورقة الحضور', $seen['html']);
        $this->assertStringContainsString('متأخر', $seen['html']);
    }

    #[Test]
    public function more_than_sixteen_sessions_continue_in_a_second_part(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        for ($day = 1; $day <= 20; $day++) {
            $this->training($u15, sprintf('2026-10-%02d', $day));
        }

        $html = $this->sheetHtml($u15);

        $this->assertSame(2, substr_count($html, '<thead>'));
        $this->assertSame(1, substr_count($html, '<pagebreak'));
        $this->assertStringContainsString('Séances 1 à 16 sur 20', $html);
        $this->assertStringContainsString('Séances 17 à 20 sur 20', $html);
        // The two blank columns fit after the last four sessions (one row).
        $this->assertSame(2, substr_count($html, 'class="blank"'));
    }

    #[Test]
    public function a_month_without_sessions_says_so(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);

        $html = $this->sheetHtml($u15);

        $this->assertStringContainsString('Aucune séance ce mois-ci.', $html);
        $this->assertStringNotContainsString('<thead>', $html);
    }

    #[Test]
    public function a_short_roster_fits_on_one_page_and_a_long_one_flows_over_several(): void
    {
        $u15 = $this->category('U15');
        for ($i = 0; $i < 8; $i++) {
            $this->player($u15);
        }
        foreach (['2026-10-05', '2026-10-07', '2026-10-12', '2026-10-14'] as $date) {
            $this->training($u15, $date);
        }
        $admin = $this->userIn('fr');

        $short = (string) $this->actingAs($admin)->get($this->url($u15))->assertOk()->getContent();
        $this->assertSame(1, self::pages($short));

        for ($i = 0; $i < 32; $i++) {
            $this->player($u15);
        }
        $long = (string) $this->actingAs($admin)->get($this->url($u15))->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(2, self::pages($long));
    }

    #[Test]
    public function printing_needs_attendance_view_only(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-10-05');
        $this->spyPdf();

        $this->actingAs($this->userWith(['attendance' => ['view']]))->get($this->url($u15))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view']]))->get($this->url($u15))->assertForbidden();
    }
}
