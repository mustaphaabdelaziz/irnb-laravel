import { test } from 'node:test';
import assert from 'node:assert/strict';
import { positionLabeller } from '../../resources/js/lib/positionLabels.js';

const positions = [
    { id: 1, name: 'Goalkeeper', abbreviation: 'GK', branch: { name: 'Football', localized_name: 'Football' } },
    { id: 2, name: 'Goalkeeper', abbreviation: 'GK', branch: { name: 'Handball', localized_name: 'Hand' } },
    { id: 3, name: 'Striker', abbreviation: 'ST', branch: { name: 'Football', localized_name: 'Football' } },
    { id: 4, name: 'Pivot', abbreviation: 'PV', branch: null },
];

test('the sport is added only to names shared by several sports', () => {
    const label = positionLabeller(positions);
    assert.equal(label(positions[0]), 'Goalkeeper · Football');
    assert.equal(label(positions[1]), 'Goalkeeper · Hand');
    assert.equal(label(positions[2]), 'Striker');
    assert.equal(label(positions[3]), 'Pivot');
});

test('a custom base label keeps the sport suffix rule', () => {
    const label = positionLabeller(positions, (p) => `${p.abbreviation} - ${p.name}`);
    assert.equal(label(positions[1]), 'GK - Goalkeeper · Hand');
    assert.equal(label(positions[2]), 'ST - Striker');
});
