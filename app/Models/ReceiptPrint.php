<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A receipt that left the printer. See ReceiptPrinter for how rows are made
 * and checked; the snapshot keys are ReceiptPrinter::snapshot()'s.
 */
class ReceiptPrint extends Model
{
    use SerializesClubDates;

    public const FORMAT_THERMAL = 'thermal';

    public const FORMAT_A4 = 'a4';

    protected $fillable = [
        'transaction_id',
        'transaction_number',
        'code',
        'copy_number',
        'format',
        'printed_by_user_id',
        'snapshot',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'copy_number' => 'integer',
            'transaction_number' => 'integer',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by_user_id');
    }

    public function isOriginal(): bool
    {
        return $this->copy_number === 1;
    }

    /** "4821 3977 1203": easier to read aloud or type by hand than twelve digits in a row. */
    public function displayCode(): string
    {
        return trim(chunk_split($this->code, 4, ' '));
    }
}
