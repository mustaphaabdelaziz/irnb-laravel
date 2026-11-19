/**
 * «عضو» with its Arabic number agreement: 1 → عضو واحد, 2 → عضوان,
 * 3–10 → N أعضاء, 11+ → N عضوًا. Other locales keep their own message wording.
 */
/**
 * vue-i18n plural rule for Arabic messages written as four forms,
 * «واحد | اثنان | 3–10 | 11 فأكثر»: 1 → 0, 2 → 1, 3–10 (and 0) → 2, the rest → 3.
 * A message with fewer forms gets its first for 1 and its last otherwise.
 */
export function arPluralIndex(n, choices) {
    const count = Math.abs(n);
    if (choices < 4) return count === 1 ? 0 : choices - 1;
    if (count === 1) return 0;
    if (count === 2) return 1;
    return count <= 10 ? 2 : 3;
}

export function arMembers(n) {
    if (n === 1) return 'عضو واحد';
    if (n === 2) return 'عضوان';
    if (n >= 3 && n <= 10) return `${n} أعضاء`;
    return `${n} عضوًا`;
}
