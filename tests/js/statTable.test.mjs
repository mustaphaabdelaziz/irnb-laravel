import { test } from 'node:test';
import assert from 'node:assert/strict';
import { BOM, columnTotals, sortRows, sumColumn, escapeFormula, toCsv, safeFilename } from '../../resources/js/lib/statTable.js';

const rows = [
    { name: 'Béja', amount: 300 },
    { name: 'alger', amount: null },
    { name: 'Oran', amount: '1200.50' },
    { name: 'Annaba', amount: 45 },
];

test('numbers sort as numbers, numeric strings included', () => {
    assert.deepEqual(sortRows(rows, 'amount', 'asc').map((r) => r.name), ['Annaba', 'Béja', 'Oran', 'alger']);
    assert.deepEqual(sortRows(rows, 'amount', 'desc').map((r) => r.name), ['Oran', 'Béja', 'Annaba', 'alger']);
});

test('blanks stay last whichever way the column is sorted', () => {
    assert.equal(sortRows(rows, 'amount', 'asc').at(-1).name, 'alger');
    assert.equal(sortRows(rows, 'amount', 'desc').at(-1).name, 'alger');
});

test('text sorts case- and accent-insensitively, and sorting never mutates the input', () => {
    const before = rows.map((r) => r.name);
    assert.deepEqual(sortRows(rows, 'name', 'asc', 'fr').map((r) => r.name), ['alger', 'Annaba', 'Béja', 'Oran']);
    assert.deepEqual(rows.map((r) => r.name), before);
});

test('no sort key returns a copy in the original order', () => {
    const out = sortRows(rows, null);
    assert.notEqual(out, rows);
    assert.deepEqual(out, rows);
});

test('equal values keep their original order', () => {
    const tied = [{ k: 'a', v: 1 }, { k: 'b', v: 1 }, { k: 'c', v: 0 }];
    assert.deepEqual(sortRows(tied, 'v', 'desc').map((r) => r.k), ['a', 'b', 'c']);
});

test('sumColumn ignores blanks and rounds float noise', () => {
    assert.equal(sumColumn(rows, 'amount'), 1545.5);
    assert.equal(sumColumn([{ v: 0.1 }, { v: 0.2 }], 'v'), 0.3);
    assert.equal(sumColumn([], 'v'), 0);
});

test('escapeFormula matches the server CsvWriter rules', () => {
    assert.equal(escapeFormula('=SUM(A1)'), "'=SUM(A1)");
    assert.equal(escapeFormula('@cmd'), "'@cmd");
    assert.equal(escapeFormula('\tx'), "'\tx");
    assert.equal(escapeFormula('+HYPERLINK("x")'), '\'+HYPERLINK("x")');
    assert.equal(escapeFormula('-cmd|x'), "'-cmd|x");
    assert.equal(escapeFormula('+213 555 12 34'), '+213 555 12 34');
    assert.equal(escapeFormula('-5'), '-5');
    assert.equal(escapeFormula(''), '');
    assert.equal(escapeFormula('Oran'), 'Oran');
});

const columns = [
    { key: 'name', label: 'Ville', format: 'text' },
    { key: 'amount', label: 'Montant', format: 'money', total: 'sum' },
    { key: 'share', label: 'Part', format: 'percent' },
];

test('BOM is the single U+FEFF character', () => {
    assert.equal(BOM.charCodeAt(0), 0xfeff);
    assert.equal(BOM.length, 1);
});

test('toCsv: BOM, CRLF, comma, currency in the money header, raw numbers', () => {
    const csv = toCsv(columns, [{ name: 'Oran', amount: 1200.5, share: 12.5 }], { currency: 'DA' });
    assert.ok(csv.startsWith(BOM));
    assert.equal(csv, `${BOM}Ville,"Montant (DA)",Part\r\nOran,1200.5,12.5\r\n`);
});

test('toCsv quotes commas, quotes, line breaks and spaces like fputcsv', () => {
    const csv = toCsv([{ key: 'v', label: 'V' }], [{ v: 'a,b' }, { v: 'say "hi"' }, { v: 'two\nlines' }, { v: 'Sidi Bel Abbès' }]);
    assert.equal(csv, `${BOM}V\r\n"a,b"\r\n"say ""hi"""\r\n"two\nlines"\r\n"Sidi Bel Abbès"\r\n`);
});

test('toCsv escapes formula cells, leaves numbers alone, blanks empty, booleans 1/0', () => {
    const csv = toCsv([{ key: 'a', label: 'A' }, { key: 'b', label: 'B' }], [{ a: '=1+1', b: -5 }, { a: null, b: true }]);
    assert.equal(csv, `${BOM}A,B\r\n'=1+1,-5\r\n,1\r\n`);
});

test('toCsv appends the totals row with its label in the first column', () => {
    const csv = toCsv(columns, [{ name: 'Oran', amount: 10, share: 50 }], { totals: { amount: 10 }, totalLabel: 'Total' });
    assert.ok(csv.endsWith('Total,10,\r\n'));
});

test('toCsv uses a column csv() accessor when given', () => {
    const csv = toCsv([{ key: 'm', label: 'Mois', csv: (row) => `M${row.m}` }], [{ m: 9 }]);
    assert.equal(csv, `${BOM}Mois\r\nM9\r\n`);
});

test('toCsv uses a csv() accessor on a non-first column', () => {
    const cols = [{ key: 'name', label: 'Nom' }, { key: 'status', label: 'Statut', csv: (row) => (row.status === 'closed' ? 'Clôturée' : 'Ouverte') }];
    const csv = toCsv(cols, [{ name: 'A', status: 'closed' }, { name: 'B', status: 'open' }]);
    assert.equal(csv, `${BOM}Nom,Statut\r\nA,Clôturée\r\nB,Ouverte\r\n`);
});

test('toCsv totals row with no summable column holds only the label', () => {
    const cols = [{ key: 'a', label: 'A' }, { key: 'b', label: 'B' }, { key: 'c', label: 'C' }];
    const csv = toCsv(cols, [{ a: 'x', b: 1, c: 2 }], { totals: {}, totalLabel: 'Total' });
    assert.ok(csv.endsWith('Total,,\r\n'));
});

test('safeFilename strips characters Windows refuses and keeps Arabic', () => {
    assert.equal(safeFilename('a/b:c*?"<>|d'), 'a-b-c-d');
    assert.equal(safeFilename('المداخيل 2026/2027'), 'المداخيل 2026-2027');
    assert.equal(safeFilename('   '), 'export');
});

const totalCols = [
    { key: 'name', label: 'Ville', format: 'text' },
    { key: 'amount', label: 'Montant', format: 'money', total: 'sum' },
    { key: 'count', label: 'N', format: 'number', total: 'sum' },
    { key: 'share', label: 'Part', format: 'percent' },
];
const totalRows = [
    { name: 'A', amount: 10.5, count: 1, share: 40 },
    { name: 'B', amount: null, count: '', share: 60 },
    { name: 'C', amount: 4.5, count: 2, share: 0 },
];

test('columnTotals sums only the columns marked total: sum', () => {
    assert.deepEqual(columnTotals(totalCols, totalRows), { amount: 15, count: 3 });
});

test('columnTotals leaves percent and no-total columns out', () => {
    const totals = columnTotals(totalCols, totalRows);
    assert.equal('share' in totals, false);
    assert.equal('name' in totals, false);
});

test('columnTotals counts blanks as 0 and gives zeros for no rows', () => {
    assert.deepEqual(columnTotals(totalCols, [{ name: 'X' }]), { amount: 0, count: 0 });
    assert.deepEqual(columnTotals(totalCols, []), { amount: 0, count: 0 });
});

test('toCsv writes the columnTotals row', () => {
    const csv = toCsv(totalCols, totalRows, { totals: columnTotals(totalCols, totalRows), totalLabel: 'Total' });
    assert.ok(csv.endsWith('Total,15,3,\r\n'));
});
