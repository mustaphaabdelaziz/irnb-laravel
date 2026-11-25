<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentOrder extends Model
{
    use SerializesClubDates;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::APPROVED, self::PAID, self::CANCELLED];

    public const BENEFICIARY_TYPES = ['supplier', 'user', 'board_member'];

    public const METHODS = ['cash', 'bank', 'ccp', 'baridimob', 'cheque', 'other'];

    protected $fillable = [
        'year', 'number', 'legacy_reference', 'is_legacy', 'legacy_source', 'status', 'order_date',
        'subject', 'amount', 'finance_category_id', 'finance_account_id', 'payment_method',
        'beneficiary_type', 'beneficiary_id', 'beneficiary_name', 'beneficiary_bank', 'beneficiary_rib',
        'payment_reference', 'payment_date', 'invoice_number', 'invoice_date', 'board_meeting_id',
        'purchase_order_id', 'observation', 'transaction_id', 'created_by', 'approved_by', 'approved_at',
        'paid_at', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $appends = ['reference'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'number' => 'integer',
            'is_legacy' => 'boolean',
            'amount' => 'decimal:2',
            'order_date' => 'date:Y-m-d',
            'payment_date' => 'date:Y-m-d',
            'invoice_date' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** NN/YYYY, or the old Access reference for imported rows. */
    protected function reference(): Attribute
    {
        return Attribute::make(get: fn () => $this->is_legacy || $this->number === null
            ? (string) $this->legacy_reference
            : sprintf('%02d/%d', $this->number, $this->year));
    }

    /** Only a draft is changed; approved, paid, cancelled and imported orders are locked. */
    public function isEditable(): bool
    {
        return $this->status === self::DRAFT && ! $this->is_legacy;
    }

    /** The beneficiary's name as its record says it now (drafts show this; approval freezes it). */
    public function liveBeneficiaryName(): ?string
    {
        if ($this->beneficiary_id === null) {
            return null;
        }

        return match ($this->beneficiary_type) {
            'supplier' => Supplier::whereKey($this->beneficiary_id)->value('name'),
            'user' => User::find($this->beneficiary_id)?->name,
            'board_member' => BoardMember::withPlayer()->find($this->beneficiary_id)?->name,
            default => null,
        };
    }

    /** The name printed and listed: frozen once approved, live while draft. */
    public function beneficiaryLabel(): ?string
    {
        return $this->status === self::DRAFT ? ($this->liveBeneficiaryName() ?? $this->beneficiary_name) : $this->beneficiary_name;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'beneficiary_id');
    }

    public function financeCategory(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class);
    }

    public function financeAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class);
    }

    public function boardMeeting(): BelongsTo
    {
        return $this->belongsTo(BoardMeeting::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
