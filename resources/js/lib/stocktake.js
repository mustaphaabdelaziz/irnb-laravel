// Count-sheet arithmetic for Inventory/Session.vue. Everything is in units:
// a line of 40 cones where 38 turned up is 38 found and 2 missing.

export function initialFound(line) {
    if (!line.counted) return line.expected_quantity;
    if (!line.found) return 0;
    return line.found_quantity ?? line.expected_quantity;
}

export function clampFound(value, expected) {
    const n = Math.floor(Number(value));
    if (!Number.isFinite(n) || n < 0) return 0;
    return Math.min(n, expected);
}

export function unitTotals(lines, state) {
    let expected = 0;
    let found = 0;
    for (const line of lines) {
        expected += line.expected_quantity;
        found += state[line.id]?.qty ?? 0;
    }
    return { expected, found, missing: expected - found };
}
