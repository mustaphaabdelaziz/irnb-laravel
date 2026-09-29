<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\TrainingSession;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceCodeSettingsTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    /** The whole settings form with some code rows changed. */
    private function payload(array $codes = []): array
    {
        $payload = AttendanceSettings::DEFAULTS;
        $payload['codes'] = array_replace_recursive($payload['codes'], $codes);

        return $payload;
    }

    #[Test]
    public function codes_colours_and_names_are_saved_normalised(): void
    {
        $this->actingAs($this->admin())->put(route('attendance.settings.update'), $this->payload([
            'present' => ['code' => 'ح', 'color' => '#10B981', 'label' => ['ar' => 'حاضر في الوقت', 'fr' => '', 'en' => null]],
            'late' => ['code' => 'rt'],
        ]))->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.attendance_settings_saved');

        $codes = AttendanceSettings::codes();
        $this->assertSame('ح', $codes['present']['code']);
        $this->assertSame('#10b981', $codes['present']['color']);
        $this->assertSame(['ar' => 'حاضر في الوقت', 'fr' => null, 'en' => null], $codes['present']['label']);
        $this->assertSame('RT', $codes['late']['code']);
        $this->assertSame('#f97316', $codes['left_early']['color']);
    }

    #[Test]
    public function bad_codes_and_colours_are_rejected(): void
    {
        $admin = $this->admin();

        foreach (['', 'ABCD', 'P1', 'P P'] as $bad) {
            $this->actingAs($admin)->put(route('attendance.settings.update'), $this->payload(['present' => ['code' => $bad]]))
                ->assertSessionHasErrors(['codes.present.code' => 'att.error.code_format']);
        }
        $this->actingAs($admin)->put(route('attendance.settings.update'), $this->payload(['late' => ['color' => 'red']]))
            ->assertSessionHasErrors(['codes.late.color' => 'att.error.color_format']);

        $this->assertSame('P', AttendanceSettings::codes()['present']['code']);
    }

    #[Test]
    public function codes_must_be_unique_ignoring_case(): void
    {
        $this->actingAs($this->admin())->put(route('attendance.settings.update'), $this->payload([
            'present' => ['code' => 'ab'], 'late' => ['code' => 'AB'],
        ]))->assertSessionHasErrors(['codes.late.code' => 'att.error.code_taken']);

        $this->assertSame('P', AttendanceSettings::codes()['present']['code']);
    }

    #[Test]
    public function the_grid_reads_and_writes_the_configured_codes_without_rewriting_marks(): void
    {
        $u15 = $this->category();
        [$a, $b] = [$this->player($u15), $this->player($u15)];
        $training = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $a->id, 'status' => AttendanceStatus::Late, 'minutes' => 15]);
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $b->id, 'status' => AttendanceStatus::Present]);
        AttendanceSettings::save(['codes' => ['present' => ['code' => 'ح'], 'late' => ['code' => 'ت']]]);
        $admin = $this->admin();

        $this->assertSame(AttendanceStatus::Late, Attendance::where('player_id', $a->id)->value('status'));

        $this->actingAs($admin)->get(route('attendance.grid', ['category_id' => $u15->id, 'month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page
                ->where("cells.{$a->id}.{$training->id}", 'ت15')
                ->where("cells.{$b->id}.{$training->id}", 'ح'));

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $training->id, 'codes' => [$a->id => 'R10', $b->id => 'ح']]],
        ])->assertSessionHasErrors("columns.0.codes.{$a->id}");

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $training->id, 'codes' => [$a->id => 'ت10', $b->id => '']]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, Attendance::where('player_id', $a->id)->value('minutes'));
    }
}
