/**
 * Pure helpers behind StatTable and the per-panel CSV download.
 *
 * toCsv mirrors App\Support\Export\CsvWriter so a file saved from a chart
 * panel opens in Excel exactly like a server export: UTF-8 BOM (Excel's cue to
 * read Arabic correctly), CRLF, comma, fputcsv-style quoting, and the same
 * formula escaping. Change one, change the other.
 */
export const BOM = '\uFEFF';

const isBlank = (value) => value === null || value === undefined || value === '';

const asNumber = (value) => {
    if (typeof value === 'number') return Number.isFinite(value) ? value : null;
    if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) return Number(value);

    return null;
};

/** A sorted copy. Blanks go last in both directions; ties keep their order. */
export function sortRows(rows, key, dir = 'asc', locale = undefined) {
    if (!key) return rows.slice();

    const collator = new Intl.Collator(locale, { numeric: true, sensitivity: 'base' });
    const sign = dir === 'desc' ? -1 : 1;

    return rows
        .map((row, index) => ({ row, index }))
        .sort((x, y) => {
            const a = x.row[key];
            const b = y.row[key];
            if (isBlank(a) && isBlank(b)) return x.index - y.index;
            if (isBlank(a)) return 1;
            if (isBlank(b)) return -1;

            const na = asNumber(a);
            const nb = asNumber(b);
            const order = na !== null && nb !== null ? na - nb : collator.compare(String(a), String(b));

            return sign * order || x.index - y.index;
        })
        .map(({ row }) => row);
}

/** Sum of a column; blanks ignored, rounded to cents so 0.1 + 0.2 reads 0.3. */
export function sumColumn(rows, key) {
    const sum = rows.reduce((total, row) => total + (asNumber(row[key]) ?? 0), 0);

    return Math.round(sum * 100) / 100;
}

/**
 * Excel runs a cell starting with =, @, a tab or a CR as a formula; an
 * apostrophe forces it back to text. + and - are escaped unless the cell reads
 * as a plain number or phone number. Same rule as CsvWriter::escapeFormula.
 */
export function escapeFormula(value) {
    if (value === '') return value;

    const first = value[0];
    if (first === '=' || first === '@' || first === '\t' || first === '\r') return `'${value}`;
    if ((first === '+' || first === '-') && !/^[+-][\d\s().]*$/.test(value)) return `'${value}`;

    return value;
}

function stringify(value) {
    if (value === null || value === undefined) return '';
    if (typeof value === 'boolean') return value ? '1' : '0';
    if (typeof value === 'number') return String(value);

    return escapeFormula(String(value));
}

// fputcsv quotes a field holding the delimiter, the enclosure, a line break,
// a tab or a space.
const quote = (cell) => (/[",\r\n\t ]/.test(cell) ? `"${cell.replace(/"/g, '""')}"` : cell);

const line = (cells) => cells.map((value) => quote(stringify(value))).join(',');

/** The table as CSV text: header (money headers carry the currency), rows, optional totals. */
export function toCsv(columns, rows, { currency = '', totals = null, totalLabel = '' } = {}) {
    const header = columns.map((column) => (column.format === 'money' && currency ? `${column.label} (${currency})` : column.label));
    const lines = [line(header)];

    for (const row of rows) {
        lines.push(line(columns.map((column) => (column.csv ? column.csv(row) : row[column.key]))));
    }

    if (totals) {
        lines.push(line(columns.map((column, index) => {
            if (column.total === 'sum' && totals[column.key] !== undefined) return totals[column.key];

            return index === 0 ? totalLabel : null;
        })));
    }

    return `${BOM}${lines.join('\r\n')}\r\n`;
}

/** A file name Windows accepts: reserved characters become dashes. */
export function safeFilename(name) {
    const cleaned = String(name ?? '').replace(/[\\/:*?"<>|]+/g, '-').trim();

    return cleaned === '' ? 'export' : cleaned;
}

/** Saves CSV text as a file through a temporary object URL. Browser only. */
export function downloadCsv(filename, csv) {
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    const base = safeFilename(filename);
    link.href = url;
    link.download = base.toLowerCase().endsWith('.csv') ? base : `${base}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 0);
}

/** Totals for the columns marked `total: 'sum'`, keyed by column key (what StatTable and toCsv read). */
export function columnTotals(columns, rows) {
    return Object.fromEntries(columns.filter((c) => c.total === 'sum').map((c) => [c.key, sumColumn(rows, c.key)]));
}
