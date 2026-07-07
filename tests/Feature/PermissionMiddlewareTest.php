<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth', 'permission'])->group(function () {
            Route::get('/_t/finance', fn () => 'ok')->name('finance.index');
            Route::delete('/_t/finance/{id}', fn () => 'ok')->name('finance.years.close');
        });
    }

    #[Test]
    public function it_blocks_without_permission_and_allows_with(): void
    {
        $viewer = User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::factory()->create(['permissions' => ['finance' => ['view']]])->id,
        ]);

        $this->actingAs($viewer)->get('/_t/finance')->assertOk();               // has finance.view
        $this->actingAs($viewer)->delete('/_t/finance/1')->assertForbidden();   // lacks finance.edit
    }

    #[Test]
    public function god_admin_passes_everything(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin']]);
        $this->actingAs($admin)->delete('/_t/finance/1')->assertOk();
    }
}
