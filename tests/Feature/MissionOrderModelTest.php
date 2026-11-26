<?php

namespace Tests\Feature;

use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\MissionOrderTraveler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MissionOrderModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function order(array $extra = []): MissionOrder
    {
        return MissionOrder::create(array_merge([
            'order_date' => '2026-11-26', 'destination' => 'Testville', 'reason' => 'Stage de formation',
            'departure_date' => '2026-12-01', 'return_date' => '2026-12-03', 'transport' => 'Bus du club',
            'status' => MissionOrder::ACTIVE,
        ], $extra));
    }

    #[Test]
    public function an_order_reads_its_dates_as_days_and_hides_its_lock_version(): void
    {
        $order = $this->order()->fresh();

        $this->assertSame('2026-11-26', $order->order_date->toDateString());
        $this->assertSame('2026-12-01', $order->departure_date->toDateString());
        $this->assertSame('2026-12-03', $order->return_date->toDateString());
        $this->assertFalse($order->is_legacy);
        $this->assertSame(0, (int) DB::table('mission_orders')->where('id', $order->id)->value('lock_version'));

        $array = $order->toArray();
        $this->assertSame('2026-12-01', $array['departure_date']);
        $this->assertArrayNotHasKey('lock_version', $array);
    }

    #[Test]
    public function only_an_active_order_typed_in_this_app_is_editable(): void
    {
        $this->assertTrue($this->order()->isEditable());
        $this->assertFalse($this->order(['status' => MissionOrder::CANCELLED])->isEditable());
        $this->assertFalse($this->order(['is_legacy' => true, 'legacy_reference' => '59', 'legacy_source' => MissionOrder::LEGACY_SOURCE])->isEditable());
    }

    #[Test]
    public function travelers_come_in_their_order_and_go_with_their_order(): void
    {
        $order = $this->order();
        $order->travelers()->create(['name' => 'Second Test', 'function' => 'Joueur', 'sort_order' => 2]);
        $order->travelers()->create(['traveler_type' => MissionOrderTraveler::USER, 'traveler_id' => 7, 'name' => 'Premier Test', 'function' => 'Entraîneur', 'sort_order' => 1]);

        $this->assertSame(['Premier Test', 'Second Test'], $order->travelers()->pluck('name')->all());
        $first = $order->travelers()->first();
        $this->assertSame('user', $first->traveler_type);
        $this->assertSame(7, $first->traveler_id);
        $this->assertSame($order->id, $first->missionOrder->id);

        // Orders are never deleted in the app; the table still cascades.
        $order->delete();
        $this->assertSame(0, MissionOrderTraveler::count());
    }

    #[Test]
    public function the_traveler_kinds_and_the_mail_source_type_are_named_once(): void
    {
        $this->assertSame(['user', 'board_member', 'player'], MissionOrderTraveler::TYPES);
        $this->assertSame('mission_order', MailEntry::SOURCE_MISSION);
        $this->assertSame(['active', 'cancelled'], MissionOrder::STATUSES);
        $this->assertSame('ordre_de_mission', MissionOrder::LEGACY_SOURCE);
    }
}
