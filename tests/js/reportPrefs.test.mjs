import { test } from 'node:test';
import assert from 'node:assert/strict';
import { pinnedReports, togglePinned, recentReports, rememberReport } from '../../resources/js/lib/reportPrefs.js';

function memoryStorage() {
    const data = new Map();
    return { getItem: (k) => (data.has(k) ? data.get(k) : null), setItem: (k, v) => data.set(k, String(v)) };
}

test('togglePinned pins then unpins', () => {
    const s = memoryStorage();
    assert.deepEqual(togglePinned('players.directory', s), ['players.directory']);
    assert.deepEqual(togglePinned('finance.summary', s), ['players.directory', 'finance.summary']);
    assert.deepEqual(togglePinned('players.directory', s), ['finance.summary']);
    assert.deepEqual(pinnedReports(s), ['finance.summary']);
});

test('rememberReport keeps newest first, unique, capped at six', () => {
    const s = memoryStorage();
    ['a', 'b', 'c', 'd', 'e', 'f', 'g'].forEach((id) => rememberReport(id, s));
    rememberReport('d', s);
    assert.deepEqual(recentReports(s), ['d', 'g', 'f', 'e', 'c', 'b']);
});

test('a broken or blocked storage never throws', () => {
    const broken = { getItem: () => { throw new Error('blocked'); }, setItem: () => { throw new Error('blocked'); } };
    assert.deepEqual(pinnedReports(broken), []);
    assert.deepEqual(togglePinned('x', broken), ['x']);
    const garbage = { getItem: () => '{"not":"a list"}', setItem: () => {} };
    assert.deepEqual(recentReports(garbage), []);
});
