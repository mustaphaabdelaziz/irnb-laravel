<?php

namespace Tests\Unit;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoleModelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_casts_json_columns_and_stores_a_matrix(): void
    {
        $role = Role::create([
            'key' => 'accountant',
            'name' => ['en' => 'Accountant', 'fr' => 'Comptable', 'ar' => 'محاسب'],
            'permissions' => ['finance' => ['view', 'edit'], 'transactions' => ['view']],
        ]);

        $fresh = $role->fresh();
        $this->assertSame(['en' => 'Accountant', 'fr' => 'Comptable', 'ar' => 'محاسب'], $fresh->name);
        $this->assertSame(['view', 'edit'], $fresh->permissions['finance']);
        $this->assertFalse($fresh->is_system);
    }

    #[Test]
    public function all_permissions_covers_every_module_and_action(): void
    {
        $all = Role::allPermissions();
        $this->assertCount(11, $all);
        $this->assertSame(['view', 'add', 'edit', 'delete'], $all['finance']);
        $this->assertArrayHasKey('settings', $all);
    }
}
