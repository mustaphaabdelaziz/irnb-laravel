<?php

namespace App\Http\Requests;

use App\Models\LiteraryReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LiteraryReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's permission middleware already decided
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'sections' => ['sometimes', 'array'],
            'sections.*' => ['string', Rule::in(LiteraryReport::SECTIONS)],
            'president_word' => ['nullable', 'string', 'max:20000'],
            'introduction' => ['nullable', 'string', 'max:20000'],
            'conclusion' => ['nullable', 'string', 'max:20000'],
            'note' => ['nullable', 'string', 'max:5000'],
            'include_details' => ['sometimes', 'boolean'],
            'signer_1_board_member_id' => ['nullable', 'integer', 'exists:board_members,id'],
            'signer_2_board_member_id' => ['nullable', 'integer', 'exists:board_members,id'],
        ];
    }
}
