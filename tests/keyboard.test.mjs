import assert from 'node:assert/strict';
import { test } from 'node:test';
import { nextRow } from '../resources/js/lib/keyboard.ts';

const row = (ariaCurrent = null) => ({ ariaCurrent });

test('j and k walk from the focused row and stop at the ends', () => {
    const rows = [row(), row(), row()];

    assert.equal(nextRow(rows, rows[0], 1), rows[1]);
    assert.equal(nextRow(rows, rows[1], -1), rows[0]);
    assert.equal(nextRow(rows, rows[2], 1), null);
    assert.equal(nextRow(rows, rows[0], -1), null);
});

test('with nothing focused the walk starts from the open row', () => {
    const rows = [row(), row('true'), row()];

    assert.equal(nextRow(rows, null, 1), rows[2]);
    assert.equal(nextRow(rows, null, -1), rows[0]);
});

test('with nothing focused or open, j takes the first row and k the last', () => {
    const rows = [row(), row(), row()];

    assert.equal(nextRow(rows, null, 1), rows[0]);
    assert.equal(nextRow(rows, null, -1), rows[2]);
    assert.equal(nextRow([], null, 1), null);
});
