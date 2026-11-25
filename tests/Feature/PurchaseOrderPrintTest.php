<?php

namespace Tests\Feature;

use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Pdf\PdfService;
use App\Support\AmountInWords;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchaseOrderPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @param list<array{0: string, 1: int, 2: ?float}>|null $lines designation, quantity, unit price */
    private function order(string $status = PurchaseOrder::SENT, ?array $lines = null): PurchaseOrder
    {
        $supplier = Supplier::create([
            'name' => 'Test Sport Shop', 'address' => '12 rue des Sports, Testville', 'phone' => '0550000000',
            'rib' => '00100000000000001234',
        ]);
        $order = PurchaseOrder::create([
            'year' => 2026, 'number' => 3, 'status' => $status, 'order_date' => '2026-11-25', 'supplier_id' => $supplier->id,
            'requested_by' => 'Section football', 'delivery_mode' => 'Livraison au club', 'payment_mode' => 'cheque',
            'observation' => "Livrer avant le match\nAppeler la veille",
        ]);
        foreach ($lines ?? [['Ballon taille 5', 10, 1500.0], ['Gonfleur', 2, 750.0]] as $i => [$designation, $quantity, $price]) {
            $order->lines()->create([
                'designation' => $designation, 'quantity' => $quantity, 'unit_price' => $price,
                'amount' => PurchaseOrderLine::amountFor($quantity, $price), 'sort_order' => $i + 1,
            ]);
        }

        return $order;
    }

    private function user(string $locale = 'fr'): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function spyPdf(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $html, string $filename, bool $rtl = true) use ($seen) {
                $seen['html'] = $html;
                $seen['filename'] = $filename;
                $seen['rtl'] = $rtl;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        return $seen;
    }

    #[Test]
    public function the_purchase_order_is_a_real_pdf_in_arabic(): void
    {
        $order = $this->order(PurchaseOrder::DRAFT);

        $response = $this->actingAs($this->user('ar'))->get(route('purchase-orders.print', $order))->assertOk();

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function the_french_print_shows_the_header_lines_total_words_and_signatures(): void
    {
        BoardMember::create(['board_term_id' => BoardTerm::current()?->id, 'name' => 'Président Test', 'role' => 'president', 'status' => 'active']);
        BoardMember::create(['board_term_id' => BoardTerm::current()?->id, 'name' => 'Trésorier Test', 'role' => 'treasurer', 'status' => 'active']);
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('purchase-orders.print', $this->order()))->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertSame('bon-de-commande-03-2026.pdf', $seen['filename']);
        foreach ([
            UiLang::get('pur.pdf.title', null, 'fr'), '03/2026', '25/11/2026', 'Test Sport Shop', '12 rue des Sports, Testville', '0550000000',
            'Section football', 'Livraison au club', UiLang::get('cheque', null, 'fr'), 'Ballon taille 5', 'Gonfleur',
            '1 500,00', '15 000,00', '16 500,00', AmountInWords::format(16500.0, 'fr'),
            'Livrer avant le match<br />', 'Président Test', 'Trésorier Test',
        ] as $text) {
            $this->assertStringContainsString($text, $seen['html'], $text);
        }
        $this->assertStringNotContainsString(UiLang::get('po.pdf.draft', null, 'fr'), $seen['html']);
    }

    #[Test]
    public function a_draft_carries_the_draft_banner(): void
    {
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('purchase-orders.print', $this->order(PurchaseOrder::DRAFT)))->assertOk();

        $this->assertStringContainsString(UiLang::get('po.pdf.draft', null, 'fr'), $seen['html']);
    }

    #[Test]
    public function an_unpriced_order_prints_blank_prices_and_no_total_in_words(): void
    {
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('purchase-orders.print', $this->order(PurchaseOrder::SENT, [
            ['Caméra de surveillance', 1, null], ['Câble', 3, 200.0],
        ])))->assertOk();

        $this->assertStringContainsString('Caméra de surveillance', $seen['html']);
        $this->assertStringContainsString('600,00', $seen['html']);
        $this->assertStringNotContainsString(UiLang::get('po.pdf.amount_words', null, 'fr'), $seen['html']);
        $this->assertStringNotContainsString(AmountInWords::format(600.0, 'fr'), $seen['html']);
    }

    #[Test]
    public function a_cancelled_order_does_not_print(): void
    {
        $this->actingAs($this->user())->get(route('purchase-orders.print', $this->order(PurchaseOrder::CANCELLED)))->assertNotFound();
    }

    #[Test]
    public function a_view_only_user_prints_and_the_print_is_logged(): void
    {
        $order = $this->order();
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'preferred_lng' => 'fr',
            'role_id' => Role::factory()->create(['permissions' => ['purchase_orders' => ['view']]])->id,
        ]);

        $this->actingAs($viewer)->get(route('purchase-orders.print', $order))->assertOk();

        $this->assertDatabaseHas('activity_logs', ['action' => ActivityAction::PURCHASE_ORDER_PRINTED, 'subject_id' => $order->id]);
    }
}
