<?php

namespace App\Http\Requests\Mail;

use App\Models\MailEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * A new entry (every field; MailRegister keeps those of its direction), or an
 * existing one: only the fields it may still change (editableFields()) and
 * its scan — so an imported letter is never asked for the correspondent it
 * lacks, and a cancelled one validates nothing but is refused by the service.
 */
class SaveMailEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'direction' => ['required', Rule::in(MailEntry::DIRECTIONS)],
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'correspondent' => ['required', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
            'sender_reference' => ['nullable', 'string', 'max:100'],
            'received_date' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // One scan per entry: a PDF or a picture, replaced by the next upload.
            'scan' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
        $entry = $this->route('mailEntry');

        return $entry instanceof MailEntry
            ? Arr::only($rules, [...$entry->editableFields(), 'scan'])
            : $rules;
    }
}
