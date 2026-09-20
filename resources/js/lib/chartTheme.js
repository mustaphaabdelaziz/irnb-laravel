/**
 * One chart language for the whole app.
 *
 * Chart components import colours and options from here instead of carrying
 * hex literals, so dark mode, RTL and any future palette change happen in one
 * file. The palette below is not a taste choice — it was validated for
 * colourblind separation, contrast against both surfaces and normal-vision
 * distinguishability. The palette it replaced failed: #0f766e and #059669 sat
 * at ΔE 10.3, indistinguishable to readers with full colour vision.
 *
 * Rules that travel with these colours:
 *   - Assign slots in order. Never cycle, never generate a ninth hue.
 *   - Money in is slot 1 (blue). Money out is slot 2 (orange). Always.
 *   - Emerald and rose stay semantic in UI chrome; they are not chart series.
 *     Green-vs-red as two adjacent marks fails CVD at ΔE 5.8.
 */

/** Categorical slots, in assignment order. Dark is re-stepped, not lightened. */
const CATEGORICAL = {
    light: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'],
    dark: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'],
};

/** Single-hue ramp for magnitude. Light to dark; index 0 is nearest the surface. */
const SEQUENTIAL = {
    light: ['#b7d3f6', '#86b6ef', '#5598e7', '#2a78d6', '#1c5cab', '#104281'],
    dark: ['#104281', '#184f95', '#256abf', '#2a78d6', '#3987e5', '#6da7ec'],
};

/**
 * Ordinal ramp for discrete ordered marks (aging buckets, funnel stages).
 *
 * Starts at a step that still clears 2:1 against the surface — the lightest
 * sequential steps are for continuous magnitude, where "near zero" is allowed
 * to recede, and would disappear as a bar.
 */
const ORDINAL = {
    light: ['#86b6ef', '#5598e7', '#2a78d6', '#1c5cab'],
    dark: ['#184f95', '#256abf', '#3987e5', '#6da7ec'],
};

export const MONEY_IN = 0;
export const MONEY_OUT = 1;

export function isDark() {
    if (typeof document === 'undefined') return false;
    const attr = document.documentElement.getAttribute('data-theme');
    if (attr === 'dark') return true;
    if (attr === 'light') return false;
    return document.documentElement.classList.contains('dark');
}

const mode = () => (isDark() ? 'dark' : 'light');

/** The full categorical palette for the active theme. */
export const palette = () => CATEGORICAL[mode()];

/** One categorical slot. Slots wrap only as a last resort — see the rules above. */
export const seriesColor = (slot) => CATEGORICAL[mode()][slot % CATEGORICAL[mode()].length];

export const sequential = (steps = 6) => SEQUENTIAL[mode()].slice(0, steps);

export const ordinal = (steps = 4) => ORDINAL[mode()].slice(0, steps);

export const moneyIn = () => seriesColor(MONEY_IN);
export const moneyOut = () => seriesColor(MONEY_OUT);

/** A series colour at low opacity, for an area wash under a line. */
export function wash(slot, alpha = 0.1) {
    const hex = seriesColor(slot);
    const int = parseInt(hex.slice(1), 16);

    return `rgba(${(int >> 16) & 255}, ${(int >> 8) & 255}, ${int & 255}, ${alpha})`;
}

/** Reads a design token off the document so charts follow the app's theme. */
export function token(name, fallback) {
    if (typeof window === 'undefined') return fallback;
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    return value ? `hsl(${value})` : fallback;
}

export const surface = () => token('--card', isDark() ? '#0f1512' : '#ffffff');
export const ink = () => token('--foreground', isDark() ? '#f8fafc' : '#0f172a');
export const mutedInk = () => token('--muted-foreground', isDark() ? '#94a3b8' : '#64748b');

/** Hairline, solid, one step off the surface. Never dashed. */
const gridColor = () => (isDark() ? 'rgba(148, 163, 184, .16)' : 'rgba(100, 116, 139, .14)');

/**
 * Shared Chart.js options.
 *
 * Mark specs live here rather than in each component: 2px lines, markers that
 * only appear on hover, bars capped at 24px with a rounded data-end, recessive
 * axes, and a legend that is present whenever there is more than one series.
 */
export function baseOptions({ rtl = false, stacked = false, horizontal = false, legend = true, money = null } = {}) {
    const axisTicks = { color: mutedInk(), font: { size: 11 }, padding: 6 };

    return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        layout: { padding: { top: 4, right: 4, bottom: 0, left: 4 } },
        indexAxis: horizontal ? 'y' : 'x',
        plugins: {
            legend: {
                display: legend,
                position: 'bottom',
                align: rtl ? 'end' : 'start',
                rtl,
                labels: {
                    color: mutedInk(),
                    usePointStyle: true,
                    pointStyle: 'circle',
                    boxWidth: 7,
                    boxHeight: 7,
                    padding: 16,
                    font: { size: 11 },
                },
            },
            tooltip: {
                rtl,
                backgroundColor: isDark() ? 'rgba(15, 21, 18, .96)' : 'rgba(15, 23, 42, .94)',
                borderColor: gridColor(),
                borderWidth: 1,
                padding: 10,
                cornerRadius: 8,
                displayColors: true,
                usePointStyle: true,
                callbacks: money
                    ? {
                        label: (ctx) => ` ${ctx.dataset.label ?? ''}: ${money(ctx.parsed[horizontal ? 'x' : 'y'])}`,
                    }
                    : undefined,
            },
        },
        scales: {
            x: {
                stacked,
                reverse: rtl && !horizontal,
                grid: { display: horizontal, color: gridColor(), drawTicks: false },
                border: { display: false },
                ticks: axisTicks,
            },
            y: {
                stacked,
                position: rtl ? 'right' : 'left',
                beginAtZero: true,
                grid: { display: !horizontal, color: gridColor(), drawTicks: false },
                border: { display: false },
                ticks: axisTicks,
            },
        },
        elements: {
            line: { borderWidth: 2, tension: 0.35, capBezierPoints: true },
            point: { radius: 0, hoverRadius: 5, hoverBorderWidth: 2, hoverBorderColor: surface() },
            bar: { borderRadius: 4, borderSkipped: 'start' },
        },
        datasets: {
            bar: { maxBarThickness: 24, borderRadius: 4, borderSkipped: 'start' },
        },
    };
}

/** A line dataset: 2px, washed fill, markers on hover only. */
export function lineDataset(label, data, slot = 0, { fill = true } = {}) {
    return {
        label,
        data,
        borderColor: seriesColor(slot),
        backgroundColor: fill ? wash(slot) : 'transparent',
        fill,
        borderWidth: 2,
        tension: 0.35,
        pointRadius: 0,
        pointHoverRadius: 5,
        pointHoverBorderColor: surface(),
        pointHoverBorderWidth: 2,
    };
}

/** A bar dataset. The 2px surface-coloured border is the gap between stacked segments. */
export function barDataset(label, data, slot = 0, { stack = undefined, color = undefined } = {}) {
    return {
        label,
        data,
        backgroundColor: color ?? seriesColor(slot),
        borderColor: surface(),
        borderWidth: { top: 0, right: 0, bottom: 0, left: 0 },
        borderSkipped: 'start',
        borderRadius: 4,
        maxBarThickness: 24,
        stack,
    };
}
