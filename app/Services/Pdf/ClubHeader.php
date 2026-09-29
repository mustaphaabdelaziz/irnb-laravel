<?php

namespace App\Services\Pdf;

use App\Models\WebsiteConfig;
use App\Support\Media;

/**
 * The club block every PDF opens with (`pdf.partials.header`): the name in
 * the app locale and the logo as a file path mPDF can read. Same shape as
 * the private club() helpers of ReportController and PlayerPrintController.
 */
final class ClubHeader
{
    /** @return array{name: ?string, logo: ?string, address: ?string, phone: ?string, email: ?string, currency: string} */
    public static function data(): array
    {
        $config = WebsiteConfig::singleton();
        $locale = app()->getLocale();
        $name = $config->club_name;

        return [
            'name' => is_array($name) ? ($name[$locale] ?? $name['en'] ?? $name['ar'] ?? collect($name)->filter()->first()) : $name,
            'logo' => Media::localFile($config->branding['logo'] ?? null),
            'address' => $config->full_address ?: null,
            'phone' => $config->contact_phone,
            'email' => $config->contact_email,
            'currency' => $config->settings['currencySymbol'] ?? $config->settings['currency'] ?? 'DZD',
        ];
    }
}
