import { test } from 'node:test';
import assert from 'node:assert/strict';
import { sportLabeller } from '../../resources/js/lib/sportLabels.js';

const positions = [
    { id: 1, name: 'Goalkeeper', abbreviation: 'GK', branch: { name: 'Football', localized_name: 'Football' } },
    { id: 2, name: 'Goalkeeper', abbreviation: 'GK', branch: { name: 'Handball', localized_name: 'Hand' } },
    { id: 3, name: 'Striker', abbreviation: 'ST', branch: { name: 'Football', localized_name: 'Football' } },
    { id: 4, name: 'Pivot', abbreviation: 'PV', branch: null },
];

test('the sport is added only to names shared by several sports', () => {
    const label = sportLabeller(positions);
    assert.equal(label(positions[0]), 'Goalkeeper · Football');
    assert.equal(label(positions[1]), 'Goalkeeper · Hand');
    assert.equal(label(positions[2]), 'Striker');
    assert.equal(label(positions[3]), 'Pivot');
});

test('a custom base label keeps the sport suffix rule', () => {
    const label = sportLabeller(positions, (p) => `${p.abbreviation} - ${p.name}`);
    assert.equal(label(positions[1]), 'GK - Goalkeeper · Hand');
    assert.equal(label(positions[2]), 'ST - Striker');
});

test('categories use the same rule on their localized names', () => {
    const categories = [
        { id: 1, name: 'Senior', localized_name: 'Séniors', branch: { localized_name: 'Football' } },
        { id: 2, name: 'Senior', localized_name: 'Séniors', branch: { localized_name: 'Natation' } },
        { id: 3, name: 'Ecole', localized_name: 'École', branch: { localized_name: 'Football' } },
    ];
    const label = sportLabeller(categories, (c) => c.localized_name || c.name);
    assert.equal(label(categories[1]), 'Séniors · Natation');
    assert.equal(label(categories[2]), 'École');
});
