<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceSessionBranchTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function payload(int $categoryId, array $extra = []): array
    {
        return $extra + ['category_id' => $categoryId, 'kind' => 'extra', 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30'];
    }

    #[Test]
    public function a_hand_added_session_needs_a_branch_once_branches_exist(): void
    {
        $u15 = $this->category();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->payload($u15->id))->assertSessionHasNoErrors();
        TrainingSession::query()->delete();

        $foot = Branch::create(['name' => 'Foot']);
        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->payload($u15->id))->assertSessionHasErrors('branch_id');
        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->payload($u15->id, ['branch_id' => $foot->id]))->assertSessionHasNoErrors();
        $this->assertSame($foot->id, TrainingSession::sole()->branch_id);
    }

    #[Test]
    public function without_branches_a_session_is_club_wide(): void
    {
        $this->assertSame(0, Branch::count());
        $u15 = $this->category();
        $this->actingAs($this->admin())->post(route('attendance.sessions.store'), $this->payload($u15->id))->assertSessionHasNoErrors();
        $this->assertNull(TrainingSession::sole()->branch_id);
    }

    #[Test]
    public function the_slot_check_compares_branches(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->payload($u15->id, ['branch_id' => $foot->id]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->payload($u15->id, ['branch_id' => $swim->id]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->payload($u15->id, ['branch_id' => $foot->id]))
            ->assertSessionHasErrors(['start_time' => 'att.error.duplicate']);
    }

    #[Test]
    public function deleting_a_regular_session_tombstones_its_branch_slot(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $session = TrainingSession::create([
            'branch_id' => $foot->id, 'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);

        $this->actingAs($this->admin())->delete(route('attendance.sessions.destroy', $session))
            ->assertRedirect(route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-10', 'branch_id' => $foot->id]));

        $this->assertSame($foot->id, (int) DB::table('deleted_session_slots')->value('branch_id'));
    }
}
