<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\AttendanceCustomStatus;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceStatusCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceStatusCatalogTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function custom(array $extra = []): AttendanceCustomStatus
    {
        return AttendanceCustomStatus::createWithKey($extra + [
            'code' => 'V', 'color' => '#123456', 'label_ar' => null, 'label_fr' => 'Voyage', 'label_en' => null,
            'behaviour' => 'not_counted', 'is_active' => true, 'sort_order' => 0,
        ]);
    }

    #[Test]
    public function a_custom_status_gets_a_permanent_key_from_its_id(): void
    {
        $status = $this->custom();

        $this->assertSame('c_'.$status->id, $status->fresh()->key);
    }

    #[Test]
    public function built_in_statuses_come_first_then_custom_ones_by_sort_order(): void
    {
        $second = $this->custom(['code' => 'W', 'sort_order' => 2]);
        $first = $this->custom(['code' => 'V', 'sort_order' => 1, 'is_active' => false]);
        $catalog = app(AttendanceStatusCatalog::class);

        $this->assertSame(
            ['present', 'late', 'left_early', 'not_training', 'absent_excused', 'absent_unexcused', $first->key, $second->key],
            $catalog->keys(),
        );
        $this->assertNotContains($first->key, $catalog->keys(activeOnly: true));
        $this->assertSame('not_counted', $catalog->behaviour($first->key));
        $this->assertSame('late', $catalog->behaviour('late'));
    }

    #[Test]
    public function a_custom_name_left_empty_falls_back_to_another_language_then_the_code(): void
    {
        $named = $this->custom();
        $bare = $this->custom(['code' => 'X', 'label_fr' => null]);
        $catalog = app(AttendanceStatusCatalog::class);

        $this->assertSame(['ar' => 'Voyage', 'fr' => 'Voyage', 'en' => 'Voyage'], $catalog->codes()[$named->key]['label']);
        $this->assertSame('X', $catalog->labels('ar')[$bare->key]);
        $this->assertTrue($catalog->codes()[$bare->key]['custom']);
        $this->assertFalse($catalog->codes()['present']['custom']);
    }

    #[Test]
    public function fold_adds_custom_marks_to_the_status_they_behave_as_and_drops_not_counted(): void
    {
        $travel = $this->custom();
        $justified = $this->custom(['code' => 'J', 'behaviour' => 'absent_excused']);
        $here = $this->custom(['code' => 'H', 'behaviour' => 'present']);

        $folded = app(AttendanceStatusCatalog::class)->fold([
            'present' => 2, 'late' => 1, $travel->key => 4, $justified->key => 3, $here->key => 1, 'absent_excused' => 1,
        ]);

        $this->assertSame(
            ['present' => 3, 'late' => 1, 'left_early' => 0, 'not_training' => 0, 'absent_excused' => 4, 'absent_unexcused' => 0],
            $folded,
        );
        $this->assertSame(['absent_unexcused'], app(AttendanceStatusCatalog::class)->keysBehavingAs('absent_unexcused'));
    }

    #[Test]
    public function a_mark_with_a_custom_status_loads_as_a_plain_string(): void
    {
        $travel = $this->custom();
        $u15 = $this->category();
        $session = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $this->player($u15)->id, 'status' => $travel->key]);

        $this->assertSame($travel->key, Attendance::first()->status);
        $this->assertTrue($travel->isUsed());
    }
}
