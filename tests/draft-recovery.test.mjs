import assert from 'node:assert/strict';
import { test, after } from 'node:test';
import { createServer } from 'vite';
import { createRenderer, defineComponent, h, reactive } from 'vue';

const server = await createServer({ configFile: false, server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom' });
after(() => server.close());
const { useDraftRecovery } = await server.ssrLoadModule('/resources/js/lib/use-draft-recovery.ts');
const { recoveryKey, readRecovery, writeRecovery, invoiceRecoveryDocument } = await server.ssrLoadModule('/resources/js/lib/draft-recovery.ts');

function storage() {
    const values = new Map();

    return { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: (key) => values.delete(key) };
}

const renderer = createRenderer({
    createElement: () => ({}), createText: () => ({}), createComment: () => ({}),
    setText() {}, setElementText() {}, parentNode: () => null, nextSibling: () => null,
    insert() {}, remove() {}, patchProp() {},
});

function mount(saved, scope = ['person', 'workspace', 'company'], revision = '1') {
    globalThis.window = { sessionStorage: saved };
    const form = reactive({ notes: '', lines: [{ description: '', quantity: '1' }] });
    const state = reactive({ revision, document: 'invoice:1', scope });
    let recovery;
    const app = renderer.createApp(defineComponent({ setup() {
        recovery = useDraftRecovery({ scope: () => state.scope, document: () => state.document, version: () => state.revision,
            data: () => ({ notes: form.notes, lines: form.lines }), restore: (data) => Object.assign(form, data) });

        return () => h('div');
    } }));
    app.mount({});

    return { form, state, recovery, close: () => app.unmount() };
}

test('unfinished nested lines survive a remount and are restored only on request', async () => {
    const saved = storage();
    const first = mount(saved);
    first.form.notes = 'Entrega amanhã';
    first.form.lines[0].description = 'Cimento';
    first.close();
    const second = mount(saved);
    assert.equal(second.form.notes, '');
    assert.ok(second.recovery.pending.value);
    await second.recovery.restore();
    assert.equal(second.form.notes, 'Entrega amanhã');
    assert.equal(second.form.lines[0].description, 'Cimento');
    second.close();
});

test('another account or company cannot pick up a recovery copy', () => {
    const saved = storage();
    const first = mount(saved);
    first.form.notes = 'Private';
    first.close();

    for (const scope of [['other', 'workspace', 'company'], ['person', 'other', 'company'], ['person', 'workspace', 'other']]) {
        const other = mount(saved, scope);
        assert.equal(other.recovery.pending.value, null);
        other.close();
    }
});

test('a newer server revision cannot be overwritten by restoration', async () => {
    const saved = storage();
    const first = mount(saved);
    first.form.notes = 'Old';
    first.close();
    const second = mount(saved, undefined, '2');
    assert.equal(second.recovery.hasConflict.value, true);
    await second.recovery.restore();
    assert.equal(second.form.notes, '');
    second.recovery.discard();
    second.close();
    const third = mount(saved);
    assert.equal(third.recovery.pending.value, null);
    third.close();
});

test('successful saves retain edits made during the request under the new revision', () => {
    const saved = storage();
    const first = mount(saved);
    first.form.notes = 'Submitted';
    first.recovery.beforeSave();
    first.form.notes = 'Typed while saving';
    first.state.revision = '2';
    first.recovery.saved();
    first.close();
    const second = mount(saved, undefined, '2');
    assert.equal(second.recovery.pending.value.data.notes, 'Typed while saving');
    second.close();
});

test('edits while a request is in flight survive a reload before its response', () => {
    const saved = storage();
    const first = mount(saved);
    first.form.notes = 'Submitted';
    first.recovery.beforeSave();
    first.form.notes = 'More work';
    first.close();
    const second = mount(saved);
    assert.equal(second.recovery.pending.value.data.notes, 'More work');
    second.close();
});

test('storage failure is visible and does not break editing', () => {
    const saved = storage();
    saved.setItem = () => {
 throw new Error('quota'); 
};
    const screen = mount(saved);
    screen.form.notes = 'Still here';
    assert.match(screen.recovery.message.value, /Não foi possível/);
    assert.equal(screen.form.notes, 'Still here');
    screen.close();
});

test('undoing all edits removes the obsolete recovery copy', () => {
    const saved = storage();
    const first = mount(saved);
    first.form.notes = 'Changed my mind';
    first.form.notes = '';
    first.close();
    const second = mount(saved);
    assert.equal(second.recovery.pending.value, null);
    second.close();
});

test('an acknowledged save removes the old copy including when a new document gets its id', () => {
    const saved = storage();
    const first = mount(saved);
    first.form.notes = 'Saved';
    first.recovery.beforeSave();
    first.state.document = 'invoice:2';
    first.state.revision = '2';
    first.recovery.saved();
    first.close();
    assert.equal(readRecovery(saved, recoveryKey(['person', 'workspace', 'company', 'invoice:1'])), null);
    assert.equal(readRecovery(saved, recoveryKey(['person', 'workspace', 'company', 'invoice:2'])), null);
});

test('switching account while mounted cannot save old form values into the new account', () => {
    const saved = storage();
    const first = mount(saved);
    first.form.notes = 'Original';
    first.state.scope = ['someone-else', 'workspace', 'company'];
    first.form.notes = 'Must not persist';
    first.close();
    const second = mount(saved);
    assert.equal(second.recovery.pending.value.data.notes, 'Original');
    second.close();
    assert.equal(readRecovery(saved, recoveryKey(['someone-else', 'workspace', 'company', 'invoice:1'])), null);
});

test('expired and damaged copies cannot reach the form', () => {
    const saved = storage();
    const key = recoveryKey(['person', 'workspace', 'company', 'invoice:1']);
    saved.setItem(key, '{broken');
    assert.equal(readRecovery(saved, key), null);
    writeRecovery(saved, key, '1', { notes: '', lines: 'broken' });
    const screen = mount(saved);
    assert.equal(screen.recovery.pending.value, null);
    assert.match(screen.recovery.message.value, /não é compatível/);
    screen.close();
    writeRecovery(saved, key, '1', { notes: '' });
    assert.equal(readRecovery(saved, key, Date.now() + 8 * 86400000), null);
});

test('repeat drafts preserve ordinary drafts and other sources and rekey after saving', () => {
    const saved = storage();
    const ordinary = invoiceRecoveryDocument(null, 'FT');
    const repeated = invoiceRecoveryDocument(null, 'FT', 'source-a');
    const other = invoiceRecoveryDocument(null, 'FT', 'source-b');
    assert.equal(new Set([ordinary, repeated, other]).size, 3);
    writeRecovery(saved, ordinary, '0', { notes: 'Keep ordinary' });
    writeRecovery(saved, repeated, '0', { notes: 'Keep repeat' });
    assert.equal(readRecovery(saved, ordinary).data.notes, 'Keep ordinary');
    assert.equal(readRecovery(saved, repeated).data.notes, 'Keep repeat');
    assert.equal(readRecovery(saved, other), null);
    assert.equal(invoiceRecoveryDocument('new-document', 'FT', 'source-a'), 'invoice:new-document');
});

test('a credit note drafted from a document keeps its own recovery key', () => {
    const blank = invoiceRecoveryDocument(null, 'NC');
    const correcting = invoiceRecoveryDocument(null, 'NC', undefined, 'source-a');
    const other = invoiceRecoveryDocument(null, 'NC', undefined, 'source-b');
    assert.equal(new Set([blank, correcting, other]).size, 3);
    assert.equal(invoiceRecoveryDocument('saved-note', 'NC', undefined, 'source-a'), 'invoice:saved-note');
});
