<?php

namespace App\Services\LiteraryReport\Sections;

use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\LiteraryReport;
use App\Support\UiLang;

/** The report's signature blocks: the chosen board members with their role. Empty when none is chosen. */
final class SignaturesSection
{
    /** @return array{signers?: list<array{label: string, name: string}>} */
    public function build(LiteraryReport $report): array
    {
        $ids = array_values(array_map('intval', array_filter([$report->signer_1_board_member_id, $report->signer_2_board_member_id])));
        if ($ids === []) {
            return [];
        }

        $members = BoardMember::withPlayer()->whereIn('id', $ids)->get()->keyBy('id');
        $roles = BoardRole::query()->whereIn('name', $members->pluck('role')->all())->pluck('label', 'name');
        $template = UiLang::get('literary.pdf.signature');

        $signers = collect($ids)
            ->map(fn (int $id) => $members->get($id))
            ->filter()
            ->map(fn (BoardMember $m) => [
                // Built-in roles have no label: their name is a UI key, as on the board pages.
                'label' => str_replace('{role}', $roles->get($m->role) ?: UiLang::get((string) $m->role), $template),
                'name' => (string) $m->name,
            ])
            ->values()->all();

        return $signers === [] ? [] : ['signers' => $signers];
    }
}
