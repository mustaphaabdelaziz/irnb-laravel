<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchaseOrderRightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[Test]
    public function purchase_orders_is_a_finance_module_with_the_canonical_actions_only(): void
    {
        $this->assertContains('purchase_orders', Role::MODULES);
        $this->assertContains('purchase_orders', Role::MODULE_GROUPS['finance']);
        $this->assertSame(['view', 'add', 'edit', 'delete'], Role::allPermissions()['purchase_orders']);
        $this->assertArrayNotHasKey('purchase_orders', Role::EXTRA_ACTIONS);
    }

    #[Test]
    public function routes_map_to_the_rights_the_spec_names(): void
    {
        $this->assertSame(['purchase_orders', 'view'], PermissionMap::resolve('purchase-orders.index'));
        $this->assertSame(['purchase_orders', 'view'], PermissionMap::resolve('purchase-orders.show'));
        $this->assertSame(['purchase_orders', 'view'], PermissionMap::resolve('purchase-orders.print'));
        $this->assertSame(['purchase_orders', 'add'], PermissionMap::resolve('purchase-orders.create'));
        $this->assertSame(['purchase_orders', 'add'], PermissionMap::resolve('purchase-orders.store'));
        $this->assertSame(['purchase_orders', 'edit'], PermissionMap::resolve('purchase-orders.update'));
        foreach (['send', 'receive', 'cancel', 'payment-order.store'] as $action) {
            $this->assertSame(['purchase_orders', 'edit'], PermissionMap::resolve('purchase-orders.'.$action), $action);
        }
    }

    #[Test]
    public function the_grant_migration_gives_system_roles_the_module_and_runs_twice_safely(): void
    {
        $system = Role::factory()->create(['is_system' => true, 'permissions' => ['players' => ['view']]]);
        $trimmed = Role::factory()->create(['is_system' => true, 'permissions' => ['purchase_orders' => ['view']]]);
        $other = Role::factory()->create(['is_system' => false, 'permissions' => ['players' => ['view']]]);
        $migration = require database_path('migrations/2026_11_25_200000_grant_purchase_order_permissions.php');

        $migration->up();
        $migration->up();

        $this->assertSame(['view', 'add', 'edit', 'delete'], $system->fresh()->permissions['purchase_orders']);
        $this->assertSame(['view'], $system->fresh()->permissions['players']);
        // A system role someone already trimmed keeps its choice.
        $this->assertSame(['view'], $trimmed->fresh()->permissions['purchase_orders']);
        $this->assertArrayNotHasKey('purchase_orders', $other->fresh()->permissions);
    }
}
