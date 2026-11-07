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

test('isMinor: under 18 until the 18th birthday itself', async () => {
    const { isMinor } = await import('../../resources/js/lib/localDate.js');
    assert.equal(isMinor('2008-11-08', '2026-11-07'), true);
    assert.equal(isMinor('2008-11-07', '2026-11-07'), false);
    assert.equal(isMinor('1990-01-01', '2026-11-07'), false);
    assert.equal(isMinor('2015-05-20', '2026-11-07'), true);
});

test('isMinor: a 29 February birthday turns 18 on 1 March in a common year', async () => {
    const { isMinor } = await import('../../resources/js/lib/localDate.js');
    assert.equal(isMinor('2008-02-29', '2026-02-28'), true);
    assert.equal(isMinor('2008-02-29', '2026-03-01'), false);
});

test('isMinor: no or malformed birthdate is never a minor', async () => {
    const { isMinor } = await import('../../resources/js/lib/localDate.js');
    assert.equal(isMinor('', '2026-11-07'), false);
    assert.equal(isMinor(null, '2026-11-07'), false);
    assert.equal(isMinor('2008-11', '2026-11-07'), false);
});
