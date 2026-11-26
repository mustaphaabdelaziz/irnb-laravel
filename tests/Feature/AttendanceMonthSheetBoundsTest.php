<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\TrainingSession;
use App\Services\Attendance\MonthSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceMonthSheetBoundsTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function training(Category $category, string $date): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
    }

    #[Test]
    public function bounds_cut_the_month_and_no_bounds_keep_it_whole(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $early = $this->training($u15, '2026-10-05');
        $late = $this->training($u15, '2026-10-20');
        $sheet = app(MonthSheet::class);

        $this->assertSame([$early->id, $late->id], $sheet->build($u15->id, 2026, 10)['sessions']->modelKeys());
        $this->assertSame([$late->id], $sheet->build($u15->id, 2026, 10, null, null, '2026-10-15', null)['sessions']->modelKeys());
        $this->assertSame([$early->id], $sheet->build($u15->id, 2026, 10, null, null, null, '2026-10-15')['sessions']->modelKeys());
    }

    #[Test]
    public function statuses_hold_the_raw_key_of_each_marked_cell_only(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $b = $this->player($u15);
        $marked = $this->training($u15, '2026-10-05');
        $this->training($u15, '2026-10-12'); // not saved yet: expected roster, no status
        Attendance::create(['training_session_id' => $marked->id, 'player_id' => $a->id, 'category_id' => $u15->id, 'status' => 'late', 'minutes' => 10]);
        Attendance::create(['training_session_id' => $marked->id, 'player_id' => $b->id, 'category_id' => $u15->id, 'status' => 'absent_unexcused']);

        $statuses = app(MonthSheet::class)->build($u15->id, 2026, 10)['statuses'];

        $this->assertSame([$a->id => [$marked->id => 'late'], $b->id => [$marked->id => 'absent_unexcused']], $statuses);
    }

    #[Test]
    public function guest_categories_name_only_players_from_another_category(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $own = $this->player($u15);
        $guest = $this->player($u17);

        $names = app(MonthSheet::class)->guestCategories(collect([$own, $guest]), $u15->id, null);

        $this->assertSame([$guest->id => 'U17'], $names);
    }
}
