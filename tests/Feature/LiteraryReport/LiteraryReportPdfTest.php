<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\LiteraryReport;
use App\Models\Role;
use App\Models\User;
use App\Services\LiteraryReport\LiteraryReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LiteraryReportPdfTest extends TestCase
{
    use RefreshDatabase;

    private function report(array $attributes = []): LiteraryReport
    {
        return LiteraryReport::create($attributes + [
            'title' => 'Rapport', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31',
            'sections' => LiteraryReport::SECTIONS,
            'president_word' => "Mot\nsur deux lignes", 'conclusion' => 'Perspectives',
        ]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    #[Test]
    public function the_pdf_streams_with_every_section_enabled(): void
    {
        $admin = User::factory()->admin()->create();
        $report = $this->report();

        $this->actingAs($admin)->get("/literary-reports/{$report->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    #[Test]
    public function the_pdf_needs_the_literary_reports_view_right(): void
    {
        $report = $this->report();
        $this->actingAs($this->userWith([]))->get("/literary-reports/{$report->id}/pdf")->assertForbidden();
        $this->actingAs($this->userWith(['literary_reports' => ['view']]))->get("/literary-reports/{$report->id}/pdf")->assertOk();
    }

    #[Test]
    public function sections_without_the_source_right_are_omitted_and_empty_texts_skipped(): void
    {
        $user = $this->userWith(['literary_reports' => ['view'], 'activities' => ['view']]);
        $keys = array_column(app(LiteraryReportBuilder::class)->build($this->report(['introduction' => null]), $user), 'key');

        $this->assertSame(['cover', 'intro', 'activities', 'conclusion'], $keys);

        $noTexts = $this->report(['president_word' => '  ', 'conclusion' => null]);
        $this->assertSame(['cover', 'activities'], array_column(app(LiteraryReportBuilder::class)->build($noTexts, $user), 'key'));
    }
}
