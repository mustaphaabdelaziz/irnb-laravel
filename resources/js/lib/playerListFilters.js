// Remembered list filters. A list page saves its filters on every change, and
// a bare visit to the list (the sidebar link, quick search, a back link) shows
// the list with them. Players/Index.vue and Equipment/Catalog/Index.vue use it.
export const PLAYER_FILTERS_KEY = 'players.filters';
export const EQUIPMENT_FILTERS_KEY = 'equipment.catalogs.filters';

// Each list's query parameters, as its page sends them.
const PLAYER_KEYS = ['search', 'lastname', 'category_id', 'status', 'position_id', 'branch_id', 'age', 'blood_group', 'academic', 'certificate', 'documents', 'archived', 'per_page'];
const EQUIPMENT_KEYS = ['search', 'category'];

/**
 * The filters saved under `storageKey`, keeping only the list's own
 * parameters; null when nothing (usable) is saved.
 */
export function savedListFilters(storageKey, keys, storage = globalThis.localStorage) {
    let saved = null;
    try { saved = JSON.parse(storage?.getItem(storageKey) || 'null'); } catch { saved = null; }
    if (!saved || typeof saved !== 'object' || Array.isArray(saved)) return null;

    const known = Object.fromEntries(keys.filter((key) => saved[key] !== undefined && saved[key] !== null && saved[key] !== '').map((key) => [key, saved[key]]));
    return Object.keys(known).length ? known : null;
}

/** Save a list's current filters, or forget them once none is set. */
export function saveListFilters(storageKey, params, storage = globalThis.localStorage) {
    try {
        if (Object.keys(params).length) storage.setItem(storageKey, JSON.stringify(params));
        else storage.removeItem(storageKey);
    } catch { /* storage unavailable: filters just aren't remembered */ }
}

export function savedPlayerFilters(storage = globalThis.localStorage) {
    return savedListFilters(PLAYER_FILTERS_KEY, PLAYER_KEYS, storage);
}

export function savedEquipmentFilters(storage = globalThis.localStorage) {
    return savedListFilters(EQUIPMENT_FILTERS_KEY, EQUIPMENT_KEYS, storage);
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

/**
 * rememberedFilterOptions over several lists: `lists` is
 * [[listUrl, readSaved], ...]; the first list the visit goes to answers.
 */
export function rememberedListsOptions(href, options, lists) {
    for (const [listUrl, readSaved] of lists) {
        const found = rememberedFilterOptions(href, options, listUrl, readSaved);
        if (found.data) return found;
    }
    return {};
}
