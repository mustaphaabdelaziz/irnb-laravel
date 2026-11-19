// Mirror of App\Support\NameNormalizer::key — keep the two in step. One
// spelling-insensitive key, so "Ballon", "BALLON " and "ballon" match, and so
// do Arabic names written with different alef or taa forms. No Vue and no `@/`
// imports, so `node --test` can load this file directly.

const LATIN = {
    à: 'a', â: 'a', ä: 'a', á: 'a', ã: 'a', å: 'a',
    è: 'e', é: 'e', ê: 'e', ë: 'e',
    ì: 'i', í: 'i', î: 'i', ï: 'i',
    ò: 'o', ó: 'o', ô: 'o', ö: 'o', õ: 'o',
    ù: 'u', ú: 'u', û: 'u', ü: 'u',
    ç: 'c', ñ: 'n', ß: 'ss',
};

const ARABIC = {
    'أ': 'ا', 'إ': 'ا', 'آ': 'ا', 'ٱ': 'ا',
    'ة': 'ه', 'ى': 'ي', 'ؤ': 'و', 'ئ': 'ي',
    'ـ': '', // tatweel
};

const SWAP = new RegExp(`[${Object.keys(LATIN).join('')}${Object.keys(ARABIC).join('')}]`, 'g');

// Harakat (fatha, damma, kasra, shadda, sukun …) are decoration, not spelling.
const HARAKAT = /[ؐ-ًؚ-ٰٟ]/g;

// Spaces, hyphens and punctuation never distinguish two names.
const NOISE = /[^a-z0-9\p{Script=Arabic}]/gu;

export function nameKey(value) {
    return String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(SWAP, (ch) => LATIN[ch] ?? ARABIC[ch])
        .replace(HARAKAT, '')
        .replace(NOISE, '');
}

// A shorter name only counts as part of a longer one from this many letters on,
// so "كرة" is not flagged against every ball.
export const PARTIAL_MIN = 4;

/** Two keys name the same thing: equal, or one holds the other (PARTIAL_MIN letters at least). */
export function keysResemble(a, b) {
    if (a === '' || b === '') return false;
    if (a === b) return true;
    const [short, long] = a.length <= b.length ? [a, b] : [b, a];
    return short.length >= PARTIAL_MIN && long.includes(short);
}

/** The first of `names` spelled like `value` (exact match first), else one that holds it or is held by it, or null. */
export function similarName(names, value) {
    const key = nameKey(value);
    if (key === '') {
        return null;
    }
    const list = names ?? [];

    return list.find((name) => nameKey(name) === key)
        ?? list.find((name) => keysResemble(nameKey(name), key))
        ?? null;
}
