import { test } from 'node:test';
import assert from 'node:assert/strict';
import { numberLocale, formatNumber, formatPercent, figureChars } from '../../resources/js/lib/numberFormat.js';

const nf = (loc, opts) => new Intl.NumberFormat(loc, opts);
const NUM = { minimumFractionDigits: 0, maximumFractionDigits: 2 };

test('numberLocale: ar -> ar-DZ, others -> fr-DZ', () => {
    assert.equal(numberLocale('ar'), 'ar-DZ');
    assert.equal(numberLocale('fr'), 'fr-DZ');
    assert.equal(numberLocale('en'), 'fr-DZ');
});

test('formatNumber follows the money locale mapping', () => {
    assert.equal(formatNumber(1234567.891, 'fr'), nf('fr-DZ', NUM).format(1234567.891));
    assert.equal(formatNumber(1234567.891, 'en'), nf('fr-DZ', NUM).format(1234567.891));
    assert.equal(formatNumber(1234567.891, 'ar'), nf('ar-DZ', NUM).format(1234567.891));
    assert.equal(formatNumber(0, 'fr'), nf('fr-DZ', NUM).format(0));
});

test('formatNumber: numeric strings format like numbers', () => {
    assert.equal(formatNumber('1200.5', 'fr'), nf('fr-DZ', NUM).format(1200.5));
});

test('formatNumber: non-numeric text is returned as-is, non-finite is a dash', () => {
    assert.equal(formatNumber('abc', 'fr'), 'abc');
    assert.equal(formatNumber(NaN, 'fr'), '—');
    assert.equal(formatNumber(Infinity, 'fr'), '—');
});

test('formatPercent: one decimal, % appended directly', () => {
    const out = formatPercent(12.5, 'fr');
    assert.ok(out.endsWith('%'));
    assert.equal(out, nf('fr-DZ', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(12.5) + '%');
    assert.equal(formatPercent(12, 'ar'), nf('ar-DZ', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(12) + '%');
});

test('formatPercent: trim drops a trailing .0', () => {
    assert.equal(formatPercent(12, 'fr', { trim: true }), nf('fr-DZ', { maximumFractionDigits: 1 }).format(12) + '%');
    assert.equal(formatPercent(12.5, 'fr', { trim: true }), nf('fr-DZ', { maximumFractionDigits: 1 }).format(12.5) + '%');
});

test('formatPercent: non-numeric rules match formatNumber', () => {
    assert.equal(formatPercent('abc', 'fr'), 'abc');
    assert.equal(formatPercent(NaN, 'fr'), '—');
    assert.equal(formatPercent('12.5', 'fr'), formatPercent(12.5, 'fr'));
});

test('figureChars counts figures and leaves words alone', () => {
    assert.equal(figureChars('372\u202f500,00'), 10);
    assert.equal(figureChars('121.600,00'), 10);
    assert.equal(figureChars('100%'), 4);
    assert.equal(figureChars(189), 3);
    assert.equal(figureChars(0), 1);
    assert.equal(figureChars('٣٧٢٫٥'), 5);
    assert.equal(figureChars('12/10/2026'), 10);
    assert.equal(figureChars('-1 500'), 6);
});

test('figureChars gives null for anything that may wrap', () => {
    assert.equal(figureChars('—'), null);
    assert.equal(figureChars('Football'), null);
    assert.equal(figureChars('12 joueurs'), null);
    assert.equal(figureChars(''), null);
    assert.equal(figureChars(null), null);
    assert.equal(figureChars(undefined), null);
});
