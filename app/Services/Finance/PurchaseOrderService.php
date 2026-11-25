<?php

namespace App\Services\Finance;

use App\Exceptions\PurchaseOrderException;
use App\Http\Requests\Equipment\ReceiveStockRequest;
use App\Models\EquipmentItem;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Documents\YearlySequence;
use App\Services\Equipment\EquipmentStockService;
use App\Support\UiLang;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every purchase order state change, one DB transaction each, each guarded by
 * an atomic conditional claim: draft (numbered) → sent (supplier, lines and
 * quantities locked; unit prices open until a payment order is linked) →
 * received (optional stock + draft payment order) or cancelled. Money is
 * recorded once, when the linked payment order is paid.
 */
final class PurchaseOrderService
{
    /** Header fields a form may set; numbering, state and audit columns are the service's. */
    private const HEADER = ['order_date', 'supplier_id', 'requested_by', 'delivery_mode', 'payment_mode', 'observation'];

    public function __construct(
        private readonly PaymentOrderService $paymentOrders,
        private readonly EquipmentStockService $stock,
    ) {}

    /** @param array<string, mixed> $data validated form fields, with `lines` */
    public function create(array $data, User $by): PurchaseOrder
    {
        return YearlySequence::retrying(fn () => DB::transaction(function () use ($data, $by) {
            $year = Carbon::parse($data['order_date'])->year;
            $order = PurchaseOrder::create([
                ...Arr::only($data, self::HEADER),
                'year' => $year,
                'number' => YearlySequence::next('purchase_orders', $year),
                'status' => PurchaseOrder::DRAFT,
                'created_by' => $by->id,
            ]);
            $this->writeLines($order, $data['lines']);
            $order->load('lines');
            ActivityRecorder::record($by, ActivityAction::PURCHASE_ORDER_CREATED, $order, [
                'reference' => $order->reference,
                'total' => $order->isFullyPriced() ? $order->total() : null,
            ]);

            return $order->fresh('lines');
        }));
    }

    /**
     * Draft: every field and the lines. Sent or received: only the unit prices
     * (`lines` of `{id, unit_price}`), until an active payment order is linked.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PurchaseOrder $order, array $data, User $by): PurchaseOrder
    {
        if ($order->is_legacy || $order->status === PurchaseOrder::CANCELLED) {
            throw new PurchaseOrderException('flash.purchase_order_locked');
        }

        return $order->status === PurchaseOrder::DRAFT
            ? $this->updateDraft($order, $data, $by)
            : $this->updatePrices($order, $data, $by);
    }

    /** @param array<string, mixed> $data */
    private function updateDraft(PurchaseOrder $order, array $data, User $by): PurchaseOrder
    {
        // The number belongs to the year it was given in.
        if (Carbon::parse($data['order_date'])->year !== $order->year) {
            throw new PurchaseOrderException('flash.purchase_order_year_locked');
        }

        return DB::transaction(function () use ($order, $data, $by) {
            $claimed = PurchaseOrder::whereKey($order->id)
                ->where('status', PurchaseOrder::DRAFT)->where('is_legacy', false)
                ->update([...Arr::only($data, self::HEADER), 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            if ($claimed === 0) {
                throw new PurchaseOrderException('flash.purchase_order_locked');
            }
            $this->writeLines($order, $data['lines']);
            ActivityRecorder::record($by, ActivityAction::PURCHASE_ORDER_UPDATED, $order, ['reference' => $order->reference]);

            return $order->fresh('lines');
        });
    }

    /** @param array<string, mixed> $data */
    private function updatePrices(PurchaseOrder $order, array $data, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data, $by) {
            $claimed = PurchaseOrder::whereKey($order->id)
                ->whereIn('status', [PurchaseOrder::SENT, PurchaseOrder::RECEIVED])->where('is_legacy', false)
                ->whereDoesntHave('paymentOrders', fn ($q) => $q->where('payment_orders.status', '!=', PaymentOrder::CANCELLED))
                ->update(['lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            if ($claimed === 0) {
                throw new PurchaseOrderException('flash.purchase_order_prices_locked');
            }

            $prices = collect($data['lines'] ?? [])->mapWithKeys(fn (array $line) => [(int) $line['id'] => $line['unit_price'] ?? null]);
            foreach (PurchaseOrderLine::where('purchase_order_id', $order->id)->get() as $line) {
                if (! $prices->has($line->id)) {
                    continue;
                }
                $price = self::price($prices->get($line->id));
                $line->update(['unit_price' => $price, 'amount' => PurchaseOrderLine::amountFor($line->quantity, $price)]);
            }
            ActivityRecorder::record($by, ActivityAction::PURCHASE_ORDER_UPDATED, $order, ['reference' => $order->reference]);

            return $order->fresh('lines');
        });
    }

    public function send(PurchaseOrder $order, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $by) {
            $claimed = PurchaseOrder::whereKey($order->id)
                ->where('status', PurchaseOrder::DRAFT)->where('is_legacy', false)
                ->whereNotNull('supplier_id')->whereHas('lines')
                ->update(['status' => PurchaseOrder::SENT, 'sent_by' => $by->id, 'sent_at' => now()]);
            if ($claimed === 0) {
                throw new PurchaseOrderException('flash.purchase_order_not_draft');
            }
            ActivityRecorder::record($by, ActivityAction::PURCHASE_ORDER_SENT, $order, ['reference' => $order->reference]);

            return $order->fresh('lines');
        });
    }

    /** Draft or sent only; the order keeps its number. */
    public function cancel(PurchaseOrder $order, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $by) {
            $claimed = PurchaseOrder::whereKey($order->id)
                ->whereIn('status', [PurchaseOrder::DRAFT, PurchaseOrder::SENT])->where('is_legacy', false)
                ->update(['status' => PurchaseOrder::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => now()]);
            if ($claimed === 0) {
                throw new PurchaseOrderException('flash.purchase_order_cannot_cancel');
            }
            ActivityRecorder::record($by, ActivityAction::PURCHASE_ORDER_CANCELLED, $order, ['reference' => $order->reference]);

            return $order->fresh('lines');
        });
    }

    /**
     * The whole order arrives at once. Optional, in the same transaction:
     * catalog lines into stock (no expense — money is recorded at payment) and
     * a draft payment order to the supplier for the order's total.
     */
    public function receive(PurchaseOrder $order, User $by, string $receivedDate, bool $toStock = false, bool $makePaymentOrder = false): PurchaseOrder
    {
        $order->load('lines.catalog:id,requires_serial');
        if ($makePaymentOrder && ! self::payable($order)) {
            throw new PurchaseOrderException('flash.purchase_order_unpriced');
        }
        /** @var Collection<int, PurchaseOrderLine> $stockLines free-text lines never go to stock */
        $stockLines = $toStock ? $order->lines->whereNotNull('equipment_catalog_id')->values() : collect();
        foreach ($stockLines as $line) {
            if ($line->catalog?->requires_serial && $line->quantity > ReceiveStockRequest::MAX_NUMBERED_UNITS) {
                throw new PurchaseOrderException('flash.purchase_order_too_many_units');
            }
        }

        return DB::transaction(function () use ($order, $by, $receivedDate, $stockLines, $makePaymentOrder) {
            $claimed = PurchaseOrder::whereKey($order->id)
                ->where('status', PurchaseOrder::SENT)->where('is_legacy', false)
                ->update([
                    'status' => PurchaseOrder::RECEIVED,
                    'received_by' => $by->id,
                    'received_at' => Carbon::parse($receivedDate)->startOfDay(),
                ]);
            if ($claimed === 0) {
                throw new PurchaseOrderException('flash.purchase_order_not_sent');
            }

            foreach ($stockLines as $line) {
                $this->stock->receive([
                    'catalog_id' => $line->equipment_catalog_id,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price !== null ? (float) $line->unit_price : null,
                    'designation' => $line->designation,
                    'condition' => 'New',
                    'received_via' => 'purchase',
                    'record_expense' => false,
                    'purchase_date' => $receivedDate,
                    'purchase_order_id' => $order->id,
                ], $by->id, $by);
            }
            $paymentOrder = $makePaymentOrder ? $this->makePaymentOrder($order, $by, $receivedDate) : null;

            ActivityRecorder::record($by, ActivityAction::PURCHASE_ORDER_RECEIVED, $order, [
                'reference' => $order->reference,
                'stock_units' => $stockLines->isEmpty() ? null : (int) EquipmentItem::where('purchase_order_id', $order->id)->sum('quantity'),
                'payment_order_id' => $paymentOrder?->id,
            ]);

            return $order->fresh('lines');
        });
    }

    /** The "Create payment order" button of a received order (dated today). */
    public function createPaymentOrder(PurchaseOrder $order, User $by): PaymentOrder
    {
        if ($order->status !== PurchaseOrder::RECEIVED || $order->is_legacy) {
            throw new PurchaseOrderException('flash.purchase_order_not_received');
        }
        $order->load('lines');

        return DB::transaction(fn () => $this->makePaymentOrder($order, $by, now()->format('Y-m-d')));
    }

    /**
     * A draft payment order to the supplier (bank details from the supplier)
     * for the order's total. PaymentOrderService::create claims the purchase
     * order atomically, so a second active payment order cannot slip in.
     */
    private function makePaymentOrder(PurchaseOrder $order, User $by, string $date): PaymentOrder
    {
        if (! self::payable($order)) {
            throw new PurchaseOrderException('flash.purchase_order_unpriced');
        }
        if ($order->hasActivePaymentOrder()) {
            throw new PurchaseOrderException('flash.purchase_order_has_payment_order');
        }
        $supplier = Supplier::find($order->supplier_id);

        return $this->paymentOrders->create([
            'order_date' => $date,
            'subject' => Str::limit(UiLang::get('pur.title').' '.$order->reference, 150, ''),
            'amount' => $order->total(),
            'payment_method' => $order->payment_mode,
            'beneficiary_type' => 'supplier',
            'beneficiary_id' => $order->supplier_id,
            'beneficiary_bank' => $supplier?->bank_name,
            'beneficiary_rib' => $supplier?->rib,
            'purchase_order_id' => $order->id,
        ], $by);
    }

    /** Every line priced and something to pay. */
    private static function payable(PurchaseOrder $order): bool
    {
        return $order->isFullyPriced() && $order->total() > 0;
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(PurchaseOrder $order, array $lines): void
    {
        PurchaseOrderLine::where('purchase_order_id', $order->id)->delete();
        foreach (array_values($lines) as $index => $line) {
            $quantity = (int) $line['quantity'];
            $price = self::price($line['unit_price'] ?? null);
            PurchaseOrderLine::create([
                'purchase_order_id' => $order->id,
                'equipment_catalog_id' => $line['equipment_catalog_id'] ?? null,
                'designation' => Str::limit(trim((string) $line['designation']), 255, ''),
                'quantity' => $quantity,
                'unit_price' => $price,
                'amount' => PurchaseOrderLine::amountFor($quantity, $price),
                'sort_order' => $index + 1,
            ]);
        }
    }

    private static function price(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : round((float) $value, 2);
    }
}
