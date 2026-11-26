<?php

namespace App\Services\Player\Duplicates;

use App\Support\NameNormalizer;

/**
 * How likely two players are one person (owner decisions 2026-11-26). Works on
 * plain profiles so the finder can score thousands of pairs cheaply.
 */
final class DuplicateScorer
{
    public const POINTS = [
        'same_name' => 80,
        'similar_name' => 50,
        'same_birthdate' => 60,
        'same_phone' => 60,
        'same_father' => 30,
        'different_gender' => -60,
    ];

    /** A pair below this is never listed. */
    public const MIN_SCORE = 50;

    /** Criterion chip => reasons that satisfy it. */
    public const CRITERIA = [
        'name' => ['same_name', 'similar_name'],
        'birthdate' => ['same_birthdate'],
        'phone' => ['same_phone'],
        'father' => ['same_father'],
    ];

    /**
     * @param  array<string, mixed>  $row  id, firstname, lastname, father, birthdate, gender, phones
     * @return array{id:int, first:string, last:string, father:string, birthdate:?string, gender:string, phones:list<string>}
     */
    public static function profile(array $row): array
    {
        $phones = $row['phones'] ?? [];
        if (is_string($phones)) {
            $phones = json_decode($phones, true) ?: [];
        }
        $birthdate = $row['birthdate'] ?? null;

        return [
            'id' => (int) $row['id'],
            'first' => NameNormalizer::key($row['firstname'] ?? null),
            'last' => NameNormalizer::key($row['lastname'] ?? null),
            'father' => NameNormalizer::key($row['father'] ?? null),
            'birthdate' => $birthdate ? substr((string) $birthdate, 0, 10) : null,
            'gender' => mb_strtolower(trim((string) ($row['gender'] ?? ''))),
            'phones' => array_values(array_unique(array_filter(array_map(
                self::phone(...),
                is_array($phones) ? $phones : [],
            )))),
        ];
    }

    /** Digits only, Algerian international prefix folded to the local 0; too short to mean anything → ''. */
    public static function phone(mixed $value): string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';
        if (str_starts_with($digits, '00213')) {
            $digits = '0'.substr($digits, 5);
        } elseif (str_starts_with($digits, '213') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 3);
        }

        return strlen($digits) >= 6 ? $digits : '';
    }

    /** @return array{score:int, reasons:list<string>} */
    public static function score(array $a, array $b): array
    {
        $reasons = [];

        if ($a['first'] !== '' && $a['last'] !== '' && $a['first'] === $b['first'] && $a['last'] === $b['last']) {
            $reasons[] = 'same_name';
        } elseif (self::similarName($a, $b)) {
            $reasons[] = 'similar_name';
        }
        if ($a['birthdate'] !== null && $a['birthdate'] === $b['birthdate']) {
            $reasons[] = 'same_birthdate';
        }
        if (array_intersect($a['phones'], $b['phones']) !== []) {
            $reasons[] = 'same_phone';
        }
        if ($a['father'] !== '' && $a['father'] === $b['father']) {
            $reasons[] = 'same_father';
        }
        if ($a['gender'] !== '' && $b['gender'] !== '' && $a['gender'] !== $b['gender']) {
            $reasons[] = 'different_gender';
        }

        return [
            'score' => array_sum(array_map(fn (string $reason) => self::POINTS[$reason], $reasons)),
            'reasons' => $reasons,
        ];
    }

    /** True when every ticked criterion is met by one of its reasons. */
    public static function meets(array $reasons, array $criteria): bool
    {
        foreach ($criteria as $criterion) {
            if (array_intersect(self::CRITERIA[$criterion] ?? [], $reasons) === []) {
                return false;
            }
        }

        return true;
    }

    private static function similarName(array $a, array $b): bool
    {
        if ($a['first'] === '' || $a['last'] === '' || $b['first'] === '' || $b['last'] === '') {
            return false;
        }
        if ($a['first'] === $b['last'] && $a['last'] === $b['first']) {
            return true;
        }
        $x = $a['first'].$a['last'];
        $y = $b['first'].$b['last'];

        return mb_strlen($x) > 4 && mb_strlen($y) > 4 && self::distance($x, $y) <= 2;
    }

    /** Levenshtein over characters: PHP's levenshtein() counts bytes, and Arabic letters are two. */
    private static function distance(string $x, string $y): int
    {
        $a = mb_str_split($x);
        $b = mb_str_split($y);
        if (abs(count($a) - count($b)) > 2) {
            return 3;
        }

        $previous = range(0, count($b));
        foreach ($a as $i => $charA) {
            $current = [$i + 1];
            foreach ($b as $j => $charB) {
                $current[$j + 1] = min(
                    $previous[$j + 1] + 1,
                    $current[$j] + 1,
                    $previous[$j] + ($charA === $charB ? 0 : 1),
                );
            }
            $previous = $current;
        }

        return $previous[count($b)];
    }
}
