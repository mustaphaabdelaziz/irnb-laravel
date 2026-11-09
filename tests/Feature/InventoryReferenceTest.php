<?php

namespace Tests\Feature;

use App\Models\InventorySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InventoryReferenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_new_stock_take_numbers_from_the_highest_used_after_a_delete(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
        $payload = ['type' => 'yearly', 'session_date' => '2026-11-01'];

        $this->actingAs($admin)->post(route('inventory.store'), $payload);
        $this->actingAs($admin)->post(route('inventory.store'), $payload);
        $first = InventorySession::orderBy('id')->first();
        $this->actingAs($admin)->delete(route('inventory.destroy', $first));

        $this->actingAs($admin)->post(route('inventory.store'), $payload)->assertSessionHasNoErrors();

        $year = now()->format('Y');
        $this->assertDatabaseHas('inventory_sessions', ['reference' => "INV-{$year}-03"]);
        $this->assertSame(2, InventorySession::count());
    }
}
