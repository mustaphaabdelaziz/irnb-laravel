import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ageRangeLabel } from '../../resources/js/lib/documentAge.js';

// Stands in for vue-i18n's t(): the key and its values, so a test reads both.
const t = (key, values) => `${key}:${JSON.stringify(values)}`;

test('a type without ages has no label', () => {
    assert.equal(ageRangeLabel(t, { min_age: null, max_age: null }), null);
    assert.equal(ageRangeLabel(t, {}), null);
});

test('a maximum alone reads "up to"', () => {
    assert.equal(ageRangeLabel(t, { min_age: null, max_age: 17 }), 'doc_up_to_age:{"age":17}');
});

test('a minimum alone reads "from"', () => {
    assert.equal(ageRangeLabel(t, { min_age: 15, max_age: null }), 'doc_from_age:{"age":15}');
});

test('both read as a range', () => {
    assert.equal(ageRangeLabel(t, { min_age: 15, max_age: 17 }), 'doc_age_range:{"min":15,"max":17}');
});

test('the same age on both sides reads as that one age', () => {
    assert.equal(ageRangeLabel(t, { min_age: 15, max_age: 15 }), 'doc_age_exact:{"age":15}');
});
