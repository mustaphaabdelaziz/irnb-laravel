<?php

namespace Tests\Feature;

use App\Models\MissionOrder;
use App\Models\User;
use App\Services\Secretariat\MissionOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MissionPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-26 10:00:00');
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(): MissionOrder
    {
        return app(MissionOrders::class)->create([
            'order_date' => '2026-11-26', 'destination' => 'Testville', 'departure_date' => '2026-12-01',
            'travelers' => [['traveler_type' => null, 'traveler_id' => null, 'name' => 'Entraîneur Test', 'function' => 'Entraîneur']],
        ], $this->admin);
    }

    #[Test]
    public function the_list_and_the_create_page_render(): void
    {
        $this->order();

        $this->actingAs($this->admin)->get(route('missions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Missions/Index')->has('orders.data', 1));
        $this->get(route('missions.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Missions/Create')->has('users')->has('boardMembers'));
    }

    #[Test]
    public function the_edit_and_show_pages_render(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)->get(route('missions.edit', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Missions/Edit')->where('order.id', $order->id));
        $this->get(route('missions.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Missions/Show')
                ->where('reference', null)->where('can.register', true)->has('order.travelers', 1));
    }
}
