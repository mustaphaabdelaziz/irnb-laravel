<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BranchRegistration;

/** A branch's short code: the first part of its membership IDs. */
class BranchCode
{
    public const RULE = '/^[A-Z]{2,6}$/';

    /** Latin letters of its name, the shortest free prefix; BRA, BRB... for a name without any. */
    public static function suggest(Branch $branch): string
    {
        $used = Branch::query()->whereNotNull('code')->pluck('code')->all();
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $branch->name_en ?: ($branch->name_fr ?: (string) $branch->name)));

        for ($length = 2; $length <= min(6, strlen($letters)); $length++) {
            if (! in_array(substr($letters, 0, $length), $used, true)) {
                return substr($letters, 0, $length);
            }
        }

        $suffix = 'A';
        while (in_array('BR'.$suffix, $used, true)) {
            $suffix++;
        }

        return 'BR'.$suffix;
    }

    public static function isLocked(Branch $branch): bool
    {
        return $branch->code !== null && BranchRegistration::query()
            ->where('branch_id', $branch->id)
            ->where('membership_id', 'like', $branch->code.'-%')
            ->exists();
    }
}
