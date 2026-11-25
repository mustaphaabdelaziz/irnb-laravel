<?php

namespace Tests\Feature;

use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Documents\YearlySequence;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Legacy\LegacyAccessImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LegacyAccessImportTest extends TestCase
{
    use RefreshDatabase;

    private function data(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/legacy-access.json')), true);
    }

    #[Test]
    public function suppliers_and_orders_are_imported_as_history(): void
    {
        $result = app(LegacyAccessImporter::class)->import($this->data(), User::factory()->admin()->create());

        $this->assertSame(['created' => 2, 'skipped' => 1], $result['suppliers']);
        $this->assertSame(['created' => 3, 'skipped' => 0, 'merged' => 1], $result['payment_orders']);
        $this->assertSame('00100000000000001234', Supplier::where('name', 'Test Journal')->value('rib'));

        $order = PaymentOrder::where('legacy_reference', '01/2021')->firstOrFail();
        $this->assertTrue($order->is_legacy);
        $this->assertNull($order->number);
        $this->assertSame(2021, $order->year);
        $this->assertSame(PaymentOrder::PAID, $order->status);
        $this->assertSame('bank', $order->payment_method);
        $this->assertSame('PUBLICATION DU BUREAU', $order->observation);
        $this->assertSame('01/2021', $order->reference);
        // Its beneficiary is a known supplier: the order is linked to it.
        $this->assertSame('supplier', $order->beneficiary_type);
        $this->assertSame(Supplier::where('name', 'Test Journal')->value('id'), $order->beneficiary_id);
        $this->assertSame('Test Journal', $order->beneficiaryLabel());

        // The Arabic payment order and the cash order of the same expense are one order.
        $merged = PaymentOrder::whereDate('order_date', '2022-10-17')->get();
        $this->assertCount(1, $merged);
        $this->assertSame('01/2022', $merged->first()->reference);
        $this->assertSame('Test Trésorier', $merged->first()->beneficiary_name);

        // History only: no money moves.
        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function importing_twice_changes_nothing(): void
    {
        $importer = app(LegacyAccessImporter::class);
        $admin = User::factory()->admin()->create();
        $importer->import($this->data(), $admin);

        $second = $importer->import($this->data(), $admin);

        $this->assertSame(['created' => 0, 'skipped' => 3], $second['suppliers']);
        $this->assertSame(['created' => 0, 'skipped' => 4, 'merged' => 0], $second['payment_orders']);
        $this->assertSame(2, Supplier::count());
        $this->assertSame(3, PaymentOrder::count());
    }

    #[Test]
    public function legacy_orders_never_take_a_sequence_number(): void
    {
        app(LegacyAccessImporter::class)->import($this->data(), User::factory()->admin()->create());

        $this->assertSame(0, PaymentOrder::whereNotNull('number')->count());
    }

    #[Test]
    public function the_old_purchase_order_is_imported_as_received_history(): void
    {
        $result = app(LegacyAccessImporter::class)->import($this->data(), User::factory()->admin()->create());

        $this->assertSame(['created' => 1, 'skipped' => 0], $result['purchase_orders']);
        $order = PurchaseOrder::firstOrFail();
        $this->assertTrue($order->is_legacy);
        $this->assertSame('purchase', $order->legacy_source);
        $this->assertNull($order->number);
        $this->assertSame(2021, $order->year);
        $this->assertSame('01/2021', $order->reference);
        $this->assertSame(PurchaseOrder::RECEIVED, $order->status);
        $this->assertNull($order->supplier_id);
        $this->assertSame('Section football', $order->requested_by);
        $this->assertSame('cheque', $order->payment_mode);
        $this->assertStringContainsString('N° Ordre : 12', $order->observation);
        $this->assertCount(1, $order->lines);
        $this->assertSame('Caméra de surveillance', $order->lines[0]->designation);
        $this->assertSame(1, $order->lines[0]->quantity);
        $this->assertNull($order->lines[0]->unit_price);
        // Not linked to the old payment order 01/2021: that one is another expense.
        $this->assertSame(0, PaymentOrder::whereNotNull('purchase_order_id')->count());
        $this->assertFalse($order->isEditable());
        $this->assertFalse($order->canEditPrices());
        $this->assertDatabaseHas('activity_logs', ['action' => 'legacy_data_imported']);
    }

    #[Test]
    public function importing_the_purchase_order_twice_changes_nothing(): void
    {
        $importer = app(LegacyAccessImporter::class);
        $admin = User::factory()->admin()->create();
        $importer->import($this->data(), $admin);

        $second = $importer->import($this->data(), $admin);

        $this->assertSame(['created' => 0, 'skipped' => 1], $second['purchase_orders']);
        $this->assertSame(1, PurchaseOrder::count());
        $this->assertSame(1, DB::transaction(fn () => YearlySequence::next('purchase_orders', 2021)));
    }

    #[Test]
    public function a_file_without_purchase_orders_still_imports(): void
    {
        $data = $this->data();
        unset($data['purchase_orders']);

        $result = app(LegacyAccessImporter::class)->import($data, User::factory()->admin()->create());

        $this->assertSame(['created' => 0, 'skipped' => 0], $result['purchase_orders']);
        $this->assertSame(['created' => 3, 'skipped' => 0, 'merged' => 1], $result['payment_orders']);
        $this->assertSame(0, PurchaseOrder::count());
    }

    #[Test]
    public function a_known_supplier_is_linked_and_bad_purchase_rows_are_skipped(): void
    {
        $result = app(LegacyAccessImporter::class)->import([
            'format' => 'access-legacy-v1',
            'suppliers' => [['name' => 'Test Journal']],
            'purchase_orders' => [
                ['reference' => '02/2021', 'date' => '2021-06-01', 'supplier' => 'TEST JOURNAL', 'payment_mode' => 'Virement',
                    'lines' => [['designation' => 'Affiches', 'quantity' => '3', 'unit_price' => '2500']]],
                ['reference' => '03/2021', 'date' => '', 'lines' => [['designation' => 'Sans date', 'quantity' => 1]]],
                ['reference' => '04/2021', 'date' => '2021-06-03', 'lines' => []],
                ['reference' => '05/2021', 'date' => '2021-06-04', 'supplier' => 'Unknown Shop', 'address' => '1 rue Test',
                    'lines' => [['designation' => '', 'reference' => 'REF-9', 'quantity' => 0]]],
                'oops',
            ],
        ], User::factory()->admin()->create());

        $this->assertSame(['created' => 2, 'skipped' => 3], $result['purchase_orders']);
        $linked = PurchaseOrder::where('legacy_reference', '02/2021')->firstOrFail();
        $this->assertSame(Supplier::where('name', 'Test Journal')->value('id'), $linked->supplier_id);
        $this->assertSame('bank', $linked->payment_mode);
        $this->assertSame(3, $linked->lines[0]->quantity);
        $this->assertSame('2500.00', $linked->lines[0]->unit_price);
        $this->assertSame('7500.00', $linked->lines[0]->amount);
        // An unknown supplier stays readable in the observation; a line named only by its reference keeps it.
        $unknown = PurchaseOrder::where('legacy_reference', '05/2021')->firstOrFail();
        $this->assertNull($unknown->supplier_id);
        $this->assertStringContainsString('Fournisseur : Unknown Shop', $unknown->observation);
        $this->assertStringContainsString('Adresse : 1 rue Test', $unknown->observation);
        $this->assertSame('REF-9', $unknown->lines[0]->designation);
        $this->assertSame(1, $unknown->lines[0]->quantity);
    }

    #[Test]
    public function only_a_superadmin_uploads_the_file(): void
    {
        $file = UploadedFile::fake()->createWithContent('legacy.json', file_get_contents(base_path('tests/Fixtures/legacy-access.json')));

        $this->actingAs(User::factory()->create(['privileges' => ['user']]))
            ->post(route('legacy-import.store'), ['file' => $file])->assertForbidden();

        $this->actingAs(User::factory()->create(['privileges' => ['superadmin']]))
            ->post(route('legacy-import.store'), ['file' => $file])
            ->assertSessionHas('legacyResult');
        $this->assertSame(3, PaymentOrder::count());
    }

    #[Test]
    public function a_file_of_another_format_is_refused(): void
    {
        $file = UploadedFile::fake()->createWithContent('other.json', '{"format":"something-else"}');

        $this->actingAs(User::factory()->create(['privileges' => ['superadmin']]))
            ->post(route('legacy-import.store'), ['file' => $file])
            ->assertSessionHasErrors('file');
    }

    #[Test]
    public function rows_with_no_supplier_name_or_an_unknown_source_count_as_skipped(): void
    {
        $result = app(LegacyAccessImporter::class)->import([
            'format' => 'access-legacy-v1',
            'suppliers' => [['name' => '  ', 'rib' => '123'], ['name' => 'Kept']],
            'payment_orders' => [
                ['source' => 'invoice', 'reference' => '01/2021', 'date' => '2021-06-01', 'subject' => 'X', 'amount' => 100],
                ['source' => 'cash', 'reference' => '02', 'date' => '2021-06-02', 'subject' => 'Y', 'amount' => 200, 'beneficiary' => 'Nobody'],
            ],
        ], User::factory()->admin()->create());

        $this->assertSame(['created' => 1, 'skipped' => 1], $result['suppliers']);
        $this->assertSame(['created' => 1, 'skipped' => 1, 'merged' => 0], $result['payment_orders']);
        // A beneficiary that is no known supplier stays a plain name.
        $this->assertNull(PaymentOrder::firstOrFail()->beneficiary_type);
    }

    #[Test]
    public function a_malformed_file_never_crashes_and_good_rows_still_import(): void
    {
        $payload = [
            'format' => 'access-legacy-v1',
            'suppliers' => 'oops',
            'payment_orders' => [
                'not-a-row',
                ['source' => 'transfer', 'reference' => '05/2021', 'date' => '31/02/2021x', 'subject' => 'Bad date', 'amount' => 100],
                ['source' => 'transfer', 'reference' => '06/2021', 'date' => '2021-06-01', 'subject' => 'Bad amount', 'amount' => 'abc'],
                ['source' => 'transfer', 'reference' => '07/2021', 'date' => '2021-06-02', 'subject' => 'Good', 'amount' => 500, 'beneficiary' => ['x']],
            ],
        ];
        $file = UploadedFile::fake()->createWithContent('bad.json', json_encode($payload));

        $this->actingAs(User::factory()->create(['privileges' => ['superadmin']]))
            ->post(route('legacy-import.store'), ['file' => $file])
            ->assertSessionHas('legacyResult')
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PaymentOrder::count());
        $result = session('legacyResult');
        $this->assertSame(['created' => 0, 'skipped' => 0], $result['suppliers']);
        $this->assertSame(['created' => 1, 'skipped' => 3, 'merged' => 0], $result['payment_orders']);
    }
}
