<?php

namespace Tests\Feature\Activities;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityTypesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    #[Test]
    public function the_list_shows_types_in_order_with_usage(): void
    {
        $this->actingAs($this->admin())->get(route('activity-types.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/ActivityTypes')
                ->has('types', 11)
                ->where('types.0.name', 'تنظيمات النادي')
                ->where('types.0.activities_count', 0));
    }

    #[Test]
    public function a_type_can_be_added_renamed_and_deleted(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('activity-types.store'), ['name' => 'يوم مفتوح'])
            ->assertSessionHas('success', 'flash.activity_type_created');
        $type = ActivityType::where('name', 'يوم مفتوح')->firstOrFail();
        $this->assertSame(12, $type->sort_order);

        $this->put(route('activity-types.update', $type), ['name' => 'أبواب مفتوحة', 'sort_order' => 2])
            ->assertSessionHas('success', 'flash.activity_type_updated');
        $this->assertSame('أبواب مفتوحة', $type->fresh()->name);

        $this->delete(route('activity-types.destroy', $type))
            ->assertSessionHas('success', 'flash.activity_type_deleted');
        $this->assertModelMissing($type);
    }

    #[Test]
    public function clearing_the_order_keeps_the_stored_one(): void
    {
        $type = ActivityType::first();

        $this->actingAs($this->admin())
            ->put(route('activity-types.update', $type), ['name' => $type->name, 'sort_order' => null])
            ->assertSessionHas('success', 'flash.activity_type_updated');
        $this->assertSame(6, $type->fresh()->sort_order);
    }

    #[Test]
    public function names_are_unique(): void
    {
        $this->actingAs($this->admin())
            ->post(route('activity-types.store'), ['name' => 'مخيم صيفي'])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function a_type_in_use_cannot_be_deleted(): void
    {
        $type = ActivityType::first();
        Activity::create(['title' => 'Camp', 'activity_type_id' => $type->id, 'starts_on' => '2026-07-01']);

        $this->actingAs($this->admin())->delete(route('activity-types.destroy', $type))
            ->assertSessionHas('error', 'flash.activity_type_in_use');
        $this->assertModelExists($type);
    }

    #[Test]
    public function the_list_needs_its_own_right(): void
    {
        $board = User::factory()->create([
            'role_id' => Role::factory()->create(['permissions' => ['activities' => ['view', 'add', 'edit', 'delete']]])->id,
        ]);
        $this->actingAs($board)->get(route('activity-types.index'))->assertForbidden();

        $config = User::factory()->create([
            'role_id' => Role::factory()->create(['permissions' => ['activity_types' => ['view']]])->id,
        ]);
        $this->actingAs($config)->get(route('activity-types.index'))->assertOk();
        $this->actingAs($config)->post(route('activity-types.store'), ['name' => 'X'])->assertForbidden();
    }
}
