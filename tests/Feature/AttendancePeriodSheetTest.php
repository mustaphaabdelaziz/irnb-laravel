<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Services\Attendance\GroupMembership;
use App\Services\Attendance\PeriodSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendancePeriodSheetTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-26 10:00:00');
    }

    private function training(Category $category, string $date, array $extra = []): TrainingSession
    {
        return TrainingSession::create(array_merge([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ], $extra));
    }

    private function markAs(TrainingSession $training, Player $player, string $status): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'category_id' => $training->category_id, 'status' => $status]);
    }

    #[Test]
    public function one_page_per_month_cut_to_the_bounds_with_a_summary(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $this->training($u15, '2026-09-28'); // before the period
        $oct = $this->training($u15, '2026-10-20');
        $nov = $this->training($u15, '2026-11-03');
        $this->markAs($oct, $a, 'present');
        $this->markAs($nov, $a, 'absent_unexcused');

        $sections = app(PeriodSheet::class)->build('2026-10-15', '2026-11-30', null, $u15->id);

        $this->assertCount(1, $sections);
        $this->assertNull($sections[0]['group']);
        $this->assertSame(['2026-10', '2026-11'], array_column($sections[0]['months'], 'month'));
        $this->assertSame([$oct->id], $sections[0]['months'][0]['sessions']->modelKeys());
        $this->assertSame(['present' => 1, 'absent' => 0, 'expected' => 1, 'rate' => 100.0], $sections[0]['months'][0]['totals'][$a->id]);
        $this->assertSame(['present' => 1, 'absent' => 1, 'expected' => 2, 'rate' => 50.0], $sections[0]['summary'][0]['totals']);
        $this->assertTrue($sections[0]['summary'][0]['player']->is($a));
    }

    #[Test]
    public function a_single_month_has_no_summary(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-10-05');

        $this->assertNull(app(PeriodSheet::class)->build('2026-10-01', '2026-10-31', null, $u15->id)[0]['summary']);
    }

    #[Test]
    public function months_after_the_current_one_are_skipped(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-11-10');
        $this->training($u15, '2026-12-08');

        $sections = app(PeriodSheet::class)->build('2026-11-01', '2027-01-31', null, $u15->id);

        $this->assertSame(['2026-11'], array_column($sections[0]['months'], 'month'));
    }

    #[Test]
    public function days_after_today_are_cut(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $past = $this->training($u15, '2026-11-10');
        $this->training($u15, '2026-11-28');

        $sections = app(PeriodSheet::class)->build('2026-11-01', '2026-11-30', null, $u15->id);

        $this->assertSame([$past->id], $sections[0]['months'][0]['sessions']->pluck('id')->all());
    }

    #[Test]
    public function all_categories_give_one_section_each_and_empty_ones_are_dropped(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $this->category('U19'); // no session
        $this->player($u15);
        $this->player($u17);
        $this->training($u15, '2026-10-05');
        $this->training($u17, '2026-10-06');

        $sections = app(PeriodSheet::class)->build('2026-10-01', '2026-10-31', null);

        $this->assertSame([$u15->id, $u17->id], array_map(fn ($s) => $s['category']->id, $sections));
    }

    #[Test]
    public function a_category_with_groups_gives_one_section_per_group_or_only_the_chosen_one(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $dolphins = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'Dauphins']);
        $sharks = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'Requins']);
        foreach ([$dolphins, $sharks] as $i => $group) {
            $player = $this->player($minime);
            $swim->players()->attach($player->id);
            app(GroupMembership::class)->add($group, $player->id, null);
            $this->training($minime, '2026-10-10', ['branch_id' => $swim->id, 'group_id' => $group->id, 'start_time' => $i === 0 ? '18:00' : '19:30', 'end_time' => $i === 0 ? '19:30' : '21:00']);
        }

        $all = app(PeriodSheet::class)->build('2026-10-01', '2026-10-31', $swim->id, $minime->id);
        $one = app(PeriodSheet::class)->build('2026-10-01', '2026-10-31', $swim->id, $minime->id, $sharks->id);

        $this->assertSame(['Dauphins', 'Requins'], array_column($all, 'group'));
        $this->assertSame(['Requins'], array_column($one, 'group'));
    }
}
