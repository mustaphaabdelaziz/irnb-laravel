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
            'category' => ['required', 'string', 'in:subscription,donation,equipment,salary,debt_payment,other'],
            'sub_category' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'payment_account' => ['nullable', 'string', 'max:255'],
            'related_entity_type' => ['nullable', 'string', 'max:255'],
            'related_entity_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:Paid,Partial,Unpaid,Exempt'],
            'receipt' => ['nullable', 'file', 'max:10240'],
            'fiscal_year' => ['nullable', 'integer', 'min:2000', 'max:'.(date('Y') + 1)],
        ];
    }
}
