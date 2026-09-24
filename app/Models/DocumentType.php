<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of document a player's file should hold (birth certificate, medical
 * certificate, ...). Managed in Settings → Document types.
 */
class DocumentType extends Model
{
    use HasLocalizedName;

    /** The seeded Photo type: satisfied by the player's profile picture, never uploaded. */
    public const PHOTO = 'photo';

    public const VALIDITY_NONE = 'none';

    public const VALIDITY_SEASON = 'season';

    public const VALIDITY_DATE = 'date';

    public const VALIDITIES = [self::VALIDITY_NONE, self::VALIDITY_SEASON, self::VALIDITY_DATE];

    protected $fillable = [
        'code',
        'name',
        'name_ar',
        'name_fr',
        'name_en',
        'is_required',
        'validity',
        'max_age',
        'is_active',
        'sort_order',
    ];

    protected $appends = [
        'localized_name',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'max_age' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function playerDocuments(): HasMany
    {
        return $this->hasMany(PlayerDocument::class);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    public function isPhoto(): bool
    {
        return $this->code === self::PHOTO;
    }

    /**
     * The earliest birth date this type still applies to, or null when it has
     * no age limit. A player applies while their birthdate is ON OR AFTER it:
     * with max_age 17 on 2026-09-23 that is 2008-09-24 — 17 today, 18 tomorrow.
     *
     * This single boundary is what both the PHP checklist and its SQL twin
     * compare against (DocumentChecklist), so they can never disagree about
     * a birthday.
     */
    public function earliestApplicableBirthdate(?CarbonInterface $today = null): ?CarbonImmutable
    {
        if ($this->max_age === null) {
            return null;
        }

        return CarbonImmutable::parse($today ?? CarbonImmutable::today())
            ->startOfDay()
            ->subYearsNoOverflow($this->max_age + 1)
            ->addDay();
    }

    /** Owner decision: a player with no birth date is treated as not limited. */
    public function appliesTo(Player $player, ?CarbonInterface $today = null): bool
    {
        $earliest = $this->earliestApplicableBirthdate($today);

        return $earliest === null
            || $player->birthdate === null
            || $player->birthdate->gte($earliest);
    }

    /**
     * When a document received on $receivedAt stops being valid, as Y-m-d, or
     * null for "never". `date` types take the date the user entered.
     */
    public function validUntilFor(CarbonInterface|string $receivedAt, ?string $entered = null): ?string
    {
        return match ($this->validity) {
            self::VALIDITY_SEASON => Season::forDate($receivedAt)->end()->toDateString(),
            self::VALIDITY_DATE => $entered ? CarbonImmutable::parse($entered)->toDateString() : null,
            default => null,
        };
    }
}
