<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\User;
use App\Support\Export\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
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
            ->get(route('equipment.catalogs.import.template', ['format' => 'csv']))
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

        $response = $this->actingAs($this->user())->get(route('equipment.catalogs.export', ['format' => 'csv']));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('Exported Kit', $content);
        $this->assertStringContainsString('Nike', $content);
    }

    #[Test]
    public function a_similar_name_is_imported_with_a_warning_from_csv_and_xlsx(): void
    {
        EquipmentCategory::firstOrCreate(['name' => 'Balls'], ['code' => 'BALL']);
        EquipmentCatalog::create(['name' => 'Match Ball', 'category' => 'Balls']);
        EquipmentCatalog::create(['name' => 'كرة مباراة', 'category' => 'Balls']);

        $rows = [
            ['match  ball', 'Balls', '', '', ''],     // case + inner space -> imported, warned
            ['كره مباراه', 'Balls', '', '', ''],      // taa form -> imported, warned
            ['Training Ball', 'Balls', '', '', ''],   // unrelated -> imported quietly
            ['training ball', 'Balls', '', '', ''],   // like a row above in the same file -> warned
        ];
        $xlsxPath = tempnam(sys_get_temp_dir(), 'catimp').'.xlsx';
        file_put_contents($xlsxPath, XlsxWriter::build(array_fill(0, 5, 'header'), $rows, null, false));

        $files = [
            'csv' => $this->makeCsv($rows),
            'xlsx' => new UploadedFile($xlsxPath, 'equipments.xlsx', null, null, true),
        ];

        foreach ($files as $format => $file) {
            EquipmentCatalog::whereNotIn('name', ['Match Ball', 'كرة مباراة'])->delete();

            $this->actingAs($this->user())
                ->post(route('equipment.catalogs.import'), ['file' => $file])
                ->assertSessionHas('success');

            // One report line per warning: the row's name, then the existing one
            // (the message itself is in the UI language).
            $error = (string) session('error');
            $this->assertMatchesRegularExpression('/"match  ball".*"Match Ball"/u', $error, $format);
            $this->assertMatchesRegularExpression('/"كره مباراه".*"كرة مباراة"/u', $error, $format);
            $this->assertMatchesRegularExpression('/"training ball".*"Training Ball"/u', $error, $format);
            $this->assertDoesNotMatchRegularExpression('/"Training Ball".*"/u', $error, $format);
            $this->assertSame(6, EquipmentCatalog::count(), $format);
        }
    }

    #[Test]
    public function the_forms_get_other_catalog_names_for_the_similar_name_hint(): void
    {
        $ball = EquipmentCatalog::create(['name' => 'Match Ball', 'category' => 'Balls']);
        EquipmentCatalog::create(['name' => 'Bib', 'category' => 'Kits']);

        $this->actingAs($this->user())->get(route('equipment.catalogs.create'))
            ->assertInertia(fn (Assert $page) => $page->where('catalogNames', ['Bib', 'Match Ball']));

        // Its own name never counts as a similar one.
        $this->actingAs($this->user())->get(route('equipment.catalogs.edit', $ball))
            ->assertInertia(fn (Assert $page) => $page->where('catalogNames', ['Bib']));
    }

    #[Test]
    public function the_form_still_saves_a_similar_name(): void
    {
        EquipmentCategory::firstOrCreate(['name' => 'Balls'], ['code' => 'BALL']);
        EquipmentCatalog::create(['name' => 'Match Ball', 'category' => 'Balls']);

        $this->actingAs($this->user())
            ->post(route('equipment.catalogs.store'), ['name' => 'match ball', 'category' => 'Balls'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, EquipmentCatalog::count());
    }
}
