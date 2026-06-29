<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Player extends Model
{
    use HasFactory;

    protected $fillable = [
        'membership_id',
        'firstname',
        'lastname',
        'nickname',
        'father',
        'grandfather',
        'birthdate',
        'gender',
        'picture_url',
        'picture_filename',
        'phones',
        'email',
        'status_class',
        'status_value',
        'state',
        'city',
        'is_student',
        'member_job_id',
        'join_year',
        'archived',
        'category_id',
        'position_id',
        'team',
        'skill_level',
        'health_medical_conditions',
        'health_blood_group_rhesus',
        'outstanding_debt',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'phones' => 'array',
            'is_student' => 'boolean',
            'join_year' => 'integer',
            'archived' => 'boolean',
            'skill_level' => 'integer',
            'outstanding_debt' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function memberJob(): BelongsTo
    {
        return $this->belongsTo(MemberJob::class, 'member_job_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function playerSubscriptions(): HasMany
    {
        return $this->hasMany(PlayerSubscription::class);
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(PlayerEmergencyContact::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(PlayerAchievement::class);
    }

    public function equipmentRentals(): MorphMany
    {
        return $this->morphMany(EquipmentRental::class, 'rentable');
    }

    public function getFullnameAttribute(): string
    {
        $parts = [
            $this->lastname,
            $this->firstname,
        ];

        if ($this->nickname) {
            $parts[] = '('.$this->nickname.')';
        }

        $full = trim(implode(' ', array_filter($parts)));

        if ($this->father) {
            $full .= ' بن '.$this->father;

            if ($this->grandfather) {
                $full .= ' بن '.$this->grandfather;
            }
        }

        return trim($full);
    }

    public function calculateTotalDebt(): float
    {
        return (float) $this->playerSubscriptions()
            ->whereHas('transaction', fn ($q) => $q->where('category', '!=', 'donation'))
            ->selectRaw('COALESCE(SUM(CASE WHEN amount_owed - amount_paid > 0 THEN amount_owed - amount_paid ELSE 0 END), 0) as total')
            ->value('total');
    }
}
