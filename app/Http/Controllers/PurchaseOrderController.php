<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseOrder\SavePurchaseOrderRequest;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\PurchaseOrderService;
use App\Support\PermissionMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Finance → Purchase orders (سندات الطلب): register, form, status actions. */
class PurchaseOrderController extends Controller
{
    private const USER_COLUMNS = 'id,name,firstname,lastname';

    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'year' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(PurchaseOrder::STATUSES)],
            'supplier' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $like = '%'.($filters['q'] ?? '').'%';

        $orders = PurchaseOrder::query()
            ->with('supplier:id,name')
            ->withCount(['lines', 'lines as unpriced_count' => fn ($q) => $q->whereNull('unit_price')])
            ->withSum('lines', 'amount')
            ->when($filters['year'] ?? null, fn ($q, $year) => $q->where('year', $year))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['supplier'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(function ($q) use ($text, $like) {
                $q->where('requested_by', 'like', $like)
                    ->orWhere('legacy_reference', 'like', $like)
                    ->orWhereHas('supplier', fn ($q) => $q->where('name', 'like', $like))
                    ->orWhereHas('lines', fn ($q) => $q->where('designation', 'like', $like));
                // "3" or "03/2026" finds order number 3 (of 2026).
                if (preg_match('/^0*(\d+)(?:\/(\d{4}))?$/', trim($text), $m)) {
                    $q->orWhere(fn ($q) => $q->where('number', (int) $m[1])
                        ->when($m[2] ?? null, fn ($q, $year) => $q->where('year', (int) $year)));
                }
            }))
            ->orderByDesc('year')->orderByDesc('number')->orderByDesc('order_date')->orderByDesc('id')
            ->paginate(50)->withQueryString()
            ->through(fn (PurchaseOrder $order) => [
                'id' => $order->id,
                'reference' => $order->reference,
                'order_date' => $order->order_date?->format('Y-m-d'),
                'supplier' => $order->supplier?->name,
                'requested_by' => $order->requested_by,
                // Blank until every line has a price.
                'total' => (int) $order->lines_count > 0 && (int) $order->unpriced_count === 0 ? (float) $order->lines_sum_amount : null,
                'status' => $order->status,
                'is_legacy' => $order->is_legacy,
            ]);

        return Inertia::render('PurchaseOrders/Index', [
            'orders' => $orders,
            'filters' => $filters,
            'years' => PurchaseOrder::query()->distinct()->orderByDesc('year')->pluck('year'),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PurchaseOrders/Create', $this->formOptions());
    }

    public function store(SavePurchaseOrderRequest $request): RedirectResponse
    {
        $order = $this->orders->create($request->validated(), $request->user());

        return redirect()->route('purchase-orders.show', $order)->with('success', 'flash.purchase_order_created');
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $purchaseOrder->load([
            'supplier:id,name,address,phone', 'lines.catalog:id,name',
            'paymentOrders' => fn ($q) => $q->orderByDesc('id'),
            'createdBy:'.self::USER_COLUMNS, 'sentBy:'.self::USER_COLUMNS,
            'receivedBy:'.self::USER_COLUMNS, 'cancelledBy:'.self::USER_COLUMNS,
        ]);
        $user = $request->user();
        $status = $purchaseOrder->status;
        $mayEdit = $user->hasPermission('purchase_orders', 'edit') && ! $purchaseOrder->is_legacy;
        $hasActive = $purchaseOrder->paymentOrders->contains(fn (PaymentOrder $p) => $p->status !== PaymentOrder::CANCELLED);
        $payable = $purchaseOrder->isFullyPriced() && $purchaseOrder->total() > 0;
        $mayMakePaymentOrder = $user->hasPermission('payment_orders', 'add');

        return Inertia::render('PurchaseOrders/Show', [
            'order' => [
                ...$purchaseOrder->toArray(),
                'payment_orders' => $purchaseOrder->paymentOrders->map(fn (PaymentOrder $p) => [
                    'id' => $p->id, 'reference' => $p->reference, 'status' => $p->status, 'amount' => (float) $p->amount,
                ])->values(),
                'total' => $purchaseOrder->total(),
                'fully_priced' => $purchaseOrder->isFullyPriced(),
                'equipment_units' => (int) EquipmentItem::where('purchase_order_id', $purchaseOrder->id)->sum('quantity'),
            ],
            'can' => [
                'edit' => $mayEdit && ($status === PurchaseOrder::DRAFT
                    || (in_array($status, [PurchaseOrder::SENT, PurchaseOrder::RECEIVED], true) && ! $hasActive)),
                'send' => $mayEdit && $status === PurchaseOrder::DRAFT,
                'receive' => $mayEdit && $status === PurchaseOrder::SENT,
                'cancel' => $mayEdit && in_array($status, [PurchaseOrder::DRAFT, PurchaseOrder::SENT], true),
                // The two ticks of the receive dialog.
                'receive_stock' => self::mayReceiveStock($user)
                    && $purchaseOrder->lines->contains(fn (PurchaseOrderLine $line) => $line->equipment_catalog_id !== null),
                'payment_order_on_receive' => $payable && $mayMakePaymentOrder,
                'create_payment_order' => $mayEdit && $mayMakePaymentOrder && $payable && ! $hasActive
                    && $status === PurchaseOrder::RECEIVED,
                'print' => $status !== PurchaseOrder::CANCELLED,
            ],
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder): Response|RedirectResponse
    {
        if (! $purchaseOrder->isEditable() && ! $purchaseOrder->canEditPrices()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)->with('error', 'flash.purchase_order_locked');
        }
        $purchaseOrder->load('lines');

        return Inertia::render('PurchaseOrders/Edit', [
            'order' => $purchaseOrder,
            'pricesOnly' => $purchaseOrder->status !== PurchaseOrder::DRAFT,
            ...$this->formOptions($purchaseOrder),
        ]);
    }

    public function update(SavePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->orders->update($purchaseOrder, $request->validated(), $request->user());

        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('success', 'flash.purchase_order_updated');
    }

    public function send(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->orders->send($purchaseOrder, $request->user());

        return back()->with('success', 'flash.purchase_order_sent');
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate([
            'received_date' => ['required', 'date', 'after_or_equal:'.$purchaseOrder->order_date->toDateString(), 'before_or_equal:today'],
            'to_stock' => ['boolean'],
            'payment_order' => ['boolean'],
        ]);
        $user = $request->user();
        $toStock = (bool) ($data['to_stock'] ?? false);
        $makePaymentOrder = (bool) ($data['payment_order'] ?? false);
        // Each tick is its own right: the equipment page's Receive Stock, adding a payment order.
        abort_if($toStock && ! self::mayReceiveStock($user), 403);
        abort_if($makePaymentOrder && ! $user->hasPermission('payment_orders', 'add'), 403);

        $this->orders->receive($purchaseOrder, $user, $data['received_date'], $toStock, $makePaymentOrder);

        return back()->with('success', 'flash.purchase_order_received');
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->orders->cancel($purchaseOrder, $request->user());

        return back()->with('success', 'flash.purchase_order_cancelled');
    }

    /** "Create payment order" on a received order with no active one. */
    public function paymentOrder(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        // The route checks purchase_orders → edit; what it makes is a payment order, so adding one is needed too.
        abort_unless($request->user()->hasPermission('payment_orders', 'add'), 403);
        $paymentOrder = $this->orders->createPaymentOrder($purchaseOrder, $request->user());

        return redirect()->route('payment-orders.show', $paymentOrder)->with('success', 'flash.payment_order_created');
    }

    /** The equipment page's Receive Stock right (whatever config/permissions maps equipment.stock.receive to). */
    public static function mayReceiveStock(User $user): bool
    {
        $required = PermissionMap::resolve('equipment.stock.receive');

        return $required === null || $user->hasPermission(...$required);
    }

    /** @return array<string, mixed> */
    private function formOptions(?PurchaseOrder $current = null): array
    {
        return [
            // Active suppliers, plus the one a draft already names.
            'suppliers' => Supplier::query()
                ->where(fn ($q) => $q->where('is_active', true)->when($current?->supplier_id, fn ($q, $id) => $q->orWhere('id', $id)))
                ->orderBy('name')->get(['id', 'name', 'address', 'phone']),
            'catalogs' => EquipmentCatalog::query()->orderBy('name')->get(['id', 'name']),
        ];
    }
}
