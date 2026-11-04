import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    concentrationLine,
    debtShares,
} from '../resources/js/lib/debt-share.ts';

const debtors = [
    { name: 'Kizomba', outstanding_minor: 26 },
    { name: 'Mavinga', outstanding_minor: 74 },
];

test('the biggest debtor leads and the shares add up', () => {
    const shares = debtShares(debtors, 100);

    assert.deepEqual(
        shares.map((entry) => [entry.name, entry.percent]),
        [
            ['Mavinga', 74],
            ['Kizomba', 26],
        ],
    );
});

test('debtors past the shown few are folded into Outros', () => {
    const many = [40, 30, 20, 6, 4].map((value, index) => ({
        name: `C${index}`,
        outstanding_minor: value,
    }));
    const shares = debtShares(many, 100);

    assert.equal(shares.length, 4);
    assert.deepEqual(shares.at(-1), {
        name: 'Outros',
        share: 0.1,
        percent: 10,
    });
});

test('nothing owed means no shares and no sentence', () => {
    assert.deepEqual(debtShares([], 0), []);
    assert.equal(concentrationLine([], 0), null);
});

test('one company holding most of the debt is named; an even spread is not', () => {
    assert.match(
        concentrationLine(debtShares(debtors, 100), 3),
        /Mavinga concentra 74%/,
    );

    const even = [50, 50].map((value, index) => ({
        name: `C${index}`,
        outstanding_minor: value / 2,
    }));

    assert.match(
        concentrationLine(
            debtShares([...even, { name: 'C9', outstanding_minor: 30 }], 80),
            30,
        ),
        /repartido/,
    );
});
