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
            // The only two settings with a meaning the server must defend: a month
            // outside 1-12 or a drawer of zero files would break file locations.
            'settings.seasonStartMonth' => ['nullable', 'integer', 'min:1', 'max:12'],
            'settings.fileDrawerSize' => ['nullable', 'integer', 'min:10', 'max:1000'],
            'settings.academicCertificates' => ['nullable', 'array'],
            'settings.academicCertificates.20' => ['required_with:settings.academicCertificates', 'array'],
            'settings.academicCertificates.10' => ['required_with:settings.academicCertificates', 'array'],
            // Chained low-to-high with `lte` (rather than high-to-low with `gte`) so a
            // descending-order violation is reported against the field that is out of
            // step with the one above it, not against the higher field itself.
            'settings.academicCertificates.20.excellence' => ['required_with:settings.academicCertificates', 'numeric', 'min:0', 'max:20'],
            'settings.academicCertificates.20.congratulations' => ['required_with:settings.academicCertificates', 'numeric', 'min:0', 'max:20', 'lte:settings.academicCertificates.20.excellence'],
            'settings.academicCertificates.20.encouragement' => ['required_with:settings.academicCertificates', 'numeric', 'min:0', 'max:20', 'lte:settings.academicCertificates.20.congratulations'],
            'settings.academicCertificates.20.honor_roll' => ['required_with:settings.academicCertificates', 'numeric', 'min:0', 'max:20', 'lte:settings.academicCertificates.20.encouragement'],
            'settings.academicCertificates.10.excellence' => ['required_with:settings.academicCertificates', 'numeric', 'min:0', 'max:10'],
            'settings.academicCertificates.10.congratulations' => ['required_with:settings.academicCertificates', 'numeric', 'min:0', 'max:10', 'lte:settings.academicCertificates.10.excellence'],
            'settings.academicCertificates.10.encouragement' => ['required_with:settings.academicCertificates', 'numeric', 'min:0', 'max:10', 'lte:settings.academicCertificates.10.congratulations'],
            'settings.academicCertificates.10.honor_roll' => ['required_with:settings.academicCertificates', 'numeric', 'min:0', 'max:10', 'lte:settings.academicCertificates.10.encouragement'],
            'seo' => ['nullable', 'array'],
            'documents' => ['nullable', 'array'],
        ];
    }
}
