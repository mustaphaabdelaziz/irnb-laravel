import test from 'node:test';
import assert from 'node:assert/strict';
import { flashToasts, flashDuration } from '../../resources/js/lib/flashToasts.js';

test('an import that flashes a count and skipped rows shows both', () => {
    const toasts = flashToasts({ success: '3 imported', error: 'Row 4: skipped' });
    assert.deepEqual(toasts, [
        { type: 'success', payload: '3 imported' },
        { type: 'error', payload: 'Row 4: skipped' },
    ]);
});

test('a single message stays a single toast; status counts as success', () => {
    assert.deepEqual(flashToasts({ success: 'saved', error: null }), [{ type: 'success', payload: 'saved' }]);
    assert.deepEqual(flashToasts({ error: 'nope' }), [{ type: 'error', payload: 'nope' }]);
    assert.deepEqual(flashToasts({ status: 'link sent' }), [{ type: 'success', payload: 'link sent' }]);
    assert.deepEqual(flashToasts(null), []);
    assert.deepEqual(flashToasts({}), []);
});

test('warnings next to a success stay up long enough to read', () => {
    assert.equal(flashDuration([{ type: 'success' }]), 2000);
    assert.equal(flashDuration([{ type: 'error' }]), 2000);
    assert.ok(flashDuration([{ type: 'success' }, { type: 'error' }]) >= 8000);
});
