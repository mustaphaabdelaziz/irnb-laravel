import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initialFound, unitTotals, clampFound } from '../../resources/js/lib/stocktake.js';

test('a new line starts with every expected unit found', () => {
    assert.equal(initialFound({ counted: false, expected_quantity: 40 }), 40);
    assert.equal(initialFound({ counted: true, found: true, found_quantity: 38, expected_quantity: 40 }), 38);
    assert.equal(initialFound({ counted: true, found: false, found_quantity: 0, expected_quantity: 40 }), 0);
    assert.equal(initialFound({ counted: true, found: true, found_quantity: null, expected_quantity: 1 }), 1);
});

test('typed numbers stay between 0 and expected', () => {
    assert.equal(clampFound('', 40), 0);
    assert.equal(clampFound(-3, 40), 0);
    assert.equal(clampFound(52, 40), 40);
    assert.equal(clampFound('38', 40), 38);
    assert.equal(clampFound(2.7, 40), 2);
});

test('tiles count units, not lines', () => {
    const lines = [{ id: 1, expected_quantity: 40 }, { id: 2, expected_quantity: 1 }, { id: 3, expected_quantity: 18 }];
    const state = { 1: { qty: 38 }, 2: { qty: 0 }, 3: { qty: 18 } };
    assert.deepEqual(unitTotals(lines, state), { expected: 59, found: 56, missing: 3 });
});

test('an emptied box counts 0 and totals stay numbers', () => {
    const lines = [{ id: 1, expected_quantity: 40 }, { id: 2, expected_quantity: 18 }];
    const t = unitTotals(lines, { 1: { qty: '' }, 2: { qty: 18 } });
    assert.deepEqual(t, { expected: 58, found: 18, missing: 40 });
    assert.equal(typeof t.found, 'number');
});

test('an over-cap qty counts as expected, missing never negative', () => {
    const lines = [{ id: 1, expected_quantity: 40 }];
    assert.deepEqual(unitTotals(lines, { 1: { qty: 52 } }), { expected: 40, found: 40, missing: 0 });
});
