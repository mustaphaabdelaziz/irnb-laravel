<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendancePeriodSheetPdfTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-26 10:00:00');
    }

    private function training(Category $category, string $date): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
    }

    private function markAs(TrainingSession $training, Player $player, string $status): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'category_id' => $training->category_id, 'status' => $status]);
    }

    private function url(array $query): string
    {
        return route('attendance.sheets.period', $query);
    }

    private function html(array $query, string $locale = 'fr'): string
    {
        $seen = $this->spyPdf();
        $this->actingAs(User::factory()->admin()->create(['preferred_lng' => $locale]))->get($this->url($query))->assertOk();

        return $seen['html'];
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    #[Test]
    public function the_sheet_renders_as_a_real_pdf_in_arabic(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $this->markAs($this->training($u15, '2026-10-05'), $a, 'present');
        $this->markAs($this->training($u15, '2026-11-02'), $a, 'absent_unexcused');

        $response = $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'ar']))
            ->get($this->url(['from' => '2026-10-01', 'to' => '2026-11-30', 'category_id' => $u15->id]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function two_months_print_month_pages_with_totals_and_a_summary(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $this->markAs($this->training($u15, '2026-10-05'), $a, 'present');
        $this->markAs($this->training($u15, '2026-11-02'), $a, 'absent_unexcused');

        $html = $this->html(['from' => '2026-10-01', 'to' => '2026-11-30', 'category_id' => $u15->id]);

        $this->assertStringContainsString('Feuille de présence de la période', $html);
        $this->assertStringContainsString('octobre 2026', $html);
        $this->assertStringContainsString('<bdi dir="ltr">01/10/2026 → 30/11/2026</bdi>', $html);
        $this->assertStringContainsString('novembre 2026', $html);
        $this->assertStringContainsString('Récapitulatif de la période', $html);
        $this->assertStringContainsString('100.0%', $html); // October page
        $this->assertStringContainsString('50.0%', $html);  // summary
    }

    #[Test]
    public function one_month_has_no_summary_page(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-10-05');

        $this->assertStringNotContainsString('Récapitulatif de la période', $this->html(['from' => '2026-10-01', 'to' => '2026-10-31', 'category_id' => $u15->id]));
    }

    #[Test]
    public function all_categories_print_each_category(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $this->player($u15);
        $this->player($u17);
        $this->training($u15, '2026-10-05');
        $this->training($u17, '2026-10-06');

        $html = $this->html(['from' => '2026-10-01', 'to' => '2026-10-31']);

        $this->assertStringContainsString('U15', $html);
        $this->assertStringContainsString('U17', $html);
    }

    #[Test]
    public function an_empty_period_says_so(): void
    {
        $u15 = $this->category('U15');

        $this->assertStringContainsString('Aucune séance sur cette période.', $this->html(['from' => '2026-10-01', 'to' => '2026-10-31', 'category_id' => $u15->id]));
    }

    #[Test]
    public function dates_are_validated(): void
    {
        $admin = User::factory()->admin()->create(['preferred_lng' => 'fr']);

        $this->actingAs($admin)->get($this->url(['from' => '2026-10-31', 'to' => '2026-10-01']))->assertSessionHasErrors('to');
        $this->actingAs($admin)->get($this->url(['from' => '2025-09-01', 'to' => '2026-10-01']))->assertSessionHasErrors('to');
        $this->actingAs($admin)->get($this->url(['to' => '2026-10-01']))->assertSessionHasErrors('from');
    }

    #[Test]
    public function printing_needs_attendance_view_only(): void
    {
        $u15 = $this->category('U15');
        $this->training($u15, '2026-10-05');
        $this->spyPdf();
        $query = ['from' => '2026-10-01', 'to' => '2026-10-31', 'category_id' => $u15->id];

        $this->actingAs($this->userWith(['attendance' => ['view']]))->get($this->url($query))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view']]))->get($this->url($query))->assertForbidden();
    }
}
