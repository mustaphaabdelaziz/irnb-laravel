<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentImportExportTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function catalog(): EquipmentCatalog
    {
        return EquipmentCatalog::create(['name' => 'Match Ball', 'category' => 'Balls']);
    }

    /** @param list<array<int,string>> $dataRows */
    private function makeCsv(array $dataRows): UploadedFile
    {
        // 6 columns: designation, purchase_date, condition, location, purchase_price, notes
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_fill(0, 6, 'header'));
        foreach ($dataRows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $content = stream_get_contents($fh);
        fclose($fh);

        $path = tempnam(sys_get_temp_dir(), 'eqimp').'.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, 'items.csv', 'text/csv', null, true);
    }

    #[Test]
    public function the_template_can_be_downloaded(): void
    {
        $this->actingAs($this->user())
            ->get(route('equipment.items.import.template', ['format' => 'csv']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    #[Test]
    public function it_imports_items_into_the_catalog_and_records_a_purchase(): void
    {
        $catalog = $this->catalog();

        $file = $this->makeCsv([
            ['Shirt 10', '2026-05-01', 'New', 'Locker A', '1500', 'first batch'],
            ['Shirt 11', '2026-05-01', 'Good', 'Locker A', '', ''],
            ['', '', '', '', '', ''], // blank row ignored
        ]);

        $this->actingAs($this->user())
            ->post(route('equipment.items.import', $catalog), ['file' => $file])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, EquipmentItem::where('catalog_id', $catalog->id)->count());
        $first = EquipmentItem::where('designation', 'Shirt 10')->firstOrFail();
        $this->assertSame('New', $first->condition);
        $this->assertSame('Available', $first->status);
        $this->assertNotNull($first->unique_identifier);
        // purchase_price populated -> an equipment expense transaction exists
        $this->assertDatabaseHas('transactions', ['category' => 'equipment', 'amount' => 1500]);
    }

    #[Test]
    public function it_defaults_bad_condition_and_missing_date(): void
    {
        $catalog = $this->catalog();
        $file = $this->makeCsv([['NoDate', '', 'Bogus', '', '', '']]);

        $this->actingAs($this->user())
            ->post(route('equipment.items.import', $catalog), ['file' => $file])
            ->assertRedirect();

        $item = EquipmentItem::where('designation', 'NoDate')->firstOrFail();
        $this->assertSame('New', $item->condition);       // invalid condition clamped
        $this->assertNotNull($item->purchase_date);       // missing date -> today
    }

    #[Test]
    public function an_items_xlsx_export_re_imports_without_reading_status_as_a_price(): void
    {
        $catalog = $this->catalog();
        EquipmentItem::create([
            'catalog_id' => $catalog->id,
            'unique_identifier' => 'IRNB-2026-BALL-00042',
            'purchase_date' => '2026-05-01',
            'status' => 'Available',
            'condition' => 'Good',
            'location' => 'Locker B',
            'designation' => 'Exported Ball',
            'notes' => 'spare',
        ]);
        $user = User::factory()->admin()->create(['email_verified_at' => now(), 'preferred_lng' => 'ar']);

        $response = $this->actingAs($user)->get(route('equipment.items.export', [$catalog, 'format' => 'xlsx']));
        $response->assertOk();
        ob_start();
        $response->baseResponse->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'eqx').'.xlsx';
        file_put_contents($path, ob_get_clean());

        $this->actingAs($user)
            ->post(route('equipment.items.import', $catalog), ['file' => new UploadedFile($path, 'items.xlsx', null, null, true)])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionMissing('error');

        $this->assertSame(0, Transaction::query()->count(), 'the export has no price column: no purchase expense');
        $copy = EquipmentItem::where('catalog_id', $catalog->id)->where('unique_identifier', '!=', 'IRNB-2026-BALL-00042')->sole();
        $this->assertSame('Exported Ball', $copy->designation);
        $this->assertSame('Good', $copy->condition);
        $this->assertSame('Locker B', $copy->location);
        $this->assertSame('2026-05-01', $copy->purchase_date->format('Y-m-d'));
        $this->assertSame('spare', $copy->notes);
        $this->assertNull($copy->purchase_transaction_id);
    }

    #[Test]
    public function it_exports_the_catalog_items_as_csv(): void
    {
        $catalog = $this->catalog();
        $item = EquipmentItem::create([
            'catalog_id' => $catalog->id,
            'unique_identifier' => 'IRNB-2026-BALL-00042',
            'purchase_date' => '2026-05-01',
            'status' => 'Available',
            'condition' => 'Good',
            'designation' => 'Exported Ball',
        ]);

        $response = $this->actingAs($this->user())->get(route('equipment.items.export', [$catalog, 'format' => 'csv']));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('IRNB-2026-BALL-00042', $content);
        $this->assertStringContainsString('Exported Ball', $content);
    }
}
