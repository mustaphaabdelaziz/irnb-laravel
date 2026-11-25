<?php

namespace App\Services\Finance;

use App\Exceptions\PaymentOrderException;
use App\Models\FinanceCategory;
use App\Models\PaymentOrder;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Documents\YearlySequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every payment order state change, one DB transaction each:
 * draft (numbered) → approved (beneficiary frozen) → paid (expense created) or cancelled.
 */
final class PaymentOrderService
{
    /** @param array<string, mixed> $data validated form fields */
    public function create(array $data, User $by): PaymentOrder
    {
        return YearlySequence::retrying(fn () => DB::transaction(function () use ($data, $by) {
            $year = Carbon::parse($data['order_date'])->year;
            $order = PaymentOrder::create([
                ...$data,
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
            $order->update($data);
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

        return DB::transaction(function () use ($order, $by) {
            $supplier = $order->beneficiary_type === 'supplier' ? Supplier::find($order->beneficiary_id) : null;
            $order->update([
                'status' => PaymentOrder::APPROVED,
                'beneficiary_name' => $order->liveBeneficiaryName() ?? $order->beneficiary_name,
                'beneficiary_bank' => $order->beneficiary_bank ?: $supplier?->bank_name,
                'beneficiary_rib' => $order->beneficiary_rib ?: $supplier?->rib,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ]);
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
        $category = $order->finance_category_id ? FinanceCategory::find($order->finance_category_id) : null;
        if ($category === null || $category->type !== 'expense' || $order->finance_account_id === null) {
            throw new PaymentOrderException('flash.payment_order_missing_finance');
        }

        return DB::transaction(function () use ($order, $by, $paymentDate, $category) {
            $date = $paymentDate ?? ($order->payment_date?->format('Y-m-d')) ?? now()->format('Y-m-d');
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

            $order->update([
                'status' => PaymentOrder::PAID,
                'transaction_id' => $transaction->id,
                'payment_date' => $date,
                'paid_at' => now(),
            ]);
            ActivityRecorder::record($by, ActivityAction::PAYMENT_ORDER_PAID, $order, [
                'amount' => (float) $order->amount, 'reference' => $order->reference,
            ]);

            return $order->fresh();
        });
    }

    public function cancel(PaymentOrder $order, User $by, string $reason): PaymentOrder
    {
        if (! in_array($order->status, [PaymentOrder::DRAFT, PaymentOrder::APPROVED], true) || $order->is_legacy) {
            throw new PaymentOrderException('flash.payment_order_cannot_cancel');
        }

        return DB::transaction(function () use ($order, $by, $reason) {
            $order->update([
                'status' => PaymentOrder::CANCELLED,
                'cancelled_by' => $by->id,
                'cancelled_at' => now(),
                'cancel_reason' => Str::limit(trim($reason), 500, ''),
            ]);
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
        if (PaymentOrder::where('transaction_id', $transaction->id)->exists()) {
            throw new PaymentOrderException('flash.payment_order_exists');
        }

        return YearlySequence::retrying(fn () => DB::transaction(function () use ($transaction, $by) {
            $date = $transaction->transaction_date ?? now();
            $year = $date->year;
            $supplierId = $transaction->supplier_id;
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
                'beneficiary_name' => $supplierId ? Supplier::whereKey($supplierId)->value('name') : $transaction->payment_holder,
                'beneficiary_bank' => $transaction->payment_bank_name,
                'beneficiary_rib' => $transaction->payment_account,
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
