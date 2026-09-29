<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class CategoryDeletionTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function deleting_a_category_hands_its_joint_sessions_to_another_category_instead_of_cascading(): void
    {
        [$u15, $u17] = [$this->category(), $this->category('U17')];
        $session = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-08-10', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Preseason, 'state' => SessionState::Held,
        ]);
        $session->categories()->syncWithoutDetaching([$u17->id]);
        $player = $this->player($u17);
        $mark = Attendance::create(['training_session_id' => $session->id, 'player_id' => $player->id, 'status' => AttendanceStatus::Present]);

        $this->actingAs($this->admin())->delete(route('categories.destroy', $u15))
            ->assertSessionHasNoErrors();

        $session->refresh();
        $this->assertSame($u17->id, $session->category_id);
        $this->assertSame([$u17->id], $session->categoryIds());
        $this->assertDatabaseHas('attendances', ['id' => $mark->id]);
        $this->assertDatabaseMissing('categories', ['id' => $u15->id]);
    }

    #[Test]
    public function deleting_a_category_still_deletes_its_own_single_category_sessions(): void
    {
        $u15 = $this->category();
        $session = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-08-10', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);

        $this->actingAs($this->admin())->delete(route('categories.destroy', $u15))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('categories', ['id' => $u15->id]);
        $this->assertDatabaseMissing('training_sessions', ['id' => $session->id]);
    }
}
