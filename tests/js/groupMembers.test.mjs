import test from 'node:test';
import assert from 'node:assert/strict';
import { groupSections, memberChanges, memberSave, tickAll } from '../../resources/js/lib/groupMembers.js';

test('memberChanges reports who was ticked and who was unticked', () => {
    assert.deepEqual(memberChanges([1, 2, 3], [2, 3, 4]), { add: [4], remove: [1] });
});

test('memberChanges reports nothing when the ticks did not change', () => {
    assert.deepEqual(memberChanges([1, 2], [2, 1]), { add: [], remove: [] });
});

test('memberChanges compares ids as numbers', () => {
    assert.deepEqual(memberChanges(['1'], [1, '2']), { add: [2], remove: [] });
});

test('groupSections gathers the groups of one branch + category with the players waiting', () => {
    const groups = [
        { id: 1, key: '1:11', branch_id: 1, category_id: 11, name: 'G1' },
        { id: 2, key: '1:11', branch_id: 1, category_id: 11, name: 'G2' },
        { id: 3, key: '2:7', branch_id: 2, category_id: 7, name: 'A' },
    ];
    const rosters = {
        '1:11': [{ id: 5, name: 'a', group_id: 1 }, { id: 6, name: 'b', group_id: null }],
    };

    const sections = groupSections(groups, rosters);

    assert.deepEqual(sections.map((s) => [s.key, s.groups.map((g) => g.id), s.waiting.map((p) => p.id)]), [
        ['1:11', [1, 2], [6]],
        ['2:7', [3], []],
    ]);
});

test('memberSave sends every ticked player, so the date also reaches those already in the group', () => {
    assert.deepEqual(memberSave([1, 2, 3], [2, 3, 4]), { add: [2, 3, 4], remove: [1], changed: true });
});

test('memberSave marks an untouched tick list as unchanged', () => {
    assert.deepEqual(memberSave([1, 2], ['2', 1]), { add: [2, 1], remove: [], changed: false });
});

test('tickAll adds every shown player and keeps the ones ticked out of view', () => {
    assert.deepEqual(tickAll([9], [1, 2, 9], true), [9, 1, 2]);
});

test('tickAll unticks only the shown players', () => {
    assert.deepEqual(tickAll([1, 2, 9], ['1', 2], false), [9]);
});
