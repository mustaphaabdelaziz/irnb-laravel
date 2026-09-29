import { barDataset } from '@/lib/chartTheme';
import { parseDay } from '@/lib/attendanceCalendar';

/**
 * Shapes AttendanceStats payloads for charts and tables (statistics page,
 * profile card). Status names and colours are passed in from
 * useAttendanceCodes(); nothing here hard-codes them.
 */

/** Short month tick ("oct. 26") for a 'Y-m' key. */
export const monthTick = (ym, locale) => parseDay(`${ym}-01`).toLocaleDateString(locale, { month: 'short', year: '2-digit' });

/** One bar per month, one stacked segment per status, in the configured colours. */
export function statusBars(monthly, statuses, label, color, locale) {
    return {
        labels: (monthly?.labels ?? []).map((ym) => monthTick(ym, locale)),
        datasets: statuses.map((status) => barDataset(label(status), monthly?.statuses?.[status] ?? [], 0, { stack: 'marks', color: color(status) })),
    };
}

/** True when any month has at least one mark. */
export const hasMarks = (monthly) => Object.values(monthly?.statuses ?? {}).some((series) => series.some((n) => n > 0));

/** 33.3 → "33.3%"; null (nothing expected) → "—". Wrap in <bdi dir="ltr"> for Arabic. */
export const pct = (value) => (value === null || value === undefined ? '—' : `${Number(value).toFixed(1)}%`);

/** Hours with one decimal. */
export const hours = (value) => Number(value ?? 0).toFixed(1);

/** The period as it travels in a URL: from/to only for a custom range. */
export const periodQuery = (period) => (period?.period === 'custom'
    ? { period: 'custom', from: period.from, to: period.to }
    : { period: period?.period ?? 'month' });
