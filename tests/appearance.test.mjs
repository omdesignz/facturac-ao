import assert from 'node:assert/strict';
import { after, test } from 'node:test';
import {
    applyAppearance,
    getStoredAppearance,
    storeAppearance,
    watchSystemAppearance,
} from '../resources/js/lib/appearance.ts';

const original = {
    window: globalThis.window,
    document: globalThis.document,
    requestAnimationFrame: globalThis.requestAnimationFrame,
};
const storage = new Map();
const classes = new Set();
const root = {
    dataset: {},
    classList: {
        contains: (name) => classes.has(name),
        add: (name) => classes.add(name),
        remove: (name) => classes.delete(name),
        toggle: (name, enabled) =>
            enabled ? classes.add(name) : classes.delete(name),
    },
};
const system = {
    matches: true,
    addEventListener: (_name, callback) => {
        system.change = callback;
    },
};
globalThis.window = Object.assign(new EventTarget(), {
    localStorage: {
        getItem: (key) => storage.get(key) ?? null,
        setItem: (key, value) => storage.set(key, value),
    },
    matchMedia: () => system,
});
globalThis.document = { documentElement: root };
globalThis.requestAnimationFrame = (callback) => callback();
after(() => Object.assign(globalThis, original));

test('theme choices persist and broadcast the applied appearance to all controls', () => {
    let changed = 0;
    window.addEventListener('appearance-changed', () => changed++);
    storeAppearance('light');
    assert.equal(getStoredAppearance(), 'light');
    assert.equal(classes.has('dark'), false);
    assert.equal(root.dataset.appearance, 'light');
    storeAppearance('dark');
    assert.equal(getStoredAppearance(), 'dark');
    assert.equal(classes.has('dark'), true);
    assert.equal(changed, 2);
    assert.equal(classes.has('theme-switching'), false);
});

test('system follows the device while explicit choices survive device changes', () => {
    storeAppearance('system');
    watchSystemAppearance();
    system.matches = false;
    system.change();
    assert.equal(classes.has('dark'), false);
    system.matches = true;
    system.change();
    assert.equal(classes.has('dark'), true);
    storeAppearance('light');
    system.change();
    assert.equal(classes.has('dark'), false);
    applyAppearance('dark');
    assert.equal(root.dataset.appearance, 'dark');
});
