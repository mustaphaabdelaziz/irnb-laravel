<?php

namespace App\Http\Controllers;

use App\Models\PaymentOrder;
use App\Models\WebsiteConfig;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Pdf\BoardSignatories;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\AmountInWords;
use App\Support\UiLang;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The three A4 forms of a payment order, in the app language. */
class PaymentOrderPrintController extends Controller
{
    private const VIEWS = [
        'payment' => ['pdf.payment-order', 'ordre-de-paiement'],
        'transfer' => ['pdf.payment-order-transfer', 'ordre-de-virement'],
        'cash' => ['pdf.payment-order-cash', 'ordre-de-versement'],
    ];

    public function __construct(private readonly PdfService $pdf) {}

    public function show(Request $request, PaymentOrder $paymentOrder, string $form): Response
    {
        abort_unless(in_array($form, PaymentOrderController::printsFor($paymentOrder), true), 404);

        $paymentOrder->load('boardMeeting:id,meeting_date,pv_number');
        $locale = app()->getLocale();
        [$view, $slug] = self::VIEWS[$form];

        $html = view($view, [
            'club' => ClubHeader::data(),
            'rtl' => $locale === 'ar',
            'order' => $paymentOrder,
            'beneficiary' => $paymentOrder->beneficiaryLabel(),
            'words' => AmountInWords::format((float) $paymentOrder->amount, $locale),
            'method' => $paymentOrder->payment_method ? UiLang::get(self::methodKey($paymentOrder->payment_method)) : '—',
            'clubRib' => WebsiteConfig::singleton()->banking_info['rib'] ?? null,
            'signatories' => BoardSignatories::names(),
        ])->render();

        ActivityRecorder::record($request->user(), ActivityAction::PAYMENT_ORDER_PRINTED, $paymentOrder, [
            'reference' => $paymentOrder->reference, 'form' => $form,
        ]);

        return $this->pdf->stream($html, $slug.'-'.str_replace('/', '-', $paymentOrder->reference).'.pdf', $locale === 'ar');
    }

    /** The UI label key of a payment method (same map as resources/js/lib/statusLabels.js). */
    private static function methodKey(string $method): string
    {
        return ['bank' => 'bank_transfer', 'cash' => 'cash', 'ccp' => 'ccp', 'baridimob' => 'baridimob', 'cheque' => 'cheque'][$method] ?? 'other';
    }
}
