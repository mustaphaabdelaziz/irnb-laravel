// localStorage can be missing or throw (private window, blocked site data,
// quota). These helpers never throw: a preference that can't be kept just
// falls back to its default.

export function readJson(key, fallback, storage = globalThis.localStorage) {
    try {
        const raw = storage?.getItem(key);
        return raw == null ? fallback : JSON.parse(raw);
    } catch {
        return fallback;
    }
}

export function writeJson(key, value, storage = globalThis.localStorage) {
    try {
        storage?.setItem(key, JSON.stringify(value));
    } catch {
        // Not kept; the in-memory state still works for this session.
    }
}
