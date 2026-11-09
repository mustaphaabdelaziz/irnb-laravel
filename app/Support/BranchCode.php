<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BranchRegistration;

/** A branch's short code: the first part of its membership IDs. */
class BranchCode
{
    public const RULE = '/^[A-Z]{2,6}$/';

    /**
     * Latin letters of its name, the shortest free prefix; BRA, BRB... for a name without any.
     * The branch form never reaches that fallback: it asks for a code instead.
     */
    public static function suggest(Branch $branch): string
    {
        $used = Branch::query()->whereNotNull('code')->pluck('code')->all();
        $letters = self::letters($branch->name_en, $branch->name_fr, $branch->name);

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

    /** The upper-cased Latin letters of the first name that is filled in: English, French, then the name. */
    public static function letters(?string $nameEn, ?string $nameFr, ?string $name): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z]/', '', $nameEn ?: ($nameFr ?: (string) $name)));
    }

    public static function isLocked(Branch $branch): bool
    {
        return $branch->code !== null && BranchRegistration::query()
            ->where('branch_id', $branch->id)
            ->where('membership_id', 'like', $branch->code.'-%')
            ->exists();
    }
}
