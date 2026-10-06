// Pure helpers behind the sidebar and the quick search. No Vue and no `@/`
// imports, so `node --test` can load this file directly.

// Arabic marks: tashkeel (diacritics), superscript alef, and tatweel.
// We exclude hamza marks (U+0653-U+065F) because they're structural
// (they make ئ from ي, etc.) and should be preserved during normalization.
const ARABIC_TASHKEEL = /[ً-ْٰـ]/g;
const LATIN_MARKS = /[̀-ͯ]/g;

/** Fold text so "اعدادات" finds "الإعدادات" and "parametrage" finds "Paramétrage". */
export function normalize(text) {
    return String(text ?? '')
        .toLowerCase()
        // Replace key variants BEFORE NFD to preserve structural hamzas
        .replace(/[أإآٱ]/g, 'ا')
        .replace(/ة/g, 'ه')
        .replace(/ى/g, 'ي')
        .normalize('NFD')
        .replace(LATIN_MARKS, '')
        .replace(ARABIC_TASHKEEL, '')
        .normalize('NFC')  // Recompose to clean up
        .trim();
}

export function isActive(item, url) {
    const current = url ?? '';
    // `match` items decide with their own pattern (Users vs its Activity pages).
    if (item.match) return item.match.test(current);
    // `exact` items (the Board hub) must not stay highlighted on their own
    // sub-pages, which have their own menu entries.
    return item.exact
        ? current === item.prefix || current.startsWith(item.prefix + '?')
        : current.startsWith(item.prefix);
}

// Items show only when the user can view their module (superadmin sees all).
// `always` items are ungated; `superadminOnly` items need the god flag;
// `desktopOnly` items need the desktop app. Empty sections are dropped.
export function visibleSections(raw, { can, isSuperadmin, isDesktop }) {
    return raw
        .map((section) => ({
            ...section,
            items: section.items.filter((i) =>
                (!i.desktopOnly || isDesktop)
                && (i.always || (i.superadminOnly ? isSuperadmin : can(i.module, 'view')))),
        }))
        .filter((section) => section.items.length > 0);
}

export function sectionHasActive(section, url) {
    return section.items.some((item) => isActive(item, url));
}

/** Sum of item badges, shown on a section that is closed. */
export function sectionBadge(section) {
    return section.items.reduce((sum, item) => sum + (Number(item.badge) || 0), 0);
}

export function flattenForSearch(sections) {
    return sections.flatMap((section) => section.items.map((item) => ({
        ...item,
        section: section.label ?? null,
        haystack: normalize(`${item.label} ${section.label ?? ''}`),
    })));
}

export function searchItems(entries, query) {
    const q = normalize(query);
    if (q === '') return entries;
    return entries.filter((entry) => entry.haystack.includes(q));
}
