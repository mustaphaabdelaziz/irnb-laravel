<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'singleton_key',
        'club_name',
        'club_short_name',
        'tagline',
        'description',
        'founding_date',
        'founder',
        'motto',
        'contact_email',
        'contact_phone',
        'contact_mobile',
        'contact_fax',
        'contact_website',
        'contact_address',
        'social_media',
        'banking_info',
        'legal_info',
        'leadership',
        'branding',
        'facilities',
        'settings',
        'seo',
        'documents',
        'last_modified_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'club_name' => 'array',
            'tagline' => 'array',
            'description' => 'array',
            'founding_date' => 'date',
            'motto' => 'array',
            'contact_address' => 'array',
            'social_media' => 'array',
            'banking_info' => 'array',
            'legal_info' => 'array',
            'leadership' => 'array',
            'branding' => 'array',
            'facilities' => 'array',
            'settings' => 'array',
            'seo' => 'array',
            'documents' => 'array',
        ];
    }

    public function lastModifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_modified_by_user_id');
    }

    public static function singleton(): self
    {
        // A single-row, PK-indexed lookup — cheap enough to run per request, and
        // safe across tests/requests (no static/cache state to go stale).
        return static::query()->firstOrCreate(
            ['singleton_key' => 1],
            [
                'club_name' => [
                    'ar' => 'Sports Club',
                    'fr' => 'Club Sportif',
                    'en' => 'Sports Club',
                ],
                'tagline' => [
                    'ar' => 'Excellence in sports',
                    'fr' => 'Excellence en sport',
                    'en' => 'Excellence in sports',
                ],
                'settings' => [
                    'currency' => 'DZD',
                    'currencySymbol' => 'DZD',
                    'timezone' => 'Africa/Algiers',
                    'dateFormat' => 'DD/MM/YYYY',
                    'defaultLanguage' => 'ar',
                    'fiscalYearStart' => '01-01',
                    'enableRegistration' => true,
                    'enableDonations' => true,
                    'maintenanceMode' => false,
                ],
            ]
        );
    }

    public function getIsConfiguredAttribute(): bool
    {
        return ! empty($this->contact_email) && ! empty($this->contact_phone);
    }

    public function getFullAddressAttribute(): string
    {
        $addr = $this->contact_address ?? [];
        $parts = array_filter([
            $addr['street'] ?? null,
            $addr['city'] ?? null,
            $addr['state'] ?? null,
            $addr['zip'] ?? null,
            $addr['country'] ?? null,
        ]);

        return implode(', ', $parts);
    }

    public function getCcpFormattedAttribute(): string
    {
        $banking = $this->banking_info ?? [];
        $ccp = $banking['ccp'] ?? [];
        $account = $ccp['accountNumber'] ?? '';
        $key = $ccp['key'] ?? '';

        if (empty($account)) {
            return '';
        }

        return $key ? "{$account} clé {$key}" : $account;
    }
}
