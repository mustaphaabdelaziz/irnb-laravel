<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\Activity;
use App\Models\ActivityPhoto;
use App\Models\ActivityType;
use App\Models\LiteraryReport;
use App\Models\Role;
use App\Models\User;
use App\Services\LiteraryReport\LiteraryReportBuilder;
use App\Services\LiteraryReport\Sections\PhotosSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhotosSectionTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = 'literary-photos-test-'.uniqid();
        File::ensureDirectoryExists(storage_path('app/public/'.$this->dir));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/public/'.$this->dir));
        parent::tearDown();
    }

    private function build(): array
    {
        return app(PhotosSection::class)->build(CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2026-08-31'));
    }

    private function activity(string $type, string $title, string $startsOn = '2026-03-01'): Activity
    {
        return Activity::create(['title' => $title, 'activity_type_id' => ActivityType::where('name', $type)->value('id'), 'starts_on' => $startsOn]);
    }

    private function photo(Activity $activity, string $name, array $extra = [], bool $onDisk = true): ActivityPhoto
    {
        if ($onDisk) {
            File::put(storage_path("app/public/{$this->dir}/{$name}"), 'jpeg-bytes');
        }

        return ActivityPhoto::create($extra + ['activity_id' => $activity->id, 'path' => "{$this->dir}/{$name}", 'thumb_path' => "{$this->dir}/{$name}"]);
    }

    #[Test]
    public function the_report_photo_wins_else_the_first_photo_else_the_activity_is_left_out(): void
    {
        $flagged = $this->activity('تنظيمات النادي', 'Iftar', '2026-03-28');
        $this->photo($flagged, 'first.jpg', ['sort_order' => 1]);
        $this->photo($flagged, 'chosen.jpg', ['sort_order' => 2, 'in_report' => true, 'caption' => 'Iftar table']);

        $plain = $this->activity('تنظيمات النادي', 'Trip', '2026-04-10');
        $this->photo($plain, 'b.jpg', ['sort_order' => 2]);
        $this->photo($plain, 'a.jpg', ['sort_order' => 1]);

        $this->activity('تنظيمات النادي', 'No photo', '2026-05-01');

        $photos = $this->build()['chapters'][0]['photos'];

        $this->assertCount(2, $photos);
        $this->assertStringEndsWith('chosen.jpg', $photos[0]['file']);
        $this->assertSame('Iftar table', $photos[0]['caption']);
        $this->assertStringEndsWith('a.jpg', $photos[1]['file']);
        $this->assertSame('Trip', $photos[1]['caption']); // no caption: the activity title
        $this->assertFileExists($photos[1]['file']);
    }

    #[Test]
    public function chapters_follow_the_type_order_and_are_numbered_and_photo_less_chapters_are_dropped(): void
    {
        $this->photo($this->activity('الحملات التطوعية', 'Clean-up'), 'c.jpg');
        $this->activity('المشاركات الرياضية', 'Cup'); // no photo: its chapter is dropped
        $this->photo($this->activity('تنظيمات النادي', 'Iftar'), 'i.jpg');

        $chapters = $this->build()['chapters'];

        $this->assertSame(['تنظيمات النادي', 'الحملات التطوعية'], array_column($chapters, 'type'));
        $this->assertSame(['01', '02'], array_column($chapters, 'number'));
    }

    #[Test]
    public function a_missing_file_or_no_photos_gives_an_empty_section(): void
    {
        $this->photo($this->activity('تنظيمات النادي', 'Gone'), 'missing.jpg', ['in_report' => true], onDisk: false);

        $this->assertSame([], $this->build());
    }

    #[Test]
    public function the_builder_skips_the_appendix_when_empty_or_without_the_activities_right(): void
    {
        $this->photo($this->activity('تنظيمات النادي', 'Iftar'), 'i.jpg');
        $report = LiteraryReport::create(['title' => 'R', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31', 'sections' => ['photos']]);

        $admin = User::factory()->admin()->create();
        $this->assertSame(['photos'], array_column(app(LiteraryReportBuilder::class)->build($report, $admin), 'key'));

        $noRight = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => ['literary_reports' => ['view']]])->id]);
        $this->assertSame([], app(LiteraryReportBuilder::class)->build($report, $noRight));

        $empty = LiteraryReport::create(['title' => 'E', 'period_from' => '2020-01-01', 'period_to' => '2020-12-31', 'sections' => ['photos']]);
        $this->assertSame([], app(LiteraryReportBuilder::class)->build($empty, $admin));
    }
}
