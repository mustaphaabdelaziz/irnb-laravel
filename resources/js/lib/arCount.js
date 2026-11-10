/**
 * «عضو» with its Arabic number agreement: 1 → عضو واحد, 2 → عضوان,
 * 3–10 → N أعضاء, 11+ → N عضوًا. Other locales keep their own message wording.
 */
export function arMembers(n) {
    if (n === 1) return 'عضو واحد';
    if (n === 2) return 'عضوان';
    if (n >= 3 && n <= 10) return `${n} أعضاء`;
    return `${n} عضوًا`;
}
