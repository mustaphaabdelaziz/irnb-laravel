// The player list's remembered filters: Players/Index.vue saves them on every
// change, and a bare visit to the list (the sidebar link, quick search, a back
// link) shows the list with them.
export const PLAYER_FILTERS_KEY = 'players.filters';

// The list's query parameters, as Players/Index.vue sends them.
const KEYS = ['search', 'lastname', 'category_id', 'status', 'position_id', 'branch_id', 'age', 'blood_group', 'academic', 'certificate', 'documents', 'archived', 'per_page'];

export function savedPlayerFilters(storage = globalThis.localStorage) {
    let saved = null;
    try { saved = JSON.parse(storage?.getItem(PLAYER_FILTERS_KEY) || 'null'); } catch { saved = null; }
    if (!saved || typeof saved !== 'object' || Array.isArray(saved)) return null;

    const known = Object.fromEntries(KEYS.filter((key) => saved[key] !== undefined && saved[key] !== null && saved[key] !== '').map((key) => [key, saved[key]]));
    return Object.keys(known).length ? known : null;
}

/**
 * Inertia `visitOptions` for a bare visit to the list: ask for the remembered
 * filters in that first request. Without it the page drew the unfiltered list,
 * then reloaded it with the filters: two requests and the wrong rows for a
 * moment. Being part of the visit's URL, a hover prefetch of the sidebar link
 * fetches the filtered list too. Anything else is left alone: a URL with its
 * own query, a visit with data, a partial reload (the page's own filter
 * reloads, clearing included), a non-GET.
 */
export function rememberedFilterOptions(href, options, listUrl, readSaved = savedPlayerFilters) {
    if ((options.method ?? 'get').toString().toLowerCase() !== 'get') return {};
    if (options.only?.length || options.except?.length || options.reset?.length) return {};
    if (options.data && Object.keys(options.data).length) return {};

    const list = new URL(listUrl);
    const url = new URL(href, list);
    if (url.origin !== list.origin || url.pathname !== list.pathname || url.search) return {};

    const saved = readSaved();
    return saved ? { data: saved } : {};
}
