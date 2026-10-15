import { test } from 'node:test';
import assert from 'node:assert/strict';
import { currencyLabel } from '../../resources/js/lib/currencyLabel.js';

test('the dinar reads in the language of the screen', () => {
    assert.equal(currencyLabel({ code: 'DZD', symbol: null }, 'د.ج'), 'د.ج');
    assert.equal(currencyLabel({ code: 'DZD', symbol: null }, 'DA'), 'DA');
});

test('the euro and the dollar use their sign', () => {
    assert.equal(currencyLabel({ code: 'EUR', symbol: null }, 'DA'), '€');
    assert.equal(currencyLabel({ code: 'usd', symbol: null }, 'DA'), '$');
});

test('another currency shows its code', () => {
    assert.equal(currencyLabel({ code: 'TND', symbol: null }, 'DA'), 'TND');
});

test('a custom symbol wins, unless it only repeats the code', () => {
    assert.equal(currencyLabel({ code: 'DZD', symbol: 'دج' }, 'DA'), 'دج');
    assert.equal(currencyLabel({ code: 'DZD', symbol: 'DZD' }, 'DA'), 'DA');
    assert.equal(currencyLabel({ code: 'EUR', symbol: '  ' }, 'DA'), '€');
});

test('no currency shared yet means the dinar', () => {
    assert.equal(currencyLabel(undefined, 'DA'), 'DA');
    assert.equal(currencyLabel({}, 'د.ج'), 'د.ج');
});
