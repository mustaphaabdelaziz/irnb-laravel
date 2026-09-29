<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Services\Activity\ActivityAction;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendancePermissionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function attendance_is_a_module_and_its_routes_map_to_it(): void
    {
        $this->assertContains('attendance', Role::MODULES);
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.index'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.sessions.show'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.grid'));
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.grid.save'));
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.sessions.marks'));
        $this->assertSame(['attendance', 'add'], PermissionMap::resolve('attendance.sessions.store'));
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.settings'));
    }

    #[Test]
    public function the_migration_mirrors_players_rights_and_gives_admin_roles_everything(): void
    {
        $coach = Role::factory()->create(['permissions' => ['players' => ['view', 'edit']]]);
        $cashier = Role::factory()->create(['permissions' => ['transactions' => ['view']]]);
        $admin = Role::factory()->create(['key' => 'administrator', 'permissions' => ['players' => ['view']]]);

        (require database_path('migrations/2026_09_29_100002_grant_attendance_permission.php'))->up();

        $this->assertSame(['view', 'edit'], $coach->fresh()->permissions['attendance']);
        $this->assertArrayNotHasKey('attendance', $cashier->fresh()->permissions);
        $this->assertSame(['view', 'add', 'edit', 'delete'], $admin->fresh()->permissions['attendance']);
    }

    #[Test]
    public function attendance_activity_codes_form_their_own_area(): void
    {
        $this->assertSame([
            ActivityAction::ATTENDANCE_MARKED,
            ActivityAction::TRAINING_SESSION_CREATED,
            ActivityAction::TRAINING_SESSION_CANCELLED,
            ActivityAction::TRAINING_SESSION_MOVED,
        ], ActivityAction::AREAS['attendance']);
    }
}
