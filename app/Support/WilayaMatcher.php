<?php

namespace App\Support;

use App\Models\CountryState;

/**
 * Resolves a free-text wilaya value (a code, a French or Arabic name, or a
 * legacy spelling from the old seed JSON) to a `country_states` row id.
 *
 * Used by the player import. The backfill migration
 * (2026_09_23_100004_add_wilaya_id_to_players.php) keeps its OWN private
 * copy of normalise() + the alias map rather than depending on this class:
 * migrations must not depend on app classes that can change (or be deleted
 * or renamed) later — a migration must keep working exactly as written,
 * forever. That duplication is deliberate.
 */
class WilayaMatcher
{
    /**
     * Legacy spellings that don't normalise to any official code/name — the
     * same typos and southern-wilaya spellings the backfill migration
     * matches — keyed by the official wilaya code they belong to.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'se9tif' => '19',
        'saefda' => '20',
        'ghardaefa' => '47',
        'tbessa' => '12',
        "El M'ghair" => '57',
        'El Menia' => '58',
        'Bordj Baji Mokhtar' => '50',
    ];

    /**
     * Arabic letter variants that are the same letter to a reader but not to
     * a string comparison — same folding App\Support\NameNormalizer applies
     * to job names — so a hand-typed 'الاغواط' meets the official 'الأغواط'
     * and 'عين الدفلي' meets the official 'عين الدفلى'. Applied to BOTH
     * sides (the keys built from official DB names in lookup(), and every
     * user-typed value passed in here), so official spellings still match
     * too.
     *
     * @var array<string, string>
     */
    private const ARABIC = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
        'ـ' => '', // tatweel
    ];

    /**
     * Lower-cased, accent-free, Arabic-letter-folded, letters/digits/Arabic
     * only — so "GHARDAIA", "Ghardaïa" and "SÉTIF" all meet the same key,
     * and so do 'الأغواط' and a hand-typed 'الاغواط'.
     */
    public static function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));

        $accents = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];

        $value = strtr($value, $accents);
        $value = strtr($value, self::ARABIC);

        // Harakat (fatha, damma, kasra, shadda, sukun …) are decoration, not spelling.
        $value = (string) preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}]/u', '', $value);

        return (string) preg_replace('/[^a-z0-9\p{Arabic}]/u', '', $value);
    }

    /**
     * Every normalised spelling (code, external_id without leading zero,
     * French name, Arabic name, legacy alias) mapped to the matching
     * `country_states` row id. Uncoded rows (stray/duplicate legacy rows
     * left behind by the wilaya-sync migration) are ignored, same as the
     * backfill migration.
     *
     * @return array<string, int>
     */
    public static function lookup(): array
    {
        $byKey = [];

        $states = CountryState::query()->whereNotNull('code')->get();

        foreach ($states as $state) {
            $values = [
                $state->code,
                (string) $state->external_id,
                $state->name,
                $state->name_fr,
                $state->name_ar,
                $state->ar_name,
            ];

            foreach ($values as $value) {
                $key = self::normalise((string) $value);
                if ($key !== '') {
                    $byKey[$key] = $state->id;
                }
            }
        }

        foreach (self::ALIASES as $alias => $code) {
            $id = $byKey[self::normalise($code)] ?? null;
            if ($id !== null) {
                $byKey[self::normalise($alias)] = $id;
            }
        }

        return $byKey;
    }
}
