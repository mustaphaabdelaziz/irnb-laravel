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
