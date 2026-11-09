import assert from 'node:assert/strict';
import { after, test } from 'node:test';
import {
    focusFirstInvalid,
    restoreDialogFocus,
} from '../resources/js/lib/focus.ts';

const previous = globalThis.document;
after(() => {
    globalThis.document = previous;
});

test('a closed dialog returns keyboard focus without scrolling the list', async () => {
    const body = {};
    globalThis.document = {
        body,
        activeElement: body,
        querySelector: () => null,
    };
    let focused;
    const origin = {
        isConnected: true,
        focus: (options) => {
            focused = options;
        },
    };
    assert.equal(await restoreDialogFocus(origin), true);
    assert.deepEqual(focused, { preventScroll: true });
});

test('closing a dialog never steals focus from another control or a newly opened dialog', async () => {
    const body = {};
    const origin = {
        isConnected: true,
        focus: () => assert.fail('Unexpected focus theft'),
    };
    globalThis.document = {
        body,
        activeElement: { isConnected: true },
        querySelector: () => null,
    };
    assert.equal(await restoreDialogFocus(origin), false);
    document.activeElement = body;
    document.querySelector = () => ({});
    assert.equal(await restoreDialogFocus(origin), false);
});

test('a removed trigger and server rendering require no focus recovery', async () => {
    const body = {};
    globalThis.document = {
        body,
        activeElement: body,
        querySelector: () => null,
    };
    assert.equal(await restoreDialogFocus({ isConnected: false }), false);
    assert.equal(await restoreDialogFocus(null), false);
    globalThis.document = undefined;
    assert.equal(await restoreDialogFocus(null), false);
});

test('server validation focuses and reveals the first invalid field', async () => {
    const calls = [];
    const control = {
        focus: (options) => calls.push(['focus', options]),
        scrollIntoView: (options) => calls.push(['scroll', options]),
    };
    assert.equal(
        await focusFirstInvalid({ querySelector: () => control }),
        true,
    );
    assert.deepEqual(calls, [
        ['focus', { preventScroll: true }],
        ['scroll', { block: 'center', behavior: 'auto' }],
    ]);
    assert.equal(await focusFirstInvalid({ querySelector: () => null }), false);
});
