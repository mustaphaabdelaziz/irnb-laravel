<?php

namespace App\Http\Requests\Transaction;

use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    private const METHODS = ['cash', 'bank', 'ccp', 'baridimob', 'cheque', 'other'];

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
            'payment_method' => ['nullable', 'string', 'max:255', Rule::in(array_values(array_filter([...self::METHODS, $this->currentPaymentMethod()])))],
            'payment_account' => ['nullable', 'string', 'max:255'],
            'payment_ccp_key' => ['nullable', 'string', 'max:255'],
            'payment_bank_name' => ['nullable', 'string', 'max:255'],
            'payment_holder' => ['nullable', 'string', 'max:255'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'invoice_date' => ['nullable', 'date'],
            'cheque_date' => ['nullable', 'date'],
            'board_meeting_id' => ['nullable', 'integer', Rule::exists('board_meetings', 'id')],
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

    /** An entry already stored with a free-text method (old import, player payment) keeps it on edit. */
    private function currentPaymentMethod(): ?string
    {
        $transaction = $this->route('transaction');

        return $transaction instanceof Transaction ? $transaction->payment_method : null;
    }

    /** The caisse the transaction being updated is booked on (route-bound), if any. */
    private function currentFinanceAccountId(): ?int
    {
        $transaction = $this->route('transaction');

        return $transaction instanceof Transaction ? $transaction->finance_account_id : null;
    }
}
