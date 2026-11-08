<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\LiteraryReport;
use App\Models\Role;
use App\Models\User;
use App\Services\LiteraryReport\LiteraryReportBuilder;
use App\Services\Pdf\ClubHeader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LiteraryReportPdfTest extends TestCase
{
    use RefreshDatabase;

    private ?string $photoDir = null;

    protected function tearDown(): void
    {
        if ($this->photoDir) {
            File::deleteDirectory(storage_path('app/public/'.$this->photoDir));
        }

        parent::tearDown();
    }

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
    public function the_arabic_pdf_streams_with_chapters_photos_note_details_and_signatures(): void
    {
        app()->setLocale('ar');
        $admin = User::factory()->admin()->create();

        BoardTerm::query()->update(['is_current' => false]);
        $term = BoardTerm::create(['name' => 'Term Pdf', 'is_current' => true]);
        $signer = BoardMember::create(['name' => 'Board Signer', 'role' => 'president', 'board_term_id' => $term->id, 'status' => 'active']);

        $dir = $this->photoDir = 'literary-pdf-'.uniqid();
        File::ensureDirectoryExists(storage_path('app/public/'.$dir));
        File::put(storage_path("app/public/{$dir}/p.jpg"), base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAA//EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AN//Z'));

        $activity = Activity::create([
            'title' => 'Pdf Activity', 'activity_type_id' => ActivityType::where('name', 'تنظيمات النادي')->value('id'),
            'starts_on' => '2026-05-05', 'organizer' => 'Pdf Organizer', 'result' => 'Pdf Result', 'date_label' => 'Pdf Date', 'report' => 'Pdf report text',
        ]);
        $activity->photos()->create(['path' => "{$dir}/p.jpg", 'thumb_path' => "{$dir}/p.jpg", 'caption' => 'Pdf Caption', 'in_report' => true]);

        $report = $this->report([
            'note' => 'Pdf note', 'include_details' => true, 'signer_1_board_member_id' => $signer->id,
            'sections' => LiteraryReport::SECTIONS,
        ]);

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

    #[Test]
    public function the_document_direction_follows_the_language_and_texts_keep_escaped_line_breaks(): void
    {
        $report = $this->report(['president_word' => "<b>سطر</b>\nثان"]);
        $render = fn (bool $rtl) => view('pdf.literary-report', [
            'club' => ClubHeader::data(),
            'report' => $report,
            'sections' => app(LiteraryReportBuilder::class)->build($report, User::factory()->admin()->create()),
            'rtl' => $rtl,
        ])->render();

        $arabic = $render(true);
        $this->assertStringContainsString('<html dir="rtl">', $arabic);
        $this->assertStringContainsString('<body dir="rtl">', $arabic);
        $this->assertStringContainsString('&lt;b&gt;سطر&lt;/b&gt;<br>', $arabic);
        $this->assertStringContainsString('<html dir="ltr">', $render(false));
    }
}
