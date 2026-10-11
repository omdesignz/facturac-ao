import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { test } from 'node:test';
import {
    addMonthsToIsoDate,
    clampIsoDate,
    formatDisplayValue,
    formatModelValue,
    formatMonthYear,
    maskTypedValue,
    monthGrid,
    parseModelValue,
    parseTypedValue,
    stepHours,
    stepMinutes,
    weekdayOfIsoDate,
} from '../resources/js/lib/dates.ts';

const timezones = ['Africa/Luanda', 'UTC', 'America/Los_Angeles'];

test('the three modes read and write their own wire format', () => {
    assert.deepEqual(parseModelValue('2026-10-11', 'date'), {
        date: '2026-10-11',
        time: null,
    });
    assert.deepEqual(parseModelValue('2026-10-11T09:05', 'datetime'), {
        date: '2026-10-11',
        time: '09:05',
    });
    assert.deepEqual(parseModelValue('09:05', 'time'), {
        date: null,
        time: '09:05',
    });

    assert.equal(
        formatModelValue({ date: '2026-10-11', time: null }, 'date'),
        '2026-10-11',
    );
    assert.equal(
        formatModelValue({ date: '2026-10-11', time: '09:05' }, 'datetime'),
        '2026-10-11T09:05',
    );
    assert.equal(
        formatModelValue({ date: null, time: '09:05' }, 'time'),
        '09:05',
    );
});

test('a datetime keeps its wall time: seconds and offsets are ignored, never applied', () => {
    for (const value of [
        '2026-10-11T09:05:30',
        '2026-10-11 09:05',
        '2026-10-11T09:05:00Z',
        '2026-10-11T09:05:00+01:00',
        '2026-10-11T09:05:00.000-08:00',
    ]) {
        assert.deepEqual(parseModelValue(value, 'datetime'), {
            date: '2026-10-11',
            time: '09:05',
        });
    }

    assert.equal(
        formatModelValue(
            { date: '2026-10-11', time: '09:05' },
            'datetime',
            ' ',
        ),
        '2026-10-11 09:05',
    );
    // A day with no time is midnight, not "now".
    assert.equal(
        formatModelValue({ date: '2026-10-11', time: null }, 'datetime'),
        '2026-10-11T00:00',
    );
});

test('a date field keeps the day of a datetime it is handed', () => {
    assert.deepEqual(parseModelValue('2026-10-11 23:30:00', 'date'), {
        date: '2026-10-11',
        time: null,
    });
});

test('what is not a real date or time is not a value', () => {
    for (const [value, mode] of [
        ['2026-02-31', 'date'],
        ['2026-13-01', 'date'],
        ['11/10/2026', 'date'],
        ['', 'date'],
        [null, 'date'],
        ['2026-10-11', 'datetime'],
        ['2026-10-11T24:00', 'datetime'],
        ['2026-10-11T09:60', 'datetime'],
        ['24:00', 'time'],
        ['9:05', 'time'],
        ['2026-10-11', 'time'],
    ]) {
        assert.equal(parseModelValue(value, mode), null, `${mode}: ${value}`);
    }

    assert.equal(
        formatModelValue({ date: '2026-02-31', time: null }, 'date'),
        null,
    );
    assert.equal(formatModelValue({ date: null, time: '25:00' }, 'time'), null);
    assert.equal(formatModelValue(null, 'date'), null);
});

test('the field shows dd/MM/yyyy, with HH:mm after it or on its own', () => {
    assert.equal(
        formatDisplayValue({ date: '2026-10-11', time: null }, 'date'),
        '11/10/2026',
    );
    assert.equal(
        formatDisplayValue({ date: '2026-01-05', time: '09:05' }, 'datetime'),
        '05/01/2026 09:05',
    );
    assert.equal(
        formatDisplayValue({ date: null, time: '09:05' }, 'time'),
        '09:05',
    );
    assert.equal(formatDisplayValue(null, 'date'), '');
});

test('typing a date by hand is read as dd/MM/yyyy', () => {
    assert.deepEqual(parseTypedValue('11/10/2026', 'date'), {
        date: '2026-10-11',
        time: null,
    });
    assert.deepEqual(parseTypedValue('1/2/2026', 'date'), {
        date: '2026-02-01',
        time: null,
    });
    assert.deepEqual(parseTypedValue('05.11.2026', 'date'), {
        date: '2026-11-05',
        time: null,
    });
    // Pasted without separators.
    assert.deepEqual(parseTypedValue('25122026', 'date'), {
        date: '2026-12-25',
        time: null,
    });
    assert.equal(parseTypedValue('31022026', 'date'), null);
    assert.deepEqual(parseTypedValue('05-11-2026', 'date'), {
        date: '2026-11-05',
        time: null,
    });
    assert.deepEqual(parseTypedValue('29/02/2028', 'date'), {
        date: '2028-02-29',
        time: null,
    });
    assert.deepEqual(parseTypedValue('25/06/2026 9:30', 'datetime'), {
        date: '2026-06-25',
        time: '09:30',
    });
    assert.deepEqual(parseTypedValue('0905', 'time'), null);
    assert.deepEqual(parseTypedValue('9:05', 'time'), {
        date: null,
        time: '09:05',
    });

    for (const [text, mode] of [
        ['31/02/2026', 'date'],
        ['29/02/2026', 'date'],
        ['11/10/26', 'date'],
        ['11/10/20261', 'date'],
        ['32/01/2026', 'date'],
        ['abc', 'date'],
        ['11/10/2026 09:00', 'date'],
        ['11/10/2026', 'datetime'],
        ['11/10/2026 25:00', 'datetime'],
        ['24:00', 'time'],
        ['12:60', 'time'],
        ['', 'date'],
    ]) {
        assert.equal(parseTypedValue(text, mode), null, `${mode}: ${text}`);
    }
});

test('separators are added as digits are typed, and a typed one is not doubled', () => {
    // Typing «15102026» one character at a time.
    let text = '';

    for (const character of '15102026') {
        text = maskTypedValue(text + character, text, 'date');
    }

    assert.equal(text, '15/10/2026');

    // Typing the slash by hand after the one that was added.
    text = '';

    for (const character of '5/11/2026') {
        text = maskTypedValue(text + character, text, 'date');
    }

    assert.equal(text, '5/11/2026');

    text = '';

    for (const character of '25062026 1430') {
        text = maskTypedValue(text + character, text, 'datetime');
    }

    assert.equal(text, '25/06/2026 14:30');

    text = '';

    for (const character of '0905') {
        text = maskTypedValue(text + character, text, 'time');
    }

    assert.equal(text, '09:05');

    // Deleting is left alone.
    assert.equal(maskTypedValue('15/', '15/1', 'date'), '15/');
    assert.equal(maskTypedValue('15', '15/', 'date'), '15');
});

test('the calendar is six weeks of real days, Sunday first', () => {
    const weeks = monthGrid(2026, 10);

    assert.equal(weeks.length, 6);
    assert.ok(weeks.every((week) => week.length === 7));
    // 1 October 2026 is a Thursday: the week opens on Sunday 27 September.
    assert.equal(weeks[0][0].iso, '2026-09-27');
    assert.equal(weeks[0][0].inMonth, false);
    assert.equal(weeks[0][4].iso, '2026-10-01');
    assert.equal(weeks[0][4].inMonth, true);
    assert.equal(weeks[5][6].iso, '2026-11-07');

    // Monday first.
    assert.equal(monthGrid(2026, 10, 1)[0][0].iso, '2026-09-28');
    // February of a leap year keeps its 29th.
    assert.ok(
        monthGrid(2028, 2)
            .flat()
            .some((day) => day.iso === '2028-02-29' && day.inMonth),
    );
    assert.equal(weekdayOfIsoDate('2026-10-11'), 0);
    assert.equal(formatMonthYear(2026, 10), 'Outubro 2026');
});

test('months move by calendar months and stop at the last day', () => {
    assert.equal(addMonthsToIsoDate('2026-01-31', 1), '2026-02-28');
    assert.equal(addMonthsToIsoDate('2028-01-31', 1), '2028-02-29');
    assert.equal(addMonthsToIsoDate('2026-10-11', -10), '2025-12-11');
    assert.equal(addMonthsToIsoDate('2026-12-15', 1), '2027-01-15');
    assert.equal(addMonthsToIsoDate('2026-10-11', 12), '2027-10-11');
    assert.equal(addMonthsToIsoDate('2026-02-30', 1), null);
});

test('bounds keep a date inside them, and either may be absent', () => {
    assert.equal(
        clampIsoDate('2026-10-11', '2026-10-15', '2026-12-01'),
        '2026-10-15',
    );
    assert.equal(
        clampIsoDate('2026-12-31', '2026-10-15', '2026-12-01'),
        '2026-12-01',
    );
    assert.equal(clampIsoDate('2026-11-01', null, null), '2026-11-01');
    assert.equal(
        clampIsoDate('2026-11-01', undefined, '2026-12-01'),
        '2026-11-01',
    );
});

test('hours wrap around the clock and minutes step to five-minute marks', () => {
    assert.equal(stepHours(23, 1), 0);
    assert.equal(stepHours(0, -1), 23);
    assert.equal(stepHours(9, 1), 10);

    assert.equal(stepMinutes(13, 1), 15);
    assert.equal(stepMinutes(13, -1), 10);
    assert.equal(stepMinutes(15, 1), 20);
    assert.equal(stepMinutes(15, -1), 10);
    assert.equal(stepMinutes(55, 1), 0);
    assert.equal(stepMinutes(0, -1), 55);
});

test('no mode moves a date or a time in any timezone', () => {
    // Run the helpers under three timezones: the answers must not differ.
    const probe = `
        import {
            formatModelValue, parseModelValue, parseTypedValue, monthGrid, addMonthsToIsoDate,
        } from ${JSON.stringify(new URL('../resources/js/lib/dates.ts', import.meta.url).href)};
        const out = [
            parseModelValue('2026-10-11T00:30', 'datetime'),
            formatModelValue(parseTypedValue('01/01/2027 00:05', 'datetime'), 'datetime'),
            formatModelValue(parseTypedValue('31/12/2026', 'date'), 'date'),
            monthGrid(2026, 3)[0][0].iso,
            addMonthsToIsoDate('2026-03-31', 1),
        ];
        console.log(JSON.stringify(out));
    `;
    const results = timezones.map((timezone) =>
        execFileSync(
            process.execPath,
            ['--input-type=module', '--eval', probe],
            { env: { ...process.env, TZ: timezone }, encoding: 'utf8' },
        ).trim(),
    );

    assert.equal(new Set(results).size, 1, results.join('\n'));
    assert.deepEqual(JSON.parse(results[0]), [
        { date: '2026-10-11', time: '00:30' },
        '2027-01-01T00:05',
        '2026-12-31',
        '2026-03-01',
        '2026-04-30',
    ]);
});
