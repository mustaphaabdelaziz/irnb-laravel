<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\DocumentType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
        // withCount so the page can hide "delete" on a type in use.
        $types = DocumentType::withCount('playerDocuments')->with('branches:id')->ordered()->get();
        $types->each(fn (DocumentType $type) => $type
            ->setAttribute('branch_ids', $type->branches->pluck('id')->map(fn ($id) => (int) $id)->values()->all())
            ->unsetRelation('branches'));

        // Records per type and branch, so unticking a branch can say how many
        // records stop counting (they are kept).
        $records = [];
        foreach (DB::table('player_documents')
            ->join('branch_player', 'branch_player.id', '=', 'player_documents.branch_registration_id')
            ->groupBy('player_documents.document_type_id', 'branch_player.branch_id')
            ->selectRaw('player_documents.document_type_id as type_id, branch_player.branch_id as branch_id, COUNT(*) as n')
            ->get() as $row) {
            $records[(int) $row->type_id][(int) $row->branch_id] = (int) $row->n;
        }

        return Inertia::render('Settings/DocumentTypes', [
            'documentTypes' => $types,
            'branches' => Branch::query()->orderBy('name')->get()
                ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->localized_name])->values(),
            'recordsByBranch' => (object) $records,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $branchIds = Arr::pull($data, 'branch_ids');

        DB::transaction(function () use ($data, $branchIds) {
            $type = DocumentType::create([...$data, 'code' => $this->uniqueCode($data)]);
            $type->branches()->sync($branchIds ?? []);
        });

        return back()->with('success', 'flash.document_type_created');
    }

    public function update(Request $request, DocumentType $documentType): RedirectResponse
    {
        // The code is deliberately not editable: it is how the app recognises a
        // type (the Photo rule keys off it). Records point at the id, so a
        // rename or a rule change never breaks them.
        $data = $this->validated($request, $documentType);
        $branchIds = Arr::pull($data, 'branch_ids');

        DB::transaction(function () use ($documentType, $data, $branchIds) {
            $documentType->update($data);
            // Left out (the active toggle) = the ticks stay as they are.
            if ($branchIds !== null) {
                $documentType->branches()->sync($branchIds);
            }
        });

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
            'required_for_matches' => ['boolean'],
            'validity' => ['required', Rule::in(DocumentType::VALIDITIES)],
            'max_age' => ['nullable', 'integer', 'min:1', 'max:99'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:99'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'branch_ids' => ['sometimes', 'array'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')],
        ]);

        // An emptied age box means "every age", so it must be written as null
        // rather than left out of the update.
        $data['max_age'] = $data['max_age'] ?? null;
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['copies'] = (int) ($data['copies'] ?? 1);
        if (array_key_exists('branch_ids', $data)) {
            $data['branch_ids'] = array_map('intval', $data['branch_ids'] ?? []);
        }

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
