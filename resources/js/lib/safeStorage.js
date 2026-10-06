// localStorage can be missing or throw (private window, blocked site data,
// quota). These helpers never throw: a preference that can't be kept just
// falls back to its default.

const DEFAULT = Symbol('localStorage');

export function readJson(key, fallback, storage = DEFAULT) {
    try {
        const store = storage === DEFAULT ? globalThis.localStorage : storage;
        const raw = store?.getItem(key);
        return raw == null ? fallback : JSON.parse(raw);
    } catch {
        return fallback;
    }
}

export function writeJson(key, value, storage = DEFAULT) {
    try {
        const store = storage === DEFAULT ? globalThis.localStorage : storage;
        store?.setItem(key, JSON.stringify(value));
    } catch {
        // Not kept; the in-memory state still works for this session.
    }
}
