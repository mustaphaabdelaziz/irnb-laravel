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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceCertificatesPdfTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

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

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function training(Category $category, string $date): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
    }

    /** Marks $player at $trainings[i] with $codes[i]: P, R (late 10 min), AE (excused), AN; null = no mark. */
    private function marks(Player $player, array $trainings, array $codes): void
    {
        foreach ($codes as $i => $code) {
            if ($code === null) {
                continue;
            }
            [$status, $extra] = match ($code) {
                'P' => [AttendanceStatus::Present, []],
                'R' => [AttendanceStatus::Late, ['minutes' => 10]],
                'AE' => [AttendanceStatus::AbsentExcused, ['reason' => 'illness']],
                'AN' => [AttendanceStatus::AbsentUnexcused, []],
            };
            Attendance::create(array_merge([
                'training_session_id' => $trainings[$i]->id, 'player_id' => $player->id,
                'category_id' => $player->category_id, 'status' => $status,
            ], $extra));
        }
    }

    /** Same as the ranking page: A 100 %, B 95 %, G 95 %, C 80 %, D 60 %; E not ranked. */
    private function seedData(): array
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $s = array_map(fn (string $date) => $this->training($u15, $date), ['2026-10-01', '2026-10-03', '2026-10-05', '2026-10-07', '2026-10-09']);
        $p = [
            'a' => $this->player($u15), 'b' => $this->player($u15), 'c' => $this->player($u15),
            'd' => $this->player($u15), 'e' => $this->player($u15), 'g' => $this->player($u15),
        ];
        $this->marks($p['a'], $s, ['P', 'P', 'P', 'P', 'P']);
        $this->marks($p['b'], $s, ['P', 'P', 'P', 'P', 'R']);
        $this->marks($p['c'], $s, ['P', 'P', 'P', 'P', 'AE']);
        $this->marks($p['d'], $s, ['P', 'P', 'P', 'P', 'AN']);
        $this->marks($p['e'], $s, ['P', 'P', 'P', 'AE', null]);
        $this->marks($p['g'], $s, ['R', 'P', 'P', 'P', 'P']);

        return ['u15' => $u15, 'u17' => $u17, ...$p];
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

    /** Pages in a real mPDF document (page objects, not the /Pages tree). */
    private static function pages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page\b#', $pdf);
    }

    #[Test]
    public function the_podium_prints_as_a_real_three_page_pdf(): void
    {
        $x = $this->seedData();

        $response = $this->actingAs($this->userIn('fr'))->get(route('attendance.certificates', ['category_id' => $x['u15']->id]))->assertOk();

        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertSame(3, self::pages((string) $response->getContent()));
    }

    #[Test]
    public function the_podium_certificates_carry_name_place_score_category_and_period(): void
    {
        $x = $this->seedData();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.certificates', ['category_id' => $x['u15']->id]))->assertOk();

        $this->assertTrue($seen['landscape']);
        $this->assertFalse($seen['rtl']);
        $this->assertSame("certificates-{$x['u15']->id}-2026-10.pdf", $seen['filename']);
        $html = $seen['html'];
        $this->assertSame(3, substr_count($html, 'class="frame"'));
        $this->assertSame(2, substr_count($html, '<pagebreak'));
        $this->assertSame(3, substr_count($html, 'class="rank"'));
        $this->assertLessThan(strpos($html, $x['b']->fullname), strpos($html, $x['a']->fullname));
        $this->assertLessThan(strpos($html, $x['g']->fullname), strpos($html, $x['b']->fullname));
        $this->assertStringNotContainsString($x['c']->fullname, $html);
        foreach (['assiduité', '1re place', '2e place', '3e place', '100.0%', '95.0%', 'U15', 'octobre 2026', 'Fait le 20/10/2026', 'entraîneur', 'Le président'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
    }

    #[Test]
    public function a_chosen_player_outside_the_podium_gets_a_certificate_without_a_place(): void
    {
        $x = $this->seedData();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.certificates', ['category_id' => $x['u15']->id, 'player_id' => $x['c']->id]))->assertOk();

        $this->assertSame("certificate-{$x['c']->membership_id}-2026-10.pdf", $seen['filename']);
        $this->assertStringContainsString($x['c']->fullname, $seen['html']);
        $this->assertStringContainsString('80.0%', $seen['html']);
        $this->assertStringNotContainsString('class="rank"', $seen['html']);
        $this->assertSame(0, substr_count($seen['html'], '<pagebreak'));
    }

    #[Test]
    public function a_chosen_player_on_the_podium_keeps_the_place(): void
    {
        $x = $this->seedData();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.certificates', ['category_id' => $x['u15']->id, 'player_id' => $x['b']->id]))->assertOk();

        $this->assertStringContainsString('2e place', $seen['html']);
        $this->assertSame(1, substr_count($seen['html'], 'class="frame"'));
    }

    #[Test]
    public function an_unranked_player_can_still_be_chosen(): void
    {
        $x = $this->seedData();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.certificates', ['category_id' => $x['u15']->id, 'player_id' => $x['e']->id]))->assertOk();

        $this->assertStringContainsString($x['e']->fullname, $seen['html']);
        $this->assertStringContainsString('75.0%', $seen['html']);   // 3 / 4
        $this->assertStringNotContainsString('class="rank"', $seen['html']);
    }

    #[Test]
    public function the_arabic_certificates_are_right_to_left_for_a_season(): void
    {
        $x = $this->seedData();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('ar'))
            ->get(route('attendance.certificates', ['category_id' => $x['u15']->id, 'type' => 'season', 'season' => 2026]))
            ->assertOk();

        $this->assertTrue($seen['rtl']);
        $this->assertTrue($seen['landscape']);
        $this->assertSame("certificates-{$x['u15']->id}-season-2026.pdf", $seen['filename']);
        $this->assertStringContainsString('شهادة مواظبة', $seen['html']);
        $this->assertStringContainsString('المرتبة الأولى', $seen['html']);
        $this->assertStringContainsString('موسم 2026/27', $seen['html']);
    }

    #[Test]
    public function nobody_ranked_means_no_podium(): void
    {
        $x = $this->seedData();

        $this->actingAs($this->admin())->get(route('attendance.certificates', ['category_id' => $x['u17']->id]))->assertNotFound();
    }

    #[Test]
    public function printing_needs_attendance_view_only(): void
    {
        $x = $this->seedData();
        $this->spyPdf();

        $this->actingAs($this->userWith(['attendance' => ['view']]))->get(route('attendance.certificates', ['category_id' => $x['u15']->id]))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view']]))->get(route('attendance.certificates', ['category_id' => $x['u15']->id]))->assertForbidden();
    }
}
