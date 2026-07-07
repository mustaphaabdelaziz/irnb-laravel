<?php

namespace Tests\Unit;

use App\Support\PermissionMap;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PermissionMapTest extends TestCase
{
    #[Test]
    public function it_derives_module_and_action_from_route_names(): void
    {
        $this->assertSame(['players', 'view'], PermissionMap::resolve('players.index'));
        $this->assertSame(['players', 'add'], PermissionMap::resolve('players.store'));
        $this->assertSame(['players', 'edit'], PermissionMap::resolve('players.update'));
        $this->assertSame(['players', 'delete'], PermissionMap::resolve('players.destroy'));
        $this->assertSame(['finance', 'edit'], PermissionMap::resolve('finance.years.close'));
        $this->assertSame(['categories', 'add'], PermissionMap::resolve('jobs.store'));
        $this->assertSame(['equipment', 'view'], PermissionMap::resolve('equipment.inventory'));
        $this->assertSame(['board', 'delete'], PermissionMap::resolve('board.meetings.destroy'));
        $this->assertSame(['inventory', 'edit'], PermissionMap::resolve('inventory.participants'));
    }

    #[Test]
    public function overrides_win_and_unguarded_returns_null(): void
    {
        $this->assertSame(['players', 'add'], PermissionMap::resolve('players.transactions.store'));
        $this->assertSame(['reports', 'view'], PermissionMap::resolve('reports.financial'));
        $this->assertNull(PermissionMap::resolve('dashboard'));
        $this->assertNull(PermissionMap::resolve('profile.edit'));
        $this->assertNull(PermissionMap::resolve(null));
        $this->assertNull(PermissionMap::resolve('login')); // unmapped auth route
    }
}
