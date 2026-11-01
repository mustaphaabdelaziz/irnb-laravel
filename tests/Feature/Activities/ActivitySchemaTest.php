<?php

namespace Tests\Feature\Activities;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Role;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivitySchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function default_activity_types_are_seeded(): void
    {
        $this->assertSame(
            ['حملة تطوعية', 'جلسة توعوية', 'مخيم صيفي', 'نشاط خيري', 'زيارة', 'احتفال / مناسبة'],
            ActivityType::orderBy('sort_order')->pluck('name')->all(),
        );
    }

    #[Test]
    public function both_modules_are_permission_controlled(): void
    {
        $this->assertContains('activities', Role::MODULES);
        $this->assertContains('activity_types', Role::MODULES);
        $this->assertContains('activities', Role::MODULE_GROUPS['social']);
        $this->assertContains('activity_types', Role::MODULE_GROUPS['config']);
    }

    #[Test]
    public function routes_resolve_to_the_new_modules(): void
    {
        $this->assertSame(['activities', 'view'], PermissionMap::resolve('activities.index'));
        $this->assertSame(['activities', 'add'], PermissionMap::resolve('activities.store'));
        $this->assertSame(['activities', 'edit'], PermissionMap::resolve('activities.update'));
        $this->assertSame(['activities', 'delete'], PermissionMap::resolve('activities.destroy'));
        $this->assertSame(['activities', 'edit'], PermissionMap::resolve('activities.photos.store'));
        $this->assertSame(['activities', 'edit'], PermissionMap::resolve('activities.photos.destroy'));
        $this->assertSame(['activities', 'edit'], PermissionMap::resolve('activities.files.store'));
        $this->assertSame(['activities', 'edit'], PermissionMap::resolve('activities.files.destroy'));
        $this->assertSame(['activities', 'view'], PermissionMap::resolve('activities.files.show'));
        $this->assertSame(['activity_types', 'view'], PermissionMap::resolve('activity-types.index'));
    }

    #[Test]
    public function the_migration_grants_the_new_rights_to_system_roles_only(): void
    {
        $system = Role::factory()->create(['key' => 'sys', 'is_system' => true, 'permissions' => ['players' => ['view']]]);
        $custom = Role::factory()->create(['key' => 'custom', 'is_system' => false, 'permissions' => ['players' => ['view']]]);

        $migration = require database_path('migrations/2026_11_01_100001_create_activities_tables.php');
        $migration->down();
        $migration->up();

        $all = ['view', 'add', 'edit', 'delete'];
        $this->assertSame($all, $system->fresh()->permissions['activities']);
        $this->assertSame($all, $system->fresh()->permissions['activity_types']);
        $this->assertArrayNotHasKey('activities', $custom->fresh()->permissions);
        $this->assertArrayNotHasKey('activity_types', $custom->fresh()->permissions);
    }

    #[Test]
    public function participants_count_adds_players_board_members_and_outside_volunteers(): void
    {
        $activity = Activity::create([
            'title' => 'Ramadan basket', 'activity_type_id' => ActivityType::first()->id,
            'starts_on' => '2026-03-10', 'external_volunteers_count' => 5,
        ]);

        $this->assertSame(5, $activity->participants_count);
        $this->assertSame('2026-03-10', $activity->toArray()['starts_on']);
    }
}
