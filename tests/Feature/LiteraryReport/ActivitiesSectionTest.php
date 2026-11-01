<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\Activity;
use App\Models\ActivityPhoto;
use App\Models\ActivityType;
use App\Models\Player;
use App\Services\LiteraryReport\Sections\ActivitiesSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivitiesSectionTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = 'literary-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/public/'.$this->dir));
        parent::tearDown();
    }

    private function build(): array
    {
        return app(ActivitiesSection::class)->build(CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2026-08-31'));
    }

    private function activity(string $type, string $startsOn, array $extra = []): Activity
    {
        return Activity::create($extra + [
            'title' => 'Act '.uniqid(), 'activity_type_id' => ActivityType::where('name', $type)->value('id'), 'starts_on' => $startsOn,
        ]);
    }

    #[Test]
    public function an_activity_starting_on_the_last_day_is_included(): void
    {
        $this->activity('مخيم صيفي', '2026-08-31', ['title' => 'Last day']);
        $this->activity('مخيم صيفي', '2025-09-01', ['title' => 'First day']);
        $this->activity('مخيم صيفي', '2026-09-01', ['title' => 'After']);

        $this->assertEqualsCanonicalizing(['Last day', 'First day'], array_column($this->build()['activities'], 'title'));
    }

    #[Test]
    public function it_summarises_activities_of_the_period_by_type(): void
    {
        $camp = $this->activity('مخيم صيفي', '2026-07-05', ['beneficiaries_count' => 40, 'external_volunteers_count' => 3]);
        $camp->players()->attach(Player::create(['membership_id' => 'A1', 'firstname' => 'Sami', 'lastname' => 'Z'])->id);
        $this->activity('مخيم صيفي', '2026-08-10', ['beneficiaries_count' => 10]);
        $this->activity('جلسة توعوية', '2025-10-01', ['beneficiaries_count' => 25, 'external_volunteers_count' => 1]);
        $this->activity('جلسة توعوية', '2025-08-31', ['beneficiaries_count' => 99]); // before the period

        $data = $this->build();

        $this->assertSame(3, $data['count']);
        $this->assertEquals([
            ['type' => 'جلسة توعوية', 'count' => 1, 'beneficiaries' => 25, 'participants' => 1],
            ['type' => 'مخيم صيفي', 'count' => 2, 'beneficiaries' => 50, 'participants' => 4],
        ], $data['by_type']);
        $this->assertSame(['totals' => ['count' => 3, 'beneficiaries' => 75, 'participants' => 5]], ['totals' => $data['totals']]);
        $this->assertSame('2025-10-01', $data['activities'][0]['starts_on']); // chronological
    }

    #[Test]
    public function only_report_photos_whose_file_exists_are_printed(): void
    {
        $activity = $this->activity('مخيم صيفي', '2026-07-05');
        File::ensureDirectoryExists(storage_path('app/public/'.$this->dir));
        File::put(storage_path("app/public/{$this->dir}/a.jpg"), 'jpeg-bytes');
        ActivityPhoto::create(['activity_id' => $activity->id, 'path' => "{$this->dir}/a.jpg", 'thumb_path' => "{$this->dir}/a.jpg", 'in_report' => true, 'caption' => 'Opening']);
        ActivityPhoto::create(['activity_id' => $activity->id, 'path' => "{$this->dir}/missing.jpg", 'thumb_path' => "{$this->dir}/missing.jpg", 'in_report' => true]);
        ActivityPhoto::create(['activity_id' => $activity->id, 'path' => "{$this->dir}/a.jpg", 'thumb_path' => "{$this->dir}/a.jpg", 'in_report' => false]);

        $photos = $this->build()['activities'][0]['photos'];

        $this->assertCount(1, $photos);
        $this->assertSame('Opening', $photos[0]['caption']);
        $this->assertFileExists($photos[0]['file']);
    }

    #[Test]
    public function an_empty_period_gives_zero_counts(): void
    {
        $this->assertSame(0, $this->build()['count']);
    }
}
