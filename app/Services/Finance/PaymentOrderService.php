<?php

namespace App\Services\Finance;

use App\Exceptions\PaymentOrderException;
use App\Models\EquipmentItem;
use App\Models\FinanceCategory;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Documents\YearlySequence;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every payment order state change, one DB transaction each:
 * draft (numbered) → approved (beneficiary frozen) → paid (expense created) or cancelled.
 */
final class PaymentOrderService
{
    /** State columns only the service itself sets, never a form. */
    private const STATE_COLUMNS = [
        'year', 'number', 'status', 'transaction_id', 'is_legacy', 'legacy_reference', 'legacy_source',
        'created_by', 'approved_by', 'approved_at', 'paid_at', 'cancelled_by', 'cancelled_at', 'cancel_reason',
        'beneficiary_name',
    ];

    /** @param array<string, mixed> $data validated form fields */
    public function create(array $data, User $by): PaymentOrder
    {
        return YearlySequence::retrying(fn () => DB::transaction(function () use ($data, $by) {
            $year = Carbon::parse($data['order_date'])->year;
            self::claimPurchaseOrder($data, null);
            $order = PaymentOrder::create([
                ...Arr::except($data, self::STATE_COLUMNS),
                'year' => $year,
                'number' => YearlySequence::next('payment_orders', $year),
                'status' => PaymentOrder::DRAFT,
                'created_by' => $by->id,
            ]);
            ActivityRecorder::record($by, ActivityAction::PAYMENT_ORDER_CREATED, $order, [
                'amount' => (float) $order->amount, 'reference' => $order->reference,
            ]);

            return $order->fresh();
        }));
    }

    /** @param array<string, mixed> $data */
    public function update(PaymentOrder $order, array $data, User $by): PaymentOrder
    {
        if (! $order->isEditable()) {
            throw new PaymentOrderException('flash.payment_order_locked');
        }
        if (Carbon::parse($data['order_date'])->year !== $order->year) {
            throw new PaymentOrderException('flash.payment_order_year_locked');
        }

        return DB::transaction(function () use ($order, $data, $by) {
            self::claimPurchaseOrder($data, $order->id);
            $order->update(Arr::except($data, self::STATE_COLUMNS));
            ActivityRecorder::record($by, ActivityAction::PAYMENT_ORDER_UPDATED, $order, ['reference' => $order->reference]);

            return $order->fresh();
        });
    }

    public function approve(PaymentOrder $order, User $by): PaymentOrder
    {
        if ($order->status !== PaymentOrder::DRAFT || $order->is_legacy) {
            throw new PaymentOrderException('flash.payment_order_not_draft');
        }
        if ($order->beneficiary_type === null || $order->beneficiary_id === null) {
            throw new PaymentOrderException('flash.payment_order_no_beneficiary');
        }
        $this->expenseCategoryOrFail($order);

        return DB::transaction(function () use ($order, $by) {
            $supplier = $order->beneficiary_type === 'supplier' ? Supplier::find($order->beneficiary_id) : null;
            $claimed = PaymentOrder::whereKey($order->id)
                ->where('status', PaymentOrder::DRAFT)->where('is_legacy', false)
                ->update([
                    'status' => PaymentOrder::APPROVED,
                    'beneficiary_name' => $order->liveBeneficiaryName() ?? $order->beneficiary_name,
                    'beneficiary_bank' => $order->beneficiary_bank ?: $supplier?->bank_name,
                    'beneficiary_rib' => $order->beneficiary_rib ?: $supplier?->rib,
                    'approved_by' => $by->id,
                    'approved_at' => now(),
                ]);
            if ($claimed === 0) {
                throw new PaymentOrderException('flash.payment_order_not_draft');
            }
            ActivityRecorder::record($by, ActivityAction::PAYMENT_ORDER_APPROVED, $order, [
                'amount' => (float) $order->amount, 'reference' => $order->reference,
            ]);

            return $order->fresh();
        });
    }

    public function markPaid(PaymentOrder $order, User $by, ?string $paymentDate = null): PaymentOrder
    {
        if ($order->status !== PaymentOrder::APPROVED || $order->transaction_id !== null) {
            throw new PaymentOrderException('flash.payment_order_not_approved');
        }
        $category = $this->expenseCategoryOrFail($order);

        return DB::transaction(function () use ($order, $by, $paymentDate, $category) {
            $date = $paymentDate ?? ($order->payment_date?->format('Y-m-d')) ?? now()->format('Y-m-d');
            $claimed = PaymentOrder::whereKey($order->id)
                ->where('status', PaymentOrder::APPROVED)->whereNull('transaction_id')
                ->update(['status' => PaymentOrder::PAID, 'payment_date' => $date, 'paid_at' => now()]);
            if ($claimed === 0) {
                throw new PaymentOrderException('flash.payment_order_not_approved');
            }

            $transaction = Transaction::create([
                'title' => Str::limit($order->subject, 150, ''),
                'amount' => $order->amount,
                'transaction_date' => $date,
                'transaction_type' => 'expense',
                'finance_category_id' => $category->id,
                'category' => Str::slug($category->name, '_'),
                'finance_account_id' => $order->finance_account_id,
                'payment_method' => $order->payment_method ?? 'cash',
                'payment_bank_name' => $order->beneficiary_bank,
                'payment_account' => $order->beneficiary_rib,
                'payment_holder' => $order->beneficiary_name,
                'payment_reference' => $order->payment_reference,
                'cheque_date' => $order->payment_method === 'cheque' ? $date : null,
                'supplier_id' => $order->beneficiary_type === 'supplier' ? $order->beneficiary_id : null,
                'invoice_number' => $order->invoice_number,
                'invoice_date' => $order->invoice_date,
                'board_meeting_id' => $order->board_meeting_id,
                'description' => $order->observation,
                'status' => 'Paid',
                'recorded_by_user_id' => $by->id,
            ]);
            ActivityRecorder::record($by, ActivityAction::TRANSACTION_RECORDED, $transaction, [
                'amount' => (float) $transaction->amount, 'type' => 'expense',
            ]);

            PaymentOrder::whereKey($order->id)->update(['transaction_id' => $transaction->id]);
            // Money is recorded once, here: the equipment received from the linked
            // purchase order now points to this expense (lots already linked stay).
            if ($order->purchase_order_id !== null) {
                EquipmentItem::where('purchase_order_id', $order->purchase_order_id)
                    ->whereNull('purchase_transaction_id')
                    ->update(['purchase_transaction_id' => $transaction->id]);
            }
            ActivityRecorder::record($by, ActivityAction::PAYMENT_ORDER_PAID, $order, [
                'amount' => (float) $order->amount, 'reference' => $order->reference,
            ]);

            return $order->fresh();
        });
    }

    /**
     * A payment order may name a received purchase order of its own supplier
     * that has no other active payment order. The claim is atomic, so two
     * orders saved at the same instant cannot both take it.
     *
     * @param  array<string, mixed>  $data
     */
    private static function claimPurchaseOrder(array $data, ?int $exceptPaymentOrderId): void
    {
        $purchaseOrderId = $data['purchase_order_id'] ?? null;
        if ($purchaseOrderId === null || $purchaseOrderId === '') {
            return;
        }
        $supplierId = ($data['beneficiary_type'] ?? null) === 'supplier' ? (int) ($data['beneficiary_id'] ?? 0) : 0;

        if (! PurchaseOrder::claimForPaymentOrder((int) $purchaseOrderId, $supplierId, $exceptPaymentOrderId)) {
            throw new PaymentOrderException('flash.payment_order_purchase_order_unavailable');
        }
    }

    /** An order is approved and paid only with an expense category and a register. */
    private function expenseCategoryOrFail(PaymentOrder $order): FinanceCategory
    {
        $category = $order->finance_category_id ? FinanceCategory::find($order->finance_category_id) : null;
        if ($category === null || $category->type !== 'expense' || $order->finance_account_id === null) {
            throw new PaymentOrderException('flash.payment_order_missing_finance');
        }

        return $category;
    }

    /** Is this account number the club's own CCP or bank account (digits compared)? */
    private static function isClubAccount(?string $account): bool
    {
        $digits = preg_replace('/\D/', '', (string) $account);
        if ($digits === '') {
            return false;
        }
        $banking = WebsiteConfig::singleton()->banking_info ?? [];
        $ccp = $banking['ccp'] ?? [];
        $ccpNumber = $ccp['account_number'] ?? $ccp['accountNumber'] ?? '';
        $club = array_filter(array_map(
            fn ($value) => preg_replace('/\D/', '', (string) $value),
            [$ccpNumber, $ccpNumber.($ccp['key'] ?? ''), $banking['rib'] ?? '', $banking['account_number'] ?? ''],
        ));

        return in_array($digits, $club, true);
    }

    public function cancel(PaymentOrder $order, User $by, string $reason): PaymentOrder
    {
        if (! in_array($order->status, [PaymentOrder::DRAFT, PaymentOrder::APPROVED], true) || $order->is_legacy) {
            throw new PaymentOrderException('flash.payment_order_cannot_cancel');
        }

        return DB::transaction(function () use ($order, $by, $reason) {
            $claimed = PaymentOrder::whereKey($order->id)
                ->whereIn('status', [PaymentOrder::DRAFT, PaymentOrder::APPROVED])->where('is_legacy', false)
                ->update([
                    'status' => PaymentOrder::CANCELLED,
                    'cancelled_by' => $by->id,
                    'cancelled_at' => now(),
                    'cancel_reason' => Str::limit(trim($reason), 500, ''),
                ]);
            if ($claimed === 0) {
                throw new PaymentOrderException('flash.payment_order_cannot_cancel');
            }
            ActivityRecorder::record($by, ActivityAction::PAYMENT_ORDER_CANCELLED, $order, ['reference' => $order->reference]);

            return $order->fresh();
        });
    }

    /** A paid order for an expense recorded without one (its number comes from the expense's year). */
    public function fromExpense(Transaction $transaction, User $by): PaymentOrder
    {
        if ($transaction->transaction_type !== 'expense') {
            throw new PaymentOrderException('flash.payment_order_not_expense');
        }
        if ($transaction->archived) {
            throw new PaymentOrderException('flash.payment_order_expense_cancelled');
        }
        if (PaymentOrder::where('transaction_id', $transaction->id)->exists()) {
            throw new PaymentOrderException('flash.payment_order_exists');
        }

        return YearlySequence::retrying(fn () => DB::transaction(function () use ($transaction, $by) {
            $date = $transaction->transaction_date ?? now();
            $year = $date->year;
            $supplier = $transaction->supplier_id ? Supplier::find($transaction->supplier_id) : null;
            $supplierId = $supplier?->id;
            // Without a supplier the expense's account may be the club's own (the paying side): never freeze that.
            $payeeAccount = $supplier === null && ! self::isClubAccount($transaction->payment_account);
            $order = PaymentOrder::create([
                'year' => $year,
                'number' => YearlySequence::next('payment_orders', $year),
                'status' => PaymentOrder::PAID,
                'order_date' => $date->format('Y-m-d'),
                'subject' => Str::limit($transaction->title ?: ($transaction->description ?: '#'.$transaction->id), 150, ''),
                'amount' => $transaction->amount,
                'finance_category_id' => $transaction->finance_category_id,
                'finance_account_id' => $transaction->finance_account_id,
                'payment_method' => $transaction->payment_method,
                'beneficiary_type' => $supplierId ? 'supplier' : null,
                'beneficiary_id' => $supplierId,
                'beneficiary_name' => $supplier?->name ?? ($payeeAccount ? $transaction->payment_holder : null),
                'beneficiary_bank' => $supplier ? ($supplier->bank_name ?: $transaction->payment_bank_name) : ($payeeAccount ? $transaction->payment_bank_name : null),
                'beneficiary_rib' => $supplier ? ($supplier->rib ?: $transaction->payment_account) : ($payeeAccount ? $transaction->payment_account : null),
                'payment_reference' => $transaction->payment_reference,
                'payment_date' => $date->format('Y-m-d'),
                'invoice_number' => $transaction->invoice_number,
                'invoice_date' => $transaction->invoice_date,
                'board_meeting_id' => $transaction->board_meeting_id,
                'transaction_id' => $transaction->id,
                'created_by' => $by->id,
                'approved_by' => $by->id,
                'approved_at' => now(),
                'paid_at' => now(),
            ]);
            ActivityRecorder::record($by, ActivityAction::PAYMENT_ORDER_CREATED, $order, [
                'amount' => (float) $order->amount, 'reference' => $order->reference,
            ]);

            return $order->fresh();
        }));
    }
}
