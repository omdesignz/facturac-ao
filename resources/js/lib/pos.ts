// Node's test runner needs the extension and the type checker does not allow
// it; the bundler resolves either. The rounding rule must stay in one place.
// @ts-expect-error TS5097: the extension is only needed outside the bundler.
import { roundFiscalLineAmount } from './fiscal-rounding.ts';

/**
 * The till's arithmetic. Pure, integer and bigint only: a float anywhere in a
 * price would let the total on screen differ from the one the server issues,
 * and the server refuses a sale whose total changed (`expected_total_minor`).
 *
 * The rules mirror `App\Fiscal\Calculation\FiscalCalculator` for a
 * Factura/Recibo, exactly as the invoice editor mirrors them:
 *  - line net   = price × quantity × (1 − discount), rounded down;
 *  - line tax   = net × rate, rounded UP;
 *  - line gross = net + tax; document totals are sums of the lines.
 * `tests/pos-cart.test.mjs` runs every cart in `tests/Fixtures/pos-cart-cases.json`
 * (the file the server's own test checks) through these functions.
 */

/** The document type whose rounding the till follows. */
const DOCUMENT_TYPE = 'FR';

const NBSP = ' ';
const MINUS = '−';

/** What the server accepts as a document total (`expected_total_minor`). */
export const MAX_TOTAL_MINOR = 999_999_999_999_999;

/**
 * The most change a sale may give back (100 000,00), the same ceiling the
 * server applies. Nobody hands over that much more than they owe; a barcode
 * read into the amount field does.
 */
export const MAX_CHANGE_MINOR = 10_000_000;

/** What the cart needs to know about one line to price it. */
export interface PricedLine {
    /** Unit price before tax, in minor units. */
    unitPriceMinor: number;
    /** Decimal string, up to four decimals. */
    quantity: string;
    /** Percentage as a decimal string, 0 to 100. */
    discountPercentage: string;
    /** IVA rate as a decimal string; «0» for exempt. */
    taxPercentage: string;
}

export interface LineAmounts {
    baseMinor: number;
    settlementMinor: number;
    netMinor: number;
    taxMinor: number;
    grossMinor: number;
}

export interface TaxRateTotal {
    /** Canonical rate: «14», «7.5», «0». */
    percentage: string;
    baseMinor: number;
    taxMinor: number;
}

export interface CartTotals {
    netMinor: number;
    taxMinor: number;
    grossMinor: number;
    settlementMinor: number;
    taxByRate: TaxRateTotal[];
}

/* ---------------------------------------------------------------- numbers */

/**
 * A non-negative decimal string as a scaled integer, or null when it is not
 * one or has more decimals than the scale allows. Trailing zeros beyond the
 * scale ("14.000") are harmless and ignored.
 */
function parseScaled(value: string, scale: number): bigint | null {
    const match = /^(0|[1-9]\d*)(?:\.(\d+))?$/.exec(value.trim());

    if (match === null) {
        return null;
    }

    const fraction = (match[2] ?? '').replace(/0+$/, '');

    if (fraction.length > scale) {
        return null;
    }

    return (
        BigInt(match[1] ?? '0') * 10n ** BigInt(scale) +
        BigInt(fraction.padEnd(scale, '0') || '0')
    );
}

function ceilDivide(numerator: bigint, denominator: bigint): bigint {
    return numerator === 0n ? 0n : (numerator + denominator - 1n) / denominator;
}

function amountsOf(line: PricedLine): {
    base: bigint;
    settlement: bigint;
    net: bigint;
    tax: bigint;
    gross: bigint;
    rate: bigint;
} {
    const quantity = parseScaled(line.quantity, 4) ?? 0n;
    const price = BigInt(Math.max(0, Math.trunc(line.unitPriceMinor)));
    const discount = parseScaled(line.discountPercentage, 2) ?? 0n;
    const rate = parseScaled(line.taxPercentage, 2) ?? 0n;
    const boundedDiscount = discount > 10_000n ? 10_000n : discount;

    const base = roundFiscalLineAmount(
        price * quantity,
        10_000n,
        DOCUMENT_TYPE,
    );
    const net = roundFiscalLineAmount(
        price * (10_000n - boundedDiscount) * quantity,
        100_000_000n,
        DOCUMENT_TYPE,
    );
    const tax = ceilDivide(net * rate, 10_000n);

    return {
        base,
        settlement: base - net,
        net,
        tax,
        gross: net + tax,
        rate,
    };
}

export function cartLineAmounts(line: PricedLine): LineAmounts {
    const amounts = amountsOf(line);

    return {
        baseMinor: Number(amounts.base),
        settlementMinor: Number(amounts.settlement),
        netMinor: Number(amounts.net),
        taxMinor: Number(amounts.tax),
        grossMinor: Number(amounts.gross),
    };
}

/** 1400 basis points → «14»; 750 → «7.5». */
function rateLabel(basisPoints: bigint): string {
    const whole = basisPoints / 100n;
    const fraction = (basisPoints % 100n)
        .toString()
        .padStart(2, '0')
        .replace(/0+$/, '');

    return fraction === '' ? whole.toString() : `${whole}.${fraction}`;
}

export function cartTotals(lines: PricedLine[]): CartTotals {
    let net = 0n;
    let tax = 0n;
    let gross = 0n;
    let settlement = 0n;
    const byRate = new Map<bigint, { base: bigint; tax: bigint }>();

    for (const line of lines) {
        const amounts = amountsOf(line);

        net += amounts.net;
        tax += amounts.tax;
        gross += amounts.gross;
        settlement += amounts.settlement;

        const row = byRate.get(amounts.rate) ?? { base: 0n, tax: 0n };
        row.base += amounts.net;
        row.tax += amounts.tax;
        byRate.set(amounts.rate, row);
    }

    const taxByRate = [...byRate.entries()]
        .sort(([left], [right]) => (left < right ? 1 : left > right ? -1 : 0))
        .map(([rate, row]) => ({
            percentage: rateLabel(rate),
            baseMinor: Number(row.base),
            taxMinor: Number(row.tax),
        }));

    return {
        netMinor: Number(net),
        taxMinor: Number(tax),
        grossMinor: Number(gross),
        settlementMinor: Number(settlement),
        taxByRate,
    };
}

/** The price a customer sees on a tile: one unit, tax included. */
export function grossUnitPriceMinor(item: {
    unitPriceMinor: number;
    taxPercentage: string;
}): number {
    return cartLineAmounts({
        unitPriceMinor: item.unitPriceMinor,
        quantity: '1',
        discountPercentage: '0',
        taxPercentage: item.taxPercentage,
    }).grossMinor;
}

/* ---------------------------------------------------------------- display */

function groupThousands(digits: string): string {
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
}

/**
 * «1 500,50»: groups of three joined by a non-breaking space, a decimal comma,
 * always two decimals so a column of totals lines up. A negative amount takes a
 * true minus sign.
 */
export function formatMinor(minor: number): string {
    const value = BigInt(Math.trunc(minor));
    const absolute = value < 0n ? -value : value;
    const whole = (absolute / 100n).toString();
    const fraction = (absolute % 100n).toString().padStart(2, '0');

    return `${value < 0n ? MINUS : ''}${groupThousands(whole)},${fraction}`;
}

/** Whole kwanzas, cêntimos rounded half up: «3 500». For round-figure labels. */
export function formatWholeMinor(minor: number): string {
    const value = BigInt(Math.trunc(minor));
    const absolute = value < 0n ? -value : value;
    const whole = ((absolute + 50n) / 100n).toString();

    return `${value < 0n ? MINUS : ''}${groupThousands(whole)}`;
}

/** As `formatMinor`, with an explicit plus for a positive figure. */
export function formatSignedMinor(minor: number): string {
    return `${minor > 0 ? '+' : ''}${formatMinor(minor)}`;
}

/** A stock or sale quantity for people: «12», «1,5». */
export function formatQuantity(quantity: string): string {
    const [whole = '0', fraction] = quantity.split('.');
    const grouped = groupThousands(whole.replace('-', ''));
    const sign = whole.startsWith('-') ? MINUS : '';

    return fraction === undefined || /^0*$/.test(fraction)
        ? `${sign}${grouped}`
        : `${sign}${grouped},${fraction.replace(/0+$/, '')}`;
}

/* ---------------------------------------------------------------- parsing */

const WHITESPACE = /[\s   ]/g;

/**
 * What a cashier typed as an amount, in minor units, or null.
 *
 * Accepts «1500», «1 500», «1.500,50», «1500.50» and «1500,5». A comma is the
 * decimal mark; when there is none, a dot followed by one or two digits is the
 * decimal mark and a dot followed by exactly three is a thousands separator.
 * Anything else, any sign and more than two decimals are refused.
 */
export function parseMoneyToMinor(text: string): number | null {
    const compact = text.replace(WHITESPACE, '');

    if (compact === '') {
        return null;
    }

    let whole: string;
    let fraction = '';

    if (compact.includes(',')) {
        const parts = compact.split(',');

        if (parts.length !== 2) {
            return null;
        }

        const integer = parts[0] ?? '';
        const decimals = parts[1] ?? '';

        if (!/^(?:\d+|[1-9]\d{0,2}(?:\.\d{3})+)$/.test(integer)) {
            return null;
        }

        whole = integer.replace(/\./g, '');
        fraction = decimals;
    } else if (/^[1-9]\d{0,2}(?:\.\d{3})+$/.test(compact)) {
        whole = compact.replace(/\./g, '');
    } else {
        const parts = compact.split('.');

        if (parts.length > 2) {
            return null;
        }

        whole = parts[0] ?? '';
        fraction = parts[1] ?? '';

        if (parts.length === 2 && fraction === '') {
            return null;
        }
    }

    if (
        !/^\d{1,13}$/.test(whole) ||
        !/^\d{0,2}$/.test(fraction) ||
        (compact.includes(',') && fraction === '')
    ) {
        return null;
    }

    return Number(
        BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0') || '0'),
    );
}

/** What the API takes for money: «1500.50». */
export function minorToDecimalString(minor: number): string {
    const value = BigInt(Math.trunc(minor));
    const absolute = value < 0n ? -value : value;
    const fraction = (absolute % 100n).toString().padStart(2, '0');

    return `${value < 0n ? '-' : ''}${absolute / 100n}.${fraction}`;
}

/** An amount as it is typed into a field: «3500» or «3250,5». No grouping. */
export function minorToInputString(minor: number): string {
    const value = BigInt(Math.trunc(minor));
    const absolute = value < 0n ? -value : value;
    const fraction = (absolute % 100n).toString().padStart(2, '0');
    const sign = value < 0n ? '-' : '';

    return fraction === '00'
        ? `${sign}${absolute / 100n}`
        : `${sign}${absolute / 100n},${fraction.replace(/0$/, '')}`;
}

function canonicalDecimal(whole: bigint, fraction: string): string {
    const trimmed = fraction.replace(/0+$/, '');

    return trimmed === '' ? whole.toString() : `${whole}.${trimmed}`;
}

/**
 * A quantity typed by the cashier as the canonical string the API takes
 * («1.5», «12»), or null when it is not a number above zero with at most four
 * decimals. «1,5» is read as 1.5.
 */
export function parseQuantity(text: string): string | null {
    const compact = text.replace(WHITESPACE, '').replace(',', '.');
    const match = /^(\d{1,9})(?:\.(\d{1,4}))?$/.exec(compact);

    if (match === null) {
        return null;
    }

    const whole = BigInt(match[1] ?? '0');
    const fraction = match[2] ?? '';

    if (whole === 0n && /^0*$/.test(fraction)) {
        return null;
    }

    return canonicalDecimal(whole, fraction);
}

/**
 * A quantity moved by a whole number of units, or null when nothing is left
 * (the line should go).
 */
export function addToQuantity(quantity: string, delta: number): string | null {
    const scaled = parseScaled(quantity, 4);

    if (scaled === null) {
        return null;
    }

    const next = scaled + BigInt(delta) * 10_000n;

    if (next <= 0n) {
        return null;
    }

    const whole = next / 10_000n;
    const fraction = (next % 10_000n).toString().padStart(4, '0');

    return canonicalDecimal(whole, fraction);
}

/**
 * A discount typed as a percentage («10», «12,5», «10%») as the canonical
 * string, or null when it is outside 0–100 or has more than two decimals.
 * An empty field is no discount.
 */
export function parseDiscountPercentage(text: string): string | null {
    const compact = text
        .replace(WHITESPACE, '')
        .replace('%', '')
        .replace(',', '.');

    if (compact === '') {
        return '0';
    }

    const scaled = parseScaled(compact, 2);

    if (scaled === null || scaled > 10_000n) {
        return null;
    }

    return rateLabel(scaled);
}

/* ---------------------------------------------------------------- tendering */

/** Notes in circulation, in kwanzas. */
const NOTES = [200, 500, 1_000, 2_000, 5_000, 10_000];

const MAX_QUICK_TENDERS = 5;

/**
 * The amounts a customer is likely to hand over: the exact total, then the
 * next round figures made of kwanza notes. At most five, ascending, no
 * duplicates.
 *
 * 3 250 → 3 250, 3 500, 4 000, 5 000, 10 000.
 *
 * When more figures qualify than fit, those that only the 200 note makes
 * (3 400) go first, since they are the ones nobody has in a wallet ready; then
 * the largest, because a 10 000 offered for a 200 is the least useful figure.
 */
export function quickTenders(totalMinor: number): number[] {
    if (!Number.isFinite(totalMinor) || totalMinor <= 0) {
        return [];
    }

    const roundUps = new Set<number>();
    const grain = (NOTES[0] ?? 200) * 100;

    for (const note of NOTES) {
        const unit = note * 100;
        const roundedUp = Math.ceil(totalMinor / unit) * unit;

        if (roundedUp > totalMinor) {
            roundUps.add(roundedUp);
        }
    }

    let figures = [...roundUps].sort((left, right) => left - right);
    const room = MAX_QUICK_TENDERS - 1;

    if (figures.length > room) {
        // Figures only the smallest note makes: a multiple of 200 that is
        // not also a multiple of the next note.
        const next = (NOTES[1] ?? 500) * 100;
        const kept = figures.filter(
            (amount) => amount % grain !== 0 || amount % next === 0,
        );

        figures = kept.length >= room ? kept : figures;
    }

    return [totalMinor, ...figures.slice(0, room)];
}

/* ---------------------------------------------------------------- sale keys */

/**
 * A key for one sale. The server turns a request it has already seen under the
 * same key into the same sale, so a retry can never charge twice.
 */
export function newClientKey(): string {
    const cryptoApi = globalThis.crypto;

    if (typeof cryptoApi?.randomUUID === 'function') {
        return cryptoApi.randomUUID();
    }

    const bytes = new Uint8Array(16);

    if (typeof cryptoApi?.getRandomValues === 'function') {
        cryptoApi.getRandomValues(bytes);
    } else {
        for (let index = 0; index < bytes.length; index++) {
            bytes[index] = Math.floor(Math.random() * 256);
        }
    }

    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0'));

    return `${Date.now().toString(36)}-${hex.join('')}`;
}

/** What the server will be sent for one line. Nothing else is accepted. */
export interface SaleLinePayload {
    catalogue_item_public_id: string;
    quantity: string;
    discount_percentage: string;
}

/** One attempt to charge a given cart, kept so a retry reuses its key. */
export interface SaleAttempt {
    key: string;
    /** What was being charged; a different cart is a different sale. */
    signature: string;
    /** The last try got no answer, so the sale may or may not exist. */
    unanswered: boolean;
}

/** The key to send for this cart: the old one while the cart is unchanged. */
export function attemptFor(
    previous: SaleAttempt | null,
    signature: string,
): { attempt: SaleAttempt; priorUnanswered: boolean } {
    if (previous !== null && previous.signature === signature) {
        return { attempt: previous, priorUnanswered: false };
    }

    return {
        attempt: { key: newClientKey(), signature, unanswered: false },
        // A different cart after an unanswered try could double-charge.
        priorUnanswered: previous?.unanswered === true,
    };
}

export function saleSignature(
    lines: SaleLinePayload[],
    customer: { kind: string; id?: string; name?: string; nif?: string },
    expectedTotalMinor: number,
): string {
    return JSON.stringify([
        lines.map((line) => [
            line.catalogue_item_public_id,
            line.quantity,
            line.discount_percentage,
        ]),
        [
            customer.kind,
            customer.id ?? '',
            customer.name ?? '',
            customer.nif ?? '',
        ],
        expectedTotalMinor,
    ]);
}

/* ---------------------------------------------------------------- failures */

export type SaleFailureKind =
    /** The server looked at the sale and said no; nothing was issued. */
    | 'rejected'
    /** The shift or the session is gone; nothing was issued. */
    | 'expired'
    /** No usable answer: the sale may exist. Retry with the same key. */
    | 'unanswered';

export interface SaleFailure {
    kind: SaleFailureKind;
    message: string;
}

export const SALE_EXPIRED_MESSAGE =
    'A sessão expirou. Recarregue a página e volte a entrar; a venda não foi emitida.';

export const SALE_UNANSWERED_MESSAGE =
    'Sem resposta do servidor. Não sabemos se a venda foi emitida — carregue em «Tentar outra vez»: a mesma venda nunca é emitida duas vezes.';

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

/**
 * What a failed request means for the cashier. Mirrors the cases the server
 * answers: 409/403 carry a message written to be read; a 419 or 401 means the
 * session is gone; anything else, including a timeout, a dropped connection or
 * a body that is not JSON, is an unanswered request.
 */
export function describeSaleFailure(error: unknown): SaleFailure {
    const response = isRecord(error) ? error.response : null;

    if (isRecord(response) && typeof response.status === 'number') {
        const status = response.status;

        if (status === 419 || status === 401) {
            return { kind: 'expired', message: SALE_EXPIRED_MESSAGE };
        }

        if (status === 409 || status === 403) {
            let body: unknown = response.data;

            if (typeof body === 'string') {
                try {
                    body = JSON.parse(body);
                } catch {
                    body = null;
                }
            }

            const message =
                isRecord(body) && typeof body.message === 'string'
                    ? body.message
                    : 'A venda não foi emitida.';

            return { kind: 'rejected', message };
        }
    }

    return { kind: 'unanswered', message: SALE_UNANSWERED_MESSAGE };
}

/** The server's first validation message for a refused sale (HTTP 422). */
export function firstValidationMessage(
    errors: Record<string, unknown>,
): { field: string; message: string } | null {
    for (const [field, value] of Object.entries(errors)) {
        const message = Array.isArray(value) ? value[0] : value;

        if (typeof message === 'string' && message !== '') {
            return { field, message };
        }
    }

    return null;
}

/* ---------------------------------------------------------------- NIF */

/** The rule `StorePosSaleRequest` applies to a typed customer's NIF. */
export function normaliseNif(text: string): string {
    return text.replace(WHITESPACE, '').toUpperCase();
}

export function isValidNif(text: string): boolean {
    return /^[A-Z0-9]{9,32}$/.test(normaliseNif(text));
}

/* ---------------------------------------------------------------- search */

/** Accent- and case-blind, so «cafe» finds «Café». */
export function foldSearch(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}
