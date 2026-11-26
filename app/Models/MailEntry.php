<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the mail register (سجل البريد): an outgoing letter (with an
 * optional text printed on the letterhead) or an incoming one. Numbered per
 * direction and calendar year; cancelled entries keep their number and are
 * never deleted. An entry made from a print (absence letter, convocation,
 * purchase order) points at its document through source_type / source_id /
 * source_key, and holds source_lock while active.
 */
class MailEntry extends Model
{
    use SerializesClubDates;

    public const OUT = 'out';

    public const IN = 'in';

    public const DIRECTIONS = [self::OUT, self::IN];

    public const ACTIVE = 'active';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::ACTIVE, self::CANCELLED];

    public const SOURCE_ABSENCE_LETTER = 'absence_letter';

    public const SOURCE_GAME = 'game_convocation';

    public const SOURCE_PURCHASE_ORDER = 'purchase_order';

    /** Every field the form may send; fieldsFor() and editableFields() narrow it. */
    public const FORM_FIELDS = ['entry_date', 'correspondent', 'address', 'subject', 'body', 'sender_reference', 'received_date', 'notes'];

    /** What an entry made from a print may still change: its document fixes direction, number, date and text. */
    public const SOURCE_FIELDS = ['correspondent', 'address', 'subject', 'notes'];

    protected $fillable = [
        'direction', 'year', 'number', 'status', 'entry_date', 'correspondent', 'address', 'subject', 'body',
        'sender_reference', 'received_date', 'notes', 'scan_path', 'scan_name',
        'source_type', 'source_id', 'source_key', 'source_lock',
        'is_legacy', 'legacy_reference', 'legacy_source', 'created_by', 'cancelled_by', 'cancelled_at',
    ];

    protected $hidden = ['scan_path', 'source_lock', 'lock_version'];

    protected $appends = ['reference', 'has_scan'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'number' => 'integer',
            'source_id' => 'integer',
            'is_legacy' => 'boolean',
            'entry_date' => 'date:Y-m-d',
            'received_date' => 'date:Y-m-d',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Outgoing 2026/01; incoming with the app language's mark (و 2026/01, A-2026/01, IN-2026/01); imported rows keep their old text. */
    protected function reference(): Attribute
    {
        return Attribute::make(get: function () {
            if ($this->is_legacy || $this->number === null) {
                return (string) $this->legacy_reference;
            }
            $reference = sprintf('%d/%02d', $this->year, $this->number);

            return $this->direction === self::IN ? UiLang::get('mreg.in_mark').$reference : $reference;
        });
    }

    protected function hasScan(): Attribute
    {
        return Attribute::make(get: fn () => (string) $this->scan_path !== '');
    }

    /**
     * The form fields a direction uses: the letter text is outgoing only, the
     * sender's reference and received date incoming only.
     *
     * @return list<string>
     */
    public static function fieldsFor(string $direction): array
    {
        $unused = $direction === self::OUT ? ['sender_reference', 'received_date'] : ['body'];

        return array_values(array_diff(self::FORM_FIELDS, $unused));
    }

    /** The marker an active entry made from a print holds (unique); cancelling clears it. */
    public static function lockFor(string $type, int $id, string $key): string
    {
        return $type.'|'.$id.'|'.$key;
    }

    public function isFromSource(): bool
    {
        return $this->source_type !== null;
    }

    /** An active outgoing entry with a letter text prints on the letterhead. */
    public function isPrintable(): bool
    {
        return $this->direction === self::OUT && $this->status === self::ACTIVE && trim((string) $this->body) !== '';
    }

    /**
     * What the edit form may still change. The scan follows the same rule
     * (none once cancelled) and is handled apart.
     *
     * @return list<string>
     */
    public function editableFields(): array
    {
        if ($this->status !== self::ACTIVE) {
            return [];
        }
        if ($this->is_legacy) {
            // Lets the secretary annotate and scan the old letters, nothing else.
            return ['notes'];
        }
        if ($this->isFromSource()) {
            return self::SOURCE_FIELDS;
        }

        return self::fieldsFor($this->direction);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('mail_entries.status', self::ACTIVE);
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
