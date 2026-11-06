import { test } from 'node:test';
import assert from 'node:assert/strict';
import { categoriesForBranch, categoriesForBranches } from '../../resources/js/lib/categoryPicker.js';

const list = [
    { id: 1, name: 'Senior', branch_id: 10, is_active: true },
    { id: 2, name: 'U15', branch_id: 20, is_active: true },
    { id: 3, name: 'Old', branch_id: 10, is_active: false },
    { id: 4, name: 'Open', branch_id: null, is_active: true },
];
const ids = (rows) => rows.map((c) => c.id);

test('a branch lists its own active categories and unowned ones', () => {
    assert.deepEqual(ids(categoriesForBranch(list, 10)), [1, 4]);
    assert.deepEqual(ids(categoriesForBranch(list, 20)), [2, 4]);
});

test('a stored value stays listed, even inactive or from another branch', () => {
    assert.deepEqual(ids(categoriesForBranch(list, 10, [3])), [1, 3, 4]);
    assert.deepEqual(ids(categoriesForBranch(list, 20, [1])), [1, 2, 4]);
    assert.deepEqual(ids(categoriesForBranch(list, 20, ['1', '', null])), [1, 2, 4]);
});

test('no branch lists every active category', () => {
    assert.deepEqual(ids(categoriesForBranch(list, null)), [1, 2, 4]);
    assert.deepEqual(ids(categoriesForBranch(list, '')), [1, 2, 4]);
});

test('several ticked branches list all of theirs, and none ticked lists all', () => {
    assert.deepEqual(ids(categoriesForBranches(list, [10, 20])), [1, 2, 4]);
    assert.deepEqual(ids(categoriesForBranches(list, [20])), [2, 4]);
    assert.deepEqual(ids(categoriesForBranches(list, [])), [1, 2, 4]);
});

test('a branch id arriving as a string still matches', () => {
    assert.deepEqual(ids(categoriesForBranch(list, '20')), [2, 4]);
});

test('a missing is_active counts as active', () => {
    assert.deepEqual(ids(categoriesForBranch([{ id: 9, branch_id: 10 }], 10)), [9]);
});
