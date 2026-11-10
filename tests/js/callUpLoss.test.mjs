import { test } from 'node:test';
import assert from 'node:assert/strict';
import { losingCallUpRows } from '../../resources/js/lib/callUpLoss.js';

const saved = [
    { player_id: 1, called_up: true, has_report_data: true },
    { player_id: 2, called_up: true, has_report_data: false },
    { player_id: 3, called_up: false, has_report_data: false },
];

test('an un-ticked player with report data is listed', () => {
    const rows = [{ player_id: 1, called_up: false }, { player_id: 2, called_up: false }];
    assert.deepEqual(losingCallUpRows(rows, saved).map((r) => r.player_id), [1]);
});

test('ticked players are never listed', () => {
    assert.deepEqual(losingCallUpRows([{ player_id: 1, called_up: true }], saved), []);
});

test('the flag comes from the fresh props, not from the stale row', () => {
    // The row was built before a report was saved: it carries no flag, the fresh props do.
    const stale = [{ player_id: 1, called_up: false, has_report_data: false }];
    assert.equal(losingCallUpRows(stale, saved).length, 1);
    // And a stale true flag does not count once the props say there is nothing to lose.
    const fresh = [{ player_id: 2, called_up: false, has_report_data: true }];
    assert.equal(losingCallUpRows(fresh, saved).length, 0);
});
