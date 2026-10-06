import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readJson, writeJson } from '../../resources/js/lib/safeStorage.js';

const memory = () => {
    const data = new Map();
    return { getItem: (k) => (data.has(k) ? data.get(k) : null), setItem: (k, v) => data.set(k, String(v)) };
};
const broken = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('quota'); } };

test('round-trips JSON', () => {
    const s = memory();
    writeJson('k', { a: true }, s);
    assert.deepEqual(readJson('k', null, s), { a: true });
});

test('returns the fallback for a missing key, bad JSON, broken or absent storage', () => {
    const s = memory();
    assert.equal(readJson('missing', 'fb', s), 'fb');
    s.setItem('bad', '{not json');
    assert.equal(readJson('bad', 'fb', s), 'fb');
    assert.equal(readJson('k', 'fb', broken), 'fb');
    assert.equal(readJson('k', 'fb', undefined), 'fb');
});

test('writeJson never throws', () => {
    assert.doesNotThrow(() => writeJson('k', 1, broken));
    assert.doesNotThrow(() => writeJson('k', 1, undefined));
});

test('readJson and writeJson survive a throwing localStorage getter', () => {
    try {
        Object.defineProperty(globalThis, 'localStorage', {
            configurable: true,
            get() {
                throw new Error('SecurityError: localStorage access blocked');
            }
        });
        assert.equal(readJson('k', 'fb'), 'fb');
        assert.doesNotThrow(() => writeJson('k', 1));
    } finally {
        delete globalThis.localStorage;
    }
});
