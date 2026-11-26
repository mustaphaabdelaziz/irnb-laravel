<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A mission order (أمر بمهمة / ordre de mission): destination, reason,
 * departure and return days, transport, and one or more travelers, each
 * printed on their own sheet. Its reference is the mail-register entry the
 * first print takes (MissionOrders::reference()); imported orders keep their
 * old text in legacy_reference. Cancelled, never deleted.
 */
class MissionOrder extends Model
{
    use SerializesClubDates;

    public const ACTIVE = 'active';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::ACTIVE, self::CANCELLED];

    /** The Access table the imported orders come from. */
    public const LEGACY_SOURCE = 'ordre_de_mission';

    /** What the form sets; status, legacy and audit columns belong to the service. */
    public const FIELDS = ['order_date', 'destination', 'reason', 'departure_date', 'return_date', 'transport'];

    protected $fillable = [
        'order_date', 'destination', 'reason', 'departure_date', 'return_date', 'transport',
        'status', 'is_legacy', 'legacy_reference', 'legacy_source', 'created_by', 'cancelled_by', 'cancelled_at',
    ];

    protected $hidden = ['lock_version'];

    protected function casts(): array
    {
        return [
            'order_date' => 'date:Y-m-d',
            'departure_date' => 'date:Y-m-d',
            'return_date' => 'date:Y-m-d',
            'is_legacy' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Active and typed in this app: editable, cancellable, and its first print takes a reference. */
    public function isEditable(): bool
    {
        return $this->status === self::ACTIVE && ! $this->is_legacy;
    }

    public function travelers(): HasMany
    {
        return $this->hasMany(MissionOrderTraveler::class)->orderBy('sort_order')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
