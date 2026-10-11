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

/*
 * The date field and its time modes.
 *
 * Everything below works on the wall clock the reader sees: the year, month and
 * day, the hour and the minute, as text. Nothing here builds a `Date` and reads
 * it back through `toISOString()`, so no value is ever converted to UTC and no
 * date moves by a day in Angola (UTC+1).
 */

/** What a date field holds: a day (`date`), a day and a time, or a time alone. */
export type DateInputMode = 'date' | 'datetime' | 'time';

/** A field's value split into its two wire parts; either is null when absent. */
export interface PickerValue {
    /** `yyyy-MM-dd`. */
    date: string | null;
    /** `HH:mm`, 24-hour. */
    time: string | null;
}

export const MONTH_NAMES = [
    'Janeiro',
    'Fevereiro',
    'Março',
    'Abril',
    'Maio',
    'Junho',
    'Julho',
    'Agosto',
    'Setembro',
    'Outubro',
    'Novembro',
    'Dezembro',
] as const;

/** Sunday first, the order Angolan calendars are printed in. */
export const WEEKDAY_NAMES = [
    'dom',
    'seg',
    'ter',
    'qua',
    'qui',
    'sex',
    'sáb',
] as const;

const MODEL_DATE = /^(\d{4}-\d{2}-\d{2})$/;
const MODEL_DATETIME =
    /^(\d{4}-\d{2}-\d{2})[T ]([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?$/;
const MODEL_TIME = /^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d(?:\.\d+)?)?$/;
const TYPED_DATE = /^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/;
const TYPED_TIME = /^(\d{1,2}):(\d{2})$/;

function two(value: number): string {
    return String(value).padStart(2, '0');
}

/** Whether `value` is a real `yyyy-MM-dd` calendar date (no 31 February). */
export function isIsoDate(value: string): boolean {
    return addDaysToIsoDate(value, 0) !== null;
}

/** Whether `value` is a 24-hour `HH:mm` time. */
export function isClockTime(value: string): boolean {
    const match = MODEL_TIME.exec(value);

    return match !== null && match[0].length === 5;
}

/** The year, month (1–12) and day of a `yyyy-MM-dd` date. */
export function isoDateParts(
    isoDate: string,
): { year: number; month: number; day: number } | null {
    if (!isIsoDate(isoDate)) {
        return null;
    }

    return {
        year: Number(isoDate.slice(0, 4)),
        month: Number(isoDate.slice(5, 7)),
        day: Number(isoDate.slice(8, 10)),
    };
}

/** Builds `yyyy-MM-dd`, or null for a day that does not exist. */
export function makeIsoDate(
    year: number,
    month: number,
    day: number,
): string | null {
    const value = `${String(year).padStart(4, '0')}-${two(month)}-${two(day)}`;

    return isIsoDate(value) ? value : null;
}

/** 0 is Sunday, as `Date#getDay` counts, for the calendar's column. */
export function weekdayOfIsoDate(isoDate: string): number {
    const parts = isoDateParts(isoDate);

    return parts === null
        ? 0
        : new Date(
              Date.UTC(parts.year, parts.month - 1, parts.day),
          ).getUTCDay();
}

/**
 * Moves a date by whole months, keeping the day where the target month has it
 * and the last day where it does not («31 Jan» plus a month is «28 Feb»).
 */
export function addMonthsToIsoDate(
    isoDate: string,
    months: number,
): string | null {
    const parts = isoDateParts(isoDate);

    if (parts === null || !Number.isInteger(months)) {
        return null;
    }

    const index = parts.year * 12 + (parts.month - 1) + months;
    const year = Math.floor(index / 12);
    const month = (index % 12) + 1;
    // Day 0 of the next month is the last day of this one; UTC is only a
    // calendar here, with no daylight-saving gaps.
    const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate();

    return makeIsoDate(year, month, Math.min(parts.day, daysInMonth));
}

/** Earlier is negative, later positive, equal zero. `yyyy-MM-dd` sorts as text. */
export function compareIsoDates(first: string, second: string): number {
    return first < second ? -1 : first > second ? 1 : 0;
}

/** Keeps `isoDate` inside the bounds, either of which may be absent. */
export function clampIsoDate(
    isoDate: string,
    min?: string | null,
    max?: string | null,
): string {
    if (min && isoDate < min) {
        return min;
    }

    if (max && isoDate > max) {
        return max;
    }

    return isoDate;
}

/** «Outubro 2026». */
export function formatMonthYear(year: number, month: number): string {
    return `${MONTH_NAMES[month - 1] ?? ''} ${year}`;
}

/** «11 de Outubro de 2026», for a screen reader reading a day. */
export function spokenIsoDate(isoDate: string): string {
    const parts = isoDateParts(isoDate);

    return parts === null
        ? isoDate
        : `${parts.day} de ${MONTH_NAMES[parts.month - 1]} de ${parts.year}`;
}

export interface CalendarDay {
    iso: string;
    day: number;
    /** False for the days of the neighbouring months that fill the weeks. */
    inMonth: boolean;
}

/**
 * The six weeks that show a month, so the popup keeps its height from one
 * month to the next. `weekStart` is the column the week begins on (0 Sunday).
 */
export function monthGrid(
    year: number,
    month: number,
    weekStart = 0,
): CalendarDay[][] {
    const first = makeIsoDate(year, month, 1);

    if (first === null) {
        return [];
    }

    const lead = (weekdayOfIsoDate(first) - weekStart + 7) % 7;
    const weeks: CalendarDay[][] = [];

    for (let week = 0; week < 6; week += 1) {
        const row: CalendarDay[] = [];

        for (let column = 0; column < 7; column += 1) {
            const iso = addDaysToIsoDate(first, week * 7 + column - lead);

            if (iso === null) {
                continue;
            }

            row.push({
                iso,
                day: Number(iso.slice(8, 10)),
                inMonth: Number(iso.slice(5, 7)) === month,
            });
        }

        weeks.push(row);
    }

    return weeks;
}

/**
 * Reads a field's value from the page's data. `date` is `yyyy-MM-dd`,
 * `datetime` is `yyyy-MM-ddTHH:mm` (a space instead of the `T` is accepted, and
 * seconds or an offset are ignored, never applied) and `time` is `HH:mm`.
 */
export function parseModelValue(
    value: string | null | undefined,
    mode: DateInputMode,
): PickerValue | null {
    if (typeof value !== 'string' || value === '') {
        return null;
    }

    if (mode === 'time') {
        const match = MODEL_TIME.exec(value);

        return match === null
            ? null
            : { date: null, time: `${match[1]}:${match[2]}` };
    }

    if (mode === 'datetime') {
        const match = MODEL_DATETIME.exec(value);

        return match === null || !isIsoDate(match[1])
            ? null
            : { date: match[1], time: `${match[2]}:${match[3]}` };
    }

    // A date field also accepts a datetime it was handed, and keeps the day.
    const match = MODEL_DATE.exec(value) ?? MODEL_DATETIME.exec(value);

    return match === null || !isIsoDate(match[1])
        ? null
        : { date: match[1], time: null };
}

/**
 * Writes a field's value for the page's data, the inverse of
 * `parseModelValue`. A datetime with a day but no time takes midnight; the
 * separator is `T` unless a legacy caller asks for a space.
 */
export function formatModelValue(
    value: PickerValue | null,
    mode: DateInputMode,
    separator: 'T' | ' ' = 'T',
): string | null {
    if (value === null) {
        return null;
    }

    if (mode === 'time') {
        return value.time !== null && isClockTime(value.time)
            ? value.time
            : null;
    }

    if (value.date === null || !isIsoDate(value.date)) {
        return null;
    }

    if (mode === 'date') {
        return value.date;
    }

    const time =
        value.time !== null && isClockTime(value.time) ? value.time : '00:00';

    return `${value.date}${separator}${time}`;
}

/** What the person reads in the field: `dd/MM/yyyy`, with `HH:mm` after it, or `HH:mm`. */
export function formatDisplayValue(
    value: PickerValue | null,
    mode: DateInputMode,
): string {
    if (value === null) {
        return '';
    }

    const parts = value.date === null ? null : isoDateParts(value.date);
    const day =
        parts === null
            ? ''
            : `${two(parts.day)}/${two(parts.month)}/${String(parts.year).padStart(4, '0')}`;

    if (mode === 'time') {
        return value.time ?? '';
    }

    if (mode === 'date') {
        return day;
    }

    return day === '' ? '' : `${day} ${value.time ?? '00:00'}`;
}

/**
 * Reads what the person typed. Days are `dd/MM/yyyy` (a dot or a dash works as
 * well as a slash, and a single digit as well as two), then a space and `HH:mm`
 * for a datetime; a time on its own is `HH:mm`. Anything that is not a real
 * date or time is null.
 */
export function parseTypedValue(
    text: string,
    mode: DateInputMode,
): PickerValue | null {
    const trimmed = text.trim();

    if (trimmed === '') {
        return null;
    }

    if (mode === 'time') {
        return parseTypedTime(trimmed);
    }

    const [typedDay, timeText, ...rest] = trimmed.split(/\s+/);

    if (rest.length > 0 || (mode === 'date' && timeText !== undefined)) {
        return null;
    }

    // Eight bare digits are a pasted or dictated «25122026»: read them as a date.
    const dayText = /^\d{8}$/.test(typedDay)
        ? `${typedDay.slice(0, 2)}/${typedDay.slice(2, 4)}/${typedDay.slice(4)}`
        : typedDay;
    const match = TYPED_DATE.exec(dayText);

    if (match === null) {
        return null;
    }

    const date = makeIsoDate(
        Number(match[3]),
        Number(match[2]),
        Number(match[1]),
    );

    if (date === null) {
        return null;
    }

    if (mode === 'date') {
        return { date, time: null };
    }

    if (timeText === undefined) {
        return null;
    }

    const time = parseTypedTime(timeText);

    return time === null ? null : { date, time: time.time };
}

function parseTypedTime(text: string): PickerValue | null {
    const match = TYPED_TIME.exec(text);

    if (match === null) {
        return null;
    }

    const hours = Number(match[1]);
    const minutes = Number(match[2]);

    return hours > 23 || minutes > 59
        ? null
        : { date: null, time: `${two(hours)}:${two(minutes)}` };
}

/**
 * Adds the separators as someone types digits at the end of the field, so
 * «1110» becomes «11/10/» without the slash being typed: after the day, after
 * the month, after the year (a space, in a datetime) and after the hour. It
 * only ever appends, and only when text was added, so deleting and editing in
 * the middle behave like any text field. A separator typed by hand right after
 * an added one is swallowed.
 */
export function maskTypedValue(
    text: string,
    previous: string,
    mode: DateInputMode,
): string {
    if (text.length <= previous.length) {
        return text;
    }

    // A separator typed right after the one that was added for the person is
    // the same separator, not a second one.
    if (
        text.length === previous.length + 1 &&
        text.startsWith(previous) &&
        /[/.\-: ]$/.test(previous) &&
        /[/.\-: ]$/.test(text)
    ) {
        return previous;
    }

    if (!/\d$/.test(text)) {
        return text;
    }

    const next =
        mode === 'time'
            ? /^\d{2}$/.test(text)
                ? ':'
                : ''
            : /^\d{2}$/.test(text) || /^\d{1,2}\/\d{2}$/.test(text)
              ? '/'
              : mode === 'datetime' && /^\d{1,2}\/\d{1,2}\/\d{4}$/.test(text)
                ? ' '
                : mode === 'datetime' &&
                    /^\d{1,2}\/\d{1,2}\/\d{4} \d{2}$/.test(text)
                  ? ':'
                  : '';

    return `${text}${next}`;
}

/** The current local time as `HH:mm`, on the reader's own clock. */
export function localClockTime(date: Date = new Date()): string {
    return `${two(date.getHours())}:${two(date.getMinutes())}`;
}

/** Steps hours by one, wrapping 23 to 0 and 0 to 23. */
export function stepHours(hours: number, direction: 1 | -1): number {
    return (hours + direction + 24) % 24;
}

/**
 * Steps minutes to the next five-minute mark: from 13 up is 15, down is 10;
 * from a mark it is five. It wraps without carrying into the hour.
 */
export function stepMinutes(minutes: number, direction: 1 | -1): number {
    const onMark = minutes % 5 === 0;
    const next =
        direction === 1
            ? (Math.floor(minutes / 5) + 1) * 5
            : onMark
              ? minutes - 5
              : Math.floor(minutes / 5) * 5;

    return (next + 60) % 60;
}
