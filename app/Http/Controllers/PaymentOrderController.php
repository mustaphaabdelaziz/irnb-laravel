<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentOrder\SavePaymentOrderRequest;
use App\Models\BoardMeeting;
use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\PaymentOrder;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\PaymentOrderService;
use App\Support\UiLang;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentOrderController extends Controller
{
    public function __construct(private readonly PaymentOrderService $orders) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'year' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(PaymentOrder::STATUSES)],
            'method' => ['nullable', Rule::in(PaymentOrder::METHODS)],
            'supplier' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $like = '%'.($filters['q'] ?? '').'%';
        $query = PaymentOrder::query()
            ->when($filters['year'] ?? null, fn ($q, $year) => $q->where('year', $year))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['method'] ?? null, fn ($q, $method) => $q->where('payment_method', $method))
            ->when($filters['supplier'] ?? null, fn ($q, $id) => $q->where('beneficiary_type', 'supplier')->where('beneficiary_id', $id))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(function ($q) use ($text, $like) {
                $q->where('subject', 'like', $like)
                    ->orWhere('beneficiary_name', 'like', $like)
                    ->orWhere('legacy_reference', 'like', $like);
                // "7" or "07/2026" finds order number 7 (of 2026).
                if (preg_match('/^0*(\d+)(?:\/(\d{4}))?$/', trim($text), $m)) {
                    $q->orWhere(fn ($q) => $q->where('number', (int) $m[1])
                        ->when($m[2] ?? null, fn ($q, $year) => $q->where('year', (int) $year)));
                }
            }));

        $total = (float) (clone $query)->where('status', '!=', PaymentOrder::CANCELLED)->sum('amount');

        $orders = $query->orderByDesc('year')->orderByDesc('number')->orderByDesc('order_date')->orderByDesc('id')
            ->paginate(50)->withQueryString()
            ->through(fn (PaymentOrder $order) => [
                'id' => $order->id,
                'reference' => $order->reference,
                'order_date' => $order->order_date?->format('Y-m-d'),
                'subject' => $order->subject,
                'amount' => (float) $order->amount,
                'status' => $order->status,
                'payment_method' => $order->payment_method,
                'is_legacy' => $order->is_legacy,
                'beneficiary_label' => $order->beneficiaryLabel(),
            ]);

        return Inertia::render('PaymentOrders/Index', [
            'orders' => $orders,
            'filters' => $filters,
            'total' => $total,
            'years' => PaymentOrder::query()->distinct()->orderByDesc('year')->pluck('year'),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PaymentOrders/Create', $this->formOptions());
    }

    public function store(SavePaymentOrderRequest $request): RedirectResponse
    {
        $order = $this->orders->create($request->validated(), $request->user());

        return redirect()->route('payment-orders.show', $order)->with('success', 'flash.payment_order_created');
    }

    public function show(Request $request, PaymentOrder $paymentOrder): Response
    {
        $paymentOrder->load([
            'financeCategory:id,name,name_ar,name_fr,name_en', 'financeAccount:id,name', 'boardMeeting:id,title,meeting_date,pv_number', 'transaction:id,amount,transaction_date',
            'createdBy:id,name,firstname,lastname', 'approvedBy:id,name,firstname,lastname', 'cancelledBy:id,name,firstname,lastname',
        ]);
        $user = $request->user();

        return Inertia::render('PaymentOrders/Show', [
            'order' => [...$paymentOrder->toArray(), 'beneficiary_label' => $paymentOrder->beneficiaryLabel()],
            'prints' => self::printsFor($paymentOrder),
            'can' => [
                'edit' => $paymentOrder->isEditable() && $user->hasPermission('payment_orders', 'edit'),
                'approve' => $paymentOrder->status === PaymentOrder::DRAFT && ! $paymentOrder->is_legacy
                    && $paymentOrder->finance_category_id !== null && $paymentOrder->finance_account_id !== null
                    && $user->hasPermission('payment_orders', 'approve'),
                'pay' => $paymentOrder->status === PaymentOrder::APPROVED && $user->hasPermission('payment_orders', 'edit'),
                'cancel' => in_array($paymentOrder->status, [PaymentOrder::DRAFT, PaymentOrder::APPROVED], true) && ! $paymentOrder->is_legacy && $user->hasPermission('payment_orders', 'edit'),
            ],
        ]);
    }

    public function edit(PaymentOrder $paymentOrder): Response|RedirectResponse
    {
        if (! $paymentOrder->isEditable()) {
            return redirect()->route('payment-orders.show', $paymentOrder)->with('error', 'flash.payment_order_locked');
        }

        return Inertia::render('PaymentOrders/Edit', ['order' => $paymentOrder, ...$this->formOptions()]);
    }

    public function update(SavePaymentOrderRequest $request, PaymentOrder $paymentOrder): RedirectResponse
    {
        $this->orders->update($paymentOrder, $request->validated(), $request->user());

        return redirect()->route('payment-orders.show', $paymentOrder)->with('success', 'flash.payment_order_updated');
    }

    public function approve(Request $request, PaymentOrder $paymentOrder): RedirectResponse
    {
        $this->orders->approve($paymentOrder, $request->user());

        return back()->with('success', 'flash.payment_order_approved');
    }

    public function pay(Request $request, PaymentOrder $paymentOrder): RedirectResponse
    {
        $data = $request->validate(['payment_date' => ['nullable', 'date']]);
        $this->orders->markPaid($paymentOrder, $request->user(), $data['payment_date'] ?? null);

        return back()->with('success', 'flash.payment_order_paid');
    }

    public function cancel(Request $request, PaymentOrder $paymentOrder): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->orders->cancel($paymentOrder, $request->user(), $data['reason']);

        return back()->with('success', 'flash.payment_order_cancelled');
    }

    /** "Create payment order" on an expense recorded without one. */
    public function fromExpense(Request $request, Transaction $transaction): RedirectResponse
    {
        $order = $this->orders->fromExpense($transaction, $request->user());

        return redirect()->route('payment-orders.show', $order)->with('success', 'flash.payment_order_created');
    }

    /** @return list<string> Which of the three forms this order prints. */
    public static function printsFor(PaymentOrder $order): array
    {
        if ($order->status === PaymentOrder::CANCELLED) {
            return [];
        }

        return array_values(array_filter([
            'payment',
            in_array($order->payment_method, ['bank', 'ccp'], true) ? 'transfer' : null,
            $order->payment_method === 'cash' ? 'cash' : null,
        ]));
    }

    /** @param \Illuminate\Support\Collection<string, string> $labels */
    private static function roleLabel(?string $role, $labels): ?string
    {
        if ($role === null || $role === '') {
            return null;
        }

        return $labels[$role] ?? UiLang::get($role, $role);
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        // A role's typed label first (custom roles), else its built-in translation — never the raw name.
        $roleLabels = BoardRole::query()->whereNotNull('label')->where('label', '!=', '')->pluck('label', 'name');

        return [
            'financeCategories' => FinanceCategory::query()->where('type', 'expense')->where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'financeAccounts' => FinanceAccount::selectable()->get()->makeHidden(['current_balance', 'opening_balance']),
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name', 'bank_name', 'rib']),
            'users' => User::query()->where('is_active', true)->orderBy('lastname')->orderBy('firstname')->get(['id', 'name', 'firstname', 'lastname'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]),
            'boardMembers' => BoardMember::query()->withPlayer()->inCurrentTerm()->orderBy('sort_order')->get()
                ->map(fn (BoardMember $m) => ['id' => $m->id, 'name' => $m->name, 'role' => self::roleLabel($m->role, $roleLabels)]),
            'boardMeetings' => BoardMeeting::query()->whereNull('cancelled_at')->orderByDesc('meeting_date')->limit(200)
                ->get(['id', 'title', 'meeting_date', 'pv_number']),
        ];
    }
}
