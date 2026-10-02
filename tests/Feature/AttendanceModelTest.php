<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\TrainingSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceModelTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function a_session_keeps_plain_date_and_time_strings_and_its_marks(): void
    {
        $u15 = $this->category();
        $player = $this->player($u15);
        $session = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
        Attendance::create([
            'training_session_id' => $session->id, 'player_id' => $player->id,
            'status' => AttendanceStatus::Late, 'minutes' => 15,
        ]);

        $fresh = TrainingSession::first();
        $this->assertSame('2026-10-05', $fresh->date);
        $this->assertSame('18:00', $fresh->start_time);
        $this->assertSame(SessionKind::Regular, $fresh->kind);
        $this->assertSame(AttendanceStatus::Late->value, $fresh->attendances->first()->status);
        $this->assertSame(15, $fresh->attendances->first()->minutes);
        $this->assertTrue($fresh->category->is($u15));
    }

    #[Test]
    public function the_same_category_slot_cannot_exist_twice(): void
    {
        $u15 = $this->category();
        $row = ['category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned];
        TrainingSession::create($row);

        $this->expectException(QueryException::class);
        TrainingSession::create($row);
    }

    #[Test]
    public function unmarked_planned_scope_skips_held_and_marked_sessions(): void
    {
        $u15 = $this->category();
        $base = ['category_id' => $u15->id, 'end_time' => '19:30', 'kind' => SessionKind::Regular];
        $planned = TrainingSession::create($base + ['date' => '2026-10-05', 'start_time' => '18:00', 'state' => SessionState::Planned]);
        TrainingSession::create($base + ['date' => '2026-10-06', 'start_time' => '18:00', 'state' => SessionState::Held]);
        $marked = TrainingSession::create($base + ['date' => '2026-10-07', 'start_time' => '18:00', 'state' => SessionState::Planned]);
        Attendance::create(['training_session_id' => $marked->id, 'player_id' => $this->player($u15)->id, 'status' => AttendanceStatus::Present]);

        $this->assertSame([$planned->id], TrainingSession::unmarkedPlanned()->pluck('id')->all());
    }
}
