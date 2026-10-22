<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\ReceiptPrint;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\ReceiptData;
use App\Services\Finance\ReceiptPrinter;
use App\Services\Pdf\PdfService;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceiptPrintTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['email_verified_at' => now(), 'name' => 'Cashier One']);
    }

    private function player(): Player
    {
        $player = Player::create([
            'membership_id' => '8100000001',
            'firstname' => 'Yacine', 'lastname' => 'Brahimi',
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
        $player->branches()->attach(Branch::create(['name' => 'Football'])->id);

        return $player;
    }

    private function subscription(Player $player, float $owed = 10000): PlayerSubscription
    {
        return PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => null, 'transaction_id' => null,
            'label' => 'Annual fee', 'year' => (int) now()->year, 'status_at_time' => 'student',
            'is_mandatory' => true, 'amount_owed' => $owed, 'amount_paid' => 0,
        ]);
    }

    private function payment(Player $player, ?PlayerSubscription $line, float $amount, string $date): Transaction
    {
        return Transaction::create([
            'amount' => $amount,
            'transaction_date' => $date,
            'transaction_type' => 'income',
            'category' => 'subscription',
            'payment_method' => 'cash',
            'status' => 'Paid',
            'related_entity_type' => 'Player',
            'related_entity_id' => $player->id,
            'player_subscription_id' => $line?->id,
            'fiscal_year' => (int) substr($date, 0, 4),
        ]);
    }

    /** Prints the thermal ticket with mPDF swapped for a spy; returns the HTML it was given. */
    private function ticketHtml(Transaction $transaction): string
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('streamRoll')->once()->andReturnUsing(function (string $html) use ($seen) {
                $seen['html'] = $html;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        $this->actingAs($this->admin)->get(route('transactions.ticket', $transaction))->assertOk();

        return $seen['html'];
    }

    #[Test]
    public function the_ticket_is_a_real_80mm_pdf(): void
    {
        $player = $this->player();
        $transaction = $this->payment($player, $this->subscription($player), 3000, now()->toDateString());

        $response = $this->actingAs($this->admin)->get(route('transactions.ticket', $transaction));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        // 80mm = 226.77pt wide; the height follows the content.
        $this->assertMatchesRegularExpression('#/MediaBox \[0 0 226\.77\d* [\d.]+\]#', $response->getContent());
    }

    #[Test]
    public function the_ticket_shows_the_player_branch_subscription_balance_and_code(): void
    {
        $player = $this->player();
        $line = $this->subscription($player);
        $first = $this->payment($player, $line, 3000, '2026-09-01');
        // A later payment must not change what the first ticket says is left.
        $this->payment($player, $line, 2000, '2026-10-01');

        $html = $this->ticketHtml($first);
        $print = ReceiptPrint::sole();

        $this->assertStringContainsString($player->fullname, $html);
        $this->assertStringContainsString('8100000001', $html);
        $this->assertStringContainsString('Football', $html);
        $this->assertStringContainsString('Annual fee', $html);
        $this->assertStringContainsString('10,000.00', $html);   // due
        $this->assertStringContainsString('7,000.00', $html);    // remaining after this payment
        $this->assertStringContainsString(__('ORIGINAL'), $html);
        $this->assertStringContainsString('<barcode code="'.$print->code.'" type="QR"', $html);
        $this->assertStringContainsString($print->displayCode(), $html);
        $this->assertStringContainsString('Cashier One', $html);
    }

    #[Test]
    public function reprints_are_numbered_duplicates_but_a_quick_reload_is_not_a_new_copy(): void
    {
        $player = $this->player();
        $transaction = $this->payment($player, null, 500, now()->toDateString());
        $this->mock(PdfService::class, function (MockInterface $mock) {
            $mock->shouldReceive('streamRoll', 'stream')->andReturn(response('%PDF-spy'));
        });

        $this->actingAs($this->admin)->get(route('transactions.ticket', $transaction))->assertOk();
        $this->actingAs($this->admin)->get(route('transactions.ticket', $transaction))->assertOk();
        $this->assertSame(1, ReceiptPrint::count());

        $this->travel(2)->minutes();
        $this->actingAs($this->admin)->get(route('transactions.ticket', $transaction))->assertOk();
        // The A4 sheet shares the numbering: it is one more paper copy.
        $this->actingAs($this->admin)->get(route('transactions.receipt', $transaction))->assertOk();

        $this->assertSame([1, 2, 3], ReceiptPrint::orderBy('id')->pluck('copy_number')->all());
        $this->assertSame(['thermal', 'thermal', 'a4'], ReceiptPrint::orderBy('id')->pluck('format')->all());
        $this->assertSame(3, ReceiptPrint::distinct()->count('code'));
    }

    #[Test]
    public function verify_tells_valid_modified_cancelled_deleted_and_unknown_apart(): void
    {
        $player = $this->player();
        $transaction = $this->payment($player, null, 1500, now()->toDateString());
        $transaction->load(ReceiptData::RELATIONS);
        $print = app(ReceiptPrinter::class)->record($transaction, ReceiptPrint::FORMAT_THERMAL, $this->admin);

        $status = fn (string $code) => $this->actingAs($this->admin)
            ->get(route('receipts.verify', ['code' => $code]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Receipts/Verify'))
            ->inertiaProps('result');

        $this->assertSame('valid', $status($print->code)['status']);

        $transaction->update(['amount' => 150]);
        $result = $status($print->code);
        $this->assertSame('modified', $result['status']);
        $this->assertSame([['field' => 'amount', 'printed' => '1500.00', 'current' => '150.00']], $result['differences']);

        $transaction->update(['amount' => 1500, 'archived' => true]);
        $this->assertSame('cancelled', $status($print->code)['status']);

        $transaction->delete();
        $result = $status($print->code);
        $this->assertSame('deleted', $result['status']);
        $this->assertSame('1500.00', $result['snapshot']['amount']);

        $this->assertSame('unknown', $status('000000000000')['status']);
    }

    #[Test]
    public function verify_reads_codes_typed_by_a_scanner_on_an_azerty_or_arabic_keyboard(): void
    {
        $printer = app(ReceiptPrinter::class);

        $this->assertSame('482139771203', $printer->normalizeCode('4821 3977 1203'));
        $this->assertSame('482139771203', $printer->normalizeCode('4821-3977-1203'));
        // "4821 3977 1203" from a QWERTY scanner on an AZERTY layout.
        $this->assertSame('482139771203', $printer->normalizeCode("'_é& \"çèè &éà\""));
        $this->assertSame('482139771203', $printer->normalizeCode('٤٨٢١٣٩٧٧١٢٠٣'));
        $this->assertNull($printer->normalizeCode('123'));
    }

    #[Test]
    public function printing_and_verifying_need_the_transactions_view_right(): void
    {
        $player = $this->player();
        $transaction = $this->payment($player, null, 500, now()->toDateString());
        $coach = User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::create(['key' => 'coach', 'name' => ['en' => 'Coach'], 'permissions' => ['players' => Role::ACTIONS]])->id,
        ]);

        $this->actingAs($coach)->get(route('transactions.ticket', $transaction))->assertForbidden();
        $this->actingAs($coach)->get(route('receipts.verify'))->assertForbidden();
        $this->assertSame(0, ReceiptPrint::count());

        $this->assertSame(['transactions', 'view'], PermissionMap::resolve('transactions.ticket'));
        $this->assertSame(['transactions', 'view'], PermissionMap::resolve('receipts.verify'));
    }

    #[Test]
    public function the_transaction_page_lists_its_printed_copies(): void
    {
        $player = $this->player();
        $transaction = $this->payment($player, null, 500, now()->toDateString());
        $transaction->load(ReceiptData::RELATIONS);
        $print = app(ReceiptPrinter::class)->record($transaction, ReceiptPrint::FORMAT_THERMAL, $this->admin);

        $this->actingAs($this->admin)->get(route('transactions.show', $transaction))
            ->assertInertia(fn (Assert $page) => $page
                ->has('prints', 1)
                ->where('prints.0.copy_number', 1)
                ->where('prints.0.code', $print->displayCode())
                ->where('prints.0.printed_by', 'Cashier One'));
    }
}
