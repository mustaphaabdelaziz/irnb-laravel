<?php

namespace App\Http\Controllers;

use App\Models\WebsiteConfig;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class PublicController extends Controller
{
    public function home(): Response
    {
        $config = WebsiteConfig::singleton();
        $locale = app()->getLocale();

        $pick = function ($value) use ($locale) {
            if (! is_array($value)) {
                return $value;
            }

            return $value[$locale] ?? $value['en'] ?? $value['ar'] ?? collect($value)->filter()->first();
        };

        $settings = $config->settings ?? [];
        $branding = $config->branding ?? [];

        return Inertia::render('Public/Home', [
            'club' => [
                'name' => $pick($config->club_name),
                'shortName' => $config->club_short_name,
                'tagline' => $pick($config->tagline),
                'description' => $pick($config->description),
                'motto' => $pick($config->motto),
                'founder' => $config->founder,
                'foundingYear' => $config->founding_date?->year,
                'email' => $config->contact_email,
                'phone' => $config->contact_phone,
                'mobile' => $config->contact_mobile,
                'address' => $config->full_address ?: null,
                'social' => array_filter($config->social_media ?? []),
                'leadership' => array_filter($config->leadership ?? []),
                'logo' => $branding['logo'] ?? null,
                'heroImages' => $branding['heroImages'] ?? [],
            ],
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register') && ($settings['enableRegistration'] ?? true),
        ]);
    }
}
