<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Services\Activity\ActivityAction;
use App\Support\PermissionMap;
use Database\Seeders\RoleSeeder;
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
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.sessions.categories'));
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.settings'));
        // Settings mutations all need edit, not the weaker add/delete deriveAction() would give them.
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.schedules.store'));
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.schedules.destroy'));
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.closures.store'));
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.closures.destroy'));
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.preseason-targets.store'));

        // 2b: statistics, their exports and the profile card only read.
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.stats'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.stats.export'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.players.show'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.players.report'));

        // Paper sheets only read. "month" is not a view verb, so the route has an override.
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.sheets.month'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.sheets.session'));

        // Follow-up: every page and PDF only reads. "alerts", "letter", "ranking", "certificates" and
        // "injuries" are not view verbs, so those routes have overrides; the export is a view verb.
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.alerts'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.alerts.export'));

        // The letter text is a setting: saving it needs edit ("letter" falls to edit).
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.settings.letter'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.players.letter'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.ranking'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.certificates'));

        // Injury details are edited, never just added or deleted: all three writes need edit.
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.injury-notes.store'));
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.injury-notes.update'));
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.injury-notes.destroy'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.injuries'));
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
    public function the_coach_preset_gets_attendance_rights(): void
    {
        (new RoleSeeder)->run();

        $this->assertSame(['view', 'add', 'edit'], Role::where('key', 'coach')->first()->permissions['attendance']);
    }

    #[Test]
    public function attendance_activity_codes_form_their_own_area(): void
    {
        $this->assertSame([
            ActivityAction::ATTENDANCE_MARKED,
            ActivityAction::TRAINING_SESSION_CREATED,
            ActivityAction::TRAINING_SESSION_CANCELLED,
            ActivityAction::TRAINING_SESSION_MOVED,
            ActivityAction::TRAINING_SESSION_CATEGORIES_CHANGED,
        ], ActivityAction::AREAS['attendance']);
    }
}
