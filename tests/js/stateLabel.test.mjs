import test from 'node:test';
import assert from 'node:assert/strict';
import { stateLabel } from '../../resources/js/lib/stateLabel.js';

const dict = { new: 'جديد', under_repair: 'قيد الإصلاح' };
const t = (k) => dict[k] ?? k;

test('translates through the lowercased underscored key', () => {
    assert.equal(stateLabel(t, 'New'), 'جديد');
    assert.equal(stateLabel(t, 'Under Repair'), 'قيد الإصلاح');
});
test('falls back to the raw value, and a dash when empty', () => {
    assert.equal(stateLabel(t, 'Weird'), 'Weird');
    assert.equal(stateLabel(t, null), '—');
});
