/**
 * Dates on the user's own clock. toISOString() gives the UTC date, which is
 * still yesterday between midnight and 1 AM in Algiers, so a date field that
 * defaults to "today" must be built from the local parts instead.
 */
const pad = (n) => String(n).padStart(2, '0');

/** 'Y-m-d' of a Date, read in local time. */
export const localDateKey = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

/** Today as 'Y-m-d' on the local clock. */
export const localToday = (now = new Date()) => localDateKey(now);

/**
 * Whether someone born on `birthdate` ('Y-m-d') is under 18 on `today`.
 * Compared as strings, so a 29 February birthday turns 18 on 1 March.
 */
export const isMinor = (birthdate, today = localToday()) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(birthdate || '');
    if (!match) return false;
    return `${Number(match[1]) + 18}-${match[2]}-${match[3]}` > today;
};
