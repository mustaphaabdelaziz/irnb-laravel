<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Pdf\BoardSignatories;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\AmountInWords;
use App\Support\UiLang;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** "Bon de commande" / «سند طلب» on A4, in the app language. */
class PurchaseOrderPrintController extends Controller
{
    /** The UI label key of a payment method (same map as resources/js/lib/statusLabels.js). */
    private const METHOD_KEYS = ['bank' => 'bank_transfer', 'cash' => 'cash', 'ccp' => 'ccp', 'baridimob' => 'baridimob', 'cheque' => 'cheque'];

    public function __construct(private readonly PdfService $pdf) {}

    public function show(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        abort_if($purchaseOrder->status === PurchaseOrder::CANCELLED, 404);

        $purchaseOrder->load(['supplier:id,name,address,phone', 'lines']);
        $locale = app()->getLocale();
        $priced = $purchaseOrder->isFullyPriced();
        $total = $purchaseOrder->total();

        $html = view('pdf.purchase-order', [
            'club' => ClubHeader::data(),
            'rtl' => $locale === 'ar',
            'order' => $purchaseOrder,
            'priced' => $priced,
            'total' => $total,
            // Total and amount in words only when every line has a price.
            'words' => $priced ? AmountInWords::format($total, $locale) : null,
            'method' => $purchaseOrder->payment_mode ? UiLang::get(self::METHOD_KEYS[$purchaseOrder->payment_mode] ?? 'other') : '—',
            'signatories' => BoardSignatories::names(),
        ])->render();

        ActivityRecorder::record($request->user(), ActivityAction::PURCHASE_ORDER_PRINTED, $purchaseOrder, [
            'reference' => $purchaseOrder->reference,
        ]);

        return $this->pdf->stream($html, 'bon-de-commande-'.str_replace('/', '-', $purchaseOrder->reference).'.pdf', $locale === 'ar');
    }
}
