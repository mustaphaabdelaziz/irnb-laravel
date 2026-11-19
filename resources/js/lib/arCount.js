/** True for the counts Arabic writes with a plural noun: 3–10, and from 100 on a number ending in 03–10. */
function takesPlural(count) {
    const tail = count % 100;
    return count >= 100 ? tail >= 3 && tail <= 10 : count <= 10;
}

/** 100, 101, 102, 200…: Arabic puts the noun in the plain singular («100 لاعب»). */
function takesPlainSingular(count) {
    return count >= 100 && count % 100 <= 2;
}

/**
 * vue-i18n plural rule for Arabic messages written as four or five forms,
 * «واحد | اثنان | جمع | مفرد منصوب | مفرد»: 1 → 0, 2 → 1, 3–10 (and 0, 103, 210…) → 2,
 * 11–99, 112… → 3, and 100, 101, 102, 200… → 4 when the message has a fifth
 * form (else 3). A message with fewer forms gets its first for 1 and its last otherwise.
 */
export function arPluralIndex(n, choices) {
    const count = Math.abs(n);
    if (choices < 4) return count === 1 ? 0 : choices - 1;
    if (count === 1) return 0;
    if (count === 2) return 1;
    if (takesPlural(count)) return 2;
    return choices >= 5 && takesPlainSingular(count) ? 4 : 3;
}

/**
 * «عضو» with its Arabic number agreement: 1 → عضو واحد, 2 → عضوان,
 * 3–10 → N أعضاء, 11–99 → N عضوًا, 100–102 → N عضو, then by the last two digits.
 * Other locales keep their own message wording.
 */
export function arMembers(n) {
    if (n === 1) return 'عضو واحد';
    if (n === 2) return 'عضوان';
    if (n >= 3 && takesPlural(n)) return `${n} أعضاء`;
    if (takesPlainSingular(n)) return `${n} عضو`;
    return `${n} عضوًا`;
}
