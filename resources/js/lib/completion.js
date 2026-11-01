/**
 * Document completion: the pure parts of CompletionBar and the dashboard's
 * completion charts, kept free of Vue so node:test covers them.
 *
 * The bar has three parts, in this order: received and still valid,
 * received but expiring within 30 days (still counted as received), missing.
 * They add up to the required documents.
 */
import { seriesColor } from './chartTheme.js';

/** Narrowest a non-zero part is drawn (percent of the bar), so one missing document stays visible. */
export const MIN_SLICE = 2;

const round3 = (value) => Math.round(value * 1000) / 1000;

/**
 * @param {{ received?: number, expiring?: number, missing?: number, required?: number }} counts
 * @returns {{ key: 'received'|'expiring'|'missing', count: number, width: number }[]}
 */
export function segments({ received = 0, expiring = 0, missing = 0, required = 0 } = {}) {
    if (!(required > 0)) return [];

    const parts = [
        { key: 'received', count: Math.max(0, received - expiring) },
        { key: 'expiring', count: Math.max(0, expiring) },
        { key: 'missing', count: Math.max(0, missing) },
    ].filter((part) => part.count > 0);

    const total = parts.reduce((sum, part) => sum + part.count, 0);
    if (total === 0) return [];

    parts.forEach((part) => { part.width = (part.count / total) * 100; });

    // Widen tiny parts to MIN_SLICE and take the difference from the widest
    // part (at least a third of the bar, so it can always give).
    let deficit = 0;
    parts.forEach((part) => {
        if (part.width < MIN_SLICE) {
            deficit += MIN_SLICE - part.width;
            part.width = MIN_SLICE;
        }
    });
    const widest = parts.reduce((a, b) => (b.width > a.width ? b : a));
    widest.width -= deficit;

    // Round the others; the widest takes the remainder so the bar is exactly 100.
    const others = parts.filter((part) => part !== widest);
    others.forEach((part) => { part.width = round3(part.width); });
    widest.width = round3(100 - others.reduce((sum, part) => sum + part.width, 0));

    return parts;
}

/** received / required × 100 with one decimal (PHP round(…, 1)); null when nothing is required. */
export function completionPct({ received = 0, required = 0 } = {}) {
    if (!(required > 0)) return null;

    return Math.round((received / required) * 1000) / 10;
}

/**
 * Part colours for the current theme. chartTheme has no semantic tokens, so
 * these are its validated categorical slots: green, amber, red. Neighbouring
 * parts are always separated by a surface-coloured edge and labelled with
 * their numbers, so colour is never the only channel.
 */
export function completionColors() {
    return {
        received: seriesColor(2),
        expiring: seriesColor(3),
        missing: seriesColor(7),
    };
}

/** CompletionBar props from a DocumentStats summary (snake_case → camelCase). */
export function completionBarProps(summary) {
    return {
        received: summary?.received ?? 0,
        expiring: summary?.expiring ?? 0,
        missing: summary?.missing ?? 0,
        required: summary?.required ?? 0,
        playersComplete: summary?.players_complete ?? 0,
        playersTotal: summary?.players_total ?? 0,
    };
}
