<?php

namespace Tests\Feature;

use App\Models\PaymentOrder;
use App\Models\Supplier;
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
    public function a_malformed_file_never_crashes_and_good_rows_still_import(): void
    {
        $payload = [
            'format' => 'irnb-access-v1',
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
