<?php

namespace App\Support;

/**
 * One spelling-insensitive key for a name, so "Ingénieur", "INGENIEUR" and
 * "ingenieur " are recognised as the same job — and so are Arabic names
 * written with different alef or taa forms.
 */
final class NameNormalizer
{
    private const LATIN = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss',
    ];

    private const ARABIC = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
        'ـ' => '', // tatweel
    ];

    /** Mirror of PARTIAL_MIN in resources/js/lib/nameKey.js. */
    public const PARTIAL_MIN = 4;

    /** Two keys name the same thing: equal, or one holds the other (PARTIAL_MIN letters at least). */
    public static function resemble(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        [$short, $long] = mb_strlen($a) <= mb_strlen($b) ? [$a, $b] : [$b, $a];

        return mb_strlen($short) >= self::PARTIAL_MIN && str_contains($long, $short);
    }

    public static function key(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = strtr($value, self::LATIN);
        $value = strtr($value, self::ARABIC);

        // Harakat (fatha, damma, kasra, shadda, sukun …) are decoration, not spelling.
        $value = (string) preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}]/u', '', $value);

        // Spaces, hyphens and punctuation never distinguish two jobs.
        return (string) preg_replace('/[^a-z0-9\p{Arabic}]/u', '', $value);
    }
}
