import assert from 'node:assert/strict';
import { test, after } from 'node:test';
import { fileURLToPath } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { renderToString } from '@vue/server-renderer';
import { createServer } from 'vite';
import { createSSRApp, h } from 'vue';

const server = await createServer({ configFile: false, plugins: [vue()], resolve: { alias: { '@': fileURLToPath(new URL('../resources/js', import.meta.url)) } }, server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom' });
after(() => server.close());
const { followUpDate, followUpsForToday } = await server.ssrLoadModule('/resources/js/lib/collection-follow-ups.ts');
const { default: Queue } = await server.ssrLoadModule('/resources/js/components/CollectionFollowUpQueue.vue');
const entry = (id, due, completed = false) => ({ customer_public_id: id, name: id, follow_up: { revision: 1, next_contact_on: '2026-11-07', promised_payment_on: null, note: '<script>private note</script>', completed, next_action_on: '2026-11-07', is_due: due, updated_by: 'Ana', updated_at: null } });

test('today includes due work and excludes completed or future work', () => {
    assert.deepEqual(followUpsForToday([entry('due', true), entry('future', false), entry('done', true, true)]).map((e) => e.name), ['due']);
});
test('date-only values never pass through a timezone conversion', () => {
    assert.equal(followUpDate('2026-11-07'), '07/11/2026');
    assert.equal(followUpDate(null), 'Sem data');
});
test('the queue opens the customer follow-up and safely renders internal notes', async () => {
    const html = await renderToString(createSSRApp({ render: () => h(Queue, { entries: [entry('due', true), entry('future', false)] }) }));
    assert.ok(html.includes('1 para hoje'));
    assert.ok(html.includes('#follow-up-heading'));
    assert.ok(html.includes('&lt;script&gt;private note&lt;/script&gt;'));
    assert.ok(!html.includes('<script>private note'));
    assert.ok(!html.includes('>future<'));
});

test('a conflict preserves typed text until explicitly loading the team version', async () => {
    const { createRenderer, defineComponent, reactive, nextTick, ssrContextKey } = await import('vue');
    const { router } = await import('@inertiajs/vue3');
    const { default: Form } = await server.ssrLoadModule('/resources/js/components/CollectionFollowUpForm.vue');
    const renderer = createRenderer({ createElement: () => ({}), createText: () => ({}), createComment: () => ({}), setText() {}, setElementText() {}, parentNode: () => null, nextSibling: () => null, insert() {}, remove() {}, patchProp() {} });
    const state = reactive({ customerPublicId: 'customer-a', followUp: entry('a', true).follow_up, canUpdate: true, recoveryScope: ['person', 'workspace'] });
    let setup;
    const app = renderer.createApp(defineComponent({ setup() {
 setup = Form.setup(state, { expose() {} });

 return () => h('div'); 
} }));
    app.provide(ssrContextKey, { modules: new Set() });
    app.mount({});
    const originalPut = router.put;
    let options;
    router.put = (_url, _data, supplied) => {
 options = supplied; 
};

    try {
        setup.form.note = 'My unsaved note';
        setup.save(false);
        state.followUp = { ...state.followUp, revision: 2, note: 'Teammate version' };
        await nextTick();
        options.onError({ revision: 'Reload first' });
        assert.equal(setup.form.note, 'My unsaved note');
        assert.equal(setup.form.revision, 1);
        setup.loadCurrentFollowUp();
        assert.equal(setup.form.note, 'Teammate version');
        assert.equal(setup.form.revision, 2);
        assert.equal(setup.form.errors.revision, undefined);
    } finally {
 router.put = originalPut; app.unmount(); 
}
});

function memoryStorage() {
    const values = new Map();

    return { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: (key) => values.delete(key) };
}

async function mountFollowUp(storage, overrides = {}) {
    const { createRenderer, defineComponent, reactive, ssrContextKey } = await import('vue');
    const { default: Form } = await server.ssrLoadModule('/resources/js/components/CollectionFollowUpForm.vue');
    globalThis.window = { sessionStorage: storage };
    const renderer = createRenderer({ createElement: () => ({}), createText: () => ({}), createComment: () => ({}), setText() {}, setElementText() {}, parentNode: () => null, nextSibling: () => null, insert() {}, remove() {}, patchProp() {} });
    const state = reactive({ customerPublicId: 'customer-a', followUp: entry('a', true).follow_up, canUpdate: true, recoveryScope: ['person', 'workspace'], ...overrides });
    let setup;
    const app = renderer.createApp(defineComponent({ setup() {
 setup = Form.setup(state, { expose() {} });

 return () => h('div'); 
} }));
    app.provide(ssrContextKey, { modules: new Set() });
    app.mount({});

    return { setup, state, close: () => app.unmount() };
}

test('follow-up notes and dates survive navigation and require explicit restoration', async () => {
    const storage = memoryStorage();
    const first = await mountFollowUp(storage);
    first.setup.form.note = 'Telefonar depois do almoço';
    first.setup.form.next_contact_on = '2026-11-09';
    first.setup.form.promised_payment_on = '2026-11-12';
    first.close();
    const second = await mountFollowUp(storage);
    assert.equal(second.setup.form.note, '<script>private note</script>');
    assert.ok(second.setup.recovery.pending.value);
    const { router } = await import('@inertiajs/vue3');
    const originalPut = router.put;
    router.put = () => {
 assert.fail('Unreviewed recovery must not submit'); 
};

    try {
 second.setup.save(false); 
} finally {
 router.put = originalPut; 
}

    await second.setup.recovery.restore();
    assert.equal(second.setup.form.note, 'Telefonar depois do almoço');
    assert.equal(second.setup.form.next_contact_on, '2026-11-09');
    assert.equal(second.setup.form.promised_payment_on, '2026-11-12');
    assert.equal(second.setup.form.revision, 1);
    second.close();
});

test('follow-up recovery is isolated by account company and customer and respects permission', async () => {
    const storage = memoryStorage();
    const first = await mountFollowUp(storage);
    first.setup.form.note = 'Private unsaved note';
    first.close();

    for (const overrides of [
        { recoveryScope: ['other-person', 'workspace'] },
        { recoveryScope: ['person', 'other-workspace'] },
        { customerPublicId: 'customer-b' },
        { canUpdate: false },
    ]) {
        const screen = await mountFollowUp(storage, overrides);
        assert.equal(screen.setup.recovery.pending.value, null);
        screen.close();
    }
});

test('a newer team revision blocks stale restoration without dropping the saved note', async () => {
    const storage = memoryStorage();
    const first = await mountFollowUp(storage);
    first.setup.form.note = 'Old local note';
    first.close();
    const next = await mountFollowUp(storage, { followUp: { ...entry('a', true).follow_up, revision: 2, note: 'Team note' } });
    assert.equal(next.setup.recovery.hasConflict.value, true);
    await next.setup.recovery.restore();
    assert.equal(next.setup.form.note, 'Team note');
    assert.equal(next.setup.recovery.pending.value.data.note, 'Old local note');
    next.setup.recovery.discard();
    next.close();
    const after = await mountFollowUp(storage);
    assert.equal(after.setup.recovery.pending.value, null);
    after.close();
});

test('successful save clears recovery even when the server normalizes the note', async () => {
    const { router } = await import('@inertiajs/vue3');
    const { nextTick } = await import('vue');
    const storage = memoryStorage();
    const first = await mountFollowUp(storage);
    first.setup.form.note = '  Nova nota  ';
    const originalPut = router.put;
    let options;
    router.put = (_url, _data, supplied) => {
 options = supplied; 
};

    try {
        first.setup.save(false);
        first.state.followUp = { ...first.state.followUp, revision: 2, note: 'Nova nota' };
        await nextTick();
        await options.onSuccess({});
    } finally {
 router.put = originalPut; 
}

    first.close();
    const next = await mountFollowUp(storage, { followUp: first.state.followUp });
    assert.equal(next.setup.recovery.pending.value, null);
    assert.equal(next.setup.form.note, 'Nova nota');
    next.close();
});
