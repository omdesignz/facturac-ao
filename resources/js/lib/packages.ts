/**
 * Pure helpers for subscription packages: the arithmetic behind the staff form's
 * live notes and the wording of a price.
 *
 * Everything is integer arithmetic on minor units (cêntimos), so a price never
 * passes through a float. The customer-facing pages never use these to state a
 * price: the server sends those already worked out. The rules here mirror
 * `SubscriptionPackage::savingPercentage()` on the server so the staff form
 * promises exactly what the pages will show.
 *
 * No imports and no Vue: the node test loads this file directly.
 */

/** The largest price the server accepts for one period, in minor units. */
export const MAXIMUM_AMOUNT_MINOR = 999_999_999_999;

const NO_BREAK_SPACE = ' ';
const MINUS_SIGN = '−';

/** What a period costs per month, rounded down: the server's `intdiv`. */
export function monthlyEquivalentMinor(
    amountMinor: number,
    months: number,
): number {
    if (months <= 0) {
        return 0;
    }

    return Math.floor(amountMinor / months);
}

/** What paying the monthly price for the same number of months would cost. */
function fullPriceMinor(months: number, monthlyAmountMinor: number): number {
    return monthlyAmountMinor * months;
}

/**
 * The whole percent a period saves against paying the monthly price for the
 * same number of months, rounded down. Null when there is no monthly price, or
 * when nothing is saved (equal, dearer, or a saving that rounds to under 1%).
 * Never negative: use `costsMoreThanMonthly` for the dearer case.
 */
export function savingPercentage(
    amountMinor: number,
    months: number,
    monthlyAmountMinor: number | null,
): number | null {
    if (monthlyAmountMinor === null || monthlyAmountMinor <= 0) {
        return null;
    }

    const full = fullPriceMinor(months, monthlyAmountMinor);

    if (full <= 0 || amountMinor >= full) {
        return null;
    }

    const percentage = Math.floor(((full - amountMinor) * 100) / full);

    return percentage > 0 ? percentage : null;
}

/** A period priced above the monthly price times its months. Allowed, but flagged. */
export function costsMoreThanMonthly(
    amountMinor: number,
    months: number,
    monthlyAmountMinor: number | null,
): boolean {
    if (monthlyAmountMinor === null || monthlyAmountMinor <= 0) {
        return false;
    }

    return amountMinor > fullPriceMinor(months, monthlyAmountMinor);
}

export interface ParsedAmount {
    /** The price in minor units. */
    minor: number;
    /** The price as the server wants it: «12500.50». */
    decimal: string;
}

/**
 * Reads a price as a person types it: «12500», «12 500», «12.500», «12500,5»,
 * «12.500,50», «12500.50». A comma is always the decimal mark. A dot is the
 * decimal mark only when one to two digits follow it; a dot followed by three
 * digits (or repeated) groups thousands, as in Portuguese. Returns null for
 * anything else: letters, a sign, more than two decimals, nothing at all, or a
 * price above the server's ceiling. Zero parses; the caller decides what it means.
 */
export function parseAmount(text: string): ParsedAmount | null {
    const compact = text.replace(/[\s  ]/g, '');

    if (compact === '') {
        return null;
    }

    let whole: string;
    let fraction = '';

    if (compact.includes(',')) {
        const parts = compact.split(',');

        if (parts.length !== 2 || /\./.test(parts[1])) {
            return null;
        }

        whole = parts[0].replace(/\./g, '');
        fraction = parts[1];
    } else if (/^\d+\.\d{1,2}$/.test(compact)) {
        [whole, fraction] = compact.split('.');
    } else {
        whole = compact.replace(/\./g, '');

        if (compact.includes('.') && !/^\d{1,3}(\.\d{3})+$/.test(compact)) {
            return null;
        }
    }

    if (!/^\d+$/.test(whole) || !/^\d{0,2}$/.test(fraction)) {
        return null;
    }

    const wholeDigits = whole.replace(/^0+(?=\d)/, '');

    if (wholeDigits.length > 12) {
        return null;
    }

    const minor = Number(wholeDigits) * 100 + Number(fraction.padEnd(2, '0'));

    if (minor > MAXIMUM_AMOUNT_MINOR) {
        return null;
    }

    return { minor, decimal: minorToDecimal(minor) };
}

/** 1250050 becomes «12500.50»: the form the server accepts. */
export function minorToDecimal(minor: number): string {
    const whole = Math.floor(minor / 100);
    const cents = minor % 100;

    return `${whole}.${String(cents).padStart(2, '0')}`;
}

/** Groups digits in threes with a no-break space: 12500000 becomes «12 500 000». */
function groupThousands(whole: number): string {
    return String(whole).replace(/\B(?=(\d{3})+(?!\d))/g, NO_BREAK_SPACE);
}

/**
 * A price for reading: whole units with thousands grouped, and the cêntimos
 * only when there are some. «12 500», «12 500,50». No currency.
 */
export function formatMinor(minor: number): string {
    const whole = Math.floor(minor / 100);
    const cents = minor % 100;
    const grouped = groupThousands(whole);

    return cents === 0
        ? grouped
        : `${grouped},${String(cents).padStart(2, '0')}`;
}

/**
 * A price in whole units, rounded to the nearest: how the public pages write
 * a monthly equivalent («≈ 11 667 Kz por mês»), since the server rounds there.
 */
export function formatMinorWhole(minor: number): string {
    return groupThousands(Math.round(minor / 100));
}

/** A price as it is put back into a text field to be edited: «12500», «12500,50». */
export function minorToInputText(minor: number): string {
    const whole = Math.floor(minor / 100);
    const cents = minor % 100;

    return cents === 0
        ? String(whole)
        : `${whole},${String(cents).padStart(2, '0')}`;
}

/** A price with its currency: «12 500 Kz». `currency` is already the label. */
export function formatPrice(minor: number, currency: string): string {
    return `${formatMinor(minor)}${NO_BREAK_SPACE}${currency}`;
}

/** The saving as the pages write it: «−7%». */
export function formatSaving(percentage: number): string {
    return `${MINUS_SIGN}${percentage}%`;
}

export interface PriceChipInput {
    intervalLabel: string;
    amountMinor: number;
    months: number;
    /** The currency as written next to a figure: «Kz». */
    currency: string;
    /** The active monthly price of the same package, or null. */
    monthlyAmountMinor: number | null;
}

/**
 * The text of a price chip in the staff list: «Mensal 12 500 Kz», or
 * «Trimestral 35 000 Kz · −7%» when the period saves against monthly.
 */
export function priceChipLabel(input: PriceChipInput): string {
    const price = `${input.intervalLabel} ${formatPrice(input.amountMinor, input.currency)}`;
    const saving = savingPercentage(
        input.amountMinor,
        input.months,
        input.monthlyAmountMinor,
    );

    return saving === null ? price : `${price} · ${formatSaving(saving)}`;
}

/**
 * «Nome do Pacote» becomes «nome-do-pacote»: accents stripped, lower-cased,
 * everything that is not a letter or digit collapsed into single hyphens, no
 * hyphen at either end, and cut to the 48 characters the server allows.
 */
export function slugFromName(name: string): string {
    return name
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 48)
        .replace(/-+$/g, '');
}

/**
 * Splits pasted text into feature lines: one per line, bullets and numbering
 * removed, blanks dropped.
 */
export function splitPastedFeatures(text: string): string[] {
    return text
        .split(/\r\n|\r|\n/)
        .map((line) =>
            line.replace(/^\s*(?:[-*•–—●]|\d{1,2}[.)])\s+/, '').trim(),
        )
        .filter((line) => line !== '');
}

export interface PeriodOption {
    /** The stored interval: «monthly», «quarterly»… */
    value: string;
    label: string;
    months: number;
    /** The best saving any package offers for this period, in whole percent. */
    saving: number | null;
}

export interface PricedPeriod {
    interval: string;
    interval_label: string;
    months: number;
    saving_percentage: number | null;
}

/**
 * The payment periods that exist across the packages on offer, shortest first,
 * each with the best saving any package gives for it. A period nobody sells
 * is not listed.
 */
export function periodOptions(rows: PricedPeriod[]): PeriodOption[] {
    const byInterval = new Map<string, PeriodOption>();

    for (const row of rows) {
        const known = byInterval.get(row.interval);

        if (known === undefined) {
            byInterval.set(row.interval, {
                value: row.interval,
                label: row.interval_label,
                months: row.months,
                saving: row.saving_percentage,
            });
        } else if (
            row.saving_percentage !== null &&
            (known.saving === null || row.saving_percentage > known.saving)
        ) {
            known.saving = row.saving_percentage;
        }
    }

    return [...byInterval.values()].sort((a, b) => a.months - b.months);
}

/**
 * The period a visitor starts on: the one they already pay for, if it is
 * offered; otherwise monthly; otherwise the first on offer. Null when nothing
 * is offered at all.
 */
export function initialPeriod(
    options: PeriodOption[],
    preferred: string | null,
): string | null {
    for (const wanted of [preferred, 'monthly']) {
        if (wanted !== null && options.some((o) => o.value === wanted)) {
            return wanted;
        }
    }

    return options[0]?.value ?? null;
}
