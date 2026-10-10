const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

/**
 * Adds whole days to a calendar date, as the calendar counts them.
 *
 * A fiscal date such as «issued on 10 Oct, due in 30 days» is a position on
 * the calendar, not an instant. Building a local-midnight `Date` and then
 * reading it back through `toISOString()` converts it to UTC, which in Angola
 * (UTC+1) lands on the previous day. This works on the year, month and day
 * alone, using UTC only as a calendar that has no daylight-saving gaps, so the
 * answer is the same in every timezone.
 *
 * Returns `null` for anything that is not a real `yyyy-MM-dd` date.
 */
export function addDaysToIsoDate(isoDate: string, days: number): string | null {
    const match = ISO_DATE.exec(isoDate);

    if (match === null || !Number.isInteger(days)) {
        return null;
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const start = new Date(Date.UTC(year, month - 1, day));

    // Date.UTC rolls 31 February into March; a real date round-trips unchanged.
    if (
        start.getUTCFullYear() !== year ||
        start.getUTCMonth() !== month - 1 ||
        start.getUTCDate() !== day
    ) {
        return null;
    }

    const result = new Date(start.getTime() + days * 86_400_000);

    return [
        String(result.getUTCFullYear()).padStart(4, '0'),
        String(result.getUTCMonth() + 1).padStart(2, '0'),
        String(result.getUTCDate()).padStart(2, '0'),
    ].join('-');
}

/**
 * The calendar date on the reader's own clock, as `yyyy-MM-dd`.
 *
 * «Today» on a form is the day the person filling it in is living, so it is
 * read from the local year, month and day. `toISOString()` answers in UTC
 * instead, which in Angola is still yesterday for the first hour of every day.
 */
export function localIsoDate(date: Date = new Date()): string {
    return [
        String(date.getFullYear()).padStart(4, '0'),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

/** The first of January of the reader's current year, as `yyyy-MM-dd`. */
export function startOfYearIsoDate(date: Date = new Date()): string {
    return `${String(date.getFullYear()).padStart(4, '0')}-01-01`;
}
