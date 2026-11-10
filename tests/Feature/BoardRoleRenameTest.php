<?php

namespace Tests\Feature;

use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardRoleRenameTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function giving_a_default_role_a_readable_name_keeps_its_members(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'approved' => true, 'email_verified_at' => now()]);
        $role = BoardRole::firstOrCreate(['name' => 'president'], ['sort_order' => 1]);
        $member = BoardMember::create(['name' => 'X', 'role' => 'president', 'status' => 'active']);

        $this->actingAs($admin)->put(route('board-roles.update', $role), [
            'name' => 'president', 'label' => 'رئيس النادي', 'level' => 'top', 'sort_order' => 1,
        ])->assertSessionHas('success', 'flash.role_updated');

        $this->assertSame('رئيس النادي', $role->fresh()->label);
        $this->assertSame('president', $member->fresh()->role);
    }

    #[Test]
    public function a_custom_role_with_no_label_keeps_its_name_and_members(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'approved' => true, 'email_verified_at' => now()]);
        $role = BoardRole::create(['name' => 'Media officer', 'label' => 'Chargé de média', 'sort_order' => 9]);
        $member = BoardMember::create(['name' => 'X', 'role' => 'Media officer', 'status' => 'active']);

        $this->actingAs($admin)->put(route('board-roles.update', $role), [
            'name' => 'Media officer', 'label' => null, 'level' => 'member', 'sort_order' => 9,
        ])->assertSessionHas('success', 'flash.role_updated');

        $role->refresh();
        $this->assertNull($role->label);
        $this->assertSame('Media officer', $role->name);
        $this->assertSame('Media officer', $member->fresh()->role);
    }

    #[Test]
    public function a_label_over_100_characters_is_refused(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'approved' => true, 'email_verified_at' => now()]);
        $role = BoardRole::firstOrCreate(['name' => 'president'], ['sort_order' => 1]);

        $this->actingAs($admin)->put(route('board-roles.update', $role), [
            'name' => 'president', 'label' => str_repeat('م', 101), 'level' => 'top', 'sort_order' => 1,
        ])->assertSessionHasErrors('label');

        $this->assertNull($role->fresh()->label);
    }
}
