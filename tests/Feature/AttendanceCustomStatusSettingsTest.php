<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\AttendanceCustomStatus;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\AttendanceSettings;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceCustomStatusSettingsTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function form(array $extra = []): array
    {
        return $extra + [
            'code' => 'v', 'color' => '#7C3AED', 'label_ar' => 'مسافر', 'label_fr' => 'Voyage', 'label_en' => '',
            'behaviour' => 'not_counted', 'is_active' => true,
        ];
    }

    #[Test]
    public function the_owner_adds_a_custom_code_with_its_behaviour(): void
    {
        $this->actingAs($this->admin())->post(route('attendance.custom-statuses.store'), $this->form())
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.attendance_settings_saved');

        $status = AttendanceCustomStatus::sole();
        $this->assertSame('c_'.$status->id, $status->key);
        $this->assertSame('V', $status->code);
        $this->assertSame('#7c3aed', $status->color);
        $this->assertNull($status->label_en);
        $this->assertSame('not_counted', $status->behaviour);
        $this->assertTrue($status->is_active);
    }

    #[Test]
    public function codes_are_unique_ignoring_case_across_built_in_and_custom_codes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('attendance.custom-statuses.store'), $this->form(['code' => 'ae']))
            ->assertSessionHasErrors(['code' => 'att.error.code_taken']);
        $this->actingAs($admin)->post(route('attendance.custom-statuses.store'), $this->form())->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('attendance.custom-statuses.store'), $this->form(['code' => ' V ']))
            ->assertSessionHasErrors(['code' => 'att.error.code_taken']);

        // A built-in code may not take a custom one either.
        $payload = AttendanceSettings::DEFAULTS;
        $payload['codes']['present']['code'] = 'v';
        $this->actingAs($admin)->put(route('attendance.settings.update'), $payload)
            ->assertSessionHasErrors(['codes.present.code' => 'att.error.code_taken']);

        // Its own code is not a clash when editing it.
        $status = AttendanceCustomStatus::sole();
        $this->actingAs($admin)->put(route('attendance.custom-statuses.update', $status), $this->form(['label_en' => 'Travelling']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Travelling', $status->fresh()->label_en);
    }

    #[Test]
    public function bad_input_is_rejected(): void
    {
        $admin = $this->admin();

        foreach (['', 'ABCD', 'V1'] as $bad) {
            $this->actingAs($admin)->post(route('attendance.custom-statuses.store'), $this->form(['code' => $bad]))
                ->assertSessionHasErrors(['code' => 'att.error.code_format']);
        }
        $this->actingAs($admin)->post(route('attendance.custom-statuses.store'), $this->form(['color' => 'red']))
            ->assertSessionHasErrors(['color' => 'att.error.color_format']);
        $this->actingAs($admin)->post(route('attendance.custom-statuses.store'), $this->form(['behaviour' => 'late']))
            ->assertSessionHasErrors('behaviour');
        $this->actingAs($admin)->post(route('attendance.custom-statuses.store'), $this->form(['label_ar' => '', 'label_fr' => null]))
            ->assertSessionHasErrors(['label_fr' => 'att.error.label_required']);

        $this->assertSame(0, AttendanceCustomStatus::count());
    }

    #[Test]
    public function a_code_in_use_can_be_hidden_but_not_deleted(): void
    {
        $admin = $this->admin();
        $used = AttendanceCustomStatus::createWithKey($this->form(['code' => 'V']));
        $unused = AttendanceCustomStatus::createWithKey($this->form(['code' => 'W']));
        $u15 = $this->category();
        $session = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $this->player($u15)->id, 'status' => $used->key]);

        $this->actingAs($admin)->delete(route('attendance.custom-statuses.destroy', $used))
            ->assertSessionHasErrors(['custom_status' => 'att.error.code_in_use']);
        $this->assertModelExists($used);

        $this->actingAs($admin)->put(route('attendance.custom-statuses.update', $used), $this->form(['code' => 'V', 'is_active' => false]))
            ->assertSessionHasNoErrors();
        $this->assertFalse($used->fresh()->is_active);

        $this->actingAs($admin)->delete(route('attendance.custom-statuses.destroy', $unused))
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.attendance_settings_saved');
        $this->assertModelMissing($unused);
    }

    #[Test]
    public function the_settings_page_lists_custom_codes_with_whether_marks_use_them(): void
    {
        $used = AttendanceCustomStatus::createWithKey($this->form(['code' => 'V']));
        $u15 = $this->category();
        $session = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $this->player($u15)->id, 'status' => $used->key]);
        AttendanceCustomStatus::createWithKey($this->form(['code' => 'W']));

        $this->actingAs($this->admin())->get(route('attendance.settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('customStatuses', 2)
                ->where('customStatuses.0.code', 'V')
                ->where('customStatuses.0.used', true)
                ->where('customStatuses.1.used', false)
                ->where('behaviours', AttendanceCustomStatus::BEHAVIOURS));
    }

    #[Test]
    public function every_custom_code_route_needs_attendance_edit(): void
    {
        foreach (['store', 'update', 'destroy'] as $action) {
            $this->assertSame(['attendance', 'edit'], PermissionMap::resolve("attendance.custom-statuses.$action"));
        }

        $user = User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => ['attendance' => ['view', 'add']]])->id]);
        $this->actingAs($user)->post(route('attendance.custom-statuses.store'), $this->form())->assertForbidden();
        $this->assertSame(0, AttendanceCustomStatus::count());
    }
}
