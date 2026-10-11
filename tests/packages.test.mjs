import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    costsMoreThanMonthly,
    formatMinor,
    formatMinorWhole,
    formatPrice,
    formatSaving,
    initialPeriod,
    minorToDecimal,
    minorToInputText,
    monthlyEquivalentMinor,
    parseAmount,
    periodOptions,
    priceChipLabel,
    savingPercentage,
    slugFromName,
    splitPastedFeatures,
} from '../resources/js/lib/packages.ts';

const nbsp = ' ';

test('the monthly equivalent rounds down, like the server', () => {
    assert.equal(monthlyEquivalentMinor(3_500_000, 3), 1_166_666);
    assert.equal(monthlyEquivalentMinor(1_250_000, 1), 1_250_000);
    assert.equal(monthlyEquivalentMinor(10, 12), 0);
    assert.equal(monthlyEquivalentMinor(1_000, 0), 0);
});

// Each case mirrors SubscriptionPackage::savingPercentage(): the saving is
// measured against the monthly price times the months, in whole percent,
// rounded down, and null when nothing is saved.
const savingCases = [
    // [amountMinor, months, monthlyMinor, expected]
    [1_250_000, 1, 1_250_000, null],
    [3_500_000, 3, 1_250_000, 6],
    [3_487_500, 3, 1_250_000, 7],
    [3_750_000, 3, 1_250_000, null],
    [3_800_000, 3, 1_250_000, null],
    [14_000_000, 12, 1_250_000, 6],
    [12_750_000, 12, 1_250_000, 15],
    [7_000_000, 6, 1_250_000, 6],
    // Rounds down: 14.9999% is 14.
    [1_276_500, 1, 1_500_000, 14],
    // A saving under 1% is null on the server.
    [14_900_000, 1, 15_000_000, null],
    [29_850_000, 2, 15_000_000, null],
    [1_195_000, 12, 100_000, null],
    // No monthly price to compare with.
    [3_500_000, 3, null, null],
    [3_500_000, 3, 0, null],
];

test('the saving matches the server rule', () => {
    for (const [amount, months, monthly, expected] of savingCases) {
        assert.equal(
            savingPercentage(amount, months, monthly),
            expected,
            `${amount} for ${months} months against ${monthly}`,
        );
    }
});

test('a saving is never negative; a dearer period is flagged separately', () => {
    assert.equal(savingPercentage(5_000_000, 3, 1_250_000), null);
    assert.equal(costsMoreThanMonthly(5_000_000, 3, 1_250_000), true);
    assert.equal(costsMoreThanMonthly(3_750_000, 3, 1_250_000), false);
    assert.equal(costsMoreThanMonthly(3_000_000, 3, 1_250_000), false);
    assert.equal(costsMoreThanMonthly(3_000_000, 3, null), false);
});

test('a price is read the way it is typed in Portuguese', () => {
    const cases = [
        ['12500', 1_250_000, '12500.00'],
        ['12 500', 1_250_000, '12500.00'],
        [`12${nbsp}500`, 1_250_000, '12500.00'],
        ['12.500', 1_250_000, '12500.00'],
        ['12.500,50', 1_250_050, '12500.50'],
        ['12500,5', 1_250_050, '12500.50'],
        ['12500,05', 1_250_005, '12500.05'],
        ['12500.5', 1_250_050, '12500.50'],
        ['12500.50', 1_250_050, '12500.50'],
        ['1.234.567', 123_456_700, '1234567.00'],
        ['0,99', 99, '0.99'],
        ['0', 0, '0.00'],
        ['007', 700, '7.00'],
        ['9999999999,99', 999_999_999_999, '9999999999.99'],
    ];

    for (const [text, minor, decimal] of cases) {
        assert.deepEqual(parseAmount(text), { minor, decimal }, text);
    }
});

test('anything that is not a price is refused', () => {
    for (const text of [
        '',
        '   ',
        'abc',
        '12a',
        '-5',
        '+5',
        '12,345',
        '12,5,0',
        '12,5.0',
        '1.2.3',
        '1234.5678',
        '.5',
        ',5',
        '12 5',
        '10000000000',
        '9999999999,991',
    ]) {
        // «12 5» is «125» once spaces go, so it is the only one that parses.
        if (text === '12 5') {
            assert.deepEqual(parseAmount(text), {
                minor: 12_500,
                decimal: '125.00',
            });
            continue;
        }

        assert.equal(parseAmount(text), null, JSON.stringify(text));
    }
});

test('minor units go back to the decimal string the server accepts', () => {
    assert.equal(minorToDecimal(1_250_000), '12500.00');
    assert.equal(minorToDecimal(5), '0.05');
    assert.equal(minorToDecimal(1_250_050), '12500.50');
});

test('a price is grouped in threes and shows cêntimos only when present', () => {
    assert.equal(formatMinor(1_250_000), `12${nbsp}500`);
    assert.equal(formatMinor(1_250_050), `12${nbsp}500,50`);
    assert.equal(formatMinor(99), '0,99');
    assert.equal(formatMinor(0), '0');
    assert.equal(
        formatMinor(100_000_000_000),
        `1${nbsp}000${nbsp}000${nbsp}000`,
    );
    assert.equal(formatPrice(1_250_000, 'Kz'), `12${nbsp}500${nbsp}Kz`);
    assert.equal(formatSaving(7), '−7%');
});

test('a monthly equivalent is written in whole kwanzas, rounded like the pages', () => {
    assert.equal(formatMinorWhole(1_166_666), `11${nbsp}667`);
    assert.equal(formatMinorWhole(1_166_649), `11${nbsp}666`);
    assert.equal(formatMinorWhole(1_250_000), `12${nbsp}500`);
    assert.equal(formatMinorWhole(49), '0');
});

test('a price goes back into its field in the form it is typed', () => {
    assert.equal(minorToInputText(1_250_000), '12500');
    assert.equal(minorToInputText(1_250_050), '12500,50');
    assert.equal(minorToInputText(5), '0,05');
    assert.deepEqual(parseAmount(minorToInputText(1_250_050)), {
        minor: 1_250_050,
        decimal: '12500.50',
    });
});

test('a chip names the period, the price and the saving', () => {
    const base = { currency: 'Kz', monthlyAmountMinor: 1_250_000 };

    assert.equal(
        priceChipLabel({
            ...base,
            intervalLabel: 'Mensal',
            amountMinor: 1_250_000,
            months: 1,
        }),
        `Mensal 12${nbsp}500${nbsp}Kz`,
    );
    assert.equal(
        priceChipLabel({
            ...base,
            intervalLabel: 'Trimestral',
            amountMinor: 3_487_500,
            months: 3,
        }),
        `Trimestral 34${nbsp}875${nbsp}Kz · −7%`,
    );
    assert.equal(
        priceChipLabel({
            ...base,
            intervalLabel: 'Anual',
            amountMinor: 20_000_000,
            months: 12,
        }),
        `Anual 200${nbsp}000${nbsp}Kz`,
    );
    assert.equal(
        priceChipLabel({
            intervalLabel: 'Anual',
            amountMinor: 20_000_000,
            months: 12,
            currency: 'Kz',
            monthlyAmountMinor: null,
        }),
        `Anual 200${nbsp}000${nbsp}Kz`,
    );
});

test('a code is suggested from the name', () => {
    assert.equal(slugFromName('Profissional'), 'profissional');
    assert.equal(slugFromName('  Pequena Empresa  '), 'pequena-empresa');
    assert.equal(slugFromName('Ação & Gestão (PME)'), 'acao-gestao-pme');
    assert.equal(slugFromName('Básico — 2026'), 'basico-2026');
    assert.equal(slugFromName('---'), '');
    assert.equal(slugFromName(''), '');

    const long = slugFromName('a'.repeat(47) + ' bcd');

    assert.equal(long.length <= 48, true);
    assert.equal(long.endsWith('-'), false);
    assert.match(slugFromName('Já está'), /^[a-z0-9]+(?:-[a-z0-9]+)*$/);
});

test('pasted text becomes one feature per line', () => {
    assert.deepEqual(splitPastedFeatures('Facturas ilimitadas'), [
        'Facturas ilimitadas',
    ]);
    assert.deepEqual(
        splitPastedFeatures(
            'Facturas ilimitadas\nSAF-T mensal\r\n\r\n  Suporte  ',
        ),
        ['Facturas ilimitadas', 'SAF-T mensal', 'Suporte'],
    );
    assert.deepEqual(
        splitPastedFeatures('- Um\n• Dois\n* Três\n1. Quatro\n2) Cinco'),
        ['Um', 'Dois', 'Três', 'Quatro', 'Cinco'],
    );
    // A hyphen inside a line, or a number that is part of the text, stays.
    assert.deepEqual(splitPastedFeatures('10 utilizadores\nPós-venda'), [
        '10 utilizadores',
        'Pós-venda',
    ]);
    assert.deepEqual(splitPastedFeatures('\n \n'), []);
});

const row = (interval, interval_label, months, saving_percentage) => ({
    interval,
    interval_label,
    months,
    saving_percentage,
});

test('only the periods that exist are offered, shortest first, with the best saving', () => {
    const options = periodOptions([
        row('annual', 'Anual', 12, 15),
        row('monthly', 'Mensal', 1, null),
        row('annual', 'Anual', 12, 20),
        row('quarterly', 'Trimestral', 3, 7),
        row('quarterly', 'Trimestral', 3, null),
        row('monthly', 'Mensal', 1, null),
    ]);

    assert.deepEqual(options, [
        { value: 'monthly', label: 'Mensal', months: 1, saving: null },
        { value: 'quarterly', label: 'Trimestral', months: 3, saving: 7 },
        { value: 'annual', label: 'Anual', months: 12, saving: 20 },
    ]);
    assert.deepEqual(periodOptions([]), []);
});

test('the switch starts on what is paid today, else monthly, else the first', () => {
    const all = periodOptions([
        row('monthly', 'Mensal', 1, null),
        row('annual', 'Anual', 12, 10),
    ]);
    const noMonthly = periodOptions([
        row('semiannual', 'Semestral', 6, 5),
        row('annual', 'Anual', 12, 10),
    ]);

    assert.equal(initialPeriod(all, 'annual'), 'annual');
    assert.equal(initialPeriod(all, null), 'monthly');
    assert.equal(initialPeriod(all, 'quarterly'), 'monthly');
    assert.equal(initialPeriod(noMonthly, 'monthly'), 'semiannual');
    assert.equal(initialPeriod(noMonthly, null), 'semiannual');
    assert.equal(initialPeriod([], 'monthly'), null);
});
