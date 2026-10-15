<?php

namespace App\Http\Requests\Transaction;

use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $current = $this->currentFinanceAccountId();

        return [
            'title' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'min:0'],
            'transaction_date' => ['nullable', 'date'],
            'transaction_type' => ['required', 'in:income,expense'],
            'finance_category_id' => [
                'required',
                Rule::exists('finance_categories', 'id')
                    ->where('type', $this->input('transaction_type')),
            ],
            'sub_category' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'payment_account' => ['nullable', 'string', 'max:255'],
            'payment_ccp_key' => ['nullable', 'string', 'max:255'],
            'payment_bank_name' => ['nullable', 'string', 'max:255'],
            'payment_holder' => ['nullable', 'string', 'max:255'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            // An active caisse — or the one the entry already sits on, so an entry
            // whose caisse was deactivated later can still be edited.
            'finance_account_id' => [
                'nullable',
                Rule::exists('finance_accounts', 'id')
                    ->where(fn ($query) => $query->where(fn ($query) => $query->where('is_active', true)
                        ->when($current, fn ($query, $id) => $query->orWhere('id', $id)))),
            ],
            // A transaction points at a player by id, never by name — and the id must exist.
            'related_entity_type' => ['nullable', 'required_with:related_entity_id', 'in:Player'],
            'related_entity_id' => ['nullable', 'integer', Rule::exists('players', 'id')],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:Paid,Partial,Unpaid,Exempt'],
            'receipt' => ['nullable', 'file', 'max:10240'],
        ];
    }

    /** The caisse the transaction being updated is booked on (route-bound), if any. */
    private function currentFinanceAccountId(): ?int
    {
        $transaction = $this->route('transaction');

        return $transaction instanceof Transaction ? $transaction->finance_account_id : null;
    }
}
