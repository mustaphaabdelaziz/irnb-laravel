<?php

namespace App\Services\Pdf;

use App\Models\BoardMember;

/**
 * The names for the signature blocks: president and treasurer (payment and
 * purchase orders), president and secretary general (letters). Current term,
 * active members; null when nobody holds the role (a blank line to sign).
 */
final class BoardSignatories
{
    /** @return array{president: ?string, treasurer: ?string, secretary: ?string} */
    public static function names(): array
    {
        $members = BoardMember::query()->withPlayer()->inCurrentTerm()
            ->where('status', 'active')
            ->whereIn('role', ['president', 'treasurer', 'secretary'])
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->groupBy('role');

        return [
            'president' => $members->get('president')?->first()?->name,
            'treasurer' => $members->get('treasurer')?->first()?->name,
            'secretary' => $members->get('secretary')?->first()?->name,
        ];
    }
}
