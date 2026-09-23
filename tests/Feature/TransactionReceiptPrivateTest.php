<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionReceiptPrivateTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_24_100005_move_transaction_receipts_to_private_disk.php';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        // firstOrCreate: the finance migrations already seed a 'Donations' income category.
        $category = FinanceCategory::firstOrCreate(['type' => 'income', 'name' => 'Donations'], ['is_active' => true]);

        return [
            'title' => 'Donation',
            'transaction_type' => 'income',
            'finance_category_id' => $category->id,
            'amount' => 500,
            'transaction_date' => now()->toDateString(),
            'status' => 'Paid',
            ...$overrides,
        ];
    }

    private function transactionWithReceipt(string $name = 'receipt.pdf', string $mime = 'application/pdf'): Transaction
    {
        $this->actingAs($this->admin())->post(route('transactions.store'), $this->payload([
            'receipt' => UploadedFile::fake()->create($name, 100, $mime),
        ]))->assertRedirect()->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    private function transaction(array $attributes = []): Transaction
    {
        return Transaction::create([
            'amount' => 100,
            'transaction_date' => now(),
            'transaction_type' => 'income',
            'category' => 'donations',
            'status' => 'Paid',
            'fiscal_year' => now()->year,
            ...$attributes,
        ]);
    }

    #[Test]
    public function an_uploaded_receipt_goes_to_the_private_disk(): void
    {
        $transaction = $this->transactionWithReceipt();

        $this->assertStringStartsWith('receipts/', $transaction->receipt_filename);
        Storage::disk('local')->assertExists($transaction->receipt_filename);
        Storage::disk('public')->assertMissing($transaction->receipt_filename);
        $this->assertNull($transaction->getAttributes()['receipt_url']);
        $this->assertSame(route('transactions.receipt-file.show', $transaction, false), $transaction->receipt_url);
    }

    #[Test]
    public function replacing_a_receipt_removes_the_previous_file(): void
    {
        $transaction = $this->transactionWithReceipt('first.pdf');
        $first = $transaction->receipt_filename;

        $this->actingAs($this->admin())->put(route('transactions.update', $transaction), $this->payload([
            'receipt' => UploadedFile::fake()->create('second.pdf', 100, 'application/pdf'),
        ]))->assertRedirect()->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($transaction->fresh()->receipt_filename);
    }

    #[Test]
    public function a_pdf_or_image_receipt_opens_inline_for_a_transactions_viewer(): void
    {
        $transaction = $this->transactionWithReceipt();

        $response = $this->actingAs($this->admin())->get(route('transactions.receipt-file.show', $transaction));

        $response->assertOk();
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    #[Test]
    public function any_other_file_type_is_downloaded_never_shown(): void
    {
        $transaction = $this->transactionWithReceipt('page.html', 'text/html');

        $response = $this->actingAs($this->admin())->get(route('transactions.receipt-file.show', $transaction));

        $response->assertOk();
        $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function the_receipt_needs_a_login_and_transactions_rights(): void
    {
        $transaction = $this->transactionWithReceipt();

        // Back to a guest.
        auth()->forgetGuards();
        $this->get(route('transactions.receipt-file.show', $transaction))->assertRedirect(route('login'));

        $coach = User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::create(['key' => 'coach', 'name' => ['en' => 'Coach'], 'permissions' => ['players' => Role::ACTIONS]])->id,
        ]);
        $this->actingAs($coach)->get(route('transactions.receipt-file.show', $transaction))->assertForbidden();

        $this->assertSame(['transactions', 'view'], PermissionMap::resolve('transactions.receipt-file.show'));
    }

    #[Test]
    public function the_public_media_route_no_longer_serves_receipts(): void
    {
        $transaction = $this->transactionWithReceipt();

        $this->get('/media/'.$transaction->receipt_filename)->assertNotFound();
    }

    #[Test]
    public function a_transaction_without_a_receipt_has_nothing_to_show(): void
    {
        $this->actingAs($this->admin())
            ->get(route('transactions.receipt-file.show', $this->transaction()))
            ->assertNotFound();
    }

    #[Test]
    public function the_migration_moves_existing_receipts_off_the_public_disk(): void
    {
        Storage::disk('public')->put('receipts/old.pdf', 'OLD-RECEIPT');
        Storage::disk('public')->put('receipts/legacy.jpg', 'LEGACY-RECEIPT');
        Storage::disk('local')->put('receipts/half.pdf', 'HA'); // an interrupted earlier copy
        Storage::disk('public')->put('receipts/half.pdf', 'HALF-RECEIPT');

        $current = $this->transaction(['receipt_url' => '/media/receipts/old.pdf', 'receipt_filename' => 'receipts/old.pdf']);
        // An imported row: absolute URL, and a filename that is not a disk path.
        $legacy = $this->transaction(['receipt_url' => 'http://localhost:8000/storage/receipts/legacy.jpg', 'receipt_filename' => 'IMG_2044.jpg']);
        $half = $this->transaction(['receipt_url' => '/media/receipts/half.pdf', 'receipt_filename' => 'receipts/half.pdf']);
        // Gone from every disk: left exactly as it was.
        $gone = $this->transaction(['receipt_url' => '/media/receipts/gone.pdf', 'receipt_filename' => 'receipts/gone.pdf']);
        // A genuinely external link: not ours to move.
        $external = $this->transaction(['receipt_url' => 'https://drive.example.com/r/123', 'receipt_filename' => null]);

        $migration = require database_path(self::MIGRATION);
        $migration->up();
        // Desktop: a re-run after a partial failure must be harmless.
        $migration->up();

        $this->assertSame('OLD-RECEIPT', Storage::disk('local')->get('receipts/old.pdf'));
        $this->assertSame('LEGACY-RECEIPT', Storage::disk('local')->get('receipts/legacy.jpg'));
        $this->assertSame('HALF-RECEIPT', Storage::disk('local')->get('receipts/half.pdf'));
        foreach (['receipts/old.pdf', 'receipts/legacy.jpg', 'receipts/half.pdf'] as $path) {
            Storage::disk('public')->assertMissing($path);
        }

        $rows = DB::table('transactions')->get()->keyBy('id');
        foreach ([$current, $half] as $transaction) {
            $this->assertNull($rows[$transaction->id]->receipt_url);
        }
        $this->assertSame('receipts/legacy.jpg', $rows[$legacy->id]->receipt_filename);
        $this->assertNull($rows[$legacy->id]->receipt_url);
        $this->assertSame('/media/receipts/gone.pdf', $rows[$gone->id]->receipt_url);
        $this->assertSame('https://drive.example.com/r/123', $rows[$external->id]->receipt_url);
        $this->assertSame('https://drive.example.com/r/123', $external->fresh()->receipt_url, 'an external link is still offered as is');
    }
}
