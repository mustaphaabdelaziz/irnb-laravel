<?php

namespace App\Services\Pdf;

use App\Models\BoardMember;

/** The president's and treasurer's names for the signature block (current term, active members). */
final class BoardSignatories
{
    /** @return array{president: ?string, treasurer: ?string} */
    public static function names(): array
    {
        $members = BoardMember::query()->withPlayer()->inCurrentTerm()
            ->where('status', 'active')
            ->whereIn('role', ['president', 'treasurer'])
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->groupBy('role');

        return [
            'president' => $members->get('president')?->first()?->name,
            'treasurer' => $members->get('treasurer')?->first()?->name,
        ];
    }
}
