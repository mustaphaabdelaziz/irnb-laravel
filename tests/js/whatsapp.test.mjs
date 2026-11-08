import { test } from 'node:test';
import assert from 'node:assert/strict';
import { whatsAppDigits, whatsAppUrl } from '../../resources/js/lib/phone.js';

test('a national Algerian number gets 213 in place of its leading 0', () => {
    assert.equal(whatsAppDigits('0559453948'), '213559453948');
    assert.equal(whatsAppDigits('05 59 45 39 48'), '213559453948');
    assert.equal(whatsAppDigits('021234567'), '21321234567');
});

test('an international number keeps its country code, without +', () => {
    assert.equal(whatsAppDigits('+213 5 59 45 39 48'), '213559453948');
    assert.equal(whatsAppDigits('00213661234567'), '213661234567');
    assert.equal(whatsAppDigits('+33612345678'), '33612345678');
});

test('no number, no link', () => {
    assert.equal(whatsAppUrl(''), null);
    assert.equal(whatsAppUrl(null), null);
    assert.equal(whatsAppUrl('+'), null);
    assert.equal(whatsAppUrl('0559453948'), 'https://wa.me/213559453948');
});
