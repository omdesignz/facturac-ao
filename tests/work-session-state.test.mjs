import assert from 'node:assert/strict';
import { after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import { createRenderer, defineComponent, h, ref, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';

const server = await createServer({ configFile: false, plugins: [{ name: 'controlled-session-clock', resolveId(id) { if (id === '@vueuse/core' || id === 'virtual:session-clock') return '\0session-clock'; }, load(id) { if (id === '\0session-clock') return `import { ref } from 'vue'; export const clock = ref(Date.now()); export function useTimestamp() { return clock; }`; } }], cacheDir: 'node_modules/.vite-tests/work-session-state', resolve: { alias: { '@vueuse/core': 'virtual:session-clock', '@': fileURLToPath(new URL('../resources/js', import.meta.url)) } }, server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom' });
after(() => server.close());
const { clock } = await server.ssrLoadModule('virtual:session-clock');
const { useWorkSession } = await server.ssrLoadModule('/resources/js/lib/work-session.ts');
const renderer = createRenderer({ createElement: () => ({}), createText: () => ({}), createComment: () => ({}), setText() {}, setElementText() {}, parentNode: () => null, nextSibling: () => null, insert() {}, remove() {}, patchProp() {} });

function mount(remaining = 30, starts = true) {
    clock.value = Date.now();
    const settings = ref({ enabled: true, remaining_seconds: remaining, total_seconds: 1500, warning_seconds: 60 });
    const originalPost = router.post;
    const requests = [];
    router.post = (url, data, options) => { requests.push({ url, data, options }); if (starts) options.onStart(); };
    let state;
    const app = renderer.createApp(defineComponent({ setup() { state = useWorkSession(() => settings.value, '/session/renew', '/logout'); return () => h('div'); } }));
    app.mount({});
    return { state, settings, requests, close() { app.unmount(); router.post = originalPost; } };
}

test('a successful renewal reanchors even when the server repeats the same duration', async () => {
    const originalNow = Date.now;
    let now = originalNow();
    Date.now = () => now;
    const screen = mount(1500);
    try {
        now += 5000;
        clock.value = now;
        await nextTick();
        assert.equal(screen.state.remainingSeconds.value, 1495);
        screen.state.renew();
        screen.settings.value = { ...screen.settings.value, remaining_seconds: 1500 };
        await nextTick();
        assert.equal(screen.state.remainingSeconds.value, 1500);
        screen.requests[0].options.onFinish();
        await nextTick();
        assert.equal(screen.state.warningVisible.value, false);
        assert.equal(screen.requests.length, 1);
        assert.equal(screen.requests[0].url, '/session/renew');
    } finally { screen.close(); Date.now = originalNow; }
});

for (const failure of ['onNetworkError', 'onHttpException', 'onError', 'onCancel']) {
    test(`renewal ${failure} keeps the deadline and permits an explicit retry`, async () => {
        const screen = mount();
        try {
            screen.state.renew();
            screen.state.renew();
            screen.state.signOut();
            assert.equal(screen.requests.length, 1);
            assert.equal(screen.state.renewing.value, true);
            screen.requests[0].options[failure]({});
            screen.requests[0].options.onFinish();
            await nextTick();
            assert.equal(screen.state.remainingSeconds.value, 30);
            assert.equal(screen.state.renewing.value, false);
            assert.equal(screen.state.warningVisible.value, true);
            assert.match(screen.state.failure.value, /prazo anterior/);
            screen.state.renew();
            assert.equal(screen.requests.length, 2);
            assert.equal(screen.state.failure.value, '');
        } finally { screen.close(); }
    });
}

test('failed automatic sign-out stays visible without retrying in a loop', async () => {
    const screen = mount(0);
    try {
        assert.equal(screen.requests[0].url, '/logout');
        assert.equal(screen.state.warningVisible.value, true);
        screen.requests[0].options.onNetworkError(new Error('offline'));
        screen.requests[0].options.onFinish();
        await nextTick();
        assert.equal(screen.requests.length, 1);
        assert.equal(screen.state.signingOut.value, false);
        assert.match(screen.state.failure.value, /confirmar a saída/);
        screen.state.signOut();
        assert.equal(screen.requests.length, 2);
    } finally { screen.close(); }
});

test('expiry does not cancel an in-flight renewal and signs out only if it still expired', async () => {
    const screen = mount();
    try {
        screen.state.renew();
        screen.settings.value = { ...screen.settings.value, remaining_seconds: 0 };
        await nextTick();
        assert.equal(screen.requests.length, 1);
        screen.requests[0].options.onNetworkError(new Error('offline'));
        screen.requests[0].options.onFinish();
        await nextTick();
        assert.equal(screen.requests.length, 2);
        assert.equal(screen.requests[1].url, '/logout');
    } finally { screen.close(); }
});

test('a visit cancelled before starting never leaves either button busy', () => {
    const screen = mount(30, false);
    try {
        screen.state.renew();
        screen.state.signOut();
        assert.equal(screen.state.renewing.value, false);
        assert.equal(screen.state.signingOut.value, false);
        assert.equal(screen.requests.length, 2);
    } finally { screen.close(); }
});

test('confirmed renewal at the deadline keeps the new session and does not sign out', async () => {
    const screen = mount();
    try {
        screen.state.renew();
        screen.settings.value = { ...screen.settings.value, remaining_seconds: 0 };
        await nextTick();
        screen.settings.value = { ...screen.settings.value, remaining_seconds: 1500 };
        await nextTick();
        screen.requests[0].options.onFinish();
        await nextTick();
        assert.equal(screen.requests.length, 1);
        assert.equal(screen.state.remainingSeconds.value, 1500);
        assert.equal(screen.state.warningVisible.value, false);
    } finally { screen.close(); }
});

test('disabled session controls never automatically sign out at zero', async () => {
    const screen = mount();
    try {
        screen.settings.value = { ...screen.settings.value, enabled: false, remaining_seconds: 0 };
        await nextTick();
        assert.equal(screen.requests.length, 0);
        assert.equal(screen.state.warningVisible.value, false);
    } finally { screen.close(); }
});
