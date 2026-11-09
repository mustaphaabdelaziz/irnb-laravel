<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardViewRightsTest extends TestCase
{
    use RefreshDatabase;

    private function viewOnlyBoardUser(): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => ['board' => ['view']]])->id]);
    }

    #[Test]
    public function the_members_page_and_the_meetings_list_are_reading(): void
    {
        $this->assertSame(['board', 'view'], PermissionMap::resolve('board.members'));
        $this->assertSame(['board', 'view'], PermissionMap::resolve('board.meetings'));
    }

    #[Test]
    public function a_view_only_user_opens_both_pages_but_cannot_change_a_term(): void
    {
        $user = $this->viewOnlyBoardUser();

        $this->actingAs($user)->get(route('board.members'))->assertOk();
        $this->actingAs($user)->get(route('board.meetings'))->assertOk();
        $this->actingAs($user)->post(route('board.terms.store'), ['name' => '2028–2032'])->assertForbidden();
    }
}
