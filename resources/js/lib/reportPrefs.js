// Per-viewer report shortcuts: pinned reports and the last ones opened.
// Kept in localStorage only (a convenience, never shared); safeStorage
// makes every read and write survive a missing or blocked storage.
import { readJson, writeJson } from './safeStorage.js';

const PINNED = 'reports.pinned';
const RECENT = 'reports.recent';
const MAX_RECENT = 6;

const ids = (value) => (Array.isArray(value) ? value.filter((v) => typeof v === 'string') : []);

export function pinnedReports(storage) {
    return ids(readJson(PINNED, [], storage));
}

/** Pins an unpinned report, unpins a pinned one; returns the new list. */
export function togglePinned(id, storage) {
    const list = pinnedReports(storage);
    const next = list.includes(id) ? list.filter((x) => x !== id) : [...list, id];
    writeJson(PINNED, next, storage);
    return next;
}

export function recentReports(storage) {
    return ids(readJson(RECENT, [], storage));
}

/** Moves a report to the front of the recent list (newest first, capped). */
export function rememberReport(id, storage) {
    const next = [id, ...recentReports(storage).filter((x) => x !== id)].slice(0, MAX_RECENT);
    writeJson(RECENT, next, storage);
    return next;
}
