<?php

namespace Tests\Feature;

use App\Http\Controllers\TransactionImportController;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\MemberJob;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Export\XlsxWriter;
use App\Support\Import\ImportColumns;
use App\Support\Spreadsheet;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $locale): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now(), 'preferred_lng' => $locale]);
    }

    private function save($response, string $ext): UploadedFile
    {
        ob_start();
        $response->baseResponse->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'tp').'.'.$ext;
        file_put_contents($path, ob_get_clean());

        return new UploadedFile($path, 'template.'.$ext, null, null, true);
    }

    private function xlsx(array $headers, array $rows, ?string $title = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tp').'.xlsx';
        file_put_contents($path, XlsxWriter::build($headers, $rows, $title, true));

        return new UploadedFile($path, 'file.xlsx', null, null, true);
    }

    public static function templates(): array
    {
        return [
            ['players.import.template', 'col.firstname'],
            ['transactions.import.template', 'col.cash_register'],
            ['equipment.catalogs.import.template', 'col.brand'],
            ['equipment.items.import.template', 'col.designation'],
        ];
    }

    #[Test]
    #[DataProvider('templates')]
    public function templates_default_to_xlsx_with_headers_in_the_users_language(string $route, string $key): void
    {
        $response = $this->actingAs($this->user('fr'))->get(route($route));

        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $rows = Spreadsheet::readRows($this->save($response, 'xlsx')->getRealPath());
        $this->assertContains(UiLang::get($key, null, 'fr'), array_map(fn ($h) => preg_replace('/\s*\(.*\)$/u', '', (string) $h), $rows[0]));
    }

    #[Test]
    #[DataProvider('templates')]
    public function templates_come_as_csv_on_request(string $route, string $key): void
    {
        $response = $this->actingAs($this->user('en'))->get(route($route, ['format' => 'csv']));

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $rows = Spreadsheet::readRows($this->save($response, 'csv')->getRealPath());
        $this->assertContains(UiLang::get($key, null, 'en'), array_map(fn ($h) => preg_replace('/\s*\(.*\)$/u', '', (string) $h), $rows[0]));
    }

    #[Test]
    public function hinted_headers_are_in_the_users_language(): void
    {
        $rows = Spreadsheet::readRows($this->save($this->actingAs($this->user('fr'))->get(route('players.import.template')), 'xlsx')->getRealPath());

        $this->assertContains('Date de naissance (AAAA-MM-JJ)', $rows[0]);
        $this->assertContains('Wilaya (code ou nom)', $rows[0]);
        $this->assertContains('Niveau (1-10)', $rows[0]);
        $this->assertContains(UiLang::get('male', null, 'fr'), $rows[1]); // localized example value
        $this->assertContains('GK', $rows[1]); // codes stay codes
    }

    #[Test]
    public function a_players_template_filled_in_french_imports_for_an_arabic_user(): void
    {
        $fr = $this->user('fr');
        $file = $this->save($this->actingAs($fr)->get(route('players.import.template', ['format' => 'csv'])), 'csv');

        $this->actingAs($this->user('ar'))->post(route('players.import.store'), ['file' => $file])->assertSessionHas('success');

        $p = Player::query()->sole(); // the example row
        $this->assertSame('Male', $p->gender);
        $this->assertFalse($p->is_student);
        $this->assertSame('2008-05-20', $p->birthdate?->format('Y-m-d') ?? (string) $p->birthdate);
    }

    #[Test]
    public function the_transactions_template_imports_as_its_example_row(): void
    {
        $file = $this->save($this->actingAs($this->user('fr'))->get(route('transactions.import.template')), 'xlsx');

        $this->actingAs($this->user('ar'))->post(route('transactions.import.store'), ['file' => $file])->assertSessionHas('success');

        $t = Transaction::query()->sole();
        $this->assertSame('income', $t->transaction_type);
        $this->assertSame('Paid', $t->status);
        $this->assertSame('cash', $t->payment_method);
        $this->assertEquals(1000, (float) $t->amount);
        $this->assertSame('2026-01-15', Carbon::parse($t->transaction_date)->format('Y-m-d'));
    }

    #[Test]
    public function the_transactions_template_example_is_keyed_by_column(): void
    {
        foreach (TransactionImportController::COLUMNS as $column) {
            $this->assertArrayHasKey('example', $column, $column['key']);
        }

        $rows = Spreadsheet::readRows($this->save($this->actingAs($this->user('fr'))->get(route('transactions.import.template')), 'xlsx')->getRealPath());
        [$headerRow, $map] = (new ImportColumns(TransactionImportController::COLUMNS))->locate($rows);
        $example = $rows[$headerRow + 1];

        $this->assertSame('1000', $example[$map['amount']]);
        $this->assertSame(UiLang::get('income', null, 'fr'), $example[$map['transaction_type']]);
        $this->assertSame(UiLang::get('paid', null, 'fr'), $example[$map['status']]);
        $this->assertSame(UiLang::get('cash', null, 'fr'), $example[$map['payment_method']]);
    }

    #[Test]
    public function the_players_template_example_is_a_worker_with_a_seeded_non_student_job(): void
    {
        $job = MemberJob::create(['name' => 'Ingénieur', 'name_fr' => 'Ingénieur']);
        MemberJob::create(['name' => 'Étudiant', 'name_fr' => 'Étudiant']);
        $file = $this->save($this->actingAs($this->user('ar'))->get(route('players.import.template', ['format' => 'csv'])), 'csv');

        $this->actingAs($this->user('ar'))->post(route('players.import.store'), ['file' => $file])->assertSessionHas('success');

        $p = Player::query()->sole();
        $this->assertFalse($p->is_student);
        $this->assertSame($job->id, $p->member_job_id);
    }

    #[Test]
    public function the_players_import_reads_an_xlsx_with_columns_in_another_order(): void
    {
        $headers = [UiLang::get('col.lastname', null, 'ar'), UiLang::get('col.firstname', null, 'ar'), UiLang::get('col.gender', null, 'ar'), UiLang::get('col.player_type', null, 'ar')];
        $file = $this->xlsx($headers, [['بن علي', 'محمد', UiLang::get('female', null, 'ar'), UiLang::get('student', null, 'fr')]]);

        $this->actingAs($this->user('ar'))->post(route('players.import.store'), ['file' => $file]);

        $p = Player::query()->sole();
        $this->assertSame('محمد', $p->firstname);
        $this->assertSame('بن علي', $p->lastname);
        $this->assertSame('Female', $p->gender);
        $this->assertTrue($p->is_student);
    }

    #[Test]
    public function import_errors_name_the_real_spreadsheet_row_below_a_title(): void
    {
        $catalogHeaders = (new ImportColumns([
            ['key' => 'name', 'label' => 'col.name'],
            ['key' => 'category', 'label' => 'col.category'],
        ]))->headers('en');

        // Title row 1, spacer row 2, header row 3, data rows 4 and 5.
        $file = $this->xlsx($catalogHeaders, [['Ball', 'Nope'], ['', '']], 'Equipment catalogs');

        $this->actingAs($this->user('en'))->post(route('equipment.catalogs.import'), ['file' => $file])
            ->assertSessionHas('error', fn (string $e) => str_contains($e, 'Row 4:') && ! str_contains($e, 'Row 3:'));
        $this->assertSame(0, EquipmentCatalog::query()->count());
    }

    #[Test]
    public function equipment_items_accept_a_localized_condition(): void
    {
        EquipmentCategory::firstOrCreate(['name' => 'Balls'], ['code' => 'BALL']);
        $catalog = EquipmentCatalog::create(['name' => 'Match ball', 'category' => 'Balls']);
        $headers = [UiLang::get('col.condition', null, 'fr'), UiLang::get('col.designation', null, 'fr')];

        $file = $this->xlsx($headers, [[UiLang::get('damaged', null, 'fr'), 'Ballon 1'], [UiLang::get('good', null, 'ar'), 'Ballon 2']]);
        $this->actingAs($this->user('en'))->post(route('equipment.items.import', $catalog), ['file' => $file]);

        $this->assertSame('Damaged', EquipmentItem::query()->where('designation', 'Ballon 1')->sole()->condition);
        $this->assertSame('Good', EquipmentItem::query()->where('designation', 'Ballon 2')->sole()->condition);
    }
}
