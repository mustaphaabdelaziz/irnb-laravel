<?php

namespace App\Http\Requests\WebsiteConfig;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWebsiteConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'club_name' => ['nullable', 'array'],
            'club_name.ar' => ['nullable', 'string', 'max:255'],
            'club_name.fr' => ['nullable', 'string', 'max:255'],
            'club_name.en' => ['nullable', 'string', 'max:255'],
            'club_short_name' => ['nullable', 'string', 'max:20'],
            'tagline' => ['nullable', 'array'],
            'description' => ['nullable', 'array'],
            'founding_date' => ['nullable', 'date'],
            'founder' => ['nullable', 'string', 'max:255'],
            'motto' => ['nullable', 'array'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contact_mobile' => ['nullable', 'string', 'max:30'],
            'contact_fax' => ['nullable', 'string', 'max:30'],
            'contact_website' => ['nullable', 'url', 'max:255'],
            'contact_address' => ['nullable', 'array'],
            'social_media' => ['nullable', 'array'],
            'banking_info' => ['nullable', 'array'],
            'legal_info' => ['nullable', 'array'],
            'leadership' => ['nullable', 'array'],
            'branding' => ['nullable', 'array'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'favicon' => ['nullable', 'image', 'max:512'],
            'theme' => ['nullable', 'string', 'max:32'],
            'primary_color' => ['nullable', 'string', 'regex:/^#?[0-9a-fA-F]{6}$/'],
            'facilities' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'seo' => ['nullable', 'array'],
            'documents' => ['nullable', 'array'],
        ];
    }
}
