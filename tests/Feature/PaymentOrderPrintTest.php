<?php

namespace Tests\Feature;

use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\PaymentOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Pdf\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentOrderPrintTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $extra = []): PaymentOrder
    {
        $supplier = Supplier::create(['name' => 'Journal El Massa', 'bank_name' => 'BNA 611', 'rib' => '00100000000000001234']);

        return PaymentOrder::create(array_merge([
            'year' => 2026, 'number' => 7, 'status' => PaymentOrder::APPROVED, 'order_date' => '2026-11-25',
            'subject' => 'Publication renouvellement du bureau', 'amount' => 45470, 'payment_method' => 'bank',
            'beneficiary_type' => 'supplier', 'beneficiary_id' => $supplier->id, 'beneficiary_name' => 'Journal El Massa',
            'beneficiary_bank' => 'BNA 611', 'beneficiary_rib' => '00100000000000001234', 'payment_reference' => 'VIR-07',
        ], $extra));
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
    public function the_payment_order_is_a_real_pdf_in_arabic(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->user('ar'))->get(route('payment-orders.print', ['paymentOrder' => $order, 'form' => 'payment']))->assertOk();

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    #[Test]
    public function the_payment_order_shows_number_subject_amount_words_beneficiary_and_signatures(): void
    {
        BoardMember::create(['board_term_id' => BoardTerm::current()?->id, 'name' => 'Président Test', 'role' => 'president', 'status' => 'active']);
        BoardMember::create(['board_term_id' => BoardTerm::current()?->id, 'name' => 'Trésorier Test', 'role' => 'treasurer', 'status' => 'active']);
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('payment-orders.print', ['paymentOrder' => $this->order(), 'form' => 'payment']))->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertSame('ordre-de-paiement-07-2026.pdf', $seen['filename']);
        foreach (['07/2026', 'Publication renouvellement du bureau', 'quarante-cinq mille quatre cent soixante-dix dinars',
            'Journal El Massa', '00100000000000001234', 'Président Test', 'Trésorier Test'] as $text) {
            $this->assertStringContainsString($text, $seen['html'], $text);
        }
    }

    #[Test]
    public function the_transfer_order_needs_a_bank_or_ccp_method(): void
    {
        $this->actingAs($this->user())->get(route('payment-orders.print', ['paymentOrder' => $this->order(['payment_method' => 'cash']), 'form' => 'transfer']))
            ->assertNotFound();
    }

    #[Test]
    public function the_cash_order_prints_for_a_cash_order(): void
    {
        $seen = $this->spyPdf();

        $this->actingAs($this->user())->get(route('payment-orders.print', ['paymentOrder' => $this->order(['payment_method' => 'cash']), 'form' => 'cash']))->assertOk();

        $this->assertStringContainsString('Journal El Massa', $seen['html']);
    }

    #[Test]
    public function a_draft_prints_with_a_watermark_and_a_cancelled_order_does_not_print(): void
    {
        $seen = $this->spyPdf();
        $this->actingAs($this->user())->get(route('payment-orders.print', ['paymentOrder' => $this->order(['status' => PaymentOrder::DRAFT]), 'form' => 'payment']))->assertOk();
        $this->assertStringContainsString('BROUILLON', $seen['html']);

        $cancelled = PaymentOrder::create([
            'year' => 2026, 'number' => 8, 'status' => PaymentOrder::CANCELLED, 'order_date' => '2026-11-25', 'subject' => 'X', 'amount' => 1,
        ]);
        $this->get(route('payment-orders.print', ['paymentOrder' => $cancelled, 'form' => 'payment']))->assertNotFound();
    }

    #[Test]
    public function printing_needs_only_the_view_right_and_is_logged(): void
    {
        $this->spyPdf();
        $role = Role::factory()->create(['permissions' => ['payment_orders' => ['view']]]);
        $viewer = User::factory()->create(['privileges' => ['user'], 'role_id' => $role->id]);
        $order = $this->order();

        $this->actingAs($viewer)->get(route('payment-orders.print', ['paymentOrder' => $order, 'form' => 'payment']))->assertOk();

        $this->assertDatabaseHas('activity_logs', ['action' => ActivityAction::PAYMENT_ORDER_PRINTED, 'subject_id' => $order->id]);
    }
}
