import { test } from 'node:test';
import assert from 'node:assert/strict';
import { pageTitle } from '../../resources/js/lib/productBrand.js';

test('the tab names the page, the club, then ASIREM', () => {
    assert.equal(pageTitle('Players', 'FCB'), 'Players - FCB · ASIREM');
    assert.equal(pageTitle('', 'FCB'), 'FCB · ASIREM');
});

test('a club with no name of its own does not read ASIREM twice', () => {
    assert.equal(pageTitle('Players', 'ASIREM'), 'Players - ASIREM');
    assert.equal(pageTitle('Players', ''), 'Players - ASIREM');
    assert.equal(pageTitle(null, null), 'ASIREM');
});
