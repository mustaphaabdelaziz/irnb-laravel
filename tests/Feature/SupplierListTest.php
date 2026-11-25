<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplierListTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    #[Test]
    public function the_list_shows_suppliers_alphabetically(): void
    {
        Supplier::create(['name' => 'Zeta Sport']);
        Supplier::create(['name' => 'Alpha Print']);

        $this->actingAs($this->admin())->get(route('suppliers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/Suppliers')
                ->where('suppliers.0.name', 'Alpha Print')
                ->where('suppliers.1.name', 'Zeta Sport'));
    }

    #[Test]
    public function a_supplier_is_created_with_bank_details(): void
    {
        $this->actingAs($this->admin())->post(route('suppliers.store'), [
            'name' => 'Journal El Massa', 'activity' => 'Press', 'bank_name' => 'BNA 611',
            'rib' => '00100000000000001234', 'is_active' => true,
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.supplier_created');

        $this->assertDatabaseHas('suppliers', ['name' => 'Journal El Massa', 'rib' => '00100000000000001234', 'is_active' => true]);
    }

    #[Test]
    public function names_are_unique_ignoring_case(): void
    {
        Supplier::create(['name' => 'Journal El Massa']);

        $this->actingAs($this->admin())->post(route('suppliers.store'), ['name' => 'JOURNAL el massa'])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function a_rib_is_digits_only(): void
    {
        $this->actingAs($this->admin())->post(route('suppliers.store'), ['name' => 'X', 'rib' => '0010 abc'])
            ->assertSessionHasErrors('rib');
    }

    #[Test]
    public function an_unused_supplier_can_be_deleted(): void
    {
        $supplier = Supplier::create(['name' => 'Old']);

        $this->actingAs($this->admin())->delete(route('suppliers.destroy', $supplier))
            ->assertSessionHas('success', 'flash.supplier_deleted');

        $this->assertModelMissing($supplier);
    }

    #[Test]
    public function the_list_needs_the_suppliers_right(): void
    {
        $role = Role::factory()->create(['permissions' => ['venues' => ['view']]]);
        $user = User::factory()->create(['privileges' => ['user'], 'role_id' => $role->id]);

        $this->actingAs($user)->get(route('suppliers.index'))->assertForbidden();
    }
}
