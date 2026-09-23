<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentCatalogImportExportTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    /** @param list<array<int,string>> $dataRows */
    private function makeCsv(array $dataRows): UploadedFile
    {
        // 5 columns: name, category, brand, purchase_price, description
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_fill(0, 5, 'header'));
        foreach ($dataRows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $content = stream_get_contents($fh);
        fclose($fh);

        $path = tempnam(sys_get_temp_dir(), 'catimp').'.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, 'equipments.csv', 'text/csv', null, true);
    }

    #[Test]
    public function the_template_can_be_downloaded(): void
    {
        $this->actingAs($this->user())
            ->get(route('equipment.catalogs.import.template'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    #[Test]
    public function it_imports_catalogs_and_skips_unknown_category_and_duplicates(): void
    {
        EquipmentCategory::firstOrCreate(['name' => 'Balls'], ['code' => 'BALL']);
        EquipmentCatalog::create(['name' => 'Existing Ball', 'category' => 'Balls']);

        $file = $this->makeCsv([
            ['Match Ball', 'Balls', 'Adidas', '2500', 'official'],
            ['Mystery Gear', 'Nope', '', '', ''],       // unknown category -> skipped
            ['Existing Ball', 'Balls', '', '', ''],       // duplicate name -> skipped
            ['', '', '', '', ''],                         // blank -> ignored
        ]);

        $this->actingAs($this->user())
            ->post(route('equipment.catalogs.import'), ['file' => $file])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('error');

        $this->assertDatabaseHas('equipment_catalogs', ['name' => 'Match Ball', 'category' => 'Balls', 'brand' => 'Adidas']);
        $this->assertDatabaseMissing('equipment_catalogs', ['name' => 'Mystery Gear']);
        // Only the pre-existing "Existing Ball" + the new "Match Ball" exist.
        $this->assertSame(2, EquipmentCatalog::count());
    }

    #[Test]
    public function it_exports_catalogs_as_csv(): void
    {
        EquipmentCatalog::create(['name' => 'Exported Kit', 'category' => 'Kits', 'brand' => 'Nike']);

        $response = $this->actingAs($this->user())->get(route('equipment.catalogs.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('Exported Kit', $content);
        $this->assertStringContainsString('Nike', $content);
    }
}
