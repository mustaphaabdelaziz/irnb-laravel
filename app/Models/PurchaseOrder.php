<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A purchase order to a supplier (سند طلب): draft → sent → received, or
 * cancelled. No approve step — money is controlled at the payment order made
 * from it, and at most one active (non-cancelled) payment order per order.
 */
class PurchaseOrder extends Model
{
    use SerializesClubDates;

    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::SENT, self::RECEIVED, self::CANCELLED];

    protected $fillable = [
        'year', 'number', 'legacy_reference', 'is_legacy', 'legacy_source', 'status', 'order_date',
        'supplier_id', 'requested_by', 'delivery_mode', 'payment_mode', 'observation',
        'created_by', 'sent_by', 'sent_at', 'received_by', 'received_at', 'cancelled_by', 'cancelled_at',
    ];

    protected $appends = ['reference'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'number' => 'integer',
            'is_legacy' => 'boolean',
            'order_date' => 'date:Y-m-d',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** NN/YYYY, or the old Access reference for the imported order. */
    protected function reference(): Attribute
    {
        return Attribute::make(get: fn () => $this->is_legacy || $this->number === null
            ? (string) $this->legacy_reference
            : sprintf('%02d/%d', $this->number, $this->year));
    }

    /** Everything is editable only while draft; imported orders never. */
    public function isEditable(): bool
    {
        return $this->status === self::DRAFT && ! $this->is_legacy;
    }

    public function hasActivePaymentOrder(): bool
    {
        return $this->paymentOrders()->where('payment_orders.status', '!=', PaymentOrder::CANCELLED)->exists();
    }

    /** Sent or received: unit prices stay editable until an active payment order is linked. */
    public function canEditPrices(): bool
    {
        return in_array($this->status, [self::SENT, self::RECEIVED], true)
            && ! $this->is_legacy
            && ! $this->hasActivePaymentOrder();
    }

    /** Every line has a price (the rule for the total in words and for making a payment order). */
    public function isFullyPriced(): bool
    {
        return $this->lines->isNotEmpty() && $this->lines->every(fn (PurchaseOrderLine $line) => $line->unit_price !== null);
    }

    /** Sum of the priced lines' amounts. */
    public function total(): float
    {
        return round((float) $this->lines->sum(fn (PurchaseOrderLine $line) => (float) $line->amount), 2);
    }

    /**
     * Received, not imported, with a supplier (this one when given), and no
     * active payment order — other than $exceptPaymentOrderId, the draft that
     * already holds the link while it is edited.
     */
    public function scopeLinkable(Builder $query, ?int $supplierId = null, ?int $exceptPaymentOrderId = null): void
    {
        $query->where('purchase_orders.status', self::RECEIVED)
            ->where('purchase_orders.is_legacy', false)
            ->whereNotNull('purchase_orders.supplier_id')
            ->when($supplierId !== null, fn (Builder $q) => $q->where('purchase_orders.supplier_id', $supplierId))
            ->whereDoesntHave('paymentOrders', fn (Builder $q) => $q
                ->where('payment_orders.status', '!=', PaymentOrder::CANCELLED)
                ->when($exceptPaymentOrderId, fn (Builder $q, int $id) => $q->whereKeyNot($id)));
    }

    /**
     * Atomically reserve this order for a payment order: the conditional
     * update succeeds only while it is still linkable, so two payment orders
     * saved at the same instant cannot both take it.
     */
    public static function claimForPaymentOrder(int $id, int $supplierId, ?int $exceptPaymentOrderId = null): bool
    {
        return self::query()->whereKey($id)->linkable($supplierId, $exceptPaymentOrderId)
            ->update(['lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]) === 1;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function paymentOrders(): HasMany
    {
        return $this->hasMany(PaymentOrder::class);
    }

    public function equipmentItems(): HasMany
    {
        return $this->hasMany(EquipmentItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
