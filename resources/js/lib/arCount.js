/** True for the counts Arabic writes with a plural noun: 3–10, and from 100 on a number ending in 03–10. */
function takesPlural(count) {
    const tail = count % 100;
    return count >= 100 ? tail >= 3 && tail <= 10 : count <= 10;
}

/**
 * vue-i18n plural rule for Arabic messages written as four forms,
 * «واحد | اثنان | جمع | مفرد منصوب»: 1 → 0, 2 → 1, 3–10 (and 0, 103, 210…) → 2,
 * the rest (11–99, 112…) → 3. A message with fewer forms gets its first for 1
 * and its last otherwise.
 */
export function arPluralIndex(n, choices) {
    const count = Math.abs(n);
    if (choices < 4) return count === 1 ? 0 : choices - 1;
    if (count === 1) return 0;
    if (count === 2) return 1;
    return takesPlural(count) ? 2 : 3;
}

/**
 * «عضو» with its Arabic number agreement: 1 → عضو واحد, 2 → عضوان,
 * 3–10 → N أعضاء, 11–99 → N عضوًا, then by the last two digits (103 أعضاء).
 * Other locales keep their own message wording.
 */
export function arMembers(n) {
    if (n === 1) return 'عضو واحد';
    if (n === 2) return 'عضوان';
    if (n >= 3 && takesPlural(n)) return `${n} أعضاء`;
    return `${n} عضوًا`;
}
