<?php

namespace App\Http\Controllers;

use App\Models\ReceiptPrint;
use App\Models\Transaction;
use App\Services\Finance\ReceiptData;
use App\Services\Finance\ReceiptPrinter;
use App\Services\Pdf\PdfService;
use App\Support\TransactionTitle;
use App\Support\UiLang;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Printed payment receipts (A4 sheet and 80mm thermal ticket) and the page
 * that checks a paper receipt against the books by its code.
 */
class ReceiptController extends Controller
{
    public function __construct(
        private readonly PdfService $pdf,
        private readonly ReceiptPrinter $printer,
        private readonly ReceiptData $data,
    ) {}

    public function a4(Request $request, Transaction $transaction): Response
    {
        $html = view('pdf.receipt', $this->viewData($request, $transaction, ReceiptPrint::FORMAT_A4))->render();

        return $this->pdf->stream($html, "receipt-{$transaction->id}.pdf", $this->rtl());
    }

    public function ticket(Request $request, Transaction $transaction): Response
    {
        $html = view('pdf.receipt-ticket', $this->viewData($request, $transaction, ReceiptPrint::FORMAT_THERMAL))->render();

        return $this->pdf->streamRoll($html, "ticket-{$transaction->id}.pdf", $this->rtl());
    }

    public function verify(Request $request): InertiaResponse
    {
        $input = trim((string) $request->query('code', ''));
        $code = $input === '' ? null : $this->printer->normalizeCode($input);

        return Inertia::render('Receipts/Verify', [
            'input' => $input,
            'result' => $input === '' ? null : $this->check($code),
        ]);
    }

    /** @return array<string, mixed> */
    private function viewData(Request $request, Transaction $transaction, string $format): array
    {
        $transaction->load(ReceiptData::RELATIONS);
        $print = $this->printer->record($transaction, $format, $request->user());

        return [...$this->data->build($transaction, $print), 'rtl' => $this->rtl()];
    }

    /**
     * unknown: no paper was ever printed with this code (mistyped or forged).
     * deleted: printed, but the transaction is gone; the snapshot is all that is left.
     * cancelled / modified / valid: the transaction now, compared with the paper.
     *
     * @return array<string, mixed>
     */
    private function check(?string $code): array
    {
        $print = $code ? ReceiptPrint::with('printedBy:id,name')->where('code', $code)->first() : null;

        if (! $print) {
            return ['status' => 'unknown', 'code' => $code];
        }

        $transaction = $print->transaction_id
            ? Transaction::with(TransactionTitle::RELATIONS)->find($print->transaction_id)
            : null;

        $differences = $transaction ? $this->printer->differences($print, $transaction) : [];

        $status = match (true) {
            ! $transaction => 'deleted',
            (bool) $transaction->archived => 'cancelled',
            $differences !== [] => 'modified',
            default => 'valid',
        };

        $copies = ReceiptPrint::with('printedBy:id,name')
            ->where('transaction_number', $print->transaction_number)
            ->orderBy('copy_number')
            ->get()
            ->map(fn (ReceiptPrint $copy) => [
                'copy_number' => $copy->copy_number,
                'format' => $copy->format,
                'printed_at' => $copy->created_at?->format('Y-m-d H:i'),
                'printed_by' => $copy->printedBy?->name,
                'is_this' => $copy->id === $print->id,
            ]);

        return [
            'status' => $status,
            'code' => $print->displayCode(),
            'copy_number' => $print->copy_number,
            'format' => $print->format,
            'printed_at' => $print->created_at?->format('Y-m-d H:i'),
            'printed_by' => $print->printedBy?->name,
            'transaction_number' => $print->transaction_number,
            'transaction_id' => $transaction?->id,
            'snapshot' => $print->snapshot,
            'payment_label' => isset($print->snapshot['payment_method'])
                ? UiLang::get(strtolower((string) $print->snapshot['payment_method']), (string) $print->snapshot['payment_method'])
                : null,
            'differences' => $differences,
            'copies' => $copies,
        ];
    }

    private function rtl(): bool
    {
        return app()->getLocale() === 'ar';
    }
}
