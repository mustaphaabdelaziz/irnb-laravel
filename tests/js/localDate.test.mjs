// Run on the club's clock, whatever the machine's: the bug only shows east of UTC.
process.env.TZ = 'Africa/Algiers';

import { test, mock } from 'node:test';
import assert from 'node:assert/strict';
import { localDateKey, localToday } from '../../resources/js/lib/localDate.js';

test('at 00:30 in Algiers today is the new day, not UTC\'s yesterday', () => {
    mock.timers.enable({ apis: ['Date'], now: Date.parse('2026-08-31T23:30:00Z') });
    try {
        // The old default: the UTC date, still 31 August.
        assert.equal(new Date().toISOString().slice(0, 10), '2026-08-31');
        assert.equal(localToday(), '2026-09-01');
    } finally {
        mock.timers.reset();
    }
});

test('a date is written from its local parts, zero-padded', () => {
    assert.equal(localDateKey(new Date(2026, 0, 5, 23, 59)), '2026-01-05');
    assert.equal(localToday(new Date(2026, 11, 31, 0, 1)), '2026-12-31');
});
