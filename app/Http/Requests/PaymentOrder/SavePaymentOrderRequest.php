<?php

namespace App\Http\Requests\PaymentOrder;

use App\Models\PaymentOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePaymentOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'beneficiary_rib' => $this->filled('beneficiary_rib') ? preg_replace('/\s+/', '', (string) $this->input('beneficiary_rib')) : null,
        ]);
    }

    public function rules(): array
    {
        $table = match ($this->input('beneficiary_type')) {
            'supplier' => 'suppliers',
            'user' => 'users',
            'board_member' => 'board_members',
            default => null,
        };
        $order = $this->route('paymentOrder');
        $current = $order instanceof PaymentOrder ? $order->finance_account_id : null;

        return [
            'order_date' => ['required', 'date'],
            'subject' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'finance_category_id' => ['nullable', 'integer', Rule::exists('finance_categories', 'id')->where('type', 'expense')],
            // An active register other than the opening fund — or the one the order already
            // sits on, so an order whose register was deactivated later can still be edited.
            'finance_account_id' => [
                'nullable', 'integer',
                Rule::exists('finance_accounts', 'id')
                    ->where(fn ($query) => $query->where(fn ($query) => $query->where('is_active', true)->where('is_opening_fund', false)
                        ->when($current, fn ($query, $id) => $query->orWhere('id', $id)))),
            ],
            'payment_method' => ['nullable', Rule::in(PaymentOrder::METHODS)],
            'beneficiary_type' => ['nullable', Rule::in(PaymentOrder::BENEFICIARY_TYPES)],
            'beneficiary_id' => ['nullable', 'required_with:beneficiary_type', 'integer', ...($table ? [Rule::exists($table, 'id')] : [])],
            'beneficiary_bank' => ['nullable', 'string', 'max:150'],
            'beneficiary_rib' => ['nullable', 'string', 'regex:/^\d{10,30}$/'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'payment_date' => ['nullable', 'date'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'invoice_date' => ['nullable', 'date'],
            'board_meeting_id' => ['nullable', 'integer', Rule::exists('board_meetings', 'id')],
            'observation' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
