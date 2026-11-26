<?php

namespace Tests\Feature;

use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Documents\YearlySequence;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Legacy\LegacyAccessImporter;
use App\Services\Secretariat\MissionOrders;
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

    #[Test]
    public function the_old_letters_are_imported_as_outgoing_history(): void
    {
        $result = app(LegacyAccessImporter::class)->import($this->data(), User::factory()->admin()->create());

        $this->assertSame(['created' => 3, 'skipped' => 1], $result['mail']);

        $printed = MailEntry::where('legacy_reference', '2019/01')->sole();
        $this->assertTrue($printed->is_legacy);
        $this->assertSame('corresponds', $printed->legacy_source);
        $this->assertSame(MailEntry::OUT, $printed->direction);
        $this->assertSame(MailEntry::ACTIVE, $printed->status);
        $this->assertNull($printed->number);
        $this->assertSame(2019, $printed->year);
        $this->assertSame('2019/01', $printed->reference);
        $this->assertSame('2019-01-15', $printed->entry_date->toDateString());
        $this->assertSame('Test Ligue de Wilaya', $printed->correspondent);
        // «طبعت» (printed) was a status typed in the address column: it moves to the notes.
        $this->assertNull($printed->address);
        $this->assertSame('طبعت', $printed->notes);
        // Read-only history: only notes (and a scan) can be added.
        $this->assertSame(['notes'], $printed->editableFields());

        $undated = MailEntry::where('legacy_reference', '2019/02')->sole();
        $this->assertNull($undated->entry_date);
        $this->assertSame(2019, $undated->year);
        $this->assertSame('1 rue Test, Testville', $undated->address);
        $this->assertNull($undated->notes);

        // Old letters never take a sequence number.
        $this->assertSame(0, MailEntry::whereNotNull('number')->count());
        $this->assertSame(1, DB::transaction(fn () => YearlySequence::next('mail_entries', 2019, ['direction' => MailEntry::OUT])));
        $this->assertDatabaseHas('activity_logs', ['action' => 'legacy_data_imported']);
    }

    #[Test]
    public function importing_the_letters_twice_changes_nothing(): void
    {
        $importer = app(LegacyAccessImporter::class);
        $admin = User::factory()->admin()->create();
        $importer->import($this->data(), $admin);

        $second = $importer->import($this->data(), $admin);

        $this->assertSame(['created' => 0, 'skipped' => 4], $second['mail']);
        $this->assertSame(3, MailEntry::count());
    }

    #[Test]
    public function a_file_without_letters_still_imports(): void
    {
        $data = $this->data();
        unset($data['mail']);

        $result = app(LegacyAccessImporter::class)->import($data, User::factory()->admin()->create());

        $this->assertSame(['created' => 0, 'skipped' => 0], $result['mail']);
        $this->assertSame(['created' => 3, 'skipped' => 0, 'merged' => 1], $result['payment_orders']);
        $this->assertSame(0, MailEntry::count());
    }

    #[Test]
    public function bad_letter_rows_are_skipped_and_a_reference_alone_still_imports(): void
    {
        $result = app(LegacyAccessImporter::class)->import([
            'format' => 'access-legacy-v1',
            'mail' => [
                'oops',
                ['reference' => '  2018/7 ', 'correspondent' => '', 'address' => '', 'date' => 'not a date'],
                // An address alone (here the "printed" status) is no letter.
                ['reference' => '', 'correspondent' => '', 'address' => 'طبعت', 'date' => null],
            ],
        ], User::factory()->admin()->create());

        $this->assertSame(['created' => 1, 'skipped' => 2], $result['mail']);
        $row = MailEntry::sole();
        $this->assertSame('2018/7', $row->legacy_reference);
        $this->assertSame(2018, $row->year);
        $this->assertNull($row->entry_date);
        $this->assertNull($row->correspondent);
    }

    #[Test]
    public function the_old_mission_orders_are_imported_as_read_only_history(): void
    {
        $result = app(LegacyAccessImporter::class)->import($this->data(), User::factory()->admin()->create());

        $this->assertSame(['created' => 2, 'skipped' => 1], $result['missions']);

        $first = MissionOrder::where('legacy_reference', '59')->sole();
        $this->assertTrue($first->is_legacy);
        $this->assertSame(MissionOrder::LEGACY_SOURCE, $first->legacy_source);
        $this->assertSame(MissionOrder::ACTIVE, $first->status);
        $this->assertFalse($first->isEditable());
        // Access kept the dates as text, day first.
        $this->assertSame('2018-05-12', $first->order_date->toDateString());
        $this->assertSame('2018-05-13', $first->departure_date->toDateString());
        $this->assertSame('2018-05-15', $first->return_date->toDateString());
        $this->assertSame('Testville', $first->destination);
        $this->assertSame('Stage de formation', $first->reason);
        $this->assertSame('Bus', $first->transport);
        $traveler = $first->travelers()->sole();
        $this->assertNull($traveler->traveler_type);
        $this->assertSame('Testeur Karim', $traveler->name);
        $this->assertSame('Entraîneur', $traveler->function);
        $this->assertSame('59', app(MissionOrders::class)->reference($first));

        $second = MissionOrder::where('legacy_reference', '60')->sole();
        $this->assertSame('2018-06-02', $second->order_date->toDateString());
        $this->assertNull($second->departure_date);
        $this->assertNull($second->return_date);

        // The old letters are already in the imported mail register: no entry for the missions.
        $this->assertSame(0, MailEntry::where('source_type', MailEntry::SOURCE_MISSION)->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'legacy_data_imported']);
    }

    #[Test]
    public function importing_the_mission_orders_twice_changes_nothing(): void
    {
        $importer = app(LegacyAccessImporter::class);
        $admin = User::factory()->admin()->create();
        $importer->import($this->data(), $admin);

        $second = $importer->import($this->data(), $admin);

        $this->assertSame(['created' => 0, 'skipped' => 3], $second['missions']);
        $this->assertSame(2, MissionOrder::count());
    }

    #[Test]
    public function a_file_without_mission_orders_still_imports(): void
    {
        $data = $this->data();
        unset($data['missions']);

        $result = app(LegacyAccessImporter::class)->import($data, User::factory()->admin()->create());

        $this->assertSame(['created' => 0, 'skipped' => 0], $result['missions']);
        $this->assertSame(['created' => 3, 'skipped' => 1], $result['mail']);
        $this->assertSame(0, MissionOrder::count());
    }

    #[Test]
    public function odd_mission_rows_are_read_defensively(): void
    {
        $result = app(LegacyAccessImporter::class)->import([
            'format' => 'access-legacy-v1',
            'missions' => [
                'oops',
                // A reference alone still imports; an impossible date stays empty; a nameless traveler is a dash.
                ['reference' => ' 61 ', 'date' => '31/02/2018'],
                // Two-digit year and dots.
                ['reference' => '62', 'date' => '05.07.18', 'lastname' => 'Test', 'destination' => 'Testville', 'departure_date' => '2018-07-06 00:00:00'],
                ['reference' => '', 'date' => '', 'lastname' => '', 'firstname' => '', 'destination' => ''],
            ],
        ], User::factory()->admin()->create());

        $this->assertSame(['created' => 2, 'skipped' => 2], $result['missions']);
        $alone = MissionOrder::where('legacy_reference', '61')->sole();
        $this->assertNull($alone->order_date);
        $this->assertSame('—', $alone->travelers()->sole()->name);
        $dotted = MissionOrder::where('legacy_reference', '62')->sole();
        $this->assertSame('2018-07-05', $dotted->order_date->toDateString());
        $this->assertSame('2018-07-06', $dotted->departure_date->toDateString());
        $this->assertSame('Test', $dotted->travelers()->sole()->name);
    }
}
