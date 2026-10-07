<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Each App Configuration list is its own permission module, so a role can be
 * given one list without the others or without the area it sits next to.
 */
class PerListPermissionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_configuration_list_resolves_to_its_own_module(): void
    {
        $this->assertSame(['categories', 'view'], PermissionMap::resolve('categories.index'));
        $this->assertSame(['branches', 'view'], PermissionMap::resolve('branches.index'));
        $this->assertSame(['positions', 'view'], PermissionMap::resolve('positions.index'));
        $this->assertSame(['player_statuses', 'view'], PermissionMap::resolve('player-statuses.index'));
        $this->assertSame(['document_types', 'view'], PermissionMap::resolve('document-types.index'));
        $this->assertSame(['jobs', 'view'], PermissionMap::resolve('jobs.index'));
        $this->assertSame(['board_roles', 'view'], PermissionMap::resolve('board-roles.index'));
        $this->assertSame(['equipment_categories', 'view'], PermissionMap::resolve('equipment-categories.index'));
        $this->assertSame(['storage_locations', 'view'], PermissionMap::resolve('storage-locations.index'));
    }

    #[Test]
    public function a_material_role_can_get_equipment_categories_alone(): void
    {
        $material = User::factory()->create([
            'role_id' => Role::factory()->create([
                'key' => 'material',
                'permissions' => ['equipment' => ['view'], 'equipment_categories' => ['view', 'add', 'edit']],
            ])->id,
        ]);

        $this->actingAs($material)->get(route('equipment-categories.index'))->assertOk();

        $this->actingAs($material)->get(route('storage-locations.index'))->assertForbidden();
        $this->actingAs($material)->get(route('board-roles.index'))->assertForbidden();
        $this->actingAs($material)->get(route('branches.index'))->assertForbidden();
        $this->actingAs($material)->get(route('categories.index'))->assertForbidden();
    }

    #[Test]
    public function board_rights_no_longer_include_board_roles(): void
    {
        $secretary = User::factory()->create([
            'role_id' => Role::factory()->create(['permissions' => ['board' => ['view', 'add', 'edit', 'delete']]])->id,
        ]);

        $this->assertTrue($secretary->hasPermission('board', 'view'));
        $this->assertFalse($secretary->hasPermission('board_roles', 'view'));
        $this->actingAs($secretary)->get(route('board-roles.index'))->assertForbidden();
    }

    #[Test]
    public function the_migration_gives_the_new_lists_to_system_roles_only(): void
    {
        $administrator = Role::factory()->create(['key' => 'administrator', 'is_system' => true, 'permissions' => ['players' => ['view']]]);
        $coach = Role::factory()->create(['key' => 'coach', 'is_system' => false, 'permissions' => ['categories' => ['view']]]);

        (require database_path('migrations/2026_10_07_110000_split_configuration_permissions.php'))->up();

        $admin = $administrator->fresh()->permissions;
        $this->assertSame(Role::ACTIONS, $admin['equipment_categories']);
        $this->assertSame(Role::ACTIONS, $admin['board_roles']);
        $this->assertSame(['view'], $admin['players']);

        // Owner's choice: other roles start with the new rights empty.
        $this->assertSame(['categories' => ['view']], $coach->fresh()->permissions);
    }

    #[Test]
    public function the_role_editor_receives_the_lists_grouped_like_the_sidebar(): void
    {
        $flat = array_merge(...array_values(Role::MODULE_GROUPS));
        sort($flat);
        $modules = Role::MODULES;
        sort($modules);

        $this->assertSame($modules, $flat);
        $this->assertContains('equipment_categories', Role::MODULE_GROUPS['config']);
    }
}
