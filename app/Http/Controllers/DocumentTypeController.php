<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Document types. Same shape as the other lookup pages (player
 * statuses, jobs): localized names, active flag, order, and a delete that is
 * refused while any player has a record of the type.
 */
class DocumentTypeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/DocumentTypes', [
            // withCount so the page can hide "delete" on a type in use.
            'documentTypes' => DocumentType::withCount('playerDocuments')->ordered()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DocumentType::create([...$data, 'code' => $this->uniqueCode($data)]);

        return back()->with('success', 'flash.document_type_created');
    }

    public function update(Request $request, DocumentType $documentType): RedirectResponse
    {
        // The code is deliberately not editable: it is how the app recognises a
        // type (the Photo rule keys off it). Records point at the id, so a
        // rename or a rule change never breaks them.
        $documentType->update($this->validated($request, $documentType));

        return back()->with('success', 'flash.document_type_updated');
    }

    public function destroy(DocumentType $documentType): RedirectResponse
    {
        if ($documentType->playerDocuments()->exists()) {
            return back()->with('error', 'flash.document_type_in_use');
        }

        $documentType->delete();

        return back()->with('success', 'flash.document_type_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?DocumentType $existing = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('document_types', 'name')->ignore($existing?->id)],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_fr' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'is_required' => ['boolean'],
            'validity' => ['required', Rule::in(DocumentType::VALIDITIES)],
            'max_age' => ['nullable', 'integer', 'min:1', 'max:99'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        // An emptied age box means "every age", so it must be written as null
        // rather than left out of the update.
        $data['max_age'] = $data['max_age'] ?? null;
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    /** A readable, unique slug, fixed at creation. */
    private function uniqueCode(array $data): string
    {
        $base = Str::slug((string) ($data['name_en'] ?? ''))
            ?: Str::slug((string) ($data['name_fr'] ?? ''))
            ?: Str::slug((string) $data['name'])
            ?: 'document';
        $base = Str::limit($base, 56, '');

        $code = $base;
        $suffix = 2;

        while (DocumentType::where('code', $code)->exists()) {
            $code = $base.'-'.$suffix++;
        }

        return $code;
    }
}
