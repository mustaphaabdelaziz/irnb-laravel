<?php

namespace Tests\Feature\Dashboard;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\Transaction;
use App\Services\Dashboard\DashboardFilters;
use App\Services\Dashboard\OverviewStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityFeedLabelTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function activity(): array
    {
        return app(OverviewStats::class)->get(
            DashboardFilters::fromRequest(Request::create('/dashboard', 'GET')),
        )['activity'];
    }

    #[Test]
    public function a_titled_transaction_shows_its_title_in_the_feed(): void
    {
        Transaction::create([
            'title' => 'Hall rent — March', 'description' => 'Paid in cash at the town hall',
            'amount' => 8000, 'transaction_type' => 'expense', 'category' => 'rent', 'status' => 'Paid',
        ]);

        $this->assertSame('Hall rent — March', $this->activity()[0]['label']);
    }

    #[Test]
    public function an_assignment_is_labelled_as_an_assignment_in_the_feed(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Coach radio', 'category' => 'Electronics']);
        $item = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 1]);
        EquipmentRental::create([
            'equipment_item_id' => $item->id, 'external_name' => 'n/a', 'type' => 'assignment',
            'quantity' => 1, 'checkout_date' => now(),
        ]);

        $this->assertSame('assignment', $this->activity()[0]['type']);
    }
}
