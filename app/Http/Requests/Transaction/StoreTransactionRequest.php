<?php

namespace App\Http\Requests\Transaction;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0'],
            'transaction_date' => ['nullable', 'date'],
            'transaction_type' => ['required', 'in:income,expense'],
            'finance_category_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('finance_categories', 'id')
                    ->where('type', $this->input('transaction_type')),
            ],
            'sub_category' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'payment_account' => ['nullable', 'string', 'max:255'],
            'payment_ccp_key' => ['nullable', 'string', 'max:255'],
            'payment_bank_name' => ['nullable', 'string', 'max:255'],
            'payment_holder' => ['nullable', 'string', 'max:255'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'related_entity_type' => ['nullable', 'string', 'max:255'],
            'related_entity_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:Paid,Partial,Unpaid,Exempt'],
            'receipt' => ['nullable', 'file', 'max:10240'],
            'fiscal_year' => ['nullable', 'integer', 'min:2000', 'max:'.(date('Y') + 1)],
        ];
    }
}
