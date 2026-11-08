<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\LiteraryReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModelLayoutMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_08_100001_literary_report_model_layout.php';

    private const CHAPTERS = ['تنظيمات النادي', 'المشاركات الرياضية', 'الحملات التطوعية', 'التكوينات', 'إعلانات النادي'];

    private const OLD = ['حملة تطوعية', 'جلسة توعوية', 'مخيم صيفي', 'نشاط خيري', 'زيارة', 'احتفال / مناسبة'];

    #[Test]
    public function the_new_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('activities', ['organizer', 'result', 'date_label']));
        $this->assertTrue(Schema::hasColumns('literary_reports', ['note', 'include_details', 'signer_1_board_member_id', 'signer_2_board_member_id']));
    }

    #[Test]
    public function chapter_types_come_first_and_the_old_types_follow(): void
    {
        $types = ActivityType::orderBy('sort_order')->get(['name', 'sort_order']);

        $this->assertSame([...self::CHAPTERS, ...self::OLD], $types->pluck('name')->all());
        $this->assertSame(range(1, 11), $types->pluck('sort_order')->all());
    }

    #[Test]
    public function on_existing_data_it_keeps_one_report_photo_appends_sections_and_reuses_a_chapter_name(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->down();

        $training = ActivityType::create(['name' => 'التكوينات', 'sort_order' => 50]);
        $activity = Activity::create(['title' => 'Seminar', 'activity_type_id' => $training->id, 'starts_on' => '2026-07-05']);
        $activity->photos()->create(['path' => 'a.jpg', 'thumb_path' => 'a.jpg', 'in_report' => true, 'sort_order' => 2]);
        $early = $activity->photos()->create(['path' => 'b.jpg', 'thumb_path' => 'b.jpg', 'in_report' => true, 'sort_order' => 1]);
        $report = LiteraryReport::create(['title' => 'R', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31', 'sections' => ['cover', 'activities']]);

        $migration->up();

        $this->assertSame([$early->id], $activity->photos()->where('in_report', true)->pluck('id')->all());
        $this->assertSame(['cover', 'activities', 'signatures', 'photos'], $report->fresh()->sections);
        $this->assertSame(1, ActivityType::where('name', 'التكوينات')->count());
        $this->assertSame(4, $training->fresh()->sort_order);
        $this->assertSame([...self::CHAPTERS, ...self::OLD], ActivityType::orderBy('sort_order')->pluck('name')->all());
    }
}
