import { test } from 'node:test';
import assert from 'node:assert/strict';
import { nameKey, similarName } from '../../resources/js/lib/nameKey.js';

test('case, spaces, accents and punctuation do not count', () => {
    assert.equal(nameKey('  Ballon  de-Match '), 'ballondematch');
    assert.equal(nameKey('Ingénieur'), nameKey('INGENIEUR'));
    assert.equal(nameKey('Match  Ball'), nameKey('match ball'));
});

test('Arabic alef, taa and harakat forms count as one spelling', () => {
    assert.equal(nameKey('كرة مباراة'), nameKey('كره مباراه'));
    assert.equal(nameKey('أقمصة'), nameKey('اقمصه'));
    assert.equal(nameKey('كُرَة'), nameKey('كرة'));
    assert.equal(nameKey('كـرة'), nameKey('كرة'));
});

test('similarName finds the existing spelling, or null', () => {
    const names = ['Match Ball', 'كرة مباراة'];
    assert.equal(similarName(names, 'match  ball'), 'Match Ball');
    assert.equal(similarName(names, 'Match Ball'), 'Match Ball');
    assert.equal(similarName(names, 'كره مباراه'), 'كرة مباراة');
    assert.equal(similarName(names, 'Training Ball'), null);
    assert.equal(similarName(names, '   '), null);
    assert.equal(similarName(undefined, 'x'), null);
});
