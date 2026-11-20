import test from 'node:test';
import assert from 'node:assert/strict';
import { groupsFor } from '../../resources/js/lib/groupPicker.js';

const GROUPS = [
    { id: 1, branch_id: 1, category_id: 11, name: 'G1' },
    { id: 2, branch_id: 1, category_id: 11, name: 'G2' },
    { id: 3, branch_id: 1, category_id: 12, name: 'Other category' },
    { id: 4, branch_id: 2, category_id: 11, name: 'Other branch' },
    { id: 5, branch_id: null, category_id: 20, name: 'Club-wide' },
];

test('groupsFor keeps the groups of one branch + category', () => {
    assert.deepEqual(groupsFor(GROUPS, 1, 11).map((g) => g.id), [1, 2]);
});

test('groupsFor compares ids as numbers', () => {
    assert.deepEqual(groupsFor(GROUPS, '1', '12').map((g) => g.id), [3]);
});

test('groupsFor matches the club-wide groups when there is no branch', () => {
    assert.deepEqual(groupsFor(GROUPS, null, 20).map((g) => g.id), [5]);
    assert.deepEqual(groupsFor(GROUPS, undefined, 20).map((g) => g.id), [5]);
});

test('groupsFor offers nothing without a category or without groups', () => {
    assert.deepEqual(groupsFor(GROUPS, 1, ''), []);
    assert.deepEqual(groupsFor(GROUPS, 1, null), []);
    assert.deepEqual(groupsFor(undefined, 1, 11), []);
});
