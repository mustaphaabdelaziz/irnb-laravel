<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\EquipmentCatalog;
use App\Models\InventorySession;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every link ExportMenu.vue renders is `<route>?format=<xlsx|csv>`: the server
 * must answer each with the matching file type, and default to xlsx without it.
 */
class ExportMenuWiringTest extends TestCase
{
    use RefreshDatabase;

    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const CSV = 'text/csv; charset=UTF-8';

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    /** Route parameters for the routes that need a record. */
    private function params(string $route): array
    {
        return match ($route) {
            'subscriptions.export' => ['subscription' => Subscription::create([
                'name' => 'Annual', 'year' => 2026, 'amount_student' => 1000, 'amount_worker' => 1500,
                'is_mandatory' => true, 'is_active' => true,
            ])->id, 'filter' => 'all'],
            'equipment.items.export' => ['catalog' => EquipmentCatalog::create(['name' => 'Balls', 'category' => 'Balls'])->id],
            'inventory.export' => ['session' => InventorySession::create([
                'reference' => 'INV-WIRING-1', 'type' => 'ad_hoc', 'session_date' => '2026-07-20', 'status' => 'in_progress',
            ])->id],
            'players.board-table' => ['category_id' => Category::create(['name' => 'Cadets'])->id],
            default => [],
        };
    }

    public static function routes(): array
    {
        return [
            'players export' => ['players.export'],
            'subscription export' => ['subscriptions.export'],
            'transactions export' => ['transactions.export'],
            'catalogs export' => ['equipment.catalogs.export'],
            'items export' => ['equipment.items.export'],
            'stocktake export' => ['inventory.export'],
            'board members export' => ['board.members.export'],
            'board tasks export' => ['board.tasks.export'],
            'players template' => ['players.import.template'],
            'transactions template' => ['transactions.import.template'],
            'catalogs template' => ['equipment.catalogs.import.template'],
            'items template' => ['equipment.items.import.template'],
        ];
    }

    #[Test]
    #[DataProvider('routes')]
    public function csv_is_served_on_request_and_xlsx_by_default(string $route): void
    {
        $admin = $this->admin();
        $params = $this->params($route);

        $csv = $this->actingAs($admin)->get(route($route, $params + ['format' => 'csv']));
        $csv->assertOk();
        $this->assertSame(self::CSV, $csv->headers->get('content-type'), "$route?format=csv");

        $default = $this->actingAs($admin)->get(route($route, $params));
        $default->assertOk();
        $this->assertSame(self::XLSX, $default->headers->get('content-type'), "$route (no format)");

        $xlsx = $this->actingAs($admin)->get(route($route, $params + ['format' => 'xlsx']));
        $xlsx->assertOk();
        $this->assertSame(self::XLSX, $xlsx->headers->get('content-type'), "$route?format=xlsx");
    }

    #[Test]
    public function the_board_table_menu_serves_pdf_by_default_and_xlsx_or_csv_on_request(): void
    {
        $admin = $this->admin();
        $params = $this->params('players.board-table');

        $pdf = $this->actingAs($admin)->get(route('players.board-table', $params));
        $pdf->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $pdf->headers->get('content-type'));

        $this->assertSame(self::XLSX, $this->actingAs($admin)->get(route('players.board-table', $params + ['format' => 'xlsx']))->assertOk()->headers->get('content-type'));
        $this->assertSame(self::CSV, $this->actingAs($admin)->get(route('players.board-table', $params + ['format' => 'csv']))->assertOk()->headers->get('content-type'));
    }
}
