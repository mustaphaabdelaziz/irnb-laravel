import { test } from 'node:test';
import assert from 'node:assert/strict';
import { branchFromUrl } from '../../resources/js/lib/attendanceBranchTab.js';

test('a link from a branch report page opens the profile card on that branch', () => {
    assert.equal(branchFromUrl('/players/12?attendance_branch=3'), 3);
    assert.equal(branchFromUrl('/players/12?tab=docs&attendance_branch=7#card'), 7);
});

test('no or an unusable branch lets the server pick the tab', () => {
    assert.equal(branchFromUrl('/players/12'), null);
    assert.equal(branchFromUrl('/players/12?attendance_branch='), null);
    assert.equal(branchFromUrl('/players/12?attendance_branch=abc'), null);
    assert.equal(branchFromUrl('/players/12?attendance_branch=0'), null);
    assert.equal(branchFromUrl('/players/12?attendance_branch=-2'), null);
    assert.equal(branchFromUrl(undefined), null);
});
