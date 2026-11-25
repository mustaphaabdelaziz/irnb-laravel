<?php

namespace App\Http\Requests\PurchaseOrder;

use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A draft sends every field and its lines. Once sent, only the unit prices
 * can change (until a payment order is linked), so the form then sends just
 * `lines[*].id` and `lines[*].unit_price` — anything else is not validated,
 * so it never reaches the service.
 */
class SavePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $order = $this->route('purchaseOrder');
        $price = ['nullable', 'numeric', 'min:0', 'max:99999999'];

        if ($order instanceof PurchaseOrder && $order->status !== PurchaseOrder::DRAFT) {
            return [
                'lines' => ['required', 'array', 'min:1'],
                'lines.*.id' => ['required', 'integer'],
                'lines.*.unit_price' => $price,
            ];
        }
        $current = $order instanceof PurchaseOrder ? $order->supplier_id : null;

        return [
            'order_date' => ['required', 'date'],
            // An active supplier — or the one the draft already names, so a draft whose
            // supplier was deactivated later can still be edited.
            'supplier_id' => [
                'required', 'integer',
                Rule::exists('suppliers', 'id')
                    ->where(fn ($query) => $query->where(fn ($query) => $query->where('is_active', true)
                        ->when($current, fn ($query, $id) => $query->orWhere('id', $id)))),
            ],
            'requested_by' => ['nullable', 'string', 'max:200'],
            'delivery_mode' => ['nullable', 'string', 'max:200'],
            'payment_mode' => ['nullable', Rule::in(PaymentOrder::METHODS)],
            'observation' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.equipment_catalog_id' => ['nullable', 'integer', Rule::exists('equipment_catalogs', 'id')],
            'lines.*.designation' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price' => $price,
        ];
    }
}
