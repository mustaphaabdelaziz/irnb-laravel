<?php

namespace App\Models;

use App\Support\Media;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Normalise the stored photo URL to a host-relative /media path (web + desktop). */
    protected function pictureUrl(): Attribute
    {
        return Attribute::make(get: fn ($value) => Media::path($value));
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'membership_id',
        'firstname',
        'lastname',
        'phones',
        'birthdate',
        'gender',
        'picture_url',
        'picture_filename',
        'is_user',
        'is_active',
        'approved',
        'state',
        'city',
        'is_student',
        'member_job_id',
        'category_id',
        'team',
        'position',
        'skill_level',
        'health',
        'privileges',
        'preferred_lng',
        'logged_in_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'phones' => 'array',
            'birthdate' => 'date',
            'is_user' => 'boolean',
            'is_active' => 'boolean',
            'approved' => 'boolean',
            'is_student' => 'boolean',
            'skill_level' => 'integer',
            'health' => 'array',
            'privileges' => 'array',
            'logged_in_at' => 'datetime',
        ];
    }

    public function memberJob(): BelongsTo
    {
        return $this->belongsTo(MemberJob::class, 'member_job_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function recordedTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'recorded_by_user_id');
    }

    public function receivedTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'received_by_user_id');
    }

    public function equipmentHistories(): HasMany
    {
        return $this->hasMany(EquipmentHistory::class, 'user_id');
    }

    public function websiteConfigUpdates(): HasMany
    {
        return $this->hasMany(WebsiteConfig::class, 'last_modified_by_user_id');
    }

    public function equipmentRentals(): MorphMany
    {
        return $this->morphMany(EquipmentRental::class, 'rentable');
    }

    public function getFullnameAttribute(): string
    {
        $full = trim((string) ($this->firstname.' '.$this->lastname));

        if ($full !== '') {
            return $full;
        }

        return (string) ($this->name ?? '');
    }
}
