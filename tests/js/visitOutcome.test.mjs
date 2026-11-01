import { test } from 'node:test';
import assert from 'node:assert/strict';
import { visitOutcome } from '../../resources/js/lib/visitOutcome.js';

test('an interrupted visit is superseded even if the success flag is set', () => {
    assert.equal(visitOutcome({ interrupted: true }, true), 'superseded');
});

test('a cancelled visit is superseded', () => {
    assert.equal(visitOutcome({ cancelled: true }, false), 'superseded');
});

test('a completed visit that succeeded is ok', () => {
    assert.equal(visitOutcome({ completed: true, cancelled: false, interrupted: false }, true), 'ok');
});

test('a completed visit that did not succeed failed', () => {
    assert.equal(visitOutcome({ completed: true, cancelled: false, interrupted: false }, false), 'failed');
});

test('no visit object and no success is a failure', () => {
    assert.equal(visitOutcome(undefined, false), 'failed');
});
