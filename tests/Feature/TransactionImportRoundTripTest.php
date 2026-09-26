<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
}
