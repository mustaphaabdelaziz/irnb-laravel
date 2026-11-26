import { test } from 'node:test';
import assert from 'node:assert/strict';
import { periodTooLong } from '../../resources/js/lib/periodLimit.js';

test('13 months less a day is allowed, one day more is not', () => {
    assert.equal(periodTooLong('2025-10-01', '2026-10-31'), false);
    assert.equal(periodTooLong('2025-10-01', '2026-11-01'), true);
});

test('month ends do not overflow', () => {
    assert.equal(periodTooLong('2025-01-31', '2026-02-27'), false);
    assert.equal(periodTooLong('2025-01-31', '2026-02-28'), true);
});

test('incomplete ranges are never too long', () => {
    assert.equal(periodTooLong('', '2026-10-01'), false);
});
