import { test } from 'node:test';
import assert from 'node:assert/strict';
import { MIN_SLICE, completionBarProps, completionColors, completionPct, segments } from '../../resources/js/lib/completion.js';

const sum = (parts) => parts.reduce((total, part) => total + part.width, 0);

test('everything received fills the bar with one part', () => {
    assert.deepEqual(segments({ received: 12, expiring: 0, missing: 0, required: 12 }), [
        { key: 'received', count: 12, width: 100 },
    ]);
});

test('parts are shares of the required documents, expiring carved out of received', () => {
    const parts = segments({ received: 80, expiring: 10, missing: 20, required: 100 });

    assert.deepEqual(parts.map((p) => [p.key, p.count, p.width]), [
        ['received', 70, 70],
        ['expiring', 10, 10],
        ['missing', 20, 20],
    ]);
});

test('a tiny non-zero part stays visible and the bar still sums to 100', () => {
    const parts = segments({ received: 999, expiring: 0, missing: 1, required: 1000 });
    const missing = parts.find((p) => p.key === 'missing');

    assert.equal(missing.width, MIN_SLICE);
    assert.ok(Math.abs(sum(parts) - 100) < 1e-9, `sum was ${sum(parts)}`);
    assert.equal(parts.find((p) => p.key === 'received').width, 98);
});

test('awkward thirds still sum to 100', () => {
    const parts = segments({ received: 2, expiring: 1, missing: 1, required: 3 });

    assert.equal(parts.length, 3);
    assert.ok(Math.abs(sum(parts) - 100) < 1e-9, `sum was ${sum(parts)}`);
});

test('nothing required draws nothing', () => {
    assert.deepEqual(segments({ received: 0, expiring: 0, missing: 0, required: 0 }), []);
    assert.deepEqual(segments({}), []);
});

test('all received documents expiring leaves no green part', () => {
    const parts = segments({ received: 5, expiring: 5, missing: 5, required: 10 });

    assert.deepEqual(parts.map((p) => p.key), ['expiring', 'missing']);
});

test('the percentage is rounded to one decimal like the server', () => {
    assert.equal(completionPct({ received: 5, required: 26 }), 19.2);
    assert.equal(completionPct({ received: 1, required: 9 }), 11.1);
    assert.equal(completionPct({ received: 0, required: 0 }), null);
});

test('bar props map the server summary, zeros for nothing', () => {
    assert.deepEqual(completionBarProps({
        required: 26, received: 5, expiring: 1, missing: 21, pct: 19.2, players_complete: 1, players_total: 9, by_type: [],
    }), { received: 5, expiring: 1, missing: 21, required: 26, playersComplete: 1, playersTotal: 9 });
    assert.deepEqual(completionBarProps(null), { received: 0, expiring: 0, missing: 0, required: 0, playersComplete: 0, playersTotal: 0 });
});

test('the three parts get three distinct theme colours', () => {
    const colors = completionColors();

    assert.deepEqual(Object.keys(colors), ['received', 'expiring', 'missing']);
    assert.equal(new Set(Object.values(colors)).size, 3);
    Object.values(colors).forEach((color) => assert.match(color, /^#[0-9a-f]{6}$/i));
});
