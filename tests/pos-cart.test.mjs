import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    addToQuantity,
    attemptFor,
    cartLineAmounts,
    cartTotals,
    describeSaleFailure,
    firstValidationMessage,
    formatMinor,
    formatQuantity,
    formatSignedMinor,
    formatWholeMinor,
    grossUnitPriceMinor,
    isValidNif,
    minorToDecimalString,
    minorToInputString,
    newClientKey,
    normaliseNif,
    parseDiscountPercentage,
    parseMoneyToMinor,
    parseQuantity,
    quickTenders,
    saleSignature,
    SALE_EXPIRED_MESSAGE,
    SALE_UNANSWERED_MESSAGE,
} from '../resources/js/lib/pos.ts';

const fixture = JSON.parse(
    readFileSync(
        new URL('./Fixtures/pos-cart-cases.json', import.meta.url),
        'utf8',
    ),
);

const NBSP = ' ';

function pricedLines(testCase) {
    return testCase.lines.map((line) => ({
        unitPriceMinor: line.unit_price_minor,
        quantity: line.quantity,
        discountPercentage: line.discount_percentage,
        taxPercentage: line.tax.percentage,
    }));
}

/* ------------------------------------------------- parity with the server */

test('the fixture holds the carts the server test reads', () => {
    assert.equal(fixture.cases.length, 14);
});

for (const testCase of fixture.cases) {
    test(`cart parity: ${testCase.name}`, () => {
        const lines = pricedLines(testCase);
        const totals = cartTotals(lines);
        const expected = testCase.expected;

        assert.equal(totals.netMinor, expected.net_total_minor, 'net');
        assert.equal(totals.taxMinor, expected.tax_payable_minor, 'tax');
        assert.equal(totals.grossMinor, expected.gross_total_minor, 'gross');
        assert.equal(
            totals.settlementMinor,
            expected.settlement_total_minor,
            'settlement',
        );

        assert.deepEqual(
            lines.map((line) => {
                const amounts = cartLineAmounts(line);

                return {
                    net_minor: amounts.netMinor,
                    tax_minor: amounts.taxMinor,
                    gross_minor: amounts.grossMinor,
                };
            }),
            expected.lines,
        );

        // The rows by rate account for the whole of the tax and the net.
        assert.equal(
            totals.taxByRate.reduce((sum, row) => sum + row.taxMinor, 0),
            totals.taxMinor,
        );
        assert.equal(
            totals.taxByRate.reduce((sum, row) => sum + row.baseMinor, 0),
            totals.netMinor,
        );
    });
}

test('tax is grouped by rate, highest first, exempt last', () => {
    const mixed = fixture.cases.find((entry) =>
        entry.name.startsWith('mixed rates'),
    );
    const { taxByRate } = cartTotals(pricedLines(mixed));

    assert.deepEqual(
        taxByRate.map((row) => row.percentage),
        ['14', '0'],
    );
    assert.equal(taxByRate[0].taxMinor, 2800);
    assert.equal(taxByRate[1].taxMinor, 0);
});

test('a rate with more zeros than the till needs prices the same', () => {
    const base = {
        unitPriceMinor: 10000,
        quantity: '1',
        discountPercentage: '0',
    };

    assert.deepEqual(
        cartLineAmounts({ ...base, taxPercentage: '14.0000' }),
        cartLineAmounts({ ...base, taxPercentage: '14.00' }),
    );
    assert.equal(cartLineAmounts({ ...base, taxPercentage: '14' }).taxMinor, 1400);
});

test('an empty cart totals nothing', () => {
    assert.deepEqual(cartTotals([]), {
        netMinor: 0,
        taxMinor: 0,
        grossMinor: 0,
        settlementMinor: 0,
        taxByRate: [],
    });
});

test('the tile price is one unit with its tax', () => {
    assert.equal(
        grossUnitPriceMinor({ unitPriceMinor: 10000, taxPercentage: '14.00' }),
        11400,
    );
    assert.equal(
        grossUnitPriceMinor({ unitPriceMinor: 99, taxPercentage: '14.00' }),
        113,
    );
    assert.equal(
        grossUnitPriceMinor({ unitPriceMinor: 5000, taxPercentage: '0.00' }),
        5000,
    );
});

/* ---------------------------------------------------------------- display */

test('formats money with grouped thousands and a decimal comma', () => {
    assert.equal(formatMinor(0), '0,00');
    assert.equal(formatMinor(5), '0,05');
    assert.equal(formatMinor(150050), `1${NBSP}500,50`);
    assert.equal(formatMinor(100000000), `1${NBSP}000${NBSP}000,00`);
    assert.equal(formatMinor(-450000), `\u22124${NBSP}500,00`);
    assert.equal(formatMinor(-500), '−5,00');
    assert.equal(formatSignedMinor(500), '+5,00');
    assert.equal(formatSignedMinor(-500), '−5,00');
    assert.equal(formatSignedMinor(0), '0,00');
});

test('formats whole kwanzas for round figures', () => {
    assert.equal(formatWholeMinor(350000), `3${NBSP}500`);
    assert.equal(formatWholeMinor(150), '2');
    assert.equal(formatWholeMinor(149), '1');
    assert.equal(formatWholeMinor(1000000000), `10${NBSP}000${NBSP}000`);
});

test('formats quantities for people', () => {
    assert.equal(formatQuantity('12'), '12');
    assert.equal(formatQuantity('1.5'), '1,5');
    assert.equal(formatQuantity('2.0000'), '2');
    assert.equal(formatQuantity('-3'), '−3');
    assert.equal(formatQuantity('1250.25'), `1${NBSP}250,25`);
});

test('writes money for the API and for a field', () => {
    assert.equal(minorToDecimalString(150050), '1500.50');
    assert.equal(minorToDecimalString(5), '0.05');
    assert.equal(minorToDecimalString(0), '0.00');
    assert.equal(minorToInputString(350000), '3500');
    assert.equal(minorToInputString(325050), '3250,5');
    assert.equal(minorToInputString(325005), '3250,05');
});

/* ---------------------------------------------------------------- parsing */

test('reads the amounts a cashier types', () => {
    const cases = [
        ['1500', 150000],
        ['1 500', 150000],
        [`1${NBSP}500`, 150000],
        ['1.500,50', 150050],
        ['1500.50', 150050],
        ['1500,5', 150050],
        ['1500,05', 150005],
        ['1.500', 150000],
        ['12.500', 1250000],
        ['0', 0],
        ['0,5', 50],
        ['0.50', 50],
        ['  25  ', 2500],
        ['007', 700],
        ['1.234.567,89', 123456789],
    ];

    for (const [text, minor] of cases) {
        assert.equal(parseMoneyToMinor(text), minor, JSON.stringify(text));
    }
});

test('refuses what is not an amount', () => {
    for (const text of [
        '',
        '   ',
        'abc',
        '-5',
        '-0,50',
        '+5',
        '1,234,5',
        '1.5.0',
        '1500.',
        '1500,',
        ',5',
        '.5',
        '1,505',
        '1500.505',
        '0.500',
        '1e3',
        '12 kz',
        '99999999999999',
    ]) {
        assert.equal(parseMoneyToMinor(text), null, JSON.stringify(text));
    }
});

test('reads quantities of up to four decimals above zero', () => {
    assert.equal(parseQuantity('1'), '1');
    assert.equal(parseQuantity('01'), '1');
    assert.equal(parseQuantity('1,5'), '1.5');
    assert.equal(parseQuantity('1.50'), '1.5');
    assert.equal(parseQuantity('2.0'), '2');
    assert.equal(parseQuantity('0,3333'), '0.3333');
    assert.equal(parseQuantity(' 12 '), '12');
    assert.equal(parseQuantity('1 000'), '1000');

    for (const text of ['', '0', '0,0000', '-1', '1,23456', 'x', '1.', '1,5,5']) {
        assert.equal(parseQuantity(text), null, JSON.stringify(text));
    }
});

test('moves a quantity by whole units and drops the line at zero', () => {
    assert.equal(addToQuantity('1', 1), '2');
    assert.equal(addToQuantity('2', -1), '1');
    assert.equal(addToQuantity('1.5', 1), '2.5');
    assert.equal(addToQuantity('1.5', -1), '0.5');
    assert.equal(addToQuantity('1', -1), null);
    assert.equal(addToQuantity('0.5', -1), null);
});

test('reads a discount as a percentage', () => {
    assert.equal(parseDiscountPercentage(''), '0');
    assert.equal(parseDiscountPercentage('10'), '10');
    assert.equal(parseDiscountPercentage('10%'), '10');
    assert.equal(parseDiscountPercentage('12,5'), '12.5');
    assert.equal(parseDiscountPercentage('33.33'), '33.33');
    assert.equal(parseDiscountPercentage('100'), '100');
    assert.equal(parseDiscountPercentage('007'), null);

    for (const text of ['101', '100,01', '-1', '1,234', 'x']) {
        assert.equal(parseDiscountPercentage(text), null, text);
    }
});

test('validates a typed NIF the way the server does', () => {
    assert.equal(normaliseNif(' 5417 000 123 '), '5417000123');
    assert.equal(normaliseNif('ab123456c'), 'AB123456C');
    assert.equal(isValidNif('5417000123'), true);
    assert.equal(isValidNif('ab123456c'), true);
    assert.equal(isValidNif('12345678'), false);
    assert.equal(isValidNif('123456789-0'), false);
    assert.equal(isValidNif('1'.repeat(33)), false);
    assert.equal(isValidNif('1'.repeat(32)), true);
});

/* ---------------------------------------------------------------- tendering */

test('offers the exact amount and the next round figures', () => {
    assert.deepEqual(quickTenders(325000), [
        325000, 350000, 400000, 500000, 1000000,
    ]);
});

test('quick amounts are unique, ascending and at most five', () => {
    for (const total of [1, 99, 100, 20000, 65000, 325050, 999900, 1000000, 5000000, 123456789]) {
        const tenders = quickTenders(total);

        assert.equal(tenders[0], total, `${total} exact first`);
        assert.ok(tenders.length <= 5, `${total} at most five`);
        assert.deepEqual(
            tenders,
            [...new Set(tenders)].sort((left, right) => left - right),
            `${total} ascending and unique`,
        );
        assert.ok(
            tenders.every((amount) => amount >= total),
            `${total} nothing short`,
        );
    }
});

test('an exact multiple of a note is not offered twice', () => {
    // 5 000 Kz is already a note; the next figures are three 2 000s and a 10 000.
    assert.deepEqual(quickTenders(500000), [500000, 600000, 1000000]);
    assert.deepEqual(quickTenders(1000000), [1000000]);
    assert.deepEqual(quickTenders(20000), [20000, 50000, 100000, 200000, 500000]);
});

test('no quick amounts for nothing to pay', () => {
    assert.deepEqual(quickTenders(0), []);
    assert.deepEqual(quickTenders(-5), []);
    assert.deepEqual(quickTenders(Number.NaN), []);
});

/* ---------------------------------------------------------------- sale keys */

test('sale keys are long enough, safe and unique', () => {
    const keys = new Set(Array.from({ length: 200 }, () => newClientKey()));

    assert.equal(keys.size, 200);

    for (const key of keys) {
        assert.match(key, /^[A-Za-z0-9_-]{16,64}$/);
    }
});

test('a sale key survives a retry of the same cart and not a different one', () => {
    const lines = [
        {
            catalogue_item_public_id: 'ITEM1',
            quantity: '2',
            discount_percentage: '0',
        },
    ];
    const customer = { kind: 'final' };
    const same = saleSignature(lines, customer, 22800);

    const first = attemptFor(null, same);
    assert.equal(first.priorUnanswered, false);

    // The server did not answer: the same cart must reuse the same key.
    const retry = attemptFor({ ...first.attempt, unanswered: true }, same);
    assert.equal(retry.attempt.key, first.attempt.key);
    assert.equal(retry.priorUnanswered, false);

    // A changed cart gets a fresh key, and the cashier is told about the doubt.
    const changed = attemptFor(
        { ...first.attempt, unanswered: true },
        saleSignature(lines, customer, 34200),
    );
    assert.notEqual(changed.attempt.key, first.attempt.key);
    assert.equal(changed.priorUnanswered, true);

    // A clean rejection followed by a different cart is not a doubt.
    const afterRejection = attemptFor(
        first.attempt,
        saleSignature(lines, { kind: 'typed', name: 'A', nif: '123456789' }, 22800),
    );
    assert.notEqual(afterRejection.attempt.key, first.attempt.key);
    assert.equal(afterRejection.priorUnanswered, false);

    // After a success the caller forgets the attempt: the same cart sells again.
    const next = attemptFor(null, same);
    assert.notEqual(next.attempt.key, first.attempt.key);
});

test('the signature tells carts apart', () => {
    const line = (quantity) => ({
        catalogue_item_public_id: 'ITEM1',
        quantity,
        discount_percentage: '0',
    });
    const customer = { kind: 'final' };

    assert.equal(
        saleSignature([line('1')], customer, 100),
        saleSignature([line('1')], customer, 100),
    );
    assert.notEqual(
        saleSignature([line('1')], customer, 100),
        saleSignature([line('2')], customer, 100),
    );
    assert.notEqual(
        saleSignature([line('1')], customer, 100),
        saleSignature([line('1')], { kind: 'saved', id: 'C1' }, 100),
    );
});

/* ---------------------------------------------------------------- failures */

function failure(status, body) {
    return {
        name: 'HttpResponseError',
        response: {
            status,
            data: typeof body === 'string' ? body : JSON.stringify(body),
        },
    };
}

test('a 409 or 403 carries the server message and nothing was issued', () => {
    assert.deepEqual(describeSaleFailure(failure(409, { message: 'Este turno já foi fechado.' })), {
        kind: 'rejected',
        message: 'Este turno já foi fechado.',
    });
    assert.equal(
        describeSaleFailure(failure(403, { message: 'Não tem permissão para vender.' })).message,
        'Não tem permissão para vender.',
    );
    assert.equal(describeSaleFailure(failure(409, 'not json')).kind, 'rejected');
});

test('a lost session says so', () => {
    for (const status of [419, 401]) {
        assert.deepEqual(describeSaleFailure(failure(status, {})), {
            kind: 'expired',
            message: SALE_EXPIRED_MESSAGE,
        });
    }
});

test('anything else is an unanswered request that must be retried with the same key', () => {
    for (const error of [
        failure(500, { message: 'Server Error' }),
        failure(502, '<html>Bad gateway</html>'),
        failure(503, {}),
        { name: 'HttpNetworkError', code: 'ERR_NETWORK' },
        { name: 'HttpCancelledError', code: 'ERR_CANCELLED' },
        new SyntaxError('Unexpected token <'),
        null,
        undefined,
        'boom',
    ]) {
        assert.deepEqual(describeSaleFailure(error), {
            kind: 'unanswered',
            message: SALE_UNANSWERED_MESSAGE,
        });
    }
});

test('picks the first validation message of a refused sale', () => {
    assert.deepEqual(
        firstValidationMessage({
            expected_total_minor: 'O total mudou desde que a venda foi apresentada. Reveja o carrinho.',
        }),
        {
            field: 'expected_total_minor',
            message: 'O total mudou desde que a venda foi apresentada. Reveja o carrinho.',
        },
    );
    assert.deepEqual(firstValidationMessage({ a: ['Primeira', 'Segunda'] }), {
        field: 'a',
        message: 'Primeira',
    });
    assert.equal(firstValidationMessage({}), null);
    assert.equal(firstValidationMessage({ a: '' }), null);
});
