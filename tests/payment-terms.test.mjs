import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { addDaysToIsoDate } from '../resources/js/lib/dates.ts';

const timezones = ['Africa/Luanda', 'UTC', 'America/Los_Angeles'];

const cases = [
    ['2026-10-10', 30, '2026-11-09'],
    ['2026-10-10', 0, '2026-10-10'],
    ['2026-10-10', 1, '2026-10-11'],
    ['2026-01-31', 1, '2026-02-01'],
    ['2026-01-31', 30, '2026-03-02'],
    ['2026-02-28', 1, '2026-03-01'],
    ['2028-02-28', 1, '2028-02-29'],
    ['2028-02-28', 2, '2028-03-01'],
    ['2026-12-31', 1, '2027-01-01'],
    ['2026-12-15', 30, '2027-01-14'],
    ['2026-11-01', 90, '2027-01-30'],
    ['2026-03-08', 1, '2026-03-09'],
    ['2026-03-29', 1, '2026-03-30'],
    ['2026-10-25', 7, '2026-11-01'],
];

test('adds payment-term days to the issue date', () => {
    for (const [issued, days, due] of cases) {
        assert.equal(
            addDaysToIsoDate(issued, days),
            due,
            `${issued} + ${days}`,
        );
    }
});

test('rejects dates that are not on the calendar', () => {
    for (const value of [
        '',
        'abc',
        '2026-02-30',
        '2026-13-01',
        '2026-00-10',
        '2026-10-32',
        '2026-1-1',
        '2026-10-10T00:00:00',
    ]) {
        assert.equal(addDaysToIsoDate(value, 30), null, value);
    }

    assert.equal(addDaysToIsoDate('2026-10-10', 1.5), null);
    assert.equal(addDaysToIsoDate('2026-10-10', Number.NaN), null);
});

test('gives the same dates in every timezone', () => {
    const script = `
        import { addDaysToIsoDate } from ${JSON.stringify(
            new URL('../resources/js/lib/dates.ts', import.meta.url).href,
        )};
        const cases = ${JSON.stringify(cases)};
        console.log(JSON.stringify(cases.map(([issued, days]) => addDaysToIsoDate(issued, days))));
    `;
    const expected = cases.map(([, , due]) => due);

    for (const timeZone of timezones) {
        const output = execFileSync(
            process.execPath,
            ['--input-type=module', '--eval', script],
            {
                env: { ...process.env, TZ: timeZone },
                cwd: fileURLToPath(new URL('..', import.meta.url)),
                encoding: 'utf8',
            },
        );

        assert.deepEqual(JSON.parse(output), expected, timeZone);
    }
});

test('reads today from the local calendar, not from UTC', () => {
    const script = `
        import { localIsoDate, startOfYearIsoDate } from ${JSON.stringify(
            new URL('../resources/js/lib/dates.ts', import.meta.url).href,
        )};
        const justAfterMidnight = new Date(2026, 0, 1, 0, 30);
        const lateEvening = new Date(2026, 9, 10, 23, 30);
        console.log(JSON.stringify([
            localIsoDate(justAfterMidnight),
            startOfYearIsoDate(justAfterMidnight),
            localIsoDate(lateEvening),
            startOfYearIsoDate(lateEvening),
        ]));
    `;

    for (const timeZone of timezones) {
        const output = execFileSync(
            process.execPath,
            ['--input-type=module', '--eval', script],
            {
                env: { ...process.env, TZ: timeZone },
                cwd: fileURLToPath(new URL('..', import.meta.url)),
                encoding: 'utf8',
            },
        );

        assert.deepEqual(
            JSON.parse(output),
            ['2026-01-01', '2026-01-01', '2026-10-10', '2026-01-01'],
            timeZone,
        );
    }
});
