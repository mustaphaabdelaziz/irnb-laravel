<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Import\XlsxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionImportRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $locale = 'ar'): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now(), 'preferred_lng' => $locale]);
    }

    private function download(User $user, string $format): UploadedFile
    {
        $response = $this->actingAs($user)->get(route('transactions.export', ['format' => $format]));
        ob_start();
        $response->baseResponse->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'rt').'.'.$format;
        file_put_contents($path, ob_get_clean());

        return new UploadedFile($path, 'transactions.'.$format, null, null, true);
    }

    private function csv(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rt').'.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, 't.csv', 'text/csv', null, true);
    }

    #[Test]
    public function an_exported_file_re_imports_with_the_same_values_in_both_formats(): void
    {
        foreach (['xlsx', 'csv'] as $format) {
            Transaction::query()->delete();
            $admin = $this->admin($format === 'xlsx' ? 'ar' : 'fr');
            // A second register, not the default one, so matching by name is what puts it back.
            $register = FinanceAccount::query()->firstOrCreate(
                ['name' => 'Caisse événements'],
                ['type' => 'cash', 'opening_balance' => 0, 'current_balance' => 0, 'currency' => 'DZD', 'is_active' => true],
            );
            $original = Transaction::create([
                'transaction_date' => '2026-02-03', 'transaction_type' => 'expense', 'category' => 'other',
                'amount' => 750.5, 'status' => 'Partial', 'payment_method' => 'bank',
                'description' => 'فاتورة كهرباء', 'title' => 'Électricité', 'finance_account_id' => $register->id,
                'recorded_by_user_id' => $admin->id,
            ]);

            $file = $this->download($admin, $format);
            Transaction::query()->delete();

            $this->actingAs($admin)->post(route('transactions.import.store'), ['file' => $file])
                ->assertSessionHasNoErrors()
                ->assertSessionMissing('error');

            $t = Transaction::query()->sole();
            $this->assertSame('2026-02-03', $t->transaction_date->format('Y-m-d'), $format);
            $this->assertSame('expense', $t->transaction_type, $format);
            $this->assertSame(750.5, (float) $t->amount, $format);
            $this->assertSame('Partial', $t->status, $format);
            $this->assertSame('bank', $t->payment_method, $format);
            $this->assertSame('فاتورة كهرباء', $t->description, $format);
            $this->assertSame('Électricité', $t->title, $format);
            $this->assertSame($register->id, $t->finance_account_id, $format);
            $this->assertSame($original->finance_category_id, $t->finance_category_id, $format);
        }
    }

    #[Test]
    public function localized_values_import_to_their_codes(): void
    {
        $admin = $this->admin('en');
        $file = $this->csv("\xEF\xBB\xBFالتاريخ,العنوان,النوع,الفئة,المبلغ,الحالة,طريقة الدفع,الصندوق,الوصف\r\n2026-03-01,X,مصاريف,أخرى,200,غير مدفوع,تحويل بنكي,,\r\n");

        $this->actingAs($admin)->post(route('transactions.import.store'), ['file' => $file])
            ->assertSessionMissing('error');

        $t = Transaction::query()->sole();
        $this->assertSame('expense', $t->transaction_type);
        $this->assertSame('Unpaid', $t->status);
        $this->assertSame('bank', $t->payment_method);
        $this->assertNotNull($t->finance_account_id);
    }

    #[Test]
    public function an_unknown_cash_register_falls_back_to_the_default_with_a_warning(): void
    {
        $admin = $this->admin('en');
        $file = $this->csv("\xEF\xBB\xBFDate,Title,Type,Category,Amount,Status,Payment method,Cash register,Description\r\n2026-02-03,X,income,other,100,Paid,cash,Nowhere Box,\r\n");

        $this->actingAs($admin)->post(route('transactions.import.store'), ['file' => $file])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Nowhere Box') && str_contains($e, 'Row 2'));

        $this->assertNotNull(Transaction::query()->sole()->finance_account_id);
    }

    #[Test]
    public function an_unparseable_amount_is_skipped_with_a_warning(): void
    {
        $admin = $this->admin('en');
        $file = $this->csv("\xEF\xBB\xBFDate,Title,Type,Category,Amount,Status,Payment method,Cash register,Description\r\n"
            ."2026-02-03,Good,income,other,100,Paid,cash,,\r\n"
            ."2026-02-03,Bad,income,other,12abc,Paid,cash,,\r\n"
            .",,,,,,,,\r\n");

        $this->actingAs($admin)->post(route('transactions.import.store'), ['file' => $file])
            ->assertSessionHas('success', '1 transactions imported successfully.')
            ->assertSessionHas('error', 'Row 3: amount "12abc" is not a number, the row was skipped.');

        $this->assertSame('Good', Transaction::query()->sole()->title);
    }

    #[Test]
    public function an_unparseable_date_uses_today_with_a_warning(): void
    {
        $admin = $this->admin('en');
        $file = $this->csv("\xEF\xBB\xBFDate,Title,Type,Category,Amount,Status,Payment method,Cash register,Description\r\n"
            ."31/02/2026,X,income,other,100,Paid,cash,,\r\n");

        $this->actingAs($admin)->post(route('transactions.import.store'), ['file' => $file])
            ->assertSessionHas('error', 'Row 2: date "31/02/2026" is not valid, today\'s date was used.');

        $this->assertSame(now()->format('Y-m-d'), Transaction::query()->sole()->transaction_date->format('Y-m-d'));
    }

    #[Test]
    public function the_new_warnings_are_translated(): void
    {
        $file = fn () => $this->csv("\xEF\xBB\xBFDate,Title,Type,Category,Amount,Status,Payment method,Cash register,Description\r\n"
            ."someday,X,income,other,100,Paid,cash,,\r\n"
            ."2026-02-03,Y,income,other,abc,Paid,cash,,\r\n");

        $this->actingAs($this->admin('fr'))->post(route('transactions.import.store'), ['file' => $file()])
            ->assertSessionHas('error', "Ligne 2 : date « someday » invalide, la date du jour a été utilisée.\nLigne 3 : montant « abc » n'est pas un nombre, la ligne a été ignorée.");

        $this->actingAs($this->admin('ar'))->post(route('transactions.import.store'), ['file' => $file()])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'السطر 2') && str_contains($e, 'someday') && str_contains($e, 'السطر 3') && str_contains($e, 'abc') && ! str_contains($e, 'Row'));
    }

    #[Test]
    public function a_file_made_from_the_old_template_still_imports(): void
    {
        $admin = $this->admin('en');
        $file = $this->csv("\xEF\xBB\xBFDate (YYYY-MM-DD),Type (income/expense),Category,Amount,Status (Paid/Partial/Unpaid/Exempt),Payment Method,Description,Title\r\n2026-01-15,expense,other,300,Unpaid,cash,desc,Old title\r\n");

        $this->actingAs($admin)->post(route('transactions.import.store'), ['file' => $file]);

        $t = Transaction::query()->sole();
        $this->assertSame('expense', $t->transaction_type);
        $this->assertSame(300.0, (float) $t->amount);
        $this->assertSame('Unpaid', $t->status);
        $this->assertSame('desc', $t->description);
        $this->assertSame('Old title', $t->title);
    }

    #[Test]
    public function an_xlsx_with_an_oversized_part_gets_the_friendly_error_not_a_500(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rt').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>');
        $zip->addFromString('xl/sharedStrings.xml', str_repeat('a', 70 * 1024));
        $zip->close();

        XlsxReader::$maxPartBytes = 64 * 1024;
        try {
            $this->actingAs($this->admin('en'))
                ->post(route('transactions.import.store'), ['file' => new UploadedFile($path, 't.xlsx', null, null, true)])
                ->assertRedirect()
                ->assertSessionHas('error', 'Could not read the file. Please use the provided template.');
        } finally {
            XlsxReader::$maxPartBytes = XlsxReader::MAX_PART_BYTES;
        }
        $this->assertSame(0, Transaction::query()->count());
    }

    private function importRow(string $date, string $amount): void
    {
        $cell = fn (string $v) => '"'.str_replace('"', '""', $v).'"';
        $file = $this->csv("\xEF\xBB\xBFDate,Title,Type,Category,Amount,Status,Payment method,Cash register,Description\r\n"
            .implode(',', [$cell($date), 'X', 'income', 'other', $cell($amount), 'Paid', 'cash', '', ''])."\r\n");

        $this->actingAs($this->admin('en'))->post(route('transactions.import.store'), ['file' => $file]);
    }

    /** @return array<string, array{0:string, 1:float|null}> */
    public static function amounts(): array
    {
        return [
            'plain' => ['1500', 1500.0],
            'dot decimal' => ['1500.5', 1500.5],
            'comma decimal' => ['750,5', 750.5],
            'comma thousands' => ['1,500', 1500.0],
            'comma thousands 12k' => ['12,000', 12000.0],
            'comma thousands dot decimal' => ['1,500.75', 1500.75],
            'space thousands comma decimal' => ['1 000,50', 1000.5],
            'nbsp thousands' => ["12\u{00A0}000", 12000.0],
            'dot thousands comma decimal' => ['1.000,50', 1000.5],
            'long spreadsheet decimal' => ['750.50000000000011', 750.5],
            'mixed thousands separators' => ['1,000.000', null],
            'bad grouping' => ['1,50,0', null],
            'text' => ['abc', null],
            'blank' => ['', null],
            'trailing nbsp' => ["100\u{00A0}", 100.0],
            'leading narrow nbsp' => ["\u{202F}1,500", 1500.0],
        ];
    }

    #[Test]
    #[DataProvider('amounts')]
    public function amounts_are_read_with_thousands_and_decimal_separators(string $raw, ?float $expected): void
    {
        $this->importRow('2026-02-03', $raw);

        if ($expected === null) {
            $this->assertSame(0, Transaction::query()->count(), "'{$raw}' must not import");

            return;
        }
        $this->assertEqualsWithDelta($expected, (float) Transaction::query()->sole()->amount, 0.001, $raw);
    }

    /** @return array<string, array{0:string, 1:string|null}> */
    public static function dates(): array
    {
        return [
            'iso' => ['2026-02-03', '2026-02-03'],
            'day first' => ['03/02/2026', '2026-02-03'],
            'day first no rollover' => ['31/02/2026', null],
            'iso no rollover' => ['2026-02-31', null],
            'iso datetime no rollover' => ['2026-02-30T10:00:00', null],
            'iso zulu no rollover' => ['2026-02-30T00:00:00.000Z', null],
            'iso datetime' => ['2026-02-28T10:00:00', '2026-02-28'],
            'iso space time no rollover' => ['2026-02-30 10:00:00', null],
            'text' => ['someday', null],
        ];
    }

    #[Test]
    #[DataProvider('dates')]
    public function impossible_dates_are_not_rolled_over(string $raw, ?string $expected): void
    {
        $this->importRow($raw, '100');

        // An unreadable date falls back to today, as it always has.
        $this->assertSame($expected ?? now()->format('Y-m-d'), Transaction::query()->sole()->transaction_date->format('Y-m-d'), $raw);
    }
}
