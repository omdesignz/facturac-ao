import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    matchItem,
    normalise,
    parseTypedLines,
} from '../resources/js/lib/typed-lines.ts';

const items = [
    {
        public_id: 'oleo',
        code: 'OLEO-1L',
        name: 'Óleo alimentar 1 L',
        unit_price: '1140.00',
    },
    {
        public_id: 'arroz',
        code: 'ARROZ-1K',
        name: 'Arroz agulha 1 kg',
        unit_price: '1299.00',
    },
    {
        public_id: 'cesto',
        code: 'CESTO',
        name: 'Cesto de compras',
        unit_price: '1000.00',
    },
    {
        public_id: 'rolo',
        code: 'ROLO-80',
        name: 'Rolo térmico 80 mm',
        unit_price: '2052.00',
    },
    {
        public_id: 'rolo2',
        code: 'ROLO-57',
        name: 'Rolo térmico 57 mm',
        unit_price: '1500.00',
    },
    {
        public_id: 'saco',
        code: 'SACO-K',
        name: 'Saco de papel kraft',
        unit_price: '65.00',
    },
];

test('accents, case and punctuation do not matter', () => {
    assert.equal(normalise('Óleo, 1L!'), 'oleo, 1l');
    assert.equal(matchItem('OLEO', items).public_id, 'oleo');
});

test('a quantity and a name become a line', () => {
    const [line] = parseTypedLines('2 oleo', items);

    assert.equal(line.quantity, '2');
    assert.equal(line.item.public_id, 'oleo');
    assert.equal(line.unitPrice, null);
});

test('no quantity means one', () => {
    const [line] = parseTypedLines('arroz', items);

    assert.equal(line.quantity, '1');
    assert.equal(line.item.public_id, 'arroz');
});

test('commas, semicolons and new lines separate lines, but a decimal comma does not', () => {
    const lines = parseTypedLines('2 oleo, 1,5 arroz; 3 cesto\n1 saco', items);

    assert.deepEqual(
        lines.map((line) => [line.quantity, line.item.public_id]),
        [
            ['2', 'oleo'],
            ['1.5', 'arroz'],
            ['3', 'cesto'],
            ['1', 'saco'],
        ],
    );
});

test('an x or an "un" after the number is allowed', () => {
    assert.equal(parseTypedLines('3x cesto', items)[0].quantity, '3');
    assert.equal(parseTypedLines('3 × cesto', items)[0].quantity, '3');
    assert.equal(
        parseTypedLines('3 un cesto', items)[0].item.public_id,
        'cesto',
    );
});

test('a price after "a" or "@" overrides the catalogue', () => {
    const [line] = parseTypedLines('20 cesto a 950', items);

    assert.equal(line.quantity, '20');
    assert.equal(line.unitPrice, '950');
    assert.equal(line.item.public_id, 'cesto');

    assert.equal(
        parseTypedLines('1 cesto @ 1050,50 kz', items)[0].unitPrice,
        '1050.50',
    );
});

test('every typed word must start a word of the name or the code', () => {
    assert.equal(matchItem('oleo 1l', items).public_id, 'oleo');
    assert.equal(matchItem('rolo 80', items).public_id, 'rolo');
    assert.equal(matchItem('SACO-K', items).public_id, 'saco');
    assert.equal(matchItem('chocolate', items), null);
});

test('two items that answer equally well are left to a person', () => {
    assert.equal(matchItem('rolo', items), null);
    assert.equal(parseTypedLines('2 rolo', items)[0].item, null);
});

test('a perfect name beats a longer one', () => {
    const more = [
        ...items,
        {
            public_id: 'arroz2',
            code: 'ARR-L',
            name: 'Arroz',
            unit_price: '10.00',
        },
        {
            public_id: 'arroz3',
            code: 'ARR-P',
            name: 'Arroz agulha premium 1 kg',
            unit_price: '10.00',
        },
    ];

    assert.equal(matchItem('arroz', more).public_id, 'arroz2');
});

test('empty text is no lines', () => {
    assert.deepEqual(parseTypedLines('  ,; \n ', items), []);
});
