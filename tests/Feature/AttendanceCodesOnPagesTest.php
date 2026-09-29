<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\TrainingSession;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceCodesOnPagesTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function every_attendance_page_receives_the_configured_codes(): void
    {
        $u15 = $this->category();
        $training = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
        AttendanceSettings::save(['codes' => ['late' => ['code' => 'ت', 'color' => '#123456', 'label' => ['ar' => 'تأخير', 'fr' => null, 'en' => null]]]]);
        $admin = $this->admin();

        $urls = [
            route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-10']),
            route('attendance.sessions.show', $training),
            route('attendance.grid', ['category_id' => $u15->id, 'month' => '2026-10']),
        ];
        foreach ($urls as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('attendanceCodes.late.code', 'ت')
                ->where('attendanceCodes.late.color', '#123456')
                ->where('attendanceCodes.late.label.ar', 'تأخير')
                ->where('attendanceCodes.present.code', 'P')
                ->where('attendanceCodes.present.color', '#059669'));
        }
    }
}
