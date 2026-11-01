<?php

namespace Tests\Feature;

use App\Models\BoardTask;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardTasksPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    #[Test]
    public function a_board_viewer_can_open_the_tasks_page(): void
    {
        $this->actingAs($this->userWith(['board' => ['view']]))
            ->get(route('board.tasks'))
            ->assertOk();
    }

    #[Test]
    public function a_board_viewer_still_cannot_change_a_task(): void
    {
        $task = BoardTask::create(['title' => 'Paint the fence', 'status' => 'not_started', 'priority' => 'medium']);
        $viewer = $this->userWith(['board' => ['view']]);

        $this->actingAs($viewer)->post(route('board.tasks.store'), ['title' => 'New', 'status' => 'not_started', 'priority' => 'low'])->assertForbidden();
        $this->actingAs($viewer)->put(route('board.tasks.update', $task), ['title' => 'Changed', 'status' => 'completed', 'priority' => 'low'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('board.tasks.destroy', $task))->assertForbidden();
        $this->assertSame('Paint the fence', $task->fresh()->title);
    }

    #[Test]
    public function without_board_view_the_tasks_page_is_refused(): void
    {
        $this->actingAs($this->userWith(['players' => ['view']]))
            ->get(route('board.tasks'))
            ->assertForbidden();
    }
}
