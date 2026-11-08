<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Category;
use App\Models\LiteraryReport;
use App\Models\Player;
use App\Models\User;
use App\Services\LiteraryReport\LiteraryReportBuilder;
use App\Services\LiteraryReport\Sections\ActivitiesSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivitiesSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
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
    public function only_activities_starting_in_the_period_are_listed(): void
    {
        $this->activity('مخيم صيفي', '2026-08-31', ['title' => 'Last day']);
        $this->activity('مخيم صيفي', '2025-09-01', ['title' => 'First day']);
        $this->activity('مخيم صيفي', '2026-09-01', ['title' => 'After']);

        $this->assertSame(['First day', 'Last day'], array_column($this->build()['chapters'][0]['rows'], 'title'));
    }

    #[Test]
    public function each_type_is_a_chapter_in_the_type_list_order_with_numbered_rows_by_date(): void
    {
        $this->activity('الحملات التطوعية', '2025-10-01', ['title' => 'Clean-up']);
        $this->activity('تنظيمات النادي', '2026-03-28', ['title' => 'Iftar']);
        $this->activity('تنظيمات النادي', '2025-11-01', ['title' => 'Gathering']);

        $chapters = $this->build()['chapters'];

        $this->assertSame(['تنظيمات النادي', 'الحملات التطوعية'], array_column($chapters, 'type'));
        $this->assertSame(['Gathering', 'Iftar'], array_column($chapters[0]['rows'], 'title'));
        $this->assertSame(['01', '02'], array_column($chapters[0]['rows'], 'number'));
        $this->assertSame('01', $chapters[1]['rows'][0]['number']);
    }

    #[Test]
    public function the_details_line_holds_organizer_result_categories_and_partners_when_filled(): void
    {
        $category = Category::create(['name' => 'U13']);
        $a = $this->activity('المشاركات الرياضية', '2026-04-11', [
            'title' => 'دورة التعارف', 'organizer' => 'نادي السلام', 'result' => 'وصيف', 'partners' => 'شباب الوحدة', 'location' => 'غرداية',
        ]);
        $a->categories()->attach($category->id);
        $this->activity('المشاركات الرياضية', '2026-04-18', ['title' => 'Bare']);

        $rows = $this->build()['chapters'][0]['rows'];

        $label = $category->fresh()->localized_name ?: 'U13';
        $this->assertSame("من تنظيم نادي السلام · وصيف · {$label} · بالتنسيق مع شباب الوحدة", $rows[0]['details']);
        $this->assertSame('غرداية', $rows[0]['place']);
        $this->assertSame('', $rows[1]['details']);
        $this->assertNull($rows[1]['place']);
    }

    #[Test]
    public function dates_print_in_words_unless_a_date_label_is_set(): void
    {
        $this->activity('تنظيمات النادي', '2025-10-09', ['title' => 'Trip', 'ends_on' => '2025-10-10']);
        $this->activity('تنظيمات النادي', '2025-10-17', ['title' => 'Watch', 'date_label' => '17 من كل شهر']);

        $rows = $this->build()['chapters'][0]['rows'];

        $this->assertSame('من 09 إلى 10 أكتوبر', $rows[0]['date']);
        $this->assertSame('17 من كل شهر', $rows[1]['date']);
    }

    #[Test]
    public function it_counts_each_chapter_and_keeps_report_texts(): void
    {
        $camp = $this->activity('مخيم صيفي', '2026-07-05', ['beneficiaries_count' => 40, 'external_volunteers_count' => 3, 'report' => 'Went well']);
        $camp->players()->attach(Player::create(['membership_id' => 'A1', 'firstname' => 'Sami', 'lastname' => 'Z'])->id);
        $this->activity('مخيم صيفي', '2026-08-10', ['beneficiaries_count' => 10, 'report' => '   ']);
        $this->activity('جلسة توعوية', '2025-10-01', ['beneficiaries_count' => 25, 'external_volunteers_count' => 1]);

        $data = $this->build();

        $this->assertSame(3, $data['count']);
        $this->assertEquals([
            ['type' => 'جلسة توعوية', 'count' => 1, 'beneficiaries' => 25, 'participants' => 1],
            ['type' => 'مخيم صيفي', 'count' => 2, 'beneficiaries' => 50, 'participants' => 4],
        ], $data['summary']);
        $this->assertSame(['count' => 3, 'beneficiaries' => 75, 'participants' => 5], $data['totals']);
        $this->assertSame(['Went well', null], array_column($data['chapters'][1]['rows'], 'report'));
    }

    #[Test]
    public function an_empty_period_gives_no_chapters(): void
    {
        $data = $this->build();

        $this->assertSame(0, $data['count']);
        $this->assertSame([], $data['chapters']);
    }

    #[Test]
    public function the_builder_adds_the_note_and_the_details_option(): void
    {
        $report = LiteraryReport::create([
            'title' => 'R', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31', 'sections' => ['activities'],
            'note' => "  يمكن التأكد عبر الصفحة الرسمية  ", 'include_details' => true,
        ]);

        $sections = app(LiteraryReportBuilder::class)->build($report, User::factory()->admin()->create());

        $this->assertSame('activities', $sections[0]['key']);
        $this->assertSame('يمكن التأكد عبر الصفحة الرسمية', $sections[0]['data']['note']);
        $this->assertTrue($sections[0]['data']['include_details']);
    }
}
