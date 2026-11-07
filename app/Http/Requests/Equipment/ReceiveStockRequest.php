<?php

namespace App\Http\Requests\Equipment;

use App\Models\EquipmentCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceiveStockRequest extends FormRequest
{
    public const MAX_NUMBERED_UNITS = 200;

    public function rules(): array
    {
        return [
            'catalog_id' => ['required', 'exists:equipment_catalogs,id'],
            // A serial catalog gets one numbered row per unit, so a typo must
            // not create thousands of rows; a plain lot is a single row.
            'quantity' => ['required', 'integer', 'min:1', 'max:'.($this->receivesNumberedUnits() ? self::MAX_NUMBERED_UNITS : 100000)],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_date' => ['required', 'date'],
            'condition' => ['required', 'in:New,Good,Fair,Poor,Damaged'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            // received_via is a plain string column (SQLite enum check
            // constraints cannot be altered later), so the allowed values are
            // enforced here.
            'received_via' => ['nullable', 'in:purchase,donation,opening_balance,adjustment'],
            'record_expense' => ['boolean'],
            'finance_account_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_accounts', 'id')->where('is_active', true),
            ],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['exists:branches,id'],
        ];
    }

    private function receivesNumberedUnits(): bool
    {
        $catalogId = $this->input('catalog_id');

        return is_numeric($catalogId)
            && (bool) EquipmentCatalog::whereKey((int) $catalogId)->value('requires_serial');
    }
}
